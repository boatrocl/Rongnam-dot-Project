<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$route_id = $_GET['route_id'] ?? null;
if (!$route_id) die("ไม่พบข้อมูลเส้นทาง");

// ดึงรายชื่อพนักงานขับ
$drivers = $pdo->query("SELECT DID, DFname, DLname FROM driver ORDER BY DFname")->fetchAll();

$route_stmt = $pdo->prepare("SELECT * FROM route WHERE route_id = ?");
$route_stmt->execute([$route_id]);
$route = $route_stmt->fetch();
if (!$route) die("ไม่พบข้อมูลเส้นทาง");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO route_assignment (assignment_id, day_of_week, DID, route_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['assignment_id'],
            $_POST['day_of_week'],
            $_POST['DID'],
            $route_id
        ]);
        $success = "มอบหมายพนักงานขับเรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>

<div class="form-container">
    <a href="route_detail.php?id=<?php echo urlencode($route_id); ?>"
       style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <h2>มอบหมายพนักงานขับ — เส้นทาง: <?php echo htmlspecialchars($route['route_name']); ?></h2>

    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <?php echo $success; ?>
            <a href="route_detail.php?id=<?php echo urlencode($route_id); ?>" style="margin-left:12px;">← กลับไปเส้นทาง</a>
        </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Assignment ID:</label>
            <input type="text" name="assignment_id" required placeholder="เช่น A001">
        </div>

        <div class="form-group">
            <label>วันในสัปดาห์:</label>
            <select name="day_of_week" required>
                <option value="">เลือกวัน</option>
                <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>พนักงานขับ:</label>
            <select name="DID" required>
                <option value="">เลือกพนักงานขับ</option>
                <?php foreach($drivers as $d): ?>
                <option value="<?php echo $d['DID']; ?>">
                    <?php echo htmlspecialchars($d['DFname'] . ' ' . $d['DLname']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> บันทึก
            </button>
            <a href="route_detail.php?id=<?php echo urlencode($route_id); ?>" class="btn btn-danger">
                <i class="fas fa-times"></i> ยกเลิก
            </a>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>
