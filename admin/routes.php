<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// จัดการการลบ
if (isset($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM route WHERE route_id = ?");
        $stmt->execute([$_GET['delete']]);
        $success = "ลบเส้นทางเรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "ไม่สามารถลบเส้นทางได้ (อาจมีหมู่บ้านหรือการมอบหมายผูกอยู่): " . $e->getMessage();
    }
}

// ดึงข้อมูลเส้นทางพร้อม villages, drivers, และจำนวนออเดอร์
$stmt = $pdo->query("
    SELECT 
        r.route_id,
        r.route_name,
        r.delivery_day,
        COUNT(DISTINCT v.village_id)       AS village_count,
        COUNT(DISTINCT ra.DID)             AS driver_count,
        COUNT(DISTINCT o.order_id)         AS order_count
    FROM route r
    LEFT JOIN village v   ON v.route_id   = r.route_id
    LEFT JOIN route_assignment ra ON ra.route_id = r.route_id
    LEFT JOIN location l  ON l.village_id = v.village_id
    LEFT JOIN `order` o   ON o.loc_id     = l.loc_id
    GROUP BY r.route_id, r.route_name, r.delivery_day
    ORDER BY r.route_name ASC
");
$routes = $stmt->fetchAll();
?>

<div class="dashboard">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2>จัดการเส้นทาง (Routes)</h2>
        <a href="route_form.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> เพิ่มเส้นทางใหม่
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
            <i class="fas fa-route"></i>
            <h3>เส้นทางทั้งหมด</h3>
            <div class="stat-number"><?php echo count($routes); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-home"></i>
            <h3>หมู่บ้านทั้งหมด</h3>
            <div class="stat-number"><?php echo array_sum(array_column($routes, 'village_count')); ?></div>
        </div>
    </div>

    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="ค้นหาเส้นทาง..." onkeyup="searchTable()">
        <button class="btn btn-primary" onclick="searchTable()">
            <i class="fas fa-search"></i> ค้นหา
        </button>
    </div>

    <div class="table-container">
        <table id="routeTable">
            <thead>
                <tr>
                    <th>Route ID</th>
                    <th>ชื่อเส้นทาง</th>
                    <th>วันส่ง</th>
                    <th>หมู่บ้าน</th>
                    <th>พนักงานขับ</th>
                    <th>ออเดอร์รวม</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($routes)): ?>
                    <tr><td colspan="7" style="text-align:center;">ไม่พบข้อมูลเส้นทาง</td></tr>
                <?php else: ?>
                    <?php foreach($routes as $route): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($route['route_id']); ?></td>
                        <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                        <td><?php echo htmlspecialchars($route['delivery_day'] ?? '-'); ?></td>
                        <td><?php echo $route['village_count']; ?> หมู่บ้าน</td>
                        <td><?php echo $route['driver_count']; ?> คน</td>
                        <td><?php echo $route['order_count']; ?></td>
                        <td style="display:flex; gap:4px; flex-wrap:wrap;">
                            <a href="route_detail.php?id=<?php echo urlencode($route['route_id']); ?>"
                               class="btn btn-primary btn-sm">ดูข้อมูล</a>
                            <a href="route_form.php?id=<?php echo urlencode($route['route_id']); ?>"
                               class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?delete=<?php echo urlencode($route['route_id']); ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('ลบเส้นทางนี้? หากมีหมู่บ้านผูกอยู่จะลบไม่ได้')">
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
    var rows = document.getElementById('routeTable').getElementsByTagName('tr');
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