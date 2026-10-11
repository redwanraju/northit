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
    if ($dbFile && is_file($dbFile)) { require_once $dbFile; $dbLoaded = true; break; }
}
if (!$dbLoaded || !isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection could not be loaded. Expected /config/db.php');
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v){ return number_format((float)$v, 2); }
function initialChar($v){ $v=trim((string)$v); if($v==='') return 'U'; if(preg_match('/^./u',$v,$m)) return $m[0]; return strtoupper(substr($v,0,1)); }
function jsonResponse($data,$code=200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function phoneExpr($alias){ return "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$alias}.phone,''),'+',''),' ',''),'-',''),'(',''),')',''),10)"; }
$siteShopId=tenantShopId();

$uPhone = phoneExpr('u');
$cPhone = phoneExpr('c');
$match = "(o.shop_id={$siteShopId} AND (
    o.account_user_id=u.id
    OR (
        o.account_user_id IS NULL
        AND c.shop_id={$siteShopId}
        AND (
            (COALESCE(u.email,'') <> '' AND COALESCE(c.email,'') <> '' AND LOWER(c.email)=LOWER(u.email))
            OR
            (COALESCE(u.phone,'') <> '' AND COALESCE(c.phone,'') <> '' AND {$cPhone}={$uPhone})
        )
    )
))";

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    if ($action === 'get_user_details') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) jsonResponse(['success'=>false,'message'=>'Invalid user ID.'],400);
        $sql = "SELECT u.id,u.shop_id,u.name,u.email,u.phone,u.role,u.status,u.image,u.address,u.created_at,u.updated_at,u.referral_code,u.referred_by_user_id,
                       COALESCE((SELECT w.balance FROM user_credit_wallets w WHERE w.shop_id=u.shop_id AND w.user_id=u.id LIMIT 1),0) AS credit_balance,
                       COALESCE((SELECT w.lifetime_earned FROM user_credit_wallets w WHERE w.shop_id=u.shop_id AND w.user_id=u.id LIMIT 1),0) AS credit_earned,
                       (SELECT COUNT(*) FROM referrals r WHERE r.shop_id=u.shop_id AND r.referrer_user_id=u.id AND r.status IN ('pending','qualified','rewarded')) AS direct_referrals,
                       (SELECT COUNT(DISTINCT o.id) FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match}) AS online_orders,
                       (SELECT COALESCE(SUM(o.total_amount),0) FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match}) AS online_spent,
                       (SELECT MAX(o.created_at) FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match}) AS last_order
                FROM users u WHERE u.id={$id} AND u.role='customer' AND u.shop_id={$siteShopId} LIMIT 1";
        $res=$conn->query($sql);
        if(!$res) jsonResponse(['success'=>false,'message'=>'Could not load user: '.$conn->error],500);
        $user=$res->fetch_assoc(); $res->free();
        if(!$user) jsonResponse(['success'=>false,'message'=>'Registered user not found.'],404);
        jsonResponse(['success'=>true,'user'=>$user]);
    }
    if ($action === 'toggle_user_status') {
        siteAdminRequireCsrf();
        $id=(int)($_POST['id'] ?? 0); $status=(int)($_POST['status'] ?? -1);
        if($id<=0 || !in_array($status,[0,1],true)) jsonResponse(['success'=>false,'message'=>'Invalid request.'],400);
        $stmt=$conn->prepare("UPDATE users SET status=? WHERE id=? AND role='customer' AND shop_id=?");
        if(!$stmt) jsonResponse(['success'=>false,'message'=>'Could not prepare status update: '.$conn->error],500);
        $stmt->bind_param('iii',$status,$id,$siteShopId);
        if(!$stmt->execute()){ $msg=$stmt->error; $stmt->close(); jsonResponse(['success'=>false,'message'=>'Could not update status: '.$msg],500); }
        $affected=$stmt->affected_rows; $stmt->close();
        if($affected<1) jsonResponse(['success'=>false,'message'=>'No registered user was updated.'],404);
        jsonResponse(['success'=>true,'message'=>$status?'User activated successfully.':'User deactivated successfully.']);
    }
    jsonResponse(['success'=>false,'message'=>'Unknown action.'],400);
}

$search=trim((string)($_GET['search'] ?? ''));
$status=(string)($_GET['status'] ?? 'all');
if(!in_array($status,['all','active','inactive'],true)) $status='all';
$page=max(1,(int)($_GET['page'] ?? 1)); $perPage=25; $offset=($page-1)*$perPage;
$where=["u.role='customer'","u.shop_id={$siteShopId}"];
if($status==='active') $where[]='u.status=1';
if($status==='inactive') $where[]='u.status=0';
if($search!==''){
    $s=$conn->real_escape_string($search);
    $where[]="(u.name LIKE '%{$s}%' OR u.email LIKE '%{$s}%' OR u.phone LIKE '%{$s}%')";
}
$whereSql=implode(' AND ',$where);

