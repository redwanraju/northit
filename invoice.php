<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "auth.php";
requireLogin();
include "../config/db.php";

function e($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($value){
    return number_format((float)($value ?? 0), 0);
}

function qtyFormat($value){
    $value = (float)($value ?? 0);
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function getOne($conn, $sql, $types = '', $params = []){
    $stmt = $conn->prepare($sql);
    if(!$stmt){
        return null;
    }

    if($types !== '' && !empty($params)){
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    return $res ? $res->fetch_assoc() : null;
}

function getRows($conn, $sql, $types = '', $params = []){
    $stmt = $conn->prepare($sql);
    if(!$stmt){
        return [];
    }

    if($types !== '' && !empty($params)){
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];

    if($res){
        while($row = $res->fetch_assoc()){
            $rows[] = $row;
        }
    }

    return $rows;
}

/* =========================================================
   LOAD ORDER
========================================================= */

$shop_id    = tenantShopId();
$order_id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$invoice_no = trim($_GET['invoice'] ?? '');

$order = null;

if($order_id > 0){
    $order = getOne($conn, "
        SELECT
            o.*,
            c.shop_name AS customer_shop_name,
            c.name AS customer_name,
            c.phone AS customer_phone,
            c.address AS customer_address,
            c.area_group,
            c.customer_group,
            c.due_amount AS customer_current_due,
            sr.sr_name AS sale_by_name,
            u.name AS entry_by_name,
            sh.shop_name AS company_name,
            sh.phone AS company_phone,
            sh.address AS company_address
        FROM orders o
        LEFT JOIN customers c
            ON c.id = o.customer_id
           AND c.shop_id = o.shop_id
        LEFT JOIN sales_representatives sr
            ON sr.id = o.sr_id
           AND sr.shop_id = o.shop_id
        LEFT JOIN users u
            ON u.id = o.user_id
           AND u.shop_id = o.shop_id
        LEFT JOIN shops sh
            ON sh.id = o.shop_id
        WHERE o.id = ?
          AND o.shop_id = ?
          AND o.order_source = 'pos'
        LIMIT 1
    ", "ii", [$order_id, $shop_id]);

} elseif($invoice_no !== ''){
    $order = getOne($conn, "
        SELECT
            o.*,
            c.shop_name AS customer_shop_name,
            c.name AS customer_name,
            c.phone AS customer_phone,
            c.address AS customer_address,
            c.area_group,
            c.customer_group,
            c.due_amount AS customer_current_due,
            sr.sr_name AS sale_by_name,
            u.name AS entry_by_name,
            sh.shop_name AS company_name,
            sh.phone AS company_phone,
            sh.address AS company_address
        FROM orders o
        LEFT JOIN customers c
            ON c.id = o.customer_id
           AND c.shop_id = o.shop_id
        LEFT JOIN sales_representatives sr
            ON sr.id = o.sr_id
           AND sr.shop_id = o.shop_id
        LEFT JOIN users u
            ON u.id = o.user_id
           AND u.shop_id = o.shop_id
        LEFT JOIN shops sh
            ON sh.id = o.shop_id
        WHERE o.invoice_no = ?
          AND o.shop_id = ?
          AND o.order_source = 'pos'
        LIMIT 1
    ", "si", [$invoice_no, $shop_id]);
}

if(!$order){
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Invoice Not Found</title>
    </head>
    <body style="font-family:Arial,sans-serif;padding:30px">
        <h3>Invoice not found</h3>
        <p>
            Use:
            <b>invoice.php?id=ORDER_ID</b>
            or
            <b>invoice.php?invoice=INVOICE_NO</b>
        </p>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
   LOAD ITEMS
========================================================= */

$order_id = (int)$order['id'];

$items = getRows($conn, "
    SELECT
        oi.*,
        p.product_name,
        p.unit,
        p.barcode
    FROM order_items oi
    INNER JOIN orders o
        ON o.id = oi.order_id
    LEFT JOIN products p
        ON p.id = oi.product_id
       AND p.shop_id = o.shop_id
    WHERE oi.order_id = ?
      AND o.shop_id = ?
      AND o.order_source = 'pos'
    ORDER BY oi.id ASC
", "ii", [$order_id, $shop_id]);

/* =========================================================
   PREVIOUS DUE
========================================================= */

$customer_id = (int)($order['customer_id'] ?? 0);
$previous_due = 0.0;

$has_balance_snapshot =
    array_key_exists('previous_due', $order)
    && $order['previous_due'] !== null;

if($has_balance_snapshot){
    $previous_due = max(0, (float)$order['previous_due']);

} elseif($customer_id > 0){
    $prevOrder = getOne($conn, "
        SELECT COALESCE(SUM(due_amount), 0) AS total_due
        FROM orders
        WHERE customer_id = ?
          AND shop_id = ?
          AND order_source = 'pos'
          AND id < ?
          AND order_status != 'cancelled'
    ", "iii", [$customer_id, (int)$order['shop_id'], $order_id]);

    $prevReceipt = getOne($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total_received
        FROM customer_due_receives
        WHERE customer_id = ?
          AND shop_id = ?
          AND created_at < ?
    ", "iis", [
        $customer_id,
        (int)$order['shop_id'],
        $order['created_at']
    ]);

    $prevAdvance = getOne($conn, "
        SELECT COALESCE(SUM(p.amount), 0) AS total_advance
        FROM payments p
        INNER JOIN customers c
            ON c.id = p.customer_id
        WHERE p.customer_id = ?
          AND c.shop_id = ?
          AND p.type = 'advance_payment'
          AND p.created_at < ?
    ", "iis", [
        $customer_id,
        $shop_id,
        $order['created_at']
    ]);

    $previous_due =
          (float)($prevOrder['total_due'] ?? 0)
        - (float)($prevReceipt['total_received'] ?? 0)
        - (float)($prevAdvance['total_advance'] ?? 0);

    if($previous_due < 0){
        $previous_due = 0;
    }
}

/* =========================================================
   CALCULATIONS
========================================================= */

$item_subtotal = 0.0;

foreach($items as $item){
    $item_subtotal += (float)($item['subtotal'] ?? 0);
}

$subtotal_amount = (float)($order['subtotal'] ?? 0);

if($subtotal_amount <= 0){
    $subtotal_amount = $item_subtotal;
}

$discount_amount = (float)($order['discount'] ?? 0);
$delivery_cost   = (float)($order['delivery_cost'] ?? 0);
$group_unit_cost = (float)($order['group_unit_cost'] ?? 0);
$tax_amount      = (float)($order['tax'] ?? 0);
$paid_amount     = (float)($order['paid_amount'] ?? 0);

$invoice_total =
      $subtotal_amount
    + $delivery_cost
    + $group_unit_cost
    + $tax_amount
    - $discount_amount;

if($invoice_total < 0){
    $invoice_total = 0;
}

$current_due = $invoice_total - $paid_amount;

if($current_due < 0){
    $current_due = 0;
}

$has_closing_snapshot =
    array_key_exists('closing_due', $order)
    && $order['closing_due'] !== null;

if($customer_id > 0){
    if($has_closing_snapshot){
        $closing_due = max(0, (float)$order['closing_due']);
    } else {
        $closing_due = max(
            0,
            $previous_due + $invoice_total - $paid_amount
        );
    }
} else {
    $previous_due = 0.0;
    $closing_due = max(0, $invoice_total - $paid_amount);
}

/* =========================================================
   DISPLAY VALUES
========================================================= */

$createdAt = !empty($order['created_at'])
    ? strtotime($order['created_at'])
    : time();

$customerDisplayName =
    trim($order['customer_shop_name'] ?? '')
    ?: trim($order['customer_name'] ?? '')
    ?: 'Walk-in Customer';

$salesBy =
    trim($order['sale_by_name'] ?? '')
    ?: trim($order['entry_by_name'] ?? '')
    ?: 'Admin';

$paymentMethod = trim($order['payment_method'] ?? '');

if($paymentMethod === ''){
    $paymentMethod = 'Cash';
}

$paymentMethod = ucwords(str_replace(['_', '-'], ' ', $paymentMethod));

// Invoice number - keep only the last part (e.g., EC80A1 from RV-20260809-EC80A1)
$fullInvoiceNo = $order['invoice_no'];
// Try to get the part after the last hyphen
$parts = explode('-', $fullInvoiceNo);
$invoiceDisplay = end($parts); // Gets the last part after hyphen

// If there's no hyphen or last part is empty, show the full invoice number
if(empty($invoiceDisplay) || $invoiceDisplay === $fullInvoiceNo){
    $invoiceDisplay = $fullInvoiceNo;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Invoice - <?= e($order['invoice_no']) ?></title>

<style>
    :root {
        --receipt-width: 85mm;
        --text: #000;
        --muted: #444;
        --paper: #fff;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html,
    body {
        min-height: 100%;
    }

    body {
        background: #ececec;
        color: var(--text);
        font-family: Arial, 'Noto Sans Bengali', 'SolaimanLipi', sans-serif;
        padding: 0;
        margin: 0;
        -webkit-font-smoothing: antialiased;
        font-weight: 800;
        text-rendering: geometricPrecision;
    }

    button,
    a {
        font: inherit;
    }

    .no-print {
        display: flex;
        justify-content: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px;
        padding: 10px;
    }

    .no-print button,
    .no-print a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 9px 16px;
        border: 0;
        border-radius: 7px;
        background: #111;
        color: #fff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
    }

    .receipt-wrap {
        width: 85mm;
        max-width: 85mm;
        min-width: 85mm;
        min-height: auto;
        height: auto;
        margin: 0 auto;
        padding: 1mm 1.5mm 2mm;
        box-shadow: none;
        background: #fff;
    }

    .dashed-line {
        border: 0;
        border-top: 1px dashed #000;
        margin: 1.5mm 0;
    }

    /* ===== HEADER ===== */
    .receipt-header {
        text-align: center;
        padding: 0 0 1mm;
        border-bottom: 1px dashed #000;
        margin-bottom: 1mm;
    }

    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 11px;
        font-weight: 1000;
        margin-bottom: 1mm;
    }

    .header-main {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 2mm;
        margin-bottom: 1mm;
    }

    .logo-wrap {
        flex: 0 0 13mm;
        width: 13mm;
        height: 13mm;
    }

    .logo-wrap img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: 50%;
        filter: grayscale(100%);
    }

    .logo-placeholder {
        width: 13mm;
        height: 13mm;
        border-radius: 50%;
        border: 2px solid #000;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 8px;
        font-weight: 1000;
        background: #f0f0f0;
    }

    .header-title {
        flex: 1;
        text-align: center;
    }

    .shop-name-bn {
        font-size: 27px;
        line-height: 1.1;
        font-weight: 1200 !important;
        letter-spacing: 1px;
        text-shadow: 0.5px 0.5px 0 rgba(0,0,0,0.1);
        font-stretch: expanded;
    }

    .header-contact {
        font-size: 12px;
        line-height: 1.4;
        font-weight: 1000;
        margin-bottom: 0.5mm;
    }

    .header-contact div {
        margin-bottom: 0.2mm;
    }

    .header-slogan {
        font-size: 11px;
        line-height: 1.3;
        font-weight: 1000;
        padding: 0.5mm 0 0.3mm;
        overflow-wrap: anywhere;
    }

    /* ===== INVOICE META (CENTERED & BIGGER) ===== */
    .invoice-meta {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        padding: 1.5mm 0 1.5mm;
    }

    .sales-invoice-title{
        display:inline-block;
        background:#000;
        color:#fff;
        font-size:18px;
        font-weight:1000;
        padding:2px 7px;
        line-height:1.1;
        font-family:Arial, sans-serif;
        letter-spacing:0.3px;
    }

    .invoice-left {
        font-size: 18px;
        font-weight: 1000;
        color: #000;
        white-space: nowrap;
    }

    .invoice-number {
        font-size: 18px;
        font-weight: 1000;
        overflow-wrap: anywhere;
        color: #000;
        white-space: nowrap;
    }

    .invoice-right {
        font-size: 18px;
        font-weight: 1000;
        color: #000;
        white-space: nowrap;
    }

    /* ===== BILL TO (Dotted underline style) ===== */
    .bill-section {
        padding: 0;
        margin-bottom: 0.5mm;
    }

    .bill-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        line-height: 1.3;
        font-weight: 1000;
    }

    .bill-table td {
        padding: 1mm 0.5mm;
        width: 50%;
        vertical-align: middle;
        border-bottom: 1px dashed #000;
        color: #000;
    }

    .bill-table tr:last-child td {
        border-bottom: none;
    }

    .bill-table .bill-label {
        color: #000;
        font-weight: 900;
        margin-right: 0.5mm;
        font-size: 12px;
    }

    .bill-table .bill-value {
        color: #000;
        font-weight: 900;
        overflow-wrap: anywhere;
        font-size: 12px;
    }

    /* ITEMS */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin: 0;
    }

    .items-table col:nth-child(1) { width: 38%; }
    .items-table col:nth-child(2) { width: 15%; }
    .items-table col:nth-child(3) { width: 22%; }
    .items-table col:nth-child(4) { width: 25%; }

    .items-table th {
        padding: 1.2mm 0 1.2mm;
        font-size: 12px;
        line-height: 1.2;
        font-weight: 1000;
        text-align: left;
        vertical-align: bottom;
        border-bottom: 1.5px solid #000;
        color: #000;
    }

    .items-table td {
        padding: 1.2mm 0 1.5mm;
        font-size: 12px;
        line-height: 1.3;
        font-weight: 1000;
        vertical-align: middle;
        border-bottom: 0.5px dotted #aaa;
        color: #000;
    }

    .items-table tr:last-child td {
        border-bottom: none;
    }

    .items-table th:nth-child(2),
    .items-table td:nth-child(2) {
        text-align: center;
    }

    .items-table th:nth-child(3),
    .items-table th:nth-child(4),
    .items-table td:nth-child(3),
    .items-table td:nth-child(4) {
        text-align: right;
    }

    .product-name {
        font-size: 12px;
        line-height: 1.3;
        font-weight: 1000;
        overflow-wrap: anywhere;
        white-space: normal;
        color: #000;
    }

    .unit-label {
        font-size: 11px;
        color: #000;
        font-weight: 900;
        margin-left: 0.5mm;
    }

    .empty-row {
        text-align: center !important;
        color: #000;
        padding: 2.5mm 0 !important;
        font-weight: 1000;
    }

    .item-count {
        font-size: 11px;
        color: #000;
        font-weight: 900;
        text-align: right;
        padding: 0.5mm 0 0.3mm;
    }

    /* TOTALS */
    .totals {
        border-top: 1px dashed #000;
        padding-top: 1.5mm;
        margin-top: 1mm;
    }

    .amount-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 2mm;
        padding: 0.5mm 0;
        font-size: 13px;
        line-height: 1.3;
        font-weight: 1000;
        color: #000;
    }

    .amount-row .amount {
        flex: 0 0 auto;
        text-align: right;
        white-space: nowrap;
        font-weight: 1000;
        color: #000;
    }

    .net-row {
        margin-top: 0.5mm;
        padding: 1mm 0;
        font-size: 16px;
        font-weight: 1000;
        border-top: 1px dashed #000;
        border-bottom: 1px dashed #000;
        color: #000;
    }

    .payment-block {
        padding: 1mm 0;
    }

    .payment-block .amount-row {
        font-size: 13px;
        color: #000;
    }

    .due-row {
        padding: 1mm 0;
        font-size: 13px;
        font-weight: 1000;
        color: #000;
    }

    .previous-due-row {
        padding: 1mm 0;
        font-size: 13px;
        font-weight: 1000;
        color: #000;
    }

    .total-due-row {
        padding: 1mm 0;
        font-size: 14px;
        font-weight: 1000;
        color: #000;
    }

    /* SIGNATURE */
    .signature-line {
        margin-top: 3mm;
        padding-top: 1.5mm;
        border-top: 1px dashed #000;
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        font-size: 12px;
        font-weight: 1000;
        color: #000;
    }

    /* FOOTER */
    .receipt-footer {
        text-align: center;
        padding-top: 2mm;
    }

    .thanks {
        font-size: 14px;
        line-height: 1.3;
        font-weight: 1000;
        color: #000;
    }

    .developer {
        margin-top: 1mm;
        color: #000;
        font-size: 10px;
        line-height: 1.2;
        font-weight: 900;
    }

    @media (max-width: 400px) {
        .receipt-wrap {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-shadow: none;
            padding: 1mm 1mm 2mm;
        }
    }

    /* ============================================================
       PRINT STYLES
       ============================================================ */
    @media print {
        @page {
            size: 85mm auto;
            margin: 0;
        }

        html,
        body {
            width: 85mm;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000 !important;
        }

        .no-print {
            display: none !important;
        }

        .receipt-wrap {
            width: 85mm;
            max-width: 85mm;
            min-width: 85mm;
            min-height: auto;
            height: auto;
            margin: 0;
            padding: 0.5mm 1mm 1.5mm;
            box-shadow: none;
            background: #fff;
        }

        * {
            color: #000 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }

        .bill-table td {
            color: #000 !important;
            border-bottom: 1px dashed #000 !important;
        }

        .bill-table .bill-label {
            color: #000 !important;
            font-weight: 900 !important;
        }

        .bill-table .bill-value {
            color: #000 !important;
            font-weight: 900 !important;
        }

        .items-table th,
        .items-table td,
        .items-table .product-name,
        .items-table .unit-label {
            color: #000 !important;
            border-color: #000 !important;
        }

        .amount-row,
        .amount-row .amount,
        .net-row,
        .payment-block .amount-row,
        .due-row,
        .previous-due-row,
        .total-due-row,
        .signature-line,
        .thanks,
        .developer {
            color: #000 !important;
        }

        .dashed-line,
        .receipt-header,
        .totals,
        .net-row,
        .signature-line {
            border-color: #000 !important;
        }

        .bill-table td {
            border-bottom: 1px dashed #000 !important;
        }

        .items-table td {
            border-bottom: 0.5px dotted #000 !important;
        }
    }
</style>
</head>

<body>

<div class="no-print">
    <button type="button" onclick="window.print()">🖨️ Print Invoice</button>
    <a href="invoice_pdf.php?id=<?= (int)$order_id ?>" target="_blank" rel="noopener">
        📄 PDF Invoice
    </a>
</div>

<main class="receipt-wrap">

    <header class="receipt-header">

        <div class="header-main">
            <div class="logo-wrap">
                <img src="https://ricevaly.bd/pos/assets/images/dokanlogo.png" alt="Logo" onerror="this.style.display='none'; this.parentNode.querySelector('.logo-placeholder').style.display='flex';">
                <div class="logo-placeholder">LOGO</div>
            </div>
            <div class="header-title">
                <div class="shop-name-bn" style="font-weight: 1200 !important; font-size: 27px;">উত্তরা খাদ্য ভাণ্ডার</div>
            </div>
        </div>

        <div class="header-contact">
            <div>সেক্টর-১৪, রোড-২৩, বাসা-০২, উত্তরা ঢাকা,১২৩০।</div>
            <div>Mobile: 01813-370808, 01799-189105</div>
        </div>
    </header>

    <!-- ===== INVOICE META: Sales Invoice & Invoice No Centered and Larger ===== -->
    <section class="invoice-meta">
        <div class="invoice-left"><span class="sales-invoice-title">Sales Invoice</span> -</div>
        <div class="invoice-right"><?= e($invoiceDisplay) ?></div>
    </section>

    <hr class="dashed-line">

    <!-- ===== BILL TO (Dotted underline style - no border) ===== -->
    <section class="bill-section">
        <table class="bill-table">
            <tr>
                <td>
                    <span class="bill-label">Shop:</span>
                    <span class="bill-value"><?= e(trim($order['customer_shop_name'] ?? '') ?: trim($order['customer_name'] ?? '')) ?></span>
                </td>
                <td>
                    <span class="bill-label">Sales By:</span>
                    <span class="bill-value"><?= e($salesBy) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="bill-label">Customer:</span>
                    <span class="bill-value"><?= e(trim($order['customer_name'] ?? '')) ?></span>
                </td>
                <td>
                    <span class="bill-label">Category:</span>
                    <span class="bill-value"><?= e(trim($order['customer_group'] ?? '')) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="bill-label">Address:</span>
                    <span class="bill-value"><?= e(trim($order['customer_address'] ?? '')) ?></span>
                </td>
                <td>
                    <span class="bill-label">Area:</span>
                    <span class="bill-value"><?= e(trim($order['area_group'] ?? '')) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="bill-label">Cell:</span>
                    <span class="bill-value"><?= e(trim($order['customer_phone'] ?? '')) ?></span>
                </td>
                <td>
                    <span class="bill-label">Date:</span>
                    <span class="bill-value"><?= e(date('d M Y', $createdAt)) ?></span>
                </td>
            </tr>
        </table>
    </section>

    <hr class="dashed-line">

    <table class="items-table">
        <colgroup>
            <col>
            <col>
            <col>
            <col>
        </colgroup>

        <thead>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Total</th>
            </tr>
        </thead>

        <tbody>
        <?php if(empty($items)){ ?>
            <tr>
                <td colspan="4" class="empty-row">No items</td>
            </tr>
        <?php } else { ?>
            <?php foreach($items as $item){ 
                $productName = trim($item['product_name'] ?? '') ?: 'Unknown';
                $unit = trim($item['unit'] ?? '');
            ?>
                <tr>
                    <td>
                        <span class="product-name"><?= e($productName) ?> <?php if($unit !== ''){ ?><?php } ?></span>
                    </td>

                    <td>
                        <?= e(qtyFormat($item['qty'] ?? 0)) ?>
                        <?php if($unit !== ''){ ?>
                            <span class="unit-label"><?= e($unit) ?></span>
                        <?php } ?>
                    </td>

                    <td>
                        &#2547;<?= e(money($item['price'] ?? 0)) ?>
                    </td>

                    <td>
                        &#2547;<?= e(money($item['subtotal'] ?? 0)) ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } ?>
        </tbody>
    </table>

    <?php if(!empty($items)){ ?>
        <div class="item-count">Total Items: <?= count($items) ?></div>
    <?php } ?>


    <section class="totals">

        <div class="amount-row">
            <span>Total Sale</span>
            <span class="amount">&#2547;<?= e(money($subtotal_amount)) ?></span>
        </div>

        <?php if($delivery_cost > 0){ ?>
            <div class="amount-row">
                <span>Delivery Charge</span>
                <span class="amount">&#2547;<?= e(money($delivery_cost)) ?></span>
            </div>
        <?php } ?>

        <?php if($group_unit_cost > 0){ ?>
            <div class="amount-row">
                <span>Other Charge</span>
                <span class="amount">&#2547;<?= e(money($group_unit_cost)) ?></span>
            </div>
        <?php } ?>

        <?php if($tax_amount > 0){ ?>
            <div class="amount-row">
                <span>Tax</span>
                <span class="amount">&#2547;<?= e(money($tax_amount)) ?></span>
            </div>
        <?php } ?>

        <?php if($discount_amount > 0){ ?>
            <div class="amount-row">
                <span>Discount</span>
                <span class="amount">- &#2547;<?= e(money($discount_amount)) ?></span>
            </div>
        <?php } ?>

        <div class="amount-row net-row">
            <span>Net Payable</span>
            <span class="amount">&#2547;<?= e(money($invoice_total)) ?></span>
        </div>

        <!-- Payment Block with Dashed Lines Added Below Each Row -->
        <div class="payment-block">
            <div class="amount-row">
                <span>Total Paid</span>
                <span class="amount">&#2547;<?= e(money($paid_amount)) ?></span>
            </div>
            <div class="dashed-line"></div>

            <div class="amount-row">
                <span>Payment Methods</span>
                <span class="amount"><?= e($paymentMethod) ?></span>
            </div>
            <div class="dashed-line"></div>

            <div class="amount-row due-row">
                <span>Due</span>
                <span class="amount">&#2547;<?= e(money($current_due)) ?></span>
            </div>
            <div class="dashed-line"></div>

            <?php if($previous_due > 0){ ?>
                <div class="amount-row previous-due-row">
                    <span>Previous Due</span>
                    <span class="amount">&#2547;<?= e(money($previous_due)) ?></span>
                </div>
                <div class="dashed-line"></div>

                <div class="amount-row total-due-row">
                    <span>Total Due</span>
                    <span class="amount">&#2547;<?= e(money($closing_due)) ?></span>
                </div>
              
            <?php } ?>
        </div>

    </section>

    <div class="signature-line">
        <span>Customer Signature</span>
        <span>_____________</span>
    </div>

    <footer class="receipt-footer">
        <div class="thanks">Thank You for your purchase</div>
        <div class="developer">Developed by North It</div>
    </footer>

</main>

</body>
</html>