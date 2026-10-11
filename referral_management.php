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
require_once __DIR__ . '/referral_engine.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function taka($v){ return '৳'.number_format((float)$v, 2); }
function redirectWithMessage(string $type, string $message): void {
    $_SESSION['rv_referral_flash'] = ['type'=>$type,'message'=>$message];
    header('Location: referral_management.php', true, 303); exit;
}
function clampInt($v,int $min,int $max): int { return max($min,min($max,(int)$v)); }
function clampPct($v): float { return max(0,min(100,round((float)$v,3))); }
function clampMoney($v): float { return max(0,min(10000000,round((float)$v,2))); }

$shopId = tenantShopId();
if (!rvReferralTableExists($conn,'referral_settings') || !rvCreditSchemaReady($conn)) {
    http_response_code(500);
    die('Credit Wallet V3 database foundation is missing. Apply credit_referral_v3_migration.sql first.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    siteAdminRequireCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($_POST['referral_prefix'] ?? 'RV')));
            $prefix = substr($prefix ?: 'RV',0,8);
            $attributionDays = clampInt($_POST['attribution_days'] ?? 30,1,365);
            $cookieDays = clampInt($_POST['cookie_days'] ?? 30,1,90);
            $minimumOrder = clampMoney($_POST['minimum_order_amount'] ?? 0);
            $purchasePct = clampPct($_POST['purchase_credit_percent'] ?? 2);
            $directPct = clampPct($_POST['direct_referral_percent'] ?? 5);

            if ($enabled && $purchasePct <= 0 && $directPct <= 0) {
                redirectWithMessage('warning','Program enable করা হয়নি: Purchase Credit বা Direct Referral % অন্তত একটি 0-এর বেশি হতে হবে।');
            }

            $stmt = $conn->prepare(
                "UPDATE referral_settings
                 SET enabled=?, referral_prefix=?, attribution_days=?, cookie_days=?,
                     require_new_account=1, require_first_order=0,
                     qualifying_order_status='completed', qualifying_payment_status='paid',
                     minimum_order_amount=?, purchase_credit_percent=?, direct_referral_percent=?,
                     referral_levels=1, credit_base='net_products',
                     referrer_reward_type='percentage', referrer_reward_value=?, referrer_max_reward=NULL,
                     referred_reward_type='percentage', referred_reward_value=?, referred_max_reward=NULL,
                     reward_delivery='ledger', max_successful_referrals_per_day=NULL
                 WHERE shop_id=?"
            );
            if (!$stmt) throw new RuntimeException('Could not prepare settings update.');
            $stmt->bind_param('isiidddddi',$enabled,$prefix,$attributionDays,$cookieDays,$minimumOrder,$purchasePct,$directPct,$directPct,$purchasePct,$shopId);
            if (!$stmt->execute()) { $msg=$stmt->error ?: $conn->error; $stmt->close(); throw new RuntimeException($msg ?: 'Settings update failed.'); }
            $stmt->close();
            redirectWithMessage('success',$enabled ? 'V3 Credit + Direct Referral program enabled.' : 'V3 settings saved. Program remains disabled.');
        }
    } catch (Throwable $e) {
        error_log('Referral V3 admin: '.$e->getMessage());
        redirectWithMessage('danger','Could not save V3 settings safely.');
    }
}

$settings = rvReferralSettings($conn,$shopId);
if (!$settings) {
    $conn->query("INSERT IGNORE INTO referral_settings (shop_id,enabled) VALUES (".(int)$shopId.",0)");
    $settings = rvReferralSettings($conn,$shopId) ?: [];
}

