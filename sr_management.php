<?php
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

// ============================================================
// Sales Representative (SR) Management - Refactored UI
// File: sr_management.php
// Purpose: Professional SR CRUD with Add/View/Edit modals,
//          safe status toggle and delete/inactive option.
// Safety: shop_id scoped, CSRF protected, prepared statements,
//         sanitized output, no ID column in table UI.
// ============================================================

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";

$shop_id = tenantShopId();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));
$user_name = $_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'Admin');

// ============================================================
// HELPERS
// ============================================================
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function taka($amount, $decimals = 2) {
    return '৳ ' . number_format((float)$amount, $decimals);
}

function cleanText($value, $maxLength = 255) {
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        }
    } elseif (strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }
    return $value;
}

function safeIdentifier($name) {
    return preg_match('/^[a-zA-Z0-9_]+$/', (string)$name) ? $name : '';
}

function tableExists($conn, $table) {
    static $cache = [];
    $table = safeIdentifier($table);
    if ($table === '') return false;
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) {
        error_log('tableExists prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $cache[$table] = ((int)($row[0] ?? 0) > 0);
    return $cache[$table];
}

function columnExists($conn, $table, $column) {
    static $cache = [];
    $table = safeIdentifier($table);
    $column = safeIdentifier($column);
    if ($table === '' || $column === '') return false;
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) {
        error_log('columnExists prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $cache[$key] = ((int)($row[0] ?? 0) > 0);
    return $cache[$key];
}

function fetchRows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchRows prepare failed: ' . $conn->error . ' SQL: ' . $sql);
        return [];
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) return [];
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function scalarQuery($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('scalarQuery prepare failed: ' . $conn->error . ' SQL: ' . $sql);
        return 0;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) return 0;
    $row = $res->fetch_row();
    return $row ? ($row[0] ?? 0) : 0;
}

