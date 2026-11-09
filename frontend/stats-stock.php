<?php
require_once __DIR__ . '/includes/header.php';

// Restriction de sécurité : Seul l'administrateur a accès aux statistiques et états des stocks
if ($_SESSION['user_role'] !== 'admin') {
    echo "<div class='alert alert-danger m-5'><strong>Accès interdit :</strong> Vous devez être administrateur pour accéder à cette page.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// 1. Paramètres de filtrage (Dépôt + Date cible + Catégorie + Produit)
$active_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : ($_SESSION['active_shop_id'] ?? 0);
if (isset($_GET['shop_id']) && $_SESSION['user_role'] === 'admin') {
    $_SESSION['active_shop_id'] = $active_shop_id;
}

$selected_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$selected_category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

$is_global = ($active_shop_id == 0);
$selected_shop_name = "Tous les dépôts (Global)";
if (!$is_global) {
    foreach ($all_shops as $s) {
        if ($s['id'] == $active_shop_id) {
            $selected_shop_name = $s['name'];
            break;
        }
    }
}

// Charger toutes les catégories pour le menu déroulant
$all_categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

// Date cible d'analyse (par défaut = instant présent)
$snapshot_datetime_raw = isset($_GET['snapshot_date']) ? trim($_GET['snapshot_date']) : date('Y-m-d\TH:i');
$formatted_snapshot_dt = str_replace('T', ' ', $snapshot_datetime_raw);
if (strlen($formatted_snapshot_dt) === 16) {
    $formatted_snapshot_dt .= ':59';
}

$is_retrospective = (strtotime($formatted_snapshot_dt) < (time() - 120)); // Vrai si la date choisie est dans le passé

// 2. Extraire les produits du catalogue avec leur stock actuel
$products_sql = "
    SELECT 
        p.id, p.name, p.sku, p.unit, p.price_purchase, p.price_sale, p.min_stock_level,
        COALESCE(c.name, 'Général') as category_name,
        c.id as cat_id,
        COALESCE(SUM(ps.initial_stock), 0) as initial_stock,
        COALESCE(SUM(ps.quantity), 0) as current_stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_stock ps ON p.id = ps.product_id " . ($is_global ? "" : "AND ps.shop_id = " . intval($active_shop_id)) . "
    WHERE 1=1
";
$params_prod = [];
if ($selected_category_id > 0) {
    $products_sql .= " AND p.category_id = :cat_id";
    $params_prod['cat_id'] = $selected_category_id;
}
if ($selected_product_id > 0) {
    $products_sql .= " AND p.id = :prod_id";
    $params_prod['prod_id'] = $selected_product_id;
}

$products_sql .= " GROUP BY p.id, p.name, p.sku, p.unit, p.price_purchase, p.price_sale, p.min_stock_level, c.name, c.id ORDER BY p.name ASC";

$stmt_prods = $pdo->prepare($products_sql);
$stmt_prods->execute($params_prod);
$catalog_products = $stmt_prods->fetchAll();

// Liste complète de tous les articles pour le select déroulant
$all_catalog_list = $pdo->query("SELECT id, name, sku, unit FROM products ORDER BY name ASC")->fetchAll();

// 3. Calculer les variations nettes survenues APRÈS la date cible (si analyse rétrospective)
$post_movements = []; // [product_id => delta_qty]

if ($is_retrospective) {
    // A. Ventes et Achats
    try {
        $ord_sql = "
            SELECT oi.product_id, o.type as order_type, SUM(oi.quantity) as total_qty
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.status = 'COMPLETED' AND o.created_at > :target_dt
        ";
        if (!$is_global) {
            $ord_sql .= " AND o.shop_id = " . intval($active_shop_id);
        }
        if ($selected_product_id > 0) {
            $ord_sql .= " AND oi.product_id = " . intval($selected_product_id);
        }
        $ord_sql .= " GROUP BY oi.product_id, o.type";
        $stmt_ord = $pdo->prepare($ord_sql);
        $stmt_ord->execute(['target_dt' => $formatted_snapshot_dt]);
        foreach ($stmt_ord->fetchAll() as $row) {
            $p_id = intval($row['product_id']);
            $qty = intval($row['total_qty']);
            if (!isset($post_movements[$p_id])) $post_movements[$p_id] = 0;
            if ($row['order_type'] === 'SALE') $post_movements[$p_id] -= $qty;
            elseif ($row['order_type'] === 'PURCHASE') $post_movements[$p_id] += $qty;
        }
    } catch (Exception $e) {}

    // B. Sorties, Découpes et Pertes
    try {
        $chk_tgt = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM stock_exit_items LIKE 'target_product_id'");
            if ($chk && $chk->fetch()) $chk_tgt = true;
        } catch (Exception $ex) {}

        if ($chk_tgt) {
            $exit_sql = "
                SELECT sei.product_id as src_id, sei.target_product_id as tgt_id, sei.quantity as src_qty, sei.target_quantity as tgt_qty, se.reason
                FROM stock_exit_items sei
                JOIN stock_exits se ON sei.stock_exit_id = se.id
                WHERE se.created_at > :target_dt
            ";
        } else {
            $exit_sql = "
                SELECT sei.product_id as src_id, NULL as tgt_id, sei.quantity as src_qty, 0 as tgt_qty, se.reason
                FROM stock_exit_items sei
                JOIN stock_exits se ON sei.stock_exit_id = se.id
                WHERE se.created_at > :target_dt
            ";
        }
        if (!$is_global) $exit_sql .= " AND se.shop_id = " . intval($active_shop_id);
        
        $stmt_exit = $pdo->prepare($exit_sql);
        $stmt_exit->execute(['target_dt' => $formatted_snapshot_dt]);
        foreach ($stmt_exit->fetchAll() as $row) {
            $src_id = intval($row['src_id']);
            $tgt_id = intval($row['tgt_id'] ?? 0);
            if ($src_id > 0) {
                if (!isset($post_movements[$src_id])) $post_movements[$src_id] = 0;
                $post_movements[$src_id] -= intval($row['src_qty']);
            }
            if ($tgt_id > 0 && $row['reason'] === 'PRODUCTION') {
                if (!isset($post_movements[$tgt_id])) $post_movements[$tgt_id] = 0;
                $post_movements[$tgt_id] += intval($row['tgt_qty']);
            }
        }
    } catch (Exception $e) {}

    // C. Transferts inter-boutiques
    if (!$is_global) {
        try {
            $tr_sql = "
                SELECT product_id, from_shop_id, to_shop_id, quantity
                FROM stock_transfers
                WHERE created_at > :target_dt AND (from_shop_id = :sh_id OR to_shop_id = :sh_id)
            ";
            if ($selected_product_id > 0) {
                $tr_sql .= " AND product_id = " . intval($selected_product_id);
            }
            $stmt_tr = $pdo->prepare($tr_sql);
            $stmt_tr->execute(['target_dt' => $formatted_snapshot_dt, 'sh_id' => $active_shop_id]);
            foreach ($stmt_tr->fetchAll() as $row) {
                $p_id = intval($row['product_id']);
                $qty = intval($row['quantity']);
                if (!isset($post_movements[$p_id])) $post_movements[$p_id] = 0;
                if ($row['from_shop_id'] == $active_shop_id) $post_movements[$p_id] -= $qty;
                if ($row['to_shop_id'] == $active_shop_id) $post_movements[$p_id] += $qty;
            }
        } catch (Exception $e) {}
    }
}

