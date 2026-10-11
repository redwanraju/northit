<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

date_default_timezone_set('Asia/Dhaka');
include "../config/db.php";
if (isset($conn) && $conn) {
    @$conn->query("SET time_zone = '+06:00'");
}

$shop_id = tenantShopId();
$selfPage = basename($_SERVER['PHP_SELF']);

/* ================= HELPERS ================= */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return ((int)($row[0] ?? 0) > 0);
}

function columnExists($conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $cache[$key] = ((int)($row[0] ?? 0) > 0);
    return $cache[$key];
}

function validateProductTaxonomy($conn, $shop_id, $category_id, $brand_id) {
    $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ? AND shop_id = ? LIMIT 1");
    $stmt->bind_param('ii', $category_id, $shop_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new Exception('Selected category does not belong to your shop.');
    }

    $stmt = $conn->prepare("SELECT id FROM brands WHERE id = ? AND shop_id = ? LIMIT 1");
    $stmt->bind_param('ii', $brand_id, $shop_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new Exception('Selected brand does not belong to your shop.');
    }
}

function jsonResponse($payload) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirectSelf($messageType = null, $message = null) {
    global $selfPage;
    if ($messageType && $message) {
        $_SESSION[$messageType] = $message;
    }
    header("Location: " . $selfPage);
    exit;
}

function uploadProductImage($fieldName, $oldImage = '') {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return $oldImage;
    }

    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $tmpName = $_FILES[$fieldName]['tmp_name'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
    }

    if (!in_array($ext, $allowedExt, true)) {
        throw new Exception('Only JPG, JPEG, PNG, GIF and WEBP images are allowed.');
    }

    if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
        throw new Exception('Invalid image file type.');
    }

    if ($_FILES[$fieldName]['size'] > 2 * 1024 * 1024) {
        throw new Exception('Image size must be under 2MB.');
    }

    $uploadDir = "uploads/products/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newImage = bin2hex(random_bytes(8)) . "." . $ext;
    if (!move_uploaded_file($tmpName, $uploadDir . $newImage)) {
        throw new Exception('Image upload failed. Please try again.');
    }

    if ($oldImage && file_exists($uploadDir . $oldImage)) {
        @unlink($uploadDir . $oldImage);
    }

    return $newImage;
}

