<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$loc = null;
$is_edit = false;

// ดึงข้อมูลสำหรับ dropdown
$users    = $pdo->query("SELECT User_ID, User_name FROM `user` ORDER BY User_name")->fetchAll();
$villages = $pdo->query("SELECT village_id, village_name FROM village ORDER BY village_name")->fetchAll();

// โหมดแก้ไข
if (isset($_GET['id'])) {
    $is_edit = true;
    $stmt = $pdo->prepare("SELECT * FROM location WHERE loc_id = ?");
    $stmt->execute([$_GET['id']]);
    $loc = $stmt->fetch();
    if (!$loc) die("ไม่พบข้อมูลสถานที่");
}

// บันทึก
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['action'] === 'insert') {
            $stmt = $pdo->prepare("
                INSERT INTO location (loc_id, loc_name, details, bottle_on_hand, latitude, longitude, village_id, User_ID)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['loc_id'],
                $_POST['loc_name'],
                $_POST['details'],
                $_POST['bottle_on_hand'],
                $_POST['latitude']  ?: null,
                $_POST['longitude'] ?: null,
                $_POST['village_id'] ?: null,
                $_POST['User_ID']
            ]);
            $success = "เพิ่มสถานที่เรียบร้อยแล้ว";

        } else {
            $stmt = $pdo->prepare("
                UPDATE location
                SET loc_name = ?, details = ?, bottle_on_hand = ?,
                    latitude = ?, longitude = ?, village_id = ?, User_ID = ?
                WHERE loc_id = ?
            ");
            $stmt->execute([
                $_POST['loc_name'],
                $_POST['details'],
                $_POST['bottle_on_hand'],
                $_POST['latitude']  ?: null,
                $_POST['longitude'] ?: null,
                $_POST['village_id'] ?: null,
                $_POST['User_ID'],
                $_POST['loc_id']
            ]);
            $success = "แก้ไขสถานที่เรียบร้อยแล้ว";
            // รีเฟรชข้อมูลหลังแก้ไข
            $stmt = $pdo->prepare("SELECT * FROM location WHERE loc_id = ?");
            $stmt->execute([$_POST['loc_id']]);
            $loc = $stmt->fetch();
        }
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>

<div class="form-container">
    <a href="locations.php" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <h2><?php echo $is_edit ? 'แก้ไขสถานที่' : 'เพิ่มสถานที่ใหม่'; ?></h2>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">

        <div class="form-group">
            <label>Location ID:</label>
            <input type="text" name="loc_id"
                   value="<?php echo htmlspecialchars($loc['loc_id'] ?? ''); ?>"
                   <?php echo $is_edit ? 'readonly' : 'required'; ?>>
        </div>

        <div class="form-group">
            <label>ชื่อสถานที่:</label>
            <input type="text" name="loc_name"
                   value="<?php echo htmlspecialchars($loc['loc_name'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label>รายละเอียด:</label>
            <input type="text" name="details"
                   value="<?php echo htmlspecialchars($loc['details'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>ขวดในมือ:</label>
            <input type="number" name="bottle_on_hand"
                   value="<?php echo htmlspecialchars($loc['bottle_on_hand'] ?? '0'); ?>" required>
        </div>

        <div class="form-group">
            <label>Latitude (ละติจูด):</label>
            <input type="number" step="0.0000001" name="latitude"
                   value="<?php echo htmlspecialchars($loc['latitude'] ?? ''); ?>"
                   placeholder="เช่น 13.7563">
        </div>

        <div class="form-group">
            <label>Longitude (ลองจิจูด):</label>
            <input type="number" step="0.0000001" name="longitude"
                   value="<?php echo htmlspecialchars($loc['longitude'] ?? ''); ?>"
                   placeholder="เช่น 100.5018">
        </div>

        <div class="form-group">
            <label>หมู่บ้าน:</label>
            <select name="village_id">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach($villages as $v): ?>
                <option value="<?php echo $v['village_id']; ?>"
                    <?php echo ($loc['village_id'] ?? '') == $v['village_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($v['village_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>เจ้าของ (ลูกค้า):</label>
            <select name="User_ID" required>
                <option value="">เลือกลูกค้า</option>
                <?php foreach($users as $u): ?>
                <option value="<?php echo $u['User_ID']; ?>"
                    <?php echo ($loc['User_ID'] ?? '') == $u['User_ID'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($u['User_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> บันทึก
            </button>
            <a href="locations.php" class="btn btn-danger">
                <i class="fas fa-times"></i> ยกเลิก
            </a>
        </div>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>
```

---

## File summary
```
pages/
├── locations.php         ← list + delete + search
├── location_detail.php   ← stats + GPS link + order history  
└── location_form.php     ← add & edit (same file, detects mode via ?id=)