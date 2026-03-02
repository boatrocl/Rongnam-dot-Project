<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$village  = null;
$is_edit  = false;
$route_id = $_GET['route_id'] ?? null;

// ดึงรายชื่อเส้นทางสำหรับ dropdown
$routes = $pdo->query("SELECT route_id, route_name FROM route ORDER BY route_name")->fetchAll();

if (isset($_GET['id'])) {
    $is_edit = true;
    $stmt = $pdo->prepare("SELECT * FROM village WHERE village_id = ?");
    $stmt->execute([$_GET['id']]);
    $village  = $stmt->fetch();
    $route_id = $village['route_id'] ?? $route_id;
    if (!$village) die("ไม่พบข้อมูลหมู่บ้าน");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['action'] === 'insert') {
            $stmt = $pdo->prepare("INSERT INTO village (village_id, village_name, route_id) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['village_id'], $_POST['village_name'], $_POST['route_id']]);
            $success  = "เพิ่มหมู่บ้านเรียบร้อยแล้ว";
            $route_id = $_POST['route_id'];
        } else {
            $stmt = $pdo->prepare("UPDATE village SET village_name = ?, route_id = ? WHERE village_id = ?");
            $stmt->execute([$_POST['village_name'], $_POST['route_id'], $_POST['village_id']]);
            $success  = "แก้ไขหมู่บ้านเรียบร้อยแล้ว";
            $route_id = $_POST['route_id'];
            $stmt = $pdo->prepare("SELECT * FROM village WHERE village_id = ?");
            $stmt->execute([$_POST['village_id']]);
            $village = $stmt->fetch();
        }
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

$back_url = $route_id ? "route_detail.php?id=" . urlencode($route_id) : "routes.php";
?>

<div class="form-container">
    <a href="<?php echo $back_url; ?>" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <h2><?php echo $is_edit ? 'แก้ไขหมู่บ้าน' : 'เพิ่มหมู่บ้านใหม่'; ?></h2>

    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <?php echo $success; ?>
            <a href="<?php echo $back_url; ?>" style="margin-left:12px;">← กลับไปเส้นทาง</a>
        </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">

        <div class="form-group">
            <label>Village ID:</label>
            <input type="text" name="village_id"
                   value="<?php echo htmlspecialchars($village['village_id'] ?? ''); ?>"
                   <?php echo $is_edit ? 'readonly' : 'required'; ?>>
        </div>

        <div class="form-group">
            <label>ชื่อหมู่บ้าน:</label>
            <input type="text" name="village_name"
                   value="<?php echo htmlspecialchars($village['village_name'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label>เส้นทาง:</label>
            <select name="route_id" required>
                <option value="">เลือกเส้นทาง</option>
                <?php foreach($routes as $r): ?>
                <option value="<?php echo $r['route_id']; ?>"
                    <?php echo ($village['route_id'] ?? $route_id) == $r['route_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($r['route_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> บันทึก
            </button>
            <a href="<?php echo $back_url; ?>" class="btn btn-danger">
                <i class="fas fa-times"></i> ยกเลิก
            </a>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>