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

// Products for size selection
$products = $pdo->query("SELECT * FROM product WHERE product_id != 'P005' ORDER BY price ASC")->fetchAll();

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

    <!-- Unpaid warning -->
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

<!-- ════════════════════════════════════════════════════════════
     Modal: Order request
════════════════════════════════════════════════════════════ -->
<div id="orderModal" class="modal-backdrop">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-title">
            <i class="fas fa-droplet" style="color:var(--foam);"></i> ส่งคำขอสั่งน้ำ
        </div>
        <form method="POST" action="submit_order_request.php" id="orderForm">
            <input type="hidden" name="loc_id" id="modal_loc_id">

            <!-- Location (read-only display) -->
            <div class="field">
                <label>สถานที่จัดส่ง</label>
                <input type="text" id="modal_loc_name" readonly
                       style="background:var(--light); color:var(--muted);">
            </div>

            <!-- Requested date -->
            <div class="field">
                <label>วันที่ต้องการรับ <span style="color:#e74c3c;">*</span></label>
                <input type="date" name="requested_date" required
                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
            </div>

            <!-- Product size cards -->
            <div class="field">
                <label>ประเภทสินค้า <span style="color:#e74c3c;">*</span></label>
                <div class="product-grid" id="productGrid">
                    <?php foreach($products as $p): ?>
                    <label class="product-card">
                        <input type="radio" name="product_id" value="<?php echo $p['product_id']; ?>"
                               data-name="<?php echo htmlspecialchars($p['name']); ?>"
                               data-price="<?php echo $p['price']; ?>"
                               onchange="onProductChange(this)" required>
                        <div class="product-card-inner">
                            <div class="product-icon">💧</div>
                            <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
                            <div class="product-price">฿<?php echo number_format($p['price'], 0); ?>/ถัง</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quantity + live price estimate -->
            <div class="field">
                <label>จำนวน (ถัง) <span style="color:#e74c3c;">*</span></label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
                    <input type="number" name="qty" id="qtyInput" required min="1" value="1"
                           style="text-align:center; width:72px; font-size:1.1rem; font-weight:700;"
                           oninput="updateEstimate()">
                    <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
                </div>
            </div>

            <!-- Price estimate chip -->
            <div id="estimateChip" style="display:none; background:var(--light);
                 border-radius:8px; padding:10px 14px; margin-bottom:4px;
                 font-size:0.85rem; color:var(--navy); display:flex;
                 justify-content:space-between; align-items:center;">
                <span>ราคาประมาณ</span>
                <strong id="estimateAmt" style="color:var(--foam); font-size:1rem;"></strong>
            </div>

            <!-- Note -->
            <div class="field">
                <label>หมายเหตุ (ถ้ามี)</label>
                <textarea name="note" id="noteInput" rows="2"
                          placeholder="เช่น ไม่มีใครอยู่บ้านช่วง 12:00-14:00"></textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:6px;">
                <button type="button" onclick="closeModal('orderModal')" class="btn btn-ghost btn-block">
                    ยกเลิก
                </button>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-paper-plane"></i> ส่งคำขอ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     Modal: Add location
════════════════════════════════════════════════════════════ -->
<div id="locationModal" class="modal-backdrop">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-title">
            <i class="fas fa-map-marked-alt" style="color:var(--foam);"></i> เพิ่มสถานที่จัดส่ง
        </div>
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
                <button type="button" onclick="closeModal('locationModal')" class="btn btn-ghost btn-block">
                    ยกเลิก
                </button>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     Styles for new product cards + qty stepper
════════════════════════════════════════════════════════════ -->
<style>
/* Product size selection grid */
.product-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-top: 4px;
}
.product-card {
    cursor: pointer;
    display: block;
}
.product-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0; height: 0;
}
.product-card-inner {
    border: 2px solid var(--border, #e0e4ed);
    border-radius: 10px;
    padding: 12px 8px;
    text-align: center;
    transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
    background: #fff;
    user-select: none;
}
.product-card input[type="radio"]:checked + .product-card-inner {
    border-color: var(--foam, #5dade2);
    background: #eaf6ff;
    box-shadow: 0 0 0 3px rgba(93,173,226,0.18);
}
.product-card-inner:hover {
    border-color: var(--foam, #5dade2);
    background: #f5fbff;
}
.product-icon  { font-size: 1.5rem; margin-bottom: 4px; }
.product-name  { font-size: 0.78rem; font-weight: 600; color: var(--navy, #1b2a4a); line-height: 1.3; }
.product-price { font-size: 0.82rem; color: var(--foam, #5dade2); font-weight: 700; margin-top: 4px; }

/* Qty stepper buttons */
.qty-btn {
    width: 38px; height: 38px;
    border-radius: 50%;
    border: 2px solid var(--border, #e0e4ed);
    background: #fff;
    font-size: 1.2rem;
    font-weight: 700;
    cursor: pointer;
    color: var(--navy, #1b2a4a);
    display: flex; align-items: center; justify-content: center;
    transition: background 0.12s, border-color 0.12s;
    flex-shrink: 0;
}
.qty-btn:hover {
    background: var(--light, #f4f6fa);
    border-color: var(--foam, #5dade2);
}
</style>

<script>
// ── Modal helpers ─────────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
function openOrderModal(locId, locName) {
    document.getElementById('modal_loc_id').value   = locId;
    document.getElementById('modal_loc_name').value = locName;
    // reset form state
    document.getElementById('orderForm').reset();
    document.getElementById('modal_loc_id').value   = locId;   // reset clears hidden too
    document.getElementById('modal_loc_name').value = locName;
    document.querySelectorAll('.product-card-inner').forEach(el => {
        el.style.borderColor = '';
        el.style.background  = '';
        el.style.boxShadow   = '';
    });
    document.getElementById('estimateChip').style.display = 'none';
    openModal('orderModal');
}

// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

// ── Qty stepper ───────────────────────────────────────────────
function changeQty(delta) {
    const inp = document.getElementById('qtyInput');
    const val = Math.max(1, (parseInt(inp.value) || 1) + delta);
    inp.value = val;
    updateEstimate();
}

// ── Product selection → update note & estimate ────────────────
let selectedPrice = 0;
let selectedName  = '';

function onProductChange(radio) {
    selectedPrice = parseFloat(radio.dataset.price) || 0;
    selectedName  = radio.dataset.name || '';
    updateEstimate();
}

function updateEstimate() {
    const qty   = parseInt(document.getElementById('qtyInput').value) || 0;
    const chip  = document.getElementById('estimateChip');
    const amt   = document.getElementById('estimateAmt');

    if (selectedPrice > 0 && qty > 0) {
        const total = (qty * selectedPrice).toLocaleString('th-TH', {minimumFractionDigits:2});
        amt.textContent  = '฿' + total;
        chip.style.display = 'flex';
    } else {
        chip.style.display = 'none';
    }
}

// ── Inject product info into note before submit ───────────────
// (since order_request has no product_id column, we prepend to note)
document.getElementById('orderForm').addEventListener('submit', function(e) {
    if (!selectedName) return; // radio validation will catch it
    const noteEl = document.getElementById('noteInput');
    const prefix = '[' + selectedName + '] ';
    // Only prepend if not already there (prevent double-prepend on retry)
    if (!noteEl.value.startsWith('[')) {
        noteEl.value = prefix + noteEl.value;
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>