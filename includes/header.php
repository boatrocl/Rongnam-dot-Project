<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$current = basename($_SERVER['PHP_SELF']);

// Nav items definition — easy to add/remove
$nav_items = [
    ['file' => 'index.php',            'icon' => 'fas fa-tachometer-alt', 'label' => 'แดชบอร์ด'],
    ['file' => 'orders.php',           'icon' => 'fas fa-shopping-cart',  'label' => 'ออเดอร์'],
    ['file' => 'pending_orders.php', 'icon' => 'fas fa-clock',          'label' => 'คำขอรอยืนยัน'],
    ['file' => 'customers.php',        'icon' => 'fas fa-users',          'label' => 'ลูกค้า'],
    ['file' => 'drivers.php',          'icon' => 'fas fa-truck',          'label' => 'พนักงานขับ'],
    ['file' => 'locations.php',        'icon' => 'fas fa-map-marker-alt', 'label' => 'สถานที่'],
    ['file' => 'routes.php',           'icon' => 'fas fa-route',          'label' => 'เส้นทาง'],
    ['file' => 'stock.php',            'icon' => 'fas fa-boxes',          'label' => 'สต็อก'],
    ['file' => 'reports.php',          'icon' => 'fas fa-chart-bar',      'label' => 'รายงาน'],
];

