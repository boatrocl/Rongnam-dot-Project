<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';
require_once '../includes/header.php';

// ===== Date range: optional filter (NULL = show all) =====
$date_from = $_GET['date_from'] ?? null;
$date_to   = $_GET['date_to']   ?? null;
$is_filtered = ($date_from !== null && $date_to !== null && $date_from !== '' && $date_to !== '');

// Helper to build WHERE clause for date ranges
function dateWhere(string $col, bool $filtered): string {
    return $filtered ? "AND DATE($col) BETWEEN ? AND ?" : "";
}
function dateParams(bool $filtered, ?string $from, ?string $to): array {
    return $filtered ? [$from, $to] : [];
}

// ===== Report 1: Revenue by date =====
$sql = "
    SELECT
        DATE(scheduled_date) AS order_date,
        COUNT(*)             AS order_count,
        SUM(qty_ordered)     AS total_qty,
        SUM(total_expected_price) AS revenue
    FROM `order`
    WHERE order_status = 'completed'
    " . ($is_filtered ? "AND DATE(scheduled_date) BETWEEN ? AND ?" : "") . "
    GROUP BY DATE(scheduled_date)
    ORDER BY order_date ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($is_filtered ? [$date_from, $date_to] : []);
$revenue_data = $stmt->fetchAll();
$total_revenue    = array_sum(array_column($revenue_data, 'revenue'));
$total_rev_orders = array_sum(array_column($revenue_data, 'order_count'));

// ===== Report 2: Orders per driver =====
$sql = "
    SELECT
        d.DID, d.DFname, d.DLname,
        COUNT(o.order_id) AS total_orders,
        SUM(CASE WHEN o.order_status = 'completed'  THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN o.order_status = 'pending'    THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN o.order_status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN o.order_status = 'completed'  THEN o.total_expected_price ELSE 0 END) AS revenue
    FROM driver d
    LEFT JOIN `order` o ON d.DID = o.DID
        " . ($is_filtered ? "AND DATE(o.scheduled_date) BETWEEN ? AND ?" : "") . "
    GROUP BY d.DID, d.DFname, d.DLname
    ORDER BY total_orders DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($is_filtered ? [$date_from, $date_to] : []);
$driver_data = $stmt->fetchAll();

