<?php
require_once '../includes/auth.php';
require_login('driver');
require_once '../config/database.php';

$driver_id = $_SESSION['ref_id'];
$order_id  = $_GET['id'] ?? null;

if (!$order_id) { header('Location: index.php'); exit; }

// ── Helper: extract [Product Name] from note ─────────────────────────────────
function extractBottleType(string $note): string {
    if (preg_match('/^\[([^\]]+)\]/', $note, $m)) return trim($m[1]);
    return '';
}

// ── Fetch order ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT o.*, u.User_name, u.tel,
           l.loc_name, l.details, l.bottle_on_hand, l.latitude, l.longitude, l.loc_id,
           COALESCE(req.note, '') AS req_note
    FROM `order` o
    JOIN user u       ON o.User_ID  = u.User_ID
    JOIN location l   ON o.loc_id   = l.loc_id
    LEFT JOIN order_request req ON req.order_id = o.order_id
    WHERE o.order_id = ? AND o.DID = ?
");
$stmt->execute([$order_id, $driver_id]);
$order = $stmt->fetch();
if (!$order) { header('Location: index.php'); exit; }

// ── Bottle type requested ─────────────────────────────────────────────────────
$requested_bottle = extractBottleType($order['req_note']);

// ── Fetch all stock types ─────────────────────────────────────────────────────
$stocks = $pdo->query("SELECT * FROM stock ORDER BY bottle_type ASC")->fetchAll();

// ── Fetch past transactions ───────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM `transaction` WHERE order_id = ? ORDER BY confirmed_at DESC");
$stmt->execute([$order_id]);
$transactions = $stmt->fetchAll();

$total_delivered_so_far = array_sum(array_column($transactions, 'qty_delivered'));
$total_collected_so_far = array_sum(array_column($transactions, 'amount_collected'));
$total_empties_so_far   = array_sum(array_column($transactions, 'qty_empty_collected'));
$remaining_qty          = $order['qty_ordered'] - $total_delivered_so_far;
$remaining_payment      = $order['total_expected_price'] - $total_collected_so_far;

$success = '';
$error   = '';

