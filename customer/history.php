<?php
require_once '../includes/auth.php';
require_login('customer');
require_once '../config/database.php';

$user_id = $_SESSION['ref_id'];

// ── Helper: extract [Product Name] from note ──────────────────────────────────
function extractBottleType(string $note): array {
    if (preg_match('/^\[([^\]]+)\]/', $note, $m)) {
        return ['type' => trim($m[1]), 'note' => trim(substr($note, strlen($m[0])))];
    }
    return ['type' => '', 'note' => $note];
}

// ── Fetch combined history ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    (SELECT
        r.requested_date    AS display_date,
        'request'           AS record_type,
        r.qty,
        r.status            AS current_status,
        l.loc_name,
        r.request_id        AS ref_no,
        r.note,
        NULL AS payment_status,
        NULL AS total_expected_price,
        NULL AS actual_amount_collected,
        NULL AS actual_qty_ordered,
        NULL AS deposit_fee,
        NULL AS scheduled_date,
        NULL AS order_id_link
    FROM order_request r
    JOIN location l ON r.loc_id = l.loc_id
    WHERE r.User_ID = ?)

    UNION ALL

    (SELECT
        o.scheduled_date    AS display_date,
        'order'             AS record_type,
        o.qty_ordered       AS qty,
        o.order_status      AS current_status,
        l.loc_name,
        o.order_id          AS ref_no,
        o.driver_note       AS note,
        o.payment_status,
        o.total_expected_price,
        o.actual_amount_collected,
        o.actual_qty_ordered,
        o.deposit_fee,
        o.scheduled_date,
        o.order_id          AS order_id_link
    FROM `order` o
    JOIN location l ON o.loc_id = l.loc_id
    WHERE o.User_ID = ?)

    ORDER BY display_date DESC
");
$stmt->execute([$user_id, $user_id]);
$history = $stmt->fetchAll();

// ── Fetch transactions for all orders in one query ───────────────────────────
// array_values re-indexes after array_unique to avoid PDO parameter mismatch
$order_ids = array_values(array_unique(array_filter(array_column($history, 'order_id_link'))));
$txn_map   = [];
if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $txns = $pdo->prepare("
        SELECT txn_id, order_id, confirmed_at, qty_delivered, qty_empty_collected,
               amount_collected, note
        FROM `transaction`
        WHERE order_id IN ($placeholders)
        ORDER BY confirmed_at ASC
    ");
    $txns->execute($order_ids);
    foreach ($txns->fetchAll() as $t) {
        $txn_map[$t['order_id']][] = $t;
    }
}

require_once '../includes/header_customer.php';
?>

<style>
/* ── Bottom sheet / modal ─────────────────────────────────────────────────── */
.detail-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 400;
    align-items: flex-end;
    justify-content: center;
}
.detail-backdrop.open { display: flex; }

.detail-sheet {
    background: #fff;
    border-radius: 20px 20px 0 0;
    width: 100%;
    max-width: 560px;
    max-height: 88vh;
    overflow-y: auto;
    padding: 0 0 32px;
    animation: sheetUp 0.25s ease;
    position: relative;
}
@keyframes sheetUp {
    from { transform: translateY(100%); }
    to   { transform: translateY(0); }
}

