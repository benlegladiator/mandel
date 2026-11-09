<?php
require_once __DIR__ . '/includes/header.php';

$active_shop_id = $_SESSION['active_shop_id'];
$user_role = $_SESSION['user_role'];

// Restriction de sécurité : Seul l'administrateur a accès au module de Comptabilité & Finance
if ($user_role !== 'admin') {
    echo "<div class='alert alert-danger m-5'><strong>Accès interdit :</strong> Vous devez être administrateur pour accéder à cette page.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$error_msg = '';
$success_msg = '';

// Configuration des dates par défaut (début du mois actuel à aujourd'hui)
$default_start = date('Y-m-01');
$default_end = date('Y-m-d');

$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : $default_start;
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : $default_end;
$filter_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : 0; // 0 = Toutes
$filter_client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0; // 0 = Tous les clients
$include_stock_loss = 0; // 0 = Exclure du résultat d'exploitation
$filter_expense_type = isset($_GET['expense_type']) ? trim($_GET['expense_type']) : 'all';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'global';

// Liste des boutiques pour les formulaires et filtres
$shops = $pdo->query("SELECT id, name, is_depot FROM shops ORDER BY name ASC")->fetchAll();
$all_clients = $pdo->query("SELECT id, name, phone FROM clients ORDER BY name ASC")->fetchAll();

// Liste des catégories de dépenses dynamiques
$expense_categories = $pdo->query("SELECT id, name, description FROM expense_categories ORDER BY name ASC")->fetchAll();


// --- TRAITEMENTS POST (Charges) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $amount = floatval($_POST['amount']);
    $category = trim($_POST['category']);
    $expense_date = $_POST['expense_date'];
    $notes = trim($_POST['notes']);
    $exp_shop_id = intval($_POST['shop_id']);

    if ($amount <= 0) {
        $error_msg = "Le montant de la dépense doit être supérieur à 0.";
    } elseif (empty($category)) {
        $error_msg = "Veuillez renseigner une catégorie de charge.";
    } elseif (empty($expense_date)) {
        $error_msg = "Veuillez renseigner la date de la dépense.";
    } elseif ($exp_shop_id <= 0) {
        $error_msg = "Veuillez sélectionner un dépôt pour cette charge.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO expenses (shop_id, user_id, amount, category, expense_date, notes) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $exp_shop_id,
                $_SESSION['user_id'],
                $amount,
                $category,
                $expense_date,
                $notes
            ]);
            $expense_id = $pdo->lastInsertId();

            // Log OUT transaction in cash_transactions
            $stmt_cash = $pdo->prepare("
                INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                VALUES (?, ?, 'OUT', ?, ?, ?, 'EXPENSE', ?)
            ");
            $stmt_cash->execute([
                $exp_shop_id,
                $_SESSION['user_id'],
                $amount,
                "Dépense [" . $category . "] : " . $notes,
                $expense_id,
                $expense_date . ' ' . date('H:i:s')
            ]);

            $success_msg = "Charge d'exploitation enregistrée avec succès.";
        } catch (Exception $e) {
            $error_msg = "Erreur lors de l'enregistrement de la charge : " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inventory_loss_expenses'])) {
    try {
        $del_cnt = $pdo->exec("DELETE FROM expenses WHERE category LIKE '%stock%' OR category LIKE '%inventaire%' OR category LIKE '%perte%'");
        $success_msg = "Les $del_cnt écritures d'ajustements théoriques de stock ont été retirées du registre des charges. Votre rentabilité d'exploitation est restaurée.";
    } catch (Exception $e) {
        $error_msg = "Erreur de nettoyage des charges de stock : " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense'])) {
    $del_id = intval($_POST['delete_expense_id']);
    if ($del_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->execute([$del_id]);

            // Delete corresponding cash transaction
            $stmt_del_cash = $pdo->prepare("DELETE FROM cash_transactions WHERE reference_id = ? AND reference_type = 'EXPENSE'");
            $stmt_del_cash->execute([$del_id]);

            $success_msg = "Dépense supprimée de l'historique.";
        } catch (Exception $e) {
            $error_msg = "Erreur de suppression : " . $e->getMessage();
        }
    }
}

// --- CALCULS COMPTABLES GLOBAUX OU PAR FILTRE ---

// 1. Chiffre d'Affaires & COGS (Filtré)
$sales_sql = "
    SELECT 
        SUM(o.total_amount) as total_sales,
        SUM(o.amount_paid) as cash_collected,
        SUM((
            SELECT COALESCE(SUM(oi.quantity * p.price_purchase), 0)
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = o.id
        )) as total_sales_cost
    FROM orders o
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
";
$sales_params = [];
if ($filter_shop_id > 0) {
    $sales_sql .= " AND o.shop_id = :shop_id";
    $sales_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $sales_sql .= " AND DATE(o.created_at) >= :date_start";
    $sales_params[':date_start'] = $date_start;
}
if ($date_end) {
    $sales_sql .= " AND DATE(o.created_at) <= :date_end";
    $sales_params[':date_end'] = $date_end;
}
$stmt_sales = $pdo->prepare($sales_sql);
$stmt_sales->execute($sales_params);
$sales_res = $stmt_sales->fetch();

$ca_sales = floatval($sales_res['total_sales'] ?? 0);
$cogs = floatval($sales_res['total_sales_cost'] ?? 0);
$cash_collected = floatval($sales_res['cash_collected'] ?? 0);
$gross_profit = $ca_sales - $cogs;

// 2. Charges de la période (Filtré)
$exp_sql = "SELECT SUM(amount) FROM expenses WHERE 1=1";
$exp_params = [];
if ($filter_shop_id > 0) {
    $exp_sql .= " AND shop_id = :shop_id";
    $exp_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $exp_sql .= " AND expense_date >= :date_start";
    $exp_params[':date_start'] = $date_start;
}
if ($date_end) {
    $exp_sql .= " AND expense_date <= :date_end";
    $exp_params[':date_end'] = $date_end;
}
$stmt_exp = $pdo->prepare($exp_sql);
$stmt_exp->execute($exp_params);

// Séparation des charges réelles de fonctionnement et des régularisations de stock
$exp_base_where = "WHERE 1=1";
$exp_base_params = [];
if ($filter_shop_id > 0) {
    $exp_base_where .= " AND shop_id = :sh_id";
    $exp_base_params[':sh_id'] = $filter_shop_id;
}
if ($date_start) {
    $exp_base_where .= " AND expense_date >= :d_start";
    $exp_base_params[':d_start'] = $date_start;
}
if ($date_end) {
    $exp_base_where .= " AND expense_date <= :d_end";
    $exp_base_params[':d_end'] = $date_end;
}

// A. Charges d'exploitation réelles (transport, salaires, emballages, entretien, loyer...)
$stmt_cash_exp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses $exp_base_where");
$stmt_cash_exp->execute($exp_base_params);
$operating_cash_expenses = floatval($stmt_cash_exp->fetchColumn() ?: 0);

// B. Bénéfice Net d'Exploitation Réel des ventes (Sans pollution d'ajustements théoriques)
$operating_profit = $gross_profit - $operating_cash_expenses;
$total_operating_expenses = $operating_cash_expenses;
$net_profit = $operating_profit;
$stock_adjustments_loss = 0;

// 3. Valeur Actuelle du Stock (Achat vs Vente)
$stock_sql = "
    SELECT 
        SUM(ps.quantity * p.price_purchase) as purchase_val,
        SUM(ps.quantity * p.price_sale) as sale_val
    FROM product_stock ps
    JOIN products p ON ps.product_id = p.id
    WHERE 1=1
";
$stock_params = [];
if ($filter_shop_id > 0) {
    $stock_sql .= " AND ps.shop_id = :shop_id";
    $stock_params[':shop_id'] = $filter_shop_id;
}
$stmt_stock = $pdo->prepare($stock_sql);
$stmt_stock->execute($stock_params);
$stock_res = $stmt_stock->fetch();
$stock_purchase_val = floatval($stock_res['purchase_val'] ?? 0);
$stock_sale_val = floatval($stock_res['sale_val'] ?? 0);

// 4. Charges par catégorie (Exploitation)
$cat_sql = "SELECT category, SUM(amount) as total_cat FROM expenses WHERE 1=1";
$cat_params = [];
if ($filter_shop_id > 0) {
    $cat_sql .= " AND shop_id = :shop_id";
    $cat_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $cat_sql .= " AND expense_date >= :date_start";
    $cat_params[':date_start'] = $date_start;
}
if ($date_end) {
    $cat_sql .= " AND expense_date <= :date_end";
    $cat_params[':date_end'] = $date_end;
}
$cat_sql .= " GROUP BY category ORDER BY total_cat DESC";
$stmt_cat = $pdo->prepare($cat_sql);
$stmt_cat->execute($cat_params);
$charges_by_cat = $stmt_cat->fetchAll();

