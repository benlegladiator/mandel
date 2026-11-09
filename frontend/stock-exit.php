<?php
require_once __DIR__ . '/includes/header.php';

// Sécurité : Seul l'administrateur a accès
if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Réservé à l'administrateur.";
    header("Location: index.php");
    exit;
}

$active_shop_id = $_SESSION['active_shop_id'] > 0 ? $_SESSION['active_shop_id'] : ($all_shops[0]['id'] ?? 1);

$error_msg = '';
$success_msg = '';

// Récupérer les catégories pour le cas "Nouveau produit" en production
$stmt_cats = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt_cats->fetchAll();

// Traitement du formulaire d'enregistrement de sortie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exit'])) {
    $reason = $_POST['reason'] ?? '';
    $src_id = intval($_POST['source_product_id'] ?? 0);
    $src_qty = intval($_POST['source_quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!in_array($reason, ['INTERNAL_USE', 'LOSS', 'PRODUCTION'])) {
        $error_msg = "Motif de sortie invalide.";
    } elseif ($src_id <= 0 || $src_qty <= 0) {
        $error_msg = "Veuillez sélectionner un article valide et une quantité supérieure à 0.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Vérifier le stock source et le prix d'achat
            $stmt_src = $pdo->prepare("
                SELECT p.*, COALESCE(s.quantity, 0) as stock_qty 
                FROM products p
                LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = ?
                WHERE p.id = ?
            ");
            $stmt_src->execute([$active_shop_id, $src_id]);
            $src_prod = $stmt_src->fetch();

            if (!$src_prod) {
                throw new Exception("L'article source est introuvable.");
            }
            if ($src_prod['stock_qty'] < $src_qty) {
                throw new Exception("Stock insuffisant. Le stock disponible pour " . $src_prod['name'] . " est de " . $src_prod['stock_qty'] . " unité(s).");
            }

            $unit_cost = floatval($src_prod['price_purchase'] ?? 0);
            $total_cost = $unit_cost * $src_qty;

            // 2. Générer la référence de sortie (ex: EX00012)
            $stmt_last_ref = $pdo->prepare("SELECT reference FROM stock_exits ORDER BY id DESC LIMIT 1");
            $stmt_last_ref->execute();
            $last_ref = $stmt_last_ref->fetchColumn();
            $next_num = 1;
            if ($last_ref && preg_match('/^EX(\d+)$/', $last_ref, $matches)) {
                $next_num = intval($matches[1]) + 1;
            }
            $reference = 'EX' . sprintf("%05d", $next_num);

            $expense_id = null;

            if ($reason === 'INTERNAL_USE') {
                // Créer une dépense avec les bonnes colonnes de la table expenses
                $stmt_exp = $pdo->prepare("
                    INSERT INTO expenses (shop_id, user_id, amount, category, expense_date, notes, status) 
                    VALUES (?, ?, ?, 'CONSOMMATION INTERNE', ?, ?, 'ACKNOWLEDGED')
                ");
                $desc = "Consommation interne : " . $src_prod['name'] . " (Qté: $src_qty)" . ($notes ? " — $notes" : "");
                $stmt_exp->execute([
                    $active_shop_id,
                    $_SESSION['user_id'],
                    $total_cost,
                    date('Y-m-d'),
                    $desc
                ]);
                $expense_id = $pdo->lastInsertId();


                // Enregistrer dans le Journal de Caisse (cash_transactions) comme dépense sortante (OUT)
                $stmt_cash = $pdo->prepare("
                    INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type)
                    VALUES (?, ?, 'OUT', ?, ?, ?, 'EXPENSE')
                ");
                $stmt_cash->execute([
                    $active_shop_id,
                    $_SESSION['user_id'],
                    $total_cost,
                    "Consommation Interne : " . $src_prod['name'] . " (Qté: $src_qty)",
                    $expense_id
                ]);

                $stmt_exit = $pdo->prepare("
                    INSERT INTO stock_exits (reference, shop_id, user_id, reason, total_value, notes, expense_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_exit->execute([
                    $reference, $active_shop_id, $_SESSION['user_id'], $reason, $total_cost, $notes, $expense_id
                ]);
                $stock_exit_id = $pdo->lastInsertId();

                $stmt_exit_item = $pdo->prepare("
                    INSERT INTO stock_exit_items (stock_exit_id, product_id, target_product_id, quantity, target_quantity, unit_cost, total_cost) 
                    VALUES (?, ?, NULL, ?, NULL, ?, ?)
                ");
                $stmt_exit_item->execute([
                    $stock_exit_id, $src_id, $src_qty, $unit_cost, $total_cost
                ]);
            } 
            elseif ($reason === 'LOSS') {
                $stmt_exit = $pdo->prepare("
                    INSERT INTO stock_exits (reference, shop_id, user_id, reason, total_value, notes, expense_id) 
                    VALUES (?, ?, ?, ?, ?, ?, NULL)
                ");
                $stmt_exit->execute([
                    $reference, $active_shop_id, $_SESSION['user_id'], $reason, $total_cost, $notes
                ]);
                $stock_exit_id = $pdo->lastInsertId();

                $stmt_exit_item = $pdo->prepare("
                    INSERT INTO stock_exit_items (stock_exit_id, product_id, target_product_id, quantity, target_quantity, unit_cost, total_cost) 
                    VALUES (?, ?, NULL, ?, NULL, ?, ?)
                ");
                $stmt_exit_item->execute([
                    $stock_exit_id, $src_id, $src_qty, $unit_cost, $total_cost
                ]);
            } 
            elseif ($reason === 'PRODUCTION') {
                $target_product_inputs = $_POST['target_product_ids'] ?? [];
                $target_quantities = $_POST['target_quantities'] ?? [];
                $target_unit_costs_input = $_POST['target_unit_costs'] ?? [];
                $new_target_names = $_POST['new_target_names'] ?? [];
                $new_target_categories = $_POST['new_target_category_ids'] ?? [];
                $new_target_units = $_POST['new_target_units'] ?? [];
                $new_price_sales = $_POST['new_price_sales'] ?? [];

                if (empty($target_product_inputs)) {
                    throw new Exception("Pour une production/transformation, veuillez ajouter au moins un produit cible (résultat de la découpe/fabrication).");
                }

                $stmt_exit = $pdo->prepare("
                    INSERT INTO stock_exits (reference, shop_id, user_id, reason, total_value, notes, expense_id) 
                    VALUES (?, ?, ?, ?, ?, ?, NULL)
                ");
                $stmt_exit->execute([
                    $reference, $active_shop_id, $_SESSION['user_id'], $reason, $total_cost, $notes
                ]);
                $stock_exit_id = $pdo->lastInsertId();

                $stmt_add_stock = $pdo->prepare("
                    INSERT INTO product_stock (product_id, shop_id, quantity) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ");

                $stmt_exit_item = $pdo->prepare("
                    INSERT INTO stock_exit_items (stock_exit_id, product_id, target_product_id, quantity, target_quantity, unit_cost, total_cost) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $all_shops_list = $pdo->query("SELECT id FROM shops")->fetchAll();
                $target_count = count($target_product_inputs);

                for ($k = 0; $k < $target_count; $k++) {
                    $target_input = trim($target_product_inputs[$k] ?? '');
                    $target_qty = intval($target_quantities[$k] ?? 0);
                    $user_cost_val = (isset($target_unit_costs_input[$k]) && $target_unit_costs_input[$k] !== '') ? floatval($target_unit_costs_input[$k]) : -1;

                    if (empty($target_input) || $target_qty <= 0) {
                        throw new Exception("Chaque ligne de produit créé doit avoir un article sélectionné et une quantité supérieure à 0.");
                    }

                    $target_id = 0;

                    // Déterminer le coût de production unitaire pour cette ligne
                    $line_unit_cost = ($user_cost_val >= 0) ? $user_cost_val : ($total_cost / ($target_qty * $target_count));
                    $line_total_cost = $line_unit_cost * $target_qty;

                    if ($target_input === 'NEW') {
                        $new_name = trim($new_target_names[$k] ?? '');
                        $new_cat_id = intval($new_target_categories[$k] ?? 0);
                        $new_unit = !empty($new_target_units[$k]) ? trim($new_target_units[$k]) : 'UNITE';
                        $new_price = floatval($new_price_sales[$k] ?? 0);

                        if (empty($new_name) || $new_cat_id <= 0) {
                            throw new Exception("Le nom et la catégorie sont obligatoires pour le nouvel article à la ligne " . ($k + 1));
                        }

                        $max_id_stmt = $pdo->query("SELECT MAX(id) as max_id FROM products");
                        $target_id = intval($max_id_stmt->fetch()['max_id'] ?? 0) + 1;
                        $sku = sprintf("ART-%04d", $target_id);

                        $stmt_new = $pdo->prepare("
                            INSERT INTO products (id, name, sku, category_id, unit, price_purchase, price_sale, margin_percent, min_stock_level) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 5.00, 5)
                        ");
                        $stmt_new->execute([
                            $target_id, $new_name, $sku, $new_cat_id, $new_unit, $line_unit_cost, $new_price
                        ]);

                        $stmt_stock_init = $pdo->prepare("INSERT IGNORE INTO product_stock (product_id, shop_id, quantity) VALUES (?, ?, 0)");
                        foreach ($all_shops_list as $s) {
                            $stmt_stock_init->execute([$target_id, $s['id']]);
                        }
                    } else {
                        $target_id = intval($target_input);

                        // Calcul du Prix Moyen Pondéré (PMP) si le produit possède déjà du stock
                        $stmt_tot_stk = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM product_stock WHERE product_id = ?");
                        $stmt_tot_stk->execute([$target_id]);
                        $current_tot_stock = floatval($stmt_tot_stk->fetchColumn() ?: 0);

                        $stmt_cur_price = $pdo->prepare("SELECT price_purchase FROM products WHERE id = ?");
                        $stmt_cur_price->execute([$target_id]);
                        $current_price_purchase = floatval($stmt_cur_price->fetchColumn() ?: 0);

                        if ($current_tot_stock > 0 && $line_unit_cost > 0) {
                            $new_pmp = (($current_tot_stock * $current_price_purchase) + ($target_qty * $line_unit_cost)) / ($current_tot_stock + $target_qty);
                        } else {
                            $new_pmp = $line_unit_cost;
                        }

                        // Mettre à jour le prix d'achat/production (PMP) dans la base de données
                        $stmt_upd_pmp = $pdo->prepare("UPDATE products SET price_purchase = ? WHERE id = ?");
                        $stmt_upd_pmp->execute([$new_pmp, $target_id]);
                    }

                    if ($target_id === $src_id) {
                        throw new Exception("Un produit cible ne peut pas être identique au produit source.");
                    }

                    // Augmenter le stock du produit cible
                    $stmt_add_stock->execute([$target_id, $active_shop_id, $target_qty, $target_qty]);

                    // Insérer l'item de sortie avec le coût unitaire et total calculé de la fournée
                    $stmt_exit_item->execute([
                        $stock_exit_id, $src_id, $target_id, $src_qty, $target_qty, $line_unit_cost, $line_total_cost
                    ]);
                }
            }

            // 4. Diminuer le stock source
            $pdo->prepare("UPDATE product_stock SET quantity = quantity - ? WHERE product_id = ? AND shop_id = ?")->execute([$src_qty, $src_id, $active_shop_id]);

            $pdo->commit();
            $success_msg = "Mouvement de sortie / transformation multi-produits ($reference) enregistré avec succès.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
}

// Traitement de l'annulation / suppression d'un mouvement de sortie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_exit'])) {
    $exit_id_to_delete = intval($_POST['exit_id'] ?? 0);
    if ($exit_id_to_delete <= 0) {
        $error_msg = "Mouvement de sortie introuvable.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Récupérer le mouvement
            $stmt_get_exit = $pdo->prepare("SELECT * FROM stock_exits WHERE id = ?");
            $stmt_get_exit->execute([$exit_id_to_delete]);
            $exit_rec = $stmt_get_exit->fetch();

            if (!$exit_rec) {
                throw new Exception("Le mouvement de sortie sélectionné n'existe pas.");
            }

            $shop_id = intval($exit_rec['shop_id']);

            // 2. Récupérer tous les items de ce mouvement
            $stmt_items = $pdo->prepare("SELECT * FROM stock_exit_items WHERE stock_exit_id = ?");
            $stmt_items->execute([$exit_id_to_delete]);
            $exit_items = $stmt_items->fetchAll();

            $source_restored = false;
            foreach ($exit_items as $item) {
                $src_id = intval($item['product_id']);
                $src_qty = intval($item['quantity']);
                $tgt_id = intval($item['target_product_id']);
                $tgt_qty = intval($item['target_quantity']);

                // Restituer la quantité au stock source une seule fois par mouvement
                if (!$source_restored && $src_id > 0 && $src_qty > 0) {
                    $pdo->prepare("
                        INSERT INTO product_stock (product_id, shop_id, quantity) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE quantity = quantity + ?
                    ")->execute([$src_id, $shop_id, $src_qty, $src_qty]);
                    $source_restored = true;
                }

                // Annuler les produits cibles créés en production (Déduire du stock cible)
                if ($exit_rec['reason'] === 'PRODUCTION' && $tgt_id > 0 && $tgt_qty > 0) {
                    $pdo->prepare("
                        UPDATE product_stock 
                        SET quantity = GREATEST(0, quantity - ?) 
                        WHERE product_id = ? AND shop_id = ?
                    ")->execute([$tgt_qty, $tgt_id, $shop_id]);
                }
            }

            // 3. Si c'était une consommation interne avec dépense créée, supprimer la dépense et la transaction de caisse
            if (!empty($exit_rec['expense_id'])) {
                $pdo->prepare("DELETE FROM cash_transactions WHERE reference_id = ? AND reference_type = 'EXPENSE'")->execute([$exit_rec['expense_id']]);
                $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$exit_rec['expense_id']]);
            }

            // 4. Supprimer les enregistrements
            $pdo->prepare("DELETE FROM stock_exit_items WHERE stock_exit_id = ?")->execute([$exit_id_to_delete]);
            $pdo->prepare("DELETE FROM stock_exits WHERE id = ?")->execute([$exit_id_to_delete]);

            $pdo->commit();
            $success_msg = "Le mouvement de sortie (" . htmlspecialchars($exit_rec['reference']) . ") a été annulé avec succès. Les stocks ont été automatiquement réajustés.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Erreur lors de l'annulation : " . $e->getMessage();
        }
    }
}

// Charger tous les produits pour la sélection
$products_stmt = $pdo->prepare("
    SELECT p.id, p.name, p.sku, COALESCE(s.quantity, 0) as stock_qty, p.price_purchase 
    FROM products p
    LEFT JOIN product_stock s ON p.id = s.product_id AND s.shop_id = ?
    ORDER BY p.name
");
$products_stmt->execute([$active_shop_id]);
$all_products = $products_stmt->fetchAll();

// Pagination des sorties
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 30;
$offset = ($page - 1) * $per_page;

$count_query = "SELECT COUNT(*) FROM stock_exits se " . ($active_shop_id > 0 ? "WHERE se.shop_id = :shop_id" : "");
$count_stmt = $pdo->prepare($count_query);
if ($active_shop_id > 0) {
    $count_stmt->execute(['shop_id' => $active_shop_id]);
} else {
    $count_stmt->execute();
}
$total_exits = intval($count_stmt->fetchColumn() ?: 0);
$total_pages = max(1, ceil($total_exits / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// Charger l'historique des mouvements de sortie (Triés du plus récent au plus ancien)
$history_query = "
    SELECT se.*, u.name as user_name, sh.name as shop_name
    FROM stock_exits se
    LEFT JOIN users u ON se.user_id = u.id
    LEFT JOIN shops sh ON se.shop_id = sh.id
    " . ($active_shop_id > 0 ? "WHERE se.shop_id = :shop_id" : "") . "
    ORDER BY se.created_at DESC
    LIMIT :limit OFFSET :offset
";
$history_stmt = $pdo->prepare($history_query);
if ($active_shop_id > 0) {
    $history_stmt->bindValue(':shop_id', $active_shop_id, PDO::PARAM_INT);
}
$history_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$history_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$history_stmt->execute();
$exits_history = $history_stmt->fetchAll();

// Récupérer les items associés aux sorties
$exit_ids = array_column($exits_history, 'id');
$exit_items_map = [];

if (!empty($exit_ids)) {
    $in_clause = implode(',', array_map('intval', $exit_ids));
    $items_stmt = $pdo->query("
        SELECT sei.*, 
               p_src.name as source_product_name, p_src.unit as source_unit,
               p_tgt.name as target_product_name, p_tgt.unit as target_unit
        FROM stock_exit_items sei
        LEFT JOIN products p_src ON sei.product_id = p_src.id
        LEFT JOIN products p_tgt ON sei.target_product_id = p_tgt.id
        WHERE sei.stock_exit_id IN ($in_clause)
        ORDER BY sei.id ASC
    ");
    $all_exit_items = $items_stmt->fetchAll();
    foreach ($all_exit_items as $item) {
        $exit_items_map[$item['stock_exit_id']][] = $item;
    }
}

// Regrouper l'historique par Jour / Date (du plus récent au plus ancien)
$grouped_exits = [];
foreach ($exits_history as $exit) {
    $date_key = date('Y-m-d', strtotime($exit['created_at']));
    $grouped_exits[$date_key][] = $exit;
}

function formatFrenchDateHeader($date_str) {
    $timestamp = strtotime($date_str);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $months = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    $day_name = $days[date('w', $timestamp)];
    $day_num = date('d', $timestamp);
    $month_name = $months[intval(date('m', $timestamp))];
    $year = date('Y', $timestamp);

    $formatted = "$day_name $day_num $month_name $year";

    if ($date_str === $today) {
        return "Aujourd'hui - " . $formatted;
    } elseif ($date_str === $yesterday) {
        return "Hier - " . $formatted;
    } else {
        $week_num = date('W', $timestamp);
        return "$formatted (Semaine $week_num)";
    }
}
?>

<!-- Title Banner -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <i class="fa-solid fa-arrow-right-from-bracket text-danger me-2"></i>Mouvement de Sortie & Production
        </h1>
        <div class="text-secondary small">
            Dépôt actif : <span class="fw-bold text-primary"><?= htmlspecialchars($current_shop['name'] ?? 'Inconnu') ?></span>
        </div>
    </div>
</div>

<?php if ($success_msg): ?>
    <div class="alert alert-success border-0 shadow-sm" style="border-radius: 12px;"><i class="fa-solid fa-check-circle me-2"></i><?= $success_msg ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px;"><i class="fa-solid fa-circle-exclamation me-2"></i><?= $error_msg ?></div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-12">
        <div class="glass-card p-4">
            <form action="stock-exit.php" method="POST" id="stockExitForm">
                
                <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">Informations Générales</h5>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Motif de la sortie <span class="text-danger">*</span></label>
                        <select name="reason" id="reason" class="form-select" required onchange="toggleProductionFields()">
                            <option value="">Sélectionnez un motif...</option>
                            <option value="INTERNAL_USE">Consommation interne (Charge)</option>
                            <option value="LOSS">Perte / Casse (Perte financière)</option>
                            <option value="PRODUCTION">Production / Découpe Multi-Produits</option>
                        </select>
                        <div class="form-text small">Le motif Production permet de retirer une matière et d'ajouter 1 ou plusieurs produits découpés.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Description / Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Justificatif de la sortie ou détails de transformation (Optionnel)">
                    </div>
                </div>

                <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">Article Consommé / Découpé (Matière Première)</h5>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-8 position-relative">
                        <label class="form-label fw-semibold">Sélectionnez l'article source <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-secondary"><i class="fa-solid fa-magnifying-glass text-primary"></i></span>
                            <input type="text" id="quickSourceSearch" class="form-control border-start-0 ps-0" placeholder="Tapez pour rechercher (ex: Couché 250g, Ramette, Cahier...)" autocomplete="off" oninput="handleSourceSearch(this.value)">
                        </div>
                        <div id="sourceSearchResults" class="list-group position-absolute w-100 shadow-lg border mt-1 d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto; border-radius: 8px;">
                            <!-- Résultats instantanés -->
                        </div>
                        
                        <!-- Affichage du produit source sélectionné -->
                        <div id="selectedSourceDisplay" class="mt-2 p-2 bg-light rounded border d-none">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="fw-bold text-slate-800" id="selectedSourceName">-</span>
                                    <span class="badge bg-primary ms-2" id="selectedSourceStock">Stock : 0</span>
                                </div>
                                <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="resetSourceProduct()"><i class="fa-solid fa-times me-1"></i>Changer</button>
                            </div>
                        </div>

                        <!-- Select caché pour soumission du formulaire -->
                        <input type="hidden" name="source_product_id" id="source_product_id" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Quantité Consommée <span class="text-danger">*</span></label>
                        <input type="number" name="source_quantity" id="source_quantity" class="form-control" min="1" required>
                    </div>
                </div>

                <!-- SECTION PRODUCTION MULTI-PRODUITS -->
                <div id="production_section" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2 mt-4 flex-wrap gap-2">
                        <h5 class="fw-bold text-primary mb-0">
                            <i class="fa-solid fa-industry me-2"></i>Articles Produits / Découpés en Résultat (Multi-Produits)
                        </h5>
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold" onclick="addTargetRow()">
                            <i class="fa-solid fa-plus me-1"></i>Ajouter un article produit
                        </button>
                    </div>

                    <div id="target_rows_container" class="d-flex flex-column gap-3 mb-4">
                        <!-- Les lignes de produits cibles s'affichent ici -->
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" name="submit_exit" class="btn btn-danger px-4 py-2 fw-bold" onclick="return confirm('Confirmer le mouvement de stock ? L\'opération mettra à jour les stocks immédiatement.');">
                        <i class="fa-solid fa-check me-2"></i>Valider la Sortie / Transformation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- HISTORIQUE DES MOUVEMENTS DE SORTIE & TRANSFORMATIONS -->
<div class="row mb-4">
    <div class="col-12">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold text-slate-800 mb-0">
                        <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Historique des Mouvements de Sortie & Transformations
                    </h5>
                    <small class="text-muted">Cliquez sur n'importe quelle ligne pour consulter l'explication complète et détaillée du mouvement.</small>
                </div>
                <div class="position-relative" style="min-width: 260px;">
                    <input type="text" id="searchExitHistory" class="form-control form-control-sm ps-4" placeholder="Rechercher dans l'historique..." onkeyup="filterExitHistory()">
                    <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="left: 10px; top: 50%; transform: translateY(-50%); font-size: 0.8rem;"></i>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="exitHistoryTable">
                    <thead class="table-light">
                        <tr>
                            <th>Référence & Heure</th>
                            <th>Motif</th>
                            <th>Article Consommé / Sorti</th>
                            <th>Résultat / Produit(s) Obtenu(s)</th>
                            <th class="text-end">Valeur Totale</th>
                            <th>Auteur / Dépôt</th>
                            <th>Notes</th>
                            <th class="text-center" style="width: 90px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grouped_exits)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fa-regular fa-folder-open fa-2x mb-2 d-block text-secondary"></i>
                                    Aucun mouvement de sortie enregistré pour le moment.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grouped_exits as $date_key => $date_exits): ?>
                                <!-- En-tête de groupe de Date / Journée (Semaine) -->
                                <tr class="group-header-row" data-date="<?= $date_key ?>">
                                    <td colspan="8" class="fw-bold py-2 px-3 text-slate-800" style="background-color: #f1f5f9; border-top: 2px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
                                        <i class="fa-solid fa-calendar-day me-2 text-primary"></i><?= formatFrenchDateHeader($date_key) ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle ms-2 fw-semibold" style="font-size: 0.75rem;">
                                            <?= count($date_exits) ?> mouvement(s)
                                        </span>
                                    </td>
                                </tr>

                                <?php foreach ($date_exits as $exit): ?>
                                    <?php 
                                    $items = $exit_items_map[$exit['id']] ?? [];
                                    $reason_label = '';
                                    $reason_badge = 'bg-secondary';
                                    if ($exit['reason'] === 'INTERNAL_USE') {
                                        $reason_label = 'Consommation Interne';
                                        $reason_badge = 'bg-info text-dark';
                                    } elseif ($exit['reason'] === 'LOSS') {
                                        $reason_label = 'Perte / Casse';
                                        $reason_badge = 'bg-danger text-white';
                                    } elseif ($exit['reason'] === 'PRODUCTION') {
                                        $reason_label = 'Production / Découpe';
                                        $reason_badge = 'bg-primary text-white';
                                    }

                                    // Préparer le JSON pour le Modal de détails
                                    $modal_items = [];
                                    foreach ($items as $it) {
                                        $modal_items[] = [
                                            'source_name' => $it['source_product_name'] ?? 'Article inconnu',
                                            'source_qty' => intval($it['quantity']),
                                            'source_unit' => $it['source_unit'] ?? 'UNITE',
                                            'target_name' => $it['target_product_name'] ?? '',
                                            'target_qty' => intval($it['target_quantity'] ?? 0),
                                            'target_unit' => $it['target_unit'] ?? 'UNITE',
                                            'unit_cost' => number_format(floatval($it['unit_cost']), 0, '.', ' ') . ' FCFA',
                                            'total_cost' => number_format(floatval($it['total_cost']), 0, '.', ' ') . ' FCFA'
                                        ];
                                    }

                                    $exit_json = json_encode([
                                        'id' => $exit['id'],
                                        'reference' => $exit['reference'],
                                        'created_at' => date('d/m/Y à H:i', strtotime($exit['created_at'])),
                                        'reason_code' => $exit['reason'],
                                        'reason_label' => $reason_label,
                                        'reason_badge' => $reason_badge,
                                        'user_name' => $exit['user_name'] ?? 'Système',
                                        'shop_name' => $exit['shop_name'] ?? 'Global',
                                        'total_value' => number_format(floatval($exit['total_value']), 0, '.', ' ') . ' FCFA',
                                        'notes' => $exit['notes'] ?: 'Aucune remarque saisie',
                                        'items' => $modal_items
                                    ]);
                                    ?>
                                    <tr class="exit-row align-middle" data-date-group="<?= $date_key ?>" style="cursor: pointer;" onclick="openExitDetails(this)" data-exit-data="<?= htmlspecialchars($exit_json, ENT_QUOTES, 'UTF-8') ?>">
                                        <td>
                                            <span class="fw-bold text-dark d-block"><?= htmlspecialchars($exit['reference']) ?></span>
                                            <small class="text-muted"><i class="fa-regular fa-clock me-1"></i><?= date('H:i', strtotime($exit['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?= $reason_badge ?> py-1 px-2 fw-semibold">
                                                <?= $reason_label ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($items)): ?>
                                                <?php 
                                                $first_item = $items[0];
                                                $src_name = $first_item['source_product_name'] ?? 'Article inconnu';
                                                $src_unit = $first_item['source_unit'] ?? '';
                                                ?>
                                                <span class="fw-bold text-slate-800"><?= htmlspecialchars($src_name) ?></span>
                                                <div class="small text-danger fw-semibold">
                                                    -<?= intval($first_item['quantity']) ?> <?= htmlspecialchars($src_unit) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($exit['reason'] === 'PRODUCTION' && !empty($items)): ?>
                                                <ul class="list-unstyled mb-0 small">
                                                    <?php foreach ($items as $it): ?>
                                                        <?php if (!empty($it['target_product_name'])): ?>
                                                            <li class="text-success fw-semibold mb-1">
                                                                <i class="fa-solid fa-arrow-right text-success me-1" style="font-size: 0.75rem;"></i>
                                                                +<?= intval($it['target_quantity']) ?> <?= htmlspecialchars($it['target_unit'] ?? '') ?> 
                                                                <span class="text-dark">(<?= htmlspecialchars($it['target_product_name']) ?>)</span>
                                                            </li>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span class="text-muted small"><em>N/A</em></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-extrabold text-slate-800">
                                            <?= number_format(floatval($exit['total_value']), 0, '.', ' ') ?> FCFA
                                        </td>
                                        <td>
                                            <span class="d-block small text-dark fw-semibold"><?= htmlspecialchars($exit['user_name'] ?? 'Système') ?></span>
                                            <small class="text-muted"><?= htmlspecialchars($exit['shop_name'] ?? '') ?></small>
                                        </td>
                                        <td>
                                            <small class="text-secondary"><?= htmlspecialchars($exit['notes'] ?: '-') ?></small>
                                        </td>
                                        <td class="text-center" onclick="event.stopPropagation();">
                                            <form action="stock-exit.php" method="POST" class="d-inline" onsubmit="return confirm('⚠️ ATTENTION : Êtes-vous sûr de vouloir annuler ce mouvement de sortie (<?= htmlspecialchars($exit['reference']) ?>) ?\n\n- La matière première consommé sera restituée au stock.\n- Les produits découpés/fabriqués seront déduits du stock.');">
                                                <input type="hidden" name="exit_id" value="<?= $exit['id'] ?>">
                                                <button type="submit" name="delete_exit" class="btn btn-outline-danger btn-sm py-1 px-2" title="Annuler / Supprimer ce mouvement">
                                                    <i class="fa-solid fa-rotate-left me-1"></i>Annuler
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2">
                    <div class="small text-secondary">
                        Affichage de <strong><?= count($exits_history) ?></strong> sur <strong><?= $total_exits ?></strong> mouvements (Page <?= $page ?> sur <?= $total_pages ?>)
                    </div>
                    <nav aria-label="Pagination sorties">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Page Précédente -->
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

                            <!-- Page Suivante -->
                            <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                    Suivant<i class="fa-solid fa-chevron-right ms-1"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL DÉTAILS MOUVEMENT DE SORTIE -->
<div class="modal fade" id="exitDetailsModal" tabindex="-1" aria-labelledby="exitDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow-lg border-0" style="border-radius: 16px;">
            <div class="modal-header bg-light border-bottom py-3">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="modal-title fw-bold text-slate-800" id="modalExitRef">EX00000</h5>
                    <span class="badge bg-primary px-2.5 py-1 fw-semibold" id="modalExitBadge">Production</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body p-4" id="modalExitBody">
                <!-- Injection dynamique des détails -->
            </div>
            <div class="modal-footer bg-light border-top py-2">
                <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal" style="border-radius: 8px;">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Templates HTML fiables et sécurisés pour les options de produits et catégories -->
<template id="target_product_options_template">
    <option value="">Sélectionnez l'article résultant...</option>
    <option value="NEW" class="fw-bold text-success">+ Créer un nouvel article</option>
    <?php foreach($all_products as $p): ?>
        <option value="<?= $p['id'] ?>" data-price="<?= floatval($p['price_purchase']) ?>" data-stock="<?= intval($p['stock_qty']) ?>">
            <?= htmlspecialchars($p['name']) ?> (Stock: <?= intval($p['stock_qty']) ?> | Prix ach.: <?= number_format($p['price_purchase'], 0, '.', ' ') ?> F)
        </option>
    <?php endforeach; ?>
</template>

<template id="target_category_options_template">
    <option value="">Sélectionnez la famille...</option>
    <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
    <?php endforeach; ?>
</template>

<!-- Structure des produits pour recherche dynamique instantanée -->
<script>
const exitCatalog = <?= json_encode(array_map(function($p) {
    return [
        'id' => intval($p['id']),
        'name' => $p['name'],
        'sku' => $p['sku'] ?? '',
        'stock_qty' => intval($p['stock_qty']),
        'price_purchase' => floatval($p['price_purchase']),
        'unit' => $p['unit'] ?? 'UNITE'
    ];
}, $all_products)) ?>;
</script>

<script>
function getProductOptionsHtml() {
    const tpl = document.getElementById('target_product_options_template');
    return tpl ? tpl.innerHTML : '<option value="">Sélectionnez l\'article résultant...</option>';
}

function getCategoryOptionsHtml() {
    const tpl = document.getElementById('target_category_options_template');
    return tpl ? tpl.innerHTML : '<option value="">Sélectionnez la famille...</option>';
}

function toggleProductionFields() {
    const reasonElem = document.getElementById('reason');
    if (!reasonElem) return;
    const reason = reasonElem.value;
    const prodSection = document.getElementById('production_section');
    const container = document.getElementById('target_rows_container');
    
    if (!prodSection || !container) return;

    if (reason === 'PRODUCTION') {
        prodSection.style.display = 'block';
        if (container.children.length === 0) {
            addTargetRow();
        }
    } else {
        prodSection.style.display = 'none';
    }
}

function normalizeSearch(str) {
    return (str || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

// Recherche dynamique pour l'article source (Matière Première)
function handleSourceSearch(query) {
    const resultsContainer = document.getElementById('sourceSearchResults');
    const normFilter = normalizeSearch(query);
    
    if (normFilter.length < 1) {
        resultsContainer.classList.add('d-none');
        resultsContainer.innerHTML = '';
        return;
    }

    const filterTokens = normFilter.split(/\s+/).filter(Boolean);

    const matches = exitCatalog.filter(p => {
        const normName = normalizeSearch(p.name);
        const normSku = normalizeSearch(p.sku);
        const idStr = p.id.toString();
        return filterTokens.every(token => 
            normName.includes(token) || normSku.includes(token) || idStr.includes(token)
        );
    });

    if (matches.length === 0) {
        resultsContainer.innerHTML = '<div class="list-group-item text-muted text-center py-2 small">Aucun article trouvé</div>';
        resultsContainer.classList.remove('d-none');
        return;
    }

    let html = '';
    matches.slice(0, 10).forEach(p => {
        const stockBadge = p.stock_qty > 0 
            ? `<span class="badge bg-success-subtle text-success py-1 px-2">${p.stock_qty} dispo</span>` 
            : `<span class="badge bg-danger text-white py-1 px-2">Stock: 0</span>`;
            
        html += `
            <button type="button" class="list-group-item list-group-item-action py-2 px-3 d-flex justify-content-between align-items-center" onclick="selectSourceProduct(${p.id})">
                <div>
                    <span class="fw-bold text-slate-800">${escapeHtml(p.name)}</span>
                    <small class="text-secondary d-block">Unité : ${escapeHtml(p.unit)}</small>
                </div>
                <div>${stockBadge}</div>
            </button>
        `;
    });

    resultsContainer.innerHTML = html;
    resultsContainer.classList.remove('d-none');
}

function selectSourceProduct(productId) {
    const product = exitCatalog.find(p => p.id === productId);
    if (!product) return;

    document.getElementById('source_product_id').value = product.id;
    document.getElementById('selectedSourceName').textContent = product.name + ' (' + product.unit + ')';
    document.getElementById('selectedSourceStock').textContent = 'Stock disponible : ' + product.stock_qty;
    document.getElementById('selectedSourceStock').className = product.stock_qty > 0 ? 'badge bg-success ms-2' : 'badge bg-danger ms-2';
    
    const qtyInput = document.getElementById('source_quantity');
    qtyInput.setAttribute('max', product.stock_qty);
    if (product.stock_qty <= 0) {
        alert("Attention : Cet article est actuellement en rupture de stock dans ce dépôt.");
    }

    document.getElementById('selectedSourceDisplay').classList.remove('d-none');
    document.getElementById('quickSourceSearch').parentElement.classList.add('d-none');
    document.getElementById('sourceSearchResults').classList.add('d-none');
    document.getElementById('sourceSearchResults').innerHTML = '';
}

function resetSourceProduct() {
    document.getElementById('source_product_id').value = '';
    document.getElementById('selectedSourceDisplay').classList.add('d-none');
    document.getElementById('quickSourceSearch').parentElement.classList.remove('d-none');
    document.getElementById('quickSourceSearch').value = '';
    document.getElementById('quickSourceSearch').focus();
    document.getElementById('source_quantity').removeAttribute('max');
}

// Fermer les résultats de recherche au clic externe
document.addEventListener('click', function(e) {
    const sourceSearch = document.getElementById('quickSourceSearch');
    const sourceResults = document.getElementById('sourceSearchResults');
    if (sourceSearch && sourceResults && !sourceSearch.contains(e.target) && !sourceResults.contains(e.target)) {
        sourceResults.classList.add('d-none');
    }
});

function addTargetRow() {
    const container = document.getElementById('target_rows_container');
    if (!container) return;
    const rowId = 'target_row_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    
    const card = document.createElement('div');
    card.className = 'p-3 bg-light rounded border border-secondary-subtle position-relative target-product-card';
    card.id = rowId;

    card.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-bold text-slate-700 small"><i class="fa-solid fa-box text-primary me-1"></i>Produit Résultat #${container.children.length + 1}</span>
            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 remove-target-btn" onclick="removeTargetRow('${rowId}')" title="Supprimer cette ligne">
                <i class="fa-solid fa-trash-can me-1"></i>Supprimer
            </button>
        </div>
        <div class="row g-3">
            <div class="col-md-5 position-relative">
                <label class="form-label fw-semibold small">Article Cible <span class="text-danger">*</span></label>
                <div class="input-group input-group-sm mb-1 target-search-box">
                    <span class="input-group-text bg-white border-end-0 text-secondary"><i class="fa-solid fa-magnifying-glass text-success"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 target-search-input" placeholder="Tapez pour rechercher (ex: A4, Ramette...)" autocomplete="off" oninput="handleTargetSearch(this)">
                </div>
                <div class="list-group position-absolute w-100 shadow-lg border mt-1 d-none target-search-results" style="z-index: 1050; max-height: 200px; overflow-y: auto; border-radius: 8px;"></div>
                
                <div class="p-2 bg-white rounded border d-none target-selected-display">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-800 small target-selected-name">-</span>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="resetTargetProduct(this)"><i class="fa-solid fa-times"></i></button>
                    </div>
                </div>

                <select name="target_product_ids[]" class="form-select form-select-sm target-select d-none" required onchange="toggleRowNewProduct(this); calculateTargetRowPMP(this);">
                    ${getProductOptionsHtml()}
                </select>
                <div class="mt-1">
                    <button type="button" class="btn btn-link btn-sm p-0 text-primary fw-semibold" onclick="enableRowNewProduct(this)" style="font-size: 0.8rem;">
                        <i class="fa-solid fa-plus-circle me-1"></i>+ Créer un nouvel article
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Quantité Produite <span class="text-danger">*</span></label>
                <input type="number" name="target_quantities[]" class="form-control form-control-sm target-qty" min="1" value="1" required oninput="calculateTargetRowPMP(this)">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Coût Production Unitaire (FCFA)</label>
                <input type="number" step="0.01" name="target_unit_costs[]" class="form-control form-control-sm target-unit-cost" placeholder="Auto ou Saisir..." oninput="calculateTargetRowPMP(this)">
            </div>
        </div>

        <div class="pmp-info-badge mt-2 text-dark small p-2 bg-white rounded border border-info-subtle" style="display: none;"></div>

        <!-- Section création nouvel article inline pour cette ligne -->
        <div class="row-new-product-fields mt-3 p-2 bg-white rounded border" style="display: none;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold text-success small mb-0"><i class="fa-solid fa-plus-circle me-1"></i>Nouveau produit à ajouter au catalogue</h6>
                <button type="button" class="btn btn-sm btn-link text-secondary p-0" onclick="cancelRowNewProduct(this)">Annuler</button>
            </div>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Nom de l'article *</label>
                    <input type="text" name="new_target_names[]" class="form-control form-control-sm new-name-input" placeholder="ex: Couché 250g A4">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Famille / Catégorie *</label>
                    <select name="new_target_category_ids[]" class="form-select form-select-sm new-cat-input">
                        ${getCategoryOptionsHtml()}
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Unité / Cond.</label>
                    <select name="new_target_units[]" class="form-select form-select-sm new-unit-input">
                        <option value="RAMETTE">RAMETTE</option>
                        <option value="PAQUET">PAQUET</option>
                        <option value="UNITE" selected>UNITE</option>
                        <option value="FEUILLE">FEUILLE</option>
                        <option value="PIECE">PIECE</option>
                        <option value="ROULEAU">ROULEAU</option>
                        <option value="BOITE">BOITE</option>
                        <option value="CARTON">CARTON</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Prix de Vente</label>
                    <input type="number" step="0.01" name="new_price_sales[]" class="form-control form-control-sm" placeholder="0.00">
                </div>
            </div>
        </div>
    `;

    container.appendChild(card);
    updateRemoveButtonsState();
}

function handleTargetSearch(inputElem) {
    const card = inputElem.closest('.target-product-card');
    if (!card) return;
    const resultsContainer = card.querySelector('.target-search-results');
    const normFilter = normalizeSearch(inputElem.value);

    if (normFilter.length < 1) {
        resultsContainer.classList.add('d-none');
        resultsContainer.innerHTML = '';
        return;
    }

    const filterTokens = normFilter.split(/\s+/).filter(Boolean);

    const matches = exitCatalog.filter(p => {
        const normName = normalizeSearch(p.name);
        const normSku = normalizeSearch(p.sku);
        const idStr = p.id.toString();
        return filterTokens.every(token => 
            normName.includes(token) || normSku.includes(token) || idStr.includes(token)
        );
    });

    if (matches.length === 0) {
        resultsContainer.innerHTML = '<div class="list-group-item text-muted text-center py-2 small">Aucun article trouvé</div>';
        resultsContainer.classList.remove('d-none');
        return;
    }

    let html = '';
    matches.slice(0, 8).forEach(p => {
        html += `
            <button type="button" class="list-group-item list-group-item-action py-1.5 px-2.5 d-flex justify-content-between align-items-center" onclick="selectTargetProduct(this, ${p.id})">
                <div>
                    <span class="fw-bold text-slate-800 small">${escapeHtml(p.name)}</span>
                    <small class="text-secondary d-block" style="font-size: 0.75rem;">Prix ach.: ${formatMoney(p.price_purchase)} F</small>
                </div>
                <span class="badge bg-light text-dark border small">${p.stock_qty} dispo</span>
            </button>
        `;
    });

    resultsContainer.innerHTML = html;
    resultsContainer.classList.remove('d-none');
}

function selectTargetProduct(btnElem, productId) {
    const card = btnElem.closest('.target-product-card');
    if (!card) return;
    const product = exitCatalog.find(p => p.id === productId);
    if (!product) return;

    const select = card.querySelector('.target-select');
    select.value = product.id;
    
    card.querySelector('.target-selected-name').textContent = product.name + ' (' + product.unit + ')';
    card.querySelector('.target-selected-display').classList.remove('d-none');
    card.querySelector('.target-search-box').classList.add('d-none');
    
    const results = card.querySelector('.target-search-results');
    results.classList.add('d-none');
    results.innerHTML = '';

    calculateTargetRowPMP(select);
}

function resetTargetProduct(btnElem) {
    const card = btnElem.closest('.target-product-card');
    if (!card) return;
    const select = card.querySelector('.target-select');
    select.value = '';
    
    card.querySelector('.target-selected-display').classList.add('d-none');
    const searchBox = card.querySelector('.target-search-box');
    searchBox.classList.remove('d-none');
    const input = searchBox.querySelector('.target-search-input');
    input.value = '';
    input.focus();
    
    calculateTargetRowPMP(select);
}

function enableRowNewProduct(btnElem) {
    const card = btnElem.closest('.target-product-card');
    if (!card) return;
    const select = card.querySelector('.target-select');
    select.value = 'NEW';
    toggleRowNewProduct(select);
    card.querySelector('.target-search-box').classList.add('d-none');
    card.querySelector('.target-selected-display').classList.add('d-none');
}

function cancelRowNewProduct(btnElem) {
    const card = btnElem.closest('.target-product-card');
    if (!card) return;
    const select = card.querySelector('.target-select');
    select.value = '';
    toggleRowNewProduct(select);
    resetTargetProduct(btnElem);
}

function formatMoney(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount);
}

function updateRemoveButtonsState() {
    const container = document.getElementById('target_rows_container');
    const removeBtns = container.querySelectorAll('.remove-target-btn');
    if (removeBtns.length <= 1) {
        removeBtns.forEach(btn => btn.style.display = 'none');
    } else {
        removeBtns.forEach(btn => btn.style.display = 'inline-block');
    }
}

function toggleRowNewProduct(selectElem) {
    const card = selectElem.closest('.target-product-card');
    const newSection = card.querySelector('.row-new-product-fields');
    const newName = card.querySelector('.new-name-input');
    const newCat = card.querySelector('.new-cat-input');

    if (selectElem.value === 'NEW') {
        newSection.style.display = 'block';
        newName.setAttribute('required', 'required');
        newCat.setAttribute('required', 'required');
    } else {
        newSection.style.display = 'none';
        newName.removeAttribute('required');
        newCat.removeAttribute('required');
    }
}

function calculateTargetRowPMP(elem) {
    const card = elem.closest('.target-product-card');
    if (!card) return;
    
    const select = card.querySelector('.target-select');
    const qtyInput = card.querySelector('.target-qty');
    const costInput = card.querySelector('.target-unit-cost');
    const pmpBadge = card.querySelector('.pmp-info-badge');

    if (!select || select.value === '' || select.value === 'NEW') {
        if (pmpBadge) pmpBadge.style.display = 'none';
        return;
    }

    const selectedOption = select.options[select.selectedIndex];
    const currentPrice = parseFloat(selectedOption.getAttribute('data-price') || 0);
    const currentStock = parseInt(selectedOption.getAttribute('data-stock') || 0);
    const producedQty = parseInt(qtyInput.value || 1);
    const enteredCost = parseFloat(costInput.value);

    let unitCost = isNaN(enteredCost) ? 0 : enteredCost;
    
    let pmp = unitCost;
    if (currentStock > 0 && producedQty > 0 && unitCost > 0) {
        pmp = Math.round(((currentStock * currentPrice) + (producedQty * unitCost)) / (currentStock + producedQty));
    }

    if (pmpBadge) {
        pmpBadge.style.display = 'block';
        let html = `<div class="d-flex flex-wrap gap-2 align-items-center">`;
        html += `<span class="badge bg-light text-dark border"><i class="fa-solid fa-boxes-stacked me-1"></i>Stock actuel: <strong>${currentStock}</strong></span>`;
        html += `<span class="badge bg-light text-dark border"><i class="fa-solid fa-tag me-1"></i>Prix d'achat actuel: <strong>${currentPrice.toLocaleString('fr-FR')} FCFA</strong></span>`;
        if (unitCost > 0) {
            html += `<span class="badge bg-info bg-opacity-10 text-info border border-info-subtle"><i class="fa-solid fa-calculator me-1"></i>Coût fournée: <strong>${unitCost.toLocaleString('fr-FR')} FCFA/u</strong></span>`;
            if (currentStock > 0) {
                html += `<span class="badge bg-success bg-opacity-10 text-success border border-success-subtle fw-bold"><i class="fa-solid fa-scale-balanced me-1"></i>Nouveau PMP réévalué: <strong>${pmp.toLocaleString('fr-FR')} FCFA</strong></span>`;
            }
        } else {
            html += `<small class="text-muted"><em>(Coût de production calculé automatiquement si non renseigné)</em></small>`;
        }
        html += `</div>`;
        pmpBadge.innerHTML = html;
    }
}

function updateSourceStock() {
    const select = document.getElementById('source_product_id');
    const qtyInput = document.getElementById('source_quantity');
    
    if (select.selectedIndex > 0) {
        const option = select.options[select.selectedIndex];
        const stock = option.getAttribute('data-stock');
        qtyInput.setAttribute('max', stock);
    } else {
        qtyInput.removeAttribute('max');
    }
}

function filterExitHistory() {
    const input = document.getElementById('searchExitHistory');
    const filter = input.value.toLowerCase().trim();
    const table = document.getElementById('exitHistoryTable');
    const rows = table.querySelectorAll('tr.exit-row');
    const headers = table.querySelectorAll('tr.group-header-row');

    const visibleGroups = new Set();

    rows.forEach(tr => {
        const text = tr.textContent.toLowerCase();
        const groupDate = tr.getAttribute('data-date-group');
        if (text.includes(filter)) {
            tr.style.display = '';
            visibleGroups.add(groupDate);
        } else {
            tr.style.display = 'none';
        }
    });

    headers.forEach(header => {
        const groupDate = header.getAttribute('data-date');
        if (visibleGroups.has(groupDate)) {
            header.style.display = '';
        } else {
            header.style.display = 'none';
        }
    });
}

function openExitDetails(trElem) {
    const rawData = trElem.getAttribute('data-exit-data');
    if (!rawData) return;

    try {
        const data = JSON.parse(rawData);
        
        document.getElementById('modalExitRef').textContent = data.reference;
        const badgeElem = document.getElementById('modalExitBadge');
        badgeElem.textContent = data.reason_label;
        badgeElem.className = 'badge px-2.5 py-1 fw-semibold ' + data.reason_badge;

        let explanationNarrative = '';

        if (data.items && data.items.length > 0) {
            const firstItem = data.items[0];
            const srcName = firstItem.source_name;
            const srcQty = firstItem.source_qty;
            const srcUnit = firstItem.source_unit;

            if (data.reason_code === 'PRODUCTION') {
                const targetListDesc = data.items
                    .filter(it => it.target_name)
                    .map(it => `<b>+${it.target_qty} ${escapeHtml(it.target_unit)}</b> (<i>${escapeHtml(it.target_name)}</i>)`)
                    .join(' et ');

                explanationNarrative = `
                    <div class="p-3 mb-4 rounded-3" style="background-color: #f0fdf4; border: 1px solid #bbf7d0;">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-industry text-success fa-lg mt-1"></i>
                            <div>
                                <h6 class="fw-bold text-success mb-1">Résumé explicatif du mouvement :</h6>
                                <div class="text-slate-800 small" style="line-height: 1.6;">
                                    <b>${escapeHtml(data.user_name)}</b> a effectué une <b>Découpe / Transformation</b> le <b>${escapeHtml(data.created_at)}</b> au dépôt <b>${escapeHtml(data.shop_name)}</b>.
                                    <br>
                                    • <b>Matière première retirée :</b> <b>-${srcQty} ${escapeHtml(srcUnit)}</b> de <i>${escapeHtml(srcName)}</i>.
                                    <br>
                                    • <b>Produits obtenus et ajoutés au stock :</b> ${targetListDesc}.
                                    <br>
                                    • <b>Coût financier engagé :</b> <b>${escapeHtml(data.total_value)}</b>.
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (data.reason_code === 'INTERNAL_USE') {
                explanationNarrative = `
                    <div class="p-3 mb-4 rounded-3" style="background-color: #f0f9ff; border: 1px solid #bae6fd;">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-circle-info text-info fa-lg mt-1"></i>
                            <div>
                                <h6 class="fw-bold text-primary mb-1">Résumé explicatif du mouvement :</h6>
                                <div class="text-slate-800 small" style="line-height: 1.6;">
                                    <b>${escapeHtml(data.user_name)}</b> a prélevé du matériel pour une <b>Consommation Interne</b> le <b>${escapeHtml(data.created_at)}</b> au dépôt <b>${escapeHtml(data.shop_name)}</b>.
                                    <br>
                                    • <b>Article retiré du stock :</b> <b>-${srcQty} ${escapeHtml(srcUnit)}</b> de <i>${escapeHtml(srcName)}</i>.
                                    <br>
                                    • <b>Impact financier :</b> Une dépense de <b>${escapeHtml(data.total_value)}</b> a été automatiquement inscrite dans la Caisse / Journal de l'entreprise.
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (data.reason_code === 'LOSS') {
                explanationNarrative = `
                    <div class="p-3 mb-4 rounded-3" style="background-color: #fef2f2; border: 1px solid #fecaca;">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-triangle-exclamation text-danger fa-lg mt-1"></i>
                            <div>
                                <h6 class="fw-bold text-danger mb-1">Résumé explicatif du mouvement :</h6>
                                <div class="text-slate-800 small" style="line-height: 1.6;">
                                    <b>${escapeHtml(data.user_name)}</b> a déclaré une <b>Perte / Casse</b> le <b>${escapeHtml(data.created_at)}</b> au dépôt <b>${escapeHtml(data.shop_name)}</b>.
                                    <br>
                                    • <b>Article gâté ou détruit :</b> <b>-${srcQty} ${escapeHtml(srcUnit)}</b> de <i>${escapeHtml(srcName)}</i>.
                                    <br>
                                    • <b>Valeur financière perdue :</b> <b>${escapeHtml(data.total_value)}</b>.
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
        }

        let itemsHtml = '';
        if (data.items && data.items.length > 0) {
            const firstItem = data.items[0];
            itemsHtml += `
                <div class="p-3 bg-danger-subtle bg-opacity-10 border border-danger-subtle rounded-3 mb-3">
                    <div class="small text-secondary fw-semibold mb-1">Matière Première / Article Consommé :</div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="fw-bold fs-6 text-slate-800"><i class="fa-solid fa-minus-circle text-danger me-1"></i>${escapeHtml(firstItem.source_name)}</span>
                        <span class="badge bg-danger text-white fs-6 font-monospace py-1.5 px-3">-${firstItem.source_qty} ${escapeHtml(firstItem.source_unit)}</span>
                    </div>
                </div>
            `;

            if (data.reason_code === 'PRODUCTION') {
                itemsHtml += `
                    <div class="p-3 bg-success-subtle bg-opacity-10 border border-success-subtle rounded-3 mb-3">
                        <div class="small text-secondary fw-semibold mb-2">Produit(s) Résultant(s) / Fabriqué(s) :</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 bg-white rounded">
                                <thead class="table-light">
                                    <tr>
                                        <th>Article Cible / Produit Fini</th>
                                        <th class="text-center">Quantité Générée</th>
                                        <th class="text-end">Coût Prod. Unitaire</th>
                                        <th class="text-end">Coût Total Attribué</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                data.items.forEach(it => {
                    if (it.target_name) {
                        itemsHtml += `
                            <tr>
                                <td class="fw-bold text-slate-800"><i class="fa-solid fa-check text-success me-1"></i>${escapeHtml(it.target_name)}</td>
                                <td class="text-center"><span class="badge bg-success py-1 px-2.5">+${it.target_qty} ${escapeHtml(it.target_unit)}</span></td>
                                <td class="text-end fw-semibold text-primary">${escapeHtml(it.unit_cost)}</td>
                                <td class="text-end fw-bold text-dark">${escapeHtml(it.total_cost)}</td>
                            </tr>
                        `;
                    }
                });
                itemsHtml += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }
        }

        const bodyHtml = `
            ${explanationNarrative}

            <div class="row g-3 mb-4 p-3 bg-light rounded-3 border">
                <div class="col-6 col-md-4">
                    <small class="text-secondary d-block">Date & Heure :</small>
                    <span class="fw-bold text-dark"><i class="fa-regular fa-clock me-1 text-primary"></i>${escapeHtml(data.created_at)}</span>
                </div>
                <div class="col-6 col-md-4">
                    <small class="text-secondary d-block">Auteur (Saisi par) :</small>
                    <span class="fw-bold text-dark"><i class="fa-solid fa-user me-1 text-primary"></i>${escapeHtml(data.user_name)}</span>
                </div>
                <div class="col-6 col-md-4">
                    <small class="text-secondary d-block">Dépôt / Magasin :</small>
                    <span class="fw-bold text-dark"><i class="fa-solid fa-store me-1 text-primary"></i>${escapeHtml(data.shop_name)}</span>
                </div>
                <div class="col-6 col-md-4">
                    <small class="text-secondary d-block">Coût Total Imputé :</small>
                    <span class="fw-extrabold text-success fs-6">${escapeHtml(data.total_value)}</span>
                </div>
                <div class="col-12">
                    <small class="text-secondary d-block">Notes & Remarques :</small>
                    <span class="fst-italic text-slate-700">${escapeHtml(data.notes)}</span>
                </div>
            </div>
            ${itemsHtml}
        `;

        document.getElementById('modalExitBody').innerHTML = bodyHtml;

        const modalElem = document.getElementById('exitDetailsModal');
        const modal = new bootstrap.Modal(modalElem);
        modal.show();

    } catch (e) {
        console.error("Erreur parsing JSON détails mouvement:", e);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    toggleProductionFields();
    updateSourceStock();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
