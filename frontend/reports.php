<?php
require_once __DIR__ . '/includes/header.php';

$active_shop_id = $_SESSION['active_shop_id'];
$user_role = $_SESSION['user_role'];

// Configuration des dates par défaut (du 1er du mois en cours à aujourd'hui)
$default_start = date('Y-m-01');
$default_end = date('Y-m-d');

$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : $default_start;
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : $default_end;
$filter_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : 0; // 0 = Toutes
$filter_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0; // 0 = Tous

// Sécurité : forcer la boutique et l'utilisateur pour le vendeur
if ($user_role !== 'admin') {
    $filter_shop_id = $active_shop_id;
    $filter_user_id = $_SESSION['user_id'];
}

// Charger les listes pour filtres (Admin uniquement)
$shops = [];
$sellers = [];
if ($user_role === 'admin') {
    $shops = $pdo->query("SELECT id, name FROM shops ORDER BY name ASC")->fetchAll();
    $sellers = $pdo->query("SELECT id, name FROM users WHERE role = 'seller' ORDER BY name ASC")->fetchAll();
}

// --- 1. CALCULS DES KPIs ---
$kpis_sql = "
    SELECT 
        SUM(CASE WHEN o.type = 'SALE' THEN o.total_amount ELSE 0 END) as total_sales,
        SUM(CASE WHEN o.type = 'PURCHASE' THEN o.total_amount ELSE 0 END) as total_purchases,
        COUNT(CASE WHEN o.type = 'SALE' THEN 1 END) as sales_count,
        SUM(CASE WHEN o.type = 'SALE' THEN (
            SELECT COALESCE(SUM(oi.quantity * p.price_purchase), 0)
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = o.id
        ) ELSE 0 END) as total_purchase_cost
    FROM orders o
    WHERE o.status = 'COMPLETED'
";
$kpis_params = [];

if ($user_role !== 'admin') {
    $kpis_sql .= " AND o.shop_id = :shop_id AND o.user_id = :user_id";
    $kpis_params[':shop_id'] = $filter_shop_id;
    $kpis_params[':user_id'] = $filter_user_id;
} else {
    if ($filter_shop_id > 0) {
        $kpis_sql .= " AND o.shop_id = :shop_id";
        $kpis_params[':shop_id'] = $filter_shop_id;
    }
    if ($filter_user_id > 0) {
        $kpis_sql .= " AND o.user_id = :user_id";
        $kpis_params[':user_id'] = $filter_user_id;
    }
}

if ($date_start) {
    $kpis_sql .= " AND DATE(o.created_at) >= :date_start";
    $kpis_params[':date_start'] = $date_start;
}
if ($date_end) {
    $kpis_sql .= " AND DATE(o.created_at) <= :date_end";
    $kpis_params[':date_end'] = $date_end;
}

$stmt = $pdo->prepare($kpis_sql);
$stmt->execute($kpis_params);
$kpis = $stmt->fetch();

$total_sales = floatval($kpis['total_sales'] ?? 0);
$total_purchases = floatval($kpis['total_purchases'] ?? 0);
$sales_count = intval($kpis['sales_count'] ?? 0);
$total_purchase_cost = floatval($kpis['total_purchase_cost'] ?? 0);

$gross_margin = $total_sales - $total_purchase_cost;
$margin_pct = $total_sales > 0 ? ($gross_margin / $total_sales) * 100 : 0;
$basket_average = $sales_count > 0 ? $total_sales / $sales_count : 0;


// --- 2. GRAPH 1 : COMPARAISON DES BOUTIQUES (Admin uniquement) ---
$shop_data = [];
if ($user_role === 'admin') {
    $shop_sql = "
        SELECT sh.name as shop_name, SUM(o.total_amount) as total_sales
        FROM orders o
        JOIN shops sh ON o.shop_id = sh.id
        WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
    ";
    $shop_params = [];
    if ($date_start) {
        $shop_sql .= " AND DATE(o.created_at) >= :date_start";
        $shop_params[':date_start'] = $date_start;
    }
    if ($date_end) {
        $shop_sql .= " AND DATE(o.created_at) <= :date_end";
        $shop_params[':date_end'] = $date_end;
    }
    $shop_sql .= " GROUP BY o.shop_id ORDER BY total_sales DESC";
    
    $stmt = $pdo->prepare($shop_sql);
    $stmt->execute($shop_params);
    $shop_data = $stmt->fetchAll();
}


