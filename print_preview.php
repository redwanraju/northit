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

$product_ids = $_POST['product_ids'] ?? [];

if(empty($product_ids)){
    echo "<div class='p-3'>No products selected</div>";
    exit;
}

$product_ids = array_values(array_filter(array_map('intval', $product_ids), static fn($id) => $id > 0));
if (!$product_ids) {
    echo "<div class='p-3'>No valid products selected</div>";
    exit;
}
$placeholders = implode(',', array_fill(0, count($product_ids), '?'));
$types = str_repeat('i', count($product_ids) + 1);
$params = array_merge([$shop_id], $product_ids);
$stmt = $conn->prepare("SELECT * FROM products WHERE shop_id = ? AND id IN ($placeholders)");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result();
?>

<!-- BOOTSTRAP 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

/* ===== MAIN WRAP ===== */
.main{
    margin-left:250px;
    padding:15px;
    background:#f8fafc;
}

/* ===== HEADER ===== */
.page-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
    padding-bottom:10px;
    border-bottom:1px solid #ddd;
}

.page-head h5{
    margin:0;
}

/* ===== PRINT AREA ===== */
.print-area{
    width:210mm;
    margin:0 auto;
    background:#fff;
    padding:8mm;
}

/* ===== GRID (MATCH print_labels STYLE) ===== */
.labels{
    display:grid;
    grid-template-columns:repeat(5, 1fr);
    gap:3mm;
}

/* ===== LABEL ===== */
.label{
    border:1px solid #ddd;
    padding:4px;
    height:34mm;

    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;

    font-size:9px;
    text-align:center;
    overflow:hidden;
}

/* IMAGE */
.label img.product{
    width:26px;
    height:26px;
    object-fit:cover;
    border-radius:4px;
}

/* TEXT */
.name{
    font-weight:600;
    font-size:9px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    width:100%;
}

.sku{
    font-size:8px;
    color:#555;
}

.price{
    font-size:9px;
    font-weight:700;
}

/* BARCODE */
.barcode{
    width:100%;
    height:22px;
    object-fit:contain;
    margin-top:2px;
}

/* ===== PRINT FIX ===== */
@media print{

    body *{
        visibility:hidden;
    }

    .print-area,
    .print-area *{
        visibility:visible;
    }

    .print-area{
        position:absolute;
        left:0;
        top:0;
        width:100%;
        padding:5mm;
    }

    .page-head{
        display:none !important;
    }

    @page{
        size:A4;
        margin:5mm;
    }
}

</style>

<div class="main">

<!-- HEADER -->
<div class="page-head">
    <h5>🖨 Print Preview (A4 Labels)</h5>

    <button onclick="window.print()" class="btn btn-dark btn-sm">
        Print Now
    </button>
</div>

<!-- PRINT AREA -->
<div class="print-area">

<div class="labels">

<?php while($p=$products->fetch_assoc()){ ?>

<div class="label">

    <!-- IMAGE -->
    <?php if(!empty($p['image'])){ ?>
        <img class="product"
             src="uploads/products/<?= $p['image'] ?>">
    <?php } ?>

    <!-- NAME -->
    <div class="name">
        <?= htmlspecialchars($p['product_name']) ?>
    </div>

    <!-- SKU -->
    <div class="sku">
        <?= $p['sku'] ?>
    </div>

    <!-- PRICE -->
    <div class="price">
        ৳<?= $p['sale_price'] ?>
    </div>

    <!-- BARCODE -->
    <img class="barcode"
         src="barcode_image.php?code=<?= urlencode($p['barcode']) ?>">

</div>

<?php } ?>

</div>

</div>

</div>

<?php include "footer.php"; ?>