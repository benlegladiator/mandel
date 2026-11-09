<?php
require_once __DIR__ . '/includes/header.php';

$current_role = strtolower(trim($_SESSION['user_role'] ?? 'seller'));
$is_admin = in_array($current_role, ['admin', 'boss', 'owner', 'superadmin']);

if (!$is_admin && $user_role !== 'admin') {
    echo "<div class='container my-5'><div class='alert alert-danger shadow-sm border-0' style='border-radius: 12px;'><i class='fa-solid fa-lock me-2'></i>Accès réservé aux administrateurs.</div></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}


$success_count = 0;
$error_count = 0;
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['prices_csv'])) {
    $file = $_FILES['prices_csv'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $filePath = $file['tmp_name'];
        $handle = fopen($filePath, 'r');

        if ($handle !== false) {
            // Détections du BOM UTF-8
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // Détection du séparateur (point-virgule ou virgule)
            $firstLine = fgets($handle);
            $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
            rewind($handle);
            if ($bom === "\xEF\xBB\xBF") {
                fread($handle, 3);
            }

            // Lire l'en-tête
            $header = fgetcsv($handle, 2000, $delimiter);

            $stmt_update = $pdo->prepare("
                UPDATE products 
                SET price_purchase = :purchase, 
                    price_sale = :sale, 
                    margin_percent = :margin 
                WHERE id = :id
            ");

            $stmt_update_sku = $pdo->prepare("
                UPDATE products 
                SET price_purchase = :purchase, 
                    price_sale = :sale, 
                    margin_percent = :margin 
                WHERE sku = :sku OR name = :name
            ");

            while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
                if (empty($row) || count($row) < 5) continue;

                $id = isset($row[0]) ? intval(trim($row[0])) : 0;
                $sku = isset($row[1]) ? trim($row[1]) : '';
                $name = isset($row[2]) ? trim($row[2]) : '';
                
                // Nettoyer les prix (enlever espaces et caractères non numériques)
                $purchase_raw = isset($row[5]) ? preg_replace('/[^0-9.]/', '', str_replace(',', '.', trim($row[5]))) : '0';
                $sale_raw = isset($row[6]) ? preg_replace('/[^0-9.]/', '', str_replace(',', '.', trim($row[6]))) : '0';
                
                $purchase = floatval($purchase_raw);
                $sale = floatval($sale_raw);

                if ($sale <= 0 && $purchase <= 0) continue;

                // Calcul automatique du pourcentage de marge
                $margin = 0;
                if ($sale > 0) {
                    $margin = round((($sale - $purchase) / $sale) * 100, 2);
                }

                try {
                    $updated = false;
                    if ($id > 0) {
                        $stmt_update->execute([
                            ':purchase' => $purchase,
                            ':sale' => $sale,
                            ':margin' => $margin,
                            ':id' => $id
                        ]);
                        if ($stmt_update->rowCount() > 0) {
                            $updated = true;
                        }
                    }

                    if (!$updated && (!empty($sku) || !empty($name))) {
                        $stmt_update_sku->execute([
                            ':purchase' => $purchase,
                            ':sale' => $sale,
                            ':margin' => $margin,
                            ':sku' => $sku,
                            ':name' => $name
                        ]);
                        if ($stmt_update_sku->rowCount() > 0) {
                            $updated = true;
                        }
                    }

                    if ($updated) {
                        $success_count++;
                    }
                } catch (Exception $ex) {
                    $error_count++;
                }
            }

            fclose($handle);

            $_SESSION['success_message'] = "🎉 <strong>Mise à jour réussie !</strong> $success_count prix d'achat et de vente ont été actualisés dans la base de données.";
            header("Location: products.php");
            exit;
        } else {
            $messages[] = "Impossible de lire le fichier téléversé.";
        }
    } else {
        $messages[] = "Erreur lors du téléversement du fichier.";
    }
}
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="fw-extrabold text-slate-800 mb-0 fs-4">
            <span class="text-primary"><i class="fa-solid fa-file-csv"></i></span> 
            Mise à jour des Prix (Import Excel / CSV)
        </h1>
        <a href="products.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px;">
            <i class="fa-solid fa-arrow-left me-1"></i>Retour au Catalogue
        </a>
    </div>
</div>

<?php if (!empty($messages)): ?>
    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px;"><?= $msg ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="row g-4">
    <!-- Étape 1 : Exportation -->
    <div class="col-12 col-md-6">
        <div class="glass-card p-4 h-100 border-start border-4 border-primary">
            <div class="d-flex align-items-center mb-3">
                <div class="bg-primary-subtle text-primary p-3 rounded-circle me-3">
                    <i class="fa-solid fa-file-export fa-xl"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-slate-800">Étape 1 : Télécharger la Grille Actuelle</h5>
                    <small class="text-secondary">Exportez le fichier CSV avec vos prix d'achat et de vente actuels</small>
                </div>
            </div>
            <p class="small text-secondary mb-4">
                Téléchargez la grille tarifaire complète. Vous pourrez l'ouvrir directement dans <strong>Microsoft Excel</strong> ou <strong>Google Sheets</strong>, modifier les colonnes <code>Prix_Achat_FCFA</code> et <code>Prix_Vente_FCFA</code>, puis enregistrer le fichier.
            </p>
            <a href="export-prices-csv.php" class="btn btn-primary fw-bold w-100 py-2.5" style="border-radius: 10px;">
                <i class="fa-solid fa-download me-2"></i>Télécharger la Grille Tarifaire Excel (.CSV)
            </a>
        </div>
    </div>

    <!-- Étape 2 : Importation & Mise à jour -->
    <div class="col-12 col-md-6">
        <div class="glass-card p-4 h-100 border-start border-4 border-success">
            <div class="d-flex align-items-center mb-3">
                <div class="bg-success-subtle text-success p-3 rounded-circle me-3">
                    <i class="fa-solid fa-file-import fa-xl"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-slate-800">Étape 2 : Importer les Prix Corrigés</h5>
                    <small class="text-secondary">Téléversez votre fichier modifié pour appliquer la mise à jour</small>
                </div>
            </div>
            
            <form action="product-prices-import.php" method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="prices_csv" class="form-label small fw-bold text-secondary">Sélectionnez le fichier CSV modifié *</label>
                    <input type="file" name="prices_csv" id="prices_csv" class="form-control form-control-custom" accept=".csv, .txt" required>
                </div>

                <div class="alert alert-warning border-0 p-2.5 mb-3 small d-flex align-items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-warning fs-5"></i>
                    <div>
                        <strong>Attention :</strong> Ne modifiez pas la colonne <code>ID</code> du fichier, elle permet d'associer exactement chaque prix à son article.
                    </div>
                </div>

                <button type="submit" class="btn btn-success fw-bold w-100 py-2.5" style="border-radius: 10px;">
                    <i class="fa-solid fa-upload me-2"></i>Mettre à Jour les Prix dans la Base de Données
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
