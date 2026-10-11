<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(true);

include "../config/db.php";

header('Content-Type: application/json');

$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$sr_id = (int)($_POST['sr_id'] ?? 0);
$shop_id = tenantShopId();

if ($sr_id > 0) {
    $srCheck = $conn->prepare("SELECT id FROM sales_representatives WHERE id = ? AND shop_id = ? AND status = 1 LIMIT 1");
    $srCheck->bind_param("ii", $sr_id, $shop_id);
    $srCheck->execute();
    if (!$srCheck->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Invalid sales representative']);
        exit;
    }
}

if(empty($name)){
    echo json_encode(['success' => false, 'message' => 'Name is required']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO customers (name, phone, address, sr_id, shop_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("sssii", $name, $phone, $address, $sr_id, $shop_id);

if($stmt->execute()){
    echo json_encode([
        'success' => true,
        'id' => $stmt->insert_id,
        'name' => $name,
        'phone' => $phone,
        'sr_id' => $sr_id
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}
?>