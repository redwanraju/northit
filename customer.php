<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);


require_once "permission.php";
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../config/db.php";

$shop_id = tenantShopId();

function redirect_self() {
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit;
}

function customer_filters($shop_id, &$params, &$types) {
    $search = trim($_GET['search'] ?? '');
    $group_filter = trim($_GET['group'] ?? '');
    $area_filter = trim($_GET['area'] ?? '');

    $where = " WHERE c.shop_id = ? ";
    $params = [$shop_id];
    $types = "i";

    if ($search !== '') {
        $where .= " AND (
            c.name LIKE ? 
            OR c.phone LIKE ? 
            OR c.shop_name LIKE ? 
            OR c.email LIKE ?
            OR c.address LIKE ?
        )";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sssss";
    }

    if ($group_filter !== '') {
        $where .= " AND c.customer_group = ? ";
        $params[] = $group_filter;
        $types .= "s";
    }

    if ($area_filter !== '') {
        $where .= " AND c.area_group = ? ";
        $params[] = $area_filter;
        $types .= "s";
    }

    return $where;
}

function fetch_area_groups($conn, $shop_id) {
    $stmt = $conn->prepare("SELECT id, area_name FROM area_groups WHERE shop_id = ? ORDER BY area_name ASC");
    $stmt->bind_param("i", $shop_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetch_sr_list($conn, $shop_id) {
    $stmt = $conn->prepare("SELECT id, sr_name FROM sales_representatives WHERE shop_id = ? AND status = 1 ORDER BY sr_name ASC");
    $stmt->bind_param("i", $shop_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function validate_customer_sr($conn, $shop_id, $sr_id) {
    if ($sr_id === null || (int)$sr_id <= 0) {
        return null;
    }
    $sr_id = (int)$sr_id;
    $stmt = $conn->prepare("SELECT id FROM sales_representatives WHERE id = ? AND shop_id = ? AND status = 1 LIMIT 1");
    $stmt->bind_param("ii", $sr_id, $shop_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new Exception("Selected sales representative does not belong to this shop.");
    }
    return $sr_id;
}

/* ================= AJAX CUSTOMER SEARCH ================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');

    try {
        $params = [];
        $types = "";
        $where = customer_filters($shop_id, $params, $types);

        $summarySql = "
            SELECT 
                COUNT(*) AS total,
                COALESCE(SUM(c.due_amount), 0) AS total_due_amount
            FROM customers c
            LEFT JOIN sales_representatives sr ON c.sr_id = sr.id AND sr.shop_id = c.shop_id
            {$where}
        ";
        $summaryStmt = $conn->prepare($summarySql);
        $summaryStmt->bind_param($types, ...$params);
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc();

        $sql = "
            SELECT 
                c.id,
                c.shop_id,
                c.shop_name,
                c.name,
                c.phone,
                c.email,
                c.address,
                c.area_group,
                c.customer_group,
                c.sr_id,
                c.due_amount,
                c.created_at,
                sr.sr_name
            FROM customers c
            LEFT JOIN sales_representatives sr ON c.sr_id = sr.id AND sr.shop_id = c.shop_id
            {$where}
            ORDER BY c.id DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $row['due_amount'] = (float)($row['due_amount'] ?? 0);
            $data[] = $row;
        }

        echo json_encode([
            'success' => true,
            'data' => $data,
            'total' => (int)($summary['total'] ?? 0),
            'total_due_amount' => (float)($summary['total_due_amount'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ================= SAVE AREA GROUP ================= */
if (isset($_POST['save_area_group'])) {
    $area_name = trim($_POST['area_name'] ?? '');

    if ($area_name !== '') {
        try {
            $stmt = $conn->prepare("INSERT INTO area_groups (shop_id, area_name) VALUES (?, ?)");
            $stmt->bind_param("is", $shop_id, $area_name);
            $stmt->execute();
            $_SESSION['success'] = "Area added successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to add area: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Area name is required!";
    }

    redirect_self();
}

/* ================= DELETE AREA GROUP ================= */
if (isset($_POST['delete_area_group_id'])) {
    $area_id = (int)($_POST['delete_area_group_id'] ?? 0);

    try {
        $stmt = $conn->prepare("DELETE FROM area_groups WHERE id = ? AND shop_id = ?");
        $stmt->bind_param("ii", $area_id, $shop_id);
        $stmt->execute();
        $_SESSION['success'] = "Area deleted successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to delete area: " . $e->getMessage();
    }

    redirect_self();
}

/* ================= SAVE CUSTOMER ================= */
if (isset($_POST['save'])) {
    try {
        $shop_name = trim($_POST['shop_name'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $area_group = trim($_POST['area_group'] ?? '');
        $customer_group = trim($_POST['customer_group'] ?? 'retail');
        $sr_id = validate_customer_sr($conn, $shop_id, (($_POST['sr_id'] ?? '') !== '' ? (int)$_POST['sr_id'] : null));
        $initial_due = max(0, (float)($_POST['initial_due'] ?? 0));

        if ($name === '') {
            throw new Exception("Customer name is required!");
        }

        $stmt = $conn->prepare("
            INSERT INTO customers 
                (shop_id, shop_name, name, phone, email, address, area_group, customer_group, sr_id, due_amount)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isssssssid",
            $shop_id,
            $shop_name,
            $name,
            $phone,
            $email,
            $address,
            $area_group,
            $customer_group,
            $sr_id,
            $initial_due
        );
        $stmt->execute();

        $_SESSION['success'] = "Customer added successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to add customer: " . $e->getMessage();
    }

    redirect_self();
}

/* ================= UPDATE CUSTOMER ================= */
if (isset($_POST['update'])) {
    try {
        $id = (int)($_POST['customer_id'] ?? 0);
        $shop_name = trim($_POST['shop_name'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $area_group = trim($_POST['area_group'] ?? '');
        $customer_group = trim($_POST['customer_group'] ?? 'retail');
        $sr_id = validate_customer_sr($conn, $shop_id, (($_POST['sr_id'] ?? '') !== '' ? (int)$_POST['sr_id'] : null));
        if ($id <= 0) {
            throw new Exception("Invalid customer ID!");
        }

        if ($name === '') {
            throw new Exception("Customer name is required!");
        }

        $stmt = $conn->prepare("
            UPDATE customers 
            SET 
                shop_name = ?,
                name = ?,
                phone = ?,
                email = ?,
                address = ?,
                area_group = ?,
                customer_group = ?,
                sr_id = ?
            WHERE id = ? AND shop_id = ?
        ");

        // Customer profile edit does not change due_amount.
        // Due changes only through sales, due collection, or a dedicated adjustment workflow.
        $stmt->bind_param(
            "sssssssiii",
            $shop_name,
            $name,
            $phone,
            $email,
            $address,
            $area_group,
            $customer_group,
            $sr_id,
            $id,
            $shop_id
        );
        $stmt->execute();

        $_SESSION['success'] = "Customer updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to update customer: " . $e->getMessage();
    }

    redirect_self();
}

/* ================= DELETE CUSTOMER ================= */
if (isset($_POST['delete_id'])) {
    try {
        $id = (int)($_POST['delete_id'] ?? 0);

        if ($id <= 0) {
            throw new Exception("Invalid customer ID!");
        }

        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND shop_id = ?");
        $stmt->bind_param("ii", $id, $shop_id);
        $stmt->execute();

        $_SESSION['success'] = "Customer deleted successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to delete customer: " . $e->getMessage();
    }

    redirect_self();
}

$search = $_GET['search'] ?? '';
$group_filter = $_GET['group'] ?? '';
$area_filter = $_GET['area'] ?? '';

$areaGroups = fetch_area_groups($conn, $shop_id);
$srList = fetch_sr_list($conn, $shop_id);

include "header.php";
include "sidebar.php";
?>

<style>
.main { flex: 1; padding: 20px; background: #f8fafc; }
.card-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 15px; }
.badge-id { background: #0f172a; color: #fff; padding: 4px 8px; border-radius: 20px; font-size: 11px; }
.table td, .table th { vertical-align: middle; }
.action-btn { padding: 4px 8px; border-radius: 6px; font-size: 12px; border: none; cursor: pointer; margin: 2px; text-decoration: none; display: inline-block; white-space: nowrap; }
.btn-edit { background: #f59e0b; color: #fff; }
.btn-edit:hover { background: #d97706; color: #fff; }
.btn-ledger { background: #6366f1; color: #fff; }
.btn-ledger:hover { background: #4f46e5; color: #fff; }
.btn-delete { background: #ef4444; color: #fff; }
.btn-delete:hover { background: #dc2626; color: #fff; }
.area-tag { display: inline-flex; align-items: center; gap: 4px; background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin: 2px; }
.area-tag .remove-area { cursor: pointer; color: #ef4444; font-weight: bold; font-size: 14px; border: 0; background: transparent; padding: 0 2px; }
.area-tag .remove-area:hover { color: #dc2626; }
tfoot.table-dark td { background: #0f172a !important; color: #fff; font-size: 14px; padding: 12px 15px; }
.toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
.search-box { position: relative; }
.search-box .clear-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #999; display: none; }
.search-box .clear-btn:hover { color: #333; }
.summary-badge { font-size: 14px; margin-left: 8px; }
.table-action-cell { min-width: 220px; }
.shop-name-cell { font-weight: 700; color: #0f172a; }
.customer-name-cell { font-weight: 400; color: #334155; }
.form-label-shop { font-weight: 700; color: #0f172a; }
.form-label-name { font-weight: 400; color: #334155; }
.small-muted { font-size: 12px; color: #64748b; }
</style>

<div class="main">

    <!-- Toast Messages -->
    <div class="toast-container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="mb-0">
            👥 Customer Management
            <span class="badge-id summary-badge" style="background:#10b981;">
                <i class="fas fa-users"></i> Total Customer: <span id="totalCustomers">0</span>
            </span>
            <span class="badge-id summary-badge" style="background:#ef4444;">
                <i class="fas fa-money-bill-wave"></i>Current Due: <span id="headerTotalDue">৳ 0.00</span>
            </span>
        </h2>

        <div>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCustomerModal" title="Add Customer">
                <i class="fas fa-plus"></i> Add Customer
            </button>
            <button type="button" class="btn btn-info text-white" data-bs-toggle="modal" data-bs-target="#areaGroupModal">
                <i class="fas fa-map-marker-alt"></i> Manage Areas
            </button>
        </div>
    </div>

    <!-- ================= SEARCH FILTERS ================= -->
    <div class="card-box mb-3">
        <div class="row g-2">
            <div class="col-md-5">
                <div class="search-box">
                    <input type="text"
                           class="form-control"
                           id="searchInput"
                           placeholder="🔍 Search by name, phone, shop, email or address"
                           value="<?= htmlspecialchars($search) ?>"
                           autocomplete="off">
                    <span class="clear-btn" id="clearSearch">✕</span>
                </div>
            </div>

            <div class="col-md-3">
                <select id="groupFilter" class="form-select">
                    <option value="">All Group</option>
                    <option value="retail" <?= $group_filter === 'retail' ? 'selected' : '' ?>>Retail</option>
                    <option value="wholesale" <?= $group_filter === 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                    <option value="home_delivery" <?= $group_filter === 'home_delivery' ? 'selected' : '' ?>>Home Delivery</option>
                </select>
            </div>

            <div class="col-md-3">
                <select id="areaFilter" class="form-select">
                    <option value="">All Area</option>
                    <?php foreach ($areaGroups as $area): ?>
                        <option value="<?= htmlspecialchars($area['area_name']) ?>" <?= $area_filter === $area['area_name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($area['area_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <button type="button" class="btn btn-secondary w-100" id="resetFilters" title="Reset filters">
                    <i class="fas fa-undo"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ================= CUSTOMER TABLE ================= -->
    <div class="card-box">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle" id="customerTable">
                <thead class="table-light">
                    <tr>
                        <th>Shop Name</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Area</th>
                        <th>Group</th>
                        <th>SR</th>
                        <th>Current Due</th>
                        <th class="table-action-cell">Action</th>
                    </tr>
                </thead>

                <tbody id="customerTableBody">
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Loading customers...</p>
                        </td>
                    </tr>
                </tbody>

                <tfoot class="table-dark">
                    <tr>
                        <td colspan="8" class="text-end fw-bold">Total:</td>
                        <td class="fw-bold" id="totalDueAmount">৳ 0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<!-- ================= AREA GROUP MODAL ================= -->
<div class="modal fade" id="areaGroupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-map-marker-alt"></i> Manage Area Groups</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <form method="POST" class="mb-3">
                    <div class="input-group">
                        <input type="text" name="area_name" class="form-control" placeholder="Enter new area name" required>
                        <button type="submit" name="save_area_group" class="btn btn-success">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </form>

                <h6 class="mb-2">Existing Areas:</h6>
                <div class="d-flex flex-wrap gap-1">
                    <?php if (count($areaGroups) > 0): ?>
                        <?php foreach ($areaGroups as $area): ?>
                            <span class="area-tag">
                                <?= htmlspecialchars($area['area_name']) ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete area: <?= htmlspecialchars($area['area_name'], ENT_QUOTES) ?>?')">
                                    <input type="hidden" name="delete_area_group_id" value="<?= (int)$area['id'] ?>">
                                    <button type="submit" class="remove-area">&times;</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-muted">No areas added yet.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= ADD CUSTOMER MODAL ================= -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label-shop">Shop Name</label>
                            <input type="text" name="shop_name" class="form-control" placeholder="Shop name">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label-name">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Customer name" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="Phone number">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" placeholder="Email address">
                        </div>

                        <div class="col-md-12 mb-3">
                            <label>Address</label>
                            <input type="text" name="address" class="form-control" placeholder="Address">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Area</label>
                            <select name="area_group" class="form-control">
                                <option value="">Select Area</option>
                                <?php foreach ($areaGroups as $area): ?>
                                    <option value="<?= htmlspecialchars($area['area_name']) ?>">
                                        <?= htmlspecialchars($area['area_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Customer Group</label>
                            <select name="customer_group" class="form-control">
                                <option value="retail">Retail</option>
                                <option value="wholesale">Wholesale</option>
                                <option value="home_delivery">Home Delivery</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Sales Representative</label>
                            <select name="sr_id" class="form-control">
                                <option value="">Direct Customer</option>
                                <?php foreach ($srList as $sr): ?>
                                    <option value="<?= (int)$sr['id'] ?>">
                                        <?= htmlspecialchars($sr['sr_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Initial Due (if any)</label>
                            <input type="number" step="0.01" min="0" name="initial_due" class="form-control" value="0.00">
                            <div class="small-muted">শুধু পুরোনো/import করা বকেয়া থাকলে লিখুন; এটি সরাসরি Current Due হিসেবে সংরক্ষিত হবে।</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= EDIT CUSTOMER MODAL - SINGLE REUSABLE MODAL ================= -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">✏️ Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="editCustomerForm">
                <div class="modal-body">
                    <input type="hidden" name="customer_id" id="edit_customer_id">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label-shop">Shop Name</label>
                            <input type="text" name="shop_name" id="edit_shop_name" class="form-control">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label-name">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>

                        <div class="col-md-12 mb-3">
                            <label>Address</label>
                            <input type="text" name="address" id="edit_address" class="form-control">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Area</label>
                            <select name="area_group" id="edit_area_group" class="form-control"></select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Customer Group</label>
                            <select name="customer_group" id="edit_customer_group" class="form-control">
                                <option value="retail">Retail</option>
                                <option value="wholesale">Wholesale</option>
                                <option value="home_delivery">Home Delivery</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Sales Representative</label>
                            <select name="sr_id" id="edit_sr_id" class="form-control"></select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>Current Due</label>
                            <input type="text" id="edit_due_amount_view" class="form-control" readonly>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <a href="#" id="editLedgerLink" class="btn btn-primary">
                        <i class="fas fa-book"></i> Ledger
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update" class="btn btn-warning">
                        💾 Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="deleteCustomerForm" style="display:none;">
    <input type="hidden" name="delete_id" id="delete_id">
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
const areaGroups = <?= json_encode($areaGroups, JSON_UNESCAPED_UNICODE) ?>;
const srList = <?= json_encode($srList, JSON_UNESCAPED_UNICODE) ?>;

let customersData = [];
let searchTimer = null;

$(document).ready(function() {
    buildEditSelects();
    toggleClearButton();
    loadCustomers();

    $('#searchInput, #groupFilter, #areaFilter').on('input change', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadCustomers, 300);
        toggleClearButton();
    });

    $('#clearSearch').on('click', function() {
        $('#searchInput').val('');
        toggleClearButton();
        loadCustomers();
    });

    $('#resetFilters').on('click', function() {
        $('#searchInput').val('');
        $('#groupFilter').val('');
        $('#areaFilter').val('');
        toggleClearButton();
        loadCustomers();
    });

    setTimeout(function() {
        $('.alert').fadeOut('slow', function() {
            if (typeof $(this).alert === 'function') {
                $(this).alert('close');
            } else {
                $(this).remove();
            }
        });
    }, 5000);
});

function loadCustomers() {
    $('#customerTableBody').html(`
        <tr>
            <td colspan="10" class="text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted">Loading customers...</p>
            </td>
        </tr>
    `);

    $.ajax({
        url: window.location.pathname,
        type: 'GET',
        dataType: 'json',
        timeout: 15000,
        data: {
            ajax: 'search',
            search: $('#searchInput').val(),
            group: $('#groupFilter').val(),
            area: $('#areaFilter').val()
        },
        success: function(response) {
            if (response.success) {
                customersData = response.data || [];
                renderCustomerTable(customersData);
                updateSummary(response);
            } else {
                showTableError(response.message || 'Unknown error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            console.error('Response:', xhr.responseText);
            showTableError(error || 'Please check console for details');
        }
    });
}

function renderCustomerTable(customers) {
    if (!customers || customers.length === 0) {
        $('#customerTableBody').html(`
            <tr>
                <td colspan="10" class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-2x d-block mb-2"></i>
                    No customers found
                </td>
            </tr>
        `);
        return;
    }

    let html = '';

    customers.forEach(function(customer) {
        const customerId = parseInt(customer.id || 0);
        const name = customer.name || '';
        const groupText = formatGroup(customer.customer_group);
        const srName = customer.sr_name
            ? `<span class="badge bg-primary">${escapeHtml(customer.sr_name)}</span>`
            : `<span class="badge bg-secondary">Direct</span>`;

        html += `
            <tr>
                <td class="shop-name-cell">${escapeHtml(customer.shop_name || '-')}</td>
                <td class="customer-name-cell">
                    ${escapeHtml(name)}
                    <div class="small-muted">ID: ${customerId}</div>
                </td>
                <td>${escapeHtml(customer.phone || '')}</td>
                <td>${escapeHtml(customer.email || '')}</td>
                <td>${escapeHtml(customer.address || '')}</td>
                <td>${escapeHtml(customer.area_group || '')}</td>
                <td>${escapeHtml(groupText)}</td>
                <td>${srName}</td>
                <td>${moneyHtml(customer.due_amount)}</td>
                <td class="table-action-cell">
                    <button type="button" class="action-btn btn-edit" onclick="openEditModal(${customerId})">
                        <i class="fas fa-edit"></i> Edit
                    </button>

                    <a class="action-btn btn-ledger" href="cus_ledger.php?customer_id=${customerId}">
    <i class="fas fa-book"></i> Ledger
</a>

                    <button type="button" class="action-btn btn-delete" onclick="confirmDelete(${customerId}, '${jsString(name)}')">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </td>
            </tr>
        `;
    });

    $('#customerTableBody').html(html);
}

function updateSummary(response) {
    const total = parseInt(response.total || 0);
    const totalDueAmount = parseFloat(response.total_due_amount || 0);

    $('#totalCustomers').text(total);
    $('#totalDueAmount').html(moneyHtml(totalDueAmount, true));
    $('#headerTotalDue').text('৳ ' + formatMoney(totalDueAmount));
}

function showTableError(message) {
    $('#customerTableBody').html(`
        <tr>
            <td colspan="10" class="text-center text-danger py-4">
                <i class="fas fa-exclamation-triangle fa-2x d-block mb-2"></i>
                Error loading data: ${escapeHtml(message)}
            </td>
        </tr>
    `);
}

function buildEditSelects() {
    let areaHtml = `<option value="">Select Area</option>`;
    areaGroups.forEach(function(area) {
        areaHtml += `<option value="${escapeHtml(area.area_name)}">${escapeHtml(area.area_name)}</option>`;
    });
    $('#edit_area_group').html(areaHtml);

    let srHtml = `<option value="">Direct Customer</option>`;
    srList.forEach(function(sr) {
        srHtml += `<option value="${parseInt(sr.id)}">${escapeHtml(sr.sr_name)}</option>`;
    });
    $('#edit_sr_id').html(srHtml);
}

window.openEditModal = function(id) {
    const customer = customersData.find(function(item) {
        return parseInt(item.id) === parseInt(id);
    });

    if (!customer) {
        alert('Customer not found. Please reload the page.');
        return;
    }

    $('#edit_customer_id').val(customer.id || '');
    $('#edit_shop_name').val(customer.shop_name || '');
    $('#edit_name').val(customer.name || '');
    $('#edit_phone').val(customer.phone || '');
    $('#edit_email').val(customer.email || '');
    $('#edit_address').val(customer.address || '');
    $('#edit_area_group').val(customer.area_group || '');
    $('#edit_customer_group').val(customer.customer_group || 'retail');
    $('#edit_sr_id').val(customer.sr_id || '');
    $('#edit_due_amount_view').val('৳ ' + formatMoney(customer.due_amount || 0));

    $('#editLedgerLink').attr('href', 'cus_ledger.php?customer_id=' + customer.id + '&id=' + customer.id);

    showModal('editCustomerModal');
};

window.confirmDelete = function(id, customerName) {
    if (confirm('Are you sure you want to delete "' + customerName + '"?\n\nThis action cannot be undone!')) {
        $('#delete_id').val(id);
        $('#deleteCustomerForm').submit();
    }
};

function showModal(modalId) {
    const el = document.getElementById(modalId);

    // Bootstrap 5 support
    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
        return;
    }

    // Bootstrap 4 fallback
    if (window.jQuery && typeof $('#' + modalId).modal === 'function') {
        $('#' + modalId).modal('show');
        return;
    }

    alert('Bootstrap modal JS is not loaded. Please check header/footer.');
}

function toggleClearButton() {
    if ($('#searchInput').val().length > 0) {
        $('#clearSearch').show();
    } else {
        $('#clearSearch').hide();
    }
}

function formatGroup(group) {
    if (!group) return '';
    return String(group).replace(/_/g, ' ').replace(/\b\w/g, function(letter) {
        return letter.toUpperCase();
    });
}

function formatMoney(amount) {
    return Number(amount || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function moneyHtml(amount, lightText = false) {
    amount = parseFloat(amount || 0);

    if (amount > 0) {
        return `<span class="text-danger fw-bold">৳ ${formatMoney(amount)}</span>`;
    }

    if (amount < 0) {
        return `<span class="text-success fw-bold">৳ ${formatMoney(amount)}</span>`;
    }

    return `<span class="${lightText ? 'text-light' : 'text-muted'}">৳ 0.00</span>`;
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function jsString(text) {
    return String(text || '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '&quot;')
        .replace(/\n/g, ' ')
        .replace(/\r/g, ' ');
}
</script>
<?php include "../pos/footer.php"; ?>
