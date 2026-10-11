<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(true);

header('Content-Type: application/json');

include "../config/db.php";

$shop_id = tenantShopId();
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if($order_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Get order details
    $order_stmt = $conn->prepare("
        SELECT o.*, c.name AS customer_name, s.sr_name 
        FROM orders o 
        LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id 
        LEFT JOIN sales_representatives s ON s.id = o.sr_id AND s.shop_id = o.shop_id 
        WHERE o.id = ? AND o.shop_id = ? AND o.order_source = 'pos'
    ");
    $order_stmt->bind_param("ii", $order_id, $shop_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // Get order items
    $items_stmt = $conn->prepare("
        SELECT oi.*, p.product_name, p.unit 
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
        WHERE oi.order_id = ? AND o.shop_id = ? AND o.order_source = 'pos'
    ");
    $items_stmt->bind_param("ii", $order_id, $shop_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'order' => $order,
        'data' => $items
    ]);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>