<?php
require_once __DIR__ . '/includes/header.php';

$active_shop_id = $_SESSION['active_shop_id'];
$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

$success_msg = '';
$error_msg = '';

// Auto-migration pour unifier les références des anciens versements de caisse
try {
    $pdo->exec("UPDATE cash_transactions SET reference_id = id WHERE reference_type = 'TRANSFER' AND shop_id IS NOT NULL AND reference_id IS NULL");
} catch (Exception $e) {}

// --- ACTIONS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Enregistrement d'un Petit Achat / Dépense (Vendeur & Admin)
    if (isset($_POST['add_small_expense'])) {
        $amount = floatval($_POST['amount']);
        $category = trim($_POST['category']);
        $notes = trim($_POST['notes']);
        $expense_date = date('Y-m-d');
        
        // Dépôt : Forcé sur le dépôt actif pour les vendeurs, ou sélectionné pour l'admin
        $shop_id = ($user_role === 'admin') ? intval($_POST['shop_id']) : $active_shop_id;

        if ($amount <= 0) {
            $error_msg = "Le montant de la dépense doit être supérieur à 0.";
        } elseif (empty($category)) {
            $error_msg = "Veuillez sélectionner une catégorie de charge.";
        } elseif ($shop_id <= 0) {
            $error_msg = "Boutique non spécifiée.";
        } else {
            try {
                $pdo->beginTransaction();

                // Définir le statut : PENDING_ACK pour le vendeur, ACKNOWLEDGED pour l'admin (automatiquement validé)
                $status = ($user_role === 'admin') ? 'ACKNOWLEDGED' : 'PENDING_ACK';

                // Insérer dans la table expenses
                $stmt_exp = $pdo->prepare("
                    INSERT INTO expenses (shop_id, user_id, amount, category, expense_date, notes, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_exp->execute([
                    $shop_id,
                    $user_id,
                    $amount,
                    $category,
                    $expense_date,
                    $notes,
                    $status
                ]);
                $expense_id = $pdo->lastInsertId();

                // Insérer la transaction OUT dans cash_transactions
                $stmt_cash = $pdo->prepare("
                    INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type)
                    VALUES (?, ?, 'OUT', ?, ?, ?, 'EXPENSE')
                ");
                $stmt_cash->execute([
                    $shop_id,
                    $user_id,
                    $amount,
                    "Petit Achat [" . $category . "] : " . $notes,
                    $expense_id,
                ]);

                $pdo->commit();
                $success_msg = "Dépense enregistrée" . ($user_role === 'seller' ? " et notifiée à l'administrateur" : "") . " avec succès.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Erreur de traitement : " . $e->getMessage();
            }
        }
    }

    // 2. Déclaration d'un Versement de caisse au Boss (Vendeur & Admin)
    if (isset($_POST['transfer_to_boss'])) {
        $amount = floatval($_POST['amount']);
        $notes = trim($_POST['notes']);
        $shop_id = ($user_role === 'admin') ? intval($_POST['shop_id']) : $active_shop_id;

        if ($amount <= 0) {
            $error_msg = "Le montant du transfert doit être supérieur à 0.";
        } elseif ($shop_id <= 0) {
            $error_msg = "Boutique source non valide.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Vérifier si le solde de la caisse de la boutique est suffisant
                $stmt_bal = $pdo->prepare("
                    SELECT SUM(CASE WHEN type='IN' THEN amount WHEN type='OUT' THEN amount*-1 ELSE 0 END) as balance 
                    FROM cash_transactions 
                    WHERE shop_id = ?
                ");
                $stmt_bal->execute([$shop_id]);
                $current_bal = floatval($stmt_bal->fetchColumn() ?: 0);

                if ($current_bal < $amount) {
                    throw new Exception("Solde de caisse insuffisant dans la boutique (Disponible : " . number_format($current_bal, 0, '.', ' ') . " FCFA).");
                }

                // 2. Transaction de sortie (OUT) de la caisse boutique (immédiatement appliquée)
                $stmt_out = $pdo->prepare("
                    INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_type, status)
                    VALUES (?, ?, 'OUT', ?, ?, 'TRANSFER', 'VALIDATED')
                ");
                $stmt_out->execute([
                    $shop_id,
                    $user_id,
                    $amount,
                    "Versement de caisse vers le Boss" . (!empty($notes) ? " (" . $notes . ")" : "")
                ]);
                $transfer_id = $pdo->lastInsertId();

                // Unifier reference_id sur la transaction OUT pour avoir le même numéro unique VERS-XXXXX
                $pdo->prepare("UPDATE cash_transactions SET reference_id = ? WHERE id = ?")->execute([$transfer_id, $transfer_id]);

                // 3. Transaction d'entrée (IN) dans la caisse du Boss (shop_id = NULL) - En attente (PENDING)
                $stmt_in = $pdo->prepare("
                    INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, status)
                    VALUES (NULL, ?, 'IN', ?, ?, ?, 'TRANSFER', 'PENDING')
                ");
                $stmt_in->execute([
                    $user_id,
                    $amount,
                    "Versement de caisse reçu de la boutique " . ($_SESSION['active_shop_name'] ?? 'Boutique'),
                    $transfer_id
                ]);

                $pdo->commit();
                $success_msg = "Le versement de caisse N° VERS-" . sprintf("%05d", $transfer_id) . " de " . number_format($amount, 0, '.', ' ') . " FCFA a été enregistré avec succès.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Erreur de transfert : " . $e->getMessage();
            }
        }
    }

    // 3. Validation / Acknowledgment d'une dépense de vendeur (Admin uniquement)
    if (isset($_POST['ack_expense']) && $user_role === 'admin') {
        $expense_id = intval($_POST['ack_expense_id']);
        if ($expense_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE expenses SET status = 'ACKNOWLEDGED' WHERE id = ?");
                $stmt->execute([$expense_id]);
                $success_msg = "La dépense de boutique a été prise en compte et validée.";
            } catch (Exception $e) {
                $error_msg = "Erreur de validation : " . $e->getMessage();
            }
        }
    }

    // 4. Validation d'un versement par le Boss (Admin uniquement)
    if (isset($_POST['validate_transfer']) && $user_role === 'admin') {
        $transaction_id = intval($_POST['validate_transaction_id']);
        if ($transaction_id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Valider la transaction d'entrée pour la caisse du Boss
                $stmt = $pdo->prepare("UPDATE cash_transactions SET status = 'VALIDATED' WHERE id = ? AND status = 'PENDING'");
                $stmt->execute([$transaction_id]);
                
                $pdo->commit();
                $success_msg = "Le versement de caisse a été validé et encaissé avec succès.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Erreur de validation : " . $e->getMessage();
            }
        }
    }

    // 5. Arrêté / Clôture physique de caisse (Ajustement et calage sur les billets réels)
    if (isset($_POST['adjust_drawer'])) {
        $shop_id = ($user_role === 'admin') ? intval($_POST['shop_id']) : $active_shop_id;
        $counted_amount = floatval($_POST['counted_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($counted_amount < 0) {
            $error_msg = "Le montant compté ne peut pas être négatif.";
        } elseif ($shop_id <= 0) {
            $error_msg = "Boutique non valide.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Calculer le solde théorique actuel de la boutique (hors achats usine)
                $stmt_b = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN type='IN' THEN amount WHEN type='OUT' THEN amount*-1 ELSE 0 END), 0)
                    FROM cash_transactions 
                    WHERE shop_id = ? AND status = 'VALIDATED'
                ");
                $stmt_b->execute([$shop_id]);
                $current_drawer = floatval($stmt_b->fetchColumn() ?: 0);
                
                $difference = $counted_amount - $current_drawer;
                
                if (abs($difference) < 0.01) {
                    $success_msg = "Le montant compté correspond exactement au solde théorique (" . number_format($counted_amount, 0, '.', ' ') . " FCFA). Aucun écart à régulariser.";
                } elseif ($difference < 0) {
                    // Écart négatif : le tiroir physique a moins que le théorique (l'argent a été remis au Boss ou dépensé sans saisie)
                    $adjustment_amount = abs($difference);
                    
                    // 1. Sortie de caisse de la boutique
                    $stmt_out = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_type, status)
                        VALUES (?, ?, 'OUT', ?, ?, 'TRANSFER', 'VALIDATED')
                    ");
                    $desc = "Arrêté de caisse physique : Versement régularisé au Boss" . ($notes ? " (" . $notes . ")" : "");
                    $stmt_out->execute([$shop_id, $user_id, $adjustment_amount, $desc]);
                    $trans_id = $pdo->lastInsertId();
                    $pdo->prepare("UPDATE cash_transactions SET reference_id = ? WHERE id = ?")->execute([$trans_id, $trans_id]);
                    
                    // 2. Encaissement dans le Coffre du Boss
                    $stmt_boss = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, status)
                        VALUES (NULL, ?, 'IN', ?, ?, ?, 'TRANSFER', 'VALIDATED')
                    ");
                    $stmt_boss->execute([$user_id, $adjustment_amount, "Régularisation arrêté de caisse reçu de la boutique", $trans_id]);
                    
                    $pdo->commit();
                    $success_msg = "Arrêté de caisse enregistré avec succès. Écart de " . number_format($adjustment_amount, 0, '.', ' ') . " FCFA régularisé vers le coffre du Boss. Le tiroir est maintenant calé sur " . number_format($counted_amount, 0, '.', ' ') . " FCFA.";
                } else {
                    // Écart positif : apport / fond de caisse non saisi
                    $stmt_in = $pdo->prepare("
                        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_type, status)
                        VALUES (?, ?, 'IN', ?, ?, 'ADJUSTMENT', 'VALIDATED')
                    ");
                    $desc = "Arrêté de caisse : Fond de caisse / Apport régularisé" . ($notes ? " (" . $notes . ")" : "");
                    $stmt_in->execute([$shop_id, $user_id, $difference, $desc]);
                    
                    $pdo->commit();
                    $success_msg = "Arrêté de caisse enregistré avec succès (+ " . number_format($difference, 0, '.', ' ') . " FCFA). Le tiroir est maintenant calé sur " . number_format($counted_amount, 0, '.', ' ') . " FCFA.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = "Erreur lors de l'arrêté de caisse : " . $e->getMessage();
            }
        }
    }
}

