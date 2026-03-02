<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['ref_id'];
    $loc_id = $_POST['loc_id'];
    $req_date = $_POST['requested_date'];
    $qty = (int)$_POST['qty'];
    $note = trim($_POST['note']);

    try {
        // 1. Generate Request ID (e.g., REQ001)
        $stmt = $pdo->query("SELECT request_id FROM order_request ORDER BY request_id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) {
            $num = (int)preg_replace('/[^0-9]/', '', $last['request_id']);
            $new_id = 'REQ' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $new_id = 'REQ001';
        }

        // 2. Insert into order_request table
        $sql = "INSERT INTO order_request (request_id, requested_date, qty, note, status, User_ID, loc_id) 
                VALUES (?, ?, ?, ?, 'pending_admin', ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_id, $req_date, $qty, $note, $user_id, $loc_id]);

        header("Location: index.php?success=order_sent");
        exit;
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}