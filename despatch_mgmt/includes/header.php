<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/auth.php';

$db       = getDB();
$company  = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();
$app_name = $company ? $company['company_name'] : APP_NAME;

$current_page = basename($_SERVER['PHP_SELF']);
$script_dir   = dirname($_SERVER['PHP_SELF']);
$in_modules   = (basename($script_dir) === 'modules');
$in_backup    = (basename($script_dir) === 'backup');
$base         = ($in_modules || $in_backup) ? '../' : '';
$modules_base = $in_modules ? '' : 'modules/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= htmlspecialchars($app_name) ?> — DMS</title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- DataTables + Responsive extension -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

    <style>
    /* ═══════════════════════ VARIABLES ════════════════════════ */
    :root {
        --primary:       #1a5632;
        --secondary:     #27ae60;
        --sidebar-w:     262px;
        --topbar-h:      58px;
        --light-bg:      #f0f8f3;
        --transition:    0.28s cubic-bezier(.4,0,.2,1);
    }

    /* ═══════════════════════ RESET / BASE ═════════════════════ */
    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        background: var(--light-bg);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        font-size: 14px;
        overflow-x: hidden;
    }

    /* ═══════════════════════ SIDEBAR ══════════════════════════ */
    .sidebar {
        position: fixed;
        top: 0; left: 0;
        width: var(--sidebar-w);
        height: 100dvh;          /* dynamic viewport height for mobile */
        background: linear-gradient(180deg, var(--primary) 0%, #2d6b3f 100%);
        color: #fff;
        overflow-y: auto;
        overflow-x: hidden;
        z-index: 1050;
        transition: transform var(--transition), box-shadow var(--transition);
        box-shadow: 4px 0 20px rgba(0,0,0,0.18);
        display: flex;
        flex-direction: column;
        -webkit-overflow-scrolling: touch;
    }
    /* Scrollbar inside sidebar – thin */
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

    .sidebar-brand {
        padding: 18px 16px 14px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        background: rgba(0,0,0,0.18);
        flex-shrink: 0;
    }
    .sidebar-brand .brand-tag  { font-size: 0.65rem; opacity: .65; letter-spacing: 1.5px; text-transform: uppercase; }
    .sidebar-brand .brand-name { font-size: 0.95rem; font-weight: 700; margin-top: 3px; line-height: 1.25; }

    .nav-section {
        padding: 16px 16px 4px;
        font-size: 0.6rem;
        text-transform: uppercase;
        letter-spacing: 1.8px;
        opacity: .45;
        font-weight: 700;
        flex-shrink: 0;
    }
    .nav-section-toggle {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        opacity: 1;
        padding: 10px 16px;
        margin: 2px 6px;
        border-radius: 7px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        color: rgba(255,255,255,.65);
        background: rgba(255,255,255,.05);
        transition: background .18s, color .18s;
        user-select: none;
        flex-shrink: 0;
    }
    .nav-section-toggle:hover {
        background: rgba(255,255,255,.12);
        color: rgba(255,255,255,.9);
    }
    .nav-section-toggle i.bi-chevron-down,
    .nav-section-toggle i.bi-chevron-up {
        font-size: 0.65rem;
        opacity: .7;
        transition: transform .2s;
    }
    .nav-group { flex-shrink: 0; }
    .nav-group > div[id^="nav-"] {
        overflow: hidden;
        transition: none;
    }
    .sidebar .nav-link {
        color: rgba(255,255,255,.8);
        padding: 10px 20px;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 11px;
        border-left: 3px solid transparent;
        transition: background .18s, border-color .18s, color .18s;
        border-radius: 0;
        white-space: nowrap;
    }
    .sidebar .nav-link i { font-size: 1.05rem; width: 20px; flex-shrink: 0; }
    .sidebar .nav-link:hover  { color: #fff; background: rgba(255,255,255,.1); border-left-color: rgba(255,255,255,.4); }
    .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.15); border-left-color: #2ecc71; }

    /* ═══════════════════════ OVERLAY ══════════════════════════ */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        z-index: 1040;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
        transition: opacity var(--transition);
    }
    .sidebar-overlay.show { display: block; }

    /* ═══════════════════════ MAIN CONTENT ═════════════════════ */
    .main-content {
        margin-left: var(--sidebar-w);
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        transition: margin var(--transition);
    }

    /* ═══════════════════════ TOPBAR ═══════════════════════════ */
    .topbar {
        background: #fff;
        height: var(--topbar-h);
        padding: 0 20px;
        box-shadow: 0 2px 12px rgba(0,0,0,.07);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 200;
        flex-shrink: 0;
    }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h4  { margin: 0; font-size: 1rem; color: var(--primary); font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 55vw; }

    /* Hamburger toggle — hidden on desktop, visible on mobile */
    .sidebar-toggle {
        display: none;
        background: none;
        border: none;
        color: var(--primary);
        font-size: 1.4rem;
        padding: 4px 6px;
        border-radius: 6px;
        cursor: pointer;
        line-height: 1;
        flex-shrink: 0;
    }
    .sidebar-toggle:hover { background: var(--light-bg); }

    .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .topbar-date  { font-size: 0.75rem; color: #888; }

    /* ═══════════════════════ CONTENT AREA ═════════════════════ */
    .content-area {
        padding: 22px 24px;
        flex: 1;
    }

    /* ═══════════════════════ CARDS ════════════════════════════ */
    .card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 2px 14px rgba(0,0,0,.06);
    }
    .card-header {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: #fff;
        border-radius: 12px 12px 0 0 !important;
        padding: 13px 18px;
        font-weight: 600;
        font-size: 0.9rem;
    }

    /* ═══════════════════════ STAT CARDS ═══════════════════════ */
    .stat-card {
        border-radius: 12px;
        padding: 18px;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    .stat-card .icon { position: absolute; right: 12px; top: 12px; font-size: 2.8rem; opacity: .18; }
    .stat-card h3  { font-size: 1.7rem; font-weight: 700; margin: 0; }
    .stat-card p   { margin: 0; opacity: .85; font-size: 0.8rem; }

    /* ═══════════════════════ BUTTONS ══════════════════════════ */
    .btn-primary       { background: var(--primary); border-color: var(--primary); }
    .btn-primary:hover { background: var(--secondary); border-color: var(--secondary); }
    .btn-action        { padding: 4px 9px; font-size: 0.76rem; border-radius: 6px; }

    /* ═══════════════════════ TABLES ═══════════════════════════ */
    .table th { background: #f5fbf7; color: var(--primary); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: .4px; }
    .table td { vertical-align: middle; font-size: 0.85rem; }
    /* Ensure tables scroll horizontally on small screens */
    .table-responsive { -webkit-overflow-scrolling: touch; }

    /* ═══════════════════════ FORMS ════════════════════════════ */
    .form-label      { font-weight: 600; font-size: 0.82rem; color: #444; }
    .form-control, .form-select {
        border-radius: 8px;
        border: 1.5px solid #d0e8d8;
        font-size: 0.875rem;
        /* larger touch target on mobile */
        min-height: 40px;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--secondary);
        box-shadow: 0 0 0 3px rgba(41,128,185,.15);
    }
    textarea.form-control { min-height: unset; }

    /* ═══════════════════════ ALERTS ═══════════════════════════ */
    .alert { border-radius: 10px; font-size: 0.875rem; }

    /* ═══════════════════════ MOBILE (≤ 991px) ═════════════════ */
    @media (max-width: 991.98px) {

        /* Hide sidebar off-screen by default */
        .sidebar {
            transform: translateX(-100%);
            box-shadow: none;
        }
        .sidebar.open {
            transform: translateX(0);
            box-shadow: 6px 0 24px rgba(0,0,0,.28);
        }

        /* Main content takes full width */
        .main-content { margin-left: 0; }

        /* Show hamburger */
        .sidebar-toggle { display: inline-flex; align-items: center; }

        /* Tighter content padding */
        .content-area { padding: 14px 12px; }

        /* Page title shorter */
        .topbar h4 { font-size: 0.9rem; }

        /* Hide date on very small screens */
        .topbar-date { display: none; }

        /* Stat cards: 2 per row on phones */
        .stat-card h3 { font-size: 1.35rem; }

        /* Action buttons stacked on tiny screens */
        .btn-action { padding: 5px 8px; font-size: 0.8rem; }

        /* DataTable search + info on mobile */
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_length { text-align: left; }
    }

    /* ═══════════════════════ SMALL PHONES (≤ 575px) ═══════════ */
    @media (max-width: 575.98px) {
        .content-area { padding: 10px 8px; }
        .card-header  { padding: 10px 14px; font-size: 0.82rem; }
        .stat-card    { padding: 14px; }
        .stat-card h3 { font-size: 1.2rem; }
        .table td, .table th { font-size: 0.78rem; padding: 6px 8px; }

        /* Stack form cols */
        .row > [class*="col-md-"] { margin-bottom: 0; }
    }

    /* ═══════════════════════ DATATABLE MOBILE ═════════════════ */
    .dataTables_wrapper .dataTables_filter input {
        border: 1.5px solid #d0e8d8;
        border-radius: 8px;
        padding: 5px 10px;
        font-size: 0.85rem;
    }
    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid #d0e8d8;
        border-radius: 8px;
        padding: 4px 8px;
        font-size: 0.85rem;
    }

    /* ═══════════════════════ TOUCH TARGETS ════════════════════ */
    /* All interactive elements have at least 44×44px touch area */
    .btn { min-height: 38px; }
    .btn-sm { min-height: 32px; }
    .btn-action { min-height: 34px; min-width: 34px; display: inline-flex; align-items: center; justify-content: center; }
    .form-select, .form-control { touch-action: manipulation; }

    /* ═══════════════════════ ITEMS TABLE SCROLL ═══════════════ */
    /* Line-item tables (purchase orders, despatch) scroll horizontally */
    #itemsTable, #dItemsTable {
        min-width: 700px;
    }

    /* ═══════════════════════ CARD MOBILE ══════════════════════ */
    .card { margin-bottom: 14px; }

    /* ═══════════════════════ BADGE RESPONSIVE ═════════════════ */
    @media (max-width: 575.98px) {
        .badge { font-size: 0.68rem; }
        /* Stack form action buttons vertically */
        .col-12.text-end .btn { margin-top: 4px; width: 100%; }
        .col-12.text-end .btn + .btn { margin-left: 0 !important; }
        /* Full-width primary buttons on mobile */
        form .col-12.text-end { display: flex; flex-direction: column-reverse; gap: 8px; }
        /* Narrower action column in tables */
        .btn-action { padding: 5px 7px; font-size: 0.75rem; }
        /* Card header wraps */
        .card-header { flex-wrap: wrap; gap: 6px; }
        /* Topbar compact */
        .topbar { padding: 0 12px; }
    }

    /* ═══════════════════════ PRINT OVERRIDE ═══════════════════ */
    @media print {
        .sidebar, .topbar, .sidebar-overlay { display: none !important; }
        .main-content { margin-left: 0 !important; }
        .content-area { padding: 0 !important; }
    }
    </style>
