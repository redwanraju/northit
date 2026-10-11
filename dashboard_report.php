<?php
session_start();
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);


// ============================================================
// SECURITY CHECK
// ============================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Only admin can view this report
if (($_SESSION['role'] ?? 'sr') !== 'admin') {
    $_SESSION['rv_dashboard_flash'] = ['type' => 'error', 'message' => 'You do not have permission to view this report.'];
    header("Location: ../index.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

include "../config/db.php";

$shop_id = tenantShopId();

// ============================================================
// DATE FILTER
// ============================================================
$from_date = isset($_GET['from']) && $_GET['from'] != '' ? $_GET['from'] : date('Y-m-d');
$to_date = isset($_GET['to']) && $_GET['to'] != '' ? $_GET['to'] : date('Y-m-d');

// Validate dates
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) ? $from_date : date('Y-m-d');
$to_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date) ? $to_date : date('Y-m-d');

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function getReportData($conn, $from, $to, $shop_id) {
    $data = [];
    
    // 1. SALES SUMMARY
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as net_sales,
            COALESCE(SUM(delivery_cost), 0) as delivery_cost,
            COALESCE(SUM(total_amount + delivery_cost), 0) as total_sales,
            COALESCE(SUM(paid_amount), 0) as payment_received,
            COALESCE(SUM(due_amount), 0) as due_amount,
            COUNT(id) as total_invoices
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND shop_id = ?
        AND order_source = 'pos'
        AND order_status != 'cancelled'
    ");
    $stmt->bind_param("ssi", $from, $to, $shop_id);
    $stmt->execute();
    $data['sales'] = $stmt->get_result()->fetch_assoc();
    
    // 2. COLLECTION SUMMARY
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'customer_due_receive' THEN amount ELSE 0 END), 0) as due_collection,
            COALESCE(SUM(CASE WHEN type = 'advance_payment' THEN amount ELSE 0 END), 0) as advance_collection,
            COALESCE(SUM(amount), 0) as total_collection
        FROM payments p
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN users u ON u.id = p.created_by AND u.shop_id = ?
        WHERE DATE(p.created_at) BETWEEN ? AND ?
        AND (p.customer_id IS NOT NULL OR p.supplier_id IS NULL)
        AND (c.shop_id = ? OR (c.id IS NULL AND u.shop_id = ?))
    ");
    $stmt->bind_param("issii", $shop_id, $from, $to, $shop_id, $shop_id);
    $stmt->execute();
    $data['collection'] = $stmt->get_result()->fetch_assoc();
    
    // 3. INVOICE COLLECTION (from orders)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(paid_amount), 0) as invoice_collection
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND shop_id = ?
        AND order_source = 'pos'
        AND order_status != 'cancelled'
        AND payment_status = 'paid'
    ");
    $stmt->bind_param("ssi", $from, $to, $shop_id);
    $stmt->execute();
    $invoice_collection = $stmt->get_result()->fetch_assoc();
    $data['collection']['invoice_collection'] = $invoice_collection['invoice_collection'] ?? 0;
    
    // 4. EXPENSE SUMMARY
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_expense,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_expense,
            COALESCE(SUM(CASE WHEN status = 'due' THEN amount ELSE 0 END), 0) as due_expense
        FROM expenses 
        WHERE DATE(expense_date) BETWEEN ? AND ?
        AND shop_id = ?
    ");
    $stmt->bind_param("ssi", $from, $to, $shop_id);
    $stmt->execute();
    $data['expense'] = $stmt->get_result()->fetch_assoc();
    
    // 5. PURCHASE SUMMARY - FIXED: shop_id removed
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(grand_total), 0) as total_purchase,
            COALESCE(SUM(paid_amount), 0) as paid_purchase,
            COALESCE(SUM(grand_total - paid_amount), 0) as due_purchase
        FROM purchases p
        INNER JOIN suppliers s ON s.id = p.supplier_id
        WHERE DATE(p.created_at) BETWEEN ? AND ?
        AND s.shop_id = ?
    ");
    $stmt->bind_param("ssi", $from, $to, $shop_id);
    $stmt->execute();
    $data['purchase'] = $stmt->get_result()->fetch_assoc();
    
    // 6. SALES PROFIT
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(oi.subtotal), 0) as total_sales,
            COALESCE(SUM(oi.qty * oi.cost_price), 0) as total_cost,
            COALESCE(SUM(oi.subtotal - (oi.qty * oi.cost_price)), 0) as sales_profit
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        AND o.shop_id = ?
        AND o.order_source = 'pos'
        AND o.order_status != 'cancelled'
    ");
    $stmt->bind_param("ssi", $from, $to, $shop_id);
    $stmt->execute();
    $data['profit'] = $stmt->get_result()->fetch_assoc();
    
    // 7. ACCOUNT BALANCE (Assets)
    $stmt = $conn->prepare("
        SELECT 
            id,
            asset_type,
            asset_name,
            amount as balance,
            note
        FROM assets 
        WHERE shop_id = ?
        AND status = 'active' 
        AND asset_type IN ('cash', 'bkash', 'nagad', 'bank')
        ORDER BY FIELD(asset_type, 'cash', 'bank', 'bkash', 'nagad'), asset_name ASC
    ");
    $stmt->bind_param("i", $shop_id);
    $stmt->execute();
    $data['assets'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // 8. NET PROFIT CALCULATION
    $data['net_profit'] = [
        'sales_profit' => $data['profit']['sales_profit'] ?? 0,
        'expense' => $data['expense']['total_expense'] ?? 0,
        'net_profit' => ($data['profit']['sales_profit'] ?? 0) - ($data['expense']['total_expense'] ?? 0)
    ];
    
    return $data;
}

// Get report data
$report = getReportData($conn, $from_date, $to_date, $shop_id);

// ============================================================
// ASSET TYPE LABELS
// ============================================================
function assetLabel($type) {
    $labels = [
        'cash' => '💵 Cash',
        'bkash' => '📱 bKash',
        'nagad' => '📱 Nagad',
        'bank' => '🏦 Bank',
        'other' => '📦 Other'
    ];
    return $labels[$type] ?? ucfirst($type);
}

function assetIcon($type) {
    $icons = [
        'cash' => 'fa-money-bill-wave',
        'bkash' => 'fa-mobile-alt',
        'nagad' => 'fa-mobile-alt',
        'bank' => 'fa-university',
        'other' => 'fa-box'
    ];
    return $icons[$type] ?? 'fa-wallet';
}

include "header.php";
include "sidebar.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== MAIN LAYOUT ===== */
        .main {
            padding: 20px;
            background: #f1f5f9;
            min-height: 100vh;
            width: 100%;
            flex: 1;
        }

        /* ===== REPORT HEADER ===== */
        .report-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 16px;
            padding: 25px 30px;
            color: #fff;
            margin-bottom: 25px;
        }
        .report-header h2 {
            font-weight: 700;
            margin: 0;
        }
        .report-header .subtitle {
            opacity: 0.8;
            font-size: 14px;
        }

        /* ===== FILTER SECTION ===== */
        .filter-section {
            background: #fff;
            border-radius: 16px;
            padding: 20px 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }
        .filter-section .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #475569;
            margin-bottom: 4px;
        }
        .filter-section .form-control, 
        .filter-section .form-select {
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 8px 14px;
            font-size: 14px;
        }
        .filter-section .form-control:focus,
        .filter-section .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* ===== STATS CARDS ===== */
        .stat-card {
            border-radius: 16px;
            padding: 20px 22px;
            color: #fff;
            height: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }
        .stat-card .stat-icon {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 32px;
            opacity: 0.25;
        }
        .stat-card .stat-label {
            font-size: 13px;
            opacity: 0.9;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .stat-value {
            font-size: 26px;
            font-weight: 700;
            margin-top: 4px;
        }
        .stat-card .stat-sub {
            font-size: 13px;
            opacity: 0.85;
            margin-top: 4px;
        }

        .bg-gradient-blue { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .bg-gradient-green { background: linear-gradient(135deg, #059669, #10b981); }
        .bg-gradient-red { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .bg-gradient-purple { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .bg-gradient-dark { background: linear-gradient(135deg, #0f172a, #1e293b); }
        .bg-gradient-amber { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .bg-gradient-cyan { background: linear-gradient(135deg, #0891b2, #06b6d4); }
        .bg-gradient-pink { background: linear-gradient(135deg, #db2777, #ec4899); }
        .bg-gradient-indigo { background: linear-gradient(135deg, #4338ca, #6366f1); }

        /* ===== SECTION CARDS ===== */
        .section-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            height: 100%;
        }
        .section-card .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }
        .section-card .section-title i {
            margin-right: 8px;
            color: #2563eb;
        }

        /* ===== DETAIL ROWS ===== */
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-row .label {
            color: #475569;
            font-size: 14px;
        }
        .detail-row .value {
            font-weight: 600;
            font-size: 15px;
            color: #0f172a;
        }
        .detail-row .value.text-success { color: #059669; }
        .detail-row .value.text-danger { color: #dc2626; }
        .detail-row .value.text-warning { color: #d97706; }
        .detail-row .value.text-primary { color: #2563eb; }

        /* ===== ACCOUNT TABLE ===== */
        .account-table {
            width: 100%;
            font-size: 14px;
        }
        .account-table thead th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 10px 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        .account-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .account-table tbody tr:hover {
            background: #f8fafc;
        }
        .account-table .asset-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 14px;
        }
        .asset-icon-cash { background: #059669; }
        .asset-icon-bank { background: #2563eb; }
        .asset-icon-bkash { background: #dc2626; }
        .asset-icon-nagad { background: #d97706; }
        .asset-icon-other { background: #7c3aed; }
        .asset-icon-other { background: #6b7280; }

        /* ===== PRINT BUTTON ===== */
        .btn-print {
            background: #0f172a;
            color: #fff;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
            border: none;
            transition: all 0.2s;
        }
        .btn-print:hover {
            background: #1e293b;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3);
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            .sidebar, .header, .no-print, .filter-section, .btn-print {
                display: none !important;
            }
            .main {
                padding: 10px !important;
                background: #fff !important;
            }
            .stat-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .section-card {
                break-inside: avoid;
                page-break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #e2e8f0;
            }
            .report-header {
                background: #0f172a !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .stat-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main {
                padding: 12px;
            }
            .report-header {
                padding: 18px 20px;
            }
            .report-header h2 {
                font-size: 20px;
            }
            .stat-card .stat-value {
                font-size: 20px;
            }
            .section-card {
                padding: 15px 16px;
            }
            .filter-section {
                padding: 15px 16px;
            }
        }
    </style>
</head>
<body>

<div class="main">

    <!-- ============================================================ -->
    <!-- REPORT HEADER -->
    <!-- ============================================================ -->
    <div class="report-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2><i class="fas fa-chart-pie me-2"></i> Dashboard Report</h2>
            <div class="subtitle">
                <i class="far fa-calendar-alt me-1"></i> 
                <?= date('d-m-Y', strtotime($from_date)) ?> to <?= date('d-m-Y', strtotime($to_date)) ?>
                <span class="mx-2">|</span>
                <i class="far fa-building me-1"></i> Shop ID: <?= $shop_id ?>
            </div>
        </div>
        <div>
            <button onclick="window.print()" class="btn-print">
                <i class="fas fa-print me-2"></i> Print Report
            </button>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- FILTER SECTION -->
    <!-- ============================================================ -->
    <div class="filter-section no-print">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label"><i class="far fa-calendar-alt me-1"></i> From Date</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label"><i class="far fa-calendar-alt me-1"></i> To Date</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-2"></i> Generate Report
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================================ -->
    <!-- STATS CARDS - ROW 1 (Sales Summary) -->
    <!-- ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="stat-card bg-gradient-blue">
                <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-label">Total Invoices</div>
                <div class="stat-value"><?= number_format($report['sales']['total_invoices'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card bg-gradient-dark">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-label">Net Sales</div>
                <div class="stat-value">৳ <?= number_format($report['sales']['net_sales'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card bg-gradient-amber">
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
                <div class="stat-label">Delivery Cost</div>
                <div class="stat-value">৳ <?= number_format($report['sales']['delivery_cost'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card bg-gradient-green">
                <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="stat-label">Total Sales</div>
                <div class="stat-value">৳ <?= number_format($report['sales']['total_sales'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card bg-gradient-purple">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Payment Received</div>
                <div class="stat-value">৳ <?= number_format($report['sales']['payment_received'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card bg-gradient-red">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Due Amount</div>
                <div class="stat-value">৳ <?= number_format($report['sales']['due_amount'] ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- STATS CARDS - ROW 2 (Collection & Expense) -->
    <!-- ============================================================ -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card bg-gradient-indigo">
                <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-label">Invoice Collection</div>
                <div class="stat-value">৳ <?= number_format($report['collection']['invoice_collection'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card bg-gradient-green">
                <div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div>
                <div class="stat-label">Due Collection</div>
                <div class="stat-value">৳ <?= number_format($report['collection']['due_collection'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card bg-gradient-cyan">
                <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="stat-label">Advance Collection</div>
                <div class="stat-value">৳ <?= number_format($report['collection']['advance_collection'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card bg-gradient-red">
                <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                <div class="stat-label">Total Expense</div>
                <div class="stat-value">৳ <?= number_format($report['expense']['total_expense'] ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- DETAIL SECTIONS -->
    <!-- ============================================================ -->
    <div class="row g-3 mb-4">

        <!-- Collection Details -->
        <div class="col-12 col-md-6">
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-hand-holding-usd"></i> Collection Summary
                </div>
                <div class="detail-row">
                    <span class="label"><i class="far fa-file-invoice me-2 text-primary"></i> Invoice Collection</span>
                    <span class="value">৳ <?= number_format($report['collection']['invoice_collection'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-hand-holding-heart me-2 text-success"></i> Due Collection</span>
                    <span class="value text-success">৳ <?= number_format($report['collection']['due_collection'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-arrow-up me-2 text-cyan"></i> Advance Collection</span>
                    <span class="value text-primary">৳ <?= number_format($report['collection']['advance_collection'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row" style="border-bottom: 2px solid #e2e8f0; padding-top: 12px; margin-top: 4px;">
                    <span class="label fw-bold"><i class="fas fa-calculator me-2"></i> Total Collection</span>
                    <span class="value text-success fw-bold">৳ <?= number_format($report['collection']['total_collection'] ?? 0, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Expense & Purchase Details -->
        <div class="col-12 col-md-6">
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-receipt"></i> Expense & Purchase Summary
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-receipt me-2 text-danger"></i> Total Expense</span>
                    <span class="value text-danger">৳ <?= number_format($report['expense']['total_expense'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-check-circle me-2 text-success"></i> Paid Expense</span>
                    <span class="value text-success">৳ <?= number_format($report['expense']['paid_expense'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-clock me-2 text-warning"></i> Due Expense</span>
                    <span class="value text-warning">৳ <?= number_format($report['expense']['due_expense'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row" style="border-bottom: 2px solid #e2e8f0; padding-top: 12px; margin-top: 4px;">
                    <span class="label fw-bold"><i class="fas fa-shopping-cart me-2"></i> Total Purchase</span>
                    <span class="value text-primary fw-bold">৳ <?= number_format($report['purchase']['total_purchase'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-check-circle me-2 text-success"></i> Purchase Paid</span>
                    <span class="value text-success">৳ <?= number_format($report['purchase']['paid_purchase'] ?? 0, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-clock me-2 text-warning"></i> Purchase Due</span>
                    <span class="value text-warning">৳ <?= number_format($report['purchase']['due_purchase'] ?? 0, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Profit Summary -->
        <div class="col-12">
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i> Profit & Loss Summary
                </div>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 text-center">
                            <div class="text-muted small fw-bold">Total Sales</div>
                            <div class="fs-4 fw-bold text-primary">৳ <?= number_format($report['profit']['total_sales'] ?? 0, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 text-center">
                            <div class="text-muted small fw-bold">Total Cost</div>
                            <div class="fs-4 fw-bold text-warning">৳ <?= number_format($report['profit']['total_cost'] ?? 0, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 text-center">
                            <div class="text-muted small fw-bold">Sales Profit</div>
                            <div class="fs-4 fw-bold text-success">৳ <?= number_format($report['net_profit']['sales_profit'] ?? 0, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 text-center">
                            <div class="text-muted small fw-bold">Net Profit</div>
                            <div class="fs-4 fw-bold <?= ($report['net_profit']['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                                ৳ <?= number_format($report['net_profit']['net_profit'] ?? 0, 2) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Balance (Assets) -->
        <div class="col-12">
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-wallet"></i> Account Balance Summary
                </div>
                <div class="table-responsive">
                    <table class="account-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Asset Type</th>
                                <th>Account Name</th>
                                <th class="text-end">Balance (৳)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_balance = 0;
                            $counter = 1;
                            if (!empty($report['assets'])): 
                                foreach ($report['assets'] as $asset): 
                                    $total_balance += (float)$asset['balance'];
                                    $icon_class = 'asset-icon-' . $asset['asset_type'];
                            ?>
                                <tr>
                                    <td><?= $counter++ ?></td>
                                    <td>
                                        <span class="asset-icon <?= $icon_class ?>">
                                            <i class="fas <?= assetIcon($asset['asset_type']) ?>"></i>
                                        </span>
                                        <span class="ms-2 fw-medium"><?= assetLabel($asset['asset_type']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($asset['asset_name'] ?: 'Main Account') ?></td>
                                    <td class="text-end fw-bold">৳ <?= number_format((float)$asset['balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; 
                            else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">
                                        <i class="fas fa-info-circle me-1"></i> No active assets found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end fw-bold">Total Balance</td>
                                <td class="text-end fw-bold text-success">৳ <?= number_format($total_balance, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- FOOTER -->
    <!-- ============================================================ -->
    <div class="text-center text-muted small py-3 no-print">
        <i class="far fa-clock me-1"></i> Report generated on <?= date('d-m-Y h:i A') ?> 
        <span class="mx-2">|</span>
        <i class="fas fa-print me-1"></i> Click Print to save as PDF
    </div>

</div>

<?php include "footer.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>