// 4. Calculs des KPIs, liste détaillée des articles et ventilation par catégorie
$total_catalog = count($catalog_products);
$total_qty = 0;
$total_initial_qty = 0;
$value_purchase = 0;
$value_sale = 0;
$alerts_count = 0;
$healthy_count = 0;
$detailed_items = [];
$category_breakdown = [];

foreach ($catalog_products as $p) {
    $p_id = intval($p['id']);
    $cur_stock = intval($p['current_stock']);
    $init_stk = intval($p['initial_stock'] ?? 0);
    $total_initial_qty += $init_stk;
    $delta_since = intval($post_movements[$p_id] ?? 0);
    
    // Stock reconstitué à la date demandée
    $computed_stock = $cur_stock - $delta_since;
    $p_stock = max(0, $computed_stock);

    $total_qty += $p_stock;
    $val_p = $p_stock * floatval($p['price_purchase']);
    $val_s = $p_stock * floatval($p['price_sale']);
    $value_purchase += $val_p;
    $value_sale += $val_s;

    if ($computed_stock <= intval($p['min_stock_level'])) {
        $alerts_count++;
    } else {
        $healthy_count++;
    }

    $c_name = $p['category_name'] ?: 'Général';
    if (!isset($category_breakdown[$c_name])) {
        $category_breakdown[$c_name] = ['qty' => 0, 'val_purchase' => 0, 'val_sale' => 0];
    }
    $category_breakdown[$c_name]['qty'] += $p_stock;
    $category_breakdown[$c_name]['val_purchase'] += $val_p;
    $category_breakdown[$c_name]['val_sale'] += $val_s;

    $detailed_items[] = [
        'id' => $p_id,
        'name' => $p['name'],
        'sku' => $p['sku'] ?: ('ART-' . sprintf('%04d', $p_id)),
        'category_name' => $c_name,
        'unit' => $p['unit'],
        'min_stock' => intval($p['min_stock_level']),
        'initial_stock' => $init_stk,
        'stock_at_date' => $computed_stock,
        'current_stock' => $cur_stock,
        'price_purchase' => floatval($p['price_purchase']),
        'price_sale' => floatval($p['price_sale']),
        'val_purchase' => $val_p,
        'val_sale' => $val_s
    ];
}

