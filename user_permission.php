<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);
$shop_id = tenantShopId();


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
error_reporting(E_ALL);
ini_set('display_errors',1);

include "../config/db.php";



if(!isset($_GET['user_id'])){

    die("User Not Found");

}

$user_id=(int)$_GET['user_id'];


/*==============================
USER INFO
==============================*/

$stmt=$conn->prepare("
SELECT *
FROM users
WHERE id=? AND shop_id=?
");

$stmt->bind_param("ii",$user_id,$shop_id);
$stmt->execute();

$user=$stmt->get_result()->fetch_assoc();

if(!$user){

    die("Invalid User");

}



/*==============================
SAVE PERMISSION
==============================*/

if(isset($_POST['save_permission'])){

    $deleteStmt = $conn->prepare("DELETE up FROM user_permissions up INNER JOIN users u ON u.id = up.user_id WHERE up.user_id = ? AND u.shop_id = ?");
    $deleteStmt->bind_param("ii", $user_id, $shop_id);
    $deleteStmt->execute();

    if(isset($_POST['pages'])){

        $stmt=$conn->prepare("
        INSERT INTO user_permissions
        (
        user_id,
        shop_id,
        page_id
        )
        VALUES
        (?,?,?)
        ");

        foreach($_POST['pages'] as $page_id){

            $page_id=(int)$page_id;

            $stmt->bind_param(
                "iii",
                $user_id,
                $shop_id,
                $page_id
            );

            $stmt->execute();

        }

    }

    header("Location:user_permission.php?user_id=".$user_id."&saved=1");
    exit;

}



/*==============================
CURRENT PERMISSION
==============================*/

$user_permissions = [];

$permStmt = $conn->prepare("SELECT up.page_id FROM user_permissions up WHERE up.user_id = ? AND up.shop_id = ?");
$permStmt->bind_param("ii", $user_id, $shop_id);
$permStmt->execute();
$res = $permStmt->get_result();

while($r = $res->fetch_assoc()){

    $user_permissions[] = (int)$r['page_id'];

}



/*==============================
ALL ACTIVE PAGES
==============================*/

$pages=$conn->query("
SELECT *
FROM pages
WHERE status=1
ORDER BY menu_group,page_name
");
include "header.php";
include "sidebar.php";
?>
<style>

.main{
    flex:1;
    padding:20px;
    background:#f8fafc;
}

.card-box{
    background:#fff;
    border-radius:12px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
}

.group-title{
    font-size:18px;
    font-weight:600;
    margin:20px 0 10px;
    padding-bottom:8px;
    border-bottom:1px solid #eee;
}

.permission-item{
    padding:8px 0;
}

</style>

<div class="main">

<div class="card-box">

<h4>
?? User Permission
</h4>

<hr>

<h5>

<?= htmlspecialchars($user['name']) ?>

<small class="text-muted">

(
<?= strtoupper($user['role']) ?>

)

</small>

</h5>

<?php if(isset($_GET['saved'])){ ?>

<div class="alert alert-success mt-3">

Permission Updated Successfully.

</div>

<?php } ?>

<form method="POST">

<?php

$current_group="";

while($row=$pages->fetch_assoc()){

    if($current_group!=$row['menu_group']){

        $current_group=$row['menu_group'];

        ?>

        <div class="group-title">

            <?= htmlspecialchars($current_group) ?>

        </div>

        <div class="row">

        <?php

    }

?>

<div class="col-md-4 permission-item">

<label>

<input
type="checkbox"
name="pages[]"
value="<?= $row['id'] ?>"
<?= in_array((int)$row['id'], $user_permissions) ? 'checked' : '' ?>

>

<?= htmlspecialchars($row['page_name']) ?>

</label>

</div>

<?php

}

?>

</div>

<hr>

<button
type="submit"
name="save_permission"
class="btn btn-success">

?? Save Permission

</button>

<a
href="user_management.php"
class="btn btn-secondary">

? Back

</a>

</form>

</div>

</div>

<?php include "../pos/footer.php"; ?>