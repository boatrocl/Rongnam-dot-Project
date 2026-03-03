<?php
require_once '../includes/auth.php';
require_login('admin');
require_once '../config/database.php';
require_once '../includes/header.php';

// ── Load dropdowns ────────────────────────────────────────────────────────────
$drivers  = $pdo->query("SELECT DID, DFname, DLname, tel FROM driver WHERE is_active = 1 ORDER BY DFname")->fetchAll();
$managers = $pdo->query("SELECT ID, MFname, MLname FROM manager ORDER BY MFname")->fetchAll();
$villages = $pdo->query("SELECT v.village_id, v.village_name, r.route_name FROM village v JOIN route r ON v.route_id = r.route_id ORDER BY r.route_name, v.village_name")->fetchAll();
$products = $pdo->query("SELECT * FROM product ORDER BY price")->fetchAll();

// Existing registered customers (non-guest, active) — for "existing customer" path
$customers = $pdo->query("
    SELECT u.User_ID, u.User_name, u.tel,
           l.loc_id, l.loc_name, l.details AS loc_details, l.village_id
    FROM `user` u
    JOIN location l ON u.User_ID = l.User_ID
    WHERE u.is_guest = 'N' AND u.is_active = 1
    ORDER BY u.User_name
")->fetchAll();

// ── Generate next IDs ─────────────────────────────────────────────────────────
function nextId(PDO $pdo, string $table, string $col, string $prefix, int $pad = 3): string {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING($col, " . (strlen($prefix)+1) . ") AS UNSIGNED)) AS mx FROM `$table`");
    $max  = $stmt->fetchColumn() ?: 0;
    return $prefix . str_pad($max + 1, $pad, '0', STR_PAD_LEFT);
}

