<?php
require_once __DIR__ . '/site_admin_guard.php';
requireSiteAdmin(1);

// Rice Valy Order Management - compatibility build (no mysqli_stmt::get_result dependency)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
mysqli_report(MYSQLI_REPORT_OFF);

define('BASE_URL', 'https://ricevaly.bd/');
// Orders in this list cannot be deleted from the UI/API. Keep critical production orders protected.
$protectedDeleteInvoices = ['RV-20260926-DCFDDE'];
// Database connection - compatible with /pos/site/order_management.php and /pos/order_management.php
$dbCandidates = [
    __DIR__ . '/../../config/db.php', // /pos/site/order_management.php
    __DIR__ . '/../config/db.php',    // /pos/order_management.php
    rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/config/db.php'
];

$dbLoaded = false;
foreach ($dbCandidates as $dbFile) {
    if ($dbFile && is_file($dbFile)) {
        require_once $dbFile;
        $dbLoaded = true;
        break;
    }
}

if (!$dbLoaded) {
    http_response_code(500);
    die('Database configuration file not found. Expected /config/db.php');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection was not initialized by config/db.php');
}

require_once __DIR__ . '/referral_engine.php';

$statusConfig = [
    'pending'     => ['label' => 'Pending',     'class' => 'warning'],
    'processing'  => ['label' => 'Processing',  'class' => 'info'],
    'on_hold'     => ['label' => 'On Hold',     'class' => 'secondary'],
    'in_courier'  => ['label' => 'In Courier',  'class' => 'primary'],
    'on_the_way'  => ['label' => 'On The Way',  'class' => 'primary'],
    'completed'   => ['label' => 'Completed',   'class' => 'success'],
    'cancelled'   => ['label' => 'Cancelled',   'class' => 'danger'],
];

$paymentConfig = [
    'pending'  => ['label' => 'Pending',  'class' => 'warning'],
    'paid'     => ['label' => 'Paid',     'class' => 'success'],
    'failed'   => ['label' => 'Failed',   'class' => 'danger'],
    'refunded' => ['label' => 'Refunded', 'class' => 'info'],
];

function jsonResponse($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value) { return number_format((float)$value, 2); }
function normalizeStatusSlug($slug) {
    $map = ['on-the-way'=>'on_the_way','on-hold'=>'on_hold','in-courier'=>'in_courier'];
    return isset($map[$slug]) ? $map[$slug] : $slug;
}
function statusToSlug($status) { return str_replace('_', '-', $status); }
function dbErrorMessage($conn, $prefix='Database query failed') {
    $err = ($conn instanceof mysqli) ? $conn->error : '';
    return $prefix . ($err ? ': '.$err : '.');
}
function getOrderStatusEnumValues($conn) {
    $values = [];
    $res = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_status'");
    if ($res && ($row = $res->fetch_assoc())) {
        if (preg_match_all("/'([^']+)'/", $row['Type'], $m)) $values = $m[1];
        $res->free();
    }
    return $values;
}

function tableExists($conn, $table) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}
function columnExists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}
function safeDbIdentifier($name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$name)) {
        throw new RuntimeException('Unsafe database identifier detected.');
    }
    return '`'.$name.'`';
}
function fetchRowsForArchive($conn, $table, $orderId) {
    if (!tableExists($conn, $table) || !columnExists($conn, $table, 'order_id')) return [];
    $t = safeDbIdentifier($table);
    $res = $conn->query("SELECT * FROM {$t} WHERE `order_id`=".(int)$orderId." ORDER BY 1 ASC");
    if (!$res) throw new RuntimeException(dbErrorMessage($conn, 'Could not snapshot '.$table));
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->free();
    return $rows;
}
function countOrderReferences($conn, $table, $column, $orderId) {
    if (!tableExists($conn, $table) || !columnExists($conn, $table, $column)) return 0;
    $t = safeDbIdentifier($table);
    $c = safeDbIdentifier($column);
    $res = $conn->query("SELECT COUNT(*) AS c FROM {$t} WHERE {$c}=".(int)$orderId);
    if (!$res) throw new RuntimeException(dbErrorMessage($conn, 'Could not check order dependencies'));
    $row = $res->fetch_assoc();
    $res->free();
    return (int)($row['c'] ?? 0);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection was not initialized by config/db.php');
}

$dbStatuses = getOrderStatusEnumValues($conn);
$siteShopId = tenantShopId();

