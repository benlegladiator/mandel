<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Désactiver l'affichage HTML des erreurs pour garantir un JSON propre
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = ['success' => false, 'message' => ''];

try {
    $raw_input = file_get_contents('php://input');
    $payload = json_decode($raw_input, true);

    if (!$payload || !isset($payload['type']) || !isset($payload['data'])) {
        throw new Exception("Payload de synchronisation hors-ligne invalide.");
    }

    $type = $payload['type'];
    $data = $payload['data'];

    $pdo->beginTransaction();

    if ($type === 'ORDER') {
        $shop_id = intval($data['shop_id'] ?? 1);
        $user_id = intval($data['user_id'] ?? 1);
        $order_type = in_array($data['order_type'] ?? '', ['SALE', 'PURCHASE']) ? $data['order_type'] : 'SALE';
        $client_id = isset($data['client_id']) && intval($data['client_id']) > 0 ? intval($data['client_id']) : null;
        $supplier_id = isset($data['supplier_id']) && intval($data['supplier_id']) > 0 ? intval($data['supplier_id']) : null;
        $payment_method = $data['payment_method'] ?? 'CASH';
        $discount = floatval($data['discount'] ?? 0);
        $notes = trim($data['notes'] ?? '') . ' [Synchro Hors-Ligne]';
        $items = $data['items'] ?? [];

        if (empty($items)) {
            throw new Exception("La commande ne contient aucun article.");
        }

        // Calculer les montants
        $subtotal = 0;
        foreach ($items as $item) {
            $qty = intval($item['quantity'] ?? 0);
            $price = floatval($item['price'] ?? 0);
            $subtotal += ($qty * $price);
        }

        $total_amount = max(0, $subtotal - $discount);
        $amount_paid = ($payment_method === 'CREDIT') ? 0 : $total_amount;
        $payment_status = ($payment_method === 'CREDIT') ? 'UNPAID' : 'PAID';

        // Insérer la commande
        $stmt_order = $pdo->prepare("
            INSERT INTO orders (shop_id, user_id, type, client_id, supplier_id, payment_method, total_amount, subtotal, discount, amount_paid, payment_status, notes, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED')
        ");
        $stmt_order->execute([
            $shop_id, $user_id, $order_type, $client_id, $supplier_id, $payment_method, $total_amount, $subtotal, $discount, $amount_paid, $payment_status, $notes
        ]);
        $order_id = $pdo->lastInsertId();
        $ref_code = 'FA' . sprintf("%05d", $order_id);

        // Insérer les items et mettre à jour le stock
        $stmt_item = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) 
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt_stock_deduct = $pdo->prepare("
            UPDATE product_stock SET quantity = quantity - ? WHERE product_id = ? AND shop_id = ?
        ");

        $stmt_stock_add = $pdo->prepare("
            INSERT INTO product_stock (product_id, shop_id, quantity) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");

        foreach ($items as $item) {
            $p_id = intval($item['product_id']);
            $qty = intval($item['quantity']);
            $price = floatval($item['price']);
            $line_total = $qty * $price;

            $stmt_item->execute([$order_id, $p_id, $qty, $price, $line_total]);

            if ($order_type === 'SALE') {
                $stmt_stock_deduct->execute([$qty, $p_id, $shop_id]);
            } else {
                $stmt_stock_add->execute([$p_id, $shop_id, $qty, $qty]);
            }
        }

        // Insérer la transaction de caisse si payé en cash/om/cb
        if ($amount_paid > 0) {
            $stmt_cash = $pdo->prepare("
                INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type) 
                VALUES (?, ?, ?, ?, ?, ?, 'ORDER')
            ");
            $cash_type = ($order_type === 'SALE') ? 'IN' : 'OUT';
            $cash_desc = ($order_type === 'SALE' ? 'Vente Synchro ' : 'Achat Synchro ') . $ref_code;
            $stmt_cash->execute([$shop_id, $user_id, $cash_type, $amount_paid, $cash_desc, $order_id]);
        }

        $pdo->commit();
        $response['success'] = true;
        $response['ref'] = $ref_code;
        $response['order_id'] = $order_id;
        $response['message'] = "Commande $ref_code synchronisée avec succès.";
    } else {
        throw new Exception("Type de synchronisation non pris en charge.");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
