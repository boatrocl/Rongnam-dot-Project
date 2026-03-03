<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';
require_once '../includes/header.php';

// ── Helper: extract [Product Name] prefix from a note string ─────────────────
function extractBottleType(string $note): array {
    if (preg_match('/^\[([^\]]+)\]/', $note, $m)) {
        return [
            'type' => trim($m[1]),
            'note' => trim(substr($note, strlen($m[0]))),
        ];
    }
    return ['type' => '', 'note' => $note];
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM `order` WHERE order_id = ?");
        $stmt->execute([$_GET['delete']]);
        $success = "ลบออเดอร์เรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "ไม่สามารถลบออเดอร์ได้: " . $e->getMessage();
    }
}

// ── Status update + stock logic ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    try {
        $new_status = $_POST['order_status'];
        $order_id   = $_POST['order_id'];

        $stmt = $pdo->prepare("SELECT order_status, qty_ordered, User_ID, ID, loc_id, DID FROM `order` WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $current    = $stmt->fetch();
        $old_status = $current['order_status'];
        $qty        = $current['qty_ordered'];

        $stmt = $pdo->prepare("UPDATE `order` SET order_status = ?, payment_status = ? WHERE order_id = ?");
        $stmt->execute([$new_status, $_POST['payment_status'], $order_id]);

        if ($old_status !== 'completed' && $new_status === 'completed') {
            $pdo->prepare("UPDATE stock SET total_qty = total_qty - ? WHERE bottle_type = 'full'")->execute([$qty]);
            $pdo->prepare("UPDATE stock SET total_qty = total_qty + ? WHERE bottle_type = 'empty'")->execute([$qty]);
            $pdo->prepare("INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id) VALUES (?, NOW(), 'bottles_out', ?, 'full', ?, ?, ?, ?, ?)")
                ->execute(['LOG-'.$order_id.'-OUT', $qty, $current['User_ID'], $current['ID'], $current['loc_id'], $current['DID'], $order_id]);
            $pdo->prepare("INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id) VALUES (?, NOW(), 'bottles_return', ?, 'empty', ?, ?, ?, ?, ?)")
                ->execute(['LOG-'.$order_id.'-RET', $qty, $current['User_ID'], $current['ID'], $current['loc_id'], $current['DID'], $order_id]);
        } elseif ($old_status === 'completed' && $new_status !== 'completed') {
            $pdo->prepare("UPDATE stock SET total_qty = total_qty + ? WHERE bottle_type = 'full'")->execute([$qty]);
            $pdo->prepare("UPDATE stock SET total_qty = total_qty - ? WHERE bottle_type = 'empty'")->execute([$qty]);
            $pdo->prepare("INSERT INTO stock_log (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id) VALUES (?, NOW(), 'manual_adjustment', ?, 'full', ?, ?, ?, ?, ?)")
                ->execute(['LOG-'.$order_id.'-REV', $qty, $current['User_ID'], $current['ID'], $current['loc_id'], $current['DID'], $order_id]);
        }

        $success = "อัปเดตสถานะออเดอร์ " . htmlspecialchars($order_id) . " เรียบร้อยแล้ว";
    } catch (PDOException $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ── Fetch all orders with joins ───────────────────────────────────────────────
$orders = $pdo->query("
    SELECT o.*,
           u.User_name, u.tel AS customer_tel, u.Unpaid_amount,
           l.loc_name, l.details AS loc_details, l.latitude, l.longitude,
           d.DFname, d.DLname, d.tel AS driver_tel,
           m.MFname, m.MLname,
           -- Pull bottle type from linked order_request note if exists
           COALESCE(req.note, '') AS req_note
    FROM `order` o
    JOIN `user`    u   ON o.User_ID   = u.User_ID
    JOIN location  l   ON o.loc_id    = l.loc_id
    JOIN driver    d   ON o.DID       = d.DID
    LEFT JOIN manager       m   ON o.ID       = m.ID
    LEFT JOIN order_request req ON req.order_id = o.order_id
    ORDER BY o.scheduled_date DESC
")->fetchAll();

// ── Fetch transactions per order ─────────────────────────────────────────────
$txn_map = [];
$txns = $pdo->query("SELECT * FROM `transaction` ORDER BY confirmed_at ASC")->fetchAll();
foreach ($txns as $t) {
    $txn_map[$t['order_id']][] = $t;
}
?>

<style>
/* ── Bottle type pill ────────────────────────────────────────────────────────── */
.bottle-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #eaf6ff;
    color: #1a7ab5;
    border: 1px solid #b3d9f5;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 0.7rem;
    font-weight: 600;
    white-space: nowrap;
}
.bottle-pill.unknown {
    background: #f4f6fa;
    color: #95a5a6;
    border-color: #dde1ea;
}

/* ── Order detail drawer ─────────────────────────────────────────────────────── */
.order-drawer-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(27,42,74,0.5);
    z-index: 500;
    align-items: flex-start;
    justify-content: flex-end;
}
.order-drawer-backdrop.open { display: flex; }

