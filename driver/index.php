<?php
require_once '../includes/auth.php';
require_login('driver');
require_once '../config/database.php';

$driver_id = $_SESSION['ref_id'];

// All active + unpaid/partial jobs
$stmt = $pdo->prepare("
    SELECT o.*, u.User_name, u.tel, l.loc_name, l.details, l.latitude, l.longitude
    FROM `order` o
    JOIN user u     ON o.User_ID = u.User_ID
    JOIN location l ON o.loc_id  = l.loc_id
    WHERE o.DID = ?
    AND (
        o.order_status NOT IN ('completed','cancelled')
        OR o.payment_status IN ('unpaid','partial')
    )
    ORDER BY o.scheduled_date ASC
");
$stmt->execute([$driver_id]);
$jobs = $stmt->fetchAll();

// Quick stats
$total_jobs    = count($jobs);
$pending_pay   = array_filter($jobs, fn($j) => in_array($j['payment_status'], ['unpaid','partial']));
$today_jobs    = array_filter($jobs, fn($j) => $j['scheduled_date'] === date('Y-m-d'));

include '../includes/header_driver.php';
?>

<div class="container">

    <!-- Driver stats strip -->
    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:18px;">
        <div style="background:#fff; border-radius:10px; padding:12px 10px; text-align:center; box-shadow:var(--card-shadow);">
            <div style="font-size:1.5rem; font-weight:bold; color:var(--blue);"><?php echo $total_jobs; ?></div>
            <div style="font-size:0.7rem; color:var(--grey); margin-top:2px;">งานทั้งหมด</div>
        </div>
        <div style="background:#fff; border-radius:10px; padding:12px 10px; text-align:center; box-shadow:var(--card-shadow);">
            <div style="font-size:1.5rem; font-weight:bold; color:var(--amber);"><?php echo count($today_jobs); ?></div>
            <div style="font-size:0.7rem; color:var(--grey); margin-top:2px;">วันนี้</div>
        </div>
        <div style="background:#fff; border-radius:10px; padding:12px 10px; text-align:center; box-shadow:var(--card-shadow);">
            <div style="font-size:1.5rem; font-weight:bold; color:var(--red);"><?php echo count($pending_pay); ?></div>
            <div style="font-size:0.7rem; color:var(--grey); margin-top:2px;">ค้างชำระ</div>
        </div>
    </div>

    <!-- Section header -->
    <div class="section-header">
        <h2><i class="fas fa-list-check" style="color:var(--blue); margin-right:6px;"></i>งานที่ต้องดำเนินการ</h2>
        <span style="font-size:0.8rem; color:var(--grey);"><?php echo date('d/m/Y'); ?></span>
    </div>

    <?php if (empty($jobs)): ?>
        <div class="empty-state">
            <i class="fas fa-check-circle" style="color:var(--green);"></i>
            <p style="font-size:1rem; font-weight:bold; color:var(--green); margin-bottom:4px;">เสร็จงานทั้งหมดแล้ว!</p>
            <p>ไม่มีงานค้างในขณะนี้</p>
        </div>

    <?php else: ?>
        <?php foreach ($jobs as $job):
            $is_today    = $job['scheduled_date'] === date('Y-m-d');
            $is_past     = $job['scheduled_date'] < date('Y-m-d');
            $delivered   = $job['actual_qty_ordered'] ?? 0;
            $qty         = $job['qty_ordered'];
            $collected   = $job['actual_amount_collected'] ?? 0;
            $total_price = $job['total_expected_price'];
            $del_pct     = $qty > 0 ? min(100, round($delivered / $qty * 100)) : 0;
            $pay_pct     = $total_price > 0 ? min(100, round($collected / $total_price * 100)) : 0;
            $remaining   = $qty - $delivered;
            $remaining_pay = $total_price - $collected;

            // Card accent colour
            $border_color = match(true) {
                $job['payment_status'] === 'partial'                     => '#f39c12',
                $job['order_status']   === 'processing'                  => '#2471a3',
                $is_past && $job['order_status'] !== 'completed'         => '#e74c3c',
                default                                                  => '#27ae60',
            };
        ?>

        <div class="job-card" style="border-left-color: <?php echo $border_color; ?>;">

            <!-- Card top row -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <!-- Date chip -->
                        <span style="font-size:0.68rem; padding:2px 7px; border-radius:20px;
                            background:<?php echo $is_today ? '#d6eaf8' : ($is_past ? '#fadbd8' : '#f0f0f0'); ?>;
                            color:<?php echo $is_today ? '#1a5276' : ($is_past ? '#c0392b' : '#666'); ?>;
                            font-weight:bold; white-space:nowrap;">
                            <i class="fas fa-calendar-day"></i>
                            <?php echo $is_today ? 'วันนี้' : ($is_past ? 'เลยกำหนด' : date('d/m', strtotime($job['scheduled_date']))); ?>
                        </span>
                        <!-- Payment badge -->
                        <span class="badge badge-<?php echo $job['payment_status']; ?>">
                            <?php echo match($job['payment_status']) {
                                'paid'    => '✓ ชำระแล้ว',
                                'partial' => '⚠ ค้างบางส่วน',
                                default   => '✗ ยังไม่ชำระ',
                            }; ?>
                        </span>
                    </div>
                    <h3 style="margin:6px 0 2px 0; font-size:1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?php echo htmlspecialchars($job['loc_name']); ?>
                    </h3>
                    <p style="margin:0; font-size:0.78rem; color:var(--grey);">
                        <?php echo htmlspecialchars($job['details'] ?? ''); ?>
                    </p>
                </div>
                <!-- Order status badge -->
                <span class="badge badge-<?php echo $job['order_status']; ?>" style="margin-left:8px; white-space:nowrap;">
                    <?php echo match($job['order_status']) {
                        'pending'    => 'รอ',
                        'processing' => 'กำลังส่ง',
                        'completed'  => 'เสร็จ',
                        default      => $job['order_status'],
                    }; ?>
                </span>
            </div>

            <!-- Progress: delivery -->
            <div class="progress-wrap">
                <div class="progress-label">
                    <span><i class="fas fa-box" style="color:var(--blue);"></i> ส่งแล้ว <?php echo $delivered; ?>/<?php echo $qty; ?> ขวด</span>
                    <span style="color:<?php echo $remaining <= 0 ? 'var(--green)' : 'var(--amber)'; ?>;">
                        <?php echo $remaining <= 0 ? 'ครบแล้ว' : "คงเหลือ $remaining"; ?>
                    </span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?php echo $del_pct; ?>%; background:var(--blue);"></div>
                </div>
            </div>

            <!-- Progress: payment -->
            <div class="progress-wrap" style="margin-bottom:12px;">
                <div class="progress-label">
                    <span><i class="fas fa-money-bill-wave" style="color:var(--green);"></i> เก็บเงิน <?php echo number_format($collected, 0); ?>/<?php echo number_format($total_price, 0); ?> บาท</span>
                    <span style="color:<?php echo $remaining_pay <= 0 ? 'var(--green)' : 'var(--red)'; ?>;">
                        <?php echo $remaining_pay <= 0 ? 'ชำระครบ' : 'ค้าง ' . number_format($remaining_pay, 0) . ' บาท'; ?>
                    </span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?php echo $pay_pct; ?>%; background:var(--green);"></div>
                </div>
            </div>

            <!-- Customer info row -->
            <div style="display:flex; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
                <a href="tel:<?php echo htmlspecialchars($job['tel']); ?>"
                   style="display:flex; align-items:center; gap:5px; font-size:0.82rem;
                          color:var(--blue); text-decoration:none; background:#eaf4fb;
                          padding:5px 10px; border-radius:20px;">
                    <i class="fas fa-phone"></i>
                    <?php echo htmlspecialchars($job['tel']); ?>
                </a>
                <span style="display:flex; align-items:center; gap:5px; font-size:0.82rem;
                             color:var(--grey); background:#f4f4f4; padding:5px 10px; border-radius:20px;">
                    <i class="fas fa-user"></i>
                    <?php echo htmlspecialchars($job['User_name']); ?>
                </span>
            </div>

            <!-- Action buttons -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <?php if ($job['latitude'] && $job['longitude']): ?>
                <a href="https://www.google.com/maps?q=<?php echo $job['latitude']; ?>,<?php echo $job['longitude']; ?>"
                   target="_blank" class="btn-action"
                   style="background:#ecf0f1; color:#2c3e50;">
                    <i class="fas fa-map-marker-alt"></i> นำทาง
                </a>
                <?php else: ?>
                <div class="btn-action" style="background:#ecf0f1; color:#aaa; cursor:not-allowed;">
                    <i class="fas fa-map-marker-alt"></i> ไม่มีพิกัด
                </div>
                <?php endif; ?>

                <a href="update_order.php?id=<?php echo urlencode($job['order_id']); ?>"
                   class="btn-action"
                   style="background: <?php echo $job['confirmed_by_driver'] ? 'linear-gradient(135deg,#2471a3,#1a5276)' : 'linear-gradient(135deg,#27ae60,#1e8449)'; ?>;">
                    <i class="fas fa-<?php echo $job['confirmed_by_driver'] ? 'edit' : 'play'; ?>"></i>
                    <?php echo $job['confirmed_by_driver'] ? 'อัปเดต' : 'เริ่มงาน'; ?>
                </a>
            </div>

        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>