<?php
require_once __DIR__ . '/../backend/config/db.php';
require_once __DIR__ . '/includes/xlsx_helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$user_role = $_SESSION['user_role'] ?? 'seller';
$active_shop_id = intval($_SESSION['active_shop_id'] ?? 0);
$selected_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : $active_shop_id;
$category_filter = intval($_GET['category_id'] ?? 0);
$stock_status_filter = trim($_GET['stock_status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// Nom du magasin
$shop_name_display = "Tous les Dépôts";
if ($selected_shop_id > 0) {
    $sh_stmt = $pdo->prepare("SELECT name FROM shops WHERE id = ?");
    $sh_stmt->execute([$selected_shop_id]);
    $shop_name_display = $sh_stmt->fetchColumn() ?: "Boutique #$selected_shop_id";
}

$where_clauses = ["1=1"];
$params = [];

if ($selected_shop_id > 0) {
    $where_clauses[] = "ps.shop_id = :shop_id";
    $params[':shop_id'] = $selected_shop_id;
}

if ($category_filter > 0) {
    $where_clauses[] = "p.category_id = :cat_id";
    $params[':cat_id'] = $category_filter;
}

if (!empty($search)) {
    $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $t_idx = 0;
    foreach ($search_terms as $term) {
        $p_name = ":st_n_$t_idx";
        $p_sku  = ":st_s_$t_idx";
        $where_clauses[] = "(p.name LIKE $p_name OR p.sku LIKE $p_sku OR p.id LIKE $p_sku)";
        $params[$p_name] = '%' . $term . '%';
        $params[$p_sku]  = '%' . $term . '%';
        $t_idx++;
    }
}

$where_sql = implode(' AND ', $where_clauses);

if ($selected_shop_id === 0) {
    $sql = "
        SELECT 
            p.id, p.sku, p.name, p.unit, p.price_purchase, p.price_sale, p.min_stock_level,
            c.name as category_name,
            'Tous dépôts consolidés' as shop_name,
            COALESCE(SUM(ps.quantity), 0) as total_qty
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock ps ON p.id = ps.product_id
        WHERE $where_sql
        GROUP BY p.id
    ";
} else {
    $sql = "
        SELECT 
            p.id, p.sku, p.name, p.unit, p.price_purchase, p.price_sale, p.min_stock_level,
            c.name as category_name,
            sh.name as shop_name,
            COALESCE(ps.quantity, 0) as total_qty
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock ps ON p.id = ps.product_id
        LEFT JOIN shops sh ON ps.shop_id = sh.id
        WHERE $where_sql
    ";
}

// Filtre sur état de stock
if ($stock_status_filter === 'out') {
    $sql .= ($selected_shop_id === 0) ? " HAVING total_qty <= 0" : " AND COALESCE(ps.quantity, 0) <= 0";
} elseif ($stock_status_filter === 'low') {
    $sql .= ($selected_shop_id === 0) ? " HAVING total_qty > 0 AND total_qty <= p.min_stock_level" : " AND COALESCE(ps.quantity, 0) > 0 AND COALESCE(ps.quantity, 0) <= p.min_stock_level";
} elseif ($stock_status_filter === 'ok') {
    $sql .= ($selected_shop_id === 0) ? " HAVING total_qty > p.min_stock_level" : " AND COALESCE(ps.quantity, 0) > p.min_stock_level";
}

$sql .= " ORDER BY p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$headers = [
    'ID',
    'Code SKU',
    'Désignation Article',
    'Catégorie',
    'Dépôt',
    'Unité',
    'Quantité Stock',
    'Seuil Alerte',
    'Prix Achat Unit. (FCFA)',
    'Valeur Achat Totale',
    'Prix Vente Unit. (FCFA)',
    'Valeur Vente Totale',
    'Marge Potentielle',
    'Statut Stock'
];

$rows = [];
foreach ($items as $it) {
    $qty = intval($it['total_qty']);
    $min_lvl = intval($it['min_stock_level']);
    $p_buy = floatval($it['price_purchase']);
    $p_sale = floatval($it['price_sale']);

    $val_buy = $qty * $p_buy;
    $val_sale = $qty * $p_sale;
    $mrg_pot = $val_sale - $val_buy;

    $statut = 'OK (Normal)';
    if ($qty <= 0) {
        $statut = 'RUPTURE (0)';
    } elseif ($qty <= $min_lvl) {
        $statut = 'ALERTE STOCK FAIBLE';
    }

    $rows[] = [
        intval($it['id']),
        $it['sku'] ?? ('ART-' . sprintf("%04d", $it['id'])),
        $it['name'],
        $it['category_name'] ?? 'Général',
        $it['shop_name'] ?? $shop_name_display,
        $it['unit'] ?? 'UNITE',
        $qty,
        $min_lvl,
        $p_buy,
        $val_buy,
        $p_sale,
        $val_sale,
        $mrg_pot,
        $statut
    ];
}

$filename = "etat_stock_" . ($selected_shop_id > 0 ? "depot{$selected_shop_id}_" : "global_") . date('Y-m-d_Hi') . ".xlsx";
MandelExcelExporter::export($filename, "Etat_Stock", $headers, $rows);
