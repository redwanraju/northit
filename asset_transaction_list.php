<?php
// ============================================================
// Asset Movement History
// File: asset_transaction_list.php
// Purpose: Read-only history of Cash/bKash/Nagad/Bank movements
// Safety: Login required, shop_id scoped, prepared statements,
//         safe output, filtered listing.
// ============================================================

include 'auth_helper.php';
requireLogin();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$shop_id = tenantShopId();
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

function safeIdentifier($name) {
    return preg_match('/^[a-zA-Z0-9_]+$/', (string)$name) ? $name : '';
}

function tableExists($conn, $table) {
    static $cache = [];

    $table = safeIdentifier($table);
    if ($table === '') return false;
    if (isset($cache[$table])) return $cache[$table];

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

function cleanFilter($value, $maxLength = 100) {
    $value = trim((string)$value);
    $value = preg_replace('/[^a-zA-Z0-9_\-]/', '', $value);
    return substr($value, 0, $maxLength);
}

function assetTypes() {
    return [
        'cash' => 'নগদ / Cash',
        'bkash' => 'বিকাশ / bKash',
        'nagad' => 'নগদ মোবাইল ব্যাংকিং / Nagad',
        'bank' => 'ব্যাংক / Bank',
        'stock' => 'Stock',
        'customer_due' => 'Customer Due',
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
        'transfer' => 'Transfer',
        'adjustment' => 'Adjustment'
    ];
}

function typeLabel($type, $labels) {
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function txBadgeClass($type) {
    if ($type === 'in') return 'v3-badge-green';
    if ($type === 'transfer') return 'v3-badge-blue';
    if ($type === 'adjustment') return 'v3-badge-orange';
    return 'v3-badge-red';
}

$transactions_table_ready = isset($conn) && $conn && tableExists($conn, 'asset_transactions');
$assets_table_ready = isset($conn) && $conn && tableExists($conn, 'assets');

$asset_labels = assetTypes();
$movement_labels = assetMovementTypes();
$transaction_type_labels = assetTransactionTypes();

$date_from = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from']) ? (string)$_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']) ? (string)$_GET['date_to'] : '';
$asset_id = isset($_GET['asset_id']) ? max(0, (int)$_GET['asset_id']) : 0;
$transaction_type = isset($_GET['transaction_type']) ? cleanFilter($_GET['transaction_type']) : '';
$movement_type = isset($_GET['movement_type']) ? cleanFilter($_GET['movement_type']) : '';

if ($transaction_type !== '' && !array_key_exists($transaction_type, $transaction_type_labels)) {
    $transaction_type = '';
}
if ($movement_type !== '' && !array_key_exists($movement_type, $movement_labels)) {
    $movement_type = '';
}

$asset_options = [];
$history_rows = [];
$total_count = 0;
$total_amount = 0;
$total_in = 0;
$total_out = 0;
$total_transfer = 0;
$selected_asset_title = '';

