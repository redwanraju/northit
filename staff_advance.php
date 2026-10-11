<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";
include "header.php";
include "sidebar.php";

$shop_id = tenantShopId();
$sr_id = isset($_GET['sr_id']) ? (int)$_GET['sr_id'] : 0;

if($sr_id == 0){
    header("Location: sr_management.php");
    exit;
}

$sr = $conn->query("SELECT * FROM sales_representatives WHERE id=$sr_id AND shop_id=$shop_id")->fetch_assoc();
if(!$sr){
    header("Location: sr_management.php");
    exit;
}

$sr_name = htmlspecialchars($sr['sr_name']);

// Save advance
if(isset($_POST['save_advance'])){
    $amount = (float)$_POST['amount'];
    $reason = trim($_POST['reason']);
    $advance_date = $_POST['advance_date'];
    
    $stmt = $conn->prepare("INSERT INTO staff_advance_salary (sr_id, shop_id, advance_date, amount, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("iisds", $sr_id, $shop_id, $advance_date, $amount, $reason);
    $stmt->execute();
    
    header("Location: staff_advance.php?sr_id=$sr_id");
    exit;
}

// Approve/Reject advance
if(isset($_GET['action']) && isset($_GET['adv_id'])){
    $adv_id = (int)$_GET['adv_id'];
    $action = $_GET['action'];
    if(in_array($action, ['approved', 'rejected', 'deducted'])){
        $stmt = $conn->prepare("UPDATE staff_advance_salary SET status = ? WHERE id = ? AND sr_id = ? AND shop_id = ?");
        $stmt->bind_param("siii", $action, $adv_id, $sr_id, $shop_id);
        $stmt->execute();
    }
    header("Location: staff_advance.php?sr_id=$sr_id");
    exit;
}

// Get all advances
$stmt = $conn->prepare("SELECT * FROM staff_advance_salary WHERE sr_id = ? AND shop_id = ? ORDER BY advance_date DESC");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$advances = $stmt->get_result();

// Stats
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM staff_advance_salary WHERE sr_id = ? AND shop_id = ?");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$total_advance = $stmt->get_result()->fetch_assoc()['t'];
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM staff_advance_salary WHERE sr_id = ? AND shop_id = ? AND status='pending'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$pending_advance = $stmt->get_result()->fetch_assoc()['t'];
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM staff_advance_salary WHERE sr_id = ? AND shop_id = ? AND status='approved'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$approved_advance = $stmt->get_result()->fetch_assoc()['t'];
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM staff_advance_salary WHERE sr_id = ? AND shop_id = ? AND status='deducted'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$deducted_advance = $stmt->get_result()->fetch_assoc()['t'];
?>

<style>
.main{flex:1; padding:20px; background:#f8fafc;}
.card-box{background:#fff; padding:15px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:15px;}
.stat-box{background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; padding:15px 20px; border-radius:12px; text-align:center;}
.stat-box h4{margin:0; font-size:24px;}
.status-pending{background:#f59e0b; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
.status-approved{background:#10b981; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
.status-rejected{background:#ef4444; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
.status-deducted{background:#3b82f6; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
</style>

<div class="main">
    <h3>💸 Advance Salary — <?= $sr_name ?></h3>
    <p class="text-muted"><a href="sr_management.php">← Back to SR List</a></p>

    <!-- Stats -->
    <div class="row mb-3">
        <div class="col-md-3"><div class="stat-box"><small>Total Advance</small><h4>৳ <?= number_format($total_advance, 2) ?></h4></div></div>
        <div class="col-md-3"><div class="stat-box" style="background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);"><small>Pending</small><h4>৳ <?= number_format($pending_advance, 2) ?></h4></div></div>
        <div class="col-md-3"><div class="stat-box" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);"><small>Approved</small><h4>৳ <?= number_format($approved_advance, 2) ?></h4></div></div>
        <div class="col-md-3"><div class="stat-box" style="background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);"><small>Deducted</small><h4>৳ <?= number_format($deducted_advance, 2) ?></h4></div></div>
    </div>

    <!-- Add Advance -->
    <div class="card-box">
        <h5>➕ Request Advance</h5>
        <form method="POST" class="row g-2">
            <div class="col-md-3">
                <label>Date</label>
                <input type="date" name="advance_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
                <label>Amount (৳)</label>
                <input type="number" step="0.01" name="amount" class="form-control" placeholder="0" required>
            </div>
            <div class="col-md-4">
                <label>Reason</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. Medical emergency">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" name="save_advance" class="btn btn-warning w-100">💾 Request</button>
            </div>
        </form>
    </div>

    <!-- Advance History -->
    <div class="card-box">
        <h5>📜 Advance History</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($a = $advances->fetch_assoc()): ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($a['advance_date'])) ?></td>
                        <td class="fw-bold">৳ <?= number_format($a['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($a['reason'] ?? '-') ?></td>
                        <td><span class="status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
                        <td>
                            <?php if($a['status'] == 'pending'): ?>
                                <a href="?sr_id=<?= $sr_id ?>&action=approved&adv_id=<?= $a['id'] ?>" class="btn btn-success btn-sm">✅ Approve</a>
                                <a href="?sr_id=<?= $sr_id ?>&action=rejected&adv_id=<?= $a['id'] ?>" class="btn btn-danger btn-sm">❌ Reject</a>
                            <?php elseif($a['status'] == 'approved'): ?>
                                <a href="?sr_id=<?= $sr_id ?>&action=deducted&adv_id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">✂️ Mark Deducted</a>
                            <?php else: ?>
                                <span class="text-muted">No action</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($advances->num_rows == 0): ?>
                    <tr><td colspan="5" class="text-center text-muted">No advance records found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>