// AJAX actions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'get_order_details') {
        $orderId = (int)($_POST['id'] ?? 0);
        if ($orderId <= 0) jsonResponse(['success'=>false,'message'=>'Invalid order ID.'], 400);

        $sql = "SELECT o.*, c.name AS customer_name, c.shop_name AS customer_shop_name,
                       c.phone AS customer_phone, c.email AS customer_email, c.address AS customer_address,
                       osa.recipient_name, osa.phone AS shipping_phone, osa.address AS shipping_address,
                       osa.city AS shipping_city, osa.postal_code AS shipping_postal_code,
                       sm.name AS shipping_method_name, sm.estimated_days AS shipping_estimated_days
                FROM orders o
                LEFT JOIN customers c ON c.id=o.customer_id AND c.shop_id={$siteShopId}
                LEFT JOIN order_shipping_addresses osa ON osa.order_id=o.id
                LEFT JOIN shipping_methods sm ON sm.id=o.shipping_method_id
                WHERE o.id={$orderId} AND o.shop_id={$siteShopId} LIMIT 1";
        $res = $conn->query($sql);
        if (!$res) jsonResponse(['success'=>false,'message'=>dbErrorMessage($conn, 'Could not load order')], 500);
        $order = $res->fetch_assoc();
        $res->free();
        if (!$order) jsonResponse(['success'=>false,'message'=>'Order not found.'], 404);

        $itemSql = "SELECT oi.id, oi.order_id, oi.product_id, oi.site_product_id, oi.qty, oi.price, oi.subtotal,
                           oi.variant_id, oi.cost_price, o.order_source,
                           CASE WHEN o.order_source='online'
                                THEN COALESCE(sp.product_name, p.product_name, sp_legacy.product_name, CONCAT('Site Product #',oi.product_id))
                                ELSE COALESCE(p.product_name, CONCAT('Product #',oi.product_id)) END AS product_name,
                           CASE WHEN o.order_source='online'
                                THEN COALESCE(sp.unit, p.unit, sp_legacy.unit)
                                ELSE p.unit END AS product_unit
                    FROM order_items oi
                    INNER JOIN orders o ON o.id=oi.order_id
                    LEFT JOIN products p ON p.id=oi.product_id
                    LEFT JOIN site_products sp ON sp.id=oi.site_product_id
                    LEFT JOIN site_products sp_legacy
                           ON oi.site_product_id IS NULL
                          AND p.id IS NULL
                          AND sp_legacy.id=oi.product_id
                    WHERE oi.order_id={$orderId} AND o.shop_id={$siteShopId}
                    ORDER BY oi.id ASC";
        $res = $conn->query($itemSql);
        if (!$res) jsonResponse(['success'=>false,'message'=>dbErrorMessage($conn, 'Could not load order items')], 500);
        $items=[];
        while ($row=$res->fetch_assoc()) $items[]=$row;
        $res->free();
        jsonResponse(['success'=>true,'order'=>$order,'items'=>$items]);
    }

    if ($action === 'update_order_status') {
        siteAdminRequireCsrf();
        $orderId = (int)($_POST['id'] ?? 0);
        $newStatus = (string)($_POST['order_status'] ?? '');
        $newPaymentStatus = (string)($_POST['payment_status'] ?? '');
        $adminNote = trim((string)($_POST['admin_note'] ?? ''));

        if ($orderId <= 0) jsonResponse(['success'=>false,'message'=>'Invalid order ID.'], 400);
        if (!isset($statusConfig[$newStatus])) jsonResponse(['success'=>false,'message'=>'Invalid order status.'], 400);
        if (!isset($paymentConfig[$newPaymentStatus])) jsonResponse(['success'=>false,'message'=>'Invalid payment status.'], 400);
        if (!empty($dbStatuses) && !in_array($newStatus, $dbStatuses, true)) {
            jsonResponse(['success'=>false,'message'=>'This status is not enabled in the database yet. Run order_management_migration.sql once.'], 400);
        }

        // Update order + V3 wallet credit lifecycle atomically.
        // Completed + Paid posts 2% buyer credit and, when applicable, 5% direct referral credit.
        try {
            $conn->begin_transaction();

            $currentRes = $conn->query(
                "SELECT total_amount, paid_amount, due_amount, account_user_id, order_source, order_status, payment_status
                 FROM orders
                 WHERE id={$orderId} AND shop_id={$siteShopId}
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$currentRes) {
                throw new RuntimeException(dbErrorMessage($conn, 'Could not read current payment values'));
            }

            $current = $currentRes->fetch_assoc();
            $currentRes->free();
            if (!$current) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'Order not found.'], 404);
            }

            $paidAmount = (float)($current['paid_amount'] ?? 0);
            $dueAmount = (float)($current['due_amount'] ?? 0);
            $totalAmount = (float)($current['total_amount'] ?? 0);

            // When payment is confirmed as Paid, keep the financial fields consistent.
            if ($newPaymentStatus === 'paid') {
                $paidAmount = $totalAmount;
                $dueAmount = 0.00;
            }

            $stmt=$conn->prepare(
                "UPDATE orders
                 SET order_status=?, payment_status=?, paid_amount=?, due_amount=?, admin_note=?
                 WHERE id=? AND shop_id=?"
            );
            if (!$stmt) throw new RuntimeException(dbErrorMessage($conn, 'Could not prepare update'));

            $stmt->bind_param(
                'ssddsii',
                $newStatus,$newPaymentStatus,$paidAmount,$dueAmount,$adminNote,$orderId,$siteShopId
            );
            if (!$stmt->execute()) {
                $msg=$stmt->error ?: $conn->error;
                $stmt->close();
                throw new RuntimeException('Could not update order: '.$msg);
            }
            $stmt->close();

            // Idempotent: repeated saves cannot double-credit a wallet.
            // If the order later stops qualifying, the shared engine posts exact reversals.
            $creditResult = rvReferralSyncOrder($conn, $siteShopId, $orderId);

            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Ricevaly order/credit update: ' . $e->getMessage());
            jsonResponse([
                'success'=>false,
                'message'=>'Could not update order safely. No partial credit change was saved.'
            ], 500);
        }

        jsonResponse([
            'success'=>true,
            'message'=>'Order and payment status updated successfully.',
            'order_status'=>$newStatus,
            'payment_status'=>$newPaymentStatus,
            'paid_amount'=>$paidAmount,
            'due_amount'=>$dueAmount,
            'credit'=>$creditResult
        ]);
    }

    if ($action === 'delete_order') {
        siteAdminRequireCsrf();
        $orderId = (int)($_POST['id'] ?? 0);
        $confirmInvoice = trim((string)($_POST['confirm_invoice'] ?? ''));
        $deleteReason = trim((string)($_POST['delete_reason'] ?? ''));

        if ($orderId <= 0) jsonResponse(['success'=>false,'message'=>'Invalid order ID.'], 400);
        if ($confirmInvoice === '') jsonResponse(['success'=>false,'message'=>'Type the invoice number to confirm deletion.'], 400);
        if (mb_strlen($deleteReason) < 3 || mb_strlen($deleteReason) > 500) {
            jsonResponse(['success'=>false,'message'=>'Deletion reason must be between 3 and 500 characters.'], 400);
        }
        if (!tableExists($conn, 'deleted_orders_archive')) {
            jsonResponse(['success'=>false,'message'=>'Deletion archive is not installed. Run order_delete_archive_migration.sql once, then retry.'], 503);
        }

        try {
            $conn->begin_transaction();

            $res = $conn->query(
                "SELECT * FROM orders WHERE id=".$orderId." AND shop_id=".$siteShopId." LIMIT 1 FOR UPDATE"
            );
            if (!$res) throw new RuntimeException(dbErrorMessage($conn, 'Could not lock order'));
            $order = $res->fetch_assoc();
            $res->free();
            if (!$order) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'Order not found.'], 404);
            }

            // Website order deletion only. POS/accounting orders are intentionally protected.
            if (($order['order_source'] ?? '') !== 'online') {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'Only online orders can be deleted from this page. POS orders are protected.'], 403);
            }
            if (in_array((string)$order['invoice_no'], $protectedDeleteInvoices, true)) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'This order is protected and cannot be deleted.'], 403);
            }
            if (!hash_equals((string)$order['invoice_no'], $confirmInvoice)) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'Invoice confirmation does not match this order.'], 409);
            }

            // Never silently erase wallet/referral history. If this order has generated or qualified a credit,
            // an administrator must reverse that accounting event first through the referral/credit workflow.
            $financialRefs = [
                ['credit_order_rewards', 'order_id', 'credit reward'],
                ['credit_wallet_transactions', 'order_id', 'wallet transaction'],
                ['referrals', 'qualified_order_id', 'referral qualification'],
            ];
            foreach ($financialRefs as $ref) {
                if (countOrderReferences($conn, $ref[0], $ref[1], $orderId) > 0) {
                    $conn->rollback();
                    jsonResponse([
                        'success'=>false,
                        'message'=>'This order has '.$ref[2].' history and cannot be hard-deleted. Reverse/resolve the financial record first.'
                    ], 409);
                }
            }

            // Discover actual FK dependencies. Known order-owned detail tables are deleted below;
            // any other referencing table blocks deletion instead of risking orphaned business records.
            $knownChildren = ['order_items', 'order_shipping_addresses', 'coupon_usages'];
            $fkRes = $conn->query(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='orders' AND REFERENCED_COLUMN_NAME='id'"
            );
            if (!$fkRes) throw new RuntimeException(dbErrorMessage($conn, 'Could not inspect order dependencies'));
            while ($fk = $fkRes->fetch_assoc()) {
                $table = (string)$fk['TABLE_NAME'];
                $column = (string)$fk['COLUMN_NAME'];
                if (in_array($table, $knownChildren, true)) continue;
                if (countOrderReferences($conn, $table, $column, $orderId) > 0) {
                    $fkRes->free(); $conn->rollback();
                    jsonResponse(['success'=>false,'message'=>'Deletion blocked: related records exist in '.$table.'.'], 409);
                }
            }
            $fkRes->free();

            $items = fetchRowsForArchive($conn, 'order_items', $orderId);
            $shipping = fetchRowsForArchive($conn, 'order_shipping_addresses', $orderId);
            $coupons = fetchRowsForArchive($conn, 'coupon_usages', $orderId);
            $orderJson = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $shippingJson = json_encode($shipping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $couponsJson = json_encode($coupons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($orderJson === false || $itemsJson === false || $shippingJson === false || $couponsJson === false) {
                throw new RuntimeException('Could not serialize order archive.');
            }

            $deletedBy = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));
            $archiveStmt = $conn->prepare(
                "INSERT INTO deleted_orders_archive
                 (shop_id, order_id, invoice_no, order_payload, items_payload, shipping_payload, coupon_payload, deleted_by, delete_reason)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            if (!$archiveStmt) throw new RuntimeException(dbErrorMessage($conn, 'Could not prepare deletion archive'));
            $invoiceNo = (string)$order['invoice_no'];
            $archiveStmt->bind_param('iisssssis', $siteShopId, $orderId, $invoiceNo, $orderJson, $itemsJson, $shippingJson, $couponsJson, $deletedBy, $deleteReason);
            if (!$archiveStmt->execute()) {
                $msg=$archiveStmt->error ?: $conn->error; $archiveStmt->close();
                throw new RuntimeException('Could not archive order before deletion: '.$msg);
            }
            $archiveStmt->close();

            foreach ($knownChildren as $childTable) {
                if (!tableExists($conn, $childTable) || !columnExists($conn, $childTable, 'order_id')) continue;
                $t = safeDbIdentifier($childTable);
                if (!$conn->query("DELETE FROM {$t} WHERE `order_id`=".$orderId)) {
                    throw new RuntimeException(dbErrorMessage($conn, 'Could not delete '.$childTable));
                }
            }

            $deleteStmt = $conn->prepare("DELETE FROM orders WHERE id=? AND shop_id=? AND order_source='online' LIMIT 1");
            if (!$deleteStmt) throw new RuntimeException(dbErrorMessage($conn, 'Could not prepare order deletion'));
            $deleteStmt->bind_param('ii', $orderId, $siteShopId);
            if (!$deleteStmt->execute() || $deleteStmt->affected_rows !== 1) {
                $msg=$deleteStmt->error ?: $conn->error; $deleteStmt->close();
                throw new RuntimeException('Order was not deleted: '.($msg ?: 'row changed before deletion'));
            }
            $deleteStmt->close();

            $conn->commit();
            jsonResponse([
                'success'=>true,
                'message'=>'Order '.$invoiceNo.' was archived and deleted successfully.',
                'id'=>$orderId,
                'invoice_no'=>$invoiceNo
            ]);
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('Ricevaly safe order delete: '.$e->getMessage());
            jsonResponse(['success'=>false,'message'=>'Order could not be deleted safely. No partial deletion was saved.'], 500);
        }
    }
    jsonResponse(['success'=>false,'message'=>'Unknown action.'],400);
}

