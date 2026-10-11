<?php
require_once __DIR__ . '/site_admin_guard.php';
requireSiteAdmin(1);

error_reporting(E_ALL); ini_set('display_errors',0); ini_set('display_startup_errors',0); mysqli_report(MYSQLI_REPORT_OFF);
$dbCandidates=[__DIR__.'/../../config/db.php',__DIR__.'/../config/db.php',rtrim($_SERVER['DOCUMENT_ROOT']??'','/').'/config/db.php'];$dbLoaded=false;foreach($dbCandidates as $f){if($f&&is_file($f)){require_once $f;$dbLoaded=true;break;}}if(!$dbLoaded||!isset($conn)||!($conn instanceof mysqli)){http_response_code(500);die('Database connection could not be loaded. Expected /config/db.php');}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function money($v){return number_format((float)$v,2);}function jsonResponse($d,$c=200){http_response_code($c);header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}function phoneExpr($a){return "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$a}.phone,''),'+',''),' ',''),'-',''),'(',''),')',''),10)";}$siteShopId=tenantShopId();
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['action'])){$action=(string)$_POST['action'];if($action==='get_customer_details'){$id=(int)($_POST['id']??0);if($id<=0)jsonResponse(['success'=>false,'message'=>'Invalid customer ID.'],400);$res=$conn->query("SELECT c.* FROM customers c WHERE c.id={$id} AND c.shop_id={$siteShopId} AND EXISTS(SELECT 1 FROM orders o WHERE o.customer_id=c.id AND o.order_source='online' AND o.shop_id={$siteShopId}) LIMIT 1");if(!$res)jsonResponse(['success'=>false,'message'=>'Could not load customer: '.$conn->error],500);$customer=$res->fetch_assoc();$res->free();if(!$customer)jsonResponse(['success'=>false,'message'=>'Online customer not found.'],404);$cPhone=phoneExpr('c');$uPhone=phoneExpr('u');$email=$conn->real_escape_string((string)($customer['email']??''));$phone=$conn->real_escape_string((string)($customer['phone']??''));$account=null;$q="SELECT u.id,u.name,u.email,u.phone,u.status,u.created_at FROM users u WHERE u.role='customer' AND u.shop_id={$siteShopId} AND ((".($email!==''?"LOWER(u.email)=LOWER('{$email}')":"0").") OR (".($phone!==''?"{$uPhone}=RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE('{$phone}','+',''),' ',''),'-',''),'(',''),')',''),10)":"0").")) ORDER BY u.id DESC LIMIT 1";$ur=$conn->query($q);if($ur){$account=$ur->fetch_assoc()?:null;$ur->free();}$orders=[];$or=$conn->query("SELECT o.id,o.invoice_no,o.total_amount,o.paid_amount,o.due_amount,o.payment_method,o.payment_status,o.order_status,o.created_at,osa.recipient_name,osa.phone AS shipping_phone,osa.address AS shipping_address,osa.city FROM orders o LEFT JOIN order_shipping_addresses osa ON osa.order_id=o.id WHERE o.customer_id={$id} AND o.order_source='online' AND o.shop_id={$siteShopId} ORDER BY o.id DESC LIMIT 100");if(!$or)jsonResponse(['success'=>false,'message'=>'Could not load online order history: '.$conn->error],500);while($r=$or->fetch_assoc())$orders[]=$r;$or->free();jsonResponse(['success'=>true,'customer'=>$customer,'account'=>$account,'orders'=>$orders]);}jsonResponse(['success'=>false,'message'=>'Unknown action.'],400);}
$search=trim((string)($_GET['search']??''));$page=max(1,(int)($_GET['page']??1));$perPage=25;$offset=($page-1)*$perPage;$where=["o.order_source='online'","o.shop_id={$siteShopId}","c.shop_id={$siteShopId}"];if($search!==''){$s=$conn->real_escape_string($search);$where[]="(c.name LIKE '%{$s}%' OR c.shop_name LIKE '%{$s}%' OR c.phone LIKE '%{$s}%' OR c.email LIKE '%{$s}%' OR c.address LIKE '%{$s}%')";}$whereSql=implode(' AND ',$where);
$summary=['customers'=>0,'orders'=>0,'value'=>0,'due'=>0];$res=$conn->query("SELECT COUNT(DISTINCT o.customer_id) customers,COUNT(*) orders,COALESCE(SUM(o.total_amount),0) value,COALESCE(SUM(CASE WHEN o.payment_status<>'paid' THEN o.due_amount ELSE 0 END),0) due FROM orders o WHERE o.order_source='online' AND o.shop_id={$siteShopId} AND o.customer_id IS NOT NULL AND o.customer_id>0");if($res){$summary=array_merge($summary,$res->fetch_assoc()?:[]);$res->free();}
$countRes=$conn->query("SELECT COUNT(DISTINCT c.id) cnt FROM customers c INNER JOIN orders o ON o.customer_id=c.id WHERE {$whereSql}");$totalFiltered=0;if($countRes){$r=$countRes->fetch_assoc();$totalFiltered=(int)($r['cnt']??0);$countRes->free();}$totalPages=max(1,(int)ceil($totalFiltered/$perPage));if($page>$totalPages){$page=$totalPages;$offset=($page-1)*$perPage;}
$sql="SELECT c.id,c.name,c.shop_name,c.phone,c.email,c.address,c.customer_group,c.created_at,COUNT(o.id) total_orders,COALESCE(SUM(o.total_amount),0) total_value,COALESCE(SUM(o.paid_amount),0) total_paid,COALESCE(SUM(CASE WHEN o.payment_status<>'paid' THEN o.due_amount ELSE 0 END),0) total_due,MAX(o.created_at) last_order FROM customers c INNER JOIN orders o ON o.customer_id=c.id WHERE {$whereSql} GROUP BY c.id,c.name,c.shop_name,c.phone,c.email,c.address,c.customer_group,c.created_at ORDER BY last_order DESC LIMIT {$perPage} OFFSET {$offset}";$customers=[];$loadError='';$res=$conn->query($sql);if(!$res)$loadError=$conn->error;else{while($r=$res->fetch_assoc())$customers[]=$r;$res->free();}function pageUrl($p){$q=$_GET;$q['page']=$p;return 'online_customers.php?'.http_build_query($q);}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Online Customers — Rice Valy Admin</title>
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
.detail-box{border:1px solid var(--rv-border);border-radius:10px;padding:14px;margin-bottom:14px;background:#fff}
.detail-label{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--rv-muted);margin-bottom:3px}
.detail-value{font-weight:600;color:#1e293b;word-break:break-word}
.customer-table td,.customer-table th{vertical-align:middle}
.order-history td,.order-history th{vertical-align:middle}
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
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
  .customer-table{min-width:1050px}
  .order-history{min-width:850px}
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
            <li><a href="registered_users.php"><i class="bi bi-person-lines-fill"></i> Registered Users</a></li>

            <li><a href="online_customers.php" class="active"><i class="bi bi-wifi"></i> Online Customers</a></li>
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
      <h5>Online Customers</h5>
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
          <h4>Online Customers</h4>
          <p>Only customers who have placed at least one website/online order. POS customers are completely excluded.</p>
        </div>
        <div class="rv-toolbar">
          <a href="registered_users.php" class="btn btn-light btn-sm"><i class="bi bi-person-badge"></i> Registered Users</a>
        </div>
      </div>

      <?php if($loadError):?>
        <div class="alert alert-danger"><strong>Database error:</strong> <?= h($loadError) ?></div>
      <?php endif; ?>

      <!-- Summary cards -->
      <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Online Customers</div><h3 class="stat-value"><?= (int)$summary['customers'] ?></h3></div>
            <div class="stat-icon"><i class="bi bi-people"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Online Orders</div><h3 class="stat-value"><?= (int)$summary['orders'] ?></h3></div>
            <div class="stat-icon"><i class="bi bi-cart3"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Order Value</div><h3 class="stat-value">৳<?= money($summary['value']) ?></h3></div>
            <div class="stat-icon"><i class="bi bi-credit-card"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div><div class="stat-label">Outstanding Due</div><h3 class="stat-value">৳<?= money($summary['due']) ?></h3></div>
            <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
          </div></div>
        </div>
      </div>

      <!-- Search -->
      <div class="card mb-3">
        <div class="card-body">
          <form method="get" class="row g-2">
            <div class="col-md-9">
              <input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search customer name, phone, email or address">
            </div>
            <div class="col-md-3 d-flex gap-2">
              <button class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i> Search</button>
              <a class="btn btn-light" href="online_customers.php">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Customer table -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0">Customers</h5>
            <small class="text-muted"><?= $totalFiltered ?> result(s)</small>
          </div>
          <span class="badge bg-primary">Page <?= $page ?> / <?= $totalPages ?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0 customer-table">
              <thead>
                <tr>
                  <th style="width:60px">#</th>
                  <th>Customer</th>
                  <th>Contact</th>
                  <th>Address</th>
                  <th>Orders</th>
                  <th>Order Value</th>
                  <th>Paid</th>
                  <th>Due</th>
                  <th>Last Purchase</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$customers): ?>
                <tr><td colspan="10"><div class="empty-state"><i class="bi bi-person-x" style="font-size:36px;display:block;margin-bottom:8px;"></i>No online customers found.</div></td></tr>
              <?php else: foreach($customers as $i=>$c): ?>
                <tr>
                  <td><?= $offset+$i+1 ?></td>
                  <td>
                    <strong><?= h($c['name']?:$c['shop_name']?:'Customer #'.$c['id']) ?></strong>
                    <div class="small text-muted">ID: <?= (int)$c['id'] ?></div>
                  </td>
                  <td>
                    <?= h($c['phone']?:'-') ?><br>
                    <small class="text-muted"><?= h($c['email']?:'No email') ?></small>
                  </td>
                  <td style="max-width:220px"><?= h($c['address']?:'-') ?></td>
                  <td><strong><?= (int)$c['total_orders'] ?></strong></td>
                  <td>৳<?= money($c['total_value']) ?></td>
                  <td class="text-success">৳<?= money($c['total_paid']) ?></td>
                  <td class="text-danger">৳<?= money($c['total_due']) ?></td>
                  <td>
                    <?= $c['last_order']?date('d M Y',strtotime($c['last_order'])):'-' ?><br>
                    <small class="text-muted"><?= $c['last_order']?date('h:i A',strtotime($c['last_order'])):'' ?></small>
                  </td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-primary view-customer" data-id="<?= (int)$c['id'] ?>"><i class="bi bi-eye"></i> View</button>
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
<!-- CUSTOMER DETAILS MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i> Online Customer Details</h5>
          <small class="text-muted">Website orders only</small>
        </div>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="cLoading" class="text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
        </div>
        <div id="cContent" class="d-none">
          <div class="detail-box">
            <div class="row g-3">
              <div class="col-md-4"><div class="detail-label">Customer</div><div class="detail-value" id="cName"></div></div>
              <div class="col-md-4"><div class="detail-label">Phone</div><div class="detail-value" id="cPhone"></div></div>
              <div class="col-md-4"><div class="detail-label">Email</div><div class="detail-value" id="cEmail"></div></div>
              <div class="col-md-8"><div class="detail-label">Address</div><div class="detail-value" id="cAddress"></div></div>
              <div class="col-md-4"><div class="detail-label">Registered Account</div><div class="detail-value" id="cAccount"></div></div>
            </div>
          </div>
          <div class="detail-box">
            <h5 class="mb-3">Online Order History</h5>
            <div class="table-responsive">
              <table class="table table-sm order-history mb-0">
                <thead class="table-light">
                  <tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Payment</th><th>Status</th><th>Delivery Address</th></tr>
                </thead>
                <tbody id="cOrders"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
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
  function m(v){ return '৳' + Number(v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }

  $(document).on('click', '.view-customer', function(){
    const id = $(this).data('id');
    $('#cLoading').removeClass('d-none');
    $('#cContent').addClass('d-none');
    new bootstrap.Modal(document.getElementById('customerModal')).show();

    $.post('online_customers.php', {action:'get_customer_details', id:id}, function(r){
      if(!r.success){ toastr.error(r.message || 'Could not load customer'); return; }
      const c = r.customer;
      $('#cName').text(c.name || c.shop_name || '-');
      $('#cPhone').text(c.phone || '-');
      $('#cEmail').text(c.email || '-');
      $('#cAddress').text(c.address || '-');
      $('#cAccount').html(r.account
        ? '<span class="badge bg-success">Yes</span> ' + esc(r.account.email || r.account.phone || '')
        : '<span class="badge bg-secondary">No signup account found</span>'
      );

      let rows = '';
      (r.orders || []).forEach(function(o){
        const ps = o.payment_status === 'paid' ? 'success' : 'warning';
        const os = o.order_status === 'completed' ? 'success' : (o.order_status === 'cancelled' ? 'danger' : 'info');
        const addr = [o.shipping_address, o.city].filter(Boolean).join(', ');
        rows += '<tr>' +
          '<td><strong>' + esc(o.invoice_no) + '</strong></td>' +
          '<td>' + esc(o.created_at) + '</td>' +
          '<td>' + m(o.total_amount) + '</td>' +
          '<td>' + m(o.paid_amount) + '</td>' +
          '<td>' + m(o.due_amount) + '</td>' +
          '<td><span class="badge bg-' + ps + '">' + esc(o.payment_status) + '</span></td>' +
          '<td><span class="badge bg-' + os + '">' + esc(o.order_status) + '</span></td>' +
          '<td>' + esc(addr || '-') + '</td>' +
        '</tr>';
      });
      $('#cOrders').html(rows || '<tr><td colspan="8" class="text-center text-muted py-3">No online orders.</td></tr>');

      $('#cLoading').addClass('d-none');
      $('#cContent').removeClass('d-none');
    }, 'json').fail(function(){
      toastr.error('Server error while loading customer');
    });
  });
});
</script>
</body>
</html>