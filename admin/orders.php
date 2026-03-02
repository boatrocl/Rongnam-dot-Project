<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// จัดการการลบ
if (isset($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM `order` WHERE order_id = ?");
        $stmt->execute([$_GET['delete']]);
        $success = "ลบออเดอร์เรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "ไม่สามารถลบออเดอร์ได้: " . $e->getMessage();
    }
}

// *** NEW: จัดการการอัปเดตสถานะจาก modal ***
// *** จัดการการอัปเดตสถานะจาก modal + auto stock update ***
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $new_status = $_POST['order_status'];
        $order_id   = $_POST['order_id'];

        // ดึงสถานะเดิมและจำนวนขวดของออเดอร์นี้
        $stmt = $pdo->prepare("SELECT order_status, qty_ordered, User_ID, ID, loc_id, DID FROM `order` WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $current = $stmt->fetch();
        $old_status = $current['order_status'];
        $qty        = $current['qty_ordered'];

        // อัปเดตสถานะออเดอร์
        $stmt = $pdo->prepare("UPDATE `order` SET order_status = ?, payment_status = ? WHERE order_id = ?");
        $stmt->execute([$new_status, $_POST['payment_status'], $order_id]);

        // ===== Stock auto-update logic =====
        // Only trigger if status actually changed TO completed or FROM completed
        
        if ($old_status !== 'completed' && $new_status === 'completed') {
            // Order just completed:
            // full bottles go OUT (decrease)
            $pdo->prepare("UPDATE stock SET total_qty = total_qty - ? WHERE bottle_type = 'full'")
                ->execute([$qty]);
            // empty bottles come IN (increase)
            $pdo->prepare("UPDATE stock SET total_qty = total_qty + ? WHERE bottle_type = 'empty'")
                ->execute([$qty]);

            // Log: bottles out
            $pdo->prepare("
                INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'bottles_out', ?, 'full', ?, ?, ?, ?, ?)
            ")->execute([
                'LOG-' . $order_id . '-OUT',
                $qty,
                $current['User_ID'], $current['ID'], $current['loc_id'], $current['DID'],
                $order_id
            ]);

            // Log: empty bottles returned
            $pdo->prepare("
                INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'bottles_return', ?, 'empty', ?, ?, ?, ?, ?)
            ")->execute([
                'LOG-' . $order_id . '-RET',
                $qty,
                $current['User_ID'], $current['ID'], $current['loc_id'], $current['DID'],
                $order_id
            ]);

        } elseif ($old_status === 'completed' && $new_status !== 'completed') {
            // Order un-completed (changed back to pending/processing/cancelled):
            // REVERSE the stock changes
            $pdo->prepare("UPDATE stock SET total_qty = total_qty + ? WHERE bottle_type = 'full'")
                ->execute([$qty]);
            $pdo->prepare("UPDATE stock SET total_qty = total_qty - ? WHERE bottle_type = 'empty'")
                ->execute([$qty]);

            // Log the reversal
            $pdo->prepare("
                INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'manual_adjustment', ?, 'full', ?, ?, ?, ?, ?)
            ")->execute([
                'LOG-' . $order_id . '-REV',
                $qty,
                $current['User_ID'], $current['ID'], $current['loc_id'], $current['DID'],
                $order_id
            ]);
        }

        $success = "อัปเดตสถานะออเดอร์ " . htmlspecialchars($order_id) . " เรียบร้อยแล้ว";

    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ดึงข้อมูลออเดอร์ทั้งหมด