// --- 3. GRAPH 2 : TOP 5 PRODUITS VENDUS ---
$top_prod_sql = "
    SELECT p.name as product_name, SUM(oi.quantity) as qty_sold, SUM(oi.total_price) as total_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
";
$top_prod_params = [];

if ($user_role !== 'admin') {
    $top_prod_sql .= " AND o.shop_id = :shop_id AND o.user_id = :user_id";
    $top_prod_params[':shop_id'] = $filter_shop_id;
    $top_prod_params[':user_id'] = $filter_user_id;
} else {
    if ($filter_shop_id > 0) {
        $top_prod_sql .= " AND o.shop_id = :shop_id";
        $top_prod_params[':shop_id'] = $filter_shop_id;
    }
    if ($filter_user_id > 0) {
        $top_prod_sql .= " AND o.user_id = :user_id";
        $top_prod_params[':user_id'] = $filter_user_id;
    }
}
if ($date_start) {
    $top_prod_sql .= " AND DATE(o.created_at) >= :date_start";
    $top_prod_params[':date_start'] = $date_start;
}
if ($date_end) {
    $top_prod_sql .= " AND DATE(o.created_at) <= :date_end";
    $top_prod_params[':date_end'] = $date_end;
}
$top_prod_sql .= " GROUP BY oi.product_id ORDER BY qty_sold DESC LIMIT 5";

$stmt = $pdo->prepare($top_prod_sql);
$stmt->execute($top_prod_params);
$top_products = $stmt->fetchAll();