function getBrands($conn, $shop_id) {
    $where = ["shop_id = ?"];
    $types = 'i';
    $params = [$shop_id];

    if (columnExists($conn, 'brands', 'status')) {
        $where[] = "(status = 1 OR status = 'active')";
    }

    $sql = "SELECT id, brand_name FROM brands";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY brand_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function getCategories($conn, $shop_id) {
    $where = ["shop_id = ?"];
    $types = 'i';
    $params = [$shop_id];

    if (columnExists($conn, 'categories', 'status')) {
        $where[] = "(status = 1 OR status = 'active')";
    }

    $sql = "SELECT id, category_name FROM categories";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY category_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

/* ================= AJAX HANDLERS ================= */
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    if ($ajax === 'get_products') {
        try {
            $search = trim($_GET['search'] ?? '');
            $categoryId = (int)($_GET['category'] ?? 0);
            $brandId = (int)($_GET['brand'] ?? 0);
            $limit = max(5, min(100, (int)($_GET['limit'] ?? 20)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));

            $sql = "SELECT p.*, c.category_name, b.brand_name
                    FROM products p
                    LEFT JOIN categories c ON c.id = p.category_id AND c.shop_id = p.shop_id
                    LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                    WHERE p.shop_id = ?";
            $params = [$shop_id];
            $types = 'i';

            if ($search !== '') {
                $sql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like);
                $types .= 'sss';
            }

            if ($categoryId > 0) {
                $sql .= " AND p.category_id = ?";
                $params[] = $categoryId;
                $types .= 'i';
            }

            if ($brandId > 0) {
                $sql .= " AND p.brand_id = ?";
                $params[] = $brandId;
                $types .= 'i';
            }

            $countSql = "SELECT COUNT(*) AS total FROM (" . $sql . ") x";
            $countStmt = $conn->prepare($countSql);
            $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

            $valueSql = "SELECT COALESCE(SUM(COALESCE(p.stock,0) * COALESCE(p.purchase_price,0)),0) AS total_value
                         FROM products p
                         LEFT JOIN categories c ON c.id = p.category_id AND c.shop_id = p.shop_id
                         LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                         WHERE p.shop_id = ?";
            $valueParams = [$shop_id];
            $valueTypes = 'i';
            if ($search !== '') {
                $valueSql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
                array_push($valueParams, $like, $like, $like);
                $valueTypes .= 'sss';
            }
            if ($categoryId > 0) {
                $valueSql .= " AND p.category_id = ?";
                $valueParams[] = $categoryId;
                $valueTypes .= 'i';
            }
            if ($brandId > 0) {
                $valueSql .= " AND p.brand_id = ?";
                $valueParams[] = $brandId;
                $valueTypes .= 'i';
            }
            $valueStmt = $conn->prepare($valueSql);
            $valueStmt->bind_param($valueTypes, ...$valueParams);
            $valueStmt->execute();
            $totalValue = (float)($valueStmt->get_result()->fetch_assoc()['total_value'] ?? 0);

            $sql .= " ORDER BY p.id DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = [];
            while ($row = $res->fetch_assoc()) $data[] = $row;

            jsonResponse([
                'success' => true,
                'data' => $data,
                'total' => $total,
                'total_value' => $totalValue,
                'limit' => $limit,
                'offset' => $offset
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($ajax === 'check_barcode') {
        $barcode = trim($_POST['barcode'] ?? $_GET['barcode'] ?? '');
        if ($barcode === '') jsonResponse(['exists' => false]);

        $stmt = $conn->prepare("SELECT product_name FROM products WHERE barcode = ? AND shop_id = ? LIMIT 1");
        $stmt->bind_param('si', $barcode, $shop_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        jsonResponse(['exists' => (bool)$row, 'product' => $row['product_name'] ?? '']);
    }

    if ($ajax === 'add_brand') {
        try {
            $name = trim($_POST['brand_name'] ?? '');
            if ($name === '') throw new Exception('Brand name is required.');

            $where = "brand_name = ? AND shop_id = ?";
            $types = 'si';
            $params = [$name, $shop_id];
            $stmt = $conn->prepare("SELECT id, brand_name FROM brands WHERE $where LIMIT 1");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if ($existing) jsonResponse(['success' => true, 'id' => (int)$existing['id'], 'name' => $existing['brand_name'], 'message' => 'Brand already exists.']);

            if (columnExists($conn, 'brands', 'status')) {
                $stmt = $conn->prepare("INSERT INTO brands (shop_id, brand_name, status) VALUES (?, ?, 1)");
            } else {
                $stmt = $conn->prepare("INSERT INTO brands (shop_id, brand_name) VALUES (?, ?)");
            }
            $stmt->bind_param('is', $shop_id, $name);
            $stmt->execute();

            jsonResponse(['success' => true, 'id' => (int)$conn->insert_id, 'name' => $name]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    if ($ajax === 'add_category') {
        try {
            $name = trim($_POST['category_name'] ?? '');
            if ($name === '') throw new Exception('Category name is required.');

            $where = "category_name = ? AND shop_id = ?";
            $types = 'si';
            $params = [$name, $shop_id];
            $stmt = $conn->prepare("SELECT id, category_name FROM categories WHERE $where LIMIT 1");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if ($existing) jsonResponse(['success' => true, 'id' => (int)$existing['id'], 'name' => $existing['category_name'], 'message' => 'Category already exists.']);

            if (columnExists($conn, 'categories', 'status')) {
                $stmt = $conn->prepare("INSERT INTO categories (shop_id, category_name, status) VALUES (?, ?, 1)");
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (shop_id, category_name) VALUES (?, ?)");
            }
            $stmt->bind_param('is', $shop_id, $name);
            $stmt->execute();

            jsonResponse(['success' => true, 'id' => (int)$conn->insert_id, 'name' => $name]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

/* ================= SAVE PRODUCT ================= */
if (isset($_POST['save_product'])) {
    try {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $brand_id = (int)($_POST['brand_id'] ?? 0);
        $product_name = trim($_POST['product_name'] ?? '');
        $purchase_price = (float)($_POST['purchase_price'] ?? 0);
        $sale_price = (float)($_POST['sale_price'] ?? 0);
        $stock = (float)($_POST['stock'] ?? $_POST['stock_qty'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');

        if ($product_name === '') throw new Exception('Product name is required.');
        if ($category_id <= 0) throw new Exception('Please select category.');
        if ($brand_id <= 0) throw new Exception('Please select brand.');
        if ($purchase_price < 0 || $sale_price < 0 || $stock < 0) throw new Exception('Price and stock cannot be negative.');
        if ($unit === '') throw new Exception('Please select unit.');
        validateProductTaxonomy($conn, $shop_id, $category_id, $brand_id);

        $sku = "RV-" . strtoupper(bin2hex(random_bytes(4)));
        if ($barcode === '') {
            $barcode = time() . rand(1000, 9999);
        }

        $check = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND shop_id = ? LIMIT 1");
        $check->bind_param('si', $barcode, $shop_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) throw new Exception('Barcode already exists!');

        $image = uploadProductImage('image');

        $stmt = $conn->prepare("INSERT INTO products (shop_id, category_id, brand_id, product_name, sku, barcode, purchase_price, sale_price, stock, unit, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiisssdddss', $shop_id, $category_id, $brand_id, $product_name, $sku, $barcode, $purchase_price, $sale_price, $stock, $unit, $image);
        $stmt->execute();

        redirectSelf('success', 'Product added successfully!');
    } catch (Exception $e) {
        redirectSelf('error', $e->getMessage());
    }
}

/* ================= DELETE PRODUCT ================= */
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];

    $stmt = $conn->prepare("SELECT image FROM products WHERE id = ? AND shop_id = ? LIMIT 1");
    $stmt->bind_param('ii', $delete_id, $shop_id);
    $stmt->execute();
    $imgData = $stmt->get_result()->fetch_assoc();
    if ($imgData && !empty($imgData['image']) && file_exists("uploads/products/" . $imgData['image'])) {
        @unlink("uploads/products/" . $imgData['image']);
    }

    $stmt = $conn->prepare("DELETE FROM products WHERE id = ? AND shop_id = ?");
    $stmt->bind_param('ii', $delete_id, $shop_id);
    $stmt->execute();

    redirectSelf('success', 'Product deleted successfully!');
}

/* ================= UPDATE PRODUCT ================= */
if (isset($_POST['update'])) {
    try {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        $brand_id = (int)($_POST['brand_id'] ?? 0);
        $product_name = trim($_POST['product_name'] ?? '');
        $purchase_price = (float)($_POST['purchase_price'] ?? 0);
        $sale_price = (float)($_POST['sale_price'] ?? 0);
        $stock = (float)($_POST['stock'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');

        if ($product_id <= 0) throw new Exception('Invalid product.');
        if ($product_name === '') throw new Exception('Product name is required.');
        if ($category_id <= 0) throw new Exception('Please select category.');
        if ($brand_id <= 0) throw new Exception('Please select brand.');
        if ($unit === '') throw new Exception('Please select unit.');
        validateProductTaxonomy($conn, $shop_id, $category_id, $brand_id);

        $stmt = $conn->prepare("SELECT image FROM products WHERE id = ? AND shop_id = ? LIMIT 1");
        $stmt->bind_param('ii', $product_id, $shop_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing) throw new Exception('Product not found.');

        $image = uploadProductImage('image', $existing['image'] ?? '');

        $stmt = $conn->prepare("UPDATE products SET category_id = ?, brand_id = ?, product_name = ?, purchase_price = ?, sale_price = ?, stock = ?, unit = ?, image = ? WHERE id = ? AND shop_id = ?");
        $stmt->bind_param('iisdddssii', $category_id, $brand_id, $product_name, $purchase_price, $sale_price, $stock, $unit, $image, $product_id, $shop_id);
        $stmt->execute();

        redirectSelf('success', 'Product updated successfully!');
    } catch (Exception $e) {
        redirectSelf('error', $e->getMessage());
    }
}

$search = $_GET['search'] ?? '';
$category_filter = (int)($_GET['category'] ?? 0);
$brand_filter = (int)($_GET['brand'] ?? 0);
$cat_list = getCategories($conn, $shop_id);
$brand_list = getBrands($conn, $shop_id);
$units = ['KG','GM','PIECE','PACKET','LITRE','BOX','BOSTA','CARTON','DOZEN'];

include "header.php";
include "sidebar.php";
?>

<style>
.main { flex: 1; padding: 20px; background: #f8fafc; min-height: 100vh; }
.card-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 15px; }
.product-img { width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
.badge-sku { background: #0f172a; color: #fff; padding: 4px 8px; border-radius: 20px; font-size: 11px; }
.action-btn { padding: 4px 10px; border-radius: 6px; font-size: 12px; border: none; cursor: pointer; margin-right: 4px; }
.btn-view { background: #3b82f6; color: #fff; }
.btn-edit { background: #f59e0b; color: #fff; }
.btn-delete { background: #ef4444; color: #fff; }
.btn-delete:hover { background: #dc2626; }
.btn-view:hover { background: #2563eb; }
.btn-edit:hover { background: #d97706; }
.view-img { width: 120px; height: 120px; object-fit: cover; border-radius: 12px; border: 3px solid #e2e8f0; }
.view-detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; gap: 14px; }
.view-detail-label { font-weight: 600; color: #64748b; }
.view-detail-value { font-weight: 500; color: #0f172a; text-align: right; }
.no-data { text-align: center; padding: 50px; color: #94a3b8; font-size: 16px; }
.table-container { position: relative; min-height: 200px; }
.rv-top-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.rv-add-main-btn { width:42px; height:42px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:18px; box-shadow:0 8px 18px rgba(22,163,74,.25); }
.rv-mini-plus { width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; padding:0; }
.rv-form-label-row { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; }
.rv-form-label-row label { margin:0; }
.rv-modal-wide { max-width: 980px; }
.rv-image-preview { display:none; width:70px; height:70px; border-radius:8px; margin-top:8px; object-fit:cover; border:1px solid #e2e8f0; }
.rv-soft-note { font-size: 12px; color:#64748b; margin-top:5px; }
@media (max-width: 768px) {
    .rv-modal-wide { max-width: 96%; }
    .rv-top-actions { width:100%; justify-content:space-between; }
}
</style>

<div class="main">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">📦 Product List</h3>
        <div class="rv-top-actions">
            <button type="button" class="btn btn-success rv-add-main-btn" data-bs-toggle="modal" data-bs-target="#addProductModal" title="Add Product">
                <i class="fas fa-plus"></i>
            </button>
            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="fas fa-plus"></i> Add Product
            </button>
        </div>
    </div>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= h($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= h($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="card-box" style="padding:12px 15px;">
        <form id="filterForm" method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-primary text-white"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control" placeholder="Search by name, barcode, SKU..." value="<?= h($search) ?>" autocomplete="off">
                </div>
            </div>
            <div class="col-md-3">
                <select name="category" id="categoryFilter" class="form-control">
                    <option value="">All Categories</option>
                    <?php foreach ($cat_list as $cl): ?>
                        <option value="<?= (int)$cl['id'] ?>" <?= $category_filter === (int)$cl['id'] ? 'selected' : '' ?>><?= h($cl['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="brand" id="brandFilter" class="form-control">
                    <option value="">All Brands</option>
                    <?php foreach ($brand_list as $bl): ?>
                        <option value="<?= (int)$bl['id'] ?>" <?= $brand_filter === (int)$bl['id'] ? 'selected' : '' ?>><?= h($bl['brand_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <span class="badge bg-dark" style="font-size:0.9rem;" id="totalProducts"><i class="fas fa-spinner fa-spin"></i></span>
                <span class="badge bg-success" style="font-size:0.9rem;" id="totalValue">৳0.00</span>
            </div>
        </form>
    </div>

    <div class="card-box">
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="productTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:8%;">Image</th>
                            <th style="width:20%;">Name</th>
                            <th style="width:12%;">Category</th>
                            <th style="width:10%;">Brand</th>
                            <th style="width:12%;">Barcode</th>
                            <th style="width:8%;">Buy</th>
                            <th style="width:8%;">Sell</th>
                            <th style="width:7%;">Stock</th>
                            <th style="width:10%;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="productBody">
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                                <p class="mt-2 text-muted">Loading products...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="text-center mt-3" id="loadMoreContainer" style="display:none;">
                <button class="btn btn-outline-primary" id="loadMoreBtn" type="button">
                    <i class="fas fa-chevron-down"></i> Load More Products
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================= ADD PRODUCT MODAL ================= -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered rv-modal-wide">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="addProductForm" action="<?= h($selfPage) ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add New Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" class="form-control" placeholder="Product Name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Barcode</label>
                            <input type="text" id="addBarcode" name="barcode" class="form-control" placeholder="Scan Barcode" autocomplete="off">
                            <div class="rv-soft-note">Enter চাপলে barcode duplicate check হবে।</div>
                        </div>
                        <div class="col-md-2">
                            <div class="rv-form-label-row">
                                <label class="form-label">Brand <span class="text-danger">*</span></label>
                                <button type="button" class="btn btn-success btn-sm rv-mini-plus" data-bs-toggle="modal" data-bs-target="#brandModal" data-bs-source="addProductModal" title="Add Brand">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            <select name="brand_id" id="addBrandSelect" class="form-control" required>
                                <option value="">Select Brand</option>
                                <?php foreach ($brand_list as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>"><?= h($b['brand_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <div class="rv-form-label-row">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <button type="button" class="btn btn-success btn-sm rv-mini-plus" data-bs-toggle="modal" data-bs-target="#categoryModal" data-bs-source="addProductModal" title="Add Category">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            <select name="category_id" id="addCategorySelect" class="form-control" required>
                                <option value="">Select Category</option>
                                <?php foreach ($cat_list as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= h($c['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Purchase Price <span class="text-danger">*</span></label>
                            <input type="number" name="purchase_price" class="form-control" placeholder="0.00" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Sale Price <span class="text-danger">*</span></label>
                            <input type="number" name="sale_price" class="form-control" placeholder="0.00" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Opening Stock</label>
                            <input type="number" name="stock" class="form-control" placeholder="0" step="0.01" min="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                            <select name="unit" class="form-control" required>
                                <option value="">Select Unit</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= h($u) ?>"><?= h($u) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Product Image</label>
                            <input type="file" name="image" id="addProductImage" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <img id="addPreview" src="" class="rv-image-preview" alt="Preview">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button name="save_product" value="1" class="btn btn-warning"><i class="fas fa-plus"></i> Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= ADD BRAND MODAL ================= -->
<div class="modal fade" id="brandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Brand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Brand Name</label>
                <input type="text" id="new_brand" class="form-control" placeholder="Brand Name">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="saveBrand">Save Brand</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= ADD CATEGORY MODAL ================= -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Category Name</label>
                <input type="text" id="new_category" class="form-control" placeholder="Category Name">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="saveCategory">Save Category</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= VIEW MODAL ================= -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">👁 Product Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="viewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= EDIT MODAL ================= -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">✏️ Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div id="editModalBody"></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
let categories = <?= json_encode($cat_list, JSON_UNESCAPED_UNICODE) ?>;
let brands = <?= json_encode($brand_list, JSON_UNESCAPED_UNICODE) ?>;
const units = <?= json_encode($units, JSON_UNESCAPED_UNICODE) ?>;

let currentOffset = 0;
let currentLimit = 20;
let totalProducts = 0;
let isLoading = false;
let hasMore = true;
let productsData = [];

function loadProducts(reset = true) {
    if (isLoading) return;

    if (reset) {
        currentOffset = 0;
        hasMore = true;
        productsData = [];
        $('#productBody').html(`
            <tr>
                <td colspan="9" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                    <p class="mt-2 text-muted">Loading products...</p>
                </td>
            </tr>
        `);
        $('#loadMoreContainer').hide();
    }

    isLoading = true;

    $.ajax({
        url: window.location.pathname,
        type: 'GET',
        dataType: 'json',
        data: {
            ajax: 'get_products',
            search: $('#searchInput').val(),
            category: $('#categoryFilter').val(),
            brand: $('#brandFilter').val(),
            limit: currentLimit,
            offset: currentOffset
        },
        success: function(response) {
            if (response.success) {
                totalProducts = response.total;
                hasMore = (currentOffset + response.data.length) < totalProducts;
                productsData = reset ? response.data : productsData.concat(response.data);
                reset ? renderProducts(response.data) : appendProducts(response.data);
                updateTotal(totalProducts, response.total_value);
                hasMore ? $('#loadMoreContainer').show() : $('#loadMoreContainer').hide();
            } else {
                showTableError(response.message || 'Failed to load products');
            }
            isLoading = false;
        },
        error: function(xhr) {
            showTableError('Error loading products. Please refresh.');
            isLoading = false;
        }
    });
}

function showTableError(message) {
    $('#productBody').html(`
        <tr>
            <td colspan="9" class="text-center text-danger py-4">
                <i class="fas fa-exclamation-triangle fa-2x d-block mb-2"></i>${escapeHtml(message)}
            </td>
        </tr>
    `);
}

function productRowHtml(p) {
    const image = p.image
        ? `<img src="uploads/products/${escapeAttr(p.image)}" class="product-img" alt="${escapeAttr(p.product_name)}">`
        : `<div class="product-img d-flex align-items-center justify-content-center bg-light text-muted" style="font-size:10px;">No Img</div>`;

    return `
        <tr data-id="${p.id}">
            <td>${image}</td>
            <td>${escapeHtml(p.product_name)}</td>
            <td>${escapeHtml(p.category_name || '')}</td>
            <td>${escapeHtml(p.brand_name || '')}</td>
            <td>${escapeHtml(p.barcode || '')}</td>
            <td>৳${Number(p.purchase_price || 0).toFixed(2)}</td>
            <td>৳${Number(p.sale_price || 0).toFixed(2)}</td>
            <td>${Number(p.stock || 0).toLocaleString()}</td>
            <td>
                <button type="button" class="action-btn btn-view" onclick="viewProduct(${p.id})"><i class="fas fa-eye"></i></button>
                <button type="button" class="action-btn btn-edit" onclick="editProduct(${p.id})"><i class="fas fa-edit"></i></button>
                <button type="button" class="action-btn btn-delete" onclick="confirmDelete(${p.id}, '${escapeJs(p.product_name)}')"><i class="fas fa-trash"></i></button>
            </td>
        </tr>
    `;
}

function renderProducts(products) {
    if (!products || products.length === 0) {
        $('#productBody').html(`
            <tr>
                <td colspan="9" class="no-data">
                    <i class="fas fa-box-open fa-3x mb-3"></i><br>
                    No products found.<br>
                    <small>+ button দিয়ে নতুন product add করুন।</small>
                </td>
            </tr>
        `);
        return;
    }
    $('#productBody').html(products.map(productRowHtml).join(''));
}

function appendProducts(products) {
    $('#productBody').append(products.map(productRowHtml).join(''));
}

function viewProduct(id) {
    const product = productsData.find(p => String(p.id) === String(id));
    if (!product) return alert('Product not found!');

    const image = product.image
        ? `<img src="uploads/products/${escapeAttr(product.image)}" class="view-img mb-3" alt="${escapeAttr(product.product_name)}">`
        : `<div class="view-img mb-3 d-flex align-items-center justify-content-center bg-light text-muted">No Image</div>`;

    $('#viewModalBody').html(`
        ${image}
        <h4 class="mb-3">${escapeHtml(product.product_name)}</h4>
        <div class="view-detail-row"><span class="view-detail-label">Barcode</span><span class="view-detail-value">${escapeHtml(product.barcode || '')}</span></div>
        <div class="view-detail-row"><span class="view-detail-label">SKU</span><span class="view-detail-value"><span class="badge-sku">${escapeHtml(product.sku || '')}</span></span></div>
        <div class="view-detail-row"><span class="view-detail-label">Category</span><span class="view-detail-value">${escapeHtml(product.category_name || 'N/A')}</span></div>
        <div class="view-detail-row"><span class="view-detail-label">Brand</span><span class="view-detail-value">${escapeHtml(product.brand_name || 'N/A')}</span></div>
        <div class="view-detail-row"><span class="view-detail-label">Purchase Price</span><span class="view-detail-value">৳${Number(product.purchase_price || 0).toFixed(2)}</span></div>
        <div class="view-detail-row"><span class="view-detail-label">Sale Price</span><span class="view-detail-value">৳${Number(product.sale_price || 0).toFixed(2)}</span></div>
        <div class="view-detail-row"><span class="view-detail-label">Stock Quantity</span><span class="view-detail-value">${Number(product.stock || 0).toLocaleString()} ${escapeHtml(product.unit || '')}</span></div>
    `);
    $('#viewModal').modal('show');
}

function editProduct(id) {
    const product = productsData.find(p => String(p.id) === String(id));
    if (!product) return alert('Product not found!');

    const catOptions = categories.map(c => `<option value="${c.id}" ${String(c.id) === String(product.category_id) ? 'selected' : ''}>${escapeHtml(c.category_name)}</option>`).join('');
    const brandOptions = brands.map(b => `<option value="${b.id}" ${String(b.id) === String(product.brand_id) ? 'selected' : ''}>${escapeHtml(b.brand_name)}</option>`).join('');
    const unitOptions = units.map(u => `<option value="${escapeAttr(u)}" ${u === (product.unit || 'PIECE') ? 'selected' : ''}>${escapeHtml(u)}</option>`).join('');
    const currentImageHtml = product.image
        ? `<div class="mb-2"><img src="uploads/products/${escapeAttr(product.image)}" style="width:80px;height:80px;object-fit:cover;border-radius:8px;" alt="Current Image"><small class="text-muted d-block">Current Image</small></div>`
        : '';

    $('#editModalBody').html(`
        <form method="POST" enctype="multipart/form-data" action="${window.location.pathname}">
            <div class="modal-body">
                <input type="hidden" name="product_id" value="${product.id}">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="product_name" class="form-control" value="${escapeAttr(product.product_name)}" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Barcode</label>
                        <input type="text" name="barcode" class="form-control" value="${escapeAttr(product.barcode || '')}" readonly>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Category <span class="text-danger">*</span></label>
                        <select name="category_id" class="form-control" required>${catOptions}</select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Brand <span class="text-danger">*</span></label>
                        <select name="brand_id" class="form-control" required>${brandOptions}</select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Purchase Price <span class="text-danger">*</span></label>
                        <input type="number" name="purchase_price" class="form-control" value="${Number(product.purchase_price || 0).toFixed(2)}" step="0.01" min="0" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Sale Price <span class="text-danger">*</span></label>
                        <input type="number" name="sale_price" class="form-control" value="${Number(product.sale_price || 0).toFixed(2)}" step="0.01" min="0" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Stock Quantity</label>
                        <input type="number" name="stock" class="form-control" value="${Number(product.stock || 0)}" step="0.01" min="0">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <select name="unit" class="form-control" required>${unitOptions}</select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Product Image</label>
                        ${currentImageHtml}
                        <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="text-muted">Leave empty to keep current image. Max 2MB.</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update" value="1" class="btn btn-primary">💾 Update Product</button>
            </div>
        </form>
    `);
    $('#editModal').modal('show');
}

function confirmDelete(id, productName) {
    if (!confirm('Are you sure you want to delete "' + productName + '"?\n\nThis action cannot be undone!')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.pathname;
    form.style.display = 'none';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_id';
    input.value = id;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function updateTotal(total, value) {
    $('#totalProducts').html(`<i class="fas fa-box"></i> ${total} Products`);
    $('#totalValue').html(`<i class="fas fa-money-bill"></i> ৳${Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`);
}

function addOptionToSelect(selectors, id, name) {
    selectors.forEach(selector => {
        const $select = $(selector);
        if (!$select.length) return;
        if ($select.find(`option[value="${id}"]`).length === 0) {
            $select.append(new Option(name, id));
        }
        $select.val(id);
    });
}

function addFilterOption(selector, id, name) {
    const $select = $(selector);
    if (!$select.length) return;
    if ($select.find(`option[value="${id}"]`).length === 0) {
        $select.append(new Option(name, id));
    }
}

function closeModalAndRestoreParent(childModalId, parentModalId) {
    const childEl = document.getElementById(childModalId);
    bootstrap.Modal.getOrCreateInstance(childEl).hide();

    setTimeout(() => {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        if (parentModalId) bootstrap.Modal.getOrCreateInstance(document.getElementById(parentModalId)).show();
    }, 250);
}

$('#saveBrand').on('click', function() {
    const name = $('#new_brand').val().trim();
    if (!name) return alert('Brand name দিন।');

    $.post(window.location.pathname + '?ajax=add_brand', {brand_name: name}, function(data) {
        if (!data.success) return alert(data.message || 'Brand save failed.');
        brands.push({id: data.id, brand_name: data.name});
        addOptionToSelect(['#addBrandSelect'], data.id, data.name);
        addFilterOption('#brandFilter', data.id, data.name);
        $('#new_brand').val('');
        closeModalAndRestoreParent('brandModal', 'addProductModal');
    }, 'json').fail(function() {
        alert('Brand save failed. Please try again.');
    });
});

$('#saveCategory').on('click', function() {
    const name = $('#new_category').val().trim();
    if (!name) return alert('Category name দিন।');

    $.post(window.location.pathname + '?ajax=add_category', {category_name: name}, function(data) {
        if (!data.success) return alert(data.message || 'Category save failed.');
        categories.push({id: data.id, category_name: data.name});
        addOptionToSelect(['#addCategorySelect'], data.id, data.name);
        addFilterOption('#categoryFilter', data.id, data.name);
        $('#new_category').val('');
        closeModalAndRestoreParent('categoryModal', 'addProductModal');
    }, 'json').fail(function() {
        alert('Category save failed. Please try again.');
    });
});

$('#addBarcode').on('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const barcode = this.value.trim();
    if (!barcode) return;

    $.post(window.location.pathname + '?ajax=check_barcode', {barcode: barcode}, function(data) {
        if (data.exists) {
            alert('Product already exists: ' + data.product);
            $('#addBarcode').focus().select();
        } else {
            $('input[name="product_name"]', '#addProductForm').focus();
        }
    }, 'json');
});

$('#addProductImage').on('change', function() {
    const file = this.files && this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        $('#addPreview').attr('src', e.target.result).show();
    };
    reader.readAsDataURL(file);
});

let searchTimeout;
$('#searchInput, #categoryFilter, #brandFilter').on('input change', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => loadProducts(true), 300);
});

$('#loadMoreBtn').on('click', function() {
    currentOffset += currentLimit;
    loadProducts(false);
});

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
}
function escapeJs(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, ' ');
}

$(document).ready(function() {
    loadProducts(true);
    setTimeout(function() {
        $('.alert').fadeOut('slow', function() { $(this).alert('close'); });
    }, 5000);
});
</script>

<?php include "footer.php"; ?>
