<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/auth_helper.php';
requireLogin();

date_default_timezone_set('Asia/Dhaka');

// Force UTF-8 output so Bangla never becomes question marks.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

if (isset($conn) && $conn instanceof mysqli) {
    @$conn->set_charset('utf8mb4');
    @$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function receiptH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function receiptBn(string $entities): string
{
    return html_entity_decode($entities, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function receiptMoney($amount): string
{
    return receiptBn('&#2547;') . number_format((float)$amount, 2);
}

function receiptTableExists($conn, string $table): bool
{
    if (!$conn || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) return false;

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return (int)($row[0] ?? 0) > 0;
}

function receiptColumnExists($conn, string $table, string $column): bool
{
    if (
        !$conn ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $table) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $column)
    ) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) return false;

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return (int)($row[0] ?? 0) > 0;
}

function receiptFirstColumn($conn, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (receiptColumnExists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function receiptShopInfo($conn, int $shopId): array
{
    $info = [
        'name' => (string)($_SESSION['shop_name'] ?? $_SESSION['business_name'] ?? 'Ricevaly POS'),
        'address' => (string)($_SESSION['shop_address'] ?? $_SESSION['business_address'] ?? ''),
        'phone' => (string)($_SESSION['shop_phone'] ?? $_SESSION['business_phone'] ?? ''),
    ];

    if (!$conn || $shopId <= 0 || !receiptTableExists($conn, 'shops')) {
        return $info;
    }

    $nameCol = receiptFirstColumn($conn, 'shops', ['shop_name', 'business_name', 'name', 'title']);
    $addressCol = receiptFirstColumn($conn, 'shops', ['address', 'shop_address', 'business_address']);
    $phoneCol = receiptFirstColumn($conn, 'shops', ['phone', 'mobile', 'contact_number', 'shop_phone']);

    $select = ['id'];
    if ($nameCol) $select[] = "`$nameCol` AS receipt_name";
    if ($addressCol) $select[] = "`$addressCol` AS receipt_address";
    if ($phoneCol) $select[] = "`$phoneCol` AS receipt_phone";

    $stmt = $conn->prepare('SELECT ' . implode(', ', $select) . ' FROM shops WHERE id = ? LIMIT 1');
    if (!$stmt) return $info;

    $stmt->bind_param('i', $shopId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return $info;

    if (!empty($row['receipt_name'])) $info['name'] = (string)$row['receipt_name'];
    if (!empty($row['receipt_address'])) $info['address'] = (string)$row['receipt_address'];
    if (!empty($row['receipt_phone'])) $info['phone'] = (string)$row['receipt_phone'];

    return $info;
}

$requestedId = trim((string)($_GET['id'] ?? ''));
$invoice = $_SESSION['rv_invoice_data'] ?? null;
$error = '';

if (!is_array($invoice) || $requestedId === '' || !hash_equals((string)($invoice['id'] ?? ''), $requestedId)) {
    $error = 'Invoice data was not found or the invoice link has expired.';
    $invoice = null;
}

$data = is_array($invoice['data'] ?? null) ? $invoice['data'] : [];
$type = (string)($invoice['type'] ?? ($data['type'] ?? 'customer_payment'));
$currentShopId = function_exists('tenantShopId') ? (int)tenantShopId() : (int)($_SESSION['shop_id'] ?? 0);
$currentUserId = function_exists('tenantUserId') ? (int)tenantUserId() : (int)($_SESSION['user_id'] ?? 0);

if ($invoice) {
    $invoiceShopId = (int)($invoice['shop_id'] ?? $data['shop_id'] ?? 0);
    $invoiceUserId = (int)($invoice['user_id'] ?? $data['user_id'] ?? 0);

    if ($currentShopId > 0 && $invoiceShopId > 0 && $currentShopId !== $invoiceShopId) {
        $error = 'You are not authorized to view this invoice.';
        $invoice = null;
    } elseif ($currentUserId > 0 && $invoiceUserId > 0 && $currentUserId !== $invoiceUserId) {
        $error = 'You are not authorized to view this invoice.';
        $invoice = null;
    }
}

$shopId = (int)($invoice['shop_id'] ?? $data['shop_id'] ?? $currentShopId);
$shop = receiptShopInfo($conn ?? null, $shopId);
$isCustomer = $type === 'customer_payment';
$title = $isCustomer
    ? receiptBn('&#2476;&#2494;&#2453;&#2495; &#2438;&#2470;&#2494;&#2527;&#2503;&#2480; &#2480;&#2488;&#2495;&#2470;')
    : 'SUPPLIER PAYMENT RECEIPT';
$partyLabel = $isCustomer ? 'Customer' : 'Supplier';
$partyName = $isCustomer
    ? (string)($data['customer_name'] ?? 'Customer')
    : (string)($data['supplier_name'] ?? 'Supplier');
$referenceNo = $isCustomer
    ? 'RCV-' . str_pad((string)($data['receive_id'] ?? 0), 6, '0', STR_PAD_LEFT)
    : 'PAY-' . str_pad((string)($data['payment_id'] ?? 0), 6, '0', STR_PAD_LEFT);
$paymentDate = (string)($data['payment_date'] ?? date('Y-m-d'));
$createdAt = (string)($invoice['created_at'] ?? date('Y-m-d H:i:s'));
$note = trim((string)($data['note'] ?? ''));
$allocations = is_array($data['allocations'] ?? null) ? $data['allocations'] : [];

$header = [
    'logo_text' => 'LOGO',
    'shop_name_bn' => receiptBn('&#2441;&#2468;&#2509;&#2468;&#2480;&#2494; &#2454;&#2494;&#2470;&#2509;&#2479; &#2477;&#2494;&#2472;&#2509;&#2465;&#2494;&#2480;'),
    'shop_name_en' => '',
    'address_line1' => 'Sector-14, Road-23, House-02, Uttara, Dhaka',
    'mobile' => '01813-870803',
    'hotline' => '01313-570863',
    'email' => 'uttarakhadyovandar@gmail.com',
    'trade_license' => 'TRAD/DNCC/1386/2022',
    'tagline_bn' => receiptBn('&#2438;&#2474;&#2472;&#2494;&#2480; &#2474;&#2459;&#2472;&#2509;&#2470;&#2503;&#2480; &#2472;&#2495;&#2468;&#2509;&#2479; &#2474;&#2509;&#2480;&#2527;&#2507;&#2460;&#2472;&#2496;&#2527; &#2474;&#2467;&#2509;&#2479; &#2453;&#2503;&#2472;&#2494;&#2480; &#2488;&#2503;&#2480;&#2494; &#2464;&#2495;&#2453;&#2494;&#2472;&#2494;'),
];
?>
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= receiptH($title . ' - ' . $referenceNo) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap');

        @page {
            size: 80mm auto;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 80mm;
            min-width: 80mm;
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #000000;
            font-family: "Nirmala UI", "Noto Sans Bengali", "Hind Siliguri", "SolaimanLipi", "Kalpurush", "Siyam Rupali", "Vrinda", Arial, sans-serif;
            font-size: 11px;
            line-height: 1.35;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
        }

        .receipt-shell {
            width: 80mm;
            margin: 0 auto;
            padding: 3mm 4mm 5mm;
            background: #ffffff;
        }


        .receipt-header {
            padding-bottom: 4px;
            border-bottom: 1px dashed #000;
        }

        .header-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 6px;
        }

        .logo-circle {
            width: 15mm;
            height: 15mm;
            border: 1px solid #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 8px;
            font-weight: 700;
            flex: 0 0 14mm;
        }

        .header-center {
            flex: 1;
            text-align: center;
        }






        .brand-name {
            display: block;
            width: 100%;
            margin: 0;
            text-align: center;
            font-size: 18px;
            font-weight: 800;
            line-height: 1.15;
            white-space: nowrap;
            word-break: keep-all;
            overflow: visible;
            letter-spacing: -0.15px;
        }

        .brand-sub {
            margin-top: 1px;
            text-align: center;
            font-size: 9px;
            line-height: 1.25;
        }

        .brand-tagline {
            margin-top: 4px;
            text-align: center;
            font-size: 8px;
            line-height: 1.3;
        }

        .title-bar {
            margin: 6px auto 6px;
            width: 72%;
            background: #111;
            color: #fff;
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 6px;
            letter-spacing: .4px;
        }

        .shop-name {
            margin: 0;
            text-align: center;
            font-size: 18px;
            font-weight: 800;
            line-height: 1.15;
        }

        .shop-meta {
            margin-top: 2px;
            text-align: center;
            font-size: 10px;
            line-height: 1.3;
        }

        .receipt-title {
            margin: 8px 0 6px;
            padding: 5px 3px;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .meta-row,
        .amount-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            padding: 2px 0;
        }

        .meta-row span:first-child,
        .amount-row span:first-child {
            flex: 0 0 38%;
            color: #222;
        }

        .meta-row strong,
        .amount-row strong {
            flex: 1;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .divider {
            margin: 6px 0;
            border-top: 1px dashed #000;
        }

        .received-box {
            margin: 6px 0;
            padding: 7px 5px;
            border: 2px solid #000;
            text-align: center;
        }

        .received-box small {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .received-box b {
            display: block;
            margin-top: 2px;
            font-size: 22px;
            line-height: 1.1;
        }

        .due-highlight {
            font-size: 13px;
        }

        .alloc-title {
            margin: 5px 0 3px;
            font-weight: 800;
        }

        .allocation {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 2px 0;
            border-bottom: 1px dotted #999;
        }

        .note {
            margin-top: 5px;
            padding: 5px;
            border: 1px solid #000;
            overflow-wrap: anywhere;
        }

        .signature-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            margin-top: 15px;
            padding-top: 10px;
        }

        .signature {
            width: 46%;
            border-top: 1px solid #000;
            padding-top: 3px;
            text-align: center;
            font-size: 9px;
        }

        .footer-note {
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px dashed #000;
            text-align: center;
            font-size: 9px;
        }

        .actions {
            width: 80mm;
            margin: 10px auto;
            padding: 0 4mm 10px;
            display: flex;
            gap: 6px;
        }

        .actions button {
            font-family: inherit;
            flex: 1;
            min-height: 36px;
            border: 1px solid #111;
            border-radius: 5px;
            background: #111;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .actions button.secondary {
            background: #fff;
            color: #111;
        }

        .error-box {
            width: 72mm;
            margin: 15mm auto;
            padding: 8mm 5mm;
            border: 1px solid #000;
            text-align: center;
        }

        @media print {
            html,
            body,
            .receipt-shell {
                width: 80mm !important;
                min-width: 80mm !important;
            }

            .receipt-shell {
                padding: 3mm 4mm 5mm !important;
            }

            .actions {
                display: none !important;
            }
        }
    
/* Receipt style refinement */
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;500;600;700;800;900&display=swap');

body, html, .invoice, .receipt-shell, .receipt-wrap {
    font-family: "Noto Sans", Arial, sans-serif !important;
    font-weight: 600;
    letter-spacing: .1px;
}
.invoice, .receipt-shell, .receipt-wrap {
    padding: 4mm 3.5mm 5mm !important;
    border: 0 !important;
    box-shadow: none !important;
}
.shop-title-bn, .shop-title-en, .brand-name, h1, h2, h3 {
    font-weight: 900 !important;
}
.shop-info, .customer-info, .details, .item-name {
    font-weight: 700 !important;
}
table, th, td {
    font-weight: 700;
}
.total, .net, .grand-total, .payable, .due, .amount {
    font-weight: 900 !important;
    font-size: 1.08em;
}
hr, .divider {
    border-top: 1px dashed #000 !important;
}
@media print {
    body { background:#fff !important; padding:0 !important; }
}
</style>
</head>
<body>
<?php if ($error !== ''): ?>
    <div class="error-box">
        <strong>Invoice unavailable</strong><br><br>
        <?= receiptH($error) ?>
    </div>
<?php else: ?>
    <main class="receipt-shell">
        <div class="receipt-header">
            <div class="header-top">
                <div class="logo-circle"><?= receiptH($header['logo_text']) ?></div>
                <div class="header-center">
                    <h1 class="brand-name"><?= receiptH($header['shop_name_bn']) ?></h1>
                    <?php if ($header['shop_name_en'] !== ''): ?>
                        <div class="brand-sub"><?= receiptH($header['shop_name_en']) ?></div>
                    <?php endif; ?>
                    <div class="brand-sub"><?= receiptH($header['address_line1']) ?></div>
                    <div class="brand-sub">Mobile: <?= receiptH($header['mobile']) ?></div>
                    <div class="brand-sub">Hotline: <?= receiptH($header['hotline']) ?></div>
                    <?php if ($header['email'] !== ''): ?>
                        <div class="brand-sub">Email: <?= receiptH($header['email']) ?></div>
                    <?php endif; ?>
                    <?php if ($header['trade_license'] !== ''): ?>
                        <div class="brand-sub"><?= receiptH($header['trade_license']) ?></div>
                    <?php endif; ?>
                </div>
        
            </div>
            <?php if ($header['tagline_bn'] !== ''): ?>
                <div class="brand-tagline"><?= receiptH($header['tagline_bn']) ?></div>
            <?php endif; ?>
        </div>

        <div class="title-bar"><?= receiptH($title) ?></div>

        <div class="meta-row"><span><?= receiptH(receiptBn('&#2480;&#2488;&#2495;&#2470; &#2472;&#2434;')) ?></span><strong><?= receiptH($referenceNo) ?></strong></div>
        <div class="meta-row"><span><?= receiptH(receiptBn('&#2468;&#2494;&#2480;&#2495;&#2454;')) ?></span><strong><?= receiptH(date('d M Y', strtotime($paymentDate))) ?></strong></div>
        <div class="meta-row"><span><?= receiptH(receiptBn('&#2488;&#2478;&#2527;')) ?></span><strong><?= receiptH(date('h:i A', strtotime($createdAt))) ?></strong></div>
        <div class="meta-row"><span><?= receiptH($partyLabel) ?></span><strong><?= receiptH($partyName) ?></strong></div>

        <div class="divider"></div>

        <div class="received-box">
            <small><?= $isCustomer ? receiptH(receiptBn('&#2455;&#2509;&#2480;&#2489;&#2467;&#2453;&#2499;&#2468; &#2463;&#2494;&#2453;&#2494;')) : 'AMOUNT PAID' ?></small>
            <b><?= receiptMoney($data['amount'] ?? 0) ?></b>
        </div>

        <div class="amount-row"><span><?= receiptH(receiptBn('&#2474;&#2503;&#2478;&#2503;&#2472;&#2509;&#2463; &#2478;&#2503;&#2469;&#2465;')) ?></span><strong><?= receiptH($data['payment_method'] ?? '-') ?></strong></div>
        <div class="amount-row"><span><?= receiptH(receiptBn('&#2474;&#2503;&#2478;&#2503;&#2472;&#2509;&#2463; &#2437;&#2509;&#2479;&#2494;&#2488;&#2503;&#2463;')) ?></span><strong><?= receiptH($data['asset_name'] ?? '-') ?></strong></div>

        <?php if ($isCustomer): ?>
            <div class="divider"></div>
            <div class="amount-row"><span><?= receiptH(receiptBn('&#2438;&#2455;&#2503;&#2480; &#2476;&#2494;&#2453;&#2495;')) ?></span><strong><?= receiptMoney($data['previous_due'] ?? 0) ?></strong></div>
            <div class="received-box" style="margin-top:6px;">
                <small><?= receiptH(receiptBn('&#2476;&#2480;&#2509;&#2468;&#2478;&#2494;&#2472; &#2476;&#2494;&#2453;&#2495;')) ?></small>
                <b class="due-highlight"><?= receiptMoney($data['remaining_due'] ?? 0) ?></b>
            </div>
        <?php elseif (!empty($allocations)): ?>
            <div class="divider"></div>
            <div class="alloc-title">Invoice Allocation</div>
            <?php foreach ($allocations as $allocation): ?>
                <div class="allocation">
                    <span>#<?= receiptH($allocation['purchase_id'] ?? '-') ?></span>
                    <strong><?= receiptMoney($allocation['amount'] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($note !== ''): ?>
            <div class="note"><strong>Note:</strong> <?= receiptH($note) ?></div>
        <?php endif; ?>

        <div class="signature-row">
            <div class="signature">Customer Signature</div>
            <div class="signature">Authorized Signature</div>
        </div>

        <div class="footer-note">
            Thank you. This is a computer-generated receipt.<br>
            Printed: <?= receiptH(date('d M Y, h:i A')) ?>
        </div>
    </main>

    <div class="actions">
        <button type="button" onclick="window.print()">Print 80mm</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 450);
        });
    </script>
<?php endif; ?>
</body>
</html>
