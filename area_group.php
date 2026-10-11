<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";
include "header.php";
include "sidebar.php";

$shop_id = tenantShopId();/* ================= SAVE AREA GROUP ================= */
if(isset($_POST['save'])){

    $area_name = trim($_POST['area_name']);

    if($area_name != ""){

        $stmt = $conn->prepare("
            INSERT INTO area_groups (shop_id, area_name)
            VALUES (?,?)
        ");

        $stmt->bind_param("is", $shop_id, $area_name);
        $stmt->execute();
    }
    header("Location: area_group.php");
    exit;
}

/* ================= UPDATE AREA GROUP ================= */
if(isset($_POST['update'])){

    $id         = (int)$_POST['area_group_id'];
    $area_name  = trim($_POST['area_name']);

    $stmt = $conn->prepare("
        UPDATE area_groups 
        SET area_name=?
        WHERE id=? AND shop_id=?
    ");

    $stmt->bind_param("sii", $area_name, $id, $shop_id);
    $stmt->execute();

    header("Location: area_group.php");
    exit;
}

/* ================= DELETE AREA GROUP ================= */
if(isset($_POST['delete_id'])){

    $id = (int)$_POST['delete_id'];

    $stmt = $conn->prepare("
        DELETE FROM area_groups
        WHERE id=? AND shop_id=?
    ");

    $stmt->bind_param("ii", $id, $shop_id);
    $stmt->execute();

    header("Location: area_group.php");
    exit;
}

/* ================= FETCH AREA GROUPS ================= */
$area_groups = $conn->query("
    SELECT *
    FROM area_groups
    WHERE shop_id = '$shop_id'
    ORDER BY area_name ASC
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
.badge-id{
    background:#0f172a;
    color:#fff;
    padding:4px 8px;
    border-radius:20px;
    font-size:11px;
}
.table td,
.table th{
    vertical-align:middle;
}
.action-btn{
    padding:4px 10px;
    border-radius:6px;
    font-size:12px;
    border:none;
    cursor:pointer;
    margin-right:4px;
    text-decoration:none;
    display:inline-block;
}
.btn-edit{
    background:#f59e0b;
    color:#fff;
}
.btn-edit:hover{
    background:#d97706;
    color:#fff;
}
.btn-delete{
    background:#ef4444;
    color:#fff;
}
.btn-delete:hover{
    background:#dc2626;
    color:#fff;
}
.area-badge{
    background:linear-gradient(135deg, #6366f1, #8b5cf6);
    color:#fff;
    padding:6px 14px;
    border-radius:20px;
    font-size:13px;
    font-weight:500;
    display:inline-block;
    margin:2px;
}
</style>

<div class="main">

<h3>📍 Area Group Management</h3>

<!-- ================= ADD AREA GROUP ================= -->
<div class="card-box">
    <form method="POST">
        <div class="row g-2">
            <div class="col-md-4">
                <input type="text" name="area_name" class="form-control" placeholder="Area Name (e.g. Uttara-1)" required>
            </div>
            <div class="col-md-2">
                <button type="submit" name="save" class="btn btn-success w-100">
                    <i class="fas fa-plus"></i> ADD
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ================= AREA GROUP TABLE ================= -->
<div class="card-box">
    <div class="table-responsive">
        <table class="table table-hover table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th width="80">ID</th>
                    <th>Area Name</th>
                    <th width="120">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if($area_groups->num_rows > 0){ ?>
                    <?php while($a = $area_groups->fetch_assoc()){ ?>
                    <tr>
                        <td><span class="badge-id">#<?= $a['id'] ?></span></td>
                        <td>
                            <span class="area-badge">
                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($a['area_name']) ?>
                            </span>
                        </td>
                        <td>
                            <!-- EDIT BUTTON -->
                            <button type="button" class="action-btn btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?= $a['id'] ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <!-- DELETE BUTTON -->
                            <button type="button" class="action-btn btn-delete" onclick="confirmDelete(<?= $a['id'] ?>, '<?= htmlspecialchars($a['area_name'], ENT_QUOTES) ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>

                    <!-- ================= EDIT MODAL ================= -->
                    <div class="modal fade" id="editModal<?= $a['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">✏️ Edit Area Group</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <input type="hidden" name="area_group_id" value="<?= $a['id'] ?>">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Area Name</label>
                                            <input type="text" name="area_name" class="form-control" value="<?= htmlspecialchars($a['area_name']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="update" class="btn btn-primary">💾 Update</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php } ?>
                <?php } else { ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-2x mb-2"></i><br>
                            No area groups found. Add one above.
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<script>
/* DELETE CONFIRMATION FUNCTION */
function confirmDelete(id, areaName){
    if(confirm('Are you sure you want to delete "' + areaName + '"?\n\nThis action cannot be undone!')){
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = 'area_group.php';
        form.style.display = 'none';
        
        let input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_id';
        input.value = id;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include "../pos/footer.php"; ?>