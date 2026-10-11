<?php
require_once __DIR__ . '/site_admin_guard.php';
requireSiteAdmin(1);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
mysqli_report(MYSQLI_REPORT_OFF);

define('BASE_URL', 'https://ricevaly.bd/');
$dbCandidates = [
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config/db.php',
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
if (!$dbLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection could not be loaded. Expected /config/db.php');
}

$siteShopId = tenantShopId();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v){ return number_format((float)$v, 2); }
function pct($v){ return number_format((float)$v, 1); }
function validDate($v){
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}
function statusClass($status){
    $map = [
        'pending'=>'warning','processing'=>'info','on_hold'=>'secondary','on_the_way'=>'primary',
        'in_courier'=>'primary','completed'=>'success','cancelled'=>'danger','paid'=>'success',
        'failed'=>'danger','refunded'=>'secondary'
    ];
    return $map[$status] ?? 'secondary';
}
function statusLabel($status){ return ucwords(str_replace(['-','_'], ' ', (string)$status)); }
function reportUrl($overrides=[]){
    $q = $_GET;
    unset($q['export']);
    foreach ($overrides as $k=>$v) {
        if ($v === null) unset($q[$k]); else $q[$k] = $v;
    }
    return 'online_business_report.php?' . http_build_query($q);
}

// -----------------------------
// FILTERS - ONLINE ONLY
// -----------------------------
$preset = (string)($_GET['preset'] ?? 'month');
$allowedPresets = ['today','7d','30d','month','all','custom'];
if (!in_array($preset, $allowedPresets, true)) $preset = 'month';

$today = date('Y-m-d');
$from = '';
$to = '';
if ($preset === 'today') {
    $from = $to = $today;
} elseif ($preset === '7d') {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to = $today;
} elseif ($preset === '30d') {
    $from = date('Y-m-d', strtotime('-29 days'));
    $to = $today;
} elseif ($preset === 'month') {
    $from = date('Y-m-01');
    $to = $today;
} elseif ($preset === 'custom') {
    $from = validDate($_GET['from'] ?? '') ? (string)$_GET['from'] : date('Y-m-01');
    $to = validDate($_GET['to'] ?? '') ? (string)$_GET['to'] : $today;
    if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }
}

// Pull real status values so any migrated workflow status is supported safely.
$orderStatuses = [];
$res = $conn->query("SELECT DISTINCT order_status FROM orders WHERE order_source='online' AND shop_id={$siteShopId} AND order_status IS NOT NULL ORDER BY order_status");
if ($res) { while($r=$res->fetch_assoc()) $orderStatuses[]=(string)$r['order_status']; $res->free(); }
$knownWorkflow = ['pending','processing','on_hold','in_courier','on_the_way','completed','cancelled'];
foreach ($knownWorkflow as $s) if (!in_array($s,$orderStatuses,true)) $orderStatuses[]=$s;

$orderStatus = (string)($_GET['order_status'] ?? 'all');
if ($orderStatus !== 'all' && !in_array($orderStatus,$orderStatuses,true)) $orderStatus='all';
$paymentStatus = (string)($_GET['payment_status'] ?? 'all');
$paymentStatuses = ['pending','paid','failed','refunded'];
if ($paymentStatus !== 'all' && !in_array($paymentStatus,$paymentStatuses,true)) $paymentStatus='all';

$where = ["o.order_source='online'", "o.shop_id={$siteShopId}"];
$dateWhere = ["o.order_source='online'", "o.shop_id={$siteShopId}"];
if ($preset !== 'all') {
    $fromEsc = $conn->real_escape_string($from);
    $toEsc = $conn->real_escape_string($to);
    $dateCondition = "o.created_at >= '{$fromEsc} 00:00:00' AND o.created_at < DATE_ADD('{$toEsc} 00:00:00', INTERVAL 1 DAY)";
    $where[] = $dateCondition;
    $dateWhere[] = $dateCondition;
}
if ($orderStatus !== 'all') $where[] = "o.order_status='".$conn->real_escape_string($orderStatus)."'";
if ($paymentStatus !== 'all') $where[] = "o.payment_status='".$conn->real_escape_string($paymentStatus)."'";
$whereSql = implode(' AND ', $where);
$dateWhereSql = implode(' AND ', $dateWhere);

$periodText = $preset === 'all' ? 'All time' : date('d M Y',strtotime($from)).' – '.date('d M Y',strtotime($to));

