<?php
require_once __DIR__ . '/includes/header.php';

// Sécurité : Seul l'administrateur a accès
if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut gérer l'annuaire des contacts.";
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'clients';

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Ajouter / Modifier un Client
    if (isset($_POST['save_client'])) {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $nui = trim($_POST['nui']);
        $rc = trim($_POST['rc']);
        $bp = trim($_POST['bp']);
        $category = trim($_POST['category']);

        if (empty($name)) {
            $error = "Le nom du client est obligatoire.";
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE clients SET name = ?, phone = ?, email = ?, address = ?, nui = ?, rc = ?, bp = ?, category = ? WHERE id = ?");
                    $stmt->execute([$name, $phone, $email, $address, $nui, $rc, $bp, $category, $id]);
                    $message = "Client mis à jour avec succès.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO clients (name, phone, email, address, nui, rc, bp, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $email, $address, $nui, $rc, $bp, $category]);
                    $message = "Client créé avec succès.";
                }
                $active_tab = 'clients';
            } catch (PDOException $e) {
                $error = "Une erreur est survenue : " . $e->getMessage();
            }
        }
    }

    // 2. Ajouter / Modifier un Fournisseur
    if (isset($_POST['save_supplier'])) {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = trim($_POST['name']);
        $contact_name = trim($_POST['contact_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $nui = trim($_POST['nui']);
        $rc = trim($_POST['rc']);
        $bp = trim($_POST['bp']);
        $category = trim($_POST['category']);

        if (empty($name)) {
            $error = "Le nom du fournisseur est obligatoire.";
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE suppliers SET name = ?, contact_name = ?, phone = ?, email = ?, address = ?, nui = ?, rc = ?, bp = ?, category = ? WHERE id = ?");
                    $stmt->execute([$name, $contact_name, $phone, $email, $address, $nui, $rc, $bp, $category, $id]);
                    $message = "Fournisseur mis à jour avec succès.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_name, phone, email, address, nui, rc, bp, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $contact_name, $phone, $email, $address, $nui, $rc, $bp, $category]);
                    $message = "Fournisseur créé avec succès.";
                }
                $active_tab = 'suppliers';
            } catch (PDOException $e) {
                $error = "Une erreur est survenue : " . $e->getMessage();
            }
        }
    }

    // 3. Suppression Client
    if (isset($_POST['delete_client'])) {
        $id = intval($_POST['delete_client']);
        try {
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Client supprimé avec succès.";
            $active_tab = 'clients';
        } catch (PDOException $e) {
            $error = "Impossible de supprimer ce client car des factures y sont associées.";
        }
    }

    // 4. Suppression Fournisseur
    if (isset($_POST['delete_supplier'])) {
        $id = intval($_POST['delete_supplier']);
        try {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $message = "Fournisseur supprimé avec succès.";
            $active_tab = 'suppliers';
        } catch (PDOException $e) {
            $error = "Impossible de supprimer ce fournisseur car des commandes de ravitaillement y sont associées.";
        }
    }
}

// Récupération de l'édition Client ou Fournisseur
$edit_client = null;
if (isset($_GET['edit_client'])) {
    $edit_id = intval($_GET['edit_client']);
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_client = $stmt->fetch();
    $active_tab = 'clients';
}

$edit_supplier = null;
if (isset($_GET['edit_supplier'])) {
    $edit_id = intval($_GET['edit_supplier']);
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_supplier = $stmt->fetch();
    $active_tab = 'suppliers';
}

// Pagination Contacts Optimisée en SQL (Zéro surcharge RAM)
$per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 30;

// Filtres de recherche optionnels côté serveur
$search_client = isset($_GET['search_client']) ? trim($_GET['search_client']) : '';
$where_c = "WHERE 1=1";
$params_c = [];
if ($search_client !== '') {
    $where_c .= " AND (name LIKE :sc OR phone LIKE :sc OR email LIKE :sc OR address LIKE :sc)";
    $params_c[':sc'] = '%' . $search_client . '%';
}

