<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// สถิติรายเดือน
$current_month = date('Y-m');
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(scheduled_date, '%Y-%m-%d') as date,
        COUNT(*) as order_count,
        SUM(total_expected_price) as daily_revenue
    FROM `order` 
    WHERE DATE_FORMAT(scheduled_date, '%Y-%m') = ?
    GROUP BY DATE_FORMAT(scheduled_date, '%Y-%m-%d')
    ORDER BY date
");
$stmt->execute([$current_month]);
$daily_stats = $stmt->fetchAll();

// สถานะออเดอร์
$stmt = $pdo->query("
    SELECT order_status, COUNT(*) as count 
    FROM `order` 
    GROUP BY order_status
");
$order_status = $stmt->fetchAll();

// พนักงานขับที่มีออเดอร์มากที่สุด
$stmt = $pdo->query("
    SELECT d.DID, d.DFname, d.DLname, COUNT(*) as order_count
    FROM driver d
    JOIN `order` o ON d.DID = o.DID
    GROUP BY d.DID
    ORDER BY order_count DESC
    LIMIT 5
");
$top_drivers = $stmt->fetchAll();

// เส้นทางยอดนิยม
$stmt = $pdo->query("
    SELECT r.route_name, COUNT(*) as order_count
    FROM route r
    JOIN village v ON r.route_id = v.route_id
    JOIN location l ON v.village_id = l.village_id
    JOIN `order` o ON l.loc_id = o.loc_id
    GROUP BY r.route_id
    ORDER BY order_count DESC
    LIMIT 5
");
$top_routes = $stmt->fetchAll();
?>

<div class="dashboard">
    <h2>แดชบอร์ด</h2>
    
    <div class="table-container" style="margin-bottom: 20px;">
        <h3>สถิติรายวัน (เดือนนี้)</h3>
        <table>
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>จำนวนออเดอร์</th>
                    <th>รายได้</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($daily_stats as $stat): ?>
                <tr>
                    <td><?php echo htmlspecialchars($stat['date']); ?></td>
                    <td><?php echo htmlspecialchars($stat['order_count']); ?></td>
                    <td><?php echo number_format($stat['daily_revenue'], 2); ?> บาท</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="table-container">
            <h3>สถานะออเดอร์</h3>
            <table>
                <thead>
                    <tr>
                        <th>สถานะ</th>
                        <th>จำนวน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($order_status as $status): ?>
                    <tr>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($status['order_status']); ?>">
                                <?php echo htmlspecialchars($status['order_status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($status['count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="table-container">
            <h3>พนักงานขับยอดนิยม</h3>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อ-นามสกุล</th>
                        <th>จำนวนออเดอร์</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($top_drivers as $driver): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($driver['DFname'] . ' ' . $driver['DLname']); ?></td>
                        <td><?php echo htmlspecialchars($driver['order_count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="table-container">
            <h3>เส้นทางยอดนิยม</h3>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อเส้นทาง</th>
                        <th>จำนวนออเดอร์</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($top_routes as $route): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                        <td><?php echo htmlspecialchars($route['order_count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>