// ════════════════════════════════════════════════════════════════════════════
//  POST HANDLER
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $empties_this_trip = (int)   ($_POST['qty_empty_collected'] ?? 0);
        $amount_this_trip  = (float) ($_POST['amount_collected']    ?? 0);
        $note              =         ($_POST['driver_note']         ?? '');

        // ── Multi-bottle: stock_id[] + qty_per_type[] ─────────────────────
        $stock_ids     = $_POST['stock_id']     ?? [];
        $qty_per_type  = $_POST['qty_per_type'] ?? [];

        // Calculate total qty this trip from all rows
        $qty_this_trip = 0;
        $bottle_rows   = []; // validated non-zero rows
        foreach ($stock_ids as $idx => $sid) {
            $q = (int)($qty_per_type[$idx] ?? 0);
            if ($sid && $q > 0) {
                $bottle_rows[] = ['stock_id' => $sid, 'qty' => $q];
                $qty_this_trip += $q;
            }
        }

        $new_total_delivered = $total_delivered_so_far + $qty_this_trip;
        $new_total_collected = $total_collected_so_far + $amount_this_trip;
        $new_total_empties   = $total_empties_so_far   + $empties_this_trip;

        // Determine statuses
        if ($action === 'cancel') {
            $new_order_status = 'cancelled';
            $new_pay_status   = $order['payment_status'];
        } else {
            $fully_delivered = ($new_total_delivered >= $order['qty_ordered']);
            $fully_paid      = ($new_total_collected >= $order['total_expected_price']);

            if ($fully_delivered && $fully_paid) {
                $new_order_status = 'completed';
                $new_pay_status   = 'paid';
            } elseif ($new_total_collected > 0 && !$fully_paid) {
                $new_order_status = $fully_delivered ? 'completed' : 'processing';
                $new_pay_status   = 'partial';
            } else {
                $new_order_status = $qty_this_trip > 0 ? 'processing' : 'pending';
                $new_pay_status   = 'unpaid';
            }
        }

        $pdo->beginTransaction();

        // 1. Insert transaction (one record, total qty of this trip)
        if ($action !== 'cancel' && $qty_this_trip > 0) {
            $txn_id = 'TXN-' . $order_id . '-' . time();
            $pdo->prepare("
                INSERT INTO `transaction`
                    (txn_id, txn_type, qty_delivered, qty_empty_collected, amount_collected, note, order_id, DID)
                VALUES (?, 'delivery', ?, ?, ?, ?, ?, ?)
            ")->execute([$txn_id, $qty_this_trip, $empties_this_trip, $amount_this_trip, $note, $order_id, $driver_id]);
        }

        // 2. Update order
        $pdo->prepare("
            UPDATE `order` SET
                actual_qty_ordered      = ?,
                actual_empty_returned   = ?,
                actual_amount_collected = ?,
                order_status            = ?,
                payment_status          = ?,
                confirmed_by_driver     = 1,
                driver_note             = ?
            WHERE order_id = ? AND DID = ?
        ")->execute([
            $new_total_delivered, $new_total_empties, $new_total_collected,
            $new_order_status, $new_pay_status, $note, $order_id, $driver_id
        ]);

        // 3. Update location bottle_on_hand
        if ($action !== 'cancel' && $qty_this_trip > 0) {
            $pdo->prepare("
                UPDATE location SET bottle_on_hand = bottle_on_hand + ? - ? WHERE loc_id = ?
            ")->execute([$qty_this_trip, $empties_this_trip, $order['loc_id']]);
        }

        // 4. Per-bottle-type: deduct stock + log each type separately
        if ($action !== 'cancel' && !empty($bottle_rows)) {
            foreach ($bottle_rows as $br) {
                // Deduct stock
                $pdo->prepare("
                    UPDATE stock SET total_qty = total_qty - ? WHERE stock_id = ?
                ")->execute([$br['qty'], $br['stock_id']]);

                // Get bottle_type label for log
                $s = $pdo->prepare("SELECT bottle_type FROM stock WHERE stock_id = ?");
                $s->execute([$br['stock_id']]);
                $srow = $s->fetch();

                $log_id = 'LOG-' . $order_id . '-' . $br['stock_id'] . '-' . time();
                $pdo->prepare("
                    INSERT INTO stock_log
                        (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                    VALUES (?, NOW(), 'stock_out', ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $log_id, $br['qty'], $srow['bottle_type'],
                    $order['User_ID'], $order['ID'], $order['loc_id'], $driver_id, $order_id
                ]);
            }

            // Log empty returns under first bottle type used
            if ($empties_this_trip > 0) {
                $first = $bottle_rows[0];
                $s = $pdo->prepare("SELECT bottle_type FROM stock WHERE stock_id = ?");
                $s->execute([$first['stock_id']]);
                $srow   = $s->fetch();
                $log_id = 'LOG-' . $order_id . '-RET-' . time();
                $pdo->prepare("
                    INSERT INTO stock_log
                        (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                    VALUES (?, NOW(), 'empty_return', ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $log_id, $empties_this_trip, $srow['bottle_type'],
                    $order['User_ID'], $order['ID'], $order['loc_id'], $driver_id, $order_id
                ]);
            }
        }

        // 5. Update customer Unpaid_amount
        if ($action !== 'cancel' && $amount_this_trip > 0) {
            $pdo->prepare("
                UPDATE user SET Unpaid_amount = GREATEST(0, Unpaid_amount - ?) WHERE User_ID = ?
            ")->execute([$amount_this_trip, $order['User_ID']]);
        }

        $pdo->commit();
        $success = $action === 'cancel' ? 'ยกเลิกออเดอร์แล้ว' : 'บันทึกข้อมูลเรียบร้อยแล้ว';

        // Refresh data
        $stmt = $pdo->prepare("
            SELECT o.*, u.User_name, u.tel,
                   l.loc_name, l.details, l.bottle_on_hand, l.latitude, l.longitude, l.loc_id,
                   COALESCE(req.note,'') AS req_note
            FROM `order` o
            JOIN user u     ON o.User_ID = u.User_ID
            JOIN location l ON o.loc_id  = l.loc_id
            LEFT JOIN order_request req ON req.order_id = o.order_id
            WHERE o.order_id = ? AND o.DID = ?
        ");
        $stmt->execute([$order_id, $driver_id]);
        $order = $stmt->fetch();
        $requested_bottle = extractBottleType($order['req_note']);

        $stmt = $pdo->prepare("SELECT * FROM `transaction` WHERE order_id = ? ORDER BY confirmed_at DESC");
        $stmt->execute([$order_id]);
        $transactions = $stmt->fetchAll();

        $total_delivered_so_far = array_sum(array_column($transactions, 'qty_delivered'));
        $total_collected_so_far = array_sum(array_column($transactions, 'amount_collected'));
        $total_empties_so_far   = array_sum(array_column($transactions, 'qty_empty_collected'));
        $remaining_qty          = $order['qty_ordered'] - $total_delivered_so_far;
        $remaining_payment      = $order['total_expected_price'] - $total_collected_so_far;

        // Refresh stock
        $stocks = $pdo->query("SELECT * FROM stock ORDER BY bottle_type ASC")->fetchAll();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Build stock map for JS
$stocks_js = json_encode(array_values(array_map(fn($s) => [
    'stock_id'    => $s['stock_id'],
    'bottle_type' => $s['bottle_type'],
    'total_qty'   => (int)$s['total_qty'],
], $stocks)));

include '../includes/header_driver.php';
?>

<style>
/* Multi-bottle input table */
.bottle-table { width:100%; border-collapse:collapse; }
.bottle-table th {
    font-size:0.72rem; font-weight:700; color:#5d7099;
    text-transform:uppercase; letter-spacing:0.05em;
    padding:6px 8px; text-align:left; border-bottom:1px solid #dde1ea;
}
.bottle-table td { padding:6px 4px; vertical-align:middle; }
.bottle-table select,
.bottle-table input[type="number"] {
    width:100%; padding:8px 10px;
    border:1.5px solid #dde1ea; border-radius:8px;
    font-size:0.9rem; background:#fafbfc; color:#1b2a4a;
    box-sizing:border-box;
}
.bottle-table select:focus,
.bottle-table input[type="number"]:focus {
    outline:none; border-color:#3498db;
    box-shadow: 0 0 0 3px rgba(52,152,219,0.12);
}
.btn-remove-row {
    background:none; border:none; color:#e74c3c;
    font-size:1.1rem; cursor:pointer; padding:4px 6px;
    border-radius:6px; transition:background 0.1s;
    flex-shrink:0;
}
.btn-remove-row:hover { background:#fef2f2; }
.btn-add-row {
    display:inline-flex; align-items:center; gap:6px;
    background:#f0f9ff; color:#1a7ab5; border:1.5px dashed #b3d9f5;
    border-radius:8px; padding:8px 14px; font-size:0.85rem;
    font-weight:600; cursor:pointer; margin-top:8px;
    width:100%; justify-content:center;
    transition:background 0.12s, border-color 0.12s;
}
.btn-add-row:hover { background:#e0f2fe; border-color:#5dade2; }

/* Requested badge */
.req-bottle-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:#fff3cd; color:#856404;
    border:1px solid #ffc107; border-radius:20px;
    padding:3px 12px; font-size:0.78rem; font-weight:600;
}

/* Total chip */
.qty-total-chip {
    display:flex; justify-content:space-between; align-items:center;
    background:#f0f6ff; border-radius:8px; padding:8px 14px;
    font-size:0.85rem; color:#1b2a4a; margin-top:6px;
}
</style>

<div class="container" style="padding-bottom:40px;">

    <a href="index.php" style="display:inline-block; margin-bottom:16px; color:#7f8c8d; font-size:0.9rem;">
        ← กลับ
    </a>

    <?php if ($success): ?>
    <div style="background:#d5f5e3; border:1px solid #27ae60; color:#1e8449;
                padding:12px 16px; border-radius:8px; margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div style="background:#fadbd8; border:1px solid #e74c3c; color:#c0392b;
                padding:12px 16px; border-radius:8px; margin-bottom:16px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <!-- ── Order header card ──────────────────────────────────────────────── -->
    <div class="job-card" style="margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <span style="font-size:0.7rem; color:#7f8c8d;"><?php echo htmlspecialchars($order['order_id']); ?></span>
                <h2 style="margin:4px 0 2px 0; font-size:1.2rem;"><?php echo htmlspecialchars($order['loc_name']); ?></h2>
                <p style="margin:0; font-size:0.85rem; color:#7f8c8d;"><?php echo htmlspecialchars($order['details'] ?? ''); ?></p>
                <?php if ($requested_bottle): ?>
                <div style="margin-top:6px;">
                    <span class="req-bottle-badge">
                        💧 สินค้าที่ลูกค้าสั่ง: <?php echo htmlspecialchars($requested_bottle); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php $sc = match($order['order_status']) {
                'completed'  => ['#d5f5e3','#1e8449','เสร็จสิ้น'],
                'processing' => ['#d6eaf8','#1a5276','กำลังดำเนินการ'],
                'cancelled'  => ['#fadbd8','#c0392b','ยกเลิก'],
                default      => ['#fef9e7','#7d6608','รอดำเนินการ'],
            }; ?>
            <span style="background:<?php echo $sc[0]; ?>; color:<?php echo $sc[1]; ?>;
                         padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:bold;">
                <?php echo $sc[2]; ?>
            </span>
        </div>

        <!-- Progress -->
        <div style="margin-top:16px;">
            <div style="margin-bottom:10px;">
                <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:4px;">
                    <span>ส่งแล้ว <?php echo $total_delivered_so_far; ?> / <?php echo $order['qty_ordered']; ?> ขวด</span>
                    <span style="color:#<?php echo $remaining_qty <= 0 ? '27ae60' : 'e67e22'; ?>;">
                        <?php echo $remaining_qty <= 0 ? 'ครบแล้ว' : 'คงเหลือ '.$remaining_qty.' ขวด'; ?>
                    </span>
                </div>
                <?php $pct = $order['qty_ordered'] > 0 ? min(100, round($total_delivered_so_far / $order['qty_ordered'] * 100)) : 0; ?>
                <div style="background:#eee; border-radius:4px; height:8px;">
                    <div style="background:#27ae60; width:<?php echo $pct; ?>%; height:8px; border-radius:4px;"></div>
                </div>
            </div>
            <div>
                <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:4px;">
                    <span>เก็บเงินแล้ว <?php echo number_format($total_collected_so_far,2); ?> / <?php echo number_format($order['total_expected_price'],2); ?> บาท</span>
                    <span style="color:#<?php echo $remaining_payment <= 0 ? '27ae60' : 'e74c3c'; ?>;">
                        <?php echo $remaining_payment <= 0 ? 'ชำระครบ' : 'ค้าง '.number_format($remaining_payment,2).' บาท'; ?>
                    </span>
                </div>
                <?php $ppct = $order['total_expected_price'] > 0 ? min(100, round($total_collected_so_far / $order['total_expected_price'] * 100)) : 0; ?>
                <div style="background:#eee; border-radius:4px; height:8px;">
                    <div style="background:#3498db; width:<?php echo $ppct; ?>%; height:8px; border-radius:4px;"></div>
                </div>
            </div>
        </div>

        <!-- Customer -->
        <div style="margin-top:14px; font-size:0.85rem; color:#555;">
            <p style="margin:2px 0;">
                <i class="fas fa-user"></i> <?php echo htmlspecialchars($order['User_name']); ?>
                &nbsp;|&nbsp;
                <i class="fas fa-phone"></i>
                <a href="tel:<?php echo $order['tel']; ?>" style="color:#2471a3;">
                    <?php echo htmlspecialchars($order['tel']); ?>
                </a>
            </p>
            <p style="margin:2px 0;">
                <i class="fas fa-wine-bottle"></i> ขวดที่สถานที่ตอนนี้:
                <strong><?php echo $order['bottle_on_hand']; ?> ขวด</strong>
            </p>
        </div>

        <?php if ($order['latitude'] && $order['longitude']): ?>
        <a href="https://www.google.com/maps?q=<?php echo $order['latitude']; ?>,<?php echo $order['longitude']; ?>"
           target="_blank" class="btn-action"
           style="display:block; margin-top:12px; background:#eee; color:#333; text-align:center;">
            <i class="fas fa-map-marker-alt"></i> นำทาง Google Maps
        </a>
        <?php endif; ?>
    </div>

    <!-- ── UPDATE FORM ────────────────────────────────────────────────────── -->
    <?php if ($order['order_status'] !== 'cancelled'): ?>
    <div class="job-card" style="margin-bottom:16px;">
        <h3 style="margin:0 0 18px 0; font-size:1rem;">
            <i class="fas fa-edit"></i>
            <?php echo $order['confirmed_by_driver'] ? 'อัปเดตข้อมูลการส่ง' : 'บันทึกการส่งครั้งนี้'; ?>
        </h3>

        <form method="POST" id="updateForm">
            <input type="hidden" name="action" value="submit">

            <!-- ── MULTI-BOTTLE TYPE SECTION ─────────────────────────────── -->
            <div style="margin-bottom:18px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label style="font-size:0.85rem; font-weight:bold; color:#2c3e50;">
                        <i class="fas fa-boxes"></i> ขวดที่นำไปส่งครั้งนี้
                    </label>
                    <?php if ($requested_bottle): ?>
                    <span style="font-size:0.72rem; color:#856404; background:#fff3cd;
                                 padding:2px 8px; border-radius:10px; border:1px solid #ffc107;">
                        💧 ลูกค้าสั่ง: <?php echo htmlspecialchars($requested_bottle); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Bottle rows container -->
                <div id="bottleRows">
                    <!-- Row template will be cloned here by JS -->
                </div>

                <button type="button" class="btn-add-row" onclick="addBottleRow()">
                    <i class="fas fa-plus-circle"></i> เพิ่มประเภทขวด
                </button>

                <!-- Running total display -->
                <div class="qty-total-chip" id="qtyTotalChip">
                    <span>จำนวนรวมครั้งนี้</span>
                    <strong id="qtyTotalDisplay" style="font-size:1rem; color:#1b2a4a;">0 ขวด</strong>
                </div>
                <?php if ($remaining_qty > 0): ?>
                <div style="font-size:0.75rem; color:#e67e22; margin-top:4px; text-align:right;">
                    คงเหลือต้องส่ง <?php echo $remaining_qty; ?> ขวด
                </div>
                <?php endif; ?>
            </div>

            <!-- Empty bottles -->
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-recycle"></i> ขวดเปล่าที่รับคืน:
                </label>
                <input type="number" name="qty_empty_collected" min="0"
                       value="<?php echo $total_empties_so_far > 0 ? 0 : $order['bottle_on_hand']; ?>"
                       style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;
                              font-size:0.95rem; box-sizing:border-box;">
            </div>

            <!-- Amount collected -->
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-money-bill-wave"></i> เงินที่เก็บได้ครั้งนี้ (บาท):
                    <span style="color:#e74c3c; font-size:0.8rem;">
                        (ค้าง <?php echo number_format(max(0, $remaining_payment), 2); ?> บาท)
                    </span>
                </label>
                <input type="number" step="0.01" name="amount_collected" min="0"
                       value="<?php echo number_format(max(0, $remaining_payment), 2, '.', ''); ?>"
                       style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;
                              font-size:0.95rem; box-sizing:border-box;">
            </div>

            <!-- Driver note -->
            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-sticky-note"></i> หมายเหตุ:
                </label>
                <textarea name="driver_note" rows="2"
                          style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;
                                 font-size:0.95rem; box-sizing:border-box; resize:vertical;"
                          placeholder="ไม่มีใครอยู่บ้าน / รับบางส่วน / อื่นๆ"><?php echo htmlspecialchars($order['driver_note'] ?? ''); ?></textarea>
            </div>

            <!-- Buttons -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <button type="submit" name="action" value="submit" class="btn-action"
                        style="background:linear-gradient(135deg,#27ae60,#1e8449); color:#fff; border:none; cursor:pointer;"
                        onclick="return validateForm()">
                    <i class="fas fa-save"></i> บันทึก
                </button>
                <button type="submit" name="action" value="cancel" class="btn-action"
                        style="background:#fadbd8; color:#c0392b; border:none; cursor:pointer;"
                        onclick="return confirm('ยืนยันการยกเลิกออเดอร์นี้?')">
                    <i class="fas fa-times"></i> ยกเลิกออเดอร์
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Transaction history ────────────────────────────────────────────── -->
    <?php if (!empty($transactions)): ?>
    <div class="job-card">
        <h3 style="margin:0 0 14px 0; font-size:1rem;">
            <i class="fas fa-history"></i> ประวัติการส่งออเดอร์นี้
        </h3>
        <?php foreach($transactions as $i => $t): ?>
        <div style="border-left:3px solid #3498db; padding:10px 14px; margin-bottom:10px;
                    background:#f8f9fa; border-radius:0 8px 8px 0;">
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                <span style="font-size:0.75rem; color:#7f8c8d;">
                    ครั้งที่ <?php echo count($transactions) - $i; ?>
                    — <?php echo $t['confirmed_at']; ?>
                </span>
            </div>
            <div style="font-size:0.85rem; display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px;">
                <div><span style="color:#7f8c8d;">ส่ง</span><br><strong><?php echo $t['qty_delivered']; ?> ขวด</strong></div>
                <div><span style="color:#7f8c8d;">รับเปล่าคืน</span><br><strong><?php echo $t['qty_empty_collected']; ?> ขวด</strong></div>
                <div><span style="color:#7f8c8d;">เก็บเงิน</span><br><strong><?php echo number_format($t['amount_collected'],2); ?> บาท</strong></div>
            </div>
            <?php if ($t['note']): ?>
            <p style="margin:6px 0 0 0; font-size:0.8rem; color:#7f8c8d; font-style:italic;">
                หมายเหตุ: <?php echo htmlspecialchars($t['note']); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div style="background:#eaf4fb; border-radius:8px; padding:10px 14px; font-size:0.85rem;">
            <strong>ยอดรวมทั้งหมด:</strong>
            ส่ง <?php echo $total_delivered_so_far; ?> ขวด |
            รับเปล่า <?php echo $total_empties_so_far; ?> ขวด |
            เก็บเงิน <?php echo number_format($total_collected_so_far,2); ?> บาท
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Hidden row template (cloned by JS) ────────────────────────────────── -->
<template id="bottleRowTemplate">
    <div class="bottle-row" style="display:grid; grid-template-columns:1fr 90px 36px; gap:6px; margin-bottom:8px; align-items:center;">
        <select name="stock_id[]" onchange="updateTotal()" required
                style="padding:9px 10px; border:1.5px solid #dde1ea; border-radius:8px;
                       font-size:0.88rem; background:#fafbfc; color:#1b2a4a; box-sizing:border-box; width:100%;">
            <option value="">— เลือกประเภทขวด —</option>
        </select>
        <input type="number" name="qty_per_type[]" min="1" value="1"
               placeholder="จำนวน" oninput="updateTotal()"
               style="padding:9px 10px; border:1.5px solid #dde1ea; border-radius:8px;
                      font-size:0.95rem; text-align:center; box-sizing:border-box; width:100%;">
        <button type="button" class="btn-remove-row" onclick="removeBottleRow(this)"
                style="background:none; border:none; color:#e74c3c; font-size:1.2rem;
                       cursor:pointer; padding:6px; border-radius:6px;">
            <i class="fas fa-times-circle"></i>
        </button>
    </div>
</template>

<script>
const STOCKS        = <?php echo $stocks_js; ?>;
const REMAINING_QTY = <?php echo max(0, $remaining_qty); ?>;
// Pre-select the requested bottle type if it matches a stock entry
const REQUESTED     = <?php echo json_encode($requested_bottle); ?>;

function buildOptions(selectEl, selectedStockId) {
    selectEl.innerHTML = '<option value="">— เลือกประเภทขวด —</option>';
    STOCKS.forEach(s => {
        const opt = document.createElement('option');
        opt.value       = s.stock_id;
        opt.textContent = s.bottle_type + ' (คงเหลือ: ' + s.total_qty + ')';
        if (s.stock_id === selectedStockId) opt.selected = true;
        selectEl.appendChild(opt);
    });
}

function addBottleRow(preSelectId, preQty) {
    const tmpl  = document.getElementById('bottleRowTemplate');
    const clone = tmpl.content.cloneNode(true);
    const row   = clone.querySelector('.bottle-row');
    const sel   = row.querySelector('select');
    const qtyIn = row.querySelector('input[type="number"]');

    buildOptions(sel, preSelectId || '');
    if (preQty) qtyIn.value = preQty;

    document.getElementById('bottleRows').appendChild(row);
    updateTotal();
}

function removeBottleRow(btn) {
    const rows = document.querySelectorAll('#bottleRows .bottle-row');
    if (rows.length <= 1) {
        // Reset instead of remove
        rows[0].querySelector('select').value = '';
        rows[0].querySelector('input[type="number"]').value = 1;
        updateTotal();
        return;
    }
    btn.closest('.bottle-row').remove();
    updateTotal();
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('#bottleRows .bottle-row').forEach(row => {
        const q = parseInt(row.querySelector('input[type="number"]').value) || 0;
        const s = row.querySelector('select').value;
        if (s) total += q;
    });
    document.getElementById('qtyTotalDisplay').textContent = total + ' ขวด';
    // Highlight if over remaining
    const chip = document.getElementById('qtyTotalChip');
    chip.style.background = (REMAINING_QTY > 0 && total > REMAINING_QTY) ? '#fef2f2' : '#f0f6ff';
    document.getElementById('qtyTotalDisplay').style.color =
        (REMAINING_QTY > 0 && total > REMAINING_QTY) ? '#e74c3c' : '#1b2a4a';
}

function validateForm() {
    const rows = document.querySelectorAll('#bottleRows .bottle-row');
    let hasAny = false;
    for (const row of rows) {
        const s = row.querySelector('select').value;
        const q = parseInt(row.querySelector('input[type="number"]').value) || 0;
        if (s && q > 0) { hasAny = true; break; }
    }
    if (!hasAny) {
        alert('กรุณาเลือกประเภทขวดและระบุจำนวนอย่างน้อย 1 รายการ');
        return false;
    }
    return true;
}

// ── Init: add one default row, pre-select requested bottle type ───────────────
(function init() {
    // Find stock_id matching the requested bottle name
    let defaultId = '';
    if (REQUESTED) {
        const match = STOCKS.find(s =>
            s.bottle_type.toLowerCase().includes(REQUESTED.toLowerCase()) ||
            REQUESTED.toLowerCase().includes(s.bottle_type.toLowerCase())
        );
        if (match) defaultId = match.stock_id;
    }
    addBottleRow(defaultId, Math.max(1, REMAINING_QTY));
})();
</script>

<?php include '../includes/footer.php'; ?>