// Count pending requests for badge
try {
    global $pdo;
    $pending_count = $pdo
        ? (int)$pdo->query("SELECT COUNT(*) FROM order_request WHERE status='pending_admin'")->fetchColumn()
        : 0;
} catch (Exception $e) {
    $pending_count = 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — ระบบจัดการน้ำดื่ม</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ── Reset & base ──────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:        #1B2A4A;
            --navy-light:  #243555;
            --blue:        #2471A3;
            --blue-light:  #2E86C1;
            --foam:        #1ABC9C;
            --success:     #27ae60;
            --warning:     #f39c12;
            --danger:      #e74c3c;
            --grey:        #7f8c8d;
            --light:       #F4F6F8;
            --white:       #FFFFFF;
            --text:        #2c3e50;
            --border:      #E8ECF0;
            --sidebar-w:   240px;
            --topbar-h:    56px;
            --shadow:      0 2px 12px rgba(27,42,74,0.10);
            --radius:      10px;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--light);
            color: var(--text);
            min-height: 100vh;
        }

        /* ── Top bar ───────────────────────────────────────────────── */
        .topbar {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--topbar-h);
            background: linear-gradient(90deg, var(--navy) 0%, var(--blue) 100%);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px 0 16px;
            z-index: 300;
            box-shadow: 0 2px 10px rgba(27,42,74,0.25);
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        /* Hamburger — mobile only */
        .menu-toggle {
            display: none;
            background: rgba(255,255,255,0.1);
            border: none;
            color: #fff;
            width: 36px; height: 36px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            align-items: center;
            justify-content: center;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 9px;
            text-decoration: none;
        }
        .topbar-brand .brand-icon {
            width: 32px; height: 32px;
            background: rgba(255,255,255,0.15);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: #7fdbff;
            font-size: 1rem;
        }
        .topbar-brand h1 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.1;
            white-space: nowrap;
        }
        .topbar-brand span {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.6);
            display: block;
            font-weight: 400;
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .admin-pill {
            display: flex;
            align-items: center;
            gap: 7px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            color: #fff;
            font-size: 0.82rem;
        }
        .admin-pill i { color: #7fdbff; font-size: 0.75rem; }
        .logout-btn {
            background: rgba(231,76,60,0.8);
            border: 1px solid rgba(231,76,60,0.5);
            color: #fff;
            padding: 6px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
            white-space: nowrap;
        }
        .logout-btn:hover { background: var(--danger); }

        /* ── Sidebar ───────────────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: var(--topbar-h);
            left: 0;
            width: var(--sidebar-w);
            height: calc(100vh - var(--topbar-h));
            background: var(--navy);
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 200;
            transition: transform 0.25s ease;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.1) transparent;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

        .sidebar-section {
            padding: 18px 12px 8px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.35);
            text-transform: uppercase;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 0.875rem;
            border-radius: 8px;
            margin: 2px 8px;
            position: relative;
            transition: background 0.15s, color 0.15s;
        }
        .nav-item i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .nav-item.active {
            background: linear-gradient(90deg, var(--blue-light), var(--blue));
            color: #fff;
            box-shadow: 0 2px 8px rgba(36,113,163,0.4);
        }
        .nav-item.active i { color: #7fdbff; }

        /* Notification badge on nav item */
        .nav-badge {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--danger);
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        .sidebar-divider {
            height: 1px;
            background: rgba(255,255,255,0.07);
            margin: 8px 16px;
        }

        /* ── Main content area ─────────────────────────────────────── */
        .main-wrap {
            margin-left: var(--sidebar-w);
            margin-top: var(--topbar-h);
            min-height: calc(100vh - var(--topbar-h));
            padding: 24px;
        }

        /* ── Page-level components (used by all admin pages) ───────── */
        .dashboard { max-width: 1300px; margin: 0 auto; }

        /* Stat cards grid */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            border-top: 3px solid var(--blue);
            transition: transform 0.15s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card i { font-size: 1.8rem; margin-bottom: 8px; }
        .stat-card h3 { font-size: 0.85rem; color: var(--grey); font-weight: 500; margin-bottom: 6px; }
        .stat-number  { font-size: 1.8rem; font-weight: 700; color: var(--navy); }

        /* Tables */
        .table-container {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow-x: auto;
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: var(--navy);
            color: #fff;
            padding: 10px 14px;
            text-align: left;
            font-size: 0.82rem;
            font-weight: 600;
            white-space: nowrap;
        }
        th:first-child { border-radius: 6px 0 0 6px; }
        th:last-child  { border-radius: 0 6px 6px 0; }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #F8FAFC; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 8px 16px;
            border-radius: 7px;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: inherit;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.15s, transform 0.1s;
            white-space: nowrap;
        }
        .btn:active { transform: scale(0.97); }
        .btn-primary { background: var(--blue);    color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-danger  { background: var(--danger);  color: #fff; }
        .btn-ghost   { background: var(--light);   color: var(--text); border: 1px solid var(--border); }
        .btn:hover   { opacity: 0.88; }
        .btn-sm      { padding: 5px 12px; font-size: 0.78rem; border-radius: 6px; }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending    { background: #FEF3CD; color: #856404; }
        .status-processing { background: #CCE5FF; color: #004085; }
        .status-completed  { background: #D4EDDA; color: #155724; }
        .status-cancelled  { background: #F8D7DA; color: #721C24; }
        .status-paid       { background: #D4EDDA; color: #155724; }
        .status-unpaid     { background: #F8D7DA; color: #721C24; }
        .status-partial    { background: #FFF3CD; color: #856404; }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #D4EDDA; border: 1px solid #C3E6CB; color: #155724; }
        .alert-error   { background: #F8D7DA; border: 1px solid #F5C6CB; color: #721C24; }
        .alert-warning { background: #FFF3CD; border: 1px solid #FFEEBA; color: #856404; }

        /* Search bar */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }
        .search-bar input {
            flex: 1;
            padding: 9px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .search-bar input:focus {
            outline: none;
            border-color: var(--blue);
        }

        /* Form groups */
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 5px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 9px 13px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
            background: var(--white);
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(36,113,163,0.1);
        }

        /* Modal overlay */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(27,42,74,0.55);
            z-index: 400;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: var(--white);
            border-radius: 14px;
            padding: 28px;
            width: 460px;
            max-width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(27,42,74,0.25);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .modal-header h3 { margin: 0; color: var(--navy); font-size: 1.05rem; }

        /* Report table */
        .report-table { width: 100%; border-collapse: collapse; }
        .report-table th {
            background: var(--navy);
            color: #fff;
            padding: 8px 12px;
            font-size: 0.82rem;
        }
        .report-table td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 0.85rem;
        }

        /* Page header row */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .page-header h2 { margin: 0; font-size: 1.2rem; color: var(--navy); }
        .page-header p  { margin: 4px 0 0; color: var(--grey); font-size: 0.85rem; }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 150;
        }

        /* ── Mobile ────────────────────────────────────────────────── */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .sidebar-overlay.open {
                display: block;
            }
            .menu-toggle {
                display: flex;
            }
            .main-wrap {
                margin-left: 0;
                padding: 16px;
            }
            .admin-pill { display: none; }
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 400px) {
            .stats-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ── Top bar ────────────────────────────────────────────────── -->
<header class="topbar">
    <div class="topbar-left">
        <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="/water_management/admin/index.php" class="topbar-brand">
            <div class="brand-icon"><i class="fas fa-water"></i></div>
            <div>
                <h1>WaterAdmin</h1>
                <span>ระบบจัดการน้ำดื่ม</span>
            </div>
        </a>
    </div>
    <div class="topbar-right">
        <div class="admin-pill">
            <i class="fas fa-user-shield"></i>
            <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>
        </div>
        <a href="/water_management/logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>ออก</span>
        </a>
    </div>
</header>

<!-- ── Sidebar ────────────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">

    <div class="sidebar-section">เมนูหลัก</div>

    <?php foreach ($nav_items as $item):
        $is_active = ($current === $item['file']);
        $is_pending_page = $item['file'] === 'pending_requests.php';
    ?>
    <a href="/water_management/admin/<?php echo $item['file']; ?>"
       class="nav-item <?php echo $is_active ? 'active' : ''; ?>">
        <i class="<?php echo $item['icon']; ?>"></i>
        <?php echo $item['label']; ?>
        <?php if ($is_pending_page && $pending_count > 0): ?>
            <span class="nav-badge"><?php echo $pending_count; ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="sidebar-divider"></div>
    <div class="sidebar-section">ระบบ</div>

    <a href="/water_management/logout.php" class="nav-item"
       style="color:rgba(231,76,60,0.8);">
        <i class="fas fa-sign-out-alt"></i>
        ออกจากระบบ
    </a>

</aside>

<!-- ── Main content wrapper ───────────────────────────────────── -->
<main class="main-wrap">
    <div class="dashboard">

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}
// Close sidebar on nav click (mobile)
document.querySelectorAll('.nav-item').forEach(function(item) {
    item.addEventListener('click', function() {
        if (window.innerWidth <= 768) closeSidebar();
    });
});
</script>