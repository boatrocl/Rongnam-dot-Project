// admin/login.php — same structure works for customer and driver, 
// just change the table and redirect
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = $_POST['username'];
    $pwd = $_POST['password'];

    // For admin — check Manager table
    $stmt = $pdo->prepare("SELECT * FROM Manager WHERE ID = ?");
    $stmt->execute([$id]);
    $manager = $stmt->fetch();

    if ($manager && password_verify($pwd, $manager['password'])) {
        session_start();
        $_SESSION['user_type'] = 'admin';
        $_SESSION['ref_id']    = $manager['ID'];
        $_SESSION['name']      = $manager['MFname'] . ' ' . $manager['MLname'];
        header('Location: index.php');
        exit;
    } else {
        $error = "รหัสผ่านหรือชื่อผู้ใช้ไม่ถูกต้อง";
    }
}
?>