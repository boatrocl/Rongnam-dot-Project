<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';


// ลบหมู่บ้าน
if (isset($_GET['delete_village'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM village WHERE village_id = ? AND route_id = ?");
        $stmt->execute([$_GET['delete_village'], $_GET['id']]);
        $success = "ลบหมู่บ้านเรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "ลบไม่ได้: " . $e->getMessage();
    }
}

// ลบการมอบหมาย
if (isset($_GET['delete_assignment'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM route_assignment WHERE assignment_id = ?");
        $stmt->execute([$_GET['delete_assignment']]);
        $success = "ยกเลิกการมอบหมายเรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "ลบไม่ได้: " . $e->getMessage();
    }
}

$route_id = $_GET['id'] ?? null;
if (!$route_id) die("ไม่พบข้อมูลเส้นทาง");

// ดึงข้อมูลเส้นทาง
$stmt = $pdo->prepare("SELECT * FROM route WHERE route_id = ?");
$stmt->execute([$route_id]);
$route = $stmt->fetch();
if (!$route) die("ไม่พบข้อมูลเส้นทาง");

// ดึงหมู่บ้านในเส้นทางนี้
$stmt = $pdo->prepare("
    SELECT v.village_id, v.village_name,
           COUNT(l.loc_id) AS location_count
    FROM village v
    LEFT JOIN location l ON l.village_id = v.village_id
    WHERE v.route_id = ?
    GROUP BY v.village_id, v.village_name
    ORDER BY v.village_name ASC
");
$stmt->execute([$route_id]);
$villages = $stmt->fetchAll();

// ดึงการมอบหมายพนักงานขับ
$stmt = $pdo->prepare("
    SELECT ra.assignment_id, ra.day_of_week,
           d.DID, d.DFname, d.DLname, d.tel
    FROM route_assignment ra
    JOIN driver d ON ra.DID = d.DID
    WHERE ra.route_id = ?
    ORDER BY ra.day_of_week ASC
");
$stmt->execute([$route_id]);
$assignments = $stmt->fetchAll();

// จำนวนออเดอร์ในเส้นทางนี้
$stmt = $pdo->prepare("
    SELECT COUNT(o.order_id) AS total,
           SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed
    FROM `order` o
    JOIN location l ON o.loc_id = l.loc_id
    JOIN village v  ON l.village_id = v.village_id
    WHERE v.route_id = ?
");
$stmt->execute([$route_id]);
$order_stats = $stmt->fetch();
?>

<div class="dashboard">
    <a href="routes.php" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2>เส้นทาง: <?php echo htmlspecialchars($route['route_name']); ?></h2>
        <a href="route_form.php?id=<?php echo urlencode($route['route_id']); ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> แก้ไขเส้นทาง
        </a>
    </div>

    <!-- Stats -->
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-route"></i>
            <h3>Route ID</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo htmlspecialchars($route['route_id']); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-calendar-day"></i>
            <h3>วันส่ง</h3>
            <div class="stat-number" style="font-size:1.1rem;"><?php echo htmlspecialchars($route['delivery_day'] ?? '-'); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-home"></i>
            <h3>หมู่บ้าน</h3>
            <div class="stat-number"><?php echo count($villages); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-truck"></i>
            <h3>พนักงานขับ</h3>
            <div class="stat-number"><?php echo count($assignments); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-shopping-cart"></i>
            <h3>ออเดอร์รวม</h3>
            <div class="stat-number"><?php echo $order_stats['total'] ?? 0; ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <h3>สำเร็จแล้ว</h3>
            <div class="stat-number" style="color:#2ecc71;"><?php echo $order_stats['completed'] ?? 0; ?></div>
        </div>
    </div>

    <!-- Villages -->
    <div class="table-container">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3>หมู่บ้านในเส้นทางนี้</h3>
            <a href="village_form.php?route_id=<?php echo urlencode($route['route_id']); ?>"
               class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> เพิ่มหมู่บ้าน
            </a>
        </div>
        <?php if (empty($villages)): ?>
            <p style="color:#999;">ยังไม่มีหมู่บ้านในเส้นทางนี้</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Village ID</th>
                    <th>ชื่อหมู่บ้าน</th>
                    <th>จำนวนสถานที่</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($villages as $v): ?>
                <tr>
                    <td><?php echo htmlspecialchars($v['village_id']); ?></td>
                    <td><?php echo htmlspecialchars($v['village_name']); ?></td>
                    <td><?php echo $v['location_count']; ?> สถานที่</td>
                    <td>
                        <a href="village_form.php?id=<?php echo urlencode($v['village_id']); ?>&route_id=<?php echo urlencode($route['route_id']); ?>"
                           class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                        <a href="?id=<?php echo urlencode($route['route_id']); ?>&delete_village=<?php echo urlencode($v['village_id']); ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('ลบหมู่บ้านนี้ออกจากเส้นทาง?')">
                           <i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Driver Assignments -->
    <div class="table-container">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3>พนักงานขับที่รับผิดชอบ</h3>
            <a href="assignment_form.php?route_id=<?php echo urlencode($route['route_id']); ?>"
               class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> มอบหมายพนักงานขับ
            </a>
        </div>
        <?php if (empty($assignments)): ?>
            <p style="color:#999;">ยังไม่มีพนักงานขับที่มอบหมาย</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Assignment ID</th>
                    <th>วันในสัปดาห์</th>
                    <th>พนักงานขับ</th>
                    <th>เบอร์โทร</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($assignments as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['assignment_id']); ?></td>
                    <td><?php echo htmlspecialchars($a['day_of_week']); ?></td>
                    <td><?php echo htmlspecialchars($a['DFname'] . ' ' . $a['DLname']); ?></td>
                    <td><?php echo htmlspecialchars($a['tel'] ?? '-'); ?></td>
                    <td>
                        <a href="?id=<?php echo urlencode($route['route_id']); ?>&delete_assignment=<?php echo urlencode($a['assignment_id']); ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('ยกเลิกการมอบหมายนี้?')">
                           <i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>