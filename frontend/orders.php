<?php
require_once __DIR__ . '/includes/header.php';

$active_shop_id = $_SESSION['active_shop_id'];
$user_role = $_SESSION['user_role'];
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$seller_view = isset($_GET['view']) && $_GET['view'] === 'all' ? 'all' : 'mine';

// Sécurité : Un vendeur ne peut voir que les Ventes
if ($user_role !== 'admin') {
    $type_filter = 'SALE';
}

$error_msg = '';
$success_msg = '';

if (isset($_SESSION['success_message'])) {
    $success_msg = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_msg = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Traitement de l'approbation/rejet en POST (Admin uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'admin') {
    $order_id = isset($_POST['action_order_id']) ? intval($_POST['action_order_id']) : 0;
    
    if (isset($_POST['approve_order']) && $order_id > 0) {
        try {
            $pdo->beginTransaction();
            
            // Mettre à jour le statut (le stock est déjà déduit/réservé lors de la création)
            $stmt = $pdo->prepare("UPDATE orders SET status = 'COMPLETED' WHERE id = ? AND status = 'PENDING_APPROVAL'");
            $stmt->execute([$order_id]);

            // Récupérer les détails de la commande pour insérer dans cash_transactions
            $stmt_o = $pdo->prepare("SELECT type, shop_id, payment_method, total_amount, amount_paid, user_id FROM orders WHERE id = ?");
            $stmt_o->execute([$order_id]);
            $o_data = $stmt_o->fetch();
            
            if ($o_data) {
                if ($o_data['type'] === 'SALE' && $o_data['payment_method'] === 'CASH') {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER')
                    ");
                    $stmt_cash->execute([
                        $o_data['shop_id'],
                        $o_data['user_id'],
                        $o_data['total_amount'],
                        "Vente en espèces " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $order_id))),
                        $order_id
                    ]);
                } elseif ($o_data['type'] === 'SALE' && $o_data['payment_method'] === 'CREDIT' && $o_data['amount_paid'] > 0) {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER')
                    ");
                    $stmt_cash->execute([
                        $o_data['shop_id'],
                        $o_data['user_id'],
                        $o_data['amount_paid'],
                        "Acompte en espèces sur vente à crédit " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $order_id))),
                        $order_id
                    ]);
                }
            }
            
            $pdo->commit();
            $success_msg = "Commande " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $order_id))) . " validée avec succès (le stock était déjà réservé et la caisse mise à jour).";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Erreur de validation : " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_order']) && $order_id > 0) {
        try {
            $pdo->beginTransaction();
            
            // 1. Mettre à jour le statut à REJECTED
            $stmt = $pdo->prepare("UPDATE orders SET status = 'REJECTED' WHERE id = ? AND status = 'PENDING_APPROVAL'");
            $stmt->execute([$order_id]);
            
            // 2. Restituer le stock (car il avait été déduit et réservé à la création de la commande)
            $stmt_o = $pdo->prepare("SELECT shop_id FROM orders WHERE id = ?");
            $stmt_o->execute([$order_id]);
            $o_data = $stmt_o->fetch();
            
            if ($o_data) {
                $stmt_items_db = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmt_items_db->execute([$order_id]);
                $order_items = $stmt_items_db->fetchAll();
                
                $stmt_update_stock = $pdo->prepare("
                    INSERT INTO product_stock (product_id, shop_id, quantity) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ");
                
                foreach ($order_items as $item) {
                    // C'est une vente rejetée, donc on rajoute la quantité au stock (changement positif)
                    $stock_change = $item['quantity'];
                    $stmt_update_stock->execute([
                        $item['product_id'],
                        $o_data['shop_id'],
                        $stock_change,
                        $stock_change
                    ]);
                }
            }
            
            $pdo->commit();
            $success_msg = "Commande " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $order_id))) . " rejetée. Le stock a été restitué avec succès.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Erreur de rejet : " . $e->getMessage();
        }
    }
}

// Récupération des filtres avancés
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : '';
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : '';
$payment_filter = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$shop_filter = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : intval($_SESSION['active_shop_id'] ?? 0);
$client_filter = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Charger la liste des clients pour le dropdown
$clients_list = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Construction de la requête
$sql = "
    SELECT o.*, 
           c.name as client_name, 
           sup.name as supplier_name,
           sh.name as shop_name,
           u.name as user_name
    FROM orders o
    JOIN shops sh ON o.shop_id = sh.id
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN suppliers sup ON o.supplier_id = sup.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE 1=1
";

$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$params = [];

if ($user_role !== 'admin') {
    $sql .= " AND o.shop_id = :shop_id";
    $params[':shop_id'] = $active_shop_id;
    $sql .= " AND o.type = 'SALE'";
    if ($seller_view === 'mine') {
        $sql .= " AND o.user_id = :user_id";
        $params[':user_id'] = $_SESSION['user_id'];
    }
} else {
    if ($shop_filter > 0) {
        $sql .= " AND o.shop_id = :shop_id";
        $params[':shop_id'] = $shop_filter;
    }
    if ($type_filter === 'SALE' || $type_filter === 'PURCHASE') {
        $sql .= " AND o.type = :type";
        $params[':type'] = $type_filter;
    }
    if ($user_filter > 0) {
        $sql .= " AND o.user_id = :user_filter";
        $params[':user_filter'] = $user_filter;
    }
}

// Filtre par client
if ($client_filter > 0) {
    $sql .= " AND o.client_id = :client_id";
    $params[':client_id'] = $client_filter;
}

// Application des filtres avancés
if ($search !== '') {
    $sql .= " AND (c.name LIKE :search OR sup.name LIKE :search OR o.id LIKE :search_id)";
    $params[':search'] = '%' . $search . '%';
    $params[':search_id'] = '%' . intval(str_replace('FAC-', '', $search)) . '%';
}

