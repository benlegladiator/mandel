<?php
require_once __DIR__ . '/includes/header.php';

// Sécurité : Seul l'administrateur a le droit de modifier une facture existante
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut modifier une facture.";
    header("Location: orders.php");
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = "Identifiant de facture invalide.";
    header("Location: orders.php");
    exit;
}

// 1. Charger la commande existante
$stmt_order = $pdo->prepare("
    SELECT o.*, 
           c.name as client_name, 
           sup.name as supplier_name,
           sh.name as shop_name,
           u.name as user_name
    FROM orders o
    LEFT JOIN shops sh ON o.shop_id = sh.id
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN suppliers sup ON o.supplier_id = sup.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.id = ?
");
$stmt_order->execute([$id]);
$order = $stmt_order->fetch();

if (!$order) {
    $_SESSION['error_message'] = "Facture " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))) . " introuvable.";
    header("Location: orders.php");
    exit;
}

$type = $order['type'];
$is_historical_order = !empty($order['is_historical']);
$error = '';
$success = '';

// 2. Charger les articles actuels de la commande
$stmt_existing_items = $pdo->prepare("
    SELECT oi.*, p.name as product_name, p.unit as product_unit, p.sku as product_sku, 
           p.price_sale as catalog_price_sale, p.price_purchase as catalog_price_purchase, 
           p.margin_percent, c.name as category_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$stmt_existing_items->execute([$id]);
$existing_items = $stmt_existing_items->fetchAll();

// Si aucun article et que c'est une ancienne créance, marquer comme historique
if (empty($existing_items)) {
    $is_historical_order = true;
}

// 3. Charger les contacts (Clients ou Fournisseurs)
if ($type === 'SALE') {
    $contacts = $pdo->query("SELECT id, name, phone, address FROM clients ORDER BY name ASC")->fetchAll();
} else {
    $contacts = $pdo->query("SELECT id, name, phone, address, email FROM suppliers ORDER BY name ASC")->fetchAll();
}

// 4. Déterminer la boutique sélectionnée (par défaut celle de la commande)
$selected_shop_id = isset($_POST['shop_id']) ? intval($_POST['shop_id']) : intval($order['shop_id']);

// 5. Charger tous les produits du catalogue avec le stock actuel dans la boutique
$products_stmt = $pdo->prepare("
    SELECT p.*, COALESCE(s.quantity, 0) as stock_qty, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = ?
    ORDER BY p.name ASC
");
$products_stmt->execute([$selected_shop_id]);
$products_catalog = $products_stmt->fetchAll();

// Créer une map des quantités déjà présentes dans cette commande pour ajuster le stock virtuel disponible lors de l'édition
$existing_item_qty_map = [];
if ($selected_shop_id === intval($order['shop_id'])) {
    foreach ($existing_items as $item) {
        $existing_item_qty_map[$item['product_id']] = intval($item['quantity']);
    }
}

// Ajuster le stock disponible affiché dans le catalogue pour cette session d'édition
foreach ($products_catalog as &$prod) {
    if (isset($existing_item_qty_map[$prod['id']]) && $type === 'SALE' && in_array($order['status'], ['COMPLETED', 'PENDING_APPROVAL'])) {
        // Le stock physique actuel + ce qui était réservé par cette commande
        $prod['stock_qty'] = intval($prod['stock_qty']) + $existing_item_qty_map[$prod['id']];
    }
}
unset($prod);

// 6. Traitement du formulaire POST de modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_shop_id = isset($_POST['shop_id']) ? intval($_POST['shop_id']) : intval($order['shop_id']);
    $contact_id = isset($_POST['contact_id']) ? intval($_POST['contact_id']) : 0;
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'CASH';
    $status = isset($_POST['status']) ? trim($_POST['status']) : $order['status'];
    
    // Nettoyer et formater la date pour MySQL
    if (!empty($_POST['order_date'])) {
        $raw_date = str_replace('T', ' ', trim($_POST['order_date']));
        if (strlen($raw_date) === 16) {
            $raw_date .= ':00';
        }
        $order_date = date('Y-m-d H:i:s', strtotime($raw_date));
    } else {
        $order_date = date('Y-m-d H:i:s', strtotime($order['created_at']));
    }

    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $discount_reason = isset($_POST['discount_reason']) ? trim($_POST['discount_reason']) : null;
    $is_formal = (isset($_POST['is_formal']) && $_POST['is_formal'] == '1') ? 1 : 0;

    $item_product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $item_quantities = isset($_POST['quantities']) ? $_POST['quantities'] : [];
    $item_prices = isset($_POST['prices']) ? $_POST['prices'] : [];

    if (empty($item_product_ids) && !$is_historical_order) {
        $error = "Veuillez ajouter au moins un produit à la facture.";
    } else {
        try {
            $pdo->beginTransaction();

            $subtotal = 0.00;
            $items_to_save = [];

            if (!empty($item_product_ids)) {
                // Valider chaque article
                for ($i = 0; $i < count($item_product_ids); $i++) {
                    $p_id = intval($item_product_ids[$i]);
                    $qty = intval($item_quantities[$i]);
                    $price = floatval($item_prices[$i]);

                    if ($p_id <= 0 || $qty <= 0 || $price < 0) {
                        throw new Exception("Données d'article incorrectes à la ligne " . ($i + 1));
                    }

                    $total_price = $qty * $price;
                    $subtotal += $total_price;

                    $items_to_save[] = [
                        'product_id' => $p_id,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'total_price' => $total_price
                    ];
                }
            } elseif ($is_historical_order) {
                $subtotal = isset($_POST['total_amount_manual']) ? floatval($_POST['total_amount_manual']) : floatval($order['total_amount']);
            }

            // Calcul fiscal (TVA / IR / Net à Payer)
            $tax_amount = 0.00;
            $ir_amount = 0.00;
            $total_amount = $subtotal;
            $nap_amount = $subtotal;

            if ($is_formal && $type === 'SALE' && !empty($items_to_save)) {
                $tva_rate = isset($_POST['tva_rate']) ? floatval($_POST['tva_rate']) : 19.25;
                $ir_rate = isset($_POST['ir_rate']) ? floatval($_POST['ir_rate']) : 5.5;

                $tax_amount = $subtotal * ($tva_rate / 100);
                $ir_amount = $subtotal * ($ir_rate / 100);
                $total_amount = $subtotal + $tax_amount;
                $nap_amount = $total_amount - $ir_amount;
            }

            // Calcul du paiement / crédit
            $due_date = null;
            $amount_paid = 0.00;
            $payment_status = 'PAID';

            if ($payment_method === 'CREDIT') {
                $due_date = isset($_POST['due_date']) && !empty($_POST['due_date']) ? date('Y-m-d', strtotime($_POST['due_date'])) : null;
                $amount_paid = isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0.00;
                $payment_status = ($amount_paid > 0) ? 'PARTIAL' : 'UNPAID';
                if ($amount_paid >= $total_amount || ($is_formal && $amount_paid >= $nap_amount)) {
                    $payment_status = 'PAID';
                    $payment_method = 'CASH';
                }
            } else {
                $amount_paid = ($payment_method === 'VIREMENT' && $is_formal) ? $nap_amount : $total_amount;
            }

            // Gestion de l'ajout rapide de nouveau contact
            if (isset($_POST['new_contact_checkbox']) && $_POST['new_contact_checkbox'] == '1') {
                $new_name = trim($_POST['new_contact_name'] ?? '');
                $new_phone = trim($_POST['new_contact_phone'] ?? '');
                $new_address = trim($_POST['new_contact_address'] ?? '');

                if (empty($new_name)) {
                    throw new Exception("Le nom du nouveau contact est obligatoire.");
                }

                if ($type === 'SALE') {
                    $stmt_new_c = $pdo->prepare("INSERT INTO clients (name, phone, address) VALUES (?, ?, ?)");
                    $stmt_new_c->execute([$new_name, $new_phone, $new_address]);
                    $contact_id = $pdo->lastInsertId();
                } else {
                    $new_email = trim($_POST['new_contact_email'] ?? '');
                    $stmt_new_c = $pdo->prepare("INSERT INTO suppliers (name, phone, address, email) VALUES (?, ?, ?, ?)");
                    $stmt_new_c->execute([$new_name, $new_phone, $new_address, $new_email]);
                    $contact_id = $pdo->lastInsertId();
                }
            }

            $client_id = ($type === 'SALE' && $contact_id > 0) ? $contact_id : null;
            $supplier_id = ($type === 'PURCHASE' && $contact_id > 0) ? $contact_id : null;

            // =========================================================================
            // ÉTAPE 1 : ANNULER L'IMPACT DE STOCK DE L'ANCIENNE VERSION DE LA COMMANDE
            // =========================================================================
            $old_shop_id = intval($order['shop_id']);
            $old_status = $order['status'];
            $old_type = $order['type'];

            $stmt_revert_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ");

            foreach ($existing_items as $old_item) {
                $old_pid = intval($old_item['product_id']);
                $old_qty = intval($old_item['quantity']);

                if ($old_type === 'SALE' && in_array($old_status, ['COMPLETED', 'PENDING_APPROVAL'])) {
                    // On remet l'ancienne quantité en stock (+old_qty)
                    $stmt_revert_stock->execute([$old_pid, $old_shop_id, $old_qty, $old_qty]);
                } elseif ($old_type === 'PURCHASE' && $old_status === 'COMPLETED') {
                    // On retire l'ancienne quantité reçue (-old_qty)
                    $stmt_revert_stock->execute([$old_pid, $old_shop_id, -$old_qty, -$old_qty]);
                }
            }

            // =========================================================================
            // ÉTAPE 2 : SUPPRIMER LES ANCIENNES LIGNES D'ARTICLES
            // =========================================================================
            $stmt_del_items = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
            $stmt_del_items->execute([$id]);

            // =========================================================================
            // ÉTAPE 3 : ENREGISTRER LES NOUVELLES LIGNES ET APPLIQUER LE NOUVEAU STOCK
            // =========================================================================
            $stmt_insert_item = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) 
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt_apply_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ");

            foreach ($items_to_save as $new_item) {
                // Insérer la nouvelle ligne
                $stmt_insert_item->execute([
                    $id,
                    $new_item['product_id'],
                    $new_item['quantity'],
                    $new_item['unit_price'],
                    $new_item['total_price']
                ]);

                // Appliquer le nouvel impact de stock
                if ($type === 'SALE' && in_array($status, ['COMPLETED', 'PENDING_APPROVAL'])) {
                    // Déduire la nouvelle quantité (-new_qty)
                    $stmt_apply_stock->execute([
                        $new_item['product_id'],
                        $target_shop_id,
                        -$new_item['quantity'],
                        -$new_item['quantity']
                    ]);
                } elseif ($type === 'PURCHASE' && $status === 'COMPLETED') {
                    // Ajouter la nouvelle quantité (+new_qty)
                    $stmt_apply_stock->execute([
                        $new_item['product_id'],
                        $target_shop_id,
                        $new_item['quantity'],
                        $new_item['quantity']
                    ]);
                }
            }

            // =========================================================================
            // ÉTAPE 4 : METTRE À JOUR L'EN-TÊTE DE LA COMMANDE
            // =========================================================================
            $stmt_update_order = $pdo->prepare("
                UPDATE orders SET 
                    shop_id = ?, 
                    client_id = ?, 
                    supplier_id = ?, 
                    total_amount = ?, 
                    subtotal = ?, 
                    tax_amount = ?, 
                    ir_amount = ?, 
                    nap_amount = ?, 
                    payment_method = ?, 
                    status = ?, 
                    due_date = ?, 
                    amount_paid = ?, 
                    payment_status = ?, 
                    discount_reason = ?, 
                    is_formal = ?, 
                    notes = ?,
                    created_at = ?
                WHERE id = ?
            ");

            $stmt_update_order->execute([
                $target_shop_id,
                $client_id,
                $supplier_id,
                $total_amount,
                $subtotal,
                $tax_amount,
                $ir_amount,
                $nap_amount,
                $payment_method,
                $status,
                $due_date,
                $amount_paid,
                $payment_status,
                $discount_reason,
                $is_formal,
                $notes,
                $order_date,
                $id
            ]);

            // =========================================================================
            // ÉTAPE 5 : SYNCHRONISER LES ENTRÉES/SORTIES DE CAISSE
            // =========================================================================
            // Supprimer l'ancienne transaction de caisse
            $stmt_del_cash = $pdo->prepare("DELETE FROM cash_transactions WHERE reference_id = ? AND reference_type = 'ORDER'");
            $stmt_del_cash->execute([$id]);

            // Réinsérer la nouvelle transaction de caisse si la facture est validée
            if ($status === 'COMPLETED') {
                if ($type === 'SALE' && $payment_method === 'CASH') {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER', ?)
                    ");
                    $stmt_cash->execute([
                        $target_shop_id,
                        $order['user_id'] ?? $_SESSION['user_id'],
                        $total_amount,
                        "Vente en espèces " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))),
                        $id,
                        $order_date
                    ]);
                } elseif ($type === 'SALE' && $payment_method === 'CREDIT' && $amount_paid > 0) {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER', ?)
                    ");
                    $stmt_cash->execute([
                        $target_shop_id,
                        $order['user_id'] ?? $_SESSION['user_id'],
                        $amount_paid,
                        "Acompte en espèces sur vente à crédit " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))),
                        $id,
                        $order_date
                    ]);
                } elseif ($type === 'PURCHASE' && $payment_method === 'CASH') {
                    // L'achat est payé depuis la Caisse Boss (NULL) — le patron paye les fournisseurs
                    // On précise dans la description quelle boutique a été ravitaillée
                    $shop_name_label = '';
                    foreach ($all_shops as $sh) {
                        if ($sh['id'] == $target_shop_id) { $shop_name_label = $sh['name']; break; }
                    }
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                        VALUES (NULL, ?, 'OUT', ?, ?, ?, 'PURCHASE', ?)
                    ");
                    $stmt_cash->execute([
                        $order['user_id'] ?? $_SESSION['user_id'],
                        $total_amount,
                        "Achat de ravitaillement fournisseur [" . ($shop_name_label ?: 'Boutique') . "] " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))),
                        $id,
                        $order_date
                    ]);
                }
            }

            $pdo->commit();

            $_SESSION['success_message'] = "La facture <strong>" . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))) . "</strong> a été modifiée avec succès. Les stocks et la caisse ont été synchronisés.";
            header("Location: invoice.php?id=" . $id . "&success=updated");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Erreur lors de la modification de la facture : " . $e->getMessage();
        }
    }
}

