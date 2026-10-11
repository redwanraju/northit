<?php
$current = basename($_SERVER['PHP_SELF']);
?>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.1/css/all.min.css"/>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

:root {
    --sidebar-bg: #0f172a;
    --sidebar-hover: #1e293b;
    --active-gradient-start: #2563eb;
    --active-gradient-end: #7c3aed;
    --text-primary: #e2e8f0;
    --text-muted: #94a3b8;
    --text-white: #ffffff;
    --danger: #ef4444;
    --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    --radius: 16px;
    --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

body {
    background: #f1f5f9;
    margin: 0;
    padding: 0;
}

/* ========== SIDEBAR TOGGLE BUTTON ========== */
.sidebar-toggle {
    position: fixed;
    left: 20px;
    top: 20px;
    z-index: 1001;
    background: linear-gradient(135deg, var(--active-gradient-start), var(--active-gradient-end));
    color: #fff;
    border: none;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    cursor: pointer;
    font-size: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
    box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.4);
}

.sidebar-toggle:hover {
    transform: scale(1.08) rotate(-4deg);
    box-shadow: 0 15px 35px -5px rgba(37, 99, 235, 0.5);
}

/* ========== SIDEBAR OVERLAY ========== */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 998;
    opacity: 0;
    visibility: hidden;
    transition: all var(--transition);
}

.sidebar-overlay.show {
    opacity: 1;
    visibility: visible;
}

/* ========== SIDEBAR ========== */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 280px;
    height: 100vh;
    background: var(--sidebar-bg);
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: var(--shadow);
    z-index: 999;
    padding-bottom: 30px;
    transform: translateX(-100%);
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    scrollbar-width: thin;
    scrollbar-color: #334155 transparent;
}

.sidebar.open {
    transform: translateX(0);
}

/* Custom Scrollbar */
.sidebar::-webkit-scrollbar {
    width: 5px;
}

.sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.sidebar::-webkit-scrollbar-thumb {
    background: #334155;
    border-radius: 50px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: #475569;
}

/* ========== BRAND ========== */


.brand::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    transform: scale(1.5);
}

.brand h3 {
    font-size: 26px;
    font-weight: 800;
    color: var(--text-white);
    margin-bottom: 4px;
    letter-spacing: -0.5px;
    position: relative;
    z-index: 1;
}

.brand small {
    color: rgba(255, 255, 255, 0.8);
    font-size: 13px;
    font-weight: 400;
    letter-spacing: 0.3px;
    position: relative;
    z-index: 1;
}

/* ========== USER CARD ========== */
.user-card {
    margin: 20px 16px 24px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: var(--radius);
    padding: 20px 16px;
    text-align: center;
    transition: var(--transition);
}

.user-card:hover {
    background: rgba(255, 255, 255, 0.07);
    border-color: rgba(255, 255, 255, 0.1);
}

.user-card img {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.15);
    margin-bottom: 12px;
    object-fit: cover;
    transition: var(--transition);
}

.user-card:hover img {
    border-color: var(--active-gradient-start);
    transform: scale(1.05);
}

.user-card h5 {
    color: var(--text-white);
    margin-bottom: 2px;
    font-size: 17px;
    font-weight: 600;
}

.user-card span {
    color: var(--text-muted);
    font-size: 13px;
    display: block;
}

/* ========== MENU ========== */
.menu-section {
    margin: 4px 12px 8px;
}

.menu-section .menu-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #475569;
    padding: 8px 14px 4px;
    margin-top: 8px;
}

.menu-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    text-decoration: none;
    padding: 12px 16px;
    border-radius: 12px;
    color: var(--text-primary);
    font-size: 14.5px;
    font-weight: 500;
    transition: all var(--transition);
    cursor: pointer;
    position: relative;
    gap: 6px;
}

.menu-item .left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
}

.menu-item .left i {
    width: 22px;
    text-align: center;
    font-size: 17px;
    flex-shrink: 0;
    transition: var(--transition);
}

.menu-item .left span {
    transition: var(--transition);
}

.menu-item .badge {
    background: var(--active-gradient-start);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 50px;
    letter-spacing: 0.3px;
    line-height: 18px;
}

.menu-item .arrow {
    transition: transform var(--transition);
    font-size: 12px;
    color: var(--text-muted);
    flex-shrink: 0;
}

.menu-item:hover {
    background: var(--sidebar-hover);
    color: var(--text-white);
    transform: translateX(4px);
}

.menu-item:hover .left i {
    color: var(--active-gradient-start);
}

.menu-item.active {
    background: linear-gradient(135deg, var(--active-gradient-start), var(--active-gradient-end));
    color: var(--text-white);
    box-shadow: 0 8px 20px -4px rgba(37, 99, 235, 0.35);
    transform: none !important;
}

.menu-item.active .left i {
    color: var(--text-white);
}

.menu-item.active .arrow {
    color: rgba(255, 255, 255, 0.7);
}

/* Submenu */
.submenu {
    display: none;
    margin: 2px 0 4px 18px;
    border-left: 2px solid rgba(255, 255, 255, 0.06);
    padding-left: 12px;
    animation: slideDown 0.25s ease forwards;
}

