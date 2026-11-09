<?php
require_once __DIR__ . '/includes/header.php';

// Filtres
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'category_asc';

// Récupérer toutes les catégories
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Requête des produits
$sql = "
    SELECT p.id, p.sku, p.name, p.unit, p.price_sale,
           c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $t_idx = 0;
    foreach ($search_terms as $term) {
        $p_name = ":st_n_$t_idx";
        $p_sku  = ":st_s_$t_idx";
        $sql .= " AND (p.name LIKE $p_name OR p.sku LIKE $p_sku)";
        $params[$p_name] = '%' . $term . '%';
        $params[$p_sku]  = '%' . $term . '%';
        $t_idx++;
    }
}

if ($category_filter > 0) {
    $sql .= " AND p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY p.name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY p.name DESC";
        break;
    case 'price_asc':
        $sql .= " ORDER BY p.price_sale ASC, p.name ASC";
        break;
    case 'price_desc':
        $sql .= " ORDER BY p.price_sale DESC, p.name ASC";
        break;
    case 'category_asc':
    default:
        $sql .= " ORDER BY c.name ASC, p.name ASC";
        break;
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE 1=1";
$count_params = [];
if (!empty($search)) {
    $search_terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $t_idx = 0;
    foreach ($search_terms as $term) {
        $p_name = ":c_st_n_$t_idx";
        $p_sku  = ":c_st_s_$t_idx";
        $count_sql .= " AND (p.name LIKE $p_name OR p.sku LIKE $p_sku)";
        $count_params[$p_name] = '%' . $term . '%';
        $count_params[$p_sku]  = '%' . $term . '%';
        $t_idx++;
    }
}
if ($category_filter > 0) {
    $count_sql .= " AND p.category_id = :cat_filter";
    $count_params[':cat_filter'] = $category_filter;
}
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$total_products = intval($count_stmt->fetchColumn() ?: 0);
$total_pages = max(1, ceil($total_products / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// Mode Complet (Tous les articles sans pagination pour impression ou consultation intégrale)
$show_all = (isset($_GET['all']) && $_GET['all'] == '1');
$auto_print = (isset($_GET['print']) && $_GET['print'] == '1');

if (!$show_all) {
    $sql .= " LIMIT :limit OFFSET :offset";
}

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
if (!$show_all) {
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
@media print {
    .no-print, header, nav, .navbar, .glass-card form, footer {
        display: none !important;
    }
    body {
        background: white !important;
        color: #000 !important;
        font-size: 11pt !important;
    }
    .container, .container-fluid {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .print-header {
        display: block !important;
        text-align: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #000;
        padding-bottom: 10px;
    }
    .table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    .table th, .table td {
        border: 1px solid #333 !important;
        padding: 6px 8px !important;
        font-size: 10pt !important;
    }
    .table thead th {
        background-color: #f1f5f9 !important;
        color: #000 !important;
        font-weight: bold !important;
    }
}
@media screen {
    .print-header {
        display: none;
    }
}
</style>

<!-- En-tête exclusif impression papier -->
<div class="print-header">
    <h2 style="font-weight: 800; margin: 0 0 4px 0; text-transform: uppercase; font-size: 16pt;">ETS MANDEL — GRILLE TARIFAIRE OFFICIELLE</h2>
    <p style="margin: 0; font-size: 10pt; color: #475569;">Prix de vente publics valables pour toutes les boutiques &bull; Document interne vendeur</p>
    <p style="margin: 2px 0 0 0; font-size: 9pt; color: #64748b;">Édité le <?= date('d/m/Y à H:i') ?> &bull; Total : <?= count($products) ?> articles référencés</p>
</div>

<!-- Header de la page (Écran) -->
<div class="row mb-3 align-items-center no-print">
    <div class="col-12 col-md-6">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-tags me-2"></i></span>Grille des Prix de Vente
        </h1>
        <p class="text-secondary small mb-0">Fiche tarifaire destinée aux vendeurs et employés (sans marges ni prix d'achat).</p>
    </div>
    <div class="col-12 col-md-6 d-flex justify-content-md-end gap-2 mt-2 mt-md-0 flex-wrap">
        <a href="products.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
            <i class="fa-solid fa-arrow-left me-1"></i>Retour Catalogue
        </a>
        <a href="export-pricesheet-excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success btn-sm fw-bold shadow-sm" style="border-radius: 8px;" title="Télécharger tous les articles filtrés en Excel (.xlsx)">
            <i class="fa-solid fa-file-excel me-1"></i>Télécharger Excel (.xlsx)
        </a>
        <a href="price-sheet.php?all=1&print=1<?= !empty($category_filter) ? '&category_id='.$category_filter : '' ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?><?= !empty($sort) ? '&sort='.$sort : '' ?>" target="_blank" class="btn btn-primary btn-sm fw-bold shadow-sm" style="border-radius: 8px;" title="Imprimer tous les articles filtrés sur papier A4">
            <i class="fa-solid fa-print me-1"></i>Imprimer Fiche A4 (Tous)
        </a>
    </div>
</div>

<!-- Filtres de recherche (Écran) -->
<div class="glass-card p-3 mb-4 no-print">
    <form method="GET" action="price-sheet.php" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label for="search" class="form-label small fw-bold text-secondary">Recherche Produit / SKU</label>
            <input type="text" name="search" id="search" class="form-control form-control-custom" placeholder="Nom ou code SKU..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label for="category_id" class="form-label small fw-bold text-secondary">Famille / Catégorie</label>
            <select name="category_id" id="category_id" class="form-select form-control-custom" onchange="this.form.submit()">
                <option value="0">Toutes les familles</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $category_filter ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="sort" class="form-label small fw-bold text-secondary">Trier par</label>
            <select name="sort" id="sort" class="form-select form-control-custom" onchange="this.form.submit()">
                <option value="category_asc" <?= $sort === 'category_asc' ? 'selected' : '' ?>>📁 Par Famille puis Nom</option>
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>🔤 Nom (A ➔ Z)</option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>🔤 Nom (Z ➔ A)</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>🏷️ Prix : Moins cher d'abord</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>🏷️ Prix : Plus cher d'abord</option>
            </select>
        </div>
        <div class="col-12 col-md-1">
            <button type="submit" class="btn btn-grad w-100 btn-sm py-2"><i class="fa-solid fa-filter"></i></button>
        </div>
    </form>
</div>

<!-- Tableau Grille Tarifaire -->
<div class="glass-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0 text-slate-800">
            <i class="fa-solid fa-list-check text-primary me-2"></i>Tarifs des Articles (<?= count($products) ?>)
        </h5>
        <span class="badge bg-light text-secondary border small no-print">Prix TTC en Francs CFA (FCFA)</span>
    </div>

    <?php if (empty($products)): ?>
        <div class="text-center py-5 text-secondary">
            <i class="fa-solid fa-box-open fa-3x mb-3 text-muted"></i>
            <p class="mb-0">Aucun produit trouvé pour ces critères.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="salePricesTable">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 120px;">Code / SKU</th>
                        <th>Désignation du Produit</th>
                        <th style="width: 220px;">Famille / Catégorie</th>
                        <th class="text-center" style="width: 100px;">Unité</th>
                        <th class="text-end" style="width: 180px;">Prix de Vente (FCFA)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $current_cat = null;
                    foreach ($products as $p): 
                        $sale_price = floatval($p['price_sale']);
                    ?>
                        <tr>
                            <td class="font-monospace fw-bold text-secondary small">
                                <?= htmlspecialchars($p['sku'] ?: ('PRD-' . $p['id'])) ?>
                            </td>
                            <td>
                                <span class="fw-bold text-slate-900"><?= htmlspecialchars($p['name']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category_name'] ?? 'Général') ?></span>
                            </td>
                            <td class="text-center text-secondary small">
                                <?= htmlspecialchars($p['unit'] ?: 'UNITE') ?>
                            </td>
                            <td class="text-end fw-extrabold text-success fs-6">
                                <?= $sale_price > 0 ? number_format($sale_price, 0, '.', ' ') . ' FCFA' : '<span class="text-danger fw-normal small">À définir</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2 no-print">
                <div class="small text-secondary">
                    Affichage de <strong><?= count($products) ?></strong> sur <strong><?= $total_products ?></strong> articles (Page <?= $page ?> sur <?= $total_pages ?>)
                </div>
                <nav aria-label="Pagination tarifs">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                <i class="fa-solid fa-chevron-left me-1"></i>Précédent
                            </a>
                        </li>

                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        if ($start_p > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a></li>
                            <?php if ($start_p > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_p; $i <= $end_p; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($end_p < $total_pages): ?>
                            <?php if ($end_p < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a></li>
                        <?php endif; ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                Suivant<i class="fa-solid fa-chevron-right ms-1"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Fonction d'export propre en CSV/Excel compatible accents français (UTF-8 avec BOM)
function exportTableToExcel(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = '\uFEFF'; // BOM UTF-8 pour ouverture parfaite dans Excel
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        const cols = row.querySelectorAll('th, td');
        const rowData = [];
        cols.forEach(col => {
            let text = col.innerText.trim();
            // Nettoyer les sauts de ligne et guillemets
            text = text.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""');
            // Enlever "FCFA" pour avoir un nombre propre dans Excel si c'est un prix
            text = text.replace(' FCFA', '');
            rowData.push('"' + text + '"');
        });
        csv += rowData.join(';') + '\r\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
<?php if ($auto_print): ?>
window.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { window.print(); }, 400);
});
<?php endif; ?>
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
