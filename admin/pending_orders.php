<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';

$success = '';
$error   = '';

// Handle approve/reject via POST
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
            $did          = $_POST['did'];
            $deposit_fee  = (float)$_POST['deposit_fee'];
            $total_price  = (float)$_POST['total_price'];
            $manager_id   = $_SESSION['ref_id'];

            // Generate new order_id
            $stmt = $pdo->query("SELECT order_id FROM `order` ORDER BY order_id DESC LIMIT 1");
            $last = $stmt->fetch();
            $new_order_id = $last
                ? 'ORD' . str_pad((int)preg_replace('/[^0-9]/', '', $last['order_id']) + 1, 3, '0', STR_PAD_LEFT)
                : 'ORD001';

            // Insert into order table
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

            // Mark request as approved and link the order
            $pdo->prepare("
                UPDATE order_request
                SET status = 'approved', order_id = ?
                WHERE request_id = ?
            ")->execute([$new_order_id, $request_id]);

            $success = "อนุมัติคำขอ {$request_id} แล้ว — สร้างออเดอร์ {$new_order_id} เรียบร้อย";

        } elseif ($action === 'reject') {
            $pdo->prepare("
                UPDATE order_request SET status = 'rejected'
                WHERE request_id = ?
            ")->execute([$request_id]);

            $success = "ปฏิเสธคำขอ {$request_id} แล้ว";
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Fetch pending requests
$stmt = $pdo->query("
    SELECT r.*, u.User_name, u.tel, l.loc_name, l.details, v.village_name
    FROM order_request r
    JOIN user u     ON r.User_ID = u.User_ID
    JOIN location l ON r.loc_id  = l.loc_id
    LEFT JOIN village v ON l.village_id = v.village_id
    WHERE r.status = 'pending_admin'
    ORDER BY r.requested_date ASC
");
$requests = $stmt->fetchAll();

// Active drivers
$drivers = $pdo->query("
    SELECT DID, DFname, DLname, tel FROM driver WHERE is_active = 1
")->fetchAll();

require_once '../includes/header.php';
?>

<div class="dashboard">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0;"><i class="fas fa-clock"></i> คำขอสั่งน้ำ</h2>
            <p style="color:#7f8c8d; margin:4px 0 0;">รอการยืนยันจาก Admin — <?php echo count($requests); ?> รายการ</p>
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

    <!-- ── Desktop table (hidden on small screens) ── -->
    <div class="table-container" style="display:block;">
        <table id="requestsTable">
            <thead>
                <tr>
                    <th>วันที่ต้องการ</th>
                    <th>ลูกค้า</th>
                    <th>สถานที่</th>
                    <th>จำนวน</th>
                    <th>มอบหมายคนขับ</th>
                    <th>ค่ามัดจำ (บาท)</th>
                    <th>ราคารวม (บาท)</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <form action="pending_requests.php" method="POST">
                        <!-- Hidden fields -->
                        <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                        <input type="hidden" name="user_id"    value="<?php echo $req['User_ID']; ?>">
                        <input type="hidden" name="loc_id"     value="<?php echo $req['loc_id']; ?>">
                        <input type="hidden" name="qty"        value="<?php echo $req['qty']; ?>">
                        <input type="hidden" name="req_date"   value="<?php echo $req['requested_date']; ?>">

                        <td>
                            <?php echo date('d/m/Y', strtotime($req['requested_date'])); ?>
                            <?php
                            $days = (int)((strtotime($req['requested_date']) - time()) / 86400);
                            if ($days < 0):
                            ?>
                            <br><span style="color:#e74c3c; font-size:0.7rem; font-weight:bold;">
                                เลยกำหนด <?php echo abs($days); ?> วัน
                            </span>
                            <?php elseif ($days === 0): ?>
                            <br><span style="color:#f39c12; font-size:0.7rem; font-weight:bold;">วันนี้</span>
                            <?php elseif ($days <= 2): ?>
                            <br><span style="color:#e67e22; font-size:0.7rem;">อีก <?php echo $days; ?> วัน</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong><?php echo htmlspecialchars($req['User_name']); ?></strong><br>
                            <small style="color:#7f8c8d;">
                                <i class="fas fa-phone" style="font-size:0.7rem;"></i>
                                <?php echo htmlspecialchars($req['tel'] ?? '-'); ?>
                            </small>
                        </td>

                        <td>
                            <strong><?php echo htmlspecialchars($req['loc_name']); ?></strong><br>
                            <small style="color:#7f8c8d;">
                                <?php echo htmlspecialchars($req['village_name'] ?? ''); ?>
                                — <?php echo htmlspecialchars($req['details'] ?? ''); ?>
                            </small>
                        </td>

                        <td style="text-align:center; font-weight:bold; font-size:1.05rem;">
                            <?php echo $req['qty']; ?>
                            <small style="color:#7f8c8d; font-weight:normal; display:block;">ถัง</small>
                        </td>

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

                        <td>
                            <input type="number" name="deposit_fee"
                                   step="0.01" min="0" value="0.00"
                                   style="width:90px; padding:7px; border:1px solid #ddd;
                                          border-radius:6px; font-size:0.85rem; text-align:right;"
                                   placeholder="0.00">
                        </td>

                        <td>
                            <input type="number" name="total_price"
                                   step="0.01" min="0" value=""
                                   required
                                   style="width:100px; padding:7px; border:1px solid #ddd;
                                          border-radius:6px; font-size:0.85rem; text-align:right;"
                                   placeholder="ระบุราคา">
                        </td>

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
// Validate driver + price are filled before approving
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