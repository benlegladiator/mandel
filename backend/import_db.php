<?php
require_once __DIR__ . '/config/db.php';

echo "Starting import of database dump...\n";

$sql = file_get_contents(__DIR__ . '/import_full_dump.sql');

try {
    // Check if the database has multi-query execution issues by executing it directly.
    $pdo->exec($sql);
    echo "Database imported successfully.\n";
} catch (PDOException $e) {
    echo "Error importing database: " . $e->getMessage() . "\n";
}