.order-drawer {
    background: #fff;
    width: 460px;
    max-width: 100vw;
    height: 100vh;
    overflow-y: auto;
    box-shadow: -8px 0 40px rgba(27,42,74,0.2);
    animation: drawerSlide 0.25s ease;
    display: flex;
    flex-direction: column;
}
@keyframes drawerSlide {
    from { transform: translateX(100%); }
    to   { transform: translateX(0); }
}

.drawer-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    position: sticky; top: 0;
    background: #fff; z-index: 10;
}
.drawer-order-id  { font-size: 0.7rem; color: var(--grey); font-family: monospace; margin-bottom: 2px; }
.drawer-loc-name  { font-size: 1.15rem; font-weight: 700; color: var(--navy); }
.drawer-close {
    background: var(--light); border: none;
    width: 32px; height: 32px; border-radius: 8px;
    cursor: pointer; font-size: 1rem; color: var(--grey);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.drawer-close:hover { background: var(--border); color: var(--navy); }
.drawer-body { padding: 20px 24px; flex: 1; }

.drawer-section { margin-bottom: 22px; }
.drawer-section-title {
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--grey); margin-bottom: 10px;
    padding-bottom: 6px; border-bottom: 1px solid var(--border);
}

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.info-cell { background: var(--light); border-radius: 8px; padding: 10px 12px; }
.info-cell .label { font-size: 0.7rem; color: var(--grey); margin-bottom: 3px; }
.info-cell .value { font-size: 0.9rem; font-weight: 600; color: var(--navy); }
.info-cell.full-width { grid-column: 1 / -1; }
.info-cell.highlight-red   .value { color: var(--danger); }
.info-cell.highlight-green .value { color: var(--success); }

