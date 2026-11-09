<?php
require_once __DIR__ . '/../backend/config/db.php';
require_once __DIR__ . '/includes/xlsx_helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$active_shop_id = intval($_SESSION['active_shop_id'] ?? 0);
$filter_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : $active_shop_id;
$search = trim($_GET['search'] ?? '');
$category_filter = intval($_GET['category_id'] ?? 0);
$stock_filter = trim($_GET['stock_filter'] ?? '');

$params = [];

if ($filter_shop_id === 0) {
    // Vue Globale (Tous les dépôts)
    $sql = "
        SELECT p.*, c.name as category_name, COALESCE(SUM(s.quantity), 0) as stock_qty 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock s ON p.id = s.product_id
        WHERE 1=1
    ";

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

    $sql .= " GROUP BY p.id";

    if ($stock_filter === 'low_stock') {
        $sql .= " HAVING stock_qty <= p.min_stock_level";
    } elseif ($stock_filter === 'out_of_stock') {
        $sql .= " HAVING stock_qty = 0";
    }

    $sql .= " ORDER BY p.name ASC";
} else {
    // Vue pour une Boutique spécifique
    $sql = "
        SELECT p.*, c.name as category_name, COALESCE(s.quantity, 0) as stock_qty 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = :active_shop_id
        WHERE 1=1
    ";
    $params[':active_shop_id'] = $filter_shop_id;

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

    if ($stock_filter === 'low_stock') {
        $sql .= " AND COALESCE(s.quantity, 0) <= p.min_stock_level";
    } elseif ($stock_filter === 'out_of_stock') {
        $sql .= " AND COALESCE(s.quantity, 0) = 0";
    }

    $sql .= " ORDER BY p.name ASC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Headers Excel
$headers = [
    'ID',
    'Code SKU',
    'Désignation Produit',
    'Catégorie',
    'Unité',
    'Prix Achat (FCFA)',
    'Prix Vente (FCFA)',
    'Marge (%)',
    'Stock Actuel',
    'Seuil Alerte',
    'Valeur Achat Stock',
    'Valeur Vente Stock',
    'Statut Stock'
];

$rows = [];
foreach ($products as $p) {
    $qty = intval($p['stock_qty']);
    $p_buy = floatval($p['price_purchase'] ?? 0);
    $p_sale = floatval($p['price_sale'] ?? 0);
    $min_lvl = intval($p['min_stock_level'] ?? 0);
    
    $val_buy = $qty * $p_buy;
    $val_sale = $qty * $p_sale;

    $status_str = 'Normal';
    if ($qty <= 0) {
        $status_str = 'Rupture';
    } elseif ($qty <= $min_lvl) {
        $status_str = 'Alerte Faible';
    }

    $margin_pct = ($p_sale > 0 && $p_buy > 0) ? round((($p_sale - $p_buy) / $p_sale) * 100, 1) : floatval($p['margin_percent'] ?? 0);

    $rows[] = [
        intval($p['id']),
        $p['sku'] ?? ('ART-' . sprintf("%04d", $p['id'])),
        $p['name'],
        $p['category_name'] ?? 'Non classé',
        $p['unit'] ?? 'UNITE',
        $p_buy,
        $p_sale,
        $margin_pct,
        $qty,
        $min_lvl,
        $val_buy,
        $val_sale,
        $status_str
    ];
}

$filename = "catalogue_produits_" . date('Y-m-d_Hi') . ".xlsx";
MandelExcelExporter::export($filename, "Catalogue", $headers, $rows);
