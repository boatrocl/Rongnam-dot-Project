<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['ref_id']; // The Manager ID from session

    if ($action === 'approve') {
        $did = $_POST['did'];
        $user_id = $_POST['user_id'];
        $loc_id = $_POST['loc_id'];
        $qty = $_POST['qty'];
        $scheduled_date = $_POST['req_date'];

        try {
            $pdo->beginTransaction();

            // 1. Generate new order_id (ORDxxx)
            $stmt = $pdo->query("SELECT order_id FROM `order` ORDER BY order_id DESC LIMIT 1");
            $last = $stmt->fetch();
            $new_order_id = $last ? 'ORD' . str_pad((int)substr($last['order_id'], 3) + 1, 3, '0', STR_PAD_LEFT) : 'ORD001';

            // 2. Insert into the `order` table
            // Note: Schema requires (order_id, User_ID, ID, DID, loc_id) as composite PK
            $sql_order = "INSERT INTO `order` (
                order_id, order_type, scheduled_date, qty_ordered, 
                order_status, payment_status, is_system_generated, 
                User_ID, ID, loc_id, DID
            ) VALUES (?, 'delivery', ?, ?, 'pending', 'unpaid', 'N', ?, ?, ?, ?)";
            
            $stmt_order = $pdo->prepare($sql_order);
            $stmt_order->execute([
                $new_order_id, $scheduled_date, $qty, 
                $user_id, $admin_id, $loc_id, $did
            ]);

            // 3. Update order_request status and link order_id
            $sql_req = "UPDATE order_request SET status = 'approved', order_id = ? WHERE request_id = ?";
            $pdo->prepare($sql_req)->execute([$new_order_id, $request_id]);

            $pdo->commit();
            header("Location: pending_orders.php?success=approved");
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Transaction Failed: " . $e->getMessage());
        }
    } elseif ($action === 'reject') {
        $sql_reject = "UPDATE order_request SET status = 'rejected' WHERE request_id = ?";
        $pdo->prepare($sql_reject)->execute([$request_id]);
        header("Location: pending_orders.php?success=rejected");
    }
    exit;
}