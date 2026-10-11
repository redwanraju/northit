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

// Get SR info
$sr = $conn->query("SELECT * FROM sales_representatives WHERE id=$sr_id AND shop_id=$shop_id")->fetch_assoc();
if(!$sr){
    header("Location: sr_management.php");
    exit;
}

$sr_name = htmlspecialchars($sr['sr_name']);

// Save attendance
if(isset($_POST['save_attendance'])){
    $att_date = $_POST['attendance_date'];
    $status = $_POST['status'];
    $check_in = $_POST['check_in'] ?: null;
    $check_out = $_POST['check_out'] ?: null;
    $note = trim($_POST['note']);
    
    $stmt = $conn->prepare("INSERT INTO staff_attendance (sr_id, shop_id, attendance_date, status, check_in, check_out, note) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE status=?, check_in=?, check_out=?, note=?");
    $stmt->bind_param("iisssssssss", $sr_id, $shop_id, $att_date, $status, $check_in, $check_out, $note,
                      $status, $check_in, $check_out, $note);
    $stmt->execute();
    
    header("Location: staff_attendance.php?sr_id=$sr_id&month=" . date('Y-m', strtotime($att_date)));
    exit;
}

// Delete attendance
if(isset($_GET['delete_att'])){
    $del_date = $_GET['delete_att'];
    $stmt = $conn->prepare("DELETE FROM staff_attendance WHERE sr_id = ? AND shop_id = ? AND attendance_date = ?");
    $stmt->bind_param("iis", $sr_id, $shop_id, $del_date);
    $stmt->execute();
    header("Location: staff_attendance.php?sr_id=$sr_id&month=" . date('Y-m', strtotime($del_date)));
    exit;
}

// Month filter
$month = $_GET['month'] ?? date('Y-m');
$year = substr($month, 0, 4);
$mon = substr($month, 5, 2);

// Get attendance for the month
$attendance = [];
$stmt = $conn->prepare("SELECT * FROM staff_attendance WHERE sr_id = ? AND shop_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? ORDER BY attendance_date");
$stmt->bind_param("iis", $sr_id, $shop_id, $month);
$stmt->execute();
$att_result = $stmt->get_result();
while($a = $att_result->fetch_assoc()){
    $attendance[$a['attendance_date']] = $a;
}

// Stats
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_attendance WHERE sr_id = ? AND shop_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND status = 'present'");
$stmt->bind_param("iis", $sr_id, $shop_id, $month);
$stmt->execute();
$present = $stmt->get_result()->fetch_assoc()['c'];
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_attendance WHERE sr_id = ? AND shop_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND status = 'absent'");
$stmt->bind_param("iis", $sr_id, $shop_id, $month);
$stmt->execute();
$absent = $stmt->get_result()->fetch_assoc()['c'];
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_attendance WHERE sr_id = ? AND shop_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND status = 'half_day'");
$stmt->bind_param("iis", $sr_id, $shop_id, $month);
$stmt->execute();
$half_day = $stmt->get_result()->fetch_assoc()['c'];
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_attendance WHERE sr_id = ? AND shop_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ? AND status = 'late'");
$stmt->bind_param("iis", $sr_id, $shop_id, $month);
$stmt->execute();
$late = $stmt->get_result()->fetch_assoc()['c'];

// Days in month
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $mon, $year);
?>

