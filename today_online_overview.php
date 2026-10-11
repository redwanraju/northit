<?php
require_once __DIR__ . '/site_admin_guard.php';
requireSiteAdmin(1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
mysqli_report(MYSQLI_REPORT_OFF);

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
    http_response_code(500); die('Database connection could not be loaded. Expected /config/db.php');
}
$conn->set_charset('utf8mb4');

$shopId = tenantShopId();
$tz = new DateTimeZone('Asia/Dhaka');
$today = new DateTimeImmutable('now', $tz);
$start = $today->setTime(0,0,0);
$end = $start->modify('+1 day');
$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v){ return '৳' . number_format((float)$v, 2); }

function oneRow(mysqli $db, string $sql, string $types, array $params): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    $refs = [$types];
    foreach ($params as $k => $v) $refs[] = &$params[$k];
    call_user_func_array([$stmt,'bind_param'],$refs);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $meta=$stmt->result_metadata();
    if (!$meta) { $stmt->close(); return []; }
    $fields=$meta->fetch_fields(); $row=[]; $bind=[];
    foreach($fields as $f){ $row[$f->name]=null; $bind[]=&$row[$f->name]; }
    call_user_func_array([$stmt,'bind_result'],$bind);
    $data=$stmt->fetch()?array_map(fn($x)=>$x,$row):[];
    $stmt->close(); return $data;
}

$orders = oneRow($conn,
    "SELECT
        COUNT(*) AS orders,
        COALESCE(SUM(CASE WHEN order_status<>'cancelled' THEN total_amount ELSE 0 END),0) AS sales,
        COALESCE(SUM(order_status='pending'),0) AS pending,
        COALESCE(SUM(order_status='processing'),0) AS processing,
        COALESCE(SUM(order_status='completed'),0) AS completed,
        COALESCE(SUM(order_status='cancelled'),0) AS cancelled
     FROM orders
     WHERE shop_id=? AND order_source='online'
       AND created_at>=? AND created_at<?",
    'iss', [$shopId,$startSql,$endSql]);

$customers = oneRow($conn,
    "SELECT COUNT(*) AS new_customers
     FROM users
     WHERE shop_id=? AND role='customer'
       AND created_at>=? AND created_at<?",
    'iss', [$shopId,$startSql,$endSql]);

