<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';

// ===== Handle all POST actions =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // --- Manual adjustment ---
        if ($action === 'adjust') {
            $stmt = $pdo->prepare("UPDATE stock SET total_qty = ? WHERE stock_id = ?");
            $stmt->execute([$_POST['new_qty'], $_POST['stock_id']]);

            // Log it
            $stmt = $pdo->prepare("
                INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'manual_adjustment', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['log_id'],
                $_POST['new_qty'],
                $_POST['bottle_type'],
                $_POST['User_ID'],
                $_POST['ID'],
                $_POST['loc_id'],
                $_POST['DID'],
                $_POST['order_id']
            ]);
            $success = "ปรับสต็อกเรียบร้อยแล้ว";
        }

        // --- Bottles out with order ---
        if ($action === 'bottles_out') {
            // Decrease full bottle stock
            $stmt = $pdo->prepare("UPDATE stock SET total_qty = total_qty - ? WHERE bottle_type = 'full'");
            $stmt->execute([$_POST['qty']]);

            $stmt = $pdo->prepare("
                INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'bottles_out', ?, 'full', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['log_id'],
                $_POST['qty'],
                $_POST['User_ID'],
                $_POST['ID'],
                $_POST['loc_id'],
                $_POST['DID'],
                $_POST['order_id']
            ]);
            $success = "บันทึกขวดออก เรียบร้อยแล้ว";
        }

        // --- Empty bottles returned ---
        if ($action === 'bottles_return') {
            // Increase empty bottle stock
            $stmt = $pdo->prepare("UPDATE stock SET total_qty = total_qty + ? WHERE bottle_type = 'empty'");
            $stmt->execute([$_POST['qty']]);

            $stmt = $pdo->prepare("
                INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'bottles_return', ?, 'empty', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['log_id'],
                $_POST['qty'],
                $_POST['User_ID'],
                $_POST['ID'],
                $_POST['loc_id'],
                $_POST['DID'],
                $_POST['order_id']
            ]);
            $success = "บันทึกขวดคืน เรียบร้อยแล้ว";
        }

    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ===== Fetch data =====

// Stock levels
$stmt = $pdo->query("SELECT * FROM stock ORDER BY bottle_type ASC");
$stocks = $stmt->fetchAll();

