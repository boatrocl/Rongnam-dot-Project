<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

/**
 * 1. Fetch Customer Data
 * We use backticks for `User` because it is a reserved keyword in SQL.
 * We are fetching the ID, Name, Phone, and their current Unpaid Balance.
 */
$stmt = $pdo->query("SELECT User_ID, User_name, tel, Unpaid_amount, is_guest FROM `User` ORDER BY User_name ASC");
$customers = $stmt->fetchAll();

// Optional: Calculate total unpaid amount for a mini-stat
$total_unpaid = array_sum(array_column($customers, 'Unpaid_amount'));
?>

<div class="dashboard">
    <h2>จัดการข้อมูลลูกค้า (Customer Management)</h2>
    
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-users"></i>
            <h3>จำนวนลูกค้าทั้งหมด</h3>
            <div class="stat-number"><?php echo count($customers); ?></div>
        </div>
        
        <div class="stat-card">
            <i class="fas fa-exclamation-circle"></i>
            <h3>ยอดค้างชำระรวม</h3>
            <div class="stat-number" style="color: #e74c3c;">
                <?php echo number_format($total_unpaid, 2); ?> บาท
            </div>
        </div>
    </div>


    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>จัดการข้อมูลลูกค้า (Customer Management)</h2>
    <a href="add_customer.php" class="status-badge status-completed" style="text-decoration:none; padding: 10px 20px; background-color: #2ecc71;">
        <i class="fas fa-plus"></i> เพิ่มผู้ใช้ใหม่
    </a>
</div>
    
    <div class="table-container">
        <h3>รายชื่อลูกค้าในระบบ</h3>
        <table>
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>เบอร์โทรศัพท์</th>
                    <th>ประเภท</th>
                    <th>ยอดค้างชำระ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center;">ไม่พบข้อมูลลูกค้า</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($customers as $customer): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($customer['User_ID']); ?></td>
                        <td><?php echo htmlspecialchars($customer['User_name']); ?></td>
                        <td><?php echo htmlspecialchars($customer['tel'] ?? '-'); ?></td>
                        <td>
                            <?php echo $customer['is_guest'] === '1' ? 'Guest' : 'Member'; ?>
                        </td>
                        <td style="font-weight: bold; color: <?php echo $customer['Unpaid_amount'] > 0 ? '#e74c3c' : '#2ecc71'; ?>">
                            <?php echo number_format($customer['Unpaid_amount'], 2); ?>
                        </td>
                        <td>
                            <a href="customer_detail.php?id=<?php echo urlencode($customer['User_ID']); ?>" 
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