$summary = ['wallets'=>0,'wallet_balance'=>0,'lifetime_earned'=>0,'lifetime_reversed'=>0,'direct_referrals'=>0,'active_rewards'=>0,'tx_count'=>0];
$res=$conn->query("SELECT COUNT(*) wallets,COALESCE(SUM(balance),0) wallet_balance,COALESCE(SUM(lifetime_earned),0) lifetime_earned,COALESCE(SUM(lifetime_reversed),0) lifetime_reversed FROM user_credit_wallets WHERE shop_id=".(int)$shopId);
if($res){$summary=array_merge($summary,$res->fetch_assoc()?:[]);$res->free();}
$res=$conn->query("SELECT COUNT(*) direct_referrals FROM referrals WHERE shop_id=".(int)$shopId." AND status IN ('pending','qualified','rewarded')");
if($res){$summary=array_merge($summary,$res->fetch_assoc()?:[]);$res->free();}
$res=$conn->query("SELECT COUNT(*) active_rewards FROM credit_order_rewards WHERE shop_id=".(int)$shopId." AND status='active'");
if($res){$summary=array_merge($summary,$res->fetch_assoc()?:[]);$res->free();}
$res=$conn->query("SELECT COUNT(*) tx_count FROM credit_transactions WHERE shop_id=".(int)$shopId);
if($res){$summary=array_merge($summary,$res->fetch_assoc()?:[]);$res->free();}

$wallets=[];
$sql="SELECT w.*,u.name,u.email,u.phone,u.referral_code,
            (SELECT COUNT(*) FROM referrals r WHERE r.shop_id=w.shop_id AND r.referrer_user_id=w.user_id AND r.status IN ('pending','qualified','rewarded')) direct_referrals
     FROM user_credit_wallets w
     INNER JOIN users u ON u.id=w.user_id AND u.shop_id=w.shop_id
     WHERE w.shop_id=".(int)$shopId."
     ORDER BY w.balance DESC,w.id DESC LIMIT 200";
$res=$conn->query($sql); if($res){while($r=$res->fetch_assoc())$wallets[]=$r;$res->free();}

$transactions=[];
$sql="SELECT t.*,u.name user_name,u.email user_email,su.name source_name,o.invoice_no,r.reward_type,r.rate_percent,r.base_amount
      FROM credit_transactions t
      LEFT JOIN users u ON u.id=t.user_id
      LEFT JOIN users su ON su.id=t.source_user_id
      LEFT JOIN orders o ON o.id=t.order_id
      LEFT JOIN credit_order_rewards r ON r.id=t.order_reward_id
      WHERE t.shop_id=".(int)$shopId."
      ORDER BY t.id DESC LIMIT 300";
$res=$conn->query($sql); if($res){while($r=$res->fetch_assoc())$transactions[]=$r;$res->free();}

$referrals=[];
$sql="SELECT r.*,ru.name referrer_name,ru.email referrer_email,nu.name referred_name,nu.email referred_email,nu.phone referred_phone,o.invoice_no
      FROM referrals r
      LEFT JOIN users ru ON ru.id=r.referrer_user_id
      LEFT JOIN users nu ON nu.id=r.referred_user_id
      LEFT JOIN orders o ON o.id=r.qualified_order_id
      WHERE r.shop_id=".(int)$shopId." ORDER BY r.id DESC LIMIT 200";
$res=$conn->query($sql); if($res){while($r=$res->fetch_assoc())$referrals[]=$r;$res->free();}