if ($date_start !== '') {
    $sql .= " AND DATE(o.created_at) >= :date_start";
    $params[':date_start'] = $date_start;
}

if ($date_end !== '') {
    $sql .= " AND DATE(o.created_at) <= :date_end";
    $params[':date_end'] = $date_end;
}

if ($payment_filter !== '') {
    $sql .= " AND o.payment_method = :payment_method";
    $params[':payment_method'] = $payment_filter;
}

if ($status_filter !== '') {
    $sql .= " AND o.status = :status";
    $params[':status'] = $status_filter;
}

// 1. Compter le nombre total de lignes correspondant aux filtres
$count_sql = "
    SELECT COUNT(o.id) as total_count 
    FROM orders o
    JOIN shops sh ON o.shop_id = sh.id
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN suppliers sup ON o.supplier_id = sup.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE 1=1
" . substr($sql, strpos($sql, "WHERE 1=1") + 9);

$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = intval($stmt_count->fetchColumn() ?: 0);

// Configuration de la pagination
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 40;
$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

$sql .= " ORDER BY o.created_at DESC LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Synthèse globale du rapport pour toute la période filtrée (Agrégation SQL exacte)
$sum_sql = "
    SELECT 
        COUNT(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' THEN 1 END) as sales_count,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' THEN o.total_amount ELSE 0 END), 0) as sales_total,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'PURCHASE' THEN o.total_amount ELSE 0 END), 0) as purchases_total,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' AND o.payment_method = 'CASH' THEN o.total_amount ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' AND o.payment_method = 'MOBILE_MONEY' THEN o.total_amount ELSE 0 END), 0) as momo_total,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' AND o.payment_method = 'CARD' THEN o.total_amount ELSE 0 END), 0) as card_total,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' AND o.payment_method = 'CREDIT' THEN o.total_amount ELSE 0 END), 0) as credit_total,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' THEN (CASE WHEN o.payment_method = 'CREDIT' THEN o.amount_paid ELSE o.total_amount END) ELSE 0 END), 0) as total_encaisse,
        COALESCE(SUM(CASE WHEN o.status = 'COMPLETED' AND o.type = 'SALE' AND o.payment_method = 'CREDIT' THEN (o.total_amount - o.amount_paid) ELSE 0 END), 0) as total_credit_restant
    FROM orders o
    JOIN shops sh ON o.shop_id = sh.id
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN suppliers sup ON o.supplier_id = sup.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE 1=1 " . substr($count_sql, strpos($count_sql, "WHERE 1=1") + 9);

$stmt_sum = $pdo->prepare($sum_sql);
$stmt_sum->execute($params);
$sum_res = $stmt_sum->fetch(PDO::FETCH_ASSOC);

$report_sales_total = floatval($sum_res['sales_total'] ?? 0);
$report_purchases_total = floatval($sum_res['purchases_total'] ?? 0);
$report_total_encaisse = floatval($sum_res['total_encaisse'] ?? 0);
$report_credit_restant = floatval($sum_res['total_credit_restant'] ?? 0);
$report_sales_count = intval($sum_res['sales_count'] ?? 0);
$report_payment_methods = [
    'CASH' => floatval($sum_res['cash_total'] ?? 0),
    'MOBILE_MONEY' => floatval($sum_res['momo_total'] ?? 0),
    'CARD' => floatval($sum_res['card_total'] ?? 0),
    'CREDIT' => floatval($sum_res['credit_total'] ?? 0)
];

// Récupération optimisée des articles et quantités pour les commandes affichées sur la page
$order_items_map = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $items_stmt = $pdo->prepare("
        SELECT oi.order_id, oi.quantity, oi.unit_price, oi.total_price, COALESCE(p.name, 'Article inconnu') as product_name, p.unit
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.id ASC
    ");
    $items_stmt->execute($order_ids);
    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $order_items_map[$item['order_id']][] = $item;
    }
}

// Synthèse par vendeur sur l'ensemble de la période filtrée
$sellers_sql = "
    SELECT COALESCE(u.name, 'Inconnu') as seller_name, SUM(o.total_amount) as total_sold
    FROM orders o
    JOIN shops sh ON o.shop_id = sh.id
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN suppliers sup ON o.supplier_id = sup.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE 1=1 " . substr($count_sql, strpos($count_sql, "WHERE 1=1") + 9) . " 
      AND o.status = 'COMPLETED' AND o.type = 'SALE'
    GROUP BY u.name
";
$stmt_sell = $pdo->prepare($sellers_sql);
$stmt_sell->execute($params);
$report_sellers = $stmt_sell->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

// Regroupement par date et calculs journaliers
$orders_grouped = [];
foreach ($orders as $ord) {
    $date_key = date('Y-m-d', strtotime($ord['created_at']));
    if (!isset($orders_grouped[$date_key])) {
        $orders_grouped[$date_key] = [
            'sales_total' => 0,
            'purchases_total' => 0,
            'items' => []
        ];
    }
    
    if ($ord['status'] === 'COMPLETED') {
        if ($ord['type'] === 'SALE') {
            $orders_grouped[$date_key]['sales_total'] += $ord['total_amount'];
        } else {
            $orders_grouped[$date_key]['purchases_total'] += $ord['total_amount'];
        }
    }
    
    $orders_grouped[$date_key]['items'][] = $ord;
}

// Formatteur de date en français
function format_french_date($date_str) {
    $timestamp = strtotime($date_str);
    $days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $months = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    
    $day_name = $days[date('w', $timestamp)];
    $day_num = date('d', $timestamp);
    $month_name = $months[intval(date('n', $timestamp))];
    $year = date('Y', $timestamp);
    
    return "$day_name $day_num $month_name $year";
}
?>

<!-- Messages d'alerte -->
<?php if ($success_msg): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= $error_msg ?>
    </div>
<?php endif; ?>

