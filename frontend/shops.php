<?php
require_once __DIR__ . '/includes/header.php';

// Sécurité : Seul l'administrateur a accès
if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut gérer les dépôts et boutiques.";
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

// Traitement du formulaire d'ajout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_shop'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);

    if (empty($name)) {
        $error = "Le nom de la boutique est obligatoire.";
    } else {
        $is_depot = isset($_POST['is_depot']) ? 1 : 0;
        try {
            if ($id > 0) {
                // Modification
                $stmt = $pdo->prepare("UPDATE shops SET name = ?, address = ?, phone = ?, is_depot = ? WHERE id = ?");
                $stmt->execute([$name, $address, $phone, $is_depot, $id]);
                $message = "Boutique mise à jour avec succès.";
            } else {
                // Ajout
                $stmt = $pdo->prepare("INSERT INTO shops (name, address, phone, is_depot) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $address, $phone, $is_depot]);
                $message = "Boutique/Dépôt créé avec succès.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "Cette boutique existe déjà.";
            } else {
                $error = "Une erreur est survenue : " . $e->getMessage();
            }
        }
    }
}

// Traitement de la suppression
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("DELETE FROM shops WHERE id = ?");
        $stmt->execute([$del_id]);
        $message = "Boutique supprimée avec succès.";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = "Impossible de supprimer cette boutique car elle contient des transactions, du stock ou des utilisateurs liés.";
        } else {
            $error = "Erreur de suppression : " . $e->getMessage();
        }
    }
}

// Récupérer la boutique pour édition
$edit_shop = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_shop = $stmt->fetch();
}

// Récupérer toutes les boutiques avec statistiques globales
$stmt = $pdo->query("
    SELECT s.*, 
           COALESCE(SUM(st.quantity), 0) as total_items, 
           COALESCE(SUM(st.quantity * p.price_purchase), 0) as total_value_purchase,
           COALESCE(SUM(st.quantity * p.price_sale), 0) as total_value_sale
    FROM shops s
    LEFT JOIN product_stock st ON s.id = st.shop_id
    LEFT JOIN products p ON st.product_id = p.id
    GROUP BY s.id
    ORDER BY s.name ASC
");
$shops_list = $stmt->fetchAll();
?>

<!-- Title -->
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-baseline flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-store"></i></span> 
            Gestion des Boutiques & Dépôts
        </h1>
        <span class="text-secondary small fw-semibold">Administration des points de vente</span>
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
    <!-- Formulaire d'Ajout (Gauche) -->
    <div class="col-12 col-lg-4">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-4 text-slate-800">
                <i class="fa-solid <?= $edit_shop ? 'fa-pen-to-square text-warning' : 'fa-plus text-primary' ?> me-2"></i>
                <?= $edit_shop ? "Modifier la Boutique" : "Ajouter une Boutique" ?>
            </h5>
            
            <form action="shops.php" method="POST" onsubmit="return confirm('Voulez-vous vraiment enregistrer cette boutique ?');">
                <?php if ($edit_shop): ?>
                    <input type="hidden" name="id" value="<?= $edit_shop['id'] ?>">
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold text-secondary">Nom de la boutique / Dépôt</label>
                    <input type="text" class="form-control form-control-custom" id="name" name="name" value="<?= $edit_shop ? htmlspecialchars($edit_shop['name']) : '' ?>" placeholder="Ex: Boutique B, Dépôt Akwa" required>
                </div>
                
                <div class="mb-3">
                    <label for="phone" class="form-label fw-semibold text-secondary">Téléphone de contact</label>
                    <input type="text" class="form-control form-control-custom" id="phone" name="phone" value="<?= $edit_shop ? htmlspecialchars($edit_shop['phone'] ?? '') : '' ?>" placeholder="Ex: +237 6xx xxx xxx">
                </div>

                <div class="mb-4">
                    <label for="address" class="form-label fw-semibold text-secondary">Adresse physique</label>
                    <input type="text" class="form-control form-control-custom" id="address" name="address" value="<?= $edit_shop ? htmlspecialchars($edit_shop['address'] ?? '') : '' ?>" placeholder="Ex: Akwa, Douala">
                </div>
                
                <div class="mb-4 form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_depot" name="is_depot" value="1" <?= ($edit_shop && $edit_shop['is_depot']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold text-dark" for="is_depot">Ceci est une Usine/Dépôt (Pas de vente client)</label>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" name="save_shop" class="btn btn-grad">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Enregistrer
                    </button>
                    <?php if ($edit_shop): ?>
                        <a href="shops.php" class="btn btn-outline-secondary" style="border-radius: 10px;">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des Boutiques avec statistiques (Droite) -->
    <div class="col-12 col-lg-8">
        <div class="glass-card p-4">
            <h5 class="fw-bold mb-3 text-slate-800"><i class="fa-solid fa-list-ul text-primary me-2"></i>Boutiques & Dépôts Enregistrés</h5>
            
            <div class="table-responsive">
                <table class="table table-custom align-middle">
                    <thead>
                        <tr>
                            <th>Boutique</th>
                            <th>Contact / Téléphone</th>
                            <th class="text-center">Total Articles</th>
                            <th class="text-end">Valeur Stock (Achat)</th>
                            <th class="text-end">Valeur Stock (Vente)</th>
                            <th class="text-end no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shops_list as $sh): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">
                                        <?= htmlspecialchars($sh['name']) ?>
                                        <?php if ($sh['is_depot']): ?>
                                            <span class="badge bg-warning ms-1 text-dark" style="font-size: 0.65rem;">USINE/DÉPÔT</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-secondary"><?= htmlspecialchars($sh['address'] ?: 'Adresse non renseignée') ?></small>
                                </td>
                                <td><?= htmlspecialchars($sh['phone'] ?: '-') ?></td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary py-2 px-3 fw-bold rounded-pill">
                                        <?= number_format($sh['total_items'], 0, '.', ' ') ?> u.
                                    </span>
                                </td>
                                <td class="text-end fw-semibold text-slate-700">
                                    <?= number_format($sh['total_value_purchase'], 0, '.', ' ') ?> FCFA
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?= number_format($sh['total_value_sale'], 0, '.', ' ') ?> FCFA
                                </td>
                                <td class="text-end no-print">
                                    <a href="shops.php?edit=<?= $sh['id'] ?>" class="btn btn-outline-warning btn-sm" style="border-radius: 8px;" title="Modifier">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <a href="shops.php?delete=<?= $sh['id'] ?>" class="btn btn-outline-danger btn-sm ms-1" style="border-radius: 8px;" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette boutique ? Cette action est irréversible.');">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
