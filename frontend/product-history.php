<?php
require_once __DIR__ . '/includes/header.php';

// Sécurité : Utilisateur connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0; // 0 = Tous les articles (Vue Globale)
$filter_shop_id = isset($_GET['shop_id']) ? intval($_GET['shop_id']) : $_SESSION['active_shop_id'];
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$event_filter = isset($_GET['event_type']) ? trim($_GET['event_type']) : 'ALL';

$error_msg = '';
$success_msg = '';

// Traitement POST : Modification / Régularisation du stock physique ou initial par l'Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_stock'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error_msg = "Accès refusé. Seul l'administrateur peut modifier le stock.";
    } else {
        $target_p_id = intval($_POST['product_id'] ?? 0);
        $target_shop_id = intval($_POST['target_shop_id'] ?? 0);
        $adj_notes = trim($_POST['adj_notes'] ?? 'Régularisation du stock initial');
        
        // Mode de saisie : initial ou direct
        $update_mode = $_POST['update_mode'] ?? 'initial';
        $new_initial_stock = isset($_POST['new_initial_stock']) ? intval($_POST['new_initial_stock']) : null;
        $net_movements_val = intval($_POST['net_movements_val'] ?? 0);

        if ($update_mode === 'initial' && $new_initial_stock !== null) {
            $new_stock_qty = max(0, $new_initial_stock + $net_movements_val);
        } else {
            $new_stock_qty = max(0, intval($_POST['new_stock_qty'] ?? 0));
        }
        
        if (!empty($_POST['adj_date'])) {
            $raw_d = str_replace('T', ' ', trim($_POST['adj_date']));
            $adj_date = (strlen($raw_d) === 16) ? $raw_d . ':00' : $raw_d;
        } else {
            $adj_date = date('Y-m-d H:i:s');
        }

        if ($target_p_id > 0 && $target_shop_id > 0) {
            try {
                $pdo->beginTransaction();

                // 1. Récupérer l'ancien stock
                $cur_stk_stmt = $pdo->prepare("SELECT quantity FROM product_stock WHERE product_id = ? AND shop_id = ?");
                $cur_stk_stmt->execute([$target_p_id, $target_shop_id]);
                $old_qty = intval($cur_stk_stmt->fetchColumn() ?: 0);

                // 2. Mettre à jour le stock physique et/ou le stock initial
                if ($update_mode === 'initial' && $new_initial_stock !== null) {
                    $up_stk_stmt = $pdo->prepare("
                        INSERT INTO product_stock (product_id, shop_id, initial_stock, quantity) 
                        VALUES (?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE initial_stock = ?, quantity = ?
                    ");
                    $up_stk_stmt->execute([$target_p_id, $target_shop_id, $new_initial_stock, $new_stock_qty, $new_initial_stock, $new_stock_qty]);
                } else {
                    $up_stk_stmt = $pdo->prepare("
                        INSERT INTO product_stock (product_id, shop_id, quantity) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE quantity = ?
                    ");
                    $up_stk_stmt->execute([$target_p_id, $target_shop_id, $new_stock_qty, $new_stock_qty]);
                }

                // 3. Imputation comptable UNIQUEMENT si explicitement demandée comme perte financière / démarque
                $p_info_stmt = $pdo->prepare("SELECT name, unit, price_purchase FROM products WHERE id = ?");
                $p_info_stmt->execute([$target_p_id]);
                $p_info = $p_info_stmt->fetch();

                if ($new_stock_qty < $old_qty && $p_info && !empty($_POST['record_as_financial_loss'])) {
                    $qty_lost = $old_qty - $new_stock_qty;
                    $loss_val = $qty_lost * floatval($p_info['price_purchase']);
                    if ($loss_val > 0) {
                        $exp_stmt = $pdo->prepare("
                            INSERT INTO expenses (shop_id, user_id, amount, category, expense_date, notes)
                            VALUES (?, ?, ?, 'Pertes de stock - Inventaire', ?, ?)
                        ");
                        $exp_note = "Ajustement de stock sur " . $p_info['name'] . " (-" . $qty_lost . " " . ($p_info['unit'] ?: 'UNITE') . "). Motif: " . $adj_notes;
                        $exp_stmt->execute([
                            $target_shop_id,
                            $_SESSION['user_id'] ?? 1,
                            $loss_val,
                            date('Y-m-d', strtotime($adj_date)),
                            $exp_note
                        ]);
                    }
                }

                $pdo->commit();
                $success_msg = "Le stock initial a été mis à jour avec succès (" . ($new_initial_stock !== null ? $new_initial_stock : $new_stock_qty) . " unités de départ ➔ " . $new_stock_qty . " unités en stock réel). L'historique et les soldes ont été recalculés.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Erreur lors de la mise à jour du stock : " . $e->getMessage();
            }
        } else {
            $error_msg = "Veuillez sélectionner un dépôt valide.";
        }
    }
}

// Récupérer tous les produits pour le dropdown de sélection rapide
$all_catalog_products = [];
try {
    $all_prods_stmt = $pdo->query("SELECT id, name, sku, unit FROM products ORDER BY name ASC");
    $all_catalog_products = $all_prods_stmt->fetchAll();
} catch (Exception $e) {
    $all_catalog_products = [];
}

$product = null;
$stock_by_shop = [];
$timeline_events = [];

// KPIs
$kpi_sales_qty = 0;
$kpi_sales_amount = 0;
$kpi_purchases_qty = 0;
$kpi_purchases_amount = 0;
$kpi_production_consumed = 0;
$kpi_production_generated = 0;
$kpi_transfers_in = 0;
$kpi_transfers_out = 0;
$kpi_losses_qty = 0;

// Si un produit spécifique est sélectionné (product_id > 0)
if ($product_id > 0) {
    try {
        $p_stmt = $pdo->prepare("
            SELECT p.*, c.name as category_name 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = ?
        ");
        $p_stmt->execute([$product_id]);
        $product = $p_stmt->fetch();
    } catch (Exception $e) {
        $product = null;
    }

    if ($product) {
        // Stock ventilé par dépôt
        try {
            $stock_stmt = $pdo->prepare("
                SELECT ps.quantity, s.id as shop_id, s.name as shop_name 
                FROM product_stock ps
                JOIN shops s ON ps.shop_id = s.id
                WHERE ps.product_id = ?
                ORDER BY s.name
            ");
            $stock_stmt->execute([$product_id]);
            $stock_by_shop = $stock_stmt->fetchAll();
        } catch (Exception $e) {
            $stock_by_shop = [];
        }
    }
}

// -------------------------------------------------------------
// EXTRACTION DES MOUVEMENTS (PRODUIT SPECIFIQUE OU VUE GLOBALE)
// -------------------------------------------------------------

// 1. Extraire Ventes & Achats
try {
    $orders_sql = "
        SELECT 
            o.created_at,
            o.type as order_type,
            o.id as ref_id,
            CONCAT('FA', LPAD(o.id, 5, '0')) as ref_code,
            o.shop_id,
            s.name as shop_name,
            u.name as user_name,
            COALESCE(cl.name, sup.name, 'Client Comptant') as contact_name,
            oi.quantity,
            oi.unit_price,
            oi.total_price,
            o.notes,
            p.id as prod_id,
            p.name as product_name,
            p.unit as product_unit
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN clients cl ON o.client_id = cl.id
        LEFT JOIN suppliers sup ON o.supplier_id = sup.id
        WHERE o.status = 'COMPLETED' 
          AND (o.reference IS NULL OR o.reference NOT LIKE 'OUVERTURE-%')
    ";
    
    $params_orders = [];
    if ($product_id > 0) {
        $orders_sql .= " AND oi.product_id = :product_id";
        $params_orders['product_id'] = $product_id;
    }
    if ($filter_shop_id > 0) {
        $orders_sql .= " AND o.shop_id = " . intval($filter_shop_id);
    }
    if (!empty($start_date)) {
        $orders_sql .= " AND DATE(o.created_at) >= " . $pdo->quote($start_date);
    }
    if (!empty($end_date)) {
        $orders_sql .= " AND DATE(o.created_at) <= " . $pdo->quote($end_date);
    }
    $history_limit = ($product_id > 0) ? 500 : 200;
    $orders_sql .= " ORDER BY o.created_at DESC LIMIT " . $history_limit;

    $orders_stmt = $pdo->prepare($orders_sql);
    $orders_stmt->execute($params_orders);
    foreach ($orders_stmt->fetchAll() as $row) {
        $unit_str = $row['product_unit'] ?: 'unités';
        if ($row['order_type'] === 'SALE') {
            $kpi_sales_qty += $row['quantity'];
            $kpi_sales_amount += floatval($row['total_price']);
            $timeline_events[] = [
                'timestamp' => strtotime($row['created_at']),
                'date' => date('d/m/Y H:i', strtotime($row['created_at'])),
                'product_id' => $row['prod_id'],
                'product_name' => $row['product_name'],
                'product_unit' => $unit_str,
                'event_type' => 'SALE',
                'badge_class' => 'bg-success text-white',
                'badge_label' => '🛒 Vente Client',
                'qty_change' => '-' . intval($row['quantity']) . ' ' . $unit_str,
                'qty_num' => -intval($row['quantity']),
                'shop_name' => $row['shop_name'],
                'contact' => $row['contact_name'],
                'operator' => $row['user_name'] ?? 'Vendeur',
                'ref_code' => $row['ref_code'],
                'unit_price' => number_format($row['unit_price'], 0, '.', ' ') . ' FCFA',
                'total_price' => number_format($row['total_price'], 0, '.', ' ') . ' FCFA',
                'notes' => $row['notes'] ?: '-',
                'link' => 'invoice.php?id=' . $row['ref_id']
            ];
        } elseif ($row['order_type'] === 'PURCHASE') {
            $kpi_purchases_qty += $row['quantity'];
            $kpi_purchases_amount += floatval($row['total_price']);
            $timeline_events[] = [
                'timestamp' => strtotime($row['created_at']),
                'date' => date('d/m/Y H:i', strtotime($row['created_at'])),
                'product_id' => $row['prod_id'],
                'product_name' => $row['product_name'],
                'product_unit' => $unit_str,
                'event_type' => 'PURCHASE',
                'badge_class' => 'bg-info text-dark',
                'badge_label' => '📥 Achat / Ravitaillement',
                'qty_change' => '+' . intval($row['quantity']) . ' ' . $unit_str,
                'qty_num' => intval($row['quantity']),
                'shop_name' => $row['shop_name'],
                'contact' => $row['contact_name'],
                'operator' => $row['user_name'] ?? 'Admin',
                'ref_code' => $row['ref_code'],
                'unit_price' => number_format($row['unit_price'], 0, '.', ' ') . ' FCFA',
                'total_price' => number_format($row['total_price'], 0, '.', ' ') . ' FCFA',
                'notes' => $row['notes'] ?: '-',
                'link' => 'invoice.php?id=' . $row['ref_id']
            ];
        }
    }
} catch (Exception $e) {}

// 2. Extraire Sorties, Pertes & Productions
try {
    $has_target_col = false;
    try {
        $chk_target = $pdo->query("SHOW COLUMNS FROM stock_exit_items LIKE 'target_product_id'");
        if ($chk_target && $chk_target->fetch()) {
            $has_target_col = true;
        }
    } catch (Exception $ex) {
        $has_target_col = false;
    }

    if ($has_target_col) {
        $exits_sql = "
            SELECT 
                se.created_at,
                se.reason,
                se.id as ref_id,
                se.reference as ref_code,
                se.shop_id,
                se.notes,
                se.total_value as exit_total_value,
                s.name as shop_name,
                u.name as user_name,
                sei.product_id as source_product_id,
                sei.target_product_id,
                sei.quantity as source_quantity,
                sei.target_quantity,
                sei.unit_cost,
                sei.total_cost,
                p_src.name as source_product_name,
                p_src.unit as source_product_unit,
                p_tgt.name as target_product_name,
                p_tgt.unit as target_product_unit
            FROM stock_exit_items sei
            JOIN stock_exits se ON sei.stock_exit_id = se.id
            JOIN shops s ON se.shop_id = s.id
            LEFT JOIN users u ON se.user_id = u.id
            LEFT JOIN products p_src ON sei.product_id = p_src.id
            LEFT JOIN products p_tgt ON sei.target_product_id = p_tgt.id
            WHERE 1=1
        ";
        if ($product_id > 0) {
            $exits_sql .= " AND (sei.product_id = " . intval($product_id) . " OR sei.target_product_id = " . intval($product_id) . ")";
        }
    } else {
        $exits_sql = "
            SELECT 
                se.created_at,
                se.reason,
                se.id as ref_id,
                se.reference as ref_code,
                se.shop_id,
                se.notes,
                se.total_value as exit_total_value,
                s.name as shop_name,
                u.name as user_name,
                sei.product_id as source_product_id,
                NULL as target_product_id,
                sei.quantity as source_quantity,
                0 as target_quantity,
                sei.unit_cost,
                sei.total_cost,
                p_src.name as source_product_name,
                p_src.unit as source_product_unit,
                '' as target_product_name,
                '' as target_product_unit
            FROM stock_exit_items sei
            JOIN stock_exits se ON sei.stock_exit_id = se.id
            JOIN shops s ON se.shop_id = s.id
            LEFT JOIN users u ON se.user_id = u.id
            LEFT JOIN products p_src ON sei.product_id = p_src.id
            WHERE 1=1
        ";
        if ($product_id > 0) {
            $exits_sql .= " AND sei.product_id = " . intval($product_id);
        }
    }

    if ($filter_shop_id > 0) {
        $exits_sql .= " AND se.shop_id = " . intval($filter_shop_id);
    }
    if (!empty($start_date)) {
        $exits_sql .= " AND DATE(se.created_at) >= " . $pdo->quote($start_date);
    }
    if (!empty($end_date)) {
        $exits_sql .= " AND DATE(se.created_at) <= " . $pdo->quote($end_date);
    }
    $exits_sql .= " ORDER BY se.created_at DESC LIMIT " . $history_limit;

    $exits_stmt = $pdo->query($exits_sql);
    $raw_exit_rows = $exits_stmt->fetchAll();

    // Grouper les items de sortie par mouvement (stock_exit_id) pour éviter de dupliquer la consommation de la matière première
    $grouped_exits = [];
    foreach ($raw_exit_rows as $r) {
        $r_id = $r['ref_id'];
        if (!isset($grouped_exits[$r_id])) {
            $grouped_exits[$r_id] = [
                'meta' => $r,
                'items' => []
            ];
        }
        $grouped_exits[$r_id]['items'][] = $r;
    }

    foreach ($grouped_exits as $exit_id => $exit_data) {
        $meta = $exit_data['meta'];
        $items = $exit_data['items'];
        $reason = $meta['reason'];

        if ($reason === 'PRODUCTION') {
            $first_item = $items[0];
            $src_id = intval($first_item['source_product_id']);
            $src_qty = intval($first_item['source_quantity']);
            $src_unit = $first_item['source_product_unit'] ?: 'unités';
            $src_name = $first_item['source_product_name'] ?: 'Matière Première';

            $is_source = ($product_id == 0 || $src_id == $product_id);

            // 1. Déduction de la matière première source : COMPTABILISÉE UNE SEULE FOIS PAR DÉCOUPE
            if ($is_source && $src_id > 0) {
                $kpi_production_consumed += $src_qty;

                $target_parts = [];
                $total_prod_val = 0;
                foreach ($items as $itm) {
                    if (!empty($itm['target_product_name'])) {
                        $t_u = $itm['target_product_unit'] ?: 'unités';
                        $target_parts[] = intval($itm['target_quantity']) . ' ' . $t_u . ' (' . $itm['target_product_name'] . ')';
                    }
                    $total_prod_val += floatval($itm['total_cost'] ?? 0);
                }
                if ($total_prod_val <= 0 && isset($meta['exit_total_value']) && floatval($meta['exit_total_value']) > 0) {
                    $total_prod_val = floatval($meta['exit_total_value']);
                }
                $target_summary = !empty($target_parts) ? implode(', ', $target_parts) : ($first_item['target_product_name'] ?: 'Multi-produits');

                $timeline_events[] = [
                    'timestamp' => strtotime($meta['created_at']),
                    'date' => date('d/m/Y H:i', strtotime($meta['created_at'])),
                    'product_id' => $src_id,
                    'product_name' => $src_name,
                    'product_unit' => $src_unit,
                    'event_type' => 'PRODUCTION',
                    'badge_class' => 'bg-primary text-white',
                    'badge_label' => '✂️ Découpe (Consommé)',
                    'qty_change' => '-' . $src_qty . ' ' . $src_unit,
                    'qty_num' => -$src_qty,
                    'shop_name' => $meta['shop_name'],
                    'contact' => 'Découpé en : ' . $target_summary,
                    'operator' => $meta['user_name'] ?? 'Opérateur',
                    'ref_code' => $meta['ref_code'],
                    'unit_price' => number_format($first_item['unit_cost'], 0, '.', ' ') . ' FCFA',
                    'total_price' => number_format($total_prod_val, 0, '.', ' ') . ' FCFA',
                    'notes' => $meta['notes'] ?: '-',
                    'link' => 'stock-exit.php'
                ];
            }

            // 2. Ajout des produits fabriqués cibles (chaque produit cible a sa propre ligne de gain)
            foreach ($items as $itm) {
                $tgt_id = intval($itm['target_product_id']);
                $tgt_qty = intval($itm['target_quantity']);
                $tgt_unit = $itm['target_product_unit'] ?: 'unités';
                $tgt_name = $itm['target_product_name'] ?: 'Produit Découpé';

                $is_target = ($product_id > 0 && $tgt_id == $product_id);

                if (($is_target || ($product_id == 0 && $tgt_id > 0)) && $tgt_id > 0) {
                    $kpi_production_generated += $tgt_qty;
                    $timeline_events[] = [
                        'timestamp' => strtotime($meta['created_at']),
                        'date' => date('d/m/Y H:i', strtotime($meta['created_at'])),
                        'product_id' => $tgt_id,
                        'product_name' => $tgt_name,
                        'product_unit' => $tgt_unit,
                        'event_type' => 'PRODUCTION',
                        'badge_class' => 'bg-success text-white',
                        'badge_label' => '✨ Produit Fabriqué',
                        'qty_change' => '+' . $tgt_qty . ' ' . $tgt_unit,
                        'qty_num' => $tgt_qty,
                        'shop_name' => $meta['shop_name'],
                        'contact' => 'Découpé depuis : ' . $src_qty . ' ' . $src_unit . ' (' . $src_name . ')',
                        'operator' => $meta['user_name'] ?? 'Opérateur',
                        'ref_code' => $meta['ref_code'],
                        'unit_price' => number_format($itm['unit_cost'], 0, '.', ' ') . ' FCFA',
                        'total_price' => number_format($itm['total_cost'], 0, '.', ' ') . ' FCFA',
                        'notes' => $meta['notes'] ?: '-',
                        'link' => 'stock-exit.php'
                    ];
                }
            }
        } elseif ($reason === 'INTERNAL_USE') {
            $first_item = $items[0];
            $src_id = intval($first_item['source_product_id']);
            $src_qty = intval($first_item['source_quantity']);
            $src_unit = $first_item['source_product_unit'] ?: 'unités';
            $src_name = $first_item['source_product_name'] ?: 'Article Consommé';

            $is_source = ($product_id == 0 || $src_id == $product_id);
            if ($is_source && $src_id > 0) {
                $kpi_losses_qty += $src_qty;
                $timeline_events[] = [
                    'timestamp' => strtotime($meta['created_at']),
                    'date' => date('d/m/Y H:i', strtotime($meta['created_at'])),
                    'product_id' => $src_id,
                    'product_name' => $src_name,
                    'product_unit' => $src_unit,
                    'event_type' => 'INTERNAL_USE',
                    'badge_class' => 'bg-warning text-dark',
                    'badge_label' => '🏢 Consommation Interne',
                    'qty_change' => '-' . $src_qty . ' ' . $src_unit,
                    'qty_num' => -$src_qty,
                    'shop_name' => $meta['shop_name'],
                    'contact' => 'Usage entreprise',
                    'operator' => $meta['user_name'] ?? 'Admin',
                    'ref_code' => $meta['ref_code'],
                    'unit_price' => number_format($first_item['unit_cost'], 0, '.', ' ') . ' FCFA',
                    'total_price' => number_format($first_item['total_cost'], 0, '.', ' ') . ' FCFA',
                    'notes' => $meta['notes'] ?: '-',
                    'link' => 'stock-exit.php'
                ];
            }
        } elseif ($reason === 'LOSS') {
            $first_item = $items[0];
            $src_id = intval($first_item['source_product_id']);
            $src_qty = intval($first_item['source_quantity']);
            $src_unit = $first_item['source_product_unit'] ?: 'unités';
            $src_name = $first_item['source_product_name'] ?: 'Article Perdu/Casse';

            $is_source = ($product_id == 0 || $src_id == $product_id);
            if ($is_source && $src_id > 0) {
                $kpi_losses_qty += $src_qty;
                $timeline_events[] = [
                    'timestamp' => strtotime($meta['created_at']),
                    'date' => date('d/m/Y H:i', strtotime($meta['created_at'])),
                    'product_id' => $src_id,
                    'product_name' => $src_name,
                    'product_unit' => $src_unit,
                    'event_type' => 'LOSS',
                    'badge_class' => 'bg-danger text-white',
                    'badge_label' => '⚠️ Perte / Casse',
                    'qty_change' => '-' . $src_qty . ' ' . $src_unit,
                    'qty_num' => -$src_qty,
                    'shop_name' => $meta['shop_name'],
                    'contact' => 'Article déprécié/gâté',
                    'operator' => $meta['user_name'] ?? 'Admin',
                    'ref_code' => $meta['ref_code'],
                    'unit_price' => number_format($first_item['unit_cost'], 0, '.', ' ') . ' FCFA',
                    'total_price' => number_format($first_item['total_cost'], 0, '.', ' ') . ' FCFA',
                    'notes' => $meta['notes'] ?: '-',
                    'link' => 'stock-exit.php'
                ];
            }
        }
    }
} catch (Exception $e) {}

// 3. Extraire Transferts Inter-Boutiques
try {
    $has_tr_code = false;
    try {
        $chk_tr = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'transfer_code'");
        if ($chk_tr && $chk_tr->fetch()) {
            $has_tr_code = true;
        }
    } catch (Exception $ex) {
        $has_tr_code = false;
    }

    $tr_code_expr = $has_tr_code ? "COALESCE(st.transfer_code, CONCAT('TR-', LPAD(st.id, 5, '0')))" : "CONCAT('TR-', LPAD(st.id, 5, '0'))";

    $tr_sql = "
        SELECT 
            st.created_at,
            $tr_code_expr as ref_code,
            st.from_shop_id,
            st.to_shop_id,
            f_shop.name as from_shop_name,
            t_shop.name as to_shop_name,
            st.quantity,
            st.status,
            st.notes,
            u.name as user_name,
            p.id as prod_id,
            p.name as product_name,
            p.unit as product_unit
        FROM stock_transfers st
        JOIN products p ON st.product_id = p.id
        JOIN shops f_shop ON st.from_shop_id = f_shop.id
        JOIN shops t_shop ON st.to_shop_id = t_shop.id
        LEFT JOIN users u ON st.user_id = u.id
        WHERE 1=1
    ";
    
    $params_tr = [];
    if ($product_id > 0) {
        $tr_sql .= " AND st.product_id = :product_id";
        $params_tr['product_id'] = $product_id;
    }
    if ($filter_shop_id > 0) {
        $tr_sql .= " AND (st.from_shop_id = " . intval($filter_shop_id) . " OR st.to_shop_id = " . intval($filter_shop_id) . ")";
    }
    if (!empty($start_date)) {
        $tr_sql .= " AND DATE(st.created_at) >= " . $pdo->quote($start_date);
    }
    if (!empty($end_date)) {
        $tr_sql .= " AND DATE(st.created_at) <= " . $pdo->quote($end_date);
    }
    $tr_sql .= " ORDER BY st.created_at DESC LIMIT " . $history_limit;

    $tr_stmt = $pdo->prepare($tr_sql);
    $tr_stmt->execute($params_tr);
    foreach ($tr_stmt->fetchAll() as $row) {
        if (($row['status'] ?? 'RECEIVED') === 'CANCELLED') {
            continue; // Ne pas compter les transferts annulés
        }
        $unit_str = $row['product_unit'] ?: 'unités';
        $is_from = ($row['from_shop_id'] == $filter_shop_id || $filter_shop_id == 0);
        $is_to = ($row['to_shop_id'] == $filter_shop_id);

        if ($filter_shop_id > 0) {
            if ($is_from && !$is_to) {
                $kpi_transfers_out += intval($row['quantity']);
                $timeline_events[] = [
                    'timestamp' => strtotime($row['created_at']),
                    'date' => date('d/m/Y H:i', strtotime($row['created_at'])),
                    'product_id' => $row['prod_id'],
                    'product_name' => $row['product_name'],
                    'product_unit' => $unit_str,
                    'event_type' => 'TRANSFER',
                    'badge_class' => 'bg-secondary text-white',
                    'badge_label' => '🔄 Transfert Sortant',
                    'qty_change' => '-' . intval($row['quantity']) . ' ' . $unit_str,
                    'qty_num' => -intval($row['quantity']),
                    'shop_name' => $row['from_shop_name'],
                    'contact' => 'Vers : ' . $row['to_shop_name'],
                    'operator' => $row['user_name'] ?? 'Opérateur',
                    'ref_code' => $row['ref_code'],
                    'unit_price' => '-',
                    'total_price' => '-',
                    'notes' => $row['notes'] ?: '-',
                    'link' => 'transfer-receipt.php?code=' . urlencode($row['ref_code'])
                ];
            } elseif ($is_to) {
                $kpi_transfers_in += intval($row['quantity']);
                $timeline_events[] = [
                    'timestamp' => strtotime($row['created_at']),
                    'date' => date('d/m/Y H:i', strtotime($row['created_at'])),
                    'product_id' => $row['prod_id'],
                    'product_name' => $row['product_name'],
                    'product_unit' => $unit_str,
                    'event_type' => 'TRANSFER',
                    'badge_class' => 'bg-dark text-white',
                    'badge_label' => '🔄 Transfert Entrant',
                    'qty_change' => '+' . intval($row['quantity']) . ' ' . $unit_str,
                    'qty_num' => intval($row['quantity']),
                    'shop_name' => $row['to_shop_name'],
                    'contact' => 'Depuis : ' . $row['from_shop_name'],
                    'operator' => $row['user_name'] ?? 'Opérateur',
                    'ref_code' => $row['ref_code'],
                    'unit_price' => '-',
                    'total_price' => '-',
                    'notes' => $row['notes'] ?: '-',
                    'link' => 'transfer-receipt.php?code=' . urlencode($row['ref_code'])
                ];
            }
        } else {
            $kpi_transfers_out += intval($row['quantity']);
            $timeline_events[] = [
                'timestamp' => strtotime($row['created_at']),
                'date' => date('d/m/Y H:i', strtotime($row['created_at'])),
                'product_id' => $row['prod_id'],
                'product_name' => $row['product_name'],
                'product_unit' => $unit_str,
                'event_type' => 'TRANSFER',
                'badge_class' => 'bg-secondary text-white',
                'badge_label' => '🔄 Transfert Inter-Dépôts',
                'qty_change' => intval($row['quantity']) . ' ' . $unit_str,
                'qty_num' => 0, // En vue globale, un transfert entre dépôts d'une même entreprise ne modifie pas le stock global cumulé

                'shop_name' => $row['from_shop_name'] . ' ➔ ' . $row['to_shop_name'],
                'contact' => $row['from_shop_name'] . ' ➔ ' . $row['to_shop_name'],
                'operator' => $row['user_name'] ?? 'Opérateur',
                'ref_code' => $row['ref_code'],
                'unit_price' => '-',
                'total_price' => '-',
                'notes' => $row['notes'] ?: '-',
                'link' => 'transfer-receipt.php?code=' . urlencode($row['ref_code'])
            ];
        }
    }
} catch (Exception $e) {}

// Filtrer par event_type si sélectionné
if ($event_filter !== 'ALL') {
    $timeline_events = array_filter($timeline_events, function($ev) use ($event_filter) {
        return $ev['event_type'] === $event_filter;
    });
}

// Trier tous les événements du plus récent au plus ancien (ordre chronologique inverse)
usort($timeline_events, function($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

// Calcul chronologique naturel à partir du stock initial officiel
$initial_stock_start = 0;
$current_real_stock = 0;

if ($product_id > 0 && $product) {
    if ($filter_shop_id > 0) {
        $cur_stock_stmt = $pdo->prepare("SELECT COALESCE(quantity, 0), COALESCE(initial_stock, 0) FROM product_stock WHERE product_id = ? AND shop_id = ?");
        $cur_stock_stmt->execute([$product_id, $filter_shop_id]);
        $stk_row = $cur_stock_stmt->fetch(PDO::FETCH_NUM);
        $current_real_stock = intval($stk_row[0] ?? 0);
        $initial_stock_start = intval($stk_row[1] ?? 0);
    } else {
        $cur_stock_stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0), COALESCE(SUM(initial_stock), 0) FROM product_stock WHERE product_id = ?");
        $cur_stock_stmt->execute([$product_id]);
        $stk_row = $cur_stock_stmt->fetch(PDO::FETCH_NUM);
        $current_real_stock = intval($stk_row[0] ?? 0);
        $initial_stock_start = intval($stk_row[1] ?? 0);
    }

    // Calcul chronologique naturel : départ du stock initial, puis ajout/retrait de chaque mouvement
    $chrono_events = array_reverse($timeline_events);
    $running_stock = $initial_stock_start;
    for ($i = 0; $i < count($chrono_events); $i++) {
        $chrono_events[$i]['stock_before'] = $running_stock;
        $var = intval($chrono_events[$i]['qty_num']);
        $running_stock = $running_stock + $var;
        $chrono_events[$i]['stock_after'] = $running_stock;
    }
    
    // Le stock final calculé est le véritable stock physique
    if (empty($start_date) && empty($end_date)) {
        $current_real_stock = max(0, $running_stock);
        // Auto-réconciliation : si le stock physique en base diverge du solde chronologique réel des mouvements
        if ($filter_shop_id > 0 && isset($stk_row[0]) && intval($stk_row[0]) !== $current_real_stock) {
            try {
                $pdo->prepare("UPDATE product_stock SET quantity = ? WHERE product_id = ? AND shop_id = ?")
                    ->execute([$current_real_stock, $product_id, $filter_shop_id]);
                // Synchroniser immédiatement la carte d'affichage du haut
                foreach ($stock_by_shop as &$sb) {
                    if ($sb['shop_id'] == $filter_shop_id) {
                        $sb['quantity'] = $current_real_stock;
                    }
                }
                unset($sb);
            } catch (Exception $e) {}
        }
    }
    
    // Remettre dans l'ordre antéchronologique (du plus récent au plus ancien) pour l'affichage du tableau
    $timeline_events = array_reverse($chrono_events);
}

// Pagination de la timeline
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 40;
$total_timeline_events = count($timeline_events);
$total_pages = max(1, ceil($total_timeline_events / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;
$paginated_events = array_slice($timeline_events, $offset, $per_page);
?>

<!-- Notifications -->
<?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-3" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-3" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Title & Selector Banner -->
<div class="row mb-4 align-items-center">
    <div class="col-12 col-md-6 mb-2 mb-md-0">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Fiche d'Historique & Traçabilité Produit
        </h1>
        <small class="text-secondary">
            <?= $product_id > 0 ? ("Journal détaillé des mouvements pour l'article <strong>" . htmlspecialchars($product['name'] ?? '') . "</strong>") : "Journal global des mouvements les plus récents de tous les articles" ?>
        </small>
    </div>
    <div class="col-12 col-md-6">
        <form action="product-history.php" method="GET" class="d-flex flex-wrap align-items-center gap-2 justify-content-md-end">
            <input type="hidden" name="shop_id" value="<?= $filter_shop_id ?>">
            <?php if ($product_id > 0 && $_SESSION['user_role'] === 'admin'): ?>
                <button type="button" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm no-print px-3 py-1.5" style="border-radius: 8px;" data-bs-toggle="modal" data-bs-target="#editStockModal" title="Définir ou réajuster le stock physique">
                    <i class="fa-solid fa-pen-to-square me-1.5"></i>Ajuster / Corriger le Stock
                </button>
            <?php endif; ?>
            <button type="button" onclick="window.print()" class="btn btn-outline-dark fw-bold btn-sm shadow-sm no-print px-3 py-1.5" style="border-radius: 8px;" title="Imprimer la Fiche et l'Historique">
                <i class="fa-solid fa-print text-primary me-1.5 fs-6"></i>Imprimer
            </button>
            <div class="position-relative" style="min-width: 240px; max-width: 320px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-secondary"><i class="fa-solid fa-magnifying-glass text-primary"></i></span>
                    <input type="text" id="historyProductSearchInput" class="form-control border-start-0 ps-0" placeholder="Rechercher un produit..." autocomplete="off" oninput="handleHistoryProductSearch(this.value)">
                </div>
                <div id="historySearchResults" class="list-group position-absolute w-100 shadow-lg border mt-1 d-none" style="z-index: 1050; max-height: 280px; overflow-y: auto; border-radius: 8px;"></div>
            </div>
            <?php if ($product_id > 0): ?>
                <a href="product-history.php?shop_id=<?= $filter_shop_id ?>" class="btn btn-outline-secondary btn-sm fw-semibold" style="border-radius: 8px;" title="Revenir au journal global">
                    <i class="fa-solid fa-globe me-1"></i>Vue Globale
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($product_id > 0 && $product): ?>
    <!-- FICHE RECAPITULATIVE & STOCK PAR DEPOT (MODE ARTICLE SPECIFIQUE) -->
    <div class="row g-3 mb-4">
        <!-- Carte Infos Produit -->
        <div class="col-12 col-lg-5">
            <div class="glass-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h4 class="fw-extrabold text-slate-800 mb-0"><?= htmlspecialchars($product['name']) ?></h4>
                        <small class="text-muted">Réf SKU: <?= htmlspecialchars($product['sku'] ?: 'ART-' . $product['id']) ?> | Famille: <span class="badge bg-light text-dark border"><?= htmlspecialchars($product['category_name'] ?: 'Général') ?></span></small>
                    </div>
                    <span class="badge bg-primary px-3 py-2 fs-6" style="border-radius: 8px;"><?= htmlspecialchars($product['unit']) ?></span>
                </div>
                
                <hr class="my-2 text-muted opacity-25">

                <div class="row g-2 text-center mt-1">
                    <div class="col-4">
                        <div class="p-2 bg-light rounded">
                            <small class="text-secondary d-block">Prix Achat</small>
                            <span class="fw-bold text-dark"><?= number_format($product['price_purchase'], 0, '.', ' ') ?> F</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 bg-light rounded">
                            <small class="text-secondary d-block">Prix Vente</small>
                            <span class="fw-extrabold text-primary"><?= number_format($product['price_sale'], 0, '.', ' ') ?> F</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 bg-light rounded">
                            <small class="text-secondary d-block">Marge %</small>
                            <span class="fw-bold text-success"><?= htmlspecialchars($product['margin_percent'] ?? '5.00') ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Carte Répartition des Stocks par Dépôt -->
        <div class="col-12 col-lg-7">
            <div class="glass-card p-3 h-100">
                <h6 class="fw-bold text-slate-800 mb-2"><i class="fa-solid fa-store text-primary me-2"></i>État du Stock Réel par Dépôt</h6>
                <div class="row g-2">
                    <?php 
                    $total_stock_all = 0;
                    foreach ($stock_by_shop as $stk): 
                        $total_stock_all += $stk['quantity'];
                    ?>
                        <div class="col-12 col-sm-4">
                            <div class="p-2.5 rounded border <?= $stk['quantity'] <= $product['min_stock_level'] ? 'bg-warning-subtle border-warning-subtle' : 'bg-white' ?>">
                                <small class="text-secondary fw-semibold d-block text-truncate"><?= htmlspecialchars($stk['shop_name']) ?></small>
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <span class="fs-5 fw-extrabold <?= $stk['quantity'] <= 0 ? 'text-danger' : 'text-slate-800' ?>"><?= number_format($stk['quantity'], 0, '.', ' ') ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($product['unit']) ?></small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-2 pt-2 border-top d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-slate-700">Stock Total Cumulé (Tous Dépôts) :</span>
                    <span class="badge bg-dark fs-6 py-1.5 px-3"><?= number_format($total_stock_all, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit']) ?></span>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- BANNIERE DE BIENVENUE EN VUE GLOBALE -->
    <div class="alert alert-primary border-0 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2" style="border-radius: 12px;">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white p-2 rounded-3 me-3"><i class="fa-solid fa-globe fa-lg"></i></div>
            <div>
                <h6 class="fw-bold mb-0 text-dark">Vue Globale — Journal des Mouvements Récent de Tous les Articles</h6>
                <p class="small text-secondary mb-0">Vous consultez l'historique complet combiné (Achats, Ventes, Découpes, Transferts). Pour cibler un article précis, choisissez-le dans le menu en haut à droite.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($product_id > 0 && $product): ?>
    <!-- CARTE RÉCAPITULATIVE DU STOCK INITIAL ET ACTUEL -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
            <div class="glass-card p-3 border-start border-4 border-secondary bg-light bg-opacity-50 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-secondary fw-bold text-uppercase d-block mb-1">
                            <i class="fa-solid fa-hourglass-start me-1 text-secondary"></i>Stock Initial (Début de Période)
                        </small>
                        <div class="fs-4 fw-extrabold text-slate-800">
                            <?= number_format($initial_stock_start, 0, '.', ' ') ?> <span class="fs-6 fw-semibold text-secondary"><?= htmlspecialchars($product['unit']) ?></span>
                        </div>
                    </div>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-2 fs-6" style="border-radius: 8px;">
                        Solde de départ
                    </span>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="glass-card p-3 border-start border-4 border-primary bg-primary bg-opacity-10 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-primary fw-bold text-uppercase d-block mb-1">
                            <i class="fa-solid fa-boxes-stacked me-1"></i>Stock Actuel Présent
                        </small>
                        <div class="fs-4 fw-extrabold text-primary">
                            <?= number_format($current_real_stock, 0, '.', ' ') ?> <span class="fs-6 fw-semibold text-primary"><?= htmlspecialchars($product['unit']) ?></span>
                        </div>
                    </div>
                    <span class="badge bg-primary fs-6 px-3 py-2" style="border-radius: 8px;">
                        En magasin
                    </span>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- CARTES KPIS CUMULÉS DU PRODUIT OU GLOBALES -->
<div class="row g-3 mb-4">
    <?php if ($product_id > 0 && $initial_stock_start !== null): ?>
        <div class="col-6 col-md-2">
            <div class="glass-card p-3 border-start border-4 border-secondary h-100">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-flag text-secondary me-1"></i>Stock Initial</small>
                <div class="fs-5 fw-extrabold text-slate-800"><?= number_format($initial_stock_start, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted">Stock au démarrage de l'historique</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="glass-card p-3 border-start border-4 border-success h-100">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-cart-shopping text-success me-1"></i>Total Ventes</small>
                <div class="fs-5 fw-extrabold text-success"><?= number_format($kpi_sales_qty, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted"><?= number_format($kpi_sales_amount, 0, '.', ' ') ?> FCFA CA</small>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="glass-card p-3 border-start border-4 border-info h-100">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-truck-field text-info me-1"></i>Total Achats</small>
                <div class="fs-5 fw-extrabold text-info"><?= number_format($kpi_purchases_qty, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted"><?= number_format($kpi_purchases_amount, 0, '.', ' ') ?> FCFA</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="glass-card p-3 border-start border-4 border-primary h-100">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-industry text-primary me-1"></i>Productions / Découpes</small>
                <div class="fs-5 fw-extrabold text-primary">+<?= number_format($kpi_production_generated, 0, '.', ' ') ?> / -<?= number_format($kpi_production_consumed, 0, '.', ' ') ?></div>
                <small class="text-muted">unités fabriquées/consommées</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="glass-card p-3 border-start border-4 border-warning h-100">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>Pertes & Démarque</small>
                <div class="fs-5 fw-extrabold text-danger"><?= number_format($kpi_losses_qty, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted">Sorties internes ou gâtées</small>
            </div>
        </div>
    <?php else: ?>
        <div class="col-6 col-md-3">
            <div class="glass-card p-3 border-start border-4 border-success">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-cart-shopping text-success me-1"></i>Total Ventes</small>
                <div class="fs-5 fw-extrabold text-success"><?= number_format($kpi_sales_qty, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted"><?= number_format($kpi_sales_amount, 0, '.', ' ') ?> FCFA de CA</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="glass-card p-3 border-start border-4 border-info">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-truck-field text-info me-1"></i>Total Achats</small>
                <div class="fs-5 fw-extrabold text-info"><?= number_format($kpi_purchases_qty, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted"><?= number_format($kpi_purchases_amount, 0, '.', ' ') ?> FCFA d'achats</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="glass-card p-3 border-start border-4 border-primary">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-industry text-primary me-1"></i>Productions / Découpes</small>
                <div class="fs-5 fw-extrabold text-primary">+<?= number_format($kpi_production_generated, 0, '.', ' ') ?> / -<?= number_format($kpi_production_consumed, 0, '.', ' ') ?></div>
                <small class="text-muted">unités fabriquées/consommées</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="glass-card p-3 border-start border-4 border-warning">
                <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>Pertes & Consommations</small>
                <div class="fs-5 fw-extrabold text-danger"><?= number_format($kpi_losses_qty, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit'] ?? 'unités') ?></div>
                <small class="text-muted">Sorties internes ou gâtées</small>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- FILTRES DE RECHERCHE DANS L'HISTORIQUE -->
<div class="glass-card p-3 mb-4">
    <form action="product-history.php" method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="id" value="<?= $product_id ?>">
        
        <div class="col-6 col-md-3">
            <label class="form-label small fw-semibold">Filtrer par Dépôt :</label>
            <select name="shop_id" class="form-select form-select-sm">
                <option value="0" <?= $filter_shop_id == 0 ? 'selected' : '' ?>>🌐 Tous les dépôts (Global)</option>
                <?php foreach ($all_shops as $sh): ?>
                    <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $filter_shop_id ? 'selected' : '' ?>>🏢 <?= htmlspecialchars($sh['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label small fw-semibold">Type de mouvement :</label>
            <select name="event_type" class="form-select form-select-sm">
                <option value="ALL" <?= $event_filter === 'ALL' ? 'selected' : '' ?>>Tous les mouvements</option>
                <option value="SALE" <?= $event_filter === 'SALE' ? 'selected' : '' ?>>🛒 Ventes seulement</option>
                <option value="PURCHASE" <?= $event_filter === 'PURCHASE' ? 'selected' : '' ?>>📥 Achats seulement</option>
                <option value="PRODUCTION" <?= $event_filter === 'PRODUCTION' ? 'selected' : '' ?>>✂️ Productions / Découpes</option>
                <option value="TRANSFER" <?= $event_filter === 'TRANSFER' ? 'selected' : '' ?>>🔄 Transferts inter-boutiques</option>
                <option value="INTERNAL_USE" <?= $event_filter === 'INTERNAL_USE' ? 'selected' : '' ?>>🏢 Consommations internes</option>
                <option value="LOSS" <?= $event_filter === 'LOSS' ? 'selected' : '' ?>>⚠️ Pertes / Casses</option>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold">Du (Début) :</label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($start_date) ?>">
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold">Au (Fin) :</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($end_date) ?>">
        </div>

        <div class="col-12 col-md-2 text-end">
            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold" style="border-radius: 8px;">
                <i class="fa-solid fa-filter me-1"></i>Filtrer
            </button>
        </div>
    </form>
</div>

<!-- TABLEAU CHRONOLOGIQUE TOUS EVENEMENTS PRODUIT OU GLOBAL -->
<div class="glass-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold text-slate-800 mb-0">
            <i class="fa-solid fa-list-check text-primary me-2"></i>
            <?= $product_id > 0 ? ("Journal Chronologique : " . htmlspecialchars($product['name'] ?? '')) : "Journal Chronologique Global des Mouvements Récent" ?>
            (<?= count($timeline_events) ?> événement<?= count($timeline_events) > 1 ? 's' : '' ?>)
        </h5>
        <input type="text" id="search_timeline_input" class="form-control form-control-sm" style="max-width: 250px;" placeholder="🔍 Rechercher un produit, réf..." onkeyup="filterTimelineTable()">
    </div>

    <?php if (empty($timeline_events)): ?>
        <div class="text-center py-5 text-secondary">
            <i class="fa-solid fa-clock-rotate-left fa-3x mb-3 text-muted"></i>
            <p class="mb-0">Aucun mouvement trouvé avec les filtres actuels.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="productTimelineTable">
                <thead class="table-light">
                    <tr>
                        <th>Date & Heure</th>
                        <th>Article / Produit</th>
                        <th>Événement / Nature</th>
                        <th class="text-center">Variation Stock</th>
                        <th class="text-center bg-primary bg-opacity-10 text-primary border-start border-end fw-bold">Stock Résultant (Solde)</th>
                        <th>Dépôt / Magasin</th>
                        <th>Tiers / Informations</th>
                        <th>Référence</th>
                        <th class="text-end">Prix / Coût Total</th>
                        <th class="text-center no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paginated_events as $ev): ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark d-block"><?= $ev['date'] ?></span>
                            </td>
                            <td>
                                <a href="product-history.php?id=<?= $ev['product_id'] ?>" class="fw-bold text-primary text-decoration-none" title="Voir l'historique exclusif de cet article">
                                    <i class="fa-solid fa-box me-1"></i><?= htmlspecialchars($ev['product_name']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge <?= $ev['badge_class'] ?> py-1.5 px-2.5 fw-semibold" style="font-size: 0.78rem;">
                                    <?= $ev['badge_label'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="fw-extrabold fs-6 <?= $ev['qty_num'] < 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= ($ev['qty_num'] > 0 ? '+' : '') . number_format($ev['qty_num'], 0, '.', ' ') ?>
                                </span>
                            </td>
                            <td class="text-center bg-light border-start border-end">
                                <?php if (isset($ev['stock_after'])): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle fs-6 px-2.5 py-1 fw-extrabold" title="Stock restant juste après ce mouvement">
                                        = <?= number_format($ev['stock_after'], 0, '.', ' ') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="fw-semibold text-slate-800"><?= htmlspecialchars($ev['shop_name']) ?></span>
                            </td>
                            <td>
                                <span class="d-block small fw-bold text-dark"><?= htmlspecialchars($ev['contact']) ?></span>
                                <small class="text-muted">Opérateur: <?= htmlspecialchars($ev['operator']) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($ev['ref_code']) ?></span>
                            </td>
                            <td class="text-end fw-extrabold text-slate-800">
                                <?= $ev['total_price'] ?>
                            </td>
                            <td class="text-center no-print">
                                <a href="<?= $ev['link'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-1 px-2" title="Voir la pièce justificative">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top flex-wrap gap-2 no-print">
                <div class="small text-secondary">
                    Affichage de <strong><?= count($paginated_events) ?></strong> sur <strong><?= $total_timeline_events ?></strong> mouvements (Page <?= $page ?> sur <?= $total_pages ?>)
                </div>
                <nav aria-label="Pagination historique">
                    <ul class="pagination pagination-sm mb-0">
                        <!-- Précédent -->
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

                        <!-- Suivant -->
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
const historyCatalog = <?= json_encode(array_map(function($p) {
    return [
        'id' => intval($p['id']),
        'name' => $p['name'],
        'sku' => $p['sku'] ?? '',
        'unit' => $p['unit'] ?? 'UNITE',
        'category_name' => $p['category_name'] ?? 'Général'
    ];
}, $all_catalog_products)) ?>;

const currentShopFilter = <?= $filter_shop_id ?>;

function normalizeSearch(str) {
    return (str || '').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();
}

function handleHistoryProductSearch(query) {
    const resultsContainer = document.getElementById('historySearchResults');
    const rawFilter = query.trim();

    if (rawFilter.length < 1) {
        resultsContainer.classList.add('d-none');
        resultsContainer.innerHTML = '';
        return;
    }

    const searchTokens = normalizeSearch(rawFilter).split(/\s+/).filter(t => t.length > 0);

    const matches = historyCatalog.filter(p => {
        const targetText = normalizeSearch(p.name + ' ' + (p.sku || '') + ' ' + p.id + ' ' + (p.unit || '') + ' ' + (p.category_name || ''));
        return searchTokens.every(token => targetText.includes(token));
    });

    if (matches.length === 0) {
        resultsContainer.innerHTML = '<div class="list-group-item text-muted text-center py-2 small">Aucun article trouvé</div>';
        resultsContainer.classList.remove('d-none');
        return;
    }

    let html = '';
    matches.slice(0, 10).forEach(p => {
        html += `
            <a href="product-history.php?id=${p.id}&shop_id=${currentShopFilter}" class="list-group-item list-group-item-action py-2 px-3 d-flex justify-content-between align-items-center text-decoration-none">
                <div>
                    <span class="fw-bold text-slate-800 small">${escapeHtml(p.name)}</span>
                    <small class="text-secondary d-block" style="font-size: 0.75rem;">${escapeHtml(p.category_name)} | ${escapeHtml(p.unit)}</small>
                </div>
                <span class="badge bg-light text-primary border small"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
        `;
    });

    resultsContainer.innerHTML = html;
    resultsContainer.classList.remove('d-none');
}

// Fermer les résultats si clic à l'extérieur
document.addEventListener('click', function(e) {
    const input = document.getElementById('historyProductSearchInput');
    const results = document.getElementById('historySearchResults');
    if (input && results && !input.contains(e.target) && !results.contains(e.target)) {
        results.classList.add('d-none');
    }
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function filterTimelineTable() {
    const input = document.getElementById('search_timeline_input');
    const filter = input.value.toLowerCase().trim();
    const table = document.getElementById('productTimelineTable');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(tr => {
        const text = tr.textContent.toLowerCase();
        tr.style.display = text.includes(filter) ? '' : 'none';
    });
}
</script>

<?php if ($product_id > 0 && $product && $_SESSION['user_role'] === 'admin'): ?>
<!-- MODAL DE DEFINITION / CORRECTION DU STOCK INITIAL & REEL -->
<div class="modal fade" id="editStockModal" tabindex="-1" aria-labelledby="editStockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0" style="border-radius: 16px;">
            <form action="product-history.php?id=<?= $product_id ?>&shop_id=<?= $filter_shop_id ?>" method="POST" id="stockAdjustmentForm">
                <input type="hidden" name="action_update_stock" value="1">
                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                <input type="hidden" name="update_mode" id="update_mode" value="initial">
                <input type="hidden" name="net_movements_val" id="net_movements_val" value="<?= ($current_real_stock - ($initial_stock_start ?? 0)) ?>">
                
                <div class="modal-header bg-light border-bottom py-3">
                    <h5 class="modal-title fw-bold text-slate-800" id="editStockModalLabel">
                        <i class="fa-solid fa-hourglass-start text-warning me-2"></i>Définir / Modifier le Stock Initial
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Article concerné</label>
                        <input type="text" class="form-control form-control-custom bg-light fw-bold" value="<?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['unit']) ?>)" readonly>
                    </div>

                    <div class="mb-3">
                        <label for="target_shop_id" class="form-label small fw-bold text-secondary">Dépôt / Boutique cible *</label>
                        <select name="target_shop_id" id="target_shop_id" class="form-select form-control-custom" required>
                            <?php foreach ($all_shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= ($filter_shop_id == $sh['id']) ? 'selected' : '' ?>>
                                    🏢 <?= htmlspecialchars($sh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Sélecteur d'onglet : Modifier Stock Initial VS Modifier Stock Réel Actuel -->
                    <ul class="nav nav-pills nav-fill mb-3 bg-light p-1 rounded" id="stockModeTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1.5 fw-bold small" id="initial-tab" type="button" onclick="setStockMode('initial')">
                                <i class="fa-solid fa-flag me-1"></i>Stock Initial (Départ)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link py-1.5 fw-bold small" id="current-tab" type="button" onclick="setStockMode('current')">
                                <i class="fa-solid fa-boxes-stacked me-1"></i>Stock Actuel (Physique)
                            </button>
                        </li>
                    </ul>

                    <!-- SECTION 1 : MODIFICATION STOCK INITIAL -->
                    <div id="section_initial_stock">
                        <div class="p-3 bg-light rounded border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small text-secondary fw-semibold">Stock initial actuel (calculé) :</span>
                                <span class="fw-bold text-slate-800"><?= number_format($initial_stock_start ?? 0, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small text-secondary fw-semibold">Total mouvements historiques :</span>
                                <span class="fw-bold <?= ($current_real_stock - ($initial_stock_start ?? 0)) >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($current_real_stock - ($initial_stock_start ?? 0)) >= 0 ? '+' : '' ?><?= ($current_real_stock - ($initial_stock_start ?? 0)) ?> <?= htmlspecialchars($product['unit']) ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                <span class="small fw-bold text-slate-800">Stock Réel Actuel en rayon :</span>
                                <span class="fw-extrabold text-primary"><?= number_format($current_real_stock, 0, '.', ' ') ?> <?= htmlspecialchars($product['unit']) ?></span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="new_initial_stock" class="form-label small fw-bold text-dark">
                                <i class="fa-solid fa-pen text-primary me-1"></i>Nouveau Stock Initial Souhaité (<?= htmlspecialchars($product['unit']) ?>) *
                            </label>
                            <input type="number" min="0" step="1" name="new_initial_stock" id="new_initial_stock" class="form-control form-control-custom fw-extrabold text-primary fs-5" value="<?= max(0, intval($initial_stock_start ?? 0)) ?>" oninput="recalcResultingStock()">
                            <small class="text-muted">Ce stock servira de point de départ initial pour cet article.</small>
                        </div>

                        <div class="alert alert-info border-0 py-2 px-3 mb-3 d-flex justify-content-between align-items-center" style="border-radius: 8px;">
                            <span class="small fw-semibold"><i class="fa-solid fa-calculator me-1"></i>Nouveau Stock Réel calculé :</span>
                            <span class="fs-6 fw-extrabold text-primary" id="preview_resulting_stock"><?= $current_real_stock ?> <?= htmlspecialchars($product['unit']) ?></span>
                        </div>
                    </div>

                    <!-- SECTION 2 : MODIFICATION DIRECTE STOCK ACTUEL -->
                    <div id="section_current_stock" class="d-none">
                        <div class="mb-3">
                            <label for="new_stock_qty" class="form-label small fw-bold text-dark">Nouvelle Quantité Physique en Rayon (<?= htmlspecialchars($product['unit']) ?>) *</label>
                            <input type="number" min="0" step="1" name="new_stock_qty" id="new_stock_qty" class="form-control form-control-custom fw-extrabold text-success fs-5" value="<?= $current_real_stock ?>">
                            <small class="text-muted">Indiquez la quantité physique exacte comptée lors du dernier inventaire.</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="adj_date" class="form-label small fw-bold text-secondary">Date & Heure d'effet</label>
                        <input type="datetime-local" name="adj_date" id="adj_date" class="form-control form-control-custom" value="<?= date('Y-m-d\TH:i') ?>" required>
                        <small class="text-muted">Date retenue pour l'imputation chronologique.</small>
                    </div>

                    <div class="mb-0">
                        <label for="adj_notes" class="form-label small fw-bold text-secondary">Motif / Justification</label>
                        <input type="text" name="adj_notes" id="adj_notes" class="form-control form-control-custom" placeholder="Ex: Définition stock de départ, correction saisie...">
                    </div>
                </div>

                <div class="modal-footer bg-light border-top py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm px-3">
                        <i class="fa-solid fa-check me-1"></i>Enregistrer la modification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const netMovements = <?= intval($current_real_stock - ($initial_stock_start ?? 0)) ?>;
const productUnit = '<?= addslashes(htmlspecialchars($product['unit'])) ?>';

function setStockMode(mode) {
    document.getElementById('update_mode').value = mode;
    const secInit = document.getElementById('section_initial_stock');
    const secCur = document.getElementById('section_current_stock');
    const tabInit = document.getElementById('initial-tab');
    const tabCur = document.getElementById('current-tab');
    
    if (mode === 'initial') {
        secInit.classList.remove('d-none');
        secCur.classList.add('d-none');
        tabInit.classList.add('active');
        tabCur.classList.remove('active');
    } else {
        secInit.classList.add('d-none');
        secCur.classList.remove('d-none');
        tabInit.classList.remove('active');
        tabCur.classList.add('active');
    }
}

function recalcResultingStock() {
    const initInput = document.getElementById('new_initial_stock');
    const previewEl = document.getElementById('preview_resulting_stock');
    const val = parseInt(initInput.value) || 0;
    const resulting = Math.max(0, val + netMovements);
    previewEl.textContent = resulting + ' ' + productUnit;
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