// Filters
$allowedSources=['online','pos','all'];
$source=(string)($_GET['source'] ?? 'online');
if (!in_array($source,$allowedSources,true)) $source='online';
$statusParam=normalizeStatusSlug(trim((string)($_GET['status'] ?? 'all')));
if ($statusParam!=='all' && !isset($statusConfig[$statusParam])) $statusParam='all';
$search=trim((string)($_GET['search'] ?? ''));
$dateFrom=trim((string)($_GET['date_from'] ?? ''));
$dateTo=trim((string)($_GET['date_to'] ?? ''));
$page=max(1,(int)($_GET['page'] ?? 1));
$perPage=25;
$offset=($page-1)*$perPage;

// Build safe WHERE using whitelists + real_escape_string
$where=["o.shop_id={$siteShopId}"];
if ($source!=='all') $where[]="o.order_source='".$conn->real_escape_string($source)."'";
$metricWhere=implode(' AND ',$where);

$metrics=['total_orders'=>0,'total_value'=>0,'pending_count'=>0,'processing_count'=>0,'on_hold_count'=>0,'in_courier_count'=>0,'on_the_way_count'=>0,'completed_count'=>0,'cancelled_count'=>0];
$metricSql="SELECT COUNT(*) total_orders, COALESCE(SUM(o.total_amount),0) total_value,
                  SUM(CASE WHEN o.order_status='pending' THEN 1 ELSE 0 END) pending_count,
                  SUM(CASE WHEN o.order_status='processing' THEN 1 ELSE 0 END) processing_count,
                  SUM(CASE WHEN o.order_status='on_hold' THEN 1 ELSE 0 END) on_hold_count,
                  SUM(CASE WHEN o.order_status='in_courier' THEN 1 ELSE 0 END) in_courier_count,
                  SUM(CASE WHEN o.order_status='on_the_way' THEN 1 ELSE 0 END) on_the_way_count,
                  SUM(CASE WHEN o.order_status='completed' THEN 1 ELSE 0 END) completed_count,
                  SUM(CASE WHEN o.order_status='cancelled' THEN 1 ELSE 0 END) cancelled_count
            FROM orders o WHERE {$metricWhere}";
$res=$conn->query($metricSql);
if (!$res) {
    error_log('Order Management metric query: '.$conn->error);
    $loadError=dbErrorMessage($conn,'Order summary could not be loaded');
} else {
    $metrics=array_merge($metrics,$res->fetch_assoc() ?: []);
    $res->free();
    $loadError='';
}

if ($statusParam!=='all') $where[]="o.order_status='".$conn->real_escape_string($statusParam)."'";
if ($search!=='') {
    $like=$conn->real_escape_string($search);
    $where[]="(o.invoice_no LIKE '%{$like}%' OR c.name LIKE '%{$like}%' OR c.shop_name LIKE '%{$like}%'
              OR c.phone LIKE '%{$like}%' OR osa.recipient_name LIKE '%{$like}%' OR osa.phone LIKE '%{$like}%')";
}
if ($dateFrom!=='' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$dateFrom)) {
    $where[]="DATE(o.created_at)>='".$conn->real_escape_string($dateFrom)."'";
}
if ($dateTo!=='' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$dateTo)) {
    $where[]="DATE(o.created_at)<='".$conn->real_escape_string($dateTo)."'";
}
$whereSql=implode(' AND ',$where);

$totalFiltered=0;
$countSql="SELECT COUNT(DISTINCT o.id) total FROM orders o
           LEFT JOIN customers c ON c.id=o.customer_id AND c.shop_id={$siteShopId}
           LEFT JOIN order_shipping_addresses osa ON osa.order_id=o.id
           WHERE {$whereSql}";
$res=$conn->query($countSql);
if (!$res) {
    error_log('Order Management count query: '.$conn->error);
    if (!$loadError) $loadError=dbErrorMessage($conn,'Order count could not be loaded');
} else {
    $r=$res->fetch_assoc();
    $totalFiltered=(int)($r['total'] ?? 0);
    $res->free();
}
$totalPages=max(1,(int)ceil($totalFiltered/$perPage));
if ($page>$totalPages) { $page=$totalPages; $offset=($page-1)*$perPage; }

