<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// จัดการการลบ
if (isset($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM location WHERE loc_id = ?");
        $stmt->execute([$_GET['delete']]);
        $success = "ลบสถานที่เรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "ไม่สามารถลบสถานที่ได้ (อาจมีออเดอร์ผูกอยู่): " . $e->getMessage();
    }
}

// ดึงข้อมูลสถานที่ทั้งหมด
$stmt = $pdo->query("
    SELECT l.*, u.User_name, v.village_name
    FROM location l
    LEFT JOIN `user` u ON l.User_ID = u.User_ID
    LEFT JOIN village v ON l.village_id = v.village_id
    ORDER BY l.loc_name ASC
");
$locations = $stmt->fetchAll();

// สถิติ
$total_bottles = array_sum(array_column($locations, 'bottle_on_hand'));
?>

<div class="dashboard">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2>จัดการสถานที่จัดส่ง (Locations)</h2>
        <a href="location_form.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> เพิ่มสถานที่ใหม่
        </a>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-map-marker-alt"></i>
            <h3>สถานที่ทั้งหมด</h3>
            <div class="stat-number"><?php echo count($locations); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-wine-bottle"></i>
            <h3>ขวดในมือรวม</h3>
            <div class="stat-number"><?php echo $total_bottles; ?></div>
        </div>
    </div>

    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="ค้นหาสถานที่..." onkeyup="searchTable()">
        <button class="btn btn-primary" onclick="searchTable()">
            <i class="fas fa-search"></i> ค้นหา
        </button>
    </div>

    <div class="table-container">
        <table id="locTable">
            <thead>
                <tr>
                    <th>Location ID</th>
                    <th>ชื่อสถานที่</th>
                    <th>รายละเอียด</th>
                    <th>เจ้าของ (ลูกค้า)</th>
                    <th>หมู่บ้าน</th>
                    <th>ขวดในมือ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($locations)): ?>
                    <tr><td colspan="7" style="text-align:center;">ไม่พบข้อมูลสถานที่</td></tr>
                <?php else: ?>
                    <?php foreach($locations as $loc): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($loc['loc_id']); ?></td>
                        <td><?php echo htmlspecialchars($loc['loc_name']); ?></td>
                        <td><?php echo htmlspecialchars($loc['details'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($loc['User_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($loc['village_name'] ?? '-'); ?></td>
                        <td style="font-weight:bold; color:<?php echo $loc['bottle_on_hand'] > 0 ? '#2ecc71' : '#e74c3c'; ?>">
                            <?php echo $loc['bottle_on_hand'] ?? 0; ?>
                        </td>
                        <td style="display:flex; gap:4px; flex-wrap:wrap;">
                            <a href="location_detail.php?id=<?php echo urlencode($loc['loc_id']); ?>"
                               class="btn btn-primary btn-sm">ดูข้อมูล</a>
                            <a href="location_form.php?id=<?php echo urlencode($loc['loc_id']); ?>"
                               class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?delete=<?php echo urlencode($loc['loc_id']); ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('ลบสถานที่นี้? หากมีออเดอร์ผูกอยู่จะลบไม่ได้')">
                               <i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function searchTable() {
    var filter = document.getElementById('searchInput').value.toUpperCase();
    var rows = document.getElementById('locTable').getElementsByTagName('tr');
    for (var i = 1; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName('td');
        var found = false;
        for (var j = 0; j < cells.length; j++) {
            if (cells[j].textContent.toUpperCase().indexOf(filter) > -1) { found = true; break; }
        }
        rows[i].style.display = found ? '' : 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>