.submenu.show {
    display: block;
}

.submenu .menu-item {
    padding: 10px 14px;
    font-size: 13.5px;
    font-weight: 400;
    color: var(--text-muted);
    border-radius: 10px;
}

.submenu .menu-item .left {
    gap: 12px;
}

.submenu .menu-item .left i {
    font-size: 14px;
    width: 18px;
}

.submenu .menu-item:hover {
    background: var(--sidebar-hover);
    color: var(--text-white);
    transform: translateX(6px);
}

.submenu .menu-item.active {
    background: rgba(37, 99, 235, 0.15);
    color: var(--text-white);
    box-shadow: none;
}

.submenu .menu-item.active .left i {
    color: var(--active-gradient-start);
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ========== DIVIDER ========== */
.menu-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
    margin: 8px 16px;
}

/* ========== LOGOUT ========== */
.menu-item.logout {
    color: var(--danger);
    margin-top: 4px;
}

.menu-item.logout:hover {
    background: rgba(239, 68, 68, 0.08);
    color: #f87171;
}

.menu-item.logout .left i {
    color: var(--danger);
}

/* ========== RESPONSIVE ========== */
@media (max-width: 768px) {
    .sidebar {
        width: 280px;
    }
    .sidebar-toggle {
        left: 12px;
        top: 12px;
        width: 42px;
        height: 42px;
        font-size: 17px;
    }
}
</style>

<!-- Toggle Button -->
<button class="sidebar-toggle" onclick="toggleSidebar()" title="Menu">
    <i class="fas fa-bars" id="toggleIcon"></i>
</button>

<!-- Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">

    <!-- User Card -->


    <!-- ================= DASHBOARD ================= -->
    <div class="menu-section">
        <div class="menu-item <?= $current == 'index.php' ? 'active' : '' ?>">
            <a href="/pos/index.php" style="display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit;width:100%;">
                <i class="fas fa-gauge-high"></i><span>Dashboard</span>
            </a>
        </div>
    </div>

    <div class="menu-divider"></div>

    <!-- ================= SALES ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-cash-register"></i><span>Sales</span></div>
            
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
            <a href="/pos/pos.php" class="menu-item <?= $current == 'pos.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-cart-plus"></i><span>POS Sale</span></div>
            </a>
            <a href="/pos/sales_list.php" class="menu-item <?= $current == 'sales_list.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-clipboard-list"></i><span>Sales List</span></div>
            </a>
        </div>
    </div>

    <!-- ================= CUSTOMERS ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-user-group"></i><span>Customers</span></div>
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
            <a href="/pos/customer.php" class="menu-item <?= $current == 'customer.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-user-plus"></i><span>Manage Customer</span></div>
            </a>
        </div>
    </div>

    <!-- ================= PRODUCTS ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-box-open"></i><span>Products</span></div>
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
            <a href="/pos/products.php" class="menu-item <?= $current == 'products.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-plus-circle"></i><span>Manage Product</span></div>
            </a>
            <a href="/pos/category.php" class="menu-item <?= $current == 'category.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-folder"></i><span>Categories</span></div>
            </a>
            <a href="/pos/brand_management.php" class="menu-item <?= $current == 'brand_management.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-tags"></i><span>Brands</span></div>
            </a>
        </div>
    </div>

    <!-- ================= SUPPLIERS ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-truck"></i><span>Suppliers</span></div>
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
            <a href="/pos/suppliers/supplier_management.php" class="menu-item <?= $current == 'supplier_management.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-user-plus"></i><span>Manage Supplier</span></div>
            </a>
        </div>
    </div>

    <!-- ================= PURCHASE ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-cart-shopping"></i><span>Purchase</span></div>
            
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
            <a href="/pos/purchase/add_purchase.php" class="menu-item <?= $current == 'add_purchase.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-plus-circle"></i><span>Add Purchase</span></div>
            </a>
            <a href="/pos/purchase/purchase_list.php" class="menu-item <?= $current == 'purchase_list.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-list"></i><span>Purchase List</span></div>
            </a>
            <a href="/pos/purchase_return.php" class="menu-item <?= $current == 'purchase_return.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-rotate-left"></i><span>Purchase Return</span></div>
            </a>
        </div>
    </div>

    <!-- ================= ACCOUNTS ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-book"></i><span>Accounts</span></div>
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
            <a href="asst_mngment.php" class="menu-item <?= $current == '' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-rotate-left"></i><span>Asst Mngment</span></div>
            </a>
            <!--
            <a href="/pos/accounts/journal_entry.php" class="menu-item <?= $current == 'journal_entry.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-pen-to-square"></i><span>Journal Entry</span></div>
            </a>
            <a href="/pos/accounts/ledger.php" class="menu-item <?= $current == 'ledger.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-file-invoice-dollar"></i><span>Ledger</span></div>
            </a>
            <a href="/pos/accounts/trial_balance.php" class="menu-item <?= $current == 'trial_balance.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-scale-balanced"></i><span>Trial Balance</span></div>
            </a>
            <a href="/pos/accounts/balance_sheet.php" class="menu-item <?= $current == 'balance_sheet.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-chart-pie"></i><span>Balance Sheet</span></div>
            </a>
            <a href="/pos/accounts/profit_loss.php" class="menu-item <?= $current == 'profit_loss.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-chart-bar"></i><span>Profit & Loss</span></div>
            </a>
            <a href="/pos/accounts/cash_flow.php" class="menu-item <?= $current == 'cash_flow.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-money-bill-transfer"></i><span>Cash Flow</span></div>
            </a>
			-->
        </div>
    </div>

    <!-- ================= ADMIN ================= -->
    <div class="menu-section">
        <div class="menu-item" onclick="toggleMenu(this);">
            <div class="left"><i class="fas fa-user-shield"></i><span>Admin Area</span></div>
            <i class="fas fa-chevron-right arrow"></i>
        </div>
        <div class="submenu">
		<a href="/pos/site/today_online_overview.php" class="menu-item <?= $current == 'site_management.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-sitemap"></i><span>E-COMMERS</span></div>
            </a>
            <a href="/pos/user_management.php" class="menu-item <?= $current == 'user_management.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-user-plus"></i><span>Manage User</span></div>
            </a>
            <a href="/pos/page_management.php" class="menu-item <?= $current == 'page_management.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-user-lock"></i><span>Role & Permission</span></div>
            </a>
			<!--
            <a href="#" class="menu-item <?= $current == 'activity_log.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-clock-rotate-left"></i><span>Pages Log</span></div>
            </a>
            <a href="/pos/admin/login_history.php" class="menu-item <?= $current == 'login_history.php' ? 'active' : '' ?>">
                <div class="left"><i class="fas fa-shield-halved"></i><span>Login History</span></div>
            </a>
			-->
        </div>
    </div>

    <div class="menu-divider"></div>

    <!-- ================= OTHER MENUS ================= -->
    <div class="menu-section">
	<!--
        <a href="/pos/label_printer.php" class="menu-item <?= $current == 'label_printer.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-print"></i><span>Label Printer</span></div>
        </a>
        <a href="/pos/sms_panel.php" class="menu-item <?= $current == 'sms_panel.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-comment-sms"></i><span>SMS Panel</span></div>
        </a>
		-->
        <a href="/pos/expense_management.php" class="menu-item <?= $current == 'expense_management.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-money-bill-wave"></i><span>Expenses</span></div>
        </a>
        <a href="/pos/sr_management.php" class="menu-item <?= $current == 'sr_management.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-user-tie"></i><span>Manage Employees</span></div>
        </a>
        <a href="/pos/reports.php" class="menu-item <?= $current == 'reports.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-chart-line"></i><span>Reports</span></div>
        </a>
        <a href="/pos/dashboard_report.php" class="menu-item <?= $current == 'dashboard_report.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-chart-simple"></i><span>Dashboard Report</span></div>
        </a>
        <a href="/pos/settings.php" class="menu-item <?= $current == 'settings.php' ? 'active' : '' ?>">
            <div class="left"><i class="fas fa-gear"></i><span>Settings</span></div>
        </a>
    </div>

    <!-- ================= LOGOUT ================= -->
    <div class="menu-section">
        <a href="/pos/logout.php" class="menu-item logout">
            <div class="left"><i class="fas fa-right-from-bracket"></i><span>Logout</span></div>
        </a>
    </div>