// --- CHARGEMENT DES DONNÉES ---

// Liste des boutiques (avec distinction dépôt / atelier)
$shops = $pdo->query("SELECT id, name, is_depot FROM shops ORDER BY is_depot ASC, name ASC")->fetchAll();

// Liste des catégories de dépenses dynamiques
$expense_categories = $pdo->query("SELECT id, name, description FROM expense_categories ORDER BY name ASC")->fetchAll();

// Métriques financières et de caisse pour chaque boutique
$shop_metrics = [];
foreach ($shops as $sh) {
    $s_id = intval($sh['id']);
    
    // CA & Crédits de la boutique
    $stmt_ca = $pdo->prepare("
        SELECT 
            COUNT(*) as sales_count,
            COALESCE(SUM(total_amount), 0) as ca_total,
            COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN total_amount ELSE 0 END), 0) as sales_cash,
            COALESCE(SUM(CASE WHEN payment_status IN ('PARTIAL', 'UNPAID') THEN total_amount ELSE 0 END), 0) as credit_total,
            COALESCE(SUM(CASE WHEN payment_status IN ('PARTIAL', 'UNPAID') THEN (total_amount - amount_paid) ELSE 0 END), 0) as credit_remaining,
            COALESCE(SUM(CASE WHEN payment_status IN ('PARTIAL', 'UNPAID') THEN amount_paid ELSE 0 END), 0) as credit_repaid
        FROM orders 
        WHERE type = 'SALE' AND shop_id = ? AND status != 'CANCELLED'
    ");
    $stmt_ca->execute([$s_id]);
    $ca = $stmt_ca->fetch(PDO::FETCH_ASSOC);

    // Mouvements réels de caisse (liquidités)
    $stmt_c = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'IN' THEN amount ELSE 0 END), 0) as total_in,
            COALESCE(SUM(CASE WHEN type = 'OUT' AND reference_type = 'EXPENSE' THEN amount ELSE 0 END), 0) as exp_out,
            COALESCE(SUM(CASE WHEN type = 'OUT' AND reference_type = 'TRANSFER' THEN amount ELSE 0 END), 0) as trans_out,
            COALESCE(SUM(CASE WHEN type = 'OUT' AND reference_type NOT IN ('EXPENSE', 'TRANSFER') THEN amount ELSE 0 END), 0) as other_out
        FROM cash_transactions 
        WHERE shop_id = ? AND status = 'VALIDATED'
    ");
    $stmt_c->execute([$s_id]);
    $cash = $stmt_c->fetch(PDO::FETCH_ASSOC);

    $drawer_balance = $cash['total_in'] - $cash['exp_out'] - $cash['trans_out'] - $cash['other_out'];

    $shop_metrics[$s_id] = [
        'name' => $sh['name'],
        'is_depot' => intval($sh['is_depot']),
        'ca_total' => floatval($ca['ca_total']),
        'sales_cash' => floatval($ca['sales_cash']),
        'credit_total' => floatval($ca['credit_total']),
        'credit_repaid' => floatval($ca['credit_repaid']),
        'credit_remaining' => floatval($ca['credit_remaining']),
        'total_in' => floatval($cash['total_in']),
        'exp_out' => floatval($cash['exp_out']),
        'trans_out' => floatval($cash['trans_out']),
        'other_out' => floatval($cash['other_out']),
        'drawer_balance' => floatval($drawer_balance)
    ];
}

