<?php
require_once __DIR__ . '/../backend/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$code = trim($_GET['code'] ?? '');
$id = intval($_GET['id'] ?? 0);

if (empty($code) && $id <= 0) {
    die("Référence de transfert non spécifiée.");
}

// Récupérer le code si on a juste l'id
if (empty($code) && $id > 0) {
    try {
        $code_stmt = $pdo->prepare("SELECT transfer_code FROM stock_transfers WHERE id = ?");
        $code_stmt->execute([$id]);
        $code = $code_stmt->fetchColumn() ?: '';
    } catch (Exception $e) {
        $code = '';
    }
}

$items = [];
if (!empty($code)) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   p.name as product_name, p.unit as product_unit,
                   f_shop.name as from_shop_name, f_shop.address as from_shop_address, f_shop.phone as from_shop_phone,
                   t_shop.name as to_shop_name, t_shop.address as to_shop_address, t_shop.phone as to_shop_phone,
                   u.name as user_name
            FROM stock_transfers t
            JOIN products p ON t.product_id = p.id
            JOIN shops f_shop ON t.from_shop_id = f_shop.id
            JOIN shops t_shop ON t.to_shop_id = t_shop.id
            JOIN users u ON t.user_id = u.id
            WHERE t.transfer_code = ? OR t.id = ?
            ORDER BY t.id ASC
        ");
        $stmt->execute([$code, $id]);
        $items = $stmt->fetchAll();
    } catch (Exception $e) {
        $items = [];
    }
}