// ── Handle form submission ────────────────────────────────────────────────────
$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode           = $_POST['customer_mode'];      // 'guest' | 'existing'
    $qty            = (int) ($_POST['qty']          ?? 0);
    $deposit        = (float) ($_POST['deposit']    ?? 0);
    $price_per_unit = (float) ($_POST['price_unit'] ?? 0);
    $scheduled_date = $_POST['scheduled_date']      ?? '';
    $scheduled_time = $scheduled_date . ' ' . ($_POST['scheduled_time'] ?? '08:00') . ':00';
    $driver_id      = $_POST['driver_id']           ?? '';
    $manager_id     = $_POST['manager_id']          ?? '';
    $order_type     = $_POST['order_type']          ?? 'delivery';
    $payment_status = $_POST['payment_status']      ?? 'unpaid';
    $product_id     = $_POST['product_id']          ?? '';

    // Resolve product name for note prefix [Product Name]
    $order_note = '';
    if ($product_id) {
        $pstmt = $pdo->prepare("SELECT name FROM product WHERE product_id = ?");
        $pstmt->execute([$product_id]);
        $prow = $pstmt->fetch();
        if ($prow) $order_note = '[' . $prow['name'] . ']';
    }

    // Basic validation
    if ($qty < 1)            $errors[] = 'จำนวนต้องมากกว่า 0';
    if (!$product_id)        $errors[] = 'กรุณาเลือกประเภทสินค้า';
    if (!$scheduled_date)    $errors[] = 'กรุณาเลือกวันที่นัดส่ง';
    if (!$driver_id)         $errors[] = 'กรุณาเลือกพนักงานขับ';
    if (!$manager_id)        $errors[] = 'กรุณาเลือกผู้อนุมัติ';
    if ($price_per_unit < 0) $errors[] = 'ราคาต่อหน่วยไม่ถูกต้อง';

    $total_price = ($qty * $price_per_unit) + $deposit;

    if ($mode === 'guest') {
        // Guest / walk-in fields
        $guest_name    = trim($_POST['guest_name']    ?? '');
        $guest_tel     = trim($_POST['guest_tel']     ?? '');
        $loc_name      = trim($_POST['loc_name']      ?? '');
        $loc_details   = trim($_POST['loc_details']   ?? '');
        $village_id    = $_POST['village_id']         ?? '';
        $lat           = trim($_POST['lat']           ?? '');
        $lng           = trim($_POST['lng']           ?? '');

        if (!$guest_name)  $errors[] = 'กรุณากรอกชื่อลูกค้า';
        if (!$guest_tel)   $errors[] = 'กรุณากรอกเบอร์โทรศัพท์';
        if (!$loc_name)    $errors[] = 'กรุณากรอกชื่อสถานที่';
        if (!$village_id)  $errors[] = 'กรุณาเลือกหมู่บ้าน/เขตพื้นที่';

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1. Create guest user
                $user_id = nextId($pdo, 'user', 'User_ID', 'U');
                $pdo->prepare("
                    INSERT INTO `user` (User_ID, User_name, password, tel, email, is_guest, is_active, Unpaid_amount)
                    VALUES (?, ?, '', ?, NULL, 'Y', 1, 0.00)
                ")->execute([$user_id, $guest_name, $guest_tel]);

                // 2. Create location
                $loc_id = nextId($pdo, 'location', 'loc_id', 'L');
                $pdo->prepare("
                    INSERT INTO location (loc_id, loc_name, details, bottle_on_hand, latitude, longitude, village_id, User_ID)
                    VALUES (?, ?, ?, 0, ?, ?, ?, ?)
                ")->execute([
                    $loc_id, $loc_name, $loc_details,
                    $lat !== '' ? $lat : null,
                    $lng !== '' ? $lng : null,
                    $village_id, $user_id
                ]);

                // 3. Create order
                $order_id = nextId($pdo, 'order', 'order_id', 'ORD');
                $pdo->prepare("
                    INSERT INTO `order`
                        (order_id, order_type, scheduled_date, scheduled_time, qty_ordered,
                         deposit_fee, total_expected_price, order_status, payment_status,
                         is_system_generated, User_ID, ID, loc_id, DID, confirmed_by_driver, driver_note)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'N', ?, ?, ?, ?, 0, ?)
                ")->execute([
                    $order_id, $order_type, $scheduled_date, $scheduled_time,
                    $qty, $deposit, $total_price, $payment_status,
                    $user_id, $manager_id, $loc_id, $driver_id, $order_note
                ]);

                $pdo->commit();
                $success = "สร้างออเดอร์ใหม่ <strong>$order_id</strong> สำหรับลูกค้า <strong>" . htmlspecialchars($guest_name) . "</strong> เรียบร้อยแล้ว";

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }

    } else {
        // Existing customer
        $user_id = $_POST['existing_user_id'] ?? '';
        $loc_id  = $_POST['existing_loc_id']  ?? '';

        if (!$user_id) $errors[] = 'กรุณาเลือกลูกค้า';
        if (!$loc_id)  $errors[] = 'กรุณาเลือกสถานที่';

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $order_id = nextId($pdo, 'order', 'order_id', 'ORD');
                $pdo->prepare("
                    INSERT INTO `order`
                        (order_id, order_type, scheduled_date, scheduled_time, qty_ordered,
                         deposit_fee, total_expected_price, order_status, payment_status,
                         is_system_generated, User_ID, ID, loc_id, DID, confirmed_by_driver, driver_note)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'N', ?, ?, ?, ?, 0, ?)
                ")->execute([
                    $order_id, $order_type, $scheduled_date, $scheduled_time,
                    $qty, $deposit, $total_price, $payment_status,
                    $user_id, $manager_id, $loc_id, $driver_id, $order_note
                ]);

                $pdo->commit();
                $success = "สร้างออเดอร์ใหม่ <strong>$order_id</strong> เรียบร้อยแล้ว";

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }
    }
}

// Encode customer data for JS (location lookup)
$customers_js = json_encode(array_values($customers));
$villages_js  = json_encode(array_values($villages));
?>

