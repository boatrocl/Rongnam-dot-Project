<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Portal — ระบบจัดการน้ำดื่ม</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --navy:       #1B2A4A;
            --blue:       #2471A3;
            --green:      #27ae60;
            --green-dark: #1e8449;
            --amber:      #f39c12;
            --red:        #e74c3c;
            --grey:       #7f8c8d;
            --light:      #f4f6f8;
            --white:      #ffffff;
            --card-shadow: 0 2px 12px rgba(0,0,0,0.08);
            --radius:     12px;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            background: var(--light);
            color: #2c3e50;
            padding-bottom: 80px; /* space for bottom nav */
        }

        /* ── Top bar ─────────────────────────────────────────────── */
        .topbar {
            background: linear-gradient(135deg, var(--navy), var(--blue));
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .topbar-left { display: flex; align-items: center; gap: 10px; }
        .topbar-left i { font-size: 1.4rem; color: #AED6F1; }
        .topbar-title {
            font-size: 1rem;
            font-weight: bold;
            color: #fff;
            line-height: 1.2;
        }
        .topbar-subtitle { font-size: 0.72rem; color: #AED6F1; }
        .topbar-logout {
            background: rgba(231,76,60,0.85);
            color: #fff;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .topbar-logout:hover { background: var(--red); }

        /* ── Main container ──────────────────────────────────────── */
        .container {
            padding: 16px;
            max-width: 640px;
            margin: 0 auto;
        }

        /* ── Cards ───────────────────────────────────────────────── */
        .job-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 14px;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--green);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .job-card:active { transform: scale(0.99); }

        /* ── Buttons ─────────────────────────────────────────────── */
        .btn-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            padding: 13px 16px;
            text-align: center;
            background: var(--green);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: bold;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-action:active { transform: scale(0.98); }
        .btn-action:hover  { background: var(--green-dark); }

        /* ── Status badges ───────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: bold;
        }
        .badge-pending    { background: #FEF9E7; color: #B7950B; }
        .badge-processing { background: #D6EAF8; color: #1A5276; }
        .badge-completed  { background: #D5F5E3; color: #1E8449; }
        .badge-cancelled  { background: #FADBD8; color: #C0392B; }
        .badge-partial    { background: #FDF2E9; color: #D35400; }
        .badge-unpaid     { background: #FADBD8; color: #C0392B; }
        .badge-paid       { background: #D5F5E3; color: #1E8449; }

        /* ── Progress bar ────────────────────────────────────────── */
        .progress-wrap { margin: 6px 0; }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.78rem;
            color: var(--grey);
            margin-bottom: 3px;
        }
        .progress-track {
            background: #eee;
            border-radius: 6px;
            height: 7px;
            overflow: hidden;
        }
        .progress-fill {
            height: 7px;
            border-radius: 6px;
            transition: width 0.4s ease;
        }

        /* ── Info rows ───────────────────────────────────────────── */
        .info-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            padding: 5px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row i { width: 16px; color: var(--blue); flex-shrink: 0; }

        /* ── Bottom navigation ───────────────────────────────────── */
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: var(--white);
            border-top: 1px solid #e0e0e0;
            display: flex;
            z-index: 100;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.08);
        }
        .bottom-nav a {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px 0;
            text-decoration: none;
            color: var(--grey);
            font-size: 0.7rem;
            gap: 3px;
            transition: color 0.2s;
        }
        .bottom-nav a i { font-size: 1.2rem; }
        .bottom-nav a.active { color: var(--blue); }
        .bottom-nav a:active { background: #f4f6f8; }

        /* ── Section header ──────────────────────────────────────── */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .section-header h2 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--navy);
        }

        /* ── Empty state ─────────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--grey);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 12px; opacity: 0.4; }
        .empty-state p { margin: 0; font-size: 0.95rem; }

        /* ── Alerts ──────────────────────────────────────────────── */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 14px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #d5f5e3; border: 1px solid #27ae60; color: #1e8449; }
        .alert-error   { background: #fadbd8; border: 1px solid #e74c3c; color: #c0392b; }

        /* ── Forms ───────────────────────────────────────────────── */
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            font-size: 0.83rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #ddd;
            border-radius: 9px;
            font-size: 1rem;
            font-family: inherit;
            background: #fff;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--blue);
        }

        /* ── History entries ─────────────────────────────────────── */
        .txn-entry {
            border-left: 3px solid var(--blue);
            padding: 10px 14px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        .txn-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
            margin-top: 6px;
        }
        .txn-stat { font-size: 0.8rem; }
        .txn-stat span { color: var(--grey); display: block; }
        .txn-stat strong { font-size: 0.95rem; }
    </style>
</head>
<body>

<!-- Top bar -->
<nav class="topbar">
    <div class="topbar-left">
        <i class="fas fa-truck"></i>
        <div>
            <div class="topbar-title">Driver Portal</div>
            <div class="topbar-subtitle">
                <i class="fas fa-user" style="font-size:0.65rem;"></i>
                <?php echo htmlspecialchars($_SESSION['name'] ?? 'Driver'); ?>
            </div>
        </div>
    </div>
    <a href="../logout.php" class="topbar-logout">
        <i class="fas fa-sign-out-alt"></i>
        <span>ออก</span>
    </a>
</nav>

<!-- Bottom navigation -->
<nav class="bottom-nav">
    <a href="index.php" class="<?php echo $current_page === 'index.php' ? 'active' : ''; ?>">
        <i class="fas fa-list-check"></i>
        งานของฉัน
    </a>
    <a href="history.php" class="<?php echo $current_page === 'history.php' ? 'active' : ''; ?>">
        <i class="fas fa-history"></i>
        ประวัติ
    </a>
</nav>