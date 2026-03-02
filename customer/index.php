<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

$user_id = $_SESSION['ref_id'];

// 1. Fetch User Profile
$stmt = $pdo->prepare("SELECT * FROM `user` WHERE User_ID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// 2. Fetch Customer Locations
$stmt = $pdo->prepare("SELECT l.*, v.village_name FROM location l LEFT JOIN village v ON l.village_id = v.village_id WHERE l.User_ID = ?");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

// 3. Fetch Pending Order Requests (New Section)
$stmt = $pdo->prepare("
    SELECT r.*, l.loc_name 
    FROM order_request r 
    JOIN location l ON r.loc_id = l.loc_id 
    WHERE r.User_ID = ? AND r.status = 'pending_admin'
    ORDER BY r.requested_at DESC
");
$stmt->execute([$user_id]);
$pending_requests = $stmt->fetchAll();

// 4. Fetch Villages (for the Add Location dropdown)
$villages = $pdo->query("SELECT * FROM village")->fetchAll();

// 5. Fetch Recent Orders (Completed/Processing)
$stmt = $pdo->prepare("
    SELECT o.*, l.loc_name 
    FROM `order` o 
    JOIN location l ON o.loc_id = l.loc_id 
    WHERE o.User_ID = ? 
    ORDER BY o.scheduled_date DESC LIMIT 5
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

require_once '../includes/header_customer.php';
?>

<div class="dashboard">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <h2 style="margin:0;">ยินดีต้อนรับคุณ, <?php echo htmlspecialchars($user['User_name']); ?></h2>
            <p style="color: #7f8c8d;">รหัสลูกค้า: <?php echo $user['User_ID']; ?></p>
        </div>
        <button onclick="openLocationModal()" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">
            <i class="fas fa-plus"></i> เพิ่มสถานที่จัดส่ง
        </button>
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
            <i class="fas fa-clock" style="color: #e67e22;"></i>
            <h3>คำขอที่รอการยืนยัน</h3>
            <div class="stat-number"><?php echo count($pending_requests); ?> รายการ</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-map-marker-alt" style="color: #3498db;"></i>
            <h3>สถานที่ทั้งหมด</h3>
            <div class="stat-number"><?php echo count($locations); ?> แห่ง</div>
        </div>
    </div>

    <div class="table-container" style="margin-bottom: 25px; border-left: 5px solid #e67e22;">
        <h3><i class="fas fa-hourglass-half"></i> คำสั่งซื้อที่รอการยืนยัน (Pending Requests)</h3>
        <table>
            <thead>
                <tr>
                    <th>วันที่ขอ</th>
                    <th>สถานที่</th>
                    <th>จำนวน</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($pending_requests)): ?>
                    <tr><td colspan="4" style="text-align:center;">ไม่มีคำค้างรอการยืนยัน</td></tr>
                <?php else: ?>
                    <?php foreach($pending_requests as $req): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($req['requested_date'])); ?></td>
                        <td><?php echo htmlspecialchars($req['loc_name']); ?></td>
                        <td><?php echo $req['qty']; ?> ถัง</td>
                        <td><span class="status-badge" style="background: #f39c12; color: white;">รอ Admin ยืนยัน</span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="table-container">
            <h3><i class="fas fa-home"></i> สถานที่ของฉัน</h3>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อสถานที่</th>
                        <th>ถังคงเหลือ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($locations as $loc): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($loc['loc_name']); ?></td>
                        <td><?php echo $loc['bottle_on_hand']; ?> ถัง</td>
                        <td>
                            <a href="javascript:void(0);" onclick="openOrderModal('<?php echo $loc['loc_id']; ?>', '<?php echo htmlspecialchars($loc['loc_name']); ?>')" class="status-badge status-completed" style="text-decoration:none;">สั่งน้ำ</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-container">
            <h3><i class="fas fa-history"></i> ประวัติล่าสุด</h3>
            <table>
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>สถานที่</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($orders as $order): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($order['scheduled_date'])); ?></td>
                        <td><?php echo htmlspecialchars($order['loc_name']); ?></td>
                        <td><span class="status-badge status-<?php echo $order['order_status']; ?>"><?php echo $order['order_status']; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="locationModal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div style="background:white; margin:5% auto; padding:20px; border-radius:10px; width:500px; max-height: 90vh; overflow-y: auto;">
        <h3><i class="fas fa-map-marked-alt"></i> เพิ่มสถานที่จัดส่งใหม่</h3>
        <hr>
        <form action="submit_location.php" method="POST">
            <div style="margin-bottom:10px;">
                <label>ชื่อเรียกสถานที่ (เช่น บ้านแม่, ออฟฟิศ):</label>
                <input type="text" name="loc_name" required style="width:100%; padding:8px; border:1px solid #ddd;">
            </div>
            <div style="margin-bottom:10px;">
                <label>หมู่บ้าน/โซน:</label>
                <select name="village_id" required style="width:100%; padding:8px; border:1px solid #ddd;">
                    <?php foreach($villages as $v): ?>
                        <option value="<?php echo $v['village_id']; ?>"><?php echo $v['village_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:10px;">
                <label>รายละเอียดที่อยู่ (บ้านเลขที่, ถนน):</label>
                <textarea name="details" required style="width:100%; padding:8px; border:1px solid #ddd;"></textarea>
            </div>
            <div style="display: flex; gap:10px;">
                <div style="flex:1;">
                    <label>Latitude:</label>
                    <input type="text" name="lat" placeholder="13.XXXX" style="width:100%; padding:8px; border:1px solid #ddd;">
                </div>
                <div style="flex:1;">
                    <label>Longitude:</label>
                    <input type="text" name="lng" placeholder="100.XXXX" style="width:100%; padding:8px; border:1px solid #ddd;">
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" style="flex:1; background:#3498db; color:white; border:none; padding:10px; border-radius:5px; cursor:pointer;">บันทึก</button>
                <button type="button" onclick="closeLocationModal()" style="flex:1; background:#95a5a6; color:white; border:none; padding:10px; border-radius:5px; cursor:pointer;">ยกเลิก</button>
            </div>
        </form>
    </div>
</div>

<div id="orderModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5);">
    <div style="background:white; margin:10% auto; padding:20px; border-radius:10px; width:400px;">
        <h3><i class="fas fa-plus-circle"></i> ส่งคำขอสั่งน้ำ</h3>
        <form id="orderForm" method="POST" action="submit_order_request.php">
            <input type="hidden" name="loc_id" id="modal_loc_id">
            <div style="margin-bottom:15px;">
                <label>สถานที่:</label>
                <input type="text" id="modal_loc_name" readonly style="width:100%; border:none; background:#eee; padding:8px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>วันที่:</label>
                <input type="date" name="requested_date" required min="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:8px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>จำนวน (ถัง):</label>
                <input type="number" name="qty" required min="1" value="1" style="width:100%; padding:8px;">
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" style="flex:1; background:#2ecc71; color:white; border:none; padding:10px; border-radius:5px;">ส่งคำขอ</button>
                <button type="button" onclick="closeModal()" style="flex:1; background:#95a5a6; color:white; border:none; padding:10px; border-radius:5px;">ยกเลิก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openLocationModal() { document.getElementById('locationModal').style.display = 'block'; }
function closeLocationModal() { document.getElementById('locationModal').style.display = 'none'; }
function openOrderModal(id, name) { 
    document.getElementById('modal_loc_id').value = id; 
    document.getElementById('modal_loc_name').value = name; 
    document.getElementById('orderModal').style.display = 'block'; 
}
function closeModal() { document.getElementById('orderModal').style.display = 'none'; }
</script>

<?php require_once '../includes/footer.php'; ?>