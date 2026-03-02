<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dfname   = trim($_POST['dfname']);
    $dlname   = trim($_POST['dlname']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $tel      = trim($_POST['tel']);
    $address  = trim($_POST['address']);

    try {
        // 1. Auto-generate DID (e.g., D004)
        $stmt = $pdo->query("SELECT DID FROM Driver ORDER BY DID DESC LIMIT 1");
        $last_driver = $stmt->fetch();
        
        if ($last_driver) {
            $last_num = (int) substr($last_driver['DID'], 1); // Extract numbers after 'D'
            $new_did = 'D' . str_pad($last_num + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $new_did = 'D001';
        }

        // 2. Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // 3. Insert into Driver table
        $sql = "INSERT INTO Driver (DID, Username, Password, DFname, DLname, tel, Address) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_did, $username, $hashed_password, $dfname, $dlname, $tel, $address]);
        
        header("Location: drivers_list.php?success=1"); // Redirect to your driver list page
        exit;
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

require_once '../includes/header.php';
?>

<div class="dashboard" style="max-width: 700px; margin: 0 auto;">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
        <a href="drivers_list.php" style="color: #2471a3; text-decoration: none;"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
        <h2 style="margin:0;">เพิ่มพนักงานขับรถใหม่ (Add New Driver)</h2>
    </div>
    
    <?php if ($error): ?>
        <div style="background:#fdf0f0; padding:15px; border-left:5px solid #e74c3c; color:#c0392b; margin-bottom:20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="table-container" style="padding: 30px;">
        <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            
            <div style="grid-column: span 1;">
                <label style="display:block; font-weight:bold; margin-bottom:8px;">ชื่อจริง (First Name)</label>
                <input type="text" name="dfname" required placeholder="เช่น สมชาย" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px;">
            </div>

            <div style="grid-column: span 1;">
                <label style="display:block; font-weight:bold; margin-bottom:8px;">นามสกุล (Last Name)</label>
                <input type="text" name="dlname" required placeholder="เช่น ใจดี" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px;">
            </div>

            <div style="grid-column: span 1;">
                <label style="display:block; font-weight:bold; margin-bottom:8px;">ชื่อผู้ใช้ (Username)</label>
                <input type="text" name="username" required placeholder="somchai.driver" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px;">
            </div>

            <div style="grid-column: span 1;">
                <label style="display:block; font-weight:bold; margin-bottom:8px;">รหัสผ่าน (Password)</label>
                <input type="password" name="password" required placeholder="••••••••" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px;">
            </div>

            <div style="grid-column: span 2;">
                <label style="display:block; font-weight:bold; margin-bottom:8px;">เบอร์โทรศัพท์</label>
                <input type="text" name="tel" required placeholder="08XXXXXXXX" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px;">
            </div>

            <div style="grid-column: span 2;">
                <label style="display:block; font-weight:bold; margin-bottom:8px;">ที่อยู่ (Address)</label>
                <textarea name="address" rows="3" required placeholder="บ้านเลขที่, ถนน, แขวง/ตำบล..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px; font-family:inherit;"></textarea>
            </div>

            <div style="grid-column: span 2; margin-top: 10px;">
                <button type="submit" style="background: #2471a3; color:white; border:none; padding:12px 25px; border-radius:5px; cursor:pointer; font-weight:bold; width: 100%;">
                    <i class="fas fa-save"></i> บันทึกข้อมูลพนักงาน
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>