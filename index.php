<?php
session_start();
require_once 'config/database.php';

// If already logged in, redirect to correct portal
if (isset($_SESSION['user_type'])) {
    switch ($_SESSION['user_type']) {
        case 'admin':    header('Location: admin/index.php');    exit;
        case 'customer': header('Location: customer/index.php'); exit;
        case 'driver':   header('Location: driver/index.php');   exit;
    }
}


$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id']   ?? '');
    $password = trim($_POST['password']   ?? '');

    if (empty($login_id) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {

        // ── Check Admin (Manager table) ──────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM Manager WHERE ID = ?");
        $stmt->execute([$login_id]);
        $manager = $stmt->fetch();
        if ($manager && password_verify($password, $manager['password'])) {
            $_SESSION['user_type'] = 'admin';
            $_SESSION['ref_id']    = $manager['ID'];
            $_SESSION['name']      = $manager['MFname'] . ' ' . $manager['MLname'];
            header('Location: admin/index.php');
            exit;
        }

        // ── Check Driver ─────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM Driver WHERE Username = ?");
        $stmt->execute([$login_id]);
        $driver = $stmt->fetch();
        if ($driver && password_verify($password, $driver['Password'])) {
            $_SESSION['user_type'] = 'driver';
            $_SESSION['ref_id']    = $driver['DID'];
            $_SESSION['name']      = $driver['DFname'] . ' ' . $driver['DLname'];
            header('Location: driver/index.php');
            exit;
        }

        // ── Check Customer (User table) ───────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM `User` WHERE User_name = ?");
        $stmt->execute([$login_id]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_active'] == 0) {
                $error = 'บัญชีนี้ถูกระงับ กรุณาติดต่อผู้ดูแลระบบ';
            } else {
                $_SESSION['user_type'] = 'customer';
                $_SESSION['ref_id']    = $user['User_ID'];
                $_SESSION['name']      = $user['User_name'];
                header('Location: customer/index.php');
                exit;
            }
        }

        // ── Nothing matched ───────────────────────────────────────
        if (empty($error)) {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — ระบบจัดการน้ำดื่ม</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a252f 0%, #2471a3 100%);
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .login-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 16px;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-logo i {
            font-size: 3rem;
            color: #2471a3;
        }
        .login-logo h1 {
            margin: 8px 0 4px 0;
            font-size: 1.4rem;
            color: #1a252f;
        }
        .login-logo p {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        .form-group .input-wrap {
            position: relative;
        }
        .form-group .input-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }
        .form-group input {
            width: 100%;
            padding: 11px 12px 11px 36px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #2471a3;
        }
        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #1a252f, #2471a3);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 8px;
            transition: opacity 0.2s;
        }
        .btn-login:hover { opacity: 0.9; }
        .alert-error {
            background: #fdf0f0;
            border: 1px solid #e74c3c;
            color: #c0392b;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 0.9rem;
        }
        .role-hint {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #eee;
            font-size: 0.8rem;
            color: #aaa;
            text-align: center;
            line-height: 1.8;
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">

        <div class="login-logo">
            <i class="fas fa-water"></i>
            <h1>ระบบจัดการน้ำดื่ม</h1>
            <p>Water Management System</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>ชื่อผู้ใช้ / Username</label>
                <div class="input-wrap">
                    <i class="fas fa-user"></i>
                    <input type="text" name="login_id"
                           value="<?php echo htmlspecialchars($_POST['login_id'] ?? ''); ?>"
                           placeholder="รหัสผู้ใช้" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน / Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="รหัสผ่าน" required>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ
            </button>
        </form>

        <div class="role-hint">
            ระบบจะพาคุณไปยังหน้าที่ถูกต้องโดยอัตโนมัติ<br>
            <i class="fas fa-shield-alt"></i> Admin &nbsp;|&nbsp;
            <i class="fas fa-truck"></i> Driver &nbsp;|&nbsp;
            <i class="fas fa-user"></i> Customer
        </div>
    </div>
</div>
</body>
</html>