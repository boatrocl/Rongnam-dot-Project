<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

$loc_id = $_GET['id'] ?? null;
if (!$loc_id) die("ไม่พบข้อมูลสถานที่");

// ดึงข้อมูลสถานที่
$stmt = $pdo->prepare("
    SELECT l.*, u.User_name, v.village_name
    FROM location l
    LEFT JOIN `user` u ON l.User_ID = u.User_ID
    LEFT JOIN village v ON l.village_id = v.village_id
    WHERE l.loc_id = ?
");
$stmt->execute([$loc_id]);
$loc = $stmt->fetch();
if (!$loc) die("ไม่พบข้อมูลสถานที่");

// ดึงประวัติออเดอร์
$stmt = $pdo->prepare("
    SELECT o.order_id, o.scheduled_date, o.qty_ordered, o.total_expected_price,
           o.order_status, o.payment_status,
           u.User_name, d.DFname, d.DLname
    FROM `order` o
    JOIN `user` u ON o.User_ID = u.User_ID
    JOIN driver d ON o.DID = d.DID
    WHERE o.loc_id = ?
    ORDER BY o.scheduled_date DESC
");
$stmt->execute([$loc_id]);
$orders = $stmt->fetchAll();

// สถิติออเดอร์
$total_orders     = count($orders);
$completed_orders = count(array_filter($orders, fn($o) => $o['order_status'] === 'completed'));
$total_revenue    = array_sum(array_column(
    array_filter($orders, fn($o) => $o['order_status'] === 'completed'),
    'total_expected_price'
));
?>

<div class="dashboard">
    <a href="locations.php" style="display:inline-block; margin-bottom:16px;">← กลับ</a>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2>ข้อมูลสถานที่: <?php echo htmlspecialchars($loc['loc_name']); ?></h2>
        <a href="location_form.php?id=<?php echo urlencode($loc['loc_id']); ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> แก้ไขสถานที่
        </a>
    </div>

    <!-- Stats -->
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-map-marker-alt"></i>
            <h3>Location ID</h3>
            <div class="stat-number" style="font-size:1.2rem;"><?php echo htmlspecialchars($loc['loc_id']); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-user"></i>
            <h3>เจ้าของ</h3>
            <div class="stat-number" style="font-size:1.1rem;"><?php echo htmlspecialchars($loc['User_name'] ?? '-'); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-wine-bottle"></i>
            <h3>ขวดในมือ</h3>
            <div class="stat-number" style="color:<?php echo $loc['bottle_on_hand'] > 0 ? '#2ecc71' : '#e74c3c'; ?>">
                <?php echo $loc['bottle_on_hand'] ?? 0; ?>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-shopping-cart"></i>
            <h3>ออเดอร์ทั้งหมด</h3>
            <div class="stat-number"><?php echo $total_orders; ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <h3>สำเร็จแล้ว</h3>
            <div class="stat-number" style="color:#2ecc71;"><?php echo $completed_orders; ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-money-bill"></i>
            <h3>ยอดขายรวม</h3>
            <div class="stat-number" style="font-size:1rem;"><?php echo number_format($total_revenue, 2); ?> บาท</div>
        </div>
    </div>

    <!-- Location Info -->
    <div class="table-container">
        <h3>ข้อมูลสถานที่</h3>
        <table>
            <tbody>
                <tr><td><strong>รายละเอียด</strong></td><td><?php echo htmlspecialchars($loc['details'] ?? '-'); ?></td></tr>
                <tr><td><strong>หมู่บ้าน</strong></td><td><?php echo htmlspecialchars($loc['village_name'] ?? '-'); ?></td></tr>
                <tr><td><strong>พิกัด GPS</strong></td>
                    <td>
                        <?php if ($loc['latitude'] && $loc['longitude']): ?>
                            <?php echo $loc['latitude']; ?>, <?php echo $loc['longitude']; ?>
                            <a href="https://maps.google.com/?q=<?php echo $loc['latitude']; ?>,<?php echo $loc['longitude']; ?>"
                               target="_blank" style="margin-left:8px; color:#3498db;">
                               <i class="fas fa-external-link-alt"></i> Google Maps
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Order History -->
    <div class="table-container">
        <h3>ประวัติออเดอร์</h3>
        <?php if (empty($orders)): ?>
            <p style="color:#999;">ไม่มีประวัติออเดอร์</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>วันที่</th>
                    <th>ลูกค้า</th>
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
                    <td><?php echo htmlspecialchars($order['User_name']); ?></td>
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