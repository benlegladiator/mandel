<?php
require_once __DIR__ . '/includes/header.php';

if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut accéder à l'usine.";
    header("Location: index.php");
    exit;
}

// 1. Get or Create Depot
$stmt_depot = $pdo->query("SELECT * FROM shops WHERE is_depot = 1 LIMIT 1");
$depot = $stmt_depot->fetch();

if (!$depot) {
    $pdo->exec("INSERT INTO shops (name, is_depot) VALUES ('Usine de Transformation (Dépôt)', 1)");
    $depot_id = $pdo->lastInsertId();
    $stmt_depot = $pdo->query("SELECT * FROM shops WHERE id = " . $depot_id);
    $depot = $stmt_depot->fetch();
} else {
    $depot_id = $depot['id'];
}

$message = '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'production';

// Fetch all products
$products_stmt = $pdo->query("SELECT * FROM products ORDER BY name");
$all_products = $products_stmt->fetchAll();

// Fetch all normal shops (not depot)
$shops_stmt = $pdo->query("SELECT * FROM shops WHERE is_depot = 0 ORDER BY name");
$normal_shops = $shops_stmt->fetchAll();

// Map ultra-légère des stocks physiques réels du dépôt pour les formulaires (FETCH_KEY_PAIR)
$stock_map_stmt = $pdo->prepare("SELECT product_id, quantity FROM product_stock WHERE shop_id = ?");
$stock_map_stmt->execute([$depot_id]);
$stock_map = $stock_map_stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

// Pagination du tableau de stock Dépôt (40 articles/page)
$per_page_depot = 40;
$search_stock = isset($_GET['search_stock']) ? trim($_GET['search_stock']) : '';

$where_stock = "";
$params_stock = [$depot_id];
if ($search_stock !== '') {
    $where_stock .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params_stock[] = '%' . $search_stock . '%';
    $params_stock[] = '%' . $search_stock . '%';
}

$stmt_cnt_stk = $pdo->prepare("SELECT COUNT(*) FROM products p LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.shop_id = ? WHERE 1=1 $where_stock");
$stmt_cnt_stk->execute($params_stock);
$total_depot_records = intval($stmt_cnt_stk->fetchColumn() ?: 0);

$total_pages_depot = max(1, ceil($total_depot_records / $per_page_depot));
$current_page_depot = ($active_tab === 'stock' && isset($_GET['page'])) ? max(1, min($total_pages_depot, intval($_GET['page']))) : 1;
$offset_depot = ($current_page_depot - 1) * $per_page_depot;

$stock_sql = "
    SELECT p.id, p.name, p.unit, p.price_purchase, COALESCE(ps.quantity, 0) as qty 
    FROM products p 
    LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.shop_id = ?
    WHERE 1=1 $where_stock
    ORDER BY qty DESC, p.name ASC
    LIMIT $per_page_depot OFFSET $offset_depot
";
$stock_stmt = $pdo->prepare($stock_sql);
$stock_stmt->execute($params_stock);
$depot_stock = $stock_stmt->fetchAll();