// 5. Total Ravitaillements (Investissement stock)
$purch_sql = "SELECT SUM(total_amount) FROM orders WHERE type = 'PURCHASE' AND status = 'COMPLETED'";
$purch_params = [];
if ($filter_shop_id > 0) {
    $purch_sql .= " AND shop_id = :shop_id";
    $purch_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $purch_sql .= " AND DATE(created_at) >= :date_start";
    $purch_params[':date_start'] = $date_start;
}
if ($date_end) {
    $purch_sql .= " AND DATE(created_at) <= :date_end";
    $purch_params[':date_end'] = $date_end;
}
$stmt_purch = $pdo->prepare($purch_sql);
$stmt_purch->execute($purch_params);
$total_purchases_stock = floatval($stmt_purch->fetchColumn() ?: 0);

// --- DETAILS DES VENTES ET ENCAISSEMENTS (Pour Modales) ---
$sales_details_sql = "
    SELECT o.id, o.reference, o.created_at, o.total_amount, o.amount_paid, o.payment_method, c.name as client_name, u.name as user_name, s.name as shop_name
    FROM orders o
    LEFT JOIN clients c ON o.client_id = c.id
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN shops s ON o.shop_id = s.id
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
";
$sales_details_params = [];
if ($filter_shop_id > 0) {
    $sales_details_sql .= " AND o.shop_id = :shop_id";
    $sales_details_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $sales_details_sql .= " AND DATE(o.created_at) >= :date_start";
    $sales_details_params[':date_start'] = $date_start;
}
if ($date_end) {
    $sales_details_sql .= " AND DATE(o.created_at) <= :date_end";
    $sales_details_params[':date_end'] = $date_end;
}
$sales_details_sql .= " ORDER BY o.created_at DESC";
$stmt_sales_details = $pdo->prepare($sales_details_sql);
$stmt_sales_details->execute($sales_details_params);
$sales_details = $stmt_sales_details->fetchAll();

$cash_details_sql = "
    SELECT o.id, o.reference, o.created_at as transaction_date, o.amount_paid as amount, 'Paiement Client' as notes, u.name as user_name, s.name as shop_name
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN shops s ON o.shop_id = s.id
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED' AND o.amount_paid > 0
";
$cash_details_params = [];
if ($filter_shop_id > 0) {
    $cash_details_sql .= " AND o.shop_id = :shop_id";
    $cash_details_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $cash_details_sql .= " AND DATE(o.created_at) >= :date_start";
    $cash_details_params[':date_start'] = $date_start;
}
if ($date_end) {
    $cash_details_sql .= " AND DATE(o.created_at) <= :date_end";
    $cash_details_params[':date_end'] = $date_end;
}
$cash_details_sql .= " ORDER BY o.created_at DESC";
$stmt_cash_details = $pdo->prepare($cash_details_sql);
$stmt_cash_details->execute($cash_details_params);
$cash_details = $stmt_cash_details->fetchAll();


// --- RECUPERATION DES FINANCES DE CHAQUE BOUTIQUE (Comparatif) ---
$shop_stats_list = [];
foreach ($shops as $sh) {
    $sh_id = $sh['id'];
    
    // Ventes, COGS, Liquidités
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(o.total_amount), 0) as sales,
            COALESCE(SUM(o.amount_paid), 0) as cash_collected,
            COALESCE(SUM((
                SELECT SUM(oi.quantity * p.price_purchase)
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = o.id
            )), 0) as cogs
        FROM orders o
        WHERE o.shop_id = ? AND o.type = 'SALE' AND o.status = 'COMPLETED'
          AND DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
    ");
    $stmt->execute([$sh_id, $date_start, $date_end]);
    $sales_info = $stmt->fetch();
    
    // Charges / Dépenses
    $stmt_exp = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM expenses 
        WHERE shop_id = ? AND expense_date >= ? AND expense_date <= ?
    ");
    $stmt_exp->execute([$sh_id, $date_start, $date_end]);
    $exp_val = floatval($stmt_exp->fetchColumn() ?: 0);
    
    // Valeur de stock
    $stmt_st = $pdo->prepare("
        SELECT 
            COALESCE(SUM(ps.quantity * p.price_purchase), 0) as p_val,
            COALESCE(SUM(ps.quantity * p.price_sale), 0) as s_val
        FROM product_stock ps
        JOIN products p ON ps.product_id = p.id
        WHERE ps.shop_id = ?
    ");
    $stmt_st->execute([$sh_id]);
    $st_info = $stmt_st->fetch();
    
    // Crédits en cours
    $stmt_cr = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount - amount_paid), 0) 
        FROM orders 
        WHERE shop_id = ? AND type = 'SALE' AND payment_status != 'PAID' AND status = 'COMPLETED'
          AND DATE(created_at) >= ? AND DATE(created_at) <= ?
    ");
    $stmt_cr->execute([$sh_id, $date_start, $date_end]);
    $sh_credits = floatval($stmt_cr->fetchColumn() ?: 0);
    
    $sh_sales = floatval($sales_info['sales']);
    $sh_cogs = floatval($sales_info['cogs']);
    $sh_gross = $sh_sales - $sh_cogs;
    $sh_net = $sh_gross - $exp_val;
    
    $shop_stats_list[] = [
        'id' => $sh_id,
        'name' => $sh['name'],
        'sales' => $sh_sales,
        'cogs' => $sh_cogs,
        'gross_profit' => $sh_gross,
        'expenses' => $exp_val,
        'net_profit' => $sh_net,
        'credits' => $sh_credits,
        'stock_purchase' => floatval($st_info['p_val']),
        'stock_sale' => floatval($st_info['s_val'])
    ];
}


