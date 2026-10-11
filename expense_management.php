<?php
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

// ============================================================
// Expense Management - Professional CRUD + Payment Source + History
// File: expense_management.php
// Safety: shop_id scoped, CSRF protected, prepared statements,
//         modal CRUD, expense payment with asset balance update
//         + asset_transactions history.
// ============================================================

session_start();
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
    return 'TK. ' . number_format((float)$amount, $decimals);
}

function moneyShort($amount) {
    return '৳' . number_format((float)$amount, 0);
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

function scalarQuery($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('scalarQuery prepare failed: ' . $conn->error . ' SQL: ' . $sql);
        return 0;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) return 0;
    $row = $res->fetch_row();
    return $row ? ($row[0] ?? 0) : 0;
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

function postValue($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
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

function setFlash($type, $message) {
    $_SESSION['expense_flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    $flash = $_SESSION['expense_flash'] ?? null;
    unset($_SESSION['expense_flash']);
    return $flash;
}

function csrfToken() {
    if (empty($_SESSION['expense_csrf_token'])) {
        $_SESSION['expense_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['expense_csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function csrfIsValid() {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['expense_csrf_token'])
        && hash_equals($_SESSION['expense_csrf_token'], $token);
}

function redirectToSelf($params = []) {
    $base = basename($_SERVER['PHP_SELF']);
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header('Location: ' . $base);
    exit;
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

function paymentMethodFromAssetType($asset_type) {
    if ($asset_type === 'bank') return 'bank';
    if (in_array($asset_type, ['bkash', 'nagad'], true)) return 'mobile_banking';
    return 'cash';
}

function methodLabel($method) {
    $labels = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'mobile_banking' => 'Mobile Banking'
    ];
    return $labels[$method] ?? ucwords(str_replace('_', ' ', (string)$method));
}

function methodBadgeClass($method) {
    if ($method === 'cash') return 'bg-success';
    if ($method === 'bank') return 'bg-info';
    if ($method === 'mobile_banking') return 'bg-warning text-dark';
    return 'bg-secondary';
}

function expenseStatusBadge($status) {
    return $status === 'due' ? 'bg-danger' : 'bg-success';
}

function typeLabel($type, $labels) {
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function validateDateString($date, $fieldName = 'Date') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        throw new Exception($fieldName . ' is invalid.');
    }
}

function lockAssetForPayment($conn, $asset_id, $shop_id) {
    $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount, status FROM assets WHERE id = ? AND shop_id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('ii', $asset_id, $shop_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $asset = $res ? $res->fetch_assoc() : null;
    if (!$asset) throw new Exception('Selected active payment asset পাওয়া যায়নি।');

    $asset_type = (string)($asset['asset_type'] ?? 'other');
    $allowed = paymentAssetTypes();
    if (!array_key_exists($asset_type, $allowed)) {
        throw new Exception('এই asset type থেকে expense payment allow করা হয়নি।');
    }
    return $asset;
}

function insertAssetTransactionExpense($conn, $shop_id, $expense_id, $asset, $amount, $balance_before, $balance_after, $date, $note, $user_id) {
    $from_asset_id = (int)$asset['id'];
    $from_asset_type = (string)$asset['asset_type'];
    $from_asset_name = (string)$asset['asset_name'];
    $reference_type = 'expense';

    $stmt = $conn->prepare("\n        INSERT INTO asset_transactions\n            (shop_id, transaction_type, movement_type,\n             from_asset_id, from_asset_type, from_asset_name,\n             to_asset_id, to_asset_type, to_asset_name,\n             amount, from_balance_before, from_balance_after,\n             to_balance_before, to_balance_after,\n             reference_type, reference_id, transaction_date, note, created_by)\n        VALUES\n            (?, 'out', 'expense_payment',\n             ?, ?, ?,\n             NULL, NULL, NULL,\n             ?, ?, ?,\n             NULL, NULL,\n             ?, ?, ?, ?, ?)\n    ");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param(
        'iissdddsissi',
        $shop_id,
        $from_asset_id,
        $from_asset_type,
        $from_asset_name,
        $amount,
        $balance_before,
        $balance_after,
        $reference_type,
        $expense_id,
        $date,
        $note,
        $user_id
    );
    $stmt->execute();
}

function updateAssetTransactionExpense($conn, $txn_id, $shop_id, $asset, $amount, $balance_before, $balance_after, $date, $note) {
    $from_asset_id = (int)$asset['id'];
    $from_asset_type = (string)$asset['asset_type'];
    $from_asset_name = (string)$asset['asset_name'];

    $stmt = $conn->prepare("\n        UPDATE asset_transactions\n        SET from_asset_id = ?,\n            from_asset_type = ?,\n            from_asset_name = ?,\n            amount = ?,\n            from_balance_before = ?,\n            from_balance_after = ?,\n            transaction_date = ?,\n            note = ?\n        WHERE id = ? AND shop_id = ? AND reference_type = 'expense' AND movement_type = 'expense_payment'\n        LIMIT 1\n    ");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('issdddssii', $from_asset_id, $from_asset_type, $from_asset_name, $amount, $balance_before, $balance_after, $date, $note, $txn_id, $shop_id);
    $stmt->execute();
}

$payment_asset_labels = paymentAssetTypes();

$expenses_table_ready = isset($conn) && $conn && tableExists($conn, 'expenses');
$assets_table_ready = isset($conn) && $conn && tableExists($conn, 'assets');
$asset_transactions_table_ready = isset($conn) && $conn && tableExists($conn, 'asset_transactions');
$expense_status_column_ready = $expenses_table_ready && columnExists($conn, 'expenses', 'status');
$expense_note_column_ready = $expenses_table_ready && columnExists($conn, 'expenses', 'note');
$system_ready = $expenses_table_ready;

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfIsValid()) {
        setFlash('error', 'Security token mismatch. Please try again.');
        redirectToSelf();
    }

    if (!isset($conn) || !$conn) {
        setFlash('error', 'Database connection not available.');
        redirectToSelf();
    }

    $action = postValue('action');

    try {
        if ($action === 'save_expense') {
            if (!$expenses_table_ready) throw new Exception('expenses table পাওয়া যায়নি।');

            $expense_date = postValue('expense_date', date('Y-m-d'));
            $category = cleanText(postValue('category'), 100);
            $description = cleanText(postValue('description'), 2000);
            $amount_raw = postValue('amount', '0');
            $reference_no = cleanText(postValue('reference_no'), 100);
            $status = $expense_status_column_ready ? postValue('status', 'paid') : 'paid';
            $pay_from_asset_id = (int)postValue('pay_from_asset_id', '0');

            validateDateString($expense_date, 'Expense date');
            if ($category === '') throw new Exception('Category is required.');
            if ($description === '') throw new Exception('Description is required.');
            if (!is_numeric($amount_raw)) throw new Exception('Amount must be numeric.');
            $amount = round((float)$amount_raw, 2);
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
            if (!in_array($status, ['paid', 'due'], true)) throw new Exception('Invalid expense status.');

            if ($status === 'paid' && (!$assets_table_ready || !$asset_transactions_table_ready)) {
                throw new Exception('Paid expense save করতে assets এবং asset_transactions table লাগবে।');
            }
            if ($status === 'paid' && $pay_from_asset_id <= 0) {
                throw new Exception('Paid expense এর জন্য Pay From Asset select করতে হবে।');
            }

            $conn->begin_transaction();
            try {
                $asset = null;
                $asset_before = null;
                $asset_after = null;
                $payment_method = 'cash';

                if ($status === 'paid') {
                    $asset = lockAssetForPayment($conn, $pay_from_asset_id, $shop_id);
                    $asset_before = round((float)($asset['amount'] ?? 0), 2);
                    if ($amount > $asset_before) {
                        throw new Exception('Selected asset balance কম। Available: ' . taka($asset_before));
                    }
                    $asset_after = round($asset_before - $amount, 2);
                    $payment_method = paymentMethodFromAssetType((string)$asset['asset_type']);

                    $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('dii', $asset_after, $pay_from_asset_id, $shop_id);
                    $stmt->execute();
                }

                $stmt = $conn->prepare("\n                    INSERT INTO expenses\n                        (shop_id, expense_date, category, description, amount, payment_method, reference_no, created_by, status, note)\n                    VALUES\n                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $note = '';
                $stmt->bind_param('isssdssiss', $shop_id, $expense_date, $category, $description, $amount, $payment_method, $reference_no, $user_id, $status, $note);
                $stmt->execute();
                $expense_id = (int)$conn->insert_id;

                if ($status === 'paid' && $asset) {
                    $txn_note = 'Expense payment: ' . $description;
                    insertAssetTransactionExpense($conn, $shop_id, $expense_id, $asset, $amount, $asset_before, $asset_after, $expense_date, $txn_note, $user_id);
                }

                $conn->commit();
                setFlash('success', 'Expense saved successfully. ' . ($status === 'paid' ? 'Asset balance কমেছে।' : 'Due expense হিসেবে save হয়েছে।'));
                redirectToSelf();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        if ($action === 'update_expense') {
            if (!$expenses_table_ready) throw new Exception('expenses table পাওয়া যায়নি।');

            $expense_id = (int)postValue('expense_id', '0');
            $expense_date = postValue('expense_date', date('Y-m-d'));
            $category = cleanText(postValue('category'), 100);
            $description = cleanText(postValue('description'), 2000);
            $amount_raw = postValue('amount', '0');
            $reference_no = cleanText(postValue('reference_no'), 100);
            $status = $expense_status_column_ready ? postValue('status', 'paid') : 'paid';
            $pay_from_asset_id = (int)postValue('pay_from_asset_id', '0');
            $create_asset_txn = postValue('create_asset_txn', '0') === '1';

            if ($expense_id <= 0) throw new Exception('Invalid expense selected.');
            validateDateString($expense_date, 'Expense date');
            if ($category === '') throw new Exception('Category is required.');
            if ($description === '') throw new Exception('Description is required.');
            if (!is_numeric($amount_raw)) throw new Exception('Amount must be numeric.');
            $amount = round((float)$amount_raw, 2);
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
            if (!in_array($status, ['paid', 'due'], true)) throw new Exception('Invalid expense status.');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ii', $expense_id, $shop_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $old_expense = $res ? $res->fetch_assoc() : null;
                if (!$old_expense) throw new Exception('Expense record পাওয়া যায়নি।');

                $old_txn = null;
                if ($asset_transactions_table_ready) {
                    $stmt = $conn->prepare("\n                        SELECT * FROM asset_transactions\n                        WHERE shop_id = ? AND reference_type = 'expense' AND reference_id = ? AND movement_type = 'expense_payment'\n                        ORDER BY id DESC LIMIT 1 FOR UPDATE\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ii', $shop_id, $expense_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $old_txn = $res ? $res->fetch_assoc() : null;
                }

                $payment_method = (string)($old_expense['payment_method'] ?? 'cash');

                // If existing expense has linked asset transaction, reverse old asset effect first.
                if ($old_txn) {
                    $old_from_asset_id = (int)($old_txn['from_asset_id'] ?? 0);
                    $old_amount = round((float)($old_txn['amount'] ?? 0), 2);
                    if ($old_from_asset_id > 0 && $old_amount > 0) {
                        $stmt = $conn->prepare("SELECT id, amount FROM assets WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE");
                        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                        $stmt->bind_param('ii', $old_from_asset_id, $shop_id);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $old_asset = $res ? $res->fetch_assoc() : null;
                        if ($old_asset) {
                            $reversed_amount = round((float)$old_asset['amount'] + $old_amount, 2);
                            $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                            $stmt->bind_param('dii', $reversed_amount, $old_from_asset_id, $shop_id);
                            $stmt->execute();
                        }
                    }
                }

                $new_txn_needed = ($status === 'paid') && ($old_txn || $create_asset_txn);

                if ($new_txn_needed) {
                    if (!$assets_table_ready || !$asset_transactions_table_ready) {
                        throw new Exception('Asset transaction update করতে assets এবং asset_transactions table লাগবে।');
                    }
                    if ($pay_from_asset_id <= 0) {
                        throw new Exception('Paid expense transaction এর জন্য Pay From Asset select করতে হবে।');
                    }
                    $asset = lockAssetForPayment($conn, $pay_from_asset_id, $shop_id);
                    $asset_before = round((float)($asset['amount'] ?? 0), 2);
                    if ($amount > $asset_before) {
                        throw new Exception('Selected asset balance কম। Available: ' . taka($asset_before));
                    }
                    $asset_after = round($asset_before - $amount, 2);
                    $payment_method = paymentMethodFromAssetType((string)$asset['asset_type']);

                    $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('dii', $asset_after, $pay_from_asset_id, $shop_id);
                    $stmt->execute();

                    $txn_note = 'Expense payment: ' . $description;
                    if ($old_txn) {
                        updateAssetTransactionExpense($conn, (int)$old_txn['id'], $shop_id, $asset, $amount, $asset_before, $asset_after, $expense_date, $txn_note);
                    } else {
                        insertAssetTransactionExpense($conn, $shop_id, $expense_id, $asset, $amount, $asset_before, $asset_after, $expense_date, $txn_note, $user_id);
                    }
                } elseif ($old_txn && $status === 'due') {
                    // Status changed to due, so remove linked payment transaction after reversal.
                    $stmt = $conn->prepare("DELETE FROM asset_transactions WHERE id = ? AND shop_id = ? LIMIT 1");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $old_txn_id = (int)$old_txn['id'];
                    $stmt->bind_param('ii', $old_txn_id, $shop_id);
                    $stmt->execute();
                    $payment_method = (string)($old_expense['payment_method'] ?? 'cash');
                }

                $stmt = $conn->prepare("\n                    UPDATE expenses\n                    SET expense_date = ?, category = ?, description = ?, amount = ?, payment_method = ?, reference_no = ?, status = ?\n                    WHERE id = ? AND shop_id = ?\n                    LIMIT 1\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('sssdsssii', $expense_date, $category, $description, $amount, $payment_method, $reference_no, $status, $expense_id, $shop_id);
                $stmt->execute();

                $conn->commit();
                setFlash('success', 'Expense updated successfully.');
                redirectToSelf();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        if ($action === 'pay_due_expense') {
            if (!$expenses_table_ready || !$assets_table_ready || !$asset_transactions_table_ready) {
                throw new Exception('Payment করতে expenses, assets এবং asset_transactions table লাগবে।');
            }

            $expense_id = (int)postValue('expense_id', '0');
            $pay_from_asset_id = (int)postValue('pay_from_asset_id', '0');
            $paid_amount_raw = postValue('paid_amount', '0');
            $payment_date = postValue('payment_date', date('Y-m-d'));
            $payment_note = cleanText(postValue('payment_note'), 2000);

            if ($expense_id <= 0) throw new Exception('Invalid expense selected.');
            if ($pay_from_asset_id <= 0) throw new Exception('Please select payment asset.');
            if (!is_numeric($paid_amount_raw)) throw new Exception('Payment amount must be numeric.');
            $paid_amount = round((float)$paid_amount_raw, 2);
            if ($paid_amount <= 0) throw new Exception('Payment amount must be greater than zero.');
            validateDateString($payment_date, 'Payment date');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ii', $expense_id, $shop_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $expense = $res ? $res->fetch_assoc() : null;
                if (!$expense) throw new Exception('Expense record পাওয়া যায়নি।');
                if (($expense['status'] ?? 'paid') !== 'due') throw new Exception('Only due expense can be paid from here.');

                $amount = round((float)($expense['amount'] ?? 0), 2);
                if ($amount <= 0) throw new Exception('Expense amount invalid.');

                $already_paid = (float)scalarQuery(
                    $conn,
                    "SELECT COALESCE(SUM(amount),0)
                     FROM asset_transactions
                     WHERE shop_id = ?
                       AND reference_type = 'expense'
                       AND reference_id = ?
                       AND movement_type = 'expense_payment'",
                    'ii',
                    [$shop_id, $expense_id]
                );

                if ($already_paid <= 0 && ($expense['status'] ?? 'due') === 'paid') {
                    $already_paid = $amount;
                }

                $remaining_due = round(max(0, $amount - $already_paid), 2);
                if ($remaining_due <= 0) throw new Exception('This expense is already paid.');
                if ($paid_amount > $remaining_due) {
                    throw new Exception('Payment amount due amount থেকে বেশি হতে পারবে না। Due: ' . taka($remaining_due));
                }

                $asset = lockAssetForPayment($conn, $pay_from_asset_id, $shop_id);
                $asset_before = round((float)($asset['amount'] ?? 0), 2);
                if ($paid_amount > $asset_before) {
                    throw new Exception('Selected asset balance কম। Available: ' . taka($asset_before));
                }
                $asset_after = round($asset_before - $paid_amount, 2);
                $payment_method = paymentMethodFromAssetType((string)$asset['asset_type']);
                $new_status = ($remaining_due - $paid_amount) <= 0 ? 'paid' : 'due';

                $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('dii', $asset_after, $pay_from_asset_id, $shop_id);
                $stmt->execute();

                $stmt = $conn->prepare("UPDATE expenses SET status = ?, payment_method = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ssii', $new_status, $payment_method, $expense_id, $shop_id);
                $stmt->execute();

                $txn_note = $payment_note !== '' ? $payment_note : ('Expense payment: ' . (string)($expense['description'] ?? ''));
                insertAssetTransactionExpense($conn, $shop_id, $expense_id, $asset, $paid_amount, $asset_before, $asset_after, $payment_date, $txn_note, $user_id);

                $conn->commit();
                setFlash('success', 'Expense payment saved. Paid: ' . taka($paid_amount) . ', Remaining due: ' . taka(max(0, $remaining_due - $paid_amount)));
                redirectToSelf();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        if ($action === 'delete_expense') {
            if (!$expenses_table_ready) throw new Exception('expenses table পাওয়া যায়নি।');
            $expense_id = (int)postValue('expense_id', '0');
            if ($expense_id <= 0) throw new Exception('Invalid expense selected.');

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ii', $expense_id, $shop_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $expense = $res ? $res->fetch_assoc() : null;
                if (!$expense) throw new Exception('Expense record পাওয়া যায়নি।');

                if ($asset_transactions_table_ready && $assets_table_ready) {
                    $stmt = $conn->prepare("\n                        SELECT * FROM asset_transactions\n                        WHERE shop_id = ? AND reference_type = 'expense' AND reference_id = ? AND movement_type = 'expense_payment'\n                        ORDER BY id DESC LIMIT 1 FOR UPDATE\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ii', $shop_id, $expense_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $txn = $res ? $res->fetch_assoc() : null;

                    if ($txn) {
                        $from_asset_id = (int)($txn['from_asset_id'] ?? 0);
                        $txn_amount = round((float)($txn['amount'] ?? 0), 2);
                        if ($from_asset_id > 0 && $txn_amount > 0) {
                            $stmt = $conn->prepare("SELECT id, amount FROM assets WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE");
                            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                            $stmt->bind_param('ii', $from_asset_id, $shop_id);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $asset = $res ? $res->fetch_assoc() : null;
                            if ($asset) {
                                $restored = round((float)$asset['amount'] + $txn_amount, 2);
                                $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                                $stmt->bind_param('dii', $restored, $from_asset_id, $shop_id);
                                $stmt->execute();
                            }
                        }
                        $txn_id = (int)$txn['id'];
                        $stmt = $conn->prepare("DELETE FROM asset_transactions WHERE id = ? AND shop_id = ? LIMIT 1");
                        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                        $stmt->bind_param('ii', $txn_id, $shop_id);
                        $stmt->execute();
                    }
                }

                $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND shop_id = ? LIMIT 1");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ii', $expense_id, $shop_id);
                $stmt->execute();

                $conn->commit();
                setFlash('success', 'Expense deleted successfully. Linked asset balance restored if applicable.');
                redirectToSelf();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        setFlash('error', 'Invalid action.');
        redirectToSelf();
    } catch (Throwable $e) {
        error_log('expense_management error: ' . $e->getMessage());
        setFlash('error', $e->getMessage());
        redirectToSelf();
    }
}

// ============================================================
// LOAD PAGE DATA
// ============================================================
$flash = getFlash();

$limit = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$start = ($page - 1) * $limit;

$search = cleanText($_GET['search'] ?? '', 100);
$filter_category = cleanText($_GET['filter_category'] ?? '', 100);
$filter_from = cleanText($_GET['filter_from'] ?? '', 10);
$filter_to = cleanText($_GET['filter_to'] ?? '', 10);
$filter_status = cleanText($_GET['filter_status'] ?? '', 20);
$filter_method = cleanText($_GET['filter_method'] ?? '', 30);

$whereParts = ["e.shop_id = ?"];
$types = 'i';
$params = [$shop_id];

if ($search !== '') {
    $whereParts[] = "(e.description LIKE ? OR e.reference_no LIKE ? OR e.category LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filter_category !== '') {
    $whereParts[] = "e.category = ?";
    $types .= 's';
    $params[] = $filter_category;
}
if ($filter_from !== '') {
    $whereParts[] = "e.expense_date >= ?";
    $types .= 's';
    $params[] = $filter_from;
}
if ($filter_to !== '') {
    $whereParts[] = "e.expense_date <= ?";
    $types .= 's';
    $params[] = $filter_to;
}
if ($filter_status !== '' && in_array($filter_status, ['paid', 'due'], true)) {
    $whereParts[] = "e.status = ?";
    $types .= 's';
    $params[] = $filter_status;
}
if ($filter_method !== '' && in_array($filter_method, ['cash', 'bank', 'mobile_banking'], true)) {
    $whereParts[] = "e.payment_method = ?";
    $types .= 's';
    $params[] = $filter_method;
}

$whereSql = implode(' AND ', $whereParts);

$total_records = 0;
$total_pages = 1;
$total_amount = 0;
$total_paid_amount = 0;
$total_due_amount = 0;
$expenses = [];
$categories = [];
$payment_assets = [];
$all_payment_assets = [];
$total_asset_expense_payments = 0;

if ($expenses_table_ready) {
    $summaryRows = fetchRows(
        $conn,
        "SELECT COUNT(*) AS total_records,
                COALESCE(SUM(e.amount),0) AS total_amount,
                COALESCE(SUM(
                    CASE
                        WHEN COALESCE(tx.paid_by_asset,0) > 0 THEN LEAST(e.amount, COALESCE(tx.paid_by_asset,0))
                        WHEN e.status = 'paid' THEN e.amount
                        ELSE 0
                    END
                ),0) AS paid_amount,
                COALESCE(SUM(
                    GREATEST(
                        e.amount - (CASE
                            WHEN COALESCE(tx.paid_by_asset,0) > 0 THEN LEAST(e.amount, COALESCE(tx.paid_by_asset,0))
                            WHEN e.status = 'paid' THEN e.amount
                            ELSE 0
                        END),
                        0
                    )
                ),0) AS due_amount
         FROM expenses e
         LEFT JOIN (
            SELECT shop_id, reference_id, COALESCE(SUM(amount),0) AS paid_by_asset
            FROM asset_transactions
            WHERE reference_type = 'expense'
              AND movement_type = 'expense_payment'
            GROUP BY shop_id, reference_id
         ) tx ON tx.shop_id = e.shop_id AND tx.reference_id = e.id
         WHERE $whereSql",
        $types,
        $params
    );
    $summary = $summaryRows[0] ?? [];
    $total_records = (int)($summary['total_records'] ?? 0);
    $total_amount = (float)($summary['total_amount'] ?? 0);
    $total_paid_amount = (float)($summary['paid_amount'] ?? 0);
    $total_due_amount = (float)($summary['due_amount'] ?? 0);
    $total_pages = max(1, (int)ceil($total_records / $limit));

    $fetchTypes = $types . 'ii';
    $fetchParams = array_merge($params, [$limit, $start]);
    $expenses = fetchRows(
        $conn,
        "SELECT e.*,
                COALESCE(tx.paid_by_asset,0) AS paid_by_asset,
                at.id AS asset_txn_id,
                at.from_asset_id AS paid_from_asset_id,
                at.from_asset_type AS paid_from_asset_type,
                at.from_asset_name AS paid_from_asset_name,
                at.from_balance_before,
                at.from_balance_after
         FROM expenses e
         LEFT JOIN (
            SELECT shop_id, reference_id, COALESCE(SUM(amount),0) AS paid_by_asset
            FROM asset_transactions
            WHERE reference_type = 'expense'
              AND movement_type = 'expense_payment'
            GROUP BY shop_id, reference_id
         ) tx ON tx.shop_id = e.shop_id AND tx.reference_id = e.id
         LEFT JOIN asset_transactions at
           ON at.id = (
                SELECT at2.id
                FROM asset_transactions at2
                WHERE at2.shop_id = e.shop_id
                  AND at2.reference_type = 'expense'
                  AND at2.reference_id = e.id
                  AND at2.movement_type = 'expense_payment'
                ORDER BY at2.id DESC
                LIMIT 1
           )
         WHERE $whereSql
         ORDER BY e.expense_date DESC, e.id DESC
         LIMIT ? OFFSET ?",
        $fetchTypes,
        $fetchParams
    );

    $categories = fetchRows(
        $conn,
        "SELECT DISTINCT category FROM expenses WHERE shop_id = ? AND category <> '' ORDER BY category ASC",
        'i',
        [$shop_id]
    );
}

if ($assets_table_ready) {
    $payment_assets = fetchRows(
        $conn,
        "SELECT id, asset_type, asset_name, amount
         FROM assets
         WHERE shop_id = ?
           AND status = 'active'
           AND amount > 0
           AND asset_type IN ('cash','bkash','nagad','bank','other')
         ORDER BY FIELD(asset_type, 'cash','bkash','nagad','bank','other'), asset_name ASC",
        'i',
        [$shop_id]
    );

    $all_payment_assets = fetchRows(
        $conn,
        "SELECT id, asset_type, asset_name, amount
         FROM assets
         WHERE shop_id = ?
           AND status = 'active'
           AND asset_type IN ('cash','bkash','nagad','bank','other')
         ORDER BY FIELD(asset_type, 'cash','bkash','nagad','bank','other'), asset_name ASC",
        'i',
        [$shop_id]
    );
}

if ($asset_transactions_table_ready) {
    $total_asset_expense_payments = (float)scalarQuery(
        $conn,
        "SELECT COALESCE(SUM(amount),0) FROM asset_transactions WHERE shop_id = ? AND movement_type = 'expense_payment'",
        'i',
        [$shop_id]
    );
}

$methodOptions = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking'];

include "header.php";
include "sidebar.php";
?>

<style>
.main{flex:1;padding:24px;background:#f8fafc;min-height:100vh;font-family:'Segoe UI',system-ui,sans-serif;}
.v3-header{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:22px 26px;margin-bottom:18px;box-shadow:0 2px 12px rgba(15,23,42,.06)}
.v3-header h3{margin:0;font-weight:800;color:#0f172a}.v3-header p{margin:5px 0 0;color:#64748b;font-size:.88rem}.v3-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.v3-btn{border:0;border-radius:12px;padding:10px 15px;font-size:.86rem;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:.2s}.v3-btn:hover{transform:translateY(-1px)}.v3-btn-primary{background:#2563eb;color:#fff}.v3-btn-success{background:#059669;color:#fff}.v3-btn-warning{background:#f59e0b;color:#fff}.v3-btn-danger{background:#dc2626;color:#fff}.v3-btn-light{background:#f1f5f9;color:#334155}.v3-btn-sm{padding:7px 10px;font-size:.76rem;border-radius:10px}.v3-btn:disabled{opacity:.55;cursor:not-allowed;transform:none}.v3-alert{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:.9rem;border:1px solid transparent}.v3-alert-success{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.v3-alert-error{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.v3-warning-box{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:.9rem}.v3-summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.v3-summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 1px 8px rgba(15,23,42,.05);position:relative;overflow:hidden}.v3-summary-card:before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:#cbd5e1}.v3-card-total:before{background:#ef4444}.v3-card-paid:before{background:#10b981}.v3-card-due:before{background:#f59e0b}.v3-card-record:before{background:#6366f1}.v3-summary-card small{font-weight:800;color:#64748b;text-transform:uppercase;font-size:.72rem;letter-spacing:.6px}.v3-summary-card h4{font-size:1.55rem;margin:8px 0 0;color:#0f172a;font-weight:900}.card-box{background:#fff;padding:18px;border-radius:16px;box-shadow:0 1px 8px rgba(15,23,42,.05);border:1px solid #e2e8f0;margin-bottom:16px}.v3-filter-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}.form-control,.form-select{border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;font-size:.9rem}.form-control:focus,.form-select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);outline:none}.v3-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:14px}.v3-table{width:100%;border-collapse:collapse;min-width:1100px;background:#fff}.v3-table th,.v3-table td{padding:12px 13px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:middle;font-size:.86rem}.v3-table th{background:#f8fafc;color:#334155;font-weight:900;white-space:nowrap}.v3-table tr:last-child td{border-bottom:0}.badge-id{background:#0f172a;color:#fff;padding:4px 8px;border-radius:999px;font-size:.72rem}.v3-money{font-weight:900;color:#0f172a;font-family:'Courier New',monospace;white-space:nowrap}.v3-inline-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.v3-muted{color:#94a3b8;font-size:.78rem}.view-detail-row{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e2e8f0}.view-detail-label{font-weight:800;color:#64748b}.view-detail-value{font-weight:700;color:#0f172a;text-align:right}.pagination{margin-bottom:0;display:flex;gap:5px;flex-wrap:wrap}.page-link{border-radius:10px!important;color:#334155}.page-item.active .page-link{background:#2563eb;border-color:#2563eb}.v3-empty{text-align:center;padding:28px;color:#64748b}
@media(max-width:1200px){.v3-summary-row{grid-template-columns:repeat(2,1fr)}.v3-filter-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:767px){.main{padding:14px}.v3-header{padding:16px}.v3-summary-row,.v3-filter-grid{grid-template-columns:1fr}.v3-btn{width:100%;justify-content:center}.v3-toolbar{width:100%}}
</style>

<div class="main">
    <div class="v3-header">
        <div>
            <h3><i class="fas fa-receipt text-danger"></i> Expense Management</h3>
            <p>Modal CRUD · Asset balance linked · Shop ID: <?= (int)$shop_id ?></p>
        </div>
        <div class="v3-toolbar">
            <button class="v3-btn v3-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addExpenseModal"><i class="fas fa-plus"></i> Add Expense</button>
            <a class="v3-btn v3-btn-light" href="asset_transaction_list.php?movement_type=expense_payment"><i class="fas fa-clock-rotate-left"></i> Expense Payment History</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="v3-alert <?= ($flash['type'] ?? '') === 'success' ? 'v3-alert-success' : 'v3-alert-error' ?>"><?= h($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <?php if (!$expenses_table_ready): ?>
        <div class="v3-warning-box"><strong>Table missing:</strong> `expenses` table পাওয়া যায়নি।</div>
    <?php endif; ?>
    <?php if ($expenses_table_ready && (!$assets_table_ready || !$asset_transactions_table_ready)): ?>
        <div class="v3-warning-box"><strong>Asset link warning:</strong> `assets` অথবা `asset_transactions` table পাওয়া যায়নি। Paid expense save/pay করলে asset balance update হবে না।</div>
    <?php endif; ?>

    <div class="v3-summary-row">
        <div class="v3-summary-card v3-card-total"><small>Total Expenses</small><h4><?= taka($total_amount) ?></h4></div>
        <div class="v3-summary-card v3-card-paid"><small>Paid Expenses</small><h4><?= taka($total_paid_amount) ?></h4></div>
        <div class="v3-summary-card v3-card-due"><small>Due Expenses</small><h4><?= taka($total_due_amount) ?></h4></div>
        <div class="v3-summary-card v3-card-record"><small>Records</small><h4><?= number_format($total_records) ?></h4></div>
    </div>

    <div class="card-box">
        <form method="GET">
            <div class="v3-filter-grid">
                <input type="text" name="search" value="<?= h($search) ?>" class="form-control" placeholder="Search category/description/ref">
                <select name="filter_category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= h($c['category']) ?>" <?= $filter_category === ($c['category'] ?? '') ? 'selected' : '' ?>><?= h($c['category']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_status" class="form-select">
                    <option value="">All Status</option>
                    <option value="paid" <?= $filter_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="due" <?= $filter_status === 'due' ? 'selected' : '' ?>>Due</option>
                </select>
                <select name="filter_method" class="form-select">
                    <option value="">All Method</option>
                    <?php foreach ($methodOptions as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $filter_method === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="filter_from" value="<?= h($filter_from) ?>" class="form-control">
                <input type="date" name="filter_to" value="<?= h($filter_to) ?>" class="form-control">
            </div>
            <div class="v3-toolbar" style="margin-top:12px;">
                <button class="v3-btn v3-btn-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="expense_management.php" class="v3-btn v3-btn-light"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="card-box">
        <div class="v3-table-wrap">
            <table class="v3-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Method</th>
                        <th>Paid From</th>
                        <th>Reference</th>
                        <th width="260">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($expenses)): ?>
                    <?php foreach ($expenses as $e):
                        $expenseId = (int)($e['id'] ?? 0);
                        $status = (string)($e['status'] ?? 'paid');
                        $hasTxn = !empty($e['asset_txn_id']);
                        $expenseAmount = round((float)($e['amount'] ?? 0), 2);
                        $paidByAsset = round((float)($e['paid_by_asset'] ?? 0), 2);
                        $paidDisplay = $paidByAsset > 0 ? min($expenseAmount, $paidByAsset) : ($status === 'paid' ? $expenseAmount : 0);
                        $dueDisplay = round(max(0, $expenseAmount - $paidDisplay), 2);
                        $displayStatus = $dueDisplay <= 0 ? 'paid' : 'due';
                    ?>
                    <tr>
                        <td><span class="badge-id">#<?= $expenseId ?></span></td>
                        <td><?= h(date('d M Y', strtotime($e['expense_date'] ?? date('Y-m-d')))) ?></td>
                        <td><span class="badge bg-primary"><?= h($e['category'] ?? '') ?></span></td>
                        <td><?= h($e['description'] ?? '') ?></td>
                        <td class="v3-money text-danger">
                            <?= taka($expenseAmount) ?>
                            <div class="v3-muted">Paid: <?= taka($paidDisplay) ?> · Due: <?= taka($dueDisplay) ?></div>
                        </td>
                        <td><span class="badge <?= h(expenseStatusBadge($displayStatus)) ?>"><?= h(ucfirst($displayStatus)) ?></span></td>
                        <td><span class="badge <?= h(methodBadgeClass($e['payment_method'] ?? 'cash')) ?>"><?= h(methodLabel($e['payment_method'] ?? 'cash')) ?></span></td>
                        <td>
                            <?php if ($hasTxn): ?>
                                <strong><?= h($e['paid_from_asset_name'] ?? '') ?></strong>
                                <div class="v3-muted"><?= h(typeLabel($e['paid_from_asset_type'] ?? '', $payment_asset_labels)) ?></div>
                            <?php else: ?>
                                <span class="v3-muted">No linked asset txn</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($e['reference_no'] ?? '-') ?></td>
                        <td>
                            <div class="v3-inline-actions">
                                <button type="button" class="v3-btn v3-btn-primary v3-btn-sm" data-bs-toggle="modal" data-bs-target="#viewExpenseModal<?= $expenseId ?>"><i class="fas fa-eye"></i> View</button>
                                <button type="button" class="v3-btn v3-btn-warning v3-btn-sm" data-bs-toggle="modal" data-bs-target="#editExpenseModal<?= $expenseId ?>"><i class="fas fa-edit"></i> Edit</button>
                                <?php if ($dueDisplay > 0): ?>
                                    <button type="button" class="v3-btn v3-btn-success v3-btn-sm" data-bs-toggle="modal" data-bs-target="#payExpenseModal<?= $expenseId ?>" <?= empty($payment_assets) ? 'disabled' : '' ?>><i class="fas fa-money-bill-transfer"></i> Pay</button>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('এই expense delete করতে চাও? Linked asset transaction থাকলে balance reverse হবে।');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                                    <button class="v3-btn v3-btn-danger v3-btn-sm" type="submit"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <!-- VIEW MODAL -->
                    <div class="modal fade" id="viewExpenseModal<?= $expenseId ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-eye"></i> Expense Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <div class="view-detail-row"><span class="view-detail-label">Expense ID</span><span class="view-detail-value"><span class="badge-id">#<?= $expenseId ?></span></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Date</span><span class="view-detail-value"><?= h(date('d M Y', strtotime($e['expense_date'] ?? date('Y-m-d')))) ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Category</span><span class="view-detail-value"><?= h($e['category'] ?? '') ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Description</span><span class="view-detail-value"><?= h($e['description'] ?? '') ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Amount</span><span class="view-detail-value text-danger"><?= taka($expenseAmount) ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Paid</span><span class="view-detail-value text-success"><?= taka($paidDisplay) ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Due</span><span class="view-detail-value text-danger"><?= taka($dueDisplay) ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Status</span><span class="view-detail-value"><?= h(ucfirst($displayStatus)) ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Payment Method</span><span class="view-detail-value"><?= h(methodLabel($e['payment_method'] ?? 'cash')) ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Paid From</span><span class="view-detail-value"><?= $hasTxn ? h(($e['paid_from_asset_name'] ?? '') . ' (' . typeLabel($e['paid_from_asset_type'] ?? '', $payment_asset_labels) . ')') : 'N/A' ?></span></div>
                                    <div class="view-detail-row"><span class="view-detail-label">Reference</span><span class="view-detail-value"><?= h($e['reference_no'] ?? 'N/A') ?></span></div>
                                    <div class="view-detail-row" style="border-bottom:0;"><span class="view-detail-label">Created</span><span class="view-detail-value"><?= h(!empty($e['created_at']) ? date('d M Y h:i A', strtotime($e['created_at'])) : '-') ?></span></div>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
                            </div>
                        </div>
                    </div>

                    <!-- EDIT MODAL -->
                    <div class="modal fade" id="editExpenseModal<?= $expenseId ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update_expense">
                                    <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-edit"></i> Edit Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="<?= h($e['expense_date'] ?? date('Y-m-d')) ?>" required></div>
                                            <div class="col-md-3"><label class="form-label">Category</label><input type="text" name="category" class="form-control" value="<?= h($e['category'] ?? '') ?>" list="categoryList" required></div>
                                            <div class="col-md-3"><label class="form-label">Amount</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="<?= h($e['amount'] ?? '0.00') ?>" required></div>
                                            <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option><option value="due" <?= $status === 'due' ? 'selected' : '' ?>>Due</option></select></div>
                                            <div class="col-md-8"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= h($e['description'] ?? '') ?>" required></div>
                                            <div class="col-md-4"><label class="form-label">Reference No</label><input type="text" name="reference_no" class="form-control" value="<?= h($e['reference_no'] ?? '') ?>"></div>
                                            <div class="col-md-12">
                                                <label class="form-label">Pay From Asset <?= $hasTxn ? '<span class="text-danger">(linked transaction will adjust)</span>' : '<span class="text-muted">(optional for legacy records)</span>' ?></label>
                                                <select name="pay_from_asset_id" class="form-select">
                                                    <option value="">Select asset</option>
                                                    <?php foreach ($all_payment_assets as $asset): ?>
                                                        <option value="<?= (int)$asset['id'] ?>" <?= ((int)($e['paid_from_asset_id'] ?? 0) === (int)$asset['id']) ? 'selected' : '' ?>><?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (!$hasTxn): ?>
                                                    <div class="form-check mt-2">
                                                        <input class="form-check-input" type="checkbox" name="create_asset_txn" value="1" id="createTxn<?= $expenseId ?>">
                                                        <label class="form-check-label" for="createTxn<?= $expenseId ?>">Create asset transaction and deduct selected asset now</label>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Expense</button></div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- PAY MODAL -->
                    <?php if ($dueDisplay > 0): ?>
                    <div class="modal fade" id="payExpenseModal<?= $expenseId ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="pay_due_expense">
                                    <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                                    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-money-bill-transfer"></i> Pay Due Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body">
                                        <div class="alert alert-light border"><strong><?= h($e['description'] ?? '') ?></strong><br>Total: <span class="fw-bold"><?= taka($expenseAmount) ?></span> · Paid: <span class="text-success fw-bold"><?= taka($paidDisplay) ?></span> · Due: <span class="text-danger fw-bold"><?= taka($dueDisplay) ?></span></div>
                                        <div class="mb-3"><label class="form-label">Pay From Asset</label><select name="pay_from_asset_id" class="form-select" required><option value="">Select source</option><?php foreach ($payment_assets as $asset): ?><option value="<?= (int)$asset['id'] ?>"><?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?></option><?php endforeach; ?></select></div>
                                        <div class="mb-3"><label class="form-label">Payment Amount</label><input type="number" name="paid_amount" class="form-control" step="0.01" min="0.01" max="<?= h((string)$dueDisplay) ?>" value="<?= h((string)$dueDisplay) ?>" required></div>
                                        <div class="mb-3"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required></div>
                                        <div class="mb-3"><label class="form-label">Note</label><textarea name="payment_note" class="form-control" rows="3" placeholder="Optional note"></textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Payment</button></div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10"><div class="v3-empty">No expense records found.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-box">
        <nav><ul class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++):
                $query = $_GET;
                $query['page'] = $i;
            ?>
                <li class="page-item <?= $page === $i ? 'active' : '' ?>"><a class="page-link" href="?<?= h(http_build_query($query)) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<datalist id="categoryList">
    <?php foreach ($categories as $c): ?>
        <option value="<?= h($c['category']) ?>">
    <?php endforeach; ?>
</datalist>

<!-- ADD MODAL -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_expense">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-plus"></i> Add Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="<?= h(date('Y-m-d')) ?>" required></div>
                        <div class="col-md-3"><label class="form-label">Category</label><input type="text" name="category" class="form-control" placeholder="Rent, Salary, Transport" list="categoryList" required></div>
                        <div class="col-md-3"><label class="form-label">Amount</label><input type="number" name="amount" step="0.01" min="0.01" class="form-control" placeholder="0.00" required></div>
                        <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select" id="addExpenseStatus"><option value="paid">Paid Now</option><option value="due">Due / Pay Later</option></select></div>
                        <div class="col-md-8"><label class="form-label">Description</label><input type="text" name="description" class="form-control" placeholder="Expense details" required></div>
                        <div class="col-md-4"><label class="form-label">Reference No</label><input type="text" name="reference_no" class="form-control" placeholder="Bill/Invoice no"></div>
                        <div class="col-md-12" id="addPayAssetWrap">
                            <label class="form-label">Pay From Asset</label>
                            <select name="pay_from_asset_id" class="form-select" <?= empty($payment_assets) ? 'disabled' : '' ?>>
                                <option value="">Select source</option>
                                <?php foreach ($payment_assets as $asset): ?>
                                    <option value="<?= (int)$asset['id'] ?>"><?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($payment_assets)): ?><div class="text-danger small mt-1">No active asset balance found. First add Cash/bKash/Nagad/Bank in Asset Management.</div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Expense</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const status = document.getElementById('addExpenseStatus');
    const wrap = document.getElementById('addPayAssetWrap');
    function toggleAddPaymentAsset() {
        if (!status || !wrap) return;
        wrap.style.display = status.value === 'paid' ? '' : 'none';
    }
    if (status) {
        status.addEventListener('change', toggleAddPaymentAsset);
        toggleAddPaymentAsset();
    }
});
</script>

<?php include "footer.php"; ?>