$summary=['total'=>0,'active'=>0,'inactive'=>0,'buyers'=>0];
$res=$conn->query("SELECT COUNT(*) total, SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) active, SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) inactive FROM users WHERE role='customer' AND shop_id={$siteShopId}");
if($res){$summary=array_merge($summary,$res->fetch_assoc()?:[]);$res->free();}
$res=$conn->query("SELECT COUNT(*) buyers FROM users u WHERE u.role='customer' AND u.shop_id={$siteShopId} AND EXISTS (SELECT 1 FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match})");
if($res){$r=$res->fetch_assoc();$summary['buyers']=(int)($r['buyers']??0);$res->free();}

$countRes=$conn->query("SELECT COUNT(*) cnt FROM users u WHERE {$whereSql}");
$totalFiltered=0; if($countRes){$r=$countRes->fetch_assoc();$totalFiltered=(int)($r['cnt']??0);$countRes->free();}
$totalPages=max(1,(int)ceil($totalFiltered/$perPage)); if($page>$totalPages){$page=$totalPages;$offset=($page-1)*$perPage;}

$listSql="SELECT u.id,u.name,u.email,u.phone,u.status,u.image,u.address,u.created_at,u.referral_code,u.referred_by_user_id,
                 COALESCE((SELECT w.balance FROM user_credit_wallets w WHERE w.shop_id=u.shop_id AND w.user_id=u.id LIMIT 1),0) AS credit_balance,
                 COALESCE((SELECT w.lifetime_earned FROM user_credit_wallets w WHERE w.shop_id=u.shop_id AND w.user_id=u.id LIMIT 1),0) AS credit_earned,
                 (SELECT COUNT(*) FROM referrals r WHERE r.shop_id=u.shop_id AND r.referrer_user_id=u.id AND r.status IN ('pending','qualified','rewarded')) AS direct_referrals,
                 (SELECT COUNT(DISTINCT o.id) FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match}) AS online_orders,
                 (SELECT COALESCE(SUM(o.total_amount),0) FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match}) AS online_spent,
                 (SELECT MAX(o.created_at) FROM customers c INNER JOIN orders o ON o.customer_id=c.id AND o.order_source='online' WHERE {$match}) AS last_order
          FROM users u WHERE {$whereSql} ORDER BY u.id DESC LIMIT {$perPage} OFFSET {$offset}";