// Fallback par ID si la recherche par code a échoué
if (empty($items) && $id > 0) {
    try {
        $stmt_fb = $pdo->prepare("
            SELECT t.*, 
                   p.name as product_name, p.unit as product_unit,
                   f_shop.name as from_shop_name, f_shop.address as from_shop_address, f_shop.phone as from_shop_phone,
                   t_shop.name as to_shop_name, t_shop.address as to_shop_address, t_shop.phone as to_shop_phone,
                   u.name as user_name
            FROM stock_transfers t
            JOIN products p ON t.product_id = p.id
            JOIN shops f_shop ON t.from_shop_id = f_shop.id
            JOIN shops t_shop ON t.to_shop_id = t_shop.id
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
            ORDER BY t.id ASC
        ");
        $stmt_fb->execute([$id]);
        $items = $stmt_fb->fetchAll();
    } catch (Exception $ex) {
        $items = [];
    }
}

if (empty($items)) {
    die("Aucun transfert trouvé pour cette référence.");
}

$first_transfer = $items[0];
$transfer_code_display = !empty($first_transfer['transfer_code']) ? $first_transfer['transfer_code'] : ('TR-' . sprintf("%05d", $first_transfer['id']));
$total_qty_sum = array_sum(array_column($items, 'quantity'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon de Transfert #<?= htmlspecialchars($transfer_code_display) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        .invoice-box {
            max-width: 480px; 
            margin: 20px auto;
            background: #fff;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-radius: 12px;
            font-family: 'Courier New', Courier, monospace;
            color: #000;
        }
        .btn-grad {
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            color: white; border: none;
        }
        .btn-grad:hover {
            background: linear-gradient(135deg, #4338ca 0%, #2563eb 100%);
            color: white;
        }
        @media print {
            body { background-color: #fff; }
            .no-print { display: none !important; }
            .invoice-box {
                box-shadow: none; border: none; margin: 0; padding: 0;
                width: 100%; max-width: 100%;
            }
            @page { margin: 0.5cm; }
        }
    </style>
</head>
<body class="invoice-print-body">

<div class="container">
    
    <!-- Boutons d'action (Masqués à l'impression) -->
    <div class="row no-print mt-4 mb-4 justify-content-center">
        <div class="col-12 col-md-8 d-flex justify-content-between">
            <button onclick="window.close()" class="btn btn-outline-secondary" style="border-radius: 10px;">
                <i class="fa-solid fa-arrow-left me-1"></i>Fermer
            </button>
            <button onclick="window.print();" class="btn btn-grad px-4" style="border-radius: 10px;">
                <i class="fa-solid fa-print me-1"></i>Imprimer le Bon de Transfert (<?= count($items) ?> article<?= count($items) > 1 ? 's' : '' ?>)
            </button>
        </div>
    </div>

    <!-- Conteneur de la Facture -->
    <div class="invoice-box print-container">
        
        <!-- Header du ticket -->
        <div class="print-header text-center mb-3">
            <h4 class="fw-bold mb-1" style="letter-spacing: -0.5px;">Ets MANDEL</h4>
            <div class="small text-uppercase fw-semibold text-secondary mb-1">BON DE TRANSFERT DE STOCK MULTI-ARTICLES</div>
            <div class="small text-secondary" style="font-size: 11px; line-height: 1.2;">
                <i class="fa-solid fa-store me-1"></i>Départ : <?= htmlspecialchars($first_transfer['from_shop_name']) ?><br>
                <i class="fa-solid fa-arrow-right me-1"></i>Arrivée : <?= htmlspecialchars($first_transfer['to_shop_name']) ?>
            </div>
        </div>

        <hr style="border-top: 1px dashed #000;" class="my-2">

        <!-- Infos Transaction -->
        <div class="mb-3 small transaction-info" style="font-size: 11px; line-height: 1.3;">
            <div><strong>N° Transfert :</strong> <?= htmlspecialchars($transfer_code_display) ?></div>
            <div><strong>Date :</strong> <?= date('d/m/Y H:i:s', strtotime($first_transfer['created_at'])) ?></div>
            <div><strong>Opérateur :</strong> <?= htmlspecialchars($first_transfer['user_name']) ?></div>
            <div><strong>Statut :</strong> <?= ($first_transfer['status'] ?? 'RECEIVED') === 'RECEIVED' ? '<span style="color: green; font-weight: bold;">[RÉCEPTIONNÉ]</span>' : '<span style="color: orange; font-weight: bold;">[EN TRANSIT - EN ATTENTE]</span>' ?></div>
            <?php if (!empty($first_transfer['notes'])): ?>
                <div><strong>Observations :</strong> <?= htmlspecialchars($first_transfer['notes']) ?></div>
            <?php endif; ?>
        </div>

        <hr style="border-top: 1px dashed #000;" class="my-2">

        <!-- Tableau des Articles -->
        <table style="width: 100%; font-size: 11px; margin-bottom: 10px;">
            <thead>
                <tr style="border-bottom: 1px dashed #000;">
                    <th class="text-start py-1">#</th>
                    <th class="text-start py-1">Description du Produit</th>
                    <th class="text-end py-1">Quantité</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <tr style="border-bottom: 1px dotted #ddd;">
                        <td class="py-1.5 align-top" style="width: 20px; font-weight: bold;"><?= $idx + 1 ?>.</td>
                        <td class="py-1.5 align-top" style="font-weight: 600;">
                            <?= htmlspecialchars($it['product_name']) ?>
                        </td>
                        <td class="text-end py-1.5 align-top" style="white-space: nowrap;">
                            <span style="font-weight: bold; font-size: 12px;"><?= $it['quantity'] ?></span> <?= htmlspecialchars($it['product_unit']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top: 1px dashed #000; font-weight: bold;">
                    <td colspan="2" class="py-2">Total (<?= count($items) ?> article<?= count($items) > 1 ? 's' : '' ?>) :</td>
                    <td class="text-end py-2" style="font-size: 12px;"><?= $total_qty_sum ?> unités</td>
                </tr>
            </tfoot>
        </table>

        <hr style="border-top: 1px dashed #000;" class="my-2 mb-3">

        <div class="d-flex justify-content-between mb-4" style="font-size: 11px;">
            <div class="text-center" style="width: 45%;">
                <strong>Visa Expéditeur</strong><br>
                (Source)<br><br><br>
                ......................
            </div>
            <div class="text-center" style="width: 45%;">
                <strong>Visa Réceptionnaire</strong><br>
                (Destination)<br><br><br>
                ......................
            </div>
        </div>

        <div class="text-center mt-3" style="font-size: 10px; color: #555;">
            Document généré le <?= date('d/m/Y H:i') ?>.<br>
            <strong>Ets MANDEL - Système de Gestion Multi-Boutiques</strong>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