if ($transactions_table_ready) {
    if ($assets_table_ready) {
        $asset_options = fetchRows(
            $conn,
            "SELECT id, asset_type, asset_name, amount, status\n             FROM assets\n             WHERE shop_id = ?\n             ORDER BY FIELD(status, 'active','inactive'), FIELD(asset_type, 'cash','bkash','nagad','bank','other'), asset_name ASC",
            'i',
            [$shop_id]
        );
    }

    $where = 'atx.shop_id = ?';
    $types = 'i';
    $params = [$shop_id];

    if ($date_from !== '') {
        $where .= ' AND atx.transaction_date >= ?';
        $types .= 's';
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $where .= ' AND atx.transaction_date <= ?';
        $types .= 's';
        $params[] = $date_to;
    }
    if ($asset_id > 0) {
        $where .= ' AND (atx.from_asset_id = ? OR atx.to_asset_id = ?)';
        $types .= 'ii';
        $params[] = $asset_id;
        $params[] = $asset_id;
    }
    if ($transaction_type !== '') {
        $where .= ' AND atx.transaction_type = ?';
        $types .= 's';
        $params[] = $transaction_type;
    }
    if ($movement_type !== '') {
        $where .= ' AND atx.movement_type = ?';
        $types .= 's';
        $params[] = $movement_type;
    }

    $total_count = (int)scalarQuery($conn, "SELECT COUNT(*) FROM asset_transactions atx WHERE $where", $types, $params);
    $total_amount = (float)scalarQuery($conn, "SELECT COALESCE(SUM(atx.amount),0) FROM asset_transactions atx WHERE $where", $types, $params);
    $total_in = (float)scalarQuery($conn, "SELECT COALESCE(SUM(atx.amount),0) FROM asset_transactions atx WHERE $where AND atx.transaction_type = 'in'", $types, $params);
    $total_out = (float)scalarQuery($conn, "SELECT COALESCE(SUM(atx.amount),0) FROM asset_transactions atx WHERE $where AND atx.transaction_type = 'out'", $types, $params);
    $total_transfer = (float)scalarQuery($conn, "SELECT COALESCE(SUM(atx.amount),0) FROM asset_transactions atx WHERE $where AND atx.transaction_type = 'transfer'", $types, $params);

    $history_rows = fetchRows(
        $conn,
        "SELECT atx.*\n         FROM asset_transactions atx\n         WHERE $where\n         ORDER BY atx.transaction_date DESC, atx.id DESC\n         LIMIT 700",
        $types,
        $params
    );

    if ($asset_id > 0) {
        foreach ($asset_options as $asset) {
            if ((int)$asset['id'] === $asset_id) {
                $selected_asset_title = (string)$asset['asset_name'];
                break;
            }
        }
        if ($selected_asset_title === '' && !empty($history_rows)) {
            $first = $history_rows[0];
            if ((int)($first['from_asset_id'] ?? 0) === $asset_id) {
                $selected_asset_title = (string)($first['from_asset_name'] ?? '');
            } elseif ((int)($first['to_asset_id'] ?? 0) === $asset_id) {
                $selected_asset_title = (string)($first['to_asset_name'] ?? '');
            }
        }
    }
}

include 'header.php';
include 'sidebar.php';
?>

