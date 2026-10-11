<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

error_reporting(E_ALL);
ini_set('display_errors', 1);  // ← ডিবাগের জন্য এখন 1 রাখুন
ini_set('log_errors', 1);

include "../config/db.php";

// ✅ কানেকশন charset নিশ্চিত
mysqli_set_charset($conn, "utf8mb4");
$conn->query("SET NAMES utf8mb4");
$conn->query("SET collation_connection = 'utf8mb4_general_ci'");



$shop_id = tenantShopId();

/* ================= EDIT FETCH ================= */
$edit = false;
$editData = [];

if (isset($_GET['edit'])) {
    $edit = true;
    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare("SELECT * FROM categories WHERE id=? AND shop_id=?");
    $stmt->bind_param("ii", $id, $shop_id);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* ================= SAVE CATEGORY ================= */
if (isset($_POST['save'])) {
    $category_name = trim($_POST['category_name'] ?? '');

    if ($category_name !== '') {
        $stmt = $conn->prepare("INSERT INTO categories (shop_id, category_name) VALUES (?,?)");
        $stmt->bind_param("is", $shop_id, $category_name);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: category.php");
    exit;
}

/* ================= UPDATE ================= */
if (isset($_POST['update'])) {
    $id = (int)$_POST['id'];
    $category_name = trim($_POST['category_name'] ?? '');

    $stmt = $conn->prepare("UPDATE categories SET category_name=? WHERE id=? AND shop_id=?");
    $stmt->bind_param("sii", $category_name, $id, $shop_id);
    $stmt->execute();
    $stmt->close();

    header("Location: category.php");
    exit;
}

/* ================= DELETE ================= */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM categories WHERE id=? AND shop_id=?");
    $stmt->bind_param("ii", $id, $shop_id);
    $stmt->execute();
    $stmt->close();

    header("Location: category.php");
    exit;
}

/* ================= FETCH ================= */
$stmt = $conn->prepare("SELECT * FROM categories WHERE shop_id=? ORDER BY id DESC");
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$categories = $stmt->get_result();
include "../pos/header.php";
include "../pos/sidebar.php";
?>

<style>
.main{flex:1;padding:20px;background:#f8fafc}
.card-box{background:#fff;padding:15px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.05);margin-bottom:15px}
</style>

<div class="main">

<h3>📂 Category Management</h3>

<!-- ADD / EDIT FORM -->
<div class="card-box">
<form method="POST" accept-charset="UTF-8">
    <div class="row">
        <div class="col-md-10">
            <input type="text"
                   name="category_name"
                   class="form-control"
                   placeholder="Category Name (বাংলায় লিখুন)"
                   value="<?= htmlspecialchars($editData['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   required>
        </div>

        <?php if ($edit): ?>
            <input type="hidden" name="id" value="<?= (int)$editData['id'] ?>">
        <?php endif; ?>

        <div class="col-md-2">
            <?php if ($edit): ?>
                <button type="submit" name="update" class="btn btn-primary w-100">Update</button>
                <a href="category.php" class="btn btn-secondary w-100 mt-1">Cancel</a>
            <?php else: ?>
                <button type="submit" name="save" class="btn btn-warning w-100">ADD</button>
            <?php endif; ?>
        </div>
    </div>
</form>
</div>

<!-- CATEGORY LIST -->
<div class="card-box">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Category Name</th>
                <th>Created</th>
                <th width="120">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($categories && $categories->num_rows > 0): ?>
                <?php while ($cat = $categories->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($cat['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <a href="?edit=<?= (int)$cat['id'] ?>" class="btn btn-sm btn-primary">✏️</a>
                        <a href="?delete=<?= (int)$cat['id'] ?>" class="btn btn-sm btn-danger"
                           onclick="return confirm('Delete category?')">🗑</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3" class="text-center py-3 text-muted">
                        কোনো ক্যাটাগরি নেই। উপরে নতুন ক্যাটাগরি যোগ করুন।
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include "../pos/footer.php"; ?>