<?php
// Charger la connexion base de données (chemin adapté vers le backend)
require_once __DIR__ . '/../backend/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Rediriger si non connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fonction de conversion des nombres en lettres
function numberToWords($number) {
    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter('fr', NumberFormatter::SPELLOUT);
        return $formatter->format($number);
    }
    return $number; // Fallback si l'extension intl n'est pas activée
}

// Charger la commande
$stmt_order = $pdo->prepare("
    SELECT o.*, 
           c.name as client_name, c.phone as client_phone, c.address as client_address, c.nui as client_nui, c.rc as client_rc, c.bp as client_bp,
           sup.name as supplier_name, sup.phone as supplier_phone, sup.address as supplier_address, sup.nui as supplier_nui, sup.rc as supplier_rc, sup.bp as supplier_bp,
           sh.name as shop_name, sh.address as shop_address, sh.phone as shop_phone, sh.nui as shop_nui, sh.rc as shop_rc,
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
    die("Facture introuvable.");
}

$error_msg = '';
$success_msg = '';

if (isset($_SESSION['success_message'])) {
    $success_msg = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_msg = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'approved') {
        $success_msg = "La commande a été validée avec succès et le stock a été mis à jour.";
    } elseif ($_GET['success'] === 'rejected') {
        $success_msg = "La commande a été rejetée.";
    } elseif ($_GET['success'] === 'updated') {
        $success_msg = "La facture a été modifiée avec succès et les stocks/caisses ont été synchronisés.";
    }
}

// Traitement de l'approbation/rejet (Admin uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['user_role'] === 'admin') {
    if (isset($_POST['approve_order'])) {
        try {
            $pdo->beginTransaction();
            
            // 1. Mettre à jour le statut (le stock est déjà déduit/réservé lors de la création)
            $stmt = $pdo->prepare("UPDATE orders SET status = 'COMPLETED' WHERE id = ? AND status = 'PENDING_APPROVAL'");
            $stmt->execute([$id]);

            // Logger dans cash_transactions
            if ($order) {
                if ($order['type'] === 'SALE' && $order['payment_method'] === 'CASH') {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER')
                    ");
                    $stmt_cash->execute([
                        $order['shop_id'],
                        $order['user_id'],
                        $order['total_amount'],
                        "Vente en espèces " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))),
                        $id
                    ]);
                } elseif ($order['type'] === 'SALE' && $order['payment_method'] === 'CREDIT' && $order['amount_paid'] > 0) {
                    $stmt_cash = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type)
                        VALUES (?, ?, 'IN', ?, ?, ?, 'ORDER')
                    ");
                    $stmt_cash->execute([
                        $order['shop_id'],
                        $order['user_id'],
                        $order['amount_paid'],
                        "Acompte en espèces sur vente à crédit " . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))),
                        $id
                    ]);
                }
            }
            
            $pdo->commit();
            header("Location: invoice.php?id=" . $id . "&success=approved");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Erreur de validation : " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_order'])) {
        try {
            $pdo->beginTransaction();
            
            // 1. Mettre à jour le statut à REJECTED
            $stmt = $pdo->prepare("UPDATE orders SET status = 'REJECTED' WHERE id = ? AND status = 'PENDING_APPROVAL'");
            $stmt->execute([$id]);
            
            // 2. Restituer le stock réservé
            $stmt_items_db = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmt_items_db->execute([$id]);
            $order_items = $stmt_items_db->fetchAll();
            
            $stmt_update_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ");
            
            foreach ($order_items as $item) {
                $stock_change = $item['quantity'];
                $stmt_update_stock->execute([
                    $item['product_id'],
                    $order['shop_id'],
                    $stock_change,
                    $stock_change
                ]);
            }
            
            $pdo->commit();
            header("Location: invoice.php?id=" . $id . "&success=rejected");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Erreur de rejet : " . $e->getMessage();
        }
    }
}