<script>
function toggleNav(id) {
    var panel = document.getElementById('nav-' + id);
    var chev  = document.getElementById('chev-' + id);
    var open  = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : '';
    chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    // Save state to sessionStorage
    try { sessionStorage.setItem('nav_' + id, open ? '0' : '1'); } catch(e) {}
}
// Restore previously open sections (except current active one which is already open)
document.addEventListener('DOMContentLoaded', function() {
    ['masters','procurement','despatch','finance','fleet','settings'].forEach(function(id) {
        var panel = document.getElementById('nav-' + id);
        var chev  = document.getElementById('chev-' + id);
        if (!panel) return;
        // If already open (active section), don't override
        if (panel.style.display !== 'none') return;
        try {
            var saved = sessionStorage.getItem('nav_' + id);
            if (saved === '1') {
                panel.style.display = '';
                if (chev) chev.className = 'bi bi-chevron-up';
            }
        } catch(e) {}
    });
});
function toggleSidebar() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebarOverlay');
    s.classList.toggle('open');
    o.classList.toggle('show');
}
function closeSidebar() {
    var s = document.getElementById('sidebar');
    var o = document.getElementById('sidebarOverlay');
    s.classList.remove('open');
    o.classList.remove('show');
}
</script>
</head>
<body>

<!-- ── Sidebar Overlay (mobile backdrop) ───────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ── Sidebar ─────────────────────────────────────────────── -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-tag"><i class="bi bi-truck me-1"></i> Despatch Management</div>
        <div class="brand-name"><?= htmlspecialchars($app_name) ?></div>
    </div>

    <?php
    // Determine which section the current page belongs to
    $section_map = [
        'index.php'                => 'dashboard',
        'vendors.php'              => 'masters',
        'items.php'                => 'masters',
        'transporters.php'         => 'masters',
        'source_of_material.php'   => 'masters',
        'purchase_orders.php'      => 'procurement',
        'despatch.php'             => 'despatch',
        'delivery_challans.php'    => 'despatch',
        'transporter_payments.php' => 'finance',
        'transporter_bills.php'    => 'finance',
        'agent_commissions.php'    => 'finance',
        'sales_invoices.php'       => 'finance',
        'export_excel.php'         => 'finance',
        'fleet_vehicles.php'       => 'fleet',
        'fleet_drivers.php'        => 'fleet',
        'fleet_customers_master.php'=> 'fleet',
        'fleet_purchase_orders.php'=> 'fleet',
        'fleet_fuel_companies.php' => 'fleet',
        'fleet_trips.php'          => 'fleet',
        'fleet_fuel.php'           => 'fleet',
        'fleet_fuel_payments.php'  => 'fleet',
        'fleet_expenses.php'       => 'fleet',
        'fleet_tyres.php'          => 'fleet',
        'fleet_salary.php'         => 'fleet',
        'companies.php'            => 'settings',
        'company_settings.php'     => 'settings',
        'users.php'                => 'settings',
    ];
    // backup/index.php maps to settings section
    if ($in_backup && $current_page === 'index.php') {
        $active_section = 'settings';
    } else {
        $active_section = $section_map[$current_page] ?? 'dashboard';
    }
    $backup_base = $in_backup ? '' : 'backup/';
    ?>

    <!-- Dashboard (always visible, no collapse) -->
    <a href="<?= $base ?>index.php" class="nav-link <?= $current_page=='index.php'?'active':'' ?>">
        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>

    <?php
    // Helper to output a collapsible section
    function navSection($id, $label, $icon, $active_section, $has_items) {
        if (!$has_items) return;
        $open = ($active_section === $id);
        echo '<div class="nav-group">';
        echo '<div class="nav-section nav-section-toggle" onclick="toggleNav(\''.$id.'\')" data-section="'.$id.'">';
        echo '<span><i class="bi '.$icon.' me-2"></i>'.$label.'</span>';
        echo '<i class="bi bi-chevron-'.($open?'up':'down').'" id="chev-'.$id.'"></i>';
        echo '</div>';
        echo '<div id="nav-'.$id.'" style="'.($open?'':'display:none').'">';
    }
    function navSectionEnd() {
        echo '</div></div>';
    }
    ?>

    <?php
    $has_masters = isAdmin() || canDo('vendors','view') || canDo('items','view') || canDo('transporters','view') || canDo('source_of_material','view');
    navSection('masters','Masters','bi-grid', $active_section, $has_masters);
    ?>
    <?php if (isAdmin() || canDo('vendors','view')): ?>
    <a href="<?= $modules_base ?>vendors.php" class="nav-link <?= $current_page=='vendors.php'?'active':'' ?>">
        <i class="bi bi-building"></i><span>Vendor Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('items','view')): ?>
    <a href="<?= $modules_base ?>items.php" class="nav-link <?= $current_page=='items.php'?'active':'' ?>">
        <i class="bi bi-box-seam"></i><span>Item Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('transporters','view')): ?>
    <a href="<?= $modules_base ?>transporters.php" class="nav-link <?= $current_page=='transporters.php'?'active':'' ?>">
        <i class="bi bi-truck-front"></i><span>Transporter Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('source_of_material','view')): ?>
    <a href="<?= $modules_base ?>source_of_material.php" class="nav-link <?= $current_page=='source_of_material.php'?'active':'' ?>">
        <i class="bi bi-geo-alt"></i><span>Source of Material</span>
    </a>
    <?php endif; ?>
    <?php if ($has_masters) navSectionEnd(); ?>

    <?php
    $has_procurement = isAdmin() || canDo('purchase_orders','view');
    navSection('procurement','Procurement','bi-file-earmark-text', $active_section, $has_procurement);
    ?>
    <?php if (isAdmin() || canDo('purchase_orders','view')): ?>
    <a href="<?= $modules_base ?>purchase_orders.php" class="nav-link <?= $current_page=='purchase_orders.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-text"></i><span>Purchase Orders</span>
    </a>
    <?php endif; ?>
    <?php if ($has_procurement) navSectionEnd(); ?>

    <?php
    $has_despatch = isAdmin() || canDo('despatch','view') || canDo('delivery_challans','view');
    navSection('despatch','Despatch','bi-send-check', $active_section, $has_despatch);
    ?>
    <?php if (isAdmin() || canDo('despatch','view')): ?>
    <a href="<?= $modules_base ?>despatch.php" class="nav-link <?= $current_page=='despatch.php'?'active':'' ?>">
        <i class="bi bi-send-check"></i><span>Despatch Orders</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('delivery_challans','view')): ?>
    <a href="<?= $modules_base ?>delivery_challans.php" class="nav-link <?= $current_page=='delivery_challans.php'?'active':'' ?>">
        <i class="bi bi-receipt"></i><span>Delivery Challans</span>
    </a>
    <?php endif; ?>
    <?php if ($has_despatch) navSectionEnd(); ?>

    <?php
    $has_finance = isAdmin() || canDo('transporter_payments','view') || canDo('transporter_bills','view') || canDo('agent_commissions','view') || canDo('sales_invoices','view') || canDo('export_excel','view');
    navSection('finance','Finance','bi-cash-coin', $active_section, $has_finance);
    ?>
    <?php if (isAdmin() || canDo('transporter_payments','view')): ?>
    <a href="<?= $modules_base ?>transporter_payments.php" class="nav-link <?= $current_page=='transporter_payments.php'?'active':'' ?>">
        <i class="bi bi-cash-coin"></i><span>Transporter Payments</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('transporter_bills','view')): ?>
    <a href="<?= $modules_base ?>transporter_bills.php" class="nav-link <?= $current_page=='transporter_bills.php'?'active':'' ?>">
        <i class="bi bi-receipt-cutoff"></i><span>Transporter Pending Bills</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('agent_commissions','view')): ?>
    <a href="<?= $modules_base ?>agent_commissions.php" class="nav-link <?= $current_page=='agent_commissions.php'?'active':'' ?>">
        <i class="bi bi-percent"></i><span>Agent Commissions</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('sales_invoices','view')): ?>
    <a href="<?= $modules_base ?>sales_invoices.php" class="nav-link <?= $current_page=='sales_invoices.php'?'active':'' ?>">
        <i class="bi bi-receipt-cutoff"></i><span>Sales Invoices</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('export_excel','view')): ?>
    <a href="<?= $modules_base ?>export_excel.php" class="nav-link <?= $current_page=='export_excel.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-excel"></i><span>Export to Excel</span>
    </a>
    <?php endif; ?>
    <?php if ($has_finance) navSectionEnd(); ?>

    <?php
    $has_fleet = isAdmin() || canDo('fleet_dashboard','view') || canDo('fleet_vehicles','view') || canDo('fleet_drivers','view') || canDo('fleet_customers_master','view') || canDo('fleet_purchase_orders','view') || canDo('fleet_fuel_companies','view') || canDo('fleet_trips','view') || canDo('fleet_fuel','view') || canDo('fleet_fuel_payments','view') || canDo('fleet_expenses','view') || canDo('fleet_tyres','view') || canDo('fleet_salary','view');
    navSection('fleet','Fleet Management','bi-truck', $active_section, $has_fleet);
    ?>
    <?php if (isAdmin() || canDo('fleet_dashboard','view')): ?>
    <a href="<?= $modules_base ?>fleet_dashboard.php" class="nav-link <?= $current_page=='fleet_dashboard.php'?'active':'' ?>">
        <i class="bi bi-speedometer2"></i><span>Fleet Dashboard</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_vehicles','view')): ?>
    <a href="<?= $modules_base ?>fleet_vehicles.php" class="nav-link <?= $current_page=='fleet_vehicles.php'?'active':'' ?>">
        <i class="bi bi-truck"></i><span>Vehicle Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_drivers','view')): ?>
    <a href="<?= $modules_base ?>fleet_drivers.php" class="nav-link <?= $current_page=='fleet_drivers.php'?'active':'' ?>">
        <i class="bi bi-person-badge"></i><span>Driver Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_customers_master','view')): ?>
    <a href="<?= $modules_base ?>fleet_customers_master.php" class="nav-link <?= $current_page=='fleet_customers_master.php'?'active':'' ?>">
        <i class="bi bi-person-lines-fill"></i><span>Customer Master</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_purchase_orders','view')): ?>
    <a href="<?= $modules_base ?>fleet_purchase_orders.php" class="nav-link <?= $current_page=='fleet_purchase_orders.php'?'active':'' ?>">
        <i class="bi bi-file-earmark-text"></i><span>Customer POs</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_fuel_companies','view')): ?>
    <a href="<?= $modules_base ?>fleet_fuel_companies.php" class="nav-link <?= $current_page=='fleet_fuel_companies.php'?'active':'' ?>">
        <i class="bi bi-fuel-pump"></i><span>Fuel Companies</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_trips','view')): ?>
    <a href="<?= $modules_base ?>fleet_trips.php" class="nav-link <?= $current_page=='fleet_trips.php'?'active':'' ?>">
        <i class="bi bi-signpost-split"></i><span>Trip Orders</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_fuel','view')): ?>
    <a href="<?= $modules_base ?>fleet_fuel.php" class="nav-link <?= $current_page=='fleet_fuel.php'?'active':'' ?>">
        <i class="bi bi-droplet-fill"></i><span>Fuel Management</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_fuel_payments','view')): ?>
    <a href="<?= $modules_base ?>fleet_fuel_payments.php" class="nav-link <?= $current_page=='fleet_fuel_payments.php'?'active':'' ?>">
        <i class="bi bi-cash-coin"></i><span>Fuel Payments</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_expenses','view')): ?>
    <a href="<?= $modules_base ?>fleet_expenses.php" class="nav-link <?= $current_page=='fleet_expenses.php'?'active':'' ?>">
        <i class="bi bi-tools"></i><span>Vehicle Expenses</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_tyres','view')): ?>
    <a href="<?= $modules_base ?>fleet_tyres.php" class="nav-link <?= $current_page=='fleet_tyres.php'?'active':'' ?>">
        <i class="bi bi-circle"></i><span>Tyre Tracking</span>
    </a>
    <?php endif; ?>
    <?php if (isAdmin() || canDo('fleet_salary','view')): ?>
    <a href="<?= $modules_base ?>fleet_salary.php" class="nav-link <?= $current_page=='fleet_salary.php'?'active':'' ?>">
        <i class="bi bi-wallet2"></i><span>Driver Salary</span>
    </a>
    <?php endif; ?>
    <?php if ($has_fleet) navSectionEnd(); ?>

    <?php if (isAdmin()): ?>
    <?php navSection('settings','Settings','bi-gear', $active_section, true); ?>
    <a href="<?= $modules_base ?>companies.php" class="nav-link <?= $current_page=='companies.php'?'active':'' ?>">
        <i class="bi bi-buildings"></i><span>Companies</span>
    </a>
    <a href="<?= $modules_base ?>company_settings.php" class="nav-link <?= $current_page=='company_settings.php'?'active':'' ?>">
        <i class="bi bi-gear"></i><span>Company Settings</span>
    </a>
    <a href="<?= $modules_base ?>users.php" class="nav-link <?= $current_page=='users.php'?'active':'' ?>">
        <i class="bi bi-people"></i><span>User Management</span>
    </a>
    <a href="<?= $base ?><?= $backup_base ?>index.php" class="nav-link <?= ($in_backup && $current_page=='index.php')?'active':'' ?>">
        <i class="bi bi-cloud-arrow-up"></i><span>Backup &amp; Restore</span>
    </a>
    <?php navSectionEnd(); ?>
    <?php endif; ?>

    <!-- Bottom spacer so content doesn't get hidden under iOS home bar -->
    <div style="height: env(safe-area-inset-bottom, 16px); flex-shrink:0"></div>
