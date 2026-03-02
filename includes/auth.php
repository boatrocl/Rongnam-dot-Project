<?php
function require_login($type) {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== $type) {
        header('Location: /water_management/index.php');
        exit;
    }
}

function current_user_name() {
    return $_SESSION['name'] ?? 'Unknown';
}

function current_user_id() {
    return $_SESSION['ref_id'] ?? null;
}
?>