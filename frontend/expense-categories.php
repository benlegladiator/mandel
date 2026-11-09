<?php
require_once __DIR__ . '/includes/header.php';

// Bloquer l'accès si l'utilisateur n'est pas connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user_role'] ?? 'seller';
$message = '';
$error = '';

// Traitement de l'Ajout / Modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_expense_category'])) {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = mb_strtoupper(trim($_POST['name']), 'UTF-8');
        $description = trim($_POST['description']);

        if (empty($name)) {
            $error = "Le nom de la catégorie de dépense est obligatoire.";
        } else {
            try {
                if ($id > 0) {
                    // Modification
                    $stmt = $pdo->prepare("UPDATE expense_categories SET name = ?, description = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $id]);
                    $message = "La catégorie de dépense a été mise à jour avec succès.";
                } else {
                    // Ajout
                    $stmt = $pdo->prepare("INSERT INTO expense_categories (name, description) VALUES (?, ?)");
                    $stmt->execute([$name, $description]);
                    $message = "La catégorie de dépense a été créée avec succès.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Cette catégorie de dépense existe déjà.";
                } else {
                    $error = "Une erreur est survenue : " . $e->getMessage();
                }
            }
        }
    }

    // Traitement de la suppression
    if (isset($_POST['delete_expense_category'])) {
        $id = intval($_POST['delete_expense_category']);
        
        // Vérifier si des dépenses utilisent cette catégorie
        $stmt_check = $pdo->prepare("
            SELECT COUNT(*) FROM expenses e
            JOIN expense_categories ec ON e.category = ec.name
            WHERE ec.id = ?
        ");
        $stmt_check->execute([$id]);
        $expense_count = $stmt_check->fetchColumn();

        if ($expense_count > 0) {
            $error = "Impossible de supprimer cette catégorie car $expense_count dépense(s) y sont associées.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE id = ?");
                $stmt->execute([$id]);
                $message = "La catégorie de dépense a été supprimée avec succès.";
            } catch (PDOException $e) {
                $error = "Erreur lors de la suppression : " . $e->getMessage();
            }
        }
    }
}

// Récupérer la catégorie en cours d'édition
$edit_cat = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_cat = $stmt->fetch();
}

// Liste des catégories avec le compte des dépenses associées
$stmt = $pdo->query("
    SELECT ec.*, COUNT(e.id) as expense_count, SUM(COALESCE(e.amount, 0)) as total_amount
    FROM expense_categories ec 
    LEFT JOIN expenses e ON ec.name = e.category 
    GROUP BY ec.id 
    ORDER BY ec.name ASC
");
$categories = $stmt->fetchAll();
?>

<!-- Sub-tabs navigation -->
<ul class="nav nav-tabs mb-4 border-bottom-2 no-print">
    <li class="nav-item">
        <a class="nav-link text-secondary fw-semibold" href="caisse.php">
            <i class="fa-solid fa-cash-register me-1"></i>Gestion de Caisse
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link active fw-bold text-danger border-danger" href="expense-categories.php">
            <i class="fa-solid fa-receipt me-1"></i>Catégories de Dépenses
        </a>
    </li>
    <?php if ($user_role === 'admin'): ?>
    <li class="nav-item">
        <a class="nav-link text-secondary fw-semibold" href="categories.php">
            <i class="fa-solid fa-tags me-1"></i>Familles d'Articles (Produits)
        </a>
    </li>
    <?php endif; ?>
</ul>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-danger"><i class="fa-solid fa-receipt"></i></span> 
            Catégories de Dépenses / Charges
        </h1>
        <span class="text-secondary small fw-semibold">Configuration des motifs de petits achats et charges d'exploitation</span>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius: 12px;" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Formulaire d'Ajout / Modification (Gauche) -->
    <div class="col-12 col-md-4">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-4 text-slate-800">
                <i class="fa-solid <?= $edit_cat ? 'fa-pen-to-square text-warning' : 'fa-plus text-danger' ?> me-2"></i>
                <?= $edit_cat ? "Modifier la catégorie" : "Ajouter une catégorie de dépense" ?>
            </h5>
            
            <form action="expense-categories.php" method="POST">
                <input type="hidden" name="save_expense_category" value="1">
                <?php if ($edit_cat): ?>
                    <input type="hidden" name="id" value="<?= $edit_cat['id'] ?>">
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold text-secondary">Nom de la catégorie *</label>
                    <input type="text" class="form-control form-control-custom" id="name" name="name" value="<?= $edit_cat ? htmlspecialchars($edit_cat['name']) : '' ?>" placeholder="Ex: LOYER, TAXI, EMBALLAGE..." required>
                    <div class="form-text">Ex: LOYER, SALAIRES, TAXI / TRANSPORT, ELECTRICITE, etc.</div>
                </div>
                
                <div class="mb-4">
                    <label for="description" class="form-label fw-semibold text-secondary">Description / Notes</label>
                    <textarea class="form-control form-control-custom" id="description" name="description" rows="3" placeholder="Description explicative facultative..."><?= $edit_cat ? htmlspecialchars($edit_cat['description']) : '' ?></textarea>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn <?= $edit_cat ? 'btn-warning' : 'btn-danger' ?> fw-bold py-2" style="border-radius: 10px;">
                        <i class="fa-solid <?= $edit_cat ? 'fa-check' : 'fa-plus' ?> me-1"></i>
                        <?= $edit_cat ? "Enregistrer les modifications" : "Créer la catégorie" ?>
                    </button>
                    <?php if ($edit_cat): ?>
                        <a href="expense-categories.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 10px;">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des Catégories de Dépenses (Droite) -->
    <div class="col-12 col-md-8">
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-slate-800 mb-0"><i class="fa-solid fa-list text-secondary me-2"></i>Liste des Catégories Disponibles (<?= count($categories) ?>)</h5>
                <a href="caisse.php" class="btn btn-sm btn-outline-danger" style="border-radius: 8px;">
                    <i class="fa-solid fa-cash-register me-1"></i>Saisir un petit achat
                </a>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nom de la catégorie</th>
                            <th>Description</th>
                            <th class="text-center">Nb. Dépenses</th>
                            <th class="text-end">Total Dépensé</th>
                            <th class="text-center" style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Aucune catégorie de dépense configurée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-slate-900"><?= htmlspecialchars($cat['name']) ?></span>
                                    </td>
                                    <td class="text-secondary small">
                                        <?= htmlspecialchars($cat['description'] ?: 'Aucune description') ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary-subtle text-secondary rounded-pill fw-bold">
                                            <?= $cat['expense_count'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-semibold text-danger">
                                        <?= number_format($cat['total_amount'], 0, '.', ' ') ?> FCFA
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="expense-categories.php?edit=<?= $cat['id'] ?>" class="btn btn-outline-primary" title="Modifier">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                            <?php if ($cat['expense_count'] == 0): ?>
                                                <form action="expense-categories.php" method="POST" class="d-inline" onsubmit="return confirm('Voulez-vous vraiment supprimer cette catégorie de dépense ?');">
                                                    <input type="hidden" name="delete_expense_category" value="<?= $cat['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="Supprimer">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-outline-secondary" disabled title="Utilisée dans <?= $cat['expense_count'] ?> dépense(s)">
                                                    <i class="fa-solid fa-lock"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
