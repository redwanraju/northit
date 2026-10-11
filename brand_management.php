<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";
include "../pos/header.php";
include "../pos/sidebar.php";

/* ================= SHOP ================= */
$shop_id = tenantShopId();

/* ================= ADD BRAND ================= */
if(isset($_POST['save'])){

    $brand_name = trim($_POST['brand_name']);
    $status     = (int)$_POST['status'];

    if($brand_name!=""){

        // Duplicate Check
        $check = $conn->prepare("
            SELECT id
            FROM brands
            WHERE shop_id=?
            AND brand_name=?
        ");

        $check->bind_param("is",$shop_id,$brand_name);
        $check->execute();
        $result = $check->get_result();

        if($result->num_rows==0){

            $stmt = $conn->prepare("
                INSERT INTO brands
                (shop_id,brand_name,status)
                VALUES (?,?,?)
            ");

            $stmt->bind_param(
                "isi",
                $shop_id,
                $brand_name,
                $status
            );

            $stmt->execute();

        }

    }

}

/* ================= DELETE ================= */
if(isset($_GET['delete'])){

    $id = (int)$_GET['delete'];

    $stmt = $conn->prepare("
        DELETE FROM brands
        WHERE id=?
        AND shop_id=?
    ");

    $stmt->bind_param(
        "ii",
        $id,
        $shop_id
    );

    $stmt->execute();

}
/* ================= UPDATE BRAND ================= */

if(isset($_POST['update'])){

    $brand_id   = (int)$_POST['brand_id'];
    $brand_name = trim($_POST['brand_name']);
    $status     = (int)$_POST['status'];

    // Duplicate Check
    $check = $conn->prepare("
        SELECT id
        FROM brands
        WHERE shop_id=?
        AND brand_name=?
        AND id<>?
    ");

    $check->bind_param(
        "isi",
        $shop_id,
        $brand_name,
        $brand_id
    );

    $check->execute();

    if($check->get_result()->num_rows==0){

        $stmt = $conn->prepare("
            UPDATE brands
            SET
                brand_name=?,
                status=?
            WHERE
                id=?
            AND
                shop_id=?
        ");

        $stmt->bind_param(
            "siii",
            $brand_name,
            $status,
            $brand_id,
            $shop_id
        );

        $stmt->execute();

        echo "
        <script>
        Swal.fire({
            icon:'success',
            title:'Updated!',
            text:'Brand updated successfully.'
        }).then(()=>{
            window.location='brand_management.php';
        });
        </script>";
    }else{

        echo "
        <script>
        Swal.fire({
            icon:'warning',
            title:'Duplicate!',
            text:'Brand already exists.'
        });
        </script>";

    }

}
/* ================= FETCH ================= */

$brands = $conn->query("
SELECT *
FROM brands
WHERE shop_id='$shop_id'
ORDER BY id DESC
");

?>

<style>

.main{
    flex:1;
    padding:20px;
    background:#f8fafc;
}

.card-box{
    background:#fff;
    padding:15px;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
    margin-bottom:15px;
}

.badge-active{
    background:#16a34a;
    color:#fff;
    padding:5px 10px;
    border-radius:20px;
    font-size:12px;
}

.badge-inactive{
    background:#dc2626;
    color:#fff;
    padding:5px 10px;
    border-radius:20px;
    font-size:12px;
}

</style>

<div class="main">

<h3>🏷 Brand Management</h3>

<div class="card-box">

<form method="POST">

<div class="row">

<div class="col-md-6">
<input
type="text"
name="brand_name"
class="form-control"
placeholder="Brand Name"
required>
</div>

<div class="col-md-3">
<select
name="status"
class="form-control">

<option value="1">
Active
</option>

<option value="0">
Inactive
</option>

</select>
</div>

<div class="col-md-3">
<button
name="save"
class="btn btn-warning w-100">

ADD BRAND

</button>
</div>

</div>

</form>

</div>

<div class="card-box">

<table class="table table-hover align-middle">

<thead>

<tr>

<th>ID</th>

<th>Brand</th>

<th>Status</th>

<th>Created</th>

<th width="120">
Action
</th>

</tr>

</thead>

<tbody>
<?php if($brands->num_rows > 0){ ?>

<?php while($b = $brands->fetch_assoc()){ ?>

<tr>

    <td><?= $b['id'] ?></td>

    <td>
        <strong><?= htmlspecialchars($b['brand_name']) ?></strong>
    </td>

    <td>

        <?php if($b['status']==1){ ?>

            <span class="badge-active">
                Active
            </span>

        <?php }else{ ?>

            <span class="badge-inactive">
                Inactive
            </span>

        <?php } ?>

    </td>

    <td>
        <?= date("d M Y", strtotime($b['created_at'])) ?>
    </td>

    <td>

        <button
            class="btn btn-sm btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#edit<?= $b['id'] ?>">

            ✏ Edit

        </button>

        <a
        href="?delete=<?= $b['id'] ?>"
        class="btn btn-sm btn-danger"
        onclick="return confirm('Delete this brand?')">

        🗑

        </a>

    </td>

</tr>

<!-- ================= EDIT MODAL ================= -->

<div
class="modal fade"
id="edit<?= $b['id'] ?>"
tabindex="-1">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<div class="modal-header">

<h5 class="modal-title">

Edit Brand

</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal">
</button>

</div>

<div class="modal-body">

<input
type="hidden"
name="brand_id"
value="<?= $b['id'] ?>">

<div class="mb-3">

<label>

Brand Name

</label>

<input
type="text"
name="brand_name"
class="form-control"
value="<?= htmlspecialchars($b['brand_name']) ?>"
required>

</div>

<div class="mb-3">

<label>

Status

</label>

<select
name="status"
class="form-control">

<option
value="1"
<?= $b['status']==1 ? 'selected' : '' ?>>

Active

</option>

<option
value="0"
<?= $b['status']==0 ? 'selected' : '' ?>>

Inactive

</option>

</select>

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal">

Close

</button>

<button
name="update"
class="btn btn-success">

Update Brand

</button>

</div>

</form>

</div>

</div>

</div>

<?php } ?>

<?php }else{ ?>

<tr>

<td colspan="5" class="text-center text-muted">

No Brand Found

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

<?php include "../pos/footer.php"; ?>