<?php
require_once __DIR__ . '/site_admin_guard.php';
requireSiteAdmin(1);

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('display_startup_errors','0');
mysqli_report(MYSQLI_REPORT_OFF);

$dbCandidates=[__DIR__.'/../../config/db.php',__DIR__.'/../config/db.php',rtrim($_SERVER['DOCUMENT_ROOT']??'','/').'/config/db.php'];
$dbLoaded=false;
foreach($dbCandidates as $f){if($f&&is_file($f)){require_once $f;$dbLoaded=true;break;}}
if(!$dbLoaded||!isset($conn)||!($conn instanceof mysqli)){http_response_code(500);die('Database connection could not be loaded. Expected /config/db.php');}
$conn->set_charset('utf8mb4');
$uploadDir=__DIR__.'/uploads/customer_dashboard_banners';
$publicDir='uploads/customer_dashboard_banners';
if(!is_dir($uploadDir)) @mkdir($uploadDir,0755,true);

function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flash($type,$msg){$_SESSION['rv_banner_flash']=['type'=>$type,'msg'=>$msg];}
function redirectSelf(){header('Location: customer_dashboard_banners.php');exit;}
function validLink($url): bool {
    $url=trim($url);
    if($url==='' || strlen($url)>1000 || preg_match('/[\r\n]/',$url)) return false;
    if(str_starts_with($url,'/')) return true;
    if(!filter_var($url,FILTER_VALIDATE_URL)) return false;
    $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
    return in_array($scheme,['http','https'],true);
}
function parseDt($v): ?string {
    $v=trim((string)$v); if($v==='') return null;
    $dt=DateTime::createFromFormat('Y-m-d\TH:i',$v,new DateTimeZone('Asia/Dhaka'));
    if(!$dt) $dt=DateTime::createFromFormat('Y-m-d H:i:s',$v,new DateTimeZone('Asia/Dhaka'));
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}
function uploadBannerImage(array $file,string $dir,string $publicDir): ?string {
    if(!isset($file['error']) || $file['error']===UPLOAD_ERR_NO_FILE) return null;
    if($file['error']!==UPLOAD_ERR_OK || (int)$file['size']<=0 || (int)$file['size']>5*1024*1024) throw new RuntimeException('Image upload failed or exceeds the 5 MB limit.');
    $tmp=(string)$file['tmp_name']; if(!is_uploaded_file($tmp)) throw new RuntimeException('Invalid upload.');
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($tmp); $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $info=@getimagesize($tmp); if(!isset($allowed[$mime])||$info===false) throw new RuntimeException('Only valid JPG, PNG, WEBP and GIF images are allowed.');
    $w=(int)$info[0];$h=(int)$info[1]; if($w<1||$h<1||$w>10000||$h>10000) throw new RuntimeException('Unsupported image dimensions.');
    if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir)) throw new RuntimeException('Banner directory is unavailable.');
    if($mime==='image/gif'||!extension_loaded('gd')||!function_exists('imagewebp')){
        $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$target=$dir.'/'.$name;if(!move_uploaded_file($tmp,$target))throw new RuntimeException('Could not store uploaded image.');@chmod($target,0644);return $publicDir.'/'.$name;
    }
    if($mime==='image/jpeg')$src=@imagecreatefromjpeg($tmp);elseif($mime==='image/png')$src=@imagecreatefrompng($tmp);else$src=@imagecreatefromwebp($tmp);if(!$src)throw new RuntimeException('Could not process uploaded image.');
    if($mime==='image/jpeg'&&function_exists('exif_read_data')){$exif=@exif_read_data($tmp);$o=(int)($exif['Orientation']??1);if($o===3)$src=imagerotate($src,180,0);elseif($o===6)$src=imagerotate($src,-90,0);elseif($o===8)$src=imagerotate($src,90,0);$w=imagesx($src);$h=imagesy($src);}
    $max=1920;$scale=min(1,$max/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));$dst=imagecreatetruecolor($nw,$nh);if(!$dst){imagedestroy($src);throw new RuntimeException('Could not optimize image.');}
    imagealphablending($dst,false);imagesavealpha($dst,true);$tr=imagecolorallocatealpha($dst,0,0,0,127);imagefilledrectangle($dst,0,0,$nw,$nh,$tr);imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
    $name=bin2hex(random_bytes(16)).'.webp';$target=$dir.'/'.$name;$ok=imagewebp($dst,$target,82);imagedestroy($src);imagedestroy($dst);if(!$ok||!is_file($target))throw new RuntimeException('Could not store optimized image.');@chmod($target,0644);return $publicDir.'/'.$name;
}
function deleteLocalImage(string $path,string $dir): void {
    $name=basename(parse_url($path,PHP_URL_PATH)??'');
    if($name==='' || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/',$name)) return;
    $target=$dir.'/'.$name;
    if(is_file($target)) @unlink($target);
}

if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    siteAdminRequireCsrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='save'){
            $id=(int)($_POST['id']??0);
            $link=trim((string)($_POST['link']??''));
            $status=(int)($_POST['status']??1); $status=$status===1?1:0;
            $sort=(int)($_POST['sort_order']??0);
            $start=parseDt($_POST['start_at']??''); $end=parseDt($_POST['end_at']??'');
            if(!validLink($link)) throw new RuntimeException('Please enter a valid HTTP/HTTPS or site-relative link.');
            if($start && $end && $start >= $end) throw new RuntimeException('End time must be after start time.');
            $image=null;
            if(isset($_FILES['image'])) $image=uploadBannerImage($_FILES['image'],$uploadDir,$publicDir);
            if($id>0){
                $stmt=$conn->prepare("SELECT image FROM customer_dashboard_banners WHERE id=? LIMIT 1");
                $stmt->bind_param('i',$id);$stmt->execute();$stmt->bind_result($oldImage);$exists=$stmt->fetch();$stmt->close();
                if(!$exists) throw new RuntimeException('Banner not found.');
                if($image){
                    $stmt=$conn->prepare("UPDATE customer_dashboard_banners SET image=?,link=?,start_at=?,end_at=?,status=?,sort_order=?,updated_at=NOW() WHERE id=?");
                    $stmt->bind_param('ssssiii',$image,$link,$start,$end,$status,$sort,$id);
                    $stmt->execute();$ok=$stmt->affected_rows>=0;$stmt->close();
                    if($ok && $oldImage!==$image) deleteLocalImage((string)$oldImage,$uploadDir);
                }else{
                    $stmt=$conn->prepare("UPDATE customer_dashboard_banners SET link=?,start_at=?,end_at=?,status=?,sort_order=?,updated_at=NOW() WHERE id=?");
                    $stmt->bind_param('sssiii',$link,$start,$end,$status,$sort,$id);$stmt->execute();$stmt->close();
                }
                flash('success','Banner updated successfully.');
            }else{
                if(!$image) throw new RuntimeException('Banner image is required.');
                $createdBy=(int)($_SESSION['user_id']??0);
                $stmt=$conn->prepare("INSERT INTO customer_dashboard_banners (image,link,start_at,end_at,status,sort_order,created_by) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssiii',$image,$link,$start,$end,$status,$sort,$createdBy);$stmt->execute();$stmt->close();
                flash('success','Banner added successfully.');
            }
            redirectSelf();
        }
        if($action==='delete'){
            $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid banner ID.');
            $stmt=$conn->prepare("SELECT image FROM customer_dashboard_banners WHERE id=? LIMIT 1");
            $stmt->bind_param('i',$id);$stmt->execute();$stmt->bind_result($img);$exists=$stmt->fetch();$stmt->close();
            if(!$exists) throw new RuntimeException('Banner not found.');
            $stmt=$conn->prepare("DELETE FROM customer_dashboard_banners WHERE id=?");$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
            deleteLocalImage((string)$img,$uploadDir); flash('success','Banner deleted successfully.'); redirectSelf();
        }
        if($action==='toggle'){
            $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid banner ID.');
            $stmt=$conn->prepare("UPDATE customer_dashboard_banners SET status=IF(status=1,0,1),updated_at=NOW() WHERE id=?");
            $stmt->bind_param('i',$id);$stmt->execute();$stmt->close(); flash('success','Banner status updated.'); redirectSelf();
        }
        throw new RuntimeException('Unknown action.');
    }catch(Throwable $e){flash('danger',$e->getMessage());redirectSelf();}
}

