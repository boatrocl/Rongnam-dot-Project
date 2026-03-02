<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$route   = null;
$is_edit = false;

if (isset($_GET['id'])) {
    $is_edit = true;
    $stmt = $pdo->prepare("SELECT * FROM route WHERE route_id = ?");
    $stmt->execute([$_GET['id']]);
    $route = $stmt->fetch();
    if (!$route) die("ไม่พบข้อมูลเส้นทาง");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['action'] === 'insert') {
            $stmt = $pdo->prepare("INSERT INTO route (route_id, route_name, delivery_day) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['route_id'], $_POST['route_name'], $_POST['delivery_day']]);
            $success = "เพิ่มเส้นทางเรียบร้อยแล้ว";
        } else {
            $stmt = $pdo->prepare("UPDATE route SET route_name = ?, delivery_day = ? WHERE route_id = ?");
            $stmt->execute([$_POST['route_name'], $_POST['delivery_day'], $_POST['route_id']]);
            $success = "แก้ไขเส้นทางเรียบร้อยแล้ว";
            $stmt = $pdo->prepare("SELECT * FROM route WHERE route_id = ?");
            $stmt->execute([$_POST['route_id']]);
            $route = $stmt->fetch();
        }
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>

<div class="form-container">
    <a href="routes.php" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <h2><?php echo $is_edit ? 'แก้ไขเส้นทาง' : 'เพิ่มเส้นทางใหม่'; ?></h2>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">

        <div class="form-group">
            <label>Route ID:</label>
            <input type="text" name="route_id"
                   value="<?php echo htmlspecialchars($route['route_id'] ?? ''); ?>"
                   <?php echo $is_edit ? 'readonly' : 'required'; ?>>
        </div>

        <div class="form-group">
            <label>ชื่อเส้นทาง:</label>
            <input type="text" name="route_name"
                   value="<?php echo htmlspecialchars($route['route_name'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label>วันส่ง:</label>
            <select name="delivery_day">
                <option value="">— ไม่ระบุ —</option>
                <?php
                $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                foreach($days as $day):
                ?>
                <option value="<?php echo $day; ?>"
                    <?php echo ($route['delivery_day'] ?? '') === $day ? 'selected' : ''; ?>>
                    <?php echo $day; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> บันทึก
            </button>
            <a href="routes.php" class="btn btn-danger">
                <i class="fas fa-times"></i> ยกเลิก
            </a>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>