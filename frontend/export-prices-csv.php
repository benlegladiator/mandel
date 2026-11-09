<?php
require_once __DIR__ . '/../backend/config/db.php';
session_start();

$current_role = strtolower(trim($_SESSION['user_role'] ?? 'seller'));
if (!isset($_SESSION['user_id']) || !in_array($current_role, ['admin', 'boss', 'owner', 'superadmin'])) {
    die("Accès refusé. Administrateur uniquement.");
}


$active_shop_id = $_SESSION['active_shop_id'] ?? 0;
$search = trim($_GET['search'] ?? '');
$category_filter = intval($_GET['category_id'] ?? 0);

$sql = "
    SELECT p.id, p.sku, p.name, c.name as category_name, p.unit, p.price_purchase, p.price_sale, p.margin_percent, COALESCE(SUM(s.quantity), 0) as stock_qty
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_stock s ON p.id = s.product_id
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

$sql .= "
    GROUP BY p.id
    ORDER BY c.name ASC, p.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "grille_tarifaire_mandel_" . date('Y-m-d_H-i') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Envoi du BOM UTF-8 pour Excel
echo "\u{FEFF}";

$output = fopen('php://output', 'w');

// En-tête du CSV avec séparateur point-virgule (spécifique Excel français)
fputcsv($output, [
    'ID',
    'Code_SKU',
    'Designation_Produit',
    'Categorie',
    'Unite',
    'Prix_Achat_FCFA',
    'Prix_Vente_FCFA',
    'Marge_Pourcent',
    'Stock_Total'
], ';');

foreach ($products as $p) {
    fputcsv($output, [
        $p['id'],
        $p['sku'] ?: ('PRD-' . $p['id']),
        $p['name'],
        $p['category_name'] ?? 'Non classé',
        $p['unit'],
        number_format(floatval($p['price_purchase']), 0, '', ''),
        number_format(floatval($p['price_sale']), 0, '', ''),
        number_format(floatval($p['margin_percent'] ?? 5.0), 2, '.', ''),
        $p['stock_qty']
    ], ';');
}

fclose($output);
exit;
