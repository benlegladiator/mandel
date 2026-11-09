<?php
require_once __DIR__ . '/includes/header.php';

$user_id = intval($_SESSION['user_id'] ?? 1);
$user_role = $_SESSION['user_role'] ?? 'seller';
$active_shop_id = intval($_SESSION['active_shop_id'] ?? 0);

$upload_dir = __DIR__ . '/../uploads/documents/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$success_msg = '';
$error_msg = '';

// ====================================================================
// 1. TÉLÉCHARGEMENT DIRECT D'UN DOCUMENT (DOWNLOAD)
// ====================================================================
if (isset($_GET['download'])) {
    $doc_id = intval($_GET['download']);
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();

    if ($doc) {
        $real_path = $upload_dir . $doc['file_name'];
        if (file_exists($real_path)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($doc['original_name']) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($real_path));
            readfile($real_path);
            exit;
        } else {
            $error_msg = "Le fichier physique est introuvable sur le serveur.";
        }
    } else {
        $error_msg = "Document non trouvé.";
    }
}

// ====================================================================
// 2. PRÉVISUALISATION / CONSULTATION DIRECTE DANS LE NAVIGATEUR (VIEW)
// ====================================================================
if (isset($_GET['view'])) {
    $doc_id = intval($_GET['view']);
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();

    if ($doc) {
        $real_path = $upload_dir . $doc['file_name'];
        if (file_exists($real_path)) {
            $mime_types = [
                'pdf'  => 'application/pdf',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
                'txt'  => 'text/plain; charset=utf-8',
            ];
            $ext = strtolower($doc['file_ext']);
            $content_type = $mime_types[$ext] ?? 'application/octet-stream';

            header('Content-Type: ' . $content_type);
            header('Content-Disposition: inline; filename="' . basename($doc['original_name']) . '"');
            header('Content-Length: ' . filesize($real_path));
            readfile($real_path);
            exit;
        } else {
            $error_msg = "Le fichier physique est introuvable sur le serveur.";
        }
    }
}

// ====================================================================
// 3. TRAITEMENT DE L'UPLOAD D'UN NOUVEAU DOCUMENT
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_doc'])) {
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'Général');
    $description = trim($_POST['description'] ?? '');
    $target_shop_id = !empty($_POST['target_shop_id']) ? intval($_POST['target_shop_id']) : null;

    if (empty($title)) {
        $error_msg = "Veuillez renseigner un titre pour le document.";
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Veuillez sélectionner un fichier valide à charger.";
    } else {
        $file = $_FILES['document_file'];
        $original_name = basename($file['name']);
        $file_size = intval($file['size']);
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        $allowed_exts = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ods', 'csv', 
            'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 
            'gif', 'zip', 'rar'
        ];

        if (!in_array($file_ext, $allowed_exts)) {
            $error_msg = "Format de fichier non autorisé (.$file_ext). Formats acceptés : PDF, Word, Excel, Images, Textes, Archives.";
        } elseif ($file_size > 35 * 1024 * 1024) { // Limite 35 Mo
            $error_msg = "Le fichier est trop lourd (maximum 35 Mo).";
        } else {
            // Générer un nom unique sécurisé sur le disque
            $unique_name = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
            $destination = $upload_dir . $unique_name;
            $relative_path = 'uploads/documents/' . $unique_name;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                try {
                    $ins_stmt = $pdo->prepare("
                        INSERT INTO documents (
                            title, category, description, file_name, 
                            original_name, file_path, file_size, file_ext, 
                            user_id, target_shop_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins_stmt->execute([
                        $title,
                        $category,
                        $description,
                        $unique_name,
                        $original_name,
                        $relative_path,
                        $file_size,
                        $file_ext,
                        $user_id,
                        $target_shop_id
                    ]);

                    $success_msg = "Le document <strong>" . htmlspecialchars($title) . "</strong> a été chargé et partagé avec succès !";
                } catch (Exception $e) {
                    @unlink($destination);
                    $error_msg = "Erreur lors de l'enregistrement en base de données : " . $e->getMessage();
                }
            } else {
                $error_msg = "Impossible de déplacer le fichier vers le dossier de destination.";
            }
        }
    }
}

