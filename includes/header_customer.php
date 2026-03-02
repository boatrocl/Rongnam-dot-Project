<?php
// Define the base path to ensure links work from any subfolder
$base_url = "http://" . $_SERVER['SERVER_NAME'] . "/water_system_folder/"; // Change this to your actual folder name
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Portal - Water Delivery</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #2980b9;
            --light-bg: #f4f7f6;
            --text-dark: #2c3e50;
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background: var(--light-bg); }
        
        .customer-nav {
            background: var(--primary-blue);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
            font-weight: 500;
        }

        .nav-links a:hover { text-decoration: underline; }

        .dashboard { padding: 20px; max-width: 1200px; margin: 0 auto; }
        
        /* Re-using your existing table/stat styles */
        .stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center; }
        .table-container { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .status-badge { padding: 5px 10px; border-radius: 15px; font-size: 0.85rem; }
    </style>
</head>
<body>

<nav class="customer-nav">
    <div class="logo">
        <i class="fas fa-tint"></i> <strong>WaterDelivery</strong> | Customer Portal
    </div>
    <div class="nav-links">
        <a href="index.php"><i class="fas fa-home"></i> หน้าแรก</a>
        <a href="history.php"><i class="fas fa-history"></i> ประวัติการสั่งซื้อ</a>
        <a href="../logout.php" style="background: #c0392b; padding: 8px 15px; border-radius: 5px;">
            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
        </a>
    </div>
</nav>