$orders=[];
$listSql="SELECT o.*, c.name AS customer_name, c.shop_name AS customer_shop_name, c.phone AS customer_phone,
                osa.recipient_name, osa.phone AS shipping_phone, osa.city AS shipping_city,
                (SELECT COUNT(*) FROM order_items x WHERE x.order_id=o.id) item_count,
                (SELECT COALESCE(SUM(x.qty),0) FROM order_items x WHERE x.order_id=o.id) total_qty
         FROM orders o
         LEFT JOIN customers c ON c.id=o.customer_id AND c.shop_id={$siteShopId}
         LEFT JOIN order_shipping_addresses osa ON osa.order_id=o.id
         WHERE {$whereSql}
         ORDER BY o.created_at DESC,o.id DESC
         LIMIT {$perPage} OFFSET {$offset}";
$res=$conn->query($listSql);
if (!$res) {
    error_log('Order Management list query: '.$conn->error);
    if (!$loadError) $loadError=dbErrorMessage($conn,'Order list could not be loaded');
} else {
    while ($row=$res->fetch_assoc()) $orders[]=$row;
    $res->free();
}

function pageUrl($targetPage,$overrides=[]) {
    $query=$_GET; $query['page']=$targetPage;
    foreach ($overrides as $key=>$value) {
        if ($value===null || $value==='') unset($query[$key]); else $query[$key]=$value;
    }
    return '?'.http_build_query($query);
}
$sourceLabel=$source==='online'?'Online':($source==='pos'?'POS':'All Sources');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Order Management — Rice Valy Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0a5b4f">
<meta name="apple-mobile-web-app-capable" content="yes">

<link rel="shortcut icon" href="https://www.ricevaly.com/public/uploads/settings/1759768409-whatsapp-image-2025-10-06-at-8.13.11-pm.webp">

