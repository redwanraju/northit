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


/*=========================================
=            SAVE PAGE
=========================================*/

if(isset($_POST['save'])){

    $page_name  = trim($_POST['page_name']);
    $page_file  = trim($_POST['page_file']);
    $page_icon  = trim($_POST['page_icon']);
    $menu_group = trim($_POST['menu_group']);
    $status     = (int)$_POST['status'];

    if($page_name != "" && $page_file != ""){

        // একই page_file আগে আছে কিনা
        $check = $conn->prepare("
            SELECT id
            FROM pages
            WHERE page_file=?
        ");

        $check->bind_param("s",$page_file);
        $check->execute();

        $exists = $check->get_result();

        if($exists->num_rows==0){

            $stmt = $conn->prepare("
                INSERT INTO pages
                (
                    page_name,
                    page_file,
                    page_icon,
                    menu_group,
                    status
                )
                VALUES
                (?,?,?,?,?)
            ");

            $stmt->bind_param(
                "ssssi",
                $page_name,
                $page_file,
                $page_icon,
                $menu_group,
                $status
            );

            $stmt->execute();

            header("Location: page_management.php?success=1");
            exit;

        }else{

            $msg="Page File Already Exists.";

        }

    }

}



/*=========================================
=            LOAD EDIT DATA
=========================================*/

$edit = false;

$page = [

    'id'          => '',
    'page_name'   => '',
    'page_file'   => '',
    'page_icon'   => '',
    'menu_group'  => '',
    'status'      => 1

];


if(isset($_GET['edit'])){

    $id=(int)$_GET['edit'];

    $stmt=$conn->prepare("
        SELECT *
        FROM pages
        WHERE id=?
    ");

    $stmt->bind_param("i",$id);
    $stmt->execute();

    $result=$stmt->get_result();

    if($result->num_rows){

        $page=$result->fetch_assoc();

        $edit=true;

    }

}



/*=========================================
=            UPDATE PAGE
=========================================*/

if(isset($_POST['update'])){

    $id          = (int)$_POST['id'];
    $page_name   = trim($_POST['page_name']);
    $page_file   = trim($_POST['page_file']);
    $page_icon   = trim($_POST['page_icon']);
    $menu_group  = trim($_POST['menu_group']);
    $status      = (int)$_POST['status'];


    $check=$conn->prepare("
        SELECT id
        FROM pages
        WHERE page_file=?
        AND id!=?
    ");

    $check->bind_param(
        "si",
        $page_file,
        $id
    );

    $check->execute();

    $exists=$check->get_result();

    if($exists->num_rows==0){

        $stmt=$conn->prepare("
            UPDATE pages
            SET

            page_name=?,
            page_file=?,
            page_icon=?,
            menu_group=?,
            status=?

            WHERE id=?

        ");

        $stmt->bind_param(

            "ssssii",

            $page_name,
            $page_file,
            $page_icon,
            $menu_group,
            $status,
            $id

        );

        $stmt->execute();

        header("Location: page_management.php?updated=1");
        exit;

    }else{

        $msg="Page File Already Exists.";

    }

}
/*=========================================
=            DELETE PAGE
=========================================*/

if(isset($_GET['delete'])){

    $id=(int)$_GET['delete'];

    // এই Page-এ Permission আছে কিনা
    $check=$conn->prepare("
        SELECT id
        FROM user_permissions
        WHERE page_id=?
        LIMIT 1
    ");

    $check->bind_param("i",$id);
    $check->execute();

    if($check->get_result()->num_rows){

        header("Location: page_management.php?used=1");
        exit;

    }

    $stmt=$conn->prepare("
        DELETE FROM pages
        WHERE id=?
    ");

    $stmt->bind_param("i",$id);
    $stmt->execute();

    header("Location: page_management.php?deleted=1");
    exit;

}



/*=========================================
=            SEARCH & PAGINATION
=========================================*/

$limit = 10;

$page_no = isset($_GET['page'])
    ? max(1,(int)$_GET['page'])
    : 1;

$offset = ($page_no-1)*$limit;

$search = trim($_GET['search'] ?? '');

$where = "";

if($search!=""){

    $search_db = $conn->real_escape_string($search);

    $where="
    WHERE
    page_name LIKE '%$search_db%'
    OR page_file LIKE '%$search_db%'
    OR menu_group LIKE '%$search_db%'
    ";

}

$total = $conn->query("
SELECT COUNT(*) total
FROM pages
$where
")->fetch_assoc()['total'];

$total_pages = ceil($total/$limit);

$pages = $conn->query("
SELECT *
FROM pages
$where
ORDER BY menu_group,page_name
LIMIT $offset,$limit
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
    padding:20px;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
    margin-bottom:20px;
}

.page-title{
    font-size:22px;
    font-weight:600;
    margin-bottom:20px;
}

label{
    font-weight:600;
    margin-bottom:6px;
}

</style>

<div class="main">

<div class="page-title">
📄 Page Management
</div>

<?php if(isset($_GET['success'])){ ?>

<div class="alert alert-success">
Page Added Successfully.
</div>

<?php } ?>

<?php if(isset($_GET['updated'])){ ?>

<div class="alert alert-primary">
Page Updated Successfully.
</div>

<?php } ?>

<?php if(isset($msg)){ ?>

<div class="alert alert-danger">
<?= $msg ?>
</div>

<?php } ?>


<div class="card-box">

<form method="POST">

<?php if($edit){ ?>

<input
type="hidden"
name="id"
value="<?= $page['id'] ?>"
>

<?php } ?>

<div class="row">

<div class="col-md-6 mb-3">

<label>Page Name</label>

<input
type="text"
name="page_name"
class="form-control"
required
value="<?= htmlspecialchars($page['page_name']) ?>"
placeholder="Example : Student List">

</div>


<div class="col-md-6 mb-3">

<label>Page File</label>

<input
type="text"
name="page_file"
class="form-control"
required
value="<?= htmlspecialchars($page['page_file']) ?>"
placeholder="students.php">

</div>



<div class="col-md-6 mb-3">

<label>Menu Group</label>

<input
type="text"
name="menu_group"
class="form-control"
value="<?= htmlspecialchars($page['menu_group']) ?>"
placeholder="Students">

</div>



<div class="col-md-6 mb-3">

<label>Font Awesome Icon</label>

<input
type="text"
name="page_icon"
class="form-control"
value="<?= htmlspecialchars($page['page_icon']) ?>"
placeholder="fa-solid fa-users">

</div>



<div class="col-md-6 mb-3">

<label>Status</label>

<select
name="status"
class="form-control">

<option
value="1"
<?= $page['status']==1?'selected':''; ?>>
Active
</option>

<option
value="0"
<?= $page['status']==0?'selected':''; ?>>
Inactive
</option>

</select>

</div>



<div class="col-md-6 d-flex align-items-end">

<?php if($edit){ ?>

<button
class="btn btn-primary w-100"
name="update">

Update Page

</button>

<?php }else{ ?>

<button
class="btn btn-success w-100"
name="save">

Save Page

</button>

<?php } ?>

</div>

</div>

</form>

</div>
<?php if(isset($_GET['deleted'])){ ?>

<div class="alert alert-success">
Page Deleted Successfully.
</div>

<?php } ?>

<?php if(isset($_GET['used'])){ ?>

<div class="alert alert-danger">
This page is already assigned to a user.
Delete permission first.
</div>

<?php } ?>



<div class="card-box">

<div class="d-flex justify-content-between mb-3">

<h5>Page List</h5>

<form method="GET">

<div class="input-group">

<input
type="text"
name="search"
class="form-control"
placeholder="Search..."
value="<?= htmlspecialchars($search) ?>">

<button class="btn btn-primary">

Search

</button>

</div>

</form>

</div>



<table class="table table-bordered table-hover align-middle">

<thead>

<tr>

<th>ID</th>

<th>Page Name</th>

<th>Page File</th>

<th>Group</th>

<th>Status</th>

<th>Action</th>

</tr>

</thead>

<tbody>

<?php while($row=$pages->fetch_assoc()){ ?>

<tr>

<td><?= $row['id'] ?></td>

<td><?= htmlspecialchars($row['page_name']) ?></td>

<td><?= htmlspecialchars($row['page_file']) ?></td>

<td><?= htmlspecialchars($row['menu_group']) ?></td>

<td>

<?php if($row['status']){ ?>

<span class="badge bg-success">

Active

</span>

<?php }else{ ?>

<span class="badge bg-danger">

Inactive

</span>

<?php } ?>

</td>

<td>

<a
href="?edit=<?= $row['id'] ?>"
class="btn btn-warning btn-sm">

Edit

</a>

<a
href="?delete=<?= $row['id'] ?>"
class="btn btn-danger btn-sm"
onclick="return confirm('Delete this page?')">

Delete

</a>

</td>

</tr>

<?php } ?>

</tbody>

</table>
<nav>

<ul class="pagination">

<?php for($i=1;$i<=$total_pages;$i++){ ?>

<li class="page-item <?= $i==$page_no?'active':'' ?>">

<a
class="page-link"
href="?page=<?= $i ?>&search=<?= urlencode($search) ?>">

<?= $i ?>

</a>

</li>

<?php } ?>

</ul>

</nav>

</div>

</div>

<?php include "../pos/footer.php"; ?>