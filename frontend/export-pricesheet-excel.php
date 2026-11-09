<?php
require_once __DIR__ . '/../backend/config/db.php';
require_once __DIR__ . '/includes/xlsx_helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'category_asc';

$sql = "
    SELECT p.id, p.sku, p.name, p.unit, p.price_sale,
           c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $t_idx = 0;
    foreach ($search_terms as $term) {
        $p_name = ":st_n_$t_idx";
        $p_sku  = ":st_s_$t_idx";
        $sql .= " AND (p.name LIKE $p_name OR p.sku LIKE $p_sku)";
        $params[$p_name] = '%' . $term . '%';
        $params[$p_sku]  = '%' . $term . '%';
        $t_idx++;
    }
}

if ($category_filter > 0) {
    $sql .= " AND p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY p.name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY p.name DESC";
        break;
    case 'price_asc':
        $sql .= " ORDER BY p.price_sale ASC, p.name ASC";
        break;
    case 'price_desc':
        $sql .= " ORDER BY p.price_sale DESC, p.name ASC";
        break;
    case 'category_asc':
    default:
        $sql .= " ORDER BY c.name ASC, p.name ASC";
        break;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$headers = [
    'N°',
    'Famille / Catégorie',
    'Code SKU',
    'Désignation de l\'Article',
    'Conditionnement / Unité',
    'Prix de Vente Public (FCFA)'
];

$rows = [];
$i = 1;
foreach ($products as $p) {
    $rows[] = [
        $i++,
        $p['category_name'] ?? 'Divers',
        $p['sku'] ?? ('ART-' . sprintf("%04d", $p['id'])),
        $p['name'],
        $p['unit'] ?? 'UNITE',
        floatval($p['price_sale'])
    ];
}

$filename = "grille_tarifs_vente_" . date('Y-m-d_Hi') . ".xlsx";
MandelExcelExporter::export($filename, "Tarifs_Vente", $headers, $rows);