$users=[];$loadError='';
$res=$conn->query($listSql);
if(!$res){$loadError=$conn->error;} else {while($r=$res->fetch_assoc())$users[]=$r;$res->free();}
function pageUrl($p){$q=$_GET;$q['page']=$p;return 'registered_users.php?'.http_build_query($q);}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Registered Users — Rice Valy Admin</title>
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
.avatar-user{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#eef2f7;border:2px solid var(--rv-border)}
.initial-avatar{
  width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  background:#e6f4f1;font-weight:700;color:var(--rv-primary);font-size:15px;flex-shrink:0;
}
.user-table td,.user-table th{vertical-align:middle}
.detail-label{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--rv-muted);margin-bottom:3px}
.detail-value{font-weight:600;color:#1e293b;word-break:break-word}
.empty-state{padding:50px 20px;text-align:center;color:var(--rv-muted)}
.pagination .page-link{min-width:38px;text-align:center;border-radius:6px}
.modal-content{border-radius:14px;border:0;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-header{border-bottom:1px solid var(--rv-border);padding:14px 20px}
.modal-title{font-weight:700;font-size:16px;color:var(--rv-primary)}
.modal-footer{border-top:1px solid var(--rv-border);padding:12px 20px}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px}
.form-control,.form-select{border-radius:8px;border-color:#d1d5db;font-size:14px}
.form-control:focus,.form-select:focus{border-color:var(--rv-primary);box-shadow:0 0 0 .2rem rgba(10,91,79,.12)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
code{color:var(--rv-primary);background:#f0fdfa;padding:2px 6px;border-radius:4px;font-size:12px}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
  .user-table{min-width:1100px}
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
        <a data-bs-toggle="collapse" href="#navUsers" role="button" aria-expanded="true" class="active">
          <i class="bi bi-people"></i> Users <i class="bi bi-chevron-down ms-auto" style="font-size:12px"></i>
        </a>
        <div class="collapse show sub" id="navUsers">
          <ul class="rv-nav">
            <li><a href="registered_users.php" class="active"><i class="bi bi-person-lines-fill"></i> Registered Users</a></li>
          
            <li><a href="online_customers.php"><i class="bi bi-wifi"></i> Online Customers</a></li>
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
      <h5>Registered Users</h5>
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

      <div class="rv-page-title">
        <div>
          <h4>Registered Users</h4>
          <p>Website signup accounts only. Admin, manager and SR accounts are excluded.</p>
        </div>
        <div class="rv-toolbar">
          <a href="referral_management.php" class="btn btn-warning btn-sm"><i class="bi bi-gift"></i> Credit &amp; Referral</a>
          <a href="online_customers.php" class="btn btn-primary btn-sm"><i class="bi bi-people"></i> Online Customers</a>
        </div>
      </div>

      <?php if($loadError): ?>
        <div class="alert alert-danger"><strong>Database error:</strong> <?= h($loadError) ?></div>
      <?php endif; ?>

      <!-- Summary cards -->
      <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Registered Users</div><h3 class="stat-value"><?= (int)$summary['total'] ?></h3></div>
            <div class="stat-icon"><i class="bi bi-person-plus"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Active</div><h3 class="stat-value"><?= (int)$summary['active'] ?></h3></div>
            <div class="stat-icon"><i class="bi bi-person-check"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Inactive</div><h3 class="stat-value"><?= (int)$summary['inactive'] ?></h3></div>
            <div class="stat-icon"><i class="bi bi-person-x"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Online Buyers</div><h3 class="stat-value"><?= (int)$summary['buyers'] ?></h3></div>
            <div class="stat-icon"><i class="bi bi-bag-check"></i></div>
          </div></div>
        </div>
      </div>

      <!-- Filter -->
      <div class="card mb-3">
        <div class="card-body">
          <form method="get" class="row g-2">
            <div class="col-md-6">
              <input name="search" value="<?= h($search) ?>" class="form-control" placeholder="Search name, email or phone">
            </div>
            <div class="col-md-3">
              <select name="status" class="form-select">
                <option value="all" <?= $status==='all'?'selected':'' ?>>All Status</option>
                <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
              </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
              <button class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i> Filter</button>
              <a href="registered_users.php" class="btn btn-light">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Users table -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0">Registered Users</h5>
            <small class="text-muted"><?= $totalFiltered ?> result(s)</small>
          </div>
          <span class="badge bg-primary">Page <?= $page ?> / <?= $totalPages ?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0 user-table">
              <thead>
                <tr>
                  <th style="width:60px">#</th>
                  <th>User</th>
                  <th>Contact</th>
                  <th>Referral Code</th>
                  <th>Credit Balance</th>
                  <th>Direct Referrals</th>
                  <th>Registered</th>
                  <th>Online Orders</th>
                  <th>Online Spend</th>
                  <th>Last Order</th>
                  <th>Status</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$users): ?>
                <tr><td colspan="12"><div class="empty-state"><i class="bi bi-people" style="font-size:36px;display:block;margin-bottom:8px;"></i>No registered users found.</div></td></tr>
              <?php else: foreach($users as $i=>$u): ?>
                <tr>
                  <td><?= $offset+$i+1 ?></td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <div class="initial-avatar"><?= h(initialChar($u['name']?:'U')) ?></div>
                      <div>
                        <strong><?= h($u['name']) ?></strong>
                        <div class="small text-muted">ID: <?= (int)$u['id'] ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div><?= h($u['email']) ?></div>
                    <small class="text-muted"><?= h($u['phone'] ?: 'No phone') ?></small>
                  </td>
                  <td><code><?= h($u['referral_code'] ?: '—') ?></code></td>
                  <td>
                    <strong>৳<?= money($u['credit_balance']) ?></strong><br>
                    <small class="text-muted">Earned ৳<?= money($u['credit_earned']) ?></small>
                  </td>
                  <td><strong><?= (int)$u['direct_referrals'] ?></strong></td>
                  <td>
                    <?= $u['created_at']?date('d M Y',strtotime($u['created_at'])):'-' ?><br>
                    <small class="text-muted"><?= $u['created_at']?date('h:i A',strtotime($u['created_at'])):'' ?></small>
                  </td>
                  <td><strong><?= (int)$u['online_orders'] ?></strong></td>
                  <td>৳<?= money($u['online_spent']) ?></td>
                  <td><?= $u['last_order']?date('d M Y',strtotime($u['last_order'])):'Never' ?></td>
                  <td>
                    <span class="badge bg-<?= $u['status']?'success':'secondary' ?> badge-status">
                      <?= $u['status']?'Active':'Inactive' ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-primary view-user" data-id="<?= (int)$u['id'] ?>"><i class="bi bi-eye"></i> View</button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php if($totalPages>1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
          <small class="text-muted">Showing <?= $totalFiltered?$offset+1:0 ?>–<?= min($offset+$perPage,$totalFiltered) ?> of <?= $totalFiltered ?></small>
          <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= h(pageUrl(max(1,$page-1))) ?>">&laquo;</a></li>
            <?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?>
              <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="<?= h(pageUrl($p)) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= h(pageUrl(min($totalPages,$page+1))) ?>">&raquo;</a></li>
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
<!-- USER DETAILS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i> Registered User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="userLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
        </div>
        <div id="userContent" class="d-none">
          <input type="hidden" id="uId">
          <div class="row g-3">
            <div class="col-md-6"><div class="detail-label">Name</div><div class="detail-value" id="uName"></div></div>
            <div class="col-md-6"><div class="detail-label">Status</div><div class="detail-value" id="uStatus"></div></div>
            <div class="col-md-6"><div class="detail-label">Email</div><div class="detail-value" id="uEmail"></div></div>
            <div class="col-md-6"><div class="detail-label">Phone</div><div class="detail-value" id="uPhone"></div></div>
            <div class="col-md-6"><div class="detail-label">Referral Code</div><div class="detail-value" id="uReferralCode"></div></div>
            <div class="col-md-6"><div class="detail-label">Referred By User ID</div><div class="detail-value" id="uReferredBy"></div></div>
            <div class="col-md-4"><div class="detail-label">Credit Balance</div><div class="detail-value" id="uCreditBalance"></div></div>
            <div class="col-md-4"><div class="detail-label">Lifetime Credit</div><div class="detail-value" id="uCreditEarned"></div></div>
            <div class="col-md-4"><div class="detail-label">Direct Referrals</div><div class="detail-value" id="uDirectReferrals"></div></div>
            <div class="col-12"><div class="detail-label">Address</div><div class="detail-value" id="uAddress"></div></div>
            <div class="col-md-4"><div class="detail-label">Registered</div><div class="detail-value" id="uCreated"></div></div>
            <div class="col-md-4"><div class="detail-label">Online Orders</div><div class="detail-value" id="uOrders"></div></div>
            <div class="col-md-4"><div class="detail-label">Online Spend</div><div class="detail-value" id="uSpend"></div></div>
            <div class="col-12">
              <div class="alert alert-light border mb-0 small">
                New online orders use the secure website-account link (<strong>orders.account_user_id</strong>). Older orders without that link use legacy phone/email matching.
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" id="toggleUser" class="btn btn-warning d-none"></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
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

$(function(){
  toastr.options = {
    closeButton: true, progressBar: true, positionClass: "toast-top-right",
    timeOut: 3000, extendedTimeOut: 1000, preventDuplicates: true
  };

  function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
  function fmtMoney(v){ return '৳' + Number(v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }

  let currentStatus = 1;

  $(document).on('click', '.view-user', function(){
    const id = $(this).data('id');
    $('#userLoading').removeClass('d-none');
    $('#userContent').addClass('d-none');
    $('#toggleUser').addClass('d-none');
    new bootstrap.Modal(document.getElementById('userModal')).show();

    $.post('registered_users.php', {action:'get_user_details', id:id}, function(r){
      if(!r.success){ toastr.error(r.message || 'Could not load user'); return; }
      const u = r.user;
      $('#uId').val(u.id);
      $('#uName').text(u.name || '-');
      $('#uEmail').text(u.email || '-');
      $('#uPhone').text(u.phone || '-');
      $('#uReferralCode').text(u.referral_code || '-');
      $('#uReferredBy').text(u.referred_by_user_id || '-');
      $('#uCreditBalance').text(fmtMoney(u.credit_balance));
      $('#uCreditEarned').text(fmtMoney(u.credit_earned));
      $('#uDirectReferrals').text(u.direct_referrals || 0);
      $('#uAddress').text(u.address || '-');
      $('#uCreated').text(u.created_at || '-');
      $('#uOrders').text(u.online_orders || 0);
      $('#uSpend').text(fmtMoney(u.online_spent));

      currentStatus = Number(u.status);
      $('#uStatus').html(currentStatus
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>'
      );
      $('#toggleUser').removeClass('d-none btn-success btn-warning')
        .addClass(currentStatus ? 'btn-warning' : 'btn-success')
        .text(currentStatus ? 'Deactivate User' : 'Activate User');

      $('#userLoading').addClass('d-none');
      $('#userContent').removeClass('d-none');
    }, 'json').fail(function(){
      toastr.error('Server error while loading user');
    });
  });

  $('#toggleUser').on('click', function(){
    const id = $('#uId').val();
    const next = currentStatus ? 0 : 1;
    if(!confirm(next ? 'Activate this registered user?' : 'Deactivate this registered user?')) return;

    $.post('registered_users.php', {action:'toggle_user_status', id:id, status:next}, function(r){
      if(r.success){
        toastr.success(r.message);
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        toastr.error(r.message || 'Update failed');
      }
    }, 'json').fail(function(){
      toastr.error('Server error');
    });
  });
});
</script>
</body>
</html>