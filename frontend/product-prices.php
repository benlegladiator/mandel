<?php
require_once __DIR__ . '/includes/header.php';

// Vérification de sécurité tolérante à la casse pour les comptes Admin / Boss
$current_role = strtolower(trim($_SESSION['user_role'] ?? 'seller'));
$is_admin = in_array($current_role, ['admin', 'boss', 'owner', 'superadmin']);

if (!$is_admin && $user_role !== 'admin') {
    echo "<div class='container my-5'><div class='alert alert-danger shadow-sm border-0' style='border-radius: 12px;'><i class='fa-solid fa-lock me-2'></i>Accès réservé aux administrateurs. (Rôle actuel : " . htmlspecialchars($_SESSION['user_role'] ?? 'non défini') . ")</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Traitement de l'enregistrement massif des prix
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prices_batch'])) {
    $prices_data = $_POST['prices'] ?? [];
    $updated_count = 0;

    $stmt_update = $pdo->prepare("
        UPDATE products 
        SET price_purchase = :purchase, 
            price_sale = :sale, 
            margin_percent = :margin 
        WHERE id = :id
    ");

    foreach ($prices_data as $pid => $data) {
        $id = intval($pid);
        $purchase = floatval($data['purchase'] ?? 0);
        $sale = floatval($data['sale'] ?? 0);
        
        $margin_pct = 0;
        if ($sale > 0) {
            $margin_pct = round((($sale - $purchase) / $sale) * 100, 2);
        }

        try {
            $stmt_update->execute([
                ':purchase' => $purchase,
                ':sale' => $sale,
                ':margin' => $margin_pct,
                ':id' => $id
            ]);
            $updated_count++;
        } catch (Exception $ex) {}
    }

    $_SESSION['success_message'] = "🎉 <strong>Mise à jour réussie !</strong> Les prix d'achat et de vente ont été enregistrés avec succès pour $updated_count article(s).";
    header("Location: product-prices.php");
    exit;
}

// Récupération des catégories et filtres
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$margin_alert = isset($_GET['margin_alert']) ? intval($_GET['margin_alert']) : 0;
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'most_sold'; // Défaut : Articles les plus vendus en premier

$sql = "
    SELECT p.*, c.name as category_name,
           (CASE WHEN p.price_sale > 0 THEN ((p.price_sale - p.price_purchase) / p.price_sale) * 100 ELSE 0 END) as calc_margin_pct,
           COALESCE(sales_stats.total_qty_sold, 0) as total_qty_sold,
           COALESCE(sales_stats.total_ca, 0) as total_ca
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN (
        SELECT oi.product_id,
               SUM(oi.quantity) as total_qty_sold,
               SUM(oi.total_price) as total_ca
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
        GROUP BY oi.product_id
    ) as sales_stats ON sales_stats.product_id = p.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $t_idx = 0;
    foreach ($search_terms as $term) {
        $p_name = ":st_n_$t_idx";
        $p_sku  = ":st_s_$t_idx";
        $p_id   = ":st_i_$t_idx";
        $sql .= " AND (p.name LIKE $p_name OR p.sku LIKE $p_sku OR p.id LIKE $p_id)";
        $params[$p_name] = '%' . $term . '%';
        $params[$p_sku]  = '%' . $term . '%';
        $params[$p_id]   = '%' . $term . '%';
        $t_idx++;
    }
}

