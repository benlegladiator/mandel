<?php
require_once __DIR__ . '/includes/header.php';

// Autoriser l'accès aux administrateurs et aux vendeurs connectés
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

// Récupérer toutes les catégories
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll();

// Récupérer toutes les boutiques pour le stock initial
$shops_stmt = $pdo->query("SELECT * FROM shops ORDER BY name");
$shops = $shops_stmt->fetchAll();

// Trouver le prochain ID disponible pour le produit
$max_id_stmt = $pdo->query("SELECT MAX(id) as max_id FROM products");
$next_id = intval($max_id_stmt->fetch()['max_id'] ?? 0) + 1;

// Traitement de l'envoi du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : $next_id;
    $name = trim($_POST['name']);
    $sku = trim($_POST['sku']);
    $category_id = intval($_POST['category_id']);
    $unit = trim($_POST['unit']);
    $price_purchase = floatval($_POST['price_purchase']);
    $price_sale = floatval($_POST['price_sale']);
    $margin_percent = floatval($_POST['margin_percent'] ?? 5.00);
    $min_stock_level = intval($_POST['min_stock_level']);
    $initial_stocks = isset($_POST['stock']) ? $_POST['stock'] : [];

    if (empty($name)) {
        $error = "Le nom du produit est obligatoire.";
    } else {
        try {
            $pdo->beginTransaction();

            // Générer un SKU si vide
            if (empty($sku)) {
                $sku = sprintf("ART-%04d", $id);
            }

            // Insérer le produit
            $stmt = $pdo->prepare("
                INSERT INTO products (id, name, sku, category_id, unit, price_purchase, price_sale, margin_percent, min_stock_level) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $name,
                $sku,
                $category_id > 0 ? $category_id : null,
                $unit ?: 'UNITE',
                $price_purchase,
                $price_sale,
                $margin_percent,
                $min_stock_level
            ]);

            // Insérer le stock initial officiel pour chaque boutique
            $stmt_stock = $pdo->prepare("INSERT INTO product_stock (product_id, shop_id, initial_stock, quantity) VALUES (?, ?, ?, ?)");
            foreach ($shops as $shop) {
                $shop_id = $shop['id'];
                $qty = isset($initial_stocks[$shop_id]) ? max(0, intval($initial_stocks[$shop_id])) : 0;
                $stmt_stock->execute([$id, $shop_id, $qty, $qty]);
            }

            $pdo->commit();
            $success = "Le produit a été créé avec succès.";
            
            // Recalculer le prochain ID
            $max_id_stmt = $pdo->query("SELECT MAX(id) as max_id FROM products");
            $next_id = intval($max_id_stmt->fetch()['max_id'] ?? 0) + 1;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == 23000) {
                $error = "Une erreur est survenue : Un produit existe déjà avec cet ID ou ce Code (SKU).";
            } else {
                $error = "Erreur système : " . $e->getMessage();
            }
        }
    }
}
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-plus-circle"></i></span> 
            Ajouter un Nouveau Produit
        </h1>
        <span class="text-secondary small fw-semibold">Création d'un article au catalogue</span>
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
            <form action="product-add.php" method="POST" onsubmit="return confirm('Voulez-vous vraiment ajouter ce produit au catalogue ?');">
                
                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-file-invoice text-primary me-2"></i>Informations Générales</h5>
                
                <div class="row g-3 mb-4">
                    <!-- ID / Numéro -->
                    <div class="col-md-4">
                        <label for="id" class="form-label fw-semibold text-secondary">Code Numérique (#ID)</label>
                        <input type="number" class="form-control form-control-custom" id="id" name="id" value="<?= $next_id ?>" required>
                        <small class="text-muted">ID unique séquentiel</small>
                    </div>
                    
                    <!-- SKU / Code personnalisé -->
                    <div class="col-md-4">
                        <label for="sku" class="form-label fw-semibold text-secondary">Référence / SKU (Code-barres)</label>
                        <input type="text" class="form-control form-control-custom" id="sku" name="sku" placeholder="Ex: ART-0222 (Généré par défaut)">
                    </div>

                    <!-- Conditionnement (Unit) -->
                    <div class="col-md-4">
                        <label for="unit" class="form-label fw-semibold text-secondary">Conditionnement (Unité)</label>
                        <input type="text" class="form-control form-control-custom" id="unit" name="unit" list="units" placeholder="Ex: RAMETTE, PIECE, RAME..." required>
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
                        <input type="text" class="form-control form-control-custom" id="name" name="name" placeholder="Ex: Cahier Oxford A4 double spirale" required>
                    </div>
                    
                    <!-- Catégorie -->
                    <div class="col-md-4">
                        <label for="category_id" class="form-label fw-semibold text-secondary">Famille / Catégorie</label>
                        <select class="form-select form-control-custom" id="category_id" name="category_id" required>
                            <option value="">Sélectionner une famille</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-dollar-sign text-success me-2"></i>Tarification & Alertes</h5>
                
                <div class="row g-3 mb-4">
                    <!-- Prix Achat -->
                    <div class="col-md-3">
                        <label for="price_purchase" class="form-label fw-semibold text-secondary">Prix d'Achat (FCFA)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom" id="price_purchase" name="price_purchase" value="0.00" required>
                    </div>

                    <!-- Prix Vente -->
                    <div class="col-md-3">
                        <label for="price_sale" class="form-label fw-semibold text-secondary">Prix de Vente (FCFA)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom" id="price_sale" name="price_sale" value="0.00" required>
                    </div>

                    <!-- Marge de négociation -->
                    <div class="col-md-3">
                        <label for="margin_percent" class="form-label fw-semibold text-secondary">Marge de négoc. (%)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom" id="margin_percent" name="margin_percent" value="5.00" min="0" max="100" required>
                    </div>

                    <!-- Seuil Stock Min -->
                    <div class="col-md-3">
                        <label for="min_stock_level" class="form-label fw-semibold text-secondary">Seuil d'alerte (Stock Bas)</label>
                        <input type="number" class="form-control form-control-custom" id="min_stock_level" name="min_stock_level" value="5" required>
                    </div>
                </div>

                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-warehouse text-accent me-2"></i>Initialisation des Stocks Physiques</h5>
                
                <div class="row g-3 mb-4">
                    <?php foreach ($shops as $shop): ?>
                        <div class="col-md-6">
                            <div class="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="fw-bold text-slate-700"><?= htmlspecialchars($shop['name']) ?></span><br>
                                    <small class="text-secondary"><?= htmlspecialchars($shop['address'] ?? '') ?></small>
                                </div>
                                <div style="width: 120px;">
                                    <input type="number" class="form-control form-control-custom text-center" name="stock[<?= $shop['id'] ?>]" value="0" min="0" placeholder="Quantité">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr class="my-4">

                <div class="d-flex gap-2 justify-content-end">
                    <a href="products.php" class="btn btn-outline-secondary" style="border-radius: 10px;">Retour au Catalogue</a>
                    <button type="submit" class="btn btn-grad"><i class="fa-solid fa-circle-check me-1"></i>Enregistrer le Produit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