$stmt_cnt_c = $pdo->prepare("SELECT COUNT(*) FROM clients $where_c");
$stmt_cnt_c->execute($params_c);
$total_clients = intval($stmt_cnt_c->fetchColumn() ?: 0);
$total_pages_clients = max(1, ceil($total_clients / $per_page));
$current_page_clients = ($active_tab === 'clients' && isset($_GET['page'])) ? max(1, min($total_pages_clients, intval($_GET['page']))) : 1;
$offset_clients = ($current_page_clients - 1) * $per_page;

$stmt_c = $pdo->prepare("SELECT * FROM clients $where_c ORDER BY name ASC LIMIT $per_page OFFSET $offset_clients");
$stmt_c->execute($params_c);
$clients = $stmt_c->fetchAll();

// Fournisseurs
$search_supplier = isset($_GET['search_supplier']) ? trim($_GET['search_supplier']) : '';
$where_s = "WHERE 1=1";
$params_s = [];
if ($search_supplier !== '') {
    $where_s .= " AND (name LIKE :ss OR phone LIKE :ss OR email LIKE :ss OR address LIKE :ss)";
    $params_s[':ss'] = '%' . $search_supplier . '%';
}

$stmt_cnt_s = $pdo->prepare("SELECT COUNT(*) FROM suppliers $where_s");
$stmt_cnt_s->execute($params_s);
$total_suppliers = intval($stmt_cnt_s->fetchColumn() ?: 0);
$total_pages_suppliers = max(1, ceil($total_suppliers / $per_page));
$current_page_suppliers = ($active_tab === 'suppliers' && isset($_GET['page'])) ? max(1, min($total_pages_suppliers, intval($_GET['page']))) : 1;
$offset_suppliers = ($current_page_suppliers - 1) * $per_page;

$stmt_s = $pdo->prepare("SELECT * FROM suppliers $where_s ORDER BY name ASC LIMIT $per_page OFFSET $offset_suppliers");
$stmt_s->execute($params_s);
$suppliers = $stmt_s->fetchAll();
?>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #printArea, #printArea * {
        visibility: visible;
    }
    #printArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<!-- Title -->
<div class="row mb-3 no-print">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-users"></i></span> 
            Annuaire des Contacts Légaux
        </h1>
        <button class="btn btn-outline-primary fw-bold" onclick="window.print();">
            <i class="fa-solid fa-print me-1"></i> Imprimer la liste
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-4" id="contactsTab" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link px-4 py-2 me-2 fw-bold <?= $active_tab === 'clients' ? 'active' : '' ?>" href="contacts.php?tab=clients">
            <i class="fa-solid fa-user-tag me-1"></i>Clients (Ventes)
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link px-4 py-2 fw-bold <?= $active_tab === 'suppliers' ? 'active' : '' ?>" href="contacts.php?tab=suppliers">
            <i class="fa-solid fa-truck-field me-1"></i>Fournisseurs (Ravitaillement)
        </a>
    </li>
</ul>