// --- RECUPERATION DES FINANCES DE CHAQUE PRODUIT (Rentabilité) ---
$product_stats_list = $pdo->prepare("
    SELECT p.id as product_id, p.name as product_name, p.unit, p.price_purchase, p.price_sale,
           COALESCE(SUM(oi.quantity), 0) as qty_sold,
           COALESCE(SUM(oi.total_price), 0) as revenue,
           COALESCE(SUM(oi.quantity * p.price_purchase), 0) as total_cogs
    FROM products p
    JOIN order_items oi ON p.id = oi.product_id
    JOIN orders o ON oi.order_id = o.id AND o.type = 'SALE' AND o.status = 'COMPLETED'
        AND DATE(o.created_at) >= :start AND DATE(o.created_at) <= :end
    GROUP BY p.id, p.name, p.unit, p.price_purchase, p.price_sale
    HAVING qty_sold > 0
    ORDER BY revenue DESC
");
$product_stats_list->execute([':start' => $date_start, ':end' => $date_end]);
$products_financials = $product_stats_list->fetchAll();

// Répartition des ventes par boutique par produit
$product_shop_breakdown = $pdo->prepare("
    SELECT oi.product_id, sh.name as shop_name, SUM(oi.quantity) as qty, 
           SUM(oi.total_price) as rev, 
           SUM(oi.quantity * p.price_purchase) as cogs
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN shops sh ON o.shop_id = sh.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
      AND DATE(o.created_at) >= :start AND DATE(o.created_at) <= :end
    GROUP BY oi.product_id, o.shop_id
");
$product_shop_breakdown->execute([':start' => $date_start, ':end' => $date_end]);
$breakdown_raw = $product_shop_breakdown->fetchAll();

$breakdown = [];
foreach ($breakdown_raw as $b) {
    $breakdown[$b['product_id']][] = $b;
}


// --- RECUPERATION DES FINANCES DE CHAQUE CLIENT (Rentabilité & Fidélité) ---
$client_sql = "
    SELECT 
        c.id as client_id,
        c.name as client_name,
        c.phone as client_phone,
        c.category as client_category,
        COUNT(DISTINCT o.id) as total_orders,
        COALESCE(SUM(o.total_amount), 0) as total_revenue,
        COALESCE(SUM(o.amount_paid), 0) as total_paid,
        COALESCE(SUM(o.total_amount - o.amount_paid), 0) as total_debt,
        COALESCE(SUM((
            SELECT SUM(oi.quantity * p.price_purchase)
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = o.id
        )), 0) as total_cogs
    FROM clients c
    JOIN orders o ON c.id = o.client_id
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
";
$client_params = [];
if ($filter_shop_id > 0) {
    $client_sql .= " AND o.shop_id = :sh_id";
    $client_params[':sh_id'] = $filter_shop_id;
}
if ($date_start) {
    $client_sql .= " AND DATE(o.created_at) >= :d_start";
    $client_params[':d_start'] = $date_start;
}
if ($date_end) {
    $client_sql .= " AND DATE(o.created_at) <= :d_end";
    $client_params[':d_end'] = $date_end;
}
if ($filter_client_id > 0) {
    $client_sql .= " AND c.id = :c_id";
    $client_params[':c_id'] = $filter_client_id;
}
$client_sql .= " GROUP BY c.id, c.name, c.phone, c.category ORDER BY total_revenue DESC";
$stmt_clients_fin = $pdo->prepare($client_sql);
$stmt_clients_fin->execute($client_params);
$clients_financials = $stmt_clients_fin->fetchAll();

// --- RECUPERATION DES CREANCES (Ventes à Crédit) ---
$stmt_credits = $pdo->prepare("
    SELECT o.id as order_id, o.created_at, o.total_amount, o.amount_paid, o.due_date, o.payment_status,
           c.name as client_name, c.phone as client_phone,
           sh.name as shop_name,
           u.name as user_name
    FROM orders o
    JOIN shops sh ON o.shop_id = sh.id
    JOIN clients c ON o.client_id = c.id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.type = 'SALE' AND o.payment_status != 'PAID' AND o.status = 'COMPLETED'
      AND DATE(o.created_at) >= :start AND DATE(o.created_at) <= :end
    ORDER BY o.due_date ASC
");
$stmt_credits->execute([':start' => $date_start, ':end' => $date_end]);
$credits_list = $stmt_credits->fetchAll();

$total_credits_outstanding = 0;
foreach ($credits_list as $cr) {
    $total_credits_outstanding += ($cr['total_amount'] - $cr['amount_paid']);
}


// --- RECUPERATION DU JOURNAL DES CHARGES ---
$list_sql = "
    SELECT e.*, u.name as user_name, sh.name as shop_name 
    FROM expenses e
    JOIN users u ON e.user_id = u.id
    JOIN shops sh ON e.shop_id = sh.id
    WHERE 1=1
";
$list_params = [];
if ($filter_shop_id > 0) {
    $list_sql .= " AND e.shop_id = :shop_id";
    $list_params[':shop_id'] = $filter_shop_id;
}
if ($date_start) {
    $list_sql .= " AND e.expense_date >= :date_start";
    $list_params[':date_start'] = $date_start;
}
if ($date_end) {
    $list_sql .= " AND e.expense_date <= :date_end";
    $list_params[':date_end'] = $date_end;
}
$list_sql .= " ORDER BY e.expense_date DESC, e.id DESC";
if ($filter_expense_type === 'cash') {
    $list_sql .= " AND e.category NOT LIKE '%stock%' AND e.category NOT LIKE '%inventaire%' AND e.category NOT LIKE '%perte%'";
} elseif ($filter_expense_type === 'stock') {
    $list_sql .= " AND (e.category LIKE '%stock%' OR e.category LIKE '%inventaire%' OR e.category LIKE '%perte%')";
}
$stmt_list = $pdo->prepare($list_sql);
$stmt_list->execute($list_params);
$expenses_list = $stmt_list->fetchAll();
?>

<!-- Impression spécifique CSS -->
<style>
@media print {
    .no-print, .btn, form, nav, .alert, .nav-pills {
        display: none !important;
    }
    body {
        background: white !important;
        color: black !important;
        font-size: 11pt;
        padding: 0;
        margin: 1cm;
    }
    .glass-card {
        border: 1px solid #cbd5e1 !important;
        box-shadow: none !important;
        background: transparent !important;
        padding: 15px !important;
        border-radius: 8px !important;
        page-break-inside: avoid;
    }
    .print-full-width {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
    }
    .d-print-block-custom {
        display: block !important;
    }
    .table-custom td, .table-custom th {
        padding: 6px 8px !important;
        font-size: 9pt !important;
    }
}
.d-print-block-custom {
    display: none;
}
</style>

<!-- En-tête imprimable -->
<div class="d-print-block-custom text-center mb-4">
    <h2 class="fw-bold mb-1">Mandel Papeterie - Rapport Financier Consolidé</h2>
    <h5 class="text-secondary mb-1">Dépôt : <?= $filter_shop_id > 0 ? htmlspecialchars($shops[array_search($filter_shop_id, array_column($shops, 'id'))]['name']) : 'Toutes les boutiques' ?></h5>
    <p class="text-secondary small mb-2">Période : Du <?= date('d/m/Y', strtotime($date_start)) ?> au <?= date('d/m/Y', strtotime($date_end)) ?></p>
    <div style="border-top: 2px solid #0f172a; margin-top: 10px; margin-bottom: 20px;"></div>
</div>

<!-- En-tête de page Standard -->
<div class="row mb-3 no-print">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-calculator"></i></span> 
            Analyses & Rapport Financiers
        </h1>
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small fw-semibold d-none d-md-inline">Rentabilité et performance commerciale</span>
            <button type="button" onclick="exportTableToExcel('financeExpensesTable', 'rapport_financier.csv')" class="btn btn-outline-success btn-sm fw-semibold" style="border-radius: 8px;">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel
            </button>
            <button onclick="window.print();" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                <i class="fa-solid fa-print me-1"></i>Imprimer
            </button>
        </div>

    </div>
</div>

<!-- Filtres Standard de Période (no-print) -->
<div class="row mb-4 no-print">
    <div class="col-12">
        <div class="glass-card p-4">
            <form method="GET" action="finance.php" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                
                <div class="col-12 col-sm-6 col-md-2">
                    <label for="date_start" class="form-label small fw-semibold text-secondary mb-1">Du (Date début)</label>
                    <input type="date" class="form-control form-control-custom" id="date_start" name="date_start" value="<?= htmlspecialchars($date_start) ?>">
                </div>

                <div class="col-12 col-sm-6 col-md-2">
                    <label for="date_end" class="form-label small fw-semibold text-secondary mb-1">Au (Date fin)</label>
                    <input type="date" class="form-control form-control-custom" id="date_end" name="date_end" value="<?= htmlspecialchars($date_end) ?>">
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="shop_id" class="form-label small fw-semibold text-secondary mb-1">Boutique / Dépôt</label>
                    <select class="form-select form-control-custom" id="shop_id" name="shop_id">
                        <option value="0">🌐 Toutes les boutiques</option>
                        <?php foreach ($shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $filter_shop_id ? 'selected' : '' ?>>
                                <?= $sh['is_depot'] ? '🏭' : '🏢' ?> <?= htmlspecialchars($sh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="client_id" class="form-label small fw-semibold text-secondary mb-1">Client (Filtre Analytique)</label>
                    <select class="form-select form-control-custom" id="client_id" name="client_id">
                        <option value="0">👥 Tous les clients</option>
                        <?php foreach ($all_clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= $cl['id'] == $filter_client_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['name']) ?> <?= !empty($cl['phone']) ? '(' . htmlspecialchars($cl['phone']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1 py-2" style="border-radius: 10px;" title="Appliquer les filtres">
                        <i class="fa-solid fa-filter me-1"></i>Filtrer
                    </button>
                    <?php if ($filter_shop_id > 0 || $filter_client_id > 0 || $date_start != $default_start || $date_end != $default_end): ?>
                        <a href="finance.php?tab=<?= htmlspecialchars($active_tab) ?>" class="btn btn-outline-secondary py-2" style="border-radius: 10px;" title="Réinitialiser">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </div>

                
            </form>
        </div>
    </div>
</div>

<!-- Alertes -->
<?php if ($success_msg): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4 no-print" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4 no-print" style="border-radius: 12px;">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error_msg ?>
    </div>
<?php endif; ?>

<!-- Navigation par Onglets -->
<ul class="nav nav-pills mb-4 no-print gap-2" id="financeTabs">
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'global' ? 'active' : 'btn-light text-dark' ?> fw-semibold" href="finance.php?tab=global&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" style="border-radius: 8px;">
            <i class="fa-solid fa-chart-line me-2"></i>Vue Globale Entreprise
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'shops' ? 'active' : 'btn-light text-dark' ?> fw-semibold" href="finance.php?tab=shops&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" style="border-radius: 8px;">
            <i class="fa-solid fa-store me-2"></i>Finances par Boutique
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'products' ? 'active' : 'btn-light text-dark' ?> fw-semibold" href="finance.php?tab=products&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" style="border-radius: 8px;">
            <i class="fa-solid fa-box me-2"></i>Finances par Produit
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'clients' ? 'active' : 'btn-light text-dark' ?> fw-semibold" href="finance.php?tab=clients&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" style="border-radius: 8px;">
            <i class="fa-solid fa-users me-2"></i>Finances par Client
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'credits' ? 'active' : 'btn-light text-dark' ?> fw-semibold" href="finance.php?tab=credits&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" style="border-radius: 8px;">
            <i class="fa-solid fa-hand-holding-dollar me-2"></i>Créances & Dettes Clients
            <?php if (count($credits_list) > 0): ?>
                <span class="badge bg-danger ms-1"><?= count($credits_list) ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'expenses' ? 'active' : 'btn-light text-dark' ?> fw-semibold" href="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" style="border-radius: 8px;">
            <i class="fa-solid fa-file-invoice-dollar me-2"></i>Registre des Charges
        </a>
    </li>
</ul>

<!-- CONTENU DES ONGLETS -->

<?php if ($active_tab === 'global'): ?>
    <!-- 1. VUE GLOBALE ENTREPRISE -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-sm-6 col-lg">
            <div class="glass-card p-4 h-100 border-start border-4 border-success text-decoration-none" style="cursor: pointer; display: block; transition: all 0.2s;" data-bs-toggle="modal" data-bs-target="#salesModal" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.05)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-label">Ventes Totales (CA)</div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-secondary small"></i>
                </div>
                <div class="stat-value text-success mt-2"><?= number_format($ca_sales, 0, '.', ' ') ?> F</div>
                <div class="small text-secondary mt-1">Ventes nettes validées</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <a href="finance.php?tab=products&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>" class="glass-card p-4 h-100 border-start border-4 border-warning text-decoration-none" style="display: block; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.05)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-label text-dark">Coût d'Achat Vendu (COGS)</div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-secondary small"></i>
                </div>
                <div class="stat-value text-warning mt-2"><?= number_format($cogs, 0, '.', ' ') ?> F</div>
                <div class="small text-secondary mt-1">Coût réel des marchandises vendues</div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <a href="finance.php?tab=products&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>" class="glass-card p-4 h-100 border-start border-4 border-primary text-decoration-none" style="display: block; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.05)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-label text-dark">Marge Commerciale brute</div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-secondary small"></i>
                </div>
                <div class="stat-value text-primary mt-2"><?= number_format($gross_profit, 0, '.', ' ') ?> F</div>
                <div class="small text-secondary mt-1">Taux de marge : <strong><?= $ca_sales > 0 ? number_format(($gross_profit / $ca_sales) * 100, 1) : 0 ?>%</strong></div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <a href="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" class="glass-card p-4 h-100 border-start border-4 border-danger text-decoration-none" style="display: block; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.05)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-label text-dark">Dépenses de Fonctionnement</div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-secondary small"></i>
                </div>
                <div class="stat-value text-danger mt-2"><?= number_format($total_operating_expenses, 0, '.', ' ') ?> F</div>
                <div class="small text-secondary mt-1">Charges diverses comptabilisées</div>
            </a>
        </div>
        <div class="col-12 col-sm-6 col-lg">
            <a href="#pnl-section" class="glass-card p-4 h-100 border-start border-4 <?= $operating_profit >= 0 ? 'border-success' : 'border-danger' ?> text-decoration-none" style="display: block; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.05)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-label text-dark">Bénéfice Opérationnel</div>
                    <i class="fa-solid fa-arrow-down text-secondary small"></i>
                </div>
                <div class="stat-value <?= $operating_profit >= 0 ? 'text-success' : 'text-danger' ?> mt-2"><?= ($operating_profit >= 0 ? '+' : '') . number_format($operating_profit, 0, '.', ' ') ?> F</div>
                <div class="small text-secondary mt-1">
                    Rentabilité réelle : <strong class="<?= $operating_profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= $ca_sales > 0 ? number_format(($operating_profit / $ca_sales) * 100, 1) : 0 ?>%</strong>
                    
                </div>
            </a>
        </div>
    </div>

    <!-- Indicateurs créatifs (Cashflow & Stock) -->
    <div class="row g-4 mb-4">
        <!-- Ratios de Rentabilité & Flux de Trésorerie -->
        <div class="col-12 col-lg-7">
            <div class="glass-card p-4 h-100">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-chart-column text-primary me-2"></i>Ratios de Performance & Flux Financiers</h5>
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="border rounded p-3 bg-light-subtle" style="cursor: pointer; transition: all 0.2s;" data-bs-toggle="modal" data-bs-target="#cashModal" onmouseover="this.style.backgroundColor='#f1f5f9'; this.style.transform='translateY(-2px)';" onmouseout="this.style.backgroundColor=''; this.style.transform='none';">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="small text-secondary font-semibold"><i class="fa-solid fa-hand-holding-dollar text-success me-1"></i>Liquidités Encaissées</div>
                                <i class="fa-solid fa-arrow-up-right-from-square text-secondary small"></i>
                            </div>
                            <div class="fw-bold text-dark fs-5 mt-1"><?= number_format($cash_collected, 0, '.', ' ') ?> FCFA</div>
                            <div class="small text-muted" style="font-size: 0.8rem;">Taux d'encaissement : <?= $ca_sales > 0 ? number_format(($cash_collected / $ca_sales) * 100, 1) : 100 ?>%</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <a href="finance.php?tab=credits&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" class="border rounded p-3 bg-light-subtle text-decoration-none d-block" style="transition: all 0.2s;" onmouseover="this.style.backgroundColor='#f1f5f9'; this.style.transform='translateY(-2px)';" onmouseout="this.style.backgroundColor=''; this.style.transform='none';">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="small text-secondary font-semibold"><i class="fa-solid fa-triangle-exclamation text-danger me-1"></i>Dettes Clients Créées</div>
                                <i class="fa-solid fa-arrow-up-right-from-square text-secondary small"></i>
                            </div>
                            <div class="fw-bold text-danger fs-5 mt-1"><?= number_format($ca_sales - $cash_collected, 0, '.', ' ') ?> FCFA</div>
                            <div class="small text-muted" style="font-size: 0.8rem;">Ventes restant à recouvrer</div>
                        </a>
                    </div>
                    <div class="col-sm-6">
                        <div class="border rounded p-3 bg-light-subtle">
                            <div class="small text-secondary font-semibold"><i class="fa-solid fa-percent text-info me-1"></i>Taux de Marge Nette</div>
                            <div class="fw-bold text-info fs-5 mt-1"><?= $ca_sales > 0 ? number_format(($net_profit / $ca_sales) * 100, 1) : 0 ?> %</div>
                            <div class="small text-muted" style="font-size: 0.8rem;">Rentabilité nette par franc vendu</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="border rounded p-3 bg-light-subtle">
                            <div class="small text-secondary font-semibold"><i class="fa-solid fa-gauge text-warning me-1"></i>Ratio de Charges Opérationnelles</div>
                            <div class="fw-bold text-warning fs-5 mt-1"><?= $ca_sales > 0 ? number_format(($total_operating_expenses / $ca_sales) * 100, 1) : 0 ?> %</div>
                            <div class="small text-muted" style="font-size: 0.8rem;">Part des ventes absorbée par le fonctionnement</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Valeur de stock globale -->
        <div class="col-12 col-lg-5">
            <div class="glass-card p-4 h-100">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-warehouse text-success me-2"></i>Valorisation Générale du Stock</h5>
                
                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-secondary fw-semibold">Valeur au Prix d'Achat :</span>
                        <span class="fw-bold text-dark"><?= number_format($stock_purchase_val, 0, '.', ' ') ?> FCFA</span>
                    </div>
                    <div class="small text-muted" style="font-size: 0.8rem;">Capital actuellement investi et immobilisé dans les rayons.</div>
                </div>

                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-secondary fw-semibold">Valeur au Prix de Vente estimée :</span>
                        <span class="fw-bold text-primary"><?= number_format($stock_sale_val, 0, '.', ' ') ?> FCFA</span>
                    </div>
                    <div class="small text-muted" style="font-size: 0.8rem;">Potentiel de vente théorique total des articles en rayon.</div>
                </div>

                <div class="border border-success-subtle rounded p-3 bg-success-subtle bg-opacity-10">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-success fw-bold">Marge Potentielle sur Stock :</span>
                        <span class="fw-extrabold text-success"><?= number_format($stock_sale_val - $stock_purchase_val, 0, '.', ' ') ?> FCFA</span>
                    </div>
                    <div class="small text-muted" style="font-size: 0.8rem;">Marge brute théorique attendue si 100% du stock est vendu.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- P&L + Diagramme de répartition des charges -->
    <div class="row g-4 mb-4">
        <!-- Compte de Résultat (P&L) -->
        <div class="col-12 col-lg-7 print-full-width">
            <div class="glass-card p-4 h-100">
                <h5 class="fw-bold mb-4 text-slate-800 border-bottom pb-2"><i class="fa-solid fa-list-check text-primary me-2"></i>Compte de Résultat Consolidé (P&L)</h5>
                
                <div class="table-responsive">
                    <table class="table align-middle table-sm table-custom">
                        <tbody>
                            <tr class="table-light">
                                <td class="fw-bold" colspan="2"><i class="fa-solid fa-wallet text-success me-2"></i>REVENUS (PRODUITS)</td>
                                <td class="text-end fw-bold"></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Chiffre d'Affaires (Ventes réalisées)</td>
                                <td></td>
                                <td class="text-end fw-bold text-success">+<?= number_format($ca_sales, 0, '.', ' ') ?> FCFA</td>
                            </tr>
                            
                            <tr class="table-light">
                                <td class="fw-bold" colspan="2"><i class="fa-solid fa-boxes-stacked text-warning me-2"></i>COÛT DES VENTES (COGS)</td>
                                <td class="text-end fw-bold"></td>
                            </tr>
                            <tr>
                                <td class="ps-4">Coût d'achat de la marchandise vendue</td>
                                <td></td>
                                <td class="text-end fw-semibold text-warning">-<?= number_format($cogs, 0, '.', ' ') ?> FCFA</td>
                            </tr>
                            
                            <tr class="fw-bold bg-light">
                                <td class="ps-3 text-primary">MARGE BRUTE OPÉRATIONNELLE</td>
                                <td></td>
                                <td class="text-end text-primary">+<?= number_format($gross_profit, 0, '.', ' ') ?> FCFA</td>
                            </tr>
                            
                            <tr class="table-light">
                                <td class="fw-bold" colspan="2"><i class="fa-solid fa-file-invoice-dollar text-danger me-2"></i>CHARGES DE FONCTIONNEMENT RÉELLES</td>
                                <td class="text-end fw-bold"></td>
                            </tr>
                            <?php 
                            $real_charges = [];
                            $stock_charges = [];
                            foreach ($charges_by_cat as $cat_exp) {
                                $cat_name = strtolower($cat_exp['category']);
                                if (strpos($cat_name, 'stock') !== false || strpos($cat_name, 'inventaire') !== false || strpos($cat_name, 'perte') !== false) {
                                    $stock_charges[] = $cat_exp;
                                } else {
                                    $real_charges[] = $cat_exp;
                                }
                            }
                            ?>
                            <?php if (empty($real_charges)): ?>
                                <tr>
                                    <td class="ps-4 text-muted italic" colspan="3">Aucune charge courante sur la période.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($real_charges as $cat_exp): ?>
                                    <tr>
                                        <td class="ps-4 text-capitalize"><?= htmlspecialchars($cat_exp['category']) ?></td>
                                        <td></td>
                                        <td class="text-end text-danger">-<?= number_format($cat_exp['total_cat'], 0, '.', ' ') ?> FCFA</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <tr class="fw-semibold text-danger bg-light">
                                <td class="ps-3">Total Charges d'Exploitation Réelles (Décaissées)</td>
                                <td></td>
                                <td class="text-end fw-bold">-<?= number_format($operating_cash_expenses, 0, '.', ' ') ?> FCFA</td>
                            </tr>
                            
                            <tr class="fw-bold" style="background-color: #f0fdf4; border-top: 2px solid #16a34a; border-bottom: 2px solid #16a34a;">
                                <td class="ps-3 text-success fs-6"><i class="fa-solid fa-circle-check me-2"></i>RÉSULTAT D'EXPLOITATION RÉEL (Bénéfice Ventes)</td>
                                <td class="text-center text-success small fw-semibold"><?= $ca_sales > 0 ? number_format(($operating_profit / $ca_sales) * 100, 1) : 0 ?> %</td>
                                <td class="text-end fw-bold text-success fs-6">+<?= number_format($operating_profit, 0, '.', ' ') ?> FCFA</td>
                            </tr>


                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded-3 small text-secondary">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    <strong>Information :</strong> Le montant global investi en réassort de stocks neufs sur cette période s'élève à <strong><?= number_format($total_purchases_stock, 0, '.', ' ') ?> FCFA</strong>.
                </div>
            </div>
        </div>

        <!-- Graphique des charges -->
        <div class="col-12 col-lg-5">
            <div class="glass-card p-4 h-100">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-chart-pie text-danger me-2"></i>Répartition des Charges</h5>
                <?php if (empty($charges_by_cat)): ?>
                    <div class="text-center py-5 text-secondary">Aucune charge d'exploitation enregistrée sur la période.</div>
                <?php else: ?>
                    <div style="position: relative; height: 280px;">
                        <canvas id="expensesCategoriesChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($active_tab === 'shops'): ?>
    <!-- 2. FINANCES PAR BOUTIQUE -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="glass-card p-4">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-store text-primary me-2"></i>Situation et Comparatif Financier par Dépôt</h5>
                
                <div class="table-responsive">
                    <table class="table table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Nom de la Boutique</th>
                                <th class="text-end">CA (Ventes)</th>
                                <th class="text-end">Coût Vendu (COGS)</th>
                                <th class="text-end">Marge Brute</th>
                                <th class="text-end">Charges Opér.</th>
                                <th class="text-end text-primary">Bénéfice Net</th>
                                <th class="text-center">Rentabilité Nette</th>
                                <th class="text-end">Stock (Achat)</th>
                                <th class="text-end">Stock (Vente)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shop_stats_list as $shop_f): 
                                $profit_pct = $shop_f['sales'] > 0 ? ($shop_f['net_profit'] / $shop_f['sales']) * 100 : 0;
                                $profit_class = $shop_f['net_profit'] >= 0 ? 'text-success' : 'text-danger';
                                $rent_badge = $profit_pct >= 20 ? 'bg-success' : ($profit_pct >= 5 ? 'bg-warning text-dark' : 'bg-danger');
                            ?>
                                <tr>
                                    <td class="fw-bold text-slate-800">
                                        <i class="fa-solid fa-warehouse text-secondary me-2"></i><?= htmlspecialchars($shop_f['name']) ?>
                                    </td>
                                    <td class="text-end font-semibold"><?= number_format($shop_f['sales'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end text-warning"><?= number_format($shop_f['cogs'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end text-success"><?= number_format($shop_f['gross_profit'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end text-danger">-<?= number_format($shop_f['expenses'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end fw-bold <?= $profit_class ?>"><?= number_format($shop_f['net_profit'], 0, '.', ' ') ?> FCFA</td>
                                    <td class="text-center">
                                        <span class="badge <?= $rent_badge ?> px-2 py-1 fw-bold" style="border-radius: 6px; font-size: 0.75rem;">
                                            <?= number_format($profit_pct, 1) ?> %
                                        </span>
                                    </td>
                                    <td class="text-end text-secondary small"><?= number_format($shop_f['stock_purchase'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end text-secondary small"><?= number_format($shop_f['stock_sale'], 0, '.', ' ') ?> F</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Graphique comparatif boutiques -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-8 mx-auto">
            <div class="glass-card p-4">
                <h5 class="fw-bold mb-4 text-slate-800 text-center"><i class="fa-solid fa-chart-column text-success me-2"></i>Performance Net Comparée par Dépôt</h5>
                <div style="position: relative; height: 320px;">
                    <canvas id="shopPerformanceChart"></canvas>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($active_tab === 'products'): ?>
    <!-- 3. FINANCES PAR PRODUIT -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="glass-card p-4">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-boxes-stacked text-primary me-2"></i>Rentabilité par Article du Catalogue</h5>
                
                <p class="text-secondary small">Cliquez sur un produit pour afficher la répartition de ses ventes par boutique.</p>
                
                <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                    <table class="table table-hover table-custom align-middle">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th>Code / ID</th>
                                <th>Nom de l'Article</th>
                                <th class="text-center">Conditionnement</th>
                                <th class="text-center">Unités vendues</th>
                                <th class="text-end">Revenu (CA)</th>
                                <th class="text-end">Coût d'Achat</th>
                                <th class="text-end text-success">Marge Réalisée</th>
                                <th class="text-center">Taux de Marge</th>
                                <th class="text-center no-print">Option</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products_financials as $prod): 
                                $p_margin = $prod['revenue'] - $prod['total_cogs'];
                                $p_margin_pct = $prod['revenue'] > 0 ? ($p_margin / $prod['revenue']) * 100 : 0;
                            ?>
                                <tr data-bs-toggle="collapse" data-bs-target="#breakdown-<?= $prod['product_id'] ?>" style="cursor: pointer;">
                                    <td class="small text-secondary">#<?= $prod['product_id'] ?></td>
                                    <td class="fw-bold text-slate-900"><?= htmlspecialchars($prod['product_name']) ?></td>
                                    <td class="text-center small text-secondary"><?= htmlspecialchars($prod['unit']) ?></td>
                                    <td class="text-center fw-bold"><?= number_format($prod['qty_sold'], 0, '.', ' ') ?></td>
                                    <td class="text-end fw-semibold"><?= number_format($prod['revenue'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end text-warning"><?= number_format($prod['total_cogs'], 0, '.', ' ') ?> F</td>
                                    <td class="text-end fw-bold text-success">+<?= number_format($p_margin, 0, '.', ' ') ?> F</td>
                                    <td class="text-center">
                                        <span class="badge bg-success-subtle text-success px-2 py-1 fw-bold" style="border-radius: 6px; font-size: 0.75rem;">
                                            <?= number_format($p_margin_pct, 1) ?> %
                                        </span>
                                    </td>
                                    <td class="text-center no-print">
                                        <button class="btn btn-light btn-xs p-1" style="font-size: 0.75rem; border-radius: 4px;">
                                            <i class="fa-solid fa-chevron-down"></i> Détails
                                        </button>
                                    </td>
                                </tr>
                                
                                <!-- Drawer de Détails pliables -->
                                <tr id="breakdown-<?= $prod['product_id'] ?>" class="collapse no-print">
                                    <td colspan="9" class="p-3 bg-light">
                                        <div class="p-3 border rounded bg-white shadow-sm" style="border-radius: 10px;">
                                            <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-store me-1"></i>Ventes et CA par Boutique pour cet article :</h6>
                                            
                                            <?php if (empty($breakdown[$prod['product_id']])): ?>
                                                <span class="small text-secondary italic">Aucune information par boutique trouvée pour cette période.</span>
                                            <?php else: ?>
                                                <div class="row g-3">
                                                    <?php foreach ($breakdown[$prod['product_id']] as $bd): 
                                                        $bd_gain = $bd['rev'] - $bd['cogs'];
                                                    ?>
                                                        <div class="col-12 col-md-4">
                                                            <div class="p-3 border rounded text-center bg-light-subtle shadow-sm">
                                                                <div class="small fw-bold text-secondary mb-2"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($bd['shop_name']) ?></div>
                                                                <div class="d-flex justify-content-between text-start small border-bottom pb-1 mb-1">
                                                                    <span class="text-muted">Unités vendues:</span>
                                                                    <span class="fw-bold text-dark"><?= number_format($bd['qty'], 0, '.', ' ') ?> <?= htmlspecialchars($prod['unit']) ?></span>
                                                                </div>
                                                                <div class="d-flex justify-content-between text-start small border-bottom pb-1 mb-1">
                                                                    <span class="text-muted">Chiffre d'Affaires:</span>
                                                                    <span class="fw-bold text-primary"><?= number_format($bd['rev'], 0, '.', ' ') ?> F</span>
                                                                </div>
                                                                <div class="d-flex justify-content-between text-start small">
                                                                    <span class="text-muted fw-bold">Gain (Bénéfice):</span>
                                                                    <span class="fw-bold text-success">+<?= number_format($bd_gain, 0, '.', ' ') ?> F</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($active_tab === 'clients'): ?>
    <!-- 4. FINANCES PAR CLIENT (Rentabilité & Fidélité) -->
    <?php
    $top_ca_client = !empty($clients_financials) ? $clients_financials[0] : null;
    $top_margin_client = null;
    $top_debt_client = null;
    $max_m = -999999999;
    $max_d = 0;
    foreach ($clients_financials as $cf) {
        $cf_gain = $cf['total_revenue'] - $cf['total_cogs'];
        if ($cf_gain > $max_m) {
            $max_m = $cf_gain;
            $top_margin_client = $cf;
        }
        if ($cf['total_debt'] > $max_d) {
            $max_d = $cf['total_debt'];
            $top_debt_client = $cf;
        }
    }
    ?>

    <!-- KPIs Meilleurs Clients -->
    <div class="row g-3 mb-4">
        <!-- N°1 Chiffre d'Affaires -->
        <div class="col-12 col-md-4">
            <div class="glass-card p-3 h-100 border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="stat-label text-primary fw-bold"><i class="fa-solid fa-trophy me-1 text-warning"></i> N°1 Chiffre d'Affaires</span>
                    <span class="badge bg-primary bg-opacity-10 text-primary">Top Ventes</span>
                </div>
                <div class="fs-5 fw-bold text-slate-900 mt-1">
                    <?= htmlspecialchars($top_ca_client['client_name'] ?? 'Aucun') ?>
                </div>
                <div class="text-success fw-bold fs-5">
                    <?= number_format($top_ca_client['total_revenue'] ?? 0, 0, '.', ' ') ?> FCFA
                </div>
                <div class="small text-muted"><?= $top_ca_client['total_orders'] ?? 0 ?> commande(s) réalisée(s)</div>
            </div>
        </div>

        <!-- N°1 Marge Bénéficiaire -->
        <div class="col-12 col-md-4">
            <div class="glass-card p-3 h-100 border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="stat-label text-success fw-bold"><i class="fa-solid fa-gem me-1 text-success"></i> N°1 Plus Rentable</span>
                    <span class="badge bg-success bg-opacity-10 text-success">Top Marge</span>
                </div>
                <div class="fs-5 fw-bold text-slate-900 mt-1">
                    <?= htmlspecialchars($top_margin_client['client_name'] ?? 'Aucun') ?>
                </div>
                <div class="text-success fw-bold fs-5">
                    +<?= number_format($max_m > 0 ? $max_m : 0, 0, '.', ' ') ?> FCFA
                </div>
                <div class="small text-muted">
                    Marge brute : <?= $top_margin_client && $top_margin_client['total_revenue'] > 0 ? number_format(($max_m / $top_margin_client['total_revenue']) * 100, 1) : 0 ?> %
                </div>
            </div>
        </div>

        <!-- Plus Grosse Dette Client -->
        <div class="col-12 col-md-4">
            <div class="glass-card p-3 h-100 border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="stat-label text-danger fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i> Plus Grosse Créance</span>
                    <span class="badge bg-danger bg-opacity-10 text-danger">À recouvrer</span>
                </div>
                <div class="fs-5 fw-bold text-slate-900 mt-1">
                    <?= htmlspecialchars($top_debt_client['client_name'] ?? 'Aucun') ?>
                </div>
                <div class="text-danger fw-bold fs-5">
                    <?= number_format($max_d, 0, '.', ' ') ?> FCFA
                </div>
                <div class="small text-muted">
                    <?= $top_debt_client ? 'Tél : ' . htmlspecialchars($top_debt_client['client_phone'] ?: '--') : 'Aucun impayé' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tableau Comparatif Rentabilité par Client -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="glass-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-users text-primary me-2"></i>Performance Financière et Marge par Client</h5>
                    <div class="d-flex align-items-center gap-2 no-print">
                        <input type="text" id="clientSearchInput" class="form-control form-control-custom w-auto" placeholder="Filtrer un client...">
                        <button type="button" onclick="exportTableToExcel('clientsFinanceTable', 'finances_clients.csv')" class="btn btn-outline-success btn-sm fw-semibold" style="border-radius: 8px;">
                            <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel
                        </button>
                    </div>
                </div>

                <?php if (empty($clients_financials)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="fa-solid fa-users-slash fa-3x mb-3 text-muted"></i>
                        <p class="mb-0">Aucun achat client enregistré sur cette période.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-custom align-middle" id="clientsFinanceTable">
                            <thead>
                                <tr>
                                    <th>Client & Téléphone</th>
                                    <th class="text-center">Commandes</th>
                                    <th class="text-end">CA Réalisé (TTC)</th>
                                    <th class="text-end text-warning">Coût d'Achat (COGS)</th>
                                    <th class="text-end text-success">Marge Brute Dégagée</th>
                                    <th class="text-center">Taux de Marge</th>
                                    <th class="text-end text-primary">Total Payé</th>
                                    <th class="text-end text-danger">Créance (Dette)</th>
                                    <th class="text-center no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients_financials as $cf): 
                                    $cf_margin = $cf['total_revenue'] - $cf['total_cogs'];
                                    $cf_margin_pct = $cf['total_revenue'] > 0 ? ($cf_margin / $cf['total_revenue']) * 100 : 0;
                                    $has_debt = $cf['total_debt'] > 0;
                                ?>
                                    <tr class="client-row">
                                        <td>
                                            <div class="fw-bold text-slate-900"><?= htmlspecialchars($cf['client_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($cf['client_phone'] ?: '--') ?></small>
                                        </td>
                                        <td class="text-center fw-bold">
                                            <span class="badge bg-light text-dark border"><?= $cf['total_orders'] ?></span>
                                        </td>
                                        <td class="text-end fw-semibold text-slate-900">
                                            <?= number_format($cf['total_revenue'], 0, '.', ' ') ?> F
                                        </td>
                                        <td class="text-end text-warning">
                                            <?= number_format($cf['total_cogs'], 0, '.', ' ') ?> F
                                        </td>
                                        <td class="text-end fw-bold text-success">
                                            +<?= number_format($cf_margin, 0, '.', ' ') ?> F
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $cf_margin_pct >= 20 ? 'bg-success' : ($cf_margin_pct >= 10 ? 'bg-primary' : 'bg-secondary') ?> px-2 py-1 fw-bold" style="border-radius: 6px; font-size: 0.75rem;">
                                                <?= number_format($cf_margin_pct, 1) ?> %
                                            </span>
                                        </td>
                                        <td class="text-end text-primary fw-semibold">
                                            <?= number_format($cf['total_paid'], 0, '.', ' ') ?> F
                                        </td>
                                        <td class="text-end fw-bold <?= $has_debt ? 'text-danger' : 'text-muted' ?>">
                                            <?= $has_debt ? number_format($cf['total_debt'], 0, '.', ' ') . ' FCFA' : '<span class="text-success small"><i class="fa-solid fa-check"></i> Soldé</span>' ?>
                                        </td>
                                        <td class="text-center no-print">
                                            <a href="finance.php?tab=global&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&client_id=<?= $cf['client_id'] ?>" class="btn btn-outline-primary btn-xs py-1 px-2" style="border-radius: 6px; font-size: 0.75rem;" title="Filtrer le rapport complet sur ce client">
                                                <i class="fa-solid fa-filter me-1"></i>Isoler
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($active_tab === 'credits'): ?>
    <!-- 4. CREANCES & DETTES CLIENTS -->
    <div class="row g-4 mb-4">
        <!-- KPIs Créances -->
        <div class="col-12 col-md-6">
            <div class="glass-card p-4 h-100 border-start border-4 border-danger">
                <div class="stat-label">Total des Créances Clients (Encours)</div>
                <div class="stat-value text-danger mt-2"><?= number_format($total_credits_outstanding, 0, '.', ' ') ?> FCFA</div>
                <div class="small text-secondary mt-1">Somme totale des dettes restant à recouvrer pour Mandel Papeterie.</div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="glass-card p-4 h-100 border-start border-4 border-warning">
                <div class="stat-label">Factures en Attente de Paiement</div>
                <div class="stat-value text-warning mt-2"><?= count($credits_list) ?> facture(s)</div>
                <div class="small text-secondary mt-1">Dossiers clients ouverts non soldés.</div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="glass-card p-4">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-list-check text-danger me-2"></i>Détail des Factures à Crédit Client</h5>
                
                <?php if (empty($credits_list)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="fa-solid fa-circle-check fa-3x mb-3 text-success"></i>
                        <p class="mb-0 fw-bold text-success">Félicitations ! Aucun crédit client en cours sur cette période.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>N° Facture</th>
                                    <th>Date</th>
                                    <th>Boutique</th>
                                    <th>Client & Contact</th>
                                    <th>Total Facture</th>
                                    <th>Montant Réglé</th>
                                    <th class="text-end text-danger">Reste à Payer</th>
                                    <th class="text-center">Échéance</th>
                                    <th class="text-center">Statut</th>
                                    <th class="text-end no-print">Facture</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credits_list as $cr): 
                                    $due_timestamp = strtotime($cr['due_date']);
                                    $is_overdue = $due_timestamp < time() && $cr['payment_status'] !== 'PAID';
                                    $remaining = $cr['total_amount'] - $cr['amount_paid'];
                                ?>
                                    <tr class="<?= $is_overdue ? 'bg-danger bg-opacity-10 border-danger' : '' ?>">
                                        <td class="fw-bold text-slate-900"><?= htmlspecialchars($cr['reference'] ?? 'FAC-'.sprintf("%05d", $cr['order_id'])) ?></td>
                                        <td class="small text-secondary"><?= date('d/m/Y', strtotime($cr['created_at'])) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($cr['shop_name']) ?></span></td>
                                        <td>
                                            <div class="fw-bold text-slate-800"><?= htmlspecialchars($cr['client_name'] ?? 'Inconnu') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($cr['client_phone'] ?: '--') ?></small>
                                        </td>
                                        <td class="fw-semibold"><?= number_format($cr['total_amount'], 0, '.', ' ') ?> F</td>
                                        <td class="text-success small"><?= number_format($cr['amount_paid'], 0, '.', ' ') ?> F</td>
                                        <td class="text-end fw-bold text-danger"><?= number_format($remaining, 0, '.', ' ') ?> FCFA</td>
                                        <td class="text-center small <?= $is_overdue ? 'text-danger fw-bold' : 'text-secondary' ?>">
                                            <?= $cr['due_date'] ? date('d/m/Y', $due_timestamp) : '--' ?>
                                            <?= $is_overdue ? ' <span class="badge bg-danger ms-1" style="font-size: 0.65rem;">Dépassement</span>' : '' ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($cr['payment_status'] === 'UNPAID'): ?>
                                                <span class="badge bg-danger text-white px-2.5 py-1.5" style="border-radius: 6px; font-size: 0.75rem;">Impayé</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark px-2.5 py-1.5" style="border-radius: 6px; font-size: 0.75rem;">Partiel</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end no-print">
                                            <a href="invoice.php?id=<?= $cr['order_id'] ?>" class="btn btn-outline-primary btn-sm px-2.5" style="border-radius: 8px;" title="Facture PDF">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($active_tab === 'expenses'): ?>
    <!-- 5. REGISTRE DES CHARGES / SAISIE & JOURNAL -->
    <div class="row g-4 mb-5">
        <!-- Formulaire de saisie à gauche -->
        <div class="col-12 col-lg-5 no-print">
            <div class="glass-card p-4">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-plus-circle text-primary me-2"></i>Enregistrer une Charge</h5>
                
                <form action="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" method="POST" onsubmit="return confirm('Confirmer l\'enregistrement de cette dépense ?');">
                    <input type="hidden" name="add_expense" value="1">
                    
                    <div class="mb-3">
                        <label for="shop_id" class="form-label">Dépôt / Boutique associé</label>
                        <select class="form-select form-control-custom" id="shop_id" name="shop_id" required>
                            <option value="">-- Sélectionner la boutique --</option>
                            <?php foreach ($shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $active_shop_id ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="amount" class="form-label">Montant (FCFA)</label>
                            <input type="number" step="0.01" class="form-control form-control-custom" id="amount" name="amount" placeholder="Ex: 25000" min="1" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="expense_date" class="form-label">Date</label>
                            <input type="date" class="form-control form-control-custom" id="expense_date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="category" class="form-label mb-0">Catégorie de Charge *</label>
                            <a href="expense-categories.php" class="small text-primary text-decoration-none fw-semibold"><i class="fa-solid fa-plus-circle me-1"></i>Gérer les catégories</a>
                        </div>
                        <select class="form-select form-control-custom" id="category" name="category" required>
                            <option value="">-- Choisir une catégorie --</option>
                            <?php if (!empty($expense_categories)): ?>
                                <?php foreach ($expense_categories as $ecat): ?>
                                    <option value="<?= htmlspecialchars($ecat['name']) ?>">
                                        <?= htmlspecialchars($ecat['name']) ?><?= !empty($ecat['description']) ? ' (' . htmlspecialchars($ecat['description']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="LOYER">Loyer (Locaux)</option>
                                <option value="SALAIRES & PRIMES">Salaires & Commissions</option>
                                <option value="TAXI / TRANSPORT">Transport / Carburant / Logistique</option>
                                <option value="ELECTRICITE / ABONNEMENT">Électricité / Eau / Internet</option>
                                <option value="EMBALLAGE">Emballages / Conditionnement</option>
                                <option value="MAINTENANCE / REPARATION">Maintenance / Réparation</option>
                                <option value="AUTRE">Autres charges diverses</option>
                            <?php endif; ?>
                        </select>
                    </div>


                    <div class="mb-4">
                        <label for="notes" class="form-label">Justification / Commentaire</label>
                        <textarea class="form-control form-control-custom" id="notes" name="notes" rows="2" placeholder="Ex: Facture d'électricité de juin / Achat de carburant..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-grad w-100 py-2">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Enregistrer la Dépense
                    </button>
                </form>
            </div>
        </div>

        <!-- Tableau journal des charges à droite -->
        <div class="col-12 col-lg-7 print-full-width">
            <div class="glass-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Journal des Charges d'Exploitation</h5>
                    <div class="btn-group btn-group-sm no-print" role="group">
                        <a href="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>&expense_type=all" class="btn <?= $filter_expense_type === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">Toutes</a>
                        <a href="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>&expense_type=cash" class="btn <?= $filter_expense_type === 'cash' ? 'btn-primary' : 'btn-outline-secondary' ?>">Charges Réelles</a>
                        <a href="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>&expense_type=stock" class="btn <?= $filter_expense_type === 'stock' ? 'btn-primary' : 'btn-outline-secondary' ?>">Ajustements Stock</a>
                    </div>
                </div>

                <?php if ($stock_adjustments_loss > 0 && ($filter_expense_type === 'all' || $filter_expense_type === 'stock')): ?>
                    <div class="alert alert-warning border-0 p-3 mb-3 small d-flex justify-content-between align-items-center flex-wrap gap-2 no-print" style="border-radius: 10px;">
                        <div>
                            <i class="fa-solid fa-triangle-exclamation me-1 text-warning-emphasis"></i>
                            <strong>Avis de régularisation stock :</strong> <?= number_format($stock_adjustments_loss, 0, '.', ' ') ?> FCFA d'ajustements de stock sont présents dans ce registre suite à des corrections d'inventaire.
                        </div>
                        <form action="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" method="POST" onsubmit="return confirm('ATTENTION : Êtes-vous sûr de vouloir supprimer définitivement ces lignes d\'ajustements de stock pour restaurer votre bénéfice d\'exploitation ?');">
                            <input type="hidden" name="delete_inventory_loss_expenses" value="1">
                            <button type="submit" class="btn btn-outline-danger btn-xs py-1 px-2 fw-bold" style="border-radius: 6px;">
                                <i class="fa-solid fa-broom me-1"></i>Nettoyer ces ajustements
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($expenses_list)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="fa-solid fa-face-frown fa-3x mb-3 text-muted"></i>
                        <p class="mb-0">Aucune dépense enregistrée sur cette période.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom align-middle" id="financeExpensesTable">
                            <thead>

                                <tr>
                                    <th>Date</th>
                                    <th>Dépôt</th>
                                    <th>Catégorie</th>
                                    <th>Description / Justificatif</th>
                                    <th>Opérateur</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-end no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses_list as $exp): ?>
                                    <tr>
                                        <td class="small text-secondary fw-semibold">
                                            <?= date('d/m/Y', strtotime($exp['expense_date'])) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border fw-normal"><?= htmlspecialchars($exp['shop_name']) ?></span>
                                        </td>
                                        <td class="text-capitalize fw-semibold">
                                            <?php
                                                $cat_labels = [
                                                    'loyer' => 'Loyer',
                                                    'salaires' => 'Salaires & Comm.',
                                                    'transport' => 'Transport',
                                                    'electricite_internet' => 'Électricité & Web',
                                                    'pertes_avaries' => 'Pertes & Avaries',
                                                    'taxes' => 'Impôts & Taxes',
                                                    'autres' => 'Autres charges'
                                                ];
                                                echo htmlspecialchars($cat_labels[$exp['category']] ?? $exp['category']);
                                            ?>
                                        </td>
                                        <td class="small text-secondary" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($exp['notes']) ?>">
                                            <?= htmlspecialchars($exp['notes']) ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= htmlspecialchars($exp['user_name']) ?></small>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            -<?= number_format($exp['amount'], 0, '.', ' ') ?> FCFA
                                        </td>
                                        <td class="text-end no-print">
                                            <form action="finance.php?tab=expenses&date_start=<?= $date_start ?>&date_end=<?= $date_end ?>&shop_id=<?= $filter_shop_id ?>&client_id=<?= $filter_client_id ?>" method="POST" onsubmit="return confirm('Voulez-vous vraiment supprimer cette dépense du journal ?');" class="d-inline">
                                                <input type="hidden" name="delete_expense" value="1">
                                                <input type="hidden" name="delete_expense_id" value="<?= $exp['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;" title="Supprimer">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Graphique répartition des charges (Tab Global)
    <?php if ($active_tab === 'global' && !empty($charges_by_cat)): ?>
        const expCtx = document.getElementById('expensesCategoriesChart').getContext('2d');
        const expLabels = <?= json_encode(array_map(function($c) {
            $cat_labels = [
                'loyer' => 'Loyer',
                'salaires' => 'Salaires',
                'transport' => 'Transport',
                'electricite_internet' => 'Électricité & Web',
                'pertes_avaries' => 'Pertes & Avaries',
                'taxes' => 'Impôts',
                'autres' => 'Autres'
            ];
            return $cat_labels[$c['category']] ?? $c['category'];
        }, $charges_by_cat)) ?>;
        const expValues = <?= json_encode(array_map(function($c) { return floatval($c['total_cat']); }, $charges_by_cat)) ?>;

        new Chart(expCtx, {
            type: 'doughnut',
            data: {
                labels: expLabels,
                datasets: [{
                    data: expValues,
                    backgroundColor: [
                        '#ef4444', // Rouge
                        '#f59e0b', // Jaune
                        '#3b82f6', // Bleu
                        '#10b981', // Vert
                        '#8b5cf6', // Violet
                        '#06b6d4', // Cyan
                        '#64748b'  // Slate
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: { size: 11, family: 'Outfit, Inter' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return ` ${context.label}: ${val.toLocaleString('fr-FR')} FCFA`;
                            }
                        }
                    }
                }
            }
        });
    <?php endif; ?>

    // 2. Graphique comparatif performance boutiques (Tab Boutiques)
    <?php if ($active_tab === 'shops' && !empty($shop_stats_list)): ?>
        const shopCtx = document.getElementById('shopPerformanceChart').getContext('2d');
        const shopLabels = <?= json_encode(array_map(function($s) { return $s['name']; }, $shop_stats_list)) ?>;
        const shopSales = <?= json_encode(array_map(function($s) { return floatval($s['sales']); }, $shop_stats_list)) ?>;
        const shopNetProfit = <?= json_encode(array_map(function($s) { return floatval($s['net_profit']); }, $shop_stats_list)) ?>;

        new Chart(shopCtx, {
            type: 'bar',
            data: {
                labels: shopLabels,
                datasets: [
                    {
                        label: 'Chiffre d\'Affaires (CA)',
                        data: shopSales,
                        backgroundColor: 'rgba(59, 130, 246, 0.85)', // Bleu
                        borderRadius: 6
                    },
                    {
                        label: 'Bénéfice Net',
                        data: shopNetProfit,
                        backgroundColor: 'rgba(16, 185, 129, 0.85)', // Vert
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: { size: 11, family: 'Outfit, Inter' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return ` ${context.dataset.label}: ${val.toLocaleString('fr-FR')} FCFA`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, family: 'Outfit, Inter' } }
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.03)' },
                        ticks: {
                            font: { size: 10, family: 'Outfit, Inter' },
                            callback: function(value) {
                                return value.toLocaleString('fr-FR') + ' FCFA';
                            }
                        }
                    }
                }
            }
        });
    <?php endif; ?>
});
</script>

<!-- MODAL VENTES TOTALES (CA) -->
<div class="modal fade no-print" id="salesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-slate-800"><i class="fa-solid fa-list-ul text-success me-2"></i>Détail des Ventes (CA)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-3">Liste de toutes les factures de ventes ayant généré le Chiffre d'Affaires de la période.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" style="font-size: 0.9rem;">
                        <thead class="table-light">
                            <tr>
                                <th>N° Facture</th>
                                <th>Date & Heure</th>
                                <th>Boutique</th>
                                <th>Client</th>
                                <th class="text-end">Montant Total</th>
                                <th class="text-end">Montant Payé</th>
                                <th class="text-center">Statut Pay.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sales_details)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">Aucune vente trouvée.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sales_details as $s): 
                                    $is_paid = ($s['total_amount'] <= $s['amount_paid']);
                                ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($s['reference'] ?? 'FAC-'.sprintf("%05d", $s['id'])) ?></td>
                                        <td class="small text-secondary"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($s['shop_name']) ?></td>
                                        <td><?= htmlspecialchars($s['client_name'] ?? 'Client Standard') ?></td>
                                        <td class="text-end fw-bold text-slate-900"><?= number_format($s['total_amount'], 0, '.', ' ') ?> F</td>
                                        <td class="text-end text-success"><?= number_format($s['amount_paid'], 0, '.', ' ') ?> F</td>
                                        <td class="text-center">
                                            <?php if ($is_paid): ?>
                                                <span class="badge bg-success-subtle text-success">Payé</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger">Crédit</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL LIQUIDITÉS ENCAISSÉES -->
<div class="modal fade no-print" id="cashModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-slate-800"><i class="fa-solid fa-hand-holding-dollar text-success me-2"></i>Détail de la Trésorerie Encaissée</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-3">Liste des entrées de liquidités réelles validées en caisse.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" style="font-size: 0.9rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Boutique</th>
                                <th>Motif / Origine</th>
                                <th>Opérateur</th>
                                <th class="text-end">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cash_details)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Aucun encaissement trouvé.</td></tr>
                            <?php else: ?>
                                <?php foreach ($cash_details as $c): ?>
                                    <tr>
                                        <td class="small text-secondary"><?= date('d/m/Y H:i', strtotime($c['transaction_date'])) ?></td>
                                        <td><?= htmlspecialchars($c['shop_name']) ?></td>
                                        <td><?= htmlspecialchars($c['notes']) ?></td>
                                        <td><?= htmlspecialchars($c['user_name']) ?></td>
                                        <td class="text-end fw-bold text-success">+<?= number_format($c['amount'], 0, '.', ' ') ?> F</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const clientSearch = document.getElementById('clientSearchInput');
    if (clientSearch) {
        clientSearch.addEventListener('input', function() {
            const query = clientSearch.value.toLowerCase().trim();
            document.querySelectorAll('#clientsFinanceTable .client-row').forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
