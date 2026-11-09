<?php
require_once __DIR__ . '/includes/header.php';

$active_shop_id = $_SESSION['active_shop_id'];
$user_role = $_SESSION['user_role'];

// Récupération des filtres
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$stock_filter = isset($_GET['stock_filter']) ? trim($_GET['stock_filter']) : '';

// Paramètres de filtres pour les exports et pages liées (sans pagination pour exporter tout le filtre actif)
$export_params = ['shop_id' => $active_shop_id];
if ($category_filter > 0) $export_params['category_id'] = $category_filter;
if (!empty($search)) $export_params['search'] = $search;
if (!empty($stock_filter)) $export_params['stock_filter'] = $stock_filter;
$export_qs = http_build_query($export_params);

// Récupérer toutes les catégories pour le filtre dropdown
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll();

// Construction de la requête SQL
if ($active_shop_id == 0) {
    $sql = "
        SELECT p.*, c.name as category_name, COALESCE(SUM(s.quantity), 0) as stock_qty 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock s ON p.id = s.product_id
        WHERE 1=1
    ";
    $params = [];
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
    $sql .= " ORDER BY p.name ASC";
} else {
    $sql = "
        SELECT p.*, c.name as category_name, COALESCE(s.quantity, 0) as stock_qty 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = :active_shop_id
        WHERE 1=1
    ";
    $params = [':active_shop_id' => $active_shop_id];

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

    $sql .= " ORDER BY p.name ASC";
}

// Pagination optimisée (Pilier 3)
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 40;

// 1. Calculer le nombre total via une sous-requête légère sans charger les données
$count_sql = "SELECT COUNT(*) FROM (" . $sql . ") as subq";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = intval($stmt_count->fetchColumn() ?: 0);

$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

