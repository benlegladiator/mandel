<?php
require_once __DIR__ . '/config/db.php';

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <title>Mandel - Migration Marge & Statut</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='bg-light py-5'>
<div class='container' style='max-width: 600px;'>
    <div class='card shadow border-0' style='border-radius: 12px;'>
        <div class='card-body p-4'>
            <h4 class='card-title fw-bold mb-3'>Migration Marge et Validation Commande</h4>";

try {
    // 1. Ajouter le statut dans la table orders
    $checkStatus = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if (!$checkStatus->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN status ENUM('COMPLETED', 'PENDING_APPROVAL', 'REJECTED') NOT NULL DEFAULT 'COMPLETED' AFTER notes");
        echo "<div class='alert alert-success'>&check; Colonne <code>status</code> ajoutée à la table <code>orders</code>.</div>";
    } else {
        echo "<div class='alert alert-info'>La colonne <code>status</code> existe déjà dans <code>orders</code>.</div>";
    }

    // 2. Ajouter la marge dans la table products
    $checkMargin = $pdo->query("SHOW COLUMNS FROM products LIKE 'margin_percent'");
    if (!$checkMargin->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN margin_percent DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER price_sale");
        echo "<div class='alert alert-success'>&check; Colonne <code>margin_percent</code> ajoutée à la table <code>products</code>.</div>";
    } else {
        echo "<div class='alert alert-info'>La colonne <code>margin_percent</code> existe déjà dans <code>products</code>.</div>";
    }

    echo "<p class='mt-3'>Migration complétée avec succès !</p>
          <a href='../frontend/index.php' class='btn btn-primary w-100 fw-bold'>Accéder à l'Accueil</a>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'><strong>Erreur :</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "        </div>
    </div>
</div>
</body>
</html>";
?>
