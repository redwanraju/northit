<?php
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

// ============================================================
// Staff Salary Management - Refactored Safe Version
// File suggestion: staff_salary.php
// Purpose: SR salary generation/update + safe salary payment
// Safety: shop_id scoped, CSRF protected, prepared statements,
//         asset balance deduction, asset transaction history.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";

$shop_id = tenantShopId();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));

// ============================================================
// HELPERS
// ============================================================
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function taka($amount, $decimals = 2) {
    return '৳ ' . number_format((float)$amount, $decimals);
}

function cleanText($value, $maxLength = 255) {
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        }
    } elseif (strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }
    return $value;
}

function safeIdentifier($name) {
    return preg_match('/^[a-zA-Z0-9_]+$/', (string)$name) ? $name : '';
}

function tableExists($conn, $table) {
    static $cache = [];
    $table = safeIdentifier($table);
    if ($table === '') return false;
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    if (!$stmt) {
        error_log('tableExists prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $cache[$table] = ((int)($row[0] ?? 0) > 0);
    return $cache[$table];
}

function columnExists($conn, $table, $column) {
    static $cache = [];
    $table = safeIdentifier($table);
    $column = safeIdentifier($column);
    if ($table === '' || $column === '') return false;
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) {
        error_log('columnExists prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : [0];
    $cache[$key] = ((int)($row[0] ?? 0) > 0);
    return $cache[$key];
}

function fetchOne($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchOne prepare failed: ' . $conn->error . ' SQL: ' . $sql);
        return null;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_assoc() : null;
}

function fetchRows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchRows prepare failed: ' . $conn->error . ' SQL: ' . $sql);
        return [];
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) return [];

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function scalarQuery($conn, $sql, $types = '', $params = []) {
    $row = fetchOne($conn, $sql, $types, $params);
    if (!$row) return 0;
    return array_values($row)[0] ?? 0;
}

function postValue($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function redirectToSelf($params = []) {
    $base = basename($_SERVER['PHP_SELF']);
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    header('Location: ' . $base);
    exit;
}

function setFlash($type, $message) {
    $_SESSION['salary_flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    $flash = $_SESSION['salary_flash'] ?? null;
    unset($_SESSION['salary_flash']);
    return $flash;
}

function csrfToken() {
    if (empty($_SESSION['salary_csrf_token'])) {
        $_SESSION['salary_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['salary_csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function csrfIsValid() {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['salary_csrf_token'])
        && hash_equals($_SESSION['salary_csrf_token'], $token);
}

function validMonth($month) {
    return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month);
}

function validDate($date) {
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function salaryStatus($paid, $due) {
    if ($due <= 0) return 'paid';
    if ($paid > 0) return 'partial';
    return 'pending';
}

function paymentAssetTypes() {
    return [
        'cash' => 'নগদ / Cash',
        'bkash' => 'বিকাশ / bKash',
        'nagad' => 'নগদ মোবাইল ব্যাংকিং / Nagad',
        'bank' => 'ব্যাংক / Bank',
        'other' => 'Other'
    ];
}

function typeLabel($type, $labels) {
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function statusClass($status) {
    if ($status === 'paid') return 'status-paid';
    if ($status === 'partial') return 'status-partial';
    return 'status-pending';
}

function getSalaryId($row) {
    return (int)($row['id'] ?? ($row['salary_id'] ?? 0));
}

function insertAssetTransactionForSalary($conn, $shop_id, $asset, $asset_id, $paid_amount, $before, $after, $salary_id, $payment_date, $note, $user_id) {
    $asset_type = (string)($asset['asset_type'] ?? 'other');
    $asset_name = (string)($asset['asset_name'] ?? '');
    $reference_type = 'staff_salary';
    $movement_note = $note !== '' ? $note : 'Staff salary payment';

    $stmt = $conn->prepare("\n        INSERT INTO asset_transactions\n            (shop_id, transaction_type, movement_type,\n             from_asset_id, from_asset_type, from_asset_name,\n             to_asset_id, to_asset_type, to_asset_name,\n             amount, from_balance_before, from_balance_after,\n             to_balance_before, to_balance_after,\n             reference_type, reference_id, transaction_date, note, created_by)\n        VALUES\n            (?, 'out', 'salary_payment',\n             ?, ?, ?,\n             NULL, NULL, NULL,\n             ?, ?, ?,\n             NULL, NULL,\n             ?, ?, ?, ?, ?)\n    ");
    if (!$stmt) {
        throw new Exception('Asset transaction prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'iissdddsissi',
        $shop_id,
        $asset_id,
        $asset_type,
        $asset_name,
        $paid_amount,
        $before,
        $after,
        $reference_type,
        $salary_id,
        $payment_date,
        $movement_note,
        $user_id
    );
    $stmt->execute();
}

function applySalaryPayment($conn, $shop_id, $salary_id, $pay_from_asset_id, $paid_amount, $payment_date, $note, $user_id, $asset_transactions_table_ready) {
    if ($paid_amount <= 0) return;
    if ($pay_from_asset_id <= 0) {
        throw new Exception('Salary payment source asset select করতে হবে।');
    }
    if (!$asset_transactions_table_ready) {
        throw new Exception('asset_transactions table পাওয়া যায়নি। Salary payment history save করা যাবে না।');
    }

    $salary = fetchOne(
        $conn,
        "SELECT * FROM staff_salary WHERE " . (columnExists($conn, 'staff_salary', 'id') ? "id = ?" : "0 = 1") . " AND shop_id = ? LIMIT 1 FOR UPDATE",
        'ii',
        [$salary_id, $shop_id]
    );
    if (!$salary) {
        throw new Exception('Salary record পাওয়া যায়নি।');
    }

    $current_due = round((float)($salary['due_amount'] ?? 0), 2);
    $current_paid = round((float)($salary['paid_amount'] ?? 0), 2);
    if ($current_due <= 0) {
        throw new Exception('This salary already paid.');
    }
    if ($paid_amount > $current_due) {
        throw new Exception('Payment amount current salary due থেকে বেশি হতে পারবে না।');
    }

    $asset = fetchOne(
        $conn,
        "SELECT id, asset_type, asset_name, amount, status FROM assets WHERE id = ? AND shop_id = ? AND status = 'active' LIMIT 1 FOR UPDATE",
        'ii',
        [$pay_from_asset_id, $shop_id]
    );
    if (!$asset) {
        throw new Exception('Active payment source asset পাওয়া যায়নি।');
    }

    $allowed_types = paymentAssetTypes();
    $asset_type = (string)($asset['asset_type'] ?? 'other');
    if (!array_key_exists($asset_type, $allowed_types)) {
        throw new Exception('এই asset type থেকে salary payment allow করা হয়নি।');
    }

    $asset_before = round((float)($asset['amount'] ?? 0), 2);
    if ($paid_amount > $asset_before) {
        throw new Exception('Selected asset balance কম। Available: ' . taka($asset_before));
    }

    $asset_after = round($asset_before - $paid_amount, 2);
    $new_paid = round($current_paid + $paid_amount, 2);
    $new_due = round($current_due - $paid_amount, 2);
    $new_status = salaryStatus($new_paid, $new_due);

    $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
    if (!$stmt) throw new Exception('Asset update prepare failed: ' . $conn->error);
    $stmt->bind_param('dii', $asset_after, $pay_from_asset_id, $shop_id);
    $stmt->execute();

    $stmt = $conn->prepare("\n        UPDATE staff_salary\n        SET paid_amount = ?, due_amount = ?, payment_status = ?, payment_date = ?\n        WHERE id = ? AND shop_id = ?\n        LIMIT 1\n    ");
    if (!$stmt) throw new Exception('Salary update prepare failed: ' . $conn->error);
    $stmt->bind_param('ddssii', $new_paid, $new_due, $new_status, $payment_date, $salary_id, $shop_id);
    $stmt->execute();

    insertAssetTransactionForSalary($conn, $shop_id, $asset, $pay_from_asset_id, $paid_amount, $asset_before, $asset_after, $salary_id, $payment_date, $note, $user_id);
}

// ============================================================
// TABLE CHECKS
// ============================================================
$staff_table_ready = isset($conn) && $conn && tableExists($conn, 'sales_representatives');
$salary_table_ready = isset($conn) && $conn && tableExists($conn, 'staff_salary');
$advance_table_ready = isset($conn) && $conn && tableExists($conn, 'staff_advance_salary');
$orders_table_ready = isset($conn) && $conn && tableExists($conn, 'orders');
$assets_table_ready = isset($conn) && $conn && tableExists($conn, 'assets');
$asset_transactions_table_ready = isset($conn) && $conn && tableExists($conn, 'asset_transactions');

// ============================================================
// SR LOAD
// ============================================================
$sr_id = isset($_GET['sr_id']) ? (int)$_GET['sr_id'] : (int)($_POST['sr_id'] ?? 0);
if ($sr_id <= 0) {
    header('Location: sr_management.php');
    exit;
}

$sr = null;
if ($staff_table_ready) {
    $sr = fetchOne($conn, "SELECT * FROM sales_representatives WHERE id = ? AND shop_id = ? LIMIT 1", 'ii', [$sr_id, $shop_id]);
}
if (!$sr) {
    header('Location: sr_management.php');
    exit;
}

$sr_name = (string)($sr['sr_name'] ?? 'SR');
$basic_salary = (float)($sr['basic_salary'] ?? 0);
$commission_rate = (float)($sr['commission_rate'] ?? 0);
$calc_month = isset($_GET['calc_month']) && validMonth($_GET['calc_month']) ? $_GET['calc_month'] : date('Y-m');

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfIsValid()) {
        setFlash('error', 'Security token mismatch. Please try again.');
        redirectToSelf(['sr_id' => $sr_id, 'calc_month' => $calc_month]);
    }

    $action = postValue('action');

    try {
        if (!$salary_table_ready) {
            throw new Exception('staff_salary table পাওয়া যায়নি।');
        }
        if (!$assets_table_ready) {
            throw new Exception('assets table পাওয়া যায়নি। Salary payment asset balance থেকে করা যাবে না।');
        }

        // ---------------- SAVE / UPDATE SALARY ----------------
        if ($action === 'save_salary') {
            $salary_month = postValue('salary_month');
            $basic = (float)postValue('basic_salary', '0');
            $commission = (float)postValue('commission_amount', '0');
            $bonus = (float)postValue('bonus', '0');
            $deduction = (float)postValue('deduction', '0');
            $advance_deducted = (float)postValue('advance_deducted', '0');
            $pay_now = (float)postValue('pay_now', '0');
            $pay_from_asset_id = (int)postValue('pay_from_asset_id', '0');
            $payment_date = postValue('payment_date', date('Y-m-d'));
            $payment_note = cleanText(postValue('payment_note'), 2000);

            if (!validMonth($salary_month)) throw new Exception('Invalid salary month.');
            if (!validDate($payment_date)) throw new Exception('Invalid payment date.');
            foreach ([$basic, $commission, $bonus, $deduction, $advance_deducted, $pay_now] as $number) {
                if ($number < 0) throw new Exception('Negative amount allowed না।');
            }

            $total_payable = round($basic + $commission + $bonus - $deduction - $advance_deducted, 2);
            if ($total_payable < 0) {
                throw new Exception('Total payable negative হতে পারবে না।');
            }

            $conn->begin_transaction();
            try {
                $existing = fetchOne($conn, "SELECT * FROM staff_salary WHERE sr_id = ? AND shop_id = ? AND salary_month = ? LIMIT 1 FOR UPDATE", 'iis', [$sr_id, $shop_id, $salary_month]);
                $existing_paid = round((float)($existing['paid_amount'] ?? 0), 2);

                if ($existing_paid > $total_payable) {
                    throw new Exception('Existing paid amount total payable থেকে বেশি। আগে salary record adjust করো।');
                }

                $due_before_payment = round($total_payable - $existing_paid, 2);
                if ($pay_now > $due_before_payment) {
                    throw new Exception('Pay now amount current due থেকে বেশি হতে পারবে না। Current due: ' . taka($due_before_payment));
                }

                // Save/update the salary first with the existing paid amount only.
                // If Pay Now is given, applySalaryPayment() will reduce asset balance and then
                // update paid_amount/due_amount once. This avoids double-counting the payment.
                $paid_before_payment = $existing_paid;
                $due_before_payment = round($total_payable - $paid_before_payment, 2);
                $status_before_payment = salaryStatus($paid_before_payment, $due_before_payment);
                $payment_date_to_save = null;
                if ($existing && !empty($existing['payment_date'])) {
                    $payment_date_to_save = (string)$existing['payment_date'];
                } elseif ($paid_before_payment > 0) {
                    $payment_date_to_save = $payment_date;
                }

                if ($existing) {
                    $salary_id = getSalaryId($existing);
                    $stmt = $conn->prepare("
                        UPDATE staff_salary
                        SET basic_salary = ?, commission_amount = ?, bonus = ?, deduction = ?, advance_deducted = ?,
                            total_payable = ?, paid_amount = ?, due_amount = ?, payment_status = ?, payment_date = ?
                        WHERE sr_id = ? AND shop_id = ? AND salary_month = ?
                        LIMIT 1
                    ");
                    if (!$stmt) throw new Exception('Salary update prepare failed: ' . $conn->error);
                    $stmt->bind_param('ddddddddssiis', $basic, $commission, $bonus, $deduction, $advance_deducted, $total_payable, $paid_before_payment, $due_before_payment, $status_before_payment, $payment_date_to_save, $sr_id, $shop_id, $salary_month);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO staff_salary
                            (sr_id, shop_id, salary_month, basic_salary, commission_amount, bonus, deduction, advance_deducted, total_payable, paid_amount, due_amount, payment_status, payment_date)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) throw new Exception('Salary insert prepare failed: ' . $conn->error);
                    $stmt->bind_param('iisddddddddss', $sr_id, $shop_id, $salary_month, $basic, $commission, $bonus, $deduction, $advance_deducted, $total_payable, $paid_before_payment, $due_before_payment, $status_before_payment, $payment_date_to_save);
                    $stmt->execute();
                    $salary_id = (int)$conn->insert_id;
                }

                if (empty($salary_id) && columnExists($conn, 'staff_salary', 'id')) {
                    $row = fetchOne($conn, "SELECT id FROM staff_salary WHERE sr_id = ? AND shop_id = ? AND salary_month = ? LIMIT 1", 'iis', [$sr_id, $shop_id, $salary_month]);
                    $salary_id = (int)($row['id'] ?? 0);
                }

                if ($pay_now > 0) {
                    if ($salary_id <= 0) {
                        throw new Exception('Salary ID পাওয়া যায়নি। Payment history save করা যাচ্ছে না।');
                    }
                    applySalaryPayment($conn, $shop_id, $salary_id, $pay_from_asset_id, $pay_now, $payment_date, $payment_note, $user_id, $asset_transactions_table_ready);
                }

                $conn->commit();
                setFlash('success', $pay_now > 0 ? 'Salary saved and payment posted successfully.' : 'Salary saved successfully.');
                redirectToSelf(['sr_id' => $sr_id, 'calc_month' => $salary_month]);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        // ---------------- PAY EXISTING SALARY DUE ----------------
        if ($action === 'pay_salary_due') {
            $salary_id = (int)postValue('salary_id', '0');
            $pay_from_asset_id = (int)postValue('pay_from_asset_id', '0');
            $paid_amount = round((float)postValue('paid_amount', '0'), 2);
            $payment_date = postValue('payment_date', date('Y-m-d'));
            $payment_note = cleanText(postValue('payment_note'), 2000);

            if ($salary_id <= 0) throw new Exception('Invalid salary selected.');
            if ($paid_amount <= 0) throw new Exception('Payment amount must be greater than zero.');
            if (!validDate($payment_date)) throw new Exception('Invalid payment date.');

            $conn->begin_transaction();
            try {
                applySalaryPayment($conn, $shop_id, $salary_id, $pay_from_asset_id, $paid_amount, $payment_date, $payment_note, $user_id, $asset_transactions_table_ready);
                $conn->commit();
                setFlash('success', 'Salary payment posted successfully. Asset balance কমেছে এবং history recorded হয়েছে।');
                redirectToSelf(['sr_id' => $sr_id, 'calc_month' => $calc_month]);
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        throw new Exception('Invalid action.');
    } catch (Throwable $e) {
        error_log('staff_salary error: ' . $e->getMessage());
        setFlash('error', $e->getMessage());
        redirectToSelf(['sr_id' => $sr_id, 'calc_month' => $calc_month]);
    }
}

// ============================================================
// PAGE DATA
// ============================================================
$flash = getFlash();
$payment_asset_labels = paymentAssetTypes();

$pending_advance = 0;
if ($advance_table_ready) {
    $pending_advance = (float)scalarQuery(
        $conn,
        "SELECT COALESCE(SUM(amount),0) FROM staff_advance_salary WHERE sr_id = ? AND shop_id = ? AND status = 'approved' AND (deduct_from_month IS NULL OR deduct_from_month = '')",
        'ii',
        [$sr_id, $shop_id]
    );
}

$month_start = $calc_month . '-01 00:00:00';
$next_month = date('Y-m-d 00:00:00', strtotime($calc_month . '-01 +1 month'));
$order_total = 0;
$order_count = 0;
if ($orders_table_ready) {
    $order_shop_where = columnExists($conn, 'orders', 'shop_id') ? ' AND shop_id = ? ' : '';
    $types = columnExists($conn, 'orders', 'shop_id') ? 'iss' : 'iss';
    if (columnExists($conn, 'orders', 'shop_id')) {
        $commission_row = fetchOne(
            $conn,
            "SELECT COALESCE(SUM(total_amount),0) AS order_total, COUNT(*) AS order_count FROM orders WHERE sr_id = ? AND shop_id = ? AND order_source = 'pos' AND created_at >= ? AND created_at < ?",
            'iiss',
            [$sr_id, $shop_id, $month_start, $next_month]
        );
    } else {
        // Legacy schema fallback: scope through the current shop's SR record.
        $commission_row = fetchOne(
            $conn,
            "SELECT COALESCE(SUM(o.total_amount),0) AS order_total, COUNT(*) AS order_count
             FROM orders o
             INNER JOIN sales_representatives sr ON sr.id = o.sr_id
             WHERE o.sr_id = ? AND sr.shop_id = ? AND o.order_source = 'pos' AND o.created_at >= ? AND o.created_at < ?",
            'iiss',
            [$sr_id, $shop_id, $month_start, $next_month]
        );
    }
    $order_total = (float)($commission_row['order_total'] ?? 0);
    $order_count = (int)($commission_row['order_count'] ?? 0);
}
$auto_commission = round($order_total * ($commission_rate / 100), 2);

$salaries = [];
if ($salary_table_ready) {
    $select_id = columnExists($conn, 'staff_salary', 'id') ? 'id,' : '';
    $salaries = fetchRows(
        $conn,
        "SELECT $select_id sr_id, shop_id, salary_month, basic_salary, commission_amount, bonus, deduction, advance_deducted, total_payable, paid_amount, due_amount, payment_status, payment_date\n         FROM staff_salary\n         WHERE sr_id = ? AND shop_id = ?\n         ORDER BY salary_month DESC",
        'ii',
        [$sr_id, $shop_id]
    );
}

$total_paid_this_year = (float)scalarQuery(
    $conn,
    "SELECT COALESCE(SUM(paid_amount),0) FROM staff_salary WHERE sr_id = ? AND shop_id = ? AND LEFT(salary_month,4) = YEAR(CURDATE())",
    'ii',
    [$sr_id, $shop_id]
);
$total_due = (float)scalarQuery(
    $conn,
    "SELECT COALESCE(SUM(due_amount),0) FROM staff_salary WHERE sr_id = ? AND shop_id = ?",
    'ii',
    [$sr_id, $shop_id]
);

$payment_assets = [];
if ($assets_table_ready) {
    $payment_assets = fetchRows(
        $conn,
        "SELECT id, asset_type, asset_name, amount\n         FROM assets\n         WHERE shop_id = ?\n           AND status = 'active'\n           AND amount > 0\n           AND asset_type IN ('cash','bkash','nagad','bank','other')\n         ORDER BY FIELD(asset_type, 'cash','bkash','nagad','bank','other'), asset_name ASC",
        'i',
        [$shop_id]
    );
}

include "header.php";
include "sidebar.php";
?>

<style>
.main{flex:1; padding:20px; background:#f8fafc; min-height:100vh;}
.card-box{background:#fff; padding:16px; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:16px; border:1px solid #e2e8f0;}
.stat-box{background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); color:#fff; padding:15px 20px; border-radius:14px; text-align:center; min-height:88px;}
.stat-box h4{margin:4px 0 0; font-size:24px; font-weight:800;}
.stat-box small{opacity:.85; font-weight:600;}
.status-paid{background:#10b981; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700;}
.status-partial{background:#f59e0b; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700;}
.status-pending{background:#ef4444; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700;}
.salary-alert{padding:12px 14px; border-radius:12px; margin-bottom:14px; font-weight:600;}
.salary-alert-success{background:#ecfdf5; color:#047857; border:1px solid #bbf7d0;}
.salary-alert-error{background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;}
.salary-warning{background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; padding:12px 14px; border-radius:12px; margin-bottom:14px;}
.form-label{font-weight:700; font-size:.82rem; color:#475569;}
.money{font-weight:800; white-space:nowrap;}
.table td,.table th{vertical-align:middle; font-size:.9rem;}
.modal-backdrop-custom{position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; padding:18px; z-index:9999;}
.modal-backdrop-custom.show{display:flex;}
.modal-card-custom{width:100%; max-width:560px; background:#fff; border-radius:18px; box-shadow:0 24px 70px rgba(0,0,0,.25); overflow:hidden; border:1px solid #e2e8f0;}
.modal-head-custom{display:flex; justify-content:space-between; align-items:center; padding:16px 18px; background:#f8fafc; border-bottom:1px solid #e2e8f0;}
.modal-head-custom h5{margin:0; font-weight:800;}
.modal-close-custom{border:none; background:#e2e8f0; color:#334155; width:34px; height:34px; border-radius:10px; font-weight:900;}
.modal-body-custom{padding:18px;}
.pay-context{background:#f8fafc; border:1px dashed #cbd5e1; border-radius:12px; padding:12px 14px; margin-bottom:14px; color:#334155;}
</style>

<div class="main">
    <h3>💰 Salary — <?= h($sr_name) ?></h3>
    <p class="text-muted">
        Basic Salary: <?= taka($basic_salary) ?> |
        Commission Rate: <?= h($commission_rate) ?>% |
        Shop ID: <?= (int)$shop_id ?> |
        <a href="sr_management.php">← Back</a>
    </p>

    <?php if ($flash): ?>
        <div class="salary-alert <?= ($flash['type'] ?? '') === 'success' ? 'salary-alert-success' : 'salary-alert-error' ?>">
            <?= h($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if (!$asset_transactions_table_ready): ?>
        <div class="salary-warning"><strong>Warning:</strong> `asset_transactions` table পাওয়া যায়নি বা enum update হয়নি। Salary payment posting disabled থাকবে।</div>
    <?php endif; ?>

    <?php if (empty($payment_assets)): ?>
        <div class="salary-warning"><strong>Warning:</strong> কোন active Cash/bKash/Nagad/Bank asset balance পাওয়া যায়নি। Salary pay করতে হলে আগে `assets` table-এ balance add করো।</div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3 mb-2"><div class="stat-box"><small>Pending Advance</small><h4><?= taka($pending_advance) ?></h4></div></div>
        <div class="col-md-3 mb-2"><div class="stat-box" style="background:linear-gradient(135deg,#059669 0%,#10b981 100%);"><small>Total Paid This Year</small><h4><?= taka($total_paid_this_year) ?></h4></div></div>
        <div class="col-md-3 mb-2"><div class="stat-box" style="background:linear-gradient(135deg,#7c3aed 0%,#8b5cf6 100%);"><small>Total Due</small><h4><?= taka($total_due) ?></h4></div></div>
        <div class="col-md-3 mb-2"><div class="stat-box" style="background:linear-gradient(135deg,#ea580c 0%,#f97316 100%);"><small>Records</small><h4><?= number_format(count($salaries)) ?></h4></div></div>
    </div>

    <div class="card-box">
        <h5>🧮 Auto Commission Calculator</h5>
        <form method="GET" class="row g-2">
            <input type="hidden" name="sr_id" value="<?= (int)$sr_id ?>">
            <div class="col-md-3">
                <input type="month" name="calc_month" class="form-control" value="<?= h($calc_month) ?>">
            </div>
            <div class="col-md-3">
                <button class="btn btn-info w-100" type="submit">🔄 Calculate</button>
            </div>
        </form>
        <div class="mt-3 p-3 bg-light rounded">
            <p class="mb-1"><b>Month:</b> <?= h(date('F Y', strtotime($calc_month . '-01'))) ?></p>
            <p class="mb-1"><b>Total Orders:</b> <?= number_format($order_count) ?></p>
            <p class="mb-1"><b>Order Amount:</b> <?= taka($order_total) ?></p>
            <p class="mb-0"><b>Commission (<?= h($commission_rate) ?>%):</b> <span class="text-success fw-bold"><?= taka($auto_commission) ?></span></p>
        </div>
    </div>

    <div class="card-box">
        <h5>➕ Add / Update Salary</h5>
        <form method="POST" class="row g-2" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_salary">
            <input type="hidden" name="sr_id" value="<?= (int)$sr_id ?>">

            <div class="col-md-2">
                <label class="form-label">Month</label>
                <input type="month" name="salary_month" class="form-control" value="<?= h($calc_month) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Basic Salary</label>
                <input type="number" step="0.01" min="0" name="basic_salary" class="form-control" value="<?= h((string)$basic_salary) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Commission</label>
                <input type="number" step="0.01" min="0" name="commission_amount" class="form-control" value="<?= h((string)$auto_commission) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Bonus</label>
                <input type="number" step="0.01" min="0" name="bonus" class="form-control" value="0">
            </div>
            <div class="col-md-1">
                <label class="form-label">Deduction</label>
                <input type="number" step="0.01" min="0" name="deduction" class="form-control" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">Advance Deduct</label>
                <input type="number" step="0.01" min="0" name="advance_deducted" class="form-control" value="0" max="<?= h((string)$pending_advance) ?>">
                <small class="text-muted">Max: <?= taka($pending_advance) ?></small>
            </div>
            <div class="col-md-2">
                <label class="form-label">Pay Now</label>
                <input type="number" step="0.01" min="0" name="pay_now" id="savePayNow" class="form-control" value="0">
                <small class="text-muted">Optional; source needed if &gt; 0</small>
            </div>

            <div class="col-md-4">
                <label class="form-label">Pay From Asset</label>
                <select name="pay_from_asset_id" id="savePayFromAsset" class="form-control" <?= empty($payment_assets) ? 'disabled' : '' ?>>
                    <option value="">Select source only if Pay Now &gt; 0</option>
                    <?php foreach ($payment_assets as $asset): ?>
                        <option value="<?= (int)$asset['id'] ?>">
                            <?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Payment Date</label>
                <input type="date" name="payment_date" class="form-control" value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label">Payment Note</label>
                <input type="text" name="payment_note" maxlength="2000" class="form-control" placeholder="Optional note / receipt reference">
            </div>

            <div class="col-md-12 mt-2">
                <button type="submit" class="btn btn-success" <?= (!$salary_table_ready || ($asset_transactions_table_ready === false && empty($payment_assets) === false)) ? '' : '' ?>>💾 Save Salary</button>
            </div>
        </form>
    </div>

    <div class="card-box">
        <h5>📜 Salary History</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Month</th>
                        <th>Basic</th>
                        <th>Commission</th>
                        <th>Bonus</th>
                        <th>Deduction</th>
                        <th>Advance Cut</th>
                        <th>Total Payable</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($salaries)): ?>
                        <?php foreach ($salaries as $s): ?>
                            <?php
                                $status = (string)($s['payment_status'] ?? 'pending');
                                $due_amount = (float)($s['due_amount'] ?? 0);
                                $salary_id = getSalaryId($s);
                            ?>
                            <tr>
                                <td><b><?= h(date('F Y', strtotime(($s['salary_month'] ?? '') . '-01'))) ?></b></td>
                                <td><?= taka($s['basic_salary'] ?? 0) ?></td>
                                <td><?= taka($s['commission_amount'] ?? 0) ?></td>
                                <td><?= taka($s['bonus'] ?? 0) ?></td>
                                <td class="text-danger"><?= taka($s['deduction'] ?? 0) ?></td>
                                <td class="text-warning"><?= taka($s['advance_deducted'] ?? 0) ?></td>
                                <td class="text-primary money"><?= taka($s['total_payable'] ?? 0) ?></td>
                                <td class="text-success money"><?= taka($s['paid_amount'] ?? 0) ?></td>
                                <td class="text-danger money"><?= taka($due_amount) ?></td>
                                <td><span class="<?= h(statusClass($status)) ?>"><?= h(ucfirst($status)) ?></span></td>
                                <td><?= !empty($s['payment_date']) ? h(date('d M Y', strtotime($s['payment_date']))) : '-' ?></td>
                                <td>
                                    <?php if ($salary_id > 0 && $due_amount > 0): ?>
                                        <button
                                            class="btn btn-sm btn-success"
                                            type="button"
                                            data-salary-pay-open
                                            data-salary-id="<?= (int)$salary_id ?>"
                                            data-salary-month="<?= h($s['salary_month'] ?? '') ?>"
                                            data-salary-due="<?= h((string)$due_amount) ?>"
                                            <?= (empty($payment_assets) || !$asset_transactions_table_ready) ? 'disabled' : '' ?>
                                        >Pay</button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="12" class="text-center text-muted">No salary records found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-backdrop-custom" id="salaryPayModal" aria-hidden="true">
    <div class="modal-card-custom" role="dialog" aria-modal="true">
        <div class="modal-head-custom">
            <h5>Pay Salary Due</h5>
            <button class="modal-close-custom" type="button" data-salary-pay-close>&times;</button>
        </div>
        <div class="modal-body-custom">
            <div class="pay-context">
                <div><strong>Month:</strong> <span id="paySalaryMonth">-</span></div>
                <div><strong>Current Due:</strong> <span id="paySalaryDue">৳ 0.00</span></div>
            </div>
            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="pay_salary_due">
                <input type="hidden" name="sr_id" value="<?= (int)$sr_id ?>">
                <input type="hidden" name="salary_id" id="paySalaryId" value="0">

                <div class="mb-3">
                    <label class="form-label">Pay From Asset</label>
                    <select name="pay_from_asset_id" class="form-control" required>
                        <option value="">Select source</option>
                        <?php foreach ($payment_assets as $asset): ?>
                            <option value="<?= (int)$asset['id'] ?>">
                                <?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Payment Amount</label>
                        <input type="number" name="paid_amount" id="paySalaryAmount" min="0.01" step="0.01" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Note</label>
                    <textarea name="payment_note" maxlength="2000" class="form-control" placeholder="Optional note / receipt reference"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-light" type="button" data-salary-pay-close>Cancel</button>
                    <button class="btn btn-success" type="submit">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('salaryPayModal');
    const idInput = document.getElementById('paySalaryId');
    const monthEl = document.getElementById('paySalaryMonth');
    const dueEl = document.getElementById('paySalaryDue');
    const amountInput = document.getElementById('paySalaryAmount');

    function formatTaka(value) {
        const number = Number(value || 0);
        return '৳ ' + number.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function openModal(button) {
        const id = button.getAttribute('data-salary-id') || '0';
        const month = button.getAttribute('data-salary-month') || '-';
        const due = button.getAttribute('data-salary-due') || '0';

        idInput.value = id;
        monthEl.textContent = month;
        dueEl.textContent = formatTaka(due);
        amountInput.value = due;
        amountInput.max = due;

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-salary-pay-open]').forEach(function (button) {
        button.addEventListener('click', function () { openModal(button); });
    });
    document.querySelectorAll('[data-salary-pay-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });
});
</script>

<?php include "footer.php"; ?>
