<?php
require_once __DIR__ . '/includes/header.php';

// Bloquer l'accès si ce n'est pas un administrateur
if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut modifier des produits.";
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0 && isset($_POST['id'])) {
    $id = intval($_POST['id']);
}

// Vérifier si le produit existe
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    echo "<div class='alert alert-danger m-4'><i class='fa-solid fa-triangle-exclamation me-2'></i>Produit introuvable.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Récupérer toutes les catégories
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll();

// Récupérer toutes les boutiques
$shops_stmt = $pdo->query("SELECT * FROM shops ORDER BY name");
$shops = $shops_stmt->fetchAll();

// Traitement de la mise à jour
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $sku = trim($_POST['sku']);
    $category_id = intval($_POST['category_id']);
    $unit = trim($_POST['unit']);
    $price_purchase = floatval($_POST['price_purchase']);
    $price_sale = floatval($_POST['price_sale']);
    $margin_percent = floatval($_POST['margin_percent'] ?? 5.00);
    $min_stock_level = intval($_POST['min_stock_level']);
    $adjustment_notes = trim($_POST['adjustment_notes'] ?? '');
    
    // Date de l'ajustement
    if (!empty($_POST['adjustment_date'])) {
        $raw_adj_date = str_replace('T', ' ', trim($_POST['adjustment_date']));
        if (strlen($raw_adj_date) === 16) {
            $raw_adj_date .= ':00';
        }
        $adjustment_datetime = date('Y-m-d H:i:s', strtotime($raw_adj_date));
        $adjustment_date_only = date('Y-m-d', strtotime($raw_adj_date));
    } else {
        $adjustment_datetime = date('Y-m-d H:i:s');
        $adjustment_date_only = date('Y-m-d');
    }

    if (empty($name)) {
        $error = "Le nom du produit est obligatoire.";
    } else {
        try {
            $pdo->beginTransaction();

            // Mettre à jour le produit
            $stmt_update = $pdo->prepare("
                UPDATE products 
                SET name = ?, sku = ?, category_id = ?, unit = ?, price_purchase = ?, price_sale = ?, margin_percent = ?, min_stock_level = ?
                WHERE id = ?
            ");
            $stmt_update->execute([
                $name,
                $sku ?: sprintf("ART-%04d", $id),
                $category_id > 0 ? $category_id : null,
                $unit ?: 'UNITE',
                $price_purchase,
                $price_sale,
                $margin_percent,
                $min_stock_level,
                $id
            ]);

            // Récupérer les anciens stocks avant modification pour enregistrer les pertes en compta
            $stmt_old_stocks = $pdo->prepare("SELECT shop_id, quantity FROM product_stock WHERE product_id = ?");
            $stmt_old_stocks->execute([$id]);
            $old_stocks_raw = $stmt_old_stocks->fetchAll();
            $old_stocks = [];
            foreach ($old_stocks_raw as $os) {
                $old_stocks[$os['shop_id']] = intval($os['quantity']);
            }

            // Mettre à jour les stocks dans chaque boutique (initial_stock et quantity)
            $stmt_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, initial_stock, quantity) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE initial_stock = VALUES(initial_stock), quantity = VALUES(quantity)
            ");
            
            $init_stocks = $_POST['initial_stock'] ?? [];
            $stocks = $_POST['stock'] ?? [];
            foreach ($shops as $shop) {
                $shop_id = $shop['id'];
                $new_init = isset($init_stocks[$shop_id]) ? max(0, intval($init_stocks[$shop_id])) : 0;
                $new_qty = isset($stocks[$shop_id]) ? max(0, intval($stocks[$shop_id])) : 0;
                $old_qty = isset($old_stocks[$shop_id]) ? $old_stocks[$shop_id] : 0;
                
                $stmt_stock->execute([$id, $shop_id, $new_init, $new_qty]);
                    
                // Imputation comptable UNIQUEMENT si explicitement demandée comme perte / démarque avérée
                if ($new_qty < $old_qty && !empty($_POST['record_as_financial_loss'])) {
                    $qty_lost = $old_qty - $new_qty;
                    $loss_value = $qty_lost * $price_purchase;
                    
                    if ($loss_value > 0) {
                        $stmt_loss = $pdo->prepare("
                            INSERT INTO expenses (shop_id, user_id, amount, category, expense_date, notes)
                            VALUES (?, ?, ?, 'Pertes de stock - Inventaire', ?, ?)
                        ");
                        $custom_note = $adjustment_notes ? " (Motif: " . $adjustment_notes . ")" : "";
                        $notes_loss = "Démarque / perte de stock manuelle sur l'article : " . $name . " (Quantité perdue : " . $qty_lost . " " . ($unit ?: 'UNITE') . ")" . $custom_note;
                        $stmt_loss->execute([
                            $shop_id,
                            $_SESSION['user_id'] ?? 1,
                            $loss_value,
                            $adjustment_date_only,
                            $notes_loss
                        ]);
                    }
                }
            }

            $pdo->commit();
            $success = "Le produit et ses stocks ont été mis à jour avec succès.";
            
            // Re-charger les détails du produit mis à jour
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur système lors de la modification : " . $e->getMessage();
        }
    }
}