function postValue($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function setFlash($type, $message) {
    $_SESSION['sr_flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    $flash = $_SESSION['sr_flash'] ?? null;
    unset($_SESSION['sr_flash']);
    return $flash;
}

function csrfToken() {
    if (empty($_SESSION['sr_csrf_token'])) {
        $_SESSION['sr_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sr_csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function csrfIsValid() {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['sr_csrf_token'])
        && hash_equals($_SESSION['sr_csrf_token'], $token);
}

function redirectToSelf($params = []) {
    $base = basename($_SERVER['PHP_SELF']);
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    header('Location: ' . $base);
    exit;
}

function validateEmailOrBlank($email) {
    $email = trim((string)$email);
    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL);
}

function srStatusLabel($status) {
    return ((int)$status === 1) ? 'Active' : 'Inactive';
}

function srStatusClass($status) {
    return ((int)$status === 1) ? 'v3-badge-green' : 'v3-badge-red';
}

$srs_table_ready = isset($conn) && $conn && tableExists($conn, 'sales_representatives');
$customers_table_ready = isset($conn) && $conn && tableExists($conn, 'customers');
$orders_table_ready = isset($conn) && $conn && tableExists($conn, 'orders');
$has_user_id_col = $srs_table_ready && columnExists($conn, 'sales_representatives', 'user_id');

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfIsValid()) {
        setFlash('error', 'Security token mismatch. Please try again.');
        redirectToSelf();
    }

    if (!$srs_table_ready) {
        setFlash('error', 'sales_representatives table পাওয়া যায়নি।');
        redirectToSelf();
    }

    $action = postValue('action');

    try {
        if ($action === 'save_sr' || $action === 'update_sr') {
            $sr_id = (int)postValue('sr_id', '0');
            $sr_name = cleanText(postValue('sr_name'), 100);
            $phone = cleanText(postValue('phone'), 20);
            $email = cleanText(postValue('email'), 100);
            $address = cleanText(postValue('address'), 2000);
            $area_group = cleanText(postValue('area_group'), 100);
            $target_amount_raw = postValue('target_amount', '0');
            $commission_rate_raw = postValue('commission_rate', '0');
            $basic_salary_raw = postValue('basic_salary', '0');
            $status = (int)postValue('status', '1');

            if ($sr_name === '') {
                throw new Exception('SR Name is required.');
            }
            if (!validateEmailOrBlank($email)) {
                throw new Exception('Invalid email address.');
            }
            if (!is_numeric($target_amount_raw) || !is_numeric($commission_rate_raw) || !is_numeric($basic_salary_raw)) {
                throw new Exception('Target, commission and salary must be numeric.');
            }

            $target_amount = round((float)$target_amount_raw, 2);
            $commission_rate = round((float)$commission_rate_raw, 2);
            $basic_salary = round((float)$basic_salary_raw, 2);

            if ($target_amount < 0 || $commission_rate < 0 || $basic_salary < 0) {
                throw new Exception('Negative amount is not allowed.');
            }
            if ($commission_rate > 100) {
                throw new Exception('Commission rate cannot be more than 100%.');
            }
            $status = $status === 1 ? 1 : 0;

            if ($action === 'update_sr') {
                if ($sr_id <= 0) {
                    throw new Exception('Invalid SR selected.');
                }
                $stmt = $conn->prepare("\n                    UPDATE sales_representatives\n                    SET sr_name = ?, phone = ?, email = ?, address = ?, area_group = ?,\n                        target_amount = ?, commission_rate = ?, basic_salary = ?, status = ?\n                    WHERE id = ? AND shop_id = ?\n                    LIMIT 1\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                // 5 strings, 3 doubles, 3 integers = sssssdddiii
                $stmt->bind_param('sssssdddiii', $sr_name, $phone, $email, $address, $area_group, $target_amount, $commission_rate, $basic_salary, $status, $sr_id, $shop_id);
                $stmt->execute();
                setFlash('success', 'SR updated successfully.');
            } else {
                $dup = fetchRows($conn, "SELECT id FROM sales_representatives WHERE shop_id = ? AND sr_name = ? LIMIT 1", 'is', [$shop_id, $sr_name]);
                if (!empty($dup)) {
                    throw new Exception('This SR name already exists.');
                }

                if ($has_user_id_col) {
                    $stmt = $conn->prepare("\n                        INSERT INTO sales_representatives\n                            (shop_id, sr_name, phone, email, address, area_group, target_amount, commission_rate, basic_salary, status, user_id)\n                        VALUES\n                            (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    // shop_id i + 5 strings + 3 doubles + user_id i = isssssdddi
                    $stmt->bind_param('isssssdddi', $shop_id, $sr_name, $phone, $email, $address, $area_group, $target_amount, $commission_rate, $basic_salary, $user_id);
                } else {
                    $stmt = $conn->prepare("\n                        INSERT INTO sales_representatives\n                            (shop_id, sr_name, phone, email, address, area_group, target_amount, commission_rate, basic_salary, status)\n                        VALUES\n                            (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    // shop_id i + 5 strings + 3 doubles = isssssddd
                    $stmt->bind_param('isssssddd', $shop_id, $sr_name, $phone, $email, $address, $area_group, $target_amount, $commission_rate, $basic_salary);
                }
                $stmt->execute();
                setFlash('success', 'SR added successfully.');
            }

            redirectToSelf();
        }

        if ($action === 'toggle_status') {
            $sr_id = (int)postValue('sr_id', '0');
            if ($sr_id <= 0) throw new Exception('Invalid SR selected.');

            $stmt = $conn->prepare("\n                UPDATE sales_representatives\n                SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END\n                WHERE id = ? AND shop_id = ?\n                LIMIT 1\n            ");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('ii', $sr_id, $shop_id);
            $stmt->execute();
            setFlash('success', 'SR status updated.');
            redirectToSelf();
        }

        if ($action === 'delete_sr') {
            $sr_id = (int)postValue('sr_id', '0');
            if ($sr_id <= 0) throw new Exception('Invalid SR selected.');

            // Safe delete: keep historical salary/order/customer relation intact.
            $stmt = $conn->prepare("UPDATE sales_representatives SET status = 0 WHERE id = ? AND shop_id = ? LIMIT 1");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('ii', $sr_id, $shop_id);
            $stmt->execute();
            setFlash('success', 'SR inactive করা হয়েছে। Historical data delete করা হয়নি।');
            redirectToSelf();
        }

        setFlash('error', 'Invalid action.');
        redirectToSelf();
    } catch (Throwable $e) {
        error_log('sr_management error: ' . $e->getMessage());
        setFlash('error', $e->getMessage());
        redirectToSelf();
    }
}

// ============================================================
// FILTERS + DATA LOAD
// ============================================================
$limit = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$start = ($page - 1) * $limit;
$search = cleanText($_GET['search'] ?? '', 100);
$filter_status = isset($_GET['filter_status']) ? trim((string)$_GET['filter_status']) : '';
$filter_area = cleanText($_GET['filter_area'] ?? '', 100);

$whereParts = ["sr.shop_id = ?"];
$types = 'i';
$params = [$shop_id];

if ($search !== '') {
    $whereParts[] = "(sr.sr_name LIKE ? OR sr.phone LIKE ? OR sr.email LIKE ? OR sr.area_group LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($filter_status === '1' || $filter_status === '0') {
    $whereParts[] = "sr.status = ?";
    $types .= 'i';
    $params[] = (int)$filter_status;
}

if ($filter_area !== '') {
    $whereParts[] = "sr.area_group = ?";
    $types .= 's';
    $params[] = $filter_area;
}

$whereSql = implode(' AND ', $whereParts);

$total_records = 0;
$total_pages = 1;
$srs = [];
$areas = [];
$active_count = 0;
$total_customers = 0;
$total_orders = 0;
$total_order_amount = 0;
$flash = getFlash();

if ($srs_table_ready) {
    $total_records = (int)scalarQuery($conn, "SELECT COUNT(*) FROM sales_representatives sr WHERE $whereSql", $types, $params);
    $total_pages = max(1, (int)ceil($total_records / $limit));
    if ($page > $total_pages) {
        $page = $total_pages;
        $start = ($page - 1) * $limit;
    }

    $orderStatsSelect = $orders_table_ready
        ? "(SELECT COUNT(*) FROM orders o WHERE o.sr_id = sr.id AND o.shop_id = sr.shop_id AND o.order_source = 'pos') AS total_orders,\n           (SELECT COALESCE(SUM(COALESCE(o.total_amount,0)),0) FROM orders o WHERE o.sr_id = sr.id AND o.shop_id = sr.shop_id AND o.order_source = 'pos') AS total_order_amount"
        : "0 AS total_orders, 0 AS total_order_amount";

    $customerStatsSelect = $customers_table_ready
        ? "(SELECT COUNT(*) FROM customers c WHERE c.sr_id = sr.id AND c.shop_id = sr.shop_id) AS total_customers"
        : "0 AS total_customers";

    $sql = "\n        SELECT sr.*,\n               $customerStatsSelect,\n               $orderStatsSelect\n        FROM sales_representatives sr\n        WHERE $whereSql\n        ORDER BY sr.status DESC, sr.sr_name ASC\n        LIMIT ? OFFSET ?\n    ";
    $fetchTypes = $types . 'ii';
    $fetchParams = array_merge($params, [$limit, $start]);
    $srs = fetchRows($conn, $sql, $fetchTypes, $fetchParams);

    $active_count = (int)scalarQuery($conn, "SELECT COUNT(*) FROM sales_representatives WHERE shop_id = ? AND status = 1", 'i', [$shop_id]);
    if ($customers_table_ready) {
        $total_customers = (int)scalarQuery($conn, "SELECT COUNT(*) FROM customers WHERE shop_id = ?", 'i', [$shop_id]);
    }
    if ($orders_table_ready) {
        $total_orders = (int)scalarQuery($conn, "SELECT COUNT(*) FROM orders WHERE shop_id = ? AND order_source = 'pos'", 'i', [$shop_id]);
        $total_order_amount = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(total_amount,0)),0) FROM orders WHERE shop_id = ? AND order_source = 'pos'", 'i', [$shop_id]);
    }

    $areas = fetchRows(
        $conn,
        "SELECT DISTINCT area_group FROM sales_representatives WHERE shop_id = ? AND area_group IS NOT NULL AND area_group <> '' ORDER BY area_group ASC",
        'i',
        [$shop_id]
    );
}

include "header.php";
include "sidebar.php";
?>

<style>
:root {
  --v3-bg: #f0f4f8;
  --v3-card: #ffffff;
  --v3-text: #1e293b;
  --v3-muted: #64748b;
  --v3-border: #e2e8f0;
  --v3-blue: #2563eb;
  --v3-green: #059669;
  --v3-red: #dc2626;
  --v3-orange: #d97706;
  --v3-purple: #7c3aed;
  --v3-pink: #db2777;
  --v3-teal: #0d9488;
}
.main {
  flex: 1;
  padding: 24px;
  background: var(--v3-bg);
  min-height: 100vh;
  font-family: 'Segoe UI', system-ui, sans-serif;
}
.v3-header {
  display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;
  background:#fff; border:1px solid var(--v3-border); border-radius:18px; padding:22px 26px;
  box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:20px;
}
.v3-header h3 { margin:0 0 5px; font-size:1.45rem; font-weight:800; color:var(--v3-text); }
.v3-header p { margin:0; color:var(--v3-muted); font-size:.86rem; }
.v3-top-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.v3-btn {
  border:none; border-radius:12px; padding:10px 15px; font-size:.84rem; font-weight:800;
  display:inline-flex; align-items:center; justify-content:center; gap:7px; cursor:pointer; text-decoration:none;
  transition:all .2s ease; white-space:nowrap;
}
.v3-btn:hover { transform:translateY(-1px); text-decoration:none; }
.v3-btn-primary { background:var(--v3-blue); color:#fff; }
.v3-btn-success { background:var(--v3-green); color:#fff; }
.v3-btn-warning { background:#f59e0b; color:#fff; }
.v3-btn-danger { background:var(--v3-red); color:#fff; }
.v3-btn-light { background:#f1f5f9; color:#334155; }
.v3-btn-purple { background:var(--v3-purple); color:#fff; }
.v3-btn-teal { background:var(--v3-teal); color:#fff; }
.v3-btn-pink { background:var(--v3-pink); color:#fff; }
.v3-btn-sm { padding:7px 10px; border-radius:10px; font-size:.74rem; }
.v3-alert { border-radius:12px; padding:12px 16px; margin-bottom:16px; border:1px solid transparent; font-size:.9rem; }
.v3-alert-success { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
.v3-alert-error { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.v3-warning-box { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; border-radius:12px; padding:12px 16px; margin-bottom:16px; }
.v3-stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:18px; }
.v3-stat-card { background:#fff; border:1px solid var(--v3-border); border-radius:16px; padding:18px; box-shadow:0 1px 8px rgba(0,0,0,.04); position:relative; overflow:hidden; }
.v3-stat-card:before { content:''; position:absolute; left:0; top:0; bottom:0; width:5px; background:#cbd5e1; }
.v3-stat-blue:before { background:var(--v3-blue); } .v3-stat-green:before { background:var(--v3-green); } .v3-stat-purple:before { background:var(--v3-purple); } .v3-stat-orange:before { background:var(--v3-orange); }
.v3-stat-card small { display:block; color:var(--v3-muted); font-weight:800; text-transform:uppercase; letter-spacing:.5px; font-size:.74rem; margin-bottom:8px; }
.v3-stat-card b { display:block; color:var(--v3-text); font-weight:900; font-size:1.55rem; line-height:1.1; }
.card-box { background:#fff; padding:18px; border-radius:18px; box-shadow:0 1px 8px rgba(0,0,0,.04); border:1px solid var(--v3-border); margin-bottom:18px; }
.v3-filter-grid { display:grid; grid-template-columns:2fr 1fr 1fr auto auto; gap:10px; align-items:end; }
.form-control, .form-select { border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:.9rem; }
.form-control:focus, .form-select:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.v3-table-wrap { overflow-x:auto; border:1px solid var(--v3-border); border-radius:16px; }
.v3-table { width:100%; border-collapse:collapse; min-width:1180px; background:#fff; }
.v3-table th, .v3-table td { padding:12px 13px; border-bottom:1px solid #f1f5f9; vertical-align:middle; font-size:.86rem; }
.v3-table th { background:#f8fafc; color:#334155; font-weight:900; white-space:nowrap; }
.v3-table td { color:#475569; }
.v3-table tr:last-child td { border-bottom:none; }
.v3-name { font-weight:900; color:var(--v3-text); }
.v3-sub { display:block; color:#94a3b8; font-size:.75rem; margin-top:2px; }
.v3-money { font-weight:900; color:#1e293b; font-family:'Courier New',monospace; white-space:nowrap; }
.v3-badge { display:inline-flex; border-radius:999px; padding:4px 10px; font-size:.72rem; font-weight:900; white-space:nowrap; }
.v3-badge-green { background:#dcfce7; color:#166534; }
.v3-badge-red { background:#fee2e2; color:#991b1b; }
.v3-badge-blue { background:#dbeafe; color:#1d4ed8; }
.v3-badge-orange { background:#ffedd5; color:#9a3412; }
.v3-actions-stack { display:flex; flex-direction:column; gap:7px; min-width:290px; }
.v3-action-row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.v3-inline-form { display:inline; margin:0; }
.v3-empty { padding:24px; text-align:center; color:#64748b; }
.v3-pagination { display:flex; flex-wrap:wrap; gap:6px; margin:0; padding:0; list-style:none; }
.v3-pagination a { display:block; padding:8px 12px; border-radius:10px; background:#f1f5f9; color:#334155; text-decoration:none; font-weight:800; }
.v3-pagination .active a { background:#2563eb; color:#fff; }
.v3-modal-detail { display:flex; justify-content:space-between; gap:16px; padding:10px 0; border-bottom:1px solid #e2e8f0; }
.v3-modal-detail span:first-child { color:#64748b; font-weight:800; }
.v3-modal-detail span:last-child { color:#0f172a; font-weight:700; text-align:right; }
@media(max-width:1200px){ .v3-stat-row{grid-template-columns:repeat(2,1fr);} .v3-filter-grid{grid-template-columns:1fr 1fr;} }
@media(max-width:767px){ .main{padding:14px;} .v3-stat-row,.v3-filter-grid{grid-template-columns:1fr;} .v3-top-actions{width:100%;} .v3-btn{width:100%;} .v3-header{padding:18px;} }
</style>

<div class="main">

    <div class="v3-header">
        <div>
            <h3><i class="fas fa-user-tie"></i> Sales Representative Management</h3>
            <p><?= h(date('l, d F Y')) ?> · Shop ID: <?= (int)$shop_id ?> · Manage SR profile, salary, attendance, advance and leave.</p>
        </div>
        <div class="v3-top-actions">
            <button class="v3-btn v3-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addSrModal">
                <i class="fas fa-plus"></i> Add SR
            </button>
            <a class="v3-btn v3-btn-light" href="staff_attendance.php"><i class="fas fa-clock"></i> Attendance</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="v3-alert <?= ($flash['type'] ?? '') === 'success' ? 'v3-alert-success' : 'v3-alert-error' ?>">
            <?= h($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if (!$srs_table_ready): ?>
        <div class="v3-warning-box"><strong>Table missing:</strong> sales_representatives table পাওয়া যায়নি।</div>
    <?php endif; ?>

    <div class="v3-stat-row">
        <div class="v3-stat-card v3-stat-blue"><small>Total SRs</small><b><?= number_format($total_records) ?></b></div>
        <div class="v3-stat-card v3-stat-green"><small>Active SRs</small><b><?= number_format($active_count) ?></b></div>
        <div class="v3-stat-card v3-stat-purple"><small>Total Customers</small><b><?= number_format($total_customers) ?></b></div>
        <div class="v3-stat-card v3-stat-orange"><small>Total Order Amount</small><b><?= taka($total_order_amount) ?></b></div>
    </div>

    <div class="card-box">
        <form method="GET" class="v3-filter-grid">
            <div>
                <label class="form-label">Search</label>
                <input type="text" name="search" value="<?= h($search) ?>" class="form-control" placeholder="Search by name, phone, email, area...">
            </div>
            <div>
                <label class="form-label">Status</label>
                <select name="filter_status" class="form-select">
                    <option value="">All Status</option>
                    <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div>
                <label class="form-label">Area</label>
                <select name="filter_area" class="form-select">
                    <option value="">All Areas</option>
                    <?php foreach ($areas as $area): $areaName = (string)($area['area_group'] ?? ''); ?>
                        <option value="<?= h($areaName) ?>" <?= $filter_area === $areaName ? 'selected' : '' ?>><?= h($areaName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="v3-btn v3-btn-primary" type="submit"><i class="fas fa-search"></i> Filter</button>
            <a class="v3-btn v3-btn-light" href="<?= h(basename($_SERVER['PHP_SELF'])) ?>"><i class="fas fa-rotate-right"></i> Reset</a>
        </form>
    </div>

    <div class="card-box">
        <div class="v3-table-wrap">
            <table class="v3-table">
                <thead>
                    <tr>
                        <th>SR</th>
                        <th>Contact</th>
                        <th>Area</th>
                        <th>Target</th>
                        <th>Commission</th>
                        <th>Salary</th>
                        <th>Customers</th>
                        <th>Orders</th>
                        <th>Order Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($srs)): ?>
                    <?php foreach ($srs as $sr):
                        $srPayload = [
                            'id' => (int)($sr['id'] ?? 0),
                            'sr_name' => (string)($sr['sr_name'] ?? ''),
                            'phone' => (string)($sr['phone'] ?? ''),
                            'email' => (string)($sr['email'] ?? ''),
                            'address' => (string)($sr['address'] ?? ''),
                            'area_group' => (string)($sr['area_group'] ?? ''),
                            'target_amount' => (string)($sr['target_amount'] ?? '0'),
                            'commission_rate' => (string)($sr['commission_rate'] ?? '0'),
                            'basic_salary' => (string)($sr['basic_salary'] ?? '0'),
                            'status' => (int)($sr['status'] ?? 0),
                            'total_customers' => (int)($sr['total_customers'] ?? 0),
                            'total_orders' => (int)($sr['total_orders'] ?? 0),
                            'total_order_amount' => (string)($sr['total_order_amount'] ?? '0'),
                            'created_at' => (string)($sr['created_at'] ?? ''),
                        ];
                        $payloadJson = h(json_encode($srPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT));
                    ?>
                    <tr>
                        <td>
                            <span class="v3-name"><?= h($sr['sr_name'] ?? '') ?></span>
                            <span class="v3-sub">Created: <?= !empty($sr['created_at']) ? h(date('d M Y', strtotime($sr['created_at']))) : '-' ?></span>
                        </td>
                        <td>
                            <?= h($sr['phone'] ?? '-') ?>
                            <?php if (!empty($sr['email'])): ?><span class="v3-sub"><?= h($sr['email']) ?></span><?php endif; ?>
                        </td>
                        <td><?= h($sr['area_group'] ?: '-') ?></td>
                        <td class="v3-money"><?= taka($sr['target_amount'] ?? 0) ?></td>
                        <td><span class="v3-badge v3-badge-blue"><?= h($sr['commission_rate'] ?? 0) ?>%</span></td>
                        <td class="v3-money"><?= taka($sr['basic_salary'] ?? 0) ?></td>
                        <td><span class="v3-badge v3-badge-blue"><?= number_format((int)($sr['total_customers'] ?? 0)) ?></span></td>
                        <td><span class="v3-badge v3-badge-orange"><?= number_format((int)($sr['total_orders'] ?? 0)) ?></span></td>
                        <td class="v3-money"><?= taka($sr['total_order_amount'] ?? 0) ?></td>
                        <td><span class="v3-badge <?= h(srStatusClass($sr['status'] ?? 0)) ?>"><?= h(srStatusLabel($sr['status'] ?? 0)) ?></span></td>
                        <td>
                            <div class="v3-actions-stack">
                                <div class="v3-action-row">
                                    <button type="button" class="v3-btn v3-btn-primary v3-btn-sm" data-sr-view='<?= $payloadJson ?>'><i class="fas fa-eye"></i> View</button>
                                    <button type="button" class="v3-btn v3-btn-warning v3-btn-sm" data-sr-edit='<?= $payloadJson ?>'><i class="fas fa-edit"></i> Edit</button>
                                    <a class="v3-btn v3-btn-teal v3-btn-sm" href="staff_salary.php?sr_id=<?= (int)$sr['id'] ?>"><i class="fas fa-money-bill"></i> Salary</a>
                                </div>
                                <div class="v3-action-row">
                                    <a class="v3-btn v3-btn-purple v3-btn-sm" href="staff_attendance.php?sr_id=<?= (int)$sr['id'] ?>"><i class="fas fa-clock"></i> Attendance</a>
                                    <a class="v3-btn v3-btn-warning v3-btn-sm" href="staff_advance.php?sr_id=<?= (int)$sr['id'] ?>"><i class="fas fa-hand-holding-dollar"></i> Advance</a>
                                    <a class="v3-btn v3-btn-pink v3-btn-sm" href="staff_leave.php?sr_id=<?= (int)$sr['id'] ?>"><i class="fas fa-calendar-minus"></i> Leave</a>
                                </div>
                                <div class="v3-action-row">
                                    <form method="POST" class="v3-inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="sr_id" value="<?= (int)$sr['id'] ?>">
                                        <button class="v3-btn v3-btn-light v3-btn-sm" type="submit"><i class="fas fa-toggle-on"></i> Toggle</button>
                                    </form>
                                    <form method="POST" class="v3-inline-form" onsubmit="return confirm('<?= h($sr['sr_name'] ?? 'This SR') ?> inactive করতে চাও? Historical data থাকবে।');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_sr">
                                        <input type="hidden" name="sr_id" value="<?= (int)$sr['id'] ?>">
                                        <button class="v3-btn v3-btn-danger v3-btn-sm" type="submit"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11"><div class="v3-empty">No SR found.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="card-box">
            <ul class="v3-pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="<?= $page === $i ? 'active' : '' ?>">
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter_status=<?= urlencode($filter_status) ?>&filter_area=<?= urlencode($filter_area) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- ADD MODAL -->
<div class="modal fade" id="addSrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_sr">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Add New SR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">SR Name *</label><input type="text" name="sr_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Area Group</label><input type="text" name="area_group" class="form-control" list="areaList"></div>
                        <div class="col-md-4"><label class="form-label">Target Amount</label><input type="number" step="0.01" min="0" name="target_amount" class="form-control" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Commission %</label><input type="number" step="0.01" min="0" max="100" name="commission_rate" class="form-control" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Basic Salary</label><input type="number" step="0.01" min="0" name="basic_salary" class="form-control" value="0"></div>
                        <div class="col-md-8"><label class="form-label">Address</label><input type="text" name="address" class="form-control"></div>
                    </div>
                    <datalist id="areaList">
                        <?php foreach ($areas as $area): ?>
                            <option value="<?= h($area['area_group'] ?? '') ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="modal-footer">
                    <button type="button" class="v3-btn v3-btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="v3-btn v3-btn-primary"><i class="fas fa-save"></i> Save SR</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewSrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> SR Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="viewSrBody"></div>
            <div class="modal-footer" id="viewSrFooter"><button type="button" class="v3-btn v3-btn-light" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editSrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" autocomplete="off" id="editSrForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_sr">
                <input type="hidden" name="sr_id" id="edit_sr_id" value="0">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit"></i> Edit SR</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">SR Name *</label><input type="text" name="sr_name" id="edit_sr_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Area Group</label><input type="text" name="area_group" id="edit_area_group" class="form-control" list="areaList"></div>
                        <div class="col-md-4"><label class="form-label">Target Amount</label><input type="number" step="0.01" min="0" name="target_amount" id="edit_target_amount" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Commission %</label><input type="number" step="0.01" min="0" max="100" name="commission_rate" id="edit_commission_rate" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Basic Salary</label><input type="number" step="0.01" min="0" name="basic_salary" id="edit_basic_salary" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Status</label><select name="status" id="edit_status" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                        <div class="col-md-12"><label class="form-label">Address</label><input type="text" name="address" id="edit_address" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="v3-btn v3-btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="v3-btn v3-btn-warning"><i class="fas fa-save"></i> Update SR</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function money(value) {
        const num = Number(value || 0);
        return '৳ ' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];
        });
    }
    function statusLabel(status) { return Number(status) === 1 ? 'Active' : 'Inactive'; }
    function statusBadge(status) { return Number(status) === 1 ? 'v3-badge v3-badge-green' : 'v3-badge v3-badge-red'; }

    document.querySelectorAll('[data-sr-view]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const sr = JSON.parse(btn.getAttribute('data-sr-view') || '{}');
            const body = document.getElementById('viewSrBody');
            const footer = document.getElementById('viewSrFooter');
            body.innerHTML = `
                <div class="v3-modal-detail"><span>Name</span><span>${esc(sr.sr_name)}</span></div>
                <div class="v3-modal-detail"><span>Phone</span><span>${esc(sr.phone || '-')}</span></div>
                <div class="v3-modal-detail"><span>Email</span><span>${esc(sr.email || '-')}</span></div>
                <div class="v3-modal-detail"><span>Area Group</span><span>${esc(sr.area_group || '-')}</span></div>
                <div class="v3-modal-detail"><span>Address</span><span>${esc(sr.address || '-')}</span></div>
                <div class="v3-modal-detail"><span>Target</span><span>${money(sr.target_amount)}</span></div>
                <div class="v3-modal-detail"><span>Commission Rate</span><span>${esc(sr.commission_rate)}%</span></div>
                <div class="v3-modal-detail"><span>Basic Salary</span><span>${money(sr.basic_salary)}</span></div>
                <div class="v3-modal-detail"><span>Customers</span><span>${Number(sr.total_customers || 0).toLocaleString()}</span></div>
                <div class="v3-modal-detail"><span>Orders</span><span>${Number(sr.total_orders || 0).toLocaleString()}</span></div>
                <div class="v3-modal-detail"><span>Order Amount</span><span>${money(sr.total_order_amount)}</span></div>
                <div class="v3-modal-detail"><span>Status</span><span><span class="${statusBadge(sr.status)}">${statusLabel(sr.status)}</span></span></div>
            `;
            footer.innerHTML = `
                <a class="v3-btn v3-btn-purple" href="staff_attendance.php?sr_id=${encodeURIComponent(sr.id)}"><i class="fas fa-clock"></i> Attendance</a>
                <a class="v3-btn v3-btn-teal" href="staff_salary.php?sr_id=${encodeURIComponent(sr.id)}"><i class="fas fa-money-bill"></i> Salary</a>
                <button type="button" class="v3-btn v3-btn-light" data-bs-dismiss="modal">Close</button>
            `;
            new bootstrap.Modal(document.getElementById('viewSrModal')).show();
        });
    });

    document.querySelectorAll('[data-sr-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const sr = JSON.parse(btn.getAttribute('data-sr-edit') || '{}');
            document.getElementById('edit_sr_id').value = sr.id || 0;
            document.getElementById('edit_sr_name').value = sr.sr_name || '';
            document.getElementById('edit_phone').value = sr.phone || '';
            document.getElementById('edit_email').value = sr.email || '';
            document.getElementById('edit_address').value = sr.address || '';
            document.getElementById('edit_area_group').value = sr.area_group || '';
            document.getElementById('edit_target_amount').value = sr.target_amount || 0;
            document.getElementById('edit_commission_rate').value = sr.commission_rate || 0;
            document.getElementById('edit_basic_salary').value = sr.basic_salary || 0;
            document.getElementById('edit_status').value = Number(sr.status) === 1 ? '1' : '0';
            new bootstrap.Modal(document.getElementById('editSrModal')).show();
        });
    });
});
</script>

<?php include "footer.php"; ?>