<?php
// Détermination du nom de boutique affiché (utilisé à l'écran et pour l'impression)
$displayed_shop_name = ($shop_filter === 0) ? '🌐 Tous les dépôts (Global)' : $current_shop['name'];
if ($shop_filter > 0) {
    foreach ($all_shops as $s) {
        if ($s['id'] == $shop_filter) {
            $displayed_shop_name = $s['name'];
            break;
        }
    }
}

// Construction de la chaîne de paramètres filtrés (pour impression complète et export Excel, sans pagination)
$export_params = [
    'shop_id' => $shop_filter,
];
if (!empty($type_filter)) $export_params['type'] = $type_filter;
if ($user_filter > 0) $export_params['user_id'] = $user_filter;
if ($client_filter > 0) $export_params['client_id'] = $client_filter;
if (!empty($search)) $export_params['search'] = $search;
if (!empty($date_start)) $export_params['date_start'] = $date_start;
if (!empty($date_end)) $export_params['date_end'] = $date_end;
if (!empty($payment_filter)) $export_params['payment_method'] = $payment_filter;
if (!empty($status_filter)) $export_params['status'] = $status_filter;
if ($user_role !== 'admin' && !empty($seller_view)) $export_params['view'] = $seller_view;

$filter_qs = http_build_query($export_params);
?>

<!-- En-tête exclusif pour l'impression -->
<div class="d-none d-print-block mb-4 text-center">
    <h2 class="fw-bold text-uppercase mb-1" style="font-size: 16pt; letter-spacing: 0.5px;">Rapport d'Activité & Flux de Caisse</h2>
    <h4 class="mb-2" style="font-size: 12pt; font-weight: 700; color: #1e293b;">
        Dépôt / Boutique : <span class="text-primary"><?= htmlspecialchars($displayed_shop_name) ?></span>
    </h4>
    
    <div class="d-flex justify-content-center gap-4 small text-secondary mb-2" style="font-size: 9.5pt;">
        <?php if ($user_filter > 0): 
            $stmt_u = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $stmt_u->execute([$user_filter]);
            $filtered_user_name = $stmt_u->fetchColumn();
        ?>
            <span><strong>Vendeur :</strong> <?= htmlspecialchars($filtered_user_name) ?></span>
        <?php elseif ($user_role !== 'admin' && $seller_view === 'mine'): ?>
            <span><strong>Vendeur :</strong> <?= htmlspecialchars($_SESSION['user_name']) ?></span>
        <?php else: ?>
            <span><strong>Vendeur :</strong> Tous les vendeurs</span>
        <?php endif; ?>
        
        <span>
            <strong>Période :</strong> 
            <?php 
            if ($date_start && $date_end) {
                echo date('d/m/Y', strtotime($date_start)) . " au " . date('d/m/Y', strtotime($date_end));
            } elseif ($date_start) {
                echo "Depuis le " . date('d/m/Y', strtotime($date_start));
            } elseif ($date_end) {
                echo "Jusqu'au " . date('d/m/Y', strtotime($date_end));
            } else {
                echo "Tout l'historique";
            }
            ?>
        </span>
        
        <span><strong>Généré le :</strong> <?= date('d/m/Y à H:i') ?></span>
    </div>
    <div style="border-top: 2px solid #0f172a; margin-top: 10px; margin-bottom: 25px;"></div>
</div>

<!-- Title (Screen only) -->
<div class="row mb-3 no-print">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-file-invoice-dollar"></i></span> 
            Historique des Flux (Achats & Ventes)
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap no-print">
            <span class="text-secondary small fw-semibold">Dépôt : <span class="badge bg-light text-dark border"><?= htmlspecialchars($displayed_shop_name) ?></span></span>
            <a href="print-orders.php?<?= $filter_qs ?>" target="_blank" class="btn btn-outline-secondary btn-sm fw-semibold" style="border-radius: 8px;" title="Imprimer tout le journal complet selon les filtres (toutes les transactions)">
                <i class="fa-solid fa-print me-1"></i>Imprimer Tout
            </a>
            <a href="export-orders-excel.php?<?= $filter_qs ?>" class="btn btn-outline-success btn-sm fw-bold shadow-sm" style="border-radius: 8px;" title="Exporter toutes les transactions filtrées au format Excel (.xlsx)">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel (.xlsx)
            </a>
            <a href="order-create.php?type=SALE" class="btn btn-grad btn-sm">
                <i class="fa-solid fa-cart-shopping me-1"></i>Vente
            </a>
            <?php if ($user_role === 'admin'): ?>
                <a href="order-create.php?type=PURCHASE" class="btn btn-accent btn-sm">
                    <i class="fa-solid fa-download me-1"></i>Achat
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($user_role === 'admin'): ?>
<!-- Tabs to Filter -->
<div class="row mb-3 no-print">
    <div class="col-12">
        <div class="glass-card p-3 d-flex gap-2">
            <a href="orders.php" class="btn btn-sm <?= empty($type_filter) ? 'btn-primary' : 'btn-light' ?>" style="border-radius: 8px;">
                Tous les flux
            </a>
            <a href="orders.php?type=SALE" class="btn btn-sm <?= $type_filter === 'SALE' ? 'btn-success text-white' : 'btn-light' ?>" style="border-radius: 8px;">
                <i class="fa-solid fa-cart-shopping me-1"></i>Ventes
            </a>
            <a href="orders.php?type=PURCHASE" class="btn btn-sm <?= $type_filter === 'PURCHASE' ? 'btn-info text-white' : 'btn-light' ?>" style="border-radius: 8px;">
                <i class="fa-solid fa-download me-1"></i>Achats
            </a>
            <a href="orders.php?type=PRODUCTION" class="btn btn-sm <?= $type_filter === 'PRODUCTION' ? 'btn-primary text-white' : 'btn-light' ?>" style="border-radius: 8px;">
                <i class="fa-solid fa-industry me-1"></i>Productions (Usine)
            </a>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Tabs to Filter for Seller -->