// Fetch transfer history
$transfers_stmt = $pdo->prepare("
    SELECT t.*, p.name as product_name, s.name as to_shop_name, u.name as user_name 
    FROM stock_transfers t
    JOIN products p ON t.product_id = p.id
    JOIN shops s ON t.to_shop_id = s.id
    JOIN users u ON t.user_id = u.id
    WHERE t.from_shop_id = ?
    ORDER BY t.created_at DESC LIMIT 100
");
$transfers_stmt->execute([$depot_id]);
$transfer_history = $transfers_stmt->fetchAll();

// Traitement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_production'])) {
        $p_id = intval($_POST['product_id']);
        $qty = intval($_POST['quantity']);
        $cost = floatval($_POST['unit_cost']);

        if ($p_id > 0 && $qty > 0 && $cost >= 0) {
            try {
                $pdo->beginTransaction();
                
                // 1. Order (Production)
                $total_cost = $qty * $cost;
                $stmt_order = $pdo->prepare("INSERT INTO orders (type, shop_id, user_id, total_amount, subtotal, status) VALUES ('PRODUCTION', ?, ?, ?, ?, 'COMPLETED')");
                $stmt_order->execute([$depot_id, $_SESSION['user_id'], $total_cost, $total_cost]);
                $order_id = $pdo->lastInsertId();
                
                // 2. Order Item
                $stmt_item = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->execute([$order_id, $p_id, $qty, $cost, $total_cost]);
                
                // 3. Update Depot Stock
                $stmt_stock = $pdo->prepare("INSERT INTO product_stock (product_id, shop_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
                $stmt_stock->execute([$p_id, $depot_id, $qty, $qty]);
                
                // 4. Update CUMP (Weighted Average Cost)
                $stmt_old = $pdo->prepare("SELECT price_purchase FROM products WHERE id = ?");
                $stmt_old->execute([$p_id]);
                $old_cump = floatval($stmt_old->fetchColumn());
                
                $stmt_gstock = $pdo->prepare("SELECT SUM(quantity) FROM product_stock WHERE product_id = ?");
                $stmt_gstock->execute([$p_id]);
                $current_global_stock = intval($stmt_gstock->fetchColumn());
                
                $old_global_stock = $current_global_stock - $qty;
                if ($old_global_stock < 0) $old_global_stock = 0;
                
                $old_value = $old_global_stock * $old_cump;
                $new_value = $old_value + $total_cost;
                $new_cump = $current_global_stock > 0 ? ($new_value / $current_global_stock) : $cost;
                
                $stmt_update_cump = $pdo->prepare("UPDATE products SET price_purchase = ? WHERE id = ?");
                $stmt_update_cump->execute([$new_cump, $p_id]);
                
                $pdo->commit();
                $message = "Production enregistrée avec succès. Le stock du dépôt et le CUMP global ont été mis à jour.";
                
                // Update local stock map for display
                $stock_map[$p_id] += $qty;
                
                $active_tab = 'production';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Erreur lors de l'enregistrement de la production : " . $e->getMessage();
            }
        } else {
            $error = "Veuillez remplir tous les champs correctement (Quantité > 0).";
        }
    }
    
    if (isset($_POST['save_transfer'])) {
        $p_id = intval($_POST['product_id']);
        $qty = intval($_POST['quantity']);
        $to_shop_id = intval($_POST['to_shop_id']);
        
        if ($p_id > 0 && $qty > 0 && $to_shop_id > 0) {
            try {
                $pdo->beginTransaction();
                
                $stmt_check = $pdo->prepare("SELECT quantity FROM product_stock WHERE product_id = ? AND shop_id = ?");
                $stmt_check->execute([$p_id, $depot_id]);
                $current_depot_stock = intval($stmt_check->fetchColumn());
                
                if ($current_depot_stock >= $qty) {
                    // Générer un code de transfert unique
                    $next_num = 1;
                    try {
                        $last_code_stmt = $pdo->query("SELECT transfer_code FROM stock_transfers WHERE transfer_code LIKE 'TR-%' ORDER BY id DESC LIMIT 1");
                        $last_code = $last_code_stmt->fetchColumn();
                        if ($last_code && preg_match('/^TR-(\d+)$/', $last_code, $matches)) {
                            $next_num = intval($matches[1]) + 1;
                        }
                    } catch (Exception $ex) {}
                    $transfer_code = 'TR-' . sprintf("%05d", $next_num);

                    $is_admin_transfer = ($_SESSION['user_role'] === 'admin');
                    $transfer_status = $is_admin_transfer ? 'RECEIVED' : 'PENDING';
                    $received_by_val = $is_admin_transfer ? $_SESSION['user_id'] : null;

                    // 1. Enregistrer dans stock_transfers
                    $stmt_transfer = $pdo->prepare("
                        INSERT INTO stock_transfers (transfer_code, from_shop_id, to_shop_id, product_id, quantity, user_id, is_read, status, received_by, received_at) 
                        VALUES (?, ?, ?, ?, ?, ?, " . ($is_admin_transfer ? "1" : "0") . ", ?, ?, " . ($is_admin_transfer ? "NOW()" : "NULL") . ")
                    ");
                    $stmt_transfer->execute([$transfer_code, $depot_id, $to_shop_id, $p_id, $qty, $_SESSION['user_id'], $transfer_status, $received_by_val]);
                    
                    // 2. Déduire le stock Usine immédiatement
                    $stmt_dec = $pdo->prepare("UPDATE product_stock SET quantity = quantity - ? WHERE product_id = ? AND shop_id = ?");
                    $stmt_dec->execute([$qty, $p_id, $depot_id]);

                    // 3. Si Admin, créditer immédiatement le stock de destination
                    if ($is_admin_transfer) {
                        $stmt_add_dest = $pdo->prepare("
                            INSERT INTO product_stock (product_id, shop_id, quantity) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                        ");
                        $stmt_add_dest->execute([$p_id, $to_shop_id, $qty]);
                    }
                    
                    $pdo->commit();
                    if ($is_admin_transfer) {
                        $message = "Transfert $transfer_code effectué avec succès. Le stock de la boutique de destination a été augmenté immédiatement (Effectué par l'Admin).";
                    } else {
                        $message = "Transfert $transfer_code expédié avec succès depuis l'Usine. Le stock du dépôt a été déduit et sera ajouté à la boutique dès sa confirmation de réception.";
                    }
                    $stock_map[$p_id] -= $qty;
                    $active_tab = 'transfer';
                } else {
                    $pdo->rollBack();
                    $error = "Stock insuffisant dans le Dépôt (Disponible : $current_depot_stock).";
                    $active_tab = 'transfer';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Erreur lors du transfert : " . $e->getMessage();
                $active_tab = 'transfer';
            }
        } else {
            $error = "Veuillez remplir tous les champs correctement.";
            $active_tab = 'transfer';
        }
    }
}
?>

<div class="row align-items-center mb-4">
    <div class="col-md-6">
        <h2 class="fw-extrabold text-slate-800 mb-1"><i class="fa-solid fa-industry text-primary me-2"></i><?= htmlspecialchars($depot['name']) ?></h2>
        <p class="text-secondary mb-0">Gérez la production de l'usine et ravitaillez vos boutiques.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success border-0 shadow-sm alert-dismissible fade show" role="alert" style="border-radius: 12px;">
        <i class="fa-solid fa-check-circle me-2"></i><?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm alert-dismissible fade show" role="alert" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs custom-tabs mb-4 border-0" id="depotTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'production' ? 'active shadow-sm' : '' ?>" id="production-tab" data-bs-toggle="tab" data-bs-target="#production" type="button" role="tab" aria-controls="production" aria-selected="<?= $active_tab === 'production' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-box-open me-2"></i>Entrée de Production
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'transfer' ? 'active shadow-sm' : '' ?>" id="transfer-tab" data-bs-toggle="tab" data-bs-target="#transfer" type="button" role="tab" aria-controls="transfer" aria-selected="<?= $active_tab === 'transfer' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-truck-fast me-2"></i>Transfert vers Boutique
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'stock' ? 'active shadow-sm' : '' ?>" id="stock-tab" data-bs-toggle="tab" data-bs-target="#stock" type="button" role="tab" aria-controls="stock" aria-selected="<?= $active_tab === 'stock' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-warehouse me-2"></i>État du Stock Dépôt
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'history' ? 'active shadow-sm' : '' ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab" aria-controls="history" aria-selected="<?= $active_tab === 'history' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-clock-rotate-left me-2"></i>Historique des Transferts
        </button>
    </li>
</ul>

<div class="tab-content" id="depotTabsContent">
    
    <!-- Tab: Production -->
    <div class="tab-pane fade <?= $active_tab === 'production' ? 'show active' : '' ?>" id="production" role="tabpanel" aria-labelledby="production-tab">
        <div class="card border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-4 text-primary"><i class="fa-solid fa-plus-circle me-2"></i>Enregistrer une production</h5>
                <form action="depot.php?tab=production" method="POST">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold text-secondary small mb-0">Produit Transformé</label>
                                <a href="product-add.php" class="badge bg-primary text-decoration-none py-1 px-2"><i class="fa-solid fa-plus me-1"></i>Nouveau produit</a>
                            </div>
                            <input type="text" id="search_prod_input" class="form-control form-control-sm mb-2" placeholder="🔍 Rechercher dans la liste..." onkeyup="filterSelectOptions('search_prod_input', 'prod_select')">
                            <select name="product_id" id="prod_select" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($all_products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-secondary small">Quantité Produite</label>
                            <input type="number" name="quantity" class="form-control" required min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Coût Unitaire (FCFA)</label>
                            <div class="input-group">
                                <input type="number" name="unit_cost" class="form-control" required min="0" step="0.01">
                                <span class="input-group-text">FCFA</span>
                            </div>
                            <div class="form-text small text-muted">Sert à recalculer le CUMP.</div>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" name="save_production" class="btn btn-primary px-4 fw-bold" style="border-radius: 10px;">
                            <i class="fa-solid fa-check me-2"></i>Valider la production
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tab: Transfer -->
    <div class="tab-pane fade <?= $active_tab === 'transfer' ? 'show active' : '' ?>" id="transfer" role="tabpanel" aria-labelledby="transfer-tab">
        <div class="card border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-4 text-accent"><i class="fa-solid fa-truck-arrow-right me-2"></i>Ravitailler une boutique depuis l'Usine</h5>
                <form action="depot.php?tab=transfer" method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Boutique Destinataire</label>
                            <select name="to_shop_id" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($normal_shops as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold text-secondary small">Produit à expédier</label>
                            <input type="text" id="search_transfer_input" class="form-control form-control-sm mb-2" placeholder="🔍 Rechercher produit..." onkeyup="filterSelectOptions('search_transfer_input', 'transfer_product_select')">
                            <select name="product_id" id="transfer_product_select" class="form-select" required onchange="updateMaxQty(this)">
                                <option value="" data-qty="0">-- Sélectionner --</option>
                                <?php foreach ($all_products as $p): 
                                    $available = $stock_map[$p['id']] ?? 0;
                                ?>
                                    <option value="<?= $p['id'] ?>" data-qty="<?= $available ?>">
                                        <?= htmlspecialchars($p['name']) ?> (Stock: <?= $available ?> <?= $p['unit'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-secondary small">Quantité</label>
                            <input type="number" name="quantity" id="transfer_qty" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" name="save_transfer" class="btn btn-accent px-4 fw-bold" style="border-radius: 10px;">
                            <i class="fa-solid fa-paper-plane me-2"></i>Expédier le stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tab: Stock -->
    <div class="tab-pane fade <?= $active_tab === 'stock' ? 'show active' : '' ?>" id="stock" role="tabpanel" aria-labelledby="stock-tab">
        <div class="card border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-boxes-stacked me-2"></i>Inventaire de l'Usine</h5>
                    <div style="min-width: 280px;">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                            <input type="text" id="searchDepotStock" class="form-control border-start-0 ps-0" placeholder="Rechercher un produit..." onkeyup="filterDepotStock()">
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="depotStockTable">
                        <thead class="table-light">
                            <tr>
                                <th>Produit</th>
                                <th class="text-center">Unité</th>
                                <th class="text-center">CUMP (Global)</th>
                                <th class="text-center">En Stock (Dépôt)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($depot_stock as $s): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($s['name']) ?></td>
                                    <td class="text-center text-secondary small"><?= htmlspecialchars($s['unit']) ?></td>
                                    <td class="text-center fw-semibold text-secondary"><?= number_format($s['price_purchase'], 0, '.', ' ') ?> FCFA</td>
                                    <td class="text-center">
                                        <span class="badge <?= $s['qty'] > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> fs-6 px-3 py-2" style="border-radius: 8px;">
                                            <?= $s['qty'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Stock Dépôt -->
                <?php if ($total_pages_depot > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                        <div class="small text-secondary fw-semibold">
                            Affichage de <?= min($total_depot_records, $offset_depot + 1) ?> à <?= min($total_depot_records, $offset_depot + count($depot_stock)) ?> sur <strong><?= number_format($total_depot_records, 0, '.', ' ') ?></strong> article(s)
                        </div>
                        <nav aria-label="Pagination stock dépôt">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $q_dep = $_GET;
                                $q_dep['tab'] = 'stock';
                                if ($current_page_depot > 1):
                                    $q_dep['page'] = $current_page_depot - 1;
                                    echo '<li class="page-item"><a class="page-link" href="depot.php?' . http_build_query($q_dep) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                endif;

                                $st_p = max(1, $current_page_depot - 2);
                                $en_p = min($total_pages_depot, $current_page_depot + 2);

                                for ($p = $st_p; $p <= $en_p; $p++):
                                    $q_dep['page'] = $p;
                                    $act = ($p == $current_page_depot) ? 'active' : '';
                                    echo '<li class="page-item ' . $act . '"><a class="page-link" href="depot.php?' . http_build_query($q_dep) . '">' . $p . '</a></li>';
                                endfor;

                                if ($current_page_depot < $total_pages_depot):
                                    $q_dep['page'] = $current_page_depot + 1;
                                    echo '<li class="page-item"><a class="page-link" href="depot.php?' . http_build_query($q_dep) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                                endif;
                                ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tab: History -->
    <div class="tab-pane fade <?= $active_tab === 'history' ? 'show active' : '' ?>" id="history" role="tabpanel" aria-labelledby="history-tab">
        <div class="card border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-clock-rotate-left me-2"></i>Historique des Transferts (100 derniers)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Heure</th>
                                <th>Réf / Code</th>
                                <th>Produit</th>
                                <th class="text-center">Quantité</th>
                                <th>Boutique Destinataire</th>
                                <th>Statut</th>
                                <th>Opérateur</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transfer_history)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">Aucun transfert enregistré.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transfer_history as $t): ?>
                                    <tr>
                                        <td class="small text-secondary"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($t['transfer_code'] ?: ('TR-' . sprintf("%05d", $t['id']))) ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars($t['product_name']) ?></td>
                                        <td class="text-center fw-bold text-accent"><?= $t['quantity'] ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['to_shop_name']) ?></span></td>
                                        <td>
                                            <?php if (($t['status'] ?? 'RECEIVED') === 'RECEIVED'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="fa-solid fa-check-double me-1"></i>Reçu
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning">
                                                    <i class="fa-solid fa-truck-fast me-1"></i>En transit
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= htmlspecialchars($t['user_name']) ?></td>
                                        <td class="text-end">
                                            <a href="transfer-receipt.php?code=<?= urlencode($t['transfer_code'] ?? '') ?>&id=<?= $t['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px;">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Stock Dépôt -->
                <?php if ($total_pages_depot > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                        <div class="small text-secondary fw-semibold">
                            Affichage de <?= min($total_depot_records, $offset_depot + 1) ?> à <?= min($total_depot_records, $offset_depot + count($depot_stock)) ?> sur <strong><?= number_format($total_depot_records, 0, '.', ' ') ?></strong> article(s)
                        </div>
                        <nav aria-label="Pagination stock dépôt">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $q_dep = $_GET;
                                $q_dep['tab'] = 'stock';
                                if ($current_page_depot > 1):
                                    $q_dep['page'] = $current_page_depot - 1;
                                    echo '<li class="page-item"><a class="page-link" href="depot.php?' . http_build_query($q_dep) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                endif;

                                $st_p = max(1, $current_page_depot - 2);
                                $en_p = min($total_pages_depot, $current_page_depot + 2);

                                for ($p = $st_p; $p <= $en_p; $p++):
                                    $q_dep['page'] = $p;
                                    $act = ($p == $current_page_depot) ? 'active' : '';
                                    echo '<li class="page-item ' . $act . '"><a class="page-link" href="depot.php?' . http_build_query($q_dep) . '">' . $p . '</a></li>';
                                endfor;

                                if ($current_page_depot < $total_pages_depot):
                                    $q_dep['page'] = $current_page_depot + 1;
                                    echo '<li class="page-item"><a class="page-link" href="depot.php?' . http_build_query($q_dep) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                                endif;
                                ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
function updateMaxQty(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const maxQty = selectedOption.getAttribute('data-qty');
    const qtyInput = document.getElementById('transfer_qty');
    
    if (maxQty) {
        qtyInput.setAttribute('max', maxQty);
        qtyInput.setAttribute('placeholder', 'Max: ' + maxQty);
    } else {
        qtyInput.removeAttribute('max');
        qtyInput.removeAttribute('placeholder');
    }
}

function normalizeSearch(str) {
    return (str || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

function filterDepotStock() {
    const input = document.getElementById('searchDepotStock');
    const normFilter = normalizeSearch(input.value);
    const filterTokens = normFilter.split(/\s+/).filter(Boolean);
    const table = document.getElementById('depotStockTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const nameCell = rows[i].getElementsByTagName('td')[0];
        const unitCell = rows[i].getElementsByTagName('td')[1];
        if (nameCell || unitCell) {
            const nameText = normalizeSearch(nameCell ? (nameCell.textContent || nameCell.innerText) : '');
            const unitText = normalizeSearch(unitCell ? (unitCell.textContent || unitCell.innerText) : '');
            const combined = nameText + ' ' + unitText;
            
            const match = filterTokens.length === 0 || filterTokens.every(token => combined.includes(token));
            rows[i].style.display = match ? '' : 'none';
        }
    }
}

function filterSelectOptions(inputId, selectId) {
    const normFilter = normalizeSearch(document.getElementById(inputId).value);
    const filterTokens = normFilter.split(/\s+/).filter(Boolean);
    const select = document.getElementById(selectId);
    const options = select.getElementsByTagName('option');
    for (let i = 0; i < options.length; i++) {
        if (i === 0 && options[i].value === "") continue;
        const text = normalizeSearch(options[i].textContent || options[i].innerText);
        const match = filterTokens.length === 0 || filterTokens.every(token => text.includes(token));
        options[i].style.display = match ? "" : "none";
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