// Récupérer les stocks actuels et initiaux pour ce produit dans toutes les boutiques
$stock_levels = [];
$initial_levels = [];
$stock_stmt = $pdo->prepare("SELECT shop_id, quantity, initial_stock FROM product_stock WHERE product_id = ?");
$stock_stmt->execute([$id]);
$rows = $stock_stmt->fetchAll();
foreach ($rows as $row) {
    $stock_levels[$row['shop_id']] = intval($row['quantity']);
    $initial_levels[$row['shop_id']] = intval($row['initial_stock']);
}
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-warning"><i class="fa-solid fa-pen-to-square"></i></span> 
            Modifier le Produit : <?= htmlspecialchars($product['name']) ?>
        </h1>
        <span class="text-secondary small fw-semibold">Édition de fiche article</span>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-12 col-lg-8 offset-lg-2">
        <div class="glass-card p-4">
            <form action="product-edit.php?id=<?= $id ?>" method="POST" onsubmit="return confirm('Voulez-vous vraiment enregistrer les modifications de ce produit ?');">
                <input type="hidden" name="id" value="<?= $id ?>">
                
                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-file-invoice text-primary me-2"></i>Informations Générales</h5>
                
                <div class="row g-3 mb-4">
                    <!-- ID / Numéro (Non modifiable) -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-secondary">Code Numérique (#ID)</label>
                        <input type="text" class="form-control form-control-custom bg-light" value="<?= $id ?>" readonly>
                        <small class="text-muted">L'ID interne n'est pas modifiable</small>
                    </div>
                    
                    <!-- SKU / Code personnalisé -->
                    <div class="col-md-4">
                        <label for="sku" class="form-label fw-semibold text-secondary">Référence / SKU (Code-barres)</label>
                        <input type="text" class="form-control form-control-custom" id="sku" name="sku" value="<?= htmlspecialchars($product['sku'] ?? '') ?>" placeholder="Ex: ART-0222">
                    </div>

                    <!-- Conditionnement (Unit) -->
                    <div class="col-md-4">
                        <label for="unit" class="form-label fw-semibold text-secondary">Conditionnement (Unité)</label>
                        <input type="text" class="form-control form-control-custom" id="unit" name="unit" list="units" value="<?= htmlspecialchars($product['unit']) ?>" placeholder="Ex: RAMETTE, PIECE..." required>
                        <datalist id="units">
                            <option value="RAMETTE">
                            <option value="RAME">
                            <option value="PIECE">
                            <option value="PAQUET">
                            <option value="BOITE">
                            <option value="DOUZAINE">
                            <option value="CARTONS">
                            <option value="UNITE">
                        </datalist>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <!-- Désignation -->
                    <div class="col-md-8">
                        <label for="name" class="form-label fw-semibold text-secondary">Désignation (Nom du produit)</label>
                        <input type="text" class="form-control form-control-custom" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                    </div>
                    
                    <!-- Catégorie -->
                    <div class="col-md-4">
                        <label for="category_id" class="form-label fw-semibold text-secondary">Famille / Catégorie</label>
                        <select class="form-select form-control-custom" id="category_id" name="category_id" required>
                            <option value="">Sélectionner une famille</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $product['category_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-dollar-sign text-success me-2"></i>Tarification & Alertes</h5>
                
                <div class="row g-3 mb-4">
                    <!-- Prix Achat -->
                    <div class="col-md-3">
                        <label for="price_purchase" class="form-label fw-semibold text-secondary">Prix d'Achat (FCFA)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom" id="price_purchase" name="price_purchase" value="<?= $product['price_purchase'] ?>" required>
                    </div>

                    <!-- Prix Vente -->
                    <div class="col-md-3">
                        <label for="price_sale" class="form-label fw-semibold text-secondary">Prix de Vente (FCFA)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom" id="price_sale" name="price_sale" value="<?= $product['price_sale'] ?>" required>
                    </div>

                    <!-- Marge de négociation -->
                    <div class="col-md-3">
                        <label for="margin_percent" class="form-label fw-semibold text-secondary">Marge de négoc. (%)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom" id="margin_percent" name="margin_percent" value="<?= htmlspecialchars($product['margin_percent'] ?? '5.00') ?>" min="0" max="100" required>
                    </div>

                    <!-- Seuil Stock Min -->
                    <div class="col-md-3">
                        <label for="min_stock_level" class="form-label fw-semibold text-secondary">Seuil d'alerte (Stock Bas)</label>
                        <input type="number" class="form-control form-control-custom" id="min_stock_level" name="min_stock_level" value="<?= $product['min_stock_level'] ?>" required>
                    </div>
                </div>

                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-warehouse text-accent me-2"></i>Ajustement des Stocks Physiques</h5>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="adjustment_date" class="form-label fw-semibold text-secondary">Date de l'Ajustement / Inventaire</label>
                        <input type="datetime-local" class="form-control form-control-custom" id="adjustment_date" name="adjustment_date" value="<?= date('Y-m-d\TH:i') ?>">
                        <small class="text-muted">Date appliquée pour l'historique et la traçabilité comptable.</small>
                    </div>
                    <div class="col-md-6">
                        <label for="adjustment_notes" class="form-label fw-semibold text-secondary">Motif / Justificatif de l'ajustement</label>
                        <input type="text" class="form-control form-control-custom" id="adjustment_notes" name="adjustment_notes" placeholder="Ex: Inventaire physique, régularisation stock initial...">
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <?php foreach ($shops as $shop): ?>
                        <?php 
                            $current_qty = isset($stock_levels[$shop['id']]) ? intval($stock_levels[$shop['id']]) : 0; 
                            $init_qty = isset($initial_levels[$shop['id']]) ? intval($initial_levels[$shop['id']]) : 0; 
                        ?>
                        <div class="col-md-6">
                            <div class="p-3 border rounded-3 bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-slate-800"><i class="fa-solid fa-store text-primary me-1"></i><?= htmlspecialchars($shop['name']) ?></span>
                                    <small class="text-secondary"><?= htmlspecialchars($shop['address'] ?? '') ?></small>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label small fw-bold text-secondary mb-1">Stock Initial (Départ)</label>
                                        <input type="number" class="form-control form-control-custom text-center fw-semibold text-secondary" name="initial_stock[<?= $shop['id'] ?>]" value="<?= $init_qty ?>" min="0" placeholder="Départ">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-bold text-primary mb-1">Stock Physique (Actuel)</label>
                                        <input type="number" class="form-control form-control-custom text-center fw-bold text-primary" name="stock[<?= $shop['id'] ?>]" value="<?= $current_qty ?>" min="0" placeholder="Actuel">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr class="my-4">

                <div class="d-flex gap-2 justify-content-end">
                    <a href="products.php" class="btn btn-outline-secondary" style="border-radius: 10px;">Retour au Catalogue</a>
                    <button type="submit" class="btn btn-grad"><i class="fa-solid fa-save me-1"></i>Enregistrer les Modifications</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
