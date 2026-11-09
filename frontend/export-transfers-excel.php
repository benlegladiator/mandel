<?php
require_once __DIR__ . '/../backend/config/db.php';
require_once __DIR__ . '/includes/xlsx_helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$filter_shop = isset($_GET['filter_shop']) ? intval($_GET['filter_shop']) : (($_SESSION['user_role'] === 'admin') ? 0 : intval($_SESSION['active_shop_id']));
$filter_status = trim($_GET['filter_status'] ?? '');
$search_term = trim($_GET['search_term'] ?? '');

$where_trans = "WHERE 1=1";
$params_trans = [];

if ($_SESSION['user_role'] !== 'admin') {
    $where_trans .= " AND (t.from_shop_id = :sid OR t.to_shop_id = :sid)";
    $params_trans[':sid'] = $_SESSION['active_shop_id'];
} elseif ($filter_shop > 0) {
    $where_trans .= " AND (t.from_shop_id = :filter_sid OR t.to_shop_id = :filter_sid)";
    $params_trans[':filter_sid'] = $filter_shop;
}

if (!empty($filter_status)) {
    $where_trans .= " AND t.status = :filter_status";
    $params_trans[':filter_status'] = $filter_status;
}

if (!empty($search_term)) {
    $where_trans .= " AND (t.transfer_code LIKE :sterm OR t.notes LIKE :sterm OR p.name LIKE :sterm OR p.sku LIKE :sterm)";
    $params_trans[':sterm'] = '%' . $search_term . '%';
}

$history_sql = "
    SELECT 
        COALESCE(t.transfer_code, CONCAT('TR-', LPAD(t.id, 5, '0'))) as group_code,
        MIN(t.id) as min_id,
        MIN(t.created_at) as created_at,
        MAX(fs.name) as from_shop_name,
        MAX(ts.name) as to_shop_name,
        MAX(u.name) as operator_name,
        MAX(rcv.name) as receiver_name,
        MAX(t.status) as status,
        MAX(t.notes) as notes,
        COUNT(t.id) as total_items_count,
        SUM(t.quantity) as total_units_sum,
        GROUP_CONCAT(CONCAT(p.name, ' (x', t.quantity, ')') SEPARATOR ', ') as items_summary
    FROM stock_transfers t
    JOIN products p ON t.product_id = p.id
    JOIN shops fs ON t.from_shop_id = fs.id
    JOIN shops ts ON t.to_shop_id = ts.id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN users rcv ON t.received_by = rcv.id
    $where_trans
    GROUP BY COALESCE(t.transfer_code, CONCAT('TR-', LPAD(t.id, 5, '0')))
    ORDER BY MIN(t.created_at) DESC
";

$stmt = $pdo->prepare($history_sql);
$stmt->execute($params_trans);
$transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$headers = [
    'Réf Bon',
    'Date & Heure',
    'Magasin Source (Départ)',
    'Magasin Destination (Arrivée)',
    'Nb Articles Différents',
    'Total Quantité Expédiée',
    'Détail des Articles Transférés',
    'Statut',
    'Émis par',
    'Réceptionné par',
    'Remarques / Motif'
];

$rows = [];
foreach ($transfers as $tr) {
    $st_label = 'En transit';
    if ($tr['status'] === 'RECEIVED') {
        $st_label = 'Reçu (Validé)';
    } elseif ($tr['status'] === 'CANCELLED') {
        $st_label = 'Annulé';
    }

    $rows[] = [
        $tr['group_code'],
        date('d/m/Y H:i', strtotime($tr['created_at'])),
        $tr['from_shop_name'],
        $tr['to_shop_name'],
        intval($tr['total_items_count']),
        intval($tr['total_units_sum']),
        $tr['items_summary'],
        $st_label,
        $tr['operator_name'] ?? '-',
        $tr['receiver_name'] ?? '-',
        $tr['notes'] ?: '-'
    ];
}

$filename = "journal_transferts_" . date('Y-m-d_Hi') . ".xlsx";
MandelExcelExporter::export($filename, "Transferts", $headers, $rows);
