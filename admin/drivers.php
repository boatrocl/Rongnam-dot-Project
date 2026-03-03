<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';
require_once '../includes/header.php';

// ── POST: toggle active status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    $pdo->prepare("UPDATE driver SET is_active = NOT is_active WHERE DID = ?")
        ->execute([$_POST['did']]);
    header('Location: drivers.php?success=updated');
    exit;
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$drivers = $pdo->query("
    SELECT d.*,
           COUNT(DISTINCT o.order_id)  AS total_orders,
           SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
           SUM(CASE WHEN DATE(o.scheduled_date) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
           MAX(o.scheduled_date) AS last_order_date
    FROM driver d
    LEFT JOIN `order` o ON d.DID = o.DID
    GROUP BY d.DID
    ORDER BY d.DFname ASC
")->fetchAll();

$total_drivers  = count($drivers);
$active_drivers = count(array_filter($drivers, fn($d) => $d['is_active']));
$driving_today  = (int)$pdo->query("
    SELECT COUNT(DISTINCT DID) FROM `order`
    WHERE DATE(scheduled_date) = CURDATE()
")->fetchColumn();
$total_orders   = (int)$pdo->query("SELECT COUNT(*) FROM `order`")->fetchColumn();
?>

<style>
/* ── Driver card grid ──────────────────────────────────────────────────── */
.driver-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.driver-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    transition: transform 0.15s, box-shadow 0.15s;
    border-top: 4px solid var(--blue);
    cursor: pointer;
    text-decoration: none;
    display: block;
    color: inherit;
}
.driver-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 28px rgba(27,42,74,0.14);
}
.driver-card.inactive { border-top-color: var(--grey); opacity: 0.75; }

.driver-card-header {
    padding: 18px 18px 12px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.driver-avatar {
    width: 48px; height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--blue), var(--foam));
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.2rem; font-weight: 700;
    flex-shrink: 0;
}
.driver-avatar.inactive {
    background: linear-gradient(135deg, #aaa, #ccc);
}
.driver-name {
    font-size: 1rem; font-weight: 700; color: var(--navy);
    margin-bottom: 2px;
}
.driver-username {
    font-size: 0.75rem; color: var(--grey);
}

.driver-card-body {
    padding: 0 18px 14px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.driver-stat {
    background: var(--light);
    border-radius: 8px;
    padding: 8px 10px;
}
.driver-stat .ds-label { font-size: 0.67rem; color: var(--grey); }
.driver-stat .ds-val   { font-size: 1rem; font-weight: 700; color: var(--navy); }

.driver-card-footer {
    padding: 10px 18px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #FAFBFC;
}

/* ── View toggle ───────────────────────────────────────────────────────── */
.view-toggle {
    display: flex;
    gap: 4px;
    background: var(--light);
    padding: 3px;
    border-radius: 8px;
}
.view-btn {
    padding: 5px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.82rem;
    background: transparent;
    color: var(--grey);
    transition: background 0.15s, color 0.15s;
}
.view-btn.active {
    background: var(--white);
    color: var(--navy);
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}

/* ── Table view adjustments ────────────────────────────────────────────── */
#tableView { display: none; }
</style>

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h2>พนักงานขับรถ</h2>
        <p>จัดการและดูข้อมูลพนักงานขับทั้งหมด</p>
    </div>
    <a href="add_driver.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> เพิ่มพนักงานขับใหม่
    </a>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> อัปเดตข้อมูลเรียบร้อยแล้ว
</div>
<?php endif; ?>

<!-- ── Stats ───────────────────────────────────────────────────────────────── -->
<div class="stats-container">
    <div class="stat-card" style="border-top-color: var(--blue);">
        <i class="fas fa-id-card" style="color:var(--blue);"></i>
        <h3>พนักงานทั้งหมด</h3>
        <div class="stat-number"><?php echo $total_drivers; ?></div>
    </div>
    <div class="stat-card" style="border-top-color: var(--success);">
        <i class="fas fa-user-check" style="color:var(--success);"></i>
        <h3>ใช้งานอยู่</h3>
        <div class="stat-number" style="color:var(--success);"><?php echo $active_drivers; ?></div>
    </div>
    <div class="stat-card" style="border-top-color: var(--warning);">
        <i class="fas fa-truck" style="color:var(--warning);"></i>
        <h3>ออกส่งวันนี้</h3>
        <div class="stat-number" style="color:var(--warning);"><?php echo $driving_today; ?></div>
    </div>
    <div class="stat-card" style="border-top-color: var(--foam);">
        <i class="fas fa-shopping-cart" style="color:var(--foam);"></i>
        <h3>ออเดอร์ทั้งหมด</h3>
        <div class="stat-number"><?php echo number_format($total_orders); ?></div>
    </div>
</div>

<!-- ── Controls row ────────────────────────────────────────────────────────── -->
<div style="display:flex; justify-content:space-between; align-items:center;
            margin-bottom:16px; flex-wrap:wrap; gap:10px;">
    <input type="text" id="searchInput" placeholder="ค้นหาชื่อ, username, เบอร์..."
           oninput="filterDrivers()"
           style="padding:8px 14px; border:1px solid var(--border); border-radius:8px;
                  font-size:0.88rem; width:260px;">
    <div style="display:flex; align-items:center; gap:10px;">
        <label style="display:flex; align-items:center; gap:6px; font-size:0.85rem;
                      color:var(--grey); cursor:pointer;">
            <input type="checkbox" id="showInactive" onchange="filterDrivers()"> แสดงที่ปิดใช้งาน
        </label>
        <div class="view-toggle">
            <button class="view-btn active" id="btnCard" onclick="setView('card')">
                <i class="fas fa-th-large"></i> การ์ด
            </button>
            <button class="view-btn" id="btnTable" onclick="setView('table')">
                <i class="fas fa-list"></i> ตาราง
            </button>
        </div>
    </div>
</div>

<!-- ── Card view ───────────────────────────────────────────────────────────── -->
<div id="cardView" class="driver-grid">
    <?php foreach($drivers as $d):
        $initials  = strtoupper(substr($d['DFname'], 0, 1) . substr($d['DLname'], 0, 1));
        $is_active = (bool)$d['is_active'];
        $completion_rate = $d['total_orders'] > 0
            ? round($d['completed_orders'] / $d['total_orders'] * 100) : 0;
    ?>
    <div class="driver-card <?php echo $is_active ? '' : 'inactive'; ?>"
         data-name="<?php echo strtolower($d['DFname'].' '.$d['DLname']); ?>"
         data-user="<?php echo strtolower($d['Username'] ?? ''); ?>"
         data-tel="<?php echo $d['tel'] ?? ''; ?>"
         data-active="<?php echo $is_active ? '1' : '0'; ?>"
         onclick="window.location='driver_detail.php?id=<?php echo urlencode($d['DID']); ?>'">

        <div class="driver-card-header">
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <div class="driver-avatar <?php echo $is_active ? '' : 'inactive'; ?>">
                    <?php echo $initials; ?>
                </div>
                <div>
                    <div class="driver-name">
                        <?php echo htmlspecialchars($d['DFname'].' '.$d['DLname']); ?>
                    </div>
                    <div class="driver-username">
                        <i class="fas fa-at" style="font-size:0.65rem;"></i>
                        <?php echo htmlspecialchars($d['Username'] ?? '-'); ?>
                    </div>
                    <?php if ($d['tel']): ?>
                    <div style="font-size:0.75rem; color:var(--blue); margin-top:2px;">
                        <i class="fas fa-phone" style="font-size:0.65rem;"></i>
                        <?php echo htmlspecialchars($d['tel']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <span class="status-badge <?php echo $is_active ? 'status-completed' : 'status-cancelled'; ?>">
                <?php echo $is_active ? 'ใช้งาน' : 'ปิดใช้'; ?>
            </span>
        </div>

        <div class="driver-card-body">
            <div class="driver-stat">
                <div class="ds-label">ออเดอร์ทั้งหมด</div>
                <div class="ds-val"><?php echo number_format($d['total_orders']); ?></div>
            </div>
            <div class="driver-stat">
                <div class="ds-label">เสร็จสมบูรณ์</div>
                <div class="ds-val" style="color:var(--success);">
                    <?php echo number_format($d['completed_orders']); ?>
                </div>
            </div>
            <div class="driver-stat">
                <div class="ds-label">วันนี้</div>
                <div class="ds-val" style="color:<?php echo $d['today_orders'] > 0 ? 'var(--warning)' : 'var(--grey)'; ?>;">
                    <?php echo $d['today_orders']; ?> งาน
                </div>
            </div>
            <div class="driver-stat">
                <div class="ds-label">อัตราสำเร็จ</div>
                <div class="ds-val" style="color:<?php echo $completion_rate >= 80 ? 'var(--success)' : 'var(--warning)'; ?>;">
                    <?php echo $completion_rate; ?>%
                </div>
            </div>
        </div>

        <!-- Completion progress bar -->
        <div style="padding:0 18px 12px;">
            <div style="display:flex; justify-content:space-between;
                        font-size:0.7rem; color:var(--grey); margin-bottom:3px;">
                <span>อัตราสำเร็จ</span>
                <span><?php echo $completion_rate; ?>%</span>
            </div>
            <div style="background:#eee; border-radius:4px; height:5px; overflow:hidden;">
                <div style="height:5px; border-radius:4px; width:<?php echo $completion_rate; ?>%;
                            background: linear-gradient(90deg, var(--blue), var(--foam));
                            transition: width 0.4s;"></div>
            </div>
        </div>

        <div class="driver-card-footer" onclick="event.stopPropagation()">
            <span style="font-size:0.72rem; color:var(--grey);">
                <?php if ($d['last_order_date']): ?>
                    <i class="fas fa-clock"></i>
                    ล่าสุด: <?php echo date('d/m/Y', strtotime($d['last_order_date'])); ?>
                <?php else: ?>
                    ยังไม่มีออเดอร์
                <?php endif; ?>
            </span>
            <div style="display:flex; gap:6px;">
                <!-- Toggle active/inactive -->
                <form method="POST" style="margin:0;" onclick="event.stopPropagation()">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="did"    value="<?php echo $d['DID']; ?>">
                    <button type="submit"
                            class="btn btn-sm <?php echo $is_active ? 'btn-ghost' : 'btn-success'; ?>"
                            onclick="return confirm('<?php echo $is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>พนักงานขับนี้?')"
                            title="<?php echo $is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                        <i class="fas fa-<?php echo $is_active ? 'ban' : 'check'; ?>"></i>
                    </button>
                </form>
                <a href="driver_detail.php?id=<?php echo urlencode($d['DID']); ?>"
                   class="btn btn-primary btn-sm" onclick="event.stopPropagation()">
                    <i class="fas fa-eye"></i> ดูข้อมูล
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Empty state ─────────────────────────────────────────────────────────── -->
<div id="emptyState" style="display:none; text-align:center; padding:60px 20px; color:var(--grey);">
    <i class="fas fa-search" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:12px;"></i>
    <p>ไม่พบพนักงานขับที่ตรงกับการค้นหา</p>
</div>

<!-- ── Table view ──────────────────────────────────────────────────────────── -->
<div id="tableView" class="table-container" style="display:none; padding:0;">
    <table id="driversTable">
        <thead>
            <tr>
                <th>Driver ID</th>
                <th>ชื่อ-นามสกุล</th>
                <th>Username</th>
                <th>เบอร์โทร</th>
                <th>ออเดอร์</th>
                <th>สำเร็จ</th>
                <th>วันนี้</th>
                <th>สถานะ</th>
                <th>จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($drivers as $d):
                $is_active = (bool)$d['is_active'];
            ?>
            <tr data-name="<?php echo strtolower($d['DFname'].' '.$d['DLname']); ?>"
                data-user="<?php echo strtolower($d['Username'] ?? ''); ?>"
                data-tel="<?php echo $d['tel'] ?? ''; ?>"
                data-active="<?php echo $is_active ? '1' : '0'; ?>"
                style="cursor:pointer;"
                onclick="window.location='driver_detail.php?id=<?php echo urlencode($d['DID']); ?>'">
                <td style="font-family:monospace; font-size:0.82rem; font-weight:600;">
                    <?php echo htmlspecialchars($d['DID']); ?>
                </td>
                <td>
                    <div style="display:flex; align-items:center; gap:9px;">
                        <div style="width:32px; height:32px; border-radius:8px; flex-shrink:0;
                                    background: linear-gradient(135deg, var(--blue), var(--foam));
                                    display:flex; align-items:center; justify-content:center;
                                    color:#fff; font-size:0.75rem; font-weight:700;">
                            <?php echo strtoupper(substr($d['DFname'],0,1).substr($d['DLname'],0,1)); ?>
                        </div>
                        <strong><?php echo htmlspecialchars($d['DFname'].' '.$d['DLname']); ?></strong>
                    </div>
                </td>
                <td style="color:var(--grey); font-size:0.85rem;">
                    <?php echo htmlspecialchars($d['Username'] ?? '-'); ?>
                </td>
                <td><?php echo htmlspecialchars($d['tel'] ?? '-'); ?></td>
                <td style="text-align:center;"><?php echo $d['total_orders']; ?></td>
                <td style="text-align:center; color:var(--success); font-weight:600;">
                    <?php echo $d['completed_orders']; ?>
                </td>
                <td style="text-align:center;">
                    <?php if ($d['today_orders'] > 0): ?>
                        <span class="status-badge status-processing"><?php echo $d['today_orders']; ?> งาน</span>
                    <?php else: ?>
                        <span style="color:var(--grey); font-size:0.8rem;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-badge <?php echo $is_active ? 'status-completed' : 'status-cancelled'; ?>">
                        <?php echo $is_active ? 'ใช้งาน' : 'ปิดใช้'; ?>
                    </span>
                </td>
                <td onclick="event.stopPropagation()" style="white-space:nowrap;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="did"    value="<?php echo $d['DID']; ?>">
                        <button type="submit"
                                class="btn btn-sm <?php echo $is_active ? 'btn-ghost' : 'btn-success'; ?>"
                                onclick="return confirm('<?php echo $is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>?')">
                            <i class="fas fa-<?php echo $is_active ? 'ban' : 'check'; ?>"></i>
                        </button>
                    </form>
                    <a href="driver_detail.php?id=<?php echo urlencode($d['DID']); ?>"
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-eye"></i> ดูข้อมูล
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
// ── View toggle ───────────────────────────────────────────────────────────────
function setView(v) {
    document.getElementById('cardView').style.display  = v === 'card'  ? 'grid' : 'none';
    document.getElementById('tableView').style.display = v === 'table' ? 'block': 'none';
    document.getElementById('btnCard').classList.toggle('active',  v === 'card');
    document.getElementById('btnTable').classList.toggle('active', v === 'table');
    localStorage.setItem('driverView', v);
}

// Restore last view preference
(function() {
    const saved = localStorage.getItem('driverView');
    if (saved === 'table') setView('table');
})();

// ── Filter / search ────────────────────────────────────────────────────────────
function filterDrivers() {
    const q          = document.getElementById('searchInput').value.toLowerCase();
    const showInactive = document.getElementById('showInactive').checked;

    // Filter cards
    const cards = document.querySelectorAll('#cardView .driver-card');
    let visibleCards = 0;
    cards.forEach(card => {
        const matchSearch = !q ||
            card.dataset.name.includes(q) ||
            card.dataset.user.includes(q) ||
            card.dataset.tel.includes(q);
        const matchActive = showInactive || card.dataset.active === '1';
        const show = matchSearch && matchActive;
        card.style.display = show ? '' : 'none';
        if (show) visibleCards++;
    });

    // Filter table rows
    const rows = document.querySelectorAll('#driversTable tbody tr');
    rows.forEach(row => {
        const matchSearch = !q ||
            row.dataset.name.includes(q) ||
            row.dataset.user.includes(q) ||
            row.dataset.tel.includes(q);
        const matchActive = showInactive || row.dataset.active === '1';
        row.style.display = (matchSearch && matchActive) ? '' : 'none';
    });

    document.getElementById('emptyState').style.display = visibleCards === 0 ? 'block' : 'none';
}

// Init: hide inactive by default
filterDrivers();
</script>

<?php require_once '../includes/footer.php'; ?>