// -----------------------------
// CSV EXPORTS
// -----------------------------
$export = (string)($_GET['export'] ?? '');
if (in_array($export,['orders','products','customers'],true)) {
    $stamp = date('Ymd_His');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ricevaly_online_'.$export.'_'.$stamp.'.csv"');
    echo "\xEF\xBB\xBF"; // Excel UTF-8 BOM
    $out = fopen('php://output','w');

    if ($export === 'orders') {
        fputcsv($out,['Invoice','Date','Customer','Phone','Subtotal','Delivery','Discount','Tax','Total','Paid','Due','Payment Method','Payment Status','Order Status','Delivery Address'], ',', '"', '');
        $sql = "SELECT o.invoice_no,o.created_at,COALESCE(c.name,c.shop_name,'Guest') customer_name,c.phone,o.subtotal,o.delivery_cost,o.discount,o.tax,o.total_amount,o.paid_amount,o.due_amount,o.payment_method,o.payment_status,o.order_status,osa.address,osa.city
                FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN order_shipping_addresses osa ON osa.order_id=o.id
                WHERE {$whereSql} ORDER BY o.created_at DESC,o.id DESC";
        if ($r=$conn->query($sql)) {
            while($x=$r->fetch_assoc()) fputcsv($out,[$x['invoice_no'],$x['created_at'],$x['customer_name'],$x['phone'],$x['subtotal'],$x['delivery_cost'],$x['discount'],$x['tax'],$x['total_amount'],$x['paid_amount'],$x['due_amount'],$x['payment_method'],$x['payment_status'],$x['order_status'],trim(($x['address']??'').(($x['city']??'')?', '.$x['city']:''),', ')], ',', '"', '');
            $r->free();
        }
    } elseif ($export === 'products') {
        fputcsv($out,['Product','Orders','Quantity','Sales Value'], ',', '"', '');
        $sql = "SELECT COALESCE(sp.product_name,p.product_name,sp_legacy.product_name,CONCAT('Product #',oi.product_id)) product_name,
                       COUNT(DISTINCT o.id) order_count,COALESCE(SUM(oi.qty),0) qty,COALESCE(SUM(oi.subtotal),0) sales
                FROM orders o
                INNER JOIN order_items oi ON oi.order_id=o.id
                LEFT JOIN products p ON p.id=oi.product_id
                LEFT JOIN site_products sp ON sp.id=oi.site_product_id
                LEFT JOIN site_products sp_legacy
                       ON oi.site_product_id IS NULL
                      AND p.id IS NULL
                      AND sp_legacy.id=oi.product_id
                WHERE {$whereSql} AND o.order_status<>'cancelled'
                GROUP BY COALESCE(oi.site_product_id,oi.product_id),sp.product_name,p.product_name,sp_legacy.product_name
                ORDER BY sales DESC";
        if ($r=$conn->query($sql)) { while($x=$r->fetch_assoc()) fputcsv($out,[$x['product_name'],$x['order_count'],$x['qty'],$x['sales']], ',', '"', ''); $r->free(); }
    } else {
        fputcsv($out,['Customer','Phone','Email','Orders','Order Value','Paid','Due','Last Purchase'], ',', '"', '');
        $sql = "SELECT c.id,COALESCE(c.name,c.shop_name,CONCAT('Customer #',c.id)) customer_name,c.phone,c.email,COUNT(o.id) orders,COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.total_amount ELSE 0 END),0) order_value,COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.paid_amount ELSE 0 END),0) paid,COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.due_amount ELSE 0 END),0) due,MAX(o.created_at) last_purchase
                FROM orders o INNER JOIN customers c ON c.id=o.customer_id WHERE {$whereSql} GROUP BY c.id,c.name,c.shop_name,c.phone,c.email ORDER BY order_value DESC";
        if ($r=$conn->query($sql)) { while($x=$r->fetch_assoc()) fputcsv($out,[$x['customer_name'],$x['phone'],$x['email'],$x['orders'],$x['order_value'],$x['paid'],$x['due'],$x['last_purchase']], ',', '"', ''); $r->free(); }
    }
    fclose($out);
    exit;
}

// -----------------------------
// KPI SUMMARY
// -----------------------------
$kpi = [
    'total_orders'=>0,'valid_orders'=>0,'gross_value'=>0,'completed_revenue'=>0,'collected'=>0,
    'outstanding'=>0,'aov'=>0,'cancelled_orders'=>0,'unique_customers'=>0,'paid_orders'=>0
];
$sql = "SELECT COUNT(*) total_orders,
               SUM(CASE WHEN o.order_status<>'cancelled' THEN 1 ELSE 0 END) valid_orders,
               COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.total_amount ELSE 0 END),0) gross_value,
               COALESCE(SUM(CASE WHEN o.order_status='completed' THEN o.total_amount ELSE 0 END),0) completed_revenue,
               COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN COALESCE(o.paid_amount,0) ELSE 0 END),0) collected,
               COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN GREATEST(COALESCE(o.due_amount,0),0) ELSE 0 END),0) outstanding,
               COALESCE(AVG(CASE WHEN o.order_status<>'cancelled' THEN o.total_amount END),0) aov,
               SUM(CASE WHEN o.order_status='cancelled' THEN 1 ELSE 0 END) cancelled_orders,
               COUNT(DISTINCT CASE WHEN o.customer_id IS NOT NULL AND o.customer_id>0 THEN o.customer_id END) unique_customers,
               SUM(CASE WHEN o.payment_status='paid' THEN 1 ELSE 0 END) paid_orders
        FROM orders o WHERE {$whereSql}";
