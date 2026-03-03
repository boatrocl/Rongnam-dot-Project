<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';
require_once '../includes/header.php';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'adjust') {
            $pdo->prepare("UPDATE stock SET total_qty = ? WHERE stock_id = ?")
                ->execute([$_POST['new_qty'], $_POST['stock_id']]);
            $pdo->prepare("INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id) VALUES (?, NOW(), 'manual_adjustment', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$_POST['log_id'], $_POST['new_qty'], $_POST['bottle_type'], $_POST['User_ID'], $_POST['ID'], $_POST['loc_id'], $_POST['DID'], $_POST['order_id']]);
            $success = "ปรับสต็อกเรียบร้อยแล้ว";
        }
        if ($action === 'bottles_out') {
            $pdo->prepare("UPDATE stock SET total_qty = total_qty - ? WHERE bottle_type = 'full'")
                ->execute([$_POST['qty']]);
            $pdo->prepare("INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id) VALUES (?, NOW(), 'bottles_out', ?, 'full', ?, ?, ?, ?, ?)")
                ->execute([$_POST['log_id'], $_POST['qty'], $_POST['User_ID'], $_POST['ID'], $_POST['loc_id'], $_POST['DID'], $_POST['order_id']]);
            $success = "บันทึกขวดออกเรียบร้อยแล้ว";
        }
        if ($action === 'bottles_return') {
            $pdo->prepare("UPDATE stock SET total_qty = total_qty + ? WHERE bottle_type = 'empty'")
                ->execute([$_POST['qty']]);
            $pdo->prepare("INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id) VALUES (?, NOW(), 'bottles_return', ?, 'empty', ?, ?, ?, ?, ?)")
                ->execute([$_POST['log_id'], $_POST['qty'], $_POST['User_ID'], $_POST['ID'], $_POST['loc_id'], $_POST['DID'], $_POST['order_id']]);
            $success = "บันทึกขวดคืนเรียบร้อยแล้ว";
        }
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$stocks    = $pdo->query("SELECT * FROM stock ORDER BY bottle_type ASC")->fetchAll();
$logs      = $pdo->query("
    SELECT sl.*, u.User_name, m.MFname, m.MLname, l.loc_name,
           d.DFname, d.DLname, d.tel AS driver_tel,
           ord.qty_ordered, ord.order_status, ord.payment_status,
           ord.total_expected_price, ord.actual_amount_collected
    FROM stock_log sl
    LEFT JOIN `user`    u   ON sl.User_ID = u.User_ID
    LEFT JOIN manager   m   ON sl.ID      = m.ID
    LEFT JOIN location  l   ON sl.loc_id  = l.loc_id
    LEFT JOIN driver    d   ON sl.DID     = d.DID
    LEFT JOIN `order`   ord ON sl.order_id = ord.order_id
    ORDER BY sl.timestamp DESC
    LIMIT 50
")->fetchAll();

$orders    = $pdo->query("SELECT order_id FROM `order` ORDER BY order_id DESC LIMIT 100")->fetchAll();
$users     = $pdo->query("SELECT User_ID, User_name FROM `user` ORDER BY User_name")->fetchAll();
$managers  = $pdo->query("SELECT ID, MFname, MLname FROM manager ORDER BY MFname")->fetchAll();
$locations = $pdo->query("SELECT loc_id, loc_name FROM location ORDER BY loc_name")->fetchAll();
$drivers   = $pdo->query("SELECT DID, DFname, DLname FROM driver ORDER BY DFname")->fetchAll();

$full_stock  = null;
$empty_stock = null;
foreach ($stocks as $s) {
    if ($s['bottle_type'] === 'full')  $full_stock  = $s;
    if ($s['bottle_type'] === 'empty') $empty_stock = $s;
}

// ── Helper: modal common fields ────────────────────────────────────────────
function modalCommonFields($orders, $users, $managers, $locations, $drivers) {
    ob_start(); ?>
    <div class="form-group">
        <label>Log ID</label>
        <input type="text" name="log_id" required placeholder="เช่น LOG010">
    </div>
    <div class="form-group">
        <label>ออเดอร์</label>
        <select name="order_id" required>
            <option value="">— เลือกออเดอร์ —</option>
            <?php foreach ($orders as $o): ?>
            <option value="<?php echo $o['order_id']; ?>"><?php echo $o['order_id']; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>ลูกค้า</label>
        <select name="User_ID" required>
            <option value="">— เลือกลูกค้า —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?php echo $u['User_ID']; ?>"><?php echo htmlspecialchars($u['User_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>ผู้จัดการ</label>
        <select name="ID" required>
            <option value="">— เลือกผู้จัดการ —</option>
            <?php foreach ($managers as $m): ?>
            <option value="<?php echo $m['ID']; ?>"><?php echo htmlspecialchars($m['MFname'].' '.$m['MLname']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>สถานที่</label>
        <select name="loc_id" required>
            <option value="">— เลือกสถานที่ —</option>
            <?php foreach ($locations as $l): ?>
            <option value="<?php echo $l['loc_id']; ?>"><?php echo htmlspecialchars($l['loc_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>พนักงานขับ</label>
        <select name="DID" required>
            <option value="">— เลือกพนักงานขับ —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?php echo $d['DID']; ?>"><?php echo htmlspecialchars($d['DFname'].' '.$d['DLname']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
    return ob_get_clean();
}
?>

<style>
/* ── Log drawer ──────────────────────────────────────────────────────────── */
.log-drawer-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(27,42,74,0.5);
    z-index: 500;
    align-items: flex-start;
    justify-content: flex-end;
}
.log-drawer-backdrop.open { display: flex; }

.log-drawer {
    background: #fff;
    width: 420px;
    max-width: 100vw;
    height: 100vh;
    overflow-y: auto;
    box-shadow: -8px 0 40px rgba(27,42,74,0.2);
    animation: drawerSlide 0.22s ease;
    display: flex;
    flex-direction: column;
}
@keyframes drawerSlide {
    from { transform: translateX(100%); }
    to   { transform: translateX(0); }
}

.drawer-header {
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: sticky; top: 0;
    background: #fff; z-index: 10;
}
.drawer-close {
    background: var(--light); border: none;
    width: 30px; height: 30px; border-radius: 7px;
    cursor: pointer; font-size: 0.95rem; color: var(--grey);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.drawer-close:hover { background: var(--border); color: var(--navy); }
.drawer-body { padding: 18px 22px; flex: 1; }

.drawer-section { margin-bottom: 20px; }
.drawer-section-title {
    font-size: 0.68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--grey); margin-bottom: 10px;
    padding-bottom: 5px; border-bottom: 1px solid var(--border);
}

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
.info-cell {
    background: var(--light); border-radius: 8px; padding: 9px 11px;
}
.info-cell .label { font-size: 0.68rem; color: var(--grey); margin-bottom: 2px; }
.info-cell .value { font-size: 0.88rem; font-weight: 600; color: var(--navy); }
.info-cell.full-width  { grid-column: 1 / -1; }
.info-cell.green .value { color: var(--success); }
.info-cell.red   .value { color: var(--danger); }
.info-cell.purple .value { color: #8e44ad; }

/* Action type pill colours */
.type-out    { background: #FADBD8; color: #C0392B; }
.type-return { background: #D5F5E3; color: #1E8449; }
.type-adjust { background: #E8DAEF; color: #7D3C98; }

/* Clickable log rows */
.log-row { cursor: pointer; transition: background 0.12s; }
.log-row:hover td { background: #EBF5FB !important; }
.log-row.selected td { background: #D6EAF8 !important; }

/* Stock level bar */
.stock-bar-wrap { margin: 8px 0 4px; }
.stock-bar-track { background: #eee; border-radius: 6px; height: 10px; overflow: hidden; }
.stock-bar-fill  { height: 10px; border-radius: 6px; transition: width 0.4s ease; }

@media (max-width: 500px) {
    .log-drawer { width: 100vw; }
    .info-grid  { grid-template-columns: 1fr; }
}
</style>

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h2>จัดการสต็อก</h2>
        <p>คลิกที่แถวประวัติเพื่อดูรายละเอียด</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button class="btn btn-danger"   onclick="openModal('modalOut')">
            <i class="fas fa-arrow-up"></i> ขวดออก
        </button>
        <button class="btn btn-success"  onclick="openModal('modalReturn')">
            <i class="fas fa-arrow-down"></i> ขวดคืน
        </button>
        <button class="btn btn-warning"  onclick="openModal('modalAdjust')">
            <i class="fas fa-sliders-h"></i> ปรับสต็อก
        </button>
    </div>
</div>

<?php if (isset($success)): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<!-- ── Stock level cards ───────────────────────────────────────────────────── -->
<div class="stats-container" style="grid-template-columns: repeat(auto-fit, minmax(220px,1fr));">

    <!-- Full bottles -->
    <div class="stat-card" style="border-top-color:#2471A3;">
        <i class="fas fa-wine-bottle" style="color:#2471A3;"></i>
        <h3>ขวดเต็ม (Full)</h3>
        <div class="stat-number" style="color:#2471A3;">
            <?php echo $full_stock ? number_format($full_stock['total_qty']) : 0; ?>
        </div>
        <?php if ($full_stock): ?>
        <div class="stock-bar-wrap">
            <?php $pct = min(100, round($full_stock['total_qty'] / max(1, $full_stock['total_qty'] + ($empty_stock['total_qty'] ?? 0)) * 100)); ?>
            <div class="stock-bar-track">
                <div class="stock-bar-fill" style="width:<?php echo $pct; ?>%; background:#2471A3;"></div>
            </div>
        </div>
        <small style="color:var(--grey);">ID: <?php echo htmlspecialchars($full_stock['stock_id']); ?></small>
        <?php endif; ?>
    </div>

    <!-- Empty bottles -->
    <div class="stat-card" style="border-top-color:#e67e22;">
        <i class="fas fa-wine-bottle" style="color:#e67e22;"></i>
        <h3>ขวดเปล่า (Empty)</h3>
        <div class="stat-number" style="color:#e67e22;">
            <?php echo $empty_stock ? number_format($empty_stock['total_qty']) : 0; ?>
        </div>
        <?php if ($empty_stock): ?>
        <div class="stock-bar-wrap">
            <?php $pct2 = min(100, round($empty_stock['total_qty'] / max(1, ($full_stock['total_qty'] ?? 0) + $empty_stock['total_qty']) * 100)); ?>
            <div class="stock-bar-track">
                <div class="stock-bar-fill" style="width:<?php echo $pct2; ?>%; background:#e67e22;"></div>
            </div>
        </div>
        <small style="color:var(--grey);">ID: <?php echo htmlspecialchars($empty_stock['stock_id']); ?></small>
        <?php endif; ?>
    </div>

    <!-- All bottle types breakdown -->
    <div class="stat-card" style="border-top-color:var(--foam);">
        <i class="fas fa-boxes" style="color:var(--foam);"></i>
        <h3>ทุกประเภท</h3>
        <div class="stat-number" style="font-size:1.4rem;">
            <?php echo number_format(array_sum(array_column($stocks, 'total_qty'))); ?>
        </div>
        <div style="margin-top:8px;">
            <?php foreach($stocks as $s): ?>
            <div style="display:flex; justify-content:space-between; font-size:0.78rem;
                        padding:2px 0; color:var(--grey);">
                <span><?php echo htmlspecialchars($s['bottle_type']); ?></span>
                <strong style="color:var(--navy);"><?php echo number_format($s['total_qty']); ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Log count -->
    <div class="stat-card" style="border-top-color:var(--grey);">
        <i class="fas fa-history" style="color:var(--grey);"></i>
        <h3>รายการล่าสุด</h3>
        <div class="stat-number" style="color:var(--grey);"><?php echo count($logs); ?></div>
        <small style="color:var(--grey);">50 รายการล่าสุด</small>
    </div>

</div>

<!-- ── Stock log table ─────────────────────────────────────────────────────── -->
<div class="table-container" style="padding:0;">
    <div style="display:flex; justify-content:space-between; align-items:center;
                padding:16px 20px 12px; flex-wrap:wrap; gap:10px;">
        <h3 style="margin:0; color:var(--navy); font-size:1rem;">
            <i class="fas fa-history" style="color:var(--foam);"></i>
            ประวัติการเคลื่อนไหว
            <span style="font-size:0.75rem; color:var(--grey); font-weight:400;">
                (50 รายการล่าสุด · คลิกแถวเพื่อดูรายละเอียด)
            </span>
        </h3>
        <input type="text" id="searchInput" placeholder="ค้นหา..."
               onkeyup="searchTable()"
               style="padding:7px 12px; border:1px solid var(--border);
                      border-radius:7px; font-size:0.85rem; width:200px;">
    </div>

    <table id="logTable">
        <thead>
            <tr>
                <th>เวลา</th>
                <th>ประเภท</th>
                <th>จำนวน</th>
                <th>ประเภทขวด</th>
                <th>Order</th>
                <th>ลูกค้า</th>
                <th>คนขับ</th>
                <th>สถานที่</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
            <tr><td colspan="8" style="text-align:center; padding:40px; color:var(--grey);">
                ยังไม่มีประวัติสต็อก
            </td></tr>
            <?php else: ?>
            <?php foreach($logs as $log):
                $type_map = [
                    'bottles_out'       => ['ขวดออก',   'type-out',    '📤'],
                    'bottles_return'    => ['ขวดคืน',   'type-return', '📥'],
                    'manual_adjustment' => ['ปรับสต็อก','type-adjust', '⚙️'],
                    'stock_out'         => ['ออก',      'type-out',    '📤'],
                    'empty_return'      => ['คืน',      'type-return', '📥'],
                ];
                [$type_label, $type_class, $type_icon] = $type_map[$log['action_type']] ?? [$log['action_type'], '', '•'];

                // Encode full row data for drawer
                $row_data = htmlspecialchars(json_encode([
                    'log_id'          => $log['log_id'],
                    'timestamp'       => $log['timestamp'],
                    'action_type'     => $log['action_type'],
                    'type_label'      => $type_label,
                    'type_class'      => $type_class,
                    'total_qty'       => $log['total_qty'],
                    'bottle_type'     => $log['bottle_type'],
                    'order_id'        => $log['order_id'],
                    'customer'        => $log['User_name'] ?? '-',
                    'manager'         => trim(($log['MFname'] ?? '') . ' ' . ($log['MLname'] ?? '')),
                    'loc_name'        => $log['loc_name'] ?? '-',
                    'driver'          => trim(($log['DFname'] ?? '') . ' ' . ($log['DLname'] ?? '')),
                    'driver_tel'      => $log['driver_tel'] ?? '-',
                    'order_qty'       => $log['qty_ordered'] ?? null,
                    'order_status'    => $log['order_status'] ?? null,
                    'order_payment'   => $log['payment_status'] ?? null,
                    'order_total'     => $log['total_expected_price'] ?? null,
                    'order_collected' => $log['actual_amount_collected'] ?? null,
                ]), ENT_QUOTES);
            ?>
            <tr class="log-row" onclick="openLogDrawer(<?php echo $row_data; ?>)"
                data-log="<?php echo htmlspecialchars($log['log_id']); ?>">
                <td style="white-space:nowrap; font-size:0.82rem;">
                    <?php echo date('d/m/y H:i', strtotime($log['timestamp'])); ?>
                </td>
                <td>
                    <span class="status-badge <?php echo $type_class; ?>">
                        <?php echo $type_icon . ' ' . $type_label; ?>
                    </span>
                </td>
                <td style="text-align:center; font-weight:700; font-size:1rem; color:var(--navy);">
                    <?php echo number_format($log['total_qty']); ?>
                </td>
                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($log['bottle_type']); ?></td>
                <td style="font-family:monospace; font-size:0.8rem;">
                    <?php echo htmlspecialchars($log['order_id']); ?>
                </td>
                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($log['User_name'] ?? '-'); ?></td>
                <td style="font-size:0.82rem;">
                    <?php echo htmlspecialchars(trim(($log['DFname'] ?? '') . ' ' . ($log['DLname'] ?? ''))); ?>
                </td>
                <td style="font-size:0.82rem;"><?php echo htmlspecialchars($log['loc_name'] ?? '-'); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     LOG DETAIL DRAWER
════════════════════════════════════════════════════════════════════════════ -->
<div class="log-drawer-backdrop" id="logDrawerBackdrop" onclick="closeLogDrawer(event)">
    <div class="log-drawer" id="logDrawer" onclick="event.stopPropagation()">

        <div class="drawer-header">
            <div>
                <div style="font-size:0.68rem; color:var(--grey); font-family:monospace;"
                     id="ld_log_id"></div>
                <div style="font-size:1.05rem; font-weight:700; color:var(--navy); margin-top:3px;"
                     id="ld_title"></div>
                <div style="font-size:0.78rem; color:var(--grey); margin-top:2px;"
                     id="ld_timestamp"></div>
            </div>
            <button class="drawer-close" onclick="closeLogDrawerBtn()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="drawer-body">

            <!-- Action summary -->
            <div class="drawer-section">
                <div class="drawer-section-title">📦 รายละเอียดการเคลื่อนไหว</div>
                <div class="info-grid">
                    <div class="info-cell">
                        <div class="label">ประเภทการดำเนินการ</div>
                        <div class="value"><span id="ld_type_badge" class="status-badge"></span></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">ประเภทขวด</div>
                        <div class="value" id="ld_bottle_type"></div>
                    </div>
                    <div class="info-cell full-width" id="ld_qty_cell">
                        <div class="label">จำนวนขวด</div>
                        <div class="value" style="font-size:1.6rem;" id="ld_qty"></div>
                    </div>
                </div>
            </div>

            <!-- Linked order info -->
            <div class="drawer-section" id="ld_order_section">
                <div class="drawer-section-title">🧾 ออเดอร์ที่เกี่ยวข้อง</div>
                <div class="info-grid">
                    <div class="info-cell">
                        <div class="label">Order ID</div>
                        <div class="value" id="ld_order_id" style="font-family:monospace;"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">จำนวนที่สั่ง</div>
                        <div class="value" id="ld_order_qty"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">สถานะออเดอร์</div>
                        <div class="value"><span id="ld_order_status" class="status-badge"></span></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">สถานะชำระ</div>
                        <div class="value"><span id="ld_order_payment" class="status-badge"></span></div>
                    </div>
                    <div class="info-cell green">
                        <div class="label">ราคารวมออเดอร์</div>
                        <div class="value" id="ld_order_total"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">เก็บเงินได้แล้ว</div>
                        <div class="value" id="ld_order_collected"></div>
                    </div>
                </div>
            </div>

            <!-- People & location -->
            <div class="drawer-section">
                <div class="drawer-section-title">👤 ผู้เกี่ยวข้อง</div>
                <div class="info-grid">
                    <div class="info-cell">
                        <div class="label">ลูกค้า</div>
                        <div class="value" id="ld_customer"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">สถานที่</div>
                        <div class="value" id="ld_loc"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">พนักงานขับ</div>
                        <div class="value" id="ld_driver"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">เบอร์คนขับ</div>
                        <div class="value" id="ld_driver_tel"></div>
                    </div>
                    <div class="info-cell full-width">
                        <div class="label">ผู้จัดการ</div>
                        <div class="value" id="ld_manager"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     ACTION MODALS
════════════════════════════════════════════════════════════════════════════ -->

<!-- Modal: ขวดออก -->
<div id="modalOut" class="modal-overlay" style="display:none; position:fixed; inset:0;
     background:rgba(27,42,74,0.55); z-index:600; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:14px; padding:28px; width:480px;
                max-width:95%; max-height:88vh; overflow-y:auto;
                box-shadow:0 20px 60px rgba(27,42,74,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; color:var(--navy);">
                <i class="fas fa-arrow-up" style="color:var(--danger);"></i> บันทึกขวดออก
            </h3>
            <button class="drawer-close" onclick="closeModal('modalOut')"><i class="fas fa-times"></i></button>
        </div>
        <p style="color:var(--grey); font-size:0.85rem; margin-bottom:18px;">
            ลดสต็อกขวดเต็ม — ขวดออกไปพร้อมออเดอร์
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="bottles_out">
            <?php echo modalCommonFields($orders, $users, $managers, $locations, $drivers); ?>
            <div class="form-group">
                <label>จำนวนขวดที่ออก</label>
                <input type="number" name="qty" min="1" required placeholder="0">
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" onclick="closeModal('modalOut')" class="btn btn-ghost">ยกเลิก</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ขวดคืน -->
<div id="modalReturn" class="modal-overlay" style="display:none; position:fixed; inset:0;
     background:rgba(27,42,74,0.55); z-index:600; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:14px; padding:28px; width:480px;
                max-width:95%; max-height:88vh; overflow-y:auto;
                box-shadow:0 20px 60px rgba(27,42,74,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; color:var(--navy);">
                <i class="fas fa-arrow-down" style="color:var(--success);"></i> บันทึกขวดคืน
            </h3>
            <button class="drawer-close" onclick="closeModal('modalReturn')"><i class="fas fa-times"></i></button>
        </div>
        <p style="color:var(--grey); font-size:0.85rem; margin-bottom:18px;">
            เพิ่มสต็อกขวดเปล่า — ลูกค้าคืนขวดมา
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="bottles_return">
            <?php echo modalCommonFields($orders, $users, $managers, $locations, $drivers); ?>
            <div class="form-group">
                <label>จำนวนขวดที่คืน</label>
                <input type="number" name="qty" min="1" required placeholder="0">
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" onclick="closeModal('modalReturn')" class="btn btn-ghost">ยกเลิก</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ปรับสต็อก -->
<div id="modalAdjust" class="modal-overlay" style="display:none; position:fixed; inset:0;
     background:rgba(27,42,74,0.55); z-index:600; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:14px; padding:28px; width:480px;
                max-width:95%; max-height:88vh; overflow-y:auto;
                box-shadow:0 20px 60px rgba(27,42,74,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; color:var(--navy);">
                <i class="fas fa-sliders-h" style="color:#8e44ad;"></i> ปรับสต็อกด้วยตัวเอง
            </h3>
            <button class="drawer-close" onclick="closeModal('modalAdjust')"><i class="fas fa-times"></i></button>
        </div>
        <p style="color:var(--grey); font-size:0.85rem; margin-bottom:18px;">
            กำหนดจำนวนสต็อกโดยตรง (ใช้เมื่อนับสต็อกจริง)
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="adjust">
            <div class="form-group">
                <label>ประเภทขวด</label>
                <select name="bottle_type" id="adjustBottleType" onchange="updateStockId()" required>
                    <option value="full">ขวดเต็ม (full)</option>
                    <option value="empty">ขวดเปล่า (empty)</option>
                </select>
            </div>
            <input type="hidden" name="stock_id" id="adjustStockId"
                   value="<?php echo htmlspecialchars($full_stock['stock_id'] ?? ''); ?>">
            <div class="form-group">
                <label>จำนวนใหม่</label>
                <input type="number" name="new_qty" min="0" required placeholder="0">
            </div>
            <?php echo modalCommonFields($orders, $users, $managers, $locations, $drivers); ?>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" onclick="closeModal('modalAdjust')" class="btn btn-ghost">ยกเลิก</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Stock adjust helper ───────────────────────────────────────────────────────
var stockIds = {
    full:  '<?php echo addslashes($full_stock['stock_id']  ?? ''); ?>',
    empty: '<?php echo addslashes($empty_stock['stock_id'] ?? ''); ?>'
};
function updateStockId() {
    var type = document.getElementById('adjustBottleType').value;
    document.getElementById('adjustStockId').value = stockIds[type] || '';
}

// ── Modals ────────────────────────────────────────────────────────────────────
function openModal(id) {
    var m = document.getElementById(id);
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// ── Log drawer ────────────────────────────────────────────────────────────────
function fmt(n) {
    return parseFloat(n || 0).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

const statusLabel = {
    pending:    '⏳ รอดำเนินการ',
    processing: '🔄 กำลังส่ง',
    completed:  '✅ เสร็จสิ้น',
    cancelled:  '❌ ยกเลิก',
    paid:       '✅ ชำระแล้ว',
    partial:    '⚠️ บางส่วน',
    unpaid:     '❌ ยังไม่ชำระ',
};

function openLogDrawer(data) {
    // Highlight selected row
    document.querySelectorAll('.log-row').forEach(r => r.classList.remove('selected'));
    const row = document.querySelector(`[data-log="${data.log_id}"]`);
    if (row) row.classList.add('selected');

    // Header
    document.getElementById('ld_log_id').textContent    = 'LOG ID: ' + data.log_id;
    document.getElementById('ld_timestamp').textContent = data.timestamp;

    // Title = action type
    const titleMap = {
        bottles_out:        '📤 ขวดออก',
        bottles_return:     '📥 ขวดคืน',
        manual_adjustment:  '⚙️ ปรับสต็อก',
        stock_out:          '📤 ขวดออก',
        empty_return:       '📥 คืนขวดเปล่า',
    };
    document.getElementById('ld_title').textContent = titleMap[data.action_type] || data.action_type;

    // Action type badge
    const tb = document.getElementById('ld_type_badge');
    tb.textContent = data.type_label;
    tb.className   = 'status-badge ' + data.type_class;

    // Bottle info
    document.getElementById('ld_bottle_type').textContent = data.bottle_type || '-';
    document.getElementById('ld_qty').textContent         = parseInt(data.total_qty || 0).toLocaleString('th-TH') + ' ขวด';

    // Colour qty by action type
    const qtyEl = document.getElementById('ld_qty');
    qtyEl.style.color = data.action_type === 'bottles_out' || data.action_type === 'stock_out'
        ? 'var(--danger)'
        : data.action_type === 'bottles_return' || data.action_type === 'empty_return'
            ? 'var(--success)'
            : '#8e44ad';

    // Linked order
    const orderSec = document.getElementById('ld_order_section');
    if (data.order_id && data.order_id !== '-') {
        orderSec.style.display = '';
        document.getElementById('ld_order_id').textContent = data.order_id;

        document.getElementById('ld_order_qty').textContent =
            data.order_qty ? data.order_qty + ' ขวด' : '-';

        const osb = document.getElementById('ld_order_status');
        if (data.order_status) {
            osb.textContent = statusLabel[data.order_status] || data.order_status;
            osb.className   = 'status-badge status-' + data.order_status;
        } else {
            osb.textContent = '-'; osb.className = '';
        }

        const opb = document.getElementById('ld_order_payment');
        if (data.order_payment) {
            opb.textContent = statusLabel[data.order_payment] || data.order_payment;
            opb.className   = 'status-badge status-' + data.order_payment;
        } else {
            opb.textContent = '-'; opb.className = '';
        }

        document.getElementById('ld_order_total').textContent     =
            data.order_total ? '฿' + fmt(data.order_total) : '-';
        document.getElementById('ld_order_collected').textContent =
            data.order_collected != null ? '฿' + fmt(data.order_collected) : '-';
    } else {
        orderSec.style.display = 'none';
    }

    // People
    document.getElementById('ld_customer').textContent   = data.customer   || '-';
    document.getElementById('ld_loc').textContent        = data.loc_name   || '-';
    document.getElementById('ld_driver').textContent     = data.driver     || '-';
    document.getElementById('ld_driver_tel').textContent = data.driver_tel || '-';
    document.getElementById('ld_manager').textContent    = data.manager    || '-';

    // Open
    document.getElementById('logDrawerBackdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLogDrawer(e) {
    if (e && e.target !== document.getElementById('logDrawerBackdrop')) return;
    closeLogDrawerBtn();
}
function closeLogDrawerBtn() {
    document.getElementById('logDrawerBackdrop').classList.remove('open');
    document.body.style.overflow = '';
    document.querySelectorAll('.log-row').forEach(r => r.classList.remove('selected'));
}

// Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLogDrawerBtn();
});

// ── Search ────────────────────────────────────────────────────────────────────
function searchTable() {
    const filter = document.getElementById('searchInput').value.toUpperCase();
    document.querySelectorAll('#logTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toUpperCase().includes(filter) ? '' : 'none';
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