.pay-bar-wrap { margin: 10px 0; }
.pay-bar-label { display: flex; justify-content: space-between; font-size: 0.78rem; color: var(--grey); margin-bottom: 4px; }
.pay-bar-track { background: #eee; border-radius: 6px; height: 8px; overflow: hidden; }
.pay-bar-fill  { height: 8px; border-radius: 6px; background: linear-gradient(90deg, var(--blue), var(--foam)); transition: width 0.4s ease; }

.txn-row {
    display: grid; grid-template-columns: auto 1fr 1fr 1fr;
    gap: 8px; align-items: center;
    padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 0.82rem;
}
.txn-row:last-child { border-bottom: none; }
.txn-num {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--navy); color: #fff;
    font-size: 0.65rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.txn-date { color: var(--grey); font-size: 0.72rem; }
.txn-val  { font-weight: 600; color: var(--navy); }

.order-row { cursor: pointer; transition: background 0.12s; }
.order-row:hover   td { background: #EBF5FB !important; }
.order-row.selected td { background: #D6EAF8 !important; }

.modal-overlay { display: none; position: fixed; inset: 0;
    background: rgba(27,42,74,0.5); z-index: 600;
    align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: #fff; border-radius: 14px; padding: 28px;
    width: 420px; max-width: 95%; box-shadow: 0 20px 60px rgba(27,42,74,0.25); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.modal-header h3 { margin: 0; color: var(--navy); }

@media (max-width: 500px) {
    .order-drawer { width: 100vw; }
    .info-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-header">
    <div>
        <h2>จัดการออเดอร์</h2>
        <p>คลิกที่แถวออเดอร์เพื่อดูรายละเอียด</p>
    </div>
    <a href="order_form.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> เพิ่มออเดอร์ใหม่
    </a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<div class="search-bar">
    <input type="text" id="searchInput"
           placeholder="ค้นหา Order ID, ลูกค้า, สถานที่, พนักงานขับ, ประเภทขวด..."
           onkeyup="searchOrders()">
</div>

<div class="table-container" style="padding:0;">
    <table id="ordersTable">
        <thead>
            <tr>
                <th>Order ID</th>
                <th>ลูกค้า</th>
                <th>สถานที่</th>
                <th>พนักงานขับ</th>
                <th>วันที่</th>
                <th>จำนวน</th>
                <th>ประเภทขวด</th>
                <th>ยอดรวม</th>
                <th>สถานะ</th>
                <th>การชำระเงิน</th>
                <th onclick="event.stopPropagation()">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($orders as $order):
                $txns       = $txn_map[$order['order_id']] ?? [];
                $paid_total = array_sum(array_column($txns, 'amount_collected'));
                $del_total  = array_sum(array_column($txns, 'qty_delivered'));
                $remaining  = $order['total_expected_price'] - $paid_total;

                // Extract bottle type: prefer req_note, fall back to driver_note
                $source_note = $order['req_note'] ?: ($order['driver_note'] ?? '');
                $parsed      = extractBottleType($source_note);
                $bottle_type = $parsed['type'];
                $clean_note  = $parsed['note'];  // note with [tag] stripped

                $data = htmlspecialchars(json_encode([
                    'order_id'       => $order['order_id'],
                    'order_type'     => $order['order_type'],
                    'order_status'   => $order['order_status'],
                    'payment_status' => $order['payment_status'],
                    'scheduled_date' => $order['scheduled_date'],
                    'qty_ordered'    => $order['qty_ordered'],
                    'actual_qty'     => $order['actual_qty_ordered'] ?? 0,
                    'deposit_fee'    => $order['deposit_fee'],
                    'total_price'    => $order['total_expected_price'],
                    'actual_collected' => $order['actual_amount_collected'] ?? 0,
                    'actual_empties'   => $order['actual_empty_returned']   ?? 0,
                    'driver_note'    => $order['driver_note'] ?? '',
                    'bottle_type'    => $bottle_type,
                    'clean_note'     => $clean_note,
                    'confirmed'      => $order['confirmed_by_driver'],
                    'loc_name'       => $order['loc_name'],
                    'loc_details'    => $order['loc_details'],
                    'lat'            => $order['latitude'],
                    'lng'            => $order['longitude'],
                    'customer'       => $order['User_name'],
                    'customer_tel'   => $order['customer_tel'],
                    'unpaid_amount'  => $order['Unpaid_amount'],
                    'driver'         => $order['DFname'] . ' ' . $order['DLname'],
                    'driver_tel'     => $order['driver_tel'],
                    'manager'        => ($order['MFname'] ?? '') . ' ' . ($order['MLname'] ?? ''),
                    'txns'           => $txns,
                    'paid_total'     => $paid_total,
                    'remaining'      => $remaining,
                    'del_total'      => $del_total,
                ]), ENT_QUOTES);
            ?>
            <tr class="order-row" onclick="openDrawer(<?php echo $data; ?>)"
                data-id="<?php echo $order['order_id']; ?>">

                <td style="font-family:monospace; font-weight:600; font-size:0.82rem;">
                    <?php echo htmlspecialchars($order['order_id']); ?>
                </td>
                <td><?php echo htmlspecialchars($order['User_name']); ?></td>
                <td>
                    <?php echo htmlspecialchars($order['loc_name']); ?>
                    <div style="font-size:0.72rem; color:var(--grey);">
                        <?php echo htmlspecialchars($order['loc_details'] ?? ''); ?>
                    </div>
                </td>
                <td><?php echo htmlspecialchars($order['DFname'] . ' ' . $order['DLname']); ?></td>
                <td style="white-space:nowrap;"><?php echo $order['scheduled_date']; ?></td>
                <td style="text-align:center;">
                    <?php echo $order['qty_ordered']; ?>
                    <?php if(($order['actual_qty_ordered'] ?? 0) > 0 && $order['actual_qty_ordered'] < $order['qty_ordered']): ?>
                    <div style="font-size:0.7rem; color:var(--warning);">
                        ส่งแล้ว <?php echo $order['actual_qty_ordered']; ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Bottle type column -->
                <td>
                    <?php if ($bottle_type): ?>
                        <span class="bottle-pill">💧 <?php echo htmlspecialchars($bottle_type); ?></span>
                    <?php else: ?>
                        <span class="bottle-pill unknown">—</span>
                    <?php endif; ?>
                </td>

                <td style="text-align:right; white-space:nowrap;">
                    <?php echo number_format($order['total_expected_price'], 2); ?> ฿
                    <?php if($remaining > 0 && $order['payment_status'] !== 'paid'): ?>
                    <div style="font-size:0.7rem; color:var(--danger);">
                        ค้าง <?php echo number_format($remaining, 2); ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-badge status-<?php echo $order['order_status']; ?>">
                        <?php echo match($order['order_status']) {
                            'pending'    => '⏳ รอ',
                            'processing' => '🔄 กำลังส่ง',
                            'completed'  => '✅ เสร็จ',
                            'cancelled'  => '❌ ยกเลิก',
                            default      => $order['order_status']
                        }; ?>
                    </span>
                </td>
                <td>
                    <span class="status-badge status-<?php echo $order['payment_status']; ?>">
                        <?php echo match($order['payment_status']) {
                            'paid'    => '✅ ชำระแล้ว',
                            'partial' => '⚠️ บางส่วน',
                            'unpaid'  => '❌ ยังไม่ชำระ',
                            default   => $order['payment_status']
                        }; ?>
                    </span>
                </td>
                <td onclick="event.stopPropagation()" style="white-space:nowrap;">
                    <button class="btn btn-primary btn-sm"
                        onclick="openStatusModal(
                            '<?php echo htmlspecialchars($order['order_id']); ?>',
                            '<?php echo htmlspecialchars($order['order_status']); ?>',
                            '<?php echo htmlspecialchars($order['payment_status']); ?>',
                            '<?php echo htmlspecialchars($order['User_name']); ?>'
                        )">
                        <i class="fas fa-tasks"></i>
                    </button>
                    <a href="?delete=<?php echo $order['order_id']; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('ลบออเดอร์นี้?')">
                        <i class="fas fa-trash"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ══════════════════════════════════════════════════════════════
     ORDER DETAIL DRAWER
═══════════════════════════════════════════════════════════════ -->
<div class="order-drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer(event)">
    <div class="order-drawer" id="orderDrawer" onclick="event.stopPropagation()">

        <div class="drawer-header">
            <div>
                <div class="drawer-order-id" id="d_order_id"></div>
                <div class="drawer-loc-name"  id="d_loc_name"></div>
                <div style="font-size:0.8rem; color:var(--grey); margin-top:3px;" id="d_loc_details"></div>
            </div>
            <button class="drawer-close" onclick="closeDrawerBtn()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="drawer-body">

            <!-- Status badges + bottle type -->
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px;">
                <span id="d_order_status_badge"   class="status-badge"></span>
                <span id="d_payment_status_badge" class="status-badge"></span>
                <span id="d_confirmed_badge"      class="status-badge"></span>
                <span id="d_bottle_badge"         class="bottle-pill" style="display:none;"></span>
            </div>

            <!-- Payment summary -->
            <div class="drawer-section">
                <div class="drawer-section-title">💰 สรุปการชำระเงิน</div>
                <div class="pay-bar-wrap">
                    <div class="pay-bar-label">
                        <span id="d_pay_label_left"></span>
                        <span id="d_pay_label_right"></span>
                    </div>
                    <div class="pay-bar-track">
                        <div class="pay-bar-fill" id="d_pay_bar" style="width:0%"></div>
                    </div>
                </div>
                <div class="info-grid" style="margin-top:10px;">
                    <div class="info-cell">
                        <div class="label">ยอดรวม</div>
                        <div class="value" id="d_total_price"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">ค่ามัดจำ</div>
                        <div class="value" id="d_deposit"></div>
                    </div>
                    <div class="info-cell highlight-green">
                        <div class="label">ชำระแล้ว</div>
                        <div class="value" id="d_paid"></div>
                    </div>
                    <div class="info-cell highlight-red">
                        <div class="label">ยังค้างชำระ</div>
                        <div class="value" id="d_remaining"></div>
                    </div>
                </div>
            </div>

            <!-- Delivery summary -->
            <div class="drawer-section">
                <div class="drawer-section-title">📦 สรุปการจัดส่ง</div>
                <div class="info-grid">
                    <div class="info-cell">
                        <div class="label">จำนวนที่สั่ง</div>
                        <div class="value" id="d_qty_ordered"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">ส่งแล้ว</div>
                        <div class="value" id="d_qty_actual"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">รับขวดเปล่าคืน</div>
                        <div class="value" id="d_empties"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">วันที่นัดส่ง</div>
                        <div class="value" id="d_date"></div>
                    </div>
                    <!-- Bottle type row (full-width) -->
                    <div class="info-cell full-width" id="d_bottle_cell" style="display:none;">
                        <div class="label">ประเภทขวด / สินค้า</div>
                        <div class="value" id="d_bottle_val"
                             style="display:flex; align-items:center; gap:6px;">
                        </div>
                    </div>
                    <div class="info-cell full-width" id="d_note_cell" style="display:none;">
                        <div class="label">หมายเหตุจากคนขับ</div>
                        <div class="value" id="d_note" style="font-size:0.85rem; font-style:italic;"></div>
                    </div>
                </div>
            </div>

            <!-- People -->
            <div class="drawer-section">
                <div class="drawer-section-title">👤 ผู้เกี่ยวข้อง</div>
                <div class="info-grid">
                    <div class="info-cell">
                        <div class="label">ลูกค้า</div>
                        <div class="value" id="d_customer"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">เบอร์ลูกค้า</div>
                        <div class="value" id="d_customer_tel"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">พนักงานขับ</div>
                        <div class="value" id="d_driver"></div>
                    </div>
                    <div class="info-cell">
                        <div class="label">เบอร์คนขับ</div>
                        <div class="value" id="d_driver_tel"></div>
                    </div>
                    <div class="info-cell full-width">
                        <div class="label">อนุมัติโดย (Manager)</div>
                        <div class="value" id="d_manager"></div>
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="drawer-section" id="d_map_section" style="display:none;">
                <div class="drawer-section-title">📍 ที่ตั้ง</div>
                <a id="d_map_link" href="#" target="_blank"
                   class="btn btn-ghost" style="width:100%; justify-content:center;">
                    <i class="fas fa-map-marker-alt"></i> เปิด Google Maps
                </a>
            </div>

            <!-- Transaction history -->
            <div class="drawer-section" id="d_txn_section" style="display:none;">
                <div class="drawer-section-title">🔄 ประวัติการส่ง</div>
                <div id="d_txn_list"></div>
                <div id="d_txn_totals"
                     style="background:var(--light); border-radius:8px;
                            padding:10px 12px; font-size:0.82rem; margin-top:8px;"></div>
            </div>

        </div>

        <!-- Sticky footer -->
        <div style="padding:16px 24px; border-top:1px solid var(--border);
                    background:#fff; position:sticky; bottom:0;">
            <button id="d_manage_btn" class="btn btn-primary" style="width:100%;">
                <i class="fas fa-tasks"></i> จัดการสถานะ
            </button>
        </div>

    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     STATUS MODAL
═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="statusModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-tasks"></i> อัปเดตสถานะ</h3>
            <button class="drawer-close" onclick="closeStatusModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <p style="color:var(--grey); font-size:0.88rem; margin-bottom:20px;">
            ออเดอร์: <strong id="modalOrderId"></strong> —
            ลูกค้า: <strong id="modalCustomer"></strong>
        </p>
        <form method="POST">
            <input type="hidden" name="action"   value="update_status">
            <input type="hidden" name="order_id" id="modalOrderIdInput">
            <div class="form-group">
                <label><i class="fas fa-box"></i> สถานะออเดอร์</label>
                <select name="order_status" id="modalOrderStatus">
                    <option value="pending">⏳ รอดำเนินการ (pending)</option>
                    <option value="processing">🔄 กำลังดำเนินการ (processing)</option>
                    <option value="completed">✅ เสร็จสิ้น (completed)</option>
                    <option value="cancelled">❌ ยกเลิก (cancelled)</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:20px;">
                <label><i class="fas fa-money-bill"></i> สถานะการชำระเงิน</label>
                <select name="payment_status" id="modalPaymentStatus">
                    <option value="unpaid">❌ ยังไม่ชำระ (unpaid)</option>
                    <option value="partial">⚠️ ชำระบางส่วน (partial)</option>
                    <option value="paid">✅ ชำระแล้ว (paid)</option>
                </select>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeStatusModal()" class="btn btn-ghost">ยกเลิก</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentOrderId = null;

function fmt(n)    { return parseFloat(n||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtQty(n) { return parseInt(n||0).toLocaleString('th-TH'); }

const statusLabel = {
    pending:'⏳ รอดำเนินการ', processing:'🔄 กำลังส่ง',
    completed:'✅ เสร็จสิ้น', cancelled:'❌ ยกเลิก',
    paid:'✅ ชำระแล้ว', partial:'⚠️ บางส่วน', unpaid:'❌ ยังไม่ชำระ',
};

function openDrawer(data) {
    currentOrderId = data.order_id;
    document.querySelectorAll('.order-row').forEach(r => r.classList.remove('selected'));
    const row = document.querySelector(`[data-id="${data.order_id}"]`);
    if (row) row.classList.add('selected');

    // Header
    document.getElementById('d_order_id').textContent   = data.order_id + ' · ' + (data.order_type || '');
    document.getElementById('d_loc_name').textContent    = data.loc_name;
    document.getElementById('d_loc_details').textContent = data.loc_details || '';

    // Status badges
    const ob = document.getElementById('d_order_status_badge');
    ob.textContent = statusLabel[data.order_status] || data.order_status;
    ob.className   = 'status-badge status-' + data.order_status;

    const pb = document.getElementById('d_payment_status_badge');
    pb.textContent = statusLabel[data.payment_status] || data.payment_status;
    pb.className   = 'status-badge status-' + data.payment_status;

    const cb = document.getElementById('d_confirmed_badge');
    cb.textContent = data.confirmed ? '✓ คนขับยืนยันแล้ว' : '⏳ คนขับยังไม่ยืนยัน';
    cb.className   = 'status-badge ' + (data.confirmed ? 'status-completed' : 'status-pending');

    // Bottle type badge in header area
    const bb = document.getElementById('d_bottle_badge');
    if (data.bottle_type) {
        bb.textContent  = '💧 ' + data.bottle_type;
        bb.style.display = '';
    } else {
        bb.style.display = 'none';
    }

    // Payment bar
    const total = parseFloat(data.total_price || 0);
    const paid  = parseFloat(data.paid_total  || 0);
    const pct   = total > 0 ? Math.min(100, Math.round(paid / total * 100)) : 0;
    document.getElementById('d_pay_bar').style.width        = pct + '%';
    document.getElementById('d_pay_label_left').textContent  = 'ชำระแล้ว ' + fmt(paid) + ' ฿';
    document.getElementById('d_pay_label_right').textContent = pct + '%';
    document.getElementById('d_total_price').textContent    = fmt(data.total_price) + ' ฿';
    document.getElementById('d_deposit').textContent        = fmt(data.deposit_fee)  + ' ฿';
    document.getElementById('d_paid').textContent           = fmt(data.paid_total)   + ' ฿';
    const rem = document.getElementById('d_remaining');
    rem.textContent = fmt(data.remaining) + ' ฿';
    rem.closest('.info-cell').style.display = data.remaining > 0 ? '' : 'none';

    // Delivery
    document.getElementById('d_qty_ordered').textContent = fmtQty(data.qty_ordered)  + ' ขวด';
    document.getElementById('d_qty_actual').textContent  = fmtQty(data.actual_qty)   + ' ขวด';
    document.getElementById('d_empties').textContent     = fmtQty(data.actual_empties) + ' ขวด';
    document.getElementById('d_date').textContent        = data.scheduled_date || '-';

    // Bottle type row in delivery section
    const btCell = document.getElementById('d_bottle_cell');
    const btVal  = document.getElementById('d_bottle_val');
    if (data.bottle_type) {
        btVal.innerHTML = '<span class="bottle-pill">💧 ' + data.bottle_type + '</span>';
        btCell.style.display = '';
    } else {
        btCell.style.display = 'none';
    }

    // Driver note (with [tag] already stripped)
    const noteCell = document.getElementById('d_note_cell');
    const noteText = data.driver_note || '';
    if (noteText) {
        document.getElementById('d_note').textContent = noteText;
        noteCell.style.display = '';
    } else {
        noteCell.style.display = 'none';
    }

    // People
    document.getElementById('d_customer').textContent     = data.customer;
    document.getElementById('d_customer_tel').textContent = data.customer_tel || '-';
    document.getElementById('d_driver').textContent       = data.driver;
    document.getElementById('d_driver_tel').textContent   = data.driver_tel   || '-';
    document.getElementById('d_manager').textContent      = data.manager      || '-';

    // Map
    const mapSec = document.getElementById('d_map_section');
    if (data.lat && data.lng) {
        document.getElementById('d_map_link').href =
            'https://www.google.com/maps?q=' + data.lat + ',' + data.lng;
        mapSec.style.display = '';
    } else {
        mapSec.style.display = 'none';
    }

    // Transactions
    const txnSec  = document.getElementById('d_txn_section');
    const txnList = document.getElementById('d_txn_list');
    const txnTot  = document.getElementById('d_txn_totals');
    if (data.txns && data.txns.length > 0) {
        txnSec.style.display = '';
        let html = '', totDel = 0, totEmp = 0, totAmt = 0;
        data.txns.forEach((t, i) => {
            totDel += parseInt(t.qty_delivered        || 0);
            totEmp += parseInt(t.qty_empty_collected  || 0);
            totAmt += parseFloat(t.amount_collected   || 0);
            html += `
            <div class="txn-row">
                <div class="txn-num">${i+1}</div>
                <div>
                    <div class="txn-date">${t.confirmed_at}</div>
                    ${t.note ? '<div style="font-size:0.7rem;color:var(--grey);font-style:italic;">' + t.note + '</div>' : ''}
                </div>
                <div class="txn-val">📦 ${t.qty_delivered} ขวด<br>
                    <span style="font-size:0.7rem;color:var(--grey);">เปล่าคืน ${t.qty_empty_collected}</span>
                </div>
                <div class="txn-val" style="color:var(--success);">฿${fmt(t.amount_collected)}</div>
            </div>`;
        });
        txnList.innerHTML = html;
        txnTot.innerHTML  = `<strong>รวม:</strong> ส่ง ${totDel} ขวด · รับเปล่า ${totEmp} ขวด · เก็บเงิน <strong style="color:var(--success);">฿${fmt(totAmt)}</strong>`;
    } else {
        txnSec.style.display = 'none';
    }

    document.getElementById('d_manage_btn').onclick = function() {
        openStatusModal(data.order_id, data.order_status, data.payment_status, data.customer);
    };

    document.getElementById('drawerBackdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeDrawer(e) {
    if (e && e.target !== document.getElementById('drawerBackdrop')) return;
    closeDrawerBtn();
}
function closeDrawerBtn() {
    document.getElementById('drawerBackdrop').classList.remove('open');
    document.body.style.overflow = '';
    document.querySelectorAll('.order-row').forEach(r => r.classList.remove('selected'));
}

function openStatusModal(orderId, orderStatus, paymentStatus, customerName) {
    document.getElementById('modalOrderId').textContent  = orderId;
    document.getElementById('modalCustomer').textContent = customerName;
    document.getElementById('modalOrderIdInput').value   = orderId;
    document.getElementById('modalOrderStatus').value    = orderStatus;
    document.getElementById('modalPaymentStatus').value  = paymentStatus;
    document.getElementById('statusModal').classList.add('open');
}
function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('open');
}
document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) closeStatusModal();
});

function searchOrders() {
    const filter = document.getElementById('searchInput').value.toUpperCase();
    document.querySelectorAll('#ordersTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toUpperCase().includes(filter) ? '' : 'none';
    });
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeDrawerBtn(); closeStatusModal(); }
});
</script>

<?php require_once '../includes/footer.php'; ?>