// ===== Report 3: Unpaid balance by customer =====
$stmt = $pdo->query("
    SELECT
        u.User_ID, u.User_name, u.tel,
        u.Unpaid_amount,
        COUNT(o.order_id) AS total_orders,
        SUM(CASE WHEN o.payment_status = 'unpaid' THEN o.total_expected_price ELSE 0 END) AS unpaid_orders_total
    FROM `user` u
    LEFT JOIN `order` o ON u.User_ID = o.User_ID
    GROUP BY u.User_ID, u.User_name, u.tel, u.Unpaid_amount
    HAVING u.Unpaid_amount > 0
    ORDER BY u.Unpaid_amount DESC
");
$unpaid_data      = $stmt->fetchAll();
$total_unpaid_sum = array_sum(array_column($unpaid_data, 'Unpaid_amount'));

// ===== Report 4: Stock movement summary =====
$sql = "
    SELECT
        action_type,
        bottle_type,
        COUNT(*)       AS log_count,
        SUM(total_qty) AS total_moved
    FROM stock_log
    " . ($is_filtered ? "WHERE DATE(timestamp) BETWEEN ? AND ?" : "") . "
    GROUP BY action_type, bottle_type
    ORDER BY action_type, bottle_type
";
$stmt = $pdo->prepare($sql);
$stmt->execute($is_filtered ? [$date_from, $date_to] : []);
$stock_data = $stmt->fetchAll();

// ===== Report 5: Orders by status breakdown =====
$sql = "
    SELECT
        order_status,
        payment_status,
        COUNT(*)                  AS count,
        SUM(total_expected_price) AS total_value
    FROM `order`
    " . ($is_filtered ? "WHERE DATE(scheduled_date) BETWEEN ? AND ?" : "") . "
    GROUP BY order_status, payment_status
    ORDER BY order_status, payment_status
";
$stmt = $pdo->prepare($sql);
$stmt->execute($is_filtered ? [$date_from, $date_to] : []);
$status_data = $stmt->fetchAll();

$status_grouped = [];
foreach ($status_data as $row) {
    $status_grouped[$row['order_status']][] = $row;
}
$total_status_orders = array_sum(array_column($status_data, 'count'));

// ===== Chart data (JSON) =====
// Bar chart: revenue by date (last 30 points max for readability)
$chart_revenue_labels = json_encode(array_column($revenue_data, 'order_date'));
$chart_revenue_values = json_encode(array_map('floatval', array_column($revenue_data, 'revenue')));

// Doughnut: order status totals
$status_totals = [];
foreach ($status_data as $row) {
    $s = $row['order_status'];
    $status_totals[$s] = ($status_totals[$s] ?? 0) + (int)$row['count'];
}
$chart_status_labels = json_encode(array_keys($status_totals));
$chart_status_values = json_encode(array_values($status_totals));

// Line chart: driver completed vs cancelled
$chart_driver_names     = json_encode(array_map(fn($d) => $d['DFname'].' '.$d['DLname'], $driver_data));
$chart_driver_completed = json_encode(array_map(fn($d) => (int)$d['completed'], $driver_data));
$chart_driver_cancelled = json_encode(array_map(fn($d) => (int)$d['cancelled'], $driver_data));
?>

<div class="dashboard">
    <h2>รายงาน (Reports)</h2>

    <!-- Date Range Filter (secondary / optional) -->
    <details id="dateFilterDetails" <?php echo $is_filtered ? 'open' : ''; ?> style="margin-bottom:24px;">
        <summary style="cursor:pointer; background:#f0f4f8; border-radius:10px; padding:14px 20px;
                        font-weight:bold; color:#2c3e50; list-style:none; display:flex;
                        align-items:center; gap:10px; user-select:none;">
            <i class="fas fa-calendar-alt" style="color:#3498db;"></i>
            กรองตามช่วงวันที่
            <?php if ($is_filtered): ?>
                <span style="background:#3498db; color:#fff; font-size:0.78rem;
                             padding:2px 10px; border-radius:20px; margin-left:8px;">
                    <?php echo $date_from; ?> → <?php echo $date_to; ?>
                </span>
            <?php else: ?>
                <span style="background:#95a5a6; color:#fff; font-size:0.78rem;
                             padding:2px 10px; border-radius:20px; margin-left:8px;">
                    แสดงข้อมูลทั้งหมด
                </span>
            <?php endif; ?>
            <i class="fas fa-chevron-down" style="margin-left:auto; font-size:0.85rem; color:#95a5a6;"></i>
        </summary>
        <div style="background:#f8f9fa; border:1px solid #e0e0e0; border-top:none;
                    border-radius:0 0 10px 10px; padding:20px;">
            <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; color:#2c3e50;">
                        <i class="fas fa-calendar"></i> ตั้งแต่วันที่:
                    </label>
                    <input type="date" name="date_from"
                           value="<?php echo htmlspecialchars($date_from ?? ''); ?>"
                           style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <div>
                    <label style="display:block; font-weight:bold; margin-bottom:4px; color:#2c3e50;">
                        <i class="fas fa-calendar"></i> ถึงวันที่:
                    </label>
                    <input type="date" name="date_to"
                           value="<?php echo htmlspecialchars($date_to ?? ''); ?>"
                           style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> กรองข้อมูล
                </button>
                <a href="reports.php" class="btn btn-danger">
                    <i class="fas fa-times"></i> รีเซ็ต (แสดงทั้งหมด)
                </a>
            </form>
            <?php if ($is_filtered): ?>
            <small style="color:#999; margin-top:8px; display:block;">
                กำลังแสดงข้อมูล: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?>
            </small>
            <?php else: ?>
            <small style="color:#27ae60; margin-top:8px; display:block;">
                <i class="fas fa-info-circle"></i> กำลังแสดงข้อมูลทั้งหมด — เลือกช่วงวันที่เพื่อกรอง
            </small>
            <?php endif; ?>
        </div>
    </details>

    <!-- ===================== STATISTICS / CHARTS ===================== -->
    <div style="margin-bottom:32px;">
        <h3 style="color:#2c3e50; margin-bottom:16px; padding-bottom:8px; border-bottom:2px solid #ecf0f1;">
            <i class="fas fa-chart-bar" style="color:#3498db;"></i> สถิติภาพรวม
        </h3>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">

            <!-- Chart 1: Revenue Bar Chart -->
            <div style="background:#fff; border-radius:12px; padding:24px;
                        box-shadow:0 2px 12px rgba(0,0,0,0.08); grid-column: span 2;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#2c3e50;">
                        <i class="fas fa-chart-bar" style="color:#27ae60;"></i>
                        รายได้ตามวันที่ (ออเดอร์ที่เสร็จสิ้น)
                    </h4>
                    <span style="font-size:0.85rem; color:#95a5a6;">Bar Chart</span>
                </div>
                <?php if (empty($revenue_data)): ?>
                    <p style="text-align:center; color:#bdc3c7; padding:40px 0;">
                        <i class="fas fa-chart-bar" style="font-size:2rem;"></i><br>ไม่มีข้อมูล
                    </p>
                <?php else: ?>
                <div style="position:relative; height:260px;">
                    <canvas id="chartRevenue"></canvas>
                </div>
                <?php endif; ?>
            </div>

            <!-- Chart 2: Order Status Doughnut -->
            <div style="background:#fff; border-radius:12px; padding:24px;
                        box-shadow:0 2px 12px rgba(0,0,0,0.08);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#2c3e50;">
                        <i class="fas fa-chart-pie" style="color:#8e44ad;"></i>
                        สัดส่วนสถานะออเดอร์
                    </h4>
                    <span style="font-size:0.85rem; color:#95a5a6;">Doughnut Chart</span>
                </div>
                <?php if (empty($status_totals)): ?>
                    <p style="text-align:center; color:#bdc3c7; padding:40px 0;">
                        <i class="fas fa-chart-pie" style="font-size:2rem;"></i><br>ไม่มีข้อมูล
                    </p>
                <?php else: ?>
                <div style="position:relative; height:260px; display:flex; align-items:center; justify-content:center;">
                    <canvas id="chartStatus"></canvas>
                </div>
                <!-- Legend -->
                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; justify-content:center;">
                    <?php
                    $doughnut_colors = ['completed'=>'#27ae60','pending'=>'#e67e22','processing'=>'#3498db','cancelled'=>'#e74c3c'];
                    foreach($status_totals as $s => $cnt):
                        $c = $doughnut_colors[$s] ?? '#95a5a6';
                    ?>
                    <span style="background:<?php echo $c; ?>20; color:<?php echo $c; ?>;
                                 border:1px solid <?php echo $c; ?>; border-radius:20px;
                                 padding:3px 12px; font-size:0.8rem; font-weight:bold;">
                        <?php echo strtoupper($s); ?>: <?php echo $cnt; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Chart 3: Driver Performance Line/Bar Chart -->
            <div style="background:#fff; border-radius:12px; padding:24px;
                        box-shadow:0 2px 12px rgba(0,0,0,0.08);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#2c3e50;">
                        <i class="fas fa-truck" style="color:#3498db;"></i>
                        ผลงานพนักงานขับ
                    </h4>
                    <span style="font-size:0.85rem; color:#95a5a6;">Grouped Bar Chart</span>
                </div>
                <?php if (empty($driver_data)): ?>
                    <p style="text-align:center; color:#bdc3c7; padding:40px 0;">
                        <i class="fas fa-truck" style="font-size:2rem;"></i><br>ไม่มีข้อมูล
                    </p>
                <?php else: ?>
                <div style="position:relative; height:260px;">
                    <canvas id="chartDriver"></canvas>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Quick-stat summary bar -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px;">
            <?php
            $quick = [
                ['label'=>'รายได้รวม','val'=>number_format($total_revenue,2).' ฿','icon'=>'fa-coins','color'=>'#27ae60'],
                ['label'=>'ออเดอร์เสร็จสิ้น','val'=>$total_rev_orders,'icon'=>'fa-check-circle','color'=>'#3498db'],
                ['label'=>'ออเดอร์ทั้งหมด','val'=>$total_status_orders,'icon'=>'fa-list','color'=>'#8e44ad'],
                ['label'=>'ยอดค้างชำระ','val'=>number_format($total_unpaid_sum,2).' ฿','icon'=>'fa-exclamation-circle','color'=>'#e74c3c'],
                ['label'=>'ลูกค้าค้างชำระ','val'=>count($unpaid_data).' คน','icon'=>'fa-users','color'=>'#e67e22'],
                ['label'=>'การเคลื่อนไหวสต็อก','val'=>array_sum(array_column($stock_data,'log_count')).' รายการ','icon'=>'fa-boxes','color'=>'#16a085'],
            ];
            foreach($quick as $q): ?>
            <div style="background:#fff; border-radius:10px; padding:16px 18px;
                        box-shadow:0 2px 8px rgba(0,0,0,0.07); border-left:4px solid <?php echo $q['color']; ?>;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <i class="fas <?php echo $q['icon']; ?>" style="color:<?php echo $q['color']; ?>;"></i>
                    <span style="font-size:0.8rem; color:#95a5a6;"><?php echo $q['label']; ?></span>
                </div>
                <div style="font-size:1.15rem; font-weight:bold; color:#2c3e50;">
                    <?php echo $q['val']; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Report Cards -->
    <h3 style="color:#2c3e50; margin-bottom:16px; padding-bottom:8px; border-bottom:2px solid #ecf0f1;">
        <i class="fas fa-table" style="color:#e67e22;"></i> รายงานละเอียด
    </h3>
    <div class="stats-container">

        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalRevenue')">
            <i class="fas fa-money-bill-wave" style="color:#27ae60;"></i>
            <h3>รายได้รวม</h3>
            <div class="stat-number" style="color:#27ae60;"><?php echo number_format($total_revenue, 2); ?></div>
            <small style="color:#999;"><?php echo $total_rev_orders; ?> ออเดอร์ที่เสร็จสิ้น</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalDriver')">
            <i class="fas fa-truck" style="color:#3498db;"></i>
            <h3>ออเดอร์ต่อพนักงานขับ</h3>
            <div class="stat-number"><?php echo count($driver_data); ?></div>
            <small style="color:#999;">พนักงานขับทั้งหมด</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalUnpaid')">
            <i class="fas fa-exclamation-circle" style="color:#e74c3c;"></i>
            <h3>ยอดค้างชำระ</h3>
            <div class="stat-number" style="color:#e74c3c;"><?php echo number_format($total_unpaid_sum, 2); ?></div>
            <small style="color:#999;"><?php echo count($unpaid_data); ?> ลูกค้าที่ค้างชำระ</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalStock')">
            <i class="fas fa-boxes" style="color:#e67e22;"></i>
            <h3>ความเคลื่อนไหวสต็อก</h3>
            <div class="stat-number"><?php echo array_sum(array_column($stock_data, 'log_count')); ?></div>
            <small style="color:#999;">รายการในช่วงเวลานี้</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalStatus')">
            <i class="fas fa-chart-pie" style="color:#8e44ad;"></i>
            <h3>สถานะออเดอร์</h3>
            <div class="stat-number"><?php echo $total_status_orders; ?></div>
            <small style="color:#999;">ออเดอร์ทั้งหมดในช่วงนี้</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 1: Revenue                                             -->
<!-- ============================================================ -->
<div id="modalRevenue" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-money-bill-wave" style="color:#27ae60;"></i> รายได้ตามวันที่</h3>
            <div style="display:flex; gap:8px;">
                <button onclick="printReport('printRevenue')" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> พิมพ์
                </button>
                <button onclick="closeModal('modalRevenue')" class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:16px;">
            <?php if ($is_filtered): ?>
                ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?> —
            <?php else: ?>ข้อมูลทั้งหมด —<?php endif; ?>
            รายได้รวม: <strong style="color:#27ae60;"><?php echo number_format($total_revenue, 2); ?> บาท</strong>
        </p>
        <div id="printRevenue">
            <div class="print-header">
                <h2>รายงานรายได้</h2>
                <p><?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to" : 'ข้อมูลทั้งหมด'; ?></p>
            </div>
            <?php if (empty($revenue_data)): ?>
                <p style="color:#999; text-align:center;">ไม่มีข้อมูล</p>
            <?php else: ?>
            <table class="report-table">
                <thead><tr><th>วันที่</th><th>จำนวนออเดอร์</th><th>ขวดรวม</th><th>รายได้</th></tr></thead>
                <tbody>
                    <?php foreach($revenue_data as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['order_date']); ?></td>
                        <td style="text-align:center;"><?php echo $row['order_count']; ?></td>
                        <td style="text-align:center;"><?php echo $row['total_qty']; ?></td>
                        <td style="text-align:right; color:#27ae60; font-weight:bold;">
                            <?php echo number_format($row['revenue'], 2); ?> บาท
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background:#f0f9f0;">
                        <td>รวม</td>
                        <td style="text-align:center;"><?php echo $total_rev_orders; ?></td>
                        <td style="text-align:center;"><?php echo array_sum(array_column($revenue_data, 'total_qty')); ?></td>
                        <td style="text-align:right; color:#27ae60;"><?php echo number_format($total_revenue, 2); ?> บาท</td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 2: Driver                                              -->
<!-- ============================================================ -->
<div id="modalDriver" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-truck" style="color:#3498db;"></i> ออเดอร์ต่อพนักงานขับ</h3>
            <div style="display:flex; gap:8px;">
                <button onclick="printReport('printDriver')" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> พิมพ์
                </button>
                <button onclick="closeModal('modalDriver')" class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:16px;">
            <?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to" : 'ข้อมูลทั้งหมด'; ?>
        </p>
        <div id="printDriver">
            <div class="print-header">
                <h2>รายงานออเดอร์ต่อพนักงานขับ</h2>
                <p><?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to" : 'ข้อมูลทั้งหมด'; ?></p>
            </div>
            <?php if (empty($driver_data)): ?>
                <p style="color:#999; text-align:center;">ไม่มีข้อมูล</p>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>พนักงานขับ</th><th>ทั้งหมด</th><th>เสร็จสิ้น</th>
                        <th>รอดำเนินการ</th><th>ยกเลิก</th><th>รายได้</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($driver_data as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['DFname'] . ' ' . $row['DLname']); ?></td>
                        <td style="text-align:center; font-weight:bold;"><?php echo $row['total_orders']; ?></td>
                        <td style="text-align:center; color:#27ae60;"><?php echo $row['completed']; ?></td>
                        <td style="text-align:center; color:#e67e22;"><?php echo $row['pending']; ?></td>
                        <td style="text-align:center; color:#e74c3c;"><?php echo $row['cancelled']; ?></td>
                        <td style="text-align:right; color:#27ae60; font-weight:bold;">
                            <?php echo number_format($row['revenue'], 2); ?> บาท
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 3: Unpaid                                             -->
<!-- ============================================================ -->
<div id="modalUnpaid" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-circle" style="color:#e74c3c;"></i> ยอดค้างชำระ</h3>
            <div style="display:flex; gap:8px;">
                <button onclick="printReport('printUnpaid')" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> พิมพ์
                </button>
                <button onclick="closeModal('modalUnpaid')" class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:16px;">
            ยอดค้างชำระรวม: <strong style="color:#e74c3c;"><?php echo number_format($total_unpaid_sum, 2); ?> บาท</strong>
            จาก <?php echo count($unpaid_data); ?> ลูกค้า
        </p>
        <div id="printUnpaid">
            <div class="print-header">
                <h2>รายงานยอดค้างชำระ</h2>
                <p>วันที่พิมพ์: <?php echo date('Y-m-d'); ?></p>
            </div>
            <?php if (empty($unpaid_data)): ?>
                <p style="color:#27ae60; text-align:center;">
                    <i class="fas fa-check-circle"></i> ไม่มีลูกค้าค้างชำระ
                </p>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr><th>ลูกค้า</th><th>เบอร์โทร</th><th>ออเดอร์รวม</th><th>ยอดค้างชำระ</th></tr>
                </thead>
                <tbody>
                    <?php foreach($unpaid_data as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['User_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['tel'] ?? '-'); ?></td>
                        <td style="text-align:center;"><?php echo $row['total_orders']; ?></td>
                        <td style="text-align:right; color:#e74c3c; font-weight:bold;">
                            <?php echo number_format($row['Unpaid_amount'], 2); ?> บาท
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background:#fdf0f0;">
                        <td colspan="3">รวมทั้งหมด</td>
                        <td style="text-align:right; color:#e74c3c;">
                            <?php echo number_format($total_unpaid_sum, 2); ?> บาท
                        </td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 4: Stock                                               -->
<!-- ============================================================ -->
<div id="modalStock" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-boxes" style="color:#e67e22;"></i> ความเคลื่อนไหวสต็อก</h3>
            <div style="display:flex; gap:8px;">
                <button onclick="printReport('printStock')" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> พิมพ์
                </button>
                <button onclick="closeModal('modalStock')" class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:16px;">
            <?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to" : 'ข้อมูลทั้งหมด'; ?>
        </p>
        <div id="printStock">
            <div class="print-header">
                <h2>รายงานความเคลื่อนไหวสต็อก</h2>
                <p><?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to" : 'ข้อมูลทั้งหมด'; ?></p>
            </div>
            <?php if (empty($stock_data)): ?>
                <p style="color:#999; text-align:center;">ไม่มีการเคลื่อนไหว</p>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr><th>ประเภทการเคลื่อนไหว</th><th>ประเภทขวด</th><th>จำนวนครั้ง</th><th>จำนวนขวดรวม</th></tr>
                </thead>
                <tbody>
                    <?php
                    $type_labels = [
                        'bottles_out'       => ['label' => 'ขวดออก',     'color' => '#e74c3c'],
                        'bottles_return'    => ['label' => 'ขวดคืน',     'color' => '#27ae60'],
                        'manual_adjustment' => ['label' => 'ปรับสต็อก', 'color' => '#8e44ad'],
                    ];
                    foreach($stock_data as $row):
                        $t = $type_labels[$row['action_type']] ?? ['label' => $row['action_type'], 'color' => '#666'];
                    ?>
                    <tr>
                        <td><span style="color:<?php echo $t['color']; ?>; font-weight:bold;"><?php echo $t['label']; ?></span></td>
                        <td><?php echo htmlspecialchars($row['bottle_type']); ?></td>
                        <td style="text-align:center;"><?php echo $row['log_count']; ?></td>
                        <td style="text-align:center; font-weight:bold;"><?php echo $row['total_moved']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 5: Status                                              -->
<!-- ============================================================ -->
<div id="modalStatus" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-chart-pie" style="color:#8e44ad;"></i> สรุปสถานะออเดอร์</h3>
            <div style="display:flex; gap:8px;">
                <button onclick="printReport('printStatus')" class="btn btn-primary btn-sm">
                    <i class="fas fa-print"></i> พิมพ์
                </button>
                <button onclick="closeModal('modalStatus')" class="btn btn-danger btn-sm">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <p style="color:#7f8c8d; font-size:0.9rem; margin-bottom:16px;">
            <?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to —" : 'ข้อมูลทั้งหมด —'; ?>
            ออเดอร์ทั้งหมด: <strong><?php echo $total_status_orders; ?></strong>
        </p>
        <div id="printStatus">
            <div class="print-header">
                <h2>รายงานสถานะออเดอร์</h2>
                <p><?php echo $is_filtered ? "ช่วงเวลา: $date_from ถึง $date_to" : 'ข้อมูลทั้งหมด'; ?></p>
            </div>
            <?php if (empty($status_grouped)): ?>
                <p style="color:#999; text-align:center;">ไม่มีข้อมูล</p>
            <?php else: ?>
            <?php
            $order_status_colors = ['completed'=>'#27ae60','pending'=>'#e67e22','processing'=>'#3498db','cancelled'=>'#e74c3c'];
            foreach($status_grouped as $order_status => $rows):
                $color = $order_status_colors[$order_status] ?? '#666';
                $group_total = array_sum(array_column($rows, 'count'));
                $group_value = array_sum(array_column($rows, 'total_value'));
            ?>
            <div style="margin-bottom:16px;">
                <div style="background:<?php echo $color; ?>; color:#fff; padding:8px 14px;
                            border-radius:6px 6px 0 0; font-weight:bold;">
                    <?php echo strtoupper($order_status); ?>
                    — <?php echo $group_total; ?> ออเดอร์
                    — <?php echo number_format($group_value, 2); ?> บาท
                </div>
                <table class="report-table" style="margin:0; border-top:none;">
                    <thead>
                        <tr><th>สถานะการชำระเงิน</th><th>จำนวนออเดอร์</th><th>มูลค่ารวม</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['payment_status']); ?></td>
                            <td style="text-align:center;"><?php echo $row['count']; ?></td>
                            <td style="text-align:right;"><?php echo number_format($row['total_value'], 2); ?> บาท</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- Chart.js CDN + Styles                                        -->
<!-- ============================================================ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<style>
/* Details/summary arrow tweak */
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
details[open] summary .fa-chevron-down { transform: rotate(180deg); transition: transform 0.2s; }

.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 1000;
    justify-content: center; align-items: center;
}
.modal-box {
    background: #fff; border-radius: 12px; padding: 32px;
    width: 680px; max-width: 95%; max-height: 85vh;
    overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.2);
}
.modal-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 8px;
}
.modal-header h3 { margin: 0; color: #2c3e50; }
.report-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.report-table th {
    background: #2c3e50; color: #fff;
    padding: 8px 12px; text-align: left; font-size: 0.9rem;
}
.report-table td { padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 0.9rem; }
.report-table tr:hover td { background: #f8f9fa; }
.report-table tfoot td { border-top: 2px solid #2c3e50; border-bottom: none; }
.print-header { display: none; }
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    .print-header { display: block; margin-bottom: 16px; }
    .print-header h2 { margin: 0; color: #000; }
    .report-table th { background: #333 !important; -webkit-print-color-adjust: exact; }
}
</style>

<script>
// ── Modal helpers ──────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

function printReport(printDivId) {
    var content = document.getElementById(printDivId).innerHTML;
    var win = window.open('', '_blank', 'width=800,height=600');
    win.document.write(`<html><head><title>รายงาน</title><style>
        body{font-family:Arial,sans-serif;padding:24px;color:#000}
        h2{margin:0 0 4px 0} p{margin:0 0 16px 0;color:#555}
        table{width:100%;border-collapse:collapse;margin-top:12px}
        th{background:#2c3e50;color:#fff;padding:8px 12px;text-align:left}
        td{padding:8px 12px;border-bottom:1px solid #ddd}
        tfoot td{border-top:2px solid #2c3e50;font-weight:bold}
        .print-header{display:block!important}
    </style></head><body>` + content + `</body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
}

// ── Chart 1: Revenue Bar ───────────────────────────────────────
(function() {
    var labels = <?php echo $chart_revenue_labels; ?>;
    var values = <?php echo $chart_revenue_values; ?>;
    if (!labels.length) return;
    var ctx = document.getElementById('chartRevenue');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'รายได้ (บาท)',
                data: values,
                backgroundColor: 'rgba(39,174,96,0.7)',
                borderColor: '#27ae60',
                borderWidth: 1.5,
                borderRadius: 5,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + parseFloat(ctx.raw).toLocaleString('th-TH', {minimumFractionDigits:2}) + ' บาท';
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 45, font: { size: 11 } } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(v) {
                            return v.toLocaleString('th-TH');
                        }
                    }
                }
            }
        }
    });
})();

// ── Chart 2: Order Status Doughnut ────────────────────────────
(function() {
    var labels = <?php echo $chart_status_labels; ?>;
    var values = <?php echo $chart_status_values; ?>;
    if (!labels.length) return;
    var ctx = document.getElementById('chartStatus');
    if (!ctx) return;
    var colorMap = {completed:'#27ae60', pending:'#e67e22', processing:'#3498db', cancelled:'#e74c3c'};
    var colors = labels.map(function(l){ return colorMap[l] || '#95a5a6'; });
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels.map(function(l){ return l.toUpperCase(); }),
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                            var pct = ((ctx.raw / total) * 100).toFixed(1);
                            return ' ' + ctx.raw + ' ออเดอร์ (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
})();

// ── Chart 3: Driver Grouped Bar ───────────────────────────────
(function() {
    var names     = <?php echo $chart_driver_names; ?>;
    var completed = <?php echo $chart_driver_completed; ?>;
    var cancelled = <?php echo $chart_driver_cancelled; ?>;
    if (!names.length) return;
    var ctx = document.getElementById('chartDriver');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: names,
            datasets: [
                {
                    label: 'เสร็จสิ้น',
                    data: completed,
                    backgroundColor: 'rgba(39,174,96,0.75)',
                    borderColor: '#27ae60',
                    borderWidth: 1.5,
                    borderRadius: 4,
                },
                {
                    label: 'ยกเลิก',
                    data: cancelled,
                    backgroundColor: 'rgba(231,76,60,0.65)',
                    borderColor: '#e74c3c',
                    borderWidth: 1.5,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: { font: { size: 12 }, padding: 16 }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>