<style>
.main{flex:1; padding:20px; background:#f8fafc;}
.card-box{background:#fff; padding:15px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:15px;}
.stat-box{background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; padding:15px 20px; border-radius:12px; text-align:center;}
.stat-box h4{margin:0; font-size:24px;}
.stat-box small{opacity:0.8;}
.present-box{background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important;}
.absent-box{background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%) !important;}
.half-box{background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%) !important;}
.late-box{background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%) !important;}
.attendance-day{border:1px solid #e2e8f0; border-radius:8px; padding:10px; text-align:center; min-height:100px;}
.attendance-day.present{background:#d1fae5; border-color:#10b981;}
.attendance-day.absent{background:#fee2e2; border-color:#ef4444;}
.attendance-day.half_day{background:#fef3c7; border-color:#f59e0b;}
.attendance-day.late{background:#ede9fe; border-color:#8b5cf6;}
.attendance-day .day-num{font-size:18px; font-weight:bold; color:#0f172a;}
.attendance-day .status-badge{font-size:11px; padding:2px 8px; border-radius:10px; margin-top:5px; display:inline-block;}
.status-present{background:#10b981; color:#fff;}
.status-absent{background:#ef4444; color:#fff;}
.status-half_day{background:#f59e0b; color:#fff;}
.status-late{background:#8b5cf6; color:#fff;}
</style>

<div class="main">
    <h3>📅 Attendance — <?= $sr_name ?></h3>
    <p class="text-muted">SR ID: #<?= $sr_id ?> | <a href="sr_management.php">← Back to SR List</a></p>

    <!-- Month Filter -->
    <div class="card-box">
        <form method="GET" class="row g-2">
            <input type="hidden" name="sr_id" value="<?= $sr_id ?>">
            <div class="col-md-3">
                <input type="month" name="month" class="form-control" value="<?= $month ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">📅 Show</button>
            </div>
            <div class="col-md-2">
                <a href="staff_attendance.php?sr_id=<?= $sr_id ?>" class="btn btn-secondary w-100">🔄 Current Month</a>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <div class="row mb-3">
        <div class="col-md-3"><div class="stat-box present-box"><small>Present</small><h4><?= $present ?></h4></div></div>
        <div class="col-md-3"><div class="stat-box absent-box"><small>Absent</small><h4><?= $absent ?></h4></div></div>
        <div class="col-md-3"><div class="stat-box half-box"><small>Half Day</small><h4><?= $half_day ?></h4></div></div>
        <div class="col-md-3"><div class="stat-box late-box"><small>Late</small><h4><?= $late ?></h4></div></div>
    </div>

    <!-- Add Attendance -->
    <div class="card-box">
        <h5>➕ Add/Update Attendance</h5>
        <form method="POST" class="row g-2">
            <div class="col-md-2">
                <label>Date</label>
                <input type="date" name="attendance_date" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label>Status</label>
                <select name="status" class="form-control" required>
                    <option value="present">✅ Present</option>
                    <option value="absent">❌ Absent</option>
                    <option value="half_day">⏳ Half Day</option>
                    <option value="late">🕐 Late</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>Check In</label>
                <input type="time" name="check_in" class="form-control">
            </div>
            <div class="col-md-2">
                <label>Check Out</label>
                <input type="time" name="check_out" class="form-control">
            </div>
            <div class="col-md-2">
                <label>Note</label>
                <input type="text" name="note" class="form-control" placeholder="Optional">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" name="save_attendance" class="btn btn-success w-100">💾 Save</button>
            </div>
        </form>
    </div>

    <!-- Calendar View -->
    <div class="card-box">
        <h5>📆 <?= date('F Y', strtotime($month . '-01')) ?></h5>
        <div class="row g-2">
            <?php for($d=1; $d<=$days_in_month; $d++): 
                $date_str = sprintf("%s-%02d-%02d", $year, $mon, $d);
                $day_name = date('D', strtotime($date_str));
                $att = $attendance[$date_str] ?? null;
                $status_class = $att ? $att['status'] : '';
                $is_weekend = ($day_name == 'Fri' || $day_name == 'Sat');
            ?>
            <div class="col-md-2 col-4">
                <div class="attendance-day <?= $status_class ?> <?= $is_weekend ? 'bg-light' : '' ?>">
                    <div class="day-num"><?= $d ?></div>
                    <small class="text-muted"><?= $day_name ?></small>
                    <?php if($att): ?>
                        <br><span class="status-badge status-<?= $att['status'] ?>"><?= ucwords(str_replace('_', ' ', $att['status'])) ?></span>
                        <?php if($att['check_in']): ?><br><small class="text-muted"><?= substr($att['check_in'], 0, 5) ?> - <?= $att['check_out'] ? substr($att['check_out'], 0, 5) : '?' ?></small><?php endif; ?>
                        <br><a href="?sr_id=<?= $sr_id ?>&delete_att=<?= $date_str ?>" class="text-danger" onclick="return confirm('Delete this record?')"><small>🗑</small></a>
                    <?php elseif($is_weekend): ?>
                        <br><span class="badge bg-secondary">Weekend</span>
                    <?php else: ?>
                        <br><span class="badge bg-light text-muted">No Entry</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<?php include "footer.php"; ?>