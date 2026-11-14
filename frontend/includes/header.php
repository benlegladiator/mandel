<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Connexion base de données
require_once __DIR__ . '/../../backend/config/db.php';

// Auto-migration ultra-sécurisée des colonnes de transferts (transfer_code & status)
try {
    $check_code_col = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'transfer_code'");
    if (!$check_code_col || !$check_code_col->fetch()) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN transfer_code VARCHAR(50) NULL AFTER id");
        $pdo->exec("UPDATE stock_transfers SET transfer_code = CONCAT('TR-', LPAD(id, 5, '0')) WHERE transfer_code IS NULL OR transfer_code = ''");
    }
    $check_status_col = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'status'");
    if (!$check_status_col || !$check_status_col->fetch()) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN status ENUM('PENDING', 'RECEIVED', 'CANCELLED') NOT NULL DEFAULT 'RECEIVED' AFTER notes");
    }
    $check_rcv_user = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'received_by'");
    if (!$check_rcv_user || !$check_rcv_user->fetch()) {
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN received_by INT NULL AFTER status");
        $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN received_at DATETIME NULL AFTER received_by");
    }
} catch (Exception $e) {}

// Rediriger vers login.php si l'utilisateur n'est pas connecté
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header("Location: login.php");
    exit;
}

// Récupérer toutes les boutiques
$shops_stmt = $pdo->query("SELECT * FROM shops ORDER BY name");
$all_shops = $shops_stmt->fetchAll();

// Si l'utilisateur connecté est un vendeur, on force son dépôt actif sur son dépôt assigné
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'seller') {
    $_SESSION['active_shop_id'] = $_SESSION['user_shop_id'] ?? 1;
}

// Initialisation de la boutique active
if (!isset($_SESSION['active_shop_id'])) {
    $_SESSION['active_shop_id'] = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? 0 : ($_SESSION['user_shop_id'] ?? 1);
}