// ====================================================================
// 4. SUPPRESSION D'UN DOCUMENT
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_doc'])) {
    $del_id = intval($_POST['doc_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$del_id]);
    $doc = $stmt->fetch();

    if ($doc) {
        // Seul l'administrateur ou la personne qui a envoyé le document peut le supprimer
        if ($user_role === 'admin' || $doc['user_id'] == $user_id) {
            $real_path = $upload_dir . $doc['file_name'];
            if (file_exists($real_path)) {
                @unlink($real_path);
            }
            $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$del_id]);
            $success_msg = "Le document a été supprimé avec succès.";
        } else {
            $error_msg = "Vous n'avez pas l'autorisation de supprimer ce document.";
        }
    }
}

// ====================================================================
// 5. EXTRACTION & RECHERCHE DES DOCUMENTS
// ====================================================================
$search = trim($_GET['search'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$type_filter = trim($_GET['type'] ?? 'ALL');

$sql = "
    SELECT d.*, u.name as author_name, u.role as author_role, s.name as target_shop_name
    FROM documents d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN shops s ON d.target_shop_id = s.id
    WHERE 1=1
";
$params = [];

// Si l'utilisateur est un vendeur, il ne voit que les documents globaux ou adressés à sa boutique active
if ($user_role !== 'admin' && $active_shop_id > 0) {
    $sql .= " AND (d.target_shop_id IS NULL OR d.target_shop_id = :user_shop_id OR d.user_id = :cur_user_id)";
    $params['user_shop_id'] = $active_shop_id;
    $params['cur_user_id'] = $user_id;
}

if (!empty($search)) {
    $sql .= " AND (d.title LIKE :search OR d.description LIKE :search OR d.original_name LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if (!empty($category_filter) && $category_filter !== 'ALL') {
    $sql .= " AND d.category = :category";
    $params['category'] = $category_filter;
}

if ($type_filter === 'PDF') {
    $sql .= " AND d.file_ext = 'pdf'";
} elseif ($type_filter === 'EXCEL') {
    $sql .= " AND d.file_ext IN ('xls', 'xlsx', 'ods', 'csv')";
} elseif ($type_filter === 'WORD') {
    $sql .= " AND d.file_ext IN ('doc', 'docx')";
} elseif ($type_filter === 'IMAGE') {
    $sql .= " AND d.file_ext IN ('png', 'jpg', 'jpeg', 'webp', 'gif')";
}

// Pagination SQL (25 documents / page)
$per_page = 25;

// Requête COUNT pour la pagination
$count_sql = preg_replace('/SELECT\s+d\.\*.*?\s+FROM\s+documents\s+d/si', 'SELECT COUNT(*) FROM documents d', $sql);
$stmt_cnt = $pdo->prepare($count_sql);
$stmt_cnt->execute($params);
$total_records = intval($stmt_cnt->fetchColumn() ?: 0);

$total_pages = max(1, ceil($total_records / $per_page));
$current_page = isset($_GET['page']) ? max(1, min($total_pages, intval($_GET['page']))) : 1;
$offset = ($current_page - 1) * $per_page;

$sql .= " ORDER BY d.created_at DESC LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Statistiques globales
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN file_ext = 'pdf' THEN 1 ELSE 0 END) as total_pdf,
        SUM(CASE WHEN file_ext IN ('xls', 'xlsx', 'ods', 'csv') THEN 1 ELSE 0 END) as total_excel,
        SUM(CASE WHEN file_ext IN ('doc', 'docx') THEN 1 ELSE 0 END) as total_word,
        SUM(CASE WHEN file_ext IN ('png', 'jpg', 'jpeg', 'webp', 'gif') THEN 1 ELSE 0 END) as total_img
    FROM documents
");
$stats = $stats_stmt->fetch();

// Fonction helper pour afficher la taille en Ko/Mo
function formatDocSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' Mo';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
    }
    return $bytes . ' octets';
}

