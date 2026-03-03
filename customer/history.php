<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

$user_id = $_SESSION['ref_id'];

$stmt = $pdo->prepare("
    (SELECT
        requested_date  AS display_date,
        'request'       AS record_type,
        qty,
        status          AS current_status,
        loc_name,
        request_id      AS ref_no,
        note
    FROM order_request
    JOIN location ON order_request.loc_id = location.loc_id
    WHERE order_request.User_ID = ?)

    UNION ALL

    (SELECT
        scheduled_date  AS display_date,
        'order'         AS record_type,
        qty_ordered     AS qty,
        order_status    AS current_status,
        loc_name,
        order_id        AS ref_no,
        driver_note     AS note
    FROM `order`
    JOIN location ON `order`.loc_id = location.loc_id
    WHERE `order`.User_ID = ?)

    ORDER BY display_date DESC
");
$stmt->execute([$user_id, $user_id]);
$history = $stmt->fetchAll();

require_once '../includes/header_customer.php';
?>

<div class="page">

    <div class="sec-header" style="margin-bottom:16px;">
        <h3 style="font-size:1.05rem;">
            <i class="fas fa-history" style="color:var(--foam);"></i> ประวัติทั้งหมด
        </h3>
        <span style="font-size:0.8rem; color:var(--muted);"><?php echo count($history); ?> รายการ</span>
    </div>

    <?php if (empty($history)): ?>
    <div class="card">
        <div class="empty">
            <i class="fas fa-box-open"></i>
            <p>ยังไม่มีประวัติการสั่งซื้อ</p>
        </div>
    </div>
    <?php else: ?>

    <!-- Group by month -->
    <?php
    $grouped = [];
    foreach ($history as $row) {
        $month = date('F Y', strtotime($row['display_date']));
        $grouped[$month][] = $row;
    }
    ?>

    <?php foreach($grouped as $month => $rows): ?>
    <div style="font-size:0.75rem; font-weight:700; color:var(--muted);
                text-transform:uppercase; letter-spacing:0.08em;
                margin: 16px 0 8px; padding-left:4px;">
        <?php echo $month; ?>
    </div>

    <div class="card" style="padding:0 20px;">
        <?php foreach($rows as $row):
            $is_request = $row['record_type'] === 'request';
            $status_map = [
                'pending_admin' => ['รอ Admin', 'badge-pending_admin'],
                'approved'      => ['อนุมัติ',  'badge-approved'],
                'rejected'      => ['ปฏิเสธ',   'badge-rejected'],
                'completed'     => ['สำเร็จ',   'badge-completed'],
                'pending'       => ['รอดำเนินการ','badge-pending'],
                'processing'    => ['กำลังส่ง', 'badge-processing'],
                'cancelled'     => ['ยกเลิก',   'badge-cancelled'],
            ];
            [$label, $badge_class] = $status_map[$row['current_status']] ?? [$row['current_status'], 'badge-pending'];
        ?>
        <div class="history-row">
            <!-- Left icon -->
            <div style="width:36px; height:36px; border-radius:10px; flex-shrink:0;
                        display:flex; align-items:center; justify-content:center;
                        background:<?php echo $is_request ? '#FFF3CD' : '#D4EDDA'; ?>;">
                <i class="fas fa-<?php echo $is_request ? 'clock' : 'truck'; ?>"
                   style="font-size:0.9rem; color:<?php echo $is_request ? '#856404' : '#155724'; ?>;"></i>
            </div>
            <!-- Info -->
            <div class="history-info">
                <h4><?php echo htmlspecialchars($row['loc_name']); ?></h4>
                <p>
                    <?php echo date('d/m/Y', strtotime($row['display_date'])); ?>
                    · <?php echo $row['qty']; ?> ถัง
                    · <span style="color:var(--ocean); font-size:0.7rem;">
                        <?php echo $is_request ? 'ใบคำขอ' : 'รายการส่ง'; ?>
                      </span>
                </p>
                <?php if ($row['note']): ?>
                <p style="color:var(--muted); font-style:italic; font-size:0.7rem; margin-top:2px;">
                    "<?php echo htmlspecialchars($row['note']); ?>"
                </p>
                <?php endif; ?>
            </div>
            <!-- Status -->
            <span class="badge <?php echo $badge_class; ?>" style="flex-shrink:0;"><?php echo $label; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>