<?php
require_once __DIR__ . '/../backend/config/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    die("Accès refusé. Veuillez vous connecter.");
}

$active_shop_id = intval($_SESSION['active_shop_id'] ?? 0);
$filter_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : $active_shop_id;
$search = trim($_GET['search'] ?? '');
$category_filter = intval($_GET['category_id'] ?? 0);
$stock_filter = trim($_GET['stock_filter'] ?? '');

$shop_name = "Tous les Dépôts (Global)";
if ($filter_shop_id > 0) {
    $sh_stmt = $pdo->prepare("SELECT name FROM shops WHERE id = ?");
    $sh_stmt->execute([$filter_shop_id]);
    $shop_name = $sh_stmt->fetchColumn() ?: "Boutique #$filter_shop_id";
}

$params = [];

if ($filter_shop_id === 0) {
    $sql = "
        SELECT p.id, p.sku, p.name, p.unit, c.name as category_name, COALESCE(SUM(s.quantity), 0) as stock_qty 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock s ON p.id = s.product_id
        WHERE 1=1
    ";

    if (!empty($search)) {
        $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $t_idx = 0;
        foreach ($search_terms as $term) {
            $p_name = ":st_n_$t_idx";
            $p_sku  = ":st_s_$t_idx";
            $p_id   = ":st_i_$t_idx";
            $sql .= " AND (p.name LIKE $p_name OR p.sku LIKE $p_sku OR p.id LIKE $p_id)";
            $params[$p_name] = '%' . $term . '%';
            $params[$p_sku]  = '%' . $term . '%';
            $params[$p_id]   = '%' . $term . '%';
            $t_idx++;
        }
    }

    if ($category_filter > 0) {
        $sql .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_filter;
    }

    $sql .= " GROUP BY p.id";

    if ($stock_filter === 'low_stock') {
        $sql .= " HAVING stock_qty <= p.min_stock_level";
    } elseif ($stock_filter === 'out_of_stock') {
        $sql .= " HAVING stock_qty = 0";
    }

    $sql .= " ORDER BY c.name ASC, p.name ASC";
} else {
    $sql = "
        SELECT p.id, p.sku, p.name, p.unit, c.name as category_name, COALESCE(s.quantity, 0) as stock_qty 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = :active_shop_id
        WHERE 1=1
    ";
    $params[':active_shop_id'] = $filter_shop_id;

    if (!empty($search)) {
        $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $t_idx = 0;
        foreach ($search_terms as $term) {
            $p_name = ":st_n_$t_idx";
            $p_sku  = ":st_s_$t_idx";
            $p_id   = ":st_i_$t_idx";
            $sql .= " AND (p.name LIKE $p_name OR p.sku LIKE $p_sku OR p.id LIKE $p_id)";
            $params[$p_name] = '%' . $term . '%';
            $params[$p_sku]  = '%' . $term . '%';
            $params[$p_id]   = '%' . $term . '%';
            $t_idx++;
        }
    }

    if ($category_filter > 0) {
        $sql .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_filter;
    }

    if ($stock_filter === 'low_stock') {
        $sql .= " AND COALESCE(s.quantity, 0) <= p.min_stock_level";
    } elseif ($stock_filter === 'out_of_stock') {
        $sql .= " AND COALESCE(s.quantity, 0) = 0";
    }

    $sql .= " ORDER BY c.name ASC, p.name ASC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Fiche de Comptage d'Inventaire - <?= htmlspecialchars($shop_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 11pt; color: #1e293b; background: #fff; }
        .print-btn-bar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 12px 24px; position: sticky; top: 0; z-index: 999; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 9.5pt; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
        }
        .table-inventory th { background-color: #1e293b !important; color: #fff !important; font-weight: 700; font-size: 8.5pt; vertical-align: middle; }
        .table-inventory td { vertical-align: middle; padding: 5px 8px; font-size: 9pt; }
        .cell-count { background-color: #fffde7 !important; width: 110px; height: 32px; border: 1px dashed #d97706 !important; }
    </style>
</head>
<body>

<div class="print-btn-bar no-print d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <button onclick="window.print()" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm">
            <i class="fa-solid fa-print me-1"></i>Imprimer la Fiche (<?= count($products) ?> articles)
        </button>
        <a href="export-inventory-excel.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success fw-bold btn-sm shadow-sm">
            <i class="fa-solid fa-file-excel me-1"></i>Télécharger Excel (.xlsx)
        </a>
    </div>
    <button onclick="window.close()" class="btn btn-outline-secondary btn-sm">Fermer</button>
</div>

<div class="container-fluid px-4 py-3">
    <!-- En-tête officiel -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            <h3 class="fw-bold mb-1 text-slate-900" style="font-size: 16pt;">MANDEL PAPETERIE ERP</h3>
            <h5 class="fw-semibold text-secondary mb-0" style="font-size: 12pt;">FICHE DE COMPTAGE D'INVENTAIRE PHYSIQUE</h5>
            <small class="text-muted">Document confidentiel de contrôle en magasin</small>
        </div>
        <div class="text-end small">
            <div><strong>Dépôt / Boutique :</strong> <?= htmlspecialchars($shop_name) ?></div>
            <div><strong>Date d'Édition :</strong> <?= date('d/m/Y à H:i') ?></div>
            <div><strong>Total Articles :</strong> <span class="badge bg-primary"><?= count($products) ?></span></div>
            <div><strong>Opérateur :</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Personnel') ?></div>
        </div>
    </div>

    <!-- Tableau de comptage complet -->
    <table class="table table-bordered table-inventory">
        <thead>
            <tr>
                <th style="width: 35px;" class="text-center">N°</th>
                <th style="width: 85px;">Code SKU</th>
                <th>Désignation de l'Article</th>
                <th style="width: 140px;">Famille</th>
                <th style="width: 70px;" class="text-center">Unité</th>
                <th style="width: 90px;" class="text-center">Stock Théo.</th>
                <th style="width: 110px;" class="text-center" style="background:#fef08a !important; color:#854d0e !important;">Comptage Réel</th>
                <th style="width: 140px;">Écarts / Remarques</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($products as $p): ?>
                <tr>
                    <td class="text-center text-muted fw-bold"><?= $i++ ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($p['sku'] ?: ('PRD-' . $p['id'])) ?></td>
                    <td class="fw-bold"><?= htmlspecialchars($p['name']) ?></td>
                    <td class="text-secondary small"><?= htmlspecialchars($p['category_name'] ?? 'Non classé') ?></td>
                    <td class="text-center text-secondary small"><?= htmlspecialchars($p['unit']) ?></td>
                    <td class="text-center fw-bold text-primary"><?= $p['stock_qty'] ?></td>
                    <td class="cell-count text-center"></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Bloc Signatures -->
    <div class="row mt-4 pt-3 border-top text-center small text-secondary">
        <div class="col-6">
            <p class="mb-4"><strong>Nom & Signature de l'Agent Comptable :</strong></p>
            <p class="text-muted">__________________________________________</p>
        </div>
        <div class="col-6">
            <p class="mb-4"><strong>Nom & Signature du Responsable de Dépôt :</strong></p>
            <p class="text-muted">__________________________________________</p>
        </div>
    </div>
</div>

</body>
</html>
