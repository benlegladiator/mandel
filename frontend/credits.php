<?php
require_once __DIR__ . '/includes/header.php';

// Restriction de sécurité : Tout utilisateur connecté a accès
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$error_msg = '';
$success_msg = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'clients';
if ($_SESSION['user_role'] === 'seller') {
    $active_tab = 'clients'; // Sécurité : Les vendeurs n'ont accès qu'aux créances clients
}

// --- TRAITEMENT DU POST : ENREGISTRER UN RÈGLEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $order_id = intval($_POST['order_id']);
    $amount_to_pay = floatval($_POST['amount_to_pay']);
    $pay_method = trim($_POST['pay_method'] ?? 'CASH');
    $payment_date = isset($_POST['payment_date']) && !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');

    if ($order_id <= 0) {
        $error_msg = "Facture invalide.";
    } elseif ($amount_to_pay <= 0) {
        $error_msg = "Le montant du versement doit être supérieur à 0.";
    } else {
        try {
            $pdo->beginTransaction();

            // Récupérer les informations de la commande
            $stmt = $pdo->prepare("SELECT type, total_amount, amount_paid, payment_status, notes FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new Exception("Facture introuvable.");
            }

            $total = floatval($order['total_amount']);
            $current_paid = floatval($order['amount_paid']);
            $remaining = $total - $current_paid;

            if ($amount_to_pay > $remaining) {
                // On accepte le paiement mais on plafonne au reste à payer
                $amount_to_pay = $remaining;
            }

            $new_paid = $current_paid + $amount_to_pay;
            $new_status = 'UNPAID';
            if ($new_paid >= $total) {
                $new_paid = $total;
                $new_status = 'PAID';
            } elseif ($new_paid > 0) {
                $new_status = 'PARTIAL';
            }

            // Mettre à jour la commande
            $stmt_update = $pdo->prepare("UPDATE orders SET amount_paid = ?, payment_status = ? WHERE id = ?");
            $stmt_update->execute([$new_paid, $new_status, $order_id]);

            // Logger le versement dans l'historique des notes
            $log_entry = "\n[" . date('d/m/Y', strtotime($payment_date)) . "] Règlement de " . number_format($amount_to_pay, 0, '.', ' ') . " FCFA en " . $pay_method . ".";
            $stmt_notes = $pdo->prepare("UPDATE orders SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?");
            $stmt_notes->execute([$log_entry, $order_id]);

            // Logger dans la table cash_transactions si réglé en espèces (CASH)
            if ($pay_method === 'CASH') {
                $stmt_o_shop = $pdo->prepare("
                    SELECT o.shop_id, o.reference, c.name as client_name, s.name as supplier_name 
                    FROM orders o 
                    LEFT JOIN clients c ON o.client_id = c.id 
                    LEFT JOIN suppliers s ON o.supplier_id = s.id 
                    WHERE o.id = ?
                ");
                $stmt_o_shop->execute([$order_id]);
                $order_data = $stmt_o_shop->fetch();
                $order_shop_id = $order_data['shop_id'] ?? null;
                $order_ref = $order_data['reference'] ?? ('FAC-' . sprintf("%05d", $order_id));
                $client_name = trim($order_data['client_name'] ?? '');
                $supplier_name = trim($order_data['supplier_name'] ?? '');
                
                // Destination de la caisse : 
                // Pour une vente (créance client) : TOUJOURS dans la caisse de la boutique d'origine de la commande ($order_shop_id)
                // Pour un achat (dette fournisseur) : Caisse centrale / Boss (NULL) si réglé par admin, sinon boutique commande
                if ($order['type'] === 'SALE') {
                    $target_shop_id = $order_shop_id ? intval($order_shop_id) : (!empty($_SESSION['active_shop_id']) ? intval($_SESSION['active_shop_id']) : null);
                    $cash_ref_type = 'ORDER';
                    $description = "Règlement créance client " . ($client_name ? "[" . $client_name . "] " : "") . "en espèces (" . $order_ref . ")";
                } else {
                    $target_shop_id = ($_SESSION['user_role'] === 'admin') ? null : ($order_shop_id ? intval($order_shop_id) : null);
                    $cash_ref_type = 'PURCHASE';
                    $description = "Règlement dette fournisseur " . ($supplier_name ? "[" . $supplier_name . "] " : "") . "en espèces (" . $order_ref . ")";
                }
                
                $cash_type = ($order['type'] === 'SALE') ? 'IN' : 'OUT';

                $stmt_cash = $pdo->prepare("
                    INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'VALIDATED', ?)
                ");
                $stmt_cash->execute([
                    $target_shop_id,
                    $_SESSION['user_id'],
                    $cash_type,
                    $amount_to_pay,
                    $description,
                    $order_id,
                    $cash_ref_type,
                    $payment_date . ' ' . date('H:i:s')
                ]);
            }

            $pdo->commit();
            $success_msg = "Le règlement de " . number_format($amount_to_pay, 0, '.', ' ') . " FCFA a été enregistré avec succès.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = "Erreur lors de l'enregistrement du règlement : " . $e->getMessage();
        }
    }
}

