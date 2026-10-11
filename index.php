<?php
// ============================================================
// Ricevaly POS Dashboard - Full Refactored Version
// Includes: Net Profit Calculation, Clean Helpers, Modular Structure
// ============================================================

include 'auth_helper.php';
requireLogin();

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bangladesh timezone
date_default_timezone_set('Asia/Dhaka');

$shop_id = tenantShopId();
$user_name = $_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'Admin');
$user_id = tenantUserId();

// MySQL timezone
if (isset($conn) && $conn) {
    @$conn->query("SET time_zone = '+06:00'");
}

// CSRF Token
if (empty($_SESSION['rv_dashboard_csrf'])) {
    $_SESSION['rv_dashboard_csrf'] = bin2hex(random_bytes(32));
}
$rv_dashboard_csrf = $_SESSION['rv_dashboard_csrf'];

// ============================================================
// HELPERS
// ============================================================
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function taka($amount, $decimals = 2) {
    return 'TK. ' . number_format((float)$amount, $decimals);
}

function moneyShort($amount) {
    return '৳' . number_format((float)$amount, 0);
}

function percentWidth($value) {
    return max(0, min(100, (float)$value));
}

function safeIdentifier($name) {
    return preg_match('/^[a-zA-Z0-9_]+$/', $name) ? $name : '';
}

function todayRange() {
    return [
        date('Y-m-d 00:00:00'),
        date('Y-m-d 23:59:59'),
        date('Y-m-d')
    ];
}

// ============================================================
// DATABASE HELPER FUNCTIONS (NO CHANGES)
// ============================================================
function tableExists($conn, $table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return false;
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $cache[$table] = ((int)($row[0] ?? 0) > 0);
    return $cache[$table];
}

function columnExists($conn, $table, $column) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($table === '' || $column === '') return false;

    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $cache[$key] = ((int)($row[0] ?? 0) > 0);
    return $cache[$key];
}

function scalarQuery($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) return 0;
    $row = $res->fetch_row();
    return $row ? ($row[0] ?? 0) : 0;
}

function buildShopWhere($conn, $table, &$types, &$params, $shop_id, $prefix = '') {
    if (columnExists($conn, $table, 'shop_id')) {
        $colPrefix = $prefix !== '' ? '`' . safeIdentifier($prefix) . '`.' : '';
        $types .= 'i';
        $params[] = $shop_id;
        return " {$colPrefix}`shop_id` = ? ";
    }
    return " 0=1 ";
}

function paymentMethodConditionSQL($column = 'payment_method', $type = 'cash', $prefix = '') {
    $column = safeIdentifier($column);
    $prefix = safeIdentifier($prefix);
    if ($column === '') return '';

    $col = ($prefix !== '' ? "`$prefix`." : '') . "`$column`";
    $expr = "LOWER(TRIM(COALESCE($col,'')))";

    $cashValues   = "'cash','cash payment','cash_in_hand','cash in hand','ক্যাশ'";
    $bkashValues  = "'bkash','bikash','b-kash','বিকাশ'";
    $nagadValues  = "'nagad','nogod','nagad_mfs','nagad mobile banking','নগদ','নগদ মোবাইল ব্যাংকিং','নগদ মোবাইল ব্যাঙ্কিং'";
    $mobileValues = "'mobile_banking','mobile banking','mobile','mfs','rocket'";
    $bankValues   = "'bank','bank_transfer','bank transfer','banking','card','cheque','check'";

    if ($type === 'bkash') return " AND $expr IN ($bkashValues) ";
    if ($type === 'nagad') return " AND $expr IN ($nagadValues) ";
    if ($type === 'mobile') return " AND $expr IN ($mobileValues) ";
    if ($type === 'bank') return " AND $expr IN ($bankValues) ";

    return " AND ($expr = '' OR $expr IN ($cashValues)) ";
}

function getProductNameColumn($conn) {
    foreach (['product_name', 'name', 'title'] as $column) {
        if (columnExists($conn, 'products', $column)) return $column;
    }
    return 'id';
}


// ============================================================
// NET PROFIT CALCULATION HELPER (NO CHANGES)
// ============================================================
function calculateNetProfit($conn, $shop_id, $start_date, $end_date, $today_date) {
    $result = [
        'total_sales' => 0,
        'total_cogs' => 0,
        'total_discount' => 0,
        'total_expense' => 0,
        'gross_profit' => 0,
        'net_profit' => 0
    ];

    if (!tableExists($conn, 'orders') || !tableExists($conn, 'order_items') || !tableExists($conn, 'products')) {
        return $result;
    }

    $sales_sql = "SELECT COALESCE(SUM(total_amount), 0) FROM orders 
                  WHERE shop_id = ? AND order_source = 'pos' AND order_status = 'completed' 
                  AND created_at >= ? AND created_at <= ?";
    $result['total_sales'] = (float)scalarQuery($conn, $sales_sql, 'iss', [$shop_id, $start_date, $end_date]);

    $cogs_sql = "SELECT COALESCE(SUM(oi.qty * COALESCE(NULLIF(oi.cost_price,0), p.purchase_price, 0)), 0) 
                 FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 LEFT JOIN products p ON oi.product_id = p.id AND p.shop_id = o.shop_id
                 WHERE o.shop_id = ? AND o.order_source = 'pos' AND o.order_status = 'completed' 
                 AND o.created_at >= ? AND o.created_at <= ?";
    $result['total_cogs'] = (float)scalarQuery($conn, $cogs_sql, 'iss', [$shop_id, $start_date, $end_date]);

    $discount_sql = "SELECT COALESCE(SUM(discount), 0) FROM orders 
                     WHERE shop_id = ? AND order_source = 'pos' AND order_status = 'completed' 
                     AND created_at >= ? AND created_at <= ?";
    $result['total_discount'] = (float)scalarQuery($conn, $discount_sql, 'iss', [$shop_id, $start_date, $end_date]);

    if (tableExists($conn, 'expenses')) {
        $expense_sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses 
                        WHERE shop_id = ? AND status = 'paid' AND expense_date = ?";
        $result['total_expense'] = (float)scalarQuery($conn, $expense_sql, 'is', [$shop_id, $today_date]);
    }

    $result['gross_profit'] = $result['total_sales'] - $result['total_cogs'];
    $result['net_profit'] = $result['gross_profit'] - $result['total_discount'] - $result['total_expense'];

    return $result;
}

// ============================================================
// FLASH & REDIRECT HELPERS (NO CHANGES)
// ============================================================
function rvFlash($type, $message) {
    $_SESSION['rv_dashboard_flash'] = ['type' => $type, 'message' => $message];
}

function rvRedirectSelf() {
    $target = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
    header('Location: ' . $target);
    exit;
}

