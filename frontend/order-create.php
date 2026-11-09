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
$type = isset($_GET['type']) && $_GET['type'] === 'PURCHASE' ? 'PURCHASE' : 'SALE';

// Sécurité : Un vendeur ne peut pas faire de ravitaillement (Achats)
if ($_SESSION['user_role'] !== 'admin' && $type === 'PURCHASE') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut enregistrer des ravitaillements (achats fournisseurs).";
    header("Location: index.php");
    exit;
}

$error = '';

// Charger les contacts correspondants
if ($type === 'SALE') {
    $contacts = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
} else {
    $contacts = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll();
}

// Charger les catégories pour la modal de création rapide de produit
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$all_categories = $categories_stmt->fetchAll();

// Charger tous les produits avec le stock actuel pour la boutique active
$products_stmt = $pdo->prepare("
    SELECT p.*, COALESCE(s.quantity, 0) as stock_qty, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = ?
    ORDER BY p.name ASC
");
$products_stmt->execute([$active_shop_id]);
$products = $products_stmt->fetchAll();

// Disponibilités dans les autres boutiques pour information
$other_stocks_stmt = $pdo->query("
    SELECT ps.product_id, ps.shop_id, s.name as shop_name, ps.quantity 
    FROM product_stock ps 
    JOIN shops s ON ps.shop_id = s.id 
    WHERE ps.quantity > 0
");
$other_stocks = [];
foreach ($other_stocks_stmt->fetchAll() as $row) {
    if (intval($row['shop_id']) !== intval($active_shop_id)) {
        $other_stocks[$row['product_id']][] = $row['shop_name'] . ' : ' . intval($row['quantity']);
    }
}
foreach ($products as &$p) {
    $p['other_shops_info'] = !empty($other_stocks[$p['id']]) ? implode(', ', $other_stocks[$p['id']]) : '';
}
unset($p);

// Traitement de l'envoi du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact_id = isset($_POST['contact_id']) ? intval($_POST['contact_id']) : 0;
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'CASH';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Date & Heure de la commande
    if (!empty($_POST['order_date'])) {
        $raw_date = str_replace('T', ' ', trim($_POST['order_date']));
        if (strlen($raw_date) === 16) {
            $raw_date .= ':00';
        }
        $order_date = date('Y-m-d H:i:s', strtotime($raw_date));
    } else {
        $order_date = date('Y-m-d H:i:s');
    }
    
    $item_product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $item_quantities = isset($_POST['quantities']) ? $_POST['quantities'] : [];
    $item_prices = isset($_POST['prices']) ? $_POST['prices'] : [];

    if (empty($item_product_ids)) {
        $error = "Veuillez ajouter au moins un produit à la facture.";
    } else {
        try {
            $pdo->beginTransaction();

            $is_formal = (isset($_POST['is_formal']) && $_POST['is_formal'] == '1') ? 1 : 0;
            $subtotal = 0.00;
            $tax_amount = 0.00;
            $total_amount = 0.00;
            $items_to_save = [];
            $order_status = 'COMPLETED';

            // 1. Valider les articles et calculer le total
            for ($i = 0; $i < count($item_product_ids); $i++) {
                $p_id = intval($item_product_ids[$i]);
                $qty = intval($item_quantities[$i]);
                $price = floatval($item_prices[$i]);

                if ($p_id <= 0 || $qty <= 0 || $price < 0) {
                    throw new Exception("Données de produit incorrectes dans la ligne " . ($i + 1));
                }

                $p_stmt = $pdo->prepare("
                    SELECT p.*, COALESCE(s.quantity, 0) as stock_qty 
                    FROM products p
                    LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = ?
                    WHERE p.id = ?
                ");
                $p_stmt->execute([$active_shop_id, $p_id]);
                $prod = $p_stmt->fetch();

                if (!$prod) {
                    throw new Exception("Produit ID $p_id introuvable.");
                }

                if ($type === 'SALE' && $prod['stock_qty'] < $qty) {
                    throw new Exception("Stock insuffisant pour le produit: " . $prod['name'] . ". Disponible: " . $prod['stock_qty']);
                }

                if ($type === 'SALE') {
                    $min_price = floatval($prod['price_sale']) * (1 - floatval($prod['margin_percent'] ?? 5.00) / 100);
                    // Le prix minimum ne peut jamais être inférieur au prix d'achat (Prix de Vente - Marge)
                    $min_price = max($min_price, floatval($prod['price_purchase']));
                    if ($price < $min_price) {
                        $order_status = 'PENDING_APPROVAL';
                    }
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
            
            $tax_amount = 0.00;
            $ir_amount = 0.00;
            $total_amount = $subtotal;
            $nap_amount = $subtotal;

            if ($is_formal && $type === 'SALE') {
                $tva_rate = isset($_POST['tva_rate']) ? floatval($_POST['tva_rate']) : 0;
                $ir_rate = isset($_POST['ir_rate']) ? floatval($_POST['ir_rate']) : 0;
                
                $tax_amount = $subtotal * ($tva_rate / 100);
                $ir_amount = $subtotal * ($ir_rate / 100);
                $total_amount = $subtotal + $tax_amount;
                $nap_amount = $total_amount - $ir_amount;
            }

            $new_contact_checkbox = isset($_POST['new_contact_checkbox']) && $_POST['new_contact_checkbox'] == '1';
            if ($new_contact_checkbox) {
                $new_name = trim($_POST['new_contact_name'] ?? '');
                $new_phone = trim($_POST['new_contact_phone'] ?? '');
                $new_address = trim($_POST['new_contact_address'] ?? '');
                
                if (empty($new_name)) {
                    throw new Exception("Le nom du nouveau " . ($type === 'SALE' ? 'client' : 'fournisseur') . " est obligatoire.");
                }
                
                if ($type === 'SALE') {
                    $stmt_new_contact = $pdo->prepare("INSERT INTO clients (name, phone, address) VALUES (?, ?, ?)");
                    $stmt_new_contact->execute([$new_name, $new_phone, $new_address]);
                    $contact_id = $pdo->lastInsertId();
                } else {
                    $new_email = trim($_POST['new_contact_email'] ?? '');
                    $stmt_new_contact = $pdo->prepare("INSERT INTO suppliers (name, phone, address, email) VALUES (?, ?, ?, ?)");
                    $stmt_new_contact->execute([$new_name, $new_phone, $new_address, $new_email]);
                    $contact_id = $pdo->lastInsertId();
                }
            }

            $due_date = NULL;
            $amount_paid = $total_amount;
            $payment_status = 'PAID';
            $discount_reason = NULL;

            if ($type === 'SALE' && $order_status === 'PENDING_APPROVAL') {
                $discount_reason = trim($_POST['discount_reason'] ?? '');
                if (empty($discount_reason)) {
                    throw new Exception("Veuillez saisir un motif pour la vente exceptionnelle en dessous de la marge autorisée.");
                }
            }

            if ($payment_method === 'CREDIT') {
                $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+7 days'));
                $amount_paid = isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0.00;
                
                if ($amount_paid >= $nap_amount) {
                    $payment_status = 'PAID';
                } elseif ($amount_paid > 0) {
                    $payment_status = 'PARTIAL';
                } else {
                    $payment_status = 'UNPAID';
                }
            }

            $stmt_order = $pdo->prepare("
                INSERT INTO orders (
                    type, shop_id, client_id, supplier_id, user_id, 
                    total_amount, payment_method, notes, status, due_date, 
                    amount_paid, payment_status, discount_reason,
                    is_formal, subtotal, tax_amount, ir_amount, nap_amount,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt_order->execute([
                $type,
                $active_shop_id,
                $type === 'SALE' ? ($contact_id > 0 ? $contact_id : NULL) : NULL,
                $type === 'PURCHASE' ? ($contact_id > 0 ? $contact_id : NULL) : NULL,
                $_SESSION['user_id'] ?? 1,
                $total_amount,
                $payment_method,
                $notes,
                $order_status,
                $due_date,
                $amount_paid,
                $payment_status,
                $discount_reason,
                $is_formal,
                $subtotal,
                $tax_amount,
                $ir_amount,
                $nap_amount,
                $order_date
            ]);

            $order_id = $pdo->lastInsertId();

            $stmt_item = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt_update_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ");

            foreach ($items_to_save as $item) {
                $stmt_item->execute([
                    $order_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price']
                ]);

                $should_update_stock = false;
                $stock_change = 0;

                if ($type === 'SALE' && in_array($order_status, ['COMPLETED', 'PENDING_APPROVAL'])) {
                    $should_update_stock = true;
                    $stock_change = -$item['quantity'];
                } elseif ($type === 'PURCHASE' && $order_status === 'COMPLETED') {
                    $should_update_stock = true;
                    $stock_change = $item['quantity'];
                }

                if ($should_update_stock) {
                    $stmt_update_stock->execute([
                        $item['product_id'],
                        $active_shop_id,
                        $stock_change,
                        $stock_change
                    ]);

                    if ($type === 'PURCHASE') {
                        $stmt_old_prod = $pdo->prepare("SELECT price_purchase FROM products WHERE id = ?");
                        $stmt_old_prod->execute([$item['product_id']]);
                        $old_cump = floatval($stmt_old_prod->fetchColumn());
                        
                        $stmt_global_stock = $pdo->prepare("SELECT SUM(quantity) FROM product_stock WHERE product_id = ?");
                        $stmt_global_stock->execute([$item['product_id']]);
                        $current_global_stock = intval($stmt_global_stock->fetchColumn());
                        
                        $purchased_qty = floatval($item['quantity']);
                        $purchased_price = floatval($item['unit_price']);
                        
                        // Stock avant cet achat
                        $old_global_stock = $current_global_stock - $purchased_qty;
                        
                        // Si le stock précédent était nul, négatif ou sans CUMP valide : CUMP = prix d'achat
                        if ($old_global_stock <= 0 || $old_cump <= 0) {
                            $new_cump = $purchased_price;
                        } else {
                            // Formule CUMP standard pondérée sur le stock réel disponible
                            $new_cump = (($old_global_stock * $old_cump) + ($purchased_qty * $purchased_price)) / ($old_global_stock + $purchased_qty);
                        }
                        
                        $stmt_update_cump = $pdo->prepare("UPDATE products SET price_purchase = ? WHERE id = ?");
                        $stmt_update_cump->execute([$new_cump, $item['product_id']]);
                    }
                }
            }

            if ($order_status === 'COMPLETED') {
                // Règle de caisse corrigée :
                // - VENTE (SALE) : l'argent va dans la caisse physique de la boutique concernée ($active_shop_id),
                //   quelle que soit la personne qui saisit (admin ou vendeur).
                // - ACHAT/RAVITAILLEMENT (PURCHASE) : la dépense sort de la Caisse Boss (NULL), car c'est
                //   toujours l'admin/patron qui paye les fournisseurs sur sa trésorerie propre.

                if ($type === 'SALE' && $payment_method === 'CASH') {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER', ?)
                    ");
                    $stmt_cash->execute([
                        $active_shop_id,  // Toujours la boutique de la vente
                        $_SESSION['user_id'] ?? 1,
                        $total_amount,
                        "Vente en espèces FAC-" . str_pad($order_id, 5, '0', STR_PAD_LEFT),
                        $order_id,
                        $order_date
                    ]);
                } elseif ($type === 'SALE' && $payment_method === 'CREDIT' && $amount_paid > 0) {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER', ?)
                    ");
                    $stmt_cash->execute([
                        $active_shop_id,  // Toujours la boutique de la vente
                        $_SESSION['user_id'] ?? 1,
                        $amount_paid,
                        "Acompte en espèces sur vente à crédit FAC-" . str_pad($order_id, 5, '0', STR_PAD_LEFT),
                        $order_id,
                        $order_date
                    ]);
                } elseif ($type === 'PURCHASE' && $payment_method === 'CASH') {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                        VALUES (NULL, ?, 'OUT', ?, ?, ?, 'PURCHASE', ?)
                    ");
                    $stmt_cash->execute([
                        $_SESSION['user_id'] ?? 1,
                        $total_amount,
                        "Achat fournisseur ravitaillement [" . ($current_shop['name'] ?? 'Boutique') . "] FAC-" . str_pad($order_id, 5, '0', STR_PAD_LEFT),
                        $order_id,
                        $order_date
                    ]);
                }
            }

            $pdo->commit();
            header("Location: invoice.php?id=" . $order_id);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!-- Title Banner -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="<?= $type === 'SALE' ? 'text-success' : 'text-primary' ?>">
                <i class="fa-solid <?= $type === 'SALE' ? 'fa-cart-shopping' : 'fa-download' ?>"></i>
            </span> 
            <?= $type === 'SALE' ? 'Enregistrer une Vente (Facture Client)' : 'Enregistrer un Ravitaillement (Achat Fournisseur)' ?>
        </h1>
        <span class="text-secondary small fw-semibold">Boutique active : <span class="badge bg-light text-dark border"><?= htmlspecialchars($current_shop['name']) ?></span></span>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form action="order-create.php?type=<?= $type ?>" method="POST" id="orderForm">
    <div class="row g-4">
        <!-- Infos Client / Fournisseur & Paiement (Gauche) -->
        <div class="col-12 col-lg-4">
            <div class="glass-card p-4">
                <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-user-tag text-primary me-2"></i>Informations générales</h5>
                
                <?php if ($user_role === 'admin'): ?>
                    <!-- Choix de la Boutique (Admin uniquement) -->
                    <div class="mb-3">
                        <label for="shop_id" class="form-label fw-semibold text-secondary">Dépôt / Boutique</label>
                        <select class="form-select form-control-custom" id="shop_id" name="shop_id" onchange="window.location.href='order-create.php?type=<?= $type ?>&shop_id=' + this.value" required>
                            <?php foreach ($all_shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $active_shop_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="shop_id" value="<?= $active_shop_id ?>">
                <?php endif; ?>
                
                <!-- Date & Heure de la Transaction (Pré-remplie par défaut à aujourd'hui) -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="order_date" class="form-label fw-semibold text-secondary mb-0">Date & Heure de la Vente *</label>
                        <button type="button" class="btn btn-outline-primary btn-xs py-0 px-1.5" style="font-size: 0.72rem; border-radius: 4px;" onclick="setCreateOrderDateNow()">
                            <i class="fa-solid fa-clock me-1"></i>Maintenant
                        </button>
                    </div>
                    <input type="datetime-local" class="form-control form-control-custom" id="order_date" name="order_date" value="<?= date('Y-m-d\TH:i') ?>" required>
                    <small class="text-muted" style="font-size: 0.75rem;">Automatiquement réglé à la date du jour. Modifiable au besoin.</small>
                </div>

                <!-- Client/Fournisseur Selector -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="contact_id" class="form-label fw-semibold text-secondary mb-0">
                            <?= $type === 'SALE' ? 'Client' : 'Fournisseur' ?>
                        </label>
                        <button type="button" class="btn btn-link btn-sm text-primary p-0 fw-semibold text-decoration-none" onclick="toggleNewContactBlock()">
                            <i class="fa-solid fa-user-plus me-1"></i>+ Nouveau <?= $type === 'SALE' ? 'Client' : 'Fournisseur' ?>
                        </button>
                    </div>
                    <select class="form-select form-control-custom" id="contact_id" name="contact_id" onchange="handleContactSelectChange(this.value)">
                        <option value="">-- Sélectionner --</option>
                        <option value="NEW" class="fw-bold text-primary">+ Créer un nouveau <?= $type === 'SALE' ? 'client' : 'fournisseur' ?>...</option>
                        <?php foreach ($contacts as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Laissez vide pour "<?= $type === 'SALE' ? 'Client comptoir' : 'Fournisseur direct' ?>"</small>
                </div>

                <!-- Case à cocher masquée pour déclenchement form -->
                <div class="mb-3 form-check d-none">
                    <input type="checkbox" class="form-check-input" id="new_contact_checkbox" name="new_contact_checkbox" value="1">
                    <label class="form-check-label small fw-bold text-secondary" for="new_contact_checkbox">
                        <i class="fa-solid fa-user-plus me-1"></i>Créer un nouveau <?= $type === 'SALE' ? 'client' : 'fournisseur' ?>
                    </label>
                </div>


                <!-- Option Facture Formelle (TVA) -->
                <?php if ($type === 'SALE'): ?>
                <div class="mb-3 p-3 bg-light border rounded-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_formal" name="is_formal" value="1">
                        <label class="form-check-label fw-bold text-slate-800" for="is_formal">Facture Légal / Formelle</label>
                    </div>
                    <div id="formal_fields" style="display: none;" class="mt-2 p-2 bg-light border rounded">
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
                            Le PU saisi = HT. TVA s'ajoute, IR se déduit du TTC (Net à Payer).
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Formulaire de saisie inline pour nouveau contact -->
                <div id="new_contact_fields" style="display: none;" class="p-3 bg-light rounded-3 border mb-3">
                    <div class="mb-2">
                        <label for="new_contact_name" class="form-label small fw-semibold text-secondary">Nom complet *</label>
                        <input type="text" class="form-control form-control-custom py-1 px-2" id="new_contact_name" name="new_contact_name" placeholder="Ex: Jean Dupont">
                    </div>
                    <div class="mb-2">
                        <label for="new_contact_phone" class="form-label small fw-semibold text-secondary">Téléphone</label>
                        <input type="text" class="form-control form-control-custom py-1 px-2" id="new_contact_phone" name="new_contact_phone" placeholder="Ex: 699000000">
                    </div>
                    <div class="mb-2">
                        <label for="new_contact_address" class="form-label small fw-semibold text-secondary">Adresse</label>
                        <input type="text" class="form-control form-control-custom py-1 px-2" id="new_contact_address" name="new_contact_address" placeholder="Ex: Yaoundé">
                    </div>
                    <?php if ($type === 'PURCHASE'): ?>
                        <div class="mb-2">
                            <label for="new_contact_email" class="form-label small fw-semibold text-secondary">Email</label>
                            <input type="email" class="form-control form-control-custom py-1 px-2" id="new_contact_email" name="new_contact_email" placeholder="Ex: email@domaine.com">
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Mode Paiement -->
                <div class="mb-3">
                    <label for="payment_method" class="form-label fw-semibold text-secondary">Mode de Règlement</label>
                    <select class="form-select form-control-custom" id="payment_method" name="payment_method">
                        <option value="CASH">Espèces (Cash)</option>
                        <option value="MOBILE_MONEY">Mobile Money (MOMO/OM)</option>
                        <option value="VIREMENT">Virement Bancaire / Chèque</option>
                        <option value="CARD">Carte Bancaire</option>
                        <option value="CREDIT">Crédit / Dette</option>
                    </select>
                </div>

                <!-- Options spécifiques au Crédit -->
                <div id="credit_fields" style="display: none;" class="p-3 bg-warning-subtle bg-opacity-10 border border-warning-subtle rounded-3 mb-3">
                    <div class="mb-2">
                        <label for="due_date" class="form-label small fw-bold text-warning-emphasis"><i class="fa-solid fa-calendar-day me-1"></i>Date Limite de Règlement *</label>
                        <input type="date" class="form-control form-control-custom py-1 px-2" id="due_date" name="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                    </div>
                    <div class="mb-0">
                        <label for="amount_paid" class="form-label small fw-bold text-warning-emphasis"><i class="fa-solid fa-money-bill-wave me-1"></i>Acompte Initial Versé</label>
                        <input type="number" step="0.01" class="form-control form-control-custom py-1 px-2" id="amount_paid" name="amount_paid" placeholder="Ex: 5000 (0 si aucun)" value="0">
                    </div>
                </div>

                <!-- Motif Vente Exceptionnelle -->
                <div id="discount_fields" style="display: none;" class="p-3 bg-danger-subtle bg-opacity-10 border border-danger-subtle rounded-3 mb-3">
                    <div class="mb-0">
                        <label for="discount_reason" class="form-label small fw-bold text-danger-emphasis"><i class="fa-solid fa-triangle-exclamation me-1"></i>Motif de la vente exceptionnelle *</label>
                        <textarea class="form-control form-control-custom py-1 px-2" id="discount_reason" name="discount_reason" rows="2" placeholder="Expliquez le motif de cette vente hors-marge (ex: client fidèle, accord du Boss...)"></textarea>
                    </div>
                </div>

                <!-- Notes -->
                <div class="mb-3">
                    <label for="notes" class="form-label fw-semibold text-secondary">Observations / Remarques</label>
                    <textarea class="form-control form-control-custom" id="notes" name="notes" rows="3" placeholder="Ex: Livraison prévue demain..."></textarea>
                </div>
            </div>
        </div>

        <!-- Lignes de commande & Total (Droite) -->
        <div class="col-12 col-lg-8">
            <div class="glass-card p-4 h-100 d-flex flex-column">
                <!-- En-tête des articles avec Total Dynamique au-dessus à gauche -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 pb-3 border-bottom">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-list-check text-primary me-2"></i>Produits commandés</h5>
                        <!-- TOTAL DYNAMIQUE AU-DESSUS A GAUCHE -->
                        <div id="grandTotalContainer" class="badge bg-success-subtle text-success fs-6 py-2 px-3 border border-success-subtle" style="border-radius: 10px;">
                            <span class="small text-secondary me-1">Total :</span>
                            <span class="fw-extrabold text-success fs-5"><span id="grandTotalDisplay">0</span> FCFA</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($type === 'PURCHASE' || $user_role === 'admin'): ?>
                            <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#quickProductModal" style="border-radius: 8px;">
                                <i class="fa-solid fa-plus-circle me-1"></i>Nouveau Produit
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-accent btn-sm" id="addRowBtn">
                            <i class="fa-solid fa-plus me-1"></i>Ajouter une ligne
                        </button>
                    </div>
                </div>

                <!-- Assistant de Recherche Express -->
                <div class="mb-4 position-relative">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-secondary" style="border-radius: 10px 0 0 10px;"><i class="fa-solid fa-magnifying-glass text-success"></i></span>
                        <input type="text" class="form-control form-control-custom border-start-0 ps-0" id="quickProductSearch" placeholder="Recherche rapide d'un article à ajouter... (ex: Couché 150g, Offset, A4...)" style="border-radius: 0 10px 10px 0;">
                    </div>
                    <div id="quickSearchResults" class="list-group position-absolute w-100 shadow-lg border mt-1 d-none" style="z-index: 1050; max-height: 220px; overflow-y: auto; border-radius: 8px;">
                        <!-- Les résultats s'affichent ici -->
                    </div>
                </div>

                <!-- Tableau des articles -->
                <div class="table-responsive mb-4">
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
                            <!-- Lignes ajoutées dynamiquement par JS -->
                        </tbody>
                    </table>
                </div>

                <div class="text-center py-4 text-secondary d-none" id="emptyState">
                    <i class="fa-regular fa-folder-open fa-3x mb-2 text-muted"></i>
                    <p class="mb-0">Aucun produit dans la liste. Cliquez sur "Ajouter une ligne".</p>
                </div>

                <!-- BOUTONS DE VALIDATION ET ANNULER EN BAS DE LA LISTE DES ARTICLES -->
                <div class="mt-auto pt-4 border-top d-flex align-items-center justify-content-end gap-3 flex-wrap">
                    <a href="orders.php" class="btn btn-outline-secondary px-4 py-2.5 fw-semibold" style="border-radius: 10px;">
                        Annuler
                    </a>
                    <button type="submit" class="btn <?= $type === 'SALE' ? 'btn-grad' : 'btn-primary' ?> px-5 py-2.5 fw-bold shadow-sm" style="border-radius: 10px; font-size: 1.05rem;">
                        <i class="fa-solid fa-check-circle me-2"></i><?= $type === 'SALE' ? 'Valider la Vente' : 'Valider le Ravitaillement' ?>
                    </button>
                </div>

            </div>
        </div>
    </div>
</form>

<!-- Modal de Création Rapide de Produit (Direct depuis la saisie d'achat) -->
<div class="modal fade" id="quickProductModal" tabindex="-1" aria-labelledby="quickProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-slate-800" id="quickProductModalLabel">
                    <i class="fa-solid fa-box-open text-success me-2"></i>Créer un Nouveau Produit
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body py-3">
                <form id="quickProductForm">
                    <div class="mb-3">
                        <label for="quick_name" class="form-label small fw-bold text-secondary">Nom du Produit *</label>
                        <input type="text" class="form-control form-control-custom" id="quick_name" required placeholder="Ex: RAMETTE A4 NAVIGATOR 80G">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="quick_category_id" class="form-label small fw-bold text-secondary">Famille / Catégorie</label>
                            <select class="form-select form-control-custom" id="quick_category_id">
                                <option value="">-- Sans Famille --</option>
                                <?php foreach ($all_categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="quick_unit" class="form-label small fw-bold text-secondary">Unité de Mesure</label>
                            <input type="text" class="form-control form-control-custom" id="quick_unit" value="UNITE" placeholder="RAMETTE, PAQUET, PIECE, CARTON...">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="quick_price_purchase" class="form-label small fw-bold text-secondary">Prix d'Achat (Fournisseur) *</label>
                            <input type="number" step="0.01" class="form-control form-control-custom" id="quick_price_purchase" value="0" required>
                        </div>
                        <div class="col-6">
                            <label for="quick_price_sale" class="form-label small fw-bold text-secondary">Prix de Vente Conseillé</label>
                            <input type="number" step="0.01" class="form-control form-control-custom" id="quick_price_sale" value="0">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label for="quick_margin_percent" class="form-label small fw-bold text-secondary">Marge Négociation (%)</label>
                            <input type="number" step="0.01" class="form-control form-control-custom" id="quick_margin_percent" value="5.00">
                        </div>
                        <div class="col-6">
                            <label for="quick_min_stock" class="form-label small fw-bold text-secondary">Seuil d'Alerte Stock</label>
                            <input type="number" class="form-control form-control-custom" id="quick_min_stock" value="5">
                        </div>
                    </div>
                    <div id="quickProductError" class="alert alert-danger py-1 px-2 small d-none mb-0 mt-2"></div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0 d-flex gap-2">
                <button type="button" class="btn btn-light px-3 py-2 flex-grow-1" data-bs-dismiss="modal" style="border-radius: 10px;">Annuler</button>
                <button type="button" class="btn btn-success px-3 py-2 flex-grow-1 fw-bold" id="saveQuickProductBtn" style="border-radius: 10px;">
                    <i class="fa-solid fa-check-circle me-1"></i>Enregistrer & Ajouter
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Template HTML pour select des produits généré une seule fois côté serveur -->
<template id="productSelectOptionsTemplate">
    <option value="">-- Choisir un produit --</option>
    <?php 
    $cur_cat = null;
    foreach ($products as $p): 
        if ($p['category_name'] !== $cur_cat):
            if ($cur_cat !== null) echo '</optgroup>';
            $cur_cat = $p['category_name'];
            echo '<optgroup label="' . htmlspecialchars($cur_cat ?: 'Sans Famille') . '">';
        endif;
    ?>
        <option value="<?= $p['id'] ?>" data-stock="<?= $p['stock_qty'] ?>" data-purchase="<?= $p['price_purchase'] ?>" data-sale="<?= $p['price_sale'] ?>">
            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['unit'] ?: 'UNITE') ?>)
        </option>
    <?php 
    endforeach; 
    if ($cur_cat !== null) echo '</optgroup>';
    ?>
</template>

<!-- Data structure for products lookup in JS -->
<script>
const productsCatalog = <?= json_encode($products) ?>;
const transactionType = '<?= $type ?>';
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tableBody = document.getElementById('itemsTableBody');
    const addRowBtn = document.getElementById('addRowBtn');
    const emptyState = document.getElementById('emptyState');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');
    const optionsTemplate = document.getElementById('productSelectOptionsTemplate');
    const orderForm = document.getElementById('orderForm');

    // Anti-double-clic et indicateur de chargement à la soumission
    if (orderForm) {
        orderForm.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Enregistrement en cours...';
            }
        });
    }

    // Ajouter une première ligne au chargement
    addNewRow();

    addRowBtn.addEventListener('click', addNewRow);

    function checkEmptyState() {
        if (tableBody.children.length === 0) {
            emptyState.classList.remove('d-none');
        } else {
            emptyState.classList.add('d-none');
        }
    }

    function createRowElement(productId = null, stock = 0, price = 0) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <select class="form-select form-control-custom product-select" name="product_ids[]" required>
                </select>
            </td>
            <td class="text-center fw-bold text-secondary stock-display">${productId ? stock : '-'}</td>
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

        const select = tr.querySelector('.product-select');
        select.appendChild(optionsTemplate.content.cloneNode(true));
        if (productId) {
            select.value = productId;
        }

        return tr;
    }

    function addNewRow() {
        const tr = createRowElement();
        tableBody.appendChild(tr);
        checkEmptyState();
        setupRowEvents(tr);
        recalculateGrandTotal();
    }

    function addNewRowWithProduct(productId, stock, price) {
        const firstRow = tableBody.querySelector('tr');
        if (firstRow && tableBody.children.length === 1) {
            const select = firstRow.querySelector('.product-select');
            if (select && select.value === '') {
                firstRow.remove();
            }
        }

        const tr = createRowElement(productId, stock, price);
        tableBody.appendChild(tr);
        checkEmptyState();
        setupRowEvents(tr);
        
        const qtyInput = tr.querySelector('.qty-input');
        if (transactionType === 'SALE') {
            qtyInput.setAttribute('max', stock);
        }
        
        recalculateGrandTotal();
        
        qtyInput.focus();
        qtyInput.select();
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
            matches.slice(0, 10).forEach(p => {
                const stock = parseInt(p.stock_qty);
                const price = transactionType === 'SALE' ? parseFloat(p.price_sale) : parseFloat(p.price_purchase);
                
                let badgeClass = 'bg-success-subtle text-success';
                let stockText = `${stock} dispo`;
                if (stock === 0 && transactionType === 'SALE') {
                    badgeClass = 'bg-danger text-white';
                    stockText = '0 en stock';
                } else if (stock <= parseInt(p.min_stock_level) && transactionType === 'SALE') {
                    badgeClass = 'bg-warning text-dark';
                }

                let otherInfoHtml = '';
                if (p.other_shops_info) {
                    otherInfoHtml = `<small class="text-primary d-block mt-0.5" style="font-size: 0.72rem;"><i class="fa-solid fa-store me-1"></i>Ailleurs : ${escapeHtml(p.other_shops_info)}</small>`;
                }

                html += `
                    <button type="button" class="list-group-item list-group-item-action py-2 px-3 d-flex justify-content-between align-items-center quick-add-item" 
                            data-id="${p.id}" data-stock="${stock}" data-price="${price}">
                        <div>
                            <span class="fw-bold text-slate-800">${escapeHtml(p.name)}</span><br>
                            <small class="text-secondary">Conditionnement: ${escapeHtml(p.unit)}</small>
                            ${otherInfoHtml}
                        </div>
                        <div class="text-end">
                            <span class="badge ${badgeClass} rounded-pill py-1 px-2 fw-semibold me-2">${stockText}</span>
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
                    
                    if (transactionType === 'SALE' && stock === 0) {
                        alert("Impossible d'ajouter cet article car le stock est épuisé.");
                        return;
                    }

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
                    qtyInput.setAttribute('max', stock);
                } else {
                    priceInput.value = purchasePrice;
                    qtyInput.removeAttribute('max');
                }
            } else {
                stockDisplay.textContent = '-';
                priceInput.value = '0.00';
                qtyInput.removeAttribute('max');
            }
            recalculateGrandTotal();
        });

        qtyInput.addEventListener('input', function() {
            if (transactionType === 'SALE') {
                const maxStock = parseInt(qtyInput.getAttribute('max') || 0);
                if (parseInt(this.value) > maxStock) {
                    alert(`Quantité demandée supérieure au stock disponible (${maxStock} disponible(s))`);
                    this.value = maxStock;
                }
            }
            recalculateGrandTotal();
        });
        priceInput.addEventListener('input', recalculateGrandTotal);

        removeBtn.addEventListener('click', function() {
            tr.remove();
            checkEmptyState();
            recalculateGrandTotal();
        });
    }

    function checkDiscountMargins() {
        const rows = tableBody.querySelectorAll('tr');
        let requiresApproval = false;
        
        rows.forEach(row => {
            const select = row.querySelector('.product-select');
            const priceInput = row.querySelector('.price-input');
            if (select && priceInput && select.value) {
                const selectedOption = select.options[select.selectedIndex];
                const salePrice = parseFloat(selectedOption.getAttribute('data-sale') || 0);
                const productId = parseInt(select.value);
                
                const product = productsCatalog.find(p => p.id === productId);
                const marginPercent = product ? parseFloat(product.margin_percent || 5.0) : 5.0;
                
                const purchasePrice = parseFloat(selectedOption.getAttribute('data-purchase') || 0);
                const minAllowedPrice = Math.max(purchasePrice, salePrice * (1 - marginPercent / 100));
                const price = parseFloat(priceInput.value || 0);
                
                if (price < minAllowedPrice) {
                    requiresApproval = true;
                    priceInput.classList.add('border-danger');
                    priceInput.style.color = '#dc3545';
                } else {
                    priceInput.classList.remove('border-danger');
                    priceInput.style.color = '';
                }
            }
        });
        
        const discountFields = document.getElementById('discount_fields');
        const discountReasonInput = document.getElementById('discount_reason');
        if (requiresApproval && transactionType === 'SALE') {
            if (discountFields) discountFields.style.display = 'block';
            if (discountReasonInput) discountReasonInput.setAttribute('required', 'required');
        } else {
            if (discountFields) discountFields.style.display = 'none';
            if (discountReasonInput) {
                discountReasonInput.removeAttribute('required');
                discountReasonInput.value = '';
            }
        }
    }

    function recalculateGrandTotal() {
        let grandTotal = 0;
        let isFormal = false;
        
        const isFormalCheckbox = document.getElementById('is_formal');
        if (isFormalCheckbox && isFormalCheckbox.checked) {
            isFormal = true;
        }

        const rows = tableBody.querySelectorAll('tr');
        rows.forEach(row => {
            const qty = parseInt(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const rowTotal = qty * price;
            grandTotal += rowTotal;
            
            const rowTotalDisplay = row.querySelector('.row-total-display');
            if (rowTotalDisplay) {
                rowTotalDisplay.textContent = formatMoney(rowTotal) + ' FCFA';
            }
        });
        
        let subtotal = grandTotal;
        let tax = 0;
        let ir = 0;
        let nap = 0;
        let container = document.getElementById('grandTotalContainer');

        if (isFormal) {
            const tvaRate = parseFloat(document.getElementById('tva_rate').value) || 0;
            const irRate = parseFloat(document.getElementById('ir_rate').value) || 0;
            
            tax = subtotal * (tvaRate / 100);
            ir = subtotal * (irRate / 100);
            grandTotal = subtotal + tax;
            nap = grandTotal - ir;
            
            if (container) {
                container.innerHTML = `
                    <div class="d-flex align-items-center gap-2 flex-wrap" style="font-size: 0.85rem;">
                        <span class="text-secondary fw-semibold">HT: ${formatMoney(subtotal)} F</span> |
                        <span class="text-danger fw-semibold">TVA (${tvaRate}%): +${formatMoney(tax)} F</span> |
                        <span class="text-primary fw-semibold">IR (${irRate}%): -${formatMoney(ir)} F</span> |
                        <span class="fw-extrabold text-success fs-6">NAP: ${formatMoney(nap)} FCFA</span>
                    </div>
                `;
            }
        } else {
            if (container) {
                container.innerHTML = `
                    <span class="small text-secondary me-1">Total :</span>
                    <span class="fw-extrabold text-success fs-5"><span>${formatMoney(grandTotal)}</span> FCFA</span>
                `;
            }
        }
        
        checkDiscountMargins();
    }

    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-FR').format(amount);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    if (document.getElementById('is_formal')) {
        document.getElementById('is_formal').addEventListener('change', function() {
            const formalFields = document.getElementById('formal_fields');
            if (this.checked) {
                if (formalFields) formalFields.style.display = 'block';
            } else {
                if (formalFields) formalFields.style.display = 'none';
            }
            recalculateGrandTotal();
        });
    }

    if (document.getElementById('tva_rate')) {
        document.getElementById('tva_rate').addEventListener('input', recalculateGrandTotal);
    }
    if (document.getElementById('ir_rate')) {
        document.getElementById('ir_rate').addEventListener('input', recalculateGrandTotal);
    }

    const newContactCheckbox = document.getElementById('new_contact_checkbox');
    const newContactFields = document.getElementById('new_contact_fields');
    const contactSelect = document.getElementById('contact_id');

    window.toggleNewContactBlock = function() {
        if (newContactCheckbox && newContactFields) {
            newContactCheckbox.checked = true;
            newContactFields.style.display = 'block';
            if (contactSelect) contactSelect.value = 'NEW';
        }
    };

    window.handleContactSelectChange = function(val) {
        if (val === 'NEW') {
            if (newContactCheckbox) newContactCheckbox.checked = true;
            if (newContactFields) newContactFields.style.display = 'block';
        } else {
            if (newContactCheckbox) newContactCheckbox.checked = false;
            if (newContactFields) newContactFields.style.display = 'none';
        }
    };

    if (newContactCheckbox && newContactFields) {
        newContactCheckbox.addEventListener('change', function() {
            if (this.checked) {
                newContactFields.style.display = 'block';
            } else {
                newContactFields.style.display = 'none';
            }
        });
    }


    const paymentMethodSelect = document.getElementById('payment_method');
    const creditFields = document.getElementById('credit_fields');

    if (paymentMethodSelect && creditFields) {
        paymentMethodSelect.addEventListener('change', function() {
            if (this.value === 'CREDIT') {
                creditFields.style.display = 'block';
            } else {
                creditFields.style.display = 'none';
            }
        });
    }

    // Gestion de la Création Rapide de Produit depuis la Modal
    const saveQuickProductBtn = document.getElementById('saveQuickProductBtn');
    const quickProductError = document.getElementById('quickProductError');
    const quickProductModalEl = document.getElementById('quickProductModal');

    if (saveQuickProductBtn) {
        saveQuickProductBtn.addEventListener('click', function() {
            const name = document.getElementById('quick_name').value.trim();
            const categoryId = document.getElementById('quick_category_id').value;
            const unit = document.getElementById('quick_unit').value.trim() || 'UNITE';
            const pricePurchase = parseFloat(document.getElementById('quick_price_purchase').value || 0);
            const priceSale = parseFloat(document.getElementById('quick_price_sale').value || 0);
            const marginPercent = parseFloat(document.getElementById('quick_margin_percent').value || 5);
            const minStock = parseInt(document.getElementById('quick_min_stock').value || 5);

            if (!name) {
                quickProductError.textContent = 'Veuillez saisir le nom du produit.';
                quickProductError.classList.remove('d-none');
                return;
            }

            saveQuickProductBtn.disabled = true;
            saveQuickProductBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Enregistrement...';
            quickProductError.classList.add('d-none');

            const formData = new FormData();
            formData.append('quick_name', name);
            formData.append('quick_category_id', categoryId);
            formData.append('quick_unit', unit);
            formData.append('quick_price_purchase', pricePurchase);
            formData.append('quick_price_sale', priceSale);
            formData.append('quick_margin_percent', marginPercent);
            formData.append('quick_min_stock', minStock);

            fetch('api-quick-product.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                saveQuickProductBtn.disabled = false;
                saveQuickProductBtn.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i>Enregistrer & Ajouter';

                if (data.success && data.product) {
                    const newProd = data.product;
                    // Ajouter au catalogue en mémoire
                    productsCatalog.push({
                        id: newProd.id,
                        name: newProd.name,
                        sku: newProd.sku,
                        category_name: newProd.category_name,
                        unit: newProd.unit,
                        price_purchase: newProd.price_purchase,
                        price_sale: newProd.price_sale,
                        stock_qty: 0,
                        margin_percent: marginPercent
                    });

                    // Insérer directement dans le tableau avec le prix d'achat
                    const targetPrice = (transactionType === 'PURCHASE') ? newProd.price_purchase : newProd.price_sale;
                    addNewRowWithProduct(newProd.id, 0, targetPrice);

                    // Fermer la modal et réinitialiser le formulaire
                    const modal = bootstrap.Modal.getInstance(quickProductModalEl);
                    if (modal) modal.hide();
                    document.getElementById('quickProductForm').reset();
                    document.getElementById('quick_unit').value = 'UNITE';
                    document.getElementById('quick_margin_percent').value = '5.00';
                    document.getElementById('quick_min_stock').value = '5';
                } else {
                    quickProductError.textContent = data.message || 'Une erreur est survenue lors de la création.';
                    quickProductError.classList.remove('d-none');
                }
            })
            .catch(err => {
                saveQuickProductBtn.disabled = false;
                saveQuickProductBtn.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i>Enregistrer & Ajouter';
                quickProductError.textContent = 'Erreur réseau ou serveur : ' + err.message;
                quickProductError.classList.remove('d-none');
            });
        });
    }
});

function setCreateOrderDateNow() {
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
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
