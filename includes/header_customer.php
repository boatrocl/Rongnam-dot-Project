<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>WaterDrop — Customer Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

        :root {
            --ocean:      #0A4D68;
            --wave:       #088395;
            --foam:       #05BFDB;
            --sky:        #00FFCA;
            --light:      #F0FAFA;
            --white:      #FFFFFF;
            --text:       #1a2f38;
            --muted:      #6b8f9a;
            --danger:     #e74c3c;
            --warning:    #f39c12;
            --success:    #27ae60;
            --card-shadow: 0 4px 20px rgba(10,77,104,0.10);
            --radius:     16px;
            --radius-sm:  10px;
        }

        body {
            font-family: 'Prompt', sans-serif;
            background: var(--light);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 80px;
        }

        /* ── Top hero bar ──────────────────────────────────────────── */
        .topbar {
            background: linear-gradient(135deg, var(--ocean) 0%, var(--wave) 60%, var(--foam) 100%);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 200;
            box-shadow: 0 4px 20px rgba(10,77,104,0.25);
        }
        .topbar-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            max-width: 900px;
            margin: 0 auto;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .topbar-brand .drop-icon {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: var(--sky);
        }
        .topbar-brand h1 {
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.1;
        }
        .topbar-brand span {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.7);
            font-weight: 300;
            display: block;
        }
        .topbar-logout {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            text-decoration: none;
            font-family: 'Prompt', sans-serif;
            display: flex; align-items: center; gap: 6px;
            transition: background 0.2s;
        }
        .topbar-logout:hover { background: rgba(255,255,255,0.25); }

        /* ── Page container ────────────────────────────────────────── */
        .page {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px 16px;
        }

        /* ── Bottom nav ────────────────────────────────────────────── */
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: var(--white);
            border-top: 1px solid rgba(10,77,104,0.1);
            display: flex;
            z-index: 200;
            box-shadow: 0 -4px 20px rgba(10,77,104,0.08);
        }
        .bottom-nav a {
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 10px 0 8px;
            text-decoration: none;
            color: var(--muted);
            font-size: 0.65rem;
            font-family: 'Prompt', sans-serif;
            gap: 3px;
            transition: color 0.2s;
        }
        .bottom-nav a i { font-size: 1.2rem; }
        .bottom-nav a.active { color: var(--wave); }
        .bottom-nav a.active i {
            background: linear-gradient(135deg, var(--wave), var(--foam));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* ── Cards ─────────────────────────────────────────────────── */
        .card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 16px;
        }
        .card-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--ocean);
            margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .card-title i { color: var(--foam); }

        /* ── Stat chips ────────────────────────────────────────────── */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        .stat-chip {
            background: var(--white);
            border-radius: var(--radius-sm);
            padding: 14px 10px;
            text-align: center;
            box-shadow: var(--card-shadow);
            border-top: 3px solid var(--foam);
        }
        .stat-chip .val {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ocean);
            line-height: 1;
        }
        .stat-chip .lbl {
            font-size: 0.65rem;
            color: var(--muted);
            margin-top: 4px;
            line-height: 1.3;
        }

        /* ── Buttons ────────────────────────────────────────────────── */
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px;
            padding: 11px 20px;
            border-radius: 10px;
            font-family: 'Prompt', sans-serif;
            font-size: 0.9rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.1s, opacity 0.2s;
        }
        .btn:active { transform: scale(0.97); }
        .btn-primary {
            background: linear-gradient(135deg, var(--wave), var(--foam));
            color: var(--white);
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-ghost {
            background: var(--light);
            color: var(--wave);
            border: 1px solid rgba(8,131,149,0.3);
        }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-block { width: 100%; }
        .btn-sm { padding: 7px 14px; font-size: 0.8rem; border-radius: 8px; }

        /* ── Badges ─────────────────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-pending_admin { background: #FEF3CD; color: #856404; }
        .badge-approved      { background: #D1ECF1; color: #0C5460; }
        .badge-rejected      { background: #F8D7DA; color: #721C24; }
        .badge-completed     { background: #D4EDDA; color: #155724; }
        .badge-pending       { background: #FFF3CD; color: #856404; }
        .badge-processing    { background: #CCE5FF; color: #004085; }
        .badge-cancelled     { background: #F8D7DA; color: #721C24; }
        .badge-paid          { background: #D4EDDA; color: #155724; }
        .badge-unpaid        { background: #F8D7DA; color: #721C24; }
        .badge-partial       { background: #FFF3CD; color: #856404; }

        /* ── Location cards ─────────────────────────────────────────── */
        .loc-card {
            border: 1px solid rgba(10,77,104,0.1);
            border-radius: var(--radius-sm);
            padding: 14px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            background: #FAFEFE;
            transition: border-color 0.2s;
        }
        .loc-card:hover { border-color: var(--foam); }
        .loc-info h4 { font-size: 0.9rem; font-weight: 600; color: var(--ocean); margin-bottom: 2px; }
        .loc-info p  { font-size: 0.75rem; color: var(--muted); }

        /* ── History rows ────────────────────────────────────────────── */
        .history-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(10,77,104,0.06);
            gap: 10px;
        }
        .history-row:last-child { border-bottom: none; }
        .history-info { flex: 1; min-width: 0; }
        .history-info h4 {
            font-size: 0.85rem; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .history-info p { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }

        /* ── Alerts ──────────────────────────────────────────────────── */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 14px;
            font-size: 0.88rem;
            display: flex; align-items: center; gap: 8px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* ── Modal ───────────────────────────────────────────────────── */
        .modal-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(10,77,104,0.5);
            z-index: 500;
            align-items: flex-end;
            justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal-sheet {
            background: var(--white);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 28px 20px 40px;
            width: 100%;
            max-width: 520px;
            max-height: 92vh;
            overflow-y: auto;
            animation: slideUp 0.25s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(100%); }
            to   { transform: translateY(0); }
        }
        .modal-handle {
            width: 40px; height: 4px;
            background: #ddd; border-radius: 2px;
            margin: -10px auto 20px;
        }
        .modal-title {
            font-size: 1.1rem; font-weight: 700;
            color: var(--ocean); margin-bottom: 18px;
        }

        /* ── Form fields ─────────────────────────────────────────────── */
        .field { margin-bottom: 14px; }
        .field label {
            display: block;
            font-size: 0.8rem; font-weight: 600;
            color: var(--ocean); margin-bottom: 5px;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid rgba(10,77,104,0.15);
            border-radius: var(--radius-sm);
            font-family: 'Prompt', sans-serif;
            font-size: 0.9rem;
            background: var(--white);
            color: var(--text);
            transition: border-color 0.2s;
        }
        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            outline: none;
            border-color: var(--foam);
        }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        /* ── Empty state ─────────────────────────────────────────────── */
        .empty {
            text-align: center; padding: 40px 20px;
            color: var(--muted);
        }
        .empty i { font-size: 2.5rem; opacity: 0.3; margin-bottom: 10px; display: block; }

        /* ── Section header ──────────────────────────────────────────── */
        .sec-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 12px;
        }
        .sec-header h3 {
            font-size: 0.95rem; font-weight: 600;
            color: var(--ocean); display: flex; align-items: center; gap: 6px;
        }
        .sec-header h3 i { color: var(--foam); }

        /* ── Unpaid warning banner ────────────────────────────────────── */
        .unpaid-banner {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            color: #fff;
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 16px;
        }
        .unpaid-banner i { font-size: 1.5rem; opacity: 0.8; }
        .unpaid-banner .amount { font-size: 1.2rem; font-weight: 700; }
        .unpaid-banner .label  { font-size: 0.75rem; opacity: 0.85; }

        @media (min-width: 600px) {
            .modal-sheet { border-radius: var(--radius); margin-bottom: 40px; }
            .modal-backdrop { align-items: center; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <div class="topbar-inner">
        <div class="topbar-brand">
            <div class="drop-icon"><i class="fas fa-tint"></i></div>
            <div>
                <h1>WaterDrop</h1>
                <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Customer'); ?></span>
            </div>
        </div>
        <a href="../logout.php" class="topbar-logout">
            <i class="fas fa-sign-out-alt"></i> ออก
        </a>
    </div>
</nav>

<nav class="bottom-nav">
    <a href="index.php" class="<?php echo $current_page==='index.php'?'active':''; ?>">
        <i class="fas fa-home"></i> หน้าแรก
    </a>
    <a href="history.php" class="<?php echo $current_page==='history.php'?'active':''; ?>">
        <i class="fas fa-history"></i> ประวัติ
    </a>
</nav>