// 2. Charger UNIQUEMENT les 40 produits de la page courante via LIMIT et OFFSET en SQL
$sql .= " LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// 3. Charger les stocks détaillés par boutique UNIQUEMENT pour les produits affichés sur la page
$all_stocks = [];
if (!empty($products)) {
    $displayed_ids = array_column($products, 'id');
    $placeholders = implode(',', array_fill(0, count($displayed_ids), '?'));
    $stmt_scoped_stocks = $pdo->prepare("
        SELECT ps.product_id, s.name as shop_name, ps.quantity 
        FROM product_stock ps 
        JOIN shops s ON ps.shop_id = s.id
        WHERE ps.product_id IN ($placeholders)
    ");
    $stmt_scoped_stocks->execute($displayed_ids);
    while ($row = $stmt_scoped_stocks->fetch()) {
        $all_stocks[$row['product_id']][] = [
            'shop_name' => $row['shop_name'],
            'quantity' => $row['quantity']
        ];
    }
}
?>



<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $_SESSION['error_message'] ?>
        <?php unset($_SESSION['error_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $_SESSION['success_message'] ?>
        <?php unset($_SESSION['success_message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Title and Add Button -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-boxes-stacked"></i></span> 
            Catalogue des Produits
        </h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="stats-stock.php?<?= $export_qs ?>" class="btn btn-warning text-dark btn-sm fw-bold shadow-sm no-print" style="border-radius: 8px;" title="Consulter l'état de stock avec tous les filtres actuels">
                <i class="fa-solid fa-chart-pie me-1"></i>État & Stats du Stock
            </a>
            <button type="button" class="btn btn-outline-warning text-dark btn-sm fw-bold shadow-sm no-print" data-bs-toggle="modal" data-bs-target="#inventoryPrintModal" style="border-radius: 8px;" title="Fiche de comptage physique (tous les articles filtrés)">
                <i class="fa-solid fa-clipboard-list me-1"></i>Fiche de Comptage
            </button>
            <a href="export-catalog-excel.php?<?= $export_qs ?>" class="btn btn-outline-success btn-sm no-print fw-bold shadow-sm" style="border-radius: 8px;" title="Exporter tous les articles filtrés au format Excel (.xlsx)">
                <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel (.xlsx)
            </a>
            <a href="print-catalog.php?<?= $export_qs ?>" target="_blank" class="btn btn-outline-secondary btn-sm no-print fw-semibold" style="border-radius: 8px;" title="Imprimer le catalogue complet de tous les articles filtrés">
                <i class="fa-solid fa-print me-1"></i>Imprimer
            </a>
            <a href="price-sheet.php?<?= $export_qs ?>" class="btn btn-outline-primary btn-sm no-print fw-bold shadow-sm" style="border-radius: 8px;" title="Consulter la grille des prix de vente employés avec les filtres actuels">
                <i class="fa-solid fa-tags me-1"></i>Grille Tarifs Employés
            </a>
            <?php if ($user_role === 'admin'): ?>
                <a href="product-prices.php?<?= $export_qs ?>" class="btn btn-warning text-dark btn-sm no-print fw-bold shadow-sm" style="border-radius: 8px;" title="Mettre à jour les prix avec les filtres actuels">
                    <i class="fa-solid fa-calculator me-1"></i>Mettre à Jour les Prix (Écran & Excel)
                </a>
                <a href="categories.php" class="btn btn-outline-secondary btn-sm no-print" style="border-radius: 8px;">
                    <i class="fa-solid fa-tags me-1"></i>Catégories
                </a>
            <?php endif; ?>
            <a href="product-add.php" class="btn btn-grad btn-sm py-1.5 px-3">
                <i class="fa-solid fa-plus me-1"></i>Ajouter un produit
            </a>
        </div>


    </div>
</div>

<!-- Filters Panel -->
<div class="row mb-4">
    <div class="col-12">
        <div class="glass-card p-4">
            <form method="GET" action="products.php" class="row g-3">
                <!-- Search -->
                <div class="col-12 col-md-4">
                    <label for="search" class="form-label small fw-semibold text-secondary">Recherche par Nom</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                        <input type="text" class="form-control form-control-custom border-start-0 ps-0" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher...">
                    </div>
                </div>
                
                <!-- Category Filter -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="category_id" class="form-label small fw-semibold text-secondary">Catégorie</label>
                    <select class="form-select form-control-custom" id="category_id" name="category_id">
                        <option value="0">Toutes les catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $category_filter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Stock Status Filter -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="stock_filter" class="form-label small fw-semibold text-secondary">État du Stock</label>
                    <select class="form-select form-control-custom" id="stock_filter" name="stock_filter">
                        <option value="">Tous les états</option>
                        <option value="low_stock" <?= $stock_filter === 'low_stock' ? 'selected' : '' ?>>Stock Bas / Alertes</option>
                        <option value="out_of_stock" <?= $stock_filter === 'out_of_stock' ? 'selected' : '' ?>>Rupture de Stock</option>
                    </select>
                </div>
                
                <!-- Filter Buttons -->
                <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-accent w-100"><i class="fa-solid fa-filter me-1"></i>Filtrer</button>
                    <a href="products.php" class="btn btn-outline-secondary w-100" style="border-radius: 10px;" title="Réinitialiser"><i class="fa-solid fa-rotate-left"></i></a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Products Table List -->
<div class="row">
    <div class="col-12">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-list-check text-primary me-2"></i>Articles Trouvés</h5>
                <span class="badge bg-secondary-subtle text-secondary py-2 px-3 fw-bold rounded-pill"><?= count($products) ?> produit(s)</span>
            </div>
            
            <?php if (empty($products)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="fa-regular fa-face-frown fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">Aucun produit ne correspond à vos critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle" id="productsTable">
                        <thead>

                            <tr>
                                <th>Designation</th>
                                <th>Catégorie</th>
                                <th>Unité</th>
                                <?php if ($user_role === 'admin'): ?>
                                    <th class="text-end">Prix Achat</th>
                                <?php endif; ?>
                                <th class="text-end">Prix Vente</th>
                                <?php if ($user_role === 'admin'): ?>
                                    <th class="text-center">Marge négoc.</th>
                                <?php endif; ?>
                                <th class="text-center">Stock</th>
                                <th class="text-end no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $prod): ?>
                                <?php 
                                    $is_low = $prod['stock_qty'] <= $prod['min_stock_level'];
                                    $is_out = $prod['stock_qty'] == 0;
                                    $row_class = '';
                                    if ($is_out) {
                                        $row_class = 'critical-stock-row';
                                    } elseif ($is_low) {
                                        $row_class = 'low-stock-row';
                                    }
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td>
                                        <div class="fw-semibold text-slate-800"><?= htmlspecialchars($prod['name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($prod['category_name'] ?? 'Non classé') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($prod['unit']) ?></span>
                                    </td>
                                    <?php if ($user_role === 'admin'): ?>
                                        <td class="text-end fw-semibold">
                                            <?= number_format($prod['price_purchase'], 0, '.', ' ') ?> FCFA
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-end fw-semibold text-primary">
                                        <?= number_format($prod['price_sale'], 0, '.', ' ') ?> FCFA
                                    </td>
                                    <?php if ($user_role === 'admin'): ?>
                                        <td class="text-center text-secondary small">
                                            <?= htmlspecialchars($prod['margin_percent'] ?? '5.00') ?> %
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-center">
                                        <?php if ($is_out): ?>
                                            <span class="badge bg-danger text-white py-2 px-3 fw-bold rounded-pill">Rupture</span>
                                        <?php elseif ($is_low): ?>
                                            <span class="badge bg-warning text-dark py-2 px-3 fw-bold rounded-pill"><?= $prod['stock_qty'] ?> (Stock bas)</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success py-2 px-3 fw-bold rounded-pill"><?= $prod['stock_qty'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end no-print">
                                        <div class="d-flex justify-content-end gap-1">
                                            <!-- Bouton Traçabilité / Historique Produit -->
                                            <a href="product-history.php?id=<?= $prod['id'] ?>" class="btn btn-outline-info btn-sm" style="border-radius: 8px;" title="Traçabilité / Historique complet du produit">
                                                <i class="fa-solid fa-clock-rotate-left"></i>
                                            </a>

                                            <!-- Bouton Détails (Modal) -->
                                            <button class="btn btn-outline-primary btn-sm btn-details" 
                                                    data-name="<?= htmlspecialchars($prod['name']) ?>" 
                                                    data-sku="<?= htmlspecialchars($prod['sku'] ?? '') ?>"
                                                    data-id="<?= $prod['id'] ?>"
                                                    data-category="<?= htmlspecialchars($prod['category_name'] ?? 'Non classé') ?>"
                                                    data-unit="<?= htmlspecialchars($prod['unit']) ?>"
                                                    data-min-stock="<?= $prod['min_stock_level'] ?>"
                                                    data-sale="<?= number_format($prod['price_sale'], 0, '.', ' ') ?> FCFA"
                                                    <?php if ($user_role === 'admin'): ?>
                                                        data-purchase="<?= number_format($prod['price_purchase'], 0, '.', ' ') ?> FCFA"
                                                    <?php endif; ?>
                                                    data-stocks='<?= json_encode($all_stocks[$prod['id']] ?? []) ?>'
                                                    style="border-radius: 8px;" title="Détails">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>

                                            <?php if ($user_role === 'admin'): ?>
                                                <a href="product-edit.php?id=<?= $prod['id'] ?>" class="btn btn-outline-warning btn-sm" style="border-radius: 8px;" title="Modifier">
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </a>
                                                <a href="product-delete.php?id=<?= $prod['id'] ?>" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce produit du catalogue ?');">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Barre de Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                        <div class="small text-secondary fw-semibold">
                            Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($products)) ?> sur <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> article(s)
                        </div>
                        <nav aria-label="Pagination produits">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $query_params = $_GET;
                                if ($current_page > 1):
                                    $query_params['page'] = $current_page - 1;
                                    echo '<li class="page-item"><a class="page-link" href="products.php?' . http_build_query($query_params) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                endif;

                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);

                                if ($start_page > 1) {
                                    $query_params['page'] = 1;
                                    echo '<li class="page-item"><a class="page-link" href="products.php?' . http_build_query($query_params) . '">1</a></li>';
                                    if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }

                                for ($p = $start_page; $p <= $end_page; $p++):
                                    $query_params['page'] = $p;
                                    $active_cls = ($p == $current_page) ? 'active' : '';
                                    echo '<li class="page-item ' . $active_cls . '"><a class="page-link" href="products.php?' . http_build_query($query_params) . '">' . $p . '</a></li>';
                                endfor;

                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    $query_params['page'] = $total_pages;
                                    echo '<li class="page-item"><a class="page-link" href="products.php?' . http_build_query($query_params) . '">' . $total_pages . '</a></li>';
                                }

                                if ($current_page < $total_pages):
                                    $query_params['page'] = $current_page + 1;
                                    echo '<li class="page-item"><a class="page-link" href="products.php?' . http_build_query($query_params) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                                endif;
                                ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Détails du Produit -->
<div class="modal fade" id="productDetailsModal" tabindex="-1" aria-labelledby="productDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header bg-light border-0 py-3" style="border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <h5 class="modal-title fw-bold text-slate-800" id="productDetailsModalLabel"><i class="fa-solid fa-info-circle text-primary me-2"></i>Détails du Produit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Fiche technique -->
                <div class="mb-4">
                    <h6 class="fw-bold text-secondary text-uppercase small mb-3">Fiche Produit</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td class="fw-semibold text-secondary" style="width: 40%;">Désignation :</td>
                                <td class="fw-bold" id="detail-name">-</td>
                            </tr>
                            <tr>
                                <td class="fw-semibold text-secondary">Famille / Catégorie :</td>
                                <td><span class="badge bg-primary-subtle text-primary fw-bold" id="detail-category">-</span></td>
                            </tr>
                            <tr>
                                <td class="fw-semibold text-secondary">Conditionnement :</td>
                                <td id="detail-unit">-</td>
                            </tr>
                            <tr>
                                <td class="fw-semibold text-secondary">Seuil d'alerte :</td>
                                <td id="detail-min-stock">-</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tarification -->
                <div class="mb-4">
                    <h6 class="fw-bold text-secondary text-uppercase small mb-3">Tarification</h6>
                    <div class="p-3 bg-light rounded-3 d-flex justify-content-between">
                        <?php if ($user_role === 'admin'): ?>
                            <div>
                                <small class="text-secondary d-block fw-semibold text-uppercase" style="font-size: 10px;">Prix d'Achat</small>
                                <span class="fw-bold text-slate-800" id="detail-purchase">-</span>
                            </div>
                        <?php endif; ?>
                        <div>
                            <small class="text-secondary d-block fw-semibold text-uppercase" style="font-size: 10px;">Prix de Vente</small>
                            <span class="fw-extrabold text-success" id="detail-sale">-</span>
                        </div>
                    </div>
                </div>

                <!-- Stocks physiques par boutique -->
                <div>
                    <h6 class="fw-bold text-secondary text-uppercase small mb-3">Stocks Disponibles par Boutique</h6>
                    <div class="list-group border-0" id="detail-stocks-list">
                        <!-- Rempli par JavaScript -->
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 10px;">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts pour gérer l'ouverture de la modal -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const detailsButtons = document.querySelectorAll('.btn-details');
    const modalElement = document.getElementById('productDetailsModal');
    const bsModal = new bootstrap.Modal(modalElement);

    // Dom Elements dans la Modal
    const domName = document.getElementById('detail-name');
    const domCategory = document.getElementById('detail-category');
    const domUnit = document.getElementById('detail-unit');
    const domMinStock = document.getElementById('detail-min-stock');
    const domPurchase = document.getElementById('detail-purchase');
    const domSale = document.getElementById('detail-sale');
    const domStocksList = document.getElementById('detail-stocks-list');

    detailsButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            // Extraction des attributs
            const id = this.getAttribute('data-id');
            const sku = this.getAttribute('data-sku');
            const name = this.getAttribute('data-name');
            const category = this.getAttribute('data-category');
            const unit = this.getAttribute('data-unit');
            const minStock = this.getAttribute('data-min-stock');
            const sale = this.getAttribute('data-sale');
            const purchase = this.getAttribute('data-purchase');
            const stocks = JSON.parse(this.getAttribute('data-stocks') || '[]');

            // Remplissage des données statiques
            domName.textContent = name;
            domCategory.textContent = category;
            domUnit.textContent = unit;
            domMinStock.textContent = minStock + ' ' + unit;
            
            if (domPurchase) {
                domPurchase.textContent = purchase || '-';
            }
            domSale.textContent = sale;

            // Remplissage des stocks par boutique
            domStocksList.innerHTML = '';
            if (stocks.length === 0) {
                domStocksList.innerHTML = '<div class="text-center py-2 text-muted small">Aucun stock disponible</div>';
            } else {
                stocks.forEach(st => {
                    const isAlert = parseInt(st.quantity) <= parseInt(minStock);
                    const qtyClass = parseInt(st.quantity) === 0 
                        ? 'bg-danger text-white' 
                        : (isAlert ? 'bg-warning text-dark' : 'bg-success-subtle text-success');
                        
                    const div = document.createElement('div');
                    div.className = 'list-group-item d-flex justify-content-between align-items-center border-0 px-0 py-2 border-bottom';
                    div.innerHTML = `
                        <div class="fw-semibold text-slate-700">${escapeHtml(st.shop_name)}</div>
                        <span class="badge ${qtyClass} rounded-pill py-2 px-3 fw-bold">${st.quantity} ${escapeHtml(unit)}</span>
                    `;
                    domStocksList.appendChild(div);
                });
            }

            // Ouvrir la modal
            bsModal.show();
        });
    });

    // Filtrage temps réel en JavaScript
    const searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.table-custom tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.setProperty('display', '', 'important');
                } else {
                    row.style.setProperty('display', 'none', 'important');
                }
            });
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
});

