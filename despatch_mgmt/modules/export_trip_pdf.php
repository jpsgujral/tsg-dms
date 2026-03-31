<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';
$db = getDB();
requirePerm('fleet_trips', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid trip ID.');

function esc($val) {
    if ($val === null || $val === '') return '';
    return htmlspecialchars(html_entity_decode((string)$val, ENT_QUOTES|ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

$t = $db->query("SELECT t.*, v.reg_no, v.make, v.model, v.chassis_no, v.engine_no,
    d.full_name AS driver_name, d.license_no AS driver_license, d.phone AS driver_phone,
    s.full_name AS supervisor_name,
    p.po_number, vn.vendor_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v  ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d   ON t.driver_id=d.id
    LEFT JOIN fleet_drivers s   ON t.supervisor_id=s.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    WHERE t.id=$id LIMIT 1")->fetch_assoc();
if (!$t) die('Trip not found.');

$trip_items = $db->query("SELECT * FROM fleet_trip_items WHERE trip_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$company    = getCompany();

// Expenses
$fuel_entries = $db->query("SELECT fl.*, fc.company_name AS fuel_company
    FROM fleet_fuel_log fl
    LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
    WHERE fl.trip_id=$id ORDER BY fl.fuel_date ASC")->fetch_all(MYSQLI_ASSOC);
$total_fuel_litres = array_sum(array_column($fuel_entries,'litres'));
$total_fuel_cost   = array_sum(array_column($fuel_entries,'amount'));

$veh_expenses = $db->query("SELECT * FROM fleet_expenses WHERE trip_id=$id ORDER BY expense_date ASC")->fetch_all(MYSQLI_ASSOC);
$total_veh_exp = array_sum(array_column($veh_expenses,'amount'));

// P&L
$items_pl    = $db->query("SELECT SUM(weight) tw, SUM(amount) ta FROM fleet_trip_items WHERE trip_id=$id")->fetch_assoc();
$total_wt    = (float)($items_pl['tw'] ?? 0);
$rate_income = (float)($items_pl['ta'] ?? 0) ?: (float)($t['freight_amount'] ?? 0);
$drv_advance = (float)($t['driver_advance'] ?? 0);
$total_exp   = $total_fuel_cost + $drv_advance + $total_veh_exp;
$net_profit  = $rate_income - $total_exp;

$status_colors = ['Planned'=>'#6c757d','In Transit'=>'#fd7e14','Completed'=>'#198754','Cancelled'=>'#dc3545'];
$status_color  = $status_colors[$t['status']] ?? '#6c757d';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trip Report — <?= esc($t['trip_no']) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 9pt; color: #222; background: #fff; }

/* Print controls */
.print-controls {
    position: fixed; top: 0; left: 0; right: 0; z-index: 999;
    background: #1a5632; color: #fff;
    padding: 8px 20px;
    display: flex; align-items: center; gap: 12px;
}
.print-controls button {
    background: #fff; color: #1a5632; border: none;
    padding: 5px 16px; border-radius: 4px; font-size: 9pt;
    cursor: pointer; font-weight: bold;
}
.print-controls button.pdf { background: #dc3545; color: #fff; }
.print-controls .trip-no { font-weight: bold; font-size: 10pt; margin-left: auto; }
@media print { .print-controls { display: none; } }

/* Page */
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 50px auto 20px;
    padding: 14mm 14mm 12mm;
    background: #fff;
}
@media print {
    .page { margin: 0; padding: 10mm 12mm; width: 100%; }
    body { font-size: 8.5pt; }
}

/* Header */
.doc-header {
    border: 2px solid #1a5632;
    display: flex;
    align-items: stretch;
    margin-bottom: 0;
}
.doc-header .company-col {
    flex: 1;
    padding: 10px 14px;
    border-right: 2px solid #1a5632;
}
.doc-header .company-col .co-name {
    font-size: 13pt;
    font-weight: bold;
    color: #1a5632;
    line-height: 1.2;
}
.doc-header .company-col .co-sub {
    font-size: 7.5pt;
    color: #555;
    margin-top: 3px;
}
.doc-header .title-col {
    width: 150px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 10px;
    background: #1a5632;
    color: #fff;
    text-align: center;
}
.doc-header .title-col .doc-title {
    font-size: 11pt;
    font-weight: bold;
    letter-spacing: 0.5px;
}
.doc-header .title-col .trip-badge {
    font-size: 9pt;
    margin-top: 4px;
    background: rgba(255,255,255,0.2);
    padding: 2px 8px;
    border-radius: 10px;
}

/* Status bar */
.status-bar {
    background: <?= $status_color ?>;
    color: #fff;
    text-align: center;
    font-size: 8pt;
    font-weight: bold;
    padding: 3px;
    letter-spacing: 1px;
    text-transform: uppercase;
    border-left: 2px solid #1a5632;
    border-right: 2px solid #1a5632;
    border-bottom: 1px solid rgba(255,255,255,0.3);
}

/* Info grid */
.info-section {
    border: 1.5px solid #1a5632;
    border-top: none;
    display: grid;
    grid-template-columns: 1fr 1fr;
}
.info-section .info-col {
    padding: 8px 12px;
}
.info-section .info-col:first-child {
    border-right: 1.5px solid #1a5632;
}
.info-row {
    display: flex;
    margin-bottom: 4px;
    font-size: 8.5pt;
}
.info-row .lbl {
    color: #555;
    width: 110px;
    flex-shrink: 0;
    font-size: 7.5pt;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.info-row .val {
    font-weight: 600;
    flex: 1;
}

/* Section title */
.section-title {
    background: #1a5632;
    color: #fff;
    padding: 4px 12px;
    font-size: 8pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    border-left: 1.5px solid #1a5632;
    border-right: 1.5px solid #1a5632;
}

/* Tables */
table.data-table {
    width: 100%;
    border-collapse: collapse;
    border-left: 1.5px solid #1a5632;
    border-right: 1.5px solid #1a5632;
    border-bottom: 1.5px solid #1a5632;
    font-size: 8.5pt;
}
table.data-table th {
    background: #f0f8f3;
    color: #1a5632;
    font-size: 7.5pt;
    text-transform: uppercase;
    font-weight: bold;
    padding: 5px 8px;
    border-bottom: 1px solid #1a5632;
    border-right: 1px solid #ddd;
    text-align: left;
}
table.data-table td {
    padding: 5px 8px;
    border-bottom: 1px solid #eee;
    border-right: 1px solid #eee;
    vertical-align: middle;
}
table.data-table tr:last-child td { border-bottom: none; }
table.data-table .r { text-align: right; }
table.data-table tfoot td {
    background: #f0f8f3;
    font-weight: bold;
    border-top: 1px solid #1a5632;
    border-bottom: none;
}

/* P&L */
.pl-table {
    width: 100%;
    border-collapse: collapse;
    border: 1.5px solid #1a5632;
    font-size: 8.5pt;
}
.pl-table tr td { padding: 5px 12px; border-bottom: 1px solid #eee; }
.pl-table tr:last-child td { border-bottom: none; }
.pl-table .pl-income td { background: #f0f8f3; }
.pl-table .pl-net td { background: #1a5632; color: #fff; font-weight: bold; font-size: 10pt; }
.pl-table .pl-net.loss td { background: #dc3545; }

/* Signature */
.sign-section {
    border: 1.5px solid #1a5632;
    border-top: none;
    display: flex;
}
.sign-box {
    flex: 1;
    padding: 10px 12px;
    border-right: 1.5px solid #1a5632;
    text-align: center;
    min-height: 60px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
}
.sign-box:last-child { border-right: none; }
.sign-box .sig-title {
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    border-top: 1px solid #999;
    padding-top: 4px;
    width: 100%;
    color: #444;
}

.mt8 { margin-top: 8px; }
.gap { height: 6px; }
</style>
</head>
<body>

<!-- Print Controls -->
<div class="print-controls">
    <button onclick="window.print()">🖨 Print</button>
    <button class="pdf" onclick="window.print()">📄 Export PDF</button>
    <span>Use browser Print → Save as PDF for PDF export</span>
    <span class="trip-no"><?= esc($t['trip_no']) ?> &nbsp;|&nbsp; <?= esc($t['status']) ?></span>
</div>

<div class="page">

<!-- Header -->
<div class="doc-header">
    <div class="company-col">
        <div class="co-name"><?= esc($company['company_name'] ?? '') ?></div>
        <div class="co-sub"><?= esc($company['address'] ?? '') ?><?= $company['city'] ? ', '.$company['city'] : '' ?></div>
        <div class="co-sub" style="margin-top:3px">
            <?php if ($company['phone']): ?>📞 <?= esc($company['phone']) ?> &nbsp;<?php endif; ?>
            <?php if ($company['gstin']): ?>GST: <?= esc($company['gstin']) ?><?php endif; ?>
        </div>
    </div>
    <div class="title-col">
        <div class="doc-title">TRIP REPORT</div>
        <div class="trip-badge"><?= esc($t['trip_no']) ?></div>
    </div>
</div>

<!-- Status bar -->
<div class="status-bar"><?= esc($t['status']) ?></div>

<!-- Trip Info -->
<div class="info-section">
    <div class="info-col">
        <div class="info-row"><span class="lbl">Trip Date</span><span class="val"><?= $t['trip_date'] ? date('d/m/Y', strtotime($t['trip_date'])) : '—' ?></span></div>
        <div class="info-row"><span class="lbl">Vehicle</span><span class="val"><?= esc($t['reg_no']) ?> — <?= esc($t['make'].' '.$t['model']) ?></span></div>
        <div class="info-row"><span class="lbl">Driver</span><span class="val"><?= esc($t['driver_name']) ?><?= $t['driver_phone'] ? ' ('.$t['driver_phone'].')' : '' ?></span></div>
        <div class="info-row"><span class="lbl">Supervisor</span><span class="val"><?= esc($t['supervisor_name'] ?: '—') ?></span></div>
        <div class="info-row"><span class="lbl">Customer PO</span><span class="val"><?= esc($t['po_number'] ?: '—') ?></span></div>
    </div>
    <div class="info-col">
        <div class="info-row"><span class="lbl">From</span><span class="val"><?= esc($t['from_location'] ?: '—') ?></span></div>
        <div class="info-row"><span class="lbl">To</span><span class="val"><?= esc($t['to_location'] ?: '—') ?></span></div>
        <div class="info-row"><span class="lbl">Customer</span><span class="val"><?= esc($t['customer_name'] ?: $t['vendor_name'] ?: '—') ?></span></div>
        <div class="info-row"><span class="lbl">Total Weight</span><span class="val"><?= number_format((float)$t['total_weight'],3) ?> MT</span></div>
        <div class="info-row"><span class="lbl">Driver Advance</span><span class="val">₹<?= number_format((float)$t['driver_advance'],2) ?></span></div>
    </div>
</div>

<!-- Items -->
<div class="gap"></div>
<div class="section-title"><i class="bi bi-list-ul"></i> Items / Materials</div>
<table class="data-table">
<thead><tr>
    <th>#</th><th>Item</th><th>UOM</th>
    <th class="r">Weight (MT)</th>
    <th class="r">Rate (₹/MT)</th>
    <th class="r">Amount (₹)</th>
</tr></thead>
<tbody>
<?php foreach ($trip_items as $i => $ti): ?>
<tr>
    <td><?= $i+1 ?></td>
    <td><?= esc($ti['item_name']) ?></td>
    <td><?= esc($ti['uom']) ?></td>
    <td class="r"><?= number_format((float)$ti['weight'],3) ?></td>
    <td class="r">₹<?= number_format((float)$ti['unit_price'],2) ?></td>
    <td class="r">₹<?= number_format((float)$ti['amount'],2) ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($trip_items)): ?>
<tr><td colspan="6" style="text-align:center;color:#999">No items recorded</td></tr>
<?php endif; ?>
</tbody>
<tfoot><tr>
    <td colspan="3" class="r">Total</td>
    <td class="r"><?= number_format($total_wt,3) ?> MT</td>
    <td></td>
    <td class="r">₹<?= number_format($rate_income,2) ?></td>
</tr></tfoot>
</table>

<?php if ($fuel_entries): ?>
<!-- Fuel -->
<div class="gap mt8"></div>
<div class="section-title">⛽ Fuel Entries</div>
<table class="data-table">
<thead><tr>
    <th>Date</th><th>Fuel Company</th>
    <th class="r">Litres</th><th class="r">Rate/L</th>
    <th class="r">Amount (₹)</th><th>Mode</th><th>Bill No</th>
</tr></thead>
<tbody>
<?php foreach ($fuel_entries as $fe): ?>
<tr>
    <td><?= date('d/m/Y', strtotime($fe['fuel_date'])) ?></td>
    <td><?= esc($fe['fuel_company'] ?? '—') ?></td>
    <td class="r"><?= number_format((float)$fe['litres'],2) ?></td>
    <td class="r">₹<?= number_format((float)$fe['rate_per_litre'],2) ?></td>
    <td class="r">₹<?= number_format((float)$fe['amount'],2) ?></td>
    <td><?= esc($fe['payment_mode']) ?></td>
    <td><?= esc($fe['bill_no'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="2" class="r">Total</td>
    <td class="r"><?= number_format($total_fuel_litres,2) ?> L</td>
    <td></td>
    <td class="r">₹<?= number_format($total_fuel_cost,2) ?></td>
    <td colspan="2"></td>
</tr></tfoot>
</table>
<?php endif; ?>

<?php if ($veh_expenses): ?>
<!-- Vehicle Expenses -->
<div class="gap mt8"></div>
<div class="section-title">🔧 Vehicle Expenses</div>
<table class="data-table">
<thead><tr>
    <th>Date</th><th>Type</th><th>Vendor</th><th>Description</th>
    <th class="r">Amount (₹)</th><th>Mode</th>
</tr></thead>
<tbody>
<?php foreach ($veh_expenses as $ve): ?>
<tr>
    <td><?= date('d/m/Y', strtotime($ve['expense_date'])) ?></td>
    <td><?= esc($ve['expense_type']) ?></td>
    <td><?= esc($ve['vendor_name'] ?? '—') ?></td>
    <td><?= esc($ve['description'] ?? '') ?></td>
    <td class="r">₹<?= number_format((float)$ve['amount'],2) ?></td>
    <td><?= esc($ve['payment_mode']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="4" class="r">Total</td>
    <td class="r">₹<?= number_format($total_veh_exp,2) ?></td>
    <td></td>
</tr></tfoot>
</table>
<?php endif; ?>

<!-- P&L -->
<div class="gap mt8"></div>
<div class="section-title">📊 Trip P&L Summary</div>
<table class="pl-table">
<tr class="pl-income">
    <td style="width:70%"><strong>Freight Income</strong> <small style="color:#555">(<?= number_format($total_wt,3) ?> MT)</small></td>
    <td class="r" style="color:#198754;font-weight:bold">₹<?= number_format($rate_income,2) ?></td>
</tr>
<tr>
    <td>Fuel Cost <small style="color:#888">(<?= number_format($total_fuel_litres,2) ?> L)</small></td>
    <td class="r" style="color:#dc3545">− ₹<?= number_format($total_fuel_cost,2) ?></td>
</tr>
<tr>
    <td>Driver Advance</td>
    <td class="r" style="color:#dc3545">− ₹<?= number_format($drv_advance,2) ?></td>
</tr>
<tr>
    <td>Vehicle Expenses</td>
    <td class="r" style="color:#dc3545">− ₹<?= number_format($total_veh_exp,2) ?></td>
</tr>
<tr style="background:#f8f9fa">
    <td><strong>Total Expenses</strong></td>
    <td class="r"><strong>₹<?= number_format($total_exp,2) ?></strong></td>
</tr>
<tr class="pl-net <?= $net_profit < 0 ? 'loss' : '' ?>">
    <td>Net <?= $net_profit >= 0 ? 'Profit' : 'Loss' ?></td>
    <td class="r"><?= $net_profit < 0 ? '− ' : '' ?>₹<?= number_format(abs($net_profit),2) ?></td>
</tr>
</table>

<?php if ($t['remarks']): ?>
<div class="gap mt8"></div>
<div class="section-title">📝 Remarks</div>
<div style="border:1.5px solid #1a5632;border-top:none;padding:8px 12px;font-size:8.5pt">
    <?= esc($t['remarks']) ?>
</div>
<?php endif; ?>

<!-- Signatures -->
<div class="gap mt8"></div>
<div class="section-title">Authorisation</div>
<div class="sign-section">
    <div class="sign-box">
        <div class="sig-title">Driver — <?= esc($t['driver_name']) ?></div>
    </div>
    <div class="sign-box">
        <div class="sig-title">Supervisor / Authorised By</div>
    </div>
    <div class="sign-box">
        <div class="sig-title">For <?= esc($company['company_name'] ?? '') ?></div>
    </div>
</div>

<!-- Footer -->
<div style="text-align:center;margin-top:6px;font-size:7pt;color:#888">
    Generated by <?= esc($company['company_name'] ?? 'DMS') ?> &bull; <?= date('d/m/Y H:i') ?> &bull; <?= esc($t['trip_no']) ?>
</div>

</div><!-- /page -->
</body>
</html>
