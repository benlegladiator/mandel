<?php
require_once __DIR__ . '/includes/header.php';

// Bloquer l'accès si ce n'est pas un administrateur
if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut gérer les utilisateurs.";
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

// Traitement de l'Ajout / Modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user'])) {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $username = trim($_POST['username']);
        $name = trim($_POST['name']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role']);
        $status = trim($_POST['status']);
        $shop_id = isset($_POST['shop_id']) && !empty($_POST['shop_id']) ? intval($_POST['shop_id']) : null;

        // Si l'utilisateur est admin, pas de boutique assignée
        if ($role === 'admin') {
            $shop_id = null;
        }

        if (empty($username) || empty($name) || ($id === 0 && empty($password))) {
            $error = "Veuillez remplir tous les champs obligatoires.";
        } else {
            try {
                if ($id > 0) {
                    // Modification
                    if (!empty($password)) {
                        // Si le mot de passe est réinitialisé
                        $hashed_pass = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, password = ?, role = ?, shop_id = ?, status = ? WHERE id = ?");
                        $stmt->execute([$username, $name, $hashed_pass, $role, $shop_id, $status, $id]);
                    } else {
                        // Sans changer le mot de passe
                        $stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, role = ?, shop_id = ?, status = ? WHERE id = ?");
                        $stmt->execute([$username, $name, $role, $shop_id, $status, $id]);
                    }
                    $message = "L'utilisateur a été mis à jour avec succès.";
                } else {
                    // Ajout
                    $hashed_pass = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, name, password, role, shop_id, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $name, $hashed_pass, $role, $shop_id, $status]);
                    $message = "Le compte utilisateur a été créé avec succès.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Ce nom d'utilisateur existe déjà.";
                } else {
                    $error = "Une erreur est survenue : " . $e->getMessage();
                }
            }
        }
    }

    // Suppression permanente de l'utilisateur
    if (isset($_POST['delete_user'])) {
        $id = intval($_POST['delete_user']);
        
        // Empêcher l'admin connecté de se supprimer lui-même
        if ($id === $_SESSION['user_id']) {
            $error = "Vous ne pouvez pas supprimer votre propre compte.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $active_admin_id = $_SESSION['user_id'];
                
                // Transférer la responsabilité des enregistrements liés (pour garder l'intégrité référentielle)
                $pdo->prepare("UPDATE expenses SET user_id = ? WHERE user_id = ?")->execute([$active_admin_id, $id]);
                $pdo->prepare("UPDATE cash_transactions SET user_id = ? WHERE user_id = ?")->execute([$active_admin_id, $id]);
                $pdo->prepare("UPDATE orders SET user_id = ? WHERE user_id = ?")->execute([$active_admin_id, $id]);
                $pdo->prepare("UPDATE stock_transfers SET user_id = ? WHERE user_id = ?")->execute([$active_admin_id, $id]);
                
                // Supprimer définitivement l'utilisateur
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                $message = "L'utilisateur a été supprimé définitivement avec succès.";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Une erreur est survenue lors de la suppression : " . $e->getMessage();
            }
        }
    }
}

// Récupérer l'utilisateur en cours d'édition
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_user = $stmt->fetch();
}

// Récupérer la liste des boutiques pour le formulaire
$shops = $pdo->query("SELECT id, name FROM shops ORDER BY name ASC")->fetchAll();