<style>
.v3-main { flex: 1; padding: 24px; background: #f0f4f8; min-height: 100vh; font-family: 'Segoe UI', system-ui, sans-serif; }
.v3-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px; background:#fff; padding:22px 28px; border-radius:16px; box-shadow:0 2px 12px rgba(0,0,0,0.06); border:1px solid #e2e8f0; }
.v3-header-text h4 { font-size:1.5rem; font-weight:700; margin:0 0 6px; color:#1e293b; }
.v3-header-text h4 i { color:#f59e0b; margin-right:8px; }
.v3-header-text p { color:#64748b; margin:0; font-size:0.85rem; }
.v3-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.v3-btn { border:none; border-radius:12px; padding:10px 16px; font-size:0.85rem; font-weight:800; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all 0.2s ease; }
.v3-btn:hover { transform:translateY(-1px); }
.v3-btn-primary { background:#2563eb; color:#fff; }
.v3-btn-light { background:#f1f5f9; color:#334155; }
.v3-btn-danger { background:#dc2626; color:#fff; }
.v3-warning-box { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:12px; padding:12px 16px; margin-bottom:16px; font-size:0.9rem; }
.v3-summary-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
.v3-summary-card { background:#fff; padding:20px; border-radius:14px; box-shadow:0 1px 6px rgba(0,0,0,0.04); border:1px solid #e2e8f0; position:relative; overflow:hidden; }
.v3-summary-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:5px; background:#cbd5e1; }
.v3-card-count::before { background:#2563eb; }
.v3-card-in::before { background:#10b981; }
.v3-card-out::before { background:#ef4444; }
.v3-card-transfer::before { background:#f59e0b; }
.v3-summary-card h6 { margin:0 0 10px; font-size:0.8rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; }
.v3-summary-big { display:block; font-size:1.5rem; font-weight:800; color:#1e293b; line-height:1.2; margin-bottom:6px; }
.v3-summary-sub { font-size:0.8rem; color:#64748b; }
.v3-panel { background:#fff; padding:22px; border-radius:18px; box-shadow:0 1px 8px rgba(0,0,0,0.04); border:1px solid #e2e8f0; margin-bottom:20px; }
.v3-filter-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; align-items:end; }
.v3-field label { display:block; font-size:0.78rem; color:#475569; font-weight:700; margin-bottom:6px; }
.v3-field input, .v3-field select { width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:0.9rem; color:#1e293b; background:#fff; outline:none; }
.v3-section-title { font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:#94a3b8; margin:28px 0 16px; display:flex; align-items:center; gap:10px; }
.v3-section-title::after { content:''; flex:1; height:1px; background:#e2e8f0; }
.v3-section-title i { color:#f59e0b; }
.v3-table-wrap { overflow-x:auto; border:1px solid #e2e8f0; border-radius:14px; }
.v3-table { width:100%; border-collapse:collapse; min-width:1150px; background:#fff; }
.v3-table th, .v3-table td { padding:12px 14px; border-bottom:1px solid #f1f5f9; text-align:left; font-size:0.86rem; vertical-align:top; }
.v3-table th { background:#f8fafc; color:#334155; font-weight:800; white-space:nowrap; }
.v3-table td { color:#475569; }
.v3-table tr:last-child td { border-bottom:none; }
.v3-money { font-weight:800; color:#1e293b; font-family:'Courier New', monospace; white-space:nowrap; }
.v3-muted { color:#94a3b8; font-size:0.8rem; }
.v3-badge { display:inline-flex; padding:4px 10px; border-radius:999px; font-size:0.72rem; font-weight:800; white-space:nowrap; }
.v3-badge-green { background:#dcfce7; color:#166534; }
.v3-badge-red { background:#fee2e2; color:#991b1b; }
.v3-badge-blue { background:#dbeafe; color:#1d4ed8; }
.v3-badge-orange { background:#ffedd5; color:#c2410c; }
.v3-empty { padding:22px; text-align:center; color:#64748b; }
@media(max-width:1200px){ .v3-summary-row{grid-template-columns:repeat(2,1fr);} .v3-filter-grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:767px){ .v3-main{padding:14px;} .v3-header{padding:16px 20px;} .v3-summary-row,.v3-filter-grid{grid-template-columns:1fr;} .v3-btn{width:100%; justify-content:center;} }
</style>

<div class="v3-main">
    <div class="v3-header">
        <div class="v3-header-text">
            <h4><i class="fas fa-clock-rotate-left"></i> Asset Movement History</h4>
            <p><?= h(date('l, d F Y')) ?> · Cash/bKash/Nagad/Bank movement audit trail · Shop ID: <?= (int)$shop_id ?></p>
        </div>
        <div class="v3-actions">
            <a class="v3-btn v3-btn-light" href="asst_mngment.php"><i class="fas fa-arrow-left"></i> Back to Management</a>
        </div>
    </div>

    <?php if (!$transactions_table_ready): ?>
        <div class="v3-warning-box">
            <strong>Table missing:</strong> `asset_transactions` table পাওয়া যায়নি। আগে Asset Movement SQL রান করো।
        </div>
    <?php endif; ?>

    <?php if ($selected_asset_title !== ''): ?>
        <div class="v3-warning-box">
            Showing movement history for: <strong><?= h($selected_asset_title) ?></strong>
        </div>
    <?php endif; ?>

    <div class="v3-summary-row">
        <div class="v3-summary-card v3-card-count">
            <h6>Total Records</h6>
            <span class="v3-summary-big"><?= number_format($total_count) ?></span>
            <span class="v3-summary-sub">Filtered movement count</span>
        </div>
        <div class="v3-summary-card v3-card-in">
            <h6>Money In</h6>
            <span class="v3-summary-big"><?= taka($total_in) ?></span>
            <span class="v3-summary-sub">Incoming asset balance</span>
        </div>
        <div class="v3-summary-card v3-card-out">
            <h6>Money Out</h6>
            <span class="v3-summary-big"><?= taka($total_out) ?></span>
            <span class="v3-summary-sub">Outgoing asset balance</span>
        </div>
        <div class="v3-summary-card v3-card-transfer">
            <h6>Transfer</h6>
            <span class="v3-summary-big"><?= taka($total_transfer) ?></span>
            <span class="v3-summary-sub">Internal asset transfers</span>
        </div>
    </div>

    <div class="v3-panel">
        <form method="get" autocomplete="off">
            <div class="v3-filter-grid">
                <div class="v3-field">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= h($date_from) ?>">
                </div>
                <div class="v3-field">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= h($date_to) ?>">
                </div>
                <div class="v3-field">
                    <label>Asset</label>
                    <select name="asset_id">
                        <option value="0">All assets</option>
                        <?php foreach ($asset_options as $asset): ?>
                            <option value="<?= (int)$asset['id'] ?>" <?= ((int)$asset['id'] === $asset_id) ? 'selected' : '' ?>>
                                <?= h(typeLabel($asset['asset_type'] ?? '', $asset_labels)) ?> — <?= h($asset['asset_name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="v3-field">
                    <label>Transaction Type</label>
                    <select name="transaction_type">
                        <option value="">All types</option>
                        <?php foreach ($transaction_type_labels as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $transaction_type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="v3-field">
                    <label>Movement Type</label>
                    <select name="movement_type">
                        <option value="">All movements</option>
                        <?php foreach ($movement_labels as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $movement_type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="v3-actions" style="margin-top:16px;">
                <button class="v3-btn v3-btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                <a class="v3-btn v3-btn-light" href="asset_transaction_list.php"><i class="fas fa-rotate-left"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="v3-section-title"><i class="fas fa-list"></i> Movement Records</div>
    <div class="v3-panel">
        <div class="v3-table-wrap">
            <table class="v3-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Movement</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Amount</th>
                        <th>From Balance</th>
                        <th>To Balance</th>
                        <th>Reference</th>
                        <th>Note</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($history_rows)): ?>
                    <?php foreach ($history_rows as $row): ?>
                        <tr>
                            <td><?= h($row['transaction_date'] ?? '') ?></td>
                            <td><span class="v3-badge <?= h(txBadgeClass($row['transaction_type'] ?? 'out')) ?>"><?= h(typeLabel($row['transaction_type'] ?? '', $transaction_type_labels)) ?></span></td>
                            <td><?= h(typeLabel($row['movement_type'] ?? '', $movement_labels)) ?></td>
                            <td>
                                <?php if (!empty($row['from_asset_name'])): ?>
                                    <strong><?= h($row['from_asset_name']) ?></strong>
                                    <div class="v3-muted"><?= h(typeLabel($row['from_asset_type'] ?? '', $asset_labels)) ?></div>
                                <?php else: ?>
                                    <span class="v3-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['to_asset_name'])): ?>
                                    <strong><?= h($row['to_asset_name']) ?></strong>
                                    <div class="v3-muted"><?= h(typeLabel($row['to_asset_type'] ?? '', $asset_labels)) ?></div>
                                <?php else: ?>
                                    <span class="v3-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="v3-money"><?= taka($row['amount'] ?? 0) ?></td>
                            <td>
                                <?php if ($row['from_balance_before'] !== null || $row['from_balance_after'] !== null): ?>
                                    <span class="v3-money"><?= taka($row['from_balance_before'] ?? 0) ?></span>
                                    <div class="v3-muted">to <?= taka($row['from_balance_after'] ?? 0) ?></div>
                                <?php else: ?>
                                    <span class="v3-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['to_balance_before'] !== null || $row['to_balance_after'] !== null): ?>
                                    <span class="v3-money"><?= taka($row['to_balance_before'] ?? 0) ?></span>
                                    <div class="v3-muted">to <?= taka($row['to_balance_after'] ?? 0) ?></div>
                                <?php else: ?>
                                    <span class="v3-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['reference_type'])): ?>
                                    <?= h($row['reference_type']) ?>
                                    <?php if (!empty($row['reference_id'])): ?>
                                        <div class="v3-muted">ID: <?= (int)$row['reference_id'] ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="v3-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['note'] ?? '') ?></td>
                            <td class="v3-muted"><?= h($row['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11"><div class="v3-empty">No asset movement record found.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