if ($res=$conn->query($sql)) { $kpi=array_merge($kpi,$res->fetch_assoc()?:[]); $res->free(); }
$cancelRate = ((int)$kpi['total_orders'] > 0) ? ((int)$kpi['cancelled_orders']*100/(int)$kpi['total_orders']) : 0;
$collectionRate = ((float)$kpi['gross_value'] > 0) ? ((float)$kpi['collected']*100/(float)$kpi['gross_value']) : 0;

// New registered users in the same date period (website customer accounts only).
$newSignups = 0;
$signupWhere = "role='customer' AND shop_id={$siteShopId}";
if ($preset !== 'all') {
    $signupWhere .= " AND created_at >= '{$fromEsc} 00:00:00' AND created_at < DATE_ADD('{$toEsc} 00:00:00', INTERVAL 1 DAY)";
}
if ($res=$conn->query("SELECT COUNT(*) c FROM users WHERE {$signupWhere}")) { $r=$res->fetch_assoc(); $newSignups=(int)($r['c']??0); $res->free(); }

// Current website product snapshot.
$productSnapshot = ['total'=>0,'live'=>0,'low'=>0,'out'=>0];
if ($res=$conn->query("SELECT COUNT(*) total,SUM(CASE WHEN site_status=1 AND site_visible=1 THEN 1 ELSE 0 END) live,SUM(CASE WHEN site_stock>0 AND site_stock<=reorder_level THEN 1 ELSE 0 END) low,SUM(CASE WHEN site_stock<=0 THEN 1 ELSE 0 END) out FROM site_products")) { $productSnapshot=array_merge($productSnapshot,$res->fetch_assoc()?:[]); $res->free(); }

// -----------------------------
// STATUS / PAYMENT BREAKDOWN
// -----------------------------
$statusRows=[];
if ($res=$conn->query("SELECT o.order_status label,COUNT(*) orders,COALESCE(SUM(o.total_amount),0) value FROM orders o WHERE {$dateWhereSql} GROUP BY o.order_status ORDER BY orders DESC")) { while($r=$res->fetch_assoc())$statusRows[]=$r; $res->free(); }
$paymentRows=[];
if ($res=$conn->query("SELECT o.payment_status label,COUNT(*) orders,COALESCE(SUM(o.total_amount),0) value FROM orders o WHERE {$dateWhereSql} GROUP BY o.payment_status ORDER BY orders DESC")) { while($r=$res->fetch_assoc())$paymentRows[]=$r; $res->free(); }

// -----------------------------
// DAILY TREND (cap all-time chart to last 60 order-days for readability)
// -----------------------------
$trend=[];
$trendSql = "SELECT DATE(o.created_at) report_date,COUNT(*) orders,
                    COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.total_amount ELSE 0 END),0) order_value,
                    COALESCE(SUM(CASE WHEN o.order_status='completed' THEN o.total_amount ELSE 0 END),0) completed_value,
                    COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.paid_amount ELSE 0 END),0) collected
             FROM orders o WHERE {$whereSql} GROUP BY DATE(o.created_at) ORDER BY report_date ASC";
if ($res=$conn->query($trendSql)) { while($r=$res->fetch_assoc())$trend[]=$r; $res->free(); }
if (count($trend)>60) $trend=array_slice($trend,-60);

// -----------------------------
// TOP PRODUCTS / CUSTOMERS
// -----------------------------
$topProducts=[];
$sql = "SELECT oi.product_id,oi.site_product_id,
               COALESCE(sp.product_name,p.product_name,sp_legacy.product_name,CONCAT('Product #',oi.product_id)) product_name,
               COUNT(DISTINCT o.id) orders,COALESCE(SUM(oi.qty),0) qty,COALESCE(SUM(oi.subtotal),0) sales
        FROM orders o
        INNER JOIN order_items oi ON oi.order_id=o.id
        LEFT JOIN products p ON p.id=oi.product_id
        LEFT JOIN site_products sp ON sp.id=oi.site_product_id
        LEFT JOIN site_products sp_legacy
               ON oi.site_product_id IS NULL
              AND p.id IS NULL
              AND sp_legacy.id=oi.product_id
        WHERE {$whereSql} AND o.order_status<>'cancelled'
        GROUP BY COALESCE(oi.site_product_id,oi.product_id),oi.product_id,oi.site_product_id,sp.product_name,p.product_name,sp_legacy.product_name
        ORDER BY sales DESC LIMIT 10";
if ($res=$conn->query($sql)) { while($r=$res->fetch_assoc())$topProducts[]=$r; $res->free(); }

