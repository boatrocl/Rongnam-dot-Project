<?php
require_once '../config/database.php';

// Managers
$rows = $pdo->query("SELECT ID, password FROM Manager")->fetchAll();
foreach ($rows as $r) {
    if (strlen($r['password']) < 60) { // not yet hashed
        $h = password_hash($r['password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE Manager SET password=? WHERE ID=?")->execute([$h, $r['ID']]);
    }
}

// Drivers
$rows = $pdo->query("SELECT DID, Password FROM Driver")->fetchAll();
foreach ($rows as $r) {
    if (strlen($r['Password']) < 60) {
        $h = password_hash($r['Password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE Driver SET Password=? WHERE DID=?")->execute([$h, $r['DID']]);
    }
}

// Users/Customers
$rows = $pdo->query("SELECT User_ID, password FROM `User`")->fetchAll();
foreach ($rows as $r) {
    if (strlen($r['password']) < 60) {
        $h = password_hash($r['password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE `User` SET password=? WHERE User_ID=?")->execute([$h, $r['User_ID']]);
    }
}

echo "Done! All passwords hashed. DELETE THIS FILE NOW.";
?>
```

---

## Checklist to do it in order
```
1. [ ] Run ALTER TABLE SQL from previous message (add is_active, email cols)
2. [ ] Move all /pages/*.php → /admin/*.php
3. [ ] Create /water_management/index.php (login page above)
4. [ ] Create /includes/auth.php
5. [ ] Create /admin/logout.php
6. [ ] Run migrate_passwords.php → DELETE IT after
7. [ ] Add require_login('admin') to top of every /admin/ page
8. [ ] Add logout button to header.php
9. [ ] Test: login as admin → goes to /admin/index.php
10. [ ] Test: wrong password → error on login page
11. [ ] Test: visit /admin/orders.php directly without login → redirects to index.php