<div class="row mb-3 no-print">
    <div class="col-12">
        <div class="glass-card p-3 d-flex gap-2">
            <a href="orders.php?view=mine" class="btn btn-sm <?= $seller_view === 'mine' ? 'btn-success text-white' : 'btn-light' ?>" style="border-radius: 8px;">
                <i class="fa-solid fa-user me-1"></i>Mes ventes uniquement
            </a>
            <a href="orders.php?view=all" class="btn btn-sm <?= $seller_view === 'all' ? 'btn-primary' : 'btn-light' ?>" style="border-radius: 8px;">
                <i class="fa-solid fa-store me-1"></i>Toutes les ventes du dépôt
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Barre d'actions & Filtres Rapides (Journal) -->
<div class="glass-card px-3 py-2 mb-3 no-print d-flex justify-content-between align-items-center flex-wrap gap-2">
    <!-- Périodes prédéfinies / Génération de Rapports -->
    <div class="d-flex flex-wrap gap-1 align-items-center">
        <span class="text-secondary small fw-bold me-1"><i class="fa-solid fa-clock-rotate-left me-1"></i>Périodes :</span>
        <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1 fw-semibold" onclick="setPeriodFilter('day')" style="border-radius: 6px; font-size: 0.8rem;">
            Aujourd'hui
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1 fw-semibold" onclick="setPeriodFilter('week')" style="border-radius: 6px; font-size: 0.8rem;">
            Cette Semaine
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1 fw-semibold" onclick="setPeriodFilter('month')" style="border-radius: 6px; font-size: 0.8rem;">
            Ce Mois
        </button>
    </div>

    <!-- Boutons d'affichage des filtres et du rapport détaillé -->
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-outline-secondary fw-semibold px-3 py-1" type="button" data-bs-toggle="collapse" data-bs-target="#advancedSearchCollapse" aria-expanded="false" aria-controls="advancedSearchCollapse" style="border-radius: 8px;">
            <i class="fa-solid fa-filter me-1"></i>Recherche / Filtres <i class="fa-solid fa-chevron-down ms-1 small"></i>
        </button>
        <button class="btn btn-sm btn-outline-info fw-semibold px-2 py-1" type="button" data-bs-toggle="collapse" data-bs-target="#detailedReportCollapse" aria-expanded="false" aria-controls="detailedReportCollapse" style="border-radius: 8px;" title="Afficher/Masquer le détail complet des règlements et performances">
            <i class="fa-solid fa-chart-pie me-1"></i>Détails Règlements
        </button>
    </div>
</div>

