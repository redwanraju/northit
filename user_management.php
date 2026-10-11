<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";

/* ============================================================
   USER MANAGEMENT - FINAL SCHEMA VERSION
   Required users columns:
   id, shop_id, name, email, password, role, status, created_at, updated_at
   PHP 7 compatible
   ============================================================ */

$shop_id = tenantShopId();
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function um_h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function um_redirect($extra = '') {
    $url = 'user_management.php';
    if ($extra !== '') {
        $url .= '?' . ltrim($extra, '?');
    }
    header('Location: ' . $url);
    exit;
}

function um_set_flash($type, $message) {
    $_SESSION['um_flash'] = array('type' => $type, 'message' => $message);
}

function um_get_flash() {
    if (!isset($_SESSION['um_flash'])) {
        return null;
    }
    $flash = $_SESSION['um_flash'];
    unset($_SESSION['um_flash']);
    return $flash;
}

function um_verify_csrf() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        um_set_flash('danger', 'Invalid request token. Please try again.');
        um_redirect();
    }
}

function um_allowed_role($role) {
    return in_array($role, array('admin', 'sr', 'manager'), true) ? $role : 'sr';
}

function um_role_label($role) {
    switch ($role) {
        case 'admin': return 'Admin';
        case 'manager': return 'Manager';
        case 'sr': return 'SR';
        default: return strtoupper((string)$role);
    }
}

function um_role_badge_class($role) {
    switch ($role) {
        case 'admin': return 'um-role-admin';
        case 'manager': return 'um-role-manager';
        case 'sr': return 'um-role-sr';
        default: return 'um-role-default';
    }
}

function um_bind_params($stmt, $types, $params) {
    if ($types === '' || empty($params)) {
        return true;
    }
    $bind = array();
    $bind[] = $types;
    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }
    return call_user_func_array(array($stmt, 'bind_param'), $bind);
}