if ($category_filter > 0) {
    $sql .= " AND p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

// Application du tri
switch ($sort) {
    case 'most_sold':
        $sql .= " ORDER BY total_qty_sold DESC, total_ca DESC, p.name ASC";
        break;
    case 'top_ca':
        $sql .= " ORDER BY total_ca DESC, total_qty_sold DESC, p.name ASC";
        break;
    case 'margin_asc':
        $sql .= " ORDER BY calc_margin_pct ASC, total_qty_sold DESC, p.name ASC";
        break;
    case 'margin_desc':
        $sql .= " ORDER BY calc_margin_pct DESC, p.name ASC";
        break;
    case 'name_asc':
        $sql .= " ORDER BY p.name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY p.name DESC";
        break;
    case 'purchase_desc':
        $sql .= " ORDER BY p.price_purchase DESC";
        break;
    default:
        $sql .= " ORDER BY total_qty_sold DESC, total_ca DESC, p.name ASC";
        break;
}

// Calcul de la pagination
$per_page = 50;

// Requête COUNT rapide sans la sous-requête de ventes
$count_sql = "SELECT COUNT(*) FROM products p WHERE 1=1";
if (!empty($search)) {
    $search_terms_cnt = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $tc_idx = 0;
    foreach ($search_terms_cnt as $term) {
        $count_sql .= " AND (p.name LIKE :cnt_n_$tc_idx OR p.sku LIKE :cnt_s_$tc_idx OR p.id LIKE :cnt_i_$tc_idx)";
        $params[":cnt_n_$tc_idx"] = '%' . $term . '%';
        $params[":cnt_s_$tc_idx"] = '%' . $term . '%';
        $params[":cnt_i_$tc_idx"] = '%' . $term . '%';
        $tc_idx++;
    }
}
if ($category_filter > 0) {
    $count_sql .= " AND p.category_id = :cnt_cat_id";
    $params[':cnt_cat_id'] = $category_filter;
}

$stmt_cnt = $pdo->prepare($count_sql);
$cnt_params = [];
foreach ($params as $k => $v) {
    if (strpos($k, ':cnt_') === 0) {
        $clean_k = str_replace(':cnt_', ':', $k);
        $cnt_params[$k] = $v;
    } elseif ($category_filter > 0 && $k === ':category_id') {
        $cnt_params[':cnt_cat_id'] = $v;
    }
}
// Si cnt_params est vide ou incomplet, bind tous les params correspondants
if (empty($cnt_params)) {
    $stmt_cnt = $pdo->query("SELECT COUNT(*) FROM products");
    $total_records = intval($stmt_cnt->fetchColumn() ?: 0);
} else {
    $stmt_cnt->execute($cnt_params);
    $total_records = intval($stmt_cnt->fetchColumn() ?: 0);
}

$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

$sql .= " LIMIT $per_page OFFSET $offset";

// Nettoyer les clés de comptage de $params avant d'exécuter la requête principale
foreach ($params as $k => $v) {
    if (strpos($k, ':cnt_') === 0) {
        unset($params[$k]);
    }
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrer par alerte faible marge si demandé
if ($margin_alert === 1) {
    $products = array_filter($products, function($p) {
        return floatval($p['calc_margin_pct']) < 5.0; // Faible marge < 5%
    });
}
?>

<!-- Title & Action Bar -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
                <span class="text-primary"><i class="fa-solid fa-calculator"></i></span> 
                Grille d'Ajustement des Prix & Marges
            </h1>
            <small class="text-secondary">Modifiez et triez vos prix en direct avec calcul en temps réel de la marge brute</small>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="export-prices-csv.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success btn-sm fw-semibold" style="border-radius: 8px;" title="Exporter tous les articles filtrés">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel (CSV)
            </a>
            <a href="product-prices-import.php" class="btn btn-outline-primary btn-sm fw-semibold" style="border-radius: 8px;">
                <i class="fa-solid fa-file-import me-1"></i>Importation CSV
            </a>
            <a href="products.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                <i class="fa-solid fa-arrow-left me-1"></i>Retour au Catalogue
            </a>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-3" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $_SESSION['success_message'] ?>
        <?php unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Formulaire de Filtres et Tri par Marge -->
<div class="glass-card p-3 mb-4">
    <form method="GET" action="product-prices.php" class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label for="search" class="form-label small fw-bold text-secondary">Recherche Produit / SKU</label>
            <input type="text" name="search" id="search" class="form-control form-control-custom" placeholder="Nom ou code SKU..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-12 col-md-3">
            <label for="category_id" class="form-label small fw-bold text-secondary">Catégorie / Famille</label>
            <select name="category_id" id="category_id" class="form-select form-control-custom" onchange="this.form.submit()">
                <option value="0">Toutes les catégories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $category_filter ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="sort" class="form-label small fw-bold text-primary"><i class="fa-solid fa-sort me-1"></i>Trier par :</label>
            <select name="sort" id="sort" class="form-select form-control-custom fw-bold" onchange="this.form.submit()">
                <option value="most_sold" <?= $sort === 'most_sold' ? 'selected' : '' ?>>🏆 Articles les plus vendus (Qté)</option>
                <option value="top_ca" <?= $sort === 'top_ca' ? 'selected' : '' ?>>💵 Articles générant le plus de CA</option>
                <option value="margin_asc" <?= $sort === 'margin_asc' ? 'selected' : '' ?>>📊 % Marge Croissant (Faibles marges d'abord)</option>
                <option value="margin_desc" <?= $sort === 'margin_desc' ? 'selected' : '' ?>>📊 % Marge Décroissant (Fortes marges d'abord)</option>
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>🔤 Nom de l'article (A ➔ Z)</option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>🔤 Nom de l'article (Z ➔ A)</option>
                <option value="purchase_desc" <?= $sort === 'purchase_desc' ? 'selected' : '' ?>>💰 Prix d'Achat le plus cher</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" id="margin_alert" name="margin_alert" value="1" <?= $margin_alert === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
                <label class="form-check-label small fw-bold text-danger" for="margin_alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Marges Critiques (&lt; 5%)
                </label>
            </div>
            <button type="submit" class="btn btn-grad w-100 btn-sm py-1.5"><i class="fa-solid fa-filter me-1"></i>Appliquer</button>
        </div>
    </form>
</div>

<!-- Grille des Prix Interactives -->
<form action="product-prices.php" method="POST" id="batchPricesForm">
    <input type="hidden" name="save_prices_batch" value="1">
    
    <div class="glass-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-list text-primary me-2"></i>Grille des Tarifs (<?= count($products) ?> articles)</h5>
                <span class="badge bg-light text-dark border small">Édition directe & Calcul dynamique</span>
            </div>
            <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow-sm" style="border-radius: 10px;">
                <i class="fa-solid fa-floppy-disk me-2"></i>Sauvegarder Tous les Prix
            </button>
        </div>

        <?php if (empty($products)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="fa-solid fa-face-frown fa-3x mb-3 text-muted"></i>
                <p class="mb-0">Aucun produit ne correspond aux filtres sélectionnés.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="pricesEditTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 80px;">SKU</th>
                            <th>
                                <a href="product-prices.php?sort=<?= $sort === 'name_asc' ? 'name_desc' : 'name_asc' ?>&search=<?= urlencode($search) ?>&category_id=<?= $category_filter ?>&margin_alert=<?= $margin_alert ?>" class="text-white text-decoration-none" title="Cliquer pour trier par nom">
                                    Désignation Produit <i class="fa-solid fa-sort ms-1"></i>
                                </a>
                            </th>
                            <th>Catégorie</th>
                            <th class="text-center" style="width: 70px;">Unité</th>
                            <th style="width: 145px;" class="bg-primary text-white">Prix Achat (FCFA) *</th>
                            <th style="width: 145px;" class="bg-success text-white">Prix Vente (FCFA) *</th>
                            <th style="width: 115px;" class="text-end">Marge Brute</th>
                            <th style="width: 120px;" class="text-center bg-warning text-dark">
                                <a href="product-prices.php?sort=<?= $sort === 'margin_asc' ? 'margin_desc' : 'margin_asc' ?>&search=<?= urlencode($search) ?>&category_id=<?= $category_filter ?>&margin_alert=<?= $margin_alert ?>" class="text-dark text-decoration-none fw-bold" title="Cliquer pour trier par % de Marge">
                                    Marge % <i class="fa-solid fa-sort ms-1"></i>
                                </a>
                            </th>
                            <th style="width: 110px;" class="text-center">
                                <a href="product-prices.php?sort=<?= $sort === 'most_sold' ? 'top_ca' : 'most_sold' ?>&search=<?= urlencode($search) ?>&category_id=<?= $category_filter ?>&margin_alert=<?= $margin_alert ?>" class="text-white text-decoration-none" title="Cliquer pour trier par ventes">
                                    <i class="fa-solid fa-trophy me-1"></i>Qté Vendue <i class="fa-solid fa-sort ms-1"></i>
                                </a>
                            </th>
                            <th style="width: 120px;" class="text-end">CA Généré</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <?php 
                            $purchase = floatval($p['price_purchase']);
                            $sale = floatval($p['price_sale']);
                            $gross = $sale - $purchase;
                            $pct = $sale > 0 ? round(($gross / $sale) * 100, 2) : 0;
                            $pid = $p['id'];
                            $qty_sold = intval($p['total_qty_sold']);
                            $ca_genere = floatval($p['total_ca']);
                            ?>
                            <tr id="row-<?= $pid ?>" class="<?= $purchase == 0 && $qty_sold > 0 ? 'table-warning' : '' ?>">
                                <td class="fw-semibold text-secondary font-monospace small"><?= htmlspecialchars($p['sku'] ?: ('PRD-' . $pid)) ?></td>
                                <td>
                                    <div class="fw-bold text-slate-900"><?= htmlspecialchars($p['name']) ?></div>
                                    <?php if ($purchase == 0 && $qty_sold > 0): ?>
                                        <small class="text-danger fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i>Prix achat manquant !</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border small"><?= htmlspecialchars($p['category_name'] ?? 'Général') ?></span>
                                </td>
                                <td class="text-center text-secondary small"><?= htmlspecialchars($p['unit']) ?></td>
                                
                                <!-- Champ Prix Achat -->
                                <td class="bg-primary bg-opacity-10">
                                    <input type="number" step="1" min="0" 
                                           class="form-control form-control-sm fw-bold border-primary input-purchase" 
                                           name="prices[<?= $pid ?>][purchase]" 
                                           value="<?= number_format($purchase, 0, '', '') ?>" 
                                           data-pid="<?= $pid ?>" 
                                           oninput="recalculateRowMargin(<?= $pid ?>)">
                                </td>

                                <!-- Champ Prix Vente -->
                                <td class="bg-success bg-opacity-10">
                                    <input type="number" step="1" min="0" 
                                           class="form-control form-control-sm fw-bold border-success input-sale" 
                                           name="prices[<?= $pid ?>][sale]" 
                                           value="<?= number_format($sale, 0, '', '') ?>" 
                                           data-pid="<?= $pid ?>" 
                                           oninput="recalculateRowMargin(<?= $pid ?>)">
                                </td>

                                <!-- Marge Brute en FCFA -->
                                <td class="text-end fw-extrabold cell-gross <?= $gross < 0 ? 'text-danger' : 'text-slate-800' ?>" id="gross-<?= $pid ?>">
                                    <?= number_format($gross, 0, '.', ' ') ?> F
                                </td>

                                <!-- Taux de Marge (%) -->
                                <td class="text-center cell-pct" id="pct-cell-<?= $pid ?>">
                                    <span class="badge <?= $pct < 5.0 ? 'bg-danger text-white' : ($pct < 15.0 ? 'bg-warning text-dark' : 'bg-success-subtle text-success') ?> px-2.5 py-1.5 fw-extrabold" id="pct-badge-<?= $pid ?>">
                                        <?= number_format($pct, 1) ?> %
                                    </span>
                                </td>

                                <!-- Qté Vendue -->
                                <td class="text-center">
                                    <?php if ($qty_sold > 0): ?>
                                        <span class="badge bg-dark text-white fw-bold px-2"><?= number_format($qty_sold, 0, '.', ' ') ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- CA Généré -->
                                <td class="text-end fw-semibold text-secondary small">
                                    <?= $ca_genere > 0 ? number_format($ca_genere, 0, '.', ' ') . ' F' : '<span class="text-muted">—</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Barre de Pagination Grille Tarifaire -->
            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                    <div class="small text-secondary fw-semibold">
                        Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($products)) ?> sur <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> article(s)
                    </div>
                    <nav aria-label="Pagination tarifs">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $query_params = $_GET;
                            if ($current_page > 1):
                                $query_params['page'] = $current_page - 1;
                                echo '<li class="page-item"><a class="page-link" href="product-prices.php?' . http_build_query($query_params) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                            else:
                                echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                            endif;

                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);

                            for ($p = $start_page; $p <= $end_page; $p++):
                                $query_params['page'] = $p;
                                $act = ($p == $current_page) ? 'active' : '';
                                echo '<li class="page-item ' . $act . '"><a class="page-link" href="product-prices.php?' . http_build_query($query_params) . '">' . $p . '</a></li>';
                            endfor;

                            if ($current_page < $total_pages):
                                $query_params['page'] = $current_page + 1;
                                echo '<li class="page-item"><a class="page-link" href="product-prices.php?' . http_build_query($query_params) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                            else:
                                echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                            endif;
                            ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-success fw-bold px-5 py-2.5 shadow" style="border-radius: 10px;">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Sauvegarder Tous les Prix Enregistrés
                </button>
            </div>
        <?php endif; ?>
    </div>
</form>

<script>
function recalculateRowMargin(pid) {
    const row = document.getElementById('row-' + pid);
    if (!row) return;

    const purchaseInput = row.querySelector('.input-purchase');
    const saleInput = row.querySelector('.input-sale');

    const purchase = parseFloat(purchaseInput.value) || 0;
    const sale = parseFloat(saleInput.value) || 0;

    const gross = sale - purchase;
    let pct = 0;
    if (sale > 0) {
        pct = ((gross / sale) * 100).toFixed(1);
    }

    // Mise à jour Marge Brute FCFA
    const grossCell = document.getElementById('gross-' + pid);
    if (grossCell) {
        grossCell.textContent = new Intl.NumberFormat('fr-FR').format(gross) + ' F';
        if (gross < 0) {
            grossCell.className = 'text-end fw-extrabold cell-gross text-danger';
        } else {
            grossCell.className = 'text-end fw-extrabold cell-gross text-slate-800';
        }
    }

    // Mise à jour Badge Taux de Marge %
    const badge = document.getElementById('pct-badge-' + pid);
    if (badge) {
        badge.textContent = pct + ' %';
        if (pct < 5.0) {
            badge.className = 'badge bg-danger text-white px-2.5 py-1.5 fw-extrabold';
        } else if (pct < 15.0) {
            badge.className = 'badge bg-warning text-dark px-2.5 py-1.5 fw-extrabold';
        } else {
            badge.className = 'badge bg-success-subtle text-success px-2.5 py-1.5 fw-extrabold';
        }
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