// Configuration de la pagination du tableau détaillé (50 articles par page)
$show_all = isset($_GET['all']) && $_GET['all'] == '1';
$per_page_stock = 50;
$total_stock_items = count($detailed_items);
$total_pages_stock = max(1, ceil($total_stock_items / $per_page_stock));
$current_page_stock = isset($_GET['page']) ? max(1, min($total_pages_stock, intval($_GET['page']))) : 1;
$offset_stock = ($current_page_stock - 1) * $per_page_stock;

$display_items = $show_all ? $detailed_items : array_slice($detailed_items, $offset_stock, $per_page_stock);

$total_active_stock_lines = $healthy_count + $alerts_count;
$global_healthy_pct = $total_active_stock_lines > 0 ? round(($healthy_count / $total_active_stock_lines) * 100) : 100;
$potential_margin = $value_sale - $value_purchase;
$potential_margin_pct = $value_sale > 0 ? round(($potential_margin / $value_sale) * 100, 2) : 0;

// Données graphiques (par Catégorie)
$chart_labels = [];
$chart_qty = [];
$chart_val_purchase = [];
$chart_val_sale = [];

// Trier les catégories par volume décroissant
uasort($category_breakdown, function($a, $b) { return $b['qty'] - $a['qty']; });

foreach ($category_breakdown as $c_name => $c_data) {
    if ($c_data['qty'] > 0 || $c_data['val_purchase'] > 0) {
        $chart_labels[] = $c_name;
        $chart_qty[] = intval($c_data['qty']);
        $chart_val_purchase[] = floatval($c_data['val_purchase']);
        $chart_val_sale[] = floatval($c_data['val_sale']);
    }
}
?>

<style>
/* Masquer les graphiques et éléments non essentiels à l'impression */
@media print {
    .no-print, nav, header, footer, .btn, #shopQtyChart, #shopValueChart, .chart-container-box {
        display: none !important;
    }
    body {
        background: #fff !important;
        color: #000 !important;
        font-size: 11pt !important;
    }
    .table {
        border-collapse: collapse !important;
        width: 100% !important;
    }
    .table th, .table td {
        border: 1px solid #333 !important;
        padding: 4px 6px !important;
    }
    .glass-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .d-print-block {
        display: block !important;
    }
}
</style>

<!-- En-tête -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <div>
            <h1 class="fw-extrabold text-slate-800 mb-1 fs-4">
                <span class="text-primary"><i class="fa-solid fa-chart-pie me-2"></i></span> 
                <?= $is_global ? 'État & Statistiques Globales des Stocks' : 'État & Statistiques du Stock - ' . htmlspecialchars($selected_shop_name) ?>
            </h1>
            <p class="text-secondary small mb-0">
                <?= $is_retrospective 
                    ? ("<span class='badge bg-warning text-dark me-1'><i class='fa-solid fa-clock-rotate-left me-1'></i>Situation au " . date('d/m/Y à H:i', strtotime($formatted_snapshot_dt)) . "</span> Reconstitution analytique des stocks et de la valorisation.") 
                    : ("<span class='badge bg-success text-white me-1'><i class='fa-solid fa-circle-dot me-1'></i>En temps réel</span> État actuel et valeur financière de vos magasins.") ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 no-print flex-wrap">
            <a href="export-stock-excel.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success btn-sm fw-bold shadow-sm" style="border-radius: 8px;" title="Exporter tous les articles filtrés au format Excel (.xlsx)">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel (.xlsx)
            </a>
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm fw-semibold" style="border-radius: 8px;">
                <i class="fa-solid fa-print me-1"></i>Imprimer le Rapport
            </button>
        </div>
    </div>
</div>