// Liste de tous les utilisateurs (avec jointure de boutique)
$stmt = $pdo->query("
    SELECT u.*, s.name as shop_name 
    FROM users u 
    LEFT JOIN shops s ON u.shop_id = s.id 
    ORDER BY u.role ASC, u.name ASC
");
$users = $stmt->fetchAll();
?>

<!-- Title -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-users-gear"></i></span> 
            Gestion des Utilisateurs & Vendeurs
        </h1>
        <span class="text-secondary small fw-semibold">Configuration des accès boutique</span>
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

<div class="row g-4">
    <!-- Formulaire d'Ajout / Modification (Gauche) -->
    <div class="col-12 col-md-4">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-4 text-slate-800">
                <i class="fa-solid <?= $edit_user ? 'fa-user-pen text-warning' : 'fa-user-plus text-primary' ?> me-2"></i>
                <?= $edit_user ? "Modifier l'utilisateur" : "Créer un compte Vendeur" ?>
            </h5>
            
            <form action="users.php" method="POST" onsubmit="return confirm('Voulez-vous vraiment enregistrer ces informations utilisateur ?');">
                <?php if ($edit_user): ?>
                    <input type="hidden" name="id" value="<?= $edit_user['id'] ?>">
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold text-secondary">Nom Complet du vendeur</label>
                    <input type="text" class="form-control form-control-custom" id="name" name="name" value="<?= $edit_user ? htmlspecialchars($edit_user['name']) : '' ?>" placeholder="Ex: Jean Kamdem" required>
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold text-secondary">Identifiant de connexion</label>
                    <input type="text" class="form-control form-control-custom" id="username" name="username" value="<?= $edit_user ? htmlspecialchars($edit_user['username']) : '' ?>" placeholder="Ex: jean_vendeur" required>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold text-secondary">Mot de passe</label>
                    <input type="password" class="form-control form-control-custom" id="password" name="password" placeholder="<?= $edit_user ? 'Laissez vide pour ne pas modifier' : 'Mot de passe de connexion' ?>" <?= $edit_user ? '' : 'required' ?>>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label fw-semibold text-secondary">Rôle / Privilèges</label>
                    <select class="form-select form-control-custom" id="role" name="role" required>
                        <option value="seller" <?= $edit_user && $edit_user['role'] === 'seller' ? 'selected' : '' ?>>Vendeur (Accès Limité)</option>
                        <option value="admin" <?= $edit_user && $edit_user['role'] === 'admin' ? 'selected' : '' ?>>Administrateur (Accès Complet)</option>
                    </select>
                </div>

                <div class="mb-3" id="shop-select-container">
                    <label for="shop_id" class="form-label fw-semibold text-secondary">Dépôt / Boutique assignée</label>
                    <select class="form-select form-control-custom" id="shop_id" name="shop_id">
                        <option value="">-- Aucun dépôt (Accès global) --</option>
                        <?php foreach ($shops as $sh): ?>
                            <option value="<?= $sh['id'] ?>" <?= $edit_user && $edit_user['shop_id'] == $sh['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label for="status" class="form-label fw-semibold text-secondary">État du Compte</label>
                    <select class="form-select form-control-custom" id="status" name="status" required>
                        <option value="active" <?= $edit_user && $edit_user['status'] === 'active' ? 'selected' : '' ?>>Actif (Accès autorisé)</option>
                        <option value="inactive" <?= $edit_user && $edit_user['status'] === 'inactive' ? 'selected' : '' ?>>Inactif (Accès révoqué)</option>
                    </select>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" name="save_user" class="btn btn-grad">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Enregistrer
                    </button>
                    <?php if ($edit_user): ?>
                        <a href="users.php" class="btn btn-outline-secondary" style="border-radius: 10px;">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des utilisateurs (Droite) -->
    <div class="col-12 col-md-8">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-users text-primary me-2"></i>Comptes Enregistrés</h5>
            
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Identifiant</th>
                            <th>Rôle</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900"><?= htmlspecialchars($u['name']) ?></div>
                                    <small class="text-secondary">Créé le : <?= date('d/m/Y', strtotime($u['created_at'])) ?></small>
                                </td>
                                <td><span class="badge bg-light text-dark fw-normal"><?= htmlspecialchars($u['username']) ?></span></td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge bg-success-subtle text-success fw-bold"><i class="fa-solid fa-user-shield me-1"></i>Admin</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary fw-bold"><i class="fa-solid fa-user-tag me-1"></i>Vendeur</span>
                                        <?php if (!empty($u['shop_name'])): ?>
                                            <div class="small text-secondary mt-1"><i class="fa-solid fa-store me-1"></i><?= htmlspecialchars($u['shop_name']) ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="badge bg-success text-white">Actif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger text-white">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-outline-warning btn-sm" style="border-radius: 8px;">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </a>
                                        <form action="users.php" method="POST" onsubmit="return confirm('Voulez-vous vraiment SUPPRIMER DÉFINITIVEMENT cet utilisateur ? Cette action est irréversible.');" style="display:inline-block;">
                                            <button type="submit" name="delete_user" value="<?= $u['id'] ?>" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;" <?= $u['id'] === $_SESSION['user_id'] ? 'disabled' : '' ?> title="Supprimer définitivement">
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
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const shopContainer = document.getElementById('shop-select-container');
    const shopSelect = document.getElementById('shop_id');
    
    function toggleShopSelect() {
        if (roleSelect.value === 'seller') {
            shopContainer.style.display = 'block';
            shopSelect.setAttribute('required', 'required');
        } else {
            shopContainer.style.display = 'none';
            shopSelect.removeAttribute('required');
            shopSelect.value = ''; // Reset value for admin
        }
    }
    
    roleSelect.addEventListener('change', toggleShopSelect);
    toggleShopSelect(); // Run on load
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