// Sécurité : Un vendeur ne peut pas voir une facture d'achat
if ($order['type'] === 'PURCHASE' && $_SESSION['user_role'] !== 'admin') {
    die("Accès refusé. Seul l'administrateur peut visualiser les factures de ravitaillement/achats.");
}

// Charger les items de la commande
$stmt_items = $pdo->prepare("
    SELECT oi.*, p.name as product_name, p.unit as product_unit, p.sku as product_sku, p.price_sale as catalog_price_sale, p.margin_percent
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt_items->execute([$id]);
$items = $stmt_items->fetchAll();

// Préparer le texte de partage WhatsApp/SMS
$whatsapp_text = "📝 *Ets MANDEL* - *Facture FAC-" . sprintf("%05d", $order['id']) . "*\n";
$whatsapp_text .= "----------------------------------------\n";
$whatsapp_text .= "Boutique: " . $order['shop_name'] . "\n";
$whatsapp_text .= "Date: " . date('d/m/Y H:i', strtotime($order['created_at'])) . "\n";
$whatsapp_text .= "Client: " . ($order['client_name'] ?? 'Client inconnu') . "\n";
$whatsapp_text .= "Paiement: " . ($order['payment_method'] === 'CREDIT' ? 'CRÉDIT' : $order['payment_method']) . "\n";
$whatsapp_text .= "----------------------------------------\n";
$whatsapp_text .= "*Produits :*\n";
foreach ($items as $item) {
    $whatsapp_text .= "- " . $item['quantity'] . "x " . $item['product_name'] . " (" . number_format($item['unit_price'], 0, '.', ' ') . " FCFA/" . $item['product_unit'] . ") : " . number_format($item['total_price'], 0, '.', ' ') . " FCFA\n";
}
$whatsapp_text .= "----------------------------------------\n";
if ($order['is_formal']) {
    $whatsapp_text .= "Total HT : " . number_format($order['subtotal'], 0, '.', ' ') . " FCFA\n";
    if ($order['tax_amount'] > 0) {
        $whatsapp_text .= "TVA : +" . number_format($order['tax_amount'], 0, '.', ' ') . " FCFA\n";
    }
    if ($order['ir_amount'] > 0 && $order['payment_method'] === 'VIREMENT') {
        $whatsapp_text .= "IR : -" . number_format($order['ir_amount'], 0, '.', ' ') . " FCFA\n";
    }
    $whatsapp_text .= "*TOTAL TTC : " . number_format($order['total_amount'], 0, '.', ' ') . " FCFA*\n";
    if ($order['ir_amount'] > 0 && $order['payment_method'] === 'VIREMENT') {
        $whatsapp_text .= "*NAP : " . number_format($order['nap_amount'], 0, '.', ' ') . " FCFA*\n";
    } else {
        $whatsapp_text .= "*NET À PAYER : " . number_format($order['total_amount'], 0, '.', ' ') . " FCFA*\n";
    }
} else {
    $whatsapp_text .= "*TOTAL : " . number_format($order['total_amount'], 0, '.', ' ') . " FCFA*\n";
}

if ($order['payment_method'] === 'CREDIT') {
    $remaining_debt = floatval($order['total_amount']) - floatval($order['amount_paid']);
    $whatsapp_text .= "*Acompte : " . number_format($order['amount_paid'], 0, '.', ' ') . " FCFA*\n";
    $whatsapp_text .= "*Reste à payer : " . number_format($remaining_debt, 0, '.', ' ') . " FCFA*\n";
    if ($order['due_date']) {
        $whatsapp_text .= "Date Limite : " . date('d/m/Y', strtotime($order['due_date'])) . "\n";
    }
}
$whatsapp_text .= "----------------------------------------\n";
$whatsapp_text .= "Merci pour votre confiance !";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facture <?= htmlspecialchars($order['reference'] ?? 'FAC-'.sprintf("%05d", $order['id'])) ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom Style Sheet -->
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        @media screen {
            body {
                background: #e2e8f0;
                padding: 15px 0;
                font-size: 0.7rem;
                line-height: 1.2;
            }
            .invoice-box {
                background: #fff;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px 24px;
                border-radius: 10px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
                border: 1px solid rgba(0,0,0,0.05);
            }
        }
        @media print {
            body * { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important; }
            .invoice-box { 
                line-height: 1.15; 
                padding: 0;
                margin: 0;
                box-shadow: none;
                border: none;
            }
            .print-header { margin-bottom: 4px !important; }
            .mb-3 { margin-bottom: 5px !important; }
            .mb-4 { margin-bottom: 6px !important; }
            .my-3, .my-4 { margin-top: 5px !important; margin-bottom: 5px !important; }
            .mt-2 { margin-top: 3px !important; }
            .mt-3 { margin-top: 4px !important; }
            .pt-2 { padding-top: 2px !important; }
            .py-2 { padding-top: 2px !important; padding-bottom: 2px !important; }
            hr { margin: 3px 0 !important; }
        }
    </style>
</head>
<body class="invoice-print-body">

<div class="container">
    
    <!-- Messages de succès/erreur (Masqués à l'impression) -->
    <?php if ($success_msg || $error_msg): ?>
        <div class="row no-print mb-3 justify-content-center">
            <div class="col-12 col-md-8">
                <?php if ($success_msg): ?>
                    <div class="alert alert-success border-0 shadow-sm" style="border-radius: 12px;">
                        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px;">
                        <i class="fa-solid fa-circle-exclamation me-2"></i><?= $error_msg ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Encadré de Validation pour l'Administrateur (Masqué à l'impression) -->
    <?php if ($order['status'] === 'PENDING_APPROVAL' && $_SESSION['user_role'] === 'admin'): ?>
        <div class="row no-print mb-4 justify-content-center">
            <div class="col-12 col-md-8">
                <div class="card border-warning bg-warning bg-opacity-10 shadow-sm p-4" style="border-radius: 16px;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
                        <div>
                            <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-shield-halved text-warning me-2"></i>Validation Requise</h5>
                            <p class="small text-secondary mb-0">Cette commande a été vendue en dessous de la marge autorisée. Veuillez valider ou rejeter cette vente exceptionnelle.</p>
                        </div>
                        <form action="invoice.php?id=<?= $id ?>" method="POST" class="d-flex gap-2">
                            <button type="submit" name="approve_order" class="btn btn-success fw-bold px-3 py-2 btn-confirm-action" style="border-radius: 10px;" onclick="return confirm('Êtes-vous sûr de vouloir valider cette commande ? Le stock sera mis à jour.');">
                                <i class="fa-solid fa-check me-1"></i>Valider la vente
                            </button>
                            <button type="submit" name="reject_order" class="btn btn-danger fw-bold px-3 py-2 btn-confirm-action" style="border-radius: 10px;" onclick="return confirm('Êtes-vous sûr de vouloir rejeter cette commande ?');">
                                <i class="fa-solid fa-xmark me-1"></i>Rejeter
                            </button>
                        </form>
                    </div>
                    
                    <!-- Table d'analyse de l'écart -->
                    <div class="p-3 bg-white rounded-3 border">
                        <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-list-check me-2"></i>Détail de l'écart de prix</h6>
                        <table class="table table-sm table-bordered mb-2" style="font-size: 0.85rem;">
                            <thead>
                                <tr class="table-light">
                                    <th>Produit</th>
                                    <th class="text-center">Prix Catalogue</th>
                                    <th class="text-center">Marge Min</th>
                                    <th class="text-center">Prix Appliqué</th>
                                    <th class="text-end">Écart Unit.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $expected_total = 0;
                                foreach ($items as $item): 
                                    $cat_price = floatval($item['catalog_price_sale']);
                                    $margin = floatval($item['margin_percent'] ?? 5);
                                    $min_price = $cat_price * (1 - $margin/100);
                                    $app_price = floatval($item['unit_price']);
                                    $diff = $app_price - $cat_price;
                                    $expected_total += $item['quantity'] * $cat_price;
                                    
                                    $row_class = ($app_price < $min_price) ? 'table-danger' : '';
                                ?>
                                    <tr class="<?= $row_class ?>">
                                        <td><?= htmlspecialchars($item['product_name']) ?> (x<?= $item['quantity'] ?>)</td>
                                        <td class="text-center"><?= number_format($cat_price, 0, '.', ' ') ?> F</td>
                                        <td class="text-center"><?= number_format($min_price, 0, '.', ' ') ?> F</td>
                                        <td class="text-center fw-bold"><?= number_format($app_price, 0, '.', ' ') ?> F</td>
                                        <td class="text-end fw-bold <?= $diff < 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= ($diff > 0 ? '+' : '') . number_format($diff, 0, '.', ' ') ?> F
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="d-flex justify-content-between text-dark fw-bold small mt-2 flex-wrap gap-2">
                            <span>Total attendu : <?= number_format($expected_total, 0, '.', ' ') ?> FCFA</span>
                            <span>Total appliqué : <?= number_format($order['total_amount'], 0, '.', ' ') ?> FCFA</span>
                            <span class="text-danger">Manque à gagner : <?= number_format($expected_total - $order['total_amount'], 0, '.', ' ') ?> FCFA</span>
                        </div>
                        <?php if (!empty($order['discount_reason'])): ?>
                            <div class="mt-3 p-2 bg-danger bg-opacity-10 border border-danger-subtle rounded text-danger-emphasis small">
                                <strong><i class="fa-solid fa-quote-left me-1"></i>Motif du vendeur :</strong>
                                <?= htmlspecialchars($order['discount_reason']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($order['status'] === 'PENDING_APPROVAL'): ?>
        <!-- Avertissement pour le Vendeur (Masqué à l'impression) -->
        <div class="row no-print mb-4 justify-content-center">
            <div class="col-12 col-md-8">
                <div class="alert alert-warning border-0 shadow-sm p-4" style="border-radius: 12px;">
                    <h6 class="fw-bold mb-2"><i class="fa-solid fa-clock me-2"></i>En attente de validation administrateur</h6>
                    <p class="small text-secondary mb-3">Cette vente est en attente car un ou plusieurs articles ont été saisis avec un prix hors marge.</p>
                    
                    <?php if (!empty($order['discount_reason'])): ?>
                        <div class="p-2 bg-white rounded border small text-secondary">
                            <strong>Motif déclaré :</strong> <?= htmlspecialchars($order['discount_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php elseif ($order['status'] === 'REJECTED'): ?>
        <!-- Alerte commande rejetée (Masquée à l'impression) -->
        <div class="row no-print mb-4 justify-content-center">
            <div class="col-12 col-md-8">
                <div class="alert alert-danger border-0 shadow-sm p-3" style="border-radius: 12px;">
                    <h6 class="fw-bold mb-1"><i class="fa-solid fa-circle-xmark me-2"></i>Facture Rejetée</h6>
                    <p class="small mb-0">Cette facture de vente a été officiellement rejetée par l'administrateur.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Boutons d'action (Masqués à l'impression) -->
    <div class="row no-print mb-4 justify-content-center">
        <div class="col-12 col-md-8 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex gap-2">
                <a href="orders.php" class="btn btn-outline-secondary" style="border-radius: 10px;">
                    <i class="fa-solid fa-arrow-left me-1"></i>Retour aux Transactions
                </a>
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                    <a href="order-edit.php?id=<?= $order['id'] ?>" class="btn btn-outline-warning" style="border-radius: 10px;" title="Modifier la facture">
                        <i class="fa-solid fa-pen-to-square me-1"></i>Modifier
                    </a>
                    <a href="order-delete.php?id=<?= $order['id'] ?>" class="btn btn-outline-danger" style="border-radius: 10px;" title="Supprimer la facture" onclick="return confirm('Êtes-vous sûr de vouloir supprimer définitivement cette facture ?\nLes stocks et la caisse seront automatiquement réajustés.');">
                        <i class="fa-solid fa-trash me-1"></i>Supprimer
                    </a>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <?php if ($order['status'] === 'COMPLETED'): ?>
                    <button onclick="copyTicketText();" class="btn btn-outline-success" style="border-radius: 10px;">
                        <i class="fa-solid fa-copy me-1"></i>Copier
                    </button>
                    <a href="https://api.whatsapp.com/send?text=<?= urlencode($whatsapp_text) ?>" target="_blank" class="btn btn-success d-flex align-items-center" style="border-radius: 10px; background-color: #25d366; border-color: #25d366; color: white; text-decoration: none;">
                        <i class="fa-brands fa-whatsapp me-1"></i>Partager
                    </a>
                    <button onclick="window.print();" class="btn btn-grad">
                        <i class="fa-solid fa-print me-1"></i>Imprimer le Ticket
                    </button>
                <?php else: ?>
                    <button class="btn btn-outline-secondary" disabled style="border-radius: 10px;" title="Action impossible en attente de validation">
                        <i class="fa-solid fa-ban me-1"></i>Partager
                    </button>
                    <button class="btn btn-secondary" disabled style="border-radius: 10px;" title="Action impossible en attente de validation">
                        <i class="fa-solid fa-print me-1"></i>Imprimer (Non validé)
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Conteneur de la Facture -->
    <div class="invoice-box print-container">
        
        <!-- Header du ticket -->
        <div class="print-header text-center mb-3">
            <h4 class="fw-bold mb-1" style="letter-spacing: -0.5px;">Ets MANDEL</h4>
            <div class="small text-uppercase fw-semibold text-secondary mb-1"><?= htmlspecialchars($order['shop_name']) ?></div>
            <div class="small text-secondary" style="font-size: 11px; line-height: 1.2;">
                <i class="fa-solid fa-map-marker-alt me-1"></i><?= htmlspecialchars($order['shop_address'] ?? 'Yaoundé, Cameroun') ?><br>
                <i class="fa-solid fa-phone me-1"></i>Tél : <?= htmlspecialchars($order['shop_phone'] ?? 'Non spécifié') ?>
                <?php if ($order['is_formal']): ?>
                    <br><strong>NUI:</strong> <?= htmlspecialchars($order['shop_nui'] ?? 'Non renseigné') ?> | <strong>RC:</strong> <?= htmlspecialchars($order['shop_rc'] ?? 'Non renseigné') ?>
                <?php endif; ?>
            </div>
        </div>

        <hr style="border-top: 1px dashed #000;" class="my-2">

        <!-- Infos Transaction -->
        <div class="mb-3 small transaction-info" style="font-size: 11px; line-height: 1.3;">
            <div><strong>Facture N° :</strong> <?= htmlspecialchars($order['reference'] ?? 'FAC-'.sprintf("%05d", $order['id'])) ?></div>
            <div><strong>Date :</strong> <?= date('d/m/Y H:i:s', strtotime($order['created_at'])) ?></div>
            <div><strong>Type :</strong> <?= $order['type'] === 'SALE' ? 'VENTE' : 'RAVITAILLEMENT' ?></div>
            <div><strong>Opérateur :</strong> <?= htmlspecialchars($order['user_name'] ?? 'Admin') ?></div>
            <div><strong>Règlement :</strong> <?= htmlspecialchars($order['payment_method']) ?></div>
            <?php if ($order['status'] === 'PENDING_APPROVAL'): ?>
                <div class="text-warning fw-bold mt-1" style="font-size: 12px;"><i class="fa-solid fa-clock me-1"></i>STATUT : EN ATTENTE DE VALIDATION</div>
            <?php elseif ($order['status'] === 'REJECTED'): ?>
                <div class="text-danger fw-bold mt-1" style="font-size: 12px;"><i class="fa-solid fa-circle-xmark me-1"></i>STATUT : REJETÉ</div>
            <?php endif; ?>
            
            <?php if ($order['type'] === 'SALE'): ?>
                <div class="mt-2 border-top pt-2">
                    <strong>Client :</strong> <?= htmlspecialchars($order['client_name'] ?? 'Client inconnu') ?><br>
                    <?php if ($order['client_phone']): ?>
                        <strong>Tél :</strong> <?= htmlspecialchars($order['client_phone']) ?><br>
                    <?php endif; ?>
                    <?php if ($order['is_formal']): ?>
                        <strong>NUI :</strong> <?= htmlspecialchars($order['client_nui'] ?? 'Non renseigné') ?><br>
                        <strong>RC :</strong> <?= htmlspecialchars($order['client_rc'] ?? 'Non renseigné') ?><br>
                        <strong>BP :</strong> <?= htmlspecialchars($order['client_bp'] ?? 'Non renseigné') ?><br>
                        <strong>Adresse :</strong> <?= htmlspecialchars($order['client_address'] ?? 'Non renseigné') ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mt-2 border-top pt-2">
                    <strong>Fournisseur :</strong> <?= htmlspecialchars($order['supplier_name'] ?? 'Fournisseur inconnu') ?><br>
                    <?php if ($order['supplier_phone']): ?>
                        <strong>Tél :</strong> <?= htmlspecialchars($order['supplier_phone']) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <hr style="border-top: 1px dashed #000;" class="my-2">

        <!-- Tableau des produits -->
        <table class="print-table w-100 mb-3" style="font-size: 11px;">
            <thead>
                <tr>
                    <th class="text-start" style="width: 55%;">Désignation</th>
                    <th class="text-center" style="width: 15%;">Qté</th>
                    <th class="text-end" style="width: 30%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="text-start py-2">
                            <div class="fw-bold"><?= htmlspecialchars($item['product_name']) ?></div>
                            <small class="text-secondary"><?= number_format($item['unit_price'], 0, '.', ' ') ?> FCFA / <?= htmlspecialchars($item['product_unit']) ?></small>
                        </td>
                        <td class="text-center py-2"><?= $item['quantity'] ?></td>
                        <td class="text-end py-2 fw-bold"><?= number_format($item['total_price'], 0, '.', ' ') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totaux -->
        <?php if ($order['is_formal']): ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-2 pt-2" style="font-size: 11px; border-top: 1px dashed #000;">
                <span class="text-uppercase fw-bold">Total HT :</span>
                <span class="fw-bold"><?= number_format($order['subtotal'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php if ($order['tax_amount'] > 0): ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-1" style="font-size: 11px;">
                <span class="text-uppercase fw-bold text-danger">TVA :</span>
                <span class="fw-bold text-danger">+ <?= number_format($order['tax_amount'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php endif; ?>
            <?php if ($order['ir_amount'] > 0 && $order['payment_method'] === 'VIREMENT'): ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-1" style="font-size: 11px;">
                <span class="text-uppercase fw-bold text-primary">IR (Retenue) :</span>
                <span class="fw-bold text-primary">- <?= number_format($order['ir_amount'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php endif; ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-1 pt-1" style="font-size: 13px; border-top: 1px solid #000;">
                <span class="text-uppercase fw-bold">Total TTC :</span>
                <span class="fw-extrabold" style="font-size: 16px;"><?= number_format($order['total_amount'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php if ($order['ir_amount'] > 0 && $order['payment_method'] === 'VIREMENT'): ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-1" style="font-size: 13px;">
                <span class="text-uppercase fw-bold">Net à Payer (NAP) :</span>
                <span class="fw-extrabold" style="font-size: 16px;"><?= number_format($order['nap_amount'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php else: ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-1" style="font-size: 13px;">
                <span class="text-uppercase fw-bold">Net à Payer :</span>
                <span class="fw-extrabold" style="font-size: 16px;"><?= number_format($order['total_amount'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-2 pt-2" style="font-size: 13px; border-top: 1px dashed #000;">
                <span class="text-uppercase fw-bold">Montant Total :</span>
                <span class="fw-extrabold" style="font-size: 16px;"><?= number_format($order['total_amount'], 0, '.', ' ') ?> FCFA</span>
            </div>
        <?php endif; ?>

        <?php if ($order['payment_method'] === 'CREDIT'): 
            $remaining = floatval($order['total_amount']) - floatval($order['amount_paid']);
        ?>
            <div class="print-total d-flex justify-content-between align-items-center mt-1 pt-1" style="font-size: 11px;">
                <span class="text-uppercase fw-bold text-success">Acompte Payé :</span>
                <span class="fw-bold text-success"><?= number_format($order['amount_paid'], 0, '.', ' ') ?> FCFA</span>
            </div>
            <div class="print-total d-flex justify-content-between align-items-center mt-1 pt-1" style="font-size: 12px; border-top: 1px dotted #ccc;">
                <span class="text-uppercase fw-bold text-danger">Reste à Payer :</span>
                <span class="fw-extrabold text-danger"><?= number_format($remaining, 0, '.', ' ') ?> FCFA</span>
            </div>
            <?php if ($order['due_date']): ?>
                <div class="print-total d-flex justify-content-between align-items-center mt-1 pt-1 text-secondary" style="font-size: 10px;">
                    <span class="text-uppercase font-semibold">Date Limite de Règlement :</span>
                    <span class="fw-bold"><?= date('d/m/Y', strtotime($order['due_date'])) ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php 
        if ($order['is_formal']): 
            // Si le client paye par virement, l'arrêtée de caisse est sur le NAP, sinon sur le TTC.
            $arrete_amount = ($order['payment_method'] === 'VIREMENT') ? $order['nap_amount'] : $order['total_amount'];
            $amount_in_words = numberToWords(floor($arrete_amount));
        ?>
            <div class="mt-3 p-2 text-center" style="font-size: 11px; font-weight: bold; text-transform: uppercase;">
                Facture arrêtée à la somme de :<br>
                <?= $amount_in_words ?> Francs CFA
                <?php if ($order['payment_method'] === 'VIREMENT'): ?>
                    <br><small class="text-muted" style="text-transform: none; font-weight: normal;">(Net à Payer par virement/chèque)</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($order['notes'])): ?>
            <div class="mt-3 p-2 bg-light border small rounded-2" style="font-size: 10px;">
                <strong>Note :</strong> <?= htmlspecialchars($order['notes']) ?>
            </div>
        <?php endif; ?>

        <hr style="border-top: 1px dashed #000;" class="my-3">

        <!-- Footer du ticket -->
        <div class="text-center small text-secondary print-footer" style="font-size: 10px; line-height: 1.3;">
            <p class="mb-1 fw-bold text-uppercase">Merci de votre visite !</p>
            <p class="mb-0">Les articles vendus ne sont ni repris, ni échangés.</p>
            <p class="mt-2 text-xs">Mandel Stock Manager &copy; <?= date('Y') ?></p>
        </div>

    </div>

</div>

<script>
function copyTicketText() {
    const text = <?= json_encode($whatsapp_text) ?>;
    navigator.clipboard.writeText(text).then(function() {
        alert("Le texte du ticket a été copié dans le presse-papiers ! Vous pouvez maintenant le coller sur WhatsApp.");
    }, function(err) {
        alert("Erreur lors de la copie : " + err);
    });
}
</script>
</body>
</html>