function rvCleanDate($date) {
    $date = trim((string)$date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
}

function rvEnumHasValue($conn, $table, $column, $value) {
    $table = safeIdentifier($table);
    $column = safeIdentifier($column);
    if ($table === '' || $column === '') return false;

    $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $type = $row['COLUMN_TYPE'] ?? '';
    return stripos($type, "'" . $value . "'") !== false;
}

function rvAssetLabel($assetType) {
    $labels = [
        'cash' => 'Cash',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'bank' => 'Bank',
        'other' => 'Other'
    ];
    return $labels[$assetType] ?? ucfirst((string)$assetType);
}

function rvColumnAcceptsValue($conn, $table, $column, $value) {
    $table = safeIdentifier($table);
    $column = safeIdentifier($column);
    if ($table === '' || $column === '') return false;

    $stmt = $conn->prepare("SELECT DATA_TYPE, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) return false;

    $dataType = strtolower((string)($row['DATA_TYPE'] ?? ''));
    $columnType = (string)($row['COLUMN_TYPE'] ?? '');
    if (!in_array($dataType, ['enum', 'set'], true)) return true;

    return stripos($columnType, "'" . $value . "'") !== false;
}

function rvTableHasColumns($conn, $table, array $columns) {
    foreach ($columns as $column) {
        if (!columnExists($conn, $table, $column)) return false;
    }
    return true;
}

function rvEnsureSupplierPaymentAllocationTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS `supplier_payment_allocations` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `payment_id` INT NOT NULL,
        `purchase_id` INT NOT NULL,
        `allocated_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_supplier_payment_purchase` (`payment_id`,`purchase_id`),
        KEY `idx_supplier_payment_alloc_purchase` (`purchase_id`),
        KEY `idx_supplier_payment_alloc_payment` (`payment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return (bool)$conn->query($sql);
}

function rvReconcileTodayUnlinkedSupplierPayments($conn, $shop_id, $today_start, $today_end, $today_date) {
    if (
        !tableExists($conn, 'suppliers') ||
        !tableExists($conn, 'purchases') ||
        !tableExists($conn, 'supplier_payments') ||
        !rvTableHasColumns($conn, 'purchases', ['id','supplier_id','grand_total','paid_amount','due_amount','created_at']) ||
        !rvTableHasColumns($conn, 'supplier_payments', ['id','supplier_id','purchase_id','payment_amount','payment_date'])
    ) {
        return true;
    }

    if (!rvEnsureSupplierPaymentAllocationTable($conn)) {
        return false;
    }

    $conn->begin_transaction();

    try {
        $paymentSql = "SELECT sp.id, sp.supplier_id, sp.payment_amount, sp.payment_date
                       FROM supplier_payments sp
                       INNER JOIN suppliers s ON s.id = sp.supplier_id
                       WHERE s.shop_id = ?
                         AND sp.purchase_id IS NULL
                         AND DATE(sp.payment_date) = ?
                         AND COALESCE(sp.payment_amount,0) > 0
                       ORDER BY sp.payment_date ASC, sp.id ASC";
        $paymentStmt = $conn->prepare($paymentSql);
        if (!$paymentStmt) throw new Exception('Unable to load unlinked supplier payments.');
        $paymentStmt->bind_param('is', $shop_id, $today_date);
        $paymentStmt->execute();
        $payments = $paymentStmt->get_result();

        $lockPayment = $conn->prepare("SELECT payment_amount, payment_date
                                      FROM supplier_payments
                                      WHERE id = ? AND supplier_id = ? AND purchase_id IS NULL
                                      FOR UPDATE");
        $allocatedTotalStmt = $conn->prepare("SELECT COALESCE(SUM(allocated_amount),0)
                                             FROM supplier_payment_allocations
                                             WHERE payment_id = ?");
        $invoiceStmt = $conn->prepare("SELECT id, grand_total, paid_amount, due_amount, created_at
                                      FROM purchases
                                      WHERE supplier_id = ?
                                        AND created_at >= ? AND created_at <= ?
                                        AND created_at <= ?
                                        AND COALESCE(due_amount,0) > 0
                                      ORDER BY created_at ASC, id ASC
                                      FOR UPDATE");
        $updateInvoice = $conn->prepare("UPDATE purchases
                                        SET paid_amount = ?, due_amount = ?
                                        WHERE id = ? AND supplier_id = ?");
        $saveAllocation = $conn->prepare("INSERT INTO supplier_payment_allocations
                                         (payment_id, purchase_id, allocated_amount)
                                         VALUES (?, ?, ?)
                                         ON DUPLICATE KEY UPDATE
                                         allocated_amount = allocated_amount + VALUES(allocated_amount)");

        if (!$lockPayment || !$allocatedTotalStmt || !$invoiceStmt || !$updateInvoice || !$saveAllocation) {
            throw new Exception('Unable to prepare supplier payment reconciliation.');
        }

        while ($payment = $payments->fetch_assoc()) {
            $paymentId = (int)$payment['id'];
            $supplierId = (int)$payment['supplier_id'];
            if ($paymentId <= 0 || $supplierId <= 0) continue;

            $lockPayment->bind_param('ii', $paymentId, $supplierId);
            $lockPayment->execute();
            $locked = $lockPayment->get_result()->fetch_assoc();
            if (!$locked) continue;

            $allocatedTotalStmt->bind_param('i', $paymentId);
            $allocatedTotalStmt->execute();
            $allocatedRow = $allocatedTotalStmt->get_result()->fetch_row();
            $alreadyAllocated = round((float)($allocatedRow[0] ?? 0), 2);
            $remaining = round(max(0, (float)$locked['payment_amount'] - $alreadyAllocated), 2);
            if ($remaining <= 0.009) continue;

            $paymentDateTime = (string)$locked['payment_date'];
            $invoiceStmt->bind_param('isss', $supplierId, $today_start, $today_end, $paymentDateTime);
            $invoiceStmt->execute();
            $invoices = $invoiceStmt->get_result();

            while ($remaining > 0.009 && ($invoice = $invoices->fetch_assoc())) {
                $purchaseId = (int)$invoice['id'];
                $grandTotal = round(max(0, (float)$invoice['grand_total']), 2);
                $invoiceDue = round(max(0, (float)$invoice['due_amount']), 2);
                if ($purchaseId <= 0 || $invoiceDue <= 0) continue;

                $allocated = round(min($remaining, $invoiceDue), 2);
                $paidStored = round(max(0, (float)$invoice['paid_amount']), 2);
                $paidDerived = round(max(0, $grandTotal - $invoiceDue), 2);
                $paidAfter = round(min($grandTotal, max($paidStored, $paidDerived) + $allocated), 2);
                $dueAfter = round(max(0, $invoiceDue - $allocated), 2);

                $updateInvoice->bind_param('ddii', $paidAfter, $dueAfter, $purchaseId, $supplierId);
                if (!$updateInvoice->execute()) {
                    throw new Exception('Unable to repair purchase invoice due.');
                }

                $saveAllocation->bind_param('iid', $paymentId, $purchaseId, $allocated);
                if (!$saveAllocation->execute()) {
                    throw new Exception('Unable to save repaired invoice allocation.');
                }

                $remaining = round(max(0, $remaining - $allocated), 2);
            }
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Supplier payment reconciliation failed: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
// INVOICE GENERATION FUNCTIONS (NEW - UI ONLY, NO DB CHANGES)
// ============================================================
function rvGenerateInvoice($type, $data) {
    $invoice_id = 'INV-' . date('Ymd') . '-' . uniqid();
    $invoice_data = [
        'id' => $invoice_id,
        'type' => $type, // 'customer_payment' or 'supplier_payment'
        'data' => $data,
        'created_at' => date('Y-m-d H:i:s'),
        'shop_id' => $data['shop_id'] ?? 0,
        'user_id' => $data['user_id'] ?? 0
    ];
    
    $_SESSION['rv_invoice_data'] = $invoice_data;
    return $invoice_id;
}

function rvRedirectToInvoice($invoice_id) {
    header('Location: invoice_view.php?id=' . urlencode($invoice_id) . '&t=' . time());
    exit;
}

// ============================================================
// POST HANDLERS (NO LOGIC CHANGES - ONLY INVOICE GENERATION ADDED)
// ============================================================
function rvSaveCustomerDueReceive($conn, $shop_id, $user_id) {
    if (!tableExists($conn, 'customers') || !tableExists($conn, 'customer_due_receives') || !tableExists($conn, 'assets')) {
        throw new Exception('Required table missing.');
    }
    if (!columnExists($conn, 'customers', 'due_amount')) {
        throw new Exception('customers.due_amount column not found.');
    }

    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $asset_id = (int)($_POST['asset_id'] ?? 0);
    $receive_date = rvCleanDate($_POST['receive_date'] ?? '');
    $amount = (float)($_POST['paid_amount'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $save_print = isset($_POST['save_print']) && $_POST['save_print'] == '1';

    if ($customer_id <= 0) throw new Exception('Please select customer.');
    if ($asset_id <= 0) throw new Exception('Please select payment asset.');
    if (!is_finite($amount) || $amount <= 0) throw new Exception('Paid amount must be greater than 0.');

    $conn->begin_transaction();

    try {
        if (columnExists($conn, 'customers', 'shop_name') && columnExists($conn, 'customers', 'name')) {
            $nameSql = "COALESCE(NULLIF(shop_name,''), NULLIF(name,''), CONCAT('Customer #', id))";
        } elseif (columnExists($conn, 'customers', 'shop_name')) {
            $nameSql = "COALESCE(NULLIF(shop_name,''), CONCAT('Customer #', id))";
        } elseif (columnExists($conn, 'customers', 'name')) {
            $nameSql = "COALESCE(NULLIF(name,''), CONCAT('Customer #', id))";
        } else {
            $nameSql = "CONCAT('Customer #', id)";
        }

        $where = "id = ? AND shop_id = ?";
        $types = "ii";
        $params = [$customer_id, $shop_id];

        $stmt = $conn->prepare("SELECT id, $nameSql AS customer_name, COALESCE(due_amount,0) AS due_amount FROM customers WHERE $where FOR UPDATE");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        if (!$customer) throw new Exception('Customer not found.');

        $customer_due = (float)$customer['due_amount'];
        if ($customer_due <= 0) throw new Exception('This customer has no due amount.');
        if ($amount > $customer_due) throw new Exception('Paid amount cannot exceed customer due.');

        $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount FROM assets WHERE id = ? AND shop_id = ? AND status = 'active' AND asset_type IN ('cash','bkash','nagad','bank','other') FOR UPDATE");
        $stmt->bind_param('ii', $asset_id, $shop_id);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        if (!$asset) throw new Exception('Selected asset not found or inactive.');

        $asset_before = (float)$asset['amount'];
        $asset_after = $asset_before + $amount;
        $new_due = max(0, $customer_due - $amount);
        $payment_method = $asset['asset_type'];

        $updateWhere = "id = ? AND shop_id = ?";
        $updateTypes = "dii";
        $updateParams = [$new_due, $customer_id, $shop_id];
        $stmt = $conn->prepare("UPDATE customers SET due_amount = ? WHERE $updateWhere");
        $stmt->bind_param($updateTypes, ...$updateParams);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ?");
        $stmt->bind_param("dii", $asset_after, $asset_id, $shop_id);
        $stmt->execute();

        $stmt = $conn->prepare("INSERT INTO customer_due_receives (shop_id, customer_id, receive_date, amount, payment_method, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisdssi', $shop_id, $customer_id, $receive_date, $amount, $payment_method, $note, $user_id);
        $stmt->execute();
        $receive_id = (int)$conn->insert_id;

        if (tableExists($conn, 'payments')) {
            $stmt = $conn->prepare("INSERT INTO payments (customer_id, amount, type, note, created_by) VALUES (?, ?, 'customer_due_receive', ?, ?)");
            $stmt->bind_param('idsi', $customer_id, $amount, $note, $user_id);
            $stmt->execute();
        }

        if (tableExists($conn, 'asset_transactions') && rvEnumHasValue($conn, 'asset_transactions', 'movement_type', 'customer_due_receive')) {
            $stmt = $conn->prepare("INSERT INTO asset_transactions
                (shop_id, transaction_type, movement_type, to_asset_id, to_asset_type, to_asset_name, amount, to_balance_before, to_balance_after, reference_type, reference_id, transaction_date, note, created_by)
                VALUES (?, 'in', 'customer_due_receive', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissdddsissi', $shop_id, $asset_id, $asset['asset_type'], $asset['asset_name'], $amount, $asset_before, $asset_after, 'customer_due_receive', $receive_id, $receive_date, $note, $user_id);
            $stmt->execute();
        }

        $conn->commit();
        
        // Generate invoice if requested
        if ($save_print) {
            $invoice_data = [
                'type' => 'customer_payment',
                'customer_id' => $customer_id,
                'customer_name' => $customer['customer_name'],
                'amount' => $amount,
                'previous_due' => $customer_due,
                'remaining_due' => $new_due,
                'payment_method' => $payment_method,
                'payment_date' => $receive_date,
                'note' => $note,
                'asset_name' => $asset['asset_name'],
                'receive_id' => $receive_id,
                'shop_id' => $shop_id,
                'user_id' => $user_id
            ];
            $invoice_id = rvGenerateInvoice('customer_payment', $invoice_data);
            rvFlash('success', 'Customer payment saved successfully.');
            rvRedirectToInvoice($invoice_id);
        }
        
        rvFlash('success', 'Customer payment saved successfully.');
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function rvSaveSupplierPayment($conn, $shop_id, $user_id) {
    if (!tableExists($conn, 'suppliers') || !tableExists($conn, 'supplier_payments') || !tableExists($conn, 'assets')) {
        throw new Exception('Required table missing.');
    }

    $supplierDueColumn = columnExists($conn, 'suppliers', 'current_due') ? 'current_due' : (columnExists($conn, 'suppliers', 'due_amount') ? 'due_amount' : '');
    if ($supplierDueColumn === '') {
        throw new Exception('suppliers.current_due/due_amount column not found.');
    }

    if (!rvEnsureSupplierPaymentAllocationTable($conn)) {
        throw new Exception('Unable to create supplier payment allocation table.');
    }

    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $asset_id = (int)($_POST['asset_id'] ?? 0);
    $payment_date = rvCleanDate($_POST['payment_date'] ?? '');
    $amount = round((float)($_POST['paid_amount'] ?? 0), 2);
    $note = trim((string)($_POST['note'] ?? ''));
    $save_print = isset($_POST['save_print']) && $_POST['save_print'] == '1';

    if ($supplier_id <= 0) throw new Exception('Please select supplier.');
    if ($asset_id <= 0) throw new Exception('Please select payment asset.');
    if (!is_finite($amount) || $amount <= 0) throw new Exception('Paid amount must be greater than 0.');

    $business_date = date('Y-m-d');
    $allocation_date = ($payment_date === $business_date) ? $business_date : $payment_date;
    $payment_datetime = $payment_date . ' ' . date('H:i:s');

    $conn->begin_transaction();

    try {
        if (columnExists($conn, 'suppliers', 'supplier_name')) {
            $nameSql = "COALESCE(NULLIF(supplier_name,''), CONCAT('Supplier #', id))";
        } elseif (columnExists($conn, 'suppliers', 'name')) {
            $nameSql = "COALESCE(NULLIF(name,''), CONCAT('Supplier #', id))";
        } else {
            $nameSql = "CONCAT('Supplier #', id)";
        }

        $stmt = $conn->prepare("SELECT id, $nameSql AS supplier_name, COALESCE(`$supplierDueColumn`,0) AS current_due
                                FROM suppliers
                                WHERE id = ? AND shop_id = ?
                                FOR UPDATE");
        if (!$stmt) throw new Exception('Unable to load supplier.');
        $stmt->bind_param('ii', $supplier_id, $shop_id);
        $stmt->execute();
        $supplier = $stmt->get_result()->fetch_assoc();
        if (!$supplier) throw new Exception('Supplier not found.');

        $supplier_due = round((float)$supplier['current_due'], 2);
        if ($supplier_due <= 0) throw new Exception('This supplier has no due amount.');
        if ($amount > $supplier_due) throw new Exception('Paid amount cannot exceed supplier due.');

        $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount
                                FROM assets
                                WHERE id = ? AND shop_id = ? AND status = 'active'
                                  AND asset_type IN ('cash','bkash','nagad','bank','other')
                                FOR UPDATE");
        if (!$stmt) throw new Exception('Unable to load payment asset.');
        $stmt->bind_param('ii', $asset_id, $shop_id);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();
        if (!$asset) throw new Exception('Selected asset not found or inactive.');

        $asset_before = round((float)$asset['amount'], 2);
        if ($amount > $asset_before) throw new Exception('Insufficient balance in selected asset.');

        $asset_after = round($asset_before - $amount, 2);
        $new_supplier_due = round(max(0, $supplier_due - $amount), 2);
        $payment_method = rvAssetLabel($asset['asset_type']);

        $remaining = $amount;
        $allocations = [];
        $allocatedToday = 0.00;

        if (
            tableExists($conn, 'purchases') &&
            rvTableHasColumns($conn, 'purchases', ['id','supplier_id','grand_total','paid_amount','due_amount','created_at'])
        ) {
            $stmt = $conn->prepare("SELECT id, grand_total, paid_amount, due_amount, created_at
                                    FROM purchases
                                    WHERE supplier_id = ?
                                      AND COALESCE(due_amount,0) > 0
                                      AND created_at <= ?
                                    ORDER BY
                                      CASE WHEN DATE(created_at) = ? THEN 0 ELSE 1 END ASC,
                                      CASE WHEN DATE(created_at) = ? THEN created_at END ASC,
                                      CASE WHEN DATE(created_at) <> ? THEN created_at END ASC,
                                      id ASC
                                    FOR UPDATE");
            if (!$stmt) throw new Exception('Unable to load outstanding purchase invoices.');
            $stmt->bind_param('issss', $supplier_id, $payment_datetime, $allocation_date, $allocation_date, $allocation_date);
            $stmt->execute();
            $invoiceResult = $stmt->get_result();

            $updatePurchase = $conn->prepare("UPDATE purchases
                                              SET paid_amount = ?, due_amount = ?
                                              WHERE id = ? AND supplier_id = ?");
            if (!$updatePurchase) throw new Exception('Unable to update purchase invoice.');

            while ($remaining > 0.009 && ($invoice = $invoiceResult->fetch_assoc())) {
                $purchaseId = (int)$invoice['id'];
                $grandTotal = round(max(0, (float)$invoice['grand_total']), 2);
                $invoiceDue = round(max(0, (float)$invoice['due_amount']), 2);
                if ($purchaseId <= 0 || $invoiceDue <= 0) continue;

                $allocated = round(min($remaining, $invoiceDue), 2);
                $paidStored = round(max(0, (float)$invoice['paid_amount']), 2);
                $paidDerived = round(max(0, $grandTotal - $invoiceDue), 2);
                $paidAfter = round(min($grandTotal, max($paidStored, $paidDerived) + $allocated), 2);
                $dueAfter = round(max(0, $invoiceDue - $allocated), 2);

                $updatePurchase->bind_param('ddii', $paidAfter, $dueAfter, $purchaseId, $supplier_id);
                if (!$updatePurchase->execute()) throw new Exception('Purchase invoice due update failed.');

                $isToday = substr((string)$invoice['created_at'], 0, 10) === $business_date;
                $allocations[] = [
                    'purchase_id' => $purchaseId,
                    'amount' => $allocated,
                    'due_after' => $dueAfter,
                    'is_today' => $isToday,
                ];
                if ($isToday) $allocatedToday = round($allocatedToday + $allocated, 2);
                $remaining = round(max(0, $remaining - $allocated), 2);
            }
        }

        $stmt = $conn->prepare("UPDATE suppliers SET `$supplierDueColumn` = ? WHERE id = ? AND shop_id = ?");
        if (!$stmt) throw new Exception('Unable to update supplier due.');
        $stmt->bind_param('dii', $new_supplier_due, $supplier_id, $shop_id);
        if (!$stmt->execute()) throw new Exception('Supplier due update failed.');

        $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ?");
        if (!$stmt) throw new Exception('Unable to update payment asset.');
        $stmt->bind_param('dii', $asset_after, $asset_id, $shop_id);
        if (!$stmt->execute()) throw new Exception('Payment asset update failed.');

        $stmt = $conn->prepare("INSERT INTO supplier_payments
                                (supplier_id, purchase_id, payment_amount, payment_method, note, payment_date)
                                VALUES (?, NULL, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception('Unable to create supplier payment record.');
        $stmt->bind_param('idsss', $supplier_id, $amount, $payment_method, $note, $payment_datetime);
        if (!$stmt->execute()) throw new Exception('Supplier payment record was not created.');
        $payment_id = (int)$conn->insert_id;
        if ($payment_id <= 0) throw new Exception('Supplier payment record was not created.');

        if (!empty($allocations)) {
            $allocationStmt = $conn->prepare("INSERT INTO supplier_payment_allocations
                                              (payment_id, purchase_id, allocated_amount)
                                              VALUES (?, ?, ?)");
            if (!$allocationStmt) throw new Exception('Unable to save invoice allocation.');

            foreach ($allocations as $allocation) {
                $purchaseId = (int)$allocation['purchase_id'];
                $allocatedAmount = (float)$allocation['amount'];
                $allocationStmt->bind_param('iid', $payment_id, $purchaseId, $allocatedAmount);
                if (!$allocationStmt->execute()) throw new Exception('Invoice allocation save failed.');
            }
        }

        if (
            tableExists($conn, 'payments') &&
            rvTableHasColumns($conn, 'payments', ['supplier_id', 'amount', 'type', 'note', 'created_by']) &&
            rvColumnAcceptsValue($conn, 'payments', 'type', 'supplier_payment')
        ) {
            try {
                $stmt = $conn->prepare("INSERT INTO payments (supplier_id, amount, type, note, created_by)
                                        VALUES (?, ?, 'supplier_payment', ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('idsi', $supplier_id, $amount, $note, $user_id);
                    $stmt->execute();
                }
            } catch (Throwable $ignoredLegacyPaymentError) {}
        }

        if (
            tableExists($conn, 'asset_transactions') &&
            rvTableHasColumns($conn, 'asset_transactions', [
                'shop_id', 'transaction_type', 'movement_type', 'from_asset_id',
                'from_asset_type', 'from_asset_name', 'amount', 'from_balance_before',
                'from_balance_after', 'reference_type', 'reference_id',
                'transaction_date', 'note', 'created_by'
            ]) &&
            rvColumnAcceptsValue($conn, 'asset_transactions', 'transaction_type', 'out') &&
            rvColumnAcceptsValue($conn, 'asset_transactions', 'movement_type', 'supplier_payment')
        ) {
            try {
                $reference_type = 'supplier_payment';
                $stmt = $conn->prepare("INSERT INTO asset_transactions
                    (shop_id, transaction_type, movement_type, from_asset_id, from_asset_type, from_asset_name,
                     amount, from_balance_before, from_balance_after, reference_type, reference_id,
                     transaction_date, note, created_by)
                    VALUES (?, 'out', 'supplier_payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('iissdddsissi',
                        $shop_id,
                        $asset_id,
                        $asset['asset_type'],
                        $asset['asset_name'],
                        $amount,
                        $asset_before,
                        $asset_after,
                        $reference_type,
                        $payment_id,
                        $payment_date,
                        $note,
                        $user_id
                    );
                    $stmt->execute();
                }
            } catch (Throwable $ignoredAssetLedgerError) {}
        }

        foreach ($allocations as $allocation) {
            $purchaseId = (int)$allocation['purchase_id'];
            $expectedDue = (float)$allocation['due_after'];
            $verify = $conn->prepare("SELECT COALESCE(due_amount,0) AS due_amount
                                      FROM purchases
                                      WHERE id = ? AND supplier_id = ?");
            if (!$verify) throw new Exception('Unable to verify purchase invoice due.');
            $verify->bind_param('ii', $purchaseId, $supplier_id);
            $verify->execute();
            $verified = $verify->get_result()->fetch_assoc();
            if (!$verified || abs((float)$verified['due_amount'] - $expectedDue) > 0.01) {
                throw new Exception('Purchase due verification failed. Payment was rolled back.');
            }
        }

        $verifySupplier = $conn->prepare("SELECT COALESCE(`$supplierDueColumn`,0) AS current_due
                                          FROM suppliers WHERE id = ? AND shop_id = ?");
        if (!$verifySupplier) throw new Exception('Unable to verify supplier due.');
        $verifySupplier->bind_param('ii', $supplier_id, $shop_id);
        $verifySupplier->execute();
        $verifiedSupplier = $verifySupplier->get_result()->fetch_assoc();
        if (!$verifiedSupplier || abs((float)$verifiedSupplier['current_due'] - $new_supplier_due) > 0.01) {
            throw new Exception('Supplier due verification failed. Payment was rolled back.');
        }

        $conn->commit();

        // Generate invoice if requested
        if ($save_print) {
            $invoice_data = [
                'type' => 'supplier_payment',
                'supplier_id' => $supplier_id,
                'supplier_name' => $supplier['supplier_name'],
                'amount' => $amount,
                'payment_method' => $payment_method,
                'payment_date' => $payment_date,
                'note' => $note,
                'asset_name' => $asset['asset_name'],
                'payment_id' => $payment_id,
                'allocations' => $allocations,
                'allocated_today' => $allocatedToday,
                'shop_id' => $shop_id,
                'user_id' => $user_id
            ];
            $invoice_id = rvGenerateInvoice('supplier_payment', $invoice_data);
            rvFlash('success', 'Supplier payment saved successfully.');
            rvRedirectToInvoice($invoice_id);
        }

        $allocatedTotal = round($amount - $remaining, 2);
        $message = 'Supplier payment of ' . taka($amount) . ' saved for ' . $supplier['supplier_name'] . '.';
        if ($allocatedTotal > 0) $message .= ' Invoice due reduced by ' . taka($allocatedTotal) . '.';
        if ($allocatedToday > 0) $message .= ' New Purchase Due Today reduced by ' . taka($allocatedToday) . '.';
        rvFlash('success', $message);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// ============================================================
// PROCESS POST REQUESTS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rv_dashboard_action'])) {
    try {
        if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['rv_dashboard_csrf'] ?? '', $_POST['csrf_token'])) {
            throw new Exception('Invalid security token. Please reload the page.');
        }

        $action = $_POST['rv_dashboard_action'];
        if ($action === 'customer_due_receive') {
            rvSaveCustomerDueReceive($conn, $shop_id, $user_id);
        } elseif ($action === 'supplier_payment') {
            rvSaveSupplierPayment($conn, $shop_id, $user_id);
        } else {
            throw new Exception('Invalid action.');
        }
    } catch (Exception $e) {
        rvFlash('error', $e->getMessage());
    }
    rvRedirectSelf();
}

rvReconcileTodayUnlinkedSupplierPayments($conn, $shop_id, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'), date('Y-m-d'));

// ============================================================
// VIEW HELPERS (NO CHANGES)
// ============================================================
function renderSectionTitle($icon, $title) {
    echo '<div class="v3-section-title"><i class="' . h($icon) . '"></i> ' . h($title) . '</div>';
}

function renderSummaryLine($label, $value, $colorClass = 'v3-gray') {
    echo '<div class="v3-summary-line">';
    echo '<span class="v3-summary-label ' . h($colorClass) . '">' . h($label) . '</span>';
    echo '<span class="v3-summary-value ' . h($colorClass) . '">' . $value . '</span>';
    echo '</div>';
}

function renderSummaryCard($title, $lines) {
    echo '<div class="v3-summary-card">';
    echo '<h6>' . h($title) . '</h6>';
    foreach ($lines as $line) {
        renderSummaryLine($line['label'], $line['value'], $line['class'] ?? 'v3-gray');
    }
    echo '</div>';
}


function renderBalanceCard($title, $headerClass, $items) {
    echo '<div class="v3-balance-card">';
    echo '<div class="v3-balance-header ' . h($headerClass) . '">' . h($title) . '</div>';
    echo '<div class="v3-balance-body">';
    foreach ($items as $item) {
        $isTotal = !empty($item['total']) ? ' v3-balance-total' : '';
        echo '<div class="v3-balance-item' . $isTotal . '">';
        echo '<span class="v3-balance-label">' . h($item['label']) . '</span>';
        echo '<span class="v3-balance-value">' . taka($item['value']) . '</span>';
        echo '</div>';
    }
    echo '</div></div>';
}

function renderStatCard($class, $icon, $label, $value, $sub) {
    echo '<div class="v3-stat-card ' . h($class) . '">';
    echo '<div class="v3-stat-top"><div class="v3-stat-icon"><i class="' . h($icon) . '"></i></div></div>';
    echo '<span class="v3-stat-label">' . h($label) . '</span>';
    echo '<span class="v3-stat-value">' . moneyShort($value) . '</span>';
    echo '<span class="v3-stat-sub">' . h($sub) . '</span>';
    echo '</div>';
}

function renderQuickActions($actions, $mode = 'bar') {
    foreach ($actions as $action) {
        $class = ($mode === 'bar') ? 'v3-fixed-btn' : 'v3-menu-item';
        if (!empty($action['primary'])) $class .= ($mode === 'bar') ? ' v3-fixed-btn-primary' : '';

        $title = $action['title'] ?? $action['label'];
        $modalSize = $action['modal_size'] ?? 'xl';
        $isLocalModal = !empty($action['local_modal']);

        $attrs = $isLocalModal
            ? ' data-rv-open-local-modal="' . h($action['local_modal']) . '"'
            : ' data-rv-modal-url="' . h($action['href']) . '" data-rv-modal-title="' . h($title) . '" data-rv-modal-size="' . h($modalSize) . '"';

        if ($mode === 'bar') {
            echo '<button type="button" class="' . h($class) . '"' . $attrs . '>';
            echo '<i class="' . h($action['icon']) . '"></i> ' . h($action['label']);
            echo '</button>';
        } else {
            echo '<button type="button" class="' . h($class) . '"' . $attrs . '>';
            echo '<div class="v3-menu-ring ' . h($action['ring']) . '"><i class="' . h($action['icon']) . '"></i></div>';
            echo '<span>' . h($action['label']) . '</span>';
            echo '</button>';
        }
    }
}

// ============================================================
// DASHBOARD METRICS (NO CHANGES)
// ============================================================
[$today_start, $today_end, $today_date] = todayRange();

$total_products = 0;
$total_customers = 0;
$total_sales_count = 0;
$today_revenue = 0;
$paid_sales = 0;
$due_sales = 0;
$order_discount = 0;
$order_delivery = 0;
$due_receive = 0;
$inst_receive = 0;
$adv_receive = 0;
$supplier_payment = 0;
$daily_expense = 0;
$daily_expense_paid = 0;
$daily_expense_due = 0;
$expense_due_total = 0;
$purchase_total = 0;
$purchase_entry_paid = 0;
$purchase_entry_due = 0;
$purchase_paid = 0;
$purchase_due = 0;
$purchase_invoice_count = 0;
$supplier_payment_today = 0;
$total_purchase_paid_today = 0;
$due_supplier_count = 0;
$item_stock_value = 0;
$receivable_customer_due = 0;
$customer_advance = 0;
$cash_total_sales = 0;
$bkash_sales = 0;
$nagad_sales = 0;
$bank_sales = 0;
$cash_due_receive = 0;
$bkash_due_receive = 0;
$nagad_due_receive = 0;
$bank_due_receive = 0;
$cash_in_hand = 0;
$bkash_balance = 0;
$nagad_balance = 0;
$bank_deposit = 0;
$supplier_due = 0;
$bank_loan = 0;
$credit_card = 0;
$investor_loan = 0;
$total_assets = 0;
$total_liability = 0;
$cash_inflow_today = 0;
$cash_outflow_today = 0;
$net_cash_today = 0;
$low_stock_items = [];
$debug_warnings = [];

$net_profit_data = calculateNetProfit($conn, $shop_id, $today_start, $today_end, $today_date);
$gross_profit = $net_profit_data['gross_profit'];
$net_profit = $net_profit_data['net_profit'];
$total_cogs = $net_profit_data['total_cogs'];
$total_discount = $net_profit_data['total_discount'];
$total_expense = $net_profit_data['total_expense'];

if (isset($conn) && $conn) {
    if (tableExists($conn, 'products')) {
        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'products', $types, $params, $shop_id);
        $total_products = (int)scalarQuery($conn, "SELECT COUNT(*) FROM products WHERE $where", $types, $params);

        if (columnExists($conn, 'products', 'stock')) {
            $priceCol = columnExists($conn, 'products', 'purchase_price') ? 'purchase_price' : (columnExists($conn, 'products', 'buying_price') ? 'buying_price' : null);
            if ($priceCol) {
                $item_stock_value = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(stock,0) * COALESCE($priceCol,0)), 0) FROM products WHERE $where", $types, $params);
            }

            $nameCol = getProductNameColumn($conn);
            $lowSql = "SELECT `$nameCol` AS name, stock FROM products WHERE $where AND stock < 10 ORDER BY stock ASC LIMIT 10";
            $stmt = $conn->prepare($lowSql);
            if ($stmt) {
                if ($types !== '') $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $lowRes = $stmt->get_result();
                while ($row = $lowRes->fetch_assoc()) $low_stock_items[] = $row;
            }
        }
    } else {
        $debug_warnings[] = "products table not found.";
    }

    if (tableExists($conn, 'customers')) {
        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'customers', $types, $params, $shop_id);
        $total_customers = (int)scalarQuery($conn, "SELECT COUNT(*) FROM customers WHERE $where", $types, $params);

        if (columnExists($conn, 'customers', 'due_amount')) {
            $receivable_customer_due = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(due_amount,0)),0) FROM customers WHERE $where AND COALESCE(due_amount,0) > 0", $types, $params);
        }
        if (columnExists($conn, 'customers', 'advance_amount')) {
            $customer_advance = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(advance_amount,0)),0) FROM customers WHERE $where AND COALESCE(advance_amount,0) > 0", $types, $params);
        }
    }

    if (tableExists($conn, 'orders')) {
        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'orders', $types, $params, $shop_id);
        // POS Dashboard is intentionally isolated from website/online orders.
        $where .= " AND order_source = 'pos' AND created_at >= ? AND created_at <= ? ";
        $types .= "ss";
        $params[] = $today_start;
        $params[] = $today_end;

        $total_sales_count = (int)scalarQuery($conn, "SELECT COUNT(*) FROM orders WHERE $where", $types, $params);
        $today_revenue = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(total_amount,0)),0) FROM orders WHERE $where", $types, $params);

        if (columnExists($conn, 'orders', 'paid_amount')) {
            $paid_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(paid_amount,0)),0) FROM orders WHERE $where", $types, $params);
        } elseif (columnExists($conn, 'orders', 'payment_status')) {
            $paid_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(total_amount,0)),0) FROM orders WHERE $where AND payment_status = 'paid'", $types, $params);
        }

        if (columnExists($conn, 'orders', 'payment_method') && columnExists($conn, 'orders', 'paid_amount')) {
            $cash_total_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(paid_amount,0)),0) FROM orders WHERE $where" . paymentMethodConditionSQL('payment_method', 'cash'), $types, $params);
            $bkash_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(paid_amount,0)),0) FROM orders WHERE $where" . paymentMethodConditionSQL('payment_method', 'bkash'), $types, $params);
            $nagad_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(paid_amount,0)),0) FROM orders WHERE $where" . paymentMethodConditionSQL('payment_method', 'nagad'), $types, $params);
            $bank_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(paid_amount,0)),0) FROM orders WHERE $where" . paymentMethodConditionSQL('payment_method', 'bank'), $types, $params);
        }

        if (columnExists($conn, 'orders', 'due_amount')) {
            $due_sales = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(due_amount,0)),0) FROM orders WHERE $where", $types, $params);
        } else {
            $due_sales = max(0, $today_revenue - $paid_sales);
        }

        if (columnExists($conn, 'orders', 'discount')) {
            $order_discount = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(discount,0)),0) FROM orders WHERE $where", $types, $params);
        }
        if (columnExists($conn, 'orders', 'delivery_cost')) {
            $order_delivery = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(delivery_cost,0)),0) FROM orders WHERE $where", $types, $params);
        }
    } else {
        $debug_warnings[] = "orders table not found.";
    }

    if (tableExists($conn, 'customer_due_receives')) {
        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'customer_due_receives', $types, $params, $shop_id);
        if (columnExists($conn, 'customer_due_receives', 'receive_date')) {
            $where .= " AND receive_date = ? ";
            $types .= "s";
            $params[] = $today_date;
        } else {
            $where .= " AND created_at >= ? AND created_at <= ? ";
            $types .= "ss";
            $params[] = $today_start;
            $params[] = $today_end;
        }

        $due_receive += (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM customer_due_receives WHERE $where", $types, $params);

        if (columnExists($conn, 'customer_due_receives', 'payment_method')) {
            $cash_due_receive = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM customer_due_receives WHERE $where" . paymentMethodConditionSQL('payment_method', 'cash'), $types, $params);
            $bkash_due_receive = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM customer_due_receives WHERE $where" . paymentMethodConditionSQL('payment_method', 'bkash'), $types, $params);
            $nagad_due_receive = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM customer_due_receives WHERE $where" . paymentMethodConditionSQL('payment_method', 'nagad'), $types, $params);
            $bank_due_receive = (float)scalarQuery($conn, "SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM customer_due_receives WHERE $where" . paymentMethodConditionSQL('payment_method', 'bank'), $types, $params);
        }
    }

    if (tableExists($conn, 'payments')) {
        $dateWhere = "p.created_at >= ? AND p.created_at <= ? AND u.shop_id = ?";
        $dateTypes = "ssi";
        $dateParams = [$today_start, $today_end, $shop_id];

        $inst_receive = (float)scalarQuery($conn, "SELECT COALESCE(SUM(p.amount),0) FROM payments p INNER JOIN users u ON u.id = p.created_by WHERE $dateWhere AND p.type = 'installment_receive'", $dateTypes, $dateParams);
        $adv_receive = (float)scalarQuery($conn, "SELECT COALESCE(SUM(p.amount),0) FROM payments p INNER JOIN users u ON u.id = p.created_by WHERE $dateWhere AND p.type = 'advance_receive'", $dateTypes, $dateParams);
        $supplier_payment = (float)scalarQuery($conn, "SELECT COALESCE(SUM(p.amount),0) FROM payments p INNER JOIN users u ON u.id = p.created_by WHERE $dateWhere AND p.type = 'supplier_payment'", $dateTypes, $dateParams);
    }

    if (tableExists($conn, 'expenses')) {
        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'expenses', $types, $params, $shop_id);

        if (columnExists($conn, 'expenses', 'expense_date')) {
            $whereToday = $where . " AND expense_date = ? ";
            $typesToday = $types . "s";
            $paramsToday = array_merge($params, [$today_date]);
        } else {
            $whereToday = $where . " AND created_at >= ? AND created_at <= ? ";
            $typesToday = $types . "ss";
            $paramsToday = array_merge($params, [$today_start, $today_end]);
        }

        $daily_expense = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $whereToday", $typesToday, $paramsToday);

        if (columnExists($conn, 'expenses', 'status')) {
            $daily_expense_paid = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $whereToday AND status = 'paid'", $typesToday, $paramsToday);
            $daily_expense_due = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $whereToday AND status = 'due'", $typesToday, $paramsToday);
            $expense_due_total = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $where AND status = 'due'", $types, $params);
        } else {
            $daily_expense_paid = $daily_expense;
        }
    }

    if (tableExists($conn, 'suppliers')) {
        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'suppliers', $types, $params, $shop_id);
        if (columnExists($conn, 'suppliers', 'status')) {
            $where .= " AND (status = 1 OR status = '1' OR LOWER(CAST(status AS CHAR)) = 'active') ";
        }

        if (columnExists($conn, 'suppliers', 'current_due')) {
            $supplier_due = (float)scalarQuery($conn, "SELECT COALESCE(SUM(current_due),0) FROM suppliers WHERE $where AND COALESCE(current_due,0) > 0", $types, $params);
        } elseif (columnExists($conn, 'suppliers', 'due_amount')) {
            $supplier_due = (float)scalarQuery($conn, "SELECT COALESCE(SUM(due_amount),0) FROM suppliers WHERE $where AND COALESCE(due_amount,0) > 0", $types, $params);
        }
    }

$purchase_total = 0;
$purchase_entry_paid = 0;
$purchase_entry_due = 0;
$purchase_paid = 0;
$purchase_due = 0;
$purchase_invoice_count = 0;
$supplier_payment_today = 0;
$total_purchase_paid_today = 0;

if (tableExists($conn, 'purchases')) {
    $types = 'iss';
    $params = [$shop_id, $today_start, $today_end];
    $where = "s.shop_id = ? AND p.created_at >= ? AND p.created_at <= ?";

    $hasAllocationTable = tableExists($conn, 'supplier_payment_allocations')
        && rvTableHasColumns($conn, 'supplier_payment_allocations', ['purchase_id', 'allocated_amount']);

    $allocationJoin = $hasAllocationTable
        ? " LEFT JOIN (
                SELECT purchase_id, COALESCE(SUM(allocated_amount),0) AS modal_allocated
                FROM supplier_payment_allocations
                GROUP BY purchase_id
            ) spa ON spa.purchase_id = p.id "
        : "";

    $purchaseFrom = " FROM purchases p
        INNER JOIN suppliers s ON s.id = p.supplier_id
        $allocationJoin
        WHERE $where";

    $purchase_invoice_count = (int)scalarQuery(
        $conn,
        "SELECT COUNT(*)" . $purchaseFrom,
        $types,
        $params
    );

    if (columnExists($conn, 'purchases', 'grand_total')) {
        $purchase_total = (float)scalarQuery(
            $conn,
            "SELECT COALESCE(SUM(GREATEST(COALESCE(p.grand_total,0),0)),0)" . $purchaseFrom,
            $types,
            $params
        );
    }

    if (columnExists($conn, 'purchases', 'paid_amount')) {
        $entryPaidExpr = $hasAllocationTable
            ? "GREATEST(COALESCE(p.paid_amount,0) - COALESCE(spa.modal_allocated,0),0)"
            : "GREATEST(COALESCE(p.paid_amount,0),0)";

        $purchase_entry_paid = (float)scalarQuery(
            $conn,
            "SELECT COALESCE(SUM(LEAST($entryPaidExpr, GREATEST(COALESCE(p.grand_total,0),0))),0)" . $purchaseFrom,
            $types,
            $params
        );
    }

    if (columnExists($conn, 'purchases', 'due_amount')) {
        $purchase_entry_due = (float)scalarQuery(
            $conn,
            "SELECT COALESCE(SUM(GREATEST(COALESCE(p.due_amount,0),0)),0)" . $purchaseFrom,
            $types,
            $params
        );
    } else {
        $purchase_entry_due = max(0, $purchase_total - $purchase_entry_paid);
    }
}

if (tableExists($conn, 'supplier_payments')) {
    $supplierPaymentScope = columnExists($conn, 'supplier_payments', 'purchase_id')
        ? " AND sp.purchase_id IS NULL "
        : "";

    $supplier_payment_today = (float)scalarQuery(
        $conn,
        "SELECT COALESCE(SUM(GREATEST(COALESCE(sp.payment_amount,0),0)),0)
         FROM supplier_payments sp
         INNER JOIN suppliers s ON s.id = sp.supplier_id
         WHERE s.shop_id = ? AND DATE(sp.payment_date) = ? $supplierPaymentScope",
        'is',
        [$shop_id, $today_date]
    );
} else {
    $supplier_payment_today = max(0, (float)$supplier_payment);
}

$total_purchase_paid_today = $purchase_entry_paid + $supplier_payment_today;
$purchase_paid = $total_purchase_paid_today;
$purchase_due = $purchase_entry_due;
$supplier_payment = $supplier_payment_today;

    if (tableExists($conn, 'assets')) {
        $cash_in_hand = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'cash' AND status = 'active'", 'i', [$shop_id]);
        $bkash_balance = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'bkash' AND status = 'active'", 'i', [$shop_id]);
        $nagad_balance = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'nagad' AND status = 'active'", 'i', [$shop_id]);
        $bank_deposit = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'bank' AND status = 'active'", 'i', [$shop_id]);
    } else {
        $debug_warnings[] = "assets table not found.";
    }

    if (tableExists($conn, 'liabilities')) {
        $bank_loan = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND liability_type = 'bank_loan' AND status = 'active'", 'i', [$shop_id]);
        $credit_card = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND liability_type = 'credit_card_loan' AND status = 'active'", 'i', [$shop_id]);
        $investor_loan = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND liability_type = 'investor_loan' AND status = 'active'", 'i', [$shop_id]);
    } else {
        $debug_warnings[] = "liabilities table not found.";
    }

    $cash_inflow_today = $paid_sales + $due_receive + $inst_receive + $adv_receive;
    $cash_outflow_today = $daily_expense_paid + $purchase_entry_paid + $supplier_payment_today;
    $net_cash_today = $cash_inflow_today - $cash_outflow_today;

    $total_assets = $item_stock_value + $receivable_customer_due + $cash_in_hand + $bkash_balance + $nagad_balance + $bank_deposit;
    $total_liability = $supplier_due + $expense_due_total + $customer_advance + $bank_loan + $credit_card + $investor_loan;
}

// ============================================================
// QUICK PAYMENT MODAL DATA
// ============================================================
$rv_payment_assets = [];
$rv_due_customers = [];
$rv_due_suppliers = [];

if (isset($conn) && $conn) {
    if (tableExists($conn, 'assets')) {
        $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount FROM assets WHERE shop_id = ? AND status = 'active' AND asset_type IN ('cash','bkash','nagad','bank','other') ORDER BY FIELD(asset_type,'cash','bkash','nagad','bank','other'), asset_name ASC");
        if ($stmt) {
            $stmt->bind_param('i', $shop_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rv_payment_assets[] = $row;
            }
        }
    }

    if (tableExists($conn, 'customers')) {
        if (columnExists($conn, 'customers', 'shop_name') && columnExists($conn, 'customers', 'name')) {
            $nameSql = "COALESCE(NULLIF(shop_name,''), NULLIF(name,''), CONCAT('Customer #', id))";
        } elseif (columnExists($conn, 'customers', 'shop_name')) {
            $nameSql = "COALESCE(NULLIF(shop_name,''), CONCAT('Customer #', id))";
        } elseif (columnExists($conn, 'customers', 'name')) {
            $nameSql = "COALESCE(NULLIF(name,''), CONCAT('Customer #', id))";
        } else {
            $nameSql = "CONCAT('Customer #', id)";
        }

        $phoneSql = "''";
        foreach (['mobile','phone','customer_mobile','mobile_no','contact','contact_number'] as $col) {
            if (columnExists($conn, 'customers', $col)) {
                $phoneSql = "COALESCE(NULLIF(`$col`,''), '')";
                break;
            }
        }

        $dueSql = columnExists($conn, 'customers', 'due_amount') ? "COALESCE(due_amount,0)" : "0";

        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'customers', $types, $params, $shop_id);
        if (columnExists($conn, 'customers', 'status')) {
            $where .= " AND (status = 1 OR status = '1' OR LOWER(CAST(status AS CHAR)) = 'active') ";
        }

        $stmt = $conn->prepare("SELECT id, $nameSql AS display_name, $phoneSql AS phone, $dueSql AS due_amount FROM customers WHERE $where ORDER BY display_name ASC");
        if ($stmt) {
            if ($types !== '') $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rv_due_customers[] = $row;
        }
    }

    if (tableExists($conn, 'suppliers')) {
        if (columnExists($conn, 'suppliers', 'supplier_name')) {
            $nameSql = "COALESCE(NULLIF(supplier_name,''), CONCAT('Supplier #', id))";
        } elseif (columnExists($conn, 'suppliers', 'name')) {
            $nameSql = "COALESCE(NULLIF(name,''), CONCAT('Supplier #', id))";
        } else {
            $nameSql = "CONCAT('Supplier #', id)";
        }

        $phoneSql = "''";
        foreach (['mobile','phone','supplier_mobile','mobile_no','contact','contact_number'] as $col) {
            if (columnExists($conn, 'suppliers', $col)) {
                $phoneSql = "COALESCE(NULLIF(`$col`,''), '')";
                break;
            }
        }

        if (columnExists($conn, 'suppliers', 'current_due')) {
            $dueSql = "COALESCE(current_due,0)";
        } elseif (columnExists($conn, 'suppliers', 'due_amount')) {
            $dueSql = "COALESCE(due_amount,0)";
        } else {
            $dueSql = "0";
        }

        $types = '';
        $params = [];
        $where = buildShopWhere($conn, 'suppliers', $types, $params, $shop_id);
        if (columnExists($conn, 'suppliers', 'status')) {
            $where .= " AND (status = 1 OR status = '1' OR LOWER(CAST(status AS CHAR)) = 'active') ";
        }

        $stmt = $conn->prepare("SELECT id, $nameSql AS supplier_name, $phoneSql AS phone, $dueSql AS current_due FROM suppliers WHERE $where AND ($dueSql) > 0 ORDER BY current_due DESC, supplier_name ASC");
        if ($stmt) {
            if ($types !== '') $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rv_due_suppliers[] = $row;
        }
    }
}

$due_supplier_count = count($rv_due_suppliers);

$sales_paid_percent = $today_revenue > 0 ? percentWidth(($paid_sales / $today_revenue) * 100) : 0;
$sales_due_percent = $today_revenue > 0 ? percentWidth(($due_sales / $today_revenue) * 100) : 0;
$expense_percent = $cash_inflow_today > 0 ? percentWidth(($cash_outflow_today / $cash_inflow_today) * 100) : 0;
$net_percent = $cash_inflow_today > 0 ? percentWidth(($net_cash_today / $cash_inflow_today) * 100) : 0;

$quickActions = [
    ['label' => 'POS',         'title' => 'Point of Sale',       'href' => 'pos.php',                       'icon' => 'fas fa-cash-register', 'ring' => 'v3-prod', 'modal_size' => 'wide'],
    ['label' => 'Sales List',   'title' => 'Sales List',           'href' => 'sales_list.php?modal=1',             'icon' => 'fas fa-file-invoice',  'ring' => 'v3-prod', 'modal_size' => 'wide'],
    ['label' => 'Due RCV',      'title' => 'Customer Payment',     'href' => '#', 'local_modal' => 'rvCustomerPaymentModal', 'icon' => 'fas fa-hand-holding-dollar', 'ring' => 'v3-prof', 'modal_size' => 'lg'],
    ['label' => 'Supplier Pay', 'title' => 'Supplier Payment',     'href' => '#', 'local_modal' => 'rvSupplierPaymentModal', 'icon' => 'fas fa-truck-loading', 'ring' => 'v3-cat',  'modal_size' => 'lg'],
	
    ['label' => 'Purchase',     'title' => 'Purchase',             'href' => 'purchase_list.php?modal=1',          'icon' => 'fas fa-cart-shopping', 'ring' => 'v3-rep',  'modal_size' => 'wide'],
	
    ['label' => 'Expense',      'title' => 'Expense Management',   'href' => 'expense_management.php?modal=1',    'icon' => 'fas fa-receipt',       'ring' => 'v3-acc',  'modal_size' => 'wide'],
];

$summaryCards = [
    ['title' => 'POS Sales', 'lines' => [
        ['label' => 'Sales', 'value' => taka($today_revenue), 'class' => 'v3-green'],
        ['label' => 'Paid', 'value' => taka($paid_sales), 'class' => 'v3-blue'],
        ['label' => 'Due', 'value' => taka($due_sales), 'class' => 'v3-red'],
        ['label' => 'Invoice', 'value' => number_format($total_sales_count), 'class' => 'v3-gray'],
    ]],
    ['title' => 'Receive', 'lines' => [
        ['label' => 'Due Rec.', 'value' => taka($due_receive), 'class' => 'v3-blue'],
    ]],
    ['title' => 'Expense', 'lines' => [
        ['label' => 'Total', 'value' => taka($daily_expense), 'class' => 'v3-green'],
        ['label' => 'Paid', 'value' => taka($daily_expense_paid), 'class' => 'v3-blue'],
        ['label' => 'Due', 'value' => taka($daily_expense_due), 'class' => 'v3-red'],
    ]],
    ['title' => 'Purchase Overview', 'lines' => [
        ['label' => 'Today Purchase', 'value' => taka($purchase_total), 'class' => 'v3-green'],
        ['label' => 'Paid at Purchase', 'value' => taka($purchase_entry_paid), 'class' => 'v3-blue'],
        ['label' => 'Paid Today (Supplier)', 'value' => taka($supplier_payment_today), 'class' => 'v3-orange'],
        ['label' => 'New Purchase Due', 'value' => taka($purchase_entry_due), 'class' => 'v3-red'],
    ]],
];

$assetItems = [
    ['label' => 'Cash In Hand', 'value' => $cash_in_hand, 'total' => true],
    ['label' => 'bKash', 'value' => $bkash_balance],
    ['label' => 'Nagad', 'value' => $nagad_balance],
    ['label' => 'Bank Deposit', 'value' => $bank_deposit],
    ['label' => 'Item Stock Value', 'value' => $item_stock_value],
    ['label' => 'Receivable Customer Due', 'value' => $receivable_customer_due],
    ['label' => 'Total Assets', 'value' => $total_assets, 'total' => true],
];

$liabilityItems = [
    ['label' => 'Supplier Due', 'value' => $supplier_due],
    ['label' => 'Expense Due', 'value' => $expense_due_total],
    ['label' => 'Bank Loan', 'value' => $bank_loan],
    ['label' => 'Credit Card Loan', 'value' => $credit_card],
    ['label' => 'Investor Loan', 'value' => $investor_loan],
    ['label' => 'Total Liability', 'value' => $total_liability, 'total' => true],
];

$netProfitClass = $net_profit >= 0 ? 'v3-green' : 'v3-red';
$netProfitIcon = $net_profit >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
$netProfitLabel = $net_profit >= 0 ? 'Profit' : 'Loss';
$netProfitColor = $net_profit >= 0 ? '#a3e635' : '#f87171';
?>

<?php include "header.php"; ?>
<?php include "sidebar.php"; ?>

<style>
:root {
  --rv-bg: #f0f4f8;
  --rv-card: #ffffff;
  --rv-border: #e2e8f0;
  --rv-text: #1e293b;
  --rv-muted: #64748b;
  --rv-green: #16a34a;
  --rv-blue: #2563eb;
  --rv-red: #dc2626;
  --rv-orange: #d97706;
  --rv-shadow: 0 1px 8px rgba(0,0,0,0.04);
  --rv-shadow-hover: 0 16px 40px rgba(0,0,0,0.10);
  --rv-radius: 18px;
  --rv-font-base: 16px; /* Add this */
}

.v3-main {
  flex: 1;
  padding: 24px;
  background: var(--rv-bg);
  min-height: 100vh;
  font-family: 'Segoe UI', system-ui, sans-serif;
  font-size: var(--rv-font-base); /* Add this */
  line-height: 1.6; /* Add this */
}

.v3-fixed-actionbar {
  position: sticky;
  top: 0;
  z-index: 9999;
  margin: -24px -24px 24px;
  padding: 12px 24px;
  background: linear-gradient(135deg, #052e16 0%, #14532d 55%, #166534 100%);
  backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255,255,255,0.16);
  box-shadow: 0 12px 34px rgba(5, 46, 22, 0.28);
}
.v3-fixed-actionbar-inner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}
.v3-fixed-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #ffffff;
  font-weight: 900;
  white-space: nowrap;
  letter-spacing: 0.2px;
}
.v3-fixed-brand i {
  width: 38px;
  height: 38px;
  border-radius: 14px;
  background: rgba(255,255,255,0.15);
  color: #bbf7d0;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.16);
}
.v3-fixed-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.v3-fixed-actions::-webkit-scrollbar { height: 4px; }
.v3-fixed-actions::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.35); border-radius: 20px; }
.v3-fixed-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 14px;
  border-radius: 999px;
  background: rgba(255,255,255,0.12);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.18);
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 800;
  white-space: nowrap;
  transition: all 0.25s ease;
  cursor: pointer;
  font-family: inherit;
}
.v3-fixed-btn:hover {
  background: rgba(255,255,255,0.22);
  color: #ffffff;
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(0,0,0,0.18);
}
.v3-fixed-btn-primary, .v3-fixed-btn-primary:hover {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #111827;
  border-color: rgba(255,255,255,0.28);
}
.v3-header, .v3-summary-card, .v3-source-card, .v3-balance-card, .v3-stat-card, .v3-panel, .v3-menu-item, .v3-stock-card {
  background: var(--rv-card);
  border: 1px solid var(--rv-border);
  box-shadow: var(--rv-shadow);
}
.v3-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 20px;
  padding: 22px 28px;
  border-radius: 16px;
}
.v3-header-text h4 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px; color: var(--rv-text); }
.v3-header-text h4 i { color: #f59e0b; margin-right: 8px; }
.v3-header-text p { color: var(--rv-muted); margin: 0; font-size: 0.85rem; }
.v3-live-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #ecfdf5;
  color: #059669;
  padding: 8px 18px;
  border-radius: 50px;
  font-size: 0.8rem;
  font-weight: 700;
}
.v3-pulse-dot {
  width: 8px;
  height: 8px;
  background: #22c55e;
  border-radius: 50%;
  animation: v3-pulse 2s infinite;
}
@keyframes v3-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(1.35); }
}
.v3-summary-row, .v3-source-row, .v3-top-row, .v3-balance-row, .v3-mid-row {
  display: grid;
  gap: 16px;
  margin-bottom: 20px;
}
.v3-summary-row, .v3-top-row { grid-template-columns: repeat(4, 1fr); }
.v3-source-row { grid-template-columns: repeat(3, 1fr); }
.v3-balance-row, .v3-mid-row { grid-template-columns: repeat(2, 1fr); }
.v3-summary-card { padding: 20px; border-radius: 14px; }
.v3-summary-card h6 { margin: 0 0 12px; font-size: 0.9rem; font-weight: 800; color: var(--rv-text); }
.v3-summary-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 7px 0;
  font-size: 0.85rem;
  border-bottom: 1px solid #f1f5f9;
}
.v3-summary-line:last-child { border-bottom: none; }
.v3-summary-label { font-weight: 700; }
.v3-summary-value { font-weight: 800; font-size: 0.9rem; white-space: nowrap; }
.v3-green { color: var(--rv-green); }
.v3-blue { color: var(--rv-blue); }
.v3-red { color: var(--rv-red); }
.v3-orange { color: var(--rv-orange); }
.v3-gray { color: #475569; }
.v3-section-title {
  font-size: 0.8rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #94a3b8;
  margin: 30px 0 14px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.v3-section-title::after { content: ''; flex: 1; height: 1px; background: var(--rv-border); }
.v3-section-title i { color: #f59e0b; }
.v3-source-note {
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  color: #475569;
  padding: 9px 12px;
  border-radius: 12px;
  font-size: 0.78rem;
  margin-bottom: 16px;
}
.v3-source-card {
  border-radius: 16px;
  padding: 20px;
  position: relative;
  overflow: hidden;
}
.v3-source-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; }
.v3-source-pos::before { background: var(--rv-blue); }
.v3-source-online::before { background: var(--rv-green); }
.v3-source-total::before { background: #f59e0b; }
.v3-source-title { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; }
.v3-source-title h6 { margin: 0; font-size: 0.95rem; color: var(--rv-text); font-weight: 800; }
.v3-source-icon { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.v3-source-pos .v3-source-icon { background: #eff6ff; color: var(--rv-blue); }
.v3-source-online .v3-source-icon { background: #ecfdf5; color: var(--rv-green); }
.v3-source-total .v3-source-icon { background: #fffbeb; color: var(--rv-orange); }
.v3-source-big { font-size: 1.8rem; font-weight: 900; color: var(--rv-text); line-height: 1; margin-bottom: 4px; }
.v3-source-sub { font-size: 0.78rem; color: var(--rv-muted); margin-bottom: 12px; }
.v3-balance-card { border-radius: 14px; overflow: hidden; }
.v3-balance-header { padding: 14px 20px; font-size: 1.1rem; font-weight: 800; text-align: center; }
.v3-assets-header { background: #e0f2fe; color: #0369a1; }
.v3-liability-header { background: #fee2e2; color: #b91c1c; }
.v3-balance-body { padding: 16px 20px; }
.v3-balance-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  font-size: 0.9rem;
  border-bottom: 1px solid #f1f5f9;
}
.v3-balance-total {
  border-bottom: none;
  padding-top: 14px;
  margin-top: 8px;
  border-top: 2px solid var(--rv-border);
  font-weight: 800;
  font-size: 1rem;
}
.v3-balance-label { font-weight: 700; color: #334155; }
.v3-balance-value { font-weight: 800; color: var(--rv-text); font-family: 'Courier New', monospace; white-space: nowrap; }
.v3-stat-card { padding: 22px; border-radius: 20px; transition: all 0.3s ease; position: relative; overflow: hidden; }
.v3-stat-card:hover, .v3-menu-item:hover, .v3-stock-card:hover { transform: translateY(-4px); box-shadow: var(--rv-shadow-hover); }
.v3-stat-card::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; }
.v3-stat-revenue::after { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.v3-stat-paid::after { background: linear-gradient(90deg, #10b981, #34d399); }
.v3-stat-due::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.v3-stat-expense::after { background: linear-gradient(90deg, #ef4444, #f87171); }
.v3-stat-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 12px; }
.v3-stat-revenue .v3-stat-icon { background: #eff6ff; color: #3b82f6; }
.v3-stat-paid .v3-stat-icon { background: #ecfdf5; color: #10b981; }
.v3-stat-due .v3-stat-icon { background: #fffbeb; color: #f59e0b; }
.v3-stat-expense .v3-stat-icon { background: #fef2f2; color: #ef4444; }
.v3-stat-label { font-size: 0.75rem; color: var(--rv-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 6px; display: block; }
.v3-stat-value { font-size: 1.6rem; font-weight: 900; color: var(--rv-text); display: block; margin-bottom: 4px; }
.v3-stat-sub { font-size: 0.75rem; color: #94a3b8; font-weight: 600; }
.v3-panel { padding: 24px; border-radius: 20px; }
.v3-panel h5 { margin: 0 0 20px; font-size: 0.92rem; font-weight: 800; color: var(--rv-text); display: flex; align-items: center; gap: 8px; }
.v3-bar-list { display: flex; flex-direction: column; gap: 18px; }
.v3-bar-item { display: grid; grid-template-columns: 1fr 120px auto; align-items: center; gap: 12px; }
.v3-bar-item span { font-size: 0.85rem; color: #475569; font-weight: 700; }
.v3-bar-item b { font-size: 0.9rem; color: var(--rv-text); font-weight: 800; text-align: right; white-space: nowrap; }
.v3-bar-track { height: 8px; background: #f1f5f9; border-radius: 50px; overflow: hidden; }
.v3-bar-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #60a5fa); border-radius: 50px; transition: width 0.6s ease; }
.v3-bar-fill.v3-red { background: linear-gradient(90deg, #ef4444, #f87171); }
.v3-bar-fill.v3-greenbar { background: linear-gradient(90deg, #10b981, #34d399); }
.v3-dues-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.v3-due-card { background: #f8fafc; padding: 18px; border-radius: 16px; display: flex; flex-direction: column; align-items: center; gap: 6px; border: 1px solid var(--rv-border); }
.v3-due-card i { font-size: 22px; color: var(--rv-muted); }
.v3-due-card span { font-size: 0.75rem; color: var(--rv-muted); font-weight: 700; }
.v3-due-card b { font-size: 1.1rem; color: var(--rv-text); font-weight: 900; }
.v3-grand {
  background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
  color: white;
  padding: 24px 28px;
  border-radius: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
  box-shadow: 0 8px 32px rgba(30, 41, 59, 0.25);
}
.v3-grand-left { display: flex; align-items: center; gap: 16px; }
.v3-grand-left i { width: 48px; height: 48px; background: rgba(250, 204, 21, 0.15); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #facc15; }
.v3-grand-left div { display: flex; flex-direction: column; }
.v3-grand-label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 4px; }
.v3-grand-value { font-size: 1.8rem; font-weight: 900; color: #facc15; }
.v3-grand-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.v3-grand-chip { background: rgba(255,255,255,0.08); padding: 4px 14px; border-radius: 50px; font-size: 0.7rem; color: #cbd5e1; font-weight: 700; }
.v3-grand-progress { width: 160px; height: 6px; background: rgba(255,255,255,0.1); border-radius: 50px; overflow: hidden; }
.v3-grand-progress-fill { height: 100%; background: linear-gradient(90deg, #facc15, #fbbf24); border-radius: 50px; transition: width 0.6s ease; }

.v3-profit-card {
  background: linear-gradient(135deg, #064e3b 0%, #047857 100%);
  padding: 24px 28px;
  border-radius: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
  box-shadow: 0 8px 32px rgba(4, 120, 87, 0.25);
}
.v3-profit-left { display: flex; align-items: center; gap: 16px; }
.v3-profit-left i { width: 48px; height: 48px; background: rgba(250, 204, 21, 0.15); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #facc15; }
.v3-profit-left div { display: flex; flex-direction: column; }
.v3-profit-label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 4px; }
.v3-profit-value { font-size: 1.8rem; font-weight: 900; }
.v3-profit-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.v3-profit-chip { background: rgba(255,255,255,0.08); padding: 4px 14px; border-radius: 50px; font-size: 0.7rem; color: #cbd5e1; font-weight: 700; }
.v3-profit-detail { font-size: 0.75rem; color: #94a3b8; }

.rv-purchase-kpis {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.rv-purchase-kpi {
  background: #ffffff;
  border: 1px solid var(--rv-border);
  border-radius: 16px;
  padding: 18px;
  box-shadow: var(--rv-shadow);
  display: flex;
  align-items: center;
  gap: 13px;
  min-width: 0;
}
.rv-purchase-kpi-icon {
  width: 46px;
  height: 46px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 46px;
  background: #ecfdf5;
  color: #059669;
  font-size: 18px;
}
.rv-purchase-kpi.is-paid .rv-purchase-kpi-icon { background: #eff6ff; color: #2563eb; }
.rv-purchase-kpi.is-due .rv-purchase-kpi-icon { background: #fef2f2; color: #dc2626; }
.rv-purchase-kpi.is-total-due .rv-purchase-kpi-icon { background: #fff7ed; color: #ea580c; }
.rv-purchase-kpi-copy { min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.rv-purchase-kpi-label { font-size: 0.72rem; color: #64748b; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; }
.rv-purchase-kpi-value { font-size: 1.25rem; line-height: 1.2; color: #0f172a; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rv-purchase-kpi-sub { font-size: 0.72rem; color: #94a3b8; font-weight: 700; }
.rv-supplier-due-panel {
  background: #ffffff;
  border: 1px solid var(--rv-border);
  border-radius: 18px;
  box-shadow: var(--rv-shadow);
  overflow: hidden;
  margin-bottom: 22px;
}
.rv-supplier-due-head {
  padding: 17px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  border-bottom: 1px solid #e2e8f0;
  background: linear-gradient(135deg, #f8fafc, #ffffff);
}
.rv-supplier-due-title { display: flex; align-items: center; gap: 10px; min-width: 0; }
.rv-supplier-due-title i { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #fff7ed; color: #ea580c; }
.rv-supplier-due-title h5 { margin: 0 0 2px; font-size: .95rem; color: #0f172a; font-weight: 900; }
.rv-supplier-due-title p { margin: 0; font-size: .74rem; color: #64748b; font-weight: 700; }
.rv-supplier-due-total { text-align: right; flex-shrink: 0; }
.rv-supplier-due-total span { display: block; font-size: .7rem; color: #64748b; font-weight: 800; text-transform: uppercase; }
.rv-supplier-due-total b { display: block; font-size: 1.15rem; color: #dc2626; font-weight: 900; }
.rv-supplier-due-scroll { max-height: 410px; overflow: auto; }
.rv-supplier-due-table { width: 100%; border-collapse: collapse; min-width: 620px; }
.rv-supplier-due-table th { position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #64748b; padding: 11px 16px; text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid #e2e8f0; }
.rv-supplier-due-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: .84rem; color: #334155; vertical-align: middle; }
.rv-supplier-due-table tr:last-child td { border-bottom: 0; }
.rv-supplier-name { font-weight: 900; color: #0f172a; }
.rv-supplier-phone { display: block; margin-top: 2px; color: #94a3b8; font-size: .72rem; font-weight: 700; }
.rv-supplier-due-amount { color: #dc2626; font-weight: 900; white-space: nowrap; }
.rv-supplier-pay-now { border: 0; border-radius: 999px; padding: 7px 13px; background: #0f766e; color: #ffffff; font-size: .76rem; font-weight: 900; cursor: pointer; white-space: nowrap; }
.rv-supplier-pay-now:hover { background: #115e59; }
.rv-supplier-empty { padding: 28px 18px; text-align: center; color: #64748b; font-weight: 800; }

/* UNIFIED PAYMENT MODAL STYLES */
.rv-pay-summary {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-bottom: 14px;
}
.rv-pay-summary-box { 
  background: #f8fafc; 
  border: 1px solid #e2e8f0; 
  border-radius: 10px; 
  padding: 10px 14px; 
}
.rv-pay-summary-box span { 
  display: block; 
  color: #64748b; 
  font-size: .72rem; 
  font-weight: 800; 
  margin-bottom: 3px; 
}
.rv-pay-summary-box b { 
  display: block; 
  color: #0f172a; 
  font-size: 1rem; 
  font-weight: 900; 
}
.rv-pay-summary-box.is-due b { color: #dc2626; }
.rv-pay-summary-box.is-balance b { color: #2563eb; }
.rv-pay-summary-box.is-profit b { color: #16a34a; }

.rv-pay-overlay { 
  position: fixed; 
  inset: 0; 
  z-index: 25000; 
  display: none; 
  align-items: center; 
  justify-content: center; 
  padding: 18px; 
  background: rgba(2, 6, 23, 0.55); 
}
.rv-pay-overlay.rv-pay-show { display: flex; }
.rv-pay-card { 
  width: min(820px, 96vw); 
  max-height: 94vh; 
  background: #ffffff; 
  border-radius: 16px; 
  box-shadow: 0 24px 72px rgba(0,0,0,0.36); 
  overflow: hidden; 
  font-family: 'Segoe UI', system-ui, sans-serif; 
}
.rv-pay-head { 
  height: 58px; 
  padding: 0 22px; 
  display: flex; 
  align-items: center; 
  justify-content: space-between; 
  gap: 12px; 
}
.rv-pay-head.rv-pay-green { background: linear-gradient(135deg, #15803d, #16a34a); color: #fff; }
.rv-pay-head.rv-pay-orange { background: linear-gradient(135deg, #0f766e, #0d9488); color: #fff; }
.rv-pay-title { font-size: 1.15rem; font-weight: 900; letter-spacing: 0.2px; display: flex; align-items: center; gap: 10px; }
.rv-pay-title i { width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(255,255,255,0.14); }
.rv-pay-close { 
  border: 0; 
  background: rgba(255,255,255,0.14); 
  color: #ffffff; 
  border-radius: 9px; 
  font-size: 22px; 
  line-height: 1; 
  cursor: pointer; 
  padding: 4px 10px; 
}
.rv-pay-close:hover { background: rgba(239,68,68,0.9); }
.rv-pay-body { 
  padding: 18px 20px 10px; 
  max-height: calc(94vh - 120px); 
  overflow-y: auto; 
}
.rv-pay-row { 
  display: grid; 
  grid-template-columns: 1fr 1fr; 
  gap: 16px; 
}
.rv-pay-group { margin-bottom: 12px; }
.rv-pay-group.rv-full { grid-column: 1 / -1; }
.rv-pay-label { 
  display: block; 
  margin-bottom: 5px; 
  font-size: 0.85rem; 
  line-height: 1.2; 
  color: #27272a; 
  font-weight: 900; 
}
.rv-pay-label .req { color: #e11d48; margin-left: 2px; }
.rv-pay-input, .rv-pay-select, .rv-pay-textarea { 
  width: 100%; 
  border: 1px solid #cfd6dc; 
  border-radius: 8px; 
  background: #ffffff; 
  padding: 8px 14px; 
  font-size: 0.95rem; 
  color: #3f3f46; 
  outline: none; 
}
.rv-pay-input, .rv-pay-select { height: 44px; }
.rv-pay-textarea { min-height: 68px; resize: vertical; }
.rv-pay-input:focus, .rv-pay-select:focus, .rv-pay-textarea:focus { 
  border-color: #22c55e; 
  box-shadow: 0 0 0 3px rgba(34,197,94,0.12); 
}
.rv-pay-input[readonly] { background: #f1f5f9; color: #475569; }
.rv-native-select { 
  position: absolute !important; 
  left: -10000px !important; 
  width: 1px !important; 
  height: 1px !important; 
  opacity: 0 !important; 
  pointer-events: none !important; 
}
.rv-live-select { 
  position: relative; 
  width: 100%; 
  z-index: 1; 
}
.rv-live-select.rv-live-open { z-index: 20; }
.rv-live-toggle { 
  width: 100%; 
  min-height: 44px; 
  border: 1px solid #cfd6dc; 
  border-radius: 8px; 
  background: #ffffff; 
  color: #27272a; 
  padding: 8px 14px; 
  display: flex; 
  align-items: center; 
  justify-content: space-between; 
  gap: 10px; 
  font-size: 0.95rem; 
  font-weight: 700; 
  cursor: pointer; 
  text-align: left; 
}
.rv-live-toggle:focus, .rv-live-select.rv-live-open .rv-live-toggle { 
  border-color: #22c55e; 
  box-shadow: 0 0 0 3px rgba(34,197,94,0.12); 
}
.rv-live-value { 
  min-width: 0; 
  overflow: hidden; 
  text-overflow: ellipsis; 
  white-space: nowrap; 
  color: #3f3f46; 
}
.rv-live-value.rv-placeholder { color: #8a8f98; }
.rv-live-panel { 
  display: none; 
  position: absolute; 
  top: calc(100% + 4px); 
  left: 0; 
  right: 0; 
  background: #ffffff; 
  border: 1px solid #cbd5e1; 
  border-radius: 10px; 
  box-shadow: 0 18px 42px rgba(15,23,42,0.22); 
  overflow: hidden; 
}
.rv-live-select.rv-live-open .rv-live-panel { display: block; }
.rv-live-search { 
  position: relative; 
  padding: 8px; 
  background: #f8fafc; 
  border-bottom: 1px solid #e2e8f0; 
}
.rv-live-search i { 
  position: absolute; 
  left: 20px; 
  top: 50%; 
  transform: translateY(-50%); 
  color: #94a3b8; 
  font-size: 13px; 
}
.rv-live-input { 
  width: 100%; 
  height: 40px; 
  border: 1px solid #cbd5e1; 
  border-radius: 6px; 
  padding: 8px 12px 8px 34px; 
  outline: none; 
  font-size: 0.9rem; 
}
.rv-live-input:focus { 
  border-color: #22c55e; 
  box-shadow: 0 0 0 3px rgba(34,197,94,0.10); 
}
.rv-live-list { max-height: 220px; overflow-y: auto; padding: 6px; }
.rv-live-option { 
  width: 100%; 
  border: 0; 
  background: #ffffff; 
  border-radius: 7px; 
  padding: 9px 12px; 
  display: flex; 
  align-items: center; 
  justify-content: space-between; 
  gap: 10px; 
  cursor: pointer; 
  text-align: left; 
  font-family: inherit; 
}
.rv-live-option:hover, .rv-live-option.rv-active { background: #ecfdf5; }
.rv-live-option-name { 
  display: block; 
  font-size: 0.9rem; 
  font-weight: 800; 
  color: #0f172a; 
}
.rv-live-option-due { 
  white-space: nowrap; 
  font-size: 0.82rem; 
  font-weight: 900; 
  color: #16a34a; 
}
.rv-live-option[data-due-zero="1"] .rv-live-option-due { color: #94a3b8; }
.rv-live-no-result { display: none; padding: 14px 12px; color: #ef4444; font-weight: 800; text-align: center; }
.rv-live-error .rv-live-toggle { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.12); }
.rv-pay-hint { display: block; margin-top: 5px; color: #64748b; font-size: 0.75rem; font-weight: 700; }
.rv-pay-actions { 
  display: flex; 
  align-items: center; 
  justify-content: center; 
  gap: 10px; 
  padding: 6px 20px 16px; 
}
.rv-pay-btn { 
  border: 0; 
  border-radius: 999px; 
  padding: 10px 32px; 
  font-size: 0.95rem; 
  font-weight: 800; 
  color: #fff; 
  cursor: pointer; 
  min-width: 150px; 
  transition: all 0.2s ease; 
}
.rv-pay-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
.rv-pay-save { background: #16a34a; }
.rv-pay-save:hover { background: #15803d; }
.rv-pay-print { background: #2563eb; }
.rv-pay-print:hover { background: #1d4ed8; }
.rv-pay-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

.rv-dashboard-alert { 
  margin: 0 0 16px; 
  border-radius: 12px; 
  padding: 12px 16px; 
  font-weight: 800; 
  border-left: 4px solid; 
}
.rv-dashboard-alert.success { background: #dcfce7; color: #166534; border-color: #22c55e; }
.rv-dashboard-alert.error { background: #fee2e2; color: #991b1b; border-color: #ef4444; }

/* MODAL STYLES */
.rv-modal-open { overflow: hidden; }
.rv-modal { 
  position: fixed; 
  inset: 0; 
  z-index: 20000; 
  display: none; 
  align-items: center; 
  justify-content: center; 
  padding: 22px; 
  background: rgba(2, 6, 23, 0.66); 
  backdrop-filter: blur(8px); 
}
.rv-modal.rv-show { display: flex; }
.rv-modal-card { 
  width: min(1280px, 98vw); 
  height: min(900px, 94vh); 
  background: #ffffff; 
  border-radius: 24px; 
  overflow: hidden; 
  display: flex; 
  flex-direction: column; 
  box-shadow: 0 34px 90px rgba(0,0,0,0.42); 
  border: 1px solid rgba(255,255,255,0.24); 
}
.rv-modal-card.rv-modal-lg { width: min(980px, 96vw); }
.rv-modal-card.rv-modal-xl { width: min(1280px, 98vw); }
.rv-modal-card.rv-modal-wide { width: min(1540px, 99vw); height: min(940px, 96vh); }
.rv-modal-head { 
  min-height: 62px; 
  display: flex; 
  align-items: center; 
  justify-content: space-between; 
  gap: 14px; 
  padding: 14px 18px 14px 22px; 
  background: linear-gradient(135deg, #052e16, #14532d 62%, #166534); 
  color: #ffffff; 
}
.rv-modal-title-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; }
.rv-modal-title-wrap i { 
  width: 36px; 
  height: 36px; 
  display: flex; 
  align-items: center; 
  justify-content: center; 
  border-radius: 12px; 
  background: rgba(255,255,255,0.14); 
  color: #fde68a; 
}
.rv-modal-title { 
  font-size: 1rem; 
  font-weight: 900; 
  white-space: nowrap; 
  overflow: hidden; 
  text-overflow: ellipsis; 
}
.rv-modal-close { 
  width: 38px; 
  height: 38px; 
  border: 0; 
  border-radius: 12px; 
  background: rgba(255,255,255,0.16); 
  color: #ffffff; 
  cursor: pointer; 
  font-size: 18px; 
  transition: all 0.2s ease; 
}
.rv-modal-close:hover { background: rgba(239,68,68,0.9); }
.rv-modal-body { position: relative; flex: 1; background: #f8fafc; }
.rv-modal-loader { 
  position: absolute; 
  inset: 0; 
  display: flex; 
  align-items: center; 
  justify-content: center; 
  color: #166534; 
  font-weight: 800; 
  background: #f8fafc; 
  z-index: 1; 
}
.rv-modal-frame { 
  position: relative; 
  z-index: 2; 
  width: 100%; 
  height: 100%; 
  border: 0; 
  background: #ffffff; 
}

.v3-warning-box { 
  background: #fff7ed; 
  border: 1px solid #fed7aa; 
  color: #9a3412; 
  border-radius: 12px; 
  padding: 12px 16px; 
  margin-bottom: 16px; 
  font-size: 0.85rem; 
}
.v3-stock-section { margin-bottom: 24px; margin-top: 24px; }
.v3-stock-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.v3-stock-header h5 { margin: 0; font-size: 0.85rem; font-weight: 800; color: var(--rv-red); text-transform: uppercase; letter-spacing: 0.5px; }
.v3-stock-count { background: var(--rv-red); color: #fff; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; }
.v3-stock-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.v3-stock-card { border-color: #fecaca; border-radius: 16px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; transition: all 0.25s ease; position: relative; overflow: hidden; }
.v3-stock-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444; }
.v3-stock-icon { width: 44px; height: 44px; background: #fef2f2; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--rv-red); flex-shrink: 0; }
.v3-stock-info { flex: 1; min-width: 0; }
.v3-stock-name { font-size: 0.95rem; font-weight: 800; color: var(--rv-text); margin: 0 0 4px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.v3-stock-meta { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: var(--rv-muted); }
.v3-stock-bar { width: 60px; height: 6px; background: #f1f5f9; border-radius: 50px; overflow: hidden; }
.v3-stock-bar-fill { height: 100%; background: #ef4444; border-radius: 50px; transition: width 0.4s ease; }
.v3-stock-qty { background: #fef2f2; color: var(--rv-red); padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 900; flex-shrink: 0; }

@media(max-width: 1200px) {
  .v3-summary-row, .v3-source-row, .v3-top-row { grid-template-columns: repeat(2, 1fr); }
  .rv-purchase-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .v3-stock-grid { grid-template-columns: repeat(3, 1fr); }
}
@media(max-width: 767px) {
  .v3-main { padding: 14px; }
  .v3-fixed-actionbar { margin: -14px -14px 18px; padding: 10px 14px; }
  .v3-fixed-actionbar-inner { align-items: flex-start; flex-direction: column; gap: 10px; }
  .v3-fixed-actions { width: 100%; }
  .v3-fixed-btn { padding: 8px 12px; font-size: 0.78rem; }
  .v3-header { padding: 16px 20px; }
  .v3-header-text h4 { font-size: 1.2rem; }
  .v3-summary-row, .v3-source-row, .v3-balance-row, .v3-mid-row, .v3-top-row { grid-template-columns: 1fr; }
  .rv-purchase-kpis { grid-template-columns: 1fr; }
  .rv-supplier-due-head { align-items: flex-start; }
  .rv-pay-summary { grid-template-columns: 1fr; }
  .v3-stock-grid { grid-template-columns: 1fr; }
  .v3-bar-item { grid-template-columns: 1fr; gap: 6px; }
  .v3-grand, .v3-profit-card { flex-direction: column; text-align: center; }
  .v3-grand-right, .v3-profit-right { align-items: center; }
  .rv-pay-row { grid-template-columns: 1fr; gap: 0; }
  .rv-pay-title { font-size: 1rem; }
  .rv-pay-actions { flex-direction: column; gap: 8px; }
  .rv-pay-btn { width: 100%; min-width: 0; }
  .rv-modal { padding: 10px; }
  .rv-modal-card { width: 100%; height: 94vh; border-radius: 18px; }
  .rv-modal-head { padding: 12px 14px; }
}
</style>

<div class="v3-main">

    <!-- Fixed Top Action Bar -->
    <div class="v3-fixed-actionbar">
        <div class="v3-fixed-actionbar-inner">
            <div class="v3-fixed-brand">
                <i class="fas fa-seedling"></i>
                <span>Quick Access</span>
            </div>
            <div class="v3-fixed-actions">
                <?php renderQuickActions($quickActions, 'bar'); ?>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['rv_dashboard_flash'])): $rvFlash = $_SESSION['rv_dashboard_flash']; unset($_SESSION['rv_dashboard_flash']); ?>
        <div class="rv-dashboard-alert <?= h($rvFlash['type'] ?? 'success') ?>">
            <?= h($rvFlash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($debug_warnings)): ?>
        <div class="v3-warning-box">
            <strong>Dashboard notice:</strong> <?= h(implode(' ', $debug_warnings)) ?>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="v3-header">
        <div class="v3-header-text">
            <h4><i class="fas fa-hand-peace"></i> Welcome back, <?= h($user_name) ?></h4>
            <p><?= date('l, d F Y') ?> · Today's shop summary · Shop ID: <?= (int)$shop_id ?></p>
        </div>
        <div class="v3-live-badge">
            <span class="v3-pulse-dot"></span> Live POS
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="v3-summary-row">
        <?php foreach ($summaryCards as $card) renderSummaryCard($card['title'], $card['lines']); ?>
    </div>

    <!-- Middle Panels -->
    <div class="v3-mid-row">
        <div class="v3-panel">
            <h5><i class="fas fa-wallet"></i> Today's Cash Movement</h5>
            <div class="v3-bar-list">
                <div class="v3-bar-item">
                    <span>Cash Inflow</span>
                    <div class="v3-bar-track"><div class="v3-bar-fill v3-greenbar" style="width:100%"></div></div>
                    <b><?= moneyShort($cash_inflow_today) ?></b>
                </div>
                <div class="v3-bar-item">
                    <span>Cash Outflow</span>
                    <div class="v3-bar-track"><div class="v3-bar-fill v3-red" style="width:<?= $expense_percent ?>%"></div></div>
                    <b><?= moneyShort($cash_outflow_today) ?></b>
                </div>
                <div class="v3-bar-item">
                    <span>Net Cash</span>
                    <div class="v3-bar-track"><div class="v3-bar-fill" style="width:<?= $net_percent ?>%"></div></div>
                    <b><?= moneyShort($net_cash_today) ?></b>
                </div>
            </div>
        </div>

        <div class="v3-panel">
            <h5><i class="fas fa-file-invoice"></i> Dues & Loans</h5>
            <div class="v3-dues-grid">
                <div class="v3-due-card"><i class="fas fa-user-clock"></i><span>Customer Due</span><b><?= moneyShort($receivable_customer_due) ?></b></div>
                <div class="v3-due-card"><i class="fas fa-truck"></i><span>Supplier Due</span><b><?= moneyShort($supplier_due) ?></b></div>
                <div class="v3-due-card"><i class="fas fa-file-invoice-dollar"></i><span>Expense Due</span><b><?= moneyShort($expense_due_total) ?></b></div>
                <div class="v3-due-card"><i class="fas fa-university"></i><span>Bank/Credit/Investor</span><b><?= moneyShort($bank_loan + $credit_card + $investor_loan) ?></b></div>
            </div>
        </div>
    </div>

    <!-- Assets & Liability -->
    <div class="v3-balance-row">
        <?php renderBalanceCard('Assets', 'v3-assets-header', $assetItems); ?>
        <?php renderBalanceCard('Liability', 'v3-liability-header', $liabilityItems); ?>
    </div>

    <!-- NET PROFIT CARD -->
    <div class="v3-profit-card">
        <div class="v3-profit-left">
            <i class="fas fa-chart-line"></i>
            <div>
                <span class="v3-profit-label">Today's POS Net Profit</span>
                <span class="v3-profit-value" style="color: <?= $netProfitColor ?>;">
                    <?= moneyShort($net_profit) ?>
                </span>
            </div>
        </div>
        <div class="v3-profit-right">
            <span class="v3-profit-chip">
                <i class="fas <?= $netProfitIcon ?>" style="color: <?= $netProfitColor ?>;"></i> <?= $netProfitLabel ?>
            </span>
            <div class="v3-profit-detail">
                Gross: <?= moneyShort($gross_profit) ?> · 
                Expense: <?= moneyShort($total_expense) ?> · 
                Discount: <?= moneyShort($total_discount) ?>
            </div>
        </div>
    </div>

</div>

<!-- ============================================================ -->
<!-- CUSTOMER PAYMENT MODAL - UNIFIED WITH SUPPLIER PAYMENT MODAL -->
<!-- ============================================================ -->
<div class="rv-pay-overlay" id="rvCustomerPaymentModal" aria-hidden="true">
    <div class="rv-pay-card" role="dialog" aria-modal="true" aria-labelledby="rvCustomerPaymentTitle">
        <div class="rv-pay-head rv-pay-green">
            <div class="rv-pay-title">
                <i class="fas fa-hand-holding-dollar"></i>
                <span id="rvCustomerPaymentTitle">Customer Payment</span>
            </div>
            <button type="button" class="rv-pay-close" data-rv-pay-close aria-label="Close">&times;</button>
        </div>
        <form method="POST" class="rv-pay-form" id="rvCustomerPaymentForm">
            <input type="hidden" name="csrf_token" value="<?= h($rv_dashboard_csrf) ?>">
            <input type="hidden" name="rv_dashboard_action" value="customer_due_receive">
            <div class="rv-pay-body">
                <!-- Customer Selection -->
                <div class="rv-pay-group rv-full">
                    <label class="rv-pay-label">Customer<span class="req">*</span></label>
                    <div class="rv-live-select" data-rv-live-select data-select="#rvCustomerSelect" data-placeholder="Select Customer" data-search-placeholder="Search customer name or mobile...">
                        <button type="button" class="rv-live-toggle" data-rv-live-toggle>
                            <span class="rv-live-value rv-placeholder" data-rv-live-value>Select Customer</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="rv-live-panel">
                            <div class="rv-live-search">
                                <i class="fas fa-search"></i>
                                <input type="text" class="rv-live-input" data-rv-live-input placeholder="Search customer name or mobile..." autocomplete="off">
                            </div>
                            <div class="rv-live-list" data-rv-live-list></div>
                            <div class="rv-live-no-result" data-rv-live-empty>No customer found</div>
                        </div>
                    </div>
                    <select name="customer_id" id="rvCustomerSelect" class="rv-pay-select rv-due-source rv-native-select" data-due-target="#rvCustomerDueAmount" data-paid-target="#rvCustomerPaidAmount" required>
                        <option value="">Select Customer</option>
                        <?php foreach ($rv_due_customers as $customer): ?>
                            <?php $phone = trim((string)($customer['phone'] ?? '')); ?>
                            <option value="<?= (int)$customer['id'] ?>" data-due="<?= h($customer['due_amount']) ?>">
                                <?= h($customer['display_name']) ?><?= $phone !== '' ? ' - ' . h($phone) : '' ?> (<?= taka($customer['due_amount']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="rv-pay-hint">Total customer: <?= number_format(count($rv_due_customers)) ?>. Type to search.</small>
                </div>

                <!-- Payment Preview - UNIFIED -->
                <div class="rv-pay-summary">
                    <div class="rv-pay-summary-box is-due">
                        <span>Current Customer Due</span>
                        <b id="rvCustomerDuePreview">৳0.00</b>
                    </div>
                    <div class="rv-pay-summary-box is-due">
                        <span>Due After Payment</span>
                        <b id="rvCustomerAfterDuePreview">৳0.00</b>
                    </div>
                    <div class="rv-pay-summary-box is-balance">
                        <span>Selected Asset Balance</span>
                        <b id="rvCustomerAssetBalancePreview">৳0.00</b>
                    </div>
                    <div class="rv-pay-summary-box is-balance">
                        <span>Asset Balance After Payment</span>
                        <b id="rvCustomerAssetAfterPreview">৳0.00</b>
                    </div>
                </div>

                <!-- Date & Amount -->
                <div class="rv-pay-row">
                    <div class="rv-pay-group">
                        <label class="rv-pay-label">Date<span class="req">*</span></label>
                        <input type="date" name="receive_date" class="rv-pay-input" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="rv-pay-group">
                        <label class="rv-pay-label">Paid Amount<span class="req">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="paid_amount" id="rvCustomerPaidAmount" class="rv-pay-input" placeholder="Enter amount" required>
                    </div>
                </div>

                <input type="hidden" id="rvCustomerDueAmount" value="">

                <!-- Payment Method -->
                <div class="rv-pay-group rv-full">
                    <label class="rv-pay-label">Payment Asset<span class="req">*</span></label>
                    <select name="asset_id" id="rvCustomerAssetSelect" class="rv-pay-select" required>
                        <?php foreach ($rv_payment_assets as $asset): ?>
                            <?php 
                                $isCash = ($asset['asset_type'] === 'cash');
                                $label = $isCash ? 'Cash In Hand' : rvAssetLabel($asset['asset_type']) . ' - ' . ($asset['asset_name'] ?: ucfirst($asset['asset_type']));
                            ?>
                            <option value="<?= (int)$asset['id'] ?>" data-balance="<?= h($asset['amount']) ?>" <?= $isCash ? 'selected' : '' ?>>
                                <?= h($label) ?> (<?= number_format((float)$asset['amount'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="rv-pay-hint">Payment cannot exceed customer due.</small>
                </div>

                <!-- Note -->
                <div class="rv-pay-group rv-full">
                    <label class="rv-pay-label">Note</label>
                    <textarea name="note" class="rv-pay-textarea" placeholder="Optional note"></textarea>
                </div>
            </div>
            <div class="rv-pay-actions">
                <button type="submit" class="rv-pay-btn rv-pay-save"><i class="fas fa-save"></i> Save</button>
                <button type="submit" name="save_print" value="1" formtarget="_blank" class="rv-pay-btn rv-pay-print"><i class="fas fa-receipt"></i> Save &amp; 80mm Invoice</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- SUPPLIER PAYMENT MODAL - UNIFIED WITH CUSTOMER PAYMENT MODAL -->
<!-- ============================================================ -->
<div class="rv-pay-overlay" id="rvSupplierPaymentModal" aria-hidden="true">
    <div class="rv-pay-card" role="dialog" aria-modal="true" aria-labelledby="rvSupplierPaymentTitle">
        <div class="rv-pay-head rv-pay-orange">
            <div class="rv-pay-title">
                <i class="fas fa-truck-loading"></i>
                <span id="rvSupplierPaymentTitle">Supplier Payment</span>
            </div>
            <button type="button" class="rv-pay-close" data-rv-pay-close aria-label="Close">&times;</button>
        </div>
        <form method="POST" class="rv-pay-form" id="rvSupplierPaymentForm">
            <input type="hidden" name="csrf_token" value="<?= h($rv_dashboard_csrf) ?>">
            <input type="hidden" name="rv_dashboard_action" value="supplier_payment">
            <div class="rv-pay-body">
                <!-- Supplier Selection -->
                <div class="rv-pay-group rv-full">
                    <label class="rv-pay-label">Supplier<span class="req">*</span></label>
                    <div class="rv-live-select" data-rv-live-select data-select="#rvSupplierSelect" data-placeholder="Select Supplier" data-search-placeholder="Search supplier name or mobile...">
                        <button type="button" class="rv-live-toggle" data-rv-live-toggle>
                            <span class="rv-live-value rv-placeholder" data-rv-live-value>Select Supplier</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="rv-live-panel">
                            <div class="rv-live-search">
                                <i class="fas fa-search"></i>
                                <input type="text" class="rv-live-input" data-rv-live-input placeholder="Search supplier name or mobile..." autocomplete="off">
                            </div>
                            <div class="rv-live-list" data-rv-live-list></div>
                            <div class="rv-live-no-result" data-rv-live-empty>No due supplier found</div>
                        </div>
                    </div>
                    <select name="supplier_id" id="rvSupplierSelect" class="rv-pay-select rv-due-source rv-native-select" data-due-target="#rvSupplierDueAmount" data-paid-target="#rvSupplierPaidAmount" required>
                        <option value="">Select Supplier</option>
                        <?php foreach ($rv_due_suppliers as $supplier): ?>
                            <?php $phone = trim((string)($supplier['phone'] ?? '')); ?>
                            <option value="<?= (int)$supplier['id'] ?>" data-due="<?= h($supplier['current_due']) ?>">
                                <?= h($supplier['supplier_name']) ?><?= $phone !== '' ? ' - ' . h($phone) : '' ?> (<?= taka($supplier['current_due']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="rv-pay-hint"><?= number_format($due_supplier_count) ?> supplier currently has due. Type to search.</small>
                </div>

                <!-- Payment Preview - UNIFIED -->
                <div class="rv-pay-summary">
                    <div class="rv-pay-summary-box is-due">
                        <span>Current Supplier Due</span>
                        <b id="rvSupplierDuePreview">৳0.00</b>
                    </div>
                    <div class="rv-pay-summary-box is-due">
                        <span>Due After Payment</span>
                        <b id="rvSupplierAfterDuePreview">৳0.00</b>
                    </div>
                    <div class="rv-pay-summary-box is-balance">
                        <span>Selected Asset Balance</span>
                        <b id="rvSupplierAssetBalancePreview">৳0.00</b>
                    </div>
                    <div class="rv-pay-summary-box is-balance">
                        <span>Asset Balance After Payment</span>
                        <b id="rvSupplierAssetAfterPreview">৳0.00</b>
                    </div>
                </div>

                <!-- Date & Amount -->
                <div class="rv-pay-row">
                    <div class="rv-pay-group">
                        <label class="rv-pay-label">Payment Date<span class="req">*</span></label>
                        <input type="date" name="payment_date" class="rv-pay-input" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="rv-pay-group">
                        <label class="rv-pay-label">Paid Amount<span class="req">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="paid_amount" id="rvSupplierPaidAmount" class="rv-pay-input" placeholder="Enter amount" required>
                    </div>
                </div>

                <input type="hidden" id="rvSupplierDueAmount" value="">

                <!-- Payment Method -->
                <div class="rv-pay-group rv-full">
                    <label class="rv-pay-label">Pay From Asset<span class="req">*</span></label>
                    <select name="asset_id" id="rvSupplierAssetSelect" class="rv-pay-select" required>
                        <?php foreach ($rv_payment_assets as $asset): ?>
                            <?php
                                $isCash = ($asset['asset_type'] === 'cash');
                                $label = $isCash ? 'Cash In Hand' : rvAssetLabel($asset['asset_type']) . ' - ' . ($asset['asset_name'] ?: ucfirst($asset['asset_type']));
                            ?>
                            <option value="<?= (int)$asset['id'] ?>" data-balance="<?= h($asset['amount']) ?>" <?= $isCash ? 'selected' : '' ?>>
                                <?= h($label) ?> (<?= number_format((float)$asset['amount'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="rv-pay-hint">Payment cannot exceed supplier due or selected asset balance.</small>
                </div>

                <!-- Note -->
                <div class="rv-pay-group rv-full">
                    <label class="rv-pay-label">Note</label>
                    <textarea name="note" class="rv-pay-textarea" placeholder="Optional payment note"></textarea>
                </div>
            </div>
            <div class="rv-pay-actions">
                <button type="submit" class="rv-pay-btn rv-pay-save"><i class="fas fa-save"></i> Save</button>
                <button type="submit" name="save_print" value="1" class="rv-pay-btn rv-pay-print"><i class="fas fa-print"></i> Save &amp; Print</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- GLOBAL MODAL -->
<!-- ============================================================ -->
<div class="rv-modal" id="rvGlobalModal" aria-hidden="true">
    <div class="rv-modal-card rv-modal-xl" id="rvModalCard" role="dialog" aria-modal="true" aria-labelledby="rvModalTitle">
        <div class="rv-modal-head">
            <div class="rv-modal-title-wrap">
                <i class="fas fa-layer-group"></i>
                <span class="rv-modal-title" id="rvModalTitle">Quick Action</span>
            </div>
            <button type="button" class="rv-modal-close" id="rvModalClose" aria-label="Close modal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="rv-modal-body">
            <div class="rv-modal-loader" id="rvModalLoader">Loading...</div>
            <iframe class="rv-modal-frame" id="rvModalFrame" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ============================================================
    // GLOBAL MODAL
    // ============================================================
    const modal = document.getElementById('rvGlobalModal');
    const card = document.getElementById('rvModalCard');
    const title = document.getElementById('rvModalTitle');
    const frame = document.getElementById('rvModalFrame');
    const loader = document.getElementById('rvModalLoader');
    const closeBtn = document.getElementById('rvModalClose');

    function appendModalParam(url) {
        try {
            const parsed = new URL(url, window.location.href);
            parsed.searchParams.set('modal', '1');
            parsed.searchParams.set('embedded', '1');
            return parsed.toString();
        } catch (error) {
            return url;
        }
    }

    function openRvModal(button) {
        const url = button.getAttribute('data-rv-modal-url');
        const modalTitle = button.getAttribute('data-rv-modal-title') || 'Quick Action';
        const modalSize = button.getAttribute('data-rv-modal-size') || 'xl';

        if (!url) return;

        title.textContent = modalTitle;
        card.classList.remove('rv-modal-lg', 'rv-modal-xl', 'rv-modal-wide');
        if (modalSize === 'lg') {
            card.classList.add('rv-modal-lg');
        } else if (modalSize === 'wide' || modalSize === 'xxl' || modalSize === 'full') {
            card.classList.add('rv-modal-wide');
        } else {
            card.classList.add('rv-modal-xl');
        }
        loader.style.display = 'flex';
        frame.src = appendModalParam(url);
        modal.classList.add('rv-show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('rv-modal-open');
    }

    function closeRvModal(reloadParent) {
        modal.classList.remove('rv-show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('rv-modal-open');
        frame.src = 'about:blank';
        loader.style.display = 'flex';
        if (reloadParent) {
            window.location.reload();
        }
    }

    // ============================================================
    // LOCAL PAYMENT MODALS
    // ============================================================
    function openLocalPayModal(modalId) {
        const localModal = document.getElementById(modalId);
        if (!localModal) return;
        localModal.classList.add('rv-pay-show');
        localModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('rv-modal-open');

        localModal.querySelectorAll('[data-rv-live-input]').forEach(function (input) {
            input.value = '';
            input.dispatchEvent(new Event('input'));
        });

        const firstInput = localModal.querySelector('[data-rv-live-toggle], select, input, textarea');
        if (firstInput) setTimeout(function(){ firstInput.focus(); }, 80);
    }

    function closeLocalPayModal(localModal) {
        if (!localModal) return;
        localModal.classList.remove('rv-pay-show');
        localModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('rv-modal-open');
    }

    // ============================================================
    // EVENT LISTENERS
    // ============================================================
    document.querySelectorAll('[data-rv-open-local-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modalId = button.getAttribute('data-rv-open-local-modal');
            const supplierId = button.getAttribute('data-rv-supplier-id');

            if (supplierId && modalId === 'rvSupplierPaymentModal') {
                const supplierSelect = document.getElementById('rvSupplierSelect');
                if (supplierSelect) {
                    supplierSelect.value = supplierId;
                    supplierSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            openLocalPayModal(modalId);
        });
    });

    document.querySelectorAll('[data-rv-pay-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeLocalPayModal(button.closest('.rv-pay-overlay'));
        });
    });

    document.querySelectorAll('.rv-pay-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeLocalPayModal(overlay);
        });
    });

    document.querySelectorAll('[data-rv-modal-url]').forEach(function (button) {
        button.addEventListener('click', function () {
            openRvModal(button);
        });
    });

    closeBtn.addEventListener('click', function () {
        closeRvModal(false);
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeRvModal(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.rv-pay-overlay.rv-pay-show').forEach(function (overlay) {
                closeLocalPayModal(overlay);
            });
            if (modal.classList.contains('rv-show')) {
                closeRvModal(false);
            }
        }
    });

    // ============================================================
    // FRAME LOAD HANDLER
    // ============================================================
    frame.addEventListener('load', function () {
        try {
            const doc = frame.contentDocument || frame.contentWindow.document;
            if (doc && doc.head && !doc.getElementById('rvEmbeddedModalStyle')) {
                const style = doc.createElement('style');
                style.id = 'rvEmbeddedModalStyle';
                style.textContent = `
                    header, .header, #header, nav, .navbar, .topbar, .top-nav, .main-header,
                    aside, .sidebar, #sidebar, .main-sidebar, .left-sidebar, .side-menu, .app-sidebar,
                    footer, .footer, #footer,
                    .v3-fixed-actionbar, .breadcrumb, .page-breadcrumb {
                        display: none !important;
                    }
                    html, body {
                        margin: 0 !important; padding: 0 !important;
                        width: 100% !important; min-height: 100% !important;
                        overflow-x: hidden !important; background: #f8fafc !important;
                    }
                    .main, .content, .page-content, .content-wrapper, .main-content, .app-content, .container-fluid {
                        margin-left: 0 !important; margin-top: 0 !important;
                        padding-top: 16px !important;
                        width: 100% !important; max-width: 100% !important;
                        min-height: auto !important;
                    }
                    .wrapper, .app, .layout, .page-wrapper {
                        margin: 0 !important; padding: 0 !important;
                        width: 100% !important;
                    }
                    body.rv-embedded-modal .modal,
                    body.rv-embedded-modal .modal-backdrop {
                        z-index: 99999 !important;
                    }
                `;
                doc.head.appendChild(style);
            }
            if (doc && doc.body) {
                doc.body.classList.add('rv-embedded-modal');
            }
        } catch (error) {}
        loader.style.display = 'none';
    });

    // ============================================================
    // LIVE SELECT INITIALIZATION
    // ============================================================
    function initRvLiveSelect() {
        const widgets = document.querySelectorAll('[data-rv-live-select]');

        function closeAll(exceptWidget) {
            widgets.forEach(function (widget) {
                if (widget !== exceptWidget) widget.classList.remove('rv-live-open');
            });
        }

        widgets.forEach(function (widget) {
            const select = document.querySelector(widget.getAttribute('data-select'));
            const toggle = widget.querySelector('[data-rv-live-toggle]');
            const valueBox = widget.querySelector('[data-rv-live-value]');
            const input = widget.querySelector('[data-rv-live-input]');
            const list = widget.querySelector('[data-rv-live-list]');
            const empty = widget.querySelector('[data-rv-live-empty]');
            const placeholder = widget.getAttribute('data-placeholder') || 'Select';
            const searchPlaceholder = widget.getAttribute('data-search-placeholder') || 'Search...';

            if (!select || !toggle || !valueBox || !input || !list) return;
            input.placeholder = searchPlaceholder;

            function getOptions() {
                return Array.from(select.options).filter(function (option) { return option.value; });
            }

            function updateLabel() {
                const selected = select.options[select.selectedIndex];
                if (selected && selected.value) {
                    valueBox.textContent = selected.textContent.trim();
                    valueBox.classList.remove('rv-placeholder');
                    widget.classList.remove('rv-live-error');
                } else {
                    valueBox.textContent = placeholder;
                    valueBox.classList.add('rv-placeholder');
                }
            }

            function chooseValue(value) {
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                updateLabel();
                widget.classList.remove('rv-live-open', 'rv-live-error');
                input.value = '';
                renderOptions('');
            }

            function renderOptions(query) {
                const term = String(query || '').toLowerCase().trim();
                const options = getOptions();
                let html = '';
                let count = 0;

                options.forEach(function (option) {
                    const rawText = option.textContent.trim();
                    const nameText = rawText.replace(/\s*\([^()]*\)\s*$/, '').trim();
                    const haystack = rawText.toLowerCase();
                    if (term && haystack.indexOf(term) === -1) return;

                    const due = Number(option.getAttribute('data-due') || 0);
                    const dueText = due ? due.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
                    const active = option.value === select.value ? ' rv-active' : '';
                    html += '<button type="button" class="rv-live-option' + active + '" data-value="' + option.value.replace(/"/g,'&quot;') + '" data-due-zero="' + (due > 0 ? '0' : '1') + '">' +
                            '<span class="rv-live-option-name">' + nameText.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>' +
                            '<span class="rv-live-option-due">৳' + dueText + '</span>' +
                            '</button>';
                    count++;
                });

                list.innerHTML = html;
                if (empty) empty.style.display = count ? 'none' : 'block';
            }

            toggle.addEventListener('click', function () {
                const willOpen = !widget.classList.contains('rv-live-open');
                closeAll(widget);
                widget.classList.toggle('rv-live-open', willOpen);
                if (willOpen) {
                    input.value = '';
                    renderOptions('');
                    setTimeout(function () { input.focus(); }, 40);
                }
            });

            input.addEventListener('input', function () {
                renderOptions(input.value);
            });

            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const first = list.querySelector('.rv-live-option');
                    if (first) chooseValue(first.getAttribute('data-value'));
                } else if (event.key === 'Escape') {
                    widget.classList.remove('rv-live-open');
                    toggle.focus();
                }
            });

            list.addEventListener('click', function (event) {
                const optionButton = event.target.closest('.rv-live-option');
                if (!optionButton) return;
                chooseValue(optionButton.getAttribute('data-value'));
            });

            select.addEventListener('change', updateLabel);
            renderOptions('');
            updateLabel();
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-rv-live-select]')) closeAll(null);
        });

        document.querySelectorAll('.rv-pay-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                const requiredSelect = form.querySelector('.rv-native-select[required]');
                if (requiredSelect && !requiredSelect.value) {
                    event.preventDefault();
                    const widget = form.querySelector('[data-rv-live-select]');
                    if (widget) {
                        widget.classList.add('rv-live-open', 'rv-live-error');
                        const liveInput = widget.querySelector('[data-rv-live-input]');
                        if (liveInput) setTimeout(function () { liveInput.focus(); }, 40);
                    }
                }
            });
        });
    }

    initRvLiveSelect();

    // ============================================================
    // CUSTOMER PAYMENT PREVIEW
    // ============================================================
    function rvMoney(value) {
        return '৳' + Number(value || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateCustomerPaymentPreview() {
        const customerSelect = document.getElementById('rvCustomerSelect');
        const paidInput = document.getElementById('rvCustomerPaidAmount');
        const assetSelect = document.getElementById('rvCustomerAssetSelect');

        if (!customerSelect || !paidInput || !assetSelect) return;

        const customerOption = customerSelect.options[customerSelect.selectedIndex];
        const assetOption = assetSelect.options[assetSelect.selectedIndex];
        const due = customerOption ? Number(customerOption.getAttribute('data-due') || 0) : 0;
        const paid = Math.max(0, Number(paidInput.value || 0));
        const balance = assetOption ? Number(assetOption.getAttribute('data-balance') || 0) : 0;
        const afterDue = Math.max(0, due - paid);
        const afterBalance = Math.max(0, balance + paid); // Customer payment adds to asset

        const duePreview = document.getElementById('rvCustomerDuePreview');
        const afterDuePreview = document.getElementById('rvCustomerAfterDuePreview');
        const balancePreview = document.getElementById('rvCustomerAssetBalancePreview');
        const afterBalancePreview = document.getElementById('rvCustomerAssetAfterPreview');

        if (duePreview) duePreview.textContent = rvMoney(due);
        if (afterDuePreview) afterDuePreview.textContent = rvMoney(afterDue);
        if (balancePreview) balancePreview.textContent = rvMoney(balance);
        if (afterBalancePreview) afterBalancePreview.textContent = rvMoney(afterBalance);

        paidInput.max = due > 0 ? due : '';
        paidInput.placeholder = due > 0 ? 'Maximum ' + due.toFixed(2) : 'No due';
        paidInput.setCustomValidity('');

        if (paid > due && due > 0) {
            paidInput.setCustomValidity('Payment cannot exceed customer due.');
        }
    }

    // ============================================================
    // SUPPLIER PAYMENT PREVIEW
    // ============================================================
    function updateSupplierPaymentPreview() {
        const supplierSelect = document.getElementById('rvSupplierSelect');
        const paidInput = document.getElementById('rvSupplierPaidAmount');
        const assetSelect = document.getElementById('rvSupplierAssetSelect');

        if (!supplierSelect || !paidInput || !assetSelect) return;

        const supplierOption = supplierSelect.options[supplierSelect.selectedIndex];
        const assetOption = assetSelect.options[assetSelect.selectedIndex];
        const due = supplierOption ? Number(supplierOption.getAttribute('data-due') || 0) : 0;
        const paid = Math.max(0, Number(paidInput.value || 0));
        const balance = assetOption ? Number(assetOption.getAttribute('data-balance') || 0) : 0;
        const afterDue = Math.max(0, due - paid);
        const afterBalance = Math.max(0, balance - paid);

        const duePreview = document.getElementById('rvSupplierDuePreview');
        const afterDuePreview = document.getElementById('rvSupplierAfterDuePreview');
        const balancePreview = document.getElementById('rvSupplierAssetBalancePreview');
        const afterBalancePreview = document.getElementById('rvSupplierAssetAfterPreview');

        if (duePreview) duePreview.textContent = rvMoney(due);
        if (afterDuePreview) afterDuePreview.textContent = rvMoney(afterDue);
        if (balancePreview) balancePreview.textContent = rvMoney(balance);
        if (afterBalancePreview) afterBalancePreview.textContent = rvMoney(afterBalance);

        paidInput.max = due > 0 ? Math.min(due, balance) : '';
        paidInput.placeholder = due > 0 ? 'Maximum ' + Math.min(due, balance).toFixed(2) : 'No due';
        paidInput.setCustomValidity('');

        if (paid > due && due > 0) {
            paidInput.setCustomValidity('Payment cannot exceed supplier due.');
        } else if (paid > balance) {
            paidInput.setCustomValidity('Insufficient balance in selected asset.');
        }
    }

    // ============================================================
    // EVENT BINDING FOR CUSTOMER PAYMENT
    // ============================================================
    document.querySelectorAll('.rv-due-source').forEach(function (select) {
        select.addEventListener('change', function () {
            const option = select.options[select.selectedIndex];
            const due = option ? Number(option.getAttribute('data-due') || 0) : 0;
            const dueTarget = document.querySelector(select.getAttribute('data-due-target'));
            const paidTarget = document.querySelector(select.getAttribute('data-paid-target'));

            if (dueTarget) {
                if (dueTarget.type === 'hidden') {
                    dueTarget.value = due;
                } else {
                    dueTarget.value = due ? due.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';
                }
            }
            if (paidTarget) {
                paidTarget.value = '';
                paidTarget.max = due > 0 ? due : '';
                paidTarget.placeholder = due > 0 ? 'Amount' : 'No due';
            }

            if (select.id === 'rvCustomerSelect') updateCustomerPaymentPreview();
            if (select.id === 'rvSupplierSelect') updateSupplierPaymentPreview();
        });
    });

    const customerPaidInput = document.getElementById('rvCustomerPaidAmount');
    const customerAssetSelect = document.getElementById('rvCustomerAssetSelect');
    if (customerPaidInput) customerPaidInput.addEventListener('input', updateCustomerPaymentPreview);
    if (customerAssetSelect) customerAssetSelect.addEventListener('change', updateCustomerPaymentPreview);

    const supplierPaidInput = document.getElementById('rvSupplierPaidAmount');
    const supplierAssetSelect = document.getElementById('rvSupplierAssetSelect');
    if (supplierPaidInput) supplierPaidInput.addEventListener('input', updateSupplierPaymentPreview);
    if (supplierAssetSelect) supplierAssetSelect.addEventListener('change', updateSupplierPaymentPreview);

    // Initial preview
    updateCustomerPaymentPreview();
    updateSupplierPaymentPreview();

    // ============================================================
    // CUSTOMER PAYMENT FORM VALIDATION
    // ============================================================
    const customerPaymentForm = document.getElementById('rvCustomerPaymentForm');
    if (customerPaymentForm) {
        customerPaymentForm.addEventListener('submit', function (event) {
            updateCustomerPaymentPreview();
            const customerSelect = document.getElementById('rvCustomerSelect');
            const paidInput = document.getElementById('rvCustomerPaidAmount');
            const customerOption = customerSelect ? customerSelect.options[customerSelect.selectedIndex] : null;
            const due = customerOption ? Number(customerOption.getAttribute('data-due') || 0) : 0;
            const paid = paidInput ? Number(paidInput.value || 0) : 0;

            if (!customerSelect || !customerSelect.value || paid <= 0 || paid > due) {
                event.preventDefault();
                if (paidInput) paidInput.reportValidity();
            }
        });
    }

    // ============================================================
    // SUPPLIER PAYMENT FORM VALIDATION
    // ============================================================
    const supplierPaymentForm = document.getElementById('rvSupplierPaymentForm');
    if (supplierPaymentForm) {
        supplierPaymentForm.addEventListener('submit', function (event) {
            updateSupplierPaymentPreview();
            const supplierSelect = document.getElementById('rvSupplierSelect');
            const paidInput = document.getElementById('rvSupplierPaidAmount');
            const assetSelect = document.getElementById('rvSupplierAssetSelect');
            const supplierOption = supplierSelect ? supplierSelect.options[supplierSelect.selectedIndex] : null;
            const assetOption = assetSelect ? assetSelect.options[assetSelect.selectedIndex] : null;
            const due = supplierOption ? Number(supplierOption.getAttribute('data-due') || 0) : 0;
            const paid = paidInput ? Number(paidInput.value || 0) : 0;
            const balance = assetOption ? Number(assetOption.getAttribute('data-balance') || 0) : 0;

            if (!supplierSelect || !supplierSelect.value || paid <= 0 || paid > due || paid > balance) {
                event.preventDefault();
                if (paidInput) paidInput.reportValidity();
            }
        });
    }

    // ============================================================
    // POST-MESSAGE LISTENER
    // ============================================================
    window.addEventListener('message', function (event) {
        if (event.data === 'rv:close-modal') closeRvModal(false);
        if (event.data === 'rv:close-modal-reload') closeRvModal(true);
    });
});
</script>

<?php include "footer.php"; ?>