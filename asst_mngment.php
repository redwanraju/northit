<?php
// ============================================================
// Asset & Liability Management
// File: asst_mngment.php
// Purpose: Manual CRUD for Assets and Liabilities
// Safety: Login required, shop_id scoped, CSRF protected,
//         prepared statements, validation, soft delete.
// ============================================================

include 'auth_helper.php';
requireLogin();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$shop_id = tenantShopId();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));
$user_name = $_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'Admin');

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

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $conn->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n        LIMIT 1\n    ");
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
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n          AND COLUMN_NAME = ?\n        LIMIT 1\n    ");
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

function redirectToSelf($params = []) {
    $base = basename($_SERVER['PHP_SELF']);
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    header('Location: ' . $base);
    exit;
}

function setFlash($type, $message) {
    $_SESSION['asst_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash() {
    $flash = $_SESSION['asst_flash'] ?? null;
    unset($_SESSION['asst_flash']);
    return $flash;
}

function csrfToken() {
    if (empty($_SESSION['asst_csrf_token'])) {
        $_SESSION['asst_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['asst_csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function csrfIsValid() {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['asst_csrf_token'])
        && hash_equals($_SESSION['asst_csrf_token'], $token);
}

function assetTypes() {
    return [
        'cash' => 'নগদ / Cash in Hand',
        'bkash' => 'বিকাশ / bKash',
        'nagad' => 'নগদ মোবাইল ব্যাংকিং / Nagad',
        'bank' => 'ব্যাংকে জমা / Bank Deposit',
        'stock' => 'Item Stock Value',
        'customer_due' => 'Receivable Customer Due',
        'other' => 'Other Asset'
    ];
}

function liabilityTypes() {
    return [
        'supplier_due' => 'Supplier Due',
        'bank_loan' => 'Bank Loan',
        'credit_card_loan' => 'Credit Card Loan',
        'investor_loan' => 'Investor Loan / Payable',
        'expense_due' => 'Expense Due',
        'other' => 'Other Liability'
    ];
}

function assetStatuses() {
    return [
        'active' => 'Active',
        'inactive' => 'Inactive'
    ];
}

function liabilityStatuses() {
    return [
        'active' => 'Active',
        'paid' => 'Paid',
        'inactive' => 'Inactive'
    ];
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


function assetMovementTypes() {
    return [
        'purchase_payment' => 'Purchase Payment',
        'expense_payment' => 'Expense Payment',
        'liability_payment' => 'Liability Payment',
        'bank_to_cash' => 'Bank to Cash Transfer',
        'cash_to_bank' => 'Cash to Bank Deposit',
        'transfer' => 'Asset Transfer',
        'manual_adjustment' => 'Manual Adjustment',
        'opening_balance' => 'Opening Balance',
        'other' => 'Other Movement'
    ];
}

function assetTransactionTypes() {
    return [
        'out' => 'Money Out',
        'in' => 'Money In',
        'transfer' => 'Transfer'
    ];
}

function statusBadgeClass($status) {
    if ($status === 'active') return 'v3-badge-green';
    if ($status === 'paid') return 'v3-badge-blue';
    return 'v3-badge-gray';
}

function typeLabel($type, $labels) {
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

// ============================================================
// REQUIRE TABLES
// ============================================================
$assets_table_ready = isset($conn) && $conn && tableExists($conn, 'assets');
$liabilities_table_ready = isset($conn) && $conn && tableExists($conn, 'liabilities');
$payments_table_ready = isset($conn) && $conn && tableExists($conn, 'liability_payments');
$asset_transactions_table_ready = isset($conn) && $conn && tableExists($conn, 'asset_transactions');
$system_ready = $assets_table_ready && $liabilities_table_ready;

// ============================================================
// CRUD ACTIONS
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
        // ---------------- ASSET SAVE ----------------
        if ($action === 'save_asset') {
            if (!$assets_table_ready) {
                setFlash('error', 'assets table পাওয়া যায়নি। আগে SQL রান করো।');
                redirectToSelf();
            }

            $asset_id = (int)postValue('asset_id', '0');
            $asset_type = postValue('asset_type', 'other');
            $asset_name = cleanText(postValue('asset_name'), 150);
            $amount_raw = postValue('amount', '0');
            $note = cleanText(postValue('note'), 2000);
            $status = postValue('status', 'active');

            $asset_type_labels = assetTypes();
            $status_labels = assetStatuses();

            if (!array_key_exists($asset_type, $asset_type_labels)) {
                throw new Exception('Invalid asset type.');
            }
            if (!array_key_exists($status, $status_labels)) {
                throw new Exception('Invalid asset status.');
            }
            if ($asset_name === '') {
                throw new Exception('Asset name is required.');
            }
            if (!is_numeric($amount_raw)) {
                throw new Exception('Amount must be numeric.');
            }

            $amount = round((float)$amount_raw, 2);
            if ($amount < 0) {
                throw new Exception('Amount cannot be negative.');
            }

            if ($asset_id > 0) {
                $stmt = $conn->prepare("\n                    UPDATE assets\n                    SET asset_type = ?, asset_name = ?, amount = ?, note = ?, status = ?\n                    WHERE id = ? AND shop_id = ?\n                    LIMIT 1\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ssdssii', $asset_type, $asset_name, $amount, $note, $status, $asset_id, $shop_id);
                $stmt->execute();
                setFlash('success', 'Asset updated successfully.');
            } else {
                $stmt = $conn->prepare("\n                    INSERT INTO assets\n                        (shop_id, asset_type, asset_name, amount, note, status, created_by)\n                    VALUES\n                        (?, ?, ?, ?, ?, ?, ?)\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('issdssi', $shop_id, $asset_type, $asset_name, $amount, $note, $status, $user_id);
                $stmt->execute();
                setFlash('success', 'Asset added successfully.');
            }

            redirectToSelf();
        }

        // ---------------- ASSET SOFT DELETE ----------------
        if ($action === 'delete_asset') {
            if (!$assets_table_ready) {
                setFlash('error', 'assets table পাওয়া যায়নি।');
                redirectToSelf();
            }

            $asset_id = (int)postValue('asset_id', '0');
            if ($asset_id <= 0) {
                throw new Exception('Invalid asset selected.');
            }

            $stmt = $conn->prepare("UPDATE assets SET status = 'inactive' WHERE id = ? AND shop_id = ? LIMIT 1");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('ii', $asset_id, $shop_id);
            $stmt->execute();

            setFlash('success', 'Asset inactive করা হয়েছে। Data delete করা হয়নি।');
            redirectToSelf();
        }

        // ---------------- LIABILITY SAVE ----------------
        if ($action === 'save_liability') {
            if (!$liabilities_table_ready) {
                setFlash('error', 'liabilities table পাওয়া যায়নি। আগে SQL রান করো।');
                redirectToSelf();
            }

            $liability_id = (int)postValue('liability_id', '0');
            $liability_type = postValue('liability_type', 'other');
            $liability_name = cleanText(postValue('liability_name'), 150);
            $amount_raw = postValue('amount', '0');
            $person_or_company = cleanText(postValue('person_or_company'), 150);
            $phone = cleanText(postValue('phone'), 30);
            $note = cleanText(postValue('note'), 2000);
            $status = postValue('status', 'active');

            $liability_type_labels = liabilityTypes();
            $status_labels = liabilityStatuses();

            if (!array_key_exists($liability_type, $liability_type_labels)) {
                throw new Exception('Invalid liability type.');
            }
            if (!array_key_exists($status, $status_labels)) {
                throw new Exception('Invalid liability status.');
            }
            if ($liability_name === '') {
                throw new Exception('Liability name is required.');
            }
            if (!is_numeric($amount_raw)) {
                throw new Exception('Amount must be numeric.');
            }

            $amount = round((float)$amount_raw, 2);
            if ($amount < 0) {
                throw new Exception('Amount cannot be negative.');
            }

            if ($liability_id > 0) {
                $stmt = $conn->prepare("\n                    UPDATE liabilities\n                    SET liability_type = ?, liability_name = ?, amount = ?, person_or_company = ?, phone = ?, note = ?, status = ?\n                    WHERE id = ? AND shop_id = ?\n                    LIMIT 1\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ssdssssii', $liability_type, $liability_name, $amount, $person_or_company, $phone, $note, $status, $liability_id, $shop_id);
                $stmt->execute();
                setFlash('success', 'Liability updated successfully.');
            } else {
                $stmt = $conn->prepare("\n                    INSERT INTO liabilities\n                        (shop_id, liability_type, liability_name, amount, person_or_company, phone, note, status, created_by)\n                    VALUES\n                        (?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('issdssssi', $shop_id, $liability_type, $liability_name, $amount, $person_or_company, $phone, $note, $status, $user_id);
                $stmt->execute();
                setFlash('success', 'Liability added successfully.');
            }

            redirectToSelf();
        }

        // ---------------- LIABILITY PAYMENT ----------------
        if ($action === 'pay_liability') {
            if (!$assets_table_ready || !$liabilities_table_ready || !$payments_table_ready) {
                setFlash('error', 'assets, liabilities অথবা liability_payments table পাওয়া যায়নি। আগে SQL রান করো।');
                redirectToSelf();
            }

            $liability_id = (int)postValue('liability_id', '0');
            $pay_from_asset_id = (int)postValue('pay_from_asset_id', '0');
            $paid_amount_raw = postValue('paid_amount', '0');
            $payment_date = postValue('payment_date', date('Y-m-d'));
            $payment_note = cleanText(postValue('payment_note'), 2000);

            if ($liability_id <= 0) {
                throw new Exception('Invalid liability selected.');
            }
            if ($pay_from_asset_id <= 0) {
                throw new Exception('Please select payment source asset.');
            }
            if (!is_numeric($paid_amount_raw)) {
                throw new Exception('Payment amount must be numeric.');
            }

            $paid_amount = round((float)$paid_amount_raw, 2);
            if ($paid_amount <= 0) {
                throw new Exception('Payment amount must be greater than zero.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
                throw new Exception('Invalid payment date.');
            }

            $allowed_payment_types = paymentAssetTypes();

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id, liability_type, liability_name, amount, status FROM liabilities WHERE id = ? AND shop_id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ii', $liability_id, $shop_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $liability = $res ? $res->fetch_assoc() : null;

                if (!$liability) {
                    throw new Exception('Active liability পাওয়া যায়নি।');
                }

                $current_liability_amount = round((float)($liability['amount'] ?? 0), 2);
                if ($current_liability_amount <= 0) {
                    throw new Exception('This liability has no payable balance.');
                }
                if ($paid_amount > $current_liability_amount) {
                    throw new Exception('Payment amount current liability amount থেকে বেশি হতে পারবে না।');
                }

                $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount, status FROM assets WHERE id = ? AND shop_id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('ii', $pay_from_asset_id, $shop_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $asset = $res ? $res->fetch_assoc() : null;

                if (!$asset) {
                    throw new Exception('Active payment source asset পাওয়া যায়নি।');
                }

                $asset_type = (string)($asset['asset_type'] ?? 'other');
                if (!array_key_exists($asset_type, $allowed_payment_types)) {
                    throw new Exception('এই asset type থেকে liability payment allow করা হয়নি।');
                }

                $current_asset_amount = round((float)($asset['amount'] ?? 0), 2);
                if ($paid_amount > $current_asset_amount) {
                    throw new Exception('Selected asset balance কম। Available: ' . taka($current_asset_amount));
                }

                $new_asset_amount = round($current_asset_amount - $paid_amount, 2);
                $new_liability_amount = round($current_liability_amount - $paid_amount, 2);
                $new_liability_status = $new_liability_amount <= 0 ? 'paid' : 'active';

                $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('dii', $new_asset_amount, $pay_from_asset_id, $shop_id);
                $stmt->execute();

                $stmt = $conn->prepare("UPDATE liabilities SET amount = ?, status = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('dsii', $new_liability_amount, $new_liability_status, $liability_id, $shop_id);
                $stmt->execute();

                $liability_type = (string)$liability['liability_type'];
                $liability_name = (string)$liability['liability_name'];

                $stmt = $conn->prepare("\n                    INSERT INTO liability_payments\n                        (shop_id, liability_id, liability_type, liability_name, paid_amount, paid_from_asset_type, payment_date, note, created_by)\n                    VALUES\n                        (?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('iissdsssi', $shop_id, $liability_id, $liability_type, $liability_name, $paid_amount, $asset_type, $payment_date, $payment_note, $user_id);
                $stmt->execute();

                // Also save cash/bKash/Nagad/Bank movement history if asset_transactions table exists.
                if ($asset_transactions_table_ready) {
                    $from_asset_name = (string)($asset['asset_name'] ?? '');
                    $reference_id = $liability_id;
                    $movement_note = $payment_note !== '' ? $payment_note : ('Liability payment: ' . $liability_name);

                    $stmt = $conn->prepare("\n                        INSERT INTO asset_transactions\n                            (shop_id, transaction_type, movement_type,\n                             from_asset_id, from_asset_type, from_asset_name,\n                             to_asset_id, to_asset_type, to_asset_name,\n                             amount, from_balance_before, from_balance_after,\n                             to_balance_before, to_balance_after,\n                             reference_type, reference_id, transaction_date, note, created_by)\n                        VALUES\n                            (?, 'out', 'liability_payment',\n                             ?, ?, ?,\n                             NULL, NULL, NULL,\n                             ?, ?, ?,\n                             NULL, NULL,\n                             'liability', ?, ?, ?, ?)\n                    " );
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param(
                        'iissdddissi',
                        $shop_id,
                        $pay_from_asset_id,
                        $asset_type,
                        $from_asset_name,
                        $paid_amount,
                        $current_asset_amount,
                        $new_asset_amount,
                        $reference_id,
                        $payment_date,
                        $movement_note,
                        $user_id
                    );
                    $stmt->execute();
                }

                $conn->commit();
                setFlash('success', 'Payment saved. Asset balance কমেছে এবং liability update হয়েছে।');
                redirectToSelf();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        // ---------------- ASSET MOVEMENT / TRANSACTION ----------------
        if ($action === 'save_asset_movement') {
            if (!$assets_table_ready || !$asset_transactions_table_ready) {
                setFlash('error', 'assets অথবা asset_transactions table পাওয়া যায়নি। আগে SQL রান করো।');
                redirectToSelf();
            }

            $transaction_type = postValue('transaction_type', 'out');
            $movement_type = postValue('movement_type', 'other');
            $from_asset_id = (int)postValue('from_asset_id', '0');
            $to_asset_id = (int)postValue('to_asset_id', '0');
            $amount_raw = postValue('movement_amount', '0');
            $transaction_date = postValue('transaction_date', date('Y-m-d'));
            $note = cleanText(postValue('movement_note'), 2000);

            $transaction_type_labels = assetTransactionTypes();
            $movement_type_labels = assetMovementTypes();

            if (!array_key_exists($transaction_type, $transaction_type_labels)) {
                throw new Exception('Invalid transaction type.');
            }
            if (!array_key_exists($movement_type, $movement_type_labels)) {
                throw new Exception('Invalid movement type.');
            }
            if (!is_numeric($amount_raw)) {
                throw new Exception('Amount must be numeric.');
            }
            $amount = round((float)$amount_raw, 2);
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than zero.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_date)) {
                throw new Exception('Invalid transaction date.');
            }

            if ($transaction_type === 'out' && $from_asset_id <= 0) {
                throw new Exception('Money Out এর জন্য From Asset select করতে হবে।');
            }
            if ($transaction_type === 'in' && $to_asset_id <= 0) {
                throw new Exception('Money In এর জন্য To Asset select করতে হবে।');
            }
            if ($transaction_type === 'transfer') {
                if ($from_asset_id <= 0 || $to_asset_id <= 0) {
                    throw new Exception('Transfer এর জন্য From Asset এবং To Asset দুটোই select করতে হবে।');
                }
                if ($from_asset_id === $to_asset_id) {
                    throw new Exception('From Asset এবং To Asset একই হতে পারবে না।');
                }
            }

            $conn->begin_transaction();
            try {
                $from_asset = null;
                $to_asset = null;
                $from_before = null;
                $from_after = null;
                $to_before = null;
                $to_after = null;
                $from_type = null;
                $from_name = null;
                $to_type = null;
                $to_name = null;

                if (in_array($transaction_type, ['out', 'transfer'], true)) {
                    $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount, status FROM assets WHERE id = ? AND shop_id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ii', $from_asset_id, $shop_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $from_asset = $res ? $res->fetch_assoc() : null;
                    if (!$from_asset) throw new Exception('From Asset active অবস্থায় পাওয়া যায়নি।');

                    $from_before = round((float)($from_asset['amount'] ?? 0), 2);
                    if ($amount > $from_before) {
                        throw new Exception('From Asset balance কম। Available: ' . taka($from_before));
                    }
                    $from_after = round($from_before - $amount, 2);
                    $from_type = (string)($from_asset['asset_type'] ?? 'other');
                    $from_name = (string)($from_asset['asset_name'] ?? '');
                }

                if (in_array($transaction_type, ['in', 'transfer'], true)) {
                    $stmt = $conn->prepare("SELECT id, asset_type, asset_name, amount, status FROM assets WHERE id = ? AND shop_id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ii', $to_asset_id, $shop_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $to_asset = $res ? $res->fetch_assoc() : null;
                    if (!$to_asset) throw new Exception('To Asset active অবস্থায় পাওয়া যায়নি।');

                    $to_before = round((float)($to_asset['amount'] ?? 0), 2);
                    $to_after = round($to_before + $amount, 2);
                    $to_type = (string)($to_asset['asset_type'] ?? 'other');
                    $to_name = (string)($to_asset['asset_name'] ?? '');
                }

                if ($from_asset) {
                    $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('dii', $from_after, $from_asset_id, $shop_id);
                    $stmt->execute();
                }

                if ($to_asset) {
                    $stmt = $conn->prepare("UPDATE assets SET amount = ? WHERE id = ? AND shop_id = ? LIMIT 1");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('dii', $to_after, $to_asset_id, $shop_id);
                    $stmt->execute();
                }

                $reference_type = 'manual_asset_movement';
                $reference_id = null;

                if ($transaction_type === 'out') {
                    $stmt = $conn->prepare("\n                        INSERT INTO asset_transactions\n                            (shop_id, transaction_type, movement_type,\n                             from_asset_id, from_asset_type, from_asset_name,\n                             to_asset_id, to_asset_type, to_asset_name,\n                             amount, from_balance_before, from_balance_after,\n                             to_balance_before, to_balance_after,\n                             reference_type, reference_id, transaction_date, note, created_by)\n                        VALUES\n                            (?, ?, ?,\n                             ?, ?, ?,\n                             NULL, NULL, NULL,\n                             ?, ?, ?,\n                             NULL, NULL,\n                             ?, NULL, ?, ?, ?)\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ississdddsssi', $shop_id, $transaction_type, $movement_type, $from_asset_id, $from_type, $from_name, $amount, $from_before, $from_after, $reference_type, $transaction_date, $note, $user_id);
                    $stmt->execute();
                } elseif ($transaction_type === 'in') {
                    $stmt = $conn->prepare("\n                        INSERT INTO asset_transactions\n                            (shop_id, transaction_type, movement_type,\n                             from_asset_id, from_asset_type, from_asset_name,\n                             to_asset_id, to_asset_type, to_asset_name,\n                             amount, from_balance_before, from_balance_after,\n                             to_balance_before, to_balance_after,\n                             reference_type, reference_id, transaction_date, note, created_by)\n                        VALUES\n                            (?, ?, ?,\n                             NULL, NULL, NULL,\n                             ?, ?, ?,\n                             ?, NULL, NULL,\n                             ?, ?,\n                             ?, NULL, ?, ?, ?)\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ississdddsssi', $shop_id, $transaction_type, $movement_type, $to_asset_id, $to_type, $to_name, $amount, $to_before, $to_after, $reference_type, $transaction_date, $note, $user_id);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("\n                        INSERT INTO asset_transactions\n                            (shop_id, transaction_type, movement_type,\n                             from_asset_id, from_asset_type, from_asset_name,\n                             to_asset_id, to_asset_type, to_asset_name,\n                             amount, from_balance_before, from_balance_after,\n                             to_balance_before, to_balance_after,\n                             reference_type, reference_id, transaction_date, note, created_by)\n                        VALUES\n                            (?, ?, ?,\n                             ?, ?, ?,\n                             ?, ?, ?,\n                             ?, ?, ?,\n                             ?, ?,\n                             ?, NULL, ?, ?, ?)\n                    ");
                    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('issississdddddsssi', $shop_id, $transaction_type, $movement_type, $from_asset_id, $from_type, $from_name, $to_asset_id, $to_type, $to_name, $amount, $from_before, $from_after, $to_before, $to_after, $reference_type, $transaction_date, $note, $user_id);
                    $stmt->execute();
                }

                $conn->commit();
                setFlash('success', 'Asset movement saved successfully. Balance updated and history recorded.');
                redirectToSelf();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        // ---------------- LIABILITY SOFT DELETE ----------------
        if ($action === 'delete_liability') {
            if (!$liabilities_table_ready) {
                setFlash('error', 'liabilities table পাওয়া যায়নি।');
                redirectToSelf();
            }

            $liability_id = (int)postValue('liability_id', '0');
            if ($liability_id <= 0) {
                throw new Exception('Invalid liability selected.');
            }

            $stmt = $conn->prepare("UPDATE liabilities SET status = 'inactive' WHERE id = ? AND shop_id = ? LIMIT 1");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('ii', $liability_id, $shop_id);
            $stmt->execute();

            setFlash('success', 'Liability inactive করা হয়েছে। Data delete করা হয়নি।');
            redirectToSelf();
        }

        setFlash('error', 'Invalid action.');
        redirectToSelf();
    } catch (Throwable $e) {
        error_log('asst_mngment error: ' . $e->getMessage());
        setFlash('error', $e->getMessage());
        redirectToSelf();
    }
}

// ============================================================
// LOAD DATA
// ============================================================
$asset_labels = assetTypes();
$liability_labels = liabilityTypes();
$payment_asset_labels = paymentAssetTypes();
$asset_movement_labels = assetMovementTypes();
$asset_transaction_type_labels = assetTransactionTypes();
$asset_status_labels = assetStatuses();
$liability_status_labels = liabilityStatuses();

$edit_asset = null;
$edit_liability = null;
$assets = [];
$liabilities = [];

$total_manual_assets = 0;
$total_manual_liabilities = 0;
$total_cash = 0;
$total_bkash = 0;
$total_nagad = 0;
$total_bank = 0;
$total_investor_loan = 0;
$total_bank_loan = 0;
$total_credit_card_loan = 0;
$total_paid_to_liabilities = 0;
$payment_assets = [];
$movement_assets = [];
$total_asset_movements = 0;
$net_position = 0;

if ($system_ready) {
    $assets = fetchRows(
        $conn,
        "SELECT *\n         FROM assets\n         WHERE shop_id = ?\n         ORDER BY FIELD(status, 'active', 'inactive'), FIELD(asset_type, 'cash','bkash','nagad','bank','stock','customer_due','other'), asset_name ASC, id DESC",
        'i',
        [$shop_id]
    );

    $liabilities = fetchRows(
        $conn,
        "SELECT *\n         FROM liabilities\n         WHERE shop_id = ?\n         ORDER BY FIELD(status, 'active', 'paid', 'inactive'), FIELD(liability_type, 'supplier_due','bank_loan','credit_card_loan','investor_loan','expense_due','other'), liability_name ASC, id DESC",
        'i',
        [$shop_id]
    );

    $total_manual_assets = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND status = 'active'", 'i', [$shop_id]);
    $total_manual_liabilities = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND status = 'active'", 'i', [$shop_id]);

    $total_cash = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'cash' AND status = 'active'", 'i', [$shop_id]);
    $total_bkash = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'bkash' AND status = 'active'", 'i', [$shop_id]);
    $total_nagad = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'nagad' AND status = 'active'", 'i', [$shop_id]);
    $total_bank = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM assets WHERE shop_id = ? AND asset_type = 'bank' AND status = 'active'", 'i', [$shop_id]);

    $total_investor_loan = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND liability_type = 'investor_loan' AND status = 'active'", 'i', [$shop_id]);
    $total_bank_loan = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND liability_type = 'bank_loan' AND status = 'active'", 'i', [$shop_id]);
    $total_credit_card_loan = (float)scalarQuery($conn, "SELECT COALESCE(SUM(amount),0) FROM liabilities WHERE shop_id = ? AND liability_type = 'credit_card_loan' AND status = 'active'", 'i', [$shop_id]);

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

    $movement_assets = fetchRows(
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

    if ($asset_transactions_table_ready) {
        $total_asset_movements = (int)scalarQuery($conn, "SELECT COUNT(*) FROM asset_transactions WHERE shop_id = ?", 'i', [$shop_id]);
    }

    if ($payments_table_ready) {
        $total_paid_to_liabilities = (float)scalarQuery($conn, "SELECT COALESCE(SUM(paid_amount),0) FROM liability_payments WHERE shop_id = ?", 'i', [$shop_id]);
    }

    $net_position = $total_manual_assets - $total_manual_liabilities;

    $edit_asset_id = isset($_GET['edit_asset']) ? (int)$_GET['edit_asset'] : 0;
    if ($edit_asset_id > 0) {
        $rows = fetchRows($conn, "SELECT * FROM assets WHERE id = ? AND shop_id = ? LIMIT 1", 'ii', [$edit_asset_id, $shop_id]);
        $edit_asset = $rows[0] ?? null;
    }

    $edit_liability_id = isset($_GET['edit_liability']) ? (int)$_GET['edit_liability'] : 0;
    if ($edit_liability_id > 0) {
        $rows = fetchRows($conn, "SELECT * FROM liabilities WHERE id = ? AND shop_id = ? LIMIT 1", 'ii', [$edit_liability_id, $shop_id]);
        $edit_liability = $rows[0] ?? null;
    }
}

$flash = getFlash();

include 'header.php';
include 'sidebar.php';
?>

<style>
.v3-main {
  flex: 1;
  padding: 24px;
  background: #f0f4f8;
  min-height: 100vh;
  font-family: 'Segoe UI', system-ui, sans-serif;
}
.v3-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 20px;
  background: #fff;
  padding: 22px 28px;
  border-radius: 16px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  border: 1px solid #e2e8f0;
}
.v3-header-text h4 {
  font-size: 1.5rem;
  font-weight: 700;
  margin: 0 0 6px;
  color: #1e293b;
}
.v3-header-text h4 i { color: #f59e0b; margin-right: 8px; }
.v3-header-text p { color: #64748b; margin: 0; font-size: 0.85rem; }
.v3-live-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #ecfdf5;
  color: #059669;
  padding: 8px 18px;
  border-radius: 50px;
  font-size: 0.8rem;
  font-weight: 600;
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
  50% { opacity: 0.5; transform: scale(1.3); }
}
.v3-alert {
  padding: 12px 16px;
  border-radius: 12px;
  margin-bottom: 16px;
  font-size: 0.9rem;
  border: 1px solid transparent;
}
.v3-alert-success { background: #ecfdf5; color: #047857; border-color: #bbf7d0; }
.v3-alert-error { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.v3-warning-box {
  background: #fff7ed;
  border: 1px solid #fed7aa;
  color: #9a3412;
  border-radius: 12px;
  padding: 12px 16px;
  margin-bottom: 16px;
  font-size: 0.9rem;
}
.v3-summary-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 20px;
}
.v3-summary-card {
  background: #fff;
  padding: 20px;
  border-radius: 14px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.04);
  border: 1px solid #e2e8f0;
  position: relative;
  overflow: hidden;
}
.v3-summary-card::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 5px;
  background: #cbd5e1;
}
.v3-card-assets::before { background: #0ea5e9; }
.v3-card-liability::before { background: #ef4444; }
.v3-card-net::before { background: #f59e0b; }
.v3-card-channel::before { background: #10b981; }
.v3-summary-card h6 {
  margin: 0 0 10px;
  font-size: 0.8rem;
  color: #64748b;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
}
.v3-summary-big {
  display: block;
  font-size: 1.6rem;
  font-weight: 800;
  color: #1e293b;
  line-height: 1.2;
  margin-bottom: 6px;
}
.v3-summary-sub { font-size: 0.8rem; color: #64748b; }
.v3-section-title {
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #94a3b8;
  margin: 28px 0 16px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.v3-section-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
.v3-section-title i { color: #f59e0b; }
.v3-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 20px;
}
.v3-panel {
  background: #fff;
  padding: 22px;
  border-radius: 18px;
  box-shadow: 0 1px 8px rgba(0,0,0,0.04);
  border: 1px solid #e2e8f0;
}
.v3-panel-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}
.v3-panel h5 {
  margin: 0;
  font-size: 1rem;
  font-weight: 800;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 8px;
}
.v3-panel h5 i { color: #64748b; }
.v3-form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
}
.v3-field-full { grid-column: 1 / -1; }
.v3-field label {
  display: block;
  font-size: 0.78rem;
  color: #475569;
  font-weight: 700;
  margin-bottom: 6px;
}
.v3-field input,
.v3-field select,
.v3-field textarea {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 12px;
  padding: 10px 12px;
  font-size: 0.9rem;
  color: #1e293b;
  background: #fff;
  outline: none;
  transition: all 0.2s ease;
}
.v3-field textarea { min-height: 82px; resize: vertical; }
.v3-field input:focus,
.v3-field select:focus,
.v3-field textarea:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
}
.v3-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 16px;
  flex-wrap: wrap;
}
.v3-btn {
  border: none;
  border-radius: 12px;
  padding: 10px 16px;
  font-size: 0.85rem;
  font-weight: 800;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s ease;
}
.v3-btn:hover { transform: translateY(-1px); }
.v3-btn-primary { background: #2563eb; color: #fff; }
.v3-btn-success { background: #059669; color: #fff; }
.v3-btn-danger { background: #dc2626; color: #fff; }
.v3-btn-light { background: #f1f5f9; color: #334155; }
.v3-btn-warning { background: #f59e0b; color: #fff; }
.v3-btn-sm { padding: 7px 10px; font-size: 0.78rem; border-radius: 10px; }
.v3-table-wrap {
  overflow-x: auto;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
}
.v3-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 820px;
  background: #fff;
}
.v3-table th,
.v3-table td {
  padding: 12px 14px;
  border-bottom: 1px solid #f1f5f9;
  text-align: left;
  font-size: 0.86rem;
  vertical-align: top;
}
.v3-table th {
  background: #f8fafc;
  color: #334155;
  font-weight: 800;
  white-space: nowrap;
}
.v3-table td { color: #475569; }
.v3-table tr:last-child td { border-bottom: none; }
.v3-money {
  font-weight: 800;
  color: #1e293b;
  font-family: 'Courier New', monospace;
  white-space: nowrap;
}
.v3-badge {
  display: inline-flex;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 800;
  white-space: nowrap;
}
.v3-badge-green { background: #dcfce7; color: #166534; }
.v3-badge-blue { background: #dbeafe; color: #1d4ed8; }
.v3-badge-gray { background: #f1f5f9; color: #475569; }
.v3-muted { color: #94a3b8; font-size: 0.8rem; }
.v3-inline-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.v3-empty {
  padding: 22px;
  text-align: center;
  color: #64748b;
}
.v3-channel-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 20px;
}
.v3-channel-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.v3-channel-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #eff6ff;
  color: #2563eb;
  flex-shrink: 0;
}
.v3-channel-card span {
  display: block;
  font-size: 0.75rem;
  color: #64748b;
  font-weight: 700;
}
.v3-channel-card b {
  display: block;
  font-size: 1rem;
  color: #1e293b;
  font-weight: 800;
  margin-top: 3px;
}
.v3-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 18px;
  z-index: 9999;
}
.v3-modal-backdrop.v3-modal-show { display: flex; }
.v3-modal-card {
  width: 100%;
  max-width: 560px;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 24px 70px rgba(0,0,0,0.25);
  overflow: hidden;
  border: 1px solid #e2e8f0;
}
.v3-modal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 18px 20px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}
.v3-modal-head h5 { margin: 0; font-size: 1rem; font-weight: 800; color: #1e293b; }
.v3-modal-close {
  width: 34px;
  height: 34px;
  border: none;
  border-radius: 10px;
  background: #e2e8f0;
  color: #334155;
  cursor: pointer;
  font-weight: 900;
}
.v3-modal-body { padding: 20px; }
.v3-pay-context {
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 12px;
  padding: 12px 14px;
  margin-bottom: 14px;
  color: #334155;
  font-size: 0.9rem;
}
@media(max-width: 1200px) {
  .v3-summary-row, .v3-channel-grid { grid-template-columns: repeat(2, 1fr); }
}
@media(max-width: 767px) {
  .v3-main { padding: 14px; }
  .v3-header { padding: 16px 20px; }
  .v3-header-text h4 { font-size: 1.2rem; }
  .v3-summary-row, .v3-form-row, .v3-channel-grid { grid-template-columns: 1fr; }
  .v3-form-grid { grid-template-columns: 1fr; }
  .v3-actions { justify-content: stretch; }
  .v3-btn { width: 100%; }
}
</style>

<div class="v3-main">

    <div class="v3-header">
        <div class="v3-header-text">
            <h4><i class="fas fa-wallet"></i> Asset & Liability Management</h4>
            <p><?= h(date('l, d F Y')) ?> · Manual balance control · Shop ID: <?= (int)$shop_id ?></p>
        </div>
        <div class="v3-live-badge">
            <span class="v3-pulse-dot"></span> Secure CRUD
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="v3-alert <?= ($flash['type'] ?? '') === 'success' ? 'v3-alert-success' : 'v3-alert-error' ?>">
            <?= h($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if (!$system_ready): ?>
        <div class="v3-warning-box">
            <strong>Table missing:</strong>
            <?php if (!$assets_table_ready): ?> <span>`assets` table পাওয়া যায়নি।</span><?php endif; ?>
            <?php if (!$liabilities_table_ready): ?> <span>`liabilities` table পাওয়া যায়নি।</span><?php endif; ?>
            আগে দেওয়া SQL রান করে তারপর এই পেজ ব্যবহার করো।
        </div>
    <?php endif; ?>

    <?php if ($system_ready && !$payments_table_ready): ?>
        <div class="v3-warning-box">
            <strong>Payment history table missing:</strong> `liability_payments` table পাওয়া যায়নি। Pay button disabled থাকবে।
        </div>
    <?php endif; ?>

    <?php if ($system_ready && !$asset_transactions_table_ready): ?>
        <div class="v3-warning-box">
            <strong>Asset transaction table missing:</strong> `asset_transactions` table পাওয়া যায়নি। Asset Movement disabled থাকবে।
        </div>
    <?php endif; ?>

    <div class="v3-summary-row">
        <div class="v3-summary-card v3-card-assets">
            <h6>Total Assets</h6>
            <span class="v3-summary-big"><?= taka($total_manual_assets) ?></span>
            <span class="v3-summary-sub">Only active asset rows</span>
        </div>
        <div class="v3-summary-card v3-card-liability">
            <h6>Total Liability</h6>
            <span class="v3-summary-big"><?= taka($total_manual_liabilities) ?></span>
            <span class="v3-summary-sub">Only active liability rows</span>
        </div>
        <div class="v3-summary-card v3-card-net">
            <h6>Net Position</h6>
            <span class="v3-summary-big"><?= taka($net_position) ?></span>
            <span class="v3-summary-sub">Assets - Liabilities</span>
        </div>
        <div class="v3-summary-card v3-card-channel">
            <h6>Investor Loan</h6>
            <span class="v3-summary-big"><?= taka($total_investor_loan) ?></span>
            <span class="v3-summary-sub">Repayable investor amount</span>
        </div>
    </div>

    <div class="v3-channel-grid">
        <div class="v3-channel-card">
            <div class="v3-channel-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div><span>নগদ / Cash</span><b><?= taka($total_cash) ?></b></div>
        </div>
        <div class="v3-channel-card">
            <div class="v3-channel-icon"><i class="fas fa-mobile-alt"></i></div>
            <div><span>বিকাশ / bKash</span><b><?= taka($total_bkash) ?></b></div>
        </div>
        <div class="v3-channel-card">
            <div class="v3-channel-icon"><i class="fas fa-mobile-screen"></i></div>
            <div><span>নগদ মোবাইল ব্যাংকিং / Nagad</span><b><?= taka($total_nagad) ?></b></div>
        </div>
        <div class="v3-channel-card">
            <div class="v3-channel-icon"><i class="fas fa-building-columns"></i></div>
            <div><span>ব্যাংকে জমা / Bank</span><b><?= taka($total_bank) ?></b></div>
        </div>
    </div>

    <div class="v3-actions" style="justify-content:flex-start; margin-bottom:20px;">
        <button class="v3-btn v3-btn-primary" type="button" data-movement-open <?= (!$asset_transactions_table_ready || empty($movement_assets)) ? 'disabled' : '' ?>><i class="fas fa-right-left"></i> Asset Movement</button>
        <a class="v3-btn v3-btn-light" href="asset_transaction_list.php"><i class="fas fa-clock-rotate-left"></i> Asset Movement History</a>
        <a class="v3-btn v3-btn-light" href="asst_payment_list.php"><i class="fas fa-file-invoice-dollar"></i> Liability Payment History</a>
        <span class="v3-muted" style="align-self:center;">Liability paid: <?= taka($total_paid_to_liabilities) ?> · Asset movements: <?= number_format($total_asset_movements) ?></span>
    </div>

    <div class="v3-section-title"><i class="fas fa-pen-to-square"></i> Add / Update Manual Records</div>

    <div class="v3-form-row">
        <div class="v3-panel">
            <div class="v3-panel-head">
                <h5><i class="fas fa-circle-plus"></i> <?= $edit_asset ? 'Update Asset' : 'Asset CRUD' ?></h5>
                <?php if ($edit_asset): ?>
                    <a class="v3-btn v3-btn-light v3-btn-sm" href="<?= h(basename($_SERVER['PHP_SELF'])) ?>"><i class="fas fa-xmark"></i> Cancel Edit</a>
                <?php endif; ?>
            </div>

            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_asset">
                <input type="hidden" name="asset_id" value="<?= (int)($edit_asset['id'] ?? 0) ?>">

                <div class="v3-form-grid">
                    <div class="v3-field">
                        <label>Asset Type</label>
                        <select name="asset_type" required <?= !$system_ready ? 'disabled' : '' ?>>
                            <?php foreach ($asset_labels as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($edit_asset['asset_type'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="v3-field">
                        <label>Status</label>
                        <select name="status" required <?= !$system_ready ? 'disabled' : '' ?>>
                            <?php foreach ($asset_status_labels as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($edit_asset['status'] ?? 'active') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="v3-field v3-field-full">
                        <label>Asset Name</label>
                        <input type="text" name="asset_name" maxlength="150" required placeholder="Example: Cash in Hand / Main bKash / DBBL Account" value="<?= h($edit_asset['asset_name'] ?? '') ?>" <?= !$system_ready ? 'disabled' : '' ?>>
                    </div>

                    <div class="v3-field v3-field-full">
                        <label>Amount</label>
                        <input type="number" name="amount" min="0" step="0.01" required placeholder="0.00" value="<?= h($edit_asset['amount'] ?? '0.00') ?>" <?= !$system_ready ? 'disabled' : '' ?>>
                    </div>

                    <div class="v3-field v3-field-full">
                        <label>Note</label>
                        <textarea name="note" maxlength="2000" placeholder="Optional note" <?= !$system_ready ? 'disabled' : '' ?>><?= h($edit_asset['note'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="v3-actions">
                    <button class="v3-btn v3-btn-primary" type="submit" <?= !$system_ready ? 'disabled' : '' ?>><i class="fas fa-save"></i> <?= $edit_asset ? 'Update Asset' : 'Save Asset' ?></button>
                </div>
            </form>
        </div>

        <div class="v3-panel">
            <div class="v3-panel-head">
                <h5><i class="fas fa-file-invoice-dollar"></i> <?= $edit_liability ? 'Update Liability' : 'Liability CRUD' ?></h5>
                <?php if ($edit_liability): ?>
                    <a class="v3-btn v3-btn-light v3-btn-sm" href="<?= h(basename($_SERVER['PHP_SELF'])) ?>"><i class="fas fa-xmark"></i> Cancel Edit</a>
                <?php endif; ?>
            </div>

            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_liability">
                <input type="hidden" name="liability_id" value="<?= (int)($edit_liability['id'] ?? 0) ?>">

                <div class="v3-form-grid">
                    <div class="v3-field">
                        <label>Liability Type</label>
                        <select name="liability_type" required <?= !$system_ready ? 'disabled' : '' ?>>
                            <?php foreach ($liability_labels as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($edit_liability['liability_type'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="v3-field">
                        <label>Status</label>
                        <select name="status" required <?= !$system_ready ? 'disabled' : '' ?>>
                            <?php foreach ($liability_status_labels as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($edit_liability['status'] ?? 'active') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="v3-field v3-field-full">
                        <label>Liability Name</label>
                        <input type="text" name="liability_name" maxlength="150" required placeholder="Example: Bank Loan / Investor Loan / Credit Card" value="<?= h($edit_liability['liability_name'] ?? '') ?>" <?= !$system_ready ? 'disabled' : '' ?>>
                    </div>

                    <div class="v3-field">
                        <label>Amount</label>
                        <input type="number" name="amount" min="0" step="0.01" required placeholder="0.00" value="<?= h($edit_liability['amount'] ?? '0.00') ?>" <?= !$system_ready ? 'disabled' : '' ?>>
                    </div>

                    <div class="v3-field">
                        <label>Person / Company</label>
                        <input type="text" name="person_or_company" maxlength="150" placeholder="Bank / Investor / Supplier" value="<?= h($edit_liability['person_or_company'] ?? '') ?>" <?= !$system_ready ? 'disabled' : '' ?>>
                    </div>

                    <div class="v3-field v3-field-full">
                        <label>Phone</label>
                        <input type="text" name="phone" maxlength="30" placeholder="Optional phone" value="<?= h($edit_liability['phone'] ?? '') ?>" <?= !$system_ready ? 'disabled' : '' ?>>
                    </div>

                    <div class="v3-field v3-field-full">
                        <label>Note</label>
                        <textarea name="note" maxlength="2000" placeholder="Optional note / repayment terms" <?= !$system_ready ? 'disabled' : '' ?>><?= h($edit_liability['note'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="v3-actions">
                    <button class="v3-btn v3-btn-success" type="submit" <?= !$system_ready ? 'disabled' : '' ?>><i class="fas fa-save"></i> <?= $edit_liability ? 'Update Liability' : 'Save Liability' ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="v3-section-title"><i class="fas fa-list"></i> Current Manual Assets</div>
    <div class="v3-panel">
        <div class="v3-table-wrap">
            <table class="v3-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Note</th>
                        <th>Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($assets)): ?>
                    <?php foreach ($assets as $row): ?>
                        <tr>
                            <td><?= h(typeLabel($row['asset_type'] ?? '', $asset_labels)) ?></td>
                            <td><strong><?= h($row['asset_name'] ?? '') ?></strong></td>
                            <td class="v3-money"><?= taka($row['amount'] ?? 0) ?></td>
                            <td><span class="v3-badge <?= h(statusBadgeClass($row['status'] ?? 'inactive')) ?>"><?= h(ucfirst($row['status'] ?? 'inactive')) ?></span></td>
                            <td><?= h($row['note'] ?? '') ?></td>
                            <td class="v3-muted"><?= h($row['updated_at'] ?? $row['created_at'] ?? '') ?></td>
                            <td>
                                <div class="v3-inline-actions">
                                    <a class="v3-btn v3-btn-warning v3-btn-sm" href="<?= h(basename($_SERVER['PHP_SELF'])) ?>?edit_asset=<?= (int)$row['id'] ?>"><i class="fas fa-edit"></i> Edit</a>

                                    <?php if (($row['status'] ?? '') === 'active' && in_array(($row['asset_type'] ?? ''), ['cash','bkash','nagad','bank','other'], true)): ?>
                                        <button
                                            class="v3-btn v3-btn-primary v3-btn-sm"
                                            type="button"
                                            data-movement-open
                                            data-from-asset-id="<?= (int)$row['id'] ?>"
                                            data-from-asset-name="<?= h($row['asset_name'] ?? '') ?>"
                                            <?= (!$asset_transactions_table_ready) ? 'disabled' : '' ?>
                                        ><i class="fas fa-right-left"></i> Movement</button>
                                    <?php endif; ?>

                                    <a class="v3-btn v3-btn-light v3-btn-sm" href="asset_transaction_list.php?asset_id=<?= (int)$row['id'] ?>"><i class="fas fa-clock-rotate-left"></i> History</a>
                                    <?php if (($row['status'] ?? '') !== 'inactive'): ?>
                                        <form method="post" onsubmit="return confirm('এই asset inactive করতে চাও? Data permanently delete হবে না।');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_asset">
                                            <input type="hidden" name="asset_id" value="<?= (int)$row['id'] ?>">
                                            <button class="v3-btn v3-btn-danger v3-btn-sm" type="submit"><i class="fas fa-ban"></i> Inactive</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7"><div class="v3-empty">No asset record found.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="v3-section-title"><i class="fas fa-list-check"></i> Current Manual Liabilities</div>
    <div class="v3-panel">
        <div class="v3-table-wrap">
            <table class="v3-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Person / Company</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Note</th>
                        <th>Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($liabilities)): ?>
                    <?php foreach ($liabilities as $row): ?>
                        <tr>
                            <td><?= h(typeLabel($row['liability_type'] ?? '', $liability_labels)) ?></td>
                            <td><strong><?= h($row['liability_name'] ?? '') ?></strong></td>
                            <td>
                                <?= h($row['person_or_company'] ?? '') ?>
                                <?php if (!empty($row['phone'])): ?>
                                    <div class="v3-muted"><?= h($row['phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="v3-money"><?= taka($row['amount'] ?? 0) ?></td>
                            <td><span class="v3-badge <?= h(statusBadgeClass($row['status'] ?? 'inactive')) ?>"><?= h(ucfirst($row['status'] ?? 'inactive')) ?></span></td>
                            <td><?= h($row['note'] ?? '') ?></td>
                            <td class="v3-muted"><?= h($row['updated_at'] ?? $row['created_at'] ?? '') ?></td>
                            <td>
                                <div class="v3-inline-actions">
                                    <a class="v3-btn v3-btn-warning v3-btn-sm" href="<?= h(basename($_SERVER['PHP_SELF'])) ?>?edit_liability=<?= (int)$row['id'] ?>"><i class="fas fa-edit"></i> Edit</a>

                                    <?php if (($row['status'] ?? '') === 'active' && (float)($row['amount'] ?? 0) > 0): ?>
                                        <button
                                            class="v3-btn v3-btn-success v3-btn-sm"
                                            type="button"
                                            data-pay-open
                                            data-liability-id="<?= (int)$row['id'] ?>"
                                            data-liability-name="<?= h($row['liability_name'] ?? '') ?>"
                                            data-liability-amount="<?= h((string)($row['amount'] ?? '0')) ?>"
                                            <?= (!$payments_table_ready || empty($payment_assets)) ? 'disabled' : '' ?>
                                        ><i class="fas fa-money-bill-transfer"></i> Pay</button>
                                    <?php endif; ?>

                                    <a class="v3-btn v3-btn-light v3-btn-sm" href="asst_payment_list.php?liability_id=<?= (int)$row['id'] ?>"><i class="fas fa-clock-rotate-left"></i> History</a>

                                    <?php if (($row['status'] ?? '') !== 'inactive'): ?>
                                        <form method="post" onsubmit="return confirm('এই liability inactive করতে চাও? Data permanently delete হবে না।');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_liability">
                                            <input type="hidden" name="liability_id" value="<?= (int)$row['id'] ?>">
                                            <button class="v3-btn v3-btn-danger v3-btn-sm" type="submit"><i class="fas fa-ban"></i> Inactive</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8"><div class="v3-empty">No liability record found.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>



    <div class="v3-modal-backdrop" id="assetMovementModal" aria-hidden="true">
        <div class="v3-modal-card" role="dialog" aria-modal="true" aria-labelledby="movementModalTitle">
            <div class="v3-modal-head">
                <h5 id="movementModalTitle"><i class="fas fa-right-left"></i> Asset Movement</h5>
                <button class="v3-modal-close" type="button" data-movement-close>&times;</button>
            </div>
            <div class="v3-modal-body">
                <div class="v3-pay-context">
                    <div><strong>Use:</strong> Bank থেকে purchase payment, Cash to Bank transfer, opening balance বা manual money in/out.</div>
                    <div><strong>Note:</strong> মাল কিনলে stock value purchase module থেকে update হবে; এখানে শুধু Cash/bKash/Nagad/Bank balance কমবে।</div>
                </div>

                <?php if (empty($movement_assets)): ?>
                    <div class="v3-warning-box">কোন active Cash/bKash/Nagad/Bank asset row পাওয়া যায়নি। আগে Asset CRUD থেকে asset add/update করো।</div>
                <?php endif; ?>

                <form method="post" autocomplete="off" id="assetMovementForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_asset_movement">

                    <div class="v3-form-grid">
                        <div class="v3-field">
                            <label>Transaction Type</label>
                            <select name="transaction_type" id="movementTransactionType" required <?= empty($movement_assets) ? 'disabled' : '' ?>>
                                <?php foreach ($asset_transaction_type_labels as $key => $label): ?>
                                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="v3-field">
                            <label>Movement Type</label>
                            <select name="movement_type" required <?= empty($movement_assets) ? 'disabled' : '' ?>>
                                <?php foreach ($asset_movement_labels as $key => $label): ?>
                                    <?php if ($key === 'liability_payment') continue; ?>
                                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="v3-field" id="movementFromWrap">
                            <label>From Asset</label>
                            <select name="from_asset_id" id="movementFromAsset" <?= empty($movement_assets) ? 'disabled' : '' ?>>
                                <option value="">Select source</option>
                                <?php foreach ($movement_assets as $asset): ?>
                                    <option value="<?= (int)$asset['id'] ?>">
                                        <?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="v3-field" id="movementToWrap">
                            <label>To Asset</label>
                            <select name="to_asset_id" id="movementToAsset" <?= empty($movement_assets) ? 'disabled' : '' ?>>
                                <option value="">Select destination</option>
                                <?php foreach ($movement_assets as $asset): ?>
                                    <option value="<?= (int)$asset['id'] ?>">
                                        <?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="v3-field">
                            <label>Amount</label>
                            <input type="number" name="movement_amount" min="0.01" step="0.01" required placeholder="0.00" <?= empty($movement_assets) ? 'disabled' : '' ?>>
                        </div>

                        <div class="v3-field">
                            <label>Transaction Date</label>
                            <input type="date" name="transaction_date" value="<?= h(date('Y-m-d')) ?>" required <?= empty($movement_assets) ? 'disabled' : '' ?>>
                        </div>

                        <div class="v3-field v3-field-full">
                            <label>Note</label>
                            <textarea name="movement_note" maxlength="2000" placeholder="Example: Bank থেকে 5,000 টাকার মাল কেনা / Cash deposit to bank" <?= empty($movement_assets) ? 'disabled' : '' ?>></textarea>
                        </div>
                    </div>

                    <div class="v3-actions">
                        <button class="v3-btn v3-btn-light" type="button" data-movement-close><i class="fas fa-xmark"></i> Cancel</button>
                        <button class="v3-btn v3-btn-primary" type="submit" <?= empty($movement_assets) ? 'disabled' : '' ?>><i class="fas fa-check"></i> Save Movement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="v3-modal-backdrop" id="liabilityPayModal" aria-hidden="true">
        <div class="v3-modal-card" role="dialog" aria-modal="true" aria-labelledby="payModalTitle">
            <div class="v3-modal-head">
                <h5 id="payModalTitle"><i class="fas fa-money-bill-transfer"></i> Pay Liability</h5>
                <button class="v3-modal-close" type="button" data-pay-close>&times;</button>
            </div>
            <div class="v3-modal-body">
                <div class="v3-pay-context">
                    <div><strong>Liability:</strong> <span id="payLiabilityName">-</span></div>
                    <div><strong>Current Due:</strong> <span id="payLiabilityAmount">TK. 0.00</span></div>
                </div>

                <?php if (empty($payment_assets)): ?>
                    <div class="v3-warning-box">কোন active cash/bKash/Nagad/Bank asset balance পাওয়া যায়নি। আগে Asset CRUD থেকে balance add/update করো।</div>
                <?php endif; ?>

                <form method="post" autocomplete="off" id="liabilityPayForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="pay_liability">
                    <input type="hidden" name="liability_id" id="payLiabilityId" value="0">

                    <div class="v3-form-grid">
                        <div class="v3-field v3-field-full">
                            <label>Pay From Asset</label>
                            <select name="pay_from_asset_id" required <?= empty($payment_assets) ? 'disabled' : '' ?>>
                                <option value="">Select source</option>
                                <?php foreach ($payment_assets as $asset): ?>
                                    <option value="<?= (int)$asset['id'] ?>">
                                        <?= h(typeLabel($asset['asset_type'] ?? '', $payment_asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?> — <?= taka($asset['amount'] ?? 0) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="v3-field">
                            <label>Payment Amount</label>
                            <input type="number" name="paid_amount" id="payAmountInput" min="0.01" step="0.01" required placeholder="0.00" <?= empty($payment_assets) ? 'disabled' : '' ?>>
                        </div>

                        <div class="v3-field">
                            <label>Payment Date</label>
                            <input type="date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" required <?= empty($payment_assets) ? 'disabled' : '' ?>>
                        </div>

                        <div class="v3-field v3-field-full">
                            <label>Note</label>
                            <textarea name="payment_note" maxlength="2000" placeholder="Optional note / receipt reference" <?= empty($payment_assets) ? 'disabled' : '' ?>></textarea>
                        </div>
                    </div>

                    <div class="v3-actions">
                        <button class="v3-btn v3-btn-light" type="button" data-pay-close><i class="fas fa-xmark"></i> Cancel</button>
                        <button class="v3-btn v3-btn-success" type="submit" <?= empty($payment_assets) ? 'disabled' : '' ?>><i class="fas fa-check"></i> Confirm Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const movementModal = document.getElementById('assetMovementModal');
    const movementType = document.getElementById('movementTransactionType');
    const fromWrap = document.getElementById('movementFromWrap');
    const toWrap = document.getElementById('movementToWrap');
    const fromSelect = document.getElementById('movementFromAsset');
    const toSelect = document.getElementById('movementToAsset');

    function setMovementMode() {
        if (!movementType) return;
        const type = movementType.value;
        if (fromWrap) fromWrap.style.display = (type === 'out' || type === 'transfer') ? '' : 'none';
        if (toWrap) toWrap.style.display = (type === 'in' || type === 'transfer') ? '' : 'none';
        if (fromSelect) fromSelect.required = (type === 'out' || type === 'transfer');
        if (toSelect) toSelect.required = (type === 'in' || type === 'transfer');
    }

    function openMovementModal(button) {
        if (!movementModal) return;

        const fromAssetId = button && button.getAttribute ? (button.getAttribute('data-from-asset-id') || '') : '';

        // Row-wise Movement button: keep clicked asset as From Asset by default.
        // Example: Bank Deposit row -> Movement -> From Asset auto-selected as Bank Deposit.
        if (fromAssetId && fromSelect) {
            fromSelect.value = fromAssetId;
            if (movementType && !movementType.value) {
                movementType.value = 'out';
            }
        }

        setMovementMode();
        movementModal.classList.add('v3-modal-show');
        movementModal.setAttribute('aria-hidden', 'false');
    }

    function closeMovementModal() {
        movementModal.classList.remove('v3-modal-show');
        movementModal.setAttribute('aria-hidden', 'true');
    }

    if (movementModal) {
        document.querySelectorAll('[data-movement-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                openMovementModal(button);
            });
        });
        document.querySelectorAll('[data-movement-close]').forEach(function (button) {
            button.addEventListener('click', closeMovementModal);
        });
        if (movementType) movementType.addEventListener('change', setMovementMode);
        movementModal.addEventListener('click', function (event) {
            if (event.target === movementModal) closeMovementModal();
        });
    }

    const modal = document.getElementById('liabilityPayModal');
    const idInput = document.getElementById('payLiabilityId');
    const nameEl = document.getElementById('payLiabilityName');
    const amountEl = document.getElementById('payLiabilityAmount');
    const amountInput = document.getElementById('payAmountInput');

    function formatTaka(value) {
        const number = Number(value || 0);
        return 'TK. ' + number.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function openModal(button) {
        const id = button.getAttribute('data-liability-id') || '0';
        const name = button.getAttribute('data-liability-name') || '-';
        const amount = button.getAttribute('data-liability-amount') || '0';

        idInput.value = id;
        nameEl.textContent = name;
        amountEl.textContent = formatTaka(amount);
        amountInput.value = amount;
        amountInput.max = amount;

        modal.classList.add('v3-modal-show');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('v3-modal-show');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-pay-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(button);
        });
    });

    document.querySelectorAll('[data-pay-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
            if (movementModal) {
                movementModal.classList.remove('v3-modal-show');
                movementModal.setAttribute('aria-hidden', 'true');
            }
        }
    });
});
</script>

</div>

<?php include 'footer.php'; ?>