// Stock log (latest 50)
$stmt = $pdo->query("
    SELECT sl.*, u.User_name, m.MFname, m.MLname, l.loc_name, d.DFname, d.DLname
    FROM stock_log sl
    LEFT JOIN `user` u    ON sl.User_ID = u.User_ID
    LEFT JOIN manager m   ON sl.ID      = m.ID
    LEFT JOIN location l  ON sl.loc_id  = l.loc_id
    LEFT JOIN driver d    ON sl.DID     = d.DID
    ORDER BY sl.timestamp DESC
    LIMIT 50
");
$logs = $stmt->fetchAll();

// Dropdowns for modals
$orders   = $pdo->query("SELECT order_id FROM `order` ORDER BY order_id DESC LIMIT 100")->fetchAll();
$users    = $pdo->query("SELECT User_ID, User_name FROM `user` ORDER BY User_name")->fetchAll();
$managers = $pdo->query("SELECT ID, MFname, MLname FROM manager ORDER BY MFname")->fetchAll();
$locations= $pdo->query("SELECT loc_id, loc_name FROM location ORDER BY loc_name")->fetchAll();
$drivers  = $pdo->query("SELECT DID, DFname, DLname FROM driver ORDER BY DFname")->fetchAll();

// Find full and empty stock for display
$full_stock  = null;
$empty_stock = null;
foreach ($stocks as $s) {
    if ($s['bottle_type'] === 'full')  $full_stock  = $s;
    if ($s['bottle_type'] === 'empty') $empty_stock = $s;
}
?>

<div class="dashboard">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2>จัดการสต็อก (Stock)</h2>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-primary" onclick="openModal('modalOut')">
                <i class="fas fa-arrow-up"></i> ขวดออก
            </button>
            <button class="btn btn-success" onclick="openModal('modalReturn')">
                <i class="fas fa-arrow-down"></i> ขวดคืน
            </button>
            <button class="btn btn-warning" onclick="openModal('modalAdjust')">
                <i class="fas fa-sliders-h"></i> ปรับสต็อก
            </button>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Stock Level Cards -->
    <div class="stats-container">
        <div class="stat-card">
            <i class="fas fa-wine-bottle" style="color:#3498db;"></i>
            <h3>ขวดเต็ม (Full)</h3>
            <div class="stat-number" style="color:#3498db;">
                <?php echo $full_stock ? $full_stock['total_qty'] : 0; ?>
            </div>
            <small style="color:#999;">stock_id: <?php echo $full_stock ? htmlspecialchars($full_stock['stock_id']) : '-'; ?></small>
        </div>
        <div class="stat-card">
            <i class="fas fa-wine-bottle" style="color:#e67e22;"></i>
            <h3>ขวดเปล่า (Empty)</h3>
            <div class="stat-number" style="color:#e67e22;">
                <?php echo $empty_stock ? $empty_stock['total_qty'] : 0; ?>
            </div>
            <small style="color:#999;">stock_id: <?php echo $empty_stock ? htmlspecialchars($empty_stock['stock_id']) : '-'; ?></small>
        </div>
        <div class="stat-card">
            <i class="fas fa-boxes"></i>
            <h3>รวมทั้งหมด</h3>
            <div class="stat-number">
                <?php echo array_sum(array_column($stocks, 'total_qty')); ?>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-history"></i>
            <h3>รายการล็อกล่าสุด</h3>
            <div class="stat-number"><?php echo count($logs); ?></div>
        </div>
    </div>

    <!-- Stock Log Table -->
    <div class="table-container">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3>ประวัติการเคลื่อนไหวสต็อก (50 รายการล่าสุด)</h3>
            <input type="text" id="searchInput" placeholder="ค้นหา..." onkeyup="searchTable()"
                   style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
        </div>
        <table id="logTable">
            <thead>
                <tr>
                    <th>Log ID</th>
                    <th>เวลา</th>
                    <th>ประเภท</th>
                    <th>จำนวน</th>
                    <th>ประเภทขวด</th>
                    <th>Order</th>
                    <th>ลูกค้า</th>
                    <th>พนักงานขับ</th>
                    <th>สถานที่</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" style="text-align:center;">ยังไม่มีประวัติสต็อก</td></tr>
                <?php else: ?>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['log_id']); ?></td>
                    <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                    <td>
                        <?php
                        $type_labels = [
                            'manual_adjustment' => ['label' => 'ปรับสต็อก',   'color' => '#8e44ad'],
                            'bottles_out'       => ['label' => 'ขวดออก',       'color' => '#e74c3c'],
                            'bottles_return'    => ['label' => 'ขวดคืน',       'color' => '#27ae60'],
                        ];
                        $t = $type_labels[$log['action_type']] ?? ['label' => $log['action_type'], 'color' => '#666'];
                        ?>
                        <span class="status-badge" style="background:<?php echo $t['color']; ?>; color:#fff;">
                            <?php echo $t['label']; ?>
                        </span>
                    </td>
                    <td style="font-weight:bold;"><?php echo htmlspecialchars($log['total_qty']); ?></td>
                    <td><?php echo htmlspecialchars($log['bottle_type']); ?></td>
                    <td><?php echo htmlspecialchars($log['order_id']); ?></td>
                    <td><?php echo htmlspecialchars($log['User_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars(($log['DFname'] ?? '') . ' ' . ($log['DLname'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($log['loc_name'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: ขวดออก (Bottles Out)                                  -->
<!-- ============================================================ -->
<div id="modalOut" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3><i class="fas fa-arrow-up" style="color:#e74c3c;"></i> บันทึกขวดออก</h3>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:20px;">
            ลดสต็อกขวดเต็ม — ขวดออกไปพร้อมออเดอร์
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="bottles_out">
            <?php echo modalCommonFields($orders, $users, $managers, $locations, $drivers, 'out'); ?>
            <div class="form-group">
                <label>จำนวนขวดที่ออก:</label>
                <input type="number" name="qty" min="1" required>
            </div>
            <?php echo modalButtons('modalOut'); ?>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: ขวดคืน (Bottles Return)                               -->
<!-- ============================================================ -->
<div id="modalReturn" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3><i class="fas fa-arrow-down" style="color:#27ae60;"></i> บันทึกขวดคืน</h3>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:20px;">
            เพิ่มสต็อกขวดเปล่า — ลูกค้าคืนขวดมา
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="bottles_return">
            <?php echo modalCommonFields($orders, $users, $managers, $locations, $drivers, 'return'); ?>
            <div class="form-group">
                <label>จำนวนขวดที่คืน:</label>
                <input type="number" name="qty" min="1" required>
            </div>
            <?php echo modalButtons('modalReturn'); ?>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: ปรับสต็อก (Manual Adjustment)                         -->
<!-- ============================================================ -->
<div id="modalAdjust" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3><i class="fas fa-sliders-h" style="color:#8e44ad;"></i> ปรับสต็อกด้วยตัวเอง</h3>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:20px;">
            กำหนดจำนวนสต็อกโดยตรง (ใช้เมื่อนับสต็อกจริง)
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="adjust">
            <div class="form-group">
                <label>ประเภทขวด:</label>
                <select name="bottle_type" id="adjustBottleType" onchange="updateStockId()" required>
                    <option value="full">ขวดเต็ม (full)</option>
                    <option value="empty">ขวดเปล่า (empty)</option>
                </select>
            </div>
            <input type="hidden" name="stock_id" id="adjustStockId"
                   value="<?php echo htmlspecialchars($full_stock['stock_id'] ?? ''); ?>">
            <div class="form-group">
                <label>จำนวนใหม่:</label>
                <input type="number" name="new_qty" min="0" required>
            </div>
            <?php echo modalCommonFields($orders, $users, $managers, $locations, $drivers, 'adjust'); ?>
            <?php echo modalButtons('modalAdjust'); ?>
        </form>
    </div>
</div>

<?php
// ===== Helper functions for modal HTML =====
function modalCommonFields($orders, $users, $managers, $locations, $drivers, $suffix) {
    $html = '';

    // Log ID
    $html .= '<div class="form-group">
        <label>Log ID:</label>
        <input type="text" name="log_id" required placeholder="เช่น LOG001">
    </div>';

    // Order
    $html .= '<div class="form-group"><label>ออเดอร์:</label><select name="order_id" required>
        <option value="">เลือกออเดอร์</option>';
    foreach ($orders as $o) {
        $html .= '<option value="' . htmlspecialchars($o['order_id']) . '">' . htmlspecialchars($o['order_id']) . '</option>';
    }
    $html .= '</select></div>';

    // User
    $html .= '<div class="form-group"><label>ลูกค้า:</label><select name="User_ID" required>
        <option value="">เลือกลูกค้า</option>';
    foreach ($users as $u) {
        $html .= '<option value="' . htmlspecialchars($u['User_ID']) . '">' . htmlspecialchars($u['User_name']) . '</option>';
    }
    $html .= '</select></div>';

    // Manager
    $html .= '<div class="form-group"><label>ผู้จัดการ:</label><select name="ID" required>
        <option value="">เลือกผู้จัดการ</option>';
    foreach ($managers as $m) {
        $html .= '<option value="' . htmlspecialchars($m['ID']) . '">' . htmlspecialchars($m['MFname'] . ' ' . $m['MLname']) . '</option>';
    }
    $html .= '</select></div>';

    // Location
    $html .= '<div class="form-group"><label>สถานที่:</label><select name="loc_id" required>
        <option value="">เลือกสถานที่</option>';
    foreach ($locations as $l) {
        $html .= '<option value="' . htmlspecialchars($l['loc_id']) . '">' . htmlspecialchars($l['loc_name']) . '</option>';
    }
    $html .= '</select></div>';

    // Driver
    $html .= '<div class="form-group"><label>พนักงานขับ:</label><select name="DID" required>
        <option value="">เลือกพนักงานขับ</option>';
    foreach ($drivers as $d) {
        $html .= '<option value="' . htmlspecialchars($d['DID']) . '">' . htmlspecialchars($d['DFname'] . ' ' . $d['DLname']) . '</option>';
    }
    $html .= '</select></div>';

    return $html;
}

function modalButtons($modalId) {
    return '<div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
        <button type="button" onclick="closeModal(\'' . $modalId . '\')" class="btn btn-danger">
            <i class="fas fa-times"></i> ยกเลิก
        </button>
        <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> บันทึก
        </button>
    </div>';
}
?>

<!-- Modal styles -->
<style>
.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 1000;
    justify-content: center; align-items: center;
}
.modal-box {
    background: #fff; border-radius: 12px; padding: 32px;
    width: 480px; max-width: 90%; max-height: 85vh;
    overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.2);
}
.modal-box h3 { margin: 0 0 8px 0; color: #2c3e50; }
</style>

<script>
// Stock IDs for the adjust modal
var stockIds = {
    full:  '<?php echo addslashes($full_stock['stock_id']  ?? ''); ?>',
    empty: '<?php echo addslashes($empty_stock['stock_id'] ?? ''); ?>'
};

function updateStockId() {
    var type = document.getElementById('adjustBottleType').value;
    document.getElementById('adjustStockId').value = stockIds[type] || '';
}

function openModal(id) {
    var m = document.getElementById(id);
    m.style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Click backdrop to close
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// Search log table
function searchTable() {
    var filter = document.getElementById('searchInput').value.toUpperCase();
    var rows = document.getElementById('logTable').getElementsByTagName('tr');
    for (var i = 1; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName('td');
        var found = false;
        for (var j = 0; j < cells.length; j++) {
            if (cells[j].textContent.toUpperCase().indexOf(filter) > -1) { found = true; break; }
        }
        rows[i].style.display = found ? '' : 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
```

---

## How it works
```
stock.php
├── 3 buttons at the top → open modals (no new pages!)
│   ├── ขวดออก modal    → POST action=bottles_out  → UPDATE stock (full -qty)  + INSERT log
│   ├── ขวดคืน modal    → POST action=bottles_return → UPDATE stock (empty +qty) + INSERT log
│   └── ปรับสต็อก modal → POST action=adjust        → UPDATE stock (set exact)  + INSERT log
├── Stock cards (full / empty / total / log count)
└── Log history table (last 50, searchable)