<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['ref_id'];
    $loc_name = trim($_POST['loc_name']);
    $village_id = $_POST['village_id'];
    $details = trim($_POST['details']);
    $lat = !empty($_POST['lat']) ? $_POST['lat'] : null;
    $lng = !empty($_POST['lng']) ? $_POST['lng'] : null;

    try {
        // Generate new loc_id
        $stmt = $pdo->query("SELECT loc_id FROM location ORDER BY loc_id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) {
            $num = (int)substr($last['loc_id'], 1);
            $new_id = 'L' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $new_id = 'L001';
        }

        $sql = "INSERT INTO location (loc_id, loc_name, details, bottle_on_hand, latitude, longitude, village_id, User_ID) 
                VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_id, $loc_name, $details, $lat, $lng, $village_id, $user_id]);

        header("Location: index.php?success=location_added");
        exit;
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
}