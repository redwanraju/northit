<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(true);
$shop_id = tenantShopId();

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);include "../config/db.php";

header('Content-Type: application/json');

$barcode = $_GET['barcode'] ?? '';

if($barcode == ''){
    echo json_encode(["success"=>false]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, product_name, sale_price, unit
    FROM products
    WHERE barcode = ? AND shop_id = ?
    LIMIT 1
");

$stmt->bind_param("si", $barcode, $shop_id);
$stmt->execute();

$result = $stmt->get_result();
$p = $result->fetch_assoc();

if(!$p){
    echo json_encode(["success"=>false]);
    exit;
}

$id = $p['id'];

if(!isset($_SESSION['cart'])){
    $_SESSION['cart'] = [];
}

/* ===== SAME YOUR LOGIC ===== */
if(isset($_SESSION['cart'][$id])){
    $_SESSION['cart'][$id]['qty']++;
} else {
    $_SESSION['cart'][$id] = [
        "name"=>$p['product_name'],
        "price"=>$p['sale_price'],
        "qty"=>1,
        "unit"=>$p['unit']
    ];
}

echo json_encode(["success"=>true]);