// Caisse centrale / Coffre du Boss (Versements nets reçus des boutiques)
$boss_in = floatval($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE shop_id IS NULL AND type = 'IN' AND status = 'VALIDATED'")->fetchColumn() ?: 0);
$boss_out = floatval($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE shop_id IS NULL AND type = 'OUT' AND reference_type != 'PURCHASE' AND status = 'VALIDATED'")->fetchColumn() ?: 0);
$boss_purchases = floatval($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE shop_id IS NULL AND type = 'OUT' AND reference_type = 'PURCHASE' AND status = 'VALIDATED'")->fetchColumn() ?: 0);
$boss_balance = $boss_in - $boss_out;

// Dépenses et Versements en attente (Admin uniquement)
$pending_expenses = [];
$pending_versements = [];
if ($user_role === 'admin') {
    $pending_expenses = $pdo->query("
        SELECT e.*, u.name as user_name, sh.name as shop_name 
        FROM expenses e
        JOIN users u ON e.user_id = u.id
        JOIN shops sh ON e.shop_id = sh.id
        WHERE e.status = 'PENDING_ACK'
        ORDER BY e.created_at DESC
    ")->fetchAll();

    $pending_versements = $pdo->query("
        SELECT ct.*, u.name as user_name,
               (SELECT name FROM shops WHERE id = (
                   SELECT shop_id FROM cash_transactions WHERE id = ct.reference_id
               )) as shop_name
        FROM cash_transactions ct
        JOIN users u ON ct.user_id = u.id
        WHERE ct.shop_id IS NULL AND ct.type = 'IN' AND ct.reference_type = 'TRANSFER' AND ct.status = 'PENDING'
        ORDER BY ct.created_at DESC
    ")->fetchAll();
}

// Pagination Caisse
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 40;

// Filtre par boutique pour l'admin (GET param filter_shop : '' = toutes, 'boss' = Caisse Boss, ou un ID entier)
$filter_shop_raw = isset($_GET['filter_shop']) ? $_GET['filter_shop'] : '';
$filter_type_raw = isset($_GET['filter_type']) ? strtoupper(trim($_GET['filter_type'])) : '';
$filter_date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to   = isset($_GET['date_to'])   && !empty($_GET['date_to'])   ? $_GET['date_to']   : '';

if ($user_role === 'admin') {
    // Construction des conditions de filtre
    $where_clauses = [];
    $bind_params   = [];

    if ($filter_shop_raw === 'boss') {
        $where_clauses[] = "ct.shop_id IS NULL AND (ct.reference_type != 'PURCHASE' OR ct.reference_type IS NULL)";
    } elseif ($filter_shop_raw === 'purchases') {
        $where_clauses[] = "ct.reference_type = 'PURCHASE'";
    } elseif ($filter_shop_raw !== '' && intval($filter_shop_raw) > 0) {
        $where_clauses[] = 'ct.shop_id = :filter_shop';
        $bind_params[':filter_shop'] = intval($filter_shop_raw);
    }

    if (in_array($filter_type_raw, ['IN', 'OUT', 'TRANSFER'])) {
        if ($filter_type_raw === 'TRANSFER') {
            $where_clauses[] = "ct.reference_type = 'TRANSFER'";
        } else {
            $where_clauses[] = "ct.type = :filter_type";
            $bind_params[':filter_type'] = $filter_type_raw;
        }
    }

    if ($filter_date_from !== '') {
        $where_clauses[] = "DATE(ct.created_at) >= :date_from";
        $bind_params[':date_from'] = $filter_date_from;
    }
    if ($filter_date_to !== '') {
        $where_clauses[] = "DATE(ct.created_at) <= :date_to";
        $bind_params[':date_to'] = $filter_date_to;
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_transactions ct $where_sql");
    foreach ($bind_params as $k => $v) $count_stmt->bindValue($k, $v);
    $count_stmt->execute();
    $total_records = intval($count_stmt->fetchColumn() ?: 0);

    $total_pages = max(1, ceil($total_records / $per_page));
    $current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
    $offset = ($current_page - 1) * $per_page;

    $history_stmt = $pdo->prepare("
        SELECT ct.*, u.name as user_name, sh.name as shop_name,
               o.id as order_id, o.reference as order_ref, o.payment_method as order_pay_method,
               COALESCE(c.name, sup.name) as contact_name,
               c.phone as contact_phone
        FROM cash_transactions ct
        JOIN users u ON ct.user_id = u.id
        LEFT JOIN shops sh ON ct.shop_id = sh.id
        LEFT JOIN orders o ON ct.reference_id = o.id AND ct.reference_type IN ('ORDER', 'PURCHASE')
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN suppliers sup ON o.supplier_id = sup.id
        $where_sql
        ORDER BY ct.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($bind_params as $k => $v) $history_stmt->bindValue($k, $v);
    $history_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $history_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $history_stmt->execute();
} else {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_transactions WHERE shop_id = ?");
    $cnt_stmt->execute([$active_shop_id]);
    $total_records = intval($cnt_stmt->fetchColumn() ?: 0);

    $total_pages = max(1, ceil($total_records / $per_page));
    $current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
    $offset = ($current_page - 1) * $per_page;

    $history_stmt = $pdo->prepare("
        SELECT ct.*, u.name as user_name, sh.name as shop_name,
               o.id as order_id, o.reference as order_ref, o.payment_method as order_pay_method,
               COALESCE(c.name, sup.name) as contact_name,
               c.phone as contact_phone
        FROM cash_transactions ct
        JOIN users u ON ct.user_id = u.id
        LEFT JOIN shops sh ON ct.shop_id = sh.id
        LEFT JOIN orders o ON ct.reference_id = o.id AND ct.reference_type IN ('ORDER', 'PURCHASE')
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN suppliers sup ON o.supplier_id = sup.id
        WHERE ct.shop_id = :shop_id
        ORDER BY ct.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $history_stmt->bindValue(':shop_id', $active_shop_id, PDO::PARAM_INT);
    $history_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $history_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $history_stmt->execute();
}
$transactions = $history_stmt->fetchAll();
?>

<!-- Titre -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
         <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
             <span class="text-success"><i class="fa-solid fa-cash-register"></i></span> 
             Gestion des Caisses & Coffres
         </h1>
         <div class="d-flex align-items-center gap-2 no-print">
             <span class="text-secondary small fw-semibold d-none d-md-inline">Suivi des liquidités et dépenses</span>
             <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
                 <i class="fa-solid fa-print me-1"></i>Imprimer
             </button>
         </div>
    </div>
</div>

<!-- Alertes -->
<?php if ($success_msg): ?>
    <div class="alert alert-success border-0 shadow-sm" style="border-radius: 12px;">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px;">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error_msg ?>
    </div>
<?php endif; ?>

<!-- Notifications Dépenses en attente (Admin uniquement) -->
<?php if ($user_role === 'admin' && !empty($pending_expenses)): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4 p-4 no-print" style="border-radius: 16px;">
        <h5 class="fw-bold text-warning-emphasis mb-3"><i class="fa-solid fa-bell fa-bounce me-2"></i>Dépenses des boutiques à valider/notifier</h5>
        <div class="row g-3">
            <?php foreach ($pending_expenses as $pe): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card border-warning bg-white h-100 p-3" style="border-radius: 12px;">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-warning text-dark"><?= htmlspecialchars($pe['category']) ?></span>
                            <span class="fw-bold text-slate-900"><?= number_format($pe['amount'], 0, '.', ' ') ?> FCFA</span>
                        </div>
                        <p class="small text-secondary mb-2"><strong>Dépôt :</strong> <?= htmlspecialchars($pe['shop_name']) ?><br>
                        <strong>Vendeur :</strong> <?= htmlspecialchars($pe['user_name']) ?><br>
                        <strong>Motif :</strong> <?= htmlspecialchars($pe['notes'] ?: 'Aucune note') ?></p>
                        <form action="caisse.php" method="POST" class="mt-auto d-grid">
                            <input type="hidden" name="ack_expense_id" value="<?= $pe['id'] ?>">
                            <button type="submit" name="ack_expense" class="btn btn-warning btn-sm fw-bold px-3" style="border-radius: 8px;">
                                <i class="fa-solid fa-check me-1"></i>Prendre note (Valider)
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Notifications Versements en attente (Admin uniquement) -->
<?php if ($user_role === 'admin' && !empty($pending_versements)): ?>
    <div class="alert alert-info border-0 shadow-sm mb-4 p-4 no-print" style="border-radius: 16px;">
        <h5 class="fw-bold text-info-emphasis mb-3"><i class="fa-solid fa-vault fa-bounce me-2"></i>Versements de boutique à confirmer (Encaisser)</h5>
        <div class="row g-3">
            <?php foreach ($pending_versements as $pv): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card border-info bg-white h-100 p-3" style="border-radius: 12px;">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-info text-white fw-bold"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>VERS-<?= sprintf("%05d", $pv['reference_id'] ?: $pv['id']) ?></span>
                            <span class="fw-bold text-slate-900"><?= number_format($pv['amount'], 0, '.', ' ') ?> FCFA</span>
                        </div>
                        <p class="small text-secondary mb-2"><strong>Dépôt d'origine :</strong> <?= htmlspecialchars($pv['shop_name'] ?? 'Boutique') ?><br>
                        <strong>Déclaré par :</strong> <?= htmlspecialchars($pv['user_name']) ?><br>
                        <strong>Date :</strong> <?= date('d/m/Y H:i', strtotime($pv['created_at'])) ?><br>
                        <strong>Description :</strong> <?= htmlspecialchars($pv['description'] ?: '-') ?></p>
                        <form action="caisse.php" method="POST" class="mt-auto d-grid" onsubmit="return confirm('Confirmer la réception physique de cette somme et l\'encaisser dans votre coffre ?');">
                            <input type="hidden" name="validate_transaction_id" value="<?= $pv['id'] ?>">
                            <button type="submit" name="validate_transfer" class="btn btn-info text-white btn-sm fw-bold px-3" style="border-radius: 8px;">
                                <i class="fa-solid fa-check me-1"></i>Confirmer l'encaissement
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$active_m = $shop_metrics[$active_shop_id] ?? reset($shop_metrics);
?>

<!-- Résumé Financier & Caisses (Les 4 Piliers de Clarté) -->
<div class="row g-3 mb-4">
    <!-- Pilier 1 : Chiffre d'Affaires Global (Ventes facturées) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-card p-3 h-100" style="border-left: 5px solid #3b82f6 !important;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label text-primary fw-bold">1. Chiffre d'Affaires</span>
                <div class="bg-primary bg-opacity-10 text-primary p-1.5 rounded-3"><i class="fa-solid fa-chart-line"></i></div>
            </div>
            <div class="stat-value text-slate-900 fs-4 fw-bold">
                <?= number_format($active_m['ca_total'], 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span>
            </div>
            <div class="small text-secondary mt-1">
                <span class="text-success fw-semibold">Cash: <?= number_format($active_m['sales_cash'], 0, '.', ' ') ?></span> | 
                <span class="text-warning-emphasis fw-semibold">Crédit: <?= number_format($active_m['credit_total'], 0, '.', ' ') ?></span>
            </div>
        </div>
    </div>

    <!-- Pilier 2 : Créances Clients (Dettes dehors non recouvrées) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-card p-3 h-100" style="border-left: 5px solid #f59e0b !important;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label text-warning-emphasis fw-bold">2. Créances Clients</span>
                <div class="bg-warning bg-opacity-10 text-warning-emphasis p-1.5 rounded-3"><i class="fa-solid fa-hand-holding-dollar"></i></div>
            </div>
            <div class="stat-value text-slate-900 fs-4 fw-bold">
                <?= number_format($active_m['credit_remaining'], 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span>
            </div>
            <div class="small text-secondary mt-1 d-flex justify-content-between align-items-center">
                <span>Remboursé: <?= number_format($active_m['credit_repaid'], 0, '.', ' ') ?> F</span>
                <a href="credits.php" class="text-primary text-decoration-none fw-bold small">Détail <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <!-- Pilier 3 : Tiroir-Caisse Espèces (Liquidités physiques de la boutique) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="glass-card p-3 h-100" style="border-left: 5px solid #10b981 !important;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="stat-label text-success fw-bold">3. Caisse : <?= htmlspecialchars($active_m['name']) ?></span>
                <div class="bg-success bg-opacity-10 text-success p-1.5 rounded-3"><i class="fa-solid fa-cash-register"></i></div>
            </div>
            <div class="stat-value text-success fs-4 fw-bold">
                <?= number_format($active_m['drawer_balance'], 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span>
            </div>
            <div class="small text-secondary mt-1">
                <span><i class="fa-solid fa-calculator text-success me-1"></i>Tiroir physique réel (Flux nets d'espèces)</span>
            </div>
        </div>
    </div>

    <!-- Pilier 4 : Coffre du Boss (Admin uniquement) OU Sorties Dépenses (Vendeur) -->
    <?php if ($user_role === 'admin'): ?>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="glass-card p-3 h-100" style="border-left: 5px solid #6366f1 !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="stat-label fw-bold" style="color: #4f46e5;">4. Caisse du Boss</span>
                    <div class="bg-primary bg-opacity-10 text-primary p-1.5 rounded-3"><i class="fa-solid fa-vault"></i></div>
                </div>
                <div class="stat-value text-slate-900 fs-4 fw-bold">
                    <?= number_format($boss_balance, 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span>
                </div>
                <div class="small text-secondary mt-1" title="Achats stocks fournisseurs : <?= number_format($boss_purchases, 0, '.', ' ') ?> FCFA">
                    Coffre fort (versements reçus)
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="glass-card p-3 h-100" style="border-left: 5px solid #ef4444 !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="stat-label text-danger fw-bold">Sorties / Versements</span>
                    <div class="bg-danger bg-opacity-10 text-danger p-1.5 rounded-3"><i class="fa-solid fa-circle-arrow-up"></i></div>
                </div>
                <div class="stat-value text-danger fs-4 fw-bold">
                    -<?= number_format($active_m['exp_out'] + $active_m['trans_out'] + $active_m['other_out'], 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span>
                </div>
                <div class="small text-secondary mt-1">Dépenses & versements Boss</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Autres Dépôts & Atelier de Production (Admin uniquement) -->
<?php if ($user_role === 'admin'): ?>
    <?php 
    $other_shops = array_filter($shops, function($s) use ($active_shop_id) { return $s['id'] != $active_shop_id; });
    if (!empty($other_shops)):
    ?>
    <div class="row g-3 mb-4">
        <?php foreach ($other_shops as $sh): 
            $m = $shop_metrics[$sh['id']];
        ?>
            <?php if ($sh['is_depot'] == 0): ?>
                <!-- Boutique commerciale secondaire -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="glass-card p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="stat-label text-secondary fw-semibold">Caisse : <?= htmlspecialchars($sh['name']) ?></span>
                            <span class="badge bg-light text-dark border">Boutique</span>
                        </div>
                        <div class="stat-value text-slate-900 fs-5 fw-bold">
                            <?= number_format($m['drawer_balance'], 0, '.', ' ') ?> <span class="fs-6 fw-normal text-muted">FCFA</span>
                        </div>
                        <div class="small text-secondary mt-1 d-flex justify-content-between">
                            <span>CA: <?= number_format($m['ca_total'], 0, '.', ' ') ?> F</span>
                            <span>Crédits: <?= number_format($m['credit_remaining'], 0, '.', ' ') ?> F</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Usine / Atelier de production (sans fausse caisse négative) -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="glass-card p-3 h-100 bg-light-subtle">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="stat-label text-secondary fw-semibold"><i class="fa-solid fa-industry me-1 text-primary"></i> <?= htmlspecialchars($sh['name']) ?></span>
                            <span class="badge bg-secondary-subtle text-secondary border">Atelier / Usine</span>
                        </div>
                        <div class="stat-value text-muted fs-6 fw-bold">
                            Atelier de production (sans vente au comptoir)
                        </div>
                        <div class="small text-secondary mt-1">
                            Charges atelier déclarées : <strong class="text-danger"><?= number_format($m['exp_out'], 0, '.', ' ') ?> FCFA</strong>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-4 mb-4 no-print">
    <!-- Section Vendeur/Admin : Petit Achat -->
    <div class="col-12 col-md-6">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-cart-shopping text-danger me-2"></i>Saisir un Petit Achat</h5>
            <form action="caisse.php" method="POST" onsubmit="return confirm('Confirmer l\'enregistrement de cette dépense sur le stock de caisse ?');">
                <input type="hidden" name="add_small_expense" value="1">
                
                <?php if ($user_role === 'admin'): ?>
                    <div class="mb-3">
                        <label for="shop_id" class="form-label fw-semibold text-secondary">Dépôt concerné</label>
                        <select name="shop_id" id="shop_id" class="form-select form-control-custom" required>
                            <?php foreach ($shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $active_shop_id ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="category" class="form-label fw-semibold text-secondary mb-0">Catégorie de Dépense *</label>
                        <a href="expense-categories.php" class="small text-danger text-decoration-none fw-semibold"><i class="fa-solid fa-plus-circle me-1"></i>Gérer les catégories</a>
                    </div>
                    <select name="category" id="category" class="form-select form-control-custom" required>
                        <option value="">-- Choisir une catégorie --</option>
                        <?php if (!empty($expense_categories)): ?>
                            <?php foreach ($expense_categories as $ecat): ?>
                                <option value="<?= htmlspecialchars($ecat['name']) ?>">
                                    <?= htmlspecialchars($ecat['name']) ?><?= !empty($ecat['description']) ? ' (' . htmlspecialchars($ecat['description']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="EAU POTABLE">Eau Potable / Boissons</option>
                            <option value="TAXI / TRANSPORT">Taxi / Coursiers</option>
                            <option value="EMBALLAGE">Emballages / Conditionnement</option>
                            <option value="PETITES FOURNITURES">Fournitures de bureau (Stylos...)</option>
                            <option value="ELECTRICITE / ABONNEMENT">Électricité / Internet</option>
                            <option value="AUTRE">Autre (Préciser en notes)</option>
                        <?php endif; ?>
                    </select>
                </div>


                <div class="mb-3">
                    <label for="amount" class="form-label fw-semibold text-secondary">Montant (FCFA) *</label>
                    <input type="number" name="amount" id="amount" class="form-control form-control-custom" min="1" placeholder="Ex: 1500" required>
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label fw-semibold text-secondary">Détails / Motif explicatif *</label>
                    <textarea name="notes" id="notes" class="form-control form-control-custom" rows="2" placeholder="Ex: Achat d'une bouteille d'eau et emballage bulle..." required></textarea>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-danger btn-sm py-2.5 fw-bold" style="border-radius: 10px;">
                        <i class="fa-solid fa-receipt me-1"></i>Enregistrer la dépense
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Section Vendeur uniquement : Versement au Boss -->
    <?php if ($user_role !== 'admin'): ?>
    <div class="col-12 col-md-6">
        <div class="glass-card p-4 h-100">
            <h5 class="fw-bold mb-4 text-slate-800"><i class="fa-solid fa-exchange-alt text-primary me-2"></i>Déclarer un Versement au Boss</h5>
            <form action="caisse.php" method="POST" onsubmit="return confirm('Confirmer le virement de liquidités vers le Boss ? Le solde de la boutique sera déduit.');">
                <input type="hidden" name="transfer_to_boss" value="1">
                
                <div class="mb-3">
                    <label for="amount_transfer" class="form-label fw-semibold text-secondary">Montant du versement (FCFA) *</label>
                    <input type="number" name="amount" id="amount_transfer" class="form-control form-control-custom" min="1" placeholder="Ex: 50000" required>
                </div>

                <div class="mb-4">
                    <label for="notes_transfer" class="form-label fw-semibold text-secondary">Remarques / Notes de transfert</label>
                    <textarea name="notes" id="notes_transfer" class="form-control form-control-custom" rows="2" placeholder="Ex: Collecte de fin de semaine..."></textarea>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-sm py-2.5 fw-bold" style="border-radius: 10px;">
                        <i class="fa-solid fa-paper-plane me-1"></i>Envoyer le virement
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Journal des Flux (Pleine largeur) -->
    <div class="col-12">
        <div class="glass-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-history text-primary me-2"></i>Journal des Mouvements de Caisse</h5>
                <div class="d-flex align-items-center gap-2 no-print">
                    <input type="text" id="caisseSearchInput" class="form-control form-control-custom w-auto" placeholder="Filtrer en direct...">
                    <button type="button" onclick="exportTableToExcel('caisseTable', 'journal_caisse.csv')" class="btn btn-outline-success btn-sm fw-semibold" style="border-radius: 8px;">
                        <i class="fa-solid fa-file-excel me-1"></i>Exporter Excel
                    </button>
                </div>
            </div>

            <?php if ($user_role === 'admin'): ?>
            <!-- Filtres Admin du Journal de Caisse -->
            <form method="GET" action="caisse.php" class="row g-2 mb-3 align-items-end no-print" id="caisseFilterForm">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold text-secondary small mb-1">Boutique / Caisse</label>
                    <select name="filter_shop" class="form-select form-select-sm form-control-custom" onchange="this.form.submit()">
                        <option value="">🌐 Toutes les caisses</option>
                        <option value="boss" <?= $filter_shop_raw === 'boss' ? 'selected' : '' ?>>💰 Caisse Boss (Coffre)</option>
                        <option value="purchases" <?= $filter_shop_raw === 'purchases' ? 'selected' : '' ?>>📦 Achats Fournisseurs</option>
                        <?php foreach ($shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= (string)$filter_shop_raw === (string)$sh['id'] ? 'selected' : '' ?>>
                                <?= $sh['is_depot'] ? '🏭' : '🏢' ?> <?= htmlspecialchars($sh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold text-secondary small mb-1">Type de flux</label>
                    <select name="filter_type" class="form-select form-select-sm form-control-custom" onchange="this.form.submit()">
                        <option value="">Tous les types</option>
                        <option value="IN"       <?= $filter_type_raw === 'IN'       ? 'selected' : '' ?>>📥 Entrées (Recettes)</option>
                        <option value="OUT"      <?= $filter_type_raw === 'OUT'      ? 'selected' : '' ?>>📤 Sorties (Dépenses)</option>
                        <option value="TRANSFER" <?= $filter_type_raw === 'TRANSFER' ? 'selected' : '' ?>>🔄 Versements Boss</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold text-secondary small mb-1">Du</label>
                    <input type="date" name="date_from" class="form-control form-control-sm form-control-custom" value="<?= htmlspecialchars($filter_date_from) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold text-secondary small mb-1">Au</label>
                    <input type="date" name="date_to" class="form-control form-control-sm form-control-custom" value="<?= htmlspecialchars($filter_date_to) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-12 col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm fw-semibold" style="border-radius:8px;">
                        <i class="fa-solid fa-filter me-1"></i>Filtrer
                    </button>
                    <a href="caisse.php" class="btn btn-outline-secondary btn-sm fw-semibold" style="border-radius:8px;">
                        <i class="fa-solid fa-xmark me-1"></i>Réinitialiser
                    </a>
                </div>
            </form>
            <?php if ($filter_shop_raw !== '' || $filter_type_raw !== '' || $filter_date_from !== '' || $filter_date_to !== ''): ?>
                <div class="alert alert-info border-0 py-2 px-3 mb-3 small" style="border-radius:8px;">
                    <i class="fa-solid fa-filter me-1"></i>
                    Filtre actif — <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> transaction(s) trouvée(s).
                </div>
            <?php endif; ?>
            <?php endif; ?>


            <?php if (empty($transactions)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="fa-solid fa-cash-register fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">Aucun flux financier n'a été enregistré pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle" id="caisseTable">
                        <thead>
                            <tr>
                                <th>Date & Heure</th>
                                <th>Dépôt</th>
                                <th>Client / Contact</th>
                                <th>N° Facture / Réf</th>
                                <th>Détails de l'Opération</th>
                                <th>Opérateur</th>
                                <th class="text-end">Montant</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): 
                                $type_class = '';
                                $type_prefix = '';
                                if ($t['type'] === 'IN') {
                                    $type_class = 'text-success fw-bold';
                                    $type_prefix = '+';
                                } elseif ($t['type'] === 'OUT') {
                                    $type_class = 'text-danger fw-bold';
                                    $type_prefix = '-';
                                }
                            ?>
                                <tr class="caisse-row">
                                    <td class="small text-secondary text-nowrap"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border fw-semibold">
                                            <i class="fa-solid fa-warehouse me-1 text-secondary"></i>
                                            <?= htmlspecialchars($t['shop_name'] ?? 'Caisse Boss') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($t['contact_name'])): ?>
                                            <div class="fw-bold text-slate-800">
                                                <i class="fa-solid fa-user text-primary me-1"></i><?= htmlspecialchars($t['contact_name']) ?>
                                            </div>
                                            <?php if (!empty($t['contact_phone'])): ?>
                                                <small class="text-muted"><i class="fa-solid fa-phone me-1" style="font-size:0.7rem;"></i><?= htmlspecialchars($t['contact_phone']) ?></small>
                                            <?php endif; ?>
                                        <?php elseif ($t['reference_type'] === 'ORDER'): ?>
                                            <span class="text-muted small italic">Client Comptant</span>
                                        <?php elseif ($t['reference_type'] === 'TRANSFER'): ?>
                                            <span class="badge bg-light text-dark border small"><i class="fa-solid fa-arrow-right-arrow-left me-1 text-secondary"></i>Boutique ➔ Boss</span>
                                        <?php elseif ($t['reference_type'] === 'EXPENSE'): ?>
                                            <span class="badge bg-danger-subtle text-danger small"><i class="fa-solid fa-receipt me-1"></i>Charge d'exploitation</span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['reference_type'] === 'ORDER' && !empty($t['reference_id'])): ?>
                                            <?php 
                                            $inv_code = !empty($t['order_ref']) ? $t['order_ref'] : ('FAC-' . sprintf('%05d', $t['reference_id']));
                                            ?>
                                            <a href="invoice.php?id=<?= $t['reference_id'] ?>" target="_blank" class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle text-decoration-none fw-bold" style="font-size: 0.78rem;" title="Ouvrir la facture">
                                                <i class="fa-solid fa-file-invoice me-1"></i><?= htmlspecialchars($inv_code) ?>
                                            </a>
                                        <?php elseif ($t['reference_type'] === 'PURCHASE' && !empty($t['reference_id'])): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border fw-bold" style="font-size: 0.75rem;">
                                                <i class="fa-solid fa-boxes-packing me-1"></i>ACHAT #<?= $t['reference_id'] ?>
                                            </span>
                                        <?php elseif ($t['reference_type'] === 'TRANSFER'): ?>
                                            <?php $transfer_code_id = $t['reference_id'] ?: $t['id']; ?>
                                            <span class="badge bg-info bg-opacity-10 text-info-emphasis border border-info-subtle fw-bold" style="font-size: 0.75rem;">
                                                <i class="fa-solid fa-money-bill-transfer me-1"></i>VERS-<?= sprintf("%05d", $transfer_code_id) ?>
                                            </span>
                                        <?php elseif ($t['reference_type'] === 'EXPENSE'): ?>
                                            <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.72rem;">
                                                DEP #<?= $t['reference_id'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">#<?= $t['id'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-slate-900 fw-semibold small"><?= htmlspecialchars($t['description']) ?></div>
                                        <?php if ($t['reference_type'] === 'TRANSFER'): ?>
                                            <div class="mt-1">
                                                <?php if ($t['status'] === 'PENDING'): ?>
                                                    <span class="badge bg-warning text-dark fw-bold" style="font-size: 0.68rem;"><i class="fa-solid fa-clock me-1"></i>En attente de confirmation Boss</span>
                                                <?php elseif ($t['status'] === 'VALIDATED' && $t['shop_id'] === null): ?>
                                                    <span class="badge bg-success text-white fw-bold" style="font-size: 0.68rem;"><i class="fa-solid fa-check-double me-1"></i>Encaissé dans le coffre du Boss</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-secondary"><?= htmlspecialchars($t['user_name']) ?></small></td>
                                    <td class="text-end <?= $type_class ?> text-nowrap">
                                        <?= $type_prefix ?> <?= number_format($t['amount'], 0, '.', ' ') ?> FCFA
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Barre de Pagination Caisse -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                        <div class="small text-secondary fw-semibold">
                            Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($transactions)) ?> sur <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> transaction(s)
                        </div>
                        <nav aria-label="Pagination caisse">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $query_params = $_GET;
                                if ($current_page > 1):
                                    $query_params['page'] = $current_page - 1;
                                    echo '<li class="page-item"><a class="page-link" href="caisse.php?' . http_build_query($query_params) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                else:
                                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                endif;

                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);

                                if ($start_page > 1) {
                                    $query_params['page'] = 1;
                                    echo '<li class="page-item"><a class="page-link" href="caisse.php?' . http_build_query($query_params) . '">1</a></li>';
                                    if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }

                                for ($p = $start_page; $p <= $end_page; $p++):
                                    $query_params['page'] = $p;
                                    $active_cls = ($p == $current_page) ? 'active' : '';
                                    echo '<li class="page-item ' . $active_cls . '"><a class="page-link" href="caisse.php?' . http_build_query($query_params) . '">' . $p . '</a></li>';
                                endfor;

                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    $query_params['page'] = $total_pages;
                                    echo '<li class="page-item"><a class="page-link" href="caisse.php?' . http_build_query($query_params) . '">' . $total_pages . '</a></li>';
                                }

                                if ($current_page < $total_pages):
                                    $query_params['page'] = $current_page + 1;
                                    echo '<li class="page-item"><a class="page-link" href="caisse.php?' . http_build_query($query_params) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
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

<!-- Modal Arrêté / Calage de Caisse Physique -->
<div class="modal fade" id="adjustDrawerModal" tabindex="-1" aria-labelledby="adjustDrawerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-slate-800" id="adjustDrawerModalLabel">
                    <i class="fa-solid fa-sliders text-success me-2"></i>Arrêté & Calage de Caisse Physique
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form action="caisse.php" method="POST" onsubmit="return confirm('Confirmer le calage du tiroir de caisse sur ce montant réel ?');">
                <input type="hidden" name="adjust_drawer" value="1">
                <div class="modal-body pt-3">
                    <p class="small text-secondary mb-3">
                        Cette opération permet de caler le solde informatique sur le <strong>montant exact d'espèces compté dans le tiroir</strong> (en fin de journée ou lors d'un contrôle).
                    </p>

                    <?php if ($user_role === 'admin'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary small">Dépôt / Boutique concernée</label>
                            <select name="shop_id" class="form-select form-control-custom" required>
                                <?php foreach ($shops as $sh): ?>
                                    <?php if ($sh['is_depot'] == 0): ?>
                                        <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $active_shop_id ? 'selected' : '' ?>>
                                            🏢 <?= htmlspecialchars($sh['name']) ?> (Solde actuel : <?= number_format($shop_metrics[$sh['id']]['drawer_balance'], 0, '.', ' ') ?> FCFA)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="shop_id" value="<?= $active_shop_id ?>">
                        <div class="alert alert-light border small mb-3">
                            <strong>Dépôt actif :</strong> <?= htmlspecialchars($_SESSION['active_shop_name'] ?? 'Boutique') ?><br>
                            <strong>Solde actuel en machine :</strong> <?= number_format($active_m['drawer_balance'], 0, '.', ' ') ?> FCFA
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Montant physique réel compté (FCFA) *</label>
                        <input type="number" name="counted_amount" class="form-control form-control-custom fw-bold fs-5 text-success" min="0" placeholder="Ex: 250000" required>
                        <div class="form-text small">Entrez la somme totale des billets et pièces actuellement dans le tiroir.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary small">Motif / Remarque explicative</label>
                        <input type="text" name="notes" class="form-control form-control-custom" placeholder="Ex: Arrêté de caisse journalier / Régularisation versements remis en mains propres">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light btn-sm fw-semibold" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success btn-sm fw-bold px-4" style="border-radius: 8px;">
                        <i class="fa-solid fa-check me-1"></i>Valider l'Arrêté de Caisse
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('caisseSearchInput');
    const tableRows = document.querySelectorAll('.caisse-row');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = searchInput.value.toLowerCase().trim();
            tableRows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
