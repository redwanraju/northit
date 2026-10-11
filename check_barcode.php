<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(true);

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include "../config/db.php";

$shop_id = tenantShopId();$barcode = trim($_POST['barcode'] ?? '');

$stmt = $conn->prepare("
    SELECT id, product_name
    FROM products
    WHERE barcode = ?
    AND shop_id = ?
");

$stmt->bind_param("si", $barcode, $shop_id);
$stmt->execute();

$result = $stmt->get_result();

if($result->num_rows > 0){

    $row = $result->fetch_assoc();

    echo json_encode([
        "exists" => true,
        "product" => $row['product_name']
    ]);

}else{

    echo json_encode([
        "exists" => false
    ]);

}