<!-- Formulaire de filtres avancés (Masqué par défaut dans un collapse) -->
<div class="collapse mb-3 no-print <?= (!empty($search) || !empty($date_start) || !empty($date_end) || !empty($payment_filter) || !empty($status_filter) || $client_filter > 0 || $user_filter > 0 || ($shop_filter > 0 && $user_role === 'admin')) ? 'show' : '' ?>" id="advancedSearchCollapse">
    <div class="glass-card p-3">
        <form method="GET" action="orders.php" class="row g-2">
            <?php if ($user_role === 'admin' && $type_filter): ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">
            <?php elseif ($user_role !== 'admin'): ?>
                <input type="hidden" name="view" value="<?= htmlspecialchars($seller_view) ?>">
            <?php endif; ?>

            <div class="col-12 col-md-2">
                <label for="search" class="form-label small fw-semibold text-secondary mb-1">Recherche</label>
                <input type="text" class="form-control form-control-sm form-control-custom" id="search" name="search" placeholder="N° FAC, Contact..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <?php if ($user_role === 'admin'): ?>
            <div class="col-12 col-sm-6 col-md-2">
                <label for="shop_id" class="form-label small fw-semibold text-secondary mb-1">Dépôt / Boutique</label>
                <select class="form-select form-select-sm form-control-custom" id="shop_id" name="shop_id">
                    <option value="0" <?= $shop_filter === 0 ? 'selected' : '' ?>>🌐 Tous les dépôts</option>
                    <?php foreach ($all_shops as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $shop_filter === $sh['id'] ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($sh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label for="user_id" class="form-label small fw-semibold text-secondary mb-1">Vendeur / Opérateur</label>
                <select class="form-select form-select-sm form-control-custom" id="user_id" name="user_id">
                    <option value="">Tous les vendeurs</option>
                    <?php 
                    $sellers_list = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();
                    foreach ($sellers_list as $sel): ?>
                        <option value="<?= $sel['id'] ?>" <?= $user_filter === $sel['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Filtre Client (visible admin et vendeur) -->
            <div class="col-12 col-sm-6 col-md-2">
                <label for="client_id" class="form-label small fw-semibold text-secondary mb-1"><i class="fa-solid fa-user-tie me-1 text-primary"></i>Client</label>
                <select class="form-select form-select-sm form-control-custom" id="client_id" name="client_id">
                    <option value="0">Tous les clients</option>
                    <?php foreach ($clients_list as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= $client_filter === $cl['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cl['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label for="date_start" class="form-label small fw-semibold text-secondary mb-1">Du (Début)</label>
                <input type="date" class="form-control form-control-sm form-control-custom" id="date_start" name="date_start" value="<?= htmlspecialchars($date_start) ?>">
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label for="date_end" class="form-label small fw-semibold text-secondary mb-1">Au (Fin)</label>
                <input type="date" class="form-control form-control-sm form-control-custom" id="date_end" name="date_end" value="<?= htmlspecialchars($date_end) ?>">
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label for="filter_month" class="form-label small fw-semibold text-secondary mb-1">Par mois précis</label>
                <input type="month" class="form-control form-control-sm form-control-custom" id="filter_month" name="filter_month" value="<?= isset($_GET['filter_month']) ? htmlspecialchars($_GET['filter_month']) : '' ?>" onchange="submitMonthFilter(this.value)">
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label for="payment_method" class="form-label small fw-semibold text-secondary mb-1">Règlement</label>
                <select class="form-select form-select-sm form-control-custom" id="payment_method" name="payment_method">
                    <option value="">Tous</option>
                    <option value="CASH" <?= $payment_filter === 'CASH' ? 'selected' : '' ?>>Espèces</option>
                    <option value="MOBILE_MONEY" <?= $payment_filter === 'MOBILE_MONEY' ? 'selected' : '' ?>>Mobile Money</option>
                    <option value="CARD" <?= $payment_filter === 'CARD' ? 'selected' : '' ?>>Carte Bancaire</option>
                    <option value="CREDIT" <?= $payment_filter === 'CREDIT' ? 'selected' : '' ?>>Crédit / Dette</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-1">
                <label for="status" class="form-label small fw-semibold text-secondary mb-1">Statut</label>
                <select class="form-select form-select-sm form-control-custom px-1" id="status" name="status">
                    <option value="">Tous</option>
                    <option value="COMPLETED" <?= $status_filter === 'COMPLETED' ? 'selected' : '' ?>>Validés</option>
                    <option value="PENDING_APPROVAL" <?= $status_filter === 'PENDING_APPROVAL' ? 'selected' : '' ?>>En attente</option>
                    <option value="REJECTED" <?= $status_filter === 'REJECTED' ? 'selected' : '' ?>>Rejetés</option>
                </select>
            </div>

            <div class="col-12 d-flex align-items-center gap-2 mt-2">
                <button type="submit" class="btn btn-grad btn-sm fw-bold px-3 py-1.5" style="border-radius: 8px;">
                    <i class="fa-solid fa-filter me-1"></i>Appliquer
                </button>
                <a href="orders.php<?= $user_role === 'admin' && $type_filter ? '?type=' . $type_filter : '' ?><?= $user_role !== 'admin' ? '?view=' . $seller_view : '' ?>" class="btn btn-outline-secondary btn-sm fw-semibold px-3 py-1.5" style="border-radius: 8px;">
                    <i class="fa-solid fa-rotate-left me-1"></i>Effacer
                </a>
            </div>
        </form>
    </div>
</div>

<?php
// Calculs pour la synthèse sur 1 ligne (calculs globaux cohérents avec la période filtrée)
$nb_ventes = $report_sales_count;
$total_encaisse = $report_total_encaisse;  // cash + momo + card + acomptes sur ventes
$total_credit = $report_credit_restant;   // crédits non encore soldés
$flow_rate = ($report_purchases_total > 0) ? min(100, round(($report_sales_total / $report_purchases_total) * 100)) : 100;
?>

<!-- BANDEAU SYNTHÈSE STATISTIQUES COMPACT (1 SEULE LIGNE) -->
<div class="glass-card px-3 py-2 mb-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 text-nowrap" style="font-size: 0.83rem;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary p-1.5 rounded-2"><i class="fa-solid fa-receipt"></i></span>
            <span class="text-secondary small">Factures:</span>
            <span class="fw-bold text-slate-800"><?= $nb_ventes ?></span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-success-subtle text-success p-1.5 rounded-2"><i class="fa-solid fa-arrow-trend-up"></i></span>
            <span class="text-secondary small">Ventes Réalisées:</span>
            <span class="fw-bold text-success"><?= number_format($report_sales_total, 0, '.', ' ') ?> F</span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-success-subtle text-success p-1.5 rounded-2"><i class="fa-solid fa-coins"></i></span>
            <span class="text-secondary small">Encaissé:</span>
            <span class="fw-bold text-success"><?= number_format($total_encaisse, 0, '.', ' ') ?> F</span>
        </div>
        <?php if ($total_credit > 0): ?>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-warning-subtle text-warning-emphasis p-1.5 rounded-2"><i class="fa-solid fa-hand-holding-dollar"></i></span>
            <span class="text-secondary small">En cours de règlement:</span>
            <span class="fw-bold text-slate-700"><?= number_format($total_credit, 0, '.', ' ') ?> F</span>
        </div>
        <?php endif; ?>
        <?php if ($user_role === 'admin'): ?>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary p-1.5 rounded-2"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="text-secondary small">Stock Approvisionné:</span>
            <span class="fw-bold text-primary"><?= number_format($report_purchases_total, 0, '.', ' ') ?> F</span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-info-subtle text-info p-1.5 rounded-2"><i class="fa-solid fa-chart-line"></i></span>
            <span class="text-secondary small">Progression écoulement:</span>
            <span class="fw-bold text-primary"><?= $flow_rate ?>%</span>
            <span class="badge bg-light text-success border small" style="font-size: 0.72rem;"><i class="fa-solid fa-arrow-up me-1"></i>En rotation</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Détails avancés des règlements et performances (Masqué par défaut, affichable sur demande) -->
<div class="collapse mb-3 no-print" id="detailedReportCollapse">
    <div class="row g-3">
        <!-- Règlements -->
        <div class="col-12 col-md-6">
            <div class="glass-card p-3 bg-white h-100">
                <h6 class="fw-bold mb-2 text-slate-800 small"><i class="fa-solid fa-wallet text-secondary me-1"></i>Répartition des Règlements</h6>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="p-2 border rounded text-center bg-light">
                            <div class="text-secondary small" style="font-size: 0.75rem;">Espèces</div>
                            <div class="fw-bold text-dark small"><?= number_format($report_payment_methods['CASH'] ?? 0, 0, '.', ' ') ?> F</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 border rounded text-center bg-light">
                            <div class="text-secondary small" style="font-size: 0.75rem;">Mobile Money</div>
                            <div class="fw-bold text-dark small"><?= number_format($report_payment_methods['MOBILE_MONEY'] ?? 0, 0, '.', ' ') ?> F</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 border rounded text-center bg-light">
                            <div class="text-secondary small" style="font-size: 0.75rem;">Carte / Virement</div>
                            <div class="fw-bold text-dark small"><?= number_format($report_payment_methods['CARD'] ?? 0, 0, '.', ' ') ?> F</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 border rounded text-center bg-warning-subtle border-warning-subtle">
                            <div class="text-secondary small" style="font-size: 0.75rem;">Ventes à Crédit</div>
                            <div class="fw-bold text-dark small"><?= number_format($report_payment_methods['CREDIT'] ?? 0, 0, '.', ' ') ?> F</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vendeurs -->
        <div class="col-12 col-md-6">
            <div class="glass-card p-3 bg-white h-100">
                <h6 class="fw-bold mb-2 text-slate-800 small"><i class="fa-solid fa-users text-warning me-1"></i>Performance par Vendeur</h6>
                <?php if (empty($report_sellers)): ?>
                    <div class="text-center py-2 text-secondary small">Aucune vente enregistrée.</div>
                <?php else: ?>
                    <div style="max-height: 105px; overflow-y: auto;">
                        <?php foreach ($report_sellers as $seller_name => $total_sold): ?>
                            <div class="d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                                <span class="small fw-semibold text-slate-700"><?= htmlspecialchars($seller_name) ?></span>
                                <span class="badge bg-primary-subtle text-primary fw-bold"><?= number_format($total_sold, 0, '.', ' ') ?> F</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Orders Table -->
<div class="row">
    <div class="col-12">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-receipt text-primary me-2"></i>Factures Enregistrées</h5>
                    <span class="badge bg-light text-dark border py-1.5 px-2.5 small fw-semibold">
                        <i class="fa-solid fa-store text-primary me-1"></i><?= htmlspecialchars($displayed_shop_name) ?>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap no-print">
                    <a href="export-orders-excel.php?<?= $filter_qs ?>" class="btn btn-outline-success btn-sm fw-bold shadow-sm" title="Télécharger tout le journal filtré en Excel (.xlsx)">
                        <i class="fa-solid fa-file-excel me-1"></i>Excel (.xlsx)
                    </a>
                    <a href="print-orders.php?<?= $filter_qs ?>" target="_blank" class="btn btn-outline-secondary btn-sm fw-semibold shadow-sm" title="Imprimer tout le journal filtré (toutes les transactions)">
                        <i class="fa-solid fa-print me-1"></i>Imprimer Tout
                    </a>
                    <span class="badge bg-secondary-subtle text-secondary py-2 px-3 fw-bold rounded-pill">
                        <?= $total_records ?> transaction(s) au total
                    </span>
                </div>
            </div>

            <?php if (empty($orders_grouped)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="fa-regular fa-folder-open fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">Aucune transaction enregistrée pour ces critères de recherche.</p>
                </div>
            <?php else: ?>
                <?php foreach ($orders_grouped as $date => $group): ?>
                    <div class="mb-5">
                        <!-- Journée Header -->
                        <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-3 mb-3 border flex-wrap gap-2">
                            <h6 class="fw-bold text-slate-800 mb-0">
                                <i class="fa-solid fa-calendar-day text-primary me-2"></i>
                                <?= date('Y-m-d') === $date ? "<span class='badge bg-danger me-2'>Aujourd'hui</span>" : '' ?>
                                <?= date('Y-m-d', strtotime('-1 day')) === $date ? "<span class='badge bg-secondary me-2'>Hier</span>" : '' ?>
                                <?= format_french_date($date) ?>
                            </h6>
                            <div class="small fw-semibold text-secondary">
                                <span class="text-success me-3"><i class="fa-solid fa-circle-arrow-up me-1"></i>Ventes : <strong><?= number_format($group['sales_total'], 0, '.', ' ') ?> FCFA</strong></span>
                                <?php if ($user_role === 'admin'): ?>
                                    <span class="text-primary"><i class="fa-solid fa-circle-arrow-down me-1"></i>Achats : <strong><?= number_format($group['purchases_total'], 0, '.', ' ') ?> FCFA</strong></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-custom align-middle">
                                <thead>
                                    <tr>
                                        <th>N° Facture</th>
                                        <th>Type</th>
                                        <th>Opérateur</th>
                                        <th>Contact (Client/Fournisseur)</th>
                                        <th>Mode Paiement</th>
                                        <th style="min-width: 300px;">Articles (Qté & Prix Détail)</th>
                                        <th class="text-end">Montant Total</th>
                                        <th class="text-end no-print" style="width: 180px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['items'] as $ord): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-bold text-slate-900"><?= htmlspecialchars($ord['reference'] ?? 'FAC-'.sprintf("%05d", $ord['id'])) ?></span>
                                                <?php if ($shop_filter === 0 && !empty($ord['shop_name'])): ?>
                                                    <span class="badge bg-light text-secondary border small ms-1" style="font-size: 0.68rem;"><i class="fa-solid fa-store me-1"></i><?= htmlspecialchars($ord['shop_name']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($ord['status'] === 'PENDING_APPROVAL'): ?>
                                                    <span class="badge bg-warning text-dark small ms-1" style="font-size: 0.7rem;"><i class="fa-solid fa-clock me-1"></i>En attente</span>
                                                <?php elseif ($ord['status'] === 'REJECTED'): ?>
                                                    <span class="badge bg-danger px-1 py-0.5 small ms-1" style="font-size: 0.7rem;"><i class="fa-solid fa-circle-xmark me-1"></i>Rejeté</span>
                                                <?php endif; ?>
                                                <?php if (!empty($ord['discount_reason'])): ?>
                                                    <span class="d-block text-danger small mt-1" style="font-size: 0.72rem; font-weight: 600;"><i class="fa-solid fa-triangle-exclamation me-1"></i>Hors-Marge</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($ord['type'] == 'SALE'): ?>
                                                    <span class="badge-custom bg-success-subtle text-success"><i class="fa-solid fa-arrow-up-right me-1"></i>Vente</span>
                                                <?php elseif ($ord['type'] == 'PURCHASE'): ?>
                                                    <span class="badge-custom bg-info-subtle text-info"><i class="fa-solid fa-arrow-down-left me-1"></i>Achat</span>
                                                <?php else: ?>
                                                    <span class="badge-custom bg-primary-subtle text-primary"><i class="fa-solid fa-industry me-1"></i>Production</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($ord['user_name'] ?? 'Inconnu') ?></span>
                                            </td>
                                            <td>
                                                <div class="fw-bold">
                                                    <?php 
                                                    if ($ord['type'] == 'SALE') {
                                                        echo htmlspecialchars($ord['client_name'] ?? 'Client divers');
                                                    } elseif ($ord['type'] == 'PURCHASE') {
                                                        echo htmlspecialchars($ord['supplier_name'] ?? 'Fournisseur inconnu');
                                                    } else {
                                                        echo "<span class='text-secondary small'>Mandel Usine</span>";
                                                    }
                                                    ?>
                                                </div>
                                                <?php if (!empty($ord['discount_reason'])): ?>
                                                    <div class="small text-danger mt-1 italic" style="font-size: 0.76rem; max-width: 250px;">
                                                        <strong>Motif :</strong> <?= htmlspecialchars($ord['discount_reason']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($ord['payment_method']) ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($order_items_map[$ord['id']])): ?>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php foreach ($order_items_map[$ord['id']] as $item): ?>
                                                            <span class="badge bg-light text-dark border py-1 px-2 text-wrap text-start article-pill" style="font-size: 0.8rem; font-weight: 500; line-height: 1.35;">
                                                                <span class="fw-semibold text-slate-800"><?= htmlspecialchars($item['product_name']) ?></span>
                                                                <span class="badge bg-primary text-white ms-1 fw-bold"><?= $item['quantity'] ?></span>
                                                                <span class="text-secondary ms-1 fw-semibold" style="font-size: 0.76rem;">× <?= number_format($item['unit_price'], 0, '.', ' ') ?> F</span>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small fst-italic">Aucun article</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold text-slate-800">
                                                <?= number_format($ord['total_amount'], 0, '.', ' ') ?> FCFA
                                            </td>
                                            <td class="text-end no-print">
                                                <a href="invoice.php?id=<?= $ord['id'] ?>" class="btn btn-outline-primary btn-sm" style="border-radius: 8px;" title="Voir la facture">
                                                    <i class="fa-solid fa-eye"></i> Voir
                                                </a>
                                                
                                                <?php if ($user_role === 'admin'): ?>
                                                    <a href="order-edit.php?id=<?= $ord['id'] ?>" class="btn btn-outline-warning btn-sm ms-1" style="border-radius: 8px;" title="Modifier la facture">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                    <a href="order-delete.php?id=<?= $ord['id'] ?>" class="btn btn-outline-danger btn-sm ms-1" style="border-radius: 8px;" title="Supprimer la facture" onclick="return confirm('Êtes-vous sûr de vouloir supprimer définitivement la facture <?= htmlspecialchars($ord['reference'] ?? 'FAC-'.sprintf("%05d", $ord['id'])) ?> ?\nLes stocks et la caisse seront automatiquement réajustés.');">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($ord['status'] === 'PENDING_APPROVAL' && $user_role === 'admin'): ?>
                                                    <form action="orders.php" method="POST" class="d-inline ms-1" onsubmit="return confirm('Confirmer cette action ?');">
                                                        <input type="hidden" name="action_order_id" value="<?= $ord['id'] ?>">
                                                        <button type="submit" name="approve_order" class="btn btn-success btn-sm px-2" title="Valider" style="border-radius: 8px;">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                        <button type="submit" name="reject_order" class="btn btn-danger btn-sm px-2 ms-1" title="Rejeter" style="border-radius: 8px;">
                                                            <i class="fa-solid fa-xmark"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Barre de Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                        <div class="small text-secondary fw-semibold">
                            Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($orders)) ?> sur <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> facture(s)
                        </div>
                        <nav aria-label="Pagination commandes">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $query_params = $_GET;
                                // Lien Précédent
                                if ($current_page > 1):
                                    $query_params['page'] = $current_page - 1;
                                    echo '<li class="page-item"><a class="page-link" href="orders.php?' . http_build_query($query_params) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                endif;

                                // Numéros de pages avec ellipse
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);

                                if ($start_page > 1) {
                                    $query_params['page'] = 1;
                                    echo '<li class="page-item"><a class="page-link" href="orders.php?' . http_build_query($query_params) . '">1</a></li>';
                                    if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }

                                for ($p = $start_page; $p <= $end_page; $p++):
                                    $query_params['page'] = $p;
                                    $active_cls = ($p == $current_page) ? 'active' : '';
                                    echo '<li class="page-item ' . $active_cls . '"><a class="page-link" href="orders.php?' . http_build_query($query_params) . '">' . $p . '</a></li>';
                                endfor;

                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    $query_params['page'] = $total_pages;
                                    echo '<li class="page-item"><a class="page-link" href="orders.php?' . http_build_query($query_params) . '">' . $total_pages . '</a></li>';
                                }

                                // Lien Suivant
                                if ($current_page < $total_pages):
                                    $query_params['page'] = $current_page + 1;
                                    echo '<li class="page-item"><a class="page-link" href="orders.php?' . http_build_query($query_params) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                                endif;
                                ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function setPeriodFilter(period) {
    const today = new Date();
    let start = '';
    let end = formatDate(today);
    
    // Vider le filtre par mois précis
    const filterMonth = document.getElementById('filter_month');
    if (filterMonth) filterMonth.value = '';
    
    if (period === 'day') {
        start = formatDate(today);
    } else if (period === 'week') {
        // Lundi de cette semaine
        const day = today.getDay();
        const diff = today.getDate() - day + (day === 0 ? -6 : 1);
        const monday = new Date(today.setDate(diff));
        start = formatDate(monday);
    } else if (period === 'month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        start = formatDate(firstDay);
    }
    
    document.getElementById('date_start').value = start;
    document.getElementById('date_end').value = end;
    document.getElementById('date_start').form.submit();
}

function submitMonthFilter(monthValue) {
    if (!monthValue) return;
    const parts = monthValue.split('-');
    const year = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    
    // Premier jour du mois
    const start = `${parts[0]}-${parts[1]}-01`;
    // Dernier jour du mois
    const lastDay = new Date(year, month, 0).getDate();
    const end = `${parts[0]}-${parts[1]}-${lastDay < 10 ? '0' + lastDay : lastDay}`;
    
    document.getElementById('date_start').value = start;
    document.getElementById('date_end').value = end;
    document.getElementById('date_start').form.submit();
}

function formatDate(date) {
    const yyyy = date.getFullYear();
    let mm = date.getMonth() + 1;
    let dd = date.getDate();
    if (mm < 10) mm = '0' + mm;
    if (dd < 10) dd = '0' + dd;
    return `${yyyy}-${mm}-${dd}`;
}

document.addEventListener('DOMContentLoaded', function() {
    const dateStart = document.getElementById('date_start');
    const dateEnd = document.getElementById('date_end');
    const filterMonth = document.getElementById('filter_month');
    
    if (dateStart && dateEnd && filterMonth) {
        dateStart.addEventListener('input', () => filterMonth.value = '');
        dateEnd.addEventListener('input', () => filterMonth.value = '');
    }
});
</script>

<!-- Styles d'impression améliorés pour le rapport de flux -->
<style>
@media print {
    /* Configuration générale de la page d'impression */
    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-family: 'Outfit', 'Segoe UI', Arial, sans-serif !important;
        font-size: 10pt !important;
        padding: 0 !important;
        margin: 1cm !important;
    }
    
    /* Masquer le layout du site et les actions non imprimables */
    .no-print, 
    .navbar, 
    .navbar-custom,
    button,
    a.btn,
    form,
    .btn-outline-secondary {
        display: none !important;
    }
    
    /* Supprimer les marges et paddings excessifs du conteneur principal */
    .container-fluid {
        padding: 0 !important;
        margin: 0 !important;
    }
    
    /* Forcer la grille de synthèse à rester en colonnes côte-à-côte */
    #report-summary-row {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        gap: 15px !important;
        margin-bottom: 25px !important;
    }
    
    #report-summary-row > div {
        flex: 1 !important;
        width: 33.333% !important;
        max-width: 33.333% !important;
    }
    
    /* Supprimer le flou/ombrage et forcer les bordures sur les cards */
    .glass-card {
        background: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        box-shadow: none !important;
        border-radius: 8px !important;
        padding: 15px !important;
        page-break-inside: avoid;
    }
    
    /* Titre principal centré */
    h1 {
        font-size: 16pt !important;
        font-weight: 800 !important;
        text-align: center !important;
        margin-top: 0 !important;
        margin-bottom: 5px !important;
    }
    
    /* Sous-titre centré */
    p.text-secondary {
        text-align: center !important;
        margin-bottom: 25px !important;
        font-size: 9pt !important;
        color: #475569 !important;
    }
    
    /* Tableau propre et compact */
    .table-custom {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-top: 10px !important;
    }
    
    .table-custom th {
        background-color: #f1f5f9 !important;
        border-bottom: 2px solid #94a3b8 !important;
        color: #0f172a !important;
        font-weight: 700 !important;
        padding: 6px 8px !important;
        font-size: 8.5pt !important;
        text-transform: uppercase;
    }
    
    .table-custom td {
        border-bottom: 1px solid #e2e8f0 !important;
        padding: 6px 8px !important;
        font-size: 8.5pt !important;
    }
    
    /* Entête des journées (Mardi 28 Juillet...) */
    .d-flex.justify-content-between.align-items-center.bg-light {
        background-color: #f8fafc !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 6px !important;
        padding: 8px 12px !important;
        margin-top: 20px !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        page-break-after: avoid;
    }
    
    .d-flex.justify-content-between.align-items-center.bg-light h6 {
        margin: 0 !important;
        font-size: 9.5pt !important;
        font-weight: 700 !important;
    }
    
    /* Éviter les coupures de lignes au milieu d'une page */
    tr {
        page-break-inside: avoid !important;
    }
    
    /* Badges textuels simples */
    .badge, .badge-custom {
        background: none !important;
        border: 1px solid #94a3b8 !important;
        color: #000000 !important;
        font-weight: normal !important;
        padding: 2px 6px !important;
        border-radius: 4px !important;
        font-size: 8pt !important;
    }
    
    /* Articles et quantités propres et aérés à l'impression */
    .article-pill {
        display: inline-block !important;
        background: #f8fafc !important;
        border: 1px solid #cbd5e1 !important;
        padding: 1px 4px !important;
        margin: 1px 2px !important;
        border-radius: 3px !important;
        font-size: 8pt !important;
        white-space: nowrap !important;
    }
    .article-pill .badge {
        border: none !important;
        font-weight: 700 !important;
        padding: 0 2px !important;
        color: #0f172a !important;
    }
    
    /* Rétablir les couleurs pour l'imprimante (ou PDF) */
    .text-success, .text-success strong {
        color: #166534 !important;
    }
    
    .text-primary, .text-primary strong {
        color: #1e40af !important;
    }
    
    .text-danger, .text-danger strong {
        color: #991b1b !important;
    }
}
</style>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