// --- TRAITEMENT DU POST : SAISIE D'UNE CRÉANCE OU DETTE ANTÉRIEURE (AVANT DIGITALISATION) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_historical_debt'])) {
    $debt_type = trim($_POST['debt_type'] ?? 'SALE'); // SALE = créance client, PURCHASE = dette fournisseur
    $shop_id = ($_SESSION['user_role'] === 'admin') ? intval($_POST['shop_id'] ?? $filter_shop_id) : $_SESSION['active_shop_id'];
    if ($shop_id <= 0) $shop_id = $_SESSION['active_shop_id'] ?: 1;

    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $created_at = !empty($_POST['created_at']) ? $_POST['created_at'] : date('Y-m-d');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $notes = trim($_POST['notes'] ?? '');

    $client_id = isset($_POST['client_id']) && !empty($_POST['client_id']) ? intval($_POST['client_id']) : null;
    $supplier_id = isset($_POST['supplier_id']) && !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;

    $new_client_name = trim($_POST['new_client_name'] ?? '');
    $new_client_phone = trim($_POST['new_client_phone'] ?? '');

    $new_supplier_name = trim($_POST['new_supplier_name'] ?? '');
    $new_supplier_phone = trim($_POST['new_supplier_phone'] ?? '');

    if ($total_amount <= 0) {
        $error_msg = "Le montant du solde antérieur doit être supérieur à 0.";
    } else {
        try {
            $pdo->beginTransaction();

            if ($debt_type === 'SALE') {
                if (!empty($new_client_name)) {
                    $stmt_c = $pdo->prepare("INSERT INTO clients (name, phone) VALUES (?, ?)");
                    $stmt_c->execute([$new_client_name, $new_client_phone]);
                    $client_id = $pdo->lastInsertId();
                }
                if (!$client_id || $client_id <= 0) {
                    throw new Exception("Veuillez sélectionner un client existant ou en créer un nouveau.");
                }
            } else {
                if (!empty($new_supplier_name)) {
                    $stmt_s = $pdo->prepare("INSERT INTO suppliers (name, phone) VALUES (?, ?)");
                    $stmt_s->execute([$new_supplier_name, $new_supplier_phone]);
                    $supplier_id = $pdo->lastInsertId();
                }
                if (!$supplier_id || $supplier_id <= 0) {
                    throw new Exception("Veuillez sélectionner un fournisseur existant ou en créer un nouveau.");
                }
            }

            $payment_status = 'UNPAID';
            if ($amount_paid >= $total_amount) {
                $amount_paid = $total_amount;
                $payment_status = 'PAID';
            } elseif ($amount_paid > 0) {
                $payment_status = 'PARTIAL';
            }

            $prefix_note = "[SOLDE ANTÉRIEUR AVANT DIGITALISATION] ";
            $full_notes = $prefix_note . $notes;

            // Auto-vérification et ajout sécurisé de la colonne is_historical
            $has_is_historical = false;
            try {
                $checkIsHist = $pdo->query("SHOW COLUMNS FROM orders LIKE 'is_historical'");
                if ($checkIsHist && $checkIsHist->fetch()) {
                    $has_is_historical = true;
                } else {
                    $pdo->exec("ALTER TABLE orders ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_reason");
                    $has_is_historical = true;
                }
            } catch (Exception $ex) {
                // Si l'exécution échoue en raison de droits restreints
                $checkIsHist = $pdo->query("SHOW COLUMNS FROM orders LIKE 'is_historical'");
                $has_is_historical = ($checkIsHist && $checkIsHist->fetch()) ? true : false;
            }

            if ($has_is_historical) {
                $stmt_ord = $pdo->prepare("
                    INSERT INTO orders (
                        type, shop_id, client_id, supplier_id, user_id,
                        total_amount, amount_paid, payment_method, notes,
                        status, due_date, payment_status, is_historical, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'CREDIT', ?, 'COMPLETED', ?, ?, 1, ?)
                ");
                $stmt_ord->execute([
                    $debt_type,
                    $shop_id,
                    ($debt_type === 'SALE') ? $client_id : null,
                    ($debt_type === 'PURCHASE') ? $supplier_id : null,
                    $_SESSION['user_id'],
                    $total_amount,
                    $amount_paid,
                    $full_notes,
                    $due_date,
                    $payment_status,
                    $created_at . ' ' . date('H:i:s')
                ]);
            } else {
                $stmt_ord = $pdo->prepare("
                    INSERT INTO orders (
                        type, shop_id, client_id, supplier_id, user_id,
                        total_amount, amount_paid, payment_method, notes,
                        status, due_date, payment_status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'CREDIT', ?, 'COMPLETED', ?, ?, ?)
                ");
                $stmt_ord->execute([
                    $debt_type,
                    $shop_id,
                    ($debt_type === 'SALE') ? $client_id : null,
                    ($debt_type === 'PURCHASE') ? $supplier_id : null,
                    $_SESSION['user_id'],
                    $total_amount,
                    $amount_paid,
                    $full_notes,
                    $due_date,
                    $payment_status,
                    $created_at . ' ' . date('H:i:s')
                ]);
            }

            $pdo->commit();
            $success_msg = "Le solde antérieur de " . number_format($total_amount, 0, '.', ' ') . " FCFA a été enregistré avec succès (Stock inchangé : 0 mouvement).";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Erreur de traitement : " . $e->getMessage();
        }
    }
}


// --- FILTRES ---
if ($_SESSION['user_role'] === 'seller') {
    $filter_shop_id = $_SESSION['active_shop_id'];
} else {
    $filter_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : 0;
}
$filter_status = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : 'UNRESOLVED'; // UNRESOLVED par défaut (en cours)
$search_client = isset($_GET['search']) ? trim($_GET['search']) : '';

// --- CHARGER LES LISTES ---
$shops = $pdo->query("SELECT id, name FROM shops ORDER BY name ASC")->fetchAll();
$all_clients = $pdo->query("SELECT id, name, phone FROM clients ORDER BY name ASC")->fetchAll();
$all_suppliers = $pdo->query("SELECT id, name, phone FROM suppliers ORDER BY name ASC")->fetchAll();


// --- REQUÊTE PRINCIPALE DES CRÉANCES ---
$query_parts = [];
$params = [];
$order_type = ($active_tab === 'clients') ? 'SALE' : 'PURCHASE';

if ($active_tab === 'clients') {
    $sql_credits = "
        SELECT o.*, c.name as client_name, c.phone as client_phone, sh.name as shop_name, u.name as user_name
        FROM orders o
        LEFT JOIN shops sh ON o.shop_id = sh.id
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.type = 'SALE' AND o.payment_method = 'CREDIT' AND o.status = 'COMPLETED'
    ";
} else {
    $sql_credits = "
        SELECT o.*, s.name as client_name, s.phone as client_phone, sh.name as shop_name, u.name as user_name
        FROM orders o
        LEFT JOIN shops sh ON o.shop_id = sh.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.type = 'PURCHASE' AND o.payment_method = 'CREDIT' AND o.status = 'COMPLETED'
    ";
}

if ($filter_shop_id > 0) {
    $sql_credits .= " AND o.shop_id = :shop_id";
    $params[':shop_id'] = $filter_shop_id;
}

if ($filter_status === 'UNRESOLVED') {
    $sql_credits .= " AND o.payment_status IN ('UNPAID', 'PARTIAL')";
} elseif (!empty($filter_status) && $filter_status !== 'ALL') {
    $sql_credits .= " AND o.payment_status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($search_client)) {
    if ($active_tab === 'clients') {
        $sql_credits .= " AND (c.name LIKE :search OR c.phone LIKE :search OR o.id LIKE :search)";
    } else {
        $sql_credits .= " AND (s.name LIKE :search OR s.phone LIKE :search OR o.id LIKE :search)";
    }
    $params[':search'] = '%' . $search_client . '%';
}

$sql_credits .= " ORDER BY o.due_date ASC, o.created_at DESC";

// --- PAGINATION SQL NATIVE PAR DOSSIER CLIENT / CONTACT (Zéro saturation RAM) ---
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 30;

// Construction de la clause WHERE commune
$where_conds = "WHERE o.type = " . ($active_tab === 'clients' ? "'SALE'" : "'PURCHASE'") . " AND o.payment_method = 'CREDIT' AND o.status = 'COMPLETED'";
$contact_table = ($active_tab === 'clients') ? 'c' : 's';
$contact_join = ($active_tab === 'clients') 
    ? "LEFT JOIN clients c ON o.client_id = c.id" 
    : "LEFT JOIN suppliers s ON o.supplier_id = s.id";
$contact_id_col = ($active_tab === 'clients') ? 'o.client_id' : 'o.supplier_id';

if ($filter_shop_id > 0) {
    $where_conds .= " AND o.shop_id = :shop_id";
}
if ($filter_status === 'UNRESOLVED') {
    $where_conds .= " AND o.payment_status IN ('UNPAID', 'PARTIAL')";
} elseif (!empty($filter_status) && $filter_status !== 'ALL') {
    $where_conds .= " AND o.payment_status = :status";
}
if (!empty($search_client)) {
    $where_conds .= " AND ({$contact_table}.name LIKE :search OR {$contact_table}.phone LIKE :search OR o.id LIKE :search)";
}

// 1. Compter les dossiers de contacts débiteurs uniques
$cnt_sql = "SELECT COUNT(DISTINCT COALESCE($contact_id_col, o.id)) FROM orders o $contact_join $where_conds";
$stmt_cnt = $pdo->prepare($cnt_sql);
$stmt_cnt->execute($params);
$total_records = intval($stmt_cnt->fetchColumn() ?: 0);

$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

// 2. Extraire les contacts de la page courante via LIMIT / OFFSET
$target_sql = "
    SELECT DISTINCT COALESCE($contact_id_col, 0) as cid, COALESCE({$contact_table}.name, CONCAT('" . ($active_tab === 'clients' ? 'Vente #' : 'Achat #') . "', o.id)) as cname
    FROM orders o 
    $contact_join 
    $where_conds
    ORDER BY cname ASC
    LIMIT $per_page OFFSET $offset
";
$stmt_target = $pdo->prepare($target_sql);
$stmt_target->execute($params);
$page_contacts = $stmt_target->fetchAll(PDO::FETCH_ASSOC);

// 3. Charger uniquement les créances appartenant aux contacts de cette page
$paginated_grouped_credits = [];
if (!empty($page_contacts)) {
    $sub_sql = "
        SELECT o.*, {$contact_table}.name as client_name, {$contact_table}.phone as client_phone, sh.name as shop_name, u.name as user_name
        FROM orders o
        LEFT JOIN shops sh ON o.shop_id = sh.id
        $contact_join
        LEFT JOIN users u ON o.user_id = u.id
        $where_conds
        ORDER BY o.due_date ASC, o.created_at DESC
    ";
    $stmt_sub = $pdo->prepare($sub_sql);
    $stmt_sub->execute($params);
    $all_matched = $stmt_sub->fetchAll();
    
    $target_names = array_column($page_contacts, 'cname');
    foreach ($all_matched as $h) {
        $fallback_label = ($active_tab === 'clients' ? 'Vente #' : 'Achat #') . $h['id'];
        $contact_name = !empty($h['client_name']) ? trim($h['client_name']) : $fallback_label;
        if (!in_array($contact_name, $target_names)) {
            continue; // Filtrer strictement sur les contacts de la page courante
        }
        if (!isset($paginated_grouped_credits[$contact_name])) {
            $paginated_grouped_credits[$contact_name] = [
                'client_name' => !empty($h['client_name']) ? $h['client_name'] : $fallback_label,
                'client_phone' => $h['client_phone'] ?? '',
                'total_amount' => 0.0,
                'amount_paid' => 0.0,
                'remaining' => 0.0,
                'has_overdue' => false,
                'items' => []
            ];
        }
        $due_time = strtotime($h['due_date']);
        $rem = floatval($h['total_amount']) - floatval($h['amount_paid']);
        $is_ovd = ($due_time < time()) && ($h['payment_status'] !== 'PAID');

        $paginated_grouped_credits[$contact_name]['total_amount'] += floatval($h['total_amount']);
        $paginated_grouped_credits[$contact_name]['amount_paid'] += floatval($h['amount_paid']);
        $paginated_grouped_credits[$contact_name]['remaining'] += $rem;
        if ($is_ovd) {
            $paginated_grouped_credits[$contact_name]['has_overdue'] = true;
        }
        $paginated_grouped_credits[$contact_name]['items'][] = $h;
    }
}
$credits = $paginated_grouped_credits;

// --- CALCULS DE SYNTHÈSE (CRÉANCES) ---
$sum_sql = "
    SELECT 
        SUM(total_amount) as total_debt,
        SUM(amount_paid) as total_collected,
        SUM(CASE WHEN due_date < CURDATE() AND payment_status IN ('UNPAID', 'PARTIAL') THEN (total_amount - amount_paid) ELSE 0 END) as overdue_debt
    FROM orders
    WHERE type = :order_type AND payment_method = 'CREDIT' AND status = 'COMPLETED'
";
if ($filter_shop_id > 0) {
    $sum_sql .= " AND shop_id = " . intval($filter_shop_id);
}
$stmt_sum = $pdo->prepare($sum_sql);
$stmt_sum->execute([':order_type' => $order_type]);
$summary = $stmt_sum->fetch();

$total_debt = floatval($summary['total_debt'] ?? 0);
$total_collected = floatval($summary['total_collected'] ?? 0);
$remaining_debt = $total_debt - $total_collected;
$overdue_debt = floatval($summary['overdue_debt'] ?? 0);

// Textes selon l'onglet
if ($active_tab === 'clients') {
    $page_title = "Gestion des Créances Clients";
    $page_subtitle = "Ventes à crédit & encaissements différés";
    $icon = "fa-hand-holding-dollar text-primary";
    $kpi1_label = "Total Crédits Accordés";
    $kpi2_label = "Acomptes Encaissés";
    $kpi3_label = "Reste à Recouvrer";
    $kpi4_label = "À relancer en priorité";
} else {
    $page_title = "Gestion des Comptes Fournisseurs";
    $page_subtitle = "Approvisionnements à terme";
    $icon = "fa-truck text-primary";
    $kpi1_label = "Total Achats à Crédit";
    $kpi2_label = "Montants Réglés";
    $kpi3_label = "Reste à Régler";
    $kpi4_label = "Échéances à planifier";
}
?>

<style>
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; left: 0; top: 0; width: 100%; }
    .no-print { display: none !important; }
}

.kpi-card-modern {
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
}
.kpi-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.08);
}
.kpi-icon-box {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}
.badge-historical {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    font-weight: 700;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
    box-shadow: 0 2px 5px rgba(99, 102, 241, 0.2);
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.badge-status-paid {
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
    font-weight: 700;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    border: 1px solid rgba(16, 185, 129, 0.2);
}
.badge-status-partial {
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
    font-weight: 700;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    border: 1px solid rgba(245, 158, 11, 0.2);
}
.badge-status-unpaid {
    background: rgba(239, 68, 68, 0.12);
    color: #b91c1c;
    font-weight: 700;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    border: 1px solid rgba(239, 68, 68, 0.2);
}
</style>

<!-- Titre principal -->
<div class="row mb-3 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-900 mb-0 fs-4 d-flex align-items-center gap-2">
            <span class="p-2 rounded-3 bg-white shadow-sm border"><i class="fa-solid <?= $icon ?>"></i></span> 
            <?= $page_title ?>
        </h1>
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small fw-semibold d-none d-md-inline"><?= $page_subtitle ?></span>
            <button class="btn btn-warning text-dark btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#historicalDebtModal" style="border-radius: 8px; padding: 0.4rem 0.9rem;">
                <i class="fa-solid fa-clock-rotate-left me-1"></i>Saisir Solde Antérieur
            </button>
            <button type="button" onclick="exportTableToExcel('creditsTable', '<?= $active_tab === 'clients' ? 'creances_clients.csv' : 'dettes_fournisseurs.csv' ?>')" class="btn btn-outline-success btn-sm fw-semibold" style="border-radius: 8px; padding: 0.4rem 0.9rem;">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel
            </button>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; padding: 0.4rem 0.9rem;">
                <i class="fa-solid fa-print me-1"></i>Imprimer
            </button>
        </div>

    </div>
</div>

<!-- Navigation par Onglets Principaux -->
<ul class="nav nav-pills mb-4 no-print gap-2" role="tablist">
    <li class="nav-item">
        <a class="nav-link px-4 py-2 fw-bold shadow-sm <?= $active_tab === 'clients' ? 'active bg-warning text-dark' : 'bg-white text-secondary border' ?>" href="credits.php?tab=clients" style="border-radius: 10px;">
            <i class="fa-solid fa-hand-holding-dollar me-1.5"></i>Créances Clients
        </a>
    </li>
    <?php if ($_SESSION['user_role'] === 'admin'): ?>
    <li class="nav-item">
        <a class="nav-link px-4 py-2 fw-bold shadow-sm <?= $active_tab === 'suppliers' ? 'active bg-danger text-white' : 'bg-white text-secondary border' ?>" href="credits.php?tab=suppliers" style="border-radius: 10px;">
            <i class="fa-solid fa-file-invoice-dollar me-1.5"></i>Dettes Fournisseurs
        </a>
    </li>
    <?php endif; ?>
</ul>


<div id="printArea">

<!-- Alertes -->
<?php if ($success_msg): ?>
    <div class="alert alert-success border-0 shadow-sm" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px;">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error_msg ?>
    </div>
<?php endif; ?>

<!-- Synthèse des Créances (KPIs) -->
<div class="row g-4 mb-5">
    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="stat-label"><?= $kpi1_label ?></span>
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3"><i class="fa-solid fa-file-invoice-dollar fa-lg"></i></div>
            </div>
            <div class="stat-value text-slate-900"><?= number_format($total_debt, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
        </div>
    </div>
    
    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="stat-label"><?= $kpi2_label ?></span>
                <div class="bg-success bg-opacity-10 text-success p-2 rounded-3"><i class="fa-solid fa-money-bill-trend-up fa-lg"></i></div>
            </div>
            <div class="stat-value text-success"><?= number_format($total_collected, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="stat-label"><?= $kpi3_label ?></span>
                <div class="bg-warning bg-opacity-10 text-warning p-2 rounded-3"><i class="fa-solid fa-comments-dollar fa-lg"></i></div>
            </div>
            <div class="stat-value text-warning"><?= number_format($remaining_debt, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100 <?= $overdue_debt > 0 ? 'bg-warning-subtle bg-opacity-25 border-warning-subtle' : '' ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="stat-label"><?= $kpi4_label ?></span>
                <div class="bg-warning bg-opacity-15 text-dark p-2 rounded-3"><i class="fa-solid fa-clock-rotate-left fa-lg"></i></div>
            </div>
            <div class="stat-value text-slate-800"><?= number_format($overdue_debt, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
        </div>
    </div>
</div>

<!-- Filtres de recherche -->
<div class="glass-card p-4 mb-4 no-print">
    <form method="GET" action="credits.php" class="row g-3 align-items-end">
        <input type="hidden" name="tab" value="<?= $active_tab ?>">
        <div class="col-12 col-md-3">
            <label for="shop_id" class="form-label small fw-bold text-secondary">Filtrer par Dépôt</label>
            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <select name="shop_id" id="shop_id" class="form-select form-control-custom">
                    <option value="0">Toutes les boutiques</option>
                    <?php foreach ($shops as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $filter_shop_id == $sh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" class="form-control form-control-custom" value="<?= htmlspecialchars($current_shop['name'] ?? '') ?>" readonly>
                <input type="hidden" name="shop_id" value="<?= $_SESSION['active_shop_id'] ?>">
            <?php endif; ?>
        </div>

        <div class="col-12 col-md-3">
            <label for="payment_status" class="form-label small fw-bold text-secondary">Statut de Règlement</label>
            <select name="payment_status" id="payment_status" class="form-select form-control-custom">
                <option value="UNRESOLVED" <?= $filter_status == 'UNRESOLVED' ? 'selected' : '' ?>>Non réglés (En cours)</option>
                <option value="UNPAID" <?= $filter_status == 'UNPAID' ? 'selected' : '' ?>>Non payés (0%)</option>
                <option value="PARTIAL" <?= $filter_status == 'PARTIAL' ? 'selected' : '' ?>>Payés partiellement</option>
                <option value="PAID" <?= $filter_status == 'PAID' ? 'selected' : '' ?>>Soldés (100%)</option>
                <option value="ALL" <?= $filter_status == 'ALL' ? 'selected' : '' ?>>Toutes les factures</option>
            </select>
        </div>

        <div class="col-12 col-md-4">
            <label for="search" class="form-label small fw-bold text-secondary">Rechercher un Client / Facture</label>
            <input type="text" name="search" id="search" class="form-control form-control-custom" placeholder="Nom, Téléphone ou N° Facture..." value="<?= htmlspecialchars($search_client) ?>">
        </div>

        <div class="col-12 col-md-2 d-grid">
            <button type="submit" class="btn btn-grad py-2"><i class="fa-solid fa-filter me-1"></i>Filtrer</button>
        </div>
    </form>
</div>

<!-- Liste des créances -->
<div class="glass-card p-4">
    <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-list-check text-primary me-2"></i>Liste des factures</h5>

    <?php if (empty($paginated_grouped_credits)): ?>
        <div class="text-center py-5 text-secondary">
            <i class="fa-solid fa-circle-check fa-3x mb-3 text-success"></i>
            <p class="mb-0 fw-bold">Aucune facture à crédit ne correspond aux filtres.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-custom align-middle" id="creditsTable">
                <thead>

                    <tr>
                        <th>N° Facture / Contact</th>
                        <th>Contact / Dépôt</th>
                        <th>Dépôt</th>
                        <th>Échéance</th>
                        <th class="text-end">Total Facturé</th>
                        <th class="text-end">Déjà Payé</th>
                        <th class="text-end">Reste à Payer</th>
                        <th class="text-center">Statut</th>
                        <th class="text-end no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $group_index = 0;
                    foreach ($paginated_grouped_credits as $contact_key => $group): 
                        $group_index++;
                        $items = $group['items'];
                        $item_count = count($items);
                    ?>
                        <?php if ($item_count === 1): ?>
                            <!-- CAS 1 : UNE SEULE CRÉANCE POUR CE CLIENT -->
                            <?php 
                                $h = $items[0];
                                $due = strtotime($h['due_date']);
                                $is_overdue = ($due < time()) && ($h['payment_status'] !== 'PAID');
                                $remaining = floatval($h['total_amount']) - floatval($h['amount_paid']);
                                
                                $status_badge = '';
                                if ($h['payment_status'] === 'PAID') {
                                    $status_badge = '<span class="badge-status-paid"><i class="fa-solid fa-check-double me-1"></i>Soldé</span>';
                                } elseif ($h['payment_status'] === 'PARTIAL') {
                                    $status_badge = '<span class="badge-status-partial"><i class="fa-solid fa-clock me-1"></i>Partiel</span>';
                                } else {
                                    $status_badge = '<span class="badge-status-unpaid"><i class="fa-solid fa-hourglass-half me-1"></i>Non réglé</span>';
                                }
                            ?>
                            <tr class="<?= $is_overdue ? 'table-danger bg-danger bg-opacity-10' : '' ?>">
                                <td class="fw-bold">
                                    <a href="invoice.php?id=<?= $h['id'] ?>" class="text-decoration-none text-primary fw-extrabold"><?= ($active_tab === 'clients' ? 'FAC-' : 'ACH-') ?><?= htmlspecialchars($h['reference'] ?? 'FAC-'.sprintf("%05d", $h['id'])) ?></a>
                                    <?php if (!empty($h['is_historical'])): ?>
                                        <br><span class="badge-historical mt-1" title="Solde enregistré avant la digitalisation (Stock inchangé)"><i class="fa-solid fa-clock-rotate-left"></i>Solde Antérieur</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-800"><?= htmlspecialchars($h['client_name'] ?? 'Inconnu') ?></div>
                                    <?php if (!empty($h['client_phone'])): ?>
                                        <small class="text-secondary"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($h['client_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-secondary"><?= htmlspecialchars($h['shop_name'] ?? '-') ?></td>
                                <td>
                                    <div class="small fw-semibold <?= $is_overdue ? 'text-danger' : 'text-secondary' ?>">
                                        <?= date('d/m/Y', $due) ?>
                                    </div>
                                    <?php if ($is_overdue): ?>
                                        <span class="badge bg-danger py-0.5 px-1.5 fw-bold" style="font-size: 0.65rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i>En Retard</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-semibold"><?= number_format($h['total_amount'], 0, '.', ' ') ?> FCFA</td>
                                <td class="text-end text-success"><?= number_format($h['amount_paid'], 0, '.', ' ') ?> FCFA</td>
                                <td class="text-end text-danger fw-bold"><?= number_format($remaining, 0, '.', ' ') ?> FCFA</td>
                                <td class="text-center"><?= $status_badge ?></td>
                                <td class="text-end no-print">
                                    <div class="d-flex justify-content-end gap-2">
                                        <?php if ($remaining > 0): ?>
                                            <button type="button" class="btn btn-sm btn-grad" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#paymentModal" 
                                                    data-order-id="<?= $h['id'] ?>" 
                                                    data-client="<?= htmlspecialchars($h['client_name'] ?? 'Inconnu') ?>"
                                                    data-remaining="<?= $remaining ?>"
                                                    data-invoice="<?= ($active_tab === 'clients' ? 'FAC-' : 'ACH-') ?><?= htmlspecialchars($h['reference'] ?? 'FAC-'.sprintf("%05d", $h['id'])) ?>">
                                                <i class="fa-solid fa-coins me-1"></i> Payer
                                            </button>
                                        <?php endif; ?>
                                        <a href="invoice.php?id=<?= $h['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Voir la facture">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>
                            <!-- CAS 2 : PLUSIEURS CRÉANCES POUR CE CLIENT (SYNTHÈSE DU TOTAL DÛ & VOLET DÉPLIABLE) -->
                            <?php 
                                $collapse_id = "group_collapse_" . $group_index;
                                $group_remaining = $group['remaining'];
                                $group_overdue = $group['has_overdue'];
                            ?>
                            <tr class="table-group-header <?= $group_overdue ? 'table-danger bg-danger bg-opacity-10 border-start border-danger border-4' : 'bg-light' ?>" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>">
                                <td colspan="2">
                                    <div class="d-flex align-items-center gap-2">
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>">
                                            <i class="fa-solid fa-chevron-down"></i>
                                        </button>
                                        <div>
                                            <span class="fw-extrabold fs-6 text-slate-900"><?= htmlspecialchars($group['client_name']) ?></span>
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle ms-2 fw-bold" style="font-size: 0.75rem;">
                                                <i class="fa-solid fa-layer-group me-1"></i><?= $item_count ?> créances cumulées
                                            </span>
                                            <?php if (!empty($group['client_phone'])): ?>
                                                <div class="small text-secondary"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($group['client_phone']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="small text-secondary">Multi-dépôts</td>
                                <td>
                                    <?php if ($group_overdue): ?>
                                        <span class="badge bg-danger py-1 px-2 fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i>Contient des retards</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary py-1 px-2">En cours</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-extrabold text-slate-800"><?= number_format($group['total_amount'], 0, '.', ' ') ?> FCFA</td>
                                <td class="text-end text-success fw-bold"><?= number_format($group['amount_paid'], 0, '.', ' ') ?> FCFA</td>
                                <td class="text-end text-danger fw-extrabold fs-6" style="background-color: rgba(239, 68, 68, 0.08);"><?= number_format($group_remaining, 0, '.', ' ') ?> FCFA</td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark px-2.5 py-1.5 fw-bold rounded-pill">
                                        <?= $group_remaining <= 0 ? 'Soldé' : ($group['amount_paid'] > 0 ? 'Partiel (Cumul)' : 'Non réglé (Cumul)') ?>
                                    </span>
                                </td>
                                <td class="text-end no-print">
                                    <button class="btn btn-sm btn-outline-primary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>">
                                        <i class="fa-solid fa-list-ul me-1"></i>Détails (<?= $item_count ?>)
                                    </button>
                                </td>
                            </tr>

                            <!-- VOLET DÉPLIABLE DES FACTURES INDIVIDUELLES -->
                            <tr class="collapse-row">
                                <td colspan="9" class="p-0 border-0">
                                    <div class="collapse multi-collapse p-3 bg-white border-bottom shadow-inner" id="<?= $collapse_id ?>">
                                        <div class="small fw-bold text-secondary mb-2">
                                            <i class="fa-solid fa-circle-nodes text-primary me-1"></i>Détail des <?= $item_count ?> créances de <strong><?= htmlspecialchars($group['client_name']) ?></strong> :
                                        </div>
                                        <table class="table table-sm table-bordered align-middle mb-0 bg-light">
                                            <thead class="table-light">
                                                <tr class="small text-secondary">
                                                    <th>Facture N°</th>
                                                    <th>Date & Dépôt</th>
                                                    <th>Échéance</th>
                                                    <th class="text-end">Montant Total</th>
                                                    <th class="text-end">Déjà Payé</th>
                                                    <th class="text-end">Reste à Payer</th>
                                                    <th class="text-center">Statut</th>
                                                    <th class="text-end no-print">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $h): 
                                                    $due = strtotime($h['due_date']);
                                                    $is_overdue = ($due < time()) && ($h['payment_status'] !== 'PAID');
                                                    $remaining = floatval($h['total_amount']) - floatval($h['amount_paid']);
                                                    
                                                    $status_badge = '';
                                                    if ($h['payment_status'] === 'PAID') {
                                                        $status_badge = '<span class="badge-status-paid"><i class="fa-solid fa-check-double me-1"></i>Soldé</span>';
                                                    } elseif ($h['payment_status'] === 'PARTIAL') {
                                                        $status_badge = '<span class="badge-status-partial"><i class="fa-solid fa-clock me-1"></i>Partiel</span>';
                                                    } else {
                                                        $status_badge = '<span class="badge-status-unpaid"><i class="fa-solid fa-hourglass-half me-1"></i>Non réglé</span>';
                                                    }
                                                ?>
                                                    <tr class="<?= $is_overdue ? 'table-danger' : '' ?>">
                                                        <td class="fw-bold">
                                                            <a href="invoice.php?id=<?= $h['id'] ?>" class="text-decoration-none text-primary"><?= ($active_tab === 'clients' ? 'FAC-' : 'ACH-') ?><?= htmlspecialchars($h['reference'] ?? 'FAC-'.sprintf("%05d", $h['id'])) ?></a>
                                                            <?php if (!empty($h['is_historical'])): ?>
                                                                <span class="badge-historical ms-1" style="font-size: 0.65rem;" title="Solde antérieur"><i class="fa-solid fa-clock-rotate-left"></i>Solde Antérieur</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small">
                                                            <span class="text-dark fw-semibold"><?= date('d/m/Y', strtotime($h['created_at'])) ?></span> 
                                                            <span class="text-muted">(<?= htmlspecialchars($h['shop_name'] ?? '-') ?>)</span>
                                                        </td>
                                                        <td class="small">
                                                            <span class="<?= $is_overdue ? 'text-danger fw-bold' : 'text-secondary' ?>"><?= date('d/m/Y', $due) ?></span>
                                                            <?php if ($is_overdue): ?>
                                                                <span class="badge bg-danger py-0.5 px-1 fw-bold" style="font-size: 0.6rem;">Retard</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end fw-semibold small"><?= number_format($h['total_amount'], 0, '.', ' ') ?> FCFA</td>
                                                        <td class="text-end text-success small"><?= number_format($h['amount_paid'], 0, '.', ' ') ?> FCFA</td>
                                                        <td class="text-end text-danger fw-bold small"><?= number_format($remaining, 0, '.', ' ') ?> FCFA</td>
                                                        <td class="text-center small"><?= $status_badge ?></td>
                                                        <td class="text-end no-print">
                                                            <div class="d-flex justify-content-end gap-1">
                                                                <?php if ($remaining > 0): ?>
                                                                    <button type="button" class="btn btn-xs btn-grad py-1 px-2" style="font-size: 0.75rem;" 
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#paymentModal" 
                                                                            data-order-id="<?= $h['id'] ?>" 
                                                                            data-client="<?= htmlspecialchars($h['client_name'] ?? 'Inconnu') ?>"
                                                                            data-remaining="<?= $remaining ?>"
                                                                            data-invoice="<?= ($active_tab === 'clients' ? 'FAC-' : 'ACH-') ?><?= htmlspecialchars($h['reference'] ?? 'FAC-'.sprintf("%05d", $h['id'])) ?>">
                                                                        <i class="fa-solid fa-coins me-1"></i> Payer
                                                                    </button>
                                                                <?php endif; ?>
                                                                <a href="invoice.php?id=<?= $h['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2" style="font-size: 0.75rem;" title="Voir">
                                                                    <i class="fa-solid fa-eye"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Barre de Pagination Créances -->
        <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                <div class="small text-secondary fw-semibold">
                    Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($paginated_grouped_credits)) ?> sur <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> contact(s)
                </div>
                <nav aria-label="Pagination créances">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $query_params = $_GET;
                        if ($current_page > 1):
                            $query_params['page'] = $current_page - 1;
                            echo '<li class="page-item"><a class="page-link" href="credits.php?' . http_build_query($query_params) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                        else:
                            echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                        endif;

                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);

                        if ($start_page > 1) {
                            $query_params['page'] = 1;
                            echo '<li class="page-item"><a class="page-link" href="credits.php?' . http_build_query($query_params) . '">1</a></li>';
                            if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }

                        for ($p = $start_page; $p <= $end_page; $p++):
                            $query_params['page'] = $p;
                            $active_cls = ($p == $current_page) ? 'active' : '';
                            echo '<li class="page-item ' . $active_cls . '"><a class="page-link" href="credits.php?' . http_build_query($query_params) . '">' . $p . '</a></li>';
                        endfor;

                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            $query_params['page'] = $total_pages;
                            echo '<li class="page-item"><a class="page-link" href="credits.php?' . http_build_query($query_params) . '">' . $total_pages . '</a></li>';
                        }

                        if ($current_page < $total_pages):
                            $query_params['page'] = $current_page + 1;
                            echo '<li class="page-item"><a class="page-link" href="credits.php?' . http_build_query($query_params) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                        else:
                            echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                        endif;
                        ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </div> <!-- /printArea close div -->
</div>

<!-- Modal Saisie de Règlement -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 450px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-slate-800" id="paymentModalLabel"><i class="fa-solid fa-coins text-warning me-2"></i>Saisir un Règlement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="credits.php" method="POST" onsubmit="return confirm('Enregistrer ce versement ?');">
                <div class="modal-body py-4">
                    <input type="hidden" name="order_id" id="modal_order_id">
                    <input type="hidden" name="record_payment" value="1">
                    <input type="hidden" name="tab" value="<?= $active_tab ?>">
                    
                    <div class="mb-3 bg-light p-3 rounded-3 border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-secondary small">Contact :</span>
                            <span class="fw-bold text-slate-900" id="modal_client_name">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-secondary small">Facture :</span>
                            <span class="fw-bold text-slate-900" id="modal_invoice_num">-</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary small">Reste à payer :</span>
                            <span class="fw-bold text-danger" id="modal_remaining_display">-</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="payment_date" class="form-label fw-bold text-secondary">Date du Règlement *</label>
                        <input type="date" class="form-control form-control-custom" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="amount_to_pay" class="form-label fw-bold text-secondary">Montant du Règlement *</label>
                        <input type="number" step="0.01" class="form-control form-control-custom fw-bold" id="amount_to_pay" name="amount_to_pay" min="0.01" required>
                    </div>

                    <div class="mb-0">
                        <label for="pay_method" class="form-label fw-bold text-secondary">Mode de Règlement</label>
                        <select name="pay_method" id="pay_method" class="form-select form-control-custom">
                            <option value="CASH">Espèces (Cash)</option>
                            <option value="MOBILE_MONEY">Mobile Money (MOMO/OM)</option>
                            <option value="CARD">Carte Bancaire</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius: 10px;">Fermer</button>
                    <button type="submit" class="btn btn-grad px-4"><i class="fa-solid fa-circle-check me-1"></i>Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Saisie d'un Solde Antérieur (Dette/Créance Historique) -->
<div class="modal fade" id="historicalDebtModal" tabindex="-1" aria-labelledby="historicalDebtModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header bg-warning bg-opacity-10 border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-slate-900" id="historicalDebtModalLabel">
                    <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Saisir un Solde Antérieur (Avant Digitalisation)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="credits.php?tab=<?= $active_tab ?>" method="POST" onsubmit="return confirm('Confirmer l\'enregistrement de ce solde antérieur ? Les stocks d\'articles ne seront pas modifiés.');">
                <input type="hidden" name="add_historical_debt" value="1">
                
                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 mb-4" style="border-radius: 12px;">
                        <i class="fa-solid fa-circle-info me-2"></i>
                        <strong>Information :</strong> Cet outil permet d'enregistrer les dettes ou créances accumulées <strong>avant l'installation de Mandel ERP</strong>. Il n'a <strong>aucun impact sur le stock d'articles actuel</strong>.
                    </div>

                    <!-- Choix du Type (Créance Client vs Dette Fournisseur) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary">Type de Solde Antérieur *</label>
                        <div class="d-flex gap-3">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="debt_type" id="debt_type_sale" value="SALE" <?= $active_tab === 'clients' ? 'checked' : '' ?> onchange="toggleDebtTypeUI()">
                                <label class="form-check-label fw-bold text-dark" for="debt_type_sale"><i class="fa-solid fa-user me-1 text-warning"></i>Créance Client (Vente passée)</label>
                            </div>
                            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="debt_type" id="debt_type_purchase" value="PURCHASE" <?= $active_tab === 'suppliers' ? 'checked' : '' ?> onchange="toggleDebtTypeUI()">
                                <label class="form-check-label fw-bold text-dark" for="debt_type_purchase"><i class="fa-solid fa-truck me-1 text-danger"></i>Dette Fournisseur (Achat passé)</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Sélection / Saisie du Client (Créance Client) -->
                    <div id="client_selection_block" class="mb-4">
                        <label for="client_id" class="form-label fw-bold text-secondary">Client Concerné *</label>
                        <select name="client_id" id="client_id" class="form-select form-control-custom mb-2" onchange="toggleNewClientInputs(this.value)">
                            <option value="">-- Sélectionner un client --</option>
                            <option value="NEW">+ Ajouter un Nouveau Client...</option>
                            <?php foreach ($all_clients as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?><?= !empty($cl['phone']) ? ' (' . htmlspecialchars($cl['phone']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div id="new_client_fields" class="row g-2 mt-1" style="display: none;">
                            <div class="col-12 col-md-7">
                                <input type="text" name="new_client_name" id="new_client_name" class="form-control form-control-custom" placeholder="Nom complet du nouveau client">
                            </div>
                            <div class="col-12 col-md-5">
                                <input type="text" name="new_client_phone" id="new_client_phone" class="form-control form-control-custom" placeholder="N° Téléphone">
                            </div>
                        </div>
                    </div>

                    <!-- Sélection / Saisie du Fournisseur (Dette Fournisseur) -->
                    <div id="supplier_selection_block" class="mb-4" style="display: none;">
                        <label for="supplier_id" class="form-label fw-bold text-secondary">Fournisseur Concerné *</label>
                        <select name="supplier_id" id="supplier_id" class="form-select form-control-custom mb-2" onchange="toggleNewSupplierInputs(this.value)">
                            <option value="">-- Sélectionner un fournisseur --</option>
                            <option value="NEW">+ Ajouter un Nouveau Fournisseur...</option>
                            <?php foreach ($all_suppliers as $sp): ?>
                                <option value="<?= $sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?><?= !empty($sp['phone']) ? ' (' . htmlspecialchars($sp['phone']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div id="new_supplier_fields" class="row g-2 mt-1" style="display: none;">
                            <div class="col-12 col-md-7">
                                <input type="text" name="new_supplier_name" id="new_supplier_name" class="form-control form-control-custom" placeholder="Nom complet du nouveau fournisseur">
                            </div>
                            <div class="col-12 col-md-5">
                                <input type="text" name="new_supplier_phone" id="new_supplier_phone" class="form-control form-control-custom" placeholder="N° Téléphone">
                            </div>
                        </div>
                    </div>

                    <!-- Montants & Dates -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="total_amount" class="form-label fw-bold text-secondary">Montant Total du Solde (FCFA) *</label>
                            <input type="number" step="0.01" min="1" name="total_amount" id="total_amount" class="form-control form-control-custom fw-bold fs-5 text-slate-900" placeholder="Ex: 150000" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="amount_paid" class="form-label fw-bold text-secondary">Acompte / Déjà Payé dans le passé (FCFA)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid" id="amount_paid" class="form-control form-control-custom" placeholder="0 si 100% impayé" value="0">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="created_at" class="form-label fw-bold text-secondary">Date d'Origine de la Dette *</label>
                            <input type="date" name="created_at" id="created_at" class="form-control form-control-custom" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="due_date" class="form-label fw-bold text-secondary">Date d'Échéance Prévue</label>
                            <input type="date" name="due_date" id="due_date" class="form-control form-control-custom">
                        </div>
                    </div>

                    <!-- Remarques -->
                    <div class="mb-2">
                        <label for="notes" class="form-label fw-bold text-secondary">Détails / Motif / Référence du solde</label>
                        <textarea name="notes" id="notes" class="form-control form-control-custom" rows="2" placeholder="Ex: Reliquat sur ancienne livraison de cahiers du mois de Mai..."></textarea>
                    </div>
                </div>

                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius: 10px;">Annuler</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-4" style="border-radius: 10px;">
                        <i class="fa-solid fa-check me-1"></i>Enregistrer le solde antérieur
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDebtTypeUI() {
    const isSale = document.getElementById('debt_type_sale').checked;
    const clientBlock = document.getElementById('client_selection_block');
    const supplierBlock = document.getElementById('supplier_selection_block');
    
    if (isSale) {
        if (clientBlock) clientBlock.style.display = 'block';
        if (supplierBlock) supplierBlock.style.display = 'none';
    } else {
        if (clientBlock) clientBlock.style.display = 'none';
        if (supplierBlock) supplierBlock.style.display = 'block';
    }
}

function toggleNewClientInputs(val) {
    const fields = document.getElementById('new_client_fields');
    if (fields) {
        fields.style.display = (val === 'NEW') ? 'flex' : 'none';
    }
}

function toggleNewSupplierInputs(val) {
    const fields = document.getElementById('new_supplier_fields');
    if (fields) {
        fields.style.display = (val === 'NEW') ? 'flex' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleDebtTypeUI();
    const paymentModal = document.getElementById('paymentModal');
    if (paymentModal) {
        paymentModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const orderId = button.getAttribute('data-order-id');
            const client = button.getAttribute('data-client');
            const remaining = parseFloat(button.getAttribute('data-remaining'));
            const invoice = button.getAttribute('data-invoice');

            document.getElementById('modal_order_id').value = orderId;
            document.getElementById('modal_client_name').textContent = client;
            document.getElementById('modal_invoice_num').textContent = invoice;
            document.getElementById('modal_remaining_display').textContent = new Intl.NumberFormat('fr-FR').format(remaining) + ' FCFA';

            const amountInput = document.getElementById('amount_to_pay');
            amountInput.value = remaining;
            amountInput.setAttribute('max', remaining);
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

