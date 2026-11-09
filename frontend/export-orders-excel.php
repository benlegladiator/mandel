<?php
require_once __DIR__ . '/../backend/config/db.php';
require_once __DIR__ . '/includes/xlsx_helper.php';

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

// Nom de la boutique pour le nom du fichier
$shop_name = "Tous les Dépôts";
if ($shop_filter > 0) {
    $st = $pdo->prepare("SELECT name FROM shops WHERE id = ?");
    $st->execute([$shop_filter]);
    $shop_name = $st->fetchColumn() ?: "Boutique #$shop_filter";
}

// Construction de la requête sans LIMIT ni OFFSET
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
    // Traiter par blocs de 1000 si besoin
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

// En-têtes Excel
$headers = [
    'Date',
    'Heure',
    'N° Facture',
    'Dépôt / Magasin',
    'Type Mouvement',
    'Statut',
    'Opérateur',
    'Tiers (Client / Fournisseur)',
    'Mode Règlement',
    'Articles & Quantités',
    'Total Facture (FCFA)',
    'Montant Encaissé (FCFA)',
    'Reste à Payer (FCFA)',
    'Remarques / Motif'
];

$rows = [];
$sum_total = 0;
$sum_encaisse = 0;
$sum_reste = 0;

foreach ($orders as $ord) {
    $dt = strtotime($ord['created_at']);
    $date_str = date('d/m/Y', $dt);
    $time_str = date('H:i', $dt);
    $ref = $ord['reference'] ?? ('FAC-' . sprintf("%05d", $ord['id']));

    // Type
    $type_str = 'Vente';
    if ($ord['type'] === 'PURCHASE') {
        $type_str = 'Achat';
    } elseif ($ord['type'] === 'PRODUCTION') {
        $type_str = 'Production';
    }

    // Statut
    $status_str = 'Validé';
    if ($ord['status'] === 'PENDING_APPROVAL') {
        $status_str = 'En attente';
    } elseif ($ord['status'] === 'REJECTED') {
        $status_str = 'Rejeté';
    }

    // Contact
    $contact = 'Client divers';
    if ($ord['type'] === 'PURCHASE') {
        $contact = $ord['supplier_name'] ?? 'Fournisseur inconnu';
    } elseif ($ord['type'] === 'SALE') {
        $contact = $ord['client_name'] ?? 'Client divers';
    } else {
        $contact = 'Mandel Usine';
    }

    // Mode paiement
    $pm_labels = [
        'CASH' => 'Espèces',
        'MOBILE_MONEY' => 'Mobile Money',
        'CARD' => 'Carte Bancaire',
        'CREDIT' => 'Crédit / Dette'
    ];
    $pm_str = $pm_labels[$ord['payment_method']] ?? $ord['payment_method'];

    // Articles résumé
    $articles_parts = [];
    if (!empty($order_items_map[$ord['id']])) {
        foreach ($order_items_map[$ord['id']] as $it) {
            $articles_parts[] = $it['product_name'] . ' (x' . $it['quantity'] . ' @ ' . number_format($it['unit_price'], 0, '.', ' ') . ' F)';
        }
    }
    $articles_str = !empty($articles_parts) ? implode('; ', $articles_parts) : '-';

    // Montants
    $total_amt = floatval($ord['total_amount'] ?? 0);
    $paid_amt = 0;
    if ($ord['payment_method'] === 'CREDIT') {
        $paid_amt = floatval($ord['amount_paid'] ?? 0);
    } else {
        $paid_amt = $total_amt;
    }
    $due_amt = max(0, $total_amt - $paid_amt);

    if ($ord['status'] === 'COMPLETED') {
        $sum_total += $total_amt;
        $sum_encaisse += $paid_amt;
        $sum_reste += $due_amt;
    }

    $notes = $ord['notes'] ?? '';
    if (!empty($ord['discount_reason'])) {
        $notes = ($notes ? $notes . ' | ' : '') . 'Hors-Marge: ' . $ord['discount_reason'];
    }

    $rows[] = [
        $date_str,
        $time_str,
        $ref,
        $ord['shop_name'] ?? '',
        $type_str,
        $status_str,
        $ord['user_name'] ?? 'Inconnu',
        $contact,
        $pm_str,
        $articles_str,
        $total_amt,
        $paid_amt,
        $due_amt,
        $notes
    ];
}

// Ligne de totalisation en fin de tableau
if (!empty($rows)) {
    $rows[] = [
        'TOTAL GÉNÉRAL',
        '',
        count($orders) . ' factures',
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        $sum_total,
        $sum_encaisse,
        $sum_reste,
        'Totaux des factures validées'
    ];
}

$safe_shop = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $shop_name);
$filename = 'Journal_Flux_' . $safe_shop . '_' . date('Ymd_His') . '.xlsx';

MandelExcelExporter::export($filename, 'Journal Flux', $headers, $rows);
