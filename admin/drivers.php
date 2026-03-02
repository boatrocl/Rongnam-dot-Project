<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// Fetch all drivers
$stmt = $pdo->query("SELECT * FROM Driver ORDER BY DFname ASC");
$drivers = $stmt->fetchAll();

// Count today's active drivers (those who have orders today)
$stmt2 = $pdo->query("SELECT COUNT(DISTINCT DID) as count FROM `Order` WHERE DATE(scheduled_date) = CURDATE()");
$active_today = $stmt2->fetch()['count'];
?>

<div class="dashboard">
    <h2>จัดการพนักงานขับ (Driver Management)</h2>

    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-truck"></i>
            <h3>พนักงานขับทั้งหมด</h3>
            <div class="stat-number"><?php echo count($drivers); ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-calendar-day"></i>
            <h3>ขับวันนี้</h3>
            <div class="stat-number"><?php echo $active_today; ?></div>
        </div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>จัดการข้อมูลลูกค้า (Customer Management)</h2>
    <a href="add_customer.php" class="status-badge status-completed" style="text-decoration:none; padding: 10px 20px; background-color: #2ecc71;">
        <i class="fas fa-plus"></i> เพิ่มผู้ใช้ใหม่
    </a>

    <div class="table-container">
        <h3>รายชื่อพนักงานขับ</h3>
        <table>
            <thead>
                <tr>
                    <th>Driver ID</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>Username</th>
                    <th>เบอร์โทร</th>
                    <th>ที่อยู่</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($drivers)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;">ไม่พบข้อมูลพนักงานขับ</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($drivers as $driver): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($driver['DID']); ?></td>
                        <td><?php echo htmlspecialchars($driver['DFname'] . ' ' . $driver['DLname']); ?></td>
                        <td><?php echo htmlspecialchars($driver['Username'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($driver['tel'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($driver['Address'] ?? '-'); ?></td>
                        <td>
                            <a href="driver_detail.php?id=<?php echo urlencode($driver['DID']); ?>"
                               class="status-badge status-completed" style="text-decoration:none;">ดูข้อมูล</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>