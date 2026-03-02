<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$did = $_GET['id'] ?? null;
if (!$did) die("ไม่พบข้อมูลพนักงานขับ");

// Fetch driver info
$stmt = $pdo->prepare("SELECT * FROM Driver WHERE DID = ?");
$stmt->execute([$did]);
$driver = $stmt->fetch();
if (!$driver) die("ไม่พบข้อมูลพนักงานขับ");

// Fetch order stats for this driver
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN DATE(scheduled_date) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM `Order` WHERE DID = ?
");
$stmt->execute([$did]);
$stats = $stmt->fetch();

// Fetch route assignments (WIP - routes page not built yet)
$stmt = $pdo->prepare("
    SELECT ra.*, r.route_name, r.delivery_day
    FROM Route_Assignment ra
    JOIN Route r ON ra.route_id = r.route_id
    WHERE ra.DID = ?
    ORDER BY ra.day_of_week ASC
");
$stmt->execute([$did]);
$routes = $stmt->fetchAll();

// Fetch recent orders for this driver
$stmt = $pdo->prepare("
    SELECT o.order_id, o.scheduled_date, o.qty_ordered, o.total_expected_price,
           o.order_status, o.payment_status,
           u.User_name, l.loc_name
    FROM `Order` o
    JOIN `User` u ON o.User_ID = u.User_ID
    JOIN Location l ON o.loc_id = l.loc_id
    WHERE o.DID = ?
    ORDER BY o.scheduled_date DESC
    LIMIT 10
");
$stmt->execute([$did]);
$orders = $stmt->fetchAll();
?>

<div class="dashboard">
    <a href="drivers.php" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <h2>ข้อมูลพนักงานขับ: <?php echo htmlspecialchars($driver['DFname'] . ' ' . $driver['DLname']); ?></h2>

    <!-- Stats -->
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-id-card"></i>
            <h3>Driver ID</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo htmlspecialchars($driver['DID']); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-phone"></i>
            <h3>เบอร์โทร</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo htmlspecialchars($driver['tel'] ?? '-'); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-box"></i>
            <h3>ออเดอร์ทั้งหมด</h3>
            <div class="stat-number"><?php echo $stats['total_orders']; ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <h3>สำเร็จแล้ว</h3>
            <div class="stat-number" style="color:#2ecc71;"><?php echo $stats['completed']; ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-calendar-day"></i>
            <h3>ออเดอร์วันนี้</h3>
            <div class="stat-number"><?php echo $stats['today']; ?></div>
        </div>
    </div>

    <!-- Driver Info -->
    <div class="table-container">
        <h3>ข้อมูลส่วนตัว</h3>
        <table>
            <tbody>
                <tr><td><strong>Username</strong></td><td><?php echo htmlspecialchars($driver['Username'] ?? '-'); ?></td></tr>
                <tr><td><strong>ที่อยู่</strong></td><td><?php echo htmlspecialchars($driver['Address'] ?? '-'); ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Route Assignments (WIP) -->
    <div class="table-container">
        <h3>เส้นทางที่รับผิดชอบ <span style="font-size:0.8rem; color:#999;">(WIP — หน้าเส้นทางยังไม่พร้อม)</span></h3>
        <?php if (empty($routes)): ?>
            <p style="color:#999;">ไม่มีเส้นทางที่กำหนด</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>วันในสัปดาห์</th>
                    <th>ชื่อเส้นทาง</th>
                    <th>วันส่ง</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($routes as $route): ?>
                <tr>
                    <td><?php echo htmlspecialchars($route['day_of_week']); ?></td>
                    <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                    <td><?php echo htmlspecialchars($route['delivery_day']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Recent Orders -->
    <div class="table-container">
        <h3>ออเดอร์ล่าสุด (10 รายการ)</h3>
        <?php if (empty($orders)): ?>
            <p style="color:#999;">ไม่มีประวัติออเดอร์</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>วันที่</th>
                    <th>ลูกค้า</th>
                    <th>สถานที่</th>
                    <th>จำนวน</th>
                    <th>ยอดรวม</th>
                    <th>สถานะ</th>
                    <th>การชำระเงิน</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $order): ?>
                <tr>
                    <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                    <td><?php echo htmlspecialchars($order['scheduled_date']); ?></td>
                    <td><?php echo htmlspecialchars($order['User_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['loc_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['qty_ordered']); ?></td>
                    <td><?php echo number_format($order['total_expected_price'], 2); ?> บาท</td>
                    <td>
                        <span class="status-badge status-<?php echo strtolower($order['order_status']); ?>">
                            <?php echo htmlspecialchars($order['order_status']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo strtolower($order['payment_status']); ?>">
                            <?php echo htmlspecialchars($order['payment_status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
```

---

## File structure so far
```
pages/
├── drivers.php          ← list of all drivers
├── driver_detail.php    ← individual driver page
├── customers.php
├── customer_detail.php
└── orders.php