<!-- Bootstrap 5.3 + Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root{
  --rv-primary:#0a5b4f;
  --rv-primary-2:#0d7a67;
  --rv-accent:#f48721;
  --rv-bg:#f4f6f8;
  --rv-sidebar:#0a5b4f;
  --rv-sidebar-hover:#0d7a67;
  --rv-text:#1f2937;
  --rv-muted:#6b7280;
  --rv-border:#e5e7eb;
  --rv-radius:12px;
  --rv-shadow:0 2px 10px rgba(15,40,35,.06);
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;background:var(--rv-bg);color:var(--rv-text);
  font-family:Inter,"Noto Sans Bengali",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
  font-size:14px;
}
.rv-layout{display:flex;min-height:100vh}
.rv-sidebar{
  width:250px;flex-shrink:0;background:var(--rv-sidebar);color:#d1fae5;
  position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:1040;
  transition:transform .25s ease;
}
.rv-sidebar::-webkit-scrollbar{width:6px}
.rv-sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.2);border-radius:6px}
.rv-sidebar .rv-brand{display:flex;align-items:center;gap:10px;padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.08)}
.rv-sidebar .rv-brand img{height:36px;background:#fff;border-radius:8px;padding:3px}
.rv-sidebar .rv-brand span{font-weight:700;color:#fff;font-size:15px;letter-spacing:.2px}
.rv-sidebar .rv-user{padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:12px}
.rv-sidebar .rv-user img{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.3)}
.rv-sidebar .rv-user .name{color:#fff;font-weight:600;font-size:14px}
.rv-sidebar .rv-user .role{color:#a7f3d0;font-size:12px}
.rv-nav{padding:8px 0;list-style:none;margin:0}
.rv-nav .rv-section{padding:14px 20px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#6ee7b7;opacity:.75}
.rv-nav a{
  display:flex;align-items:center;gap:10px;padding:10px 20px;color:#d1fae5;
  text-decoration:none;font-size:14px;border-left:3px solid transparent;transition:.15s;
}
.rv-nav a:hover{background:var(--rv-sidebar-hover);color:#fff}
.rv-nav a.active{background:var(--rv-sidebar-hover);color:#fff;border-left-color:var(--rv-accent);font-weight:600}
.rv-nav a i{font-size:16px;width:20px;text-align:center}
.rv-nav .sub a{padding-left:52px;font-size:13px}
.rv-main{flex:1;margin-left:250px;display:flex;flex-direction:column;min-width:0}
.rv-topbar{
  background:#fff;border-bottom:1px solid var(--rv-border);padding:12px 24px;
  display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:1030;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.rv-topbar .rv-toggle{display:none;background:none;border:none;font-size:22px;color:var(--rv-primary);cursor:pointer}
.rv-topbar h5{margin:0;font-weight:700;color:var(--rv-primary);font-size:16px}
.rv-topbar .rv-actions{margin-left:auto;display:flex;align-items:center;gap:12px}
.rv-topbar .rv-actions img{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--rv-border)}
.rv-content{padding:24px;flex:1}
.rv-page-title{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.rv-page-title h4{margin:0;font-weight:700;font-size:20px}
.rv-page-title p{margin:2px 0 0;color:var(--rv-muted);font-size:13px}
.rv-page-title .rv-toolbar{display:flex;flex-wrap:wrap;gap:8px}
.card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow)}
.card-header{background:#fafbfc;border-bottom:1px solid var(--rv-border);font-weight:600}
.table{background:#fff;margin-bottom:0}
.table thead th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--rv-muted);font-weight:600;border-bottom:1px solid var(--rv-border)}
.table td,.table th{vertical-align:middle;padding:.7rem .75rem}
.btn{border-radius:8px;font-weight:500}
.btn-primary{background:var(--rv-primary);border-color:var(--rv-primary)}
.btn-primary:hover{background:var(--rv-primary-2);border-color:var(--rv-primary-2)}
.badge-status{font-size:11px;padding:4px 9px;border-radius:20px}
.stat-card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow);background:#fff}
.stat-card .stat-icon{
  width:48px;height:48px;display:flex;align-items:center;justify-content:center;
  border-radius:12px;background:#e6f4f1;color:var(--rv-primary);font-size:22px;
}
.stat-card .stat-label{color:var(--rv-muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.stat-card .stat-value{font-size:22px;font-weight:700;margin:0}
.status-filter-wrap{display:flex;gap:8px;flex-wrap:wrap}
.status-filter-wrap .btn{border-radius:20px}
.order-table .invoice{font-weight:700;color:#334155}
.order-table .source-badge{font-size:10px;letter-spacing:.3px;padding:4px 8px;border-radius:12px}
.order-table .order-action-group{display:flex;justify-content:flex-end;gap:6px;flex-wrap:nowrap}
.detail-section{border:1px solid var(--rv-border);border-radius:10px;padding:14px;margin-bottom:14px;background:#fff}
.order-detail-label{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--rv-muted);margin-bottom:3px}
.order-detail-value{font-weight:600;color:#1e293b;word-break:break-word}
.item-table td,.item-table th{vertical-align:middle}
.empty-state{padding:50px 20px;text-align:center;color:var(--rv-muted)}
.pagination .page-link{min-width:38px;text-align:center;border-radius:6px}
.delete-warning-box{border:1px solid #fecaca;background:#fff7f7;border-radius:10px;padding:12px 14px;color:#991b1b;font-size:13px}
.delete-warning-box strong{color:#7f1d1d}
.modal-content{border-radius:14px;border:0;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-header{border-bottom:1px solid var(--rv-border);padding:14px 20px}
.modal-title{font-weight:700;font-size:16px;color:var(--rv-primary)}
.modal-footer{border-top:1px solid var(--rv-border);padding:12px 20px}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px}
.form-control,.form-select{border-radius:8px;border-color:#d1d5db;font-size:14px}
.form-control:focus,.form-select:focus{border-color:var(--rv-primary);box-shadow:0 0 0 .2rem rgba(10,91,79,.12)}
.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.75);z-index:20000;display:flex;align-items:center;justify-content:center}
.loading-overlay .spinner-border{width:3rem;height:3rem;color:var(--rv-primary)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
.bg-soft-primary{background:#e6f4f1!important}
.bg-soft-dark{background:#e5e7eb!important}
.text-primary{color:var(--rv-primary)!important}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
  .order-table{min-width:980px}
}
</style>
</head>
<body>

<div class="rv-layout">

  <!-- Sidebar -->
  <aside class="rv-sidebar" id="rvSidebar">
    <div class="rv-brand">
      <img src="https://www.ricevaly.com/public/uploads/settings/1759901568-whatsapp-image-2025-10-07-at-10.42.40-pm.webp" alt="logo">
      <span>Rice Valy Admin</span>
    </div>
    <div class="rv-user">
      <img src="https://www.ricevaly.com/public/backEnd/assets/images/users/user-1.jpg" alt="user">
      <div>
        <div class="name">Admin</div>
        <div class="role">Admin Head</div>
      </div>
    </div>

    <ul class="rv-nav">
      <li class="rv-section">Main</li>
      <li><a href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>

      <li class="rv-section">Orders</li>
      <li>
        <a data-bs-toggle="collapse" href="#navOrders" role="button" aria-expanded="true" class="active">
          <i class="bi bi-cart3"></i> Orders <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse show sub" id="navOrders">
          <ul class="rv-nav">
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=all&source=online"><i class="bi bi-list-ul"></i> All Orders</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=pending&source=online"><i class="bi bi-clock"></i> Pending</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=processing&source=online"><i class="bi bi-gear"></i> Processing</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=on-the-way&source=online"><i class="bi bi-truck"></i> On The Way</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=on-hold&source=online"><i class="bi bi-pause-circle"></i> On Hold</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=in-courier&source=online"><i class="bi bi-box-seam"></i> In Courier</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=completed&source=online"><i class="bi bi-check2-circle"></i> Completed</a></li>
            <li><a href="https://ricevaly.bd/pos/site/order_management.php?status=cancelled&source=online"><i class="bi bi-x-circle"></i> Cancelled</a></li>
          </ul>
        </div>
      </li>

      <li class="rv-section">Products</li>
      <li>
        <a data-bs-toggle="collapse" href="#navProducts" role="button" aria-expanded="false">
          <i class="bi bi-box"></i> Products <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse sub" id="navProducts">
          <ul class="rv-nav">
            <li><a href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-pencil-square"></i> Product Manage</a></li>
            <li><a href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-box-seam"></i> Site Products</a></li>
			

          </ul>
        </div>
      </li>

      <li class="rv-section">Users</li>
      <li>
        <a data-bs-toggle="collapse" href="#navUsers" role="button" aria-expanded="false">
          <i class="bi bi-people"></i> Users <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse sub" id="navUsers">
          <ul class="rv-nav">
            <li><a href="https://ricevaly.bd/pos/site/registered_users.php"><i class="bi bi-person-lines-fill"></i> Registered Users</a></li>
          
            <li><a href="https://ricevaly.bd/pos/site/online_customers.php"><i class="bi bi-wifi"></i> Online Customers</a></li>
          </ul>
        </div>
      </li>





    </ul>
  </aside>

  <div class="rv-backdrop" id="rvBackdrop"></div>

  <!-- Main -->
  <div class="rv-main">
    <header class="rv-topbar">
      <button class="rv-toggle" id="rvToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
      <h5>Order Management</h5>
      <div class="rv-actions">
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <img src="https://www.ricevaly.com/public/uploads/users/1707537536-faveicon.webp" alt="user">
            <span class="ms-2 d-none d-sm-inline fw-semibold">Admin</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header">Welcome, Admin</h6></li>
            <li><a class="dropdown-item" href="https://ricevaly.bd/pos/site/site_management.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
            <li><a class="dropdown-item" href="referral_management.php"><i class="bi bi-gift me-2"></i> Credit &amp; Referral</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="document.getElementById('logout-form').submit();return false;"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="rv-content">

      <div class="rv-page-title">
        <div>
          <h4>Order Management</h4>
          <p>Manage website and POS orders from one place. Current source: <strong><?= h($sourceLabel) ?></strong></p>
        </div>
        <div class="rv-toolbar">
          <a href="https://ricevaly.bd/pos/site/site_management.php" class="btn btn-light btn-sm"><i class="bi bi-box-seam"></i> Product Management</a>
        </div>
      </div>

      <?php if (!empty($loadError)): ?>
        <div class="alert alert-danger"><strong>Order Management database error:</strong> <?= h($loadError) ?></div>
      <?php endif; ?>
      <?php if (!empty($dbStatuses) && (count(array_diff(array_keys($statusConfig), $dbStatuses)) > 0)): ?>
        <div class="alert alert-warning py-2">
          Basic order management is available, but the database still needs the status migration for On Hold / In Courier / On The Way. Run <code>order_management_migration.sql</code> once.
        </div>
      <?php endif; ?>

      <!-- Summary Cards -->
      <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Total Orders</div><h3 class="stat-value"><?= (int)($metrics['total_orders'] ?? 0) ?></h3></div>
            <div class="stat-icon"><i class="bi bi-cart3"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Pending</div><h3 class="stat-value"><?= (int)($metrics['pending_count'] ?? 0) ?></h3></div>
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Completed</div><h3 class="stat-value"><?= (int)($metrics['completed_count'] ?? 0) ?></h3></div>
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Order Value</div><h3 class="stat-value">৳<?= money($metrics['total_value'] ?? 0) ?></h3></div>
            <div class="stat-icon"><i class="bi bi-currency-dollar"></i></div>
          </div></div>
        </div>
      </div>

      <!-- Status quick filters -->
      <div class="card mb-3">
        <div class="card-body py-3">
          <div class="status-filter-wrap">
            <?php
            $quickStatuses = [
              'all' => ['All', (int)($metrics['total_orders'] ?? 0)],
              'pending' => ['Pending', (int)($metrics['pending_count'] ?? 0)],
              'processing' => ['Processing', (int)($metrics['processing_count'] ?? 0)],
              'on_hold' => ['On Hold', (int)($metrics['on_hold_count'] ?? 0)],
              'in_courier' => ['In Courier', (int)($metrics['in_courier_count'] ?? 0)],
              'on_the_way' => ['On The Way', (int)($metrics['on_the_way_count'] ?? 0)],
              'completed' => ['Completed', (int)($metrics['completed_count'] ?? 0)],
              'cancelled' => ['Cancelled', (int)($metrics['cancelled_count'] ?? 0)],
            ];
            foreach ($quickStatuses as $key => $info):
              $active = $statusParam === $key;
              $url = pageUrl(1, ['status' => $key === 'all' ? 'all' : statusToSlug($key)]);
            ?>
              <a href="<?= h($url) ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= h($info[0]) ?> <span class="badge bg-light text-dark ms-1"><?= (int)$info[1] ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Advanced filters -->
      <div class="card mb-3">
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <div class="col-xl-4 col-md-6">
              <label class="form-label">Search</label>
              <input type="text" name="search" value="<?= h($search) ?>" class="form-control" placeholder="Invoice, customer or phone...">
            </div>
            <div class="col-xl-2 col-md-3">
              <label class="form-label">Source</label>
              <select name="source" class="form-select">
                <option value="online" <?= $source === 'online' ? 'selected' : '' ?>>Online</option>
                <option value="pos" <?= $source === 'pos' ? 'selected' : '' ?>>POS</option>
                <option value="all" <?= $source === 'all' ? 'selected' : '' ?>>All Sources</option>
              </select>
            </div>
            <div class="col-xl-2 col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="all">All</option>
                <?php foreach ($statusConfig as $key => $cfg): ?>
                  <option value="<?= h(statusToSlug($key)) ?>" <?= $statusParam === $key ? 'selected' : '' ?>><?= h($cfg['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-xl-1 col-md-3">
              <label class="form-label">From</label>
              <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="form-control">
            </div>
            <div class="col-xl-1 col-md-3">
              <label class="form-label">To</label>
              <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="form-control">
            </div>
            <div class="col-xl-2 col-md-6 d-flex gap-2">
              <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i> Filter</button>
              <a href="https://ricevaly.bd/pos/site/order_management.php?source=online&status=all" class="btn btn-light">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Orders table -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0">Orders</h5>
            <small class="text-muted"><?= $totalFiltered ?> result(s)</small>
          </div>
          <span class="badge bg-soft-primary text-primary">Page <?= $page ?> / <?= $totalPages ?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0 order-table">
              <thead>
                <tr>
                  <th style="width:70px">#</th>
                  <th>Invoice</th>
                  <th>Customer</th>
                  <th>Items</th>
                  <th>Amount</th>
                  <th>Payment</th>
                  <th>Status</th>
                  <th>Source</th>
                  <th>Date</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($orders)): ?>
                <tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox" style="font-size:36px;display:block;margin-bottom:8px;"></i>No orders found for the selected filters.</div></td></tr>
              <?php else: ?>
                <?php foreach ($orders as $index => $order):
                  $st = $statusConfig[$order['order_status']] ?? ['label' => ucfirst(str_replace('_',' ', $order['order_status'])), 'class' => 'secondary'];
                  $pay = $paymentConfig[$order['payment_status']] ?? ['label' => ucfirst((string)$order['payment_status']), 'class' => 'secondary'];
                  $customerName = $order['recipient_name'] ?: ($order['customer_shop_name'] ?: ($order['customer_name'] ?: 'Walk-in Customer'));
                  $customerPhone = $order['shipping_phone'] ?: ($order['customer_phone'] ?: '');
                ?>
                <tr id="orderRow<?= (int)$order['id'] ?>">
                  <td><?= $offset + $index + 1 ?></td>
                  <td>
                    <div class="invoice"><?= h($order['invoice_no']) ?></div>
                    <small class="text-muted">ID: <?= (int)$order['id'] ?></small>
                  </td>
                  <td>
                    <div class="fw-semibold"><?= h($customerName) ?></div>
                    <?php if ($customerPhone): ?><small class="text-muted"><?= h($customerPhone) ?></small><?php endif; ?>
                  </td>
                  <td>
                    <span class="fw-semibold"><?= (int)$order['item_count'] ?></span>
                    <small class="text-muted d-block">Qty: <?= h(rtrim(rtrim(number_format((float)$order['total_qty'], 2, '.', ''), '0'), '.')) ?></small>
                  </td>
                  <td>
                    <div class="fw-bold">৳<?= money($order['total_amount']) ?></div>
                    <?php if ((float)$order['delivery_cost'] > 0): ?><small class="text-muted">Delivery ৳<?= money($order['delivery_cost']) ?></small><?php endif; ?>
                  </td>
                  <td>
                    <span class="badge bg-<?= h($pay['class']) ?> badge-status"><?= h($pay['label']) ?></span>
                    <small class="d-block text-muted mt-1"><?= h(strtoupper((string)$order['payment_method'])) ?></small>
                  </td>
                  <td><span class="badge bg-<?= h($st['class']) ?> badge-status js-order-status-badge"><?= h($st['label']) ?></span></td>
                  <td><span class="badge <?= $order['order_source'] === 'online' ? 'bg-soft-primary text-primary' : 'bg-soft-dark text-dark' ?> source-badge"><?= h(strtoupper($order['order_source'])) ?></span></td>
                  <td>
                    <div><?= h(date('d M Y', strtotime($order['created_at']))) ?></div>
                    <small class="text-muted"><?= h(date('h:i A', strtotime($order['created_at']))) ?></small>
                  </td>
                  <td class="text-end">
                    <div class="order-action-group">
                      <button type="button" class="btn btn-sm btn-primary view-order-btn" data-id="<?= (int)$order['id'] ?>">
                        <i class="bi bi-eye"></i> View
                      </button>
                      <?php if (($order['order_source'] ?? '') === 'online' && in_array((string)$order['invoice_no'], $protectedDeleteInvoices, true)): ?>
                        <span class="btn btn-sm btn-light text-muted disabled" title="Protected order"><i class="bi bi-lock"></i> Protected</span>
                      <?php elseif (($order['order_source'] ?? '') === 'online'): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger delete-order-btn"
                                data-id="<?= (int)$order['id'] ?>"
                                data-invoice="<?= h($order['invoice_no']) ?>"
                                data-customer="<?= h($customerName) ?>">
                          <i class="bi bi-trash"></i> Delete
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
          <small class="text-muted">Showing <?= $totalFiltered ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $totalFiltered) ?> of <?= $totalFiltered ?></small>
          <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(pageUrl(max(1, $page - 1))) ?>">&laquo;</a></li>
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
              <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h(pageUrl($p)) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h(pageUrl(min($totalPages, $page + 1))) ?>">&raquo;</a></li>
          </ul></nav>
        </div>
        <?php endif; ?>
      </div>

    </main>

    <footer class="text-center text-muted py-3 small border-top bg-white">
      © <?php echo date('Y'); ?> Rice Valy — <a href="https://digitalsoftwarebd.com" target="_blank" class="text-decoration-none">Designed by DSB</a>
    </footer>
  </div>
</div>

<form id="logout-form" action="/admin/logout" method="POST" style="display:none;">
  <input type="hidden" name="_token" value="Tzrj0tXolfHXDKSJ8dFPoXmwrjtVbwt6bkEtEaYb">
</form>

<!-- ============================================================ -->
<!-- ORDER DETAILS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title"><i class="bi bi-receipt me-2"></i> Order Details</h5>
          <small class="text-muted" id="modalInvoiceText">Loading...</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="orderLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="mt-2 text-muted">Loading order...</div>
        </div>

        <div id="orderDetailContent" class="d-none">
          <input type="hidden" id="detailOrderId">
          <div class="row">
            <div class="col-lg-8">
              <div class="detail-section">
                <h5 class="mb-3">Order Summary</h5>
                <div class="row g-3">
                  <div class="col-md-4"><div class="order-detail-label">Invoice</div><div class="order-detail-value" id="dInvoice"></div></div>
                  <div class="col-md-4"><div class="order-detail-label">Order Source</div><div class="order-detail-value" id="dSource"></div></div>
                  <div class="col-md-4"><div class="order-detail-label">Order Date</div><div class="order-detail-value" id="dDate"></div></div>
                  <div class="col-md-4"><div class="order-detail-label">Payment Method</div><div class="order-detail-value" id="dPaymentMethod"></div></div>
                  <div class="col-md-4"><div class="order-detail-label">Payment Status</div><div class="order-detail-value" id="dPaymentStatus"></div></div>
                  <div class="col-md-4"><div class="order-detail-label">Order Status</div><div class="order-detail-value" id="dOrderStatus"></div></div>
                </div>
              </div>

              <div class="detail-section">
                <h5 class="mb-3">Customer & Delivery</h5>
                <div class="row g-3">
                  <div class="col-md-6"><div class="order-detail-label">Customer</div><div class="order-detail-value" id="dCustomer"></div></div>
                  <div class="col-md-6"><div class="order-detail-label">Phone</div><div class="order-detail-value" id="dPhone"></div></div>
                  <div class="col-md-6"><div class="order-detail-label">Email</div><div class="order-detail-value" id="dEmail"></div></div>
                  <div class="col-md-6"><div class="order-detail-label">Shipping Method</div><div class="order-detail-value" id="dShippingMethod"></div></div>
                  <div class="col-12"><div class="order-detail-label">Shipping Address</div><div class="order-detail-value" id="dAddress"></div></div>
                  <div class="col-12"><div class="order-detail-label">Customer Note</div><div class="order-detail-value" id="dCustomerNote"></div></div>
                </div>
              </div>

              <div class="detail-section">
                <h5 class="mb-3">Items</h5>
                <div class="table-responsive">
                  <table class="table table-sm item-table mb-0">
                    <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody id="orderItemsBody"></tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="col-lg-4">
              <div class="detail-section">
                <h5 class="mb-3">Amount</h5>
                <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><strong id="aSubtotal"></strong></div>
                <div class="d-flex justify-content-between mb-2"><span>Delivery</span><strong id="aDelivery"></strong></div>
                <div class="d-flex justify-content-between mb-2"><span>Discount</span><strong id="aDiscount"></strong></div>
                <div class="d-flex justify-content-between mb-2"><span>Tax</span><strong id="aTax"></strong></div>
                <hr>
                <div class="d-flex justify-content-between fs-5"><span>Total</span><strong id="aTotal"></strong></div>
                <div class="d-flex justify-content-between mt-2"><span>Paid</span><strong class="text-success" id="aPaid"></strong></div>
                <div class="d-flex justify-content-between mt-2"><span>Due</span><strong class="text-danger" id="aDue"></strong></div>
              </div>

              <div class="detail-section">
                <h5 class="mb-3">Update Order</h5>
                <div class="mb-3">
                  <label class="form-label">Order Status</label>
                  <select id="editOrderStatus" class="form-select">
                    <?php foreach ($statusConfig as $key => $cfg): ?>
                      <option value="<?= h($key) ?>"><?= h($cfg['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Payment Status</label>
                  <select id="editPaymentStatus" class="form-select">
                    <?php foreach ($paymentConfig as $key => $cfg): ?>
                      <option value="<?= h($key) ?>"><?= h($cfg['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted d-block mt-1">For COD orders, choosing Completed will automatically select Paid. You can still change it before saving.</small>
                </div>
                <div class="mb-3">
                  <label class="form-label">Admin Note</label>
                  <textarea id="editAdminNote" rows="4" class="form-control" placeholder="Internal note about this order..."></textarea>
                </div>
                <button type="button" id="saveOrderUpdate" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i> Save Update</button>
                <small class="text-muted d-block mt-2">This page updates order workflow only. It does not create cash/bank accounting entries.</small>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SAFE DELETE ORDER MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="deleteOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-1"></i> Delete Online Order</h5>
          <small class="text-muted">Protected, audited hard-delete</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="deleteOrderId">
        <div class="delete-warning-box mb-3">
          This permanently removes the live order and its owned item/shipping records. A full JSON snapshot is saved to <strong>deleted_orders_archive</strong> first. Orders linked to wallet/referral accounting are blocked.
        </div>
        <div class="mb-3">
          <div class="order-detail-label">Order</div>
          <div class="fw-bold" id="deleteOrderInvoiceLabel"></div>
          <small class="text-muted" id="deleteOrderCustomerLabel"></small>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason for deletion <span class="text-danger">*</span></label>
          <textarea id="deleteOrderReason" class="form-control" rows="3" maxlength="500" placeholder="Example: Test order / duplicate order"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label">Type the invoice number to confirm</label>
          <input type="text" id="deleteOrderInvoiceConfirm" class="form-control" autocomplete="off" spellcheck="false">
          <small class="text-muted">Exact match required.</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteOrder" disabled>
          <i class="bi bi-trash me-1"></i> Archive &amp; Delete
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- SCRIPTS -->
<!-- ============================================================ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/2.1.2/sweetalert.min.js"></script>

<script>
if (window.jQuery) {
  jQuery.ajaxSetup({
    headers: { 'X-CSRF-Token': <?= json_encode(siteAdminCsrfToken(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?> }
  });
}
</script>

<script>
/* ============ SIDEBAR TOGGLE ============ */
document.addEventListener('DOMContentLoaded', function(){
  const sb=document.getElementById('rvSidebar');
  const bd=document.getElementById('rvBackdrop');
  const tg=document.getElementById('rvToggle');
  function close(){ sb.classList.remove('open'); bd.classList.remove('show'); }
  tg && tg.addEventListener('click', function(){ sb.classList.toggle('open'); bd.classList.toggle('show'); });
  bd && bd.addEventListener('click', close);
});
</script>

<script>
(function($) {
    'use strict';

    const STATUS_LABELS = <?= json_encode(array_map(fn($v) => $v['label'], $statusConfig), JSON_UNESCAPED_UNICODE) ?>;
    const PAYMENT_LABELS = <?= json_encode(array_map(fn($v) => $v['label'], $paymentConfig), JSON_UNESCAPED_UNICODE) ?>;

    toastr.options = {
        closeButton: true, progressBar: true, positionClass: "toast-top-right",
        timeOut: 3000, extendedTimeOut: 1000, preventDuplicates: true
    };

    function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }

    function money(value) {
        const n = parseFloat(value || 0);
        return '৳' + n.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function fmtQty(value) {
        const n = parseFloat(value || 0);
        return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    }

    function fmtDate(value) {
        if (!value) return '-';
        const d = new Date(value.replace(' ', 'T'));
        if (isNaN(d.getTime())) return value;
        return d.toLocaleString('en-GB', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
    }

    function customerName(o) {
        return o.recipient_name || o.customer_shop_name || o.customer_name || 'Walk-in Customer';
    }

    function shippingAddress(o) {
        let parts = [];
        if (o.shipping_address) parts.push(o.shipping_address);
        if (o.shipping_city) parts.push(o.shipping_city);
        if (o.shipping_postal_code) parts.push(o.shipping_postal_code);
        if (!parts.length && o.customer_address) parts.push(o.customer_address);
        return parts.length ? parts.join(', ') : '-';
    }

    function loadOrder(id) {
        $('#orderLoading').removeClass('d-none');
        $('#orderDetailContent').addClass('d-none');
        $('#modalInvoiceText').text('Loading...');
        $('#orderDetailsModal').modal('show');

        $.ajax({
            url: window.location.pathname, method: 'POST', dataType: 'json',
            data: {action: 'get_order_details', id: id},
            success: function(res) {
                if (!res.success) { toastr.error(res.message || 'Could not load order.'); return; }
                const o = res.order;
                $('#detailOrderId').val(o.id);
                $('#modalInvoiceText').text(o.invoice_no || ('Order #' + o.id));
                $('#dInvoice').text(o.invoice_no || '-');
                $('#dSource').text((o.order_source || '-').toUpperCase());
                $('#dDate').text(fmtDate(o.created_at));
                $('#dPaymentMethod').text((o.payment_method || '-').toUpperCase());
                $('#dPaymentStatus').text(PAYMENT_LABELS[o.payment_status] || o.payment_status || '-');
                $('#dOrderStatus').text(STATUS_LABELS[o.order_status] || o.order_status || '-');

                $('#dCustomer').text(customerName(o));
                $('#dPhone').text(o.shipping_phone || o.customer_phone || '-');
                $('#dEmail').text(o.customer_email || '-');
                $('#dShippingMethod').text(o.shipping_method_name || (parseFloat(o.delivery_cost || 0) > 0 ? 'Delivery' : '-'));
                $('#dAddress').text(shippingAddress(o));
                $('#dCustomerNote').text(o.customer_note || '-');

                $('#aSubtotal').text(money(o.subtotal));
                $('#aDelivery').text(money(o.delivery_cost));
                $('#aDiscount').text(money(o.discount));
                $('#aTax').text(money(o.tax));
                $('#aTotal').text(money(o.total_amount));
                $('#aPaid').text(money(o.paid_amount));
                $('#aDue').text(money(o.due_amount));

                $('#editOrderStatus').val(o.order_status);
                $('#editPaymentStatus').val(o.payment_status || 'pending');
                $('#editOrderStatus').data('payment-method', (o.payment_method || '').toLowerCase());
                $('#editAdminNote').val(o.admin_note || '');

                let rows = '';
                if (!res.items || !res.items.length) {
                    rows = '<tr><td colspan="4" class="text-center text-muted py-3">No items found.</td></tr>';
                } else {
                    res.items.forEach(function(item) {
                        const unit = item.product_unit ? ' ' + esc(item.product_unit) : '';
                        rows += '<tr>' +
                            '<td><strong>' + esc(item.product_name) + '</strong><small class="text-muted d-block">Product ID: ' + esc(item.product_id) + '</small></td>' +
                            '<td class="text-end">' + esc(fmtQty(item.qty)) + unit + '</td>' +
                            '<td class="text-end">' + money(item.price) + '</td>' +
                            '<td class="text-end fw-semibold">' + money(item.subtotal) + '</td>' +
                            '</tr>';
                    });
                }
                $('#orderItemsBody').html(rows);

                $('#orderLoading').addClass('d-none');
                $('#orderDetailContent').removeClass('d-none');
            },
            error: function(xhr) {
                let msg = 'Could not load order details.';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                toastr.error(msg);
                $('#orderDetailsModal').modal('hide');
            }
        });
    }

    $(document).on('click', '.view-order-btn', function() {
        loadOrder($(this).data('id'));
    });

    /* ============ DELETE ORDER ============ */
    let deleteTargetInvoice = '';

    $(document).on('click', '.delete-order-btn', function() {
        const id = parseInt($(this).data('id') || 0, 10);
        const invoice = String($(this).data('invoice') || '');
        const customer = String($(this).data('customer') || '');
        if (!id || !invoice) return;

        deleteTargetInvoice = invoice;
        $('#deleteOrderId').val(id);
        $('#deleteOrderInvoiceLabel').text(invoice + ' · ID ' + id);
        $('#deleteOrderCustomerLabel').text(customer || 'Online customer');
        $('#deleteOrderReason').val('');
        $('#deleteOrderInvoiceConfirm').val('');
        $('#confirmDeleteOrder').prop('disabled', true);
        $('#deleteOrderModal').modal('show');
    });

    $('#deleteOrderInvoiceConfirm, #deleteOrderReason').on('input', function() {
        const invoiceOk = $('#deleteOrderInvoiceConfirm').val().trim() === deleteTargetInvoice;
        const reasonOk = $('#deleteOrderReason').val().trim().length >= 3;
        $('#confirmDeleteOrder').prop('disabled', !(invoiceOk && reasonOk));
    });

    $('#confirmDeleteOrder').on('click', function() {
        const id = parseInt($('#deleteOrderId').val() || 0, 10);
        const confirmInvoice = $('#deleteOrderInvoiceConfirm').val().trim();
        const reason = $('#deleteOrderReason').val().trim();
        if (!id || confirmInvoice !== deleteTargetInvoice || reason.length < 3) return;

        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Deleting...');

        $.ajax({
            url: window.location.pathname, method: 'POST', dataType: 'json',
            data: { action: 'delete_order', id: id, confirm_invoice: confirmInvoice, delete_reason: reason },
            success: function(res) {
                if (!res.success) { toastr.error(res.message || 'Delete failed.'); return; }
                $('#deleteOrderModal').modal('hide');
                $('#orderRow' + id).fadeOut(250, function(){ $(this).remove(); });
                toastr.success(res.message || 'Order deleted.');
                setTimeout(function(){ window.location.reload(); }, 700);
            },
            error: function(xhr) {
                let msg = 'Order could not be deleted safely.';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                toastr.error(msg);
            },
            complete: function() {
                $btn.html('<i class="bi bi-trash me-1"></i> Archive &amp; Delete');
                const invoiceOk = $('#deleteOrderInvoiceConfirm').val().trim() === deleteTargetInvoice;
                const reasonOk = $('#deleteOrderReason').val().trim().length >= 3;
                $btn.prop('disabled', !(invoiceOk && reasonOk));
            }
        });
    });

    /* ============ UPDATE ORDER ============ */
    $('#editOrderStatus').on('change', function() {
        const method = String($(this).data('payment-method') || '').toLowerCase();
        if ($(this).val() === 'completed' && method === 'cod') {
            $('#editPaymentStatus').val('paid');
        }
    });

    $('#saveOrderUpdate').on('click', function() {
        const id = parseInt($('#detailOrderId').val() || 0, 10);
        const status = $('#editOrderStatus').val();
        const paymentStatus = $('#editPaymentStatus').val();
        const note = $('#editAdminNote').val();
        if (!id) return;

        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.ajax({
            url: window.location.pathname, method: 'POST', dataType: 'json',
            data: { action: 'update_order_status', id: id, order_status: status, payment_status: paymentStatus, admin_note: note },
            success: function(res) {
                if (!res.success) { toastr.error(res.message || 'Update failed.'); return; }
                toastr.success(res.message || 'Order updated.');
                $('#dOrderStatus').text(STATUS_LABELS[status] || status);
                $('#dPaymentStatus').text(PAYMENT_LABELS[paymentStatus] || paymentStatus);
                if (paymentStatus === 'paid') {
                    $('#aPaid').text(money(res.paid_amount));
                    $('#aDue').text(money(res.due_amount));
                }
                setTimeout(function() { window.location.reload(); }, 500);
            },
            error: function(xhr) {
                let msg = 'Update failed.';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                if (msg.toLowerCase().includes('data truncated') || msg.toLowerCase().includes('order_status')) {
                    msg += ' Run order_management_migration.sql once to enable the new order statuses.';
                }
                toastr.error(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Save Update');
            }
        });
    });

})(jQuery);
</script>
</body>
</html>