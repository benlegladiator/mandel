<?php
require_once __DIR__ . '/config/db.php';

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <title>Mandel - Migration Finance</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='bg-light py-5'>
<div class='container' style='max-width: 600px;'>
    <div class='card shadow border-0' style='border-radius: 12px;'>
        <div class='card-body p-4'>
            <h4 class='card-title fw-bold mb-3'>Migration : Table des Charges d'Exploitation</h4>";

try {
    // Créer la table expenses
    $sql = "
        CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shop_id INT NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            category VARCHAR(100) NOT NULL,
            expense_date DATE NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "<div class='alert alert-success'>&check; Table <code>expenses</code> créée avec succès.</div>";
    
    echo "<p class='mt-3'>La base de données est prête pour le suivi comptable et financier.</p>
          <a href='../frontend/finance.php' class='btn btn-primary w-100 fw-bold'>Accéder au Module Compta & Finance</a>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'><strong>Erreur :</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "        </div>
    </div>
</div>
</body>
</html>";
?>
