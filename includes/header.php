<?php
// session_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการน้ำดื่ม</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="/water_management/css/style.css">
</head>
<body>
    <nav class="navbar">
        <h1><i class="fas fa-water"></i> ระบบจัดการน้ำดื่ม</h1>
        <div class="nav-links">
            <a href="/water_management/index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> หน้าแรก
            </a>
            <a href="/water_management/admin/dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> แดชบอร์ด
            </a>
            <a href="/water_management/admin/orders.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> ออเดอร์
            </a>
            <a href="/water_management/admin/pending_orders.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'pending_orders.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> ออเดอร์ที่รอการยืนยัน
            </a>
            <a href="/water_management/admin/customers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> ลูกค้า
            </a>
            <a href="/water_management/admin/drivers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'drivers.php' ? 'active' : ''; ?>">
                <i class="fas fa-truck"></i> พนักงานขับ
            </a>
            <a href="/water_management/admin/locations.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'locations.php' ? 'active' : ''; ?>">
                <i class="fas fa-map-marker-alt"></i> สถานที่
            </a>
            <a href="/water_management/admin/routes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'routes.php' ? 'active' : ''; ?>">
                <i class="fas fa-route"></i> เส้นทาง
            </a>
            <a href="/water_management/admin/stock.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'stock.php' ? 'active' : ''; ?>">
                <i class="fas fa-box"></i> สต็อก
            </a>
            <a href="/water_management/admin/reports.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> รายงาน
            </a>
            
            <span style="color:rgba(255,255,255,0.6); margin: 0 8px;">|</span>
    <span style="color:rgba(255,255,255,0.8); font-size:0.9rem;">
        <i class="fas fa-user-shield"></i>
        <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>
    </span>
    <a href="../logout.php" style="color:#e74c3c;">
        <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
    </a>
        </div>
    </nav>
    <div class="container">