// Fonction helper pour l'icône et la couleur du fichier
function getDocVisual($ext) {
    $ext = strtolower($ext);
    switch ($ext) {
        case 'pdf':
            return ['icon' => 'fa-file-pdf', 'color' => '#dc2626', 'bg' => '#fef2f2', 'badge' => 'PDF'];
        case 'xls':
        case 'xlsx':
        case 'ods':
        case 'csv':
            return ['icon' => 'fa-file-excel', 'color' => '#16a34a', 'bg' => '#f0fdf4', 'badge' => 'Excel'];
        case 'doc':
        case 'docx':
            return ['icon' => 'fa-file-word', 'color' => '#2563eb', 'bg' => '#eff6ff', 'badge' => 'Word'];
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'webp':
        case 'gif':
            return ['icon' => 'fa-file-image', 'color' => '#9333ea', 'bg' => '#faf5ff', 'badge' => 'Image'];
        case 'zip':
        case 'rar':
            return ['icon' => 'fa-file-zipper', 'color' => '#ea580c', 'bg' => '#fff7ed', 'badge' => 'Archive'];
        default:
            return ['icon' => 'fa-file-lines', 'color' => '#475569', 'bg' => '#f8fafc', 'badge' => strtoupper($ext)];
    }
}
?>

<!-- EN-TÊTE DE LA PAGE -->
<div class="row mb-4 align-items-center">
    <div class="col-12 col-md-7">
        <h1 class="fw-extrabold text-slate-800 mb-1 fs-3">
            <span class="text-primary"><i class="fa-solid fa-folder-open me-2"></i></span>
            Partage de Fichiers & Documents
        </h1>
        <p class="text-secondary mb-0 small">
            Espace d'échange et de transmission de documents (Word, Excel, PDF, Images) entre le Boss et les équipes en boutique.
        </p>
    </div>
    <div class="col-12 col-md-5 text-md-end mt-3 mt-md-0">
        <button class="btn btn-grad shadow-sm px-4 py-2" data-bs-toggle="collapse" data-bs-target="#uploadSection">
            <i class="fa-solid fa-cloud-arrow-up me-2"></i>Charger un Document
        </button>
    </div>
</div>

<!-- NOTIFICATIONS -->
<?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error_msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- CARTES KPIS / COMPTEURS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="glass-card p-3 border-start border-4 border-primary h-100">
            <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-folder me-1 text-primary"></i>Total Fichiers</small>
            <div class="fs-4 fw-extrabold text-slate-800"><?= number_format($stats['total'] ?? 0, 0, '.', ' ') ?></div>
            <small class="text-muted">documents partagés</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="glass-card p-3 border-start border-4 border-danger h-100">
            <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-file-pdf me-1 text-danger"></i>PDF</small>
            <div class="fs-4 fw-extrabold text-danger"><?= number_format($stats['total_pdf'] ?? 0, 0, '.', ' ') ?></div>
            <small class="text-muted">contrats, notes, factures</small>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="glass-card p-3 border-start border-4 border-success h-100">
            <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-file-excel me-1 text-success"></i>Excel & ODS</small>
            <div class="fs-4 fw-extrabold text-success"><?= number_format($stats['total_excel'] ?? 0, 0, '.', ' ') ?></div>
            <small class="text-muted">inventaires, tableaux</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass-card p-3 border-start border-4 border-primary h-100">
            <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-file-word me-1 text-primary"></i>Word & Textes</small>
            <div class="fs-4 fw-extrabold text-primary"><?= number_format($stats['total_word'] ?? 0, 0, '.', ' ') ?></div>
            <small class="text-muted">courriers, fiches</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="glass-card p-3 border-start border-4 border-warning h-100">
            <small class="text-secondary fw-semibold d-block mb-1"><i class="fa-solid fa-file-image me-1 text-warning"></i>Images & Scans</small>
            <div class="fs-4 fw-extrabold text-warning"><?= number_format($stats['total_img'] ?? 0, 0, '.', ' ') ?></div>
            <small class="text-muted">photos, reçus scannés</small>
        </div>
    </div>
</div>