<style>
/* ── Product card grid (admin order form) ───────────────────────────────── */
.product-grid-admin {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-top: 6px;
}
@media (max-width: 640px) {
    .product-grid-admin { grid-template-columns: repeat(2, 1fr); }
}
.product-card-admin { cursor: pointer; display: block; }
.product-card-admin input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
.product-card-admin-inner {
    border: 2px solid var(--border, #e0e4ed);
    border-radius: 10px;
    padding: 12px 8px;
    text-align: center;
    background: #fff;
    transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
    user-select: none;
}
.product-card-admin input[type="radio"]:checked + .product-card-admin-inner {
    border-color: #e67e22;
    background: #fff8f0;
    box-shadow: 0 0 0 3px rgba(230,126,34,0.15);
}
.product-card-admin-inner:hover { border-color: #e67e22; background: #fffaf5; }
.pca-icon  { font-size: 1.4rem; margin-bottom: 4px; }
.pca-name  { font-size: 0.72rem; font-weight: 600; color: var(--navy, #1b2a4a); line-height: 1.3; }
.pca-price { font-size: 0.8rem; color: #e67e22; font-weight: 700; margin-top: 4px; }

/* ── Page layout ─────────────────────────────────────────────────────────── */
.form-page { max-width: 860px; margin: 0 auto; }

/* ── Mode toggle ─────────────────────────────────────────────────────────── */
.mode-toggle {
    display: flex;
    gap: 0;
    background: var(--light, #f4f6fa);
    border-radius: 10px;
    padding: 4px;
    width: fit-content;
    margin-bottom: 28px;
}
.mode-btn {
    padding: 10px 28px;
    border: none;
    background: transparent;
    border-radius: 8px;
    font-size: 0.92rem;
    font-weight: 600;
    cursor: pointer;
    color: var(--grey, #95a5a6);
    transition: all 0.18s;
}
.mode-btn.active {
    background: #fff;
    color: var(--navy, #1b2a4a);
    box-shadow: 0 2px 8px rgba(27,42,74,0.12);
}

/* ── Section cards ───────────────────────────────────────────────────────── */
.form-card {
    background: #fff;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 18px;
    box-shadow: 0 2px 10px rgba(27,42,74,0.07);
    border: 1px solid #eaecf2;
}
.form-card-title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--grey, #95a5a6);
    margin-bottom: 18px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eaecf2;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Grid helpers ────────────────────────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.col-span-2 { grid-column: span 2; }
.col-span-3 { grid-column: span 3; }

/* ── Form elements ───────────────────────────────────────────────────────── */
.field { display: flex; flex-direction: column; gap: 5px; }
.field label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--navy, #1b2a4a);
}
.field label .req { color: #e74c3c; margin-left: 2px; }
.field input,
.field select,
.field textarea {
    padding: 9px 12px;
    border: 1.5px solid #dde1ea;
    border-radius: 8px;
    font-size: 0.9rem;
    color: var(--navy, #1b2a4a);
    background: #fafbfc;
    transition: border-color 0.15s, box-shadow 0.15s;
    font-family: inherit;
}
.field input:focus,
.field select:focus,
.field textarea:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52,152,219,0.12);
    background: #fff;
}
.field textarea { resize: vertical; min-height: 72px; }

/* ── Price preview ───────────────────────────────────────────────────────── */
.price-preview {
    background: linear-gradient(135deg, #1b2a4a 0%, #2c3e6e 100%);
    color: #fff;
    border-radius: 10px;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 6px;
}
.price-preview .label { font-size: 0.8rem; opacity: 0.7; margin-bottom: 2px; }
.price-preview .amount { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; }
.price-breakdown { font-size: 0.75rem; opacity: 0.65; margin-top: 4px; }

/* ── Guest badge ─────────────────────────────────────────────────────────── */
.guest-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff3cd; color: #856404;
    border: 1px solid #ffc107;
    border-radius: 20px; padding: 3px 12px;
    font-size: 0.78rem; font-weight: 600;
}

/* ── Unpaid warning ──────────────────────────────────────────────────────── */
.unpaid-warn {
    background: #fff5f5; border: 1px solid #fca5a5;
    border-radius: 8px; padding: 10px 14px;
    font-size: 0.83rem; color: #991b1b;
    display: none; align-items: center; gap: 8px;
    margin-top: 8px;
}

/* ── Submit bar ──────────────────────────────────────────────────────────── */
.submit-bar {
    display: flex; gap: 12px; justify-content: flex-end;
    align-items: center; margin-top: 6px;
}

/* ── Alerts ──────────────────────────────────────────────────────────────── */
.alert { border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;
         display: flex; align-items: flex-start; gap: 10px; font-size: 0.9rem; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
.alert ul { margin: 6px 0 0 18px; }

@media (max-width: 640px) {
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .col-span-2, .col-span-3 { grid-column: span 1; }
}
</style>

<div class="form-page">

    <div class="page-header" style="margin-bottom:24px;">
        <div>
            <h2><i class="fas fa-plus-circle" style="color:#3498db;"></i> เพิ่มออเดอร์ใหม่</h2>
            <p style="color:var(--grey); margin:0; font-size:0.88rem;">
                สำหรับลูกค้าโทรสั่ง / Walk-in (มีบัญชีหรือไม่มีบัญชีก็ได้)
            </p>
        </div>
        <a href="orders.php" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> กลับ
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle" style="font-size:1.1rem; flex-shrink:0;"></i>
        <div>
            <?php echo $success; ?>
            <div style="margin-top:8px; display:flex; gap:8px;">
                <a href="orders.php" class="btn btn-success btn-sm">
                    <i class="fas fa-list"></i> ดูรายการออเดอร์
                </a>
                <a href="order_form.php" class="btn btn-ghost btn-sm">
                    <i class="fas fa-plus"></i> สร้างออเดอร์ใหม่อีก
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle" style="font-size:1.1rem; flex-shrink:0;"></i>
        <div>
            <strong>พบข้อผิดพลาด:</strong>
            <ul><?php foreach($errors as $e) echo "<li>$e</li>"; ?></ul>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="orderForm">

        <!-- ── Customer mode toggle ──────────────────────────────────────── -->
        <div class="mode-toggle">
            <button type="button" class="mode-btn active" id="btnGuest"
                    onclick="setMode('guest')">
                <i class="fas fa-user-plus"></i> ลูกค้าใหม่ / Walk-in
            </button>
            <button type="button" class="mode-btn" id="btnExisting"
                    onclick="setMode('existing')">
                <i class="fas fa-user-check"></i> ลูกค้ามีบัญชีแล้ว
            </button>
        </div>
        <input type="hidden" name="customer_mode" id="customerMode" value="guest">

        <!-- ══════════════════════════════════════════════════════════════
             SECTION A: GUEST (new / walk-in)
        ═══════════════════════════════════════════════════════════════ -->
        <div id="sectionGuest">

            <!-- Customer info -->
            <div class="form-card">
                <div class="form-card-title">
                    <i class="fas fa-user-plus" style="color:#3498db;"></i>
                    ข้อมูลลูกค้าใหม่
                    <span class="guest-badge"><i class="fas fa-star"></i> Walk-in / โทรสั่ง</span>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label>ชื่อลูกค้า <span class="req">*</span></label>
                        <input type="text" name="guest_name" placeholder="ชื่อ-นามสกุล หรือชื่อร้าน"
                               value="<?php echo htmlspecialchars($_POST['guest_name'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label>เบอร์โทรศัพท์ <span class="req">*</span></label>
                        <input type="tel" name="guest_tel" placeholder="0812345678"
                               value="<?php echo htmlspecialchars($_POST['guest_tel'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="form-card">
                <div class="form-card-title">
                    <i class="fas fa-map-marker-alt" style="color:#e74c3c;"></i>
                    ที่อยู่จัดส่ง
                </div>
                <div class="grid-2" style="margin-bottom:14px;">
                    <div class="field">
                        <label>ชื่อสถานที่ <span class="req">*</span></label>
                        <input type="text" name="loc_name" placeholder="เช่น บ้านคุณสมชาย, ร้านขายของ"
                               value="<?php echo htmlspecialchars($_POST['loc_name'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label>หมู่บ้าน / เขตพื้นที่ <span class="req">*</span></label>
                        <select name="village_id">
                            <option value="">— เลือกพื้นที่ —</option>
                            <?php foreach($villages as $v): ?>
                            <option value="<?php echo $v['village_id']; ?>"
                                <?php echo (($_POST['village_id'] ?? '') === $v['village_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($v['village_name'] . ' (' . $v['route_name'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field" style="margin-bottom:14px;">
                    <label>รายละเอียดที่อยู่</label>
                    <input type="text" name="loc_details" placeholder="เช่น บ้านเลขที่ 123 ซอย 5 ถ.สุขุมวิท"
                           value="<?php echo htmlspecialchars($_POST['loc_details'] ?? ''); ?>">
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label>Latitude <span style="color:#95a5a6; font-weight:400;">(ไม่บังคับ)</span></label>
                        <input type="number" step="any" name="lat" placeholder="13.7367170"
                               value="<?php echo htmlspecialchars($_POST['lat'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label>Longitude <span style="color:#95a5a6; font-weight:400;">(ไม่บังคับ)</span></label>
                        <input type="number" step="any" name="lng" placeholder="100.5645860"
                               value="<?php echo htmlspecialchars($_POST['lng'] ?? ''); ?>">
                    </div>
                </div>
                <p style="font-size:0.75rem; color:#95a5a6; margin:8px 0 0;">
                    <i class="fas fa-info-circle"></i>
                    พิกัด GPS ใช้สำหรับแสดงบนแผนที่ในหน้าออเดอร์ (ไม่บังคับ)
                </p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             SECTION B: EXISTING CUSTOMER
        ═══════════════════════════════════════════════════════════════ -->
        <div id="sectionExisting" style="display:none;">
            <div class="form-card">
                <div class="form-card-title">
                    <i class="fas fa-user-check" style="color:#27ae60;"></i>
                    เลือกลูกค้า
                </div>

                <div class="field" style="margin-bottom:14px;">
                    <label>ค้นหาลูกค้า <span class="req">*</span></label>
                    <input type="text" id="customerSearch"
                           placeholder="พิมพ์ชื่อหรือเบอร์โทร..."
                           autocomplete="off" oninput="filterCustomers()">
                </div>

                <div id="customerList" style="border:1.5px solid #dde1ea; border-radius:8px;
                     max-height:220px; overflow-y:auto; display:none;">
                </div>

                <!-- Selected customer display -->
                <div id="selectedCustomer" style="display:none; background:#f0fdf4;
                     border:1.5px solid #86efac; border-radius:8px; padding:12px 16px; margin-top:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-weight:700; color:#166534;" id="sc_name"></div>
                            <div style="font-size:0.8rem; color:#15803d;" id="sc_tel"></div>
                        </div>
                        <button type="button" onclick="clearCustomer()"
                                style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1rem;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="unpaidWarn" class="unpaid-warn">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>ลูกค้ามียอดค้างชำระ: <strong id="unpaidAmt"></strong> บาท</span>
                    </div>
                </div>

                <input type="hidden" name="existing_user_id" id="existingUserId">

                <!-- Location picker (populated after selecting customer) -->
                <div id="existingLocWrap" style="margin-top:16px; display:none;">
                    <div class="field">
                        <label>สถานที่จัดส่ง <span class="req">*</span></label>
                        <select name="existing_loc_id" id="existingLocSelect">
                            <option value="">— เลือกสถานที่ —</option>
                        </select>
                    </div>
                </div>

            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             SECTION C: ORDER DETAILS (shared)
        ═══════════════════════════════════════════════════════════════ -->
        <div class="form-card">
            <div class="form-card-title">
                <i class="fas fa-box" style="color:#e67e22;"></i>
                รายละเอียดออเดอร์
            </div>

            <div class="grid-3" style="margin-bottom:16px;">
                <div class="field">
                    <label>ประเภทออเดอร์ <span class="req">*</span></label>
                    <select name="order_type">
                        <option value="delivery" <?php echo (($_POST['order_type'] ?? 'delivery') === 'delivery') ? 'selected' : ''; ?>>
                            🚚 จัดส่ง (Delivery)
                        </option>
                        <option value="pickup" <?php echo (($_POST['order_type'] ?? '') === 'pickup') ? 'selected' : ''; ?>>
                            🏪 รับเอง (Pickup)
                        </option>
                    </select>
                </div>
                <div class="field">
                    <label>วันที่นัดส่ง <span class="req">*</span></label>
                    <input type="date" name="scheduled_date"
                           value="<?php echo htmlspecialchars($_POST['scheduled_date'] ?? date('Y-m-d')); ?>"
                           min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="field">
                    <label>เวลานัดส่ง</label>
                    <input type="time" name="scheduled_time"
                           value="<?php echo htmlspecialchars($_POST['scheduled_time'] ?? '08:00'); ?>">
                </div>
            </div>

            <!-- Product / bottle type selector -->
            <div class="field" style="margin-bottom:18px;">
                <label>ประเภทสินค้า <span class="req">*</span></label>
                <div class="product-grid-admin" id="productGridAdmin">
                    <?php foreach($products as $p):
                        if ($p['product_id'] === 'P005') continue; // skip deposit product
                        $checked = (($_POST['product_id'] ?? '') === $p['product_id']) ? 'checked' : '';
                    ?>
                    <label class="product-card-admin">
                        <input type="radio" name="product_id"
                               value="<?php echo $p['product_id']; ?>"
                               data-price="<?php echo $p['price']; ?>"
                               data-name="<?php echo htmlspecialchars($p['name']); ?>"
                               onchange="onAdminProductChange(this)"
                               <?php echo $checked; ?> required>
                        <div class="product-card-admin-inner">
                            <div class="pca-icon">💧</div>
                            <div class="pca-name"><?php echo htmlspecialchars($p['name']); ?></div>
                            <div class="pca-price">฿<?php echo number_format($p['price'], 2); ?>/ขวด</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid-3" style="margin-bottom:16px;">
                <div class="field">
                    <label>จำนวนขวด <span class="req">*</span></label>
                    <input type="number" name="qty" id="inputQty" min="1"
                           value="<?php echo (int)($_POST['qty'] ?? 1); ?>"
                           oninput="calcPrice()">
                </div>
                <div class="field">
                    <label>ราคาต่อขวด (บาท) <span class="req">*</span></label>
                    <input type="number" name="price_unit" id="inputPrice"
                           step="0.01" min="0"
                           value="<?php echo htmlspecialchars($_POST['price_unit'] ?? ''); ?>"
                           placeholder="กรอกหรือเลือกสินค้าด้านบน"
                           oninput="calcPrice()">
                    <div id="priceHint" style="font-size:0.72rem; color:#27ae60; margin-top:3px; display:none;">
                        <i class="fas fa-check-circle"></i> ราคามาตรฐาน — แก้ไขได้
                    </div>
                </div>
                <div class="field">
                    <label>ค่ามัดจำขวด (บาท)</label>
                    <input type="number" name="deposit" id="inputDeposit" min="0" step="0.01"
                           value="<?php echo htmlspecialchars($_POST['deposit'] ?? '0'); ?>"
                           placeholder="0.00" oninput="calcPrice()">
                </div>
            </div>

            <!-- Price preview -->
            <div class="price-preview">
                <div>
                    <div class="label">ยอดรวมทั้งหมด</div>
                    <div class="amount" id="totalDisplay">฿ 0.00</div>
                    <div class="price-breakdown" id="breakdownDisplay">—</div>
                </div>
                <i class="fas fa-receipt" style="font-size:2rem; opacity:0.3;"></i>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             SECTION D: ASSIGNMENT
        ═══════════════════════════════════════════════════════════════ -->
        <div class="form-card">
            <div class="form-card-title">
                <i class="fas fa-truck" style="color:#8e44ad;"></i>
                มอบหมายงาน
            </div>
            <div class="grid-3">
                <div class="field">
                    <label>พนักงานขับ <span class="req">*</span></label>
                    <select name="driver_id">
                        <option value="">— เลือกพนักงานขับ —</option>
                        <?php foreach($drivers as $d): ?>
                        <option value="<?php echo $d['DID']; ?>"
                            <?php echo (($_POST['driver_id'] ?? '') === $d['DID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($d['DFname'] . ' ' . $d['DLname']); ?>
                            (<?php echo htmlspecialchars($d['tel']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>ผู้อนุมัติ (Manager) <span class="req">*</span></label>
                    <select name="manager_id">
                        <option value="">— เลือกผู้อนุมัติ —</option>
                        <?php foreach($managers as $m): ?>
                        <option value="<?php echo $m['ID']; ?>"
                            <?php echo (($_POST['manager_id'] ?? '') === $m['ID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m['MFname'] . ' ' . $m['MLname']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>สถานะการชำระเงิน</label>
                    <select name="payment_status">
                        <option value="unpaid"  <?php echo (($_POST['payment_status'] ?? 'unpaid') === 'unpaid')  ? 'selected' : ''; ?>>❌ ยังไม่ชำระ</option>
                        <option value="partial" <?php echo (($_POST['payment_status'] ?? '') === 'partial') ? 'selected' : ''; ?>>⚠️ ชำระบางส่วน</option>
                        <option value="paid"    <?php echo (($_POST['payment_status'] ?? '') === 'paid')    ? 'selected' : ''; ?>>✅ ชำระแล้ว</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="submit-bar">
            <a href="orders.php" class="btn btn-ghost">ยกเลิก</a>
            <button type="submit" class="btn btn-primary" style="min-width:160px;">
                <i class="fas fa-save"></i> สร้างออเดอร์
            </button>
        </div>

    </form>
</div>

<script>
// ── Mode toggle ───────────────────────────────────────────────────────────────
function setMode(mode) {
    document.getElementById('customerMode').value = mode;
    document.getElementById('sectionGuest').style.display    = mode === 'guest'    ? '' : 'none';
    document.getElementById('sectionExisting').style.display = mode === 'existing' ? '' : 'none';
    document.getElementById('btnGuest').classList.toggle('active',    mode === 'guest');
    document.getElementById('btnExisting').classList.toggle('active', mode === 'existing');
}

// ── Product card selection → auto-fill price ──────────────────────────────────
function onAdminProductChange(radio) {
    const price = parseFloat(radio.dataset.price) || 0;
    document.getElementById('inputPrice').value = price.toFixed(2);
    document.getElementById('priceHint').style.display = price > 0 ? '' : 'none';
    calcPrice();
}

// ── Price calculator ───────────────────────────────────────────────────────────
function calcPrice() {
    const qty     = parseFloat(document.getElementById('inputQty').value)    || 0;
    const unit    = parseFloat(document.getElementById('inputPrice').value)   || 0;
    const deposit = parseFloat(document.getElementById('inputDeposit').value) || 0;
    const total   = qty * unit + deposit;

    document.getElementById('totalDisplay').textContent =
        '฿ ' + total.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('breakdownDisplay').textContent =
        qty > 0 && unit > 0
            ? `${qty} ขวด × ฿${unit.toFixed(2)} + มัดจำ ฿${deposit.toFixed(2)}`
            : '—';
}

// If page reloads with a POST (validation error), restore card selection state
(function restoreProductCard() {
    const checked = document.querySelector('input[name="product_id"]:checked');
    if (checked) { onAdminProductChange(checked); }
    calcPrice();
})();

// ── Existing customer picker ──────────────────────────────────────────────────
const CUSTOMERS = <?php echo $customers_js; ?>;

function filterCustomers() {
    const q   = document.getElementById('customerSearch').value.toLowerCase().trim();
    const box = document.getElementById('customerList');

    if (!q) { box.style.display = 'none'; box.innerHTML = ''; return; }

    const matches = CUSTOMERS.filter(c =>
        (c.User_name || '').toLowerCase().includes(q) ||
        (c.tel       || '').includes(q)
    ).slice(0, 10);

    if (!matches.length) {
        box.innerHTML = '<div style="padding:12px 16px; color:#95a5a6; font-size:0.85rem;">ไม่พบลูกค้า</div>';
        box.style.display = '';
        return;
    }

    box.innerHTML = matches.map(c => `
        <div class="customer-item" onclick="selectCustomer(${c.User_ID.replace(/\D/g,'')||0},'${esc(c.User_ID)}','${esc(c.User_name)}','${esc(c.tel||'')}','${esc(c.loc_id)}','${esc(c.loc_name)}','${esc(c.loc_details||'')}',${parseFloat(c.Unpaid_amount||0)})"
             style="padding:10px 16px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:0.88rem; transition:background 0.1s;"
             onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
            <div style="font-weight:600; color:#1b2a4a;">${esc(c.User_name)}</div>
            <div style="color:#95a5a6; font-size:0.78rem;">${esc(c.tel||'-')} · ${esc(c.loc_name)}</div>
        </div>
    `).join('');
    box.style.display = '';
}

function esc(s) { return String(s).replace(/'/g,"\\'").replace(/</g,'&lt;'); }

function selectCustomer(_, uid, name, tel, locId, locName, locDetails, unpaid) {
    document.getElementById('existingUserId').value      = uid;
    document.getElementById('customerSearch').value      = name;
    document.getElementById('customerList').style.display = 'none';

    document.getElementById('sc_name').textContent = name;
    document.getElementById('sc_tel').textContent  = tel;
    document.getElementById('selectedCustomer').style.display = '';

    // unpaid warning
    const warn = document.getElementById('unpaidWarn');
    if (unpaid > 0) {
        document.getElementById('unpaidAmt').textContent = unpaid.toFixed(2);
        warn.style.display = 'flex';
    } else {
        warn.style.display = 'none';
    }

    // Populate locations belonging to this customer
    // Filter all locations for this user
    const locs = CUSTOMERS.filter(c => c.User_ID === uid);
    const sel  = document.getElementById('existingLocSelect');
    sel.innerHTML = '<option value="">— เลือกสถานที่ —</option>';
    locs.forEach(c => {
        const opt = document.createElement('option');
        opt.value       = c.loc_id;
        opt.textContent = c.loc_name + (c.loc_details ? ' — ' + c.loc_details : '');
        sel.appendChild(opt);
    });
    // Auto-select if only one
    if (locs.length === 1) sel.value = locs[0].loc_id;
    document.getElementById('existingLocWrap').style.display = '';
}

function clearCustomer() {
    document.getElementById('existingUserId').value            = '';
    document.getElementById('customerSearch').value            = '';
    document.getElementById('selectedCustomer').style.display  = 'none';
    document.getElementById('existingLocWrap').style.display   = 'none';
    document.getElementById('customerList').style.display      = 'none';
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#sectionExisting')) {
        document.getElementById('customerList').style.display = 'none';
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>