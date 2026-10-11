<?php
// ============================================================
// ajax_products.php - Simple Version for Testing
// ============================================================
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set JSON header
header('Content-Type: application/json');

// ============================================================
// Include required files
// ============================================================
include "permission.php";
include "auth.php";
requireLogin();
include "../config/db.php";

// ============================================================
// Helper Functions
// ============================================================
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyToFloat($value) {
    if ($value === null || $value === '') return 0.0;
    $value = (string)$value;
    $value = str_replace(['৳', ',', ' '], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    return is_numeric($value) ? (float)$value : 0.0;
}

// ============================================================
// CSRF Verification
// ============================================================
function verifyCsrfToken() {
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    
    if ($session === '' || $posted === '' || !hash_equals($session, $posted)) {
        echo json_encode(["success" => false, "message" => "Invalid security token"]);
        exit;
    }
}

// ============================================================
// Get shop_id and user_id from session
// ============================================================
$shop_id = tenantShopId();
$user_id = tenantUserId();

if ($shop_id == 0 || $user_id == 0) {
    echo json_encode(["success" => false, "message" => "Session expired"]);
    exit;
}

// ============================================================
// Handle Action
// ============================================================
$action = $_POST['action'] ?? '';

if ($action === 'add_product') {
    // Verify CSRF
    verifyCsrfToken();
    
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $price = moneyToFloat($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $brand_id = (int)($_POST['brand_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    
    // Validate
    if (empty($name)) {
        echo json_encode(["success" => false, "message" => "Product name is required"]);
        exit;
    }
    
    if ($price <= 0) {
        echo json_encode(["success" => false, "message" => "Valid price is required"]);
        exit;
    }
    
    // Check database connection
    if (!$conn) {
        echo json_encode(["success" => false, "message" => "Database connection failed"]);
        exit;
    }
    
    // Check if products table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'products'");
    if ($table_check->num_rows == 0) {
        echo json_encode(["success" => false, "message" => "Products table does not exist"]);
        exit;
    }
    
    // Brand and category IDs must belong to the logged-in shop.
    if ($brand_id > 0) {
        $brandStmt = $conn->prepare("SELECT id FROM brands WHERE id = ? AND shop_id = ? LIMIT 1");
        $brandStmt->bind_param("ii", $brand_id, $shop_id);
        $brandStmt->execute();
        if (!$brandStmt->get_result()->fetch_assoc()) {
            echo json_encode(["success" => false, "message" => "Invalid brand for this shop"]);
            exit;
        }
    }

    if ($category_id > 0) {
        $categoryStmt = $conn->prepare("SELECT id FROM categories WHERE id = ? AND shop_id = ? LIMIT 1");
        $categoryStmt->bind_param("ii", $category_id, $shop_id);
        $categoryStmt->execute();
        if (!$categoryStmt->get_result()->fetch_assoc()) {
            echo json_encode(["success" => false, "message" => "Invalid category for this shop"]);
            exit;
        }
    }

    // Check if barcode already exists
    if (!empty($barcode)) {
        $check_stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND shop_id = ?");
        if ($check_stmt) {
            $check_stmt->bind_param("si", $barcode, $shop_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                echo json_encode(["success" => false, "message" => "Barcode already exists"]);
                $check_stmt->close();
                exit;
            }
            $check_stmt->close();
        }
    }
    
    // Get table columns to check what fields exist
    $columns = [];
    $col_result = $conn->query("SHOW COLUMNS FROM products");
    while ($col = $col_result->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    
    // Build query based on existing columns
    $sql = "INSERT INTO products (product_name, sale_price, stock, barcode, sku, brand_id, category_id, shop_id";
    $values = "?, ?, ?, ?, ?, ?, ?, ?";
    $params = [$name, $price, $stock, $barcode, $sku, $brand_id, $category_id, $shop_id];
    $types = "sdissiii";
    
    // Add purchase_price if column exists
    if (in_array('purchase_price', $columns)) {
        $sql .= ", purchase_price";
        $values .= ", ?";
        $purchase_price = $price * 0.7;
        $params[] = $purchase_price;
        $types .= "d";
    }
    
    // Add created_by if column exists
    if (in_array('created_by', $columns)) {
        $sql .= ", created_by";
        $values .= ", ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    // Add created_at if column exists
    if (in_array('created_at', $columns)) {
        $sql .= ", created_at";
        $values .= ", NOW()";
    }
    
    // Add unit if column exists
    if (in_array('unit', $columns)) {
        $sql .= ", unit";
        $values .= ", ?";
        $params[] = '';
        $types .= "s";
    }
    
    $sql .= ") VALUES ($values)";
    
    // Prepare and execute
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "SQL Error: " . $conn->error]);
        exit;
    }
    
    // Bind parameters dynamically
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Product added successfully",
            "product_id" => $stmt->insert_id
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to save: " . $stmt->error
        ]);
    }
    
    $stmt->close();
    exit;
}

// ============================================================
// Default response for invalid action
// ============================================================
echo json_encode([
    "success" => false,
    "message" => "Invalid action"
]);
?>