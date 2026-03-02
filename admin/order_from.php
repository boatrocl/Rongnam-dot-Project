<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$order = null;
$is_edit = false;

// ดึงข้อมูลสำหรับ dropdown
$users = $pdo->query("SELECT User_ID, User_name FROM `user` ORDER BY User_name")->fetchAll();
$locations = $pdo->query("SELECT loc_id, loc_name FROM location ORDER BY loc_name")->fetchAll();
$drivers = $pdo->query("SELECT DID, DFname, DLname FROM driver ORDER BY DFname")->fetchAll();
$managers = $pdo->query("SELECT ID, MFname, MLname FROM manager ORDER BY MFname")->fetchAll();

// ถ้ามี ID ส่งมา แสดงว่าเป็นการแก้ไข
if (isset($_GET['id'])) {
    $is_edit = true;
    $stmt = $pdo->prepare("SELECT * FROM `order` WHERE order_id = ?");
    $stmt->execute([$_GET['id']]);
    $order = $stmt->fetch();
}

// จัดการการบันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if ($_POST['action'] == 'insert') {
            $stmt = $pdo->prepare("
                INSERT INTO `order` (
                    order_id, order_type, scheduled_date, scheduled_time,
                    qty_ordered, deposit_fee, total_expected_price,
                    order_status, payment_status, User_ID, ID, loc_id, DID
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ");
            
            $stmt->execute([
                $_POST['order_id'],
                $_POST['order_type'],
                $_POST['scheduled_date'],
                $_POST['scheduled_date'] . ' ' . $_POST['scheduled_time'],
                $_POST['qty_ordered'],
                $_POST['deposit_fee'],
                $_POST['total_expected_price'],
                $_POST['order_status'],
                $_POST['payment_status'],
                $_POST['User_ID'],
                $_POST['ID'],
                $_POST['loc_id'],
                $_POST['DID']
            ]);
            
            $success = "เพิ่มออเดอร์เรียบร้อยแล้ว";
        } else {
            // Update logic here
        }
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>

<div class="form-container">
    <h2><?php echo $is_edit ? 'แก้ไขออเดอร์' : 'เพิ่มออเดอร์ใหม่'; ?></h2>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">
        
        <div class="form-group">
            <label>Order ID:</label>
            <input type="text" name="order_id" value="<?php echo $order['order_id'] ?? ''; ?>" <?php echo $is_edit ? 'readonly' : 'required'; ?>>
        </div>
        
        <div class="form-group">
            <label>ประเภทออเดอร์:</label>
            <select name="order_type" required>
                <option value="delivery" <?php echo ($order['order_type'] ?? '') == 'delivery' ? 'selected' : ''; ?>>จัดส่ง</option>
                <option value="pickup" <?php echo ($order['order_type'] ?? '') == 'pickup' ? 'selected' : ''; ?>>มารับเอง</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>วันที่:</label>
            <input type="date" name="scheduled_date" value="<?php echo $order['scheduled_date'] ?? date('Y-m-d'); ?>" required>
        </div>
        
        <div class="form-group">
            <label>เวลา:</label>
            <input type="time" name="scheduled_time" value="<?php echo $order['scheduled_time'] ?? date('H:i'); ?>" required>
        </div>
        
        <div class="form-group">
            <label>จำนวนที่สั่ง:</label>
            <input type="number" name="qty_ordered" value="<?php echo $order['qty_ordered'] ?? ''; ?>" required>
        </div>
        
        <div class="form-group">
            <label>ค่ามัดจำ:</label>
            <input type="number" step="0.01" name="deposit_fee" value="<?php echo $order['deposit_fee'] ?? '0.00'; ?>" required>
        </div>
        
        <div class="form-group">
            <label>ยอดรวมที่คาดหวัง:</label>
            <input type="number" step="0.01" name="total_expected_price" value="<?php echo $order['total_expected_price'] ?? ''; ?>" required>
        </div>
        
        <div class="form-group">
            <label>สถานะออเดอร์:</label>
            <select name="order_status" required>
                <option value="pending" <?php echo ($order['order_status'] ?? '') == 'pending' ? 'selected' : ''; ?>>รอดำเนินการ</option>
                <option value="processing" <?php echo ($order['order_status'] ?? '') == 'processing' ? 'selected' : ''; ?>>กำลังดำเนินการ</option>
                <option value="completed" <?php echo ($order['order_status'] ?? '') == 'completed' ? 'selected' : ''; ?>>เสร็จสิ้น</option>
                <option value="cancelled" <?php echo ($order['order_status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>ยกเลิก</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>สถานะการชำระเงิน:</label>
            <select name="payment_status" required>
                <option value="unpaid" <?php echo ($order['payment_status'] ?? '') == 'unpaid' ? 'selected' : ''; ?>>ยังไม่ชำระ</option>
                <option value="partial" <?php echo ($order['payment_status'] ?? '') == 'partial' ? 'selected' : ''; ?>>ชำระบางส่วน</option>
                <option value="paid" <?php echo ($order['payment_status'] ?? '') == 'paid' ? 'selected' : ''; ?>>ชำระแล้ว</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>ลูกค้า:</label>
            <select name="User_ID" required>
                <option value="">เลือกลูกค้า</option>
                <?php foreach($users as $user): ?>
                <option value="<?php echo $user['User_ID']; ?>" <?php echo ($order['User_ID'] ?? '') == $user['User_ID'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($user['User_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>ผู้จัดการ:</label>
            <select name="ID" required>
                <option value="">เลือกผู้จัดการ</option>
                <?php foreach($managers as $manager): ?>
                <option value="<?php echo $manager['ID']; ?>" <?php echo ($order['ID'] ?? '') == $manager['ID'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($manager['MFname'] . ' ' . $manager['MLname']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>สถานที่จัดส่ง:</label>
            <select name="loc_id" required>
                <option value="">เลือกสถานที่</option>
                <?php foreach($locations as $location): ?>
                <option value="<?php echo $location['loc_id']; ?>" <?php echo ($order['loc_id'] ?? '') == $location['loc_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($location['loc_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>พนักงานขับ:</label>
            <select name="DID" required>
                <option value="">เลือกพนักงานขับ</option>
                <?php foreach($drivers as $driver): ?>
                <option value="<?php echo $driver['DID']; ?>" <?php echo ($order['DID'] ?? '') == $driver['DID'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($driver['DFname'] . ' ' . $driver['DLname']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> บันทึก
            </button>
            <a href="orders.php" class="btn btn-danger">
                <i class="fas fa-times"></i> ยกเลิก
            </a>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>