</div>

<script>
// ========== SIDEBAR TOGGLE ==========
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const icon = document.getElementById('toggleIcon');

    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('show');

    if (sidebar.classList.contains('open')) {
        icon.classList.remove('fa-bars');
        icon.classList.add('fa-xmark');
    } else {
        icon.classList.remove('fa-xmark');
        icon.classList.add('fa-bars');
    }
}

// ========== SUBMENU TOGGLE ==========
function toggleMenu(el) {
    const submenu = el.nextElementSibling;
    const arrow = el.querySelector('.arrow');

    // Close other open submenus
    document.querySelectorAll('.submenu').forEach(function(other) {
        if (other !== submenu && other.classList.contains('show')) {
            other.classList.remove('show');
            const prevArrow = other.previousElementSibling?.querySelector('.arrow');
            if (prevArrow) prevArrow.classList.remove('rotate');
        }
    });

    submenu.classList.toggle('show');
    if (arrow) arrow.classList.toggle('rotate');
}

// ========== AUTO OPEN ACTIVE SUBMENU ==========
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.submenu').forEach(function(sub) {
        if (sub.querySelector('.active')) {
            sub.classList.add('show');
            const parentArrow = sub.previousElementSibling?.querySelector('.arrow');
            if (parentArrow) parentArrow.classList.add('rotate');
        }
    });
});

// ========== ESC TO CLOSE ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) {
            toggleSidebar();
        }
    }
});

// ========== CLICK OUTSIDE TO CLOSE ==========
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.querySelector('.sidebar-toggle');
    if (sidebar && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            toggleSidebar();
        }
    }
});

// ========== RIPPLE/SCROLL ACTIVE INTO VIEW ==========
document.querySelectorAll('.menu-item.active').forEach(function(el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
</script>