<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_name = trim($_POST['user_name']);
    $password  = trim($_POST['password']);
    $tel       = trim($_POST['tel']);
    $email     = trim($_POST['email']) ?: null;
    $is_guest  = $_POST['is_guest']; // 'Y' or 'N'

    // 1. Auto-generate User_ID (e.g., U006)
    // Get the last ID to determine the next number
    $stmt = $pdo->query("SELECT User_ID FROM `User` ORDER BY User_ID DESC LIMIT 1");
    $last_user = $stmt->fetch();
    
    if ($last_user) {
        $last_num = (int) substr($last_user['User_ID'], 1); // Get '005' from 'U005'
        $new_id = 'U' . str_pad($last_num + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $new_id = 'U001';
    }

    // 2. Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    try {
        $sql = "INSERT INTO `User` (User_ID, User_name, password, tel, email, is_guest, is_active, Unpaid_amount) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 0.00)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_id, $user_name, $hashed_password, $tel, $email, $is_guest]);
        
        header("Location: index.php?success=1"); // Redirect back to list
        exit;
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
    }
}

require_once '../includes/header.php';
?>

<div class="dashboard" style="max-width: 600px; margin: 0 auto;">
    <h2><i class="fas fa-user-plus"></i> เพิ่มผู้ใช้ใหม่</h2>
    
    <?php if ($message): ?>
        <div class="alert-error" style="background:#fdf0f0; padding:10px; border:1px solid red; margin-bottom:15px;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="table-container">
        <form method="POST" style="display: flex; flex-direction: column; gap: 15px; padding: 20px;">
            <div>
                <label style="display:block; margin-bottom:5px;">ชื่อผู้ใช้ (Username)</label>
                <input type="text" name="user_name" required style="width:100%; padding:8px;">
            </div>

            <div>
                <label style="display:block; margin-bottom:5px;">รหัสผ่าน (Password)</label>
                <input type="password" name="password" required style="width:100%; padding:8px;">
            </div>

            <div>
                <label style="display:block; margin-bottom:5px;">เบอร์โทรศัพท์</label>
                <input type="text" name="tel" required style="width:100%; padding:8px;">
            </div>

            <div>
                <label style="display:block; margin-bottom:5px;">อีเมล (ถ้ามี)</label>
                <input type="email" name="email" style="width:100%; padding:8px;">
            </div>

            <div>
                <label style="display:block; margin-bottom:5px;">ประเภทลูกค้า</label>
                <select name="is_guest" style="width:100%; padding:8px;">
                    <option value="N">Member (ถาวร)</option>
                    <option value="Y">Guest (ขาจร)</option>
                </select>
            </div>

            <div style="margin-top: 10px; display: flex; gap: 10px;">
                <button type="submit" style="background: #2471a3; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer;">
                    บันทึกข้อมูล
                </button>
                <a href="index.php" style="text-decoration:none; color:#7f8c8d; padding:10px;">ยกเลิก</a>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>