<!-- BARRE DE FILTRES COMBINÉE : BOUTIQUE + DATE + FAMILLE + ARTICLE -->
<div class="glass-card p-3 mb-4 no-print border border-primary-subtle shadow-sm" style="border-radius: 12px;">
    <form method="GET" action="stats-stock.php" id="stockFilterForm">
        <div class="row g-2 align-items-end">
            <!-- 1. Dépôt / Magasin -->
            <div class="col-12 col-md-3">
                <label for="shop_id" class="form-label small fw-bold text-secondary mb-1">
                    <i class="fa-solid fa-store text-primary me-1"></i>Dépôt / Boutique
                </label>
                <select name="shop_id" id="shop_id" class="form-select form-control-custom" style="border-radius: 8px; font-weight: 600;">
                    <option value="0" <?= $active_shop_id == 0 ? 'selected' : '' ?>>🌐 Tous les dépôts (Global)</option>
                    <?php foreach ($all_shops as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $active_shop_id ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($sh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. Date d'analyse -->
            <div class="col-12 col-md-3">
                <label for="snapshot_date" class="form-label small fw-bold text-secondary mb-1">
                    <i class="fa-solid fa-calendar-day text-primary me-1"></i>Période / Date d'Analyse
                </label>
                <input type="datetime-local" class="form-control form-control-custom fw-bold" id="snapshot_date" name="snapshot_date" value="<?= htmlspecialchars($snapshot_datetime_raw) ?>" required>
            </div>

            <!-- 3. Raccourcis Rapides en 1 Clic -->
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary mb-1">
                    <i class="fa-solid fa-bolt text-warning me-1"></i>Raccourcis de dates
                </label>
                <div class="d-flex gap-1 flex-wrap">
                    <button type="button" class="btn btn-light btn-xs border fw-semibold py-1 px-2" style="font-size: 0.75rem;" onclick="setStatsDate('NOW')">Maintenant</button>
                    <button type="button" class="btn btn-light btn-xs border fw-semibold py-1 px-2" style="font-size: 0.75rem;" onclick="setStatsDate('LAST_MONTH_END')">Fin mois dernier</button>
                    <button type="button" class="btn btn-light btn-xs border fw-semibold py-1 px-2" style="font-size: 0.75rem;" onclick="setStatsDate('30_DAYS_AGO')">Il y a 30 j</button>
                    <button type="button" class="btn btn-light btn-xs border fw-semibold py-1 px-2" style="font-size: 0.75rem;" onclick="setStatsDate('60_DAYS_AGO')">Il y a 60 j</button>
                    <button type="button" class="btn btn-light btn-xs border fw-semibold py-1 px-2" style="font-size: 0.75rem;" onclick="setStatsDate('90_DAYS_AGO')">Il y a 90 j</button>
                </div>
            </div>

            <!-- 4. Filtre par Famille / Catégorie -->
            <div class="col-12 col-md-4">
                <label for="category_id" class="form-label small fw-bold text-secondary mb-1">
                    <i class="fa-solid fa-tags text-primary me-1"></i>Filtrer par Famille
                </label>
                <select name="category_id" id="category_id" class="form-select form-control-custom" style="border-radius: 8px;">
                    <option value="0">-- Toutes les Familles --</option>
                    <?php foreach ($all_categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $selected_category_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 5. Filtre par Article Précis (Choix direct du produit) -->
            <div class="col-12 col-md-6">
                <label for="product_id" class="form-label small fw-bold text-secondary mb-1">
                    <i class="fa-solid fa-box text-primary me-1"></i>Cibler un Article Précis
                </label>
                <select name="product_id" id="product_id" class="form-select form-control-custom" style="border-radius: 8px;">
                    <option value="0">-- Tous les Articles du catalogue --</option>
                    <?php foreach ($all_catalog_list as $prod_opt): ?>
                        <option value="<?= $prod_opt['id'] ?>" <?= $prod_opt['id'] == $selected_product_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($prod_opt['name']) ?> (<?= htmlspecialchars($prod_opt['unit'] ?: 'UNITE') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 6. Bouton Actualiser -->
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm" style="border-radius: 8px;">
                    <i class="fa-solid fa-filter me-1"></i>Filtrer
                </button>
            </div>
        </div>
    </form>
</div>

<!-- KPIs (Adaptés selon le filtre de boutique, date et article) -->
<div class="row g-4 mb-4">
    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label">Stock Total</span>
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3"><i class="fa-solid fa-cubes fa-lg"></i></div>
            </div>
            <div class="stat-value text-slate-900"><?= number_format($total_qty, 0, '.', ' ') ?></div>
            <div class="small text-secondary mt-1">
                <?= $is_retrospective ? ('Unités au ' . date('d/m/Y', strtotime($formatted_snapshot_dt))) : 'Unités physiques présentes' ?>
                <span class="d-block text-muted small mt-1"><i class="fa-solid fa-flag text-secondary me-1"></i>Départ officiel : <strong><?= number_format($total_initial_qty, 0, '.', ' ') ?></strong> u</span>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label">Valeur d'Achat</span>
                <div class="bg-success bg-opacity-10 text-success p-2 rounded-3"><i class="fa-solid fa-file-invoice-dollar fa-lg"></i></div>
            </div>
            <div class="stat-value text-slate-900"><?= number_format($value_purchase, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
            <div class="small text-secondary mt-1">Capital investi (Actif stock)</div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100 border-start border-4 border-info">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label">Valeur de Vente</span>
                <div class="bg-info bg-opacity-10 text-info p-2 rounded-3"><i class="fa-solid fa-cart-shopping fa-lg"></i></div>
            </div>
            <div class="stat-value text-slate-900"><?= number_format($value_sale, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span></div>
            <div class="small text-secondary mt-1">Marge potentielle : <?= number_format($potential_margin, 0, '.', ' ') ?> F (<?= $potential_margin_pct ?>%)</div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100 border-start border-4 border-danger">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label">Alertes Réassort</span>
                <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-3"><i class="fa-solid fa-triangle-exclamation fa-lg"></i></div>
            </div>
            <div class="stat-value text-danger"><?= number_format($alerts_count, 0, '.', ' ') ?></div>
            <div class="small text-secondary mt-1"><?= $alerts_count > 0 ? 'Articles sous le seuil d\'alerte' : 'Aucune alerte' ?></div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-lg">
        <div class="glass-card p-4 h-100 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label">Santé Stock</span>
                <div class="bg-warning bg-opacity-10 text-warning p-2 rounded-3"><i class="fa-solid fa-heart-pulse fa-lg"></i></div>
            </div>
            <div class="stat-value text-success"><?= $global_healthy_pct ?>%</div>
            <div class="progress mt-2" style="height: 6px; border-radius: 3px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $global_healthy_pct ?>%" aria-valuenow="<?= $global_healthy_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="small text-secondary mt-1"><?= $healthy_count ?> sains / <?= $alerts_count ?> bas</div>
        </div>
    </div>
</div>

<!-- Graphiques de Comparaison par Catégorie (Uniquement affiché à l'écran, masqué à l'impression) -->
<div class="row g-4 mb-5 no-print">
    <div class="col-12 col-lg-6">
        <div class="glass-card p-4 h-100 chart-container-box">
            <h5 class="fw-bold mb-4 text-slate-800">
                <i class="fa-solid fa-chart-pie text-success me-2"></i>
                Répartition du Volume par Catégorie <?= $is_retrospective ? ('au ' . date('d/m/Y', strtotime($formatted_snapshot_dt))) : '' ?>
            </h5>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="shopQtyChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="glass-card p-4 h-100 chart-container-box">
            <h5 class="fw-bold mb-4 text-slate-800">
                <i class="fa-solid fa-chart-column text-primary me-2"></i>
                Valeur du Stock par Catégorie (Achat vs Vente en FCFA)
            </h5>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="shopValueChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- En-tête officiel pour l'impression papier -->
<div class="d-none d-print-block mb-3 border-bottom pb-2">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="fw-bold mb-0">ETS MANDEL — RAPPORT D'ÉTAT DU STOCK</h4>
            <div class="small text-secondary">Établissement Commercial & Distribution</div>
        </div>
        <div class="text-end small">
            <div><strong>Dépôt / Magasin :</strong> <?= htmlspecialchars($selected_shop_name) ?></div>
            <div><strong>Situation au :</strong> <?= date('d/m/Y à H:i', strtotime($formatted_snapshot_dt)) ?></div>
            <div><strong>Édité le :</strong> <?= date('d/m/Y à H:i') ?></div>
        </div>
    </div>
</div>

<!-- TABLEAU DÉTAILLÉ DE TOUS LES ARTICLES (STOCK & VALORISATION INDIVIDUELLE) -->
<div class="glass-card p-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h5 class="fw-bold mb-0 text-slate-800">
                <i class="fa-solid fa-boxes-stacked text-primary me-2"></i>
                État Détaillé des Articles <?= $is_retrospective ? ('au ' . date('d/m/Y à H:i', strtotime($formatted_snapshot_dt))) : '(Temps Réel)' ?>
            </h5>
            <small class="text-secondary">Quantité exacte, prix d'achat, prix de vente et valorisation financière de chaque produit.</small>
        </div>
        <div class="d-flex align-items-center gap-2 no-print">
            <input type="text" id="detailedProductSearch" class="form-control form-control-custom w-auto" placeholder="Recherche rapide d'article..." oninput="filterDetailedTable()">
            <?php
            $q_all = $_GET;
            if ($show_all):
                unset($q_all['all']);
                echo '<a href="stats-stock.php?' . http_build_query($q_all) . '" class="btn btn-outline-primary btn-sm fw-semibold" style="border-radius: 8px;"><i class="fa-solid fa-list-ol me-1"></i>Mode Paginé (50/page)</a>';
            else:
                $q_all['all'] = '1';
                echo '<a href="stats-stock.php?' . http_build_query($q_all) . '" class="btn btn-outline-secondary btn-sm fw-semibold" style="border-radius: 8px;"><i class="fa-solid fa-eye me-1"></i>Afficher Tous (' . $total_stock_items . ')</a>';
            endif;
            ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-custom align-middle" id="detailedItemsTable">
            <thead class="table-light">
                <tr>
                    <th style="width: 35px;" class="text-center">#</th>
                    <th style="width: 90px;">SKU / Code</th>
                    <th>Désignation de l'Article</th>
                    <th>Famille</th>
                    <th class="text-center" style="width: 75px;">Unité</th>
                    <th class="text-center" style="width: 85px; background-color: #f1f5f9; color: #475569; font-weight: bold;">
                        Stock Départ
                    </th>
                    <th class="text-center" style="width: 90px; background-color: #e0f2fe; color: #0369a1; font-weight: bold;">
                        Stock <?= $is_retrospective ? ('au ' . date('d/m', strtotime($formatted_snapshot_dt))) : 'Actuel' ?>
                    </th>
                    <th class="text-end" style="width: 100px;">P.U Achat</th>
                    <th class="text-end" style="width: 100px;">P.U Vente</th>
                    <th class="text-end" style="width: 120px;">Val. Achat</th>
                    <th class="text-end" style="width: 120px;">Val. Vente</th>
                    <th class="text-center no-print" style="width: 60px;">Fiche</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detailed_items)): ?>
                    <tr>
                        <td colspan="12" class="text-center py-4 text-secondary">
                            <i class="fa-solid fa-box-open fa-2x mb-2 d-block opacity-25"></i>
                            Aucun article trouvé pour ces critères de filtrage.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $p_idx = $show_all ? 1 : ($offset_stock + 1);
                    foreach ($display_items as $item): 
                        $is_low = ($item['stock_at_date'] <= $item['min_stock'] && $item['stock_at_date'] > 0);
                        $is_zero = ($item['stock_at_date'] <= 0);
                    ?>
                        <tr class="product-item-row <?= $is_zero ? 'table-danger bg-danger bg-opacity-10' : ($is_low ? 'table-warning bg-warning bg-opacity-10' : '') ?>">
                            <td class="text-center text-secondary small"><?= $p_idx++ ?></td>
                            <td class="small fw-semibold text-secondary"><?= htmlspecialchars($item['sku']) ?></td>
                            <td class="fw-bold text-slate-900"><?= htmlspecialchars($item['name']) ?></td>
                            <td><span class="badge bg-light text-dark border small"><?= htmlspecialchars($item['category_name']) ?></span></td>
                            <td class="text-center small text-secondary"><?= htmlspecialchars($item['unit']) ?></td>
                            
                            <!-- Stock de Départ -->
                            <td class="text-center font-monospace fw-semibold text-slate-700" style="background-color: #f8fafc;">
                                <?= number_format($item['initial_stock'], 0, '.', ' ') ?>
                            </td>

                            <!-- Quantité en stock actuel -->
                            <td class="text-center fw-extrabold fs-6" style="background-color: #f0f9ff;">
                                <?php if ($is_zero): ?>
                                    <span class="text-danger fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= $item['stock_at_date'] ?></span>
                                <?php elseif ($is_low): ?>
                                    <span class="text-warning-emphasis fw-bold"><?= $item['stock_at_date'] ?></span>
                                <?php else: ?>
                                    <span class="text-primary"><?= $item['stock_at_date'] ?></span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end small"><?= number_format($item['price_purchase'], 0, '.', ' ') ?> F</td>
                            <td class="text-end small text-primary fw-semibold"><?= number_format($item['price_sale'], 0, '.', ' ') ?> F</td>
                            <td class="text-end text-success fw-bold small"><?= number_format($item['val_purchase'], 0, '.', ' ') ?> F</td>
                            <td class="text-end text-slate-900 fw-bold small"><?= number_format($item['val_sale'], 0, '.', ' ') ?> F</td>
                            
                            <td class="text-center no-print">
                                <a href="product-history.php?id=<?= $item['id'] ?>&shop_id=<?= $active_shop_id ?>" class="btn btn-outline-primary btn-xs py-1 px-2" title="Consulter l'historique complet">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-dark fw-bold">
                <tr>
                    <td colspan="5" class="text-end">TOTAL GÉNÉRAL :</td>
                    <td class="text-center text-warning fs-6"><?= number_format($total_qty, 0, '.', ' ') ?></td>
                    <td colspan="2"></td>
                    <td class="text-end text-success fs-6"><?= number_format($value_purchase, 0, '.', ' ') ?> FCFA</td>
                    <td class="text-end text-white fs-6"><?= number_format($value_sale, 0, '.', ' ') ?> FCFA</td>
                    <td class="no-print"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Barre de Pagination Tableau de Stock -->
    <?php if (!$show_all && $total_pages_stock > 1): ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
            <div class="small text-secondary fw-semibold">
                Affichage de <?= min($total_stock_items, $offset_stock + 1) ?> à <?= min($total_stock_items, $offset_stock + count($display_items)) ?> sur <strong><?= number_format($total_stock_items, 0, '.', ' ') ?></strong> article(s)
            </div>
            <nav aria-label="Pagination stock">
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $q_stk = $_GET;
                    if ($current_page_stock > 1):
                        $q_stk['page'] = $current_page_stock - 1;
                        echo '<li class="page-item"><a class="page-link" href="stats-stock.php?' . http_build_query($q_stk) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                    else:
                        echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                    endif;

                    $st_p = max(1, $current_page_stock - 2);
                    $en_p = min($total_pages_stock, $current_page_stock + 2);

                    if ($st_p > 1) {
                        $q_stk['page'] = 1;
                        echo '<li class="page-item"><a class="page-link" href="stats-stock.php?' . http_build_query($q_stk) . '">1</a></li>';
                        if ($st_p > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }

                    for ($p = $st_p; $p <= $en_p; $p++):
                        $q_stk['page'] = $p;
                        $act = ($p == $current_page_stock) ? 'active' : '';
                        echo '<li class="page-item ' . $act . '"><a class="page-link" href="stats-stock.php?' . http_build_query($q_stk) . '">' . $p . '</a></li>';
                    endfor;

                    if ($en_p < $total_pages_stock) {
                        if ($en_p < $total_pages_stock - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        $q_stk['page'] = $total_pages_stock;
                        echo '<li class="page-item"><a class="page-link" href="stats-stock.php?' . http_build_query($q_stk) . '">' . $total_pages_stock . '</a></li>';
                    }

                    if ($current_page_stock < $total_pages_stock):
                        $q_stk['page'] = $current_page_stock + 1;
                        echo '<li class="page-item"><a class="page-link" href="stats-stock.php?' . http_build_query($q_stk) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                    else:
                        echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                    endif;
                    ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Tableau Synthétique par Famille / Catégorie -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-4 text-slate-800">
                <i class="fa-solid fa-folder text-warning me-2"></i>
                Récapitulatif Synthétique par Famille de Produits <?= $is_retrospective ? ('au ' . date('d/m/Y à H:i', strtotime($formatted_snapshot_dt))) : '' ?>
            </h5>
            
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Famille / Catégorie</th>
                            <th class="text-end">Quantité en Stock</th>
                            <th class="text-end">Valeur d'Achat</th>
                            <th class="text-end">Valeur de Vente</th>
                            <th class="text-end">Marge Théorique</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($category_breakdown as $c_name => $cat_stat): 
                            $c_margin = $cat_stat['val_sale'] - $cat_stat['val_purchase'];
                        ?>
                            <tr>
                                <td class="fw-bold text-slate-800">
                                    <i class="fa-solid fa-folder text-warning me-2"></i><?= htmlspecialchars($c_name) ?>
                                </td>
                                <td class="text-end fw-semibold"><?= number_format($cat_stat['qty'], 0, '.', ' ') ?></td>
                                <td class="text-end text-success"><?= number_format($cat_stat['val_purchase'], 0, '.', ' ') ?> FCFA</td>
                                <td class="text-end text-primary"><?= number_format($cat_stat['val_sale'], 0, '.', ' ') ?> FCFA</td>
                                <td class="text-end fw-bold text-slate-800">+<?= number_format(max(0, $c_margin), 0, '.', ' ') ?> FCFA</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>TOTAL :</td>
                            <td class="text-end text-primary fs-6"><?= number_format($total_qty, 0, '.', ' ') ?></td>
                            <td class="text-end text-success fs-6"><?= number_format($value_purchase, 0, '.', ' ') ?> FCFA</td>
                            <td class="text-end text-primary fs-6"><?= number_format($value_sale, 0, '.', ' ') ?> FCFA</td>
                            <td class="text-end text-slate-900 fs-6">+<?= number_format(max(0, $potential_margin), 0, '.', ' ') ?> FCFA</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Signatures pour impression propre -->
<div class="d-none d-print-block mt-4 pt-3 border-top">
    <div class="row text-center small">
        <div class="col-4">
            <p class="fw-bold mb-5">Le Responsable des Stocks</p>
            <p class="text-muted">Signature & Cachet</p>
        </div>
        <div class="col-4">
            <p class="fw-bold mb-5">Le Contrôleur Interne</p>
            <p class="text-muted">Signature & Cachet</p>
        </div>
        <div class="col-4">
            <p class="fw-bold mb-5">La Direction Générale</p>
            <p class="text-muted">Signature & Cachet</p>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
function setStatsDate(shortcut) {
    const input = document.getElementById('snapshot_date');
    const now = new Date();
    let target = new Date();
    
    if (shortcut === 'NOW') {
        target = now;
    } else if (shortcut === '30_DAYS_AGO') {
        target.setDate(now.getDate() - 30);
    } else if (shortcut === '60_DAYS_AGO') {
        target.setDate(now.getDate() - 60);
    } else if (shortcut === '90_DAYS_AGO') {
        target.setDate(now.getDate() - 90);
    } else if (shortcut === 'LAST_MONTH_END') {
        target = new Date(now.getFullYear(), now.getMonth(), 0, 23, 59);
    }

    const year = target.getFullYear();
    const month = String(target.getMonth() + 1).padStart(2, '0');
    const day = String(target.getDate()).padStart(2, '0');
    const hours = String(target.getHours()).padStart(2, '0');
    const minutes = String(target.getMinutes()).padStart(2, '0');

    input.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    document.getElementById('stockFilterForm').submit();
}

function filterDetailedTable() {
    const input = document.getElementById('detailedProductSearch');
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.product-item-row');

    rows.forEach(tr => {
        const text = tr.innerText.toLowerCase();
        tr.style.display = text.includes(filter) ? '' : 'none';
    });
}

function exportStockTableToCSV() {
    const table = document.getElementById('detailedItemsTable');
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        let cols = row.querySelectorAll('th, td');
        let rowData = [];
        cols.forEach((col, idx) => {
            if (idx < cols.length - 1 || col.tagName.toLowerCase() === 'th') {
                let text = col.innerText.replace(/"/g, '""').replace(/\r?\n|\r/g, ' ').trim();
                rowData.push(`"${text}"`);
            }
        });
        if (rowData.length > 0) {
            csv.push(rowData.join(';'));
        }
    });

    const csvContent = "data:text/csv;charset=utf-8,\uFEFF" + csv.join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `etat_stock_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

document.addEventListener('DOMContentLoaded', function() {
    const labels = <?= json_encode($chart_labels) ?>;
    const quantities = <?= json_encode($chart_qty) ?>;
    const purchaseValues = <?= json_encode($chart_val_purchase) ?>;
    const saleValues = <?= json_encode($chart_val_sale) ?>;

    // 1. Chart Volume de Stock (Doughnut)
    const ctxQtyEl = document.getElementById('shopQtyChart');
    if (ctxQtyEl && labels.length > 0) {
        new Chart(ctxQtyEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: quantities,
                    backgroundColor: [
                        '#10b981', '#0284c7', '#f59e0b', '#8b5cf6', '#ec4899', '#64748b', '#06b6d4', '#84cc16'
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
                        labels: { boxWidth: 12, font: { size: 11, family: 'Outfit, Inter' } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ` ${context.label}: ${context.raw.toLocaleString('fr-FR')} unités`;
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Chart Valeur Financière (Achat vs Vente) - Bar Chart
    const ctxValEl = document.getElementById('shopValueChart');
    if (ctxValEl && labels.length > 0) {
        new Chart(ctxValEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Valeur d\'Achat',
                        data: purchaseValues,
                        backgroundColor: 'rgba(16, 185, 129, 0.85)',
                        borderRadius: 6,
                        maxBarThickness: 35
                    },
                    {
                        label: 'Valeur de Vente',
                        data: saleValues,
                        backgroundColor: 'rgba(2, 132, 199, 0.85)',
                        borderRadius: 6,
                        maxBarThickness: 35
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11, family: 'Outfit, Inter' } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ` ${context.label}: ${context.raw.toLocaleString('fr-FR')} FCFA`;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Outfit, Inter' } } },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.03)' },
                        ticks: {
                            font: { size: 10, family: 'Outfit, Inter' },
                            callback: function(value) { return value.toLocaleString('fr-FR') + ' F'; }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
