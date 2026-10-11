<?php
// ============================================================
// SECURITY & CONFIGURATION
// ============================================================
session_start();
error_reporting(0); // Production mode
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/error.log'); // Set your path

include "permission.php";
include "auth.php";
requireLogin();

include "../config/db.php";

// ============================================================
// SESSION & VARIABLES
// ============================================================
if(!isset($_SESSION['cart'])){
    $_SESSION['cart'] = [];
}

$shop_id = tenantShopId();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$name = $_SESSION['user_name'] ?? 'User';

if($user_id == 0){
    die("Session expired. Please login again.");
}

// Regenerate CSRF token on login
if(!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_regenerated']) || $_SESSION['csrf_regenerated'] !== true){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_regenerated'] = true;
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// HELPERS
// ============================================================
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyToFloat($value) {
    if ($value === null || $value === '') return 0.0;

    $value = (string)$value;
    $bnDigits = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    $enDigits = ['0','1','2','3','4','5','6','7','8','9'];
    $value = str_replace($bnDigits, $enDigits, $value);
    $value = str_replace(['৳', ',', ' '], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    if ($value === '' || $value === '-' || $value === '.') return 0.0;

    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = array_shift($parts) . '.' . implode('', $parts);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function cartSubtotal($cart) {
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $qty = moneyToFloat($item['qty'] ?? 0);
        $price = moneyToFloat($item['price'] ?? 0);
        if ($qty > 0) {
            // Use BCMath for precision if available
            if (function_exists('bcadd') && function_exists('bcmul')) {
                $subtotal = (float)bcadd((string)$subtotal, bcmul((string)$price, (string)$qty, 2), 2);
            } else {
                $subtotal += ($price * $qty);
            }
        }
    }
    return $subtotal;
}

function safeDiscount($discount, $subtotal) {
    $discount = max(0, moneyToFloat($discount));
    return min($discount, max(0, moneyToFloat($subtotal)));
}

function verifyCsrfToken() {
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';

    if ($session === '' || $posted === '' || !hash_equals($session, $posted)) {
        header('Content-Type: application/json');
        echo json_encode(["success" => false, "message" => "Invalid security token. Please refresh the page."]);
        exit;
    }
}

// ============================================================
// FILTERS - with XSS protection
// ============================================================
$brand_filter = isset($_GET['brand']) ? (int)$_GET['brand'] : 0;
$cat_filter = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$search = isset($_GET['search']) ? h(trim($_GET['search'])) : '';

// ============================================================
// AJAX HANDLERS
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'barcode_scan') {
    header('Content-Type: application/json');
    
    $barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : '';
    
    if ($barcode == '') {
        echo json_encode(["success" => false, "message" => "Invalid barcode"]);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT * FROM products WHERE barcode = ? AND shop_id = ? LIMIT 1");
    $stmt->bind_param("si", $barcode, $shop_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if (!$product) {
        echo json_encode(["success" => false, "message" => "Product Not Found"]);
        exit;
    }
    
    if (($product['stock'] ?? 0) <= 0) {
        echo json_encode(["success" => false, "message" => "Out of Stock!", "stock_warning" => true]);
        exit;
    }
    
    $id = (int)$product['id'];
    
    if (!isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id] = [
            "name" => $product['product_name'],
            "price" => (float)$product['sale_price'],
            "qty" => 1
        ];
    } else {
        // Check if adding more exceeds stock
        $new_qty = $_SESSION['cart'][$id]['qty'] + 1;
        if ($new_qty > $product['stock']) {
            echo json_encode([
                "success" => false, 
                "message" => "Not enough stock! Available: " . $product['stock'],
                "stock_warning" => true
            ]);
            exit;
        }
        $_SESSION['cart'][$id]['qty'] = $new_qty;
    }
    
    $subtotal = cartSubtotal($_SESSION['cart']);
    
    echo json_encode([
        "success" => true,
        "product_name" => $product['product_name'],
        "subtotal" => number_format($subtotal, 2),
        "total_amount" => number_format($subtotal, 2),
        "cart_html" => renderCartItems($_SESSION['cart'], $conn)
    ]);
    exit;
}

if(isset($_GET['ajax']) && $_GET['ajax'] == 'search_products') {
    header('Content-Type: application/json');
    
    $term = isset($_GET['term']) ? trim($_GET['term']) : '';
    
    $sql = "SELECT p.* FROM products p 
            WHERE p.shop_id = ? 
            AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?) 
            ORDER BY p.product_name ASC LIMIT 20";
    
    $search_param = '%' . $term . '%';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $shop_id, $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    echo json_encode(['success' => true, 'products' => $products]);
    exit;
}