.sheet-handle {
    width: 36px; height: 4px;
    background: #dde1ea; border-radius: 2px;
    margin: 12px auto 0;
}
.sheet-header {
    padding: 16px 20px 12px;
    border-bottom: 1px solid #f0f2f7;
    position: sticky; top: 0;
    background: #fff; z-index: 5;
}
.sheet-ref {
    font-size: 0.68rem; color: var(--muted, #95a5a6);
    font-family: monospace; margin-bottom: 2px;
}
.sheet-title { font-size: 1.05rem; font-weight: 700; color: var(--navy, #1b2a4a); }
.sheet-close {
    position: absolute; top: 14px; right: 16px;
    background: #f4f6fa; border: none;
    width: 30px; height: 30px; border-radius: 8px;
    cursor: pointer; font-size: 0.9rem; color: #7f8c8d;
    display: flex; align-items: center; justify-content: center;
}
.sheet-close:hover { background: #e8eaf0; }

.sheet-body { padding: 16px 20px; }

/* ── Section label ────────────────────────────────────────────────────────── */
.detail-section-label {
    font-size: 0.65rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--muted, #95a5a6);
    margin: 16px 0 8px;
    padding-bottom: 5px;
    border-bottom: 1px solid #f0f2f7;
}

/* ── Info grid ────────────────────────────────────────────────────────────── */
.detail-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.detail-cell {
    background: #f7f9fc; border-radius: 8px; padding: 9px 12px;
}
.detail-cell .dc-label { font-size: 0.68rem; color: var(--muted); margin-bottom: 2px; }
.detail-cell .dc-value { font-size: 0.88rem; font-weight: 600; color: var(--navy); }
.detail-cell.full  { grid-column: 1 / -1; }
.detail-cell.green .dc-value { color: #27ae60; }
.detail-cell.red   .dc-value { color: #e74c3c; }
.detail-cell.amber .dc-value { color: #e67e22; }

/* ── Progress bar ─────────────────────────────────────────────────────────── */
.mini-bar-track {
    background: #eee; border-radius: 4px; height: 6px; margin-top: 6px; overflow: hidden;
}
.mini-bar-fill { height: 6px; border-radius: 4px; }

/* ── Alert chips ──────────────────────────────────────────────────────────── */
.alert-chip {
    display: flex; align-items: center; gap: 8px;
    border-radius: 8px; padding: 9px 12px; font-size: 0.8rem;
    margin-bottom: 8px;
}
.alert-chip.warn  { background: #fff8e1; color: #7d5a00; border: 1px solid #ffe082; }
.alert-chip.danger{ background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
.alert-chip.ok    { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }

/* ── Transaction rows ─────────────────────────────────────────────────────── */
.txn-item {
    display: grid; grid-template-columns: auto 1fr auto;
    gap: 10px; align-items: start;
    padding: 8px 0; border-bottom: 1px solid #f0f2f7; font-size: 0.8rem;
}
.txn-item:last-child { border-bottom: none; }
.txn-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--foam, #5dade2); margin-top: 5px; flex-shrink: 0;
}
.txn-date { font-size: 0.68rem; color: var(--muted); margin-top: 2px; }

/* ── Bottle pill ──────────────────────────────────────────────────────────── */
.bottle-pill-sm {
    display: inline-flex; align-items: center; gap: 4px;
    background: #eaf6ff; color: #1a7ab5;
    border: 1px solid #b3d9f5; border-radius: 20px;
    padding: 2px 9px; font-size: 0.72rem; font-weight: 600;
}

/* ── Row tap feedback ─────────────────────────────────────────────────────── */
.history-row {
    cursor: pointer;
    border-radius: 8px;
    transition: background 0.12s;
    margin: 0 -8px;
    padding: 10px 8px;
}
.history-row:active { background: #f0f6ff; }
</style>

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
                margin:16px 0 8px; padding-left:4px;">
        <?php echo $month; ?>
    </div>

    <div class="card" style="padding:0 12px;">
        <?php foreach($rows as $row):
            $is_request = $row['record_type'] === 'request';
            $status_map = [
                'pending_admin' => ['รอ Admin',     'badge-pending_admin'],
                'approved'      => ['อนุมัติ',      'badge-approved'],
                'rejected'      => ['ปฏิเสธ',       'badge-rejected'],
                'completed'     => ['สำเร็จ',       'badge-completed'],
                'pending'       => ['รอดำเนินการ',  'badge-pending'],
                'processing'    => ['กำลังส่ง',     'badge-processing'],
                'cancelled'     => ['ยกเลิก',       'badge-cancelled'],
            ];
            [$label, $badge_class] = $status_map[$row['current_status']] ?? [$row['current_status'], 'badge-pending'];

            // Parse bottle type from note
            $parsed      = extractBottleType($row['note'] ?? '');
            $bottle_type = $parsed['type'];
            $clean_note  = $parsed['note'];

            // Encode all data needed for the detail sheet into JSON
            $txns_for_row = $row['order_id_link'] ? ($txn_map[$row['order_id_link']] ?? []) : [];
            $detail_data  = htmlspecialchars(json_encode([
                'ref_no'            => $row['ref_no'],
                'record_type'       => $row['record_type'],
                'loc_name'          => $row['loc_name'],
                'display_date'      => $row['display_date'],
                'scheduled_date'    => $row['scheduled_date'],
                'qty'               => $row['qty'],
                'current_status'    => $row['current_status'],
                'status_label'      => $label,
                'payment_status'    => $row['payment_status'],
                'total_price'       => (float)($row['total_expected_price'] ?? 0),
                'paid'              => (float)($row['actual_amount_collected'] ?? 0),
                'deposit_fee'       => (float)($row['deposit_fee'] ?? 0),
                'delivered'         => (int)($row['actual_qty_ordered'] ?? 0),
                'bottle_type'       => $bottle_type,
                'clean_note'        => $clean_note,
                'txns'              => $txns_for_row,
            ]), ENT_QUOTES);
        ?>
        <div class="history-row" onclick="openDetail(<?php echo $detail_data; ?>)">
            <!-- Icon -->
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
                    <?php if ($bottle_type): ?>
                    · <span class="bottle-pill-sm">💧 <?php echo htmlspecialchars($bottle_type); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <!-- Status + chevron -->
            <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                <span class="badge <?php echo $badge_class; ?>"><?php echo $label; ?></span>
                <i class="fas fa-chevron-right" style="font-size:0.7rem; color:#c5cad6;"></i>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     DETAIL BOTTOM SHEET
═══════════════════════════════════════════════════════════════════════ -->
<div class="detail-backdrop" id="detailBackdrop" onclick="closeDetail(event)">
    <div class="detail-sheet" onclick="event.stopPropagation()">
        <div class="sheet-handle"></div>

        <div class="sheet-header">
            <div class="sheet-ref"  id="ds_ref"></div>
            <div class="sheet-title" id="ds_title"></div>
            <button class="sheet-close" onclick="closeDetailBtn()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="sheet-body" id="ds_body">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<script>
function fmt(n, d=2) {
    return parseFloat(n||0).toLocaleString('th-TH',{minimumFractionDigits:d,maximumFractionDigits:d});
}
function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s);
    return d.toLocaleDateString('th-TH', {day:'2-digit', month:'short', year:'numeric'});
}
function fmtDateTime(s) {
    if (!s) return '—';
    const d = new Date(s);
    return d.toLocaleDateString('th-TH',{day:'2-digit',month:'short'}) + ' ' +
           d.toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'});
}

const STATUS_TH = {
    pending_admin: 'รอ Admin', approved: 'อนุมัติแล้ว', rejected: 'ถูกปฏิเสธ',
    completed: 'สำเร็จ', pending: 'รอดำเนินการ',
    processing: 'กำลังส่ง', cancelled: 'ยกเลิก',
    paid: 'ชำระครบ', partial: 'ชำระบางส่วน', unpaid: 'ยังไม่ชำระ',
};
const STATUS_COLOR = {
    completed:'#27ae60', approved:'#27ae60', paid:'#27ae60',
    processing:'#2471a3', partial:'#e67e22',
    pending:'#e67e22', pending_admin:'#e67e22',
    rejected:'#e74c3c', cancelled:'#e74c3c', unpaid:'#e74c3c',
};

function openDetail(d) {
    document.getElementById('ds_ref').textContent   = d.ref_no + ' · ' + (d.record_type === 'request' ? 'ใบคำขอ' : 'รายการจัดส่ง');
    document.getElementById('ds_title').textContent = d.loc_name;
    document.getElementById('ds_body').innerHTML    = buildBody(d);
    document.getElementById('detailBackdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDetail(e) {
    if (e.target === document.getElementById('detailBackdrop')) closeDetailBtn();
}
function closeDetailBtn() {
    document.getElementById('detailBackdrop').classList.remove('open');
    document.body.style.overflow = '';
}

function buildBody(d) {
    let html = '';

    // ── Status + bottle type badges ──────────────────────────────────────────
    html += '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">';
    html += `<span style="display:inline-flex; align-items:center; gap:5px;
                 background:${STATUS_COLOR[d.current_status]||'#999'}22;
                 color:${STATUS_COLOR[d.current_status]||'#999'};
                 border:1px solid ${STATUS_COLOR[d.current_status]||'#999'}55;
                 border-radius:20px; padding:3px 11px; font-size:0.78rem; font-weight:700;">
             ${d.status_label}</span>`;
    if (d.bottle_type) {
        html += `<span class="bottle-pill-sm">💧 ${d.bottle_type}</span>`;
    }
    html += '</div>';

    // ── Alert chips for incomplete states ────────────────────────────────────
    if (d.record_type === 'order') {
        const remaining_pay = d.total_price - d.paid;
        const remaining_del = d.qty - d.delivered;
        if (d.current_status === 'cancelled') {
            html += `<div class="alert-chip danger"><i class="fas fa-times-circle"></i> ออเดอร์นี้ถูกยกเลิก</div>`;
        } else {
            if (remaining_del > 0 && d.current_status !== 'completed') {
                html += `<div class="alert-chip warn"><i class="fas fa-box"></i> ยังส่งไม่ครบ — คงเหลือ <strong>${remaining_del} ขวด</strong></div>`;
            }
            if (remaining_pay > 0.005) {
                html += `<div class="alert-chip danger"><i class="fas fa-money-bill-wave"></i> ยอดค้างชำระ <strong>฿${fmt(remaining_pay)}</strong></div>`;
            }
            if (remaining_del <= 0 && remaining_pay <= 0.005 && d.current_status === 'completed') {
                html += `<div class="alert-chip ok"><i class="fas fa-check-circle"></i> จัดส่งและชำระครบแล้ว</div>`;
            }
        }
    } else {
        // Request alerts
        if (d.current_status === 'rejected') {
            html += `<div class="alert-chip danger"><i class="fas fa-times-circle"></i> คำขอนี้ถูกปฏิเสธ</div>`;
        } else if (d.current_status === 'approved') {
            html += `<div class="alert-chip ok"><i class="fas fa-check-circle"></i> คำขออนุมัติแล้ว — มีออเดอร์ในระบบ</div>`;
        } else if (d.current_status === 'pending_admin') {
            html += `<div class="alert-chip warn"><i class="fas fa-clock"></i> รอ Admin ตรวจสอบและยืนยัน</div>`;
        }
    }

    // ── Basic info grid ───────────────────────────────────────────────────────
    html += '<div class="detail-section-label">📋 ข้อมูลคำสั่ง</div>';
    html += '<div class="detail-grid">';
    html += cell('วันที่', fmtDate(d.display_date));
    html += cell('จำนวนที่สั่ง', d.qty + ' ขวด');
    if (d.bottle_type) html += cell('ประเภทสินค้า', '💧 ' + d.bottle_type, 'full');
    if (d.clean_note)  html += cell('หมายเหตุ', '"' + d.clean_note + '"', 'full');
    html += '</div>';

    // ── Order-only sections ───────────────────────────────────────────────────
    if (d.record_type === 'order' && d.total_price > 0) {

        // Delivery progress
        html += '<div class="detail-section-label">📦 การจัดส่ง</div>';
        html += '<div class="detail-grid">';
        const del_pct = d.qty > 0 ? Math.min(100, Math.round(d.delivered / d.qty * 100)) : 0;
        html += `<div class="detail-cell full">
            <div class="dc-label">ส่งแล้ว</div>
            <div class="dc-value" style="color:${d.delivered >= d.qty ? '#27ae60' : '#e67e22'};">
                ${d.delivered} / ${d.qty} ขวด
            </div>
            <div class="mini-bar-track">
                <div class="mini-bar-fill"
                     style="width:${del_pct}%;
                            background:${d.delivered >= d.qty ? '#27ae60' : '#e67e22'};"></div>
            </div>
        </div>`;
        html += '</div>';

        // Payment summary
        const remaining_pay = d.total_price - d.paid;
        const pay_pct = d.total_price > 0 ? Math.min(100, Math.round(d.paid / d.total_price * 100)) : 0;
        const pay_status_label = STATUS_TH[d.payment_status] || d.payment_status;
        const pay_color = STATUS_COLOR[d.payment_status] || '#999';

        html += '<div class="detail-section-label">💰 การชำระเงิน</div>';
        html += '<div class="detail-grid">';
        html += cell('ราคารวม', '฿' + fmt(d.total_price));
        html += cell('ค่ามัดจำ', '฿' + fmt(d.deposit_fee));
        html += `<div class="detail-cell full">
            <div class="dc-label">ชำระแล้ว</div>
            <div class="dc-value" style="color:${pay_color};">
                ฿${fmt(d.paid)} <span style="font-size:0.72rem; font-weight:400; opacity:0.8;">(${pay_status_label})</span>
            </div>
            <div class="mini-bar-track">
                <div class="mini-bar-fill" style="width:${pay_pct}%; background:${pay_color};"></div>
            </div>
        </div>`;
        if (remaining_pay > 0.005) {
            html += `<div class="detail-cell red full">
                <div class="dc-label">ยังค้างชำระ</div>
                <div class="dc-value">฿${fmt(remaining_pay)}</div>
            </div>`;
        }
        html += '</div>';

        // Transaction history
        if (d.txns && d.txns.length > 0) {
            html += '<div class="detail-section-label">🔄 ประวัติการรับสินค้า</div>';
            d.txns.forEach((t, i) => {
                html += `<div class="txn-item">
                    <div class="txn-dot"></div>
                    <div>
                        <div style="font-weight:600; color:var(--navy);">ครั้งที่ ${i+1} — รับ ${t.qty_delivered} ขวด</div>
                        <div class="txn-date">${fmtDateTime(t.confirmed_at)}</div>
                        ${t.qty_empty_collected > 0 ? `<div style="font-size:0.72rem; color:#7f8c8d; margin-top:1px;">คืนขวดเปล่า ${t.qty_empty_collected} ขวด</div>` : ''}
                        ${t.note ? `<div style="font-size:0.72rem; color:#7f8c8d; font-style:italic; margin-top:1px;">"${t.note}"</div>` : ''}
                    </div>
                    <div style="text-align:right; font-weight:700; color:#27ae60; white-space:nowrap;">
                        ฿${fmt(t.amount_collected)}
                    </div>
                </div>`;
            });
            const totDel = d.txns.reduce((s,t) => s + parseInt(t.qty_delivered||0), 0);
            const totAmt = d.txns.reduce((s,t) => s + parseFloat(t.amount_collected||0), 0);
            html += `<div style="background:#f0f9ff; border-radius:8px; padding:8px 12px;
                          font-size:0.78rem; color:#1b2a4a; display:flex;
                          justify-content:space-between; margin-top:4px;">
                <span>รวม: ส่ง <strong>${totDel}</strong> ขวด</span>
                <span>เก็บเงิน <strong style="color:#27ae60;">฿${fmt(totAmt)}</strong></span>
            </div>`;
        }
    }

    return html;
}

function cell(label, value, extra = '') {
    return `<div class="detail-cell ${extra}">
        <div class="dc-label">${label}</div>
        <div class="dc-value">${value}</div>
    </div>`;
}

// Swipe down to close
let startY = 0;
document.querySelector('.detail-sheet').addEventListener('touchstart', e => {
    startY = e.touches[0].clientY;
}, {passive: true});
document.querySelector('.detail-sheet').addEventListener('touchend', e => {
    if (e.changedTouches[0].clientY - startY > 80) closeDetailBtn();
}, {passive: true});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetailBtn(); });
</script>

<?php require_once '../includes/footer.php'; ?>