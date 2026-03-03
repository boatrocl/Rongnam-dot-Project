<?php
require_once '../includes/auth.php';
require_login('driver');
require_once '../config/database.php';

$driver_id = $_SESSION['ref_id'];
$order_id  = $_GET['id'] ?? null;

if (!$order_id) {
    header('Location: index.php');
    exit;
}

// ── Fetch order — verify it belongs to this driver ───────────────────────────
$stmt = $pdo->prepare("
    SELECT o.*, u.User_name, u.tel, l.loc_name, l.details, l.bottle_on_hand,
           l.latitude, l.longitude, l.loc_id
    FROM `order` o
    JOIN user u     ON o.User_ID = u.User_ID
    JOIN location l ON o.loc_id  = l.loc_id
    WHERE o.order_id = ? AND o.DID = ?
");
$stmt->execute([$order_id, $driver_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php');
    exit;
}

// ── Fetch previous transactions for this order ───────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM `transaction`
    WHERE order_id = ?
    ORDER BY confirmed_at DESC
");
$stmt->execute([$order_id]);
$transactions = $stmt->fetchAll();

// ── Calculate totals already delivered/collected across all past transactions ─
$total_delivered_so_far = array_sum(array_column($transactions, 'qty_delivered'));
$total_collected_so_far = array_sum(array_column($transactions, 'amount_collected'));
$total_empties_so_far   = array_sum(array_column($transactions, 'qty_empty_collected'));
$remaining_qty          = $order['qty_ordered'] - $total_delivered_so_far;
$remaining_payment      = $order['total_expected_price'] - $total_collected_so_far;

// ── Fetch stock options for dropdown ─────────────────────────────────────────
$stocks = $pdo->query("SELECT * FROM stock ORDER BY bottle_type ASC")->fetchAll();

$success = '';
$error   = '';

// ════════════════════════════════════════════════════════════════════════════
//  POST HANDLER
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ── Determine new order + payment status based on quantities ──────────
        $qty_this_trip      = (int)   ($_POST['qty_delivered']      ?? 0);
        $empties_this_trip  = (int)   ($_POST['qty_empty_collected'] ?? 0);
        $amount_this_trip   = (float) ($_POST['amount_collected']    ?? 0);
        $note               =         ($_POST['driver_note']         ?? '');
        $stock_id           =         ($_POST['stock_id']            ?? null);

        $new_total_delivered  = $total_delivered_so_far + $qty_this_trip;
        $new_total_collected  = $total_collected_so_far + $amount_this_trip;
        $new_total_empties    = $total_empties_so_far   + $empties_this_trip;

        // Determine order_status
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

        // 1. Insert transaction record
        if ($action !== 'cancel' && $qty_this_trip > 0) {
            $txn_id = 'TXN-' . $order_id . '-' . time();
            $stmt = $pdo->prepare("
                INSERT INTO `transaction`
                    (txn_id, txn_type, qty_delivered, qty_empty_collected, amount_collected, note, order_id, DID)
                VALUES (?, 'delivery', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $txn_id,
                $qty_this_trip,
                $empties_this_trip,
                $amount_this_trip,
                $note,
                $order_id,
                $driver_id
            ]);
        }

        // 2. Update order with running totals + new statuses
        $stmt = $pdo->prepare("
            UPDATE `order` SET
                actual_qty_ordered       = ?,
                actual_empty_returned    = ?,
                actual_amount_collected  = ?,
                order_status             = ?,
                payment_status           = ?,
                confirmed_by_driver      = 1,
                driver_note              = ?
            WHERE order_id = ? AND DID = ?
        ");
        $stmt->execute([
            $new_total_delivered,
            $new_total_empties,
            $new_total_collected,
            $new_order_status,
            $new_pay_status,
            $note,
            $order_id,
            $driver_id
        ]);

        // 3. Update bottle_on_hand at location
        if ($action !== 'cancel' && $qty_this_trip > 0) {
            // Full bottles arrive at location, empties leave
            $stmt = $pdo->prepare("
                UPDATE location
                SET bottle_on_hand = bottle_on_hand + ? - ?
                WHERE loc_id = ?
            ");
            $stmt->execute([$qty_this_trip, $empties_this_trip, $order['loc_id']]);
        }

        // 4. Update stock (deduct from chosen bottle type)
        if ($action !== 'cancel' && $qty_this_trip > 0 && $stock_id) {
            $stmt = $pdo->prepare("
                UPDATE stock
                SET total_qty = total_qty - ?
                WHERE stock_id = ?
            ");
            $stmt->execute([$qty_this_trip, $stock_id]);

            // Get stock details for log
            $s = $pdo->prepare("SELECT bottle_type FROM stock WHERE stock_id = ?");
            $s->execute([$stock_id]);
            $stock_row = $s->fetch();

            // 5. Insert stock_log (needs all 5 composite FK fields from order)
            $log_id = 'LOG-' . $order_id . '-' . time();
            $stmt = $pdo->prepare("
                INSERT INTO stock_log
                    (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                VALUES (?, NOW(), 'stock_out', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $log_id,
                $qty_this_trip,
                $stock_row['bottle_type'],
                $order['User_ID'],
                $order['ID'],
                $order['loc_id'],
                $driver_id,
                $order_id
            ]);

            // Also log empty returns if any
            if ($empties_this_trip > 0) {
                $log_id2 = 'LOG-' . $order_id . '-RET-' . time();
                $stmt = $pdo->prepare("
                    INSERT INTO stock_log
                        (log_id, timestamp, action_type, total_qty, bottle_type, User_ID, ID, loc_id, DID, order_id)
                    VALUES (?, NOW(), 'empty_return', ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $log_id2,
                    $empties_this_trip,
                    $stock_row['bottle_type'],
                    $order['User_ID'],
                    $order['ID'],
                    $order['loc_id'],
                    $driver_id,
                    $order_id
                ]);
            }
        }

        // 6. Update customer Unpaid_amount if payment was collected
        if ($action !== 'cancel' && $amount_this_trip > 0) {
            $stmt = $pdo->prepare("
                UPDATE user
                SET Unpaid_amount = GREATEST(0, Unpaid_amount - ?)
                WHERE User_ID = ?
            ");
            $stmt->execute([$amount_this_trip, $order['User_ID']]);
        }

        $pdo->commit();
        $success = $action === 'cancel' ? 'ยกเลิกออเดอร์แล้ว' : 'บันทึกข้อมูลเรียบร้อยแล้ว';

        // Refresh order + transaction data after save
        $stmt = $pdo->prepare("
            SELECT o.*, u.User_name, u.tel, l.loc_name, l.details, l.bottle_on_hand,
                   l.latitude, l.longitude, l.loc_id
            FROM `order` o
            JOIN user u     ON o.User_ID = u.User_ID
            JOIN location l ON o.loc_id  = l.loc_id
            WHERE o.order_id = ? AND o.DID = ?
        ");
        $stmt->execute([$order_id, $driver_id]);
        $order = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM `transaction` WHERE order_id = ? ORDER BY confirmed_at DESC");
        $stmt->execute([$order_id]);
        $transactions = $stmt->fetchAll();

        $total_delivered_so_far = array_sum(array_column($transactions, 'qty_delivered'));
        $total_collected_so_far = array_sum(array_column($transactions, 'amount_collected'));
        $total_empties_so_far   = array_sum(array_column($transactions, 'qty_empty_collected'));
        $remaining_qty          = $order['qty_ordered'] - $total_delivered_so_far;
        $remaining_payment      = $order['total_expected_price'] - $total_collected_so_far;

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

include '../includes/header_driver.php';
?>

<div class="container" style="padding-bottom: 40px;">

    <!-- Back -->
    <a href="index.php" style="display:inline-block; margin-bottom:16px; color:#7f8c8d; font-size:0.9rem;">
        ← กลับ
    </a>

    <!-- Alerts -->
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

    <!-- Order Header Card -->
    <div class="job-card" style="margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
                <span style="font-size:0.7rem; color:#7f8c8d;">
                    <?php echo htmlspecialchars($order['order_id']); ?>
                </span>
                <h2 style="margin:4px 0 2px 0; font-size:1.2rem;">
                    <?php echo htmlspecialchars($order['loc_name']); ?>
                </h2>
                <p style="margin:0; font-size:0.85rem; color:#7f8c8d;">
                    <?php echo htmlspecialchars($order['details'] ?? ''); ?>
                </p>
            </div>
            <!-- Overall status badge -->
            <?php
            $sc = match($order['order_status']) {
                'completed'  => ['#d5f5e3','#1e8449','เสร็จสิ้น'],
                'processing' => ['#d6eaf8','#1a5276','กำลังดำเนินการ'],
                'cancelled'  => ['#fadbd8','#c0392b','ยกเลิก'],
                default      => ['#fef9e7','#7d6608','รอดำเนินการ'],
            };
            ?>
            <span style="background:<?php echo $sc[0]; ?>; color:<?php echo $sc[1]; ?>;
                         padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:bold;">
                <?php echo $sc[2]; ?>
            </span>
        </div>

        <!-- Progress bars -->
        <div style="margin-top:16px;">
            <!-- Delivery progress -->
            <div style="margin-bottom:10px;">
                <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:4px;">
                    <span>ส่งแล้ว <?php echo $total_delivered_so_far; ?> / <?php echo $order['qty_ordered']; ?> ขวด</span>
                    <span style="color:#<?php echo $remaining_qty <= 0 ? '27ae60' : 'e67e22'; ?>;">
                        <?php echo $remaining_qty <= 0 ? 'ครบแล้ว' : 'คงเหลือ ' . $remaining_qty . ' ขวด'; ?>
                    </span>
                </div>
                <?php $pct = $order['qty_ordered'] > 0 ? min(100, round($total_delivered_so_far / $order['qty_ordered'] * 100)) : 0; ?>
                <div style="background:#eee; border-radius:4px; height:8px;">
                    <div style="background:#27ae60; width:<?php echo $pct; ?>%; height:8px; border-radius:4px; transition:width 0.3s;"></div>
                </div>
            </div>
            <!-- Payment progress -->
            <div>
                <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:4px;">
                    <span>เก็บเงินแล้ว <?php echo number_format($total_collected_so_far, 2); ?> / <?php echo number_format($order['total_expected_price'], 2); ?> บาท</span>
                    <span style="color:#<?php echo $remaining_payment <= 0 ? '27ae60' : 'e74c3c'; ?>;">
                        <?php echo $remaining_payment <= 0 ? 'ชำระครบ' : 'ค้าง ' . number_format($remaining_payment, 2) . ' บาท'; ?>
                    </span>
                </div>
                <?php $ppct = $order['total_expected_price'] > 0 ? min(100, round($total_collected_so_far / $order['total_expected_price'] * 100)) : 0; ?>
                <div style="background:#eee; border-radius:4px; height:8px;">
                    <div style="background:#3498db; width:<?php echo $ppct; ?>%; height:8px; border-radius:4px; transition:width 0.3s;"></div>
                </div>
            </div>
        </div>

        <!-- Customer info + map -->
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

    <!-- ── UPDATE FORM (hidden if cancelled) ───────────────────────────── -->
    <?php if ($order['order_status'] !== 'cancelled'): ?>
    <div class="job-card" style="margin-bottom:16px;">
        <h3 style="margin:0 0 16px 0; font-size:1rem;">
            <i class="fas fa-edit"></i>
            <?php echo $order['confirmed_by_driver'] ? 'อัปเดตข้อมูลการส่ง' : 'บันทึกการส่งครั้งนี้'; ?>
        </h3>

        <form method="POST">
            <input type="hidden" name="action" value="submit">

            <!-- Stock type selector -->
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-boxes"></i> ประเภทขวดที่นำไปส่ง:
                </label>
                <select name="stock_id" required
                        style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:0.95rem;">
                    <option value="">— เลือกประเภทขวด —</option>
                    <?php foreach($stocks as $s): ?>
                    <option value="<?php echo $s['stock_id']; ?>">
                        <?php echo htmlspecialchars($s['bottle_type']); ?>
                        (คงเหลือ: <?php echo $s['total_qty']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Qty delivered this trip -->
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-box"></i> จำนวนขวดที่ส่งครั้งนี้:
                    <span style="color:#e74c3c; font-size:0.8rem;">
                        (คงเหลือ <?php echo max(0, $remaining_qty); ?> ขวด)
                    </span>
                </label>
                <input type="number" name="qty_delivered" min="0"
                       max="<?php echo max(0, $remaining_qty); ?>"
                       value="<?php echo max(0, $remaining_qty); ?>"
                       style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:0.95rem; box-sizing:border-box;"
                       required>
            </div>

            <!-- Empty bottles collected -->
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-recycle"></i> ขวดเปล่าที่รับคืน:
                </label>
                <input type="number" name="qty_empty_collected" min="0"
                       value="<?php echo $total_empties_so_far > 0 ? 0 : $order['bottle_on_hand']; ?>"
                       style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:0.95rem; box-sizing:border-box;">
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
                       style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:0.95rem; box-sizing:border-box;">
            </div>

            <!-- Driver note -->
            <div style="margin-bottom:18px;">
                <label style="display:block; font-size:0.85rem; font-weight:bold; margin-bottom:6px; color:#2c3e50;">
                    <i class="fas fa-sticky-note"></i> หมายเหตุ:
                </label>
                <textarea name="driver_note" rows="2"
                          style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:0.95rem; box-sizing:border-box; resize:vertical;"
                          placeholder="ไม่มีใครอยู่บ้าน / รับบางส่วน / อื่นๆ"><?php echo htmlspecialchars($order['driver_note'] ?? ''); ?></textarea>
            </div>

            <!-- Buttons -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <button type="submit" name="action" value="submit" class="btn-action"
                        style="background: linear-gradient(135deg,#27ae60,#1e8449); color:#fff; border:none; cursor:pointer;">
                    <i class="fas fa-save"></i> บันทึก
                </button>
                <button type="submit" name="action" value="cancel"
                        class="btn-action"
                        style="background:#fadbd8; color:#c0392b; border:none; cursor:pointer;"
                        onclick="return confirm('ยืนยันการยกเลิกออเดอร์นี้?')">
                    <i class="fas fa-times"></i> ยกเลิกออเดอร์
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── TRANSACTION HISTORY ──────────────────────────────────────────── -->
    <?php if (!empty($transactions)): ?>
    <div class="job-card">
        <h3 style="margin:0 0 14px 0; font-size:1rem;">
            <i class="fas fa-history"></i> ประวัติการส่งออเดอร์นี้
        </h3>
        <?php foreach($transactions as $i => $t): ?>
        <div style="border-left: 3px solid #3498db; padding:10px 14px; margin-bottom:10px;
                    background:#f8f9fa; border-radius:0 8px 8px 0;">
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                <span style="font-size:0.75rem; color:#7f8c8d;">
                    ครั้งที่ <?php echo count($transactions) - $i; ?>
                    — <?php echo $t['confirmed_at']; ?>
                </span>
            </div>
            <div style="font-size:0.85rem; display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px;">
                <div>
                    <span style="color:#7f8c8d;">ส่ง</span><br>
                    <strong><?php echo $t['qty_delivered']; ?> ขวด</strong>
                </div>
                <div>
                    <span style="color:#7f8c8d;">รับเปล่าคืน</span><br>
                    <strong><?php echo $t['qty_empty_collected']; ?> ขวด</strong>
                </div>
                <div>
                    <span style="color:#7f8c8d;">เก็บเงิน</span><br>
                    <strong><?php echo number_format($t['amount_collected'], 2); ?> บาท</strong>
                </div>
            </div>
            <?php if ($t['note']): ?>
            <p style="margin:6px 0 0 0; font-size:0.8rem; color:#7f8c8d; font-style:italic;">
                หมายเหตุ: <?php echo htmlspecialchars($t['note']); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <!-- Running totals -->
        <div style="background:#eaf4fb; border-radius:8px; padding:10px 14px; font-size:0.85rem;">
            <strong>ยอดรวมทั้งหมด:</strong>
            ส่ง <?php echo $total_delivered_so_far; ?> ขวด |
            รับเปล่า <?php echo $total_empties_so_far; ?> ขวด |
            เก็บเงิน <?php echo number_format($total_collected_so_far, 2); ?> บาท
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>