<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';

$success = '';
$error   = '';

// ── Helper: extract [Product Name] prefix from note ───────────────────────────
function extractBottleType(string $note): array {
    if (preg_match('/^\[([^\]]+)\]/', $note, $m)) {
        return [
            'type' => trim($m[1]),
            'note' => trim(substr($note, strlen($m[0]))),
        ];
    }
    return ['type' => '', 'note' => $note];
}

// ── Handle approve / reject ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'];
    $request_id = $_POST['request_id'];
    $user_id    = $_POST['user_id'];
    $loc_id     = $_POST['loc_id'];
    $qty        = (int)$_POST['qty'];
    $req_date   = $_POST['req_date'];

    try {
        $pdo->beginTransaction();

        if ($action === 'approve') {
            $did         = $_POST['did'];
            $deposit_fee = (float)$_POST['deposit_fee'];
            $total_price = (float)$_POST['total_price'];
            $manager_id  = $_SESSION['ref_id'];

            // Generate next order_id
            $stmt = $pdo->query("
                SELECT order_id FROM `order`
                ORDER BY CAST(SUBSTRING(order_id, 4) AS UNSIGNED) DESC
                LIMIT 1
            ");
            $last         = $stmt->fetch();
            $new_order_id = $last
                ? 'ORD' . str_pad((int)preg_replace('/[^0-9]/', '', $last['order_id']) + 1, 3, '0', STR_PAD_LEFT)
                : 'ORD001';

            $pdo->prepare("
                INSERT INTO `order`
                    (order_id, order_type, scheduled_date, qty_ordered,
                     deposit_fee, total_expected_price,
                     order_status, payment_status,
                     User_ID, ID, loc_id, DID,
                     confirmed_by_driver, is_system_generated)
                VALUES
                    (?, 'delivery', ?, ?,
                     ?, ?,
                     'pending', 'unpaid',
                     ?, ?, ?, ?,
                     0, 'N')
            ")->execute([
                $new_order_id, $req_date, $qty,
                $deposit_fee, $total_price,
                $user_id, $manager_id, $loc_id, $did
            ]);

            $pdo->prepare("
                UPDATE order_request SET status = 'approved', order_id = ?
                WHERE request_id = ?
            ")->execute([$new_order_id, $request_id]);

            $success = "อนุมัติคำขอ {$request_id} แล้ว — สร้างออเดอร์ {$new_order_id} เรียบร้อย";

        } elseif ($action === 'reject') {
            $pdo->prepare("
                UPDATE order_request SET status = 'rejected' WHERE request_id = ?
            ")->execute([$request_id]);

            $success = "ปฏิเสธคำขอ {$request_id} แล้ว";
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// ── Fetch pending requests ────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT r.*, u.User_name, u.tel, l.loc_name, l.details, v.village_name
    FROM order_request r
    JOIN `user`  u  ON r.User_ID  = u.User_ID
    JOIN location l ON r.loc_id   = l.loc_id
    LEFT JOIN village v ON l.village_id = v.village_id
    WHERE r.status = 'pending_admin'
    ORDER BY r.requested_date ASC
");
$requests = $stmt->fetchAll();

// Active drivers
$drivers = $pdo->query("
    SELECT DID, DFname, DLname, tel FROM driver WHERE is_active = 1
")->fetchAll();

// Products for price suggestion
$products = $pdo->query("
    SELECT product_id, name, price FROM product WHERE product_id != 'P005' ORDER BY price ASC
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
/* Bottle type pill */
.bottle-pill {
    display: inline-flex; align-items: center; gap: 4px;
    background: #eaf6ff; color: #1a7ab5;
    border: 1px solid #b3d9f5;
    border-radius: 20px; padding: 3px 10px;
    font-size: 0.75rem; font-weight: 600; white-space: nowrap;
}
.bottle-pill.unknown { background: #f4f6fa; color: #95a5a6; border-color: #dde1ea; }

/* Note text (after stripping product tag) */
.req-note {
    font-size: 0.75rem; color: #7f8c8d;
    font-style: italic; margin-top: 3px;
}

/* Auto-price button */
.price-hint {
    font-size: 0.72rem; color: #3498db; cursor: pointer;
    text-decoration: underline; white-space: nowrap;
}
</style>

<div class="dashboard">

    <div style="display:flex; justify-content:space-between; align-items:center;
                margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0;"><i class="fas fa-clock"></i> คำขอสั่งน้ำ</h2>
            <p style="color:#7f8c8d; margin:4px 0 0;">
                รอการยืนยันจาก Admin — <?php echo count($requests); ?> รายการ
            </p>
        </div>
        <?php if (!empty($requests)): ?>
        <span style="background:#fef3cd; color:#856404; padding:6px 14px;
                     border-radius:20px; font-size:0.85rem; font-weight:bold;">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo count($requests); ?> รายการรอดำเนินการ
        </span>
        <?php endif; ?>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <?php if (empty($requests)): ?>
    <div class="table-container" style="text-align:center; padding:60px 20px; color:#7f8c8d;">
        <i class="fas fa-check-circle" style="font-size:3rem; color:#27ae60; display:block; margin-bottom:12px;"></i>
        <p style="font-size:1.1rem; font-weight:bold; color:#27ae60;">ไม่มีคำขอรอดำเนินการ</p>
        <p>คำขอทั้งหมดได้รับการจัดการแล้ว</p>
    </div>

    <?php else: ?>

    <div class="table-container" style="display:block;">
        <table id="requestsTable">
            <thead>
                <tr>
                    <th>วันที่ต้องการ</th>
                    <th>ลูกค้า</th>
                    <th>สถานที่</th>
                    <th>จำนวน</th>
                    <th>ประเภทขวด</th>
                    <th>มอบหมายคนขับ</th>
                    <th>ค่ามัดจำ (บาท)</th>
                    <th>ราคารวม (บาท)</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req):
                    $parsed      = extractBottleType($req['note'] ?? '');
                    $bottle_type = $parsed['type'];
                    $clean_note  = $parsed['note'];

                    // Find matching product price for auto-fill hint
                    $hint_price = null;
                    foreach ($products as $p) {
                        if (stripos($p['name'], $bottle_type) !== false || stripos($bottle_type, $p['name']) !== false) {
                            $hint_price = $p['price'];
                            break;
                        }
                    }
                    // Exact match fallback
                    if (!$hint_price && $bottle_type) {
                        foreach ($products as $p) {
                            if (strtolower(trim($p['name'])) === strtolower(trim($bottle_type))) {
                                $hint_price = $p['price'];
                                break;
                            }
                        }
                    }
                    $hint_total = $hint_price ? $req['qty'] * $hint_price : null;
                ?>
                <tr>
                    <form action="pending_orders.php" method="POST">
                        <!-- Hidden fields -->
                        <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                        <input type="hidden" name="user_id"    value="<?php echo $req['User_ID']; ?>">
                        <input type="hidden" name="loc_id"     value="<?php echo $req['loc_id']; ?>">
                        <input type="hidden" name="qty"        value="<?php echo $req['qty']; ?>">
                        <input type="hidden" name="req_date"   value="<?php echo $req['requested_date']; ?>">

                        <!-- Date -->
                        <td>
                            <?php echo date('d/m/Y', strtotime($req['requested_date'])); ?>
                            <?php
                            $days = (int)((strtotime($req['requested_date']) - time()) / 86400);
                            if ($days < 0): ?>
                                <br><span style="color:#e74c3c; font-size:0.7rem; font-weight:bold;">
                                    เลยกำหนด <?php echo abs($days); ?> วัน
                                </span>
                            <?php elseif ($days === 0): ?>
                                <br><span style="color:#f39c12; font-size:0.7rem; font-weight:bold;">วันนี้</span>
                            <?php elseif ($days <= 2): ?>
                                <br><span style="color:#e67e22; font-size:0.7rem;">อีก <?php echo $days; ?> วัน</span>
                            <?php endif; ?>
                        </td>

                        <!-- Customer -->
                        <td>
                            <strong><?php echo htmlspecialchars($req['User_name']); ?></strong><br>
                            <small style="color:#7f8c8d;">
                                <i class="fas fa-phone" style="font-size:0.7rem;"></i>
                                <?php echo htmlspecialchars($req['tel'] ?? '-'); ?>
                            </small>
                        </td>

                        <!-- Location -->
                        <td>
                            <strong><?php echo htmlspecialchars($req['loc_name']); ?></strong><br>
                            <small style="color:#7f8c8d;">
                                <?php echo htmlspecialchars($req['village_name'] ?? ''); ?>
                                <?php if ($req['details']): ?>
                                — <?php echo htmlspecialchars($req['details']); ?>
                                <?php endif; ?>
                            </small>
                        </td>

                        <!-- Qty -->
                        <td style="text-align:center; font-weight:bold; font-size:1.05rem;">
                            <?php echo $req['qty']; ?>
                            <small style="color:#7f8c8d; font-weight:normal; display:block;">ถัง</small>
                        </td>

                        <!-- Bottle type (NEW) -->
                        <td>
                            <?php if ($bottle_type): ?>
                                <span class="bottle-pill">💧 <?php echo htmlspecialchars($bottle_type); ?></span>
                                <?php if ($clean_note): ?>
                                    <div class="req-note">
                                        <i class="fas fa-comment-dots"></i>
                                        <?php echo htmlspecialchars($clean_note); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="bottle-pill unknown">ไม่ระบุ</span>
                                <?php if ($req['note']): ?>
                                    <div class="req-note"><?php echo htmlspecialchars($req['note']); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <!-- Driver select -->
                        <td>
                            <select name="did"
                                    style="width:100%; padding:7px; border:1px solid #ddd;
                                           border-radius:6px; font-size:0.85rem;">
                                <option value="">— เลือกคนขับ —</option>
                                <?php foreach ($drivers as $d): ?>
                                <option value="<?php echo $d['DID']; ?>">
                                    <?php echo htmlspecialchars($d['DFname'] . ' ' . $d['DLname']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Deposit -->
                        <td>
                            <input type="number" name="deposit_fee"
                                   step="0.01" min="0" value="0.00"
                                   style="width:90px; padding:7px; border:1px solid #ddd;
                                          border-radius:6px; font-size:0.85rem; text-align:right;">
                        </td>

                        <!-- Total price with auto-fill hint -->
                        <td>
                            <input type="number" name="total_price"
                                   id="price_<?php echo $req['request_id']; ?>"
                                   step="0.01" min="0" value="" required
                                   style="width:100px; padding:7px; border:1px solid #ddd;
                                          border-radius:6px; font-size:0.85rem; text-align:right;"
                                   placeholder="ระบุราคา">
                            <?php if ($hint_total): ?>
                            <div style="margin-top:4px;">
                                <span class="price-hint"
                                      onclick="document.getElementById('price_<?php echo $req['request_id']; ?>').value='<?php echo number_format($hint_total, 2, '.', ''); ?>'">
                                    <i class="fas fa-magic"></i>
                                    แนะนำ ฿<?php echo number_format($hint_total, 2); ?>
                                    (<?php echo $req['qty']; ?>×฿<?php echo number_format($hint_price, 2); ?>)
                                </span>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <button type="submit" name="action" value="approve"
                                        class="btn btn-success btn-sm"
                                        onclick="return validateApprove(this)">
                                    <i class="fas fa-check"></i> อนุมัติ
                                </button>
                                <button type="submit" name="action" value="reject"
                                        class="btn btn-danger btn-sm"
                                        onclick="return confirm('ยืนยันการปฏิเสธคำขอนี้?')">
                                    <i class="fas fa-times"></i> ปฏิเสธ
                                </button>
                            </div>
                        </td>

                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<script>
function validateApprove(btn) {
    const row   = btn.closest('tr');
    const did   = row.querySelector('select[name="did"]').value;
    const price = row.querySelector('input[name="total_price"]').value;
    if (!did) {
        alert('กรุณาเลือกคนขับก่อนอนุมัติ');
        return false;
    }
    if (!price || parseFloat(price) <= 0) {
        alert('กรุณาระบุราคารวมก่อนอนุมัติ');
        return false;
    }
    return confirm('ยืนยันการอนุมัติคำขอนี้?');
}
</script>

<?php require_once '../includes/footer.php'; ?>