// --- 4. GRAPH 3 : FLOP 5 PRODUITS MOINS VENDUS ---
$flop_prod_sql = "
    SELECT p.name as product_name, 
           COALESCE((
               SELECT SUM(oi.quantity) 
               FROM order_items oi 
               JOIN orders o ON oi.order_id = o.id 
               WHERE oi.product_id = p.id 
                 AND o.status = 'COMPLETED' 
                 AND o.type = 'SALE'
";
$flop_prod_params = [];

if ($user_role !== 'admin') {
    $flop_prod_sql .= " AND o.shop_id = :shop_id AND o.user_id = :user_id";
    $flop_prod_params[':shop_id'] = $filter_shop_id;
    $flop_prod_params[':user_id'] = $filter_user_id;
} else {
    if ($filter_shop_id > 0) {
        $flop_prod_sql .= " AND o.shop_id = :shop_id";
        $flop_prod_params[':shop_id'] = $filter_shop_id;
    }
    if ($filter_user_id > 0) {
        $flop_prod_sql .= " AND o.user_id = :user_id";
        $flop_prod_params[':user_id'] = $filter_user_id;
    }
}
if ($date_start) {
    $flop_prod_sql .= " AND DATE(o.created_at) >= :date_start";
    $flop_prod_params[':date_start'] = $date_start;
}
if ($date_end) {
    $flop_prod_sql .= " AND DATE(o.created_at) <= :date_end";
    $flop_prod_params[':date_end'] = $date_end;
}

$flop_prod_sql .= "           ), 0) as qty_sold
    FROM products p
    ORDER BY qty_sold ASC 
    LIMIT 5
";

$stmt = $pdo->prepare($flop_prod_sql);
$stmt->execute($flop_prod_params);
$flop_products = $stmt->fetchAll();


// --- 5. GRAPH 4 : ÉVOLUTION TEMPORELLE DES VENTES ---
$evo_sql = "
    SELECT DATE(o.created_at) as sale_date, SUM(o.total_amount) as daily_sales
    FROM orders o
    WHERE o.type = 'SALE' AND o.status = 'COMPLETED'
";
$evo_params = [];

if ($user_role !== 'admin') {
    $evo_sql .= " AND o.shop_id = :shop_id AND o.user_id = :user_id";
    $evo_params[':shop_id'] = $filter_shop_id;
    $evo_params[':user_id'] = $filter_user_id;
} else {
    if ($filter_shop_id > 0) {
        $evo_sql .= " AND o.shop_id = :shop_id";
        $evo_params[':shop_id'] = $filter_shop_id;
    }
    if ($filter_user_id > 0) {
        $evo_sql .= " AND o.user_id = :user_id";
        $evo_params[':user_id'] = $filter_user_id;
    }
}
if ($date_start) {
    $evo_sql .= " AND DATE(o.created_at) >= :date_start";
    $evo_params[':date_start'] = $date_start;
}
if ($date_end) {
    $evo_sql .= " AND DATE(o.created_at) <= :date_end";
    $evo_params[':date_end'] = $date_end;
}
$evo_sql .= " GROUP BY DATE(o.created_at) ORDER BY sale_date ASC";

$stmt = $pdo->prepare($evo_sql);
$stmt->execute($evo_params);
$evo_data = $stmt->fetchAll();
?>

<!-- Titre & Entête -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-chart-pie"></i></span> 
            Analyses & Statistiques
        </h1>
        <div class="d-flex align-items-center gap-2 no-print">
            <span class="text-secondary small fw-semibold d-none d-md-inline">Chiffre d'affaires et rentabilité</span>
            <button type="button" onclick="exportTableToExcel('reportsSummaryTable', 'rapport_statistiques.csv')" class="btn btn-outline-success btn-sm fw-semibold" style="border-radius: 8px;">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel
            </button>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                <i class="fa-solid fa-print me-1"></i>Imprimer
            </button>
        </div>

    </div>
</div>

<!-- Filtres de Période & Scope -->
<div class="row mb-4">
    <div class="col-12">
        <div class="glass-card p-4">
            <form method="GET" action="reports.php" class="row g-3">
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="date_start" class="form-label small fw-semibold text-secondary">Du (Date début)</label>
                    <input type="date" class="form-control form-control-custom" id="date_start" name="date_start" value="<?= htmlspecialchars($date_start) ?>">
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="date_end" class="form-label small fw-semibold text-secondary">Au (Date fin)</label>
                    <input type="date" class="form-control form-control-custom" id="date_end" name="date_end" value="<?= htmlspecialchars($date_end) ?>">
                </div>

                <?php if ($user_role === 'admin'): ?>
                    <div class="col-12 col-sm-6 col-md-2">
                        <label for="shop_id" class="form-label small fw-semibold text-secondary">Boutique / Dépôt</label>
                        <select class="form-select form-control-custom" id="shop_id" name="shop_id">
                            <option value="0">Toutes les boutiques</option>
                            <?php foreach ($shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $filter_shop_id ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-md-2">
                        <label for="user_id" class="form-label small fw-semibold text-secondary">Vendeur / Opérateur</label>
                        <select class="form-select form-control-custom" id="user_id" name="user_id">
                            <option value="0">Tous les vendeurs</option>
                            <?php foreach ($sellers as $sel): ?>
                                <option value="<?= $sel['id'] ?>" <?= $sel['id'] == $filter_user_id ? 'selected' : '' ?>><?= htmlspecialchars($sel['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary w-100 py-2" style="border-radius: 10px;">
                        <i class="fa-solid fa-sync me-1"></i>Actualiser
                    </button>
                    <a href="reports.php" class="btn btn-outline-secondary py-2 px-3" style="border-radius: 10px;" title="Réinitialiser">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- KPIs Row -->
<div class="row g-4 mb-5">
    <!-- CA Ventes -->
    <div class="col-12 col-sm-6 col-md-3">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="stat-label">Chiffre d'Affaires</span>
                <div class="bg-success-subtle text-success p-2 rounded-3"><i class="fa-solid fa-coins fa-lg"></i></div>
            </div>
            <div class="stat-value text-success"><?= number_format($total_sales, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
            <div class="small text-secondary mt-1">Cumul des ventes validées</div>
        </div>
    </div>

    <!-- Marge Brute Estimée (Admin uniquement) -->
    <?php if ($user_role === 'admin'): ?>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="stat-label">Marge Brute</span>
                    <div class="bg-primary-subtle text-primary p-2 rounded-3"><i class="fa-solid fa-sack-dollar fa-lg"></i></div>
                </div>
                <div class="stat-value text-primary"><?= number_format($gross_margin, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
                <div class="small text-secondary mt-1">Marge estimée : <strong><?= number_format($margin_pct, 1) ?> %</strong></div>
            </div>
        </div>
    <?php else: ?>
        <!-- Pour le vendeur, on montre le CA des achats ou le panier moyen -->
        <div class="col-12 col-sm-6 col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="stat-label">Panier Moyen</span>
                    <div class="bg-primary-subtle text-primary p-2 rounded-3"><i class="fa-solid fa-basket-shopping fa-lg"></i></div>
                </div>
                <div class="stat-value"><?= number_format($basket_average, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
                <div class="small text-secondary mt-1">Valeur moyenne par panier</div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Nombre de Transactions -->
    <div class="col-12 col-sm-6 col-md-3">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="stat-label">Transactions</span>
                <div class="bg-accent-subtle text-accent p-2 rounded-3"><i class="fa-solid fa-file-receipt fa-lg"></i></div>
            </div>
            <div class="stat-value"><?= number_format($sales_count, 0, '.', ' ') ?></div>
            <div class="small text-secondary mt-1">Factures de vente créées</div>
        </div>
    </div>

    <!-- Achats / Réassort Stock (Admin uniquement) -->
    <?php if ($user_role === 'admin'): ?>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="stat-label">Réassort Stock (Achats)</span>
                    <div class="bg-primary-subtle text-primary p-2 rounded-3"><i class="fa-solid fa-boxes-packing fa-lg"></i></div>
                </div>
                <div class="stat-value text-slate-800"><?= number_format($total_purchases, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
                <div class="small text-secondary mt-1">Stock acheté pour rayons (Capital actif)</div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-12 col-sm-6 col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="stat-label">Total Articles Vendus</span>
                    <div class="bg-info-subtle text-info p-2 rounded-3"><i class="fa-solid fa-boxes-stacked fa-lg"></i></div>
                </div>
                <?php
                    // Calcul du total des articles vendus par le vendeur
                    $stmt_art = $pdo->prepare("
                        SELECT SUM(oi.quantity) as sum
                        FROM order_items oi
                        JOIN orders o ON oi.order_id = o.id
                        WHERE o.user_id = ? AND o.type = 'SALE' AND o.status = 'COMPLETED'
                          AND DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
                    ");
                    $stmt_art->execute([$_SESSION['user_id'], $date_start, $date_end]);
                    $vqty = intval($stmt_art->fetch()['sum'] ?? 0);
                ?>
                <div class="stat-value"><?= number_format($vqty, 0, '.', ' ') ?></div>
                <div class="small text-secondary mt-1">Articles physiques vendus</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Tableau récapitulatif synthétique pour l'impression et l'export Excel -->
<div class="glass-card p-4 mb-4">
    <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-table me-2 text-primary"></i>Synthèse des Chiffres de la Période</h5>
    <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0" id="reportsSummaryTable">
            <thead class="table-light">
                <tr>
                    <th>Indicateur / Métrique</th>
                    <th class="text-end">Valeur en FCFA / Unité</th>
                    <th>Observations / Détails</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="fw-bold">Chiffre d'Affaires Total (Ventes)</td>
                    <td class="text-end fw-bold text-success"><?= number_format($total_sales, 0, '.', ' ') ?> FCFA</td>
                    <td>Cumul des ventes validées sur la période</td>
                </tr>
                <?php if ($user_role === 'admin'): ?>
                <tr>
                    <td class="fw-bold"><i class="fa-solid fa-boxes-packing text-primary me-2"></i>Achats & Réassort Stock</td>
                    <td class="text-end fw-bold text-slate-800"><?= number_format($total_purchases, 0, '.', ' ') ?> FCFA</td>
                    <td>Marchandises neuves achetées aux fournisseurs (Capital stock actif en rayon)</td>
                </tr>
                <tr>
                    <td class="fw-bold">Marge Brute Estimée</td>
                    <td class="text-end fw-bold text-primary"><?= number_format($gross_margin, 0, '.', ' ') ?> FCFA (<?= number_format($margin_pct, 1) ?> %)</td>
                    <td>Bénéfice brut théorique sur ventes</td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="fw-bold">Nombre de Ventes Effectuées</td>
                    <td class="text-end fw-bold"><?= number_format($sales_count, 0, '.', ' ') ?> transactions</td>
                    <td>Panier moyen : <?= number_format($basket_average, 0, '.', ' ') ?> FCFA</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>


<!-- Graphiques Layout -->
<div class="row g-4 mb-5 no-print">
    <!-- Évolution Temporelle des Ventes -->
    <div class="col-12 <?= $user_role === 'admin' ? 'col-lg-8' : 'col-lg-12' ?>">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-chart-line text-primary me-2"></i>Évolution Temporelle des Ventes (CA)</h5>
            <div style="height: 320px;">
                <canvas id="evoChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Parts de Marché des Boutiques (Admin uniquement) -->
    <?php if ($user_role === 'admin'): ?>
        <div class="col-12 col-lg-4">
            <div class="glass-card p-4 h-100">
                <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-trophy text-warning me-2"></i>Ventes par Boutique</h5>
                <div style="height: 320px; position: relative;">
                    <canvas id="shopChart"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Classement des Produits (Top & Flop) -->
<div class="row g-4 mb-5 no-print">
    <!-- Top 5 produits les plus vendus -->
    <div class="col-12 col-md-6">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-4 text-slate-800 text-success"><i class="fa-solid fa-arrow-trend-up me-2 text-success"></i>Top 5 - Produits les plus vendus</h5>
            <div style="height: 250px;">
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Flop 5 produits les moins vendus -->
    <div class="col-12 col-md-6">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-4 text-slate-800 text-danger"><i class="fa-solid fa-arrow-trend-down me-2 text-danger"></i>Flop 5 - Produits les moins vendus</h5>
            <div style="height: 250px;">
                <canvas id="flopProductsChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Palette de couleurs premium
    const colors = {
        primary: '#0284c7', // Sky-600
        primaryLight: '#38bdf8', // Sky-400
        success: '#10b981', // Emerald-500
        accent: '#f59e0b', // Amber-500
        danger: '#ef4444', // Red-500
        dangerSubtle: '#fee2e2',
        info: '#06b6d4', // Cyan-500
        slate: '#475569',
        border: 'rgba(226, 232, 240, 0.8)',
        glow: 'rgba(2, 132, 199, 0.15)'
    };

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            x: {
                grid: {
                    color: 'rgba(0,0,0,0.03)'
                },
                ticks: {
                    font: { size: 10, family: 'Outfit, Inter' }
                }
            },
            y: {
                grid: {
                    color: 'rgba(0,0,0,0.03)'
                },
                ticks: {
                    font: { size: 10, family: 'Outfit, Inter' }
                }
            }
        }
    };

    // --- 1. GRAPHIQUE ÉVOLUTION DES VENTES ---
    const evoCtx = document.getElementById('evoChart').getContext('2d');
    const evoDates = <?= json_encode(array_map(function($d) { return date('d/m', strtotime($d['sale_date'])); }, $evo_data)) ?>;
    const evoValues = <?= json_encode(array_map(function($d) { return floatval($d['daily_sales']); }, $evo_data)) ?>;

    const evoGrad = evoCtx.createLinearGradient(0, 0, 0, 300);
    evoGrad.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
    evoGrad.addColorStop(1, 'rgba(16, 185, 129, 0)');

    new Chart(evoCtx, {
        type: 'line',
        data: {
            labels: evoDates.length ? evoDates : ['Aucune donnée'],
            datasets: [{
                label: 'Chiffre d\'Affaires (FCFA)',
                data: evoValues.length ? evoValues : [0],
                borderColor: colors.success,
                borderWidth: 3,
                backgroundColor: evoGrad,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: colors.success,
                pointHoverRadius: 7
            }]
        },
        options: {
            ...chartOptions,
            plugins: {
                legend: { display: false }
            }
        }
    });

    // --- 2. GRAPHIQUE COMPARAISON DES BOUTIQUES (Admin uniquement) ---
    <?php if ($user_role === 'admin'): ?>
        const shopCtx = document.getElementById('shopChart').getContext('2d');
        const shopLabels = <?= json_encode(array_map(function($s) { return $s['shop_name']; }, $shop_data)) ?>;
        const shopValues = <?= json_encode(array_map(function($s) { return floatval($s['total_sales']); }, $shop_data)) ?>;

        new Chart(shopCtx, {
            type: 'doughnut',
            data: {
                labels: shopLabels.length ? shopLabels : ['Aucune vente'],
                datasets: [{
                    data: shopValues.length ? shopValues : [0],
                    backgroundColor: [
                        '#0284c7', // Sky
                        '#10b981', // Emerald
                        '#f59e0b', // Amber
                        '#8b5cf6', // Violet
                        '#ec4899'  // Pink
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
                        display: true,
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: { size: 11, family: 'Outfit, Inter' }
                        }
                    }
                }
            }
        });
    <?php endif; ?>

    // --- 3. GRAPHIQUE TOP PRODUITS (Horizontal Bar) ---
    const topCtx = document.getElementById('topProductsChart').getContext('2d');
    const topLabels = <?= json_encode(array_map(function($p) { return strlen($p['product_name']) > 22 ? substr($p['product_name'], 0, 20) . '...' : $p['product_name']; }, $top_products)) ?>;
    const topValues = <?= json_encode(array_map(function($p) { return intval($p['qty_sold']); }, $top_products)) ?>;

    new Chart(topCtx, {
        type: 'bar',
        data: {
            labels: topLabels.length ? topLabels : ['Aucun article'],
            datasets: [{
                label: 'Quantité vendue',
                data: topValues.length ? topValues : [0],
                backgroundColor: 'rgba(16, 185, 129, 0.85)',
                borderRadius: 6,
                maxBarThickness: 30
            }]
        },
        options: {
            ...chartOptions,
            indexAxis: 'y', // Rendre la barre horizontale
        }
    });

    // --- 4. GRAPHIQUE FLOP PRODUITS (Horizontal Bar) ---
    const flopCtx = document.getElementById('flopProductsChart').getContext('2d');
    const flopLabels = <?= json_encode(array_map(function($p) { return strlen($p['product_name']) > 22 ? substr($p['product_name'], 0, 20) . '...' : $p['product_name']; }, $flop_products)) ?>;
    const flopValues = <?= json_encode(array_map(function($p) { return intval($p['qty_sold']); }, $flop_products)) ?>;

    new Chart(flopCtx, {
        type: 'bar',
        data: {
            labels: flopLabels.length ? flopLabels : ['Aucun article'],
            datasets: [{
                label: 'Quantité vendue',
                data: flopValues.length ? flopValues : [0],
                backgroundColor: 'rgba(239, 68, 68, 0.8)',
                borderRadius: 6,
                maxBarThickness: 30
            }]
        },
        options: {
            ...chartOptions,
            indexAxis: 'y',
        }
    });
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