// Mettre à jour la boutique active si modifiée par l'utilisateur (Admin uniquement)
if (isset($_POST['change_active_shop']) && $_SESSION['user_role'] === 'admin') {
    $new_shop_id = intval($_POST['change_active_shop']);
    if ($new_shop_id === 0) {
        $_SESSION['active_shop_id'] = 0; // Tous les dépôts (Global)
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    // Vérifier que la boutique existe
    foreach ($all_shops as $s) {
        if ($s['id'] == $new_shop_id) {
            $_SESSION['active_shop_id'] = $new_shop_id;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Récupérer les détails de la boutique active courante
$current_shop = null;
if ($_SESSION['active_shop_id'] == 0) {
    $current_shop = ['id' => 0, 'name' => 'Tous les dépôts (Global)', 'address' => 'Global', 'phone' => ''];
} else {
    foreach ($all_shops as $s) {
        if ($s['id'] == $_SESSION['active_shop_id']) {
            $current_shop = $s;
            break;
        }
    }
    if (!$current_shop && !empty($all_shops)) {
        $current_shop = $all_shops[0];
        $_SESSION['active_shop_id'] = $current_shop['id'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mandel - Gestion de Stock</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=6">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=6">
    <link rel="icon" type="image/png" href="assets/images/favicon.png?v=6">
    <link rel="shortcut icon" href="favicon.ico?v=6" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png?v=6">
    <script>
        if (localStorage.getItem('mandel-compact-mode') !== 'disabled') {
            document.documentElement.classList.add('compact-mode');
        }
    </script>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- PWA Manifest & Condition de Licence PWA_OFFLINE_ENABLED -->
    <?php if (defined('PWA_OFFLINE_ENABLED') && PWA_OFFLINE_ENABLED === true): ?>
        <?php
        $is_mandel_prod = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'mandel.free.nf') !== false);
        $manifest_url = $is_mandel_prod 
            ? "https://cdn.jsdelivr.net/gh/benlegladiator/mandel@main/frontend/manifest.json?v=6" 
            : "manifest.php?v=6";
        ?>
        <link rel="manifest" href="<?= $manifest_url ?>" crossorigin="anonymous">
        <meta name="theme-color" content="#059669">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Mandel">
        <script src="assets/js/pwa-offline-sync.js?v=<?= file_exists(__DIR__ . '/../assets/js/pwa-offline-sync.js') ? filemtime(__DIR__ . '/../assets/js/pwa-offline-sync.js') : time() ?>"></script>
    <?php else: ?>
        <script>
            window.PWA_DISABLED = true;
            window.PWA_DISABLED_MSG = <?= json_encode(defined('PWA_DISABLED_MESSAGE') ? PWA_DISABLED_MESSAGE : 'Service PWA non disponible pour l\'instant. Veuillez contacter l\'opérateur d\'hébergement.') ?>;
        </script>
        <script src="assets/js/pwa-offline-sync.js?v=<?= file_exists(__DIR__ . '/../assets/js/pwa-offline-sync.js') ? filemtime(__DIR__ . '/../assets/js/pwa-offline-sync.js') : time() ?>"></script>
    <?php endif; ?>
    <!-- Custom Style Sheet with Cache Buster -->
    <link href="assets/css/style.css?v=<?= file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time() ?>" rel="stylesheet">
    <!-- Export Excel Utility Script -->
    <script src="assets/js/export-excel.js?v=<?= file_exists(__DIR__ . '/../assets/js/export-excel.js') ? filemtime(__DIR__ . '/../assets/js/export-excel.js') : time() ?>"></script>
</head>

<body>
<?php
$active_page = basename($_SERVER['PHP_SELF']);

// Compter les dépenses et versements en attente d'avis (Admin uniquement)
$pending_expenses_count = 0;
$pending_transfers_count = 0;
$overdue_clients = 0;
$overdue_suppliers = 0;

if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    $pending_expenses_count = intval($pdo->query("SELECT COUNT(*) FROM expenses WHERE status = 'PENDING_ACK'")->fetchColumn() ?: 0);
    $pending_transfers_count = intval($pdo->query("SELECT COUNT(*) FROM cash_transactions WHERE shop_id IS NULL AND type = 'IN' AND reference_type = 'TRANSFER' AND status = 'PENDING'")->fetchColumn() ?: 0);
}
$total_caisse_alerts = $pending_expenses_count + $pending_transfers_count;

// Alertes Dettes/Créances échues et Transferts non lus
$unread_transfers_count = 0;
if (isset($_SESSION['user_id'])) {
    $shop_filter = ($_SESSION['user_role'] === 'admin' && $_SESSION['active_shop_id'] == 0) ? "" : " AND shop_id = " . intval($_SESSION['active_shop_id']);
    $overdue_clients = intval($pdo->query("SELECT COUNT(*) FROM orders WHERE type = 'SALE' AND payment_method = 'CREDIT' AND status = 'COMPLETED' AND payment_status IN ('UNPAID', 'PARTIAL') AND due_date < CURDATE()" . $shop_filter)->fetchColumn() ?: 0);
    $overdue_suppliers = intval($pdo->query("SELECT COUNT(*) FROM orders WHERE type = 'PURCHASE' AND payment_method = 'CREDIT' AND status = 'COMPLETED' AND payment_status IN ('UNPAID', 'PARTIAL') AND due_date < CURDATE()" . $shop_filter)->fetchColumn() ?: 0);
    
    $tr_shop_filter = ($_SESSION['user_role'] === 'admin' && $_SESSION['active_shop_id'] == 0) ? " WHERE status = 'PENDING'" : " WHERE to_shop_id = " . intval($_SESSION['active_shop_id']) . " AND status = 'PENDING'";
    $unread_transfers_count = intval($pdo->query("SELECT COUNT(DISTINCT transfer_code) FROM stock_transfers" . $tr_shop_filter)->fetchColumn() ?: 0);
}
$total_debt_alerts = $overdue_clients + $overdue_suppliers;
$total_notifications_count = $total_debt_alerts + $unread_transfers_count;

?>
    <!-- Navbar simplifiée et épurée -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top no-print mb-4">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2 py-1" href="index.php">
                <img src="assets/images/logo.png" alt="Mandel" style="height: 34px; width: auto; object-fit: contain;">
                <span>Ets MANDEL</span>
            </a>
            
            <?php if (isset($_SESSION['user_id'])): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="index.php">
                            <i class="fa-solid fa-house me-1"></i>Accueil
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'caisse.php' ? 'active' : '' ?>" href="caisse.php">
                            <i class="fa-solid fa-cash-register me-1"></i>Caisse
                            <?php if ($total_caisse_alerts > 0): ?>
                                <span class="badge bg-warning text-dark rounded-pill ms-1" style="font-size: 0.75rem; vertical-align: middle;"><?= $total_caisse_alerts ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'stock-transfers.php' ? 'active' : '' ?>" href="stock-transfers.php">
                            <i class="fa-solid fa-truck-ramp-box me-1"></i>Transferts
                            <?php if ($unread_transfers_count > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-1" style="font-size: 0.75rem; vertical-align: middle;"><?= $unread_transfers_count ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'product-history.php' ? 'active' : '' ?>" href="product-history.php">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i>Fiche Produit
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'documents.php' ? 'active' : '' ?>" href="documents.php">
                            <i class="fa-solid fa-folder-open me-1 text-primary"></i>Documents
                        </a>
                    </li>
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'depot.php' ? 'active' : '' ?>" href="depot.php">
                            <i class="fa-solid fa-industry me-1"></i>Usine (Dépôt)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'stock-exit.php' ? 'active' : '' ?>" href="stock-exit.php">
                            <i class="fa-solid fa-arrow-right-from-bracket me-1 text-danger"></i>Mvt Sortie
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Sélecteur de Boutique active -->
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                    <form class="d-flex align-items-center me-3" method="POST" action="">
                        <span class="text-secondary small me-2 d-none d-xl-inline"><i class="fa-solid fa-location-dot text-primary me-1"></i>Dépôt :</span>
                        <select class="form-select form-select-sm border-secondary-subtle" name="change_active_shop" onchange="this.form.submit()" style="border-radius: 8px; font-weight: 600;">
                            <option value="0" <?= $_SESSION['active_shop_id'] == 0 ? 'selected' : '' ?>>
                                🌐 Tous les dépôts (Global)
                            </option>
                            <?php foreach ($all_shops as $sh): ?>
                                <option value="<?= $sh['id'] ?>" <?= $sh['id'] == $_SESSION['active_shop_id'] ? 'selected' : '' ?>>
                                    🏢 <?= htmlspecialchars($sh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <div class="d-flex align-items-center me-3">
                        <span class="badge bg-light text-dark py-2 px-3 fw-semibold border" style="border-radius: 10px;">
                            <i class="fa-solid fa-location-dot text-primary me-1"></i>
                            Dépôt : <?= htmlspecialchars($current_shop['name'] ?? '') ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Indicateur Réseau & Centre Hors-Ligne Mandel -->
                <button type="button" class="btn btn-sm btn-light border fw-bold me-2 text-nowrap d-flex align-items-center gap-2 shadow-sm" id="networkStatusBtn" data-bs-toggle="modal" data-bs-target="#offlineSyncModal" style="border-radius: 8px; padding: 0.35rem 0.75rem;" title="Centre Hors-Ligne & Synchronisation">
                    <span id="networkStatusDot" style="width: 10px; height: 10px; border-radius: 50%; background-color: #22c55e; display: inline-block;"></span>
                    <span id="networkStatusText" style="font-size: 0.85rem;">En ligne</span>
                    <span class="badge bg-warning text-dark d-none ms-1" id="networkPendingBadge" style="font-size: 0.7rem; border-radius: 10px;">0</span>
                </button>

                <!-- Infos Profil & Bouton Déconnexion -->
                <div class="d-flex align-items-center gap-2">
                    
                    <!-- Cloche de notifications -->
                    <div class="dropdown me-1">
                        <button class="btn btn-light position-relative border" type="button" id="alertsDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 10px; padding: 0.375rem 0.75rem;" title="Notifications">
                            <i class="fa-solid fa-bell text-secondary"></i>
                            <?php if ($total_notifications_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
                                    <?= $total_notifications_count ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" aria-labelledby="alertsDropdown" style="border-radius: 12px; min-width: 280px;">
                            <li><h6 class="dropdown-header fw-bold text-dark">Notifications & Alertes</h6></li>
                            <?php if ($unread_transfers_count > 0): ?>
                                <li><a class="dropdown-item py-2 fw-semibold" href="stock-transfers.php"><i class="fa-solid fa-truck-fast text-primary me-2"></i><span class="text-primary"><?= $unread_transfers_count ?> Transfert(s) reçu(s) à consulter</span></a></li>
                            <?php endif; ?>
                            <?php if ($overdue_clients > 0): ?>
                                <li><a class="dropdown-item py-2 fw-semibold" href="credits.php?tab=clients"><i class="fa-solid fa-hand-holding-dollar text-warning me-2"></i><span class="text-danger"><?= $overdue_clients ?> Créance(s) client échue(s)</span></a></li>
                            <?php endif; ?>
                            <?php if ($overdue_suppliers > 0): ?>
                                <li><a class="dropdown-item py-2 fw-semibold" href="credits.php?tab=suppliers"><i class="fa-solid fa-file-invoice-dollar text-danger me-2"></i><span class="text-danger"><?= $overdue_suppliers ?> Dette(s) fournisseur échue(s)</span></a></li>
                            <?php endif; ?>
                            <?php if ($total_notifications_count == 0): ?>
                                <li><span class="dropdown-item text-secondary py-2 small">Aucune nouvelle alerte.</span></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <button id="compact-toggle" class="btn btn-outline-secondary btn-sm px-3" style="border-radius: 10px;" title="Mode Compact">
                        <i class="fa-solid fa-compress me-1"></i> <span class="d-none d-xl-inline">Mode Compact</span>
                    </button>
                    
                    <span class="badge bg-primary-subtle text-primary py-2 px-3 fw-semibold" style="border-radius: 10px;">
                        <i class="fa-solid <?= $_SESSION['user_role'] === 'admin' ? 'fa-user-shield' : 'fa-user' ?> me-1"></i>
                        <?= htmlspecialchars($_SESSION['user_name']) ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm px-3" style="border-radius: 10px;" title="Se déconnecter">
                        <i class="fa-solid fa-right-from-bracket"></i> <span class="d-none d-md-inline ms-1">Déconnexion</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </nav>
    
    <!-- Main Container -->
    <div class="container-fluid p-4 p-md-5">
        <!-- Bouton Retour automatique sur toutes les sous-pages -->
        <?php if ($active_page !== 'index.php'): ?>
            <div class="mb-4 no-print d-flex gap-2">
                <a href="javascript:history.back()" class="btn btn-light btn-sm text-secondary border px-3" style="border-radius: 8px; font-weight: 500;">
                    <i class="fa-solid fa-arrow-left me-1"></i>Retour
                </a>
                <a href="index.php" class="btn btn-light btn-sm text-secondary border px-3" style="border-radius: 8px; font-weight: 500;">
                    <i class="fa-solid fa-house me-1"></i>Accueil
                </a>
            </div>
        <?php endif; ?>
