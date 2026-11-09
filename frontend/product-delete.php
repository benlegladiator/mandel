<?php
session_start();
require_once __DIR__ . '/../backend/config/db.php';

// Bloquer l'accès si non connecté ou si ce n'est pas un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Action non autorisée.";
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Impossible de supprimer ce produit car des factures ou commandes y font référence.";
        header("Location: products.php");
        exit;
    }
}

header("Location: products.php");
exit;