<!-- SECTION FORMULAIRE DE CHARGEMENT (COLLAPSIBLE) -->
<div class="collapse mb-4" id="uploadSection">
    <div class="glass-card p-4 border border-primary border-opacity-25 shadow-sm" style="border-radius: 16px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-slate-800 mb-0">
                <i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i>Charger un nouveau fichier ou document
            </h5>
            <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#uploadSection" aria-label="Fermer"></button>
        </div>

        <form method="POST" action="documents.php" enctype="multipart/form-data">
            <input type="hidden" name="action_upload_doc" value="1">
            
            <div class="row g-3">
                <!-- Titre du document -->
                <div class="col-md-6">
                    <label class="form-label small fw-bold text-slate-700">Titre / Objet du document <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control form-control-custom" placeholder="Ex: Inventaire officiel fin Juillet, Reçu de livraison, Note de service..." required>
                </div>

                <!-- Catégorie -->
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-slate-700">Catégorie</label>
                    <select name="category" class="form-select form-control-custom">
                        <option value="Inventaire / Stock">📊 Inventaire / Stock</option>
                        <option value="Comptabilité / Finances">💰 Comptabilité / Finances</option>
                        <option value="Administratif">📑 Administratif / Direction</option>
                        <option value="Boutique / Ventes">🛒 Boutique / Ventes</option>
                        <option value="Ressources Humaines">👥 Personnel / RH</option>
                        <option value="Général" selected>📁 Général</option>
                    </select>
                </div>

                <!-- Boutique Cible -->
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-slate-700">Destinataire</label>
                    <select name="target_shop_id" class="form-select form-control-custom">
                        <option value="">🌐 Tous les dépôts (Tout le monde)</option>
                        <?php foreach ($all_shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>">🏢 <?= htmlspecialchars($sh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Zone de Fichier -->
                <div class="col-md-12">
                    <label class="form-label small fw-bold text-slate-700">Fichier à envoyer <span class="text-danger">*</span></label>
                    <div class="p-3 border rounded-3 bg-light text-center border-dashed position-relative" style="border: 2px dashed #cbd5e1;">
                        <i class="fa-solid fa-file-arrow-up fa-2x text-primary mb-2 d-block"></i>
                        <input type="file" name="document_file" id="documentFileInput" class="form-control" required style="max-width: 400px; margin: 0 auto;">
                        <small class="text-muted d-block mt-2">
                            Formats acceptés : <strong>PDF, Word (.doc, .docx), Excel (.xls, .xlsx, .ods, .csv), Images (.jpg, .png, .webp), ZIP</strong> (Max: 35 Mo)
                        </small>
                    </div>
                </div>

                <!-- Notes / Description -->
                <div class="col-md-12">
                    <label class="form-label small fw-bold text-slate-700">Description / Instructions (Optionnel)</label>
                    <textarea name="description" rows="2" class="form-control form-control-custom" placeholder="Précisions à l'attention de l'employé ou du Boss..."></textarea>
                </div>

                <div class="col-md-12 text-end">
                    <button type="button" class="btn btn-outline-secondary me-2" data-bs-toggle="collapse" data-bs-target="#uploadSection">Annuler</button>
                    <button type="submit" class="btn btn-grad px-4"><i class="fa-solid fa-paper-plane me-1"></i>Envoyer le Document</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- BARRE DE RECHERCHE ET FILTRES -->
<div class="glass-card p-3 mb-4">
    <form method="GET" action="documents.php" class="row g-2 align-items-center">
        <!-- Recherche par mot clé -->
        <div class="col-12 col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0" style="border-radius: 10px 0 0 10px;">
                    <i class="fa-solid fa-magnifying-glass text-muted"></i>
                </span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Rechercher par titre, nom de fichier..." value="<?= htmlspecialchars($search) ?>" style="border-radius: 0 10px 10px 0;">
            </div>
        </div>

        <!-- Filtre Catégorie -->
        <div class="col-6 col-md-3">
            <select name="category" class="form-select" onchange="this.form.submit()" style="border-radius: 10px;">
                <option value="ALL">Toutes les catégories</option>
                <option value="Inventaire / Stock" <?= $category_filter === 'Inventaire / Stock' ? 'selected' : '' ?>>📊 Inventaire / Stock</option>
                <option value="Comptabilité / Finances" <?= $category_filter === 'Comptabilité / Finances' ? 'selected' : '' ?>>💰 Comptabilité / Finances</option>
                <option value="Administratif" <?= $category_filter === 'Administratif' ? 'selected' : '' ?>>📑 Administratif</option>
                <option value="Boutique / Ventes" <?= $category_filter === 'Boutique / Ventes' ? 'selected' : '' ?>>🛒 Boutique / Ventes</option>
                <option value="Ressources Humaines" <?= $category_filter === 'Ressources Humaines' ? 'selected' : '' ?>>👥 Personnel / RH</option>
                <option value="Général" <?= $category_filter === 'Général' ? 'selected' : '' ?>>📁 Général</option>
            </select>
        </div>

        <!-- Filtre Type de Fichier -->
        <div class="col-6 col-md-3">
            <select name="type" class="form-select" onchange="this.form.submit()" style="border-radius: 10px;">
                <option value="ALL" <?= $type_filter === 'ALL' ? 'selected' : '' ?>>Tous les types de fichiers</option>
                <option value="PDF" <?= $type_filter === 'PDF' ? 'selected' : '' ?>>📄 PDF</option>
                <option value="EXCEL" <?= $type_filter === 'EXCEL' ? 'selected' : '' ?>>📊 Tableurs (Excel / ODS / CSV)</option>
                <option value="WORD" <?= $type_filter === 'WORD' ? 'selected' : '' ?>>📝 Documents Word</option>
                <option value="IMAGE" <?= $type_filter === 'IMAGE' ? 'selected' : '' ?>>🖼️ Images & Scans</option>
            </select>
        </div>

        <div class="col-12 col-md-1">
            <a href="documents.php" class="btn btn-outline-secondary w-100" style="border-radius: 10px;" title="Réinitialiser les filtres">
                <i class="fa-solid fa-rotate-left"></i>
            </a>
        </div>
    </form>
</div>

<!-- LISTE DES DOCUMENTS PARTAGÉS -->
<div class="glass-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0 text-slate-800">
            <i class="fa-solid fa-list-check text-primary me-2"></i>Documents Disponibles
        </h5>
        <span class="badge bg-secondary-subtle text-secondary py-2 px-3 fw-bold rounded-pill">
            <?= count($documents) ?> document(s) trouvé(s)
        </span>
    </div>

    <?php if (empty($documents)): ?>
        <div class="text-center py-5 text-secondary">
            <i class="fa-solid fa-folder-open fa-3x mb-3 text-muted"></i>
            <h6 class="fw-bold">Aucun document partagé pour le moment</h6>
            <p class="small text-muted mb-3">Utilisez le bouton ci-dessus pour envoyer et partager votre premier document.</p>
            <button class="btn btn-outline-primary btn-sm px-3" data-bs-toggle="collapse" data-bs-target="#uploadSection">
                <i class="fa-solid fa-plus me-1"></i>Charger un document maintenant
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-custom align-middle">
                <thead>
                    <tr>
                        <th style="width: 50px;">Type</th>
                        <th>Document & Objet</th>
                        <th>Catégorie</th>
                        <th>Taille</th>
                        <th>Expéditeur (Auteur)</th>
                        <th>Destinataire</th>
                        <th>Date & Heure</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <?php 
                            $vis = getDocVisual($doc['file_ext']); 
                            $can_delete = ($user_role === 'admin' || $doc['user_id'] == $user_id);
                            $can_preview = in_array(strtolower($doc['file_ext']), ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt']);
                        ?>
                        <tr>
                            <!-- Icône Type de Fichier -->
                            <td class="text-center">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: <?= $vis['bg'] ?>; color: <?= $vis['color'] ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                    <i class="fa-solid <?= $vis['icon'] ?>"></i>
                                </div>
                            </td>

                            <!-- Titre & Détails du document -->
                            <td>
                                <div class="fw-bold text-slate-800 fs-6"><?= htmlspecialchars($doc['title']) ?></div>
                                <div class="small text-secondary font-monospace"><?= htmlspecialchars($doc['original_name']) ?></div>
                                <?php if (!empty($doc['description'])): ?>
                                    <small class="text-muted d-block mt-1 fst-italic">"<?= htmlspecialchars($doc['description']) ?>"</small>
                                <?php endif; ?>
                            </td>

                            <!-- Catégorie -->
                            <td>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($doc['category']) ?></span>
                            </td>

                            <!-- Taille -->
                            <td>
                                <span class="small fw-semibold text-secondary"><?= formatDocSize($doc['file_size']) ?></span>
                            </td>

                            <!-- Auteur / Expéditeur -->
                            <td>
                                <div class="fw-semibold text-slate-700">
                                    <i class="fa-solid fa-user-circle me-1 text-primary"></i><?= htmlspecialchars($doc['author_name']) ?>
                                </div>
                                <span class="badge bg-<?= $doc['author_role'] === 'admin' ? 'primary' : 'secondary' ?>-subtle text-<?= $doc['author_role'] === 'admin' ? 'primary' : 'secondary' ?>" style="font-size: 0.65rem;">
                                    <?= $doc['author_role'] === 'admin' ? 'Direction (Boss)' : 'Employé' ?>
                                </span>
                            </td>

                            <!-- Destinataire -->
                            <td>
                                <?php if (!empty($doc['target_shop_name'])): ?>
                                    <span class="badge bg-info-subtle text-info fw-semibold">
                                        <i class="fa-solid fa-store me-1"></i><?= htmlspecialchars($doc['target_shop_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success fw-semibold">
                                        <i class="fa-solid fa-globe me-1"></i>Tous les dépôts
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Date & Heure -->
                            <td>
                                <div class="small text-slate-700 fw-semibold"><?= date('d/m/Y', strtotime($doc['created_at'])) ?></div>
                                <small class="text-muted"><?= date('H:i', strtotime($doc['created_at'])) ?></small>
                            </td>

                            <!-- Actions : Visualiser / Télécharger / Supprimer -->
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <!-- Visualiser (si PDF ou Image) -->
                                    <?php if ($can_preview): ?>
                                        <a href="documents.php?view=<?= $doc['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm py-1 px-2.5" style="border-radius: 8px;" title="Consulter dans le navigateur">
                                            <i class="fa-solid fa-eye me-1"></i>Voir
                                        </a>
                                    <?php endif; ?>

                                    <!-- Télécharger -->
                                    <a href="documents.php?download=<?= $doc['id'] ?>" class="btn btn-outline-success btn-sm py-1 px-2.5" style="border-radius: 8px;" title="Télécharger le document d'origine">
                                        <i class="fa-solid fa-download me-1"></i>Télécharger
                                    </a>

                                    <!-- Supprimer -->
                                    <?php if ($can_delete): ?>
                                        <form method="POST" action="documents.php" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer définitivement le document : <?= addslashes($doc['title']) ?> ?');">
                                            <input type="hidden" name="action_delete_doc" value="1">
                                            <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-1 px-2" style="border-radius: 8px;" title="Supprimer">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination Documents -->
<?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 mb-4 no-print">
        <div class="small text-secondary fw-semibold">
            Affichage de <?= min($total_records, $offset + 1) ?> à <?= min($total_records, $offset + count($documents)) ?> sur <strong><?= number_format($total_records, 0, '.', ' ') ?></strong> document(s)
        </div>
        <nav aria-label="Pagination documents">
            <ul class="pagination pagination-sm mb-0">
                <?php
                $q_doc = $_GET;
                if ($current_page > 1):
                    $q_doc['page'] = $current_page - 1;
                    echo '<li class="page-item"><a class="page-link" href="documents.php?' . http_build_query($q_doc) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                else:
                    echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                endif;

                $st_p = max(1, $current_page - 2);
                $en_p = min($total_pages, $current_page + 2);

                for ($p = $st_p; $p <= $en_p; $p++):
                    $q_doc['page'] = $p;
                    $act = ($p == $current_page) ? 'active' : '';
                    echo '<li class="page-item ' . $act . '"><a class="page-link" href="documents.php?' . http_build_query($q_doc) . '">' . $p . '</a></li>';
                endfor;

                if ($current_page < $total_pages):
                    $q_doc['page'] = $current_page + 1;
                    echo '<li class="page-item"><a class="page-link" href="documents.php?' . http_build_query($q_doc) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
                else:
                    echo '<li class="page-item disabled"><span class="page-link">Suivant <i class="fa-solid fa-chevron-right"></i></span></li>';
                endif;
                ?>
            </ul>
        </nav>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
?>
