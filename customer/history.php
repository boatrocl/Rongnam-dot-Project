<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

$user_id = $_SESSION['ref_id'];

// Fetch combined history: Both Order Requests and Actual Orders
// We use UNION to show the flow from request to completed order
$query = "
    (SELECT 
        requested_date as display_date, 
        'Request' as record_type, 
        qty, 
        status as current_status, 
        loc_name,
        request_id as ref_no
    FROM order_request 
    JOIN location ON order_request.loc_id = location.loc_id
    WHERE order_request.User_ID = ?)
    
    UNION ALL
    
    (SELECT 
        scheduled_date as display_date, 
        'Delivery' as record_type, 
        qty_ordered as qty, 
        order_status as current_status, 
        loc_name,
        order_id as ref_no
    FROM `order` 
    JOIN location ON `order`.loc_id = location.loc_id
    WHERE `order`.User_ID = ?)
    
    ORDER BY display_date DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id, $user_id]);
$history = $stmt->fetchAll();

require_once '../includes/header_customer.php';
?>

<div class="dashboard">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <h2><i class="fas fa-history"></i> ประวัติการสั่งซื้อทั้งหมด (Order History)</h2>
        <a href="index.php" style="text-decoration: none; color: var(--primary-blue);">
            <i class="fas fa-chevron-left"></i> กลับหน้าหลัก
        </a>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr style="background-color: #f8f9fa;">
                    <th>วันที่</th>
                    <th>เลขที่อ้างอิง</th>
                    <th>ประเภท</th>
                    <th>สถานที่จัดส่ง</th>
                    <th>จำนวน (ถัง)</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-box-open" style="font-size: 3rem; display: block; margin-bottom: 10px;"></i>
                            ยังไม่มีประวัติการทำรายการ
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($row['display_date'])); ?></td>
                            <td style="font-family: monospace; font-weight: bold;"><?php echo $row['ref_no']; ?></td>
                            <td>
                                <small style="color: #7f8c8d; text-transform: uppercase;">
                                    <?php echo $row['record_type'] === 'Request' ? 'ใบคำขอ' : 'รายการส่งน้ำ'; ?>
                                </small>
                            </td>
                            <td><?php echo htmlspecialchars($row['loc_name']); ?></td>
                            <td><?php echo $row['qty']; ?></td>
                            <td>
                                <?php
                                    $status = $row['current_status'];
                                    $badge_color = '#95a5a6'; // Default grey
                                    $thai_status = $status;

                                    switch($status) {
                                        case 'pending_admin': $badge_color = '#f39c12'; $thai_status = 'รออนุมัติ'; break;
                                        case 'approved': $badge_color = '#3498db'; $thai_status = 'อนุมัติแล้ว'; break;
                                        case 'rejected': $badge_color = '#e74c3c'; $thai_status = 'ปฏิเสธ'; break;
                                        case 'completed': $badge_color = '#2ecc71'; $thai_status = 'จัดส่งสำเร็จ'; break;
                                        case 'pending': $badge_color = '#f1c40f'; $thai_status = 'กำลังดำเนินการ'; break;
                                    }
                                ?>
                                <span class="status-badge" style="background: <?php echo $badge_color; ?>; color: white;">
                                    <?php echo $thai_status; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>