</div>

<!-- ── Main Content ────────────────────────────────────────── -->
<div class="main-content" id="mainContent">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle menu">
                <i class="bi bi-list"></i>
            </button>
            <h4 id="page-title"><i class="bi bi-grid me-1"></i>Dashboard</h4>
        </div>
        <div class="topbar-right">
            <span class="topbar-date d-none d-sm-inline"><?= date('d M Y') ?></span>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                        style="border-radius:20px;padding:4px 12px">
                    <span class="d-flex align-items-center justify-content-center rounded-circle text-white fw-bold"
                          style="width:26px;height:26px;font-size:.75rem;background:linear-gradient(135deg,#1a5632,#27ae60)">
                        <?= strtoupper(substr($_SESSION['full_name']??$_SESSION['username']??'U',0,1)) ?>
                    </span>
                    <span class="d-none d-md-inline" style="font-size:.82rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?>
                    </span>
                    <?php if (isAdmin()): ?><span class="badge bg-danger" style="font-size:.6rem">Admin</span><?php endif; ?>
                    <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:200px;border-radius:12px">
                    <li><div class="px-3 py-2 border-bottom">
                        <div class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
                        <div class="text-muted" style="font-size:.76rem">@<?= htmlspecialchars($_SESSION['username'] ?? '') ?></div>
                    </div></li>
                    <?php if (isAdmin()): ?>
                    <li><a class="dropdown-item py-2" href="<?= $modules_base ?>users.php">
                        <i class="bi bi-people me-2 text-primary"></i>User Management
                    </a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="<?= $base ?>logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="content-area">
<?php displayAlert(); ?>