$topCustomers=[];
$sql = "SELECT c.id,COALESCE(c.name,c.shop_name,CONCAT('Customer #',c.id)) customer_name,c.phone,
               COUNT(o.id) orders,COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.total_amount ELSE 0 END),0) value,
               COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.paid_amount ELSE 0 END),0) paid,
               COALESCE(SUM(CASE WHEN o.order_status<>'cancelled' THEN o.due_amount ELSE 0 END),0) due,
               MAX(o.created_at) last_order
        FROM orders o INNER JOIN customers c ON c.id=o.customer_id
        WHERE {$whereSql} GROUP BY c.id,c.name,c.shop_name,c.phone ORDER BY value DESC LIMIT 10";
if ($res=$conn->query($sql)) { while($r=$res->fetch_assoc())$topCustomers[]=$r; $res->free(); }

// -----------------------------
// RECENT ORDERS
// -----------------------------
$recent=[];
$sql = "SELECT o.id,o.invoice_no,o.created_at,o.total_amount,o.paid_amount,o.due_amount,o.payment_method,o.payment_status,o.order_status,
               COALESCE(c.name,c.shop_name,'Guest') customer_name,c.phone,
               (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi WHERE oi.order_id=o.id) item_qty
        FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE {$whereSql}
        ORDER BY o.created_at DESC,o.id DESC LIMIT 15";
if ($res=$conn->query($sql)) { while($r=$res->fetch_assoc())$recent[]=$r; $res->free(); }