$stmt = $pdo->query("
    SELECT o.*, u.User_name, l.loc_name, d.DFname, d.DLname 
    FROM `order` o
    JOIN `user` u ON o.User_ID = u.User_ID
    JOIN location l ON o.loc_id = l.loc_id
    JOIN driver d ON o.DID = d.DID
    ORDER BY o.scheduled_date DESC
");
$orders = $stmt->fetchAll();
?>

<div class="dashboard">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>จัดการออเดอร์</h2>
        <a href="order_form.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> เพิ่มออเดอร์ใหม่
        </a>
    </div>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="ค้นหาออเดอร์..." onkeyup="searchOrders()">
        <button class="btn btn-primary" onclick="searchOrders()">
            <i class="fas fa-search"></i> ค้นหา
        </button>
    </div>
    
    <div class="table-container">
        <table id="ordersTable">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>ลูกค้า</th>
                    <th>สถานที่</th>
                    <th>พนักงานขับ</th>
                    <th>วันที่</th>
                    <th>จำนวน</th>
                    <th>ยอดรวม</th>
                    <th>สถานะ</th>
                    <th>การชำระเงิน</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $order): ?>
                <tr>
                    <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                    <td><?php echo htmlspecialchars($order['User_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['loc_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['DFname'] . ' ' . $order['DLname']); ?></td>
                    <td><?php echo htmlspecialchars($order['scheduled_date']); ?></td>
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
                    <td style="display:flex; gap:4px; flex-wrap:wrap;">
                        <!-- *** NEW: ปุ่มจัดการเปิด modal *** -->
                        <button 
                            class="btn btn-primary btn-sm"
                            onclick="openModal(
                                '<?php echo htmlspecialchars($order['order_id']); ?>',
                                '<?php echo htmlspecialchars($order['order_status']); ?>',
                                '<?php echo htmlspecialchars($order['payment_status']); ?>',
                                '<?php echo htmlspecialchars($order['User_name']); ?>'
                            )">
                            <i class="fas fa-tasks"></i> จัดการ
                        </button>
                        <!-- <a href="order_form.php?id=<?php echo $order['order_id']; ?>" class="btn btn-warning btn-sm">
                            <i class="fas fa-edit"></i>
                        </a> -->
                        <a href="?delete=<?php echo $order['order_id']; ?>" 
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบออเดอร์นี้?')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- *** NEW: Status Modal *** -->
<div id="statusModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
     background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; padding:32px; width:400px; max-width:90%; box-shadow:0 8px 32px rgba(0,0,0,0.2);">
        
        <h3 style="margin:0 0 8px 0; color:#2C3E50;">
            <i class="fas fa-tasks"></i> อัปเดตสถานะออเดอร์
        </h3>
        <p style="margin:0 0 24px 0; color:#7F8C8D; font-size:0.9rem;">
            ออเดอร์: <strong id="modalOrderId"></strong> — ลูกค้า: <strong id="modalCustomer"></strong>
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="modalOrderIdInput">

            <div class="form-group" style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:6px; font-weight:bold; color:#2C3E50;">
                    <i class="fas fa-box"></i> สถานะออเดอร์:
                </label>
                <select name="order_status" id="modalOrderStatus" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:1rem;">
                    <option value="pending">⏳ รอดำเนินการ (pending)</option>
                    <option value="processing">🔄 กำลังดำเนินการ (processing)</option>
                    <option value="completed">✅ เสร็จสิ้น (completed)</option>
                    <option value="cancelled">❌ ยกเลิก (cancelled)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:24px;">
                <label style="display:block; margin-bottom:6px; font-weight:bold; color:#2C3E50;">
                    <i class="fas fa-money-bill"></i> สถานะการชำระเงิน:
                </label>
                <select name="payment_status" id="modalPaymentStatus" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:1rem;">
                    <option value="unpaid">❌ ยังไม่ชำระ (unpaid)</option>
                    <option value="partial">⚠️ ชำระบางส่วน (partial)</option>
                    <option value="paid">✅ ชำระแล้ว (paid)</option>
                </select>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeModal()" class="btn btn-danger">
                    <i class="fas fa-times"></i> ยกเลิก
                </button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// เปิด modal และใส่ข้อมูลออเดอร์ที่เลือก
function openModal(orderId, orderStatus, paymentStatus, customerName) {
    document.getElementById('modalOrderId').textContent    = orderId;
    document.getElementById('modalCustomer').textContent   = customerName;
    document.getElementById('modalOrderIdInput').value     = orderId;
    document.getElementById('modalOrderStatus').value      = orderStatus;
    document.getElementById('modalPaymentStatus').value    = paymentStatus;

    // แสดง modal
    var modal = document.getElementById('statusModal');
    modal.style.display = 'flex';
}

// ปิด modal
function closeModal() {
    document.getElementById('statusModal').style.display = 'none';
}

// คลิกพื้นหลังเพื่อปิด
document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ค้นหา
function searchOrders() {
    var filter = document.getElementById('searchInput').value.toUpperCase();
    var table  = document.getElementById('ordersTable');
    var rows   = table.getElementsByTagName('tr');
    
    for (var i = 1; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName('td');
        var found = false;
        for (var j = 0; j < cells.length; j++) {
            if (cells[j].textContent.toUpperCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        rows[i].style.display = found ? '' : 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
```

Here's what changed and why:

**Added at the top — POST handler for status updates:**
```
POST action=update_status → UPDATE order SET order_status, payment_status WHERE order_id
```
This is separate from the delete (GET) and the form insert (POST), so they don't interfere.

**Added จัดการ button** that calls `openModal()` passing the current order's data — so the dropdowns are pre-filled with the current status when the modal opens.

**Modal flow:**
```
click จัดการ → openModal() fills dropdowns → user changes status → submit → POST → UPDATE DB → page reloads with success message