<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);


require_once "permission.php";
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../config/db.php";

$shop_id = tenantShopId();

function ensure_customer_due_receive_table_for_ledger($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS customer_due_receives (
            id INT(11) NOT NULL AUTO_INCREMENT,
            shop_id INT(11) NOT NULL,
            customer_id INT(11) NOT NULL,
            receive_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) DEFAULT 'cash',
            note TEXT NULL,
            created_by INT(11) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_shop_id (shop_id),
            KEY idx_customer_id (customer_id),
            KEY idx_receive_date (receive_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function money($amount) {
    return number_format((float)$amount, 2);
}

function h($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function transaction_timestamp($date, $createdAt = '') {
    $date = trim((string)$date);
    $createdAt = trim((string)$createdAt);

    if ($date === '') {
        return $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s');
    }

    $time = '00:00:00';
    if ($createdAt !== '' && strtotime($createdAt) !== false) {
        $time = date('H:i:s', strtotime($createdAt));
    }

    return $date . ' ' . $time;
}

ensure_customer_due_receive_table_for_ledger($conn);

$customer_id = (int)($_GET['customer_id'] ?? $_GET['id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}
if ($from !== '' && $to !== '' && $from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$customer = null;
$ledgerRows = [];
$allTransactions = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
$closingBalance = 0.0;
$periodSalesDue = 0.0;
$periodReceived = 0.0;
$openingCarry = 0.0;
$liveCurrentDue = 0.0;
$balanceAdjustment = 0.0;

if ($customer_id > 0) {
    $stmt = $conn->prepare("
        SELECT c.*, sr.sr_name
        FROM customers c
        LEFT JOIN sales_representatives sr ON c.sr_id = sr.id AND sr.shop_id = c.shop_id
        WHERE c.id = ? AND c.shop_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $customer_id, $shop_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($customer) {
        $liveCurrentDue = max(0, (float)($customer['due_amount'] ?? 0));
        $customerCreatedAt = !empty($customer['created_at'])
            ? date('Y-m-d H:i:s', strtotime($customer['created_at']))
            : date('Y-m-d 00:00:00');

        /* Total recorded sales due */
        $stmtSalesTotal = $conn->prepare("
            SELECT COALESCE(SUM(CASE
                WHEN order_status <> 'cancelled' THEN GREATEST(COALESCE(due_amount, 0), 0)
                ELSE 0
            END), 0) AS total_sales_due
            FROM orders
            WHERE shop_id = ? AND customer_id = ?
              AND order_source = 'pos'
        ");
        $stmtSalesTotal->bind_param("ii", $shop_id, $customer_id);
        $stmtSalesTotal->execute();
        $salesTotalAll = (float)($stmtSalesTotal->get_result()->fetch_assoc()['total_sales_due'] ?? 0);
        $stmtSalesTotal->close();

        /* Total recorded due collections */
        $stmtReceiveTotal = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_received
            FROM customer_due_receives
            WHERE shop_id = ? AND customer_id = ?
        ");
        $stmtReceiveTotal->bind_param("ii", $shop_id, $customer_id);
        $stmtReceiveTotal->execute();
        $receivedTotalAll = (float)($stmtReceiveTotal->get_result()->fetch_assoc()['total_received'] ?? 0);
        $stmtReceiveTotal->close();

        /*
         * due_amount is the live source of truth.
         * This balancing row represents imported/legacy due or any historical
         * amount that predates the available order/collection records.
         */
        $balanceAdjustment = $liveCurrentDue - ($salesTotalAll - $receivedTotalAll);

        if (abs($balanceAdjustment) >= 0.005) {
            $allTransactions[] = [
                'datetime' => $customerCreatedAt,
                'sort_order' => 0,
                'type' => 'Balance B/F',
                'details' => 'Initial / legacy balance carried forward',
                'debit' => $balanceAdjustment > 0 ? $balanceAdjustment : 0,
                'credit' => $balanceAdjustment < 0 ? abs($balanceAdjustment) : 0,
                'payment_method' => '',
                'note' => 'Reconciled with customer current due',
                'order_id' => 0,
                'invoice_no' => ''
            ];
        }

        /* Sales invoices: only the unpaid portion affects customer due */
        $stmtOrders = $conn->prepare("
            SELECT id, invoice_no, total_amount, paid_amount, due_amount,
                   payment_method, created_at
            FROM orders
            WHERE shop_id = ?
              AND customer_id = ?
              AND order_source = 'pos'
              AND order_status <> 'cancelled'
            ORDER BY created_at ASC, id ASC
        ");
        $stmtOrders->bind_param("ii", $shop_id, $customer_id);
        $stmtOrders->execute();
        $ordersResult = $stmtOrders->get_result();

        while ($order = $ordersResult->fetch_assoc()) {
            $invoiceTotal = max(0, (float)($order['total_amount'] ?? 0));
            $invoiceDue = max(0, (float)($order['due_amount'] ?? 0));
            $invoiceCredit = max(0, $invoiceTotal - $invoiceDue);

            $allTransactions[] = [
                'datetime' => !empty($order['created_at']) ? $order['created_at'] : date('Y-m-d H:i:s'),
                'sort_order' => 1,
                'type' => 'Sales Invoice',
                'details' => 'Invoice ' . ($order['invoice_no'] ?? '') .
                    ' | Total ৳' . money($invoiceTotal) .
                    ' | Paid ৳' . money($order['paid_amount'] ?? $invoiceCredit) .
                    ' | Due ৳' . money($invoiceDue),
                'debit' => $invoiceTotal,
                'credit' => $invoiceCredit,
                'payment_method' => $order['payment_method'] ?? '',
                'note' => '',
                'order_id' => (int)$order['id'],
                'invoice_no' => $order['invoice_no'] ?? ''
            ];
        }
        $stmtOrders->close();

        /* Due collection receipts */
        $stmtReceives = $conn->prepare("
            SELECT id, receive_date, amount, payment_method, note, created_at
            FROM customer_due_receives
            WHERE shop_id = ? AND customer_id = ?
            ORDER BY receive_date ASC, id ASC
        ");
        $stmtReceives->bind_param("ii", $shop_id, $customer_id);
        $stmtReceives->execute();
        $receiveResult = $stmtReceives->get_result();

        while ($receive = $receiveResult->fetch_assoc()) {
            $allTransactions[] = [
                'datetime' => transaction_timestamp($receive['receive_date'] ?? '', $receive['created_at'] ?? ''),
                'sort_order' => 2,
                'type' => 'Due Receive',
                'details' => 'Payment received against customer due',
                'debit' => 0,
                'credit' => max(0, (float)($receive['amount'] ?? 0)),
                'payment_method' => $receive['payment_method'] ?? '',
                'note' => $receive['note'] ?? '',
                'order_id' => 0,
                'invoice_no' => ''
            ];
        }
        $stmtReceives->close();

        usort($allTransactions, function ($a, $b) {
            $timeCompare = strcmp($a['datetime'], $b['datetime']);
            if ($timeCompare !== 0) return $timeCompare;
            return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        });

        $fromStart = $from !== '' ? $from . ' 00:00:00' : null;
        $toEnd = $to !== '' ? $to . ' 23:59:59' : null;
        $periodRows = [];

        foreach ($allTransactions as $transaction) {
            $transactionDateTime = $transaction['datetime'];

            if ($fromStart !== null && $transactionDateTime < $fromStart) {
                $openingCarry += (float)$transaction['debit'];
                $openingCarry -= (float)$transaction['credit'];
                continue;
            }

            if ($toEnd !== null && $transactionDateTime > $toEnd) {
                continue;
            }

            $periodRows[] = $transaction;
            if ($transaction['type'] === 'Sales Invoice') {
                $periodSalesDue += max(0, (float)$transaction['debit'] - (float)$transaction['credit']);
            } elseif ($transaction['type'] === 'Due Receive') {
                $periodReceived += (float)$transaction['credit'];
            }
        }

        if ($fromStart !== null && abs($openingCarry) >= 0.005) {
            $ledgerRows[] = [
                'datetime' => $fromStart,
                'sort_order' => -1,
                'type' => 'Balance B/F',
                'details' => 'Balance carried forward before selected period',
                'debit' => $openingCarry > 0 ? $openingCarry : 0,
                'credit' => $openingCarry < 0 ? abs($openingCarry) : 0,
                'payment_method' => '',
                'note' => '',
                'order_id' => 0,
                'invoice_no' => ''
            ];
        }

        foreach ($periodRows as $row) {
            $ledgerRows[] = $row;
        }

        $running = 0.0;
        foreach ($ledgerRows as $key => $row) {
            $running += (float)$row['debit'];
            $running -= (float)$row['credit'];

            $ledgerRows[$key]['balance'] = $running;
            $totalDebit += (float)$row['debit'];
            $totalCredit += (float)$row['credit'];
        }

        $closingBalance = $running;
    }
}

include "header.php";
include "sidebar.php";
?>

<style>
.main { flex: 1; padding: 20px; background: #f8fafc; }
.card-box { background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 15px; }
.info-card { border-radius: 12px; padding: 14px; color: #fff; min-height: 92px; }
.info-card h6 { margin: 0; opacity: .9; font-size: 13px; }
.info-card h3 { margin: 6px 0 0; font-size: 22px; }
.bg-blue { background: #2563eb; }
.bg-green { background: #059669; }
.bg-red { background: #dc2626; }
.bg-darkx { background: #0f172a; }
.table th, .table td { vertical-align: middle; }
.print-header { display: none; }
.action-print { display: inline-flex; gap: 6px; align-items: center; }
.ledger-details { min-width: 260px; }
@media print {
    body { background: #fff !important; }
    .sidebar, .navbar, .no-print, header, footer { display: none !important; }
    .main { padding: 0 !important; background: #fff !important; }
    .card-box { box-shadow: none !important; border: 1px solid #ddd; }
    .print-header { display: block; text-align: center; margin-bottom: 15px; }
    .table { font-size: 11px; }
    a { color: #000 !important; text-decoration: none !important; }
}
</style>

<div class="main">

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h3 class="mb-0">📒 Customer Ledger</h3>
        <div>
            <a href="customer.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <?php if ($customer): ?>
                <button type="button" onclick="window.print()" class="btn btn-dark action-print">
                    <i class="fas fa-print"></i> Print
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$customer_id): ?>
        <div class="alert alert-warning">Invalid customer. Please open ledger from customer list.</div>
    <?php elseif (!$customer): ?>
        <div class="alert alert-danger">Customer not found.</div>
    <?php else: ?>

        <div class="print-header">
            <h2>Customer Ledger</h2>
            <p><?= h($customer['shop_name'] ?: $customer['name']) ?> | <?= h($customer['phone'] ?? '') ?></p>
            <p>
                Period: <?= $from !== '' ? h($from) : 'Beginning' ?>
                to <?= $to !== '' ? h($to) : date('Y-m-d') ?>
            </p>
        </div>

        <div class="card-box">
            <div class="row">
                <div class="col-md-3 mb-2"><strong>Customer:</strong><br><?= h($customer['name']) ?></div>
                <div class="col-md-3 mb-2"><strong>Shop:</strong><br><?= h($customer['shop_name'] ?? '') ?></div>
                <div class="col-md-3 mb-2"><strong>Phone:</strong><br><?= h($customer['phone'] ?? '') ?></div>
                <div class="col-md-3 mb-2"><strong>Email:</strong><br><?= h($customer['email'] ?? '') ?></div>
                <div class="col-md-3 mb-2"><strong>Area:</strong><br><?= h($customer['area_group'] ?? '') ?></div>
                <div class="col-md-3 mb-2"><strong>Group:</strong><br><?= h(ucwords(str_replace('_', ' ', $customer['customer_group'] ?? ''))) ?></div>
                <div class="col-md-3 mb-2"><strong>SR:</strong><br><?= h($customer['sr_name'] ?? 'Direct') ?></div>
                <div class="col-md-3 mb-2"><strong>Address:</strong><br><?= h($customer['address'] ?? '') ?></div>
            </div>
        </div>

        <div class="row no-print">
            <div class="col-md-3 mb-3">
                <div class="info-card bg-red">
                    <h6>Current Due</h6>
                    <h3>৳ <?= money($liveCurrentDue) ?></h3>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="info-card bg-blue">
                    <h6>New Due (Selected Period)</h6>
                    <h3>৳ <?= money($periodSalesDue) ?></h3>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="info-card bg-green">
                    <h6>Due Received (Selected Period)</h6>
                    <h3>৳ <?= money($periodReceived) ?></h3>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="info-card bg-darkx">
                    <h6>Ledger Closing</h6>
                    <h3>৳ <?= money($closingBalance) ?></h3>
                </div>
            </div>
        </div>

        <div class="card-box no-print">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="customer_id" value="<?= (int)$customer_id ?>">
                <div class="col-md-4">
                    <label>From Date</label>
                    <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
                </div>
                <div class="col-md-4">
                    <label>To Date</label>
                    <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="cus_ledger.php?customer_id=<?= (int)$customer_id ?>" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>

        <div class="card-box">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th style="width:135px;">Type</th>
                            <th class="ledger-details">Details</th>
                            <th style="width:120px;" class="text-end">Debit</th>
                            <th style="width:120px;" class="text-end">Credit</th>
                            <th style="width:120px;" class="text-end">Balance</th>
                            <th style="width:100px;">Method</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($ledgerRows) === 0): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No ledger data found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($ledgerRows as $row): ?>
                                <tr>
                                    <td><?= h(date('Y-m-d', strtotime($row['datetime']))) ?></td>
                                    <td>
                                        <?php if ($row['type'] === 'Due Receive'): ?>
                                            <span class="badge bg-success"><?= h($row['type']) ?></span>
                                        <?php elseif ($row['type'] === 'Sales Invoice'): ?>
                                            <span class="badge bg-danger"><?= h($row['type']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-primary"><?= h($row['type']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['order_id'])): ?>
                                            <a href="invoice.php?id=<?= (int)$row['order_id'] ?>" target="_blank">
                                                <?= h($row['details']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= h($row['details']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= (float)$row['debit'] > 0 ? '৳ ' . money($row['debit']) : '-' ?></td>
                                    <td class="text-end"><?= (float)$row['credit'] > 0 ? '৳ ' . money($row['credit']) : '-' ?></td>
                                    <td class="text-end fw-bold">৳ <?= money($row['balance']) ?></td>
                                    <td><?= h(ucwords((string)$row['payment_method'])) ?></td>
                                    <td><?= h($row['note']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="3" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold">৳ <?= money($totalDebit) ?></td>
                            <td class="text-end fw-bold">৳ <?= money($totalCredit) ?></td>
                            <td class="text-end fw-bold">৳ <?= money($closingBalance) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="alert alert-info mt-3 no-print mb-0">
                <strong>Calculation:</strong> Each sales invoice is shown as Debit, its same-invoice payment as Credit, and later due collection as Credit.
                Customer <code>due_amount</code> is treated as the live current balance. Any old/imported amount not represented by available transaction history appears as <strong>Balance B/F</strong>.
            </div>
        </div>

    <?php endif; ?>
</div>

<?php include "../pos/footer.php"; ?>
