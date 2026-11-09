<?php
require_once __DIR__ . '/includes/header.php';

// Sécurité : Tout utilisateur connecté a accès, mais les vendeurs ont des restrictions sur la source
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$message = '';
$error = '';

// Migration automatique ultra-sécurisée au chargement de la page (transfer_code & status)
$has_transfer_code = false;
try {
    $check_code_col = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'transfer_code'");
    if ($check_code_col && $check_code_col->fetch()) {
        $has_transfer_code = true;
    } else {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN transfer_code VARCHAR(50) NULL AFTER id");
        $pdo->exec("UPDATE stock_transfers SET transfer_code = CONCAT('TR-', LPAD(id, 5, '0')) WHERE transfer_code IS NULL OR transfer_code = ''");
        $has_transfer_code = true;
    }
} catch (Exception $e) {
    $has_transfer_code = false;
}

try {
    $check_status_col = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'status'");
    if (!$check_status_col || !$check_status_col->fetch()) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN status ENUM('PENDING', 'RECEIVED', 'CANCELLED') NOT NULL DEFAULT 'RECEIVED' AFTER notes");
        // Les anciens transferts existants restent RECEIVED
    }
    $check_rcv_user = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'received_by'");
    if (!$check_rcv_user || !$check_rcv_user->fetch()) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN received_by INT NULL AFTER status");
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN received_at DATETIME NULL AFTER received_by");
    }
} catch (Exception $e) {}

// Récupérer toutes les boutiques pour le formulaire de transfert
$shops_stmt = $pdo->query("SELECT * FROM shops ORDER BY name");
$all_shops = $shops_stmt->fetchAll();

$active_shop_id = intval($_SESSION['active_shop_id'] ?? 0);

