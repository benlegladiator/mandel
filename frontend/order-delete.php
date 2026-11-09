<?php
session_start();
require_once __DIR__ . '/../backend/config/db.php';

// Sécurité : Seul l'administrateur a le droit de supprimer une facture
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_message'] = "Accès refusé. Seul l'administrateur peut supprimer une facture.";
    header("Location: orders.php");
    exit;
}

$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;

if ($id <= 0) {
    $_SESSION['error_message'] = "Identifiant de facture invalide.";
    header("Location: orders.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Récupérer les informations de la commande
    $stmt_order = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt_order->execute([$id]);
    $order = $stmt_order->fetch();

    if (!$order) {
        throw new Exception("Facture introuvable (ID: $id).");
    }

    $shop_id = intval($order['shop_id']);
    $order_type = $order['type'];
    $order_status = $order['status'];

    // 2. Récupérer les articles de la commande pour ajuster les stocks
    $stmt_items = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $stmt_items->execute([$id]);
    $items = $stmt_items->fetchAll();

    $stmt_update_stock = $pdo->prepare("
        INSERT INTO product_stock (product_id, shop_id, quantity) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE quantity = quantity + ?
    ");

    foreach ($items as $item) {
        $p_id = intval($item['product_id']);
        $qty = intval($item['quantity']);

        if ($order_type === 'SALE') {
            // Pour une vente qui avait déduit/réservé du stock (COMPLETED ou PENDING_APPROVAL)
            // On restitue la quantité en stock (+qty)
            if ($order_status === 'COMPLETED' || $order_status === 'PENDING_APPROVAL') {
                $stmt_update_stock->execute([$p_id, $shop_id, $qty, $qty]);
            }
        } elseif ($order_type === 'PURCHASE') {
            // Pour un achat fournisseur validé qui avait approvisionné le stock
            // On déduit la quantité du stock (-qty)
            if ($order_status === 'COMPLETED') {
                $stmt_update_stock->execute([$p_id, $shop_id, -$qty, -$qty]);
            }
        }
    }

    // 3. Supprimer les transactions de caisse liées
    $stmt_del_cash = $pdo->prepare("DELETE FROM cash_transactions WHERE reference_id = ? AND reference_type = 'ORDER'");
    $stmt_del_cash->execute([$id]);

    // 4. Supprimer les articles de la commande
    $stmt_del_items = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt_del_items->execute([$id]);

    // 5. Supprimer la commande
    $stmt_del_order = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt_del_order->execute([$id]);

    $pdo->commit();

    $_SESSION['success_message'] = "La facture <strong>" . ($order['reference'] ?? ('FAC-' . sprintf("%05d", $id))) . "</strong> a été supprimée avec succès. Les stocks et la caisse ont été réajustés.";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "Erreur lors de la suppression de la facture : " . $e->getMessage();
}

header("Location: orders.php");
exit;
