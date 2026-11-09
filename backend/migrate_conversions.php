<?php
require_once __DIR__ . '/config/db.php';

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <title>Mandel - Migration Déconditionnement</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='bg-light py-5'>
<div class='container' style='max-width: 600px;'>
    <div class='card shadow border-0' style='border-radius: 12px;'>
        <div class='card-body p-4'>
            <h4 class='card-title fw-bold mb-3'>Migration : Table des Déconditionnements</h4>";

try {
    // Créer la table stock_conversions
    $sql = "
        CREATE TABLE IF NOT EXISTS stock_conversions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shop_id INT NOT NULL,
            user_id INT NOT NULL,
            source_product_id INT NOT NULL,
            target_product_id INT NOT NULL,
            source_quantity INT NOT NULL,
            multiplier INT NOT NULL,
            target_quantity INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (source_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "<div class='alert alert-success'>&check; Table <code>stock_conversions</code> créée avec succès.</div>";
    
    echo "<p class='mt-3'>La base de données est prête pour le module de déconditionnement.</p>
          <a href='../frontend/stock-conversion.php' class='btn btn-success w-100 fw-bold'>Accéder au Déconditionnement</a>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'><strong>Erreur :</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "        </div>
    </div>
</div>
</body>
</html>";
?>
