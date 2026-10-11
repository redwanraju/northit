<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');
$dbCandidates=[__DIR__.'/../../config/db.php',__DIR__.'/../config/db.php',rtrim($_SERVER['DOCUMENT_ROOT']??'','/').'/config/db.php'];
$dbLoaded=false; foreach($dbCandidates as $f){if($f&&is_file($f)){require_once $f;$dbLoaded=true;break;}}
if(!$dbLoaded||!isset($conn)||!($conn instanceof mysqli)){http_response_code(500);exit('Database unavailable.');}
$conn->set_charset('utf8mb4');
$id=(int)($_GET['id']??0);
if($id<=0){http_response_code(400);exit('Invalid banner.');}
$now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
$stmt=$conn->prepare("SELECT link FROM customer_dashboard_banners WHERE id=? AND status=1 AND (start_at IS NULL OR start_at<=?) AND (end_at IS NULL OR end_at>=?) LIMIT 1");
if(!$stmt){http_response_code(500);exit('Could not prepare redirect.');}
$stmt->bind_param('iss',$id,$now,$now);$stmt->execute();$stmt->bind_result($target);$found=$stmt->fetch();$stmt->close();
if(!$found){http_response_code(404);exit('Banner is no longer available.');}
if(!is_string($target)||$target===''||preg_match('/[\r\n]/',$target)){http_response_code(400);exit('Invalid target URL.');}
if(str_starts_with($target,'/')){
    if(str_starts_with($target,'//')){http_response_code(400);exit('Invalid target URL.');}
}else{
    if(!filter_var($target,FILTER_VALIDATE_URL)){http_response_code(400);exit('Invalid target URL.');}
    $scheme=strtolower((string)parse_url($target,PHP_URL_SCHEME));
    if(!in_array($scheme,['http','https'],true)){http_response_code(400);exit('Invalid target URL.');}
}
$stmt=$conn->prepare("UPDATE customer_dashboard_banners SET click_count=click_count+1,updated_at=updated_at WHERE id=?");
if($stmt){$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();}
header('Cache-Control: no-store, private');
header('Location: '.$target, true, 302);
exit;
?>
