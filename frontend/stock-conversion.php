<?php
require_once __DIR__ . '/includes/header.php';

$user_role = $_SESSION['user_role'];
$active_shop_id = $_SESSION['active_shop_id'];
if (isset($_GET['shop_id']) && $user_role === 'admin') {
    $active_shop_id = intval($_GET['shop_id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shop_id'])) {
    $active_shop_id = intval($_POST['shop_id']);
}
if ($active_shop_id <= 0 && !empty($all_shops)) {
    $active_shop_id = $all_shops[0]['id'];
}

$error_msg = '';
$success_msg = '';

// Traitement du Déconditionnement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_conversion'])) {
    $src_id = intval($_POST['source_product_id'] ?? 0);
    $target_input = isset($_POST['target_product_id']) ? trim($_POST['target_product_id']) : '';
    $src_qty = intval($_POST['source_quantity'] ?? 0);
    $multiplier = intval($_POST['multiplier'] ?? 0);
    $new_price = isset($_POST['new_price_sale']) && $_POST['new_price_sale'] !== '' ? floatval($_POST['new_price_sale']) : null;
    $reason = trim($_POST['reason'] ?? '');

    // Validation initiale
    if ($src_id <= 0 || empty($target_input)) {
        $error_msg = "Veuillez sélectionner un article source et un article cible.";
    } elseif ($src_qty <= 0) {
        $error_msg = "La quantité à déconditionner doit être supérieure à 0.";
    } elseif ($multiplier <= 0) {
        $error_msg = "Le multiplicateur de conversion doit être supérieur à 0.";
    } elseif (empty($reason)) {
        $error_msg = "Veuillez renseigner le motif de cette conversion.";
    } else {
        try {
            $pdo->beginTransaction();

            $tgt_id = 0;
            
            // Récupérer le prix d'achat du produit source pour calculer sa fraction
            $stmt_src_price = $pdo->prepare("SELECT price_purchase FROM products WHERE id = ?");
            $stmt_src_price->execute([$src_id]);
            $src_price_purchase = floatval($stmt_src_price->fetchColumn() ?: 0);
            $new_tgt_purchase_price = $multiplier > 0 ? ($src_price_purchase / $multiplier) : 0.00;

            // 1. Création dynamique de l'article cible s'il n'existe pas
            if ($target_input === 'NEW') {
                $new_name = trim($_POST['new_target_name'] ?? '');
                $new_unit = trim($_POST['new_target_unit'] ?? '');
                $new_cat_id = isset($_POST['new_target_category_id']) ? intval($_POST['new_target_category_id']) : 0;

                if (empty($new_name)) {
                    throw new Exception("Le nom du nouvel article cible est requis.");
                }
                if ($new_cat_id <= 0) {
                    throw new Exception("Veuillez sélectionner une famille pour le nouvel article.");
                }

                // Récupérer le prochain ID produit
                $max_id_stmt = $pdo->query("SELECT MAX(id) as max_id FROM products");
                $tgt_id = intval($max_id_stmt->fetch()['max_id'] ?? 0) + 1;
                $sku = sprintf("ART-%04d", $tgt_id);

                // Insérer le nouveau produit avec le prix d'achat propagé
                $stmt_new_prod = $pdo->prepare("
                    INSERT INTO products (id, name, sku, category_id, unit, price_purchase, price_sale, margin_percent, min_stock_level) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 5.00, 5)
                ");
                $stmt_new_prod->execute([
                    $tgt_id,
                    $new_name,
                    $sku,
                    $new_cat_id,
                    $new_unit ?: 'UNITE',
                    $new_tgt_purchase_price,
                    $new_price ?: 0.00
                ]);

                // Initialiser le stock à 0 pour toutes les boutiques afin d'assurer l'intégrité de l'affichage
                $all_shops_list = $pdo->query("SELECT id FROM shops")->fetchAll();
                $stmt_stock_init = $pdo->prepare("INSERT IGNORE INTO product_stock (product_id, shop_id, quantity) VALUES (?, ?, 0)");
                foreach ($all_shops_list as $s) {
                    $stmt_stock_init->execute([$tgt_id, $s['id']]);
                }
            } else {
                $tgt_id = intval($target_input);
                
                // Si l'article cible existant a un prix d'achat égal à 0, on met à jour avec la valeur fractionnée du produit source
                $stmt_check_tgt = $pdo->prepare("SELECT price_purchase FROM products WHERE id = ?");
                $stmt_check_tgt->execute([$tgt_id]);
                $tgt_price_purchase = floatval($stmt_check_tgt->fetchColumn() ?: 0);
                
                if ($tgt_price_purchase == 0.00 && $new_tgt_purchase_price > 0) {
                    $stmt_update_tgt_price = $pdo->prepare("UPDATE products SET price_purchase = ? WHERE id = ?");
                    $stmt_update_tgt_price->execute([$new_tgt_purchase_price, $tgt_id]);
                }
            }

            if ($src_id === $tgt_id) {
                throw new Exception("L'article source et l'article cible doivent être différents.");
            }

            // Vérifier le stock actuel de l'article source dans cette boutique
            $stmt_check = $pdo->prepare("SELECT quantity FROM product_stock WHERE product_id = ? AND shop_id = ?");
            $stmt_check->execute([$src_id, $active_shop_id]);
            $current_src_stock = intval($stmt_check->fetchColumn() ?: 0);

            if ($current_src_stock < $src_qty) {
                throw new Exception("Stock insuffisant. Le stock actuel du produit source est de $current_src_stock unité(s).");
            }

            // 1. Déduire la quantité de l'article source
            $stmt_deduct = $pdo->prepare("UPDATE product_stock SET quantity = quantity - ? WHERE product_id = ? AND shop_id = ?");
            $stmt_deduct->execute([$src_qty, $src_id, $active_shop_id]);

            // 2. Ajouter la quantité de l'article cible (le produit existant ou nouvellement créé)
            $tgt_qty = $src_qty * $multiplier;
            $stmt_add = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ");
            $stmt_add->execute([$tgt_id, $active_shop_id, $tgt_qty, $tgt_qty]);

            // 3. Mettre à jour le prix de vente si spécifié
            if ($new_price !== null && $new_price > 0) {
                $stmt_price = $pdo->prepare("UPDATE products SET price_sale = ? WHERE id = ?");
                $stmt_price->execute([$new_price, $tgt_id]);
            }

            // 4. Enregistrer la conversion dans l'historique
            $stmt_log = $pdo->prepare("
                INSERT INTO stock_conversions (shop_id, user_id, source_product_id, target_product_id, source_quantity, multiplier, target_quantity, reason) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_log->execute([
                $active_shop_id,
                $_SESSION['user_id'],
                $src_id,
                $tgt_id,
                $src_qty,
                $multiplier,
                $tgt_qty,
                $reason
            ]);

            $pdo->commit();
            $success_msg = "Opération de déconditionnement réussie ! Les stocks ont été ajustés avec succès.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = "Une erreur est survenue lors de la conversion : " . $e->getMessage();
        }
    }
}