$m = [
    'orders'=>(int)($orders['orders']??0),
    'sales'=>(float)($orders['sales']??0),
    'new_customers'=>(int)($customers['new_customers']??0),
    'pending'=>(int)($orders['pending']??0),
    'processing'=>(int)($orders['processing']??0),
    'completed'=>(int)($orders['completed']??0),
    'cancelled'=>(int)($orders['cancelled']??0),
];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Today Online Overview — Rice Valy Admin</title>
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
.btn{border-radius:8px;font-weight:500}
.btn-primary{background:var(--rv-primary);border-color:var(--rv-primary)}
.btn-primary:hover{background:var(--rv-primary-2);border-color:var(--rv-primary-2)}
.stat-card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow);background:#fff;height:100%}
.stat-card .stat-icon{
  width:48px;height:48px;display:flex;align-items:center;justify-content:center;
  border-radius:12px;background:#e6f4f1;color:var(--rv-primary);font-size:22px;flex-shrink:0;
}
.stat-card .stat-label{color:var(--rv-muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.stat-card .stat-value{font-size:26px;font-weight:700;margin:0;line-height:1.15}
.stat-card .stat-sub{font-size:12px;color:#94a3b8;margin-top:4px}
.status-card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);background:#fff;padding:18px 20px;height:100%;box-shadow:var(--rv-shadow)}
.status-card .status-label{font-size:12px;color:var(--rv-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.status-card .status-value{font-size:24px;font-weight:700;line-height:1.2}
.status-card.status-info .status-value{color:#0369a1}
.status-card.status-success .status-value{color:#15803d}
.status-card.status-danger .status-value{color:#b91c1c}
.status-card.status-neutral .status-value{font-size:16px;color:#1f2937}
.hero-strip{
  background:linear-gradient(135deg,#0a5b4f 0%,#0d7a67 100%);
  color:#fff;border-radius:14px;padding:22px 24px;
  box-shadow:0 8px 24px rgba(10,91,79,.2);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
  margin-bottom:20px;
}
.hero-strip h4{margin:0 0 4px;font-weight:700;color:#fff;font-size:20px}
.hero-strip p{margin:0;color:rgba(255,255,255,.85);font-size:13px}
.hero-strip .hero-date{font-size:22px;font-weight:700;color:#fff;letter-spacing:.3px}
.hero-strip .hero-meta{font-size:12px;color:rgba(255,255,255,.75);margin-top:4px}
.hero-strip .rv-refresh{
  display:inline-flex;align-items:center;gap:6px;
  border:1px solid rgba(255,255,255,.4);color:#fff;background:transparent;
  padding:7px 14px;border-radius:9px;text-decoration:none;font-size:13px;font-weight:500;
  transition:.15s;
}
.hero-strip .rv-refresh:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.65)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
  .hero-strip{padding:18px}
  .hero-strip .hero-date{font-size:18px}
  .stat-card .stat-value{font-size:22px}
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
      <li><a href="today_online_overview.php" class="active"><i class="bi bi-activity"></i> Today Online Overview</a></li>
      <li><a href="online_business_report.php"><i class="bi bi-bar-chart"></i> Online Business Report</a></li>

      <li class="rv-section">Site Setting</li>
      <li><a href="https://www.ricevaly.com/admin/shipping-charge/manage"><i class="bi bi-truck"></i> Shipping Charge</a></li>
    </ul>
  </aside>

  <div class="rv-backdrop" id="rvBackdrop"></div>

  <!-- Main -->
  <div class="rv-main">
    <header class="rv-topbar">
      <button class="rv-toggle" id="rvToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
      <h5>Today Online Overview</h5>
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
          <h4>Today Online Overview</h4>
          <p>Live snapshot of today's online customer activity, orders and sales — Bangladesh time (Asia/Dhaka).</p>
        </div>
        <div class="rv-toolbar">
          <a href="today_online_overview.php" class="btn btn-primary btn-sm"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
        </div>
      </div>

      <!-- Hero date strip -->
      <div class="hero-strip">
        <div>
          <h4>Today's Snapshot</h4>
          <p>Online orders only · POS excluded</p>
        </div>
        <div class="text-md-end">
          <div class="hero-date"><?= h($start->format('d M Y')) ?></div>
          <div class="hero-meta">Day starts <?= h($start->format('h:i A')) ?> · Next reset <?= h($end->format('d M Y, h:i A')) ?></div>
        </div>
      </div>

      <!-- Primary KPI cards -->
      <div class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Today's Online Orders</div>
              <h3 class="stat-value"><?= number_format($m['orders']) ?></h3>
              <div class="stat-sub">Created today</div>
            </div>
            <div class="stat-icon"><i class="bi bi-cart3"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Today's Online Sales</div>
              <h3 class="stat-value"><?= h(money($m['sales'])) ?></h3>
              <div class="stat-sub">Total order amount</div>
            </div>
            <div class="stat-icon"><i class="bi bi-cash-coin"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">New Registered Customers</div>
              <h3 class="stat-value"><?= number_format($m['new_customers']) ?></h3>
              <div class="stat-sub">Customer accounts created today</div>
            </div>
            <div class="stat-icon"><i class="bi bi-person-plus"></i></div>
          </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
          <div class="card stat-card"><div class="card-body d-flex align-items-center justify-content-between">
            <div>
              <div class="stat-label">Pending Orders</div>
              <h3 class="stat-value text-warning"><?= number_format($m['pending']) ?></h3>
              <div class="stat-sub">Awaiting processing</div>
            </div>
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
          </div></div>
        </div>
      </div>

      <!-- Status breakdown -->
      <div class="row g-3 mb-3">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <i class="bi bi-pie-chart me-1"></i> <strong>Order Status Breakdown</strong>
              <span class="text-muted small ms-2">Today's online orders only</span>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-lg-3 col-md-6">
                  <div class="status-card status-info">
                    <div class="status-label">Processing</div>
                    <div class="status-value"><?= number_format($m['processing']) ?></div>
                  </div>
                </div>
                <div class="col-lg-3 col-md-6">
                  <div class="status-card status-success">
                    <div class="status-label">Completed</div>
                    <div class="status-value"><?= number_format($m['completed']) ?></div>
                  </div>
                </div>
                <div class="col-lg-3 col-md-6">
                  <div class="status-card status-danger">
                    <div class="status-label">Cancelled</div>
                    <div class="status-value"><?= number_format($m['cancelled']) ?></div>
                  </div>
                </div>
                <div class="col-lg-3 col-md-6">
                  <div class="status-card status-neutral">
                    <div class="status-label">Day Window</div>
                    <div class="status-value"><?= h($start->format('d M')) ?> → <?= h($end->format('d M')) ?></div>
                    <div class="text-muted small mt-1">Bangladesh time (Asia/Dhaka)</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="card">
        <div class="card-header"><i class="bi bi-lightning-charge me-1"></i> <strong>Quick Actions</strong></div>
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2">
            <a href="order_management.php?status=pending&source=online" class="btn btn-outline-primary btn-sm"><i class="bi bi-clock"></i> View Pending Orders</a>
            <a href="order_management.php?status=completed&source=online" class="btn btn-outline-success btn-sm"><i class="bi bi-check2-circle"></i> View Completed</a>
            <a href="registered_users.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-people"></i> Registered Users</a>
            <a href="online_business_report.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-bar-chart"></i> Full Business Report</a>
          </div>
        </div>
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
<!-- SCRIPTS -->
<!-- ============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const sb=document.getElementById('rvSidebar');
  const bd=document.getElementById('rvBackdrop');
  const tg=document.getElementById('rvToggle');
  function close(){ sb.classList.remove('open'); bd.classList.remove('show'); }
  tg && tg.addEventListener('click', function(){ sb.classList.toggle('open'); bd.classList.toggle('show'); });
  bd && bd.addEventListener('click', close);
});
</script>
</body>
</html>