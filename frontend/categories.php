<?php
require_once __DIR__ . '/includes/header.php';

// Bloquer l'accès si ce n'est pas un administrateur
if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut gérer les catégories.";
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

// Traitement de l'Ajout / Modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_category'])) {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);

        if (empty($name)) {
            $error = "Le nom de la catégorie est obligatoire.";
        } else {
            try {
                if ($id > 0) {
                    // Modification
                    $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $id]);
                    $message = "La catégorie a été mise à jour avec succès.";
                } else {
                    // Ajout
                    $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                    $stmt->execute([$name, $description]);
                    $message = "La catégorie a été créée avec succès.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Cette catégorie existe déjà.";
                } else {
                    $error = "Une erreur est survenue : " . $e->getMessage();
                }
            }
        }
    }

    // Traitement de la suppression
    if (isset($_POST['delete_category'])) {
        $id = intval($_POST['delete_category']);
        try {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $message = "La catégorie a été supprimée avec succès.";
        } catch (PDOException $e) {
            $error = "Impossible de supprimer cette catégorie car elle contient des produits associés.";
        }
    }
}

// Récupérer la catégorie en cours d'édition
$edit_cat = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_cat = $stmt->fetch();
}

// Liste des catégories avec le compte des produits associés
$stmt = $pdo->query("
    SELECT c.*, COUNT(p.id) as product_count 
    FROM categories c 
    LEFT JOIN products p ON c.id = p.category_id 
    GROUP BY c.id 
    ORDER BY c.name ASC
");
$categories = $stmt->fetchAll();
?>

<!-- Inner Navigation Sub-tabs -->
<ul class="nav nav-tabs mb-4 border-bottom-2">
    <li class="nav-item">
        <a class="nav-link text-secondary fw-semibold" href="products.php">
            <i class="fa-solid fa-list-ul me-1"></i>Catalogue des Produits
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link active fw-bold text-success border-success" href="categories.php">
            <i class="fa-solid fa-tags me-1"></i>Familles d'Articles
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-secondary fw-semibold" href="expense-categories.php">
            <i class="fa-solid fa-receipt me-1"></i>Catégories de Dépenses
        </a>
    </li>
</ul>


<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-tags"></i></span> 
            Gestion des Catégories (Familles)
        </h1>
        <span class="text-secondary small fw-semibold">Organisation du catalogue d'articles</span>
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
                <i class="fa-solid <?= $edit_cat ? 'fa-pen-to-square text-warning' : 'fa-plus text-primary' ?> me-2"></i>
                <?= $edit_cat ? "Modifier la catégorie" : "Ajouter une catégorie" ?>
            </h5>
            
            <form action="categories.php" method="POST">
                <?php if ($edit_cat): ?>
                    <input type="hidden" name="id" value="<?= $edit_cat['id'] ?>">
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold text-secondary">Nom de la catégorie</label>
                    <input type="text" class="form-control form-control-custom" id="name" name="name" value="<?= $edit_cat ? htmlspecialchars($edit_cat['name']) : '' ?>" placeholder="Ex: Cahiers, Stylos, Offset A2..." required>
                </div>
                
                <div class="mb-4">
                    <label for="description" class="form-label fw-semibold text-secondary">Description</label>
                    <textarea class="form-control form-control-custom" id="description" name="description" rows="3" placeholder="Description facultative..."><?= $edit_cat ? htmlspecialchars($edit_cat['description']) : '' ?></textarea>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" name="save_category" class="btn btn-grad">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Enregistrer
                    </button>
                    <?php if ($edit_cat): ?>
                        <a href="categories.php" class="btn btn-outline-secondary" style="border-radius: 10px;">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des catégories existantes (Droite) -->
    <div class="col-12 col-md-8">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-list-ul text-primary me-2"></i>Catégories Enregistrées</h5>
            
            <?php if (empty($categories)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="fa-regular fa-folder-open fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">Aucune catégorie n'est encore enregistrée.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle">
                        <thead>
                            <tr>
                                <th># ID</th>
                                <th>Nom de la catégorie</th>
                                <th>Description</th>
                                <th class="text-center">Nbr Produits</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><span class="text-secondary fw-semibold">#<?= $cat['id'] ?></span></td>
                                    <td class="fw-bold text-slate-900"><?= htmlspecialchars($cat['name']) ?></td>
                                    <td class="text-secondary text-truncate" style="max-width: 250px;"><?= htmlspecialchars($cat['description'] ?? '') ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary-subtle text-primary py-2 px-3 fw-bold rounded-pill">
                                            <?= $cat['product_count'] ?> art.
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <a href="categories.php?edit=<?= $cat['id'] ?>" class="btn btn-outline-warning btn-sm" style="border-radius: 8px;">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                            <form action="categories.php" method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette catégorie ?');" style="display:inline-block;">
                                                <button type="submit" name="delete_category" value="<?= $cat['id'] ?>" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;">
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
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