// Date initiale de la facture conservée par défaut
$original_order_date = date('Y-m-d\TH:i', strtotime($order['created_at']));
$current_order_date = isset($_POST['order_date']) ? htmlspecialchars($_POST['order_date']) : $original_order_date;
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="orders.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                    <i class="fa-solid fa-arrow-left me-1"></i>Retour
                </a>
                <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
                    <i class="fa-solid fa-pen-to-square text-warning me-2"></i>Modifier la Facture <span class="text-primary"><?= htmlspecialchars($order['reference'] ?? 'FAC-'.sprintf("%05d", $order['id'])) ?></span>
                </h1>
            </div>
            <p class="text-secondary small mb-0">
                Type : <strong class="<?= $type === 'SALE' ? 'text-success' : 'text-primary' ?>"><?= $type === 'SALE' ? 'VENTE CLIENT' : 'ACHAT FOURNISSEUR' ?></strong> 
                | Créée le <?= date('d/m/Y à H:i', strtotime($order['created_at'])) ?> par <strong><?= htmlspecialchars($order['user_name'] ?? 'Admin') ?></strong>
            </p>
        </div>
        
        <div class="d-flex gap-2">
            <a href="invoice.php?id=<?= $order['id'] ?>" class="btn btn-outline-primary btn-sm" style="border-radius: 8px;" target="_blank">
                <i class="fa-solid fa-eye me-1"></i>Visualiser la facture
            </a>
            <a href="order-delete.php?id=<?= $order['id'] ?>" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;" onclick="return confirm('Êtes-vous sûr de vouloir supprimer définitivement cette facture ? Les stocks et la caisse seront automatiquement réajustés.');">
                <i class="fa-solid fa-trash me-1"></i>Supprimer
            </a>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm border-0 mb-4" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<form action="order-edit.php?id=<?= $order['id'] ?>" method="POST" id="orderEditForm">
    <div class="row g-4">
        <!-- Informations Générales & Règlement (Colonne Gauche) -->
        <div class="col-12 col-lg-4">
            <div class="glass-card p-4 mb-4">
                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-sliders text-primary me-2"></i>Paramètres de la Facture</h5>

                <!-- Choix de la Boutique / Dépôt -->
                <div class="mb-3">
                    <label for="shop_id" class="form-label fw-semibold text-secondary">Dépôt / Boutique *</label>
                    <select class="form-select form-control-custom" id="shop_id" name="shop_id" required>
                        <?php foreach ($all_shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $selected_shop_id === intval($sh['id']) ? 'selected' : '' ?>>
                                🏢 <?= htmlspecialchars($sh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date & Heure de la commande -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="order_date" class="form-label fw-semibold text-secondary mb-0">Date & Heure de la Transaction *</label>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary btn-xs py-0 px-1.5" style="font-size: 0.72rem; border-radius: 4px;" onclick="setOrderDateNow()">
                                <i class="fa-solid fa-clock me-1"></i>Aujourd'hui
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-0 px-1.5 ms-1" style="font-size: 0.72rem; border-radius: 4px;" onclick="setOrderDateOriginal()" title="Rétablir la date d'origine de la facture">
                                <i class="fa-solid fa-rotate-left me-1"></i>Date initiale
                            </button>
                        </div>
                    </div>
                    <input type="datetime-local" class="form-control form-control-custom" id="order_date" name="order_date" value="<?= $current_order_date ?>" required>
                    <small class="text-muted" style="font-size: 0.75rem;">Date d'origine de la vente conservée. Ajustable si besoin.</small>
                </div>

                <!-- Sélection du Contact (Client ou Fournisseur) -->
                <div class="mb-3">
                    <label for="contact_id" class="form-label fw-semibold text-secondary">
                        <?= $type === 'SALE' ? 'Client' : 'Fournisseur' ?> *
                    </label>
                    <select class="form-select form-control-custom" id="contact_id" name="contact_id">
                        <option value="0">-- Client Comptoir / Inconnu --</option>
                        <?php 
                        $active_contact_id = ($type === 'SALE') ? intval($order['client_id']) : intval($order['supplier_id']);
                        foreach ($contacts as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $active_contact_id === intval($c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> <?= !empty($c['phone']) ? '(' . htmlspecialchars($c['phone']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Option Facture Légale / Formelle -->
                <?php if ($type === 'SALE'): ?>
                <div class="mb-3 p-3 bg-light border rounded-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_formal" name="is_formal" value="1" <?= $order['is_formal'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-slate-800" for="is_formal">Facture Légale / Formelle (TVA & IR)</label>
                    </div>
                    <div id="formal_fields" style="display: <?= $order['is_formal'] ? 'block' : 'none' ?>;" class="mt-2 p-2 bg-light border rounded">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="small text-secondary fw-semibold">Taux TVA (%)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="tva_rate" name="tva_rate" value="19.25">
                            </div>
                            <div class="col-6">
                                <label class="small text-secondary fw-semibold">Taux IR (%)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" id="ir_rate" name="ir_rate" value="5.5">
                            </div>
                        </div>
                        <div class="small text-secondary mt-1" style="font-size: 0.75rem; line-height: 1.2;">
                            Le PU = HT. TVA s'ajoute au total, IR se déduit si virement (Net à Payer).
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Mode de Règlement -->
                <div class="mb-3">
                    <label for="payment_method" class="form-label fw-semibold text-secondary">Mode de Règlement</label>
                    <select class="form-select form-control-custom" id="payment_method" name="payment_method">
                        <option value="CASH" <?= $order['payment_method'] === 'CASH' ? 'selected' : '' ?>>Espèces (Cash)</option>
                        <option value="MOBILE_MONEY" <?= $order['payment_method'] === 'MOBILE_MONEY' ? 'selected' : '' ?>>Mobile Money (OM/MOMO)</option>
                        <option value="VIREMENT" <?= $order['payment_method'] === 'VIREMENT' ? 'selected' : '' ?>>Virement Bancaire / Chèque</option>
                        <option value="CARD" <?= $order['payment_method'] === 'CARD' ? 'selected' : '' ?>>Carte Bancaire</option>
                        <option value="CREDIT" <?= $order['payment_method'] === 'CREDIT' ? 'selected' : '' ?>>Crédit / Dette</option>
                    </select>
                </div>

                <!-- Options spécifiques au Crédit -->
                <div id="credit_fields" style="display: <?= $order['payment_method'] === 'CREDIT' ? 'block' : 'none' ?>;" class="p-3 bg-warning-subtle bg-opacity-10 border border-warning-subtle rounded-3 mb-3">
                    <div class="mb-2">
                        <label for="due_date" class="form-label small fw-bold text-warning-emphasis"><i class="fa-solid fa-calendar-day me-1"></i>Date Limite de Paiement</label>
                        <input type="date" class="form-control form-control-custom py-1 px-2" id="due_date" name="due_date" value="<?= htmlspecialchars($order['due_date'] ?? date('Y-m-d', strtotime('+7 days'))) ?>">
                    </div>
                    <div class="mb-0">
                        <label for="amount_paid" class="form-label small fw-bold text-warning-emphasis"><i class="fa-solid fa-money-bill-wave me-1"></i>Acompte Déjà Encaissé (FCFA)</label>
                        <input type="number" step="0.01" class="form-control form-control-custom py-1 px-2" id="amount_paid" name="amount_paid" value="<?= floatval($order['amount_paid']) ?>">
                    </div>
                </div>

                <!-- Statut de la Commande -->
                <div class="mb-3">
                    <label for="status" class="form-label fw-semibold text-secondary">Statut de la Facture</label>
                    <select class="form-select form-control-custom" id="status" name="status">
                        <option value="COMPLETED" <?= $order['status'] === 'COMPLETED' ? 'selected' : '' ?>>✅ Validée / Terminée</option>
                        <option value="PENDING_APPROVAL" <?= $order['status'] === 'PENDING_APPROVAL' ? 'selected' : '' ?>>⏳ En attente de validation</option>
                        <option value="REJECTED" <?= $order['status'] === 'REJECTED' ? 'selected' : '' ?>>❌ Rejetée / Annulée</option>
                    </select>
                </div>

                <!-- Motif de Vente Exceptionnelle -->
                <div id="discount_fields" class="p-3 bg-danger-subtle bg-opacity-10 border border-danger-subtle rounded-3 mb-3" style="display: <?= !empty($order['discount_reason']) ? 'block' : 'none' ?>;">
                    <div class="mb-0">
                        <label for="discount_reason" class="form-label small fw-bold text-danger-emphasis"><i class="fa-solid fa-triangle-exclamation me-1"></i>Justification / Motif Dérogation</label>
                        <textarea class="form-control form-control-custom py-1 px-2" id="discount_reason" name="discount_reason" rows="2" placeholder="Ex: Accord du Boss, remise client fidèle..."><?= htmlspecialchars($order['discount_reason'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Notes / Observations -->
                <div class="mb-3">
                    <label for="notes" class="form-label fw-semibold text-secondary">Observations / Remarques</label>
                    <textarea class="form-control form-control-custom" id="notes" name="notes" rows="2" placeholder="Remarques éventuelles..."><?= htmlspecialchars($order['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Lignes d'Articles (Colonne Droite) -->
        <div class="col-12 col-lg-8">
            <div class="glass-card p-4 h-100 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div>
                            <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-list-check text-primary me-2"></i>Articles de la Facture</h5>
                            <small class="text-secondary">Modifiez les quantités, les prix unitaires ou ajoutez de nouvelles lignes.</small>
                        </div>
                        <div id="grandTotalContainer" class="badge bg-success-subtle text-success fs-6 py-2 px-3 border border-success-subtle" style="border-radius: 10px;">
                            <span class="small text-secondary me-1">Total :</span>
                            <span class="fw-extrabold text-success fs-5"><span id="grandTotalDisplay"><?= number_format($order['total_amount'], 0, '.', ' ') ?></span> FCFA</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-accent btn-sm" id="addRowBtn">
                        <i class="fa-solid fa-plus me-1"></i>Ajouter une ligne
                    </button>
                </div>

                <!-- Récapitulatif Fiscal si Vente Légale -->
                <div id="formalSummaryBox" class="p-3 bg-light rounded-3 border mb-3" style="display: <?= $order['is_formal'] ? 'block' : 'none' ?>;">
                    <div class="row g-2 text-center text-md-start">
                        <div class="col-6 col-md-3">
                            <span class="small text-secondary d-block">Sous-total HT</span>
                            <strong class="text-dark" id="displaySubtotal">0 FCFA</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="small text-secondary d-block">TVA (+19.25%)</span>
                            <strong class="text-primary" id="displayTax">0 FCFA</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="small text-secondary d-block">Total TTC</span>
                            <strong class="text-success" id="displayTTC">0 FCFA</strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="small text-secondary d-block">Net à Payer (IR déduit)</span>
                            <strong class="text-indigo fw-bold" id="displayNAP">0 FCFA</strong>
                        </div>
                    </div>
                </div>

                <!-- Assistant de Recherche Express d'article -->
                <?php if ($is_historical_order && empty($existing_items)): ?>
                    <div class="alert alert-warning border-0 shadow-sm mb-4" style="border-radius: 12px;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fa-solid fa-clock-rotate-left fa-lg text-warning"></i>
                            <strong class="text-dark fs-6">Solde Antérieur / Ancienne Créance (Sans détail d'articles)</strong>
                        </div>
                        <p class="small text-secondary mb-3">
                            Cette facture a été enregistrée comme une dette/créance globale sans impacter les stocks de marchandises. Vous pouvez modifier son montant total ci-dessous :
                        </p>
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-sm-6 col-md-6">
                                <label for="total_amount_manual" class="form-label small fw-bold text-dark mb-1">Montant Total de la Créance (FCFA) *</label>
                                <input type="number" step="0.01" class="form-control form-control-custom fw-bold text-primary fs-5" id="total_amount_manual" name="total_amount_manual" value="<?= floatval($order['total_amount']) ?>" required>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mb-4 position-relative">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-secondary" style="border-radius: 10px 0 0 10px;"><i class="fa-solid fa-magnifying-glass text-warning"></i></span>
                        <input type="text" class="form-control form-control-custom border-start-0 ps-0" id="quickProductSearch" placeholder="Recherche rapide d'un article à ajouter... (ex: Couché 150g, Offset, A4...)" style="border-radius: 0 10px 10px 0;">
                    </div>
                    <div id="quickSearchResults" class="list-group position-absolute w-100 shadow-lg border mt-1 d-none" style="z-index: 1050; max-height: 220px; overflow-y: auto; border-radius: 8px;">
                        <!-- Résultats dynamiques -->
                    </div>
                </div>

                <!-- Template unique des options de produits pour économiser la mémoire et accélérer l'affichage -->
                <template id="order_product_options_template">
                    <option value="">-- Choisir un produit --</option>
                    <?php 
                    $current_cat = '';
                    foreach ($products_catalog as $p): 
                        if ($p['category_name'] !== $current_cat) {
                            if ($current_cat !== '') echo '</optgroup>';
                            $current_cat = $p['category_name'];
                            echo '<optgroup label="' . htmlspecialchars($current_cat ?: 'Sans Famille') . '">';
                        }
                    ?>
                        <option value="<?= $p['id'] ?>" 
                                data-stock="<?= $p['stock_qty'] ?>" 
                                data-purchase="<?= $p['price_purchase'] ?>" 
                                data-sale="<?= $p['price_sale'] ?>" 
                                data-margin="<?= $p['margin_percent'] ?? 5 ?>">
                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                    <?php if ($current_cat !== '') echo '</optgroup>'; ?>
                </template>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 180px;">Désignation Produit</th>
                                <th style="width: 75px; min-width: 70px;" class="text-center">Stock</th>
                                <th style="width: 90px; min-width: 85px;" class="text-center">Qté</th>
                                <th style="width: 125px; min-width: 110px;" class="text-end">Prix Unitaire</th>
                                <th style="width: 115px; min-width: 100px;" class="text-end">Total</th>
                                <th style="width: 45px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsTableBody">
                            <!-- Lignes existantes injectées par PHP -->
                            <?php foreach ($existing_items as $idx => $it): ?>
                                <tr>
                                    <td>
                                        <select class="form-select form-control-custom product-select" name="product_ids[]" data-selected-id="<?= $it['product_id'] ?>" required>
                                            <option value="<?= $it['product_id'] ?>" 
                                                    data-stock="<?= $it['stock_qty'] ?? 0 ?>" 
                                                    data-purchase="<?= $it['catalog_price_purchase'] ?? 0 ?>" 
                                                    data-sale="<?= $it['catalog_price_sale'] ?? 0 ?>" 
                                                    data-margin="<?= $it['margin_percent'] ?? 5 ?>" selected>
                                                <?= htmlspecialchars($it['product_name']) ?> (<?= htmlspecialchars($it['product_unit']) ?>)
                                            </option>
                                        </select>
                                    </td>
                                    <td class="text-center fw-bold text-secondary stock-display">
                                        <?php
                                        $p_found = null;
                                        foreach ($products_catalog as $p) {
                                            if ($p['id'] == $it['product_id']) { $p_found = $p; break; }
                                        }
                                        echo $p_found ? $p_found['stock_qty'] : '-';
                                        ?>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-custom text-center qty-input" name="quantities[]" min="1" value="<?= intval($it['quantity']) ?>" style="color: #0f172a !important; background-color: #ffffff !important; font-weight: 700;" required>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" class="form-control form-control-custom price-input text-end" name="prices[]" value="<?= floatval($it['unit_price']) ?>" style="color: #0f172a !important; background-color: #ffffff !important; font-weight: 600;" required>
                                    </td>
                                    <td class="fw-bold text-slate-700 text-end row-total-display"><?= number_format($it['total_price'], 0, '.', ' ') ?> FCFA</td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-row-btn" style="border-radius: 8px;">
                                            <i class="fa-solid fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-center py-4 text-secondary <?= empty($existing_items) ? '' : 'd-none' ?>" id="emptyState">
                    <i class="fa-regular fa-folder-open fa-3x mb-2 text-muted"></i>
                    <p class="mb-0">Aucun produit dans la liste. Cliquez sur "Ajouter une ligne".</p>
                </div>

                <!-- BOUTONS DE VALIDATION ET ANNULER EN BAS DE LA LISTE DES ARTICLES -->
                <div class="mt-auto pt-4 border-top d-flex align-items-center justify-content-end gap-3 flex-wrap">
                    <a href="orders.php" class="btn btn-outline-secondary px-4 py-2.5 fw-semibold" style="border-radius: 10px;">
                        Annuler
                    </a>
                    <button type="submit" class="btn btn-warning text-dark px-5 py-2.5 fw-bold shadow-sm" style="border-radius: 10px; font-size: 1.05rem;">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Enregistrer les Modifications
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const productsCatalog = <?= json_encode($products_catalog) ?>;
const transactionType = '<?= $type ?>';

document.addEventListener('DOMContentLoaded', function() {
    const tableBody = document.getElementById('itemsTableBody');
    const addRowBtn = document.getElementById('addRowBtn');
    const emptyState = document.getElementById('emptyState');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');
    const paymentMethodSelect = document.getElementById('payment_method');
    const creditFields = document.getElementById('credit_fields');
    const isFormalSwitch = document.getElementById('is_formal');
    const formalFields = document.getElementById('formal_fields');
    const formalSummaryBox = document.getElementById('formalSummaryBox');
    const discountFields = document.getElementById('discount_fields');
    const tvaRateInput = document.getElementById('tva_rate');
    const irRateInput = document.getElementById('ir_rate');

    const productOptionsTemplate = document.getElementById('order_product_options_template');
    const cachedOptionsHtml = productOptionsTemplate ? productOptionsTemplate.innerHTML : '';

    function getProductOptionsHtml(selectedId = 0) {
        if (!cachedOptionsHtml) return '<option value="">-- Choisir --</option>';
        if (!selectedId) return cachedOptionsHtml;
        return cachedOptionsHtml.replace(`value="${selectedId}"`, `value="${selectedId}" selected`);
    }

    // Activer les écouteurs et peupler les listes déroulantes existantes
    tableBody.querySelectorAll('tr').forEach(tr => {
        const select = tr.querySelector('.product-select');
        if (select) {
            const selectedId = parseInt(select.getAttribute('data-selected-id') || select.value || 0);
            select.innerHTML = getProductOptionsHtml(selectedId);
        }
        setupRowEvents(tr);
    });

    checkEmptyState();
    recalculateGrandTotal();

    addRowBtn.addEventListener('click', addNewRow);

    if (paymentMethodSelect) {
        paymentMethodSelect.addEventListener('change', function() {
            if (this.value === 'CREDIT') {
                creditFields.style.display = 'block';
            } else {
                creditFields.style.display = 'none';
            }
            recalculateGrandTotal();
        });
    }

    if (isFormalSwitch) {
        isFormalSwitch.addEventListener('change', function() {
            if (this.checked) {
                formalFields.style.display = 'block';
                if (formalSummaryBox) formalSummaryBox.style.display = 'block';
            } else {
                formalFields.style.display = 'none';
                if (formalSummaryBox) formalSummaryBox.style.display = 'none';
            }
            recalculateGrandTotal();
        });
    }

    if (tvaRateInput) tvaRateInput.addEventListener('input', recalculateGrandTotal);
    if (irRateInput) irRateInput.addEventListener('input', recalculateGrandTotal);

    function checkEmptyState() {
        if (tableBody.children.length === 0) {
            emptyState.classList.remove('d-none');
        } else {
            emptyState.classList.add('d-none');
        }
    }

    function addNewRow() {
        const tr = document.createElement('tr');
        
        tr.innerHTML = `
            <td>
                <select class="form-select form-control-custom product-select" name="product_ids[]" required>
                    ${getProductOptionsHtml()}
                </select>
            </td>
            <td class="text-center fw-bold text-secondary stock-display">-</td>
            <td>
                <input type="number" class="form-control form-control-custom text-center qty-input" name="quantities[]" min="1" value="1" style="color: #0f172a !important; background-color: #ffffff !important; font-weight: 700;" required>
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-custom price-input text-end" name="prices[]" value="0.00" style="color: #0f172a !important; background-color: #ffffff !important; font-weight: 600;" required>
            </td>
            <td class="fw-bold text-slate-700 text-end row-total-display">0 FCFA</td>
            <td class="text-end">
                <button type="button" class="btn btn-outline-danger btn-sm remove-row-btn" style="border-radius: 8px;">
                    <i class="fa-solid fa-times"></i>
                </button>
            </td>
        `;

        tableBody.appendChild(tr);
        checkEmptyState();
        setupRowEvents(tr);
        recalculateGrandTotal();
    }

    function addNewRowWithProduct(productId, stock, price) {
        const tr = document.createElement('tr');
        
        tr.innerHTML = `
            <td>
                <select class="form-select form-control-custom product-select" name="product_ids[]" required>
                    ${getProductOptionsHtml(productId)}
                </select>
            </td>
            <td class="text-center fw-bold text-secondary stock-display">${stock}</td>
            <td>
                <input type="number" class="form-control form-control-custom text-center qty-input" name="quantities[]" min="1" value="1" style="color: #0f172a !important; background-color: #ffffff !important; font-weight: 700;" required>
            </td>
            <td>
                <input type="number" step="0.01" class="form-control form-control-custom price-input text-end" name="prices[]" value="${price}" style="color: #0f172a !important; background-color: #ffffff !important; font-weight: 600;" required>
            </td>
            <td class="fw-bold text-slate-700 text-end row-total-display">${formatMoney(price)} FCFA</td>
            <td class="text-end">
                <button type="button" class="btn btn-outline-danger btn-sm remove-row-btn" style="border-radius: 8px;">
                    <i class="fa-solid fa-times"></i>
                </button>
            </td>
        `;

        tableBody.appendChild(tr);
        checkEmptyState();
        setupRowEvents(tr);
        
        recalculateGrandTotal();
        const qtyInput = tr.querySelector('.qty-input');
        if (qtyInput) {
            qtyInput.focus();
            qtyInput.select();
        }
    }

    // Fonction de normalisation pour recherche intelligente (insensible aux accents et majuscules)
    function normalizeSearch(str) {
        return (str || '').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();
    }

    // Gestion de la recherche rapide
    const quickSearch = document.getElementById('quickProductSearch');
    const quickResults = document.getElementById('quickSearchResults');

    if (quickSearch && quickResults) {
        quickSearch.addEventListener('input', function() {
            const rawQuery = this.value.trim();
            if (rawQuery.length < 1) {
                quickResults.classList.add('d-none');
                quickResults.innerHTML = '';
                return;
            }

            const searchTokens = normalizeSearch(rawQuery).split(/\s+/).filter(t => t.length > 0);

            const matches = productsCatalog.filter(p => {
                const targetText = normalizeSearch(
                    p.name + ' ' + (p.sku || '') + ' ' + p.id + ' ' + (p.unit || '') + ' ' + (p.category_name || '')
                );
                return searchTokens.every(token => targetText.includes(token));
            });

            if (matches.length === 0) {
                quickResults.innerHTML = '<div class="list-group-item text-muted text-center py-2 small">Aucun produit trouvé</div>';
                quickResults.classList.remove('d-none');
                return;
            }

            let html = '';
            matches.slice(0, 8).forEach(p => {
                const stock = parseInt(p.stock_qty);
                const price = transactionType === 'SALE' ? parseFloat(p.price_sale) : parseFloat(p.price_purchase);
                
                let badgeClass = 'bg-success-subtle text-success';
                if (stock === 0 && transactionType === 'SALE') {
                    badgeClass = 'bg-danger text-white';
                }

                html += `
                    <button type="button" class="list-group-item list-group-item-action py-2 px-3 d-flex justify-content-between align-items-center quick-add-item" 
                            data-id="${p.id}" data-stock="${stock}" data-price="${price}">
                        <div>
                            <span class="fw-bold text-slate-800">${escapeHtml(p.name)}</span><br>
                            <small class="text-secondary">Conditionnement: ${escapeHtml(p.unit)}</small>
                        </div>
                        <div class="text-end">
                            <span class="badge ${badgeClass} rounded-pill py-1 px-2 fw-semibold me-2">${stock} dispo</span>
                            <span class="fw-extrabold text-primary">${formatMoney(price)} FCFA</span>
                        </div>
                    </button>
                `;
            });

            quickResults.innerHTML = html;
            quickResults.classList.remove('d-none');

            const items = quickResults.querySelectorAll('.quick-add-item');
            items.forEach(item => {
                item.addEventListener('click', function() {
                    const id = parseInt(this.getAttribute('data-id'));
                    const stock = parseInt(this.getAttribute('data-stock'));
                    const price = parseFloat(this.getAttribute('data-price'));
                    
                    addNewRowWithProduct(id, stock, price);
                    quickSearch.value = '';
                    quickResults.innerHTML = '';
                    quickResults.classList.add('d-none');
                });
            });
        });

        document.addEventListener('click', function(e) {
            if (!quickSearch.contains(e.target) && !quickResults.contains(e.target)) {
                quickResults.classList.add('d-none');
            }
        });
    }

    function setupRowEvents(tr) {
        const productSelect = tr.querySelector('.product-select');
        const stockDisplay = tr.querySelector('.stock-display');
        const qtyInput = tr.querySelector('.qty-input');
        const priceInput = tr.querySelector('.price-input');
        const removeBtn = tr.querySelector('.remove-row-btn');

        productSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const stock = parseInt(selectedOption.getAttribute('data-stock'));
                const purchasePrice = parseFloat(selectedOption.getAttribute('data-purchase'));
                const salePrice = parseFloat(selectedOption.getAttribute('data-sale'));

                stockDisplay.textContent = stock;
                
                if (transactionType === 'SALE') {
                    priceInput.value = salePrice;
                } else {
                    priceInput.value = purchasePrice;
                }
            } else {
                stockDisplay.textContent = '-';
                priceInput.value = '0.00';
            }
            recalculateGrandTotal();
        });

        qtyInput.addEventListener('input', function() {
            recalculateGrandTotal();
        });

        priceInput.addEventListener('input', recalculateGrandTotal);

        removeBtn.addEventListener('click', function() {
            tr.remove();
            checkEmptyState();
            recalculateGrandTotal();
        });
    }

    const manualTotalInput = document.getElementById('total_amount_manual');
    if (manualTotalInput) {
        manualTotalInput.addEventListener('input', recalculateGrandTotal);
    }

    function checkDiscountMargins() {
        const rows = tableBody.querySelectorAll('tr');
        let hasDiscount = false;

        rows.forEach(row => {
            const select = row.querySelector('.product-select');
            const priceInput = row.querySelector('.price-input');
            if (select && priceInput && select.value) {
                const selectedOption = select.options[select.selectedIndex];
                const salePrice = parseFloat(selectedOption.getAttribute('data-sale') || 0);
                const marginPercent = parseFloat(selectedOption.getAttribute('data-margin') || 5.0);
                const minAllowedPrice = salePrice * (1 - marginPercent / 100);
                const price = parseFloat(priceInput.value || 0);

                if (price < minAllowedPrice) {
                    hasDiscount = true;
                    priceInput.classList.add('border-danger');
                    priceInput.style.color = '#dc3545';
                } else {
                    priceInput.classList.remove('border-danger');
                    priceInput.style.color = '';
                }
            }
        });

        if (discountFields) {
            if (hasDiscount) {
                discountFields.style.display = 'block';
            }
        }
    }

    function recalculateGrandTotal() {
        let subtotal = 0;
        const rows = tableBody.querySelectorAll('tr');

        if (rows.length > 0) {
            rows.forEach(row => {
                const qtyInput = row.querySelector('.qty-input');
                const priceInput = row.querySelector('.price-input');
                const totalDisplay = row.querySelector('.row-total-display');

                if (qtyInput && priceInput && totalDisplay) {
                    const qty = parseInt(qtyInput.value) || 0;
                    const price = parseFloat(priceInput.value) || 0;
                    const total = qty * price;
                    subtotal += total;
                    totalDisplay.textContent = `${formatMoney(total)} FCFA`;
                }
            });
        } else {
            const manualInput = document.getElementById('total_amount_manual');
            if (manualInput) {
                subtotal = parseFloat(manualInput.value) || 0;
            }
        }

        const isFormal = isFormalSwitch && isFormalSwitch.checked && transactionType === 'SALE';
        let grandTotal = subtotal;

        if (isFormal && rows.length > 0) {
            const tvaRate = parseFloat(tvaRateInput ? tvaRateInput.value : 19.25) || 0;
            const irRate = parseFloat(irRateInput ? irRateInput.value : 5.5) || 0;

            const taxAmount = subtotal * (tvaRate / 100);
            const irAmount = subtotal * (irRate / 100);
            const ttcAmount = subtotal + taxAmount;
            const napAmount = ttcAmount - irAmount;

            grandTotal = ttcAmount;

            const dispSub = document.getElementById('displaySubtotal');
            const dispTax = document.getElementById('displayTax');
            const dispTTC = document.getElementById('displayTTC');
            const dispNAP = document.getElementById('displayNAP');

            if (dispSub) dispSub.textContent = `${formatMoney(subtotal)} FCFA`;
            if (dispTax) dispTax.textContent = `+${formatMoney(taxAmount)} FCFA (${tvaRate}%)`;
            if (dispTTC) dispTTC.textContent = `${formatMoney(ttcAmount)} FCFA`;
            if (dispNAP) dispNAP.textContent = `${formatMoney(napAmount)} FCFA (-${irRate}%)`;
        }

        if (grandTotalDisplay) {
            grandTotalDisplay.textContent = formatMoney(grandTotal);
        }
        checkDiscountMargins();
    }

    function formatMoney(amount) {
        return Math.round(amount).toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ");
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});

const originalOrderDate = "<?= $original_order_date ?>";

function setOrderDateNow() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentFormatted = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    const input = document.getElementById('order_date');
    if (input) {
        input.value = currentFormatted;
    }
}

function setOrderDateOriginal() {
    const input = document.getElementById('order_date');
    if (input && originalOrderDate) {
        input.value = originalOrderDate;
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
