<?php
require_once '../includes/auth.php';
require_login('admin');
// ... rest of existing code unchanged
require_once '../config/database.php';
require_once '../includes/header.php';
// ===== Default date range: this month =====
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// ===== Report 1: Revenue by date range =====
$stmt = $pdo->prepare("
    SELECT 
        DATE(scheduled_date) AS order_date,
        COUNT(*)             AS order_count,
        SUM(qty_ordered)     AS total_qty,
        SUM(total_expected_price) AS revenue
    FROM `order`
    WHERE order_status = 'completed'
      AND DATE(scheduled_date) BETWEEN ? AND ?
    GROUP BY DATE(scheduled_date)
    ORDER BY order_date ASC
");
$stmt->execute([$date_from, $date_to]);
$revenue_data = $stmt->fetchAll();
$total_revenue    = array_sum(array_column($revenue_data, 'revenue'));
$total_rev_orders = array_sum(array_column($revenue_data, 'order_count'));

// ===== Report 2: Orders per driver =====
$stmt = $pdo->prepare("
    SELECT 
        d.DID, d.DFname, d.DLname,
        COUNT(o.order_id) AS total_orders,
        SUM(CASE WHEN o.order_status = 'completed'  THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN o.order_status = 'pending'    THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN o.order_status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN o.order_status = 'completed'  THEN o.total_expected_price ELSE 0 END) AS revenue
    FROM driver d
    LEFT JOIN `order` o ON d.DID = o.DID
        AND DATE(o.scheduled_date) BETWEEN ? AND ?
    GROUP BY d.DID, d.DFname, d.DLname
    ORDER BY total_orders DESC
");
$stmt->execute([$date_from, $date_to]);
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
$stmt = $pdo->prepare("
    SELECT 
        action_type,
        bottle_type,
        COUNT(*)       AS log_count,
        SUM(total_qty) AS total_moved
    FROM stock_log
    WHERE DATE(timestamp) BETWEEN ? AND ?
    GROUP BY action_type, bottle_type
    ORDER BY action_type, bottle_type
");
$stmt->execute([$date_from, $date_to]);
$stock_data = $stmt->fetchAll();

// ===== Report 5: Orders by status breakdown =====
$stmt = $pdo->prepare("
    SELECT 
        order_status,
        payment_status,
        COUNT(*)                  AS count,
        SUM(total_expected_price) AS total_value
    FROM `order`
    WHERE DATE(scheduled_date) BETWEEN ? AND ?
    GROUP BY order_status, payment_status
    ORDER BY order_status, payment_status
");
$stmt->execute([$date_from, $date_to]);
$status_data = $stmt->fetchAll();

// Group status data by order_status for easier display
$status_grouped = [];
foreach ($status_data as $row) {
    $status_grouped[$row['order_status']][] = $row;
}
$total_status_orders = array_sum(array_column($status_data, 'count'));
?>

<div class="dashboard">
    <h2>รายงาน (Reports)</h2>

    <!-- Date Range Filter -->
    <div style="background:#f8f9fa; border-radius:10px; padding:20px; margin-bottom:24px;">
        <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <label style="display:block; font-weight:bold; margin-bottom:4px; color:#2c3e50;">
                    <i class="fas fa-calendar"></i> ตั้งแต่วันที่:
                </label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>"
                       style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div>
                <label style="display:block; font-weight:bold; margin-bottom:4px; color:#2c3e50;">
                    <i class="fas fa-calendar"></i> ถึงวันที่:
                </label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>"
                       style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> กรองข้อมูล
            </button>
            <a href="reports.php" class="btn btn-danger">
                <i class="fas fa-times"></i> รีเซ็ต
            </a>
        </form>
        <small style="color:#999; margin-top:8px; display:block;">
            กำลังแสดงข้อมูล: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?>
        </small>
    </div>

    <!-- Report Cards -->
    <div class="stats-container">

        <!-- Card 1: Revenue -->
        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalRevenue')">
            <i class="fas fa-money-bill-wave" style="color:#27ae60;"></i>
            <h3>รายได้รวม</h3>
            <div class="stat-number" style="color:#27ae60;">
                <?php echo number_format($total_revenue, 2); ?>
            </div>
            <small style="color:#999;"><?php echo $total_rev_orders; ?> ออเดอร์ที่เสร็จสิ้น</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <!-- Card 2: Driver -->
        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalDriver')">
            <i class="fas fa-truck" style="color:#3498db;"></i>
            <h3>ออเดอร์ต่อพนักงานขับ</h3>
            <div class="stat-number"><?php echo count($driver_data); ?></div>
            <small style="color:#999;">พนักงานขับทั้งหมด</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <!-- Card 3: Unpaid -->
        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalUnpaid')">
            <i class="fas fa-exclamation-circle" style="color:#e74c3c;"></i>
            <h3>ยอดค้างชำระ</h3>
            <div class="stat-number" style="color:#e74c3c;">
                <?php echo number_format($total_unpaid_sum, 2); ?>
            </div>
            <small style="color:#999;"><?php echo count($unpaid_data); ?> ลูกค้าที่ค้างชำระ</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <!-- Card 4: Stock -->
        <div class="stat-card" style="cursor:pointer;" onclick="openModal('modalStock')">
            <i class="fas fa-boxes" style="color:#e67e22;"></i>
            <h3>ความเคลื่อนไหวสต็อก</h3>
            <div class="stat-number"><?php echo array_sum(array_column($stock_data, 'log_count')); ?></div>
            <small style="color:#999;">รายการในช่วงเวลานี้</small>
            <div style="margin-top:8px; color:#3498db; font-size:0.85rem;">
                <i class="fas fa-search"></i> คลิกดูรายละเอียด
            </div>
        </div>

        <!-- Card 5: Status -->
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
<!-- MODAL 1: Revenue by date range                               -->
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
            ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?>
            — รายได้รวม: <strong style="color:#27ae60;"><?php echo number_format($total_revenue, 2); ?> บาท</strong>
        </p>

        <div id="printRevenue">
            <div class="print-header">
                <h2>รายงานรายได้</h2>
                <p>ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?></p>
            </div>
            <?php if (empty($revenue_data)): ?>
                <p style="color:#999; text-align:center;">ไม่มีข้อมูลในช่วงเวลานี้</p>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>จำนวนออเดอร์</th>
                        <th>ขวดรวม</th>
                        <th>รายได้</th>
                    </tr>
                </thead>
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
<!-- MODAL 2: Orders per driver                                   -->
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
            ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?>
        </p>

        <div id="printDriver">
            <div class="print-header">
                <h2>รายงานออเดอร์ต่อพนักงานขับ</h2>
                <p>ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?></p>
            </div>
            <?php if (empty($driver_data)): ?>
                <p style="color:#999; text-align:center;">ไม่มีข้อมูลในช่วงเวลานี้</p>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>พนักงานขับ</th>
                        <th>ทั้งหมด</th>
                        <th>เสร็จสิ้น</th>
                        <th>รอดำเนินการ</th>
                        <th>ยกเลิก</th>
                        <th>รายได้</th>
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
<!-- MODAL 3: Unpaid balance by customer                         -->
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
                    <tr>
                        <th>ลูกค้า</th>
                        <th>เบอร์โทร</th>
                        <th>ออเดอร์รวม</th>
                        <th>ยอดค้างชำระ</th>
                    </tr>
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
<!-- MODAL 4: Stock movement summary                              -->
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
            ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?>
        </p>

        <div id="printStock">
            <div class="print-header">
                <h2>รายงานความเคลื่อนไหวสต็อก</h2>
                <p>ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?></p>
            </div>
            <?php if (empty($stock_data)): ?>
                <p style="color:#999; text-align:center;">ไม่มีการเคลื่อนไหวในช่วงเวลานี้</p>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>ประเภทการเคลื่อนไหว</th>
                        <th>ประเภทขวด</th>
                        <th>จำนวนครั้ง</th>
                        <th>จำนวนขวดรวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $type_labels = [
                        'bottles_out'       => ['label' => 'ขวดออก',       'color' => '#e74c3c'],
                        'bottles_return'    => ['label' => 'ขวดคืน',       'color' => '#27ae60'],
                        'manual_adjustment' => ['label' => 'ปรับสต็อก',   'color' => '#8e44ad'],
                    ];
                    foreach($stock_data as $row):
                        $t = $type_labels[$row['action_type']] ?? ['label' => $row['action_type'], 'color' => '#666'];
                    ?>
                    <tr>
                        <td>
                            <span style="color:<?php echo $t['color']; ?>; font-weight:bold;">
                                <?php echo $t['label']; ?>
                            </span>
                        </td>
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
<!-- MODAL 5: Orders by status breakdown                          -->
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
            ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?>
            — ออเดอร์ทั้งหมด: <strong><?php echo $total_status_orders; ?></strong>
        </p>

        <div id="printStatus">
            <div class="print-header">
                <h2>รายงานสถานะออเดอร์</h2>
                <p>ช่วงเวลา: <?php echo $date_from; ?> ถึง <?php echo $date_to; ?></p>
            </div>
            <?php if (empty($status_grouped)): ?>
                <p style="color:#999; text-align:center;">ไม่มีข้อมูลในช่วงเวลานี้</p>
            <?php else: ?>

            <?php
            $order_status_colors = [
                'completed'  => '#27ae60',
                'pending'    => '#e67e22',
                'processing' => '#3498db',
                'cancelled'  => '#e74c3c',
            ];
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
                        <tr>
                            <th>สถานะการชำระเงิน</th>
                            <th>จำนวนออเดอร์</th>
                            <th>มูลค่ารวม</th>
                        </tr>
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

<!-- Shared styles -->
<style>
.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 1000;
    justify-content: center; align-items: center;
}
.modal-box {
    background: #fff; border-radius: 12px; padding: 32px;
    width: 640px; max-width: 95%; max-height: 85vh;
    overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.2);
}
.modal-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 8px;
}
.modal-header h3 { margin: 0; color: #2c3e50; }
.report-table {
    width: 100%; border-collapse: collapse; margin-bottom: 8px;
}
.report-table th {
    background: #2c3e50; color: #fff;
    padding: 8px 12px; text-align: left; font-size: 0.9rem;
}
.report-table td {
    padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 0.9rem;
}
.report-table tr:hover td { background: #f8f9fa; }
.report-table tfoot td {
    border-top: 2px solid #2c3e50; border-bottom: none;
}

/* Print styles */
.print-header { display: none; }
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea {
        position: fixed; top: 0; left: 0; width: 100%;
        padding: 20px; background: #fff;
    }
    .print-header { display: block; margin-bottom: 16px; }
    .print-header h2 { margin: 0; color: #000; }
    .report-table th { background: #333 !important; -webkit-print-color-adjust: exact; }
    .modal-overlay, .modal-box { position: static !important; overflow: visible !important; }
}
</style>

<script>
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Click backdrop to close
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// Print just the report content inside the modal
function printReport(printDivId) {
    var content = document.getElementById(printDivId).innerHTML;
    var win = window.open('', '_blank', 'width=800,height=600');
    win.document.write(`
        <html>
        <head>
            <title>รายงาน</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #000; }
                h2 { margin: 0 0 4px 0; }
                p  { margin: 0 0 16px 0; color: #555; }
                table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                th { background: #2c3e50; color: #fff; padding: 8px 12px; text-align: left; }
                td { padding: 8px 12px; border-bottom: 1px solid #ddd; }
                tfoot td { border-top: 2px solid #2c3e50; font-weight: bold; }
                .print-header { display: block !important; }
                div[style*="background"] { padding: 8px 14px; font-weight: bold;
                    margin-bottom: 0; color: #fff; }
            </style>
        </head>
        <body>` + content + `</body>
        </html>
    `);
    win.document.close();
    win.focus();
    win.print();
    win.close();
}
</script>

<?php require_once '../includes/footer.php'; ?>