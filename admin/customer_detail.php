<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// Get customer ID from URL, exit if missing
$user_id = $_GET['id'] ?? null;
if (!$user_id) {
    die("ไม่พบข้อมูลลูกค้า");
}

// Fetch customer info
$stmt = $pdo->prepare("SELECT * FROM `User` WHERE User_ID = ?");
$stmt->execute([$user_id]);
$customer = $stmt->fetch();

if (!$customer) {
    die("ไม่พบข้อมูลลูกค้า");
}

// Fetch their locations
$stmt = $pdo->prepare("SELECT * FROM Location WHERE User_ID = ?");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

// Fetch their order history
$stmt = $pdo->prepare("
    SELECT o.order_id, o.scheduled_date, o.qty_ordered, o.total_expected_price,
           o.order_status, o.payment_status, l.loc_name,
           d.DFname, d.DLname
    FROM `Order` o
    JOIN Location l ON o.loc_id = l.loc_id
    JOIN Driver d ON o.DID = d.DID
    WHERE o.User_ID = ?
    ORDER BY o.scheduled_date DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();
?>

<div class="dashboard">
    <a href="customers.php" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <h2>ข้อมูลลูกค้า: <?php echo htmlspecialchars($customer['User_name']); ?></h2>

    <!-- Customer Info -->
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-id-card"></i>
            <h3>Customer ID</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo htmlspecialchars($customer['User_ID']); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-phone"></i>
            <h3>เบอร์โทร</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo htmlspecialchars($customer['tel'] ?? '-'); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-tag"></i>
            <h3>ประเภท</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo $customer['is_guest'] === '1' ? 'Guest' : 'Member'; ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-exclamation-circle"></i>
            <h3>ยอดค้างชำระ</h3>
            <div class="stat-number" style="color: <?php echo $customer['Unpaid_amount'] > 0 ? '#e74c3c' : '#2ecc71'; ?>">
                <?php echo number_format($customer['Unpaid_amount'], 2); ?> บาท
            </div>
        </div>
    </div>

    <!-- Locations -->
    <div class="table-container">
        <h3>สถานที่จัดส่ง</h3>
        <?php if (empty($locations)): ?>
            <p>ไม่มีสถานที่จัดส่ง</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Location ID</th>
                    <th>ชื่อสถานที่</th>
                    <th>รายละเอียด</th>
                    <th>ขวดในมือ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($locations as $loc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loc['loc_id']); ?></td>
                    <td><?php echo htmlspecialchars($loc['loc_name']); ?></td>
                    <td><?php echo htmlspecialchars($loc['details'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($loc['bottle_on_hand'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Order History -->
    <div class="table-container">
        <h3>ประวัติออเดอร์</h3>
        <?php if (empty($orders)): ?>
            <p>ไม่มีประวัติออเดอร์</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>วันที่</th>
                    <th>สถานที่</th>
                    <th>พนักงานขับ</th>
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
                    <td><?php echo htmlspecialchars($order['loc_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['DFname'] . ' ' . $order['DLname']); ?></td>
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