// Charger les produits dispo en stock pour la source
$stmt_src = $pdo->prepare("
    SELECT p.id, p.name, p.unit, p.price_purchase, p.price_sale, COALESCE(ps.quantity, 0) as quantity 
    FROM products p
    JOIN product_stock ps ON ps.product_id = p.id 
    WHERE ps.shop_id = ? AND ps.quantity > 0 
    ORDER BY p.name ASC
");
$stmt_src->execute([$active_shop_id]);
$src_products = $stmt_src->fetchAll();

// Charger tous les produits du catalogue pour la cible
$tgt_products = $pdo->query("SELECT id, name, unit, price_purchase, price_sale FROM products ORDER BY name ASC")->fetchAll();

// Charger les catégories pour la création de produit cible
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

// Pagination SQL de l'historique des conversions (30 par page)
$per_page = 30;
$stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_conversions WHERE shop_id = ?");
$stmt_cnt->execute([$active_shop_id]);
$total_conversions = intval($stmt_cnt->fetchColumn() ?: 0);

$total_pages = max(1, ceil($total_conversions / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

$stmt_history = $pdo->prepare("
    SELECT sc.*, u.name as user_name,
           p_src.name as src_name, p_src.unit as src_unit,
           p_tgt.name as tgt_name, p_tgt.unit as tgt_unit
    FROM stock_conversions sc
    JOIN users u ON sc.user_id = u.id
    JOIN products p_src ON sc.source_product_id = p_src.id
    JOIN products p_tgt ON sc.target_product_id = p_tgt.id
    WHERE sc.shop_id = ?
    ORDER BY sc.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt_history->execute([$active_shop_id]);
$history = $stmt_history->fetchAll();

// Nom de la boutique active
$current_active_shop_name = 'Dépôt sélectionné';
foreach ($all_shops as $s) {
    if ($s['id'] == $active_shop_id) {
        $current_active_shop_name = $s['name'];
        break;
    }
}
?>

<!-- Titre & Entête -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-boxes-packing"></i></span> 
            Fractionnement de Stock (Déconditionnement / Détail)
        </h1>
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small fw-semibold">Dépôt actif : <span class="badge bg-light text-dark border"><?= htmlspecialchars($current_active_shop_name) ?></span></span>
        </div>
    </div>
</div>

<!-- Alertes -->
<?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error_msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-5">
    <!-- Formulaire à gauche -->
    <div class="col-12 col-lg-5">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-arrows-spin text-primary me-2"></i>Nouvelle Conversion</h5>
            
            <form action="stock-conversion.php" method="POST" id="conversionForm" onsubmit="return validateAndConfirmConversion();">
                
                <?php if ($user_role === 'admin'): ?>
                    <!-- Choix du Dépôt (Admin) -->
                    <div class="mb-3">
                        <label for="shop_id" class="form-label fw-semibold text-secondary small">Dépôt concerné</label>
                        <select class="form-select form-control-custom" id="shop_id" name="shop_id" onchange="window.location.href='stock-conversion.php?shop_id=' + this.value">
                            <?php foreach ($all_shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $active_shop_id ? 'selected' : '' ?>>
                                    🏢 <?= htmlspecialchars($sh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="shop_id" value="<?= $active_shop_id ?>">
                <?php endif; ?>

                <!-- Article Source -->
                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary small mb-1">
                        1. Article Source (Gros / En Stock à fractionner) <span class="text-danger">*</span>
                    </label>
                    
                    <div class="position-relative" id="source_search_wrapper">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                            <input type="text" id="source_search_input" class="form-control form-control-custom border-start-0 ps-0" placeholder="Taper le nom (ex: Couché, Papier, Rame...)" autocomplete="off" style="border-radius: 0 8px 8px 0;">
                        </div>
                        <div id="source_dropdown" class="dropdown-menu w-100 shadow-lg border p-1 mt-1" style="max-height: 260px; overflow-y: auto; display: none; position: absolute; z-index: 1050; border-radius: 8px;"></div>
                    </div>

                    <!-- Carte de l'article source sélectionné -->
                    <div id="source_selected_card" class="card bg-light border border-primary-subtle p-2.5 mt-2 d-none shadow-sm" style="border-radius: 8px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold text-slate-800" id="source_card_name">-</div>
                                <div class="small text-secondary">
                                    Stock dispo : <span class="badge bg-success-subtle text-success fw-bold" id="source_card_stock">0</span> | 
                                    Achat : <span class="fw-semibold text-dark" id="source_card_price">0 FCFA</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" onclick="resetSourceSelection()" title="Changer d'article" style="border-radius: 6px;">
                                <i class="fa-solid fa-rotate-left me-1"></i>Changer
                            </button>
                        </div>
                    </div>

                    <!-- Hidden native select for submit -->
                    <select class="d-none" id="source_product_id" name="source_product_id" required>
                        <option value="">-- Aucun --</option>
                        <?php foreach ($src_products as $p): ?>
                            <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-stock="<?= $p['quantity'] ?>" data-unit="<?= htmlspecialchars($p['unit']) ?>" data-purchase="<?= $p['price_purchase'] ?>">
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text small text-muted" id="source_hint">
                        <?= count($src_products) ?> article(s) disponible(s) en stock dans ce dépôt.
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <!-- Quantité à convertir -->
                    <div class="col-sm-6">
                        <label for="source_quantity" class="form-label small fw-semibold text-secondary">Qté Source à diviser <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-custom" id="source_quantity" name="source_quantity" value="1" min="1" required oninput="calculatePreview()">
                    </div>
                    
                    <!-- Multiplicateur -->
                    <div class="col-sm-6">
                        <label for="multiplier" class="form-label small fw-semibold text-secondary">Multiplicateur (Unités obtenues) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-custom" id="multiplier" name="multiplier" placeholder="Ex: 500 ou 100" min="1" required oninput="calculatePreview()">
                    </div>
                </div>

                <!-- Article Cible -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary mb-0">
                            2. Article Cible (Détail / Unitaire obtenu) <span class="text-danger">*</span>
                        </label>
                        <button type="button" class="btn btn-link btn-sm p-0 text-primary fw-bold text-decoration-none" onclick="triggerNewTargetProduct()" style="font-size: 0.82rem;">
                            <i class="fa-solid fa-plus-circle me-1"></i>+ Nouvel article
                        </button>
                    </div>
                    
                    <div class="position-relative" id="target_search_wrapper">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0" style="border-radius: 8px 0 0 8px;"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                            <input type="text" id="target_search_input" class="form-control form-control-custom border-start-0 ps-0" placeholder="Rechercher ou créer l'article au détail..." autocomplete="off" style="border-radius: 0 8px 8px 0;">
                        </div>
                        <div id="target_dropdown" class="dropdown-menu w-100 shadow-lg border p-1 mt-1" style="max-height: 260px; overflow-y: auto; display: none; position: absolute; z-index: 1050; border-radius: 8px;"></div>
                    </div>

                    <!-- Carte de l'article cible sélectionné -->
                    <div id="target_selected_card" class="card bg-light border border-success-subtle p-2.5 mt-2 d-none shadow-sm" style="border-radius: 8px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold text-slate-800" id="target_card_name">-</div>
                                <div class="small text-secondary">
                                    Conditionnement : <span class="badge bg-info-subtle text-info fw-bold" id="target_card_unit">-</span> | 
                                    Prix vente actuel : <span class="fw-semibold text-dark" id="target_card_price">0 FCFA</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" onclick="resetTargetSelection()" title="Changer d'article" style="border-radius: 6px;">
                                <i class="fa-solid fa-rotate-left me-1"></i>Changer
                            </button>
                        </div>
                    </div>

                    <!-- Hidden native select for submit -->
                    <select class="d-none" id="target_product_id" name="target_product_id" required>
                        <option value="">-- Aucun --</option>
                        <option value="NEW">+ Créer un nouvel article cible...</option>
                        <?php foreach ($tgt_products as $p): ?>
                            <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-unit="<?= htmlspecialchars($p['unit']) ?>" data-price-sale="<?= $p['price_sale'] ?>" data-purchase="<?= $p['price_purchase'] ?>">
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nouveaux champs pour article cible si 'NEW' est sélectionné -->
                <div id="new_product_fields" class="p-3 mb-3 border border-primary bg-primary bg-opacity-10 d-none" style="border-radius: 10px;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-primary"><i class="fa-solid fa-plus-circle me-1"></i>Créer un Nouvel Article Cible</h6>
                        <button type="button" class="btn-close btn-sm" onclick="cancelNewTargetProduct()"></button>
                    </div>
                    
                    <div class="mb-2">
                        <label for="new_target_name" class="form-label small mb-1 fw-semibold text-secondary">Désignation de l'article obtenu <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-custom form-control-sm" id="new_target_name" name="new_target_name" placeholder="Ex: Couché 2 FACE 128G A4 100 FEUILLES">
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-6">
                            <label for="new_target_unit" class="form-label small mb-1 fw-semibold text-secondary">Unité / Cond. <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-custom form-control-sm" id="new_target_unit" name="new_target_unit" placeholder="Ex: FEUILLE, RAMETTE" value="FEUILLE">
                        </div>
                        <div class="col-6">
                            <label for="new_target_category_id" class="form-label small mb-1 fw-semibold text-secondary">Famille / Rayon <span class="text-danger">*</span></label>
                            <select class="form-select form-control-custom form-control-sm" id="new_target_category_id" name="new_target_category_id">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Aperçu dynamique du résultat -->
                <div id="conversion_preview_box" class="alert alert-info border-0 p-3 mb-3 d-none" style="border-radius: 10px;">
                    <div class="small fw-bold text-dark mb-1"><i class="fa-solid fa-calculator me-1"></i>Aperçu de la conversion :</div>
                    <div class="small text-secondary" id="conversion_preview_text">-</div>
                </div>

                <!-- Prix cible optionnel -->
                <div class="mb-3">
                    <label for="new_price_sale" class="form-label small fw-semibold text-secondary">Nouveau Prix de Vente Unitaire (Optionnel)</label>
                    <div class="input-group">
                        <input type="number" step="0.01" class="form-control form-control-custom" id="new_price_sale" name="new_price_sale" placeholder="Ex: 50 (Laisser vide si inchangé)">
                        <span class="input-group-text bg-white text-muted">FCFA</span>
                    </div>
                </div>

                <!-- Motif de conversion -->
                <div class="mb-4">
                    <label for="reason" class="form-label small fw-semibold text-secondary">Motif / Justification <span class="text-danger">*</span></label>
                    <textarea class="form-control form-control-custom" id="reason" name="reason" rows="2" placeholder="Ex: Détaillage de rame pour vente au format ou à la feuille..." required></textarea>
                </div>

                <button type="submit" name="submit_conversion" class="btn btn-grad w-100 py-2.5 fw-bold shadow-sm" style="border-radius: 8px;">
                    <i class="fa-solid fa-box-open me-2"></i>Exécuter la Conversion
                </button>
            </form>
        </div>
    </div>

    <!-- Historique à droite -->
    <div class="col-12 col-lg-7">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Historique des Déconditionnements</h5>
                <span class="badge bg-light text-dark border"><?= count($history) ?> opération(s)</span>
            </div>
            
            <?php if (empty($history)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="fa-solid fa-clock fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">Aucun déconditionnement enregistré pour ce dépôt.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive" style="max-height: 540px; overflow-y: auto;">
                    <table class="table table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Date & Heure</th>
                                <th>Opérateur</th>
                                <th>Source (&darr;)</th>
                                <th>Cible (&uarr;)</th>
                                <th>Motif</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td class="small text-secondary">
                                        <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($h['user_name']) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-danger">-<?= $h['source_quantity'] ?> <?= htmlspecialchars($h['src_unit']) ?></div>
                                        <small class="text-secondary"><?= htmlspecialchars($h['src_name']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-success">+<?= $h['target_quantity'] ?> <?= htmlspecialchars($h['tgt_unit']) ?></div>
                                        <small class="text-secondary"><?= htmlspecialchars($h['tgt_name']) ?></small>
                                    </td>
                                    <td class="small text-secondary" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($h['reason']) ?>">
                                        <?= htmlspecialchars($h['reason']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Barre de Pagination Transformations -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                        <div class="small text-secondary fw-semibold">
                            Affichage de <?= min($total_conversions, $offset + 1) ?> à <?= min($total_conversions, $offset + count($history)) ?> sur <strong><?= number_format($total_conversions, 0, '.', ' ') ?></strong> conversion(s)
                        </div>
                        <nav aria-label="Pagination conversions">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $q_conv = $_GET;
                                if ($current_page > 1):
                                    $q_conv['page'] = $current_page - 1;
                                    echo '<li class="page-item"><a class="page-link" href="stock-conversion.php?' . http_build_query($q_conv) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                endif;

                                $st_p = max(1, $current_page - 2);
                                $en_p = min($total_pages, $current_page + 2);

                                for ($p = $st_p; $p <= $en_p; $p++):
                                    $q_conv['page'] = $p;
                                    $act = ($p == $current_page) ? 'active' : '';
                                    echo '<li class="page-item ' . $act . '"><a class="page-link" href="stock-conversion.php?' . http_build_query($q_conv) . '">' . $p . '</a></li>';
                                endfor;

                                if ($current_page < $total_pages):
                                    $q_conv['page'] = $current_page + 1;
                                    echo '<li class="page-item"><a class="page-link" href="stock-conversion.php?' . http_build_query($q_conv) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
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

<!-- DATA JSON pour recherche ultra-rapide -->
<script>
const SOURCE_PRODUCTS = <?= json_encode(array_map(function($p) {
    return [
        'id' => intval($p['id']),
        'name' => $p['name'],
        'unit' => $p['unit'],
        'stock' => intval($p['quantity']),
        'price_purchase' => floatval($p['price_purchase']),
        'price_sale' => floatval($p['price_sale'])
    ];
}, $src_products), JSON_UNESCAPED_UNICODE) ?>;

const TARGET_PRODUCTS = <?= json_encode(array_map(function($p) {
    return [
        'id' => intval($p['id']),
        'name' => $p['name'],
        'unit' => $p['unit'],
        'price_sale' => floatval($p['price_sale']),
        'price_purchase' => floatval($p['price_purchase'])
    ];
}, $tgt_products), JSON_UNESCAPED_UNICODE) ?>;

// Normalisation pour recherche sans accent
function normalizeText(text) {
    return (text || '').normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
}

function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return (text || '').replace(/[&<>"']/g, function(m) { return map[m]; });
}

// -------------------------------------------------------------
// GESTION RECHERCHE ARTICLE SOURCE
// -------------------------------------------------------------
const srcSearchInput = document.getElementById('source_search_input');
const srcDropdown = document.getElementById('source_dropdown');
const srcSelect = document.getElementById('source_product_id');
const srcCard = document.getElementById('source_selected_card');
const srcWrapper = document.getElementById('source_search_wrapper');
let selectedSourceProduct = null;

function renderSourceDropdown(query = '') {
    const normQuery = normalizeText(query);
    const filtered = SOURCE_PRODUCTS.filter(p => {
        if (!normQuery) return true;
        return normalizeText(p.name).includes(normQuery) || normalizeText(p.unit).includes(normQuery);
    });

    if (filtered.length === 0) {
        srcDropdown.innerHTML = '<div class="p-3 text-muted text-center small"><i class="fa-solid fa-box-open me-1"></i>Aucun article en stock trouvé.</div>';
    } else {
        let html = '';
        filtered.slice(0, 30).forEach(p => {
            html += `
                <button type="button" class="dropdown-item d-flex justify-content-between align-items-center py-2 px-3 border-bottom border-light" onclick="selectSourceProduct(${p.id})">
                    <div class="text-start pe-2 text-wrap">
                        <div class="fw-bold text-slate-800">${escapeHtml(p.name)}</div>
                        <small class="text-secondary">Achat : ${Number(p.price_purchase).toLocaleString('fr-FR')} FCFA</small>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold ms-auto">
                        ${p.stock} ${escapeHtml(p.unit)}
                    </span>
                </button>
            `;
        });
        srcDropdown.innerHTML = html;
    }
    srcDropdown.style.display = 'block';
}

function selectSourceProduct(id) {
    const product = SOURCE_PRODUCTS.find(p => p.id === id);
    if (!product) return;

    selectedSourceProduct = product;
    srcSelect.value = product.id;

    // Afficher la carte sélectionnée
    document.getElementById('source_card_name').textContent = product.name;
    document.getElementById('source_card_stock').textContent = `${product.stock} ${product.unit}`;
    document.getElementById('source_card_price').textContent = `${Number(product.price_purchase).toLocaleString('fr-FR')} FCFA`;

    srcWrapper.classList.add('d-none');
    srcCard.classList.remove('d-none');
    srcDropdown.style.display = 'none';

    // Ajuster le max de quantité
    const qtyInput = document.getElementById('source_quantity');
    qtyInput.max = product.stock;
    if (parseInt(qtyInput.value) > product.stock) {
        qtyInput.value = product.stock;
    }

    calculatePreview();
}

function resetSourceSelection() {
    selectedSourceProduct = null;
    srcSelect.value = '';
    srcCard.classList.add('d-none');
    srcWrapper.classList.remove('d-none');
    srcSearchInput.value = '';
    srcSearchInput.focus();
    renderSourceDropdown('');
    calculatePreview();
}

srcSearchInput.addEventListener('input', function() {
    renderSourceDropdown(this.value);
});

srcSearchInput.addEventListener('focus', function() {
    renderSourceDropdown(this.value);
});

// -------------------------------------------------------------
// GESTION RECHERCHE ARTICLE CIBLE
// -------------------------------------------------------------
const tgtSearchInput = document.getElementById('target_search_input');
const tgtDropdown = document.getElementById('target_dropdown');
const tgtSelect = document.getElementById('target_product_id');
const tgtCard = document.getElementById('target_selected_card');
const tgtWrapper = document.getElementById('target_search_wrapper');
const newFieldsDiv = document.getElementById('new_product_fields');
const newNameInput = document.getElementById('new_target_name');
const newUnitInput = document.getElementById('new_target_unit');
const newCatSelect = document.getElementById('new_target_category_id');
let selectedTargetProduct = null;

function renderTargetDropdown(query = '') {
    const normQuery = normalizeText(query);
    const filtered = TARGET_PRODUCTS.filter(p => {
        if (!normQuery) return true;
        return normalizeText(p.name).includes(normQuery) || normalizeText(p.unit).includes(normQuery);
    });

    let html = `
        <button type="button" class="dropdown-item d-flex align-items-center py-2 px-3 bg-primary bg-opacity-10 text-primary fw-bold border-bottom" onclick="triggerNewTargetProduct('${escapeHtml(query)}')">
            <i class="fa-solid fa-plus-circle me-2"></i>+ Créer "${escapeHtml(query || 'un nouvel article')}"
        </button>
    `;

    if (filtered.length === 0) {
        html += '<div class="p-3 text-muted text-center small"><i class="fa-solid fa-info-circle me-1"></i>Aucun article existant ne correspond. Utilisez le bouton ci-dessus pour le créer.</div>';
    } else {
        filtered.slice(0, 30).forEach(p => {
            html += `
                <button type="button" class="dropdown-item d-flex justify-content-between align-items-center py-2 px-3 border-bottom border-light" onclick="selectTargetProduct(${p.id})">
                    <div class="text-start pe-2 text-wrap">
                        <div class="fw-bold text-slate-800">${escapeHtml(p.name)}</div>
                        <small class="text-secondary">Unité : <strong>${escapeHtml(p.unit)}</strong></small>
                    </div>
                    <span class="badge bg-light text-dark border ms-auto">
                        ${Number(p.price_sale).toLocaleString('fr-FR')} FCFA
                    </span>
                </button>
            `;
        });
    }
    tgtDropdown.innerHTML = html;
    tgtDropdown.style.display = 'block';
}

function selectTargetProduct(id) {
    const product = TARGET_PRODUCTS.find(p => p.id === id);
    if (!product) return;

    selectedTargetProduct = product;
    tgtSelect.value = product.id;

    // Masquer formulaire nouveau
    cancelNewTargetProduct(false);

    // Afficher carte sélectionnée
    document.getElementById('target_card_name').textContent = product.name;
    document.getElementById('target_card_unit').textContent = product.unit;
    document.getElementById('target_card_price').textContent = `${Number(product.price_sale).toLocaleString('fr-FR')} FCFA`;

    tgtWrapper.classList.add('d-none');
    tgtCard.classList.remove('d-none');
    tgtDropdown.style.display = 'none';

    // Remplir éventuellement le prix de vente
    if (product.price_sale > 0 && !document.getElementById('new_price_sale').value) {
        document.getElementById('new_price_sale').placeholder = `Actuel : ${product.price_sale} FCFA`;
    }

    calculatePreview();
}

function resetTargetSelection() {
    selectedTargetProduct = null;
    tgtSelect.value = '';
    tgtCard.classList.add('d-none');
    tgtWrapper.classList.remove('d-none');
    cancelNewTargetProduct(false);
    tgtSearchInput.value = '';
    tgtSearchInput.focus();
    renderTargetDropdown('');
    calculatePreview();
}

function triggerNewTargetProduct(initialName = '') {
    selectedTargetProduct = null;
    tgtSelect.value = 'NEW';
    
    tgtWrapper.classList.add('d-none');
    tgtCard.classList.add('d-none');
    tgtDropdown.style.display = 'none';
    
    newFieldsDiv.classList.remove('d-none');
    newNameInput.required = true;
    newUnitInput.required = true;
    newCatSelect.required = true;

    if (initialName) {
        newNameInput.value = initialName;
    }
    newNameInput.focus();
    calculatePreview();
}

function cancelNewTargetProduct(restoreSearch = true) {
    newFieldsDiv.classList.add('d-none');
    newNameInput.required = false;
    newUnitInput.required = false;
    newCatSelect.required = false;

    if (restoreSearch) {
        tgtSelect.value = '';
        tgtWrapper.classList.remove('d-none');
        tgtSearchInput.focus();
    }
    calculatePreview();
}

tgtSearchInput.addEventListener('input', function() {
    renderTargetDropdown(this.value);
});

tgtSearchInput.addEventListener('focus', function() {
    renderTargetDropdown(this.value);
});

// Fermer les menus si clic en dehors
document.addEventListener('click', function(e) {
    if (!srcWrapper.contains(e.target)) {
        srcDropdown.style.display = 'none';
    }
    if (!tgtWrapper.contains(e.target)) {
        tgtDropdown.style.display = 'none';
    }
});

// -------------------------------------------------------------
// CALCUL DE L'APERÇU
// -------------------------------------------------------------
function calculatePreview() {
    const previewBox = document.getElementById('conversion_preview_box');
    const previewText = document.getElementById('conversion_preview_text');
    
    const qty = parseInt(document.getElementById('source_quantity').value) || 0;
    const mult = parseInt(document.getElementById('multiplier').value) || 0;
    
    if (selectedSourceProduct && qty > 0 && mult > 0) {
        const totalObtained = qty * mult;
        const totalSrcPurchase = selectedSourceProduct.price_purchase * qty;
        const unitCostEstimate = mult > 0 ? (selectedSourceProduct.price_purchase / mult) : 0;
        
        let tgtName = 'unités de l\'article cible';
        if (selectedTargetProduct) {
            tgtName = `${selectedTargetProduct.name} (${selectedTargetProduct.unit})`;
        } else if (tgtSelect.value === 'NEW' && newNameInput.value) {
            tgtName = `${newNameInput.value} (${newUnitInput.value || 'UNITE'})`;
        }

        previewText.innerHTML = `
            <strong>${qty} ${escapeHtml(selectedSourceProduct.unit)}</strong> de <em>"${escapeHtml(selectedSourceProduct.name)}"</em> vont être transformées en 
            <strong class="text-success">${totalObtained}</strong> ${escapeHtml(tgtName)}.<br>
            <span class="text-muted">Prix d'achat fractionné estimé : <strong>${Number(unitCostEstimate).toFixed(2)} FCFA</strong> par unité cible.</span>
        `;
        previewBox.classList.remove('d-none');
    } else {
        previewBox.classList.add('d-none');
    }
}

// -------------------------------------------------------------
// VALIDATION & CONFIRMATION
// -------------------------------------------------------------
function validateAndConfirmConversion() {
    const srcId = srcSelect.value;
    const tgtId = tgtSelect.value;

    if (!srcId) {
        alert("Veuillez sélectionner un article source avec du stock disponible.");
        srcSearchInput.focus();
        return false;
    }

    if (!tgtId) {
        alert("Veuillez sélectionner ou créer un article cible.");
        tgtSearchInput.focus();
        return false;
    }

    if (tgtId === 'NEW' && !newNameInput.value.trim()) {
        alert("Veuillez saisir la désignation du nouvel article cible.");
        newNameInput.focus();
        return false;
    }

    return confirm("Confirmer cette opération de fractionnement / déconditionnement de stock ?");
}
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