if (isset($_POST['ajax_cart']) && $_POST['ajax_cart'] == '1') {
    header('Content-Type: application/json');
    verifyCsrfToken();
    
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add') {
        $id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        
        if ($id <= 0) {
            echo json_encode(["success" => false, "message" => "Invalid product"]);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND shop_id = ?");
        $stmt->bind_param("ii", $id, $shop_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        
        if (!$product) {
            echo json_encode(["success" => false, "message" => "Product not found"]);
            exit;
        }
        
        if (($product['stock'] ?? 0) <= 0) {
            echo json_encode(["success" => false, "message" => "Out of Stock!", "stock_warning" => true]);
            exit;
        }
        
        if (!isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id] = [
                "name" => $product['product_name'],
                "price" => (float)$product['sale_price'],
                "qty" => 1
            ];
        } else {
            // Check if adding more exceeds stock
            $new_qty = $_SESSION['cart'][$id]['qty'] + 1;
            if ($new_qty > $product['stock']) {
                echo json_encode([
                    "success" => false, 
                    "message" => "Not enough stock! Available: " . $product['stock'],
                    "stock_warning" => true
                ]);
                exit;
            }
            $_SESSION['cart'][$id]['qty'] = $new_qty;
        }
    }
    elseif ($action == "update") {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $field = $_POST['field'] ?? '';
        $value = moneyToFloat($_POST['value'] ?? 0);
        
        if ($field == "qty") {
            // Validate stock before updating
            $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ? AND shop_id = ?");
            $stmt->bind_param("ii", $id, $shop_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            
            if ($product && $value > $product['stock']) {
                echo json_encode([
                    "success" => false, 
                    "message" => "Not enough stock! Available: " . $product['stock'],
                    "stock_warning" => true
                ]);
                exit;
            }
            
            if ($value < 0) {
                $_SESSION['cart'][$id]['qty'] = 0;
            } else {
                $_SESSION['cart'][$id]['qty'] = $value;
            }
        }
        if ($field == "price") {
            $_SESSION['cart'][$id]['price'] = max(0, $value);
        }
    }
    elseif ($action == "remove") {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        unset($_SESSION['cart'][$id]);
    }
    elseif ($action == "clear") {
        $_SESSION['cart'] = [];
        unset($_SESSION['discount_amount']);
        unset($_SESSION['paid_amount']);
    }
    
    $subtotal = cartSubtotal($_SESSION['cart']);
    
    echo json_encode([
        "success" => true,
        "subtotal" => number_format($subtotal, 2),
        "total_amount" => number_format($subtotal, 2),
        "cart_html" => renderCartItems($_SESSION['cart'], $conn)
    ]);
    exit;
}

if (isset($_POST['ajax_totals']) && $_POST['ajax_totals'] == '1') {
    header('Content-Type: application/json');
    verifyCsrfToken();
    
    $_SESSION['discount_amount'] = moneyToFloat($_POST['discount'] ?? 0);
    $_SESSION['paid_amount'] = moneyToFloat($_POST['paid'] ?? 0);
    
    $subtotal = cartSubtotal($_SESSION['cart']);
    
    $discount = safeDiscount($_SESSION['discount_amount'], $subtotal);
    $_SESSION['discount_amount'] = $discount;
    $paid = max(0, moneyToFloat($_SESSION['paid_amount'] ?? 0));
    $total = max(0, $subtotal - $discount);
    $due = $total - $paid;
    
    echo json_encode([
        "success" => true,
        "subtotal" => round($subtotal, 2),
        "discount" => round($discount, 2),
        "total_amount" => round($total, 2),
        "due_amount" => round($due, 2)
    ]);
    exit;
}

if (isset($_POST['ajax_save_customer']) && $_POST['ajax_save_customer'] == '1') {
    header('Content-Type: application/json');
    verifyCsrfToken();
    
    $customer_name = trim($_POST['name'] ?? '');
    $customer_phone = trim($_POST['phone'] ?? '');
    
    if (empty($customer_name)) {
        echo json_encode(["success" => false, "message" => "Name required"]);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO customers (name, phone, shop_id, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("ssi", $customer_name, $customer_phone, $shop_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "customer" => [
                "id" => $stmt->insert_id,
                "name" => $customer_name,
                "phone" => $customer_phone
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to save"]);
    }
    exit;
}

// ============================================================
// HELPER: Render Cart Items - Optimized with single query
// ============================================================
function renderCartItems($cart, $conn) {
    $shop_id = tenantShopId();
    if (empty($cart)) {
        return '<div class="text-center py-10 text-gray-400">
                    <i class="fas fa-shopping-cart text-5xl block mb-3"></i>
                    <p class="text-lg">Cart is empty</p>
                </div>';
    }
    
    // Get all product units in one query
    $ids = array_values(array_filter(array_map('intval', array_keys($cart)), static fn($id) => $id > 0));
    if (!$ids) {
        return '<div class="text-center py-10 text-gray-400"><p class="text-lg">Cart is empty</p></div>';
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids)) . 'i';
    $params = array_merge($ids, [$shop_id]);
    
    $stmt = $conn->prepare("SELECT id, product_name, unit FROM products WHERE id IN ($placeholders) AND shop_id = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $productLookup = [];
    while ($row = $result->fetch_assoc()) {
        $productLookup[(int)$row['id']] = [
            'name' => $row['product_name'],
            'unit' => $row['unit']
        ];
    }
    $stmt->close();
    
    $html = '';
    foreach ($cart as $id => $item) {
        $id = (int)$id;
        if (!isset($productLookup[$id])) {
            continue;
        }
        $qty = (float)($item['qty'] ?? 0);
        $total = $item['price'] * $qty;
        $isZero = ($qty <= 0);
        $unit = h($productLookup[$id]['unit']);
        $productName = h($productLookup[$id]['name']);
        
        $html .= '<div class="grid grid-cols-12 gap-2 py-3 border-b border-gray-200 items-center ' . ($isZero ? 'opacity-50' : '') . '" data-id="' . $id . '">
                    <div class="col-span-2 font-medium text-sm truncate ' . ($isZero ? 'text-gray-500' : 'text-gray-700') . '">' . $productName . '</div>
                    <div class="col-span-3">
                        <input type="text" value="' . number_format($item['price'], 2) . '" 
                               class="w-full px-2 py-1 bg-gray-100 border border-gray-300 rounded text-sm text-gray-800 text-right focus:ring-2 focus:ring-blue-500 focus:border-blue-500 price-input"
                               data-id="' . $id . '"
                               oninput="handlePriceInput(this, ' . $id . ')">
                    </div>
                    <div class="col-span-2">
                        <input type="text"
                               value="' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . '"
                               inputmode="decimal"
                               class="w-14 px-2 py-1 bg-gray-100 border border-gray-300 rounded text-center text-sm text-gray-800 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 ' . ($isZero ? 'border-red-500' : '') . ' qty-input"
                               data-id="' . $id . '"
                               oninput="handleQtyInput(this, ' . $id . ')">
                    </div>
                    <div class="col-span-1 text-xs text-gray-500 text-center">' . $unit . '</div>
                    <div class="col-span-3 font-semibold text-sm ' . ($isZero ? 'text-gray-500' : 'text-blue-600') . ' text-right item-total" data-id="' . $id . '">৳' . number_format($total, 2) . '</div>
                    <div class="col-span-1 text-center">
                        <button onclick="removeItem(' . $id . ')" class="text-red-500 hover:text-red-700 hover:bg-red-100 rounded-full p-1 transition" title="Remove from cart">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>';
    }
    return $html;
}

// ============================================================
// FETCH DATA - Using Prepared Statements
// ============================================================
// Brands
$stmt = $conn->prepare("SELECT id, brand_name FROM brands WHERE shop_id = ? ORDER BY brand_name");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$brands = $stmt->get_result();

// Categories
$stmt = $conn->prepare("SELECT id, category_name FROM categories WHERE shop_id = ? ORDER BY category_name");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$categories = $stmt->get_result();

// Products with filters
$product_sql = "SELECT p.* FROM products p WHERE p.shop_id = ?";
$product_types = "i";
$product_params = [$shop_id];

if ($brand_filter > 0) {
    $product_sql .= " AND p.brand_id = ?";
    $product_types .= "i";
    $product_params[] = $brand_filter;
}

if ($cat_filter > 0) {
    $product_sql .= " AND p.category_id = ?";
    $product_types .= "i";
    $product_params[] = $cat_filter;
}

if ($search !== '') {
    $product_sql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
    $product_types .= "sss";
    $search_param = '%' . $search . '%';
    $product_params[] = $search_param;
    $product_params[] = $search_param;
    $product_params[] = $search_param;
}

$product_sql .= " ORDER BY p.product_name ASC LIMIT 50";
$product_stmt = $conn->prepare($product_sql);
$product_stmt->bind_param($product_types, ...$product_params);
$product_stmt->execute();
$products = $product_stmt->get_result();

// Customers
$stmt = $conn->prepare("SELECT id, name, phone, shop_name, due_amount FROM customers WHERE shop_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$customers = $stmt->get_result();

// ============================================================
// CALCULATIONS
// ============================================================
$subtotal = cartSubtotal($_SESSION['cart']);
$discount = safeDiscount($_SESSION['discount_amount'] ?? 0, $subtotal);
$_SESSION['discount_amount'] = $discount;
$paid = max(0, moneyToFloat($_SESSION['paid_amount'] ?? 0));
$_SESSION['paid_amount'] = $paid;
$total = max(0, $subtotal - $discount);
$due = $total - $paid;
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS - Point of Sale</title>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
<style>
    body {
        background: linear-gradient(135deg, #eaf8ff 0%, #d8f2ff 52%, #cfeeff 100%);
        color: #0c4a6e;
    }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #e5e7eb; border-radius: 3px; }
    ::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: #2563eb; }
    
    input[type=text] { outline: none; }
    
    .fab-container {
        position: fixed;
        bottom: 30px;
        left: 30px;
        z-index: 100;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .fab-btn {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        border: none;
        color: white;
    }
    
    .fab-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 30px rgba(0,0,0,0.2);
    }
    
    .fab-btn-dashboard {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
    }
    .fab-btn-dashboard:hover {
        background: linear-gradient(135deg, #818cf8, #a78bfa);
    }
    
    .fab-btn-add {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }
    .fab-btn-add:hover {
        background: linear-gradient(135deg, #60a5fa, #3b82f6);
    }
    
    .fab-btn-right {
        position: fixed;
        bottom: 30px;
        right: 30px;
        background: linear-gradient(135deg, #10b981, #059669);
        z-index: 100;
    }
    .fab-btn-right:hover {
        background: linear-gradient(135deg, #34d399, #10b981);
    }
    
    .modal-light .modal-box {
        background: #ffffff;
        border: 1px solid #e5e7eb;
    }
    
    .modal-light input,
    .modal-light select {
        background: #f9fafb;
        border: 1px solid #d1d5db;
        color: #1f2937;
    }
    
    .modal-light input:focus,
    .modal-light select:focus {
        border-color: #3b82f6;
        ring: 2px solid #3b82f6;
    }
    
    .modal-light label {
        color: #6b7280;
    }
    
    .toast-notification {
        box-shadow: 0 4px 30px rgba(0,0,0,0.15);
    }

    .select2-container {
        width: 100% !important;
    }
    
    .select2-container--default .select2-selection--single {
        background-color: #ffffff !important;
        border: 1px solid #d1d5db !important;
        border-radius: 0.5rem !important;
        height: 42px !important;
        padding: 4px 8px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #1f2937 !important;
        line-height: 34px !important;
        padding-left: 8px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 8px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow b {
        border-color: #6b7280 transparent transparent transparent !important;
    }
    
    .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
        border-color: transparent transparent #6b7280 transparent !important;
    }
    
    .select2-dropdown {
        background-color: #ffffff !important;
        border: 1px solid #d1d5db !important;
        border-radius: 0.5rem !important;
        margin-top: 4px !important;
    }
    
    .select2-container--default .select2-results__option {
        color: #1f2937 !important;
        padding: 8px 12px !important;
        background-color: transparent !important;
    }
    
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #2563eb !important;
        color: white !important;
    }
    
    .select2-container--default .select2-results__option[aria-selected="true"] {
        background-color: #dbeafe !important;
        color: #1f2937 !important;
    }
    
    .select2-container--default .select2-results__option--highlighted[aria-selected="true"] {
        background-color: #2563eb !important;
        color: white !important;
    }
    
    .select2-container--default .select2-search--dropdown .select2-search__field {
        background-color: #f9fafb !important;
        border: 1px solid #d1d5db !important;
        color: #1f2937 !important;
        border-radius: 0.5rem !important;
        padding: 8px 12px !important;
        width: calc(100% - 24px) !important;
        margin: 8px 12px !important;
    }
    
    .select2-container--default .select2-search--dropdown .select2-search__field:focus {
        border-color: #3b82f6 !important;
        outline: none !important;
        ring: 2px solid #3b82f6;
    }
    
    .select2-container--default .select2-results__option .select2-results__option--disabled {
        color: #9ca3af !important;
    }
    
    .select2-container--default .select2-results > .select2-results__options {
        max-height: 200px !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__clear {
        color: #6b7280 !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__placeholder {
        color: #9ca3af !important;
    }
    
    .barcode-scanning {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
    }
    
    .product-card-zero-stock {
        opacity: 0.6;
        cursor: not-allowed !important;
        pointer-events: none !important;
    }
    .product-card-zero-stock:hover {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2) !important;
    }
    .stock-badge-zero {
        background: #fee2e2;
        color: #dc2626;
        font-weight: 600;
        padding: 1px 8px;
        border-radius: 9999px;
        font-size: 10px;
        display: inline-block;
    }


    /* SKY BLUE POS THEME — visual changes only */
    .pos-shell {
        background: #eefaff !important;
        border-color: #7dd3fc !important;
        box-shadow: 0 12px 34px rgba(14, 165, 233, 0.18) !important;
    }

    .pos-left-panel {
        background: #f4fbff !important;
    }

    .pos-right-panel {
        background: #e7f7ff !important;
        border-color: #a5dcf7 !important;
    }

    .pos-action-bar {
        background: #dff4ff !important;
        border-color: #7dd3fc !important;
        box-shadow: 0 5px 18px rgba(14, 165, 233, 0.10);
    }

    .pos-product-card {
        background: #f8fdff !important;
        border-color: #bae6fd !important;
    }

    .pos-product-card:hover {
        background: #e6f7ff !important;
        border-color: #38bdf8 !important;
        box-shadow: 0 8px 20px rgba(14, 165, 233, 0.14) !important;
    }

    #searchResults,
    #cartItems {
        scrollbar-color: #38bdf8 #dff4ff;
    }

    #searchResults {
        background: #f8fdff !important;
        border-color: #bae6fd !important;
    }

    body input:not([type="hidden"]),
    body select,
    .select2-container--default .select2-selection--single,
    .select2-dropdown,
    .select2-container--default .select2-search--dropdown .select2-search__field {
        background-color: #f8fdff !important;
        border-color: #a5dcf7 !important;
    }

    body input:not([type="hidden"]):focus,
    body select:focus,
    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: #0ea5e9 !important;
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.16) !important;
    }

    body .bg-white {
        background-color: #f8fdff !important;
    }

    body .bg-gray-50 {
        background-color: #eaf8ff !important;
    }

    body .bg-gray-100 {
        background-color: #dff4ff !important;
    }

    body .border-gray-200,
    body .border-gray-300 {
        border-color: #bae6fd !important;
    }

    body .text-blue-500 {
        color: #0ea5e9 !important;
    }

    body .text-blue-600 {
        color: #0284c7 !important;
    }

    body .bg-blue-600 {
        background-color: #0284c7 !important;
    }

    body .hover\:bg-blue-700:hover {
        background-color: #0369a1 !important;
    }

    body .hover\:border-blue-400:hover {
        border-color: #38bdf8 !important;
    }

    body .focus\:border-blue-500:focus {
        border-color: #0ea5e9 !important;
    }

    body .focus\:ring-blue-500:focus {
        --tw-ring-color: rgba(14, 165, 233, 0.38) !important;
    }

    .fab-btn-dashboard {
        background: linear-gradient(135deg, #38bdf8, #0284c7);
    }

    .fab-btn-dashboard:hover {
        background: linear-gradient(135deg, #7dd3fc, #0ea5e9);
    }

    .fab-btn-add {
        background: linear-gradient(135deg, #22d3ee, #0284c7);
    }

    .fab-btn-add:hover {
        background: linear-gradient(135deg, #67e8f9, #0ea5e9);
    }

    #addItemModal > div,
    #customerModal > div {
        background: #eefaff !important;
        border-color: #7dd3fc !important;
    }

</style>
</head>
<body class="bg-sky-100">

<!-- ============================================================ -->
<!-- FLOATING ACTION BUTTONS -->
<!-- ============================================================ -->
<div class="fab-container">
    <a href="index.php" class="fab-btn fab-btn-dashboard" title="Go to Dashboard">
        <i class="fas fa-home"></i>
    </a>
    <button onclick="openAddItemModal()" class="fab-btn fab-btn-add" title="Add New Item">
        <i class="fas fa-plus"></i>
    </button>
</div>

<!-- ============================================================ -->
<!-- SCANNER STATUS -->
<!-- ============================================================ -->
<div id="scannerStatus" class="fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-lg text-sm font-medium hidden shadow-lg">
    <i class="fas fa-qrcode mr-2"></i>
    <span id="scannerStatusText">Scanner ready...</span>
</div>

<!-- ============================================================ -->
<!-- POS WRAPPER - FULL SCREEN -->
<!-- ============================================================ -->
<div class="w-full px-3 py-3">
    <div class="pos-shell grid grid-cols-1 lg:grid-cols-5 gap-3 bg-white rounded-xl shadow-xl border border-gray-200 overflow-hidden">
        
        <!-- LEFT PANEL -->
        <div class="pos-left-panel lg:col-span-3 p-4 md:p-5 overflow-y-auto max-h-[calc(100vh-100px)]">
            
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-cash-register text-blue-500 mr-2"></i> POS
                </h1>
                <div class="flex items-center gap-2">
                    <span id="scannerBadge" class="flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold border border-green-300">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                        </span>
                        Scanner Ready
                    </span>
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-100 rounded-full text-sm text-gray-700 border border-gray-200">
                        <i class="fas fa-user-circle text-blue-500 text-lg"></i>
                        <span class="font-medium"><?= h($name) ?></span>
                    </div>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-2 mb-3">
                <select onchange="applyFilters()" id="brandFilter" 
                        class="px-3 py-2 bg-white border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-w-[120px]">
                    <option value="0">All Brands</option>
                    <?php while($b = $brands->fetch_assoc()){ ?>
                        <option value="<?= $b['id'] ?>" <?= $brand_filter == $b['id'] ? 'selected' : '' ?>>
                            <?= h($b['brand_name']) ?>
                        </option>
                    <?php } ?>
                </select>
                
                <select onchange="applyFilters()" id="categoryFilter" 
                        class="px-3 py-2 bg-white border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-w-[120px]">
                    <option value="0">All Categories</option>
                    <?php while($c = $categories->fetch_assoc()){ ?>
                        <option value="<?= $c['id'] ?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['category_name']) ?>
                        </option>
                    <?php } ?>
                </select>
                
                <div class="flex-1 min-w-[150px] relative">
                    <input type="text" id="searchInput" 
                           placeholder="Search products or scan barcode..." 
                           value="<?= h($search) ?>"
                           oninput="handleSearchInput(this.value)"
                           autofocus
                           class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-sm text-gray-700 placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <i class="fas fa-barcode absolute right-3 top-3 text-gray-400 cursor-pointer hover:text-blue-500 transition" 
                       id="barcodeIcon" title="Click to scan barcode"></i>
                </div>
            </div>
            
            <div id="searchResults" class="hidden mb-3 bg-white border border-gray-200 rounded-lg shadow-xl max-h-72 overflow-y-auto"></div>
            
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2" id="productGrid">
                <?php if($products && $products->num_rows > 0){ 
                    while($p = $products->fetch_assoc()){ 
                        $stock = (int)($p['stock'] ?? 0);
                        $low_stock = ($stock > 0 && $stock < 10);
                        $zero_stock = ($stock <= 0);
                ?>
                    <div onclick="handleProductClick(<?= (int)$p['id'] ?>, <?= $zero_stock ? 'true' : 'false' ?>, this)" 
                         class="pos-product-card bg-white border <?= $zero_stock ? 'border-red-200 product-card-zero-stock' : 'border-gray-200 hover:border-blue-400' ?> rounded-lg p-3 text-center cursor-pointer hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 group">
                        <div class="text-2xl <?= $zero_stock ? 'text-gray-400' : 'text-gray-500 group-hover:text-blue-500' ?> mb-1 transition">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="text-xs font-semibold leading-tight mb-0.5 <?= $zero_stock ? 'text-gray-400' : 'text-gray-700' ?> line-clamp-2"><?= h($p['product_name']) ?></div>
                        <div class="text-base font-bold <?= $zero_stock ? 'text-gray-400' : 'text-blue-600' ?>">৳<?= number_format((float)$p['sale_price'], 2) ?></div>
                        <div class="text-xs <?= $zero_stock ? 'text-red-500' : ($low_stock ? 'text-orange-500' : 'text-gray-500') ?>">
                            <?php if($zero_stock): ?>
                                <span class="stock-badge-zero">Out of Stock</span>
                            <?php else: ?>
                                Stock: <?= $stock ?>
                            <?php endif; ?>
                        </div>
                        <?php if(!empty($p['barcode'])){ ?>
                            <div class="text-[10px] text-gray-400 mt-0.5 font-mono"><?= h($p['barcode']) ?></div>
                        <?php } ?>
                    </div>
                <?php } } else { ?>
                    <div class="col-span-full text-center py-12 text-gray-500">
                        <i class="fas fa-box-open text-5xl block mb-3"></i>
                        <p class="text-lg">No products found</p>
                    </div>
                <?php } ?>
            </div>
        </div>
        
        <!-- RIGHT PANEL - Cart -->
        <div class="pos-right-panel lg:col-span-2 bg-gray-50 p-4 md:p-5 border-t lg:border-t-0 lg:border-l border-gray-200 flex flex-col max-h-[calc(100vh-100px)]">
            
            <div class="mb-3">
                <div class="flex gap-2">
                    <div class="flex-1 min-w-0">
                        <select id="customerSelect" onchange="updateCustomer()" class="w-full">
                            <option value="0">👤 Walk In</option>
                            <?php while($cu = $customers->fetch_assoc()){ ?>
                                <option value="<?= (int)$cu['id'] ?>" data-due="<?= h(number_format(max(0, moneyToFloat($cu['due_amount'] ?? 0)), 2, '.', '')) ?>">
                                    <?= h($cu['name']) ?>
                                    <?php if(!empty($cu['shop_name'])){ ?>
                                        - <?= h($cu['shop_name']) ?>
                                    <?php } ?>
                                    <?php if(!empty($cu['phone'])){ ?>
                                        (<?= h($cu['phone']) ?>)
                                    <?php } ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <button type="button" onclick="openCustomerModal()" 
                            class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition whitespace-nowrap flex-shrink-0">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            
            <div class="grid grid-cols-12 gap-2 px-1 pb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b-2 border-gray-200">
                <div class="col-span-2">Product</div>
                <div class="col-span-3 text-right">Price</div>
                <div class="col-span-2 text-center">Qty</div>
                <div class="col-span-1 text-center">Unit</div>
                <div class="col-span-3 text-right">Total</div>
                <div class="col-span-1"></div>
            </div>
            
            <div class="flex-1 overflow-y-auto mb-3" id="cartItems">
                <?php if(!empty($_SESSION['cart'])){ 
                    // Get all product units in one query for initial render
                    $cart_ids = array_values(array_filter(array_map('intval', array_keys($_SESSION['cart'])), static fn($id) => $id > 0));
                    $placeholders = implode(',', array_fill(0, count($cart_ids), '?'));
                    $types = 'i' . str_repeat('i', count($cart_ids));
                    $params = array_merge([$shop_id], $cart_ids);
                    
                    $stmt = $conn->prepare("SELECT id, product_name, unit FROM products WHERE shop_id = ? AND id IN ($placeholders)");
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    $unitLookup = [];
                    $validProductIds = [];
                    while ($row = $result->fetch_assoc()) {
                        $productId = (int)$row['id'];
                        $validProductIds[$productId] = true;
                        $unitLookup[$productId] = h($row['unit']);
                        // Keep the displayed name tied to the current shop's product record.
                        $_SESSION['cart'][$productId]['name'] = $row['product_name'];
                    }
                    $stmt->close();

                    // Remove stale cart entries left from another shop/account.
                    foreach (array_keys($_SESSION['cart']) as $cartProductId) {
                        if (!isset($validProductIds[(int)$cartProductId])) {
                            unset($_SESSION['cart'][$cartProductId]);
                        }
                    }
                    
                    foreach($_SESSION['cart'] as $id => $item){ 
                        $qty = moneyToFloat($item['qty'] ?? 0);
                        $price = moneyToFloat($item['price'] ?? 0);
                        $total = $price * $qty;
                        $isZero = ($qty <= 0);
                        $unit = $unitLookup[$id] ?? '';
                ?>
                    <div class="grid grid-cols-12 gap-2 py-2.5 border-b border-gray-200 items-center <?= $isZero ? 'opacity-50' : '' ?>" data-id="<?= $id ?>">
                        <div class="col-span-2 font-medium text-sm truncate <?= $isZero ? 'text-gray-400' : 'text-gray-700' ?>"><?= h($item['name']) ?></div>
                        <div class="col-span-3">
                            <input type="text" value="<?= h(number_format($price, 2, '.', '')) ?>" 
                                   class="w-full px-2 py-1 bg-white border border-gray-300 rounded text-sm text-gray-800 text-right focus:ring-2 focus:ring-blue-500 focus:border-blue-500 price-input"
                                   data-id="<?= $id ?>"
                                   oninput="handlePriceInput(this, <?= $id ?>)">
                        </div>
                        <div class="col-span-2 text-center">
                            <input type="text" value="<?= h(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')) ?>"
                                   inputmode="decimal"
                                   class="w-12 px-1 py-1 bg-white border border-gray-300 rounded text-center text-sm text-gray-800 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 <?= $isZero ? 'border-red-500' : '' ?> qty-input"
                                   data-id="<?= $id ?>"
                                   oninput="handleQtyInput(this, <?= $id ?>)">
                        </div>
                        <div class="col-span-1 text-xs text-gray-500 text-center"><?= $unit ?></div>
                        <div class="col-span-3 font-semibold text-sm <?= $isZero ? 'text-gray-400' : 'text-blue-600' ?> text-right item-total" data-id="<?= $id ?>">৳<?= number_format($total, 2) ?></div>
                        <div class="col-span-1 text-center">
                            <button onclick="removeItem(<?= $id ?>)" class="text-red-500 hover:text-red-700 hover:bg-red-100 rounded-full p-1 transition" title="Remove from cart">
                                <i class="fas fa-trash-alt text-sm"></i>
                            </button>
                        </div>
                    </div>
                <?php } } else { ?>
                    <div class="text-center py-10 text-gray-400">
                        <i class="fas fa-shopping-cart text-4xl block mb-2"></i>
                        <p>Cart is empty</p>
                    </div>
                <?php } ?>
            </div>
            
            <div class="border-t-2 border-gray-200 pt-3 space-y-1.5">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="font-semibold text-gray-700" id="subtotalDisplay">৳<?= number_format($subtotal, 2) ?></span>
                </div>
                
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-500">Discount (৳)</span>
                    <div class="flex items-center gap-1">
                        <input type="text" id="discountInput" 
                               value="<?= $discount > 0 ? h(number_format($discount, 2, '.', '')) : '' ?>" 
                               inputmode="decimal"
                               placeholder="0.00"
                               oninput="liveCalculate()"
                               class="w-24 px-2 py-1 bg-white border border-gray-300 rounded text-right text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-500">Paid (৳)</span>
                    <div class="flex items-center gap-1">
                        <input type="text" id="paidInput" 
                               value="<?= $paid > 0 ? h(number_format($paid, 2, '.', '')) : '' ?>" 
                               inputmode="decimal"
                               placeholder="0.00"
                               oninput="liveCalculate()"
                               class="w-24 px-2 py-1 bg-white border border-gray-300 rounded text-right text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div class="flex justify-between text-lg font-bold pt-1.5 border-t-2 border-gray-200">
                    <span class="text-gray-700">Total</span>
                    <span class="text-blue-600" id="totalDisplay">৳<?= number_format($total, 2) ?></span>
                </div>

                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Previous Due</span>
                    <span class="font-semibold text-amber-600" id="previousDueDisplay">৳0.00</span>
                </div>
                
                <div class="flex justify-between text-base font-bold">
                    <span class="text-gray-500">Closing Due</span>
                    <span class="text-red-600" id="dueDisplay">৳<?= number_format($due, 2) ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="pos-action-bar mt-3 bg-white border border-gray-200 rounded-xl p-3 flex flex-col sm:flex-row justify-end items-center gap-2">
        <span id="bottomTotal" class="hidden">৳<?= number_format($total, 2) ?></span>
        <div class="flex gap-2">
            <button onclick="clearCart()" class="px-4 py-2 bg-red-100 text-red-600 rounded-lg font-semibold hover:bg-red-200 transition text-sm flex items-center gap-1.5 border border-red-200">
                <i class="fas fa-trash"></i> Clear
            </button>
            <button onclick="window.print()" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg font-semibold hover:bg-gray-200 transition text-sm flex items-center gap-1.5 border border-gray-200">
                <i class="fas fa-print"></i> PDF
            </button>
            <button onclick="processCheckout()" class="px-5 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 hover:shadow-lg transition text-sm flex items-center gap-1.5">
                <i class="fas fa-credit-card"></i> Checkout
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- ADD ITEM MODAL -->
<!-- ============================================================ -->
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center" id="addItemModal">
    <div class="bg-white border border-gray-200 rounded-xl p-6 w-[500px] max-w-[92%] max-h-[90vh] overflow-y-auto modal-light">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-plus-circle text-blue-500"></i> Add New Item
            </h3>
            <button onclick="closeAddItemModal()" class="text-gray-400 hover:text-gray-600 transition text-2xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="addItemForm" onsubmit="saveNewItem(event)">
            <div class="mb-3">
                <label class="block text-sm font-semibold text-gray-600 mb-1">Product Name *</label>
                <input type="text" id="newProductName" placeholder="Enter product name" required
                       class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">Price (৳) *</label>
                    <input type="number" id="newProductPrice" placeholder="0.00" step="0.01" min="0" required
                           class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">Stock</label>
                    <input type="number" id="newProductStock" placeholder="0" min="0"
                           class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">Barcode</label>
                    <input type="text" id="newProductBarcode" placeholder="Enter barcode"
                           class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">SKU</label>
                    <input type="text" id="newProductSku" placeholder="Enter SKU"
                           class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">Brand</label>
                    <select id="newProductBrand" class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="0">Select Brand</option>
                        <?php $brands->data_seek(0); while($b = $brands->fetch_assoc()){ ?>
                            <option value="<?= $b['id'] ?>"><?= h($b['brand_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-600 mb-1">Category</label>
                    <select id="newProductCategory" class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="0">Select Category</option>
                        <?php $categories->data_seek(0); while($c = $categories->fetch_assoc()){ ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['category_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-2 justify-end mt-5 pt-3 border-t border-gray-200">
                <button type="button" onclick="closeAddItemModal()" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg font-semibold hover:bg-gray-200 transition text-sm">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition text-sm">
                    <i class="fas fa-save"></i> Save Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- CUSTOMER MODAL -->
<!-- ============================================================ -->
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center" id="customerModal">
    <div class="bg-white border border-gray-200 rounded-xl p-6 w-[380px] max-w-[92%] max-h-[90vh] overflow-y-auto modal-light">
        <h3 class="text-xl font-bold mb-4 text-gray-800 flex items-center gap-2">
            <i class="fas fa-user-plus text-blue-500"></i> Add Customer
        </h3>
        
        <div class="mb-3">
            <label class="block text-sm font-semibold text-gray-600 mb-1">Name *</label>
            <input type="text" id="newCustomerName" placeholder="Enter name" 
                   class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        
        <div class="mb-3">
            <label class="block text-sm font-semibold text-gray-600 mb-1">Phone</label>
            <input type="text" id="newCustomerPhone" placeholder="01XXXXXXXXX" 
                   class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        
        <div class="flex gap-2 justify-end mt-5 pt-3 border-t border-gray-200">
            <button onclick="closeCustomerModal()" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg font-semibold hover:bg-gray-200 transition text-sm">
                Cancel
            </button>
            <button onclick="saveCustomer()" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition text-sm">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- HIDDEN FORM -->
<!-- ============================================================ -->
<form id="checkoutForm" method="POST" action="checkout.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
    <input type="hidden" name="customer_id" id="checkoutCustomerId" value="0">
    <input type="hidden" name="subtotal" id="checkoutSubtotal" value="<?= h(number_format($subtotal, 2, '.', '')) ?>">
    <input type="hidden" name="discount" id="checkoutDiscount" value="<?= h(number_format($discount, 2, '.', '')) ?>">
    <input type="hidden" name="total" id="checkoutTotal" value="<?= h(number_format($total, 2, '.', '')) ?>">
    <input type="hidden" name="paid" id="checkoutPaid" value="<?= h(number_format($paid, 2, '.', '')) ?>">
    <input type="hidden" name="previous_due" id="checkoutPreviousDue" value="0.00">
    <input type="hidden" name="due" id="checkoutDue" value="<?= h(number_format($due, 2, '.', '')) ?>">
</form>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
// ============================================================
// CONFIGURATION
// ============================================================
const csrfToken = "<?= h($csrf_token) ?>";
const posEndpoint = window.location.pathname.split('/').pop() || 'pos.php';
let searchTimeout = null;
let totalsTimeout = null;
let barcodeTimeout = null;
let isProcessingBarcode = false;
let isProcessingBarcodeInput = false;
let updateTimeouts = {};
let selectedCustomerPreviousDue = 0;

// ============================================================
// MONEY HELPERS
// ============================================================
function moneyToNumber(value) {
    value = String(value ?? '');

    const bnDigits = {'০':'0','১':'1','২':'2','৩':'3','৪':'4','৫':'5','৬':'6','৭':'7','৮':'8','৯':'9'};
    value = value.replace(/[০-৯]/g, d => bnDigits[d]);
    value = value.replace(/[৳,\s]/g, '');
    value = value.replace(/[^0-9.\-]/g, '');

    const parts = value.split('.');
    if (parts.length > 2) value = parts[0] + '.' + parts.slice(1).join('');

    const num = parseFloat(value);
    return isNaN(num) ? 0 : num;
}

function setMoneyDisplays(subtotal, discount, paid) {
    subtotal = Math.max(0, moneyToNumber(subtotal));
    discount = Math.min(Math.max(0, moneyToNumber(discount)), subtotal);
    paid = Math.max(0, moneyToNumber(paid));

    const total = Math.max(0, subtotal - discount);
    const previousDue = Math.max(0, moneyToNumber(selectedCustomerPreviousDue));
    const closingDue = Math.max(0, previousDue + total - paid);

    document.getElementById('subtotalDisplay').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('totalDisplay').textContent = '৳' + total.toFixed(2);
    document.getElementById('previousDueDisplay').textContent = '৳' + previousDue.toFixed(2);
    document.getElementById('dueDisplay').textContent = '৳' + closingDue.toFixed(2);
    document.getElementById('bottomTotal').textContent = '৳' + total.toFixed(2);

    document.getElementById('checkoutSubtotal').value = subtotal.toFixed(2);
    document.getElementById('checkoutDiscount').value = discount.toFixed(2);
    document.getElementById('checkoutPaid').value = paid.toFixed(2);
    document.getElementById('checkoutPreviousDue').value = previousDue.toFixed(2);
    document.getElementById('checkoutTotal').value = total.toFixed(2);
    document.getElementById('checkoutDue').value = closingDue.toFixed(2);
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

// ============================================================
// HANDLE SEARCH INPUT
// ============================================================
function handleSearchInput(value) {
    if (isProcessingBarcodeInput) return;
    
    const trimmed = value.trim();
    
    if (trimmed.length >= 4 && isBarcodeFormat(trimmed)) {
        if (isProcessingBarcode) return;
        
        showScannerStatus('scanning', 'Scanning barcode...');
        clearTimeout(barcodeTimeout);
        
        barcodeTimeout = setTimeout(() => {
            processBarcode(trimmed);
            document.getElementById('searchInput').value = '';
            document.getElementById('searchInput').focus();
            const input = document.getElementById('searchInput');
            input.classList.add('barcode-scanning');
            setTimeout(() => input.classList.remove('barcode-scanning'), 1000);
        }, 300);
    } else {
        searchProducts(trimmed);
    }
}

// ============================================================
// HANDLE PRICE INPUT
// ============================================================
function handlePriceInput(input, id) {
    const value = moneyToNumber(input.value);
    updateLocalDisplay(id, 'price', value);
    clearTimeout(updateTimeouts['price_' + id]);
    updateTimeouts['price_' + id] = setTimeout(function() {
        updateCart(id, 'price', value);
    }, 600);
}

// ============================================================
// HANDLE QTY INPUT
// ============================================================
function handleQtyInput(input, id) {
    const value = moneyToNumber(input.value);
    updateLocalDisplay(id, 'qty', value);

    clearTimeout(updateTimeouts['qty_' + id]);
    updateTimeouts['qty_' + id] = setTimeout(() => {
        updateCart(id, 'qty', value);
    }, 500);
}

// ============================================================
// UPDATE LOCAL DISPLAY
// ============================================================
function updateLocalDisplay(id, field, value) {
    const item = document.querySelector(`.grid[data-id="${id}"]`);
    if (!item) return;

    const qtyInput = item.querySelector('.qty-input');
    const priceInput = item.querySelector('.price-input');

    const qty = moneyToNumber(qtyInput.value);
    const price = moneyToNumber(priceInput.value);

    const total = qty * price;
    const totalSpan = item.querySelector('.item-total');

    if (totalSpan) {
        totalSpan.textContent = '৳' + total.toFixed(2);
    }

    if (field === 'qty') {
        if (qty <= 0) {
            item.classList.add('opacity-50');
            qtyInput.classList.add('border-red-500');
        } else {
            item.classList.remove('opacity-50');
            qtyInput.classList.remove('border-red-500');
        }
    }

    updateSubtotalDisplay();
}

// ============================================================
// UPDATE SUBTOTAL DISPLAY
// ============================================================
function updateSubtotalDisplay() {
    let subtotal = 0;
    document.querySelectorAll('#cartItems .grid').forEach(item => {
        const qtyInput = item.querySelector('.qty-input');
        const priceInput = item.querySelector('.price-input');
        if (qtyInput && priceInput) {
            const qty = moneyToNumber(qtyInput.value);
            const price = moneyToNumber(priceInput.value);
            if (qty > 0) subtotal += (price * qty);
        }
    });
    document.getElementById('subtotalDisplay').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('checkoutSubtotal').value = subtotal.toFixed(2);
    liveCalculate();
}

// ============================================================
// LIVE CALCULATION
// ============================================================
function liveCalculate() {
    clearTimeout(totalsTimeout);
    totalsTimeout = setTimeout(() => {
        const discount = moneyToNumber(document.getElementById('discountInput').value);
        const paid = moneyToNumber(document.getElementById('paidInput').value);
        const subtotal = moneyToNumber(document.getElementById('subtotalDisplay').textContent);
        setMoneyDisplays(subtotal, discount, paid);
        saveTotalsToSession(discount, paid);
    }, 300);
}

// ============================================================
// SAVE TOTALS TO SESSION
// ============================================================
function saveTotalsToSession(discount, paid) {
    const formData = new FormData();
    formData.append('ajax_totals', '1');
    formData.append('discount', Number(discount || 0).toFixed(2));
    formData.append('paid', Number(paid || 0).toFixed(2));
    formData.append('csrf_token', csrfToken);
    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            setMoneyDisplays(
                moneyToNumber(data.subtotal),
                moneyToNumber(data.discount),
                moneyToNumber(paid)
            );
        }
    })
    .catch(err => console.error('Save totals error:', err));
}

// ============================================================
// UPDATE CART
// ============================================================
function updateCart(id, field, value) {
    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('action', 'update');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            if (data.stock_warning) {
                showToast('⚠️ ' + data.message, 'warning');
                // Revert the input
                const item = document.querySelector(`.grid[data-id="${id}"]`);
                if (item) {
                    const qtyInput = item.querySelector('.qty-input');
                    if (qtyInput) {
                        // Get current stock from session or revert
                        location.reload(); // Simple reload to sync
                    }
                }
            } else {
                showToast('❌ ' + data.message, 'error');
            }
            return;
        }
        updateTotals();
    })
    .catch(err => {
        console.error(err);
    });
}

// ============================================================
// CHECK IF TEXT IS BARCODE FORMAT
// ============================================================
function isBarcodeFormat(text) {
    const cleaned = text.replace(/[^0-9A-Z-]/gi, '');
    const numericCount = (cleaned.match(/[0-9]/g) || []).length;
    const totalLength = cleaned.length;
    
    if (totalLength >= 6 && totalLength <= 20 && (numericCount / totalLength) >= 0.7) {
        return true;
    }
    
    if (/^[0-9]{8}$/.test(text) || /^[0-9]{12}$/.test(text) || /^[0-9]{13}$/.test(text)) {
        return true;
    }
    
    return false;
}

// ============================================================
// PROCESS BARCODE
// ============================================================
function processBarcode(barcode) {
    if (isProcessingBarcode) return;
    if (!barcode || barcode.length < 2) {
        showScannerStatus('error', 'Invalid barcode');
        return;
    }
    
    isProcessingBarcode = true;
    isProcessingBarcodeInput = true;
    
    showScannerStatus('processing', 'Processing: ' + barcode);
    
    fetch(`${posEndpoint}?ajax=barcode_scan&barcode=${encodeURIComponent(barcode)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('cartItems').innerHTML = data.cart_html;
                updateTotals();
                showScannerStatus('success', '✅ ' + data.product_name + ' added');
                playScannerBeep(true);
                showToast('✅ ' + data.product_name + ' added to cart', 'success');
            } else {
                showScannerStatus('error', '❌ ' + data.message);
                playScannerBeep(false);
                if (data.stock_warning) {
                    showToast('⚠️ ' + data.message, 'warning');
                } else {
                    showToast('❌ ' + data.message, 'error');
                }
            }
            isProcessingBarcode = false;
            isProcessingBarcodeInput = false;
        })
        .catch(err => {
            console.error('Barcode error:', err);
            showScannerStatus('error', '❌ Scanner error');
            playScannerBeep(false);
            isProcessingBarcode = false;
            isProcessingBarcodeInput = false;
        });
}

// ============================================================
// SCANNER STATUS
// ============================================================
function showScannerStatus(type, message) {
    const status = document.getElementById('scannerStatus');
    const text = document.getElementById('scannerStatusText');
    const badge = document.getElementById('scannerBadge');
    
    status.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-lg text-sm font-medium shadow-lg transition-all duration-300';
    
    switch(type) {
        case 'scanning':
            status.classList.add('bg-blue-100', 'text-blue-700', 'border', 'border-blue-300');
            status.style.display = 'block';
            break;
        case 'processing':
            status.classList.add('bg-yellow-100', 'text-yellow-700', 'border', 'border-yellow-300');
            status.style.display = 'block';
            break;
        case 'success':
            status.classList.add('bg-green-100', 'text-green-700', 'border', 'border-green-300');
            status.style.display = 'block';
            badge.className = 'flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold border border-green-300';
            badge.innerHTML = `
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                </span>
                Scanner Active
            `;
            setTimeout(() => { status.style.display = 'none'; }, 2000);
            break;
        case 'error':
            status.classList.add('bg-red-100', 'text-red-700', 'border', 'border-red-300');
            status.style.display = 'block';
            badge.className = 'flex items-center gap-1.5 px-3 py-1.5 bg-red-100 text-red-700 rounded-full text-xs font-semibold border border-red-300';
            badge.innerHTML = `
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                </span>
                Scanner Error
            `;
            setTimeout(() => {
                status.style.display = 'none';
                badge.className = 'flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold border border-green-300';
                badge.innerHTML = `
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    </span>
                    Scanner Active
                `;
            }, 3000);
            break;
        default:
            status.style.display = 'none';
    }
    
    text.textContent = message;
}

// ============================================================
// SCANNER BEEP
// ============================================================
function playScannerBeep(success) {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        if (success) {
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            gainNode.gain.value = 0.3;
            oscillator.start();
            setTimeout(() => {
                oscillator.stop();
                setTimeout(() => {
                    const osc2 = audioCtx.createOscillator();
                    const gain2 = audioCtx.createGain();
                    osc2.connect(gain2);
                    gain2.connect(audioCtx.destination);
                    osc2.frequency.value = 1000;
                    osc2.type = 'sine';
                    gain2.gain.value = 0.3;
                    osc2.start();
                    setTimeout(() => osc2.stop(), 150);
                }, 150);
            }, 150);
        } else {
            oscillator.frequency.value = 300;
            oscillator.type = 'sawtooth';
            gainNode.gain.value = 0.2;
            oscillator.start();
            setTimeout(() => oscillator.stop(), 500);
        }
    } catch(e) {}
}

// ============================================================
// FILTERS
// ============================================================
function applyFilters() {
    const brand = document.getElementById('brandFilter').value;
    const cat = document.getElementById('categoryFilter').value;
    const search = document.getElementById('searchInput').value;
    let url = posEndpoint + '?';
    if (brand > 0) url += 'brand=' + brand + '&';
    if (cat > 0) url += 'cat=' + cat + '&';
    if (search) url += 'search=' + encodeURIComponent(search);
    window.location.href = url;
}

// ============================================================
// SEARCH PRODUCTS
// ============================================================
function searchProducts(term) {
    clearTimeout(searchTimeout);
    
    if (term.length < 1) {
        document.getElementById('searchResults').classList.add('hidden');
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch(`${posEndpoint}?ajax=search_products&term=${encodeURIComponent(term)}`)
            .then(r => r.json())
            .then(data => {
                const resultsDiv = document.getElementById('searchResults');
                if (!data.success || !data.products.length) {
                    resultsDiv.innerHTML = '<div class="p-3 text-gray-500 text-center text-sm">No products found</div>';
                    resultsDiv.classList.remove('hidden');
                    return;
                }
                let html = '';
                data.products.forEach(p => {
                    const stock = p.stock ?? 0;
                    const isZeroStock = (stock <= 0);
                    html += `
                        <div class="flex justify-between items-center px-3 py-2 border-b border-gray-200 transition text-sm ${isZeroStock ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-50'}" 
                             ${isZeroStock ? '' : `onclick="addToCart(${p.id})"`}
                             style="${isZeroStock ? 'pointer-events: none;' : ''}">
                            <span class="font-medium ${isZeroStock ? 'text-gray-400' : 'text-gray-700'}">${escapeHtml(p.product_name)}</span>
                            <span class="font-bold ${isZeroStock ? 'text-gray-400' : 'text-blue-600'}">৳${parseFloat(p.sale_price).toFixed(2)}</span>
                            ${isZeroStock ? '<span class="text-red-500 text-xs font-semibold">Out of Stock</span>' : ''}
                        </div>
                    `;
                });
                resultsDiv.innerHTML = html;
                resultsDiv.classList.remove('hidden');
            })
            .catch(err => console.error('Search error:', err));
    }, 300);
}

// Close search results
document.addEventListener('click', function(e) {
    if (!e.target.closest('#searchInput')) {
        document.getElementById('searchResults').classList.add('hidden');
    }
});

// ============================================================
// CART OPERATIONS
// ============================================================
function handleProductClick(productId, isZeroStock, element) {
    if (isZeroStock) {
        const productName = element.querySelector('.line-clamp-2')?.textContent || 'Product';
        showStockWarning(productName);
        return;
    }
    addToCart(productId);
}

function showStockWarning(productName) {
    showToast('⚠️ ' + productName + ' is out of stock!', 'warning');
}

function addToCart(productId) {
    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('action', 'add');
    formData.append('product_id', productId);
    formData.append('csrf_token', csrfToken);
    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartItems').innerHTML = data.cart_html;
            updateTotals();
            showToast('✅ Added to cart', 'success');
            document.getElementById('searchResults').classList.add('hidden');
            document.getElementById('searchInput').value = '';
            document.getElementById('searchInput').focus();
            playScannerBeep(true);
        } else {
            if (data.stock_warning) {
                showToast('⚠️ ' + data.message, 'warning');
            } else {
                showToast('❌ ' + data.message, 'error');
            }
        }
    })
    .catch(err => console.error('Add error:', err));
}

function removeItem(id) {
    if (!confirm('Remove this item from cart?')) return;
    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('action', 'remove');
    formData.append('id', id);
    formData.append('csrf_token', csrfToken);
    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartItems').innerHTML = data.cart_html;
            updateTotals();
            showToast('🗑️ Item removed', 'info');
        }
    })
    .catch(err => console.error('Remove error:', err));
}

function clearCart() {
    if (!confirm('Clear entire cart?')) return;
    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('action', 'clear');
    formData.append('csrf_token', csrfToken);
    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartItems').innerHTML = data.cart_html;
            document.getElementById('discountInput').value = '';
            document.getElementById('paidInput').value = '';
            updateTotals();
            showToast('🗑️ Cart cleared', 'info');
            document.getElementById('searchInput').focus();
        }
    })
    .catch(err => console.error('Clear error:', err));
}

// ============================================================
// UPDATE TOTALS
// ============================================================
function updateTotals() {
    const discount = moneyToNumber(document.getElementById('discountInput').value);
    const paid = moneyToNumber(document.getElementById('paidInput').value);
    const formData = new FormData();
    formData.append('ajax_totals', '1');
    formData.append('discount', Number(discount || 0).toFixed(2));
    formData.append('paid', Number(paid || 0).toFixed(2));
    formData.append('csrf_token', csrfToken);
    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            setMoneyDisplays(
                moneyToNumber(data.subtotal),
                moneyToNumber(data.discount),
                moneyToNumber(paid)
            );
        }
    })
    .catch(err => console.error('Update totals error:', err));
}

// ============================================================
// ADD ITEM MODAL
// ============================================================
function openAddItemModal() {
    document.getElementById('addItemModal').classList.remove('hidden');
    document.getElementById('addItemModal').classList.add('flex');
}

function closeAddItemModal() {
    document.getElementById('addItemModal').classList.add('hidden');
    document.getElementById('addItemModal').classList.remove('flex');
    document.getElementById('addItemForm').reset();
    document.getElementById('searchInput').focus();
}

// ============================================================
// SAVE NEW ITEM - WITH BETTER ERROR HANDLING
// ============================================================
function saveNewItem(event) {
    event.preventDefault();
    
    const name = document.getElementById('newProductName').value.trim();
    const price = document.getElementById('newProductPrice').value;
    const stock = document.getElementById('newProductStock').value || 0;
    const barcode = document.getElementById('newProductBarcode').value.trim();
    const sku = document.getElementById('newProductSku').value.trim();
    const brand_id = document.getElementById('newProductBrand').value;
    const category_id = document.getElementById('newProductCategory').value;
    
    // Validation
    if (!name) {
        showToast('❌ Product name is required', 'error');
        document.getElementById('newProductName').focus();
        return;
    }
    
    if (!price || parseFloat(price) <= 0) {
        showToast('❌ Valid price is required', 'error');
        document.getElementById('newProductPrice').focus();
        return;
    }
    
    // Disable submit button
    const submitBtn = document.querySelector('#addItemForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    const formData = new FormData();
    formData.append('action', 'add_product');
    formData.append('name', name);
    formData.append('price', price);
    formData.append('stock', stock);
    formData.append('barcode', barcode);
    formData.append('sku', sku);
    formData.append('brand_id', brand_id);
    formData.append('category_id', category_id);
    formData.append('csrf_token', csrfToken);
    
    // Log the request for debugging
    console.log('Sending product data:', {
        name, price, stock, barcode, sku, brand_id, category_id
    });
    
    fetch('ajax_products.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Response is not JSON');
        }
        return response.json();
    })
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (data.success) {
            showToast('✅ ' + data.message, 'success');
            closeAddItemModal();
            // Reload to show new product
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showToast('❌ ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Save product error:', error);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        showToast('❌ Error: ' + error.message, 'error');
    });
}
// ============================================================
// CUSTOMER
// ============================================================
function updateCustomer() {
    const select = document.getElementById('customerSelect');
    const selectedOption = select.options[select.selectedIndex];

    document.getElementById('checkoutCustomerId').value = select.value;
    selectedCustomerPreviousDue = select.value === '0'
        ? 0
        : Math.max(0, moneyToNumber(selectedOption ? selectedOption.dataset.due : 0));

    const discount = moneyToNumber(document.getElementById('discountInput').value);
    const paid = moneyToNumber(document.getElementById('paidInput').value);
    const subtotal = moneyToNumber(document.getElementById('subtotalDisplay').textContent);
    setMoneyDisplays(subtotal, discount, paid);
}

function openCustomerModal() {
    document.getElementById('customerModal').classList.remove('hidden');
    document.getElementById('customerModal').classList.add('flex');
}

function closeCustomerModal() {
    document.getElementById('customerModal').classList.add('hidden');
    document.getElementById('customerModal').classList.remove('flex');
    document.getElementById('newCustomerName').value = '';
    document.getElementById('newCustomerPhone').value = '';
    document.getElementById('searchInput').focus();
}

function saveCustomer() {
    const name = document.getElementById('newCustomerName').value.trim();
    const phone = document.getElementById('newCustomerPhone').value.trim();
    
    if (!name) {
        showToast('❌ Name is required', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax_save_customer', '1');
    formData.append('name', name);
    formData.append('phone', phone);
    formData.append('csrf_token', csrfToken);
    
    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('customerSelect');
            const option = document.createElement('option');
            option.value = data.customer.id;
            option.dataset.due = '0.00';
            option.textContent = data.customer.name + (data.customer.phone ? ' (' + data.customer.phone + ')' : '');
            select.appendChild(option);
            select.value = data.customer.id;
            document.getElementById('checkoutCustomerId').value = data.customer.id;
            
            $('#customerSelect').select2('destroy');
            $('#customerSelect').select2({
                placeholder: "🔍 Search Customer",
                width: '100%',
                dropdownCssClass: 'bg-white border border-gray-200 rounded-lg text-gray-700',
                containerCssClass: 'w-full',
                theme: 'default'
            });
            $('#customerSelect').val(data.customer.id).trigger('change');
            
            closeCustomerModal();
            showToast('✅ Customer saved', 'success');
        } else {
            showToast('❌ ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Save customer error:', err);
        showToast('❌ Failed to save', 'error');
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCustomerModal();
        closeAddItemModal();
    }
});

// ============================================================
// CHECKOUT
// ============================================================
function processCheckout() {
    let validItem = false;
    let hasValidQty = false;

    document.querySelectorAll('#cartItems .grid').forEach(item => {
        const qtyInput = item.querySelector('.qty-input');
        const priceInput = item.querySelector('.price-input');
        const qty = moneyToNumber(qtyInput ? qtyInput.value : 0);
        const price = moneyToNumber(priceInput ? priceInput.value : 0);
        if (qty > 0 && price >= 0) {
            validItem = true;
            hasValidQty = true;
        }
    });

    if (!validItem || !hasValidQty) {
        showToast('❌ Cart is empty or has invalid items', 'error');
        return;
    }

    clearTimeout(totalsTimeout);
    const discount = moneyToNumber(document.getElementById('discountInput').value);
    const paid = moneyToNumber(document.getElementById('paidInput').value);
    const subtotal = moneyToNumber(document.getElementById('subtotalDisplay').textContent);
    setMoneyDisplays(subtotal, discount, paid);

    const customerId = document.getElementById('checkoutCustomerId').value;
    const subtotalVal = document.getElementById('checkoutSubtotal').value;
    const discountVal = document.getElementById('checkoutDiscount').value;
    const totalVal = document.getElementById('checkoutTotal').value;
    const paidVal = document.getElementById('checkoutPaid').value;
    const previousDueVal = document.getElementById('checkoutPreviousDue').value;
    const dueVal = document.getElementById('checkoutDue').value;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'checkout.php';
    form.target = '_blank';

    const fields = {
        'csrf_token': csrfToken,
        'customer_id': customerId,
        'subtotal': subtotalVal,
        'discount': discountVal,
        'total': totalVal,
        'paid': paidVal,
        'previous_due': previousDueVal,
        'due': dueVal,
        'clear_cart': '1'
    };

    for (const [key, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);

    showToast('📄 Invoice is being generated...', 'info');

    setTimeout(() => {
        const formData = new FormData();
        formData.append('ajax_cart', '1');
        formData.append('action', 'clear');
        formData.append('csrf_token', csrfToken);
        
        fetch(posEndpoint, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('cartItems').innerHTML = data.cart_html;
                document.getElementById('discountInput').value = '';
                document.getElementById('paidInput').value = '';
                updateTotals();
                showToast('✅ Cart cleared successfully', 'success');
                document.getElementById('searchInput').focus();
            }
        })
        .catch(err => console.error('Clear cart error:', err));
    }, 500);
}

// ============================================================
// TOAST
// ============================================================
function showToast(message, type = 'info') {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();
    const colors = {
        success: 'bg-emerald-600',
        error: 'bg-red-600',
        warning: 'bg-amber-600',
        info: 'bg-blue-600'
    };
    const toast = document.createElement('div');
    toast.className = `toast-notification fixed top-4 right-4 z-50 ${colors[type] || colors.info} text-white px-4 py-2.5 rounded-lg shadow-lg flex items-center gap-2 text-sm font-medium transition-all duration-300`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        document.getElementById('searchInput').focus();
    }
    if (e.key === 'F9') {
        e.preventDefault();
        processCheckout();
    }
    if (e.key === 'F8') {
        e.preventDefault();
        clearCart();
    }
    if (e.key === 'F6') {
        e.preventDefault();
        openAddItemModal();
    }
});

// ============================================================
// INITIALIZE SELECT2
// ============================================================
$(document).ready(function () {
    $('#customerSelect').select2({
        placeholder: "🔍 Search Customer",
        width: '100%',
        dropdownCssClass: 'bg-white border border-gray-200 rounded-lg text-gray-700',
        containerCssClass: 'w-full',
        theme: 'default',
        language: {
            noResults: function() {
                return "No customer found. Click + to add new.";
            }
        }
    });
    $('.select2-container').addClass('w-full');
});

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updateCustomer();
    document.getElementById('searchInput').focus();
});
</script>

</body>
</html>