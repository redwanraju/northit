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

// Save leave
if(isset($_POST['save_leave'])){
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = trim($_POST['reason']);
    
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $diff = $start->diff($end);
    $total_days = $diff->days + 1;
    
    $stmt = $conn->prepare("INSERT INTO staff_leaves (sr_id, shop_id, leave_type, start_date, end_date, total_days, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("iisssis", $sr_id, $shop_id, $leave_type, $start_date, $end_date, $total_days, $reason);
    $stmt->execute();
    
    header("Location: staff_leave.php?sr_id=$sr_id");
    exit;
}

// Approve/Reject leave
if(isset($_GET['action']) && isset($_GET['leave_id'])){
    $leave_id = (int)$_GET['leave_id'];
    $action = $_GET['action'];
    if(in_array($action, ['approved', 'rejected'])){
        $stmt = $conn->prepare("UPDATE staff_leaves SET status = ?, approved_at = NOW() WHERE id = ? AND sr_id = ? AND shop_id = ?");
        $stmt->bind_param("siii", $action, $leave_id, $sr_id, $shop_id);
        $stmt->execute();
    }
    header("Location: staff_leave.php?sr_id=$sr_id");
    exit;
}

// Get all leaves
$stmt = $conn->prepare("SELECT * FROM staff_leaves WHERE sr_id = ? AND shop_id = ? ORDER BY start_date DESC");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$leaves = $stmt->get_result();

// Stats
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_days), 0) AS t FROM staff_leaves WHERE sr_id = ? AND shop_id = ? AND status='approved'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$total_leaves = $stmt->get_result()->fetch_assoc()['t'];
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_days), 0) AS t FROM staff_leaves WHERE sr_id = ? AND shop_id = ? AND status='approved' AND leave_type='casual'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$casual = $stmt->get_result()->fetch_assoc()['t'];
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_days), 0) AS t FROM staff_leaves WHERE sr_id = ? AND shop_id = ? AND status='approved' AND leave_type='sick'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$sick = $stmt->get_result()->fetch_assoc()['t'];
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_days), 0) AS t FROM staff_leaves WHERE sr_id = ? AND shop_id = ? AND status='approved' AND leave_type='annual'");
$stmt->bind_param("ii", $sr_id, $shop_id);
$stmt->execute();
$annual = $stmt->get_result()->fetch_assoc()['t'];
?>

<style>
.main{flex:1; padding:20px; background:#f8fafc;}
.card-box{background:#fff; padding:15px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:15px;}
.stat-box{background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; padding:15px 20px; border-radius:12px; text-align:center;}
.stat-box h4{margin:0; font-size:24px;}
.status-pending{background:#f59e0b; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
.status-approved{background:#10b981; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
.status-rejected{background:#ef4444; color:#fff; padding:4px 10px; border-radius:20px; font-size:12px;}
</style>

<div class="main">
    <h3>🏖️ Leave Management — <?= $sr_name ?></h3>
    <p class="text-muted"><a href="sr_management.php">← Back to SR List</a></p>

    <!-- Stats -->
    <div class="row mb-3">
        <div class="col-md-3"><div class="stat-box"><small>Total Approved Leaves</small><h4><?= $total_leaves ?> Days</h4></div></div>
        <div class="col-md-3"><div class="stat-box" style="background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);"><small>Casual</small><h4><?= $casual ?> Days</h4></div></div>
        <div class="col-md-3"><div class="stat-box" style="background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);"><small>Sick</small><h4><?= $sick ?> Days</h4></div></div>
        <div class="col-md-3"><div class="stat-box" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);"><small>Annual</small><h4><?= $annual ?> Days</h4></div></div>
    </div>

    <!-- Apply Leave -->
    <div class="card-box">
        <h5>➕ Apply for Leave</h5>
        <form method="POST" class="row g-2">
            <div class="col-md-2">
                <label>Leave Type</label>
                <select name="leave_type" class="form-control" required>
                    <option value="casual">🏖️ Casual</option>
                    <option value="sick">🤒 Sick</option>
                    <option value="annual">📅 Annual</option>
                    <option value="unpaid">💔 Unpaid</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>Start Date</label>
                <input type="date" name="start_date" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>End Date</label>
                <input type="date" name="end_date" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>Reason</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. Family event">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" name="save_leave" class="btn btn-success w-100">💾 Apply</button>
            </div>
        </form>
    </div>

    <!-- Leave History -->
    <div class="card-box">
        <h5>📜 Leave History</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($l = $leaves->fetch_assoc()): ?>
                    <tr>
                        <td><span class="badge bg-<?= $l['leave_type']=='casual'?'info':($l['leave_type']=='sick'?'danger':($l['leave_type']=='annual'?'success':'secondary')) ?>">
                            <?= ucfirst($l['leave_type']) ?>
                        </span></td>
                        <td><?= date('d M Y', strtotime($l['start_date'])) ?></td>
                        <td><?= date('d M Y', strtotime($l['end_date'])) ?></td>
                        <td class="fw-bold"><?= $l['total_days'] ?> Days</td>
                        <td><?= htmlspecialchars($l['reason'] ?? '-') ?></td>
                        <td><span class="status-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span></td>
                        <td><?= date('d M Y', strtotime($l['created_at'])) ?></td>
                        <td>
                            <?php if($l['status'] == 'pending'): ?>
                                <a href="?sr_id=<?= $sr_id ?>&action=approved&leave_id=<?= $l['id'] ?>" class="btn btn-success btn-sm">✅ Approve</a>
                                <a href="?sr_id=<?= $sr_id ?>&action=rejected&leave_id=<?= $l['id'] ?>" class="btn btn-danger btn-sm">❌ Reject</a>
                            <?php else: ?>
                                <span class="text-muted">Decided</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if($leaves->num_rows == 0): ?>
                    <tr><td colspan="8" class="text-center text-muted">No leave records found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>