$flash=$_SESSION['rv_referral_flash']??null; unset($_SESSION['rv_referral_flash']);
$csrf=siteAdminCsrfToken();
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Credit &amp; Referral — Rice Valy Admin</title>
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
.stat-card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow);background:#fff;height:100%}
.stat-card .stat-icon{
  width:44px;height:44px;display:flex;align-items:center;justify-content:center;
  border-radius:12px;background:#e6f4f1;color:var(--rv-primary);font-size:20px;flex-shrink:0;
}
.stat-card.stat-gold{border-color:#fde68a;background:#fff9df}
.stat-card.stat-gold .stat-icon{background:#fef3c7;color:#b45309}
.stat-card.stat-green{border-color:#bbf7d0;background:#ecfdf5}
.stat-card.stat-green .stat-icon{background:#dcfce7;color:#15803d}
.stat-card .stat-label{color:var(--rv-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.stat-card .stat-value{font-size:22px;font-weight:700;margin:0;line-height:1.2}
.stat-card .stat-sub{font-size:11px;color:#94a3b8;margin-top:3px}
.rulebox{
  background:#eefbf4;border:1px solid #b8e7cc;border-radius:12px;
  padding:14px 18px;line-height:1.65;font-size:13px;color:#1f2937;
}
.rulebox b{color:#0a5b4f}
.rulebox .rule-highlight{color:#b45309;font-weight:700}
.note{
  background:#fff9df;border:1px solid #ecd66f;border-radius:10px;
  padding:10px 14px;color:#5f5000;font-size:12.5px;
}
.section-title{font-size:16px;font-weight:700;color:var(--rv-text);margin:0}
.amount-pos{color:#087f5b;font-weight:700}
.amount-neg{color:#c92a2a;font-weight:700}
code{color:var(--rv-primary);background:#f0fdfa;padding:2px 6px;border-radius:4px;font-size:12px}
.empty-state{padding:36px 20px;text-align:center;color:var(--rv-muted);font-size:13px}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px}
.form-control,.form-select{border-radius:8px;border-color:#d1d5db;font-size:14px}
.form-control:focus,.form-select:focus{border-color:var(--rv-primary);box-shadow:0 0 0 .2rem rgba(10,91,79,.12)}
.form-control[readonly]{background:#f8fafc}
.form-check-input:checked{background-color:var(--rv-primary);border-color:var(--rv-primary)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
  .table{min-width:1050px}
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
            <li><a href="online_customers.php"><i class="bi bi-wifi"></i> Online Customers</a></li>
            <li><a href="referral_management.php" class="active"><i class="bi bi-gift"></i> Credit &amp; Referral</a></li>
          </ul>
        </div>
      </li>

      <li class="rv-section">Marketing</li>
      <li><a href="customer_dashboard_banners.php"><i class="bi bi-image"></i> Dashboard Banner</a></li>

      <li class="rv-section">Reports</li>
      <li><a href="today_online_overview.php"><i class="bi bi-activity"></i> Today Online Overview</a></li>
      <li><a href="online_business_report.php"><i class="bi bi-bar-chart"></i> Online Business Report</a></li>
    </ul>
  </aside>

  <div class="rv-backdrop" id="rvBackdrop"></div>

  <!-- Main -->
  <div class="rv-main">
    <header class="rv-topbar">
      <button class="rv-toggle" id="rvToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>
      <h5>Credit &amp; Referral Management</h5>
      <div class="rv-actions">
        <div class="dropdown">
          <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <img src="https://www.ricevaly.com/public/uploads/users/1707537536-faveicon.webp" alt="user">
            <span class="ms-2 d-none d-sm-inline fw-semibold">Admin</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><h6 class="dropdown-header">Welcome, Admin</h6></li>
            <li><a class="dropdown-item" href="site_management.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
            <li><a class="dropdown-item" href="registered_users.php"><i class="bi bi-people me-2"></i> Registered Users</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="#" onclick="document.getElementById('logout-form').submit();return false;"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="rv-content">

      <div class="rv-page-title">
        <div>
          <h4>Credit Wallet + Direct Referral</h4>
          <p>V3: recurring 2% own purchase credit + recurring 5% direct referral commission, one level only.</p>
        </div>
        <div class="rv-toolbar">
          <a href="registered_users.php" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i> Registered Users</a>
          <a href="order_management.php?status=all&source=online" class="btn btn-light btn-sm"><i class="bi bi-cart3"></i> Online Orders</a>
          <a href="site_management.php" class="btn btn-primary btn-sm"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </div>
      </div>

      <?php if($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> d-flex align-items-center gap-2">
          <i class="bi <?= $flash['type']==='success'?'bi-check-circle-fill':($flash['type']==='danger'?'bi-exclamation-triangle-fill':'bi-info-circle-fill') ?>"></i>
          <?= h($flash['message']) ?>
        </div>
      <?php endif; ?>

      <!-- Fixed business rule -->
      <div class="rulebox mb-3">
        <b>Fixed business rule:</b> Buyer gets <b><?= h($settings['purchase_credit_percent']??2) ?>%</b> on net product value.
        Only that buyer's <b>direct referrer</b> gets <b><?= h($settings['direct_referral_percent']??5) ?>%</b>. No upline, no second level.
        Delivery/tax are excluded. Credit posts only when online order is <span class="rule-highlight">Completed + Paid</span>, and reverses if the order later stops qualifying.
      </div>

      <!-- KPI summary -->
      <div class="row g-3 mb-3">
        <div class="col-lg-2 col-md-4 col-6">
          <div class="card stat-card"><div class="card-body">
            <div class="stat-label">Wallets</div>
            <h3 class="stat-value"><?= (int)$summary['wallets'] ?></h3>
          </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
          <div class="card stat-card stat-gold"><div class="card-body">
            <div class="stat-label">Outstanding Credit</div>
            <h3 class="stat-value"><?= taka($summary['wallet_balance']) ?></h3>
          </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
          <div class="card stat-card stat-green"><div class="card-body">
            <div class="stat-label">Lifetime Credited</div>
            <h3 class="stat-value"><?= taka($summary['lifetime_earned']) ?></h3>
          </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
          <div class="card stat-card"><div class="card-body">
            <div class="stat-label">Reversed</div>
            <h3 class="stat-value text-danger"><?= taka($summary['lifetime_reversed']) ?></h3>
          </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
          <div class="card stat-card"><div class="card-body">
            <div class="stat-label">Direct Referrals</div>
            <h3 class="stat-value"><?= (int)$summary['direct_referrals'] ?></h3>
          </div></div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
          <div class="card stat-card"><div class="card-body">
            <div class="stat-label">Ledger Entries</div>
            <h3 class="stat-value"><?= (int)$summary['tx_count'] ?></h3>
          </div></div>
        </div>
      </div>

      <!-- Program settings -->
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <span class="section-title"><i class="bi bi-sliders me-1"></i> Program Settings</span>
            <div class="text-muted small mt-1">
              Status:
              <?php if(!empty($settings['enabled'])): ?>
                <span class="badge bg-success">Enabled</span>
              <?php else: ?>
                <span class="badge bg-secondary">Disabled</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="note mb-0">Historical orders are not backfilled. Referral commission is unlimited recurring but strictly one-level.</div>
        </div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="col-md-3">
              <label class="form-label">Program Status</label>
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= !empty($settings['enabled'])?'checked':'' ?>>
                <label class="form-check-label" for="enabled">Enable Credit + Referral</label>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Own Purchase Credit %</label>
              <input type="number" step="0.001" min="0" max="100" name="purchase_credit_percent" class="form-control"
                     value="<?= h($settings['purchase_credit_percent']??2) ?>">
              <small class="text-muted">Default 2%</small>
            </div>
            <div class="col-md-3">
              <label class="form-label">Direct Referral %</label>
              <input type="number" step="0.001" min="0" max="100" name="direct_referral_percent" class="form-control"
                     value="<?= h($settings['direct_referral_percent']??5) ?>">
              <small class="text-muted">Default 5%, one level only</small>
            </div>
            <div class="col-md-3">
              <label class="form-label">Minimum Net Purchase</label>
              <input type="number" step="0.01" min="0" name="minimum_order_amount" class="form-control"
                     value="<?= h($settings['minimum_order_amount']??0) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Referral Prefix</label>
              <input name="referral_prefix" maxlength="8" class="form-control" value="<?= h($settings['referral_prefix']??'RV') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Referral Attribution Days</label>
              <input type="number" min="1" max="365" name="attribution_days" class="form-control"
                     value="<?= h($settings['attribution_days']??30) ?>">
              <small class="text-muted">Link → signup window only</small>
            </div>
            <div class="col-md-3">
              <label class="form-label">Referral Cookie Days</label>
              <input type="number" min="1" max="90" name="cookie_days" class="form-control"
                     value="<?= h($settings['cookie_days']??30) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Referral Level</label>
              <input class="form-control" value="1 — Direct only" readonly>
              <small class="text-muted">Cannot be changed in V3</small>
            </div>

            <div class="col-12">
              <div class="note">
                Credit base is locked to <b>Subtotal − Discount</b>. Delivery charge, tax and other fees do not generate credit.
              </div>
            </div>

            <div class="col-12 text-end">
              <button class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Save V3 Settings</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Wallets -->
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="section-title"><i class="bi bi-wallet2 me-1"></i> Customer Credit Wallets</span>
          <span class="badge bg-primary"><?= count($wallets) ?> wallets</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Referral Code</th>
                  <th class="text-center">Direct Referrals</th>
                  <th class="text-end">Balance</th>
                  <th class="text-end">Lifetime Credited</th>
                  <th class="text-end">Reversed</th>
                  <th>Updated</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$wallets): ?>
                <tr><td colspan="7" class="empty-state">No wallets yet.</td></tr>
              <?php else: foreach($wallets as $w): ?>
                <tr>
                  <td>
                    <strong><?= h($w['name']) ?></strong><br>
                    <small class="text-muted"><?= h($w['email']) ?> · <?= h($w['phone']) ?></small>
                  </td>
                  <td><code><?= h($w['referral_code']?:'—') ?></code></td>
                  <td class="text-center"><strong><?= (int)$w['direct_referrals'] ?></strong></td>
                  <td class="text-end fw-bold"><?= taka($w['balance']) ?></td>
                  <td class="text-end amount-pos"><?= taka($w['lifetime_earned']) ?></td>
                  <td class="text-end <?= $w['lifetime_reversed']>0?'amount-neg':'text-muted' ?>"><?= taka($w['lifetime_reversed']) ?></td>
                  <td><small class="text-muted"><?= h($w['updated_at']) ?></small></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Transaction ledger -->
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="section-title"><i class="bi bi-journal-text me-1"></i> Credit Transaction Ledger</span>
          <span class="badge bg-primary"><?= count($transactions) ?> entries</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Customer</th>
                  <th>Type</th>
                  <th>Order</th>
                  <th>Source Buyer</th>
                  <th>Base / Rate</th>
                  <th class="text-end">Amount</th>
                  <th class="text-end">Balance After</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$transactions): ?>
                <tr><td colspan="9" class="empty-state">No credit transactions yet.</td></tr>
              <?php else: foreach($transactions as $t): $amt=(float)$t['amount']; ?>
                <tr>
                  <td>#<?= (int)$t['id'] ?></td>
                  <td>
                    <strong><?= h($t['user_name']?:'User #'.$t['user_id']) ?></strong><br>
                    <small class="text-muted"><?= h($t['user_email']) ?></small>
                  </td>
                  <td><span class="badge bg-light text-dark"><?= h(str_replace('_',' ',ucwords($t['entry_type'],'_'))) ?></span></td>
                  <td><?= h($t['invoice_no']?:($t['order_id']?'#'.$t['order_id']:'—')) ?></td>
                  <td><?= h($t['source_name']?:($t['source_user_id']?'User #'.$t['source_user_id']:'—')) ?></td>
                  <td>
                    <?php if($t['base_amount']!==null): ?>
                      <?= taka($t['base_amount']) ?> × <?= h($t['rate_percent']) ?>%
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td class="text-end <?= $amt>=0?'amount-pos':'amount-neg' ?>">
                    <?= $amt>=0?'+':'−' ?><?= taka(abs($amt)) ?>
                  </td>
                  <td class="text-end fw-bold"><?= taka($t['balance_after']) ?></td>
                  <td><small class="text-muted"><?= h($t['created_at']) ?></small></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Direct referrals -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="section-title"><i class="bi bi-diagram-2 me-1"></i> Direct Referral Relationships</span>
          <span class="badge bg-primary"><?= count($referrals) ?> relationships</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Referrer</th>
                  <th>Referred Customer</th>
                  <th>Code</th>
                  <th>Status</th>
                  <th>First Qualified Order</th>
                  <th>Attributed</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$referrals): ?>
                <tr><td colspan="7" class="empty-state">No direct referrals yet.</td></tr>
              <?php else: foreach($referrals as $r): ?>
                <tr>
                  <td>#<?= (int)$r['id'] ?></td>
                  <td>
                    <strong><?= h($r['referrer_name']?:'User #'.$r['referrer_user_id']) ?></strong><br>
                    <small class="text-muted"><?= h($r['referrer_email']) ?></small>
                  </td>
                  <td>
                    <strong><?= h($r['referred_name']?:'User #'.$r['referred_user_id']) ?></strong><br>
                    <small class="text-muted"><?= h($r['referred_email']) ?> · <?= h($r['referred_phone']) ?></small>
                  </td>
                  <td><code><?= h($r['referral_code']) ?></code></td>
                  <td>
                    <span class="badge bg-<?= in_array($r['status'],['qualified','rewarded'],true)?'success':($r['status']==='pending'?'warning':'secondary') ?>">
                      <?= h($r['status']) ?>
                    </span>
                  </td>
                  <td><?= h($r['invoice_no']?:'—') ?></td>
                  <td><small class="text-muted"><?= h($r['attributed_at']) ?></small></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
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