<!-- Tabs Content -->
<div class="tab-content">
    
    <!-- Tab 1: Clients -->
    <?php if ($active_tab === 'clients'): ?>
        <div class="row g-4">
            <!-- Formulaire Client (Gauche) -->
            <div class="col-12 col-md-4">
                <div class="glass-card p-4">
                    <h5 class="fw-bold mb-4 text-slate-800">
                        <i class="fa-solid <?= $edit_client ? 'fa-user-pen text-warning' : 'fa-user-plus text-primary' ?> me-2"></i>
                        <?= $edit_client ? "Modifier le client" : "Nouveau Client" ?>
                    </h5>
                    
                    <form action="contacts.php?tab=clients" method="POST" onsubmit="return confirm('Voulez-vous vraiment enregistrer ce client ?');">
                        <?php if ($edit_client): ?>
                            <input type="hidden" name="id" value="<?= $edit_client['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="c_name" class="form-label fw-semibold text-secondary">Raison Sociale / Nom</label>
                                <input type="text" class="form-control form-control-custom" id="c_name" name="name" value="<?= $edit_client ? htmlspecialchars($edit_client['name']) : '' ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label for="c_category" class="form-label fw-semibold text-secondary">Catégorie</label>
                                <select class="form-select form-control-custom" id="c_category" name="category">
                                    <option value="PARTICULIER" <?= ($edit_client && $edit_client['category'] === 'PARTICULIER') ? 'selected' : '' ?>>Particulier</option>
                                    <option value="PME" <?= ($edit_client && $edit_client['category'] === 'PME') ? 'selected' : '' ?>>PME / Entreprise</option>
                                    <option value="PUBLIC" <?= ($edit_client && $edit_client['category'] === 'PUBLIC') ? 'selected' : '' ?>>Public / État</option>
                                    <option value="AUTRE" <?= ($edit_client && $edit_client['category'] === 'AUTRE') ? 'selected' : '' ?>>Autre</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="c_nui" class="form-label fw-semibold text-secondary">NUI</label>
                                <input type="text" class="form-control form-control-custom" id="c_nui" name="nui" value="<?= $edit_client ? htmlspecialchars($edit_client['nui'] ?? '') : '' ?>" placeholder="Numéro Unique d'Identification">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="c_rc" class="form-label fw-semibold text-secondary">RC</label>
                                <input type="text" class="form-control form-control-custom" id="c_rc" name="rc" value="<?= $edit_client ? htmlspecialchars($edit_client['rc'] ?? '') : '' ?>" placeholder="Registre du Commerce">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="c_phone" class="form-label fw-semibold text-secondary">Téléphone</label>
                                <input type="text" class="form-control form-control-custom" id="c_phone" name="phone" value="<?= $edit_client ? htmlspecialchars($edit_client['phone'] ?? '') : '' ?>" placeholder="Ex: +237 6xx xxx xxx">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="c_email" class="form-label fw-semibold text-secondary">Email</label>
                                <input type="email" class="form-control form-control-custom" id="c_email" name="email" value="<?= $edit_client ? htmlspecialchars($edit_client['email'] ?? '') : '' ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-8 mb-4">
                                <label for="c_address" class="form-label fw-semibold text-secondary">Localisation / Adresse</label>
                                <input type="text" class="form-control form-control-custom" id="c_address" name="address" value="<?= $edit_client ? htmlspecialchars($edit_client['address'] ?? '') : '' ?>">
                            </div>
                            <div class="col-4 mb-4">
                                <label for="c_bp" class="form-label fw-semibold text-secondary">Boîte Postale</label>
                                <input type="text" class="form-control form-control-custom" id="c_bp" name="bp" value="<?= $edit_client ? htmlspecialchars($edit_client['bp'] ?? '') : '' ?>" placeholder="Ex: BP 123">
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" name="save_client" class="btn btn-grad">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Enregistrer
                            </button>
                            <?php if ($edit_client): ?>
                                <a href="contacts.php?tab=clients" class="btn btn-outline-secondary" style="border-radius: 10px;">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste Clients (Droite) -->
            <div class="col-12 col-md-8">
                <div class="glass-card p-4" id="printArea">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-address-book text-primary me-2"></i>Liste des Clients</h5>
                        <div class="d-flex gap-2 no-print">
                            <select id="clientCategoryFilter" class="form-select form-control-custom w-auto">
                                <option value="">Toutes les catégories</option>
                                <option value="PARTICULIER">Particulier</option>
                                <option value="PME">PME / Entreprise</option>
                                <option value="PUBLIC">Public / État</option>
                            </select>
                            <input type="text" id="clientSearchInput" class="form-control form-control-custom w-auto" placeholder="Rechercher...">
                        </div>
                    </div>
                    
                    <?php if (empty($clients)): ?>
                        <div class="text-center py-5 text-secondary">
                            <i class="fa-regular fa-user fa-3x mb-3 text-muted"></i>
                            <p class="mb-0">Aucun client enregistré.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-custom align-middle">
                                <thead>
                                    <tr>
                                        <th>Raison Sociale</th>
                                        <th>Catégorie</th>
                                        <th>NUI / RC</th>
                                        <th>Contact</th>
                                        <th>Localisation</th>
                                        <th class="text-end no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients as $client): ?>
                                        <tr class="client-row" data-category="<?= htmlspecialchars($client['category'] ?? 'PARTICULIER') ?>">
                                            <td class="fw-bold text-slate-900"><?= htmlspecialchars($client['name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($client['category'] ?? 'PARTICULIER') ?></span></td>
                                            <td class="small">
                                                NUI: <?= htmlspecialchars($client['nui'] ?: '-') ?><br>
                                                RC: <?= htmlspecialchars($client['rc'] ?: '-') ?>
                                            </td>
                                            <td class="small">
                                                <?= htmlspecialchars($client['phone'] ?: '-') ?><br>
                                                <span class="text-secondary"><?= htmlspecialchars($client['email'] ?: '') ?></span>
                                            </td>
                                            <td class="small">
                                                <?= htmlspecialchars($client['address'] ?: '-') ?><br>
                                                <?= $client['bp'] ? 'BP: ' . htmlspecialchars($client['bp']) : '' ?>
                                            </td>
                                            <td class="text-end no-print">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <a href="contacts.php?tab=clients&edit_client=<?= $client['id'] ?>" class="btn btn-outline-warning btn-sm" style="border-radius: 8px;">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                    <form action="contacts.php?tab=clients" method="POST" onsubmit="return confirm('Supprimer ce client ?');" style="display:inline-block;">
                                                        <button type="submit" name="delete_client" value="<?= $client['id'] ?>" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Clients -->
                        <?php if ($total_pages_clients > 1): ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                                <div class="small text-secondary fw-semibold">
                                    Affichage de <?= min($total_clients, $offset_clients + 1) ?> à <?= min($total_clients, $offset_clients + count($clients)) ?> sur <strong><?= $total_clients ?></strong> client(s)
                                </div>
                                <nav aria-label="Pagination clients">
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php
                                        $q_cl = $_GET;
                                        $q_cl['tab'] = 'clients';
                                        if ($current_page_clients > 1):
                                            $q_cl['page'] = $current_page_clients - 1;
                                            echo '<li class="page-item"><a class="page-link" href="contacts.php?' . http_build_query($q_cl) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                        else:
                                            echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                        endif;

                                        $st_p = max(1, $current_page_clients - 2);
                                        $en_p = min($total_pages_clients, $current_page_clients + 2);

                                        for ($p = $st_p; $p <= $en_p; $p++):
                                            $q_cl['page'] = $p;
                                            $act = ($p == $current_page_clients) ? 'active' : '';
                                            echo '<li class="page-item ' . $act . '"><a class="page-link" href="contacts.php?' . http_build_query($q_cl) . '">' . $p . '</a></li>';
                                        endfor;

                                        if ($current_page_clients < $total_pages_clients):
                                            $q_cl['page'] = $current_page_clients + 1;
                                            echo '<li class="page-item"><a class="page-link" href="contacts.php?' . http_build_query($q_cl) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
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
    <?php endif; ?>

    <!-- Tab 2: Suppliers -->
    <?php if ($active_tab === 'suppliers'): ?>
        <div class="row g-4">
            <!-- Formulaire Fournisseur (Gauche) -->
            <div class="col-12 col-md-4">
                <div class="glass-card p-4">
                    <h5 class="fw-bold mb-4 text-slate-800">
                        <i class="fa-solid <?= $edit_supplier ? 'fa-pen-to-square text-warning' : 'fa-plus text-primary' ?> me-2"></i>
                        <?= $edit_supplier ? "Modifier le fournisseur" : "Nouveau Fournisseur" ?>
                    </h5>
                    
                    <form action="contacts.php?tab=suppliers" method="POST" onsubmit="return confirm('Voulez-vous vraiment enregistrer ce fournisseur ?');">
                        <?php if ($edit_supplier): ?>
                            <input type="hidden" name="id" value="<?= $edit_supplier['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="s_name" class="form-label fw-semibold text-secondary">Raison Sociale</label>
                                <input type="text" class="form-control form-control-custom" id="s_name" name="name" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['name']) : '' ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label for="s_category" class="form-label fw-semibold text-secondary">Catégorie</label>
                                <select class="form-select form-control-custom" id="s_category" name="category">
                                    <option value="PME" <?= ($edit_supplier && $edit_supplier['category'] === 'PME') ? 'selected' : '' ?>>PME / Entreprise</option>
                                    <option value="PUBLIC" <?= ($edit_supplier && $edit_supplier['category'] === 'PUBLIC') ? 'selected' : '' ?>>Public / État</option>
                                    <option value="AUTRE" <?= ($edit_supplier && $edit_supplier['category'] === 'AUTRE') ? 'selected' : '' ?>>Autre</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="s_nui" class="form-label fw-semibold text-secondary">NUI</label>
                                <input type="text" class="form-control form-control-custom" id="s_nui" name="nui" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['nui'] ?? '') : '' ?>">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="s_rc" class="form-label fw-semibold text-secondary">RC</label>
                                <input type="text" class="form-control form-control-custom" id="s_rc" name="rc" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['rc'] ?? '') : '' ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <label for="s_contact" class="form-label fw-semibold text-secondary">Nom du Contact</label>
                                <input type="text" class="form-control form-control-custom" id="s_contact" name="contact_name" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['contact_name'] ?? '') : '' ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="s_phone" class="form-label fw-semibold text-secondary">Téléphone</label>
                                <input type="text" class="form-control form-control-custom" id="s_phone" name="phone" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['phone'] ?? '') : '' ?>">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="s_email" class="form-label fw-semibold text-secondary">Email</label>
                                <input type="email" class="form-control form-control-custom" id="s_email" name="email" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['email'] ?? '') : '' ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-8 mb-4">
                                <label for="s_address" class="form-label fw-semibold text-secondary">Localisation</label>
                                <input type="text" class="form-control form-control-custom" id="s_address" name="address" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['address'] ?? '') : '' ?>">
                            </div>
                            <div class="col-4 mb-4">
                                <label for="s_bp" class="form-label fw-semibold text-secondary">Boîte Postale</label>
                                <input type="text" class="form-control form-control-custom" id="s_bp" name="bp" value="<?= $edit_supplier ? htmlspecialchars($edit_supplier['bp'] ?? '') : '' ?>">
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" name="save_supplier" class="btn btn-grad">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Enregistrer
                            </button>
                            <?php if ($edit_supplier): ?>
                                <a href="contacts.php?tab=suppliers" class="btn btn-outline-secondary" style="border-radius: 10px;">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste Fournisseurs (Droite) -->
            <div class="col-12 col-md-8">
                <div class="glass-card p-4" id="printArea">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-truck text-primary me-2"></i>Liste des Fournisseurs</h5>
                        <div class="d-flex gap-2 no-print">
                            <select id="supplierCategoryFilter" class="form-select form-control-custom w-auto">
                                <option value="">Toutes les catégories</option>
                                <option value="PME">PME / Entreprise</option>
                                <option value="PUBLIC">Public / État</option>
                                <option value="AUTRE">Autre</option>
                            </select>
                            <input type="text" id="supplierSearchInput" class="form-control form-control-custom w-auto" placeholder="Rechercher...">
                        </div>
                    </div>
                    
                    <?php if (empty($suppliers)): ?>
                        <div class="text-center py-5 text-secondary">
                            <i class="fa-solid fa-truck-field fa-3x mb-3 text-muted"></i>
                            <p class="mb-0">Aucun fournisseur enregistré.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-custom align-middle">
                                <thead>
                                    <tr>
                                        <th>Raison Sociale</th>
                                        <th>Catégorie</th>
                                        <th>NUI / RC</th>
                                        <th>Contact</th>
                                        <th>Localisation</th>
                                        <th class="text-end no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <tr class="supplier-row" data-category="<?= htmlspecialchars($sup['category'] ?? 'PME') ?>">
                                            <td class="fw-bold text-slate-900"><?= htmlspecialchars($sup['name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($sup['category'] ?? 'PME') ?></span></td>
                                            <td class="small">
                                                NUI: <?= htmlspecialchars($sup['nui'] ?: '-') ?><br>
                                                RC: <?= htmlspecialchars($sup['rc'] ?: '-') ?>
                                            </td>
                                            <td class="small">
                                                Rep: <?= htmlspecialchars($sup['contact_name'] ?: '-') ?><br>
                                                <?= htmlspecialchars($sup['phone'] ?: '-') ?>
                                            </td>
                                            <td class="small">
                                                <?= htmlspecialchars($sup['address'] ?: '-') ?><br>
                                                <?= $sup['bp'] ? 'BP: ' . htmlspecialchars($sup['bp']) : '' ?>
                                            </td>
                                            <td class="text-end no-print">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <a href="contacts.php?tab=suppliers&edit_supplier=<?= $sup['id'] ?>" class="btn btn-outline-warning btn-sm" style="border-radius: 8px;">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </a>
                                                    <form action="contacts.php?tab=suppliers" method="POST" onsubmit="return confirm('Supprimer ce fournisseur ?');" style="display:inline-block;">
                                                        <button type="submit" name="delete_supplier" value="<?= $sup['id'] ?>" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Fournisseurs -->
                        <?php if ($total_pages_suppliers > 1): ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-3 no-print">
                                <div class="small text-secondary fw-semibold">
                                    Affichage de <?= min($total_suppliers, $offset_suppliers + 1) ?> à <?= min($total_suppliers, $offset_suppliers + count($suppliers)) ?> sur <strong><?= $total_suppliers ?></strong> fournisseur(s)
                                </div>
                                <nav aria-label="Pagination fournisseurs">
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php
                                        $q_sup = $_GET;
                                        $q_sup['tab'] = 'suppliers';
                                        if ($current_page_suppliers > 1):
                                            $q_sup['page'] = $current_page_suppliers - 1;
                                            echo '<li class="page-item"><a class="page-link" href="contacts.php?' . http_build_query($q_sup) . '"><i class="fa-solid fa-chevron-left"></i> Précédent</a></li>';
                                        else:
                                            echo '<li class="page-item disabled"><span class="page-link"><i class="fa-solid fa-chevron-left"></i> Précédent</span></li>';
                                        endif;

                                        $st_s = max(1, $current_page_suppliers - 2);
                                        $en_s = min($total_pages_suppliers, $current_page_suppliers + 2);

                                        for ($p = $st_s; $p <= $en_s; $p++):
                                            $q_sup['page'] = $p;
                                            $act_s = ($p == $current_page_suppliers) ? 'active' : '';
                                            echo '<li class="page-item ' . $act_s . '"><a class="page-link" href="contacts.php?' . http_build_query($q_sup) . '">' . $p . '</a></li>';
                                        endfor;

                                        if ($current_page_suppliers < $total_pages_suppliers):
                                            $q_sup['page'] = $current_page_suppliers + 1;
                                            echo '<li class="page-item"><a class="page-link" href="contacts.php?' . http_build_query($q_sup) . '">Suivant <i class="fa-solid fa-chevron-right"></i></a></li>';
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
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function filterTable(searchInputId, filterSelectId, rowClass) {
        const searchInput = document.getElementById(searchInputId);
        const filterSelect = document.getElementById(filterSelectId);
        const rows = document.querySelectorAll(rowClass);

        function doFilter() {
            const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const category = filterSelect ? filterSelect.value : '';

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const rowCategory = row.getAttribute('data-category');
                
                const matchesSearch = text.includes(query);
                const matchesCategory = category === '' || rowCategory === category;
                
                row.style.display = (matchesSearch && matchesCategory) ? '' : 'none';
            });
        }

        if (searchInput) searchInput.addEventListener('input', doFilter);
        if (filterSelect) filterSelect.addEventListener('change', doFilter);
    }

    filterTable('clientSearchInput', 'clientCategoryFilter', '.client-row');
    filterTable('supplierSearchInput', 'supplierCategoryFilter', '.supplier-row');
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
