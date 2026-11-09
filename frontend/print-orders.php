<?php
require_once __DIR__ . '/../backend/config/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$user_role = $_SESSION['user_role'] ?? 'seller';
$active_shop_id = intval($_SESSION['active_shop_id'] ?? 0);

// Récupération des filtres
$shop_filter = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : $active_shop_id;
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : '';
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$client_filter = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_start = isset($_GET['date_start']) ? trim($_GET['date_start']) : '';
$date_end = isset($_GET['date_end']) ? trim($_GET['date_end']) : '';
$payment_filter = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$seller_view = isset($_GET['view']) ? trim($_GET['view']) : 'all';

// Nom de la boutique
$shop_name = "Tous les Dépôts (Global)";
if ($shop_filter > 0) {
    $st = $pdo->prepare("SELECT name FROM shops WHERE id = ?");
    $st->execute([$shop_filter]);
    $shop_name = $st->fetchColumn() ?: "Boutique #$shop_filter";
}

// Nom du vendeur filtré
$seller_name = "Tous les vendeurs";
if ($user_filter > 0) {
    $su = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $su->execute([$user_filter]);
    $seller_name = $su->fetchColumn() ?: "Vendeur #$user_filter";
} elseif ($user_role !== 'admin' && $seller_view === 'mine') {
    $seller_name = $_SESSION['user_name'] ?? 'Mes ventes';
}

// Construction de la requête SANS LIMIT (prend TOUT ce qui correspond aux filtres)
$sql = "
    SELECT o.*, 
           c.name as client_name, 
           sup.name as supplier_name,
           sh.name as shop_name,
           u.name as user_name
    FROM orders o
    JOIN shops sh ON o.shop_id = sh.id
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN suppliers sup ON o.supplier_id = sup.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE 1=1
";

$params = [];

if ($user_role !== 'admin') {
    $sql .= " AND o.shop_id = :shop_id";
    $params[':shop_id'] = $active_shop_id;
    $sql .= " AND o.type = 'SALE'";
    if ($seller_view === 'mine') {
        $sql .= " AND o.user_id = :user_id";
        $params[':user_id'] = $_SESSION['user_id'];
    }
} else {
    if ($shop_filter > 0) {
        $sql .= " AND o.shop_id = :shop_id";
        $params[':shop_id'] = $shop_filter;
    }
    if ($type_filter === 'SALE' || $type_filter === 'PURCHASE') {
        $sql .= " AND o.type = :type";
        $params[':type'] = $type_filter;
    }
    if ($user_filter > 0) {
        $sql .= " AND o.user_id = :user_filter";
        $params[':user_filter'] = $user_filter;
    }
}

if ($client_filter > 0) {
    $sql .= " AND o.client_id = :client_id";
    $params[':client_id'] = $client_filter;
}

if ($search !== '') {
    $sql .= " AND (c.name LIKE :search OR sup.name LIKE :search OR o.id LIKE :search_id)";
    $params[':search'] = '%' . $search . '%';
    $params[':search_id'] = '%' . intval(str_replace('FAC-', '', $search)) . '%';
}

if ($date_start !== '') {
    $sql .= " AND DATE(o.created_at) >= :date_start";
    $params[':date_start'] = $date_start;
}

if ($date_end !== '') {
    $sql .= " AND DATE(o.created_at) <= :date_end";
    $params[':date_end'] = $date_end;
}

if ($payment_filter !== '') {
    $sql .= " AND o.payment_method = :payment_method";
    $params[':payment_method'] = $payment_filter;
}

if ($status_filter !== '') {
    $sql .= " AND o.status = :status";
    $params[':status'] = $status_filter;
}

$sql .= " ORDER BY o.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des articles pour toutes les commandes
$order_items_map = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $chunks = array_chunk($order_ids, 800);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt_items = $pdo->prepare("
            SELECT oi.order_id, oi.quantity, oi.unit_price, oi.total_price, COALESCE(p.name, 'Article') as product_name, p.unit
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id IN ($placeholders)
            ORDER BY oi.id ASC
        ");
        $stmt_items->execute($chunk);
        foreach ($stmt_items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $order_items_map[$it['order_id']][] = $it;
        }
    }
}

// Calcul des totaux globaux et regroupement par date
$total_sales_count = 0;
$total_sales_amt = 0;
$total_purchases_amt = 0;
$total_encaisse = 0;
$total_credit_restant = 0;

