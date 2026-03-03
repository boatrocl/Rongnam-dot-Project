<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$user_id    = $_SESSION['ref_id'];
$loc_id     = $_POST['loc_id']          ?? '';
$req_date   = $_POST['requested_date']  ?? '';
$qty        = (int) ($_POST['qty']      ?? 0);
$note       = trim($_POST['note']       ?? '');
$product_id = $_POST['product_id']      ?? '';

// ── Basic validation ──────────────────────────────────────────
if (!$loc_id || !$req_date || $qty < 1) {
    header('Location: index.php?error=missing_fields');
    exit;
}

// ── Verify this location belongs to this customer ─────────────
$stmt = $pdo->prepare("SELECT loc_id FROM location WHERE loc_id = ? AND User_ID = ?");
$stmt->execute([$loc_id, $user_id]);
if (!$stmt->fetch()) {
    header('Location: index.php?error=invalid_location');
    exit;
}

// ── Validate product exists (if provided) ────────────────────
// The note already has [Product Name] prepended by the frontend JS.
// We also verify the product_id server-side for integrity.
if ($product_id) {
    $stmt = $pdo->prepare("SELECT name, price FROM product WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if ($product) {
        // Ensure the note prefix is correct (server-side rebuild in case JS was bypassed)
        $prefix = '[' . $product['name'] . '] ';
        if (!str_starts_with($note, '[')) {
            $note = $prefix . $note;
        }
    }
}

// ── Generate next request ID ──────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT request_id FROM order_request
        ORDER BY CAST(SUBSTRING(request_id, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $last   = $stmt->fetch();
    $new_id = $last
        ? 'REQ' . str_pad((int) preg_replace('/[^0-9]/', '', $last['request_id']) + 1, 3, '0', STR_PAD_LEFT)
        : 'REQ001';

    $pdo->prepare("
        INSERT INTO order_request (request_id, requested_date, qty, note, status, User_ID, loc_id)
        VALUES (?, ?, ?, ?, 'pending_admin', ?, ?)
    ")->execute([$new_id, $req_date, $qty, $note, $user_id, $loc_id]);

    header('Location: index.php?success=order_sent');
    exit;

} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}