// Récupérer tous les produits et leurs stocks par boutique
$products_stmt = $pdo->query("
    SELECT p.id, p.name, p.unit, p.sku, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    ORDER BY p.name ASC
");
$all_products = $products_stmt->fetchAll();

$all_stocks_stmt = $pdo->query("SELECT product_id, shop_id, quantity FROM product_stock");
$all_product_stocks = [];
foreach ($all_stocks_stmt->fetchAll() as $row) {
    $all_product_stocks[$row['product_id']][$row['shop_id']] = intval($row['quantity']);
}

// 1. Traitement de la VALIDATION / RÉCEPTION d'un transfert par la boutique destinataire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_transfer_receipt'])) {
    $transfer_code_to_receive = trim($_POST['transfer_code'] ?? '');
    $transfer_id_to_receive = intval($_POST['transfer_id'] ?? 0);

    if (empty($transfer_code_to_receive) && $transfer_id_to_receive <= 0) {
        $error = "Transfert non spécifié.";
    } else {
        try {
            $pdo->beginTransaction();

            // Récupérer les lignes de transfert en attente (Recherche robuste par code ou par id)
            $pending_items = [];
            if (!empty($transfer_code_to_receive)) {
                $stmt_fetch_tr = $pdo->prepare("SELECT * FROM stock_transfers WHERE transfer_code = ? AND (status = 'PENDING' OR status IS NULL OR status = '')");
                $stmt_fetch_tr->execute([$transfer_code_to_receive]);
                $pending_items = $stmt_fetch_tr->fetchAll();
            }
            
            if (empty($pending_items) && $transfer_id_to_receive > 0) {
                // Essayer par l'ID exact ou par le code associé à cet ID
                $stmt_fetch_tr = $pdo->prepare("
                    SELECT * FROM stock_transfers 
                    WHERE (id = ? OR transfer_code = (SELECT t2.transfer_code FROM stock_transfers t2 WHERE t2.id = ?))
                      AND (status = 'PENDING' OR status IS NULL OR status = '')
                ");
                $stmt_fetch_tr->execute([$transfer_id_to_receive, $transfer_id_to_receive]);
                $pending_items = $stmt_fetch_tr->fetchAll();
            }

            if (empty($pending_items)) {
                throw new Exception("Ce transfert a déjà été réceptionné ou est introuvable.");
            }

            // Vérifier les droits : l'utilisateur doit être de la boutique destinataire ou Admin
            $dest_shop = $pending_items[0]['to_shop_id'];
            if ($_SESSION['user_role'] !== 'admin' && $_SESSION['active_shop_id'] != $dest_shop) {
                throw new Exception("Seule la boutique destinataire peut accuser réception de ce transfert.");
            }

            // Créditer le stock de la boutique de destination pour chaque article
            $stmt_add_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");

            foreach ($pending_items as $item) {
                $stmt_add_stock->execute([
                    $item['product_id'],
                    $item['to_shop_id'],
                    $item['quantity']
                ]);
            }

            // Mettre à jour le statut en 'RECEIVED' pour toutes les lignes trouvées par leur ID direct
            $item_ids = array_column($pending_items, 'id');
            if (!empty($item_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($item_ids), '?'));
                $stmt_up_status = $pdo->prepare("
                    UPDATE stock_transfers 
                    SET status = 'RECEIVED', is_read = 1, received_by = ?, received_at = NOW() 
                    WHERE id IN ($in_placeholders)
                ");
                $params = array_merge([$_SESSION['user_id']], $item_ids);
                $stmt_up_status->execute($params);
            }

            $pdo->commit();
            $message = "Réception confirmée avec succès ! Le stock de votre boutique a été augmenté de " . count($pending_items) . " article(s).";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Erreur lors de la validation de la réception : " . $e->getMessage();
        }
    }
}

// 1b. Traitement de l'ANNULATION d'un transfert (Restitution automatique des stocks)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_transfer'])) {
    $transfer_code_to_cancel = trim($_POST['transfer_code'] ?? '');
    $transfer_id_to_cancel = intval($_POST['transfer_id'] ?? 0);

    if (empty($transfer_code_to_cancel) && $transfer_id_to_cancel <= 0) {
        $error = "Transfert non spécifié.";
    } else {
        try {
            $pdo->beginTransaction();

            $items_to_cancel = [];
            if (!empty($transfer_code_to_cancel)) {
                $stmt_fetch = $pdo->prepare("SELECT * FROM stock_transfers WHERE transfer_code = ?");
                $stmt_fetch->execute([$transfer_code_to_cancel]);
                $items_to_cancel = $stmt_fetch->fetchAll();
            }

            if (empty($items_to_cancel) && $transfer_id_to_cancel > 0) {
                $stmt_fetch = $pdo->prepare("
                    SELECT * FROM stock_transfers 
                    WHERE id = ? OR transfer_code = (SELECT t2.transfer_code FROM stock_transfers t2 WHERE t2.id = ?)
                ");
                $stmt_fetch->execute([$transfer_id_to_cancel, $transfer_id_to_cancel]);
                $items_to_cancel = $stmt_fetch->fetchAll();
            }

            if (empty($items_to_cancel)) {
                throw new Exception("Transfert introuvable.");
            }

            $current_status = $items_to_cancel[0]['status'] ?? 'PENDING';
            if ($current_status === 'CANCELLED') {
                throw new Exception("Ce transfert a déjà été annulé auparavant.");
            }

            // Vérification des droits : Admin ou l'expéditeur si encore PENDING
            $is_admin = ($_SESSION['user_role'] === 'admin');
            $is_sender = ($items_to_cancel[0]['user_id'] == $_SESSION['user_id'] || $items_to_cancel[0]['from_shop_id'] == $_SESSION['active_shop_id']);
            
            if (!$is_admin && !($is_sender && $current_status === 'PENDING')) {
                throw new Exception("Seul l'administrateur ou l'expéditeur d'un transfert en transit peut l'annuler.");
            }

            // Préparation de la restitution au dépôt d'origine (from_shop_id)
            $stmt_restore_src = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");

            if ($current_status === 'RECEIVED') {
                // Le stock avait déjà été crédité au destinataire -> vérifier qu'il est encore disponible et le déduire
                $stmt_chk_dest = $pdo->prepare("SELECT quantity FROM product_stock WHERE product_id = ? AND shop_id = ?");
                $stmt_deduct_dest = $pdo->prepare("UPDATE product_stock SET quantity = quantity - ? WHERE product_id = ? AND shop_id = ?");

                foreach ($items_to_cancel as $item) {
                    $stmt_chk_dest->execute([$item['product_id'], $item['to_shop_id']]);
                    $dest_qty = intval($stmt_chk_dest->fetchColumn() ?: 0);
                    if ($dest_qty < $item['quantity']) {
                        $p_stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                        $p_stmt->execute([$item['product_id']]);
                        $p_name = $p_stmt->fetchColumn() ?: "Article #{$item['product_id']}";
                        throw new Exception("Impossible d'annuler : la boutique destinataire a déjà vendu ou consommé une partie du stock (Restant : $dest_qty / Requis pour annulation : {$item['quantity']} pour '$p_name').");
                    }
                }

                // Déduire de la destination et remettre à la source
                foreach ($items_to_cancel as $item) {
                    $stmt_deduct_dest->execute([$item['quantity'], $item['product_id'], $item['to_shop_id']]);
                    $stmt_restore_src->execute([$item['product_id'], $item['from_shop_id'], $item['quantity']]);
                }
            } else {
                // Statut PENDING : la destination n'a jamais reçu le stock. On réinjecte uniquement dans le dépôt d'origine
                foreach ($items_to_cancel as $item) {
                    $stmt_restore_src->execute([$item['product_id'], $item['from_shop_id'], $item['quantity']]);
                }
            }

            // Marquer comme CANCELLED
            $item_ids = array_column($items_to_cancel, 'id');
            $in_placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $stmt_cancel = $pdo->prepare("UPDATE stock_transfers SET status = 'CANCELLED' WHERE id IN ($in_placeholders)");
            $stmt_cancel->execute($item_ids);

            $pdo->commit();
            $tr_display_code = !empty($items_to_cancel[0]['transfer_code']) ? $items_to_cancel[0]['transfer_code'] : ('TR-' . sprintf("%05d", $items_to_cancel[0]['id']));
            $message = "Le transfert $tr_display_code a été annulé avec succès. Les stocks ont été automatiquement restitués au magasin d'origine (" . count($items_to_cancel) . " article(s)).";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Erreur lors de l'annulation du transfert : " . $e->getMessage();
        }
    }
}

// 2. Traitement de l'envoi d'un transfert Multi-Produits (Départ immédiat, statut PENDING)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perform_transfer'])) {
    $from_shop_id = intval($_POST['from_shop_id'] ?? 0);
    $to_shop_id = intval($_POST['to_shop_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? [];

    // Si l'utilisateur est un vendeur, on force le dépôt de départ sur son dépôt actif
    if ($_SESSION['user_role'] === 'seller') {
        $from_shop_id = $_SESSION['active_shop_id'];
    }

    if ($from_shop_id <= 0 || $to_shop_id <= 0) {
        $error = "Veuillez spécifier la boutique de départ et la boutique d'arrivée.";
    } elseif ($from_shop_id === $to_shop_id) {
        $error = "La boutique de départ et la boutique de destination doivent être différentes.";
    } elseif (empty($product_ids)) {
        $error = "Veuillez ajouter au moins un article à transférer.";
    } else {
        try {
            $pdo->beginTransaction();

            // Générer un code de transfert unique (ex: TR-00012)
            $next_num = 1;
            if ($has_transfer_code) {
                try {
                    $last_code_stmt = $pdo->query("SELECT transfer_code FROM stock_transfers WHERE transfer_code LIKE 'TR-%' ORDER BY id DESC LIMIT 1");
                    $last_code = $last_code_stmt->fetchColumn();
                    if ($last_code && preg_match('/^TR-(\d+)$/', $last_code, $matches)) {
                        $next_num = intval($matches[1]) + 1;
                    }
                } catch (Exception $ex) {}
            }
            $transfer_code = 'TR-' . sprintf("%05d", $next_num);

            $stmt_check_stock = $pdo->prepare("
                SELECT quantity FROM product_stock 
                WHERE product_id = ? AND shop_id = ?
            ");

            $stmt_deduct = $pdo->prepare("
                UPDATE product_stock 
                SET quantity = quantity - ? 
                WHERE product_id = ? AND shop_id = ?
            ");

            $is_admin_transfer = ($_SESSION['user_role'] === 'admin');
            $transfer_status = $is_admin_transfer ? 'RECEIVED' : 'PENDING';
            $received_by_val = $is_admin_transfer ? $_SESSION['user_id'] : null;

            // Préparer l'ajout immédiat au stock de destination si Admin
            $stmt_add_dest_stock = $pdo->prepare("
                INSERT INTO product_stock (product_id, shop_id, quantity) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");

            if ($has_transfer_code) {
                $stmt_log = $pdo->prepare("
                    INSERT INTO stock_transfers (transfer_code, product_id, from_shop_id, to_shop_id, quantity, notes, user_id, is_read, status, received_by, received_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, " . ($is_admin_transfer ? "1" : "0") . ", ?, ?, " . ($is_admin_transfer ? "NOW()" : "NULL") . ")
                ");
            } else {
                $stmt_log = $pdo->prepare("
                    INSERT INTO stock_transfers (product_id, from_shop_id, to_shop_id, quantity, notes, user_id, is_read, status, received_by, received_at) 
                    VALUES (?, ?, ?, ?, ?, ?, " . ($is_admin_transfer ? "1" : "0") . ", ?, ?, " . ($is_admin_transfer ? "NOW()" : "NULL") . ")
                ");
            }

            $items_transferred_count = 0;

            for ($i = 0; $i < count($product_ids); $i++) {
                $p_id = intval($product_ids[$i] ?? 0);
                $qty = intval($quantities[$i] ?? 0);

                if ($p_id <= 0 || $qty <= 0) continue;

                // 1. Vérifier le stock disponible dans la boutique de départ
                $stmt_check_stock->execute([$p_id, $from_shop_id]);
                $curr_stock = $stmt_check_stock->fetchColumn();

                // Nom du produit pour message d'erreur
                $p_info_stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                $p_info_stmt->execute([$p_id]);
                $p_name = $p_info_stmt->fetchColumn() ?: "Article #$p_id";

                if ($curr_stock === false || $curr_stock < $qty) {
                    throw new Exception("Stock insuffisant pour l'article '$p_name' dans la boutique de départ. Stock disponible : " . ($curr_stock !== false ? $curr_stock : 0));
                }

                // 2. Déduire le stock source immédiatement
                $stmt_deduct->execute([$qty, $p_id, $from_shop_id]);

                // 3. Si Admin, créditer immédiatement le stock récepteur
                if ($is_admin_transfer) {
                    $stmt_add_dest_stock->execute([$p_id, $to_shop_id, $qty]);
                }

                // 4. Enregistrer dans stock_transfers
                if ($has_transfer_code) {
                    $stmt_log->execute([$transfer_code, $p_id, $from_shop_id, $to_shop_id, $qty, $notes, $_SESSION['user_id'], $transfer_status, $received_by_val]);
                } else {
                    $stmt_log->execute([$p_id, $from_shop_id, $to_shop_id, $qty, $notes, $_SESSION['user_id'], $transfer_status, $received_by_val]);
                }

                $items_transferred_count++;
            }

            if ($items_transferred_count === 0) {
                throw new Exception("Aucun article valide n'a été sélectionné.");
            }

            $pdo->commit();
            if ($is_admin_transfer) {
                $message = "Le transfert $transfer_code comportant $items_transferred_count article(s) a été validé et crédité immédiatement dans la boutique de destination (Effectué par l'Admin).";
            } else {
                $message = "Le bon de transfert $transfer_code comportant $items_transferred_count article(s) a été expédié. Le stock source a été déduit et le transfert est en attente de validation par la boutique réceptrice.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Erreur lors du transfert : " . $e->getMessage();
        }
    }
}

// Traitement de l'acquittement / lecture des notifications de transfert
if (isset($_POST['mark_transfers_read'])) {
    try {
        if ($_SESSION['user_role'] === 'admin' && $_SESSION['active_shop_id'] == 0) {
            $pdo->exec("UPDATE stock_transfers SET is_read = 1 WHERE is_read = 0");
        } else {
            $mark_stmt = $pdo->prepare("UPDATE stock_transfers SET is_read = 1 WHERE to_shop_id = ? AND is_read = 0");
            $mark_stmt->execute([$_SESSION['active_shop_id']]);
        }
        $message = "Les notifications de transfert ont été marquées comme lues.";
    } catch (Exception $ex) {}
}

// Compter les transferts entrants en attente de réception pour la boutique courante
$pending_incoming_count = 0;
try {
    if ($_SESSION['user_role'] === 'admin' && $_SESSION['active_shop_id'] == 0) {
        $pending_incoming_count = intval($pdo->query("SELECT COUNT(DISTINCT transfer_code) FROM stock_transfers WHERE status = 'PENDING'")->fetchColumn() ?: 0);
    } else {
        $stmt_u = $pdo->prepare("SELECT COUNT(DISTINCT transfer_code) FROM stock_transfers WHERE to_shop_id = ? AND status = 'PENDING'");
        $stmt_u->execute([$_SESSION['active_shop_id']]);
        $pending_incoming_count = intval($stmt_u->fetchColumn() ?: 0);
    }
} catch (Exception $ex) {
    $pending_incoming_count = 0;
}

// Paramètres de filtrage pour l'historique des transferts
$filter_shop = isset($_GET['filter_shop']) ? intval($_GET['filter_shop']) : (($_SESSION['user_role'] === 'admin') ? 0 : intval($_SESSION['active_shop_id']));
$filter_status = trim($_GET['filter_status'] ?? '');
$search_term = trim($_GET['search_term'] ?? '');
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 30;

$where_trans = "WHERE 1=1";
$params_trans = [];

if ($_SESSION['user_role'] !== 'admin') {
    $where_trans .= " AND (t.from_shop_id = :sid OR t.to_shop_id = :sid)";
    $params_trans[':sid'] = $_SESSION['active_shop_id'];
} elseif ($filter_shop > 0) {
    $where_trans .= " AND (t.from_shop_id = :filter_sid OR t.to_shop_id = :filter_sid)";
    $params_trans[':filter_sid'] = $filter_shop;
}

if (!empty($filter_status)) {
    $where_trans .= " AND t.status = :filter_status";
    $params_trans[':filter_status'] = $filter_status;
}

if (!empty($search_term)) {
    $where_trans .= " AND (t.transfer_code LIKE :sterm OR t.notes LIKE :sterm OR p.name LIKE :sterm OR p.sku LIKE :sterm)";
    $params_trans[':sterm'] = '%' . $search_term . '%';
}

// 1. Calcul du nombre total de transferts uniques (Zéro doublon de comptage)
$count_sql = "
    SELECT COUNT(DISTINCT COALESCE(t.transfer_code, CONCAT('TR-', LPAD(t.id, 5, '0'))))
    FROM stock_transfers t
    JOIN products p ON t.product_id = p.id
    $where_trans
";
$cnt_stmt = $pdo->prepare($count_sql);
$cnt_stmt->execute($params_trans);
$total_records = intval($cnt_stmt->fetchColumn() ?: 0);

$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

// 2. Extraction des transferts groupés par bon (100% compatible MySQL strict et sans split intempestif)
try {
    $history_sql = "
        SELECT 
            COALESCE(t.transfer_code, CONCAT('TR-', LPAD(t.id, 5, '0'))) as group_code,
            MIN(t.id) as min_id,
            MAX(t.from_shop_id) as from_shop_id,
            MAX(t.to_shop_id) as to_shop_id,
            MAX(t.user_id) as user_id,
            MIN(t.created_at) as created_at,
            MAX(t.notes) as notes,
            MAX(t.status) as status,
            MAX(t.is_read) as is_read_status,
            MAX(fs.name) as from_shop_name,
            MAX(ts.name) as to_shop_name,
            MAX(u.name) as operator_name,
            MAX(rcv.name) as receiver_name,
            COUNT(t.id) as total_items_count,
            SUM(t.quantity) as total_units_sum,
            GROUP_CONCAT(CONCAT(p.name, ' (x', t.quantity, ')') SEPARATOR ', ') as items_summary
        FROM stock_transfers t
        JOIN products p ON t.product_id = p.id
        JOIN shops fs ON t.from_shop_id = fs.id
        JOIN shops ts ON t.to_shop_id = ts.id
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN users rcv ON t.received_by = rcv.id
        $where_trans
        GROUP BY COALESCE(t.transfer_code, CONCAT('TR-', LPAD(t.id, 5, '0')))
        ORDER BY MIN(t.created_at) DESC
        LIMIT $per_page OFFSET $offset
    ";
    $history_stmt = $pdo->prepare($history_sql);
    $history_stmt->execute($params_trans);
    $transfers = $history_stmt->fetchAll();
} catch (Exception $e) {
    $transfers = [];
    $error = "Erreur lors du chargement de l'historique : " . $e->getMessage();
}

$active_tab = $_GET['tab'] ?? 'history';
?>

<!-- Title & Header -->
<div class="row mb-3 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-900 mb-0 fs-4 d-flex align-items-center gap-2">
            <span class="p-2 rounded-3 bg-white shadow-sm border"><i class="fa-solid fa-truck-ramp-box text-primary"></i></span> 
            Transfert de Stock Inter-Boutiques
        </h1>
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small fw-semibold d-none d-md-inline">Boutique active :</span>
            <span class="badge bg-white text-dark border px-2.5 py-1.5 shadow-sm fw-bold">
                <i class="fa-solid fa-warehouse text-secondary me-1"></i><?= htmlspecialchars($current_shop['name'] ?? 'Boutique') ?>
            </span>
        </div>
    </div>
</div>

<!-- Alertes Transferts en Attente de Réception -->
<?php if ($pending_incoming_count > 0): ?>
    <div class="alert alert-warning border-0 shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2 no-print" style="border-radius: 14px;">
        <div class="d-flex align-items-center">
            <div class="bg-warning text-dark p-2 rounded-3 me-3"><i class="fa-solid fa-truck-moving fa-lg fa-bounce"></i></div>
            <div>
                <h6 class="fw-bold mb-0 text-dark"><?= $pending_incoming_count ?> bon(s) de transfert en attente de réception physique !</h6>
                <p class="small text-secondary mb-0">Veuillez vérifier les colis reçus et valider la réception dans la liste ci-dessous pour créditer les articles au stock.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Notifications Flash -->
<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Navigation par Onglets Principaux (Fluidité & Ergonomie Mobile) -->
<ul class="nav nav-pills mb-4 no-print gap-2" role="tablist">
    <li class="nav-item">
        <a class="nav-link px-4 py-2.5 fw-bold shadow-sm <?= $active_tab === 'history' ? 'active bg-primary text-white' : 'bg-white text-secondary border' ?>" href="stock-transfers.php?tab=history<?= $filter_shop > 0 ? '&filter_shop='.$filter_shop : '' ?>" style="border-radius: 10px;">
            <i class="fa-solid fa-list-check me-1.5"></i>Journal des Transferts
            <span class="badge bg-white text-dark ms-1.5 fw-bold rounded-pill" style="font-size: 0.75rem;"><?= $total_records ?></span>
            <?php if ($pending_incoming_count > 0): ?>
                <span class="badge bg-warning text-dark ms-1 fw-bold rounded-pill" title="En attente de réception">🟡 <?= $pending_incoming_count ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link px-4 py-2.5 fw-bold shadow-sm <?= $active_tab === 'new' ? 'active bg-primary text-white' : 'bg-white text-secondary border' ?>" href="stock-transfers.php?tab=new" style="border-radius: 10px;">
            <i class="fa-solid fa-truck-moving me-1.5"></i>Nouveau Transfert
        </a>
    </li>
</ul>

<?php if ($active_tab === 'new'): ?>
    <!-- ========================================== -->
    <!-- ONGLET 1 : FORMULAIRE DE CRÉATION FLUIDE   -->
    <!-- ========================================== -->
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8 col-xl-7">
            <div class="glass-card p-4 p-md-5">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                    <div>
                        <h4 class="fw-bold mb-1 text-slate-900"><i class="fa-solid fa-dolly text-primary me-2"></i>Émettre un Nouveau Transfert</h4>
                        <p class="text-secondary small mb-0">Sélectionnez les boutiques et ajoutez un ou plusieurs articles.</p>
                    </div>
                    <a href="stock-transfers.php?tab=history" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                        <i class="fa-solid fa-arrow-left me-1"></i>Historique
                    </a>
                </div>

                <form action="stock-transfers.php?tab=new" method="POST" id="multiTransferForm">
                    <input type="hidden" name="tab" value="new">
                    
                    <div class="row g-3 mb-4">
                        <!-- Boutique Départ -->
                        <div class="col-12 col-md-6">
                            <label for="from_shop_id" class="form-label fw-bold text-secondary small">1. Boutique de Départ (Source) *</label>
                            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                <select class="form-select form-control-custom" id="from_shop_id" name="from_shop_id" required>
                                    <option value="">Sélectionner la source...</option>
                                    <?php foreach ($all_shops as $sh): ?>
                                        <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $_SESSION['active_shop_id'] ? 'selected' : '' ?>>
                                            <?= $sh['is_depot'] ? '🏭' : '🏢' ?> <?= htmlspecialchars($sh['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control form-control-custom" value="<?= htmlspecialchars($current_shop['name'] ?? '') ?>" readonly>
                                <input type="hidden" name="from_shop_id" value="<?= $_SESSION['active_shop_id'] ?>">
                            <?php endif; ?>
                        </div>

                        <!-- Boutique Arrivée -->
                        <div class="col-12 col-md-6">
                            <label for="to_shop_id" class="form-label fw-bold text-secondary small">2. Boutique de Destination (Arrivée) *</label>
                            <select class="form-select form-control-custom" id="to_shop_id" name="to_shop_id" required>
                                <option value="">Sélectionner la destination...</option>
                                <?php foreach ($all_shops as $sh): ?>
                                    <option value="<?= $sh['id'] ?>" <?= ($sh['id'] != $_SESSION['active_shop_id'] && count($all_shops) > 1) ? 'selected' : '' ?>>
                                        <?= $sh['is_depot'] ? '🏭' : '🏢' ?> <?= htmlspecialchars($sh['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Section des Articles Multiples -->
                    <div class="d-flex justify-content-between align-items-center mb-3 pt-2 border-top">
                        <label class="fw-bold text-slate-800 small mb-0"><i class="fa-solid fa-boxes-stacked text-primary me-1.5"></i>Articles à expédier</label>
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill py-1 px-3 fw-bold" onclick="addTransferItemRow()">
                            <i class="fa-solid fa-plus me-1"></i>Ajouter un autre article
                        </button>
                    </div>

                    <div id="transfer_items_container" class="d-flex flex-column gap-2 mb-4">
                        <!-- Lignes dynamiques injectées par Javascript -->
                    </div>

                    <!-- Notes / Commentaire -->
                    <div class="mb-4">
                        <label for="notes" class="form-label fw-bold text-secondary small">Remarques / Motif du transfert</label>
                        <textarea class="form-control form-control-custom" id="notes" name="notes" rows="2" placeholder="Ex: Ravitaillement urgent pour vente, Commande spéciale client..."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" name="perform_transfer" class="btn btn-grad py-2.5 fw-bold fs-6 shadow-sm" onclick="return confirm('Confirmer l\'envoi de ce bon de transfert ? Le stock du magasin source sera immédiatement déduit.');">
                            <i class="fa-solid fa-truck-fast me-2"></i>Valider & Exécuter le Transfert
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ========================================== -->
    <!-- ONGLET 2 : HISTORIQUE RESPONSIVE (100% LARGEUR) -->
    <!-- ========================================== -->
    <div class="glass-card p-3 p-md-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-0 text-slate-800 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-history text-primary"></i>
                    <span>Journal des Transferts</span>
                </h5>
                <small class="text-secondary"><?= $total_records ?> bon(s) répertorié(s) selon vos filtres</small>
            </div>
            <div class="d-flex align-items-center gap-2 no-print">
                <a href="export-transfers-excel.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success btn-sm fw-bold px-3 py-2" style="border-radius: 8px;" title="Exporter tous les transferts filtrés au format Excel (.xlsx)">
                    <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel
                </a>
                <a href="stock-transfers.php?tab=new" class="btn btn-grad btn-sm fw-bold px-3 py-2" style="border-radius: 8px;">
                    <i class="fa-solid fa-plus me-1"></i>Nouveau Transfert
                </a>
            </div>
        </div>

        <!-- Barre de Filtres Historique Transferts -->
        <form method="GET" action="stock-transfers.php" class="row g-2 mb-4 align-items-end no-print p-3 bg-light rounded-3 border">
            <input type="hidden" name="tab" value="history">
            
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Dépôt concerné</label>
                <select name="filter_shop" class="form-select form-select-sm form-control-custom" onchange="this.form.submit()">
                    <?php if ($_SESSION['user_role'] === 'admin'): ?>
                        <option value="0" <?= $filter_shop == 0 ? 'selected' : '' ?>>🌐 Tous les dépôts</option>
                    <?php endif; ?>
                    <?php foreach ($all_shops as $sh): ?>
                        <option value="<?= $sh['id'] ?>" <?= $filter_shop == $sh['id'] ? 'selected' : '' ?>>
                            <?= $sh['is_depot'] ? '🏭' : '🏢' ?> <?= htmlspecialchars($sh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Statut du Transfert</label>
                <select name="filter_status" class="form-select form-select-sm form-control-custom" onchange="this.form.submit()">
                    <option value="" <?= $filter_status === '' ? 'selected' : '' ?>>Tous les statuts</option>
                    <option value="PENDING" <?= $filter_status === 'PENDING' ? 'selected' : '' ?>>🟡 En transit (En attente)</option>
                    <option value="RECEIVED" <?= $filter_status === 'RECEIVED' ? 'selected' : '' ?>>🟢 Reçus (Validés)</option>
                    <option value="CANCELLED" <?= $filter_status === 'CANCELLED' ? 'selected' : '' ?>>🔴 Annulés</option>
                </select>
            </div>

            <div class="col-12 col-sm-8 col-md-4">
                <label class="form-label small fw-bold text-secondary mb-1">Recherche</label>
                <input type="text" name="search_term" class="form-control form-control-sm form-control-custom" placeholder="Code (ex: TR-00045), Article, Opérateur..." value="<?= htmlspecialchars($search_term) ?>">
            </div>

            <div class="col-12 col-sm-4 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold" style="border-radius: 8px;">
                    <i class="fa-solid fa-filter me-1"></i>Filtrer
                </button>
                <?php if ($filter_shop > 0 || !empty($filter_status) || !empty($search_term)): ?>
                    <a href="stock-transfers.php?tab=history" class="btn btn-sm btn-outline-secondary" title="Réinitialiser" style="border-radius: 8px;">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (empty($transfers)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="fa-solid fa-truck-ramp-box fa-3x mb-3 text-muted"></i>
                <p class="mb-0 fw-semibold">Aucun transfert ne correspond aux critères sélectionnés.</p>
            </div>
        <?php else: ?>
            
            <!-- VUE 1 : GRANDS ÉCRANS / ORDINATEURS & TABLETTES (Tableau spacieux 100% fluide) -->
            <div class="d-none d-md-block table-responsive">
                <table class="table table-custom align-middle mb-0" id="transferHistoryTable">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Réf & Date</th>
                            <th style="min-width: 250px;">Trajet (Source ➔ Destination)</th>
                            <th style="min-width: 280px;">Articles Transférés</th>
                            <th class="text-center" style="min-width: 90px;">Total Qté</th>
                            <th style="min-width: 170px;">Statut</th>
                            <th style="min-width: 160px;">Auteur & Notes</th>
                            <th class="text-end no-print" style="min-width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $tr): 
                            $is_incoming = (isset($_SESSION['active_shop_id']) && $tr['to_shop_id'] == $_SESSION['active_shop_id']);
                            $is_pending = ($tr['status'] === 'PENDING');
                            $is_cancelled = ($tr['status'] === 'CANCELLED');
                            $is_admin = ($_SESSION['user_role'] === 'admin');
                            $is_sender = ($tr['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['active_shop_id']) && $tr['from_shop_id'] == $_SESSION['active_shop_id']));

                            $can_validate = ($is_pending && ($is_incoming || $is_admin));
                            $can_cancel = (!$is_cancelled && ($is_admin || ($is_sender && $is_pending)));
                        ?>
                            <tr class="<?= ($is_pending && $is_incoming) ? 'table-warning bg-warning bg-opacity-10' : ($is_cancelled ? 'table-light opacity-75' : '') ?>">
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle fw-bold fs-6 px-2 py-1"><?= htmlspecialchars($tr['group_code']) ?></span>
                                    <div class="small text-muted mt-1 text-nowrap"><i class="fa-regular fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($tr['created_at'])) ?></div>
                                    <?php if ($is_pending && $is_incoming): ?>
                                        <span class="badge bg-warning text-dark mt-1 fw-bold" style="font-size:0.7rem;">À réceptionner</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                            <i class="fa-solid fa-arrow-up-from-bracket me-1"></i><?= htmlspecialchars($tr['from_shop_name']) ?>
                                        </span>
                                        <i class="fa-solid fa-arrow-right text-secondary small"></i>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                            <i class="fa-solid fa-arrow-down-to-bracket me-1"></i><?= htmlspecialchars($tr['to_shop_name']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-800 small"><?= $tr['total_items_count'] ?> article(s) :</div>
                                    <div class="text-secondary small mt-0.5" title="<?= htmlspecialchars($tr['items_summary']) ?>">
                                        <?= htmlspecialchars($tr['items_summary']) ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-primary border border-primary-subtle fw-extrabold fs-6 px-2.5 py-1">
                                        <?= $tr['total_units_sum'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($tr['status'] === 'RECEIVED'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 fw-bold">
                                            <i class="fa-solid fa-check-double me-1"></i>Reçu / Stock OK
                                        </span>
                                        <?php if (!empty($tr['receiver_name'])): ?>
                                            <div class="small text-muted mt-1" style="font-size: 0.73rem;"><i class="fa-solid fa-user-check me-1"></i><?= htmlspecialchars($tr['receiver_name']) ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($tr['status'] === 'CANCELLED'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 fw-bold">
                                            <i class="fa-solid fa-ban me-1"></i>Annulé
                                        </span>
                                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">Stock restitué</div>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-dark border border-warning px-2.5 py-1.5 fw-bold">
                                            <i class="fa-solid fa-truck-fast me-1 fa-fade"></i>En transit
                                        </span>
                                        <div class="small text-danger fw-semibold mt-1" style="font-size: 0.72rem;"><i class="fa-solid fa-hourglass-half me-1"></i>En attente réception</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark"><i class="fa-solid fa-user-pen text-secondary me-1"></i><?= htmlspecialchars($tr['operator_name'] ?? 'Opérateur') ?></div>
                                    <?php if (!empty($tr['notes'])): ?>
                                        <div class="small text-muted fst-italic mt-0.5" style="font-size: 0.75rem;">« <?= htmlspecialchars($tr['notes']) ?> »</div>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end no-print">
                                    <div class="d-flex align-items-center justify-content-end gap-1.5 text-nowrap">
                                        <?php if ($can_validate): ?>
                                            <form method="POST" action="stock-transfers.php" class="d-inline" onsubmit="return confirm('Confirmer la bonne réception physique des articles ? Le stock de votre boutique sera alors augmenté.');">
                                                <input type="hidden" name="transfer_code" value="<?= htmlspecialchars($tr['group_code']) ?>">
                                                <input type="hidden" name="transfer_id" value="<?= $tr['min_id'] ?>">
                                                <input type="hidden" name="tab" value="history">
                                                <button type="submit" name="confirm_transfer_receipt" class="btn btn-sm btn-success fw-bold px-2 py-1" style="border-radius: 6px;" title="Valider la réception physique du stock">
                                                    <i class="fa-solid fa-box-open me-1"></i>Valider
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($can_cancel): ?>
                                            <form method="POST" action="stock-transfers.php" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir ANNULER ce bon de transfert (<?= htmlspecialchars($tr['group_code']) ?>) ?\nLes quantités seront automatiquement restituées au stock d\'origine.');">
                                                <input type="hidden" name="transfer_code" value="<?= htmlspecialchars($tr['group_code']) ?>">
                                                <input type="hidden" name="transfer_id" value="<?= $tr['min_id'] ?>">
                                                <input type="hidden" name="tab" value="history">
                                                <button type="submit" name="cancel_transfer" class="btn btn-sm btn-outline-danger fw-bold px-2 py-1" style="border-radius: 6px;" title="Annuler le transfert et restituer le stock">
                                                    <i class="fa-solid fa-ban me-1"></i>Annuler
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <a href="transfer-receipt.php?code=<?= urlencode($tr['group_code']) ?>&id=<?= $tr['min_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold px-2 py-1" style="border-radius: 6px;" title="Imprimer le bon de transfert multi-articles">
                                            <i class="fa-solid fa-print me-1"></i>Bon
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- VUE 2 : PETITS ÉCRANS & SMARTPHONES (Cartes élégantes & aérées) -->
            <div class="d-md-none">
                <?php foreach ($transfers as $tr): 
                    $is_incoming = (isset($_SESSION['active_shop_id']) && $tr['to_shop_id'] == $_SESSION['active_shop_id']);
                    $is_pending = ($tr['status'] === 'PENDING');
                    $is_cancelled = ($tr['status'] === 'CANCELLED');
                    $is_admin = ($_SESSION['user_role'] === 'admin');
                    $is_sender = ($tr['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['active_shop_id']) && $tr['from_shop_id'] == $_SESSION['active_shop_id']));

                    $can_validate = ($is_pending && ($is_incoming || $is_admin));
                    $can_cancel = (!$is_cancelled && ($is_admin || ($is_sender && $is_pending)));
                ?>
                    <div class="card mb-3 border shadow-sm rounded-3 <?= ($is_pending && $is_incoming) ? 'border-warning bg-warning bg-opacity-10' : ($is_cancelled ? 'bg-light opacity-75' : 'bg-white') ?>">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle fw-bold fs-6"><?= htmlspecialchars($tr['group_code']) ?></span>
                                    <div class="small text-muted mt-0.5"><i class="fa-regular fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($tr['created_at'])) ?></div>
                                </div>
                                <div class="text-end">
                                    <?php if ($tr['status'] === 'RECEIVED'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-1">
                                            <i class="fa-solid fa-check-double me-1"></i>Reçu OK
                                        </span>
                                    <?php elseif ($tr['status'] === 'CANCELLED'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold px-2 py-1">
                                            <i class="fa-solid fa-ban me-1"></i>Annulé
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-dark border border-warning fw-bold px-2 py-1">
                                            <i class="fa-solid fa-truck-fast me-1 fa-fade"></i>En transit
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-2 p-2 bg-light rounded border d-flex align-items-center justify-content-between flex-wrap gap-1">
                                <span class="small fw-bold text-danger"><i class="fa-solid fa-arrow-up-from-bracket me-1"></i><?= htmlspecialchars($tr['from_shop_name']) ?></span>
                                <i class="fa-solid fa-arrow-right text-muted mx-1"></i>
                                <span class="small fw-bold text-success"><i class="fa-solid fa-arrow-down-to-bracket me-1"></i><?= htmlspecialchars($tr['to_shop_name']) ?></span>
                            </div>

                            <div class="mb-2">
                                <div class="small fw-bold text-dark"><i class="fa-solid fa-boxes-stacked text-primary me-1"></i><?= $tr['total_items_count'] ?> article(s) (<?= $tr['total_units_sum'] ?> unités) :</div>
                                <div class="small text-secondary"><?= htmlspecialchars($tr['items_summary']) ?></div>
                            </div>

                            <?php if (!empty($tr['notes'])): ?>
                                <div class="small text-muted fst-italic mb-2">« <?= htmlspecialchars($tr['notes']) ?> »</div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2 flex-wrap gap-2">
                                <small class="text-secondary"><i class="fa-solid fa-user me-1"></i><?= htmlspecialchars($tr['operator_name'] ?? 'Opérateur') ?></small>
                                <div class="d-flex gap-1.5 no-print">
                                    <?php if ($can_validate): ?>
                                        <form method="POST" action="stock-transfers.php" class="d-inline" onsubmit="return confirm('Confirmer la réception physique de ce transfert ?');">
                                            <input type="hidden" name="transfer_code" value="<?= htmlspecialchars($tr['group_code']) ?>">
                                            <input type="hidden" name="transfer_id" value="<?= $tr['min_id'] ?>">
                                            <input type="hidden" name="tab" value="history">
                                            <button type="submit" name="confirm_transfer_receipt" class="btn btn-sm btn-success fw-bold py-1 px-2.5">
                                                <i class="fa-solid fa-box-open me-1"></i>Valider
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($can_cancel): ?>
                                        <form method="POST" action="stock-transfers.php" class="d-inline" onsubmit="return confirm('Confirmer l\'ANNULATION du transfert ? Le stock sera restitué.');">
                                            <input type="hidden" name="transfer_code" value="<?= htmlspecialchars($tr['group_code']) ?>">
                                            <input type="hidden" name="transfer_id" value="<?= $tr['min_id'] ?>">
                                            <input type="hidden" name="tab" value="history">
                                            <button type="submit" name="cancel_transfer" class="btn btn-sm btn-outline-danger fw-bold py-1 px-2">
                                                <i class="fa-solid fa-ban me-1"></i>Annuler
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <a href="transfer-receipt.php?code=<?= urlencode($tr['group_code']) ?>&id=<?= $tr['min_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-1 px-2.5 fw-bold">
                                        <i class="fa-solid fa-print me-1"></i>Bon
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Transferts -->
            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                    <div class="small text-secondary fw-semibold">
                        Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($transfers)) ?> sur <strong><?= $total_records ?></strong> bon(s) de transfert
                    </div>
                    <nav aria-label="Pagination transferts">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $q_tr = $_GET;
                            $q_tr['tab'] = 'history';
                            if ($current_page > 1):
                                $q_tr['page'] = $current_page - 1;
                                echo '<li class="page-item"><a class="page-link" href="stock-transfers.php?' . http_build_query($q_tr) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                            else:
                                echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                            endif;

                            $st_t = max(1, $current_page - 2);
                            $en_t = min($total_pages, $current_page + 2);

                            for ($p = $st_t; $p <= $en_t; $p++):
                                $q_tr['page'] = $p;
                                $act_t = ($p == $current_page) ? 'active' : '';
                                echo '<li class="page-item ' . $act_t . '"><a class="page-link" href="stock-transfers.php?' . http_build_query($q_tr) . '">' . $p . '</a></li>';
                            endfor;

                            if ($current_page < $total_pages):
                                $q_tr['page'] = $current_page + 1;
                                echo '<li class="page-item"><a class="page-link" href="stock-transfers.php?' . http_build_query($q_tr) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
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
<?php endif; ?>

<script>
const transferCatalog = <?= json_encode(array_map(function($p) use ($all_product_stocks) {
    return [
        'id' => intval($p['id']),
        'name' => $p['name'],
        'sku' => $p['sku'] ?? '',
        'unit' => $p['unit'] ?? 'UNITE',
        'stocks' => $all_product_stocks[$p['id']] ?? []
    ];
}, $all_products)) ?>;

function normalizeSearch(str) {
    return (str || '').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim();
}

function getSelectedSourceShopId() {
    const sel = document.getElementById('from_shop_id');
    return sel ? parseInt(sel.value || 0) : <?= $active_shop_id > 0 ? $active_shop_id : 1 ?>;
}

function addTransferItemRow() {
    const container = document.getElementById('transfer_items_container');
    const rowId = 'tr_item_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    
    const row = document.createElement('div');
    row.className = 'p-2.5 bg-light rounded border transfer-item-row position-relative mb-2';
    row.id = rowId;

    row.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-1">
            <small class="fw-bold text-slate-700">Article #${container.children.length + 1}</small>
            <button type="button" class="btn btn-link text-danger p-0 ms-2 remove-tr-item-btn" onclick="removeTransferItemRow('${rowId}')" title="Supprimer cet article">
                <i class="fa-solid fa-times-circle"></i>
            </button>
        </div>
        <div class="row g-2">
            <div class="col-8 position-relative">
                <div class="input-group input-group-sm tr-search-box">
                    <span class="input-group-text bg-white border-end-0 text-secondary"><i class="fa-solid fa-magnifying-glass text-primary"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 tr-search-input" placeholder="Rechercher article... (ex: Couché, Ramette)" autocomplete="off" oninput="handleTransferProductSearch(this)">
                </div>
                <div class="list-group position-absolute w-100 shadow-lg border mt-1 d-none tr-search-results" style="z-index: 1050; max-height: 200px; overflow-y: auto; border-radius: 8px;"></div>
                
                <div class="p-1.5 bg-white rounded border d-none tr-selected-display">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-800 small tr-selected-name">-</span>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="resetTransferProduct(this)"><i class="fa-solid fa-times"></i></button>
                    </div>
                </div>

                <input type="hidden" name="product_ids[]" class="tr-hidden-product-id" required>
            </div>
            <div class="col-4">
                <input type="number" class="form-control form-control-sm text-center fw-bold" name="quantities[]" min="1" value="1" placeholder="Qté" required>
            </div>
        </div>
    `;

    container.appendChild(row);
    updateRemoveTransferItemButtons();
}

function handleTransferProductSearch(inputElem) {
    const row = inputElem.closest('.transfer-item-row');
    if (!row) return;
    const resultsContainer = row.querySelector('.tr-search-results');
    const rawFilter = inputElem.value.trim();

    if (rawFilter.length < 1) {
        resultsContainer.classList.add('d-none');
        resultsContainer.innerHTML = '';
        return;
    }

    const currentShopId = getSelectedSourceShopId();
    const searchTokens = normalizeSearch(rawFilter).split(/\s+/).filter(t => t.length > 0);

    const matches = transferCatalog.filter(p => {
        const targetText = normalizeSearch(p.name + ' ' + (p.sku || '') + ' ' + p.id + ' ' + (p.unit || ''));
        return searchTokens.every(token => targetText.includes(token));
    });

    if (matches.length === 0) {
        resultsContainer.innerHTML = '<div class="list-group-item text-muted text-center py-2 small">Aucun article trouvé</div>';
        resultsContainer.classList.remove('d-none');
        return;
    }

    let html = '';
    matches.slice(0, 10).forEach(p => {
        const stock = parseInt(p.stocks[currentShopId] || 0);
        let badgeClass = stock > 0 ? 'bg-success-subtle text-success' : 'bg-danger text-white';
        html += `
            <button type="button" class="list-group-item list-group-item-action py-1.5 px-2.5 d-flex justify-content-between align-items-center" onclick="selectTransferProduct(this, ${p.id})">
                <div>
                    <span class="fw-bold text-slate-800 small">${escapeHtml(p.name)}</span>
                    <small class="text-secondary d-block" style="font-size: 0.75rem;">Unité : ${escapeHtml(p.unit)}</small>
                </div>
                <span class="badge ${badgeClass} border small">${stock} en stock source</span>
            </button>
        `;
    });

    resultsContainer.innerHTML = html;
    resultsContainer.classList.remove('d-none');
}

function selectTransferProduct(btnElem, productId) {
    const row = btnElem.closest('.transfer-item-row');
    if (!row) return;
    const product = transferCatalog.find(p => p.id === productId);
    if (!product) return;

    row.querySelector('.tr-hidden-product-id').value = product.id;
    row.querySelector('.tr-selected-name').textContent = product.name + ' (' + product.unit + ')';
    row.querySelector('.tr-selected-display').classList.remove('d-none');
    row.querySelector('.tr-search-box').classList.add('d-none');
    
    const results = row.querySelector('.tr-search-results');
    results.classList.add('d-none');
    results.innerHTML = '';
}

function resetTransferProduct(btnElem) {
    const row = btnElem.closest('.transfer-item-row');
    if (!row) return;
    row.querySelector('.tr-hidden-product-id').value = '';
    row.querySelector('.tr-selected-display').classList.add('d-none');
    
    const searchBox = row.querySelector('.tr-search-box');
    searchBox.classList.remove('d-none');
    const input = searchBox.querySelector('.tr-search-input');
    input.value = '';
    input.focus();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function removeTransferItemRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) {
        row.remove();
        updateRemoveTransferItemButtons();
    }
}

function updateRemoveTransferItemButtons() {
    const container = document.getElementById('transfer_items_container');
    const btns = container.querySelectorAll('.remove-tr-item-btn');
    if (btns.length <= 1) {
        btns.forEach(btn => btn.style.display = 'none');
    } else {
        btns.forEach(btn => btn.style.display = 'inline-block');
    }
}

function filterTransferHistoryTable() {
    const filter = document.getElementById('search_transfer_table').value.toLowerCase().trim();
    const table = document.getElementById('transferHistoryTable');
    if (!table) return;
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const text = rows[i].textContent || rows[i].innerText;
        if (text.toLowerCase().includes(filter)) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
}

// Initialiser avec 1 ligne d'article par défaut
document.addEventListener('DOMContentLoaded', function() {
    addTransferItemRow();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
