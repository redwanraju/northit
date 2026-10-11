<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);


include "../config/db.php";

/* ================= LOGIN CHECK ================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../pos/login.php");
    exit;
}

/* ================= ADMIN ================= */

if ($_SESSION['role'] == 'admin') {
    return;
}

/* ================= CURRENT PAGE ================= */

$current_page = basename($_SERVER['PHP_SELF']);

/*
যে পেজগুলোতে Permission লাগবে না
*/
$ignore_pages = [
    "index.php",
    "header.php",
    "sidebar.php",
    "footer.php",
    "permission.php",
    "access_denied.php"
];

if (in_array($current_page, $ignore_pages)) {
    return;
}

/* ================= CHECK PAGE EXISTS ================= */

$stmt = $conn->prepare("
    SELECT id
    FROM pages
    WHERE page_file = ?
    AND status = 1
    LIMIT 1
");

$stmt->bind_param("s", $current_page);
$stmt->execute();

$page = $stmt->get_result()->fetch_assoc();

/*
যদি pages টেবিলে Page না থাকে
তাহলে Access Block
*/

if (!$page) {

    die("
    <div style='padding:40px;font-family:Arial'>
        <h2>Page Not Registered</h2>
        <p>This page is not added in Page Management.</p>
    </div>
    ");

}

$page_id = $page['id'];

/* ================= USER PERMISSION ================= */

$stmt = $conn->prepare("
SELECT up.id
FROM user_permissions up
WHERE up.user_id = ?
AND up.shop_id = ?
AND up.page_id = ?
LIMIT 1
");

$stmt->bind_param(
    "iii",
    $_SESSION['user_id'],
    tenantShopId(),
    $page_id
);

$stmt->execute();

$permission = $stmt->get_result()->fetch_assoc();

if (!$permission) {

    header("Location: access_denied.php");
    exit;

}