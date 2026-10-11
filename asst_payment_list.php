<?php
// ============================================================
// Asset & Liability Payment History
// File: asst_payment_list.php
// Purpose: Read-only history of liability payments
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

function paymentAssetTypes() {
    return [
        'cash' => 'নগদ / Cash',
        'bkash' => 'বিকাশ / bKash',
        'nagad' => 'নগদ মোবাইল ব্যাংকিং / Nagad',
        'bank' => 'ব্যাংক / Bank',
        'other' => 'Other'
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

function typeLabel($type, $labels) {
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

$payments_table_ready = isset($conn) && $conn && tableExists($conn, 'liability_payments');
$liabilities_table_ready = isset($conn) && $conn && tableExists($conn, 'liabilities');

$payment_asset_labels = paymentAssetTypes();
$liability_labels = liabilityTypes();

$date_from = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from']) ? (string)$_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']) ? (string)$_GET['date_to'] : '';
$liability_id = isset($_GET['liability_id']) ? max(0, (int)$_GET['liability_id']) : 0;
$paid_from_asset_type = isset($_GET['paid_from_asset_type']) ? cleanFilter($_GET['paid_from_asset_type']) : '';

if ($paid_from_asset_type !== '' && !array_key_exists($paid_from_asset_type, $payment_asset_labels)) {
    $paid_from_asset_type = '';
}

$history_rows = [];
$liability_options = [];
$total_paid = 0;
$total_count = 0;
$selected_liability_title = '';

if ($payments_table_ready) {
    if ($liabilities_table_ready) {
        $liability_options = fetchRows(
            $conn,
            "SELECT id, liability_type, liability_name, amount, status\n             FROM liabilities\n             WHERE shop_id = ?\n             ORDER BY FIELD(status, 'active','paid','inactive'), liability_name ASC",
            'i',
            [$shop_id]
        );
    }

    $where = "lp.shop_id = ?";
    $types = 'i';
    $params = [$shop_id];

    if ($date_from !== '') {
        $where .= " AND lp.payment_date >= ?";
        $types .= 's';
        $params[] = $date_from;
    }

    if ($date_to !== '') {
        $where .= " AND lp.payment_date <= ?";
        $types .= 's';
        $params[] = $date_to;
    }

    if ($liability_id > 0) {
        $where .= " AND lp.liability_id = ?";
        $types .= 'i';
        $params[] = $liability_id;
    }

    if ($paid_from_asset_type !== '') {
        $where .= " AND lp.paid_from_asset_type = ?";
        $types .= 's';
        $params[] = $paid_from_asset_type;
    }

    $total_paid = (float)scalarQuery(
        $conn,
        "SELECT COALESCE(SUM(lp.paid_amount),0)\n         FROM liability_payments lp\n         WHERE $where",
        $types,
        $params
    );

    $total_count = (int)scalarQuery(
        $conn,
        "SELECT COUNT(*)\n         FROM liability_payments lp\n         WHERE $where",
        $types,
        $params
    );

    $history_rows = fetchRows(
        $conn,
        "SELECT lp.*, l.person_or_company, l.phone, l.amount AS current_due, l.status AS current_status\n         FROM liability_payments lp\n         LEFT JOIN liabilities l ON l.id = lp.liability_id AND l.shop_id = lp.shop_id\n         WHERE $where\n         ORDER BY lp.payment_date DESC, lp.id DESC\n         LIMIT 500",
        $types,
        $params
    );

    if ($liability_id > 0) {
        foreach ($liability_options as $item) {
            if ((int)$item['id'] === $liability_id) {
                $selected_liability_title = (string)$item['liability_name'];
                break;
            }
        }
        if ($selected_liability_title === '' && !empty($history_rows)) {
            $selected_liability_title = (string)($history_rows[0]['liability_name'] ?? '');
        }
    }
}

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
.v3-btn-light { background: #f1f5f9; color: #334155; }
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
  grid-template-columns: repeat(3, 1fr);
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
  background: #0ea5e9;
}
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
.v3-panel {
  background: #fff;
  padding: 22px;
  border-radius: 18px;
  box-shadow: 0 1px 8px rgba(0,0,0,0.04);
  border: 1px solid #e2e8f0;
  margin-bottom: 20px;
}
.v3-filter-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  align-items: end;
}
.v3-field label {
  display: block;
  font-size: 0.78rem;
  color: #475569;
  font-weight: 700;
  margin-bottom: 6px;
}
.v3-field input,
.v3-field select {
  width: 100%;
  border: 1px solid #cbd5e1;
  border-radius: 12px;
  padding: 10px 12px;
  font-size: 0.9rem;
  color: #1e293b;
  background: #fff;
  outline: none;
}
.v3-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 14px; }
.v3-table { width: 100%; border-collapse: collapse; min-width: 900px; background: #fff; }
.v3-table th,
.v3-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; text-align: left; font-size: 0.86rem; vertical-align: top; }
.v3-table th { background: #f8fafc; color: #334155; font-weight: 800; white-space: nowrap; }
.v3-table td { color: #475569; }
.v3-table tr:last-child td { border-bottom: none; }
.v3-money { font-weight: 800; color: #1e293b; font-family: 'Courier New', monospace; white-space: nowrap; }
.v3-muted { color: #94a3b8; font-size: 0.8rem; }
.v3-badge { display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; white-space: nowrap; background: #dbeafe; color: #1d4ed8; }
.v3-empty { padding: 22px; text-align: center; color: #64748b; }
@media(max-width: 1200px) {
  .v3-summary-row { grid-template-columns: repeat(2, 1fr); }
  .v3-filter-grid { grid-template-columns: repeat(2, 1fr); }
}
@media(max-width: 767px) {
  .v3-main { padding: 14px; }
  .v3-header { padding: 16px 20px; }
  .v3-header-text h4 { font-size: 1.2rem; }
  .v3-summary-row, .v3-filter-grid { grid-template-columns: 1fr; }
  .v3-btn { width: 100%; }
}
</style>

<div class="v3-main">

    <div class="v3-header">
        <div class="v3-header-text">
            <h4><i class="fas fa-clock-rotate-left"></i> Liability Payment History</h4>
            <p><?= h(date('l, d F Y')) ?> · Shop ID: <?= (int)$shop_id ?><?= $selected_liability_title ? ' · ' . h($selected_liability_title) : '' ?></p>
        </div>
        <a class="v3-btn v3-btn-light" href="asst_mngment.php"><i class="fas fa-arrow-left"></i> Back to Management</a>
    </div>

    <?php if (!$payments_table_ready): ?>
        <div class="v3-warning-box">
            <strong>Table missing:</strong> `liability_payments` table পাওয়া যায়নি। আগে SQL রান করো।
        </div>
    <?php endif; ?>

    <div class="v3-summary-row">
        <div class="v3-summary-card">
            <h6>Total Paid</h6>
            <span class="v3-summary-big"><?= taka($total_paid) ?></span>
            <span class="v3-summary-sub">Filtered payment total</span>
        </div>
        <div class="v3-summary-card">
            <h6>Total Entries</h6>
            <span class="v3-summary-big"><?= number_format($total_count) ?></span>
            <span class="v3-summary-sub">Filtered history rows</span>
        </div>
        <div class="v3-summary-card">
            <h6>Mode</h6>
            <span class="v3-summary-big">Read Only</span>
            <span class="v3-summary-sub">History should not be edited</span>
        </div>
    </div>

    <div class="v3-panel">
        <form method="get">
            <div class="v3-filter-grid">
                <div class="v3-field">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?= h($date_from) ?>">
                </div>
                <div class="v3-field">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?= h($date_to) ?>">
                </div>
                <div class="v3-field">
                    <label>Liability</label>
                    <select name="liability_id">
                        <option value="0">All liabilities</option>
                        <?php foreach ($liability_options as $item): ?>
                            <option value="<?= (int)$item['id'] ?>" <?= ((int)$item['id'] === $liability_id) ? 'selected' : '' ?>>
                                <?= h($item['liability_name'] ?? '') ?> — <?= h(typeLabel($item['liability_type'] ?? '', $liability_labels)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="v3-field">
                    <label>Paid From</label>
                    <select name="paid_from_asset_type">
                        <option value="">All sources</option>
                        <?php foreach ($payment_asset_labels as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $paid_from_asset_type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button class="v3-btn v3-btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                    <a class="v3-btn v3-btn-light" href="asst_payment_list.php"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="v3-panel">
        <div class="v3-table-wrap">
            <table class="v3-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Liability</th>
                        <th>Type</th>
                        <th>Person / Company</th>
                        <th>Paid From</th>
                        <th>Paid Amount</th>
                        <th>Current Due</th>
                        <th>Note</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($history_rows)): ?>
                    <?php foreach ($history_rows as $row): ?>
                        <tr>
                            <td><span class="v3-badge"><?= h($row['payment_date'] ?? '') ?></span></td>
                            <td><strong><?= h($row['liability_name'] ?? '') ?></strong></td>
                            <td><?= h(typeLabel($row['liability_type'] ?? '', $liability_labels)) ?></td>
                            <td>
                                <?= h($row['person_or_company'] ?? '') ?>
                                <?php if (!empty($row['phone'])): ?>
                                    <div class="v3-muted"><?= h($row['phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= h(typeLabel($row['paid_from_asset_type'] ?? '', $payment_asset_labels)) ?></td>
                            <td class="v3-money"><?= taka($row['paid_amount'] ?? 0) ?></td>
                            <td class="v3-money"><?= isset($row['current_due']) ? taka($row['current_due']) : 'n/a' ?></td>
                            <td><?= h($row['note'] ?? '') ?></td>
                            <td class="v3-muted"><?= h($row['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9"><div class="v3-empty">No payment history found.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include 'footer.php'; ?>
