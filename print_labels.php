<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);
$shop_id = tenantShopId();

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";
include "header.php";
include "sidebar.php";

$only_stock = isset($_GET['stock']) ? 1 : 0;

$sql = "
    SELECT p.*, c.category_name 
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id AND c.shop_id = p.shop_id
    WHERE p.shop_id = ?
";

if($only_stock){
    $sql .= " AND p.stock > 0";
}

$sql .= " ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $shop_id);
$stmt->execute();
$products = $stmt->get_result();
?>

<style>

/* ===== SAME MAIN STYLE (MATCH PRODUCTS PAGE) ===== */
.main{
    flex:1;
    padding:20px;
    background:#f8fafc;
}

/* ===== SAME CARD BOX ===== */
.card-box{
    background:#fff;
    padding:15px;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,0.05);
    margin-bottom:15px;
}

/* ===== HEADER BUTTON STYLE ===== */
.top-action{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}

/* ===== GRID (same feel like table cards) ===== */
.grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
    gap:12px;
}

/* ===== ITEM CARD (MATCH PRODUCT BOX STYLE) ===== */
.item-box{
    display:flex;
    gap:10px;
    align-items:center;
    padding:10px;
    border:1px solid #eee;
    border-radius:10px;
    cursor:pointer;
    transition:0.2s;
    background:#fff;
}

.item-box:hover{
    background:#f9fafb;
}

.item-box.selected{
    border:1px solid #22c55e;
    background:#f0fdf4;
}

/* IMAGE SAME STYLE */
.item-box img{
    width:45px;
    height:45px;
    object-fit:cover;
    border-radius:8px;
}

/* SKU SAME STYLE */
.badge-sku{
    background:#0f172a;
    color:#fff;
    padding:4px 8px;
    border-radius:20px;
    font-size:11px;
}

</style>

<div class="main">

<h3>🖨 Print Labels (ERP STYLE MATCH)</h3>

<a href="print_labels.php" class="btn btn-dark mb-2">
↩ Back
</a>

<!-- ACTION BOX (LIKE PRODUCT PAGE CARD-BOX) -->
<div class="card-box">

<div class="top-action">

<div>
    <b>Select Products for Print</b><br>
    <small class="text-muted">Choose items to generate barcode labels</small>
</div>

<div>
    <span class="badge bg-dark">
        Selected: <span id="counter">0</span>
    </span>
</div>

</div>

<!-- ACTION BUTTONS -->
<div class="mb-2">
<button type="button" class="btn btn-success btn-sm" onclick="selectAll()">Select All</button>
<button type="button" class="btn btn-warning btn-sm" onclick="unselectAll()">Clear</button>
<a href="?stock=1" class="btn btn-info btn-sm">Stock Only</a>
<a href="print_labels.php" class="btn btn-secondary btn-sm">Reset</a>
</div>

</div>

<!-- FORM BOX -->
<div class="card-box">

<form method="POST" action="print_preview.php">

<div class="grid">

<?php while($p=$products->fetch_assoc()){ ?>

<div class="item-box" onclick="toggle(this)">

<input type="checkbox" name="product_ids[]" value="<?= $p['id'] ?>">

<?php if($p['image']){ ?>
<img src="uploads/products/<?= $p['image'] ?>">
<?php } ?>

<div style="flex:1">
    <div><b><?= htmlspecialchars($p['product_name']) ?></b></div>
    <small><?= $p['category_name'] ?></small><br>
    <span class="badge-sku"><?= $p['sku'] ?></span>
</div>

</div>

<?php } ?>

</div>

<button class="btn btn-dark w-100 mt-3">
➡ Print Preview
</button>

</form>

</div>

</div>

<script>

function toggle(el){
    let cb = el.querySelector("input");
    cb.checked = !cb.checked;
    el.classList.toggle("selected");
    update();
}

function update(){
    let count = document.querySelectorAll("input[type=checkbox]:checked").length;
    document.getElementById("counter").innerText = count;
}

function selectAll(){
    document.querySelectorAll(".item-box").forEach(el=>{
        let cb = el.querySelector("input");
        cb.checked = true;
        el.classList.add("selected");
    });
    update();
}

function unselectAll(){
    document.querySelectorAll(".item-box").forEach(el=>{
        let cb = el.querySelector("input");
        cb.checked = false;
        el.classList.remove("selected");
    });
    update();
}

</script>

<?php include "footer.php"; ?>