$orders_grouped = [];
foreach ($orders as $ord) {
    $date_key = date('Y-m-d', strtotime($ord['created_at']));
    if (!isset($orders_grouped[$date_key])) {
        $orders_grouped[$date_key] = [
            'sales_total' => 0,
            'purchases_total' => 0,
            'items' => []
        ];
    }

    $amt = floatval($ord['total_amount'] ?? 0);
    if ($ord['status'] === 'COMPLETED') {
        if ($ord['type'] === 'SALE') {
            $total_sales_count++;
            $total_sales_amt += $amt;
            $orders_grouped[$date_key]['sales_total'] += $amt;

            if ($ord['payment_method'] === 'CREDIT') {
                $paid = floatval($ord['amount_paid'] ?? 0);
                $total_encaisse += $paid;
                $total_credit_restant += max(0, $amt - $paid);
            } else {
                $total_encaisse += $amt;
            }
        } elseif ($ord['type'] === 'PURCHASE') {
            $total_purchases_amt += $amt;
            $orders_grouped[$date_key]['purchases_total'] += $amt;
        }
    }

    $orders_grouped[$date_key]['items'][] = $ord;
}

// Formatage libellé période
$periode_label = "Tout l'historique";
if ($date_start && $date_end) {
    $periode_label = "Du " . date('d/m/Y', strtotime($date_start)) . " au " . date('d/m/Y', strtotime($date_end));
} elseif ($date_start) {
    $periode_label = "Depuis le " . date('d/m/Y', strtotime($date_start));
} elseif ($date_end) {
    $periode_label = "Jusqu'au " . date('d/m/Y', strtotime($date_end));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Journal des Flux - <?= htmlspecialchars($shop_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 8.5pt;
            color: #0f172a;
            background: #fff;
        }
        .print-btn-bar {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 24px;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 8pt; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
            .date-header-card { page-break-after: avoid; }
            @page {
                size: A4 landscape;
                margin: 8mm 8mm 8mm 8mm;
            }
        }
        .table-journal {
            border-color: #cbd5e1;
        }
        .table-journal th {
            background-color: #0f172a !important;
            color: #ffffff !important;
            font-weight: 700;
            font-size: 8pt;
            text-transform: uppercase;
            padding: 5px 6px;
            vertical-align: middle;
        }
        .table-journal td {
            vertical-align: middle;
            padding: 4px 6px;
            font-size: 8pt;
        }
        .kpi-box {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px 12px;
            background: #f8fafc;
        }
        .kpi-title {
            font-size: 7.5pt;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
        }
        .kpi-val {
            font-size: 11pt;
            font-weight: 800;
            color: #0f172a;
        }
        .badge-print {
            padding: 2px 5px;
            font-size: 7.5pt;
            border-radius: 4px;
            font-weight: 600;
            display: inline-block;
        }
        .article-tag {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 1px 4px;
            margin: 1px;
            display: inline-block;
            font-size: 7.5pt;
            line-height: 1.25;
        }
    </style>
</head>
<body>

<div class="print-btn-bar no-print d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <button onclick="window.print()" class="btn btn-primary fw-bold btn-sm shadow-sm">
            <i class="fa-solid fa-print me-1"></i>Imprimer le Journal (<?= count($orders) ?> transactions)
        </button>
        <a href="export-orders-excel.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success fw-bold btn-sm shadow-sm">
            <i class="fa-solid fa-file-excel me-1"></i>Télécharger Excel (.xlsx)
        </a>
    </div>
    <div class="text-secondary small">
        <i class="fa-solid fa-circle-info me-1"></i>Impression paysage A4 recommandée. Toutes les pages filtrées sont incluses.
    </div>
    <button onclick="window.close()" class="btn btn-outline-secondary btn-sm">Fermer</button>
</div>

<div class="container-fluid px-3 py-2">
    <!-- En-tête officiel Mandel -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
        <div>
            <h4 class="fw-extrabold mb-0 text-slate-900" style="letter-spacing: 0.5px;">ETS MANDEL PAPETERIE</h4>
            <div class="fw-bold text-uppercase text-primary" style="font-size: 10.5pt;">
                RAPPORT D'ACTIVITÉ & JOURNAL DES FLUX DE CAISSE
            </div>
            <div class="text-secondary small mt-1">
                <strong>Dépôt / Magasin :</strong> <span class="text-dark fw-bold"><?= htmlspecialchars($shop_name) ?></span>
                &nbsp;|&nbsp;
                <strong>Période :</strong> <span class="text-dark fw-bold"><?= htmlspecialchars($periode_label) ?></span>
                &nbsp;|&nbsp;
                <strong>Vendeur :</strong> <span class="text-dark fw-bold"><?= htmlspecialchars($seller_name) ?></span>
                <?php if ($type_filter): ?>
                    &nbsp;|&nbsp;<strong>Flux :</strong> <span class="text-dark fw-bold"><?= $type_filter === 'SALE' ? 'Ventes' : 'Achats' ?></span>
                <?php endif; ?>
                <?php if ($payment_filter): ?>
                    &nbsp;|&nbsp;<strong>Règlement :</strong> <span class="text-dark fw-bold"><?= htmlspecialchars($payment_filter) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-end small">
            <div><strong>Date d'Édition :</strong> <?= date('d/m/Y à H:i') ?></div>
            <div><strong>Opérateur :</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Personnel') ?></div>
            <div><strong>Total Enregistrements :</strong> <span class="badge bg-dark"><?= count($orders) ?></span></div>
        </div>
    </div>

    <!-- Synthèse Chiffrée Globale (KPIs) -->
    <div class="row g-2 mb-3">
        <div class="col">
            <div class="kpi-box text-center">
                <div class="kpi-title">Factures Traitées</div>
                <div class="kpi-val text-primary"><?= count($orders) ?></div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-box text-center">
                <div class="kpi-title">Ventes Réalisées</div>
                <div class="kpi-val text-success"><?= number_format($total_sales_amt, 0, '.', ' ') ?> F</div>
            </div>
        </div>
        <div class="col">
            <div class="kpi-box text-center">
                <div class="kpi-title">Encaissé Net</div>
                <div class="kpi-val text-success"><?= number_format($total_encaisse, 0, '.', ' ') ?> F</div>
            </div>
        </div>
        <?php if ($total_credit_restant > 0): ?>
        <div class="col">
            <div class="kpi-box text-center">
                <div class="kpi-title">Crédits en cours</div>
                <div class="kpi-val text-danger"><?= number_format($total_credit_restant, 0, '.', ' ') ?> F</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($user_role === 'admin'): ?>
        <div class="col">
            <div class="kpi-box text-center">
                <div class="kpi-title">Stock Approvisionné</div>
                <div class="kpi-val text-primary"><?= number_format($total_purchases_amt, 0, '.', ' ') ?> F</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tableau détaillé groupé par date -->
    <?php if (empty($orders)): ?>
        <div class="alert alert-warning text-center my-4">
            Aucune transaction ne correspond aux filtres sélectionnés.
        </div>
    <?php else: ?>
        <?php foreach ($orders_grouped as $date => $grp): 
            $dt_label = date('d/m/Y', strtotime($date));
            $day_name = [
                'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi',
                'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi', 'Sunday' => 'Dimanche'
            ][date('l', strtotime($date))] ?? '';
        ?>
            <!-- En-tête de date du jour avec totaux journaliers -->
            <div class="d-flex justify-content-between align-items-center bg-light border px-2 py-1 mb-1 mt-2 rounded date-header-card">
                <div>
                    <span class="fw-bold text-dark"><i class="fa-regular fa-calendar me-1"></i><?= $day_name ?> <?= $dt_label ?></span>
                    <span class="text-secondary small ms-2">(<?= count($grp['items']) ?> transaction(s))</span>
                </div>
                <div class="small fw-bold">
                    <span class="text-success me-3">Ventes : <?= number_format($grp['sales_total'], 0, '.', ' ') ?> FCFA</span>
                    <?php if ($user_role === 'admin' && $grp['purchases_total'] > 0): ?>
                        <span class="text-primary">Achats : <?= number_format($grp['purchases_total'], 0, '.', ' ') ?> FCFA</span>
                    <?php endif; ?>
                </div>
            </div>

            <table class="table table-bordered table-sm table-journal mb-3">
                <thead>
                    <tr>
                        <th style="width: 75px;">N° Facture</th>
                        <th style="width: 45px;" class="text-center">Heure</th>
                        <?php if ($shop_filter === 0): ?>
                            <th style="width: 100px;">Dépôt</th>
                        <?php endif; ?>
                        <th style="width: 55px;" class="text-center">Type</th>
                        <th style="width: 90px;">Opérateur</th>
                        <th style="width: 130px;">Tiers (Client / Fourn.)</th>
                        <th style="width: 65px;" class="text-center">Règlement</th>
                        <th>Articles (Qté & Prix)</th>
                        <th style="width: 90px;" class="text-end">Montant Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grp['items'] as $ord): 
                        $ref = $ord['reference'] ?? ('FAC-' . sprintf("%05d", $ord['id']));
                        $items = $order_items_map[$ord['id']] ?? [];
                    ?>
                        <tr>
                            <td class="fw-bold">
                                <?= htmlspecialchars($ref) ?>
                                <?php if ($ord['status'] === 'PENDING_APPROVAL'): ?>
                                    <span class="badge-print bg-warning text-dark">Attente</span>
                                <?php elseif ($ord['status'] === 'REJECTED'): ?>
                                    <span class="badge-print bg-danger text-white">Rejeté</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-muted"><?= date('H:i', strtotime($ord['created_at'])) ?></td>
                            <?php if ($shop_filter === 0): ?>
                                <td class="small"><?= htmlspecialchars($ord['shop_name']) ?></td>
                            <?php endif; ?>
                            <td class="text-center">
                                <?php if ($ord['type'] === 'SALE'): ?>
                                    <span class="badge-print bg-success text-white">Vente</span>
                                <?php elseif ($ord['type'] === 'PURCHASE'): ?>
                                    <span class="badge-print bg-primary text-white">Achat</span>
                                <?php else: ?>
                                    <span class="badge-print bg-info text-white">Prod</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($ord['user_name'] ?? 'Inconnu') ?></td>
                            <td class="fw-semibold">
                                <?php 
                                if ($ord['type'] === 'SALE') {
                                    echo htmlspecialchars($ord['client_name'] ?? 'Client divers');
                                } elseif ($ord['type'] === 'PURCHASE') {
                                    echo htmlspecialchars($ord['supplier_name'] ?? 'Fournisseur inconnu');
                                } else {
                                    echo "Mandel Usine";
                                }
                                ?>
                                <?php if (!empty($ord['discount_reason'])): ?>
                                    <div class="text-danger small" style="font-size: 7pt;">Hors-marge: <?= htmlspecialchars($ord['discount_reason']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center small">
                                <?php 
                                $pm_short = [
                                    'CASH' => 'Espèces',
                                    'MOBILE_MONEY' => 'Momo',
                                    'CARD' => 'Carte',
                                    'CREDIT' => 'Crédit'
                                ];
                                echo $pm_short[$ord['payment_method']] ?? $ord['payment_method'];
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($items)): ?>
                                    <div>
                                        <?php foreach ($items as $it): ?>
                                            <span class="article-tag">
                                                <strong><?= htmlspecialchars($it['product_name']) ?></strong> 
                                                <span class="text-primary fw-bold">×<?= $it['quantity'] ?></span> 
                                                <span class="text-muted">(<?= number_format($it['unit_price'], 0, '.', ' ') ?> F)</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small fst-italic">Aucun article</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold">
                                <?= number_format($ord['total_amount'], 0, '.', ' ') ?> F
                                <?php if ($ord['payment_method'] === 'CREDIT' && $ord['total_amount'] > $ord['amount_paid']): ?>
                                    <div class="text-danger small fw-normal" style="font-size: 7pt;">
                                        Reste: <?= number_format($ord['total_amount'] - $ord['amount_paid'], 0, '.', ' ') ?> F
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <!-- Totalisation Générale en fin de document -->
        <div class="row mt-4 pt-2 border-top">
            <div class="col-8">
                <div class="small text-secondary">
                    <em>Document officiel généré par le système Mandel Papeterie ERP. Fait foi pour l'audit et le contrôle des flux de caisse et de stocks.</em>
                </div>
                <div class="row mt-4 pt-3">
                    <div class="col-6">
                        <strong>Visa Responsable Magasin :</strong>
                        <div style="border-bottom: 1px dotted #94a3b8; height: 35px; width: 80%;"></div>
                    </div>
                    <div class="col-6">
                        <strong>Visa Direction Générale :</strong>
                        <div style="border-bottom: 1px dotted #94a3b8; height: 35px; width: 80%;"></div>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <table class="table table-sm table-bordered">
                    <tr class="table-light">
                        <th>Total Ventes :</th>
                        <td class="text-end fw-bold text-success"><?= number_format($total_sales_amt, 0, '.', ' ') ?> FCFA</td>
                    </tr>
                    <?php if ($user_role === 'admin'): ?>
                    <tr class="table-light">
                        <th>Total Achats :</th>
                        <td class="text-end fw-bold text-primary"><?= number_format($total_purchases_amt, 0, '.', ' ') ?> FCFA</td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-light">
                        <th>Encaissé Net :</th>
                        <td class="text-end fw-bold text-dark"><?= number_format($total_encaisse, 0, '.', ' ') ?> FCFA</td>
                    </tr>
                    <?php if ($total_credit_restant > 0): ?>
                    <tr class="table-light">
                        <th>Crédits en attente :</th>
                        <td class="text-end fw-bold text-danger"><?= number_format($total_credit_restant, 0, '.', ' ') ?> FCFA</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
window.addEventListener('load', function() {
    // Petit délai pour permettre le rendu net des polices avant l'impression
    setTimeout(function() {
        window.print();
    }, 600);
});
</script>

</body>
</html>
