<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Désactiver l'affichage HTML des erreurs pour garantir un JSON valide
ini_set('display_errors', 0);
error_reporting(E_ALL);

$response = [
    'success' => false,
    'message' => '',
    'synced_count' => 0,
    'synced_ids' => [],
    'details' => []
];

try {
    $raw_input = file_get_contents('php://input');
    if (empty($raw_input)) {
        throw new Exception("Aucune donnée reçue pour la synchronisation.");
    }

    $payload = json_decode($raw_input, true);
    if (!is_array($payload)) {
        throw new Exception("Format de données invalide (JSON attendu).");
    }

    // Récupérer la liste des commandes à synchroniser (batch ou unique)
    $orders_to_sync = [];
    if (isset($payload['orders']) && is_array($payload['orders'])) {
        $orders_to_sync = $payload['orders'];
    } elseif (isset($payload['type']) && $payload['type'] === 'ORDER' && isset($payload['data'])) {
        $orders_to_sync = [$payload['data']];
    } elseif (isset($payload[0]) && is_array($payload[0])) {
        $orders_to_sync = $payload;
    } elseif (isset($payload['items'])) {
        // Commande unique directe
        $orders_to_sync = [$payload];
    } else {
        throw new Exception("Payload de synchronisation non reconnu.");
    }

    if (empty($orders_to_sync)) {
        $response['success'] = true;
        $response['message'] = "Aucune commande en attente de synchronisation.";
        echo json_encode($response);
        exit;
    }

    $synced_ids = [];
    $details = [];

    // Requêtes préparées réutilisables
    $stmt_find_ref = $pdo->prepare("SELECT id, reference FROM orders WHERE reference = ? LIMIT 1");

    $stmt_order = $pdo->prepare("
        INSERT INTO orders (
            reference, type, shop_id, client_id, supplier_id, user_id, 
            total_amount, payment_method, notes, status, due_date, 
            amount_paid, payment_status, discount_reason,
            is_formal, subtotal, tax_amount, ir_amount, nap_amount,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?, ?, ?, 0, ?, 0, 0, ?, ?)
    ");

    $stmt_item = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) 
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt_stock_deduct = $pdo->prepare("
        INSERT INTO product_stock (product_id, shop_id, quantity) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE quantity = quantity - ?
    ");

    $stmt_stock_add = $pdo->prepare("
        INSERT INTO product_stock (product_id, shop_id, quantity) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE quantity = quantity + ?
    ");

    $stmt_cash = $pdo->prepare("
        INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'ORDER', ?)
    ");

    foreach ($orders_to_sync as $order) {
        $local_id = $order['id'] ?? null;
        $offline_ref = trim($order['reference'] ?? '');

        // 1. Idempotence : si cette référence hors-ligne est déjà enregistrée sur le serveur
        if (!empty($offline_ref)) {
            $stmt_find_ref->execute([$offline_ref]);
            $existing = $stmt_find_ref->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($local_id !== null) {
                    $synced_ids[] = $local_id;
                }
                $details[] = [
                    'local_id' => $local_id,
                    'order_id' => $existing['id'],
                    'reference' => $existing['reference'],
                    'status' => 'already_synced'
                ];
                continue;
            }
        }

        try {
            $pdo->beginTransaction();

            $shop_id = intval($order['shop_id'] ?? ($_SESSION['shop_id'] ?? 1));
            $user_id = intval($order['user_id'] ?? ($_SESSION['user_id'] ?? 1));
            $order_type = in_array($order['order_type'] ?? '', ['SALE', 'PURCHASE']) ? $order['order_type'] : 'SALE';
            $client_id = (!empty($order['client_id']) && intval($order['client_id']) > 0) ? intval($order['client_id']) : null;
            $supplier_id = (!empty($order['supplier_id']) && intval($order['supplier_id']) > 0) ? intval($order['supplier_id']) : null;
            $payment_method = $order['payment_method'] ?? 'CASH';
            $discount = floatval($order['discount'] ?? 0);
            $notes = trim($order['notes'] ?? '');
            $items = $order['items'] ?? [];

            if (empty($items)) {
                throw new Exception("La commande ne contient aucun article.");
            }

            // Calculer sous-total
            $subtotal = 0;
            foreach ($items as $item) {
                $qty = intval($item['quantity'] ?? 0);
                $price = floatval($item['price'] ?? 0);
                $subtotal += ($qty * $price);
            }

            $total_amount = max(0, $subtotal - $discount);
            $nap_amount = $total_amount;

            if ($payment_method === 'CREDIT') {
                $amount_paid = floatval($order['amount_paid'] ?? 0);
                $due_date = !empty($order['due_date']) ? $order['due_date'] : date('Y-m-d', strtotime('+7 days'));
                $payment_status = ($amount_paid >= $total_amount && $total_amount > 0) ? 'PAID' : (($amount_paid > 0) ? 'PARTIAL' : 'UNPAID');
            } else {
                $amount_paid = $total_amount;
                $payment_status = 'PAID';
                $due_date = null;
            }

            $discount_reason = ($discount > 0) ? ("Remise accordée: " . number_format($discount, 0, '.', ' ') . " FCFA") : null;
            $saved_at = !empty($order['saved_at']) ? date('Y-m-d H:i:s', strtotime($order['saved_at'])) : date('Y-m-d H:i:s');

            // Insérer la commande d'abord avec reference null pour obtenir l'order_id
            $stmt_order->execute([
                null,
                $order_type,
                $shop_id,
                $client_id,
                $supplier_id,
                $user_id,
                $total_amount,
                $payment_method,
                $notes,
                $due_date,
                $amount_paid,
                $payment_status,
                $discount_reason,
                $subtotal,
                $total_amount,
                $saved_at
            ]);
            $order_id = $pdo->lastInsertId();

            // Attribuer la référence finale
            $final_ref = 'FAC-' . sprintf("%05d", $order_id);
            if (!empty($offline_ref)) {
                // Si la référence hors-ligne n'est pas en conflit dans la base, on la préserve (correspondance exacte avec le ticket imprimé)
                $stmt_find_ref->execute([$offline_ref]);
                if (!$stmt_find_ref->fetch()) {
                    $final_ref = $offline_ref;
                }
            }

            $pdo->prepare("UPDATE orders SET reference = ? WHERE id = ?")->execute([$final_ref, $order_id]);

            // Insérer les articles et déduire/ajouter le stock
            foreach ($items as $item) {
                $p_id = intval($item['product_id'] ?? 0);
                $qty = intval($item['quantity'] ?? 0);
                $price = floatval($item['price'] ?? 0);
                $line_total = $qty * $price;

                if ($p_id > 0 && $qty > 0) {
                    $stmt_item->execute([$order_id, $p_id, $qty, $price, $line_total]);

                    if ($order_type === 'SALE') {
                        $stmt_stock_deduct->execute([$p_id, $shop_id, -$qty, $qty]);
                    } else {
                        $stmt_stock_add->execute([$p_id, $shop_id, $qty, $qty]);
                    }
                }
            }

            // Écriture en caisse
            if ($amount_paid > 0 && in_array(strtoupper($payment_method), ['CASH', 'MOBILE_MONEY', 'MOMO', 'OM'])) {
                $cash_type = ($order_type === 'SALE') ? 'IN' : 'OUT';
                $cash_shop = ($order_type === 'SALE') ? $shop_id : null;
                $cash_desc = ($order_type === 'SALE' ? 'Vente en espèces ' : 'Achat ravitaillement ') . $final_ref;
                $stmt_cash->execute([$cash_shop, $user_id, $cash_type, $amount_paid, $cash_desc, $order_id, $saved_at]);
            }

            $pdo->commit();

            if ($local_id !== null) {
                $synced_ids[] = $local_id;
            }
            $details[] = [
                'local_id' => $local_id,
                'order_id' => $order_id,
                'reference' => $final_ref,
                'status' => 'synced'
            ];
        } catch (Exception $subEx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $details[] = [
                'local_id' => $local_id,
                'error' => $subEx->getMessage(),
                'status' => 'failed'
            ];
        }
    }

    $response['success'] = count($synced_ids) > 0;
    $response['synced_count'] = count($synced_ids);
    $response['synced_ids'] = $synced_ids;
    $response['details'] = $details;
    $response['message'] = count($synced_ids) . " commande(s) synchronisée(s) avec succès.";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
