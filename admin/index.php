<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// ดึงข้อมูลสรุป
$stats = [];

// จำนวนออเดอร์วันนี้
$stmt = $pdo->query("SELECT COUNT(*) as count FROM `order` WHERE DATE(scheduled_date) = CURDATE()");
$stats['today_orders'] = $stmt->fetch()['count'];

// จำนวนออเดอร์ทั้งหมด
$stmt = $pdo->query("SELECT COUNT(*) as count FROM `order`");
$stats['total_orders'] = $stmt->fetch()['count'];

// จำนวนลูกค้า
$stmt = $pdo->query("SELECT COUNT(*) as count FROM `user`");
$stats['total_customers'] = $stmt->fetch()['count'];

// จำนวนพนักงานขับ
$stmt = $pdo->query("SELECT COUNT(*) as count FROM driver");
$stats['total_drivers'] = $stmt->fetch()['count'];

// ยอดขายรวม
$stmt = $pdo->query("SELECT SUM(total_expected_price) as total FROM `order` WHERE order_status = 'completed'");
$stats['total_revenue'] = $stmt->fetch()['total'] ?? 0;

// ออเดอร์ล่าสุด
$stmt = $pdo->query("
    SELECT o.*, u.User_name, l.loc_name, d.DFname, d.DLname 
    FROM `order` o
    JOIN `user` u ON o.User_ID = u.User_ID
    JOIN location l ON o.loc_id = l.loc_id
    JOIN driver d ON o.DID = d.DID
    ORDER BY o.scheduled_date DESC
    LIMIT 5
");
$recent_orders = $stmt->fetchAll();
?>

<div class="dashboard">
    <h2>ยินดีต้อนรับสู่ระบบจัดการน้ำดื่ม</h2>
    
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-shopping-cart"></i>
            <h3>ออเดอร์วันนี้</h3>
            <div class="stat-number"><?php echo $stats['today_orders']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-box"></i>
            <h3>ออเดอร์ทั้งหมด</h3>
            <div class="stat-number"><?php echo $stats['total_orders']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-users"></i>
            <h3>ลูกค้าทั้งหมด</h3>
            <div class="stat-number"><?php echo $stats['total_customers']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-truck"></i>
            <h3>พนักงานขับ</h3>
            <div class="stat-number"><?php echo $stats['total_drivers']; ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-money-bill"></i>
            <h3>ยอดขายรวม</h3>
            <div class="stat-number"><?php echo number_format($stats['total_revenue'], 2); ?> บาท</div>
        </div>
    </div>
    
    <div class="table-container">
        <h3>ออเดอร์ล่าสุด</h3>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>ลูกค้า</th>
                    <th>สถานที่</th>
                    <th>พนักงานขับ</th>
                    <th>วันที่</th>
                    <th>จำนวน</th>
                    <th>สถานะ</th>
                    <th>การชำระเงิน</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($recent_orders as $order): ?>
                <tr>
                    <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                    <td><?php echo htmlspecialchars($order['User_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['loc_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['DFname'] . ' ' . $order['DLname']); ?></td>
                    <td><?php echo htmlspecialchars($order['scheduled_date']); ?></td>
                    <td><?php echo htmlspecialchars($order['qty_ordered']); ?></td>
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
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>