<?php
/**
 * RiceValy Customer Dashboard Banner Widget
 *
 * Include this file from the authenticated customer dashboard after $conn is loaded:
 *   require_once __DIR__ . '/pos/site/customer_dashboard_banner_widget.php';
 *   echo renderCustomerDashboardBanners($conn, (int)$_SESSION['user_id'], 1);
 *
 * The helper verifies that the supplied account belongs to an active customer.
 */
if (!function_exists('renderCustomerDashboardBanners')) {
function renderCustomerDashboardBanners(mysqli $db, int $userId, int $shopId = 1): string
{
    if ($userId <= 0 || $shopId <= 0) return '';
    $check=$db->prepare("SELECT id FROM users WHERE id=? AND shop_id=? AND role='customer' AND status=1 LIMIT 1");
    if(!$check) return '';
    $check->bind_param('ii',$userId,$shopId); $check->execute(); $check->bind_result($validId);
    $valid=$check->fetch(); $check->close(); if(!$valid) return '';

    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $stmt=$db->prepare("SELECT id,image FROM customer_dashboard_banners WHERE status=1 AND (start_at IS NULL OR start_at<=?) AND (end_at IS NULL OR end_at>=?) ORDER BY sort_order ASC,id DESC");
    if(!$stmt) return '';
    $stmt->bind_param('ss',$now,$now); $stmt->execute();
    $stmt->bind_result($id,$image);
    $rows=[]; while($stmt->fetch()) $rows[]=['id'=>(int)$id,'image'=>(string)$image]; $stmt->close();
    if(!$rows) return '';

    $inc=$db->prepare("UPDATE customer_dashboard_banners SET view_count=view_count+1,updated_at=updated_at WHERE id=?");
    $html='<div class="rv-customer-dashboard-banners" aria-label="Promotional banners">';
    $html.='<style>.rv-customer-dashboard-banners{display:grid;gap:14px;margin:0 0 20px}.rv-customer-dashboard-banner{display:block;border-radius:14px;overflow:hidden;line-height:0;background:#f3f5f7}.rv-customer-dashboard-banner img{display:block;width:100%;height:auto;max-height:360px;object-fit:cover}</style>';
    foreach($rows as $row){
        if($inc){$bid=$row['id'];$inc->bind_param('i',$bid);$inc->execute();}
        $safeImage=htmlspecialchars($row['image'],ENT_QUOTES,'UTF-8');
        $safeId=(int)$row['id'];
        $html.='<a class="rv-customer-dashboard-banner" href="/pos/site/customer_dashboard_banner_click.php?id='.$safeId.'" rel="noopener"><img src="'.$safeImage.'" alt="Promotional banner" loading="lazy"></a>';
    }
    if($inc)$inc->close();
    return $html.'</div>';
}
}
?>
