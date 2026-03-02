<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

$user_id = $_SESSION['ref_id'];

// 1. Fetch User Profile
$stmt = $pdo->prepare("SELECT * FROM `user` WHERE User_ID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// 2. Fetch Customer Locations (Houses/Shops)
$stmt = $pdo->prepare("SELECT * FROM location WHERE User_ID = ?");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

// 3. Fetch Recent Orders
$stmt = $pdo->prepare("
    SELECT o.*, l.loc_name 
    FROM `order` o 
    JOIN location l ON o.loc_id = l.loc_id 
    WHERE o.User_ID = ? 
    ORDER BY o.scheduled_date DESC LIMIT 5
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// 4. Fetch Active Subscriptions
$stmt = $pdo->prepare("
    SELECT s.*, l.loc_name 
    FROM subscription s 
    JOIN location l ON s.loc_id = l.loc_id 
    WHERE l.User_ID = ? AND s.status = 'active'
");
$stmt->execute([$user_id]);
$subscriptions = $stmt->fetchAll();

require_once '../includes/header_customer.php';
?>

<div class="dashboard">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <h2 style="margin:0;">ยินดีต้อนรับคุณ, <?php echo htmlspecialchars($user['User_name']); ?></h2>
            <p style="color: #7f8c8d; margin: 5px 0 0 0;">รหัสลูกค้า: <?php echo $user['User_ID']; ?></p>
        </div>
        
    </div>

    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-wallet" style="color: #f39c12;"></i>
            <h3>ยอดค้างชำระปัจจุบัน</h3>
            <div class="stat-number" style="color: <?php echo $user['Unpaid_amount'] > 0 ? '#e74c3c' : '#2ecc71'; ?>">
                <?php echo number_format($user['Unpaid_amount'], 2); ?> บาท
            </div>
        </div>

        <div class="stat-card">
            <i class="fas fa-map-marker-alt" style="color: #3498db;"></i>
            <h3>สถานที่จัดส่ง</h3>
            <div class="stat-number"><?php echo count($locations); ?> แห่ง</div>
        </div>

        <div class="stat-card">
            <i class="fas fa-calendar-check" style="color: #2ecc71;"></i>
            <h3>สมาชิกรายเดือน</h3>
            <div class="stat-number"><?php echo count($subscriptions); ?> รายการ</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
        
        <div class="table-container">
            <h3><i class="fas fa-home"></i> สถานที่ของฉัน</h3>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อสถานที่</th>
                        <th>จำนวนถังคงเหลือ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($locations as $loc): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($loc['loc_name']); ?></td>
                        <td><?php echo $loc['bottle_on_hand']; ?> ถัง</td>
                        <td>
                            <a href="request_order.php?loc_id=<?php echo $loc['loc_id']; ?>" class="status-badge status-completed" style="text-decoration:none;">สั่งน้ำ</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-container">
            <h3><i class="fas fa-history"></i> ประวัติการสั่งซื้อล่าสุด</h3>
            <table>
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>สถานที่</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($orders)): ?>
                        <tr><td colspan="3" style="text-align:center;">ยังไม่มีประวัติการสั่งซื้อ</td></tr>
                    <?php else: ?>
                        <?php foreach($orders as $order): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($order['scheduled_date'])); ?></td>
                            <td><?php echo htmlspecialchars($order['loc_name']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $order['order_status'] === 'completed' ? 'completed' : 'pending'; ?>">
                                    <?php echo $order['order_status']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<?php require_once '../includes/footer.php'; ?>