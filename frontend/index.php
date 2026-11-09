<?php
require_once __DIR__ . '/includes/header.php';

$active_shop_id = $_SESSION['active_shop_id'];
$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// --- STATISTIQUES COMMUNES / RÔLES ---

// 1. Nombre total de produits dans le catalogue
$stmt_total_catalog = $pdo->query("SELECT COUNT(*) as count FROM products");
$total_catalog = $stmt_total_catalog->fetch()['count'];

// 2. Quantité totale d'articles en stock (Global ou par boutique)
if ($active_shop_id == 0) {
    $stmt_total_qty = $pdo->query("SELECT SUM(quantity) as sum FROM product_stock");
    $total_qty = $stmt_total_qty->fetch()['sum'] ?? 0;

    $stmt_alerts_count = $pdo->query("
        SELECT COUNT(DISTINCT p.id) as count 
        FROM products p 
        JOIN product_stock s ON p.id = s.product_id 
        WHERE s.quantity <= p.min_stock_level
    ");
    $alerts_count = $stmt_alerts_count->fetch()['count'];

    $stmt_healthy_count = $pdo->query("
        SELECT COUNT(DISTINCT p.id) as count 
        FROM products p 
        JOIN product_stock s ON p.id = s.product_id 
        WHERE s.quantity > p.min_stock_level
    ");
    $healthy_count = $stmt_healthy_count->fetch()['count'];
} else {
    $stmt_total_qty = $pdo->prepare("SELECT SUM(quantity) as sum FROM product_stock WHERE shop_id = ?");
    $stmt_total_qty->execute([$active_shop_id]);
    $total_qty = $stmt_total_qty->fetch()['sum'] ?? 0;

    $stmt_alerts_count = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM products p 
        JOIN product_stock s ON p.id = s.product_id 
        WHERE s.shop_id = ? AND s.quantity <= p.min_stock_level
    ");
    $stmt_alerts_count->execute([$active_shop_id]);
    $alerts_count = $stmt_alerts_count->fetch()['count'];

    $stmt_healthy_count = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM products p 
        JOIN product_stock s ON p.id = s.product_id 
        WHERE s.shop_id = ? AND s.quantity > p.min_stock_level
    ");
    $stmt_healthy_count->execute([$active_shop_id]);
    $healthy_count = $stmt_healthy_count->fetch()['count'];
}

$total_active_stock_lines = $healthy_count + $alerts_count;
$healthy_pct = $total_active_stock_lines > 0 ? round(($healthy_count / $total_active_stock_lines) * 100) : 100;

// --- STATISTIQUES EXCLUSIVES ADMIN ---
$value_purchase = 0;
$value_sale = 0;
if ($user_role === 'admin') {
    if ($active_shop_id == 0) {
        $stmt_value_purchase = $pdo->query("
            SELECT SUM(p.price_purchase * s.quantity) as sum 
            FROM products p 
            JOIN product_stock s ON p.id = s.product_id
        ");
        $value_purchase = $stmt_value_purchase->fetch()['sum'] ?? 0;

        $stmt_value_sale = $pdo->query("
            SELECT SUM(p.price_sale * s.quantity) as sum 
            FROM products p 
            JOIN product_stock s ON p.id = s.product_id
        ");
        $value_sale = $stmt_value_sale->fetch()['sum'] ?? 0;
    } else {
        $stmt_value_purchase = $pdo->prepare("
            SELECT SUM(p.price_purchase * s.quantity) as sum 
            FROM products p 
            JOIN product_stock s ON p.id = s.product_id 
            WHERE s.shop_id = ?
        ");
        $stmt_value_purchase->execute([$active_shop_id]);
        $value_purchase = $stmt_value_purchase->fetch()['sum'] ?? 0;

        $stmt_value_sale = $pdo->prepare("
            SELECT SUM(p.price_sale * s.quantity) as sum 
            FROM products p 
            JOIN product_stock s ON p.id = s.product_id 
            WHERE s.shop_id = ?
        ");
        $stmt_value_sale->execute([$active_shop_id]);
        $value_sale = $stmt_value_sale->fetch()['sum'] ?? 0;
    }

    // Commandes exceptionnelles en attente de validation
    $stmt_pending = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'PENDING_APPROVAL'");
    $stmt_pending->execute();
    $pending_count = $stmt_pending->fetchColumn();
} else {
    $pending_count = 0;
}

// --- STATISTIQUES EXCLUSIVES VENDEUR ---
$today_sales = 0;
$seller_credits = 0;
$seller_overdue_credits = 0;
if ($user_role === 'seller') {
    // Chiffre d'affaires réalisé par ce vendeur aujourd'hui dans cette boutique
    $stmt_today_sales = $pdo->prepare("
        SELECT SUM(total_amount) as sum 
        FROM orders 
        WHERE type = 'SALE' AND user_id = ? AND shop_id = ? AND DATE(created_at) = CURDATE()
    ");
    $stmt_today_sales->execute([$user_id, $active_shop_id]);
    $today_sales = $stmt_today_sales->fetch()['sum'] ?? 0;

    // Total des créances clients en cours pour cette boutique
    $stmt_credits = $pdo->prepare("
        SELECT 
            SUM(total_amount - amount_paid) as remaining,
            SUM(CASE WHEN due_date < CURDATE() THEN (total_amount - amount_paid) ELSE 0 END) as overdue
        FROM orders 
        WHERE type = 'SALE' AND payment_method = 'CREDIT' AND status = 'COMPLETED' 
          AND payment_status IN ('UNPAID', 'PARTIAL') AND shop_id = ?
    ");
    $stmt_credits->execute([$active_shop_id]);
    $cr_data = $stmt_credits->fetch();
    $seller_credits = floatval($cr_data['remaining'] ?? 0);
    $seller_overdue_credits = floatval($cr_data['overdue'] ?? 0);
}

// Récupérer le message d'erreur éventuel de session
$session_error = '';
if (isset($_SESSION['error_message'])) {
    $session_error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!-- Title Banner -->
<div class="row mb-3">
    <div class="col-12">
        <?php if ($session_error): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-3" style="border-radius: 12px;" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $session_error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
            <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
                Bonjour, <?= htmlspecialchars($_SESSION['user_name']) ?> 👋
            </h1>
            <span class="text-secondary small fw-semibold">
                Dépôt connecté : <span class="badge bg-light text-dark border"><?= htmlspecialchars($current_shop['name']) ?></span>
                <?php if ($user_role === 'admin'): ?>
                    <a href="stats-stock.php" class="ms-2 badge bg-primary text-decoration-none py-1 px-2 fw-semibold" style="border-radius: 6px;">
                        <i class="fa-solid fa-chart-pie me-1"></i> Toutes boutiques
                    </a>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($pending_count > 0): ?>
            <div class="alert alert-warning border-0 shadow-sm p-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="border-radius: 12px;">
                <div class="d-flex align-items-center">
                    <div class="bg-warning bg-opacity-20 text-warning p-2 rounded-3 me-2"><i class="fa-solid fa-bell fa-bounce"></i></div>
                    <h6 class="fw-bold mb-0 text-dark small"><?= $pending_count ?> commande(s) hors-marge en attente de validation</h6>
                </div>
                <a href="orders.php" class="btn btn-warning btn-sm fw-bold px-3 py-1" style="border-radius: 8px;">
                    <i class="fa-solid fa-shield-halved me-1"></i>Valider
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Barre Indicateurs Compacte (1 Seule Ligne) -->
<?php if ($user_role === 'admin'): ?>
<div class="glass-card px-3 py-2 mb-3 fade-in-up">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 text-nowrap" style="font-size: 0.82rem;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary p-1.5 rounded-2"><i class="fa-solid fa-box"></i></span>
            <span class="text-secondary small">Catalogue:</span>
            <span class="fw-bold text-slate-800"><?= number_format($total_catalog, 0, '.', ' ') ?></span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary p-1.5 rounded-2"><i class="fa-solid fa-cubes"></i></span>
            <span class="text-secondary small"><?= $active_shop_id == 0 ? 'Stock Global:' : 'Stock Dépôt:' ?></span>
            <span class="fw-bold text-slate-800"><?= number_format($total_qty, 0, '.', ' ') ?></span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary p-1.5 rounded-2"><i class="fa-solid fa-wallet"></i></span>
            <span class="text-secondary small">Valeur Achat:</span>
            <span class="fw-bold text-slate-800"><?= number_format($value_purchase, 0, '.', ' ') ?> F</span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-warning-subtle text-dark p-1.5 rounded-2"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="text-secondary small">À réapprovisionner:</span>
            <span class="fw-bold text-slate-800"><?= number_format($alerts_count, 0, '.', ' ') ?></span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-success-subtle text-success p-1.5 rounded-2"><i class="fa-solid fa-heart-pulse"></i></span>
            <span class="text-secondary small">Disponibilité:</span>
            <span class="fw-bold text-success"><?= $healthy_pct ?>%</span>
        </div>
    </div>
</div>
<?php else: ?>
<!-- STATS VENDEUR - Bandeau compact 1 ligne -->
<div class="glass-card px-3 py-2 mb-3 fade-in-up">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 text-nowrap" style="font-size: 0.82rem;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success-subtle text-success p-1.5 rounded-2"><i class="fa-solid fa-coins"></i></span>
            <span class="text-secondary small">Mes Ventes du Jour:</span>
            <span class="fw-bold text-success"><?= number_format($today_sales, 0, '.', ' ') ?> F</span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary p-1.5 rounded-2"><i class="fa-solid fa-hand-holding-dollar"></i></span>
            <span class="text-secondary small">Crédits en cours:</span>
            <span class="fw-bold text-slate-800"><?= number_format($seller_credits, 0, '.', ' ') ?> F</span>
        </div>
        <div class="border-start ps-2 d-flex align-items-center gap-2">
            <span class="badge bg-warning-subtle text-dark p-1.5 rounded-2"><i class="fa-solid fa-boxes-stacked"></i></span>
            <span class="text-secondary small">Articles à réapprovisionner:</span>
            <span class="fw-bold text-slate-800"><?= number_format($alerts_count, 0, '.', ' ') ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Navigation Cards -->
<h6 class="fw-bold text-secondary text-uppercase small mb-2 letter-spacing-1"><i class="fa-solid fa-compass me-2"></i>Navigation rapide</h6>

<div class="row g-2">
    <?php if ($user_role === 'admin'): ?>
        <!-- 1. Caisse & Opérations -->
        <div class="col-12 col-md-6 col-lg-4 fade-in-up delay-1">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="btn-grad p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-cash-register fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Caisse & Opérations</h6>
                </div>
                <div class="mt-auto d-flex gap-2 w-100">
                    <a href="order-create.php?type=SALE" class="btn btn-grad py-2 px-3 flex-grow-1 fw-bold fs-6 d-flex align-items-center justify-content-center gap-1" style="border-radius: 8px;">
                        <i class="fa-solid fa-cart-shopping me-1"></i>Vente
                    </a>
                    <a href="order-create.php?type=PURCHASE" class="btn btn-accent py-2 px-3 flex-grow-1 fw-bold fs-6 d-flex align-items-center justify-content-center gap-1" style="border-radius: 8px;">
                        <i class="fa-solid fa-download me-1"></i>Achat
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Catalogue & Stocks -->
        <div class="col-12 col-md-6 col-lg-4 fade-in-up delay-2">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="bg-primary-subtle text-primary p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-boxes-stacked fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Catalogue & Stocks</h6>
                </div>
                <div class="mt-auto d-flex flex-wrap gap-2 w-100">
                    <a href="products.php" class="btn btn-outline-success py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-list me-1"></i>Articles
                    </a>
                    <a href="stock-conversion.php" class="btn btn-outline-info text-dark py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-arrows-spin me-1"></i>Déconditionner
                    </a>
                    <a href="stats-stock.php" class="btn btn-outline-warning text-dark py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-chart-pie me-1"></i>État & Stats Stock
                    </a>
                </div>
            </div>
        </div>

        <!-- 3. Historique & Finance -->
        <div class="col-12 col-md-6 col-lg-4 fade-in-up delay-3">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="bg-success-subtle text-success p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-chart-line fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Historique & Finance</h6>
                </div>
                <div class="mt-auto d-flex flex-wrap gap-2 w-100">
                    <a href="orders.php" class="btn btn-outline-secondary py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-receipt me-1"></i>Journal
                    </a>
                    <a href="reports.php" class="btn btn-outline-success py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-chart-pie me-1"></i>Analyses
                    </a>
                    <a href="finance.php" class="btn btn-outline-primary py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-calculator me-1"></i>Finance
                    </a>
                    <a href="credits.php" class="btn btn-outline-warning text-dark py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-hand-holding-dollar me-1"></i>Crédits
                    </a>
                </div>
            </div>
        </div>

        <!-- 4. Contacts -->
        <div class="col-12 col-md-6 fade-in-up delay-4">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="bg-warning-subtle text-warning p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-users fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Annuaire Contacts</h6>
                </div>
                <div class="mt-auto">
                    <a href="contacts.php" class="btn btn-outline-warning text-dark py-2 px-3 w-100 fw-bold" style="border-radius: 8px;">
                        <i class="fa-solid fa-address-book me-2"></i>Gérer les Clients & Fournisseurs
                    </a>
                </div>
            </div>
        </div>

        <!-- 5. Paramètres -->
        <div class="col-12 col-md-6 fade-in-up delay-5">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="bg-danger-subtle text-danger p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-gears fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Paramètres Généraux</h6>
                </div>
                <div class="mt-auto d-flex gap-2 w-100">
                    <a href="shops.php" class="btn btn-outline-info text-dark py-2 px-3 flex-grow-1 fw-bold" style="border-radius: 8px;">
                        <i class="fa-solid fa-store me-1"></i>Dépôts
                    </a>
                    <a href="users.php" class="btn btn-outline-danger py-2 px-3 flex-grow-1 fw-bold" style="border-radius: 8px;">
                        <i class="fa-solid fa-users-gear me-1"></i>Vendeurs
                    </a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- CARTES VENDEUR - même style compact que l'admin -->

        <!-- 1. Nouvelle Vente -->
        <div class="col-12 col-md-4 fade-in-up delay-1">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="btn-grad p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-cart-shopping fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Nouvelle Vente</h6>
                </div>
                <div class="mt-auto">
                    <a href="order-create.php?type=SALE" class="btn btn-grad py-2.5 px-3 w-100 fw-bold fs-6 d-flex align-items-center justify-content-center gap-2" style="border-radius: 8px;">
                        <i class="fa-solid fa-cart-shopping me-1"></i>Enregistrer une Vente (Caisse)
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Catalogue & Stocks -->
        <div class="col-12 col-md-4 fade-in-up delay-2">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="btn-accent p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-boxes-stacked fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Catalogue & Stocks</h6>
                </div>
                <div class="mt-auto d-flex gap-2 w-100">
                    <a href="products.php" class="btn btn-outline-success py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>Consulter Stock
                    </a>
                    <a href="stock-conversion.php" class="btn btn-outline-primary py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-arrows-spin me-1"></i>Déconditionner
                    </a>
                </div>
            </div>
        </div>

        <!-- 3. Ventes & Analyses -->
        <div class="col-12 col-md-4 fade-in-up delay-3">
            <div class="glass-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="bg-success-subtle text-success p-2 rounded-3 flex-shrink-0"><i class="fa-solid fa-file-invoice-dollar fa-lg"></i></div>
                    <h6 class="fw-bold text-slate-900 mb-0">Ventes, Crédits & Analyses</h6>
                </div>
                <div class="mt-auto d-flex flex-wrap gap-2 w-100">
                    <a href="orders.php?view=mine" class="btn btn-outline-secondary py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-receipt me-1"></i>Mes Ventes
                    </a>
                    <a href="credits.php" class="btn btn-outline-warning text-dark py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-hand-holding-dollar me-1"></i>Créances
                    </a>
                    <a href="reports.php" class="btn btn-outline-success py-2 px-3 flex-grow-1 fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-chart-pie me-1"></i>Analyses
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