function printInventorySheetOnly() {
    window.print();
}
</script>

<!-- Modal Fiche d'Inventaire Physique (Comptage Réel Confidentiel sans Prix) -->
<div class="modal fade" id="inventoryPrintModal" tabindex="-1" aria-labelledby="inventoryPrintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header bg-warning bg-opacity-10 border-bottom-0 pb-3 no-print">
                <h5 class="modal-title fw-bold text-slate-900" id="inventoryPrintModalLabel">
                    <i class="fa-solid fa-clipboard-list text-warning me-2"></i>Fiche d'Inventaire Physique (Comptage Magasin)
                    <span class="badge bg-primary text-white ms-2" style="font-size: 0.75rem;"><?= $total_records ?> article(s) filtré(s)</span>
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <a href="export-inventory-excel.php?<?= $export_qs ?>" class="btn btn-outline-success btn-sm fw-bold shadow-sm" title="Télécharger la fiche de comptage complète en Excel (.xlsx)">
                        <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel (.xlsx)
                    </a>
                    <a href="print-inventory-sheet.php?<?= $export_qs ?>" target="_blank" class="btn btn-warning text-dark btn-sm fw-bold px-3 shadow-sm" title="Imprimer la fiche de comptage complète sur papier A4">
                        <i class="fa-solid fa-print me-1"></i>Imprimer Toute la Fiche A4
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            
            <div class="modal-body p-4" id="inventoryPrintArea">
                <!-- En-tête d'Impression Officiel Inventaire -->
                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                    <div>
                        <h3 class="fw-extrabold mb-1 text-slate-900 fs-5"><i class="fa-solid fa-folder-open text-primary me-2"></i>MANDEL PAPETERIE ERP</h3>
                        <h4 class="fw-bold text-secondary mb-0 fs-6">FICHE DE COMPTAGE D'INVENTAIRE PHYSIQUE DE STOCK</h4>
                    </div>
                    <div class="text-end small text-secondary">
                        <div><strong>Dépôt / Magasin :</strong> <?= htmlspecialchars($current_shop['name']) ?></div>
                        <div><strong>Date d'Édition :</strong> <?= date('d/m/Y H:i') ?></div>
                        <div><strong>Opérateur :</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Personnel') ?></div>
                    </div>
                </div>

                <div class="alert alert-warning border-0 py-2 mb-3 small d-flex align-items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-warning fs-5"></i>
                    <div>
                        <strong>Document de Comptage Réel :</strong> Aucun prix d'achat ni prix de vente ne figure sur ce document. Veuillez remplir la colonne <u>Stock Physique Réel</u> lors du contrôle en magasin.
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle" id="inventoryTablePrint" style="font-size: 0.85rem;">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 40px;" class="text-center">N°</th>
                                <th style="width: 100px;">Code / SKU</th>
                                <th>Désignation du Produit</th>
                                <th>Famille / Catégorie</th>
                                <th class="text-center" style="width: 90px;">Unité</th>
                                <th class="text-center" style="width: 110px;">Stock Théorique</th>
                                <th class="text-center" style="width: 140px; background-color: #fef08a; color: #854d0e;">Stock Physique Réel</th>
                                <th style="width: 160px;">Écarts / Remarques</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $inv_idx = 1;
                            foreach ($products as $p): 
                            ?>
                                <tr>
                                    <td class="text-center fw-bold text-secondary"><?= $inv_idx++ ?></td>
                                    <td class="fw-semibold text-slate-700"><?= htmlspecialchars($p['sku'] ?: ('PRD-' . $p['id'])) ?></td>
                                    <td class="fw-bold text-slate-900"><?= htmlspecialchars($p['name']) ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars($p['category_name'] ?? 'Non classé') ?></td>
                                    <td class="text-center text-secondary"><?= htmlspecialchars($p['unit']) ?></td>
                                    <td class="text-center fw-bold text-primary fs-6"><?= $p['stock_qty'] ?></td>
                                    <td class="text-center" style="background-color: #fffde7; height: 35px;"></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Section de signature d'inventaire -->
                <div class="row mt-4 pt-3 border-top text-center small text-secondary">
                    <div class="col-6">
                        <p class="mb-5"><strong>Nom & Signature de l'Employé (Comptage) :</strong></p>
                        <p class="text-muted">__________________________________________</p>
                    </div>
                    <div class="col-6">
                        <p class="mb-5"><strong>Nom & Signature du Responsable Stock :</strong></p>
                        <p class="text-muted">__________________________________________</p>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top-0 pt-0 no-print">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius: 10px;">Fermer</button>
                <a href="export-inventory-excel.php?<?= $export_qs ?>" class="btn btn-outline-success fw-bold px-3" style="border-radius: 10px;">
                    <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel (.xlsx)
                </a>
                <a href="print-inventory-sheet.php?<?= $export_qs ?>" target="_blank" class="btn btn-warning text-dark fw-bold px-4" style="border-radius: 10px;">
                    <i class="fa-solid fa-print me-1"></i>Imprimer Toute la Fiche A4 (<?= $total_records ?>)
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
