<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../backend/config/db.php';

// Sécurité : Utilisateur connecté et rôle autorisé (admin ou vendeur selon permissions)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Veuillez vous reconnecter.']);
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Action non autorisée. Seul l\'administrateur peut créer des produits.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$p_name = trim($_POST['quick_name'] ?? '');
$p_cat_id = intval($_POST['quick_category_id'] ?? 0);
$p_unit = trim($_POST['quick_unit'] ?? 'UNITE') ?: 'UNITE';
$p_purchase = floatval($_POST['quick_price_purchase'] ?? 0);
$p_sale = floatval($_POST['quick_price_sale'] ?? 0);
$p_margin = floatval($_POST['quick_margin_percent'] ?? 5.00);
$p_min_stock = intval($_POST['quick_min_stock'] ?? 5);

if (empty($p_name)) {
    echo json_encode(['success' => false, 'message' => 'Le nom du produit est obligatoire.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $max_id_stmt = $pdo->query("SELECT MAX(id) as max_id FROM products");
    $next_p_id = intval($max_id_stmt->fetch()['max_id'] ?? 0) + 1;
    $p_sku = sprintf("ART-%04d", $next_p_id);

    $stmt_ins = $pdo->prepare("
        INSERT INTO products (id, name, sku, category_id, unit, price_purchase, price_sale, margin_percent, min_stock_level)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_ins->execute([
        $next_p_id,
        $p_name,
        $p_sku,
        $p_cat_id > 0 ? $p_cat_id : null,
        $p_unit,
        $p_purchase,
        $p_sale,
        $p_margin,
        $p_min_stock
    ]);

    // Initialiser les entrées de stock à 0 pour toutes les boutiques existantes
    $shops_stmt = $pdo->query("SELECT id FROM shops");
    $all_shops = $shops_stmt->fetchAll();
    if (!empty($all_shops)) {
        $stk_stmt = $pdo->prepare("INSERT IGNORE INTO product_stock (product_id, shop_id, quantity) VALUES (?, ?, 0)");
        foreach ($all_shops as $shop) {
            $stk_stmt->execute([$next_p_id, $shop['id']]);
        }
    }

    $pdo->commit();

    // Récupérer le nom de la catégorie pour l'affichage
    $cat_name = 'Sans Famille';
    if ($p_cat_id > 0) {
        $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $cat_stmt->execute([$p_cat_id]);
        $cat_name = $cat_stmt->fetchColumn() ?: 'Sans Famille';
    }

    echo json_encode([
        'success' => true,
        'product' => [
            'id' => $next_p_id,
            'name' => $p_name,
            'sku' => $p_sku,
            'category_name' => $cat_name,
            'unit' => $p_unit,
            'price_purchase' => $p_purchase,
            'price_sale' => $p_sale,
            'stock_qty' => 0,
            'margin_percent' => $p_margin
        ]
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la création : ' . $e->getMessage()]);
    exit;
}
