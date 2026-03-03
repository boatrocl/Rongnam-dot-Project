<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

$user_id = $_SESSION['ref_id'];

// User profile
$stmt = $pdo->prepare("SELECT * FROM `user` WHERE User_ID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Locations
$stmt = $pdo->prepare("
    SELECT l.*, v.village_name
    FROM location l
    LEFT JOIN village v ON l.village_id = v.village_id
    WHERE l.User_ID = ?
    ORDER BY l.loc_name ASC
");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

// Pending requests
$stmt = $pdo->prepare("
    SELECT r.*, l.loc_name
    FROM order_request r
    JOIN location l ON r.loc_id = l.loc_id
    WHERE r.User_ID = ? AND r.status = 'pending_admin'
    ORDER BY r.requested_at DESC
");
$stmt->execute([$user_id]);
$pending = $stmt->fetchAll();

// Recent orders (last 5)
$stmt = $pdo->prepare("
    SELECT o.*, l.loc_name
    FROM `order` o
    JOIN location l ON o.loc_id = l.loc_id
    WHERE o.User_ID = ?
    ORDER BY o.scheduled_date DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_orders = $stmt->fetchAll();

// Villages for modal
$villages = $pdo->query("SELECT * FROM village ORDER BY village_name")->fetchAll();

// Success message
$success_msg = '';
if (isset($_GET['success'])) {
    $success_msg = match($_GET['success']) {
        'order_sent'      => 'ส่งคำขอสั่งน้ำเรียบร้อยแล้ว รอ Admin ยืนยัน',
        'location_added'  => 'เพิ่มสถานที่จัดส่งเรียบร้อยแล้ว',
        default           => ''
    };
}

require_once '../includes/header_customer.php';
?>

<div class="page">

    <?php if ($success_msg): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
    </div>
    <?php endif; ?>

    <!-- Unpaid warning (only if owed) -->
    <?php if ($user['Unpaid_amount'] > 0): ?>
    <div class="unpaid-banner">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <div class="amount"><?php echo number_format($user['Unpaid_amount'], 2); ?> บาท</div>
            <div class="label">ยอดค้างชำระ — กรุณาชำระกับพนักงานส่งน้ำ</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-row">
        <div class="stat-chip">
            <div class="val" style="color:<?php echo $user['Unpaid_amount']>0?'var(--danger)':'var(--success)';?>">
                <?php echo number_format($user['Unpaid_amount'],0); ?>
            </div>
            <div class="lbl">ค้างชำระ (บาท)</div>
        </div>
        <div class="stat-chip">
            <div class="val" style="color:var(--warning);"><?php echo count($pending); ?></div>
            <div class="lbl">รอยืนยัน</div>
        </div>
        <div class="stat-chip">
            <div class="val"><?php echo count($locations); ?></div>
            <div class="lbl">สถานที่</div>
        </div>
    </div>

    <!-- Pending requests -->
    <?php if (!empty($pending)): ?>
    <div class="card">
        <div class="card-title">
            <i class="fas fa-hourglass-half"></i> คำขอรอการยืนยัน
        </div>
        <?php foreach($pending as $req): ?>
        <div class="history-row">
            <div class="history-info">
                <h4><?php echo htmlspecialchars($req['loc_name']); ?></h4>
                <p><?php echo date('d/m/Y', strtotime($req['requested_date'])); ?> · <?php echo $req['qty']; ?> ถัง</p>
            </div>
            <span class="badge badge-pending_admin">รอ Admin</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- My locations -->
    <div class="card">
        <div class="sec-header">
            <h3><i class="fas fa-map-marker-alt"></i> สถานที่ของฉัน</h3>
            <button class="btn btn-primary btn-sm" onclick="openModal('locationModal')">
                <i class="fas fa-plus"></i> เพิ่ม
            </button>
        </div>

        <?php if (empty($locations)): ?>
        <div class="empty">
            <i class="fas fa-map-marked-alt"></i>
            <p>ยังไม่มีสถานที่จัดส่ง<br>กดปุ่ม + เพื่อเพิ่ม</p>
        </div>
        <?php else: ?>
            <?php foreach($locations as $loc): ?>
            <div class="loc-card">
                <div class="loc-info">
                    <h4><?php echo htmlspecialchars($loc['loc_name']); ?></h4>
                    <p>
                        <?php echo htmlspecialchars($loc['village_name'] ?? ''); ?>
                        <?php if($loc['bottle_on_hand']): ?>
                        · <i class="fas fa-wine-bottle" style="color:var(--foam);"></i>
                        <?php echo $loc['bottle_on_hand']; ?> ถัง
                        <?php endif; ?>
                    </p>
                </div>
                <button class="btn btn-primary btn-sm"
                        onclick="openOrderModal('<?php echo $loc['loc_id']; ?>','<?php echo htmlspecialchars(addslashes($loc['loc_name'])); ?>')">
                    <i class="fas fa-droplet"></i> สั่งน้ำ
                </button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recent orders -->
    <?php if (!empty($recent_orders)): ?>
    <div class="card">
        <div class="sec-header">
            <h3><i class="fas fa-history"></i> รายการล่าสุด</h3>
            <a href="history.php" class="btn btn-ghost btn-sm">ดูทั้งหมด</a>
        </div>
        <?php foreach($recent_orders as $ord): ?>
        <div class="history-row">
            <div class="history-info">
                <h4><?php echo htmlspecialchars($ord['loc_name']); ?></h4>
                <p><?php echo date('d/m/Y', strtotime($ord['scheduled_date'])); ?>
                   · <?php echo $ord['qty_ordered']; ?> ถัง</p>
            </div>
            <div style="text-align:right; flex-shrink:0;">
                <span class="badge badge-<?php echo $ord['order_status']; ?>">
                    <?php echo match($ord['order_status']) {
                        'pending'    => 'รอดำเนินการ',
                        'processing' => 'กำลังส่ง',
                        'completed'  => 'สำเร็จ',
                        'cancelled'  => 'ยกเลิก',
                        default      => $ord['order_status']
                    }; ?>
                </span>
                <br>
                <span class="badge badge-<?php echo $ord['payment_status']; ?>" style="margin-top:3px;">
                    <?php echo match($ord['payment_status']) {
                        'paid'    => 'ชำระแล้ว',
                        'partial' => 'บางส่วน',
                        'unpaid'  => 'ยังไม่ชำระ',
                        default   => $ord['payment_status']
                    }; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- ── Modal: Order request ──────────────────────────────────── -->
<div id="orderModal" class="modal-backdrop">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-title"><i class="fas fa-droplet" style="color:var(--foam);"></i> ส่งคำขอสั่งน้ำ</div>
        <form method="POST" action="submit_order_request.php">
            <input type="hidden" name="loc_id" id="modal_loc_id">
            <div class="field">
                <label>สถานที่จัดส่ง</label>
                <input type="text" id="modal_loc_name" readonly
                       style="background:var(--light); color:var(--muted);">
            </div>
            <div class="field">
                <label>วันที่ต้องการรับ</label>
                <input type="date" name="requested_date" required
                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
            </div>
            <div class="field">
                <label>จำนวน (ถัง)</label>
                <input type="number" name="qty" required min="1" value="1">
            </div>
            <div class="field">
                <label>หมายเหตุ (ถ้ามี)</label>
                <textarea name="note" rows="2" placeholder="ไม่มีใครอยู่บ้านช่วง..."></textarea>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:6px;">
                <button type="button" onclick="closeModal('orderModal')" class="btn btn-ghost btn-block">ยกเลิก</button>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-paper-plane"></i> ส่งคำขอ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal: Add location ───────────────────────────────────── -->
<div id="locationModal" class="modal-backdrop">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-title"><i class="fas fa-map-marked-alt" style="color:var(--foam);"></i> เพิ่มสถานที่จัดส่ง</div>
        <form method="POST" action="submit_location.php">
            <div class="field">
                <label>ชื่อสถานที่ (เช่น บ้านแม่, ออฟฟิศ)</label>
                <input type="text" name="loc_name" required placeholder="บ้านหลัก">
            </div>
            <div class="field">
                <label>หมู่บ้าน / โซน</label>
                <select name="village_id" required>
                    <option value="">— เลือกหมู่บ้าน —</option>
                    <?php foreach($villages as $v): ?>
                    <option value="<?php echo $v['village_id']; ?>">
                        <?php echo htmlspecialchars($v['village_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>รายละเอียดที่อยู่</label>
                <textarea name="details" required rows="2"
                          placeholder="บ้านเลขที่ 123 ซอย 5..."></textarea>
            </div>
            <div class="field-row">
                <div class="field" style="margin-bottom:0;">
                    <label>Latitude (ละติจูด)</label>
                    <input type="number" step="0.0000001" name="lat" placeholder="13.7563">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label>Longitude (ลองจิจูด)</label>
                    <input type="number" step="0.0000001" name="lng" placeholder="100.5018">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:18px;">
                <button type="button" onclick="closeModal('locationModal')" class="btn btn-ghost btn-block">ยกเลิก</button>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
function openOrderModal(locId, locName) {
    document.getElementById('modal_loc_id').value  = locId;
    document.getElementById('modal_loc_name').value = locName;
    openModal('orderModal');
}
// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
</script>

<?php require_once '../includes/footer.php'; ?>