$trendLabels = array_map(fn($r)=>date('d M',strtotime($r['report_date'])),$trend);
$trendOrders = array_map(fn($r)=>(int)$r['orders'],$trend);
$trendValue = array_map(fn($r)=>(float)$r['order_value'],$trend);
$trendCollected = array_map(fn($r)=>(float)$r['collected'],$trend);
$statusLabels = array_map(fn($r)=>statusLabel($r['label']),$statusRows);
$statusCounts = array_map(fn($r)=>(int)$r['orders'],$statusRows);
$paymentLabels = array_map(fn($r)=>statusLabel($r['label']),$paymentRows);
$paymentCounts = array_map(fn($r)=>(int)$r['orders'],$paymentRows);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Online Business Report — Rice Valy Admin</title>
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
.btn-dark{background:#1f2937;border-color:#1f2937}
.btn-xs{padding:2px 8px;font-size:12px;border-radius:6px}
.badge-status{font-size:11px;padding:4px 9px;border-radius:20px}
.stat-card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow);background:#fff;height:100%}
.stat-card .stat-icon{
  width:44px;height:44px;display:flex;align-items:center;justify-content:center;
  border-radius:12px;background:#e6f4f1;color:var(--rv-primary);font-size:20px;flex-shrink:0;
}
.stat-card .stat-label{color:var(--rv-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.stat-card .stat-value{font-size:20px;font-weight:700;margin:0;line-height:1.2}
.stat-card .stat-sub{font-size:11px;color:#94a3b8;margin-top:3px}
.preset-btn{border-radius:20px;font-size:12px;padding:4px 12px}
.preset-btn.active{background:var(--rv-primary);color:#fff;border-color:var(--rv-primary);font-weight:600}
.online-lock{display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#dcfce7;color:#15803d;font-weight:700;font-size:11px;padding:5px 10px}
.filter-note{font-size:12px;color:var(--rv-muted)}
.section-note{font-size:12px;color:var(--rv-muted)}
.mini-bar{height:6px;background:#edf0f5;border-radius:8px;overflow:hidden;margin-top:4px}
.mini-bar>span{display:block;height:100%;background:var(--rv-primary);border-radius:8px}
.rank-badge{width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#e6f4f1;color:var(--rv-primary);font-weight:800;font-size:12px}
.snapshot-pill{border:1px solid var(--rv-border);border-radius:12px;padding:12px 14px;background:#fff;height:100%}
.snapshot-pill strong{font-size:20px;color:var(--rv-text);display:block;margin-top:2px}
.chart-wrap{position:relative;height:320px}
.chart-small{position:relative;height:240px}
.empty-state{padding:40px 20px;text-align:center;color:var(--rv-muted);font-size:13px}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px}
.form-control,.form-select{border-radius:8px;border-color:#d1d5db;font-size:14px}
.form-control:focus,.form-select:focus{border-color:var(--rv-primary);box-shadow:0 0 0 .2rem rgba(10,91,79,.12)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
code{color:var(--rv-primary);background:#f0fdfa;padding:2px 6px;border-radius:4px;font-size:12px}
.table-link{color:var(--rv-primary);font-weight:600;text-decoration:none}
.table-link:hover{text-decoration:underline}
.report-generated{font-size:12px;color:var(--rv-muted)}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
  .chart-wrap{height:280px}
  .report-table{min-width:980px}
}
@media print{
  body{background:#fff!important}
  .rv-sidebar,.rv-topbar,.filter-card,.no-print,.rv-footer{display:none!important}
  .rv-main{margin-left:0!important}
  .rv-content{padding:0!important}
  .card{box-shadow:none!important;break-inside:avoid;border-color:#d9dee7!important}
  .chart-wrap{height:250px}
  .page-break{break-before:page}
  .report-table{font-size:10px}
  .report-table th,.report-table td{padding:5px!important}
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
      <li><a href="site_management.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>

      <li class="rv-section">Orders</li>
      <li>
        <a data-bs-toggle="collapse" href="#navOrders" role="button" aria-expanded="false">
          <i class="bi bi-cart3"></i> Orders <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse sub" id="navOrders">
          <ul class="rv-nav">
            <li><a href="order_management.php?status=all&source=online"><i class="bi bi-list-ul"></i> All Orders</a></li>
            <li><a href="order_management.php?status=pending&source=online"><i class="bi bi-clock"></i> Pending</a></li>
            <li><a href="order_management.php?status=processing&source=online"><i class="bi bi-gear"></i> Processing</a></li>
            <li><a href="order_management.php?status=on-the-way&source=online"><i class="bi bi-truck"></i> On The Way</a></li>
            <li><a href="order_management.php?status=on-hold&source=online"><i class="bi bi-pause-circle"></i> On Hold</a></li>
            <li><a href="order_management.php?status=in-courier&source=online"><i class="bi bi-box-seam"></i> In Courier</a></li>
            <li><a href="order_management.php?status=completed&source=online"><i class="bi bi-check2-circle"></i> Completed</a></li>
            <li><a href="order_management.php?status=cancelled&source=online"><i class="bi bi-x-circle"></i> Cancelled</a></li>
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
            <li><a href="site_management.php"><i class="bi bi-pencil-square"></i> Product Manage</a></li>
            <li><a href="site_management.php"><i class="bi bi-box-seam"></i> Site Products</a></li>
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
            <li><a href="registered_users.php"><i class="bi bi-person-lines-fill"></i> Registered Users</a></li>
            <li><a href="online_customers.php"><i class="bi bi-wifi"></i> Online Customers</a></li>
          </ul>
        </div>
      </li>

      <li class="rv-section">Marketing</li>
      <li><a href="customer_dashboard_banners.php"><i class="bi bi-image"></i> Dashboard Banner</a></li>

      <li class="rv-section">Reports</li>
      <li><a href="today_online_overview.php"><i class="bi bi-activity"></i> Today Online Overview</a></li>
      <li><a href="online_business_report.php" class="active"><i class="bi bi-bar-chart"></i> Online Business Report</a></li>

      <li class="rv-section">Site Setting</li>
      <li><a href="https://www.ricevaly.com/admin/shipping-charge/manage"><i class="bi bi-truck"></i> Shipping Charge</a></li>
    </ul>
  </aside>

  <div class="rv-backdrop" id="rvBackdrop"></div>

  <!-- Main -->
  <div class="rv-main">
    <header class="rv-topbar">
      <button class="rv-toggle" id="rvToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
      <h5>Online Business Report</h5>
      <div class="rv-actions">
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <img src="https://www.ricevaly.com/public/uploads/users/1707537536-faveicon.webp" alt="user">
            <span class="ms-2 d-none d-sm-inline fw-semibold">Admin</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header">Welcome, Admin</h6></li>
            <li><a class="dropdown-item" href="site_management.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
            <li><a class="dropdown-item" href="referral_management.php"><i class="bi bi-gift me-2"></i> Credit &amp; Referral</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="document.getElementById('logout-form').submit();return false;"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="rv-content">

      <!-- Page title + hero -->
      <div class="rv-page-title">
        <div>
          <h4>Online Business Performance Report</h4>
          <p>
            Orders, revenue, collection, customers, registrations and website product performance in one management view.
            <span class="online-lock ms-2"><i class="bi bi-lock-fill"></i> ONLINE ONLY · POS EXCLUDED</span>
          </p>
          <div class="report-generated mt-1">Reporting period: <strong><?= h($periodText) ?></strong> · Generated: <?= h(date('d M Y, h:i A')) ?></div>
        </div>
        <div class="rv-toolbar no-print">
          <button class="btn btn-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print / PDF</button>
          <div class="btn-group">
            <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Export CSV</button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= h(reportUrl(['export'=>'orders'])) ?>"><i class="bi bi-receipt me-2"></i> Orders CSV</a></li>
              <li><a class="dropdown-item" href="<?= h(reportUrl(['export'=>'products'])) ?>"><i class="bi bi-box me-2"></i> Products CSV</a></li>
              <li><a class="dropdown-item" href="<?= h(reportUrl(['export'=>'customers'])) ?>"><i class="bi bi-people me-2"></i> Customers CSV</a></li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Filter -->
      <div class="card filter-card mb-3 no-print">
        <div class="card-body">
          <form method="get" id="reportFilter">
            <div class="row g-3 align-items-end">
              <div class="col-xl-4">
                <label class="form-label">Quick Period</label>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach(['today'=>'Today','7d'=>'7 Days','30d'=>'30 Days','month'=>'This Month','all'=>'All Time'] as $key=>$label): ?>
                    <a class="btn btn-sm btn-outline-primary preset-btn <?= $preset===$key?'active':'' ?>"
                       href="<?= h(reportUrl(['preset'=>$key,'from'=>null,'to'=>null])) ?>"><?= $label ?></a>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="col-md-3 col-xl-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
              <div class="col-md-3 col-xl-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
              <div class="col-md-3 col-xl-2">
                <label class="form-label">Order Status</label>
                <select class="form-select" name="order_status">
                  <option value="all">All Statuses</option>
                  <?php foreach($orderStatuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $orderStatus===$s?'selected':'' ?>><?= h(statusLabel($s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3 col-xl-2">
                <label class="form-label">Payment</label>
                <select class="form-select" name="payment_status">
                  <option value="all">All Payments</option>
                  <?php foreach($paymentStatuses as $s): ?>
                    <option value="<?= h($s) ?>" <?= $paymentStatus===$s?'selected':'' ?>><?= h(statusLabel($s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <input type="hidden" name="preset" value="custom">
              <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="filter-note"><i class="bi bi-info-circle me-1"></i> All calculations are hard-filtered to website/online orders; POS is never included.</div>
                <div class="d-flex gap-2">
                  <a href="online_business_report.php" class="btn btn-light btn-sm">Reset</a>
                  <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Apply Filter</button>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Online Orders</div>
              <h3 class="stat-value"><?= number_format((int)$kpi['total_orders']) ?></h3>
              <div class="stat-sub"><?= number_format((int)$kpi['unique_customers']) ?> unique buyers</div>
            </div>
            <div class="stat-icon"><i class="bi bi-cart3"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Gross Order Value</div>
              <h3 class="stat-value">৳<?= money($kpi['gross_value']) ?></h3>
              <div class="stat-sub">Cancelled orders excluded</div>
            </div>
            <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Collected Amount</div>
              <h3 class="stat-value">৳<?= money($kpi['collected']) ?></h3>
              <div class="stat-sub"><?= pct($collectionRate) ?>% of gross value</div>
            </div>
            <div class="stat-icon"><i class="bi bi-credit-card"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Outstanding Due</div>
              <h3 class="stat-value text-danger">৳<?= money($kpi['outstanding']) ?></h3>
              <div class="stat-sub">Current filtered online orders</div>
            </div>
            <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Completed Revenue</div>
              <h3 class="stat-value text-success">৳<?= money($kpi['completed_revenue']) ?></h3>
              <div class="stat-sub">Completed status only</div>
            </div>
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Average Order Value</div>
              <h3 class="stat-value">৳<?= money($kpi['aov']) ?></h3>
              <div class="stat-sub">Non-cancelled orders</div>
            </div>
            <div class="stat-icon"><i class="bi bi-bar-chart"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">New Registered Users</div>
              <h3 class="stat-value"><?= number_format($newSignups) ?></h3>
              <div class="stat-sub">Website customer accounts in period</div>
            </div>
            <div class="stat-icon"><i class="bi bi-person-plus"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Cancellation Rate</div>
              <h3 class="stat-value"><?= pct($cancelRate) ?>%</h3>
              <div class="stat-sub"><?= number_format((int)$kpi['cancelled_orders']) ?> cancelled of <?= number_format((int)$kpi['total_orders']) ?></div>
            </div>
            <div class="stat-icon"><i class="bi bi-x-circle"></i></div>
          </div></div>
        </div>
      </div>

      <!-- Website product snapshot -->
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-box-seam me-1"></i> <strong>Website Product Snapshot</strong>
            <div class="section-note">Current site product inventory snapshot; not affected by report date.</div>
          </div>
          <a class="btn btn-sm btn-light no-print" href="site_management.php"><i class="bi bi-box"></i> Manage Products</a>
        </div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-3 col-6">
              <div class="snapshot-pill">
                <div class="text-muted small">Total Site Products</div>
                <strong><?= number_format((int)$productSnapshot['total']) ?></strong>
              </div>
            </div>
            <div class="col-md-3 col-6">
              <div class="snapshot-pill">
                <div class="text-muted small">Live &amp; Visible</div>
                <strong class="text-success"><?= number_format((int)$productSnapshot['live']) ?></strong>
              </div>
            </div>
            <div class="col-md-3 col-6">
              <div class="snapshot-pill">
                <div class="text-muted small">Low Stock (1–5)</div>
                <strong class="text-warning"><?= number_format((int)$productSnapshot['low']) ?></strong>
              </div>
            </div>
            <div class="col-md-3 col-6">
              <div class="snapshot-pill">
                <div class="text-muted small">Out of Stock</div>
                <strong class="text-danger"><?= number_format((int)$productSnapshot['out']) ?></strong>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="row g-3 mb-3">
        <div class="col-xl-8">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between mb-2">
                <div>
                  <h5 class="mb-1 fw-bold">Sales &amp; Collection Trend</h5>
                  <div class="section-note">Non-cancelled online order value versus collected amount.</div>
                </div>
              </div>
              <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
              <noscript><div class="alert alert-light mt-3">Enable JavaScript to display the chart.</div></noscript>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="card mb-3">
            <div class="card-body">
              <h5 class="mb-1 fw-bold">Order Status Mix</h5>
              <div class="section-note mb-2">Period-wide status distribution.</div>
              <div class="chart-small"><canvas id="statusChart"></canvas></div>
            </div>
          </div>
          <div class="card">
            <div class="card-body">
              <h5 class="mb-1 fw-bold">Payment Status Mix</h5>
              <div class="section-note mb-2">Period-wide payment distribution.</div>
              <div class="chart-small"><canvas id="paymentChart"></canvas></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top products & customers -->
      <div class="row g-3 mb-3 page-break">
        <div class="col-xl-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <h5 class="mb-1 fw-bold">Top Online Products</h5>
                  <div class="section-note">Ranked by non-cancelled online sales value.</div>
                </div>
                <a class="btn btn-sm btn-light no-print" href="<?= h(reportUrl(['export'=>'products'])) ?>"><i class="bi bi-download"></i> Export</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr><th>Rank</th><th>Product</th><th class="text-end">Orders</th><th class="text-end">Qty</th><th class="text-end">Sales</th></tr>
                  </thead>
                  <tbody>
                  <?php if(!$topProducts): ?>
                    <tr><td colspan="5" class="empty-state">No product sales in this filter.</td></tr>
                  <?php else:
                    $maxSales = max(array_map(fn($x)=>(float)$x['sales'], $topProducts));
                    foreach($topProducts as $i=>$p): ?>
                    <tr>
                      <td><span class="rank-badge"><?= $i+1 ?></span></td>
                      <td>
                        <strong><?= h($p['product_name']) ?></strong>
                        <div class="mini-bar"><span style="width:<?= ($maxSales>0?min(100,(float)$p['sales']*100/$maxSales):0) ?>%"></span></div>
                      </td>
                      <td class="text-end"><?= number_format((int)$p['orders']) ?></td>
                      <td class="text-end"><?= money($p['qty']) ?></td>
                      <td class="text-end fw-bold">৳<?= money($p['sales']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                  <h5 class="mb-1 fw-bold">Top Online Customers</h5>
                  <div class="section-note">Ranked by non-cancelled online order value.</div>
                </div>
                <a class="btn btn-sm btn-light no-print" href="<?= h(reportUrl(['export'=>'customers'])) ?>"><i class="bi bi-download"></i> Export</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr><th>Customer</th><th class="text-end">Orders</th><th class="text-end">Value</th><th class="text-end">Due</th></tr>
                  </thead>
                  <tbody>
                  <?php if(!$topCustomers): ?>
                    <tr><td colspan="4" class="empty-state">No online customers in this filter.</td></tr>
                  <?php else: foreach($topCustomers as $c): ?>
                    <tr>
                      <td>
                        <strong><?= h($c['customer_name']) ?></strong>
                        <div class="small text-muted"><?= h($c['phone']?:'No phone') ?> · Last: <?= h($c['last_order']?date('d M Y',strtotime($c['last_order'])):'-') ?></div>
                      </td>
                      <td class="text-end"><?= number_format((int)$c['orders']) ?></td>
                      <td class="text-end fw-bold">৳<?= money($c['value']) ?></td>
                      <td class="text-end <?= $c['due']>0?'text-danger':'' ?>">৳<?= money($c['due']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent orders -->
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <i class="bi bi-clock-history me-1"></i> <strong>Recent Online Orders</strong>
            <div class="section-note">Latest 15 orders matching the active report filter.</div>
          </div>
          <div class="no-print d-flex gap-2">
            <a class="btn btn-sm btn-light" href="<?= h(reportUrl(['export'=>'orders'])) ?>"><i class="bi bi-download"></i> CSV</a>
            <a class="btn btn-sm btn-primary" href="order_management.php?status=all&source=online"><i class="bi bi-list-ul"></i> Manage Orders</a>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0 report-table">
              <thead>
                <tr>
                  <th>Invoice</th><th>Customer</th><th>Items</th>
                  <th class="text-end">Amount</th><th class="text-end">Paid</th><th class="text-end">Due</th>
                  <th>Payment</th><th>Status</th><th>Date</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$recent): ?>
                <tr><td colspan="9" class="empty-state">No online orders found for this report filter.</td></tr>
              <?php else: foreach($recent as $o): ?>
                <tr>
                  <td>
                    <a class="table-link" href="order_management.php?status=all&source=online&search=<?= urlencode($o['invoice_no']) ?>"><?= h($o['invoice_no']) ?></a>
                    <div class="small text-muted">ID: <?= intval($o['id']) ?></div>
                  </td>
                  <td>
                    <strong><?= h($o['customer_name']) ?></strong>
                    <div class="small text-muted"><?= h($o['phone']?:'-') ?></div>
                  </td>
                  <td><?= money($o['item_qty']) ?></td>
                  <td class="text-end fw-bold">৳<?= money($o['total_amount']) ?></td>
                  <td class="text-end text-success">৳<?= money($o['paid_amount']) ?></td>
                  <td class="text-end <?= $o['due_amount']>0?'text-danger':'' ?>">৳<?= money($o['due_amount']) ?></td>
                  <td>
                    <span class="badge bg-<?= h(statusClass($o['payment_status'])) ?>"><?= h(statusLabel($o['payment_status'])) ?></span>
                    <div class="small text-muted mt-1"><?= h(strtoupper((string)$o['payment_method'])) ?></div>
                  </td>
                  <td><span class="badge bg-<?= h(statusClass($o['order_status'])) ?>"><?= h(statusLabel($o['order_status'])) ?></span></td>
                  <td>
                    <?= h(date('d M Y',strtotime($o['created_at']))) ?>
                    <div class="small text-muted"><?= h(date('h:i A',strtotime($o['created_at']))) ?></div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Management notes -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-info-circle me-1"></i> <strong>Management Notes</strong></div>
        <div class="card-body">
          <div class="row g-2 small">
            <div class="col-md-6"><strong class="text-dark">Gross Order Value:</strong> <span class="text-muted">excludes cancelled orders, so it is a cleaner view of active/completed demand.</span></div>
            <div class="col-md-6"><strong class="text-dark">Completed Revenue:</strong> <span class="text-muted">includes only orders currently marked Completed.</span></div>
            <div class="col-md-6"><strong class="text-dark">Collected:</strong> <span class="text-muted">uses the stored paid_amount of non-cancelled online orders.</span></div>
            <div class="col-md-6"><strong class="text-dark">POS isolation:</strong> <span class="text-muted">every order query is explicitly restricted to order_source = online.</span></div>
          </div>
        </div>
      </div>

    </main>

    <footer class="rv-footer text-center text-muted py-3 small border-top bg-white">
      © <?php echo date('Y'); ?> Rice Valy — <a href="https://digitalsoftwarebd.com" target="_blank" class="text-decoration-none">Designed by DSB</a>
    </footer>
  </div>
</div>

<form id="logout-form" action="/admin/logout" method="POST" style="display:none;">
  <input type="hidden" name="_token" value="Tzrj0tXolfHXDKSJ8dFPoXmwrjtVbwt6bkEtEaYb">
</form>

<!-- ============================================================ -->
<!-- SCRIPTS -->
<!-- ============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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
(function(){
  if(typeof Chart==='undefined') return;

  Chart.defaults.font.family = 'Inter, "Noto Sans Bengali", Arial, sans-serif';
  Chart.defaults.color = '#697386';

  const trendLabels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
  const trendOrders = <?= json_encode($trendOrders) ?>;
  const trendValue = <?= json_encode($trendValue) ?>;
  const trendCollected = <?= json_encode($trendCollected) ?>;

  new Chart(document.getElementById('trendChart'), {
    type:'bar',
    data:{
      labels: trendLabels,
      datasets:[
        { type:'line', label:'Orders', data:trendOrders, yAxisID:'y1',
          borderColor:'#0a5b4f', backgroundColor:'#0a5b4f', tension:.3, borderWidth:2, pointRadius:2 },
        { label:'Order Value', data:trendValue,
          backgroundColor:'rgba(10,91,79,.22)', borderColor:'#0a5b4f', borderWidth:1, borderRadius:4 },
        { label:'Collected', data:trendCollected,
          backgroundColor:'rgba(244,135,33,.28)', borderColor:'#f48721', borderWidth:1, borderRadius:4 }
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      interaction:{mode:'index', intersect:false},
      plugins:{ legend:{ position:'bottom', labels:{boxWidth:10, usePointStyle:true} } },
      scales:{
        y:{ beginAtZero:true, ticks:{ callback:v=>'৳'+Number(v).toLocaleString() } },
        y1:{ position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, ticks:{ precision:0 } }
      }
    }
  });

  const doughnutOptions = {
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ position:'bottom', labels:{boxWidth:10, usePointStyle:true} } },
    cutout:'66%'
  };

  new Chart(document.getElementById('statusChart'), {
    type:'doughnut',
    data:{
      labels: <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets:[{ data: <?= json_encode($statusCounts) ?>,
        backgroundColor:['#f48721','#3b82f6','#0a5b4f','#14b8a6','#22c55e','#ef4444','#64748b'] }]
    },
    options: doughnutOptions
  });

  new Chart(document.getElementById('paymentChart'), {
    type:'doughnut',
    data:{
      labels: <?= json_encode($paymentLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets:[{ data: <?= json_encode($paymentCounts) ?>,
        backgroundColor:['#f48721','#22c55e','#ef4444','#64748b'] }]
    },
    options: doughnutOptions
  });
})();
</script>
</body>
</html>