<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>Migration Mandel DB - Catégories de Dépenses & Reports de Soldes Antérieurs</h2>";

try {
    // 1. Table expense_categories
    $checkExpenseCategories = $pdo->query("SHOW TABLES LIKE 'expense_categories'");
    if (!$checkExpenseCategories->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expense_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "<p style='color:green;'>✓ Table 'expense_categories' créée avec succès.</p>";
    } else {
        echo "<p style='color:blue;'>ℹ Table 'expense_categories' existe déjà.</p>";
    }

    // Seed default categories
    $defaultCategories = [
        ['EAU POTABLE', 'Consommation d\'eau potable et boissons boutique'],
        ['TAXI / TRANSPORT', 'Frais de transport, coursier et déplacements'],
        ['EMBALLAGE', 'Emballages, plastiques et conditionnement'],
        ['PETITES FOURNITURES', 'Fournitures de bureau (stylos, papier, consommables)'],
        ['ELECTRICITE / ABONNEMENT', 'Factures d\'électricité, eau réseau et abonnements internet'],
        ['LOYER', 'Loyer commercial des boutiques et dépôts'],
        ['SALAIRES & PRIMES', 'Avances sur salaires, primes et gratifications du personnel'],
        ['MAINTENANCE / REPARATION', 'Entretien et réparations des matériels et locaux'],
        ['AUTRE', 'Autres charges courantes d\'exploitation']
    ];

    $stmt_seed = $pdo->prepare("INSERT IGNORE INTO expense_categories (name, description) VALUES (?, ?)");
    $inserted_count = 0;
    foreach ($defaultCategories as $cat) {
        $stmt_seed->execute([$cat[0], $cat[1]]);
        if ($stmt_seed->rowCount() > 0) $inserted_count++;
    }
    echo "<p style='color:green;'>✓ $inserted_count catégories par défaut insérées.</p>";

    // 2. Column is_historical in orders table
    $checkIsHistorical = $pdo->query("SHOW COLUMNS FROM orders LIKE 'is_historical'");
    if (!$checkIsHistorical->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_reason");
        echo "<p style='color:green;'>✓ Colonne 'is_historical' ajoutée à la table 'orders'.</p>";
    } else {
        echo "<p style='color:blue;'>ℹ Colonne 'is_historical' existe déjà dans 'orders'.</p>";
    }

    echo "<h3>Migration terminée avec succès !</h3>";

} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Erreur lors de la migration : " . htmlspecialchars($e->getMessage()) . "</p>";
}