function um_fetch_one($conn, $sql, $types = '', $params = array()) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    if ($types !== '') {
        um_bind_params($stmt, $types, $params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

function um_scalar($conn, $sql, $types = '', $params = array(), $default = 0) {
    $row = um_fetch_one($conn, $sql, $types, $params);
    if (!$row) {
        return $default;
    }
    $values = array_values($row);
    return isset($values[0]) ? $values[0] : $default;
}

function um_email_exists($conn, $email, $except_id = 0) {
    if ($except_id > 0) {
        $row = um_fetch_one($conn, "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1", 'si', array($email, $except_id));
    } else {
        $row = um_fetch_one($conn, "SELECT id FROM users WHERE email = ? LIMIT 1", 's', array($email));
    }
    return $row ? true : false;
}

function um_active_admin_count($conn, $shop_id) {
    return (int)um_scalar($conn, "SELECT COUNT(*) FROM users WHERE shop_id = ? AND role = 'admin' AND status = 1", 'i', array($shop_id), 0);
}

function um_get_user($conn, $id, $shop_id) {
    return um_fetch_one($conn, "SELECT id, shop_id, name, email, role, status, created_at, updated_at FROM users WHERE id = ? AND shop_id = ? LIMIT 1", 'ii', array($id, $shop_id));
}

/* ============================================================
   HANDLE POST ACTIONS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    um_verify_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    /* ---------------- SAVE USER ---------------- */
    if ($action === 'save_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password_raw = (string)($_POST['password'] ?? '');
        $role = um_allowed_role($_POST['role'] ?? 'sr');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
        $status = $status === 1 ? 1 : 0;

        if ($name === '') {
            um_set_flash('danger', 'User name is required.');
            um_redirect();
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            um_set_flash('danger', 'Valid email is required.');
            um_redirect();
        }
        if ($password_raw === '') {
            um_set_flash('danger', 'Password is required for new user.');
            um_redirect();
        }
        if (um_email_exists($conn, $email, 0)) {
            um_set_flash('danger', 'This email already exists.');
            um_redirect();
        }

        $password = password_hash($password_raw, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            um_set_flash('danger', 'Database error while preparing user insert.');
            um_redirect();
        }
        $stmt->bind_param('issssi', $shop_id, $name, $email, $password, $role, $status);

        if ($stmt->execute()) {
            um_set_flash('success', 'User added successfully.');
        } else {
            um_set_flash('danger', 'User could not be saved. Email may already exist.');
        }
        um_redirect();
    }

    /* ---------------- UPDATE USER ---------------- */
    if ($action === 'update_user') {
        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password_raw = (string)($_POST['password'] ?? '');
        $role = um_allowed_role($_POST['role'] ?? 'sr');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
        $status = $status === 1 ? 1 : 0;

        $old_user = um_get_user($conn, $id, $shop_id);
        if (!$old_user) {
            um_set_flash('danger', 'User not found.');
            um_redirect();
        }
        if ($name === '') {
            um_set_flash('danger', 'User name is required.');
            um_redirect();
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            um_set_flash('danger', 'Valid email is required.');
            um_redirect();
        }
        if (um_email_exists($conn, $email, $id)) {
            um_set_flash('danger', 'This email already exists.');
            um_redirect();
        }

        // Do not deactivate current logged-in user from this page
        if ($current_user_id > 0 && $id === $current_user_id && $status !== 1) {
            um_set_flash('danger', 'You cannot deactivate your own account.');
            um_redirect();
        }

        // Last active admin protection
        if (($old_user['role'] === 'admin' && (int)$old_user['status'] === 1) && ($role !== 'admin' || $status !== 1)) {
            if (um_active_admin_count($conn, $shop_id) <= 1) {
                um_set_flash('danger', 'At least one active admin must remain.');
                um_redirect();
            }
        }

        if ($password_raw !== '') {
            $password = password_hash($password_raw, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, status = ? WHERE id = ? AND shop_id = ?");
            if (!$stmt) {
                um_set_flash('danger', 'Database error while preparing user update.');
                um_redirect();
            }
            $stmt->bind_param('ssssiii', $name, $email, $password, $role, $status, $id, $shop_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ? AND shop_id = ?");
            if (!$stmt) {
                um_set_flash('danger', 'Database error while preparing user update.');
                um_redirect();
            }
            $stmt->bind_param('sssiii', $name, $email, $role, $status, $id, $shop_id);
        }

        if ($stmt->execute()) {
            um_set_flash('success', 'User updated successfully.');
        } else {
            um_set_flash('danger', 'User could not be updated.');
        }
        um_redirect();
    }

    /* ---------------- TOGGLE STATUS ---------------- */
    if ($action === 'toggle_status') {
        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $old_user = um_get_user($conn, $id, $shop_id);
        if (!$old_user) {
            um_set_flash('danger', 'User not found.');
            um_redirect();
        }
        if ($current_user_id > 0 && $id === $current_user_id) {
            um_set_flash('danger', 'You cannot change your own status.');
            um_redirect();
        }
        $new_status = ((int)$old_user['status'] === 1) ? 0 : 1;

        if ($old_user['role'] === 'admin' && (int)$old_user['status'] === 1 && $new_status === 0) {
            if (um_active_admin_count($conn, $shop_id) <= 1) {
                um_set_flash('danger', 'At least one active admin must remain.');
                um_redirect();
            }
        }

        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND shop_id = ?");
        $stmt->bind_param('iii', $new_status, $id, $shop_id);
        if ($stmt->execute()) {
            um_set_flash('success', 'User status updated successfully.');
        } else {
            um_set_flash('danger', 'Status could not be updated.');
        }
        um_redirect();
    }

    /* ---------------- DELETE USER ---------------- */
    if ($action === 'delete_user') {
        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $old_user = um_get_user($conn, $id, $shop_id);
        if (!$old_user) {
            um_set_flash('danger', 'User not found.');
            um_redirect();
        }
        if ($current_user_id > 0 && $id === $current_user_id) {
            um_set_flash('danger', 'You cannot delete your own account.');
            um_redirect();
        }
        if ($old_user['role'] === 'admin' && (int)$old_user['status'] === 1) {
            if (um_active_admin_count($conn, $shop_id) <= 1) {
                um_set_flash('danger', 'At least one active admin must remain.');
                um_redirect();
            }
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND shop_id = ?");
        $stmt->bind_param('ii', $id, $shop_id);
        if ($stmt->execute()) {
            um_set_flash('success', 'User deleted successfully.');
        } else {
            um_set_flash('danger', 'User could not be deleted.');
        }
        um_redirect();
    }
}

/* ============================================================
   FILTER, PAGINATION, DATA FETCH
   ============================================================ */
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$filter_role = trim($_GET['role'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$where = array('shop_id = ?');
$types = 'i';
$params = array($shop_id);

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}
if ($filter_role !== '' && in_array($filter_role, array('admin', 'sr', 'manager'), true)) {
    $where[] = 'role = ?';
    $types .= 's';
    $params[] = $filter_role;
}
if ($filter_status !== '' && in_array($filter_status, array('0', '1'), true)) {
    $where[] = 'status = ?';
    $types .= 'i';
    $params[] = (int)$filter_status;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$total_records = (int)um_scalar($conn, "SELECT COUNT(*) FROM users $where_sql", $types, $params, 0);
$total_pages = $total_records > 0 ? (int)ceil($total_records / $limit) : 1;
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

$user_rows = array();
$list_sql = "SELECT id, shop_id, name, email, role, status, created_at, updated_at FROM users $where_sql ORDER BY id DESC LIMIT ? OFFSET ?";
$list_types = $types . 'ii';
$list_params = $params;
$list_params[] = $limit;
$list_params[] = $offset;
$list_stmt = $conn->prepare($list_sql);
if ($list_stmt) {
    um_bind_params($list_stmt, $list_types, $list_params);
    $list_stmt->execute();
    $list_result = $list_stmt->get_result();
    if ($list_result) {
        while ($row = $list_result->fetch_assoc()) {
            $user_rows[] = $row;
        }
    }
}

$summary_total = (int)um_scalar($conn, "SELECT COUNT(*) FROM users WHERE shop_id = ?", 'i', array($shop_id), 0);
$summary_active = (int)um_scalar($conn, "SELECT COUNT(*) FROM users WHERE shop_id = ? AND status = 1", 'i', array($shop_id), 0);
$summary_admin = (int)um_scalar($conn, "SELECT COUNT(*) FROM users WHERE shop_id = ? AND role = 'admin'", 'i', array($shop_id), 0);
$summary_inactive = (int)um_scalar($conn, "SELECT COUNT(*) FROM users WHERE shop_id = ? AND status = 0", 'i', array($shop_id), 0);

$flash = um_get_flash();

include "header.php";
include "sidebar.php";
?>

<style>
:root{
    --um-primary:#2563eb;
    --um-primary-dark:#1d4ed8;
    --um-success:#10b981;
    --um-danger:#ef4444;
    --um-warning:#f59e0b;
    --um-info:#6366f1;
    --um-dark:#0f172a;
    --um-muted:#64748b;
    --um-soft:#f8fafc;
    --um-border:#e2e8f0;
}
.main{flex:1;padding:20px;background:var(--um-soft);min-height:100vh;}
.um-page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;}
.um-title h3{margin:0;font-weight:800;color:var(--um-dark);}
.um-title p{margin:4px 0 0;color:var(--um-muted);font-size:13px;}
.card-box{background:#fff;padding:16px;border-radius:14px;box-shadow:0 2px 12px rgba(15,23,42,.06);border:1px solid rgba(226,232,240,.7);margin-bottom:15px;}
.um-add-btn{border:0;border-radius:12px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-weight:700;padding:10px 15px;box-shadow:0 8px 20px rgba(37,99,235,.22);}
.um-add-btn:hover{color:#fff;transform:translateY(-1px);}
.um-stat{border-radius:14px;padding:15px;color:#fff;background:linear-gradient(135deg,#0f172a,#1e293b);min-height:92px;}
.um-stat small{opacity:.82;font-weight:600;}
.um-stat h4{margin:6px 0 0;font-size:26px;font-weight:800;}
.um-stat.active{background:linear-gradient(135deg,#059669,#10b981);}
.um-stat.admin{background:linear-gradient(135deg,#7c3aed,#8b5cf6);}
.um-stat.inactive{background:linear-gradient(135deg,#dc2626,#ef4444);}
.um-table thead th{background:#f1f5f9;color:#334155;font-size:12px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--um-border);}
.um-table td,.um-table th{vertical-align:middle;}
.um-user-cell{display:flex;align-items:center;gap:10px;}
.um-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-weight:800;}
.um-user-name{font-weight:750;color:var(--um-dark);}
.um-user-email{font-size:12px;color:var(--um-muted);}
.um-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;}
.um-role-admin{background:#ede9fe;color:#6d28d9;}
.um-role-manager{background:#dbeafe;color:#1d4ed8;}
.um-role-sr{background:#ecfdf5;color:#047857;}
.um-role-default{background:#e2e8f0;color:#334155;}
.um-status-active{background:#dcfce7;color:#15803d;}
.um-status-inactive{background:#fee2e2;color:#b91c1c;}
.um-actions{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-start;}
.um-action-btn{border:0;border-radius:9px;padding:7px 9px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:5px;line-height:1;color:#fff;}
.um-action-view{background:#3b82f6;}
.um-action-edit{background:#f59e0b;}
.um-action-permission{background:#10b981;}
.um-action-status{background:#6366f1;}
.um-action-delete{background:#ef4444;}
.um-action-btn:hover{filter:brightness(.94);color:#fff;text-decoration:none;}
.um-detail-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--um-border);}
.um-detail-row:last-child{border-bottom:0;}
.um-detail-label{font-weight:700;color:var(--um-muted);}
.um-detail-value{font-weight:650;color:var(--um-dark);text-align:right;}
.um-alert{border-radius:12px;padding:12px 14px;margin-bottom:15px;font-weight:650;}
.um-alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.um-alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.um-modal-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,#2563eb,#1d4ed8);margin-right:10px;}
.modal-title-wrap{display:flex;align-items:center;}
@media(max-width:768px){
    .main{padding:10px;}
    .um-page-head{align-items:flex-start;flex-direction:column;}
    .um-actions{min-width:180px;}
    .um-table{font-size:12px;}
}
</style>

<div class="main">

    <div class="um-page-head">
        <div class="um-title">
            <h3><i class="fas fa-users-cog text-primary"></i> User Management</h3>
            <p>Manage admin, manager and SR users from one place.</p>
        </div>
        <button type="button" class="um-add-btn" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-plus-circle"></i> Add User
        </button>
    </div>

    <?php if ($flash): ?>
        <div class="um-alert um-alert-<?= um_h($flash['type']) ?>">
            <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <?= um_h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3 mb-2"><div class="um-stat"><small>Total Users</small><h4><?= number_format($summary_total) ?></h4></div></div>
        <div class="col-md-3 mb-2"><div class="um-stat active"><small>Active Users</small><h4><?= number_format($summary_active) ?></h4></div></div>
        <div class="col-md-3 mb-2"><div class="um-stat admin"><small>Admin Users</small><h4><?= number_format($summary_admin) ?></h4></div></div>
        <div class="col-md-3 mb-2"><div class="um-stat inactive"><small>Inactive Users</small><h4><?= number_format($summary_inactive) ?></h4></div></div>
    </div>

    <div class="card-box">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Search by name or email" value="<?= um_h($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Role</label>
                <select name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="manager" <?= $filter_role === 'manager' ? 'selected' : '' ?>>Manager</option>
                    <option value="sr" <?= $filter_role === 'sr' ? 'selected' : '' ?>>SR</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filter</button>
            </div>
            <div class="col-md-2">
                <a href="user_management.php" class="btn btn-secondary w-100"><i class="fas fa-rotate"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="card-box">
        <div class="table-responsive">
            <table class="table table-hover align-middle um-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th style="width:310px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($user_rows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
                <?php foreach ($user_rows as $u): ?>
                    <?php
                        $initial = strtoupper(substr(trim($u['name'] ?? 'U'), 0, 1));
                        $created = !empty($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-';
                        $updated = !empty($u['updated_at']) ? date('d M Y', strtotime($u['updated_at'])) : '-';
                    ?>
                    <tr>
                        <td>
                            <div class="um-user-cell">
                                <div class="um-avatar"><?= um_h($initial) ?></div>
                                <div>
                                    <div class="um-user-name"><?= um_h($u['name']) ?></div>
                                    <div class="um-user-email"><?= um_h($u['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="um-badge <?= um_role_badge_class($u['role']) ?>"><i class="fas fa-user-shield"></i> <?= um_h(um_role_label($u['role'])) ?></span></td>
                        <td>
                            <?php if ((int)$u['status'] === 1): ?>
                                <span class="um-badge um-status-active"><i class="fas fa-circle-check"></i> Active</span>
                            <?php else: ?>
                                <span class="um-badge um-status-inactive"><i class="fas fa-circle-xmark"></i> Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= um_h($created) ?></td>
                        <td><?= um_h($updated) ?></td>
                        <td>
                            <div class="um-actions">
                                <button type="button" class="um-action-btn um-action-view"
                                    data-bs-toggle="modal" data-bs-target="#viewUserModal"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-name="<?= um_h($u['name']) ?>"
                                    data-email="<?= um_h($u['email']) ?>"
                                    data-role="<?= um_h($u['role']) ?>"
                                    data-role-label="<?= um_h(um_role_label($u['role'])) ?>"
                                    data-status="<?= (int)$u['status'] ?>"
                                    data-created="<?= um_h($created) ?>"
                                    data-updated="<?= um_h($updated) ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                <button type="button" class="um-action-btn um-action-edit"
                                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-name="<?= um_h($u['name']) ?>"
                                    data-email="<?= um_h($u['email']) ?>"
                                    data-role="<?= um_h($u['role']) ?>"
                                    data-status="<?= (int)$u['status'] ?>">
                                    <i class="fas fa-pen"></i> Edit
                                </button>

                                <a href="user_permission.php?user_id=<?= (int)$u['id'] ?>" class="um-action-btn um-action-permission">
                                    <i class="fas fa-key"></i> Permission
                                </a>

                                <button type="button" class="um-action-btn um-action-status"
                                    data-bs-toggle="modal" data-bs-target="#statusUserModal"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-name="<?= um_h($u['name']) ?>"
                                    data-status="<?= (int)$u['status'] ?>">
                                    <i class="fas fa-toggle-on"></i> <?= (int)$u['status'] === 1 ? 'Inactive' : 'Active' ?>
                                </button>

                                <button type="button" class="um-action-btn um-action-delete"
                                    data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-name="<?= um_h($u['name']) ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-box">
        <nav>
            <ul class="pagination mb-0 flex-wrap">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($filter_role) ?>&status=<?= urlencode($filter_status) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

</div>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= um_h($csrf_token) ?>">
                <input type="hidden" name="action" value="save_user">
                <div class="modal-header">
                    <div class="modal-title-wrap">
                        <div class="um-modal-icon"><i class="fas fa-user-plus"></i></div>
                        <h5 class="modal-title">Add New User</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">User Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required autocomplete="new-password">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control" required>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="sr" selected>SR</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control" required>
                                <option value="1" selected>Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW USER MODAL -->
<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <div class="um-modal-icon"><i class="fas fa-eye"></i></div>
                    <h5 class="modal-title">User Details</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="um-detail-row"><span class="um-detail-label">Name</span><span class="um-detail-value" id="viewName"></span></div>
                <div class="um-detail-row"><span class="um-detail-label">Email</span><span class="um-detail-value" id="viewEmail"></span></div>
                <div class="um-detail-row"><span class="um-detail-label">Role</span><span class="um-detail-value" id="viewRole"></span></div>
                <div class="um-detail-row"><span class="um-detail-label">Status</span><span class="um-detail-value" id="viewStatus"></span></div>
                <div class="um-detail-row"><span class="um-detail-label">Created</span><span class="um-detail-value" id="viewCreated"></span></div>
                <div class="um-detail-row"><span class="um-detail-label">Updated</span><span class="um-detail-value" id="viewUpdated"></span></div>
            </div>
            <div class="modal-footer">
                <a href="#" id="viewPermissionLink" class="btn btn-success"><i class="fas fa-key"></i> Permission</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= um_h($csrf_token) ?>">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-header">
                    <div class="modal-title-wrap">
                        <div class="um-modal-icon"><i class="fas fa-pen"></i></div>
                        <h5 class="modal-title">Edit User</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">User Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="editEmail" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep old password" autocomplete="new-password">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Role</label>
                            <select name="role" id="editRole" class="form-control" required>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="sr">SR</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="editStatus" class="form-control" required>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- STATUS MODAL -->
<div class="modal fade" id="statusUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= um_h($csrf_token) ?>">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" id="statusUserId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-toggle-on text-primary"></i> Change User Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Are you sure you want to change status for <b id="statusUserName"></b>?</p>
                    <small class="text-muted" id="statusUserText"></small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Yes, Change</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= um_h($csrf_token) ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-triangle-exclamation text-danger"></i> Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <b id="deleteUserName"></b>?</p>
                    <div class="alert alert-warning mb-0">This action cannot be undone.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    function safeText(value) { return value || '-'; }

    var viewModal = document.getElementById('viewUserModal');
    if (viewModal) {
        viewModal.addEventListener('show.bs.modal', function(event){
            var btn = event.relatedTarget;
            if (!btn) return;
            var id = btn.getAttribute('data-id') || '';
            document.getElementById('viewName').textContent = safeText(btn.getAttribute('data-name'));
            document.getElementById('viewEmail').textContent = safeText(btn.getAttribute('data-email'));
            document.getElementById('viewRole').textContent = safeText(btn.getAttribute('data-role-label'));
            document.getElementById('viewStatus').textContent = btn.getAttribute('data-status') === '1' ? 'Active' : 'Inactive';
            document.getElementById('viewCreated').textContent = safeText(btn.getAttribute('data-created'));
            document.getElementById('viewUpdated').textContent = safeText(btn.getAttribute('data-updated'));
            document.getElementById('viewPermissionLink').setAttribute('href', 'user_permission.php?user_id=' + encodeURIComponent(id));
        });
    }

    var editModal = document.getElementById('editUserModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event){
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('editUserId').value = btn.getAttribute('data-id') || '';
            document.getElementById('editName').value = btn.getAttribute('data-name') || '';
            document.getElementById('editEmail').value = btn.getAttribute('data-email') || '';
            document.getElementById('editRole').value = btn.getAttribute('data-role') || 'sr';
            document.getElementById('editStatus').value = btn.getAttribute('data-status') || '1';
        });
    }

    var statusModal = document.getElementById('statusUserModal');
    if (statusModal) {
        statusModal.addEventListener('show.bs.modal', function(event){
            var btn = event.relatedTarget;
            if (!btn) return;
            var status = btn.getAttribute('data-status') || '1';
            document.getElementById('statusUserId').value = btn.getAttribute('data-id') || '';
            document.getElementById('statusUserName').textContent = btn.getAttribute('data-name') || 'this user';
            document.getElementById('statusUserText').textContent = status === '1' ? 'This user will become inactive.' : 'This user will become active.';
        });
    }

    var deleteModal = document.getElementById('deleteUserModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function(event){
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('deleteUserId').value = btn.getAttribute('data-id') || '';
            document.getElementById('deleteUserName').textContent = btn.getAttribute('data-name') || 'this user';
        });
    }
})();
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    include "footer.php";
} elseif (file_exists(__DIR__ . '/../pos/footer.php')) {
    include "../pos/footer.php";
}
?>