$editId=(int)($_GET['edit']??0); $edit=null;
if($editId>0){
    $stmt=$conn->prepare("SELECT id,image,link,view_count,click_count,start_at,end_at,status,sort_order,created_by,created_at,updated_at FROM customer_dashboard_banners WHERE id=? LIMIT 1");
    if($stmt){$stmt->bind_param('i',$editId);$stmt->execute();$stmt->bind_result($eid,$eimage,$elink,$eviews,$eclicks,$estart,$eend,$estatus,$esort,$ecreatedby,$ecreatedat,$eupdatedat);if($stmt->fetch()){$edit=['id'=>$eid,'image'=>$eimage,'link'=>$elink,'view_count'=>$eviews,'click_count'=>$eclicks,'start_at'=>$estart,'end_at'=>$eend,'status'=>$estatus,'sort_order'=>$esort,'created_by'=>$ecreatedby,'created_at'=>$ecreatedat,'updated_at'=>$eupdatedat];}$stmt->close();}
}
$list=[];
$res=$conn->query("SELECT * FROM customer_dashboard_banners ORDER BY sort_order ASC,id DESC");
if($res){while($r=$res->fetch_assoc())$list[]=$r;$res->free();}
$flash=$_SESSION['rv_banner_flash']??null; unset($_SESSION['rv_banner_flash']);
$csrf=siteAdminCsrfToken();
$tz=new DateTimeZone('Asia/Dhaka');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<title>Customer Dashboard Banner — Rice Valy Admin</title>
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
.card{border:1px solid var(--rv-border);border-radius:var(--rv-radius);box-shadow:var(--rv-shadow)}
.card-header{background:#fafbfc;border-bottom:1px solid var(--rv-border);font-weight:600}
.table{background:#fff;margin-bottom:0}
.table thead th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--rv-muted);font-weight:600;border-bottom:1px solid var(--rv-border)}
.table td,.table th{vertical-align:middle;padding:.7rem .75rem}
.btn{border-radius:8px;font-weight:500}
.btn-primary{background:var(--rv-primary);border-color:var(--rv-primary)}
.btn-primary:hover{background:var(--rv-primary-2);border-color:var(--rv-primary-2)}
.pill{display:inline-block;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700}
.pill.on{background:#dcfce7;color:#15803d}
.pill.off{background:#fee2e2;color:#b91c1c}
.thumb{width:140px;height:64px;object-fit:cover;border-radius:8px;background:#f1f3f5;border:1px solid var(--rv-border)}
.empty-state{padding:50px 20px;text-align:center;color:var(--rv-muted)}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px}
.form-control,.form-select{border-radius:8px;border-color:#d1d5db;font-size:14px}
.form-control:focus,.form-select:focus{border-color:var(--rv-primary);box-shadow:0 0 0 .2rem rgba(10,91,79,.12)}
.rv-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1035}
.rv-backdrop.show{display:block}
.notice{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:500;display:flex;align-items:center;gap:8px}
.notice.success{background:#eaf8f0;color:#16794c;border:1px solid #bbf7d0}
.notice.danger{background:#feeceb;color:#b42318;border:1px solid #fecaca}
@media (max-width: 991.98px){
  .rv-sidebar{transform:translateX(-100%)}
  .rv-sidebar.open{transform:translateX(0)}
  .rv-main{margin-left:0}
  .rv-topbar .rv-toggle{display:inline-block}
  .rv-content{padding:16px}
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
      <li><a href="order_management.php?source=online&status=all"><i class="bi bi-cart3"></i> Orders</a></li>

      <li class="rv-section">Products</li>
      <li><a href="site_management.php"><i class="bi bi-box"></i> Products</a></li>

      <li class="rv-section">Users</li>
      <li><a href="registered_users.php"><i class="bi bi-people"></i> Registered Users</a></li>
      <li><a href="online_customers.php"><i class="bi bi-wifi"></i> Online Customers</a></li>

      <li class="rv-section">Marketing</li>
      <li>
        <a href="customer_dashboard_banners.php" class="active"><i class="bi bi-image"></i> Dashboard Banner</a>
      </li>

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
      <h5>Customer Dashboard Banner</h5>
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
          <h4><?= $edit ? 'Edit Banner' : 'Add Banner' ?></h4>
          <p>Customers see active banners during their dashboard session. Schedule uses Bangladesh time (Asia/Dhaka).</p>
        </div>
      </div>

      <?php if($flash): ?>
        <div class="notice <?= h($flash['type']==='success'?'success':'danger') ?>">
          <i class="bi <?= $flash['type']==='success'?'bi-check-circle-fill':'bi-exclamation-triangle-fill' ?>"></i>
          <?= h($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- Add/Edit form -->
      <div class="card mb-3">
        <div class="card-header">
          <i class="bi <?= $edit?'bi-pencil-square':'bi-plus-circle' ?> me-1"></i>
          <?= $edit ? 'Edit Banner #'.(int)$edit['id'] : 'Create New Banner' ?>
        </div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <?php if($edit): ?><input type="hidden" name="id" value="<?= h($edit['id']) ?>"><?php endif; ?>

            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Banner Image <?= $edit?'<span class="text-muted fw-normal">(leave empty to keep current image)</span>':'' ?></label>
                <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" <?= $edit?'':'required' ?>>
                <div class="text-muted small mt-1">Max 5 MB. JPG, PNG, WEBP or GIF only.</div>
                <?php if($edit && !empty($edit['image'])): ?>
                  <div class="mt-2">
                    <img class="thumb" src="<?= h($edit['image']) ?>" alt="Current banner">
                  </div>
                <?php endif; ?>
              </div>

              <div class="col-12">
                <label class="form-label">Link</label>
                <input type="text" name="link" class="form-control" maxlength="1000" required
                       value="<?= h($edit['link']??'') ?>"
                       placeholder="https://example.com/page or /some-page">
              </div>

              <div class="col-md-6">
                <label class="form-label">Start At</label>
                <input type="datetime-local" name="start_at" class="form-control"
                       value="<?= $edit && $edit['start_at'] ? h((new DateTime($edit['start_at'], $tz))->format('Y-m-d\TH:i')) : '' ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label">End At</label>
                <input type="datetime-local" name="end_at" class="form-control"
                       value="<?= $edit && $edit['end_at'] ? h((new DateTime($edit['end_at'], $tz))->format('Y-m-d\TH:i')) : '' ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <option value="1" <?= (!$edit || (int)$edit['status']===1)?'selected':'' ?>>Active</option>
                  <option value="0" <?= ($edit && (int)$edit['status']===0)?'selected':'' ?>>Inactive</option>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control"
                       value="<?= h($edit['sort_order']??0) ?>" min="0" max="999999">
              </div>

              <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                  <i class="bi <?= $edit?'bi-save':'bi-plus-lg' ?>"></i>
                  <?= $edit?'Update Banner':'Add Banner' ?>
                </button>
                <?php if($edit): ?>
                  <a class="btn btn-light" href="customer_dashboard_banners.php"><i class="bi bi-x-lg"></i> Cancel</a>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Banner list -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-images me-1"></i>
            <strong>Customer Dashboard Banners</strong>
          </div>
          <span class="badge bg-primary"><?= count($list) ?> total</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0" style="min-width:900px">
              <thead>
                <tr>
                  <th>Banner</th>
                  <th>Link</th>
                  <th class="text-center">Views</th>
                  <th class="text-center">Clicks</th>
                  <th>Schedule</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($list as $b): ?>
                <tr>
                  <td><img class="thumb" src="<?= h($b['image']) ?>" alt="Banner"></td>
                  <td style="max-width:260px;word-break:break-all"><?= h($b['link']) ?></td>
                  <td class="text-center"><?= number_format((int)$b['view_count']) ?></td>
                  <td class="text-center"><?= number_format((int)$b['click_count']) ?></td>
                  <td class="small text-muted"><?= h($b['start_at']?:'Now') ?> → <?= h($b['end_at']?:'No expiry') ?></td>
                  <td>
                    <span class="pill <?= ((int)$b['status']===1?'on':'off') ?>">
                      <?= ((int)$b['status']===1?'Active':'Inactive') ?>
                    </span>
                  </td>
                  <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= h($b['id']) ?>"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Toggle this banner status?')">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= h($b['id']) ?>">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-toggle-on"></i> Toggle</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this banner? This cannot be undone.')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= h($b['id']) ?>">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$list): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="bi bi-image" style="font-size:36px;display:block;margin-bottom:8px;"></i>No banners created yet. Add your first banner using the form above.</div></td></tr>
              <?php endif; ?>
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

</body>
</html>