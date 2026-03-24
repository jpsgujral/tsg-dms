<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid trip ID.');

$t = $db->query("SELECT t.*, v.reg_no, v.make, v.model, v.chassis_no, v.engine_no,
    d.full_name AS driver_name, d.license_no AS driver_license, d.phone AS driver_phone,
    s.full_name AS supervisor_name,
    p.po_number, vn.vendor_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v  ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d   ON t.driver_id=d.id
    LEFT JOIN fleet_drivers s   ON t.supervisor_id=s.id
    LEFT JOIN purchase_orders p ON t.po_id=p.id
    LEFT JOIN vendors vn        ON t.vendor_id=vn.id
    WHERE t.id=$id LIMIT 1")->fetch_assoc();
if (!$t) die('Trip not found.');

$trip_items = $db->query("SELECT * FROM fleet_trip_items WHERE trip_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$company    = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();
$total_exp  = $t['toll_amount'] + $t['loading_charges'] + $t['unloading_charges'] + $t['other_expenses'];
$km = ($t['end_odometer'] > $t['start_odometer']) ? ($t['end_odometer'] - $t['start_odometer']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Trip Challan — <?= htmlspecialchars($t['trip_no']) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:12px;color:#000;background:#fff}
.page{width:210mm;min-height:297mm;margin:0 auto;padding:8mm;position:relative}
.header{border-bottom:2px solid #1a5632;padding-bottom:6px;margin-bottom:6px}
.company-name{font-size:18px;font-weight:bold;color:#1a5632}
.company-sub{font-size:10px;color:#555;margin-top:1px}
.title-bar{background:#1a5632;color:#fff;text-align:center;padding:4px;font-size:13px;font-weight:bold;border-radius:3px;margin-bottom:6px}
.section-title{background:#e5f5eb;border-left:3px solid #1a5632;padding:3px 6px;font-weight:bold;font-size:11px;margin:5px 0 3px}
table.info{width:100%;border-collapse:collapse;margin-bottom:4px;font-size:11px}
table.info td{padding:3px 5px;border:1px solid #ccc;vertical-align:top}
table.info td.label{background:#f5f5f5;font-weight:bold;width:22%}
table.items{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:4px}
table.items th{background:#1a5632;color:#fff;padding:4px 5px;text-align:left}
table.items th.r,table.items td.r{text-align:right}
table.items td{padding:3px 5px;border-bottom:1px solid #eee}
table.items tr:nth-child(even) td{background:#fafafa}
table.items tfoot td{background:#e5f5eb;font-weight:bold;border-top:2px solid #1a5632}
.mtc-box{border:1px solid #17a2b8;border-radius:4px;padding:6px;margin-bottom:4px;background:#f0fbfd}
.mtc-title{color:#17a2b8;font-weight:bold;font-size:11px;margin-bottom:4px}
.mtc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;font-size:10px}
.mtc-field .label{color:#666;font-size:9px}
.mtc-field .val{font-weight:bold}
.sign-row{display:flex;justify-content:space-between;margin-top:15mm}
.sign-box{text-align:center;border-top:1px solid #000;padding-top:4px;min-width:45mm;font-size:10px}
.draft-stamp{position:absolute;top:60mm;left:50%;transform:translateX(-50%) rotate(-30deg);font-size:60px;font-weight:bold;color:rgba(200,0,0,0.1);white-space:nowrap;pointer-events:none}
@media print{body{margin:0}.no-print{display:none}.page{width:100%;padding:5mm}}
</style>
</head>
<body>
<div class="no-print" style="padding:10px;text-align:center;background:#f5f5f5;border-bottom:1px solid #ddd">
    <button onclick="window.print()" style="background:#1a5632;color:#fff;border:none;padding:8px 24px;border-radius:4px;cursor:pointer;font-size:14px">🖨 Print / Save PDF</button>
    <button onclick="window.close()" style="margin-left:10px;padding:8px 24px;border-radius:4px;cursor:pointer;font-size:14px">Close</button>
</div>

<div class="page">
<?php if ($t['status'] === 'Planned'): ?>
<div class="draft-stamp">DRAFT</div>
<?php endif; ?>

<!-- Header -->
<div class="header">
<div style="display:flex;justify-content:space-between;align-items:flex-start">
    <div>
        <div class="company-name"><?= htmlspecialchars($company['company_name'] ?? '') ?></div>
        <div class="company-sub"><?= htmlspecialchars($company['address'] ?? '') ?><?= ($company['city']??'') ? ', '.htmlspecialchars($company['city']) : '' ?></div>
        <div class="company-sub">GSTIN: <?= htmlspecialchars($company['gstin'] ?? '') ?> | Ph: <?= htmlspecialchars($company['phone'] ?? '') ?></div>
    </div>
    <div style="text-align:right">
        <div style="font-size:10px;color:#555">Trip Challan No</div>
        <div style="font-size:16px;font-weight:bold;color:#1a5632"><?= htmlspecialchars($t['trip_no']) ?></div>
        <div style="font-size:10px;color:#555">Date: <?= date('d/m/Y', strtotime($t['trip_date'])) ?></div>
        <?php if ($t['po_number']): ?>
        <div style="font-size:10px;color:#555">PO Ref: <?= htmlspecialchars($t['po_number']) ?></div>
        <?php endif; ?>
    </div>
</div>
</div>

<div class="title-bar">MATERIAL TRANSPORT CHALLAN</div>

<!-- Vehicle & Driver -->
<div class="section-title">Vehicle & Driver Details</div>
<table class="info">
<tr>
    <td class="label">Vehicle Reg No</td><td><strong><?= htmlspecialchars($t['reg_no']) ?></strong></td>
    <td class="label">Make / Model</td><td><?= htmlspecialchars($t['make'].' '.$t['model']) ?></td>
</tr>
<tr>
    <td class="label">Driver Name</td><td><?= htmlspecialchars($t['driver_name']) ?></td>
    <td class="label">License No</td><td><?= htmlspecialchars($t['driver_license'] ?? '—') ?></td>
</tr>
<?php if ($t['supervisor_name']): ?>
<tr><td class="label">Supervisor</td><td colspan="3"><?= htmlspecialchars($t['supervisor_name']) ?></td></tr>
<?php endif; ?>
<?php if ($t['vendor_name']): ?>
<tr><td class="label">Vendor / Source</td><td colspan="3"><?= htmlspecialchars($t['vendor_name']) ?></td></tr>
<?php endif; ?>
</table>

<!-- Route -->
<div class="section-title">Route Details</div>
<table class="info">
<tr>
    <td class="label">From</td><td><strong><?= htmlspecialchars($t['from_location']) ?></strong></td>
    <td class="label">To</td><td><strong><?= htmlspecialchars($t['to_location']) ?></strong></td>
</tr>
<tr>
    <td class="label">Consignee</td><td><strong><?= htmlspecialchars($t['customer_name']) ?></strong></td>
    <td class="label">City / State</td><td><?= htmlspecialchars(($t['customer_city']??'').' '.($t['customer_state']??'')) ?></td>
</tr>
<tr>
    <td class="label">Consignee GSTIN</td><td><?= htmlspecialchars($t['customer_gstin']??'—') ?></td>
    <td class="label">Start Date</td><td><?= $t['start_date'] ? date('d/m/Y',strtotime($t['start_date'])) : '—' ?></td>
</tr>
</table>

<!-- Items -->
<?php if ($trip_items): ?>
<div class="section-title">Material / Items</div>
<table class="items">
<thead><tr>
    <th style="width:5%">#</th>
    <th style="width:28%">Item</th>
    <th style="width:25%">Description</th>
    <th style="width:8%">UOM</th>
    <th class="r" style="width:10%">Qty</th>
    <th class="r" style="width:10%">Wt (MT)</th>
    <th class="r" style="width:10%">Rate ₹</th>
    <th class="r" style="width:10%">Amount ₹</th>
</tr></thead>
<tbody>
<?php $ri=1; foreach ($trip_items as $ti): ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($ti['item_name']) ?></td>
    <td><?= htmlspecialchars($ti['description']??'') ?></td>
    <td><?= htmlspecialchars($ti['uom']) ?></td>
    <td class="r"><?= number_format($ti['qty'],3) ?></td>
    <td class="r"><?= number_format($ti['weight'],3) ?></td>
    <td class="r">₹<?= number_format($ti['unit_price'],2) ?></td>
    <td class="r">₹<?= number_format($ti['amount'],2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="5" class="r">Total</td>
    <td class="r"><?= number_format($t['total_weight'],3) ?> MT</td>
    <td></td>
    <td class="r">₹<?= number_format($t['subtotal'],2) ?></td>
</tr></tfoot>
</table>
<?php else: ?>
<div class="section-title">Material Details</div>
<table class="info">
<tr>
    <td class="label">Total Weight</td><td><strong><?= number_format($t['total_weight'],3) ?> MT</strong></td>
    <td class="label">UOM</td><td><?= htmlspecialchars($t['uom']) ?></td>
</tr>
</table>
<?php endif; ?>

<!-- MTC -->
<?php if ($t['mtc_required'] === 'Yes'): ?>
<div class="section-title">Material Test Certificate (MTC)</div>
<div class="mtc-box">
<div class="mtc-title">MTC Details</div>
<div class="mtc-grid">
    <div class="mtc-field"><div class="label">Source / Plant</div><div class="val"><?= htmlspecialchars($t['mtc_source']??'—') ?></div></div>
    <div class="mtc-field"><div class="label">Item Name</div><div class="val"><?= htmlspecialchars($t['mtc_item_name']??'—') ?></div></div>
    <div class="mtc-field"><div class="label">Test Date</div><div class="val"><?= $t['mtc_test_date'] ? date('d/m/Y',strtotime($t['mtc_test_date'])) : '—' ?></div></div>
    <div class="mtc-field"><div class="label">RoS 45μ (%)</div><div class="val"><?= htmlspecialchars($t['mtc_ros_45']??'—') ?></div></div>
    <div class="mtc-field"><div class="label">Moisture (%)</div><div class="val"><?= htmlspecialchars($t['mtc_moisture']??'—') ?></div></div>
    <div class="mtc-field"><div class="label">LOI (%)</div><div class="val"><?= htmlspecialchars($t['mtc_loi']??'—') ?></div></div>
    <div class="mtc-field"><div class="label">Fineness</div><div class="val"><?= htmlspecialchars($t['mtc_fineness']??'—') ?></div></div>
    <?php if ($t['mtc_remarks']): ?><div class="mtc-field" style="grid-column:span 1"><div class="label">MTC Remarks</div><div class="val"><?= htmlspecialchars($t['mtc_remarks']) ?></div></div><?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- Financials -->
<div class="section-title">Financial Details</div>
<table class="info">
<tr>
    <td class="label">Freight Amount</td><td>₹<?= number_format($t['freight_amount'],2) ?></td>
    <td class="label">Driver Advance</td><td>₹<?= number_format($t['driver_advance'],2) ?></td>
</tr>
<tr>
    <td class="label">Toll</td><td>₹<?= number_format($t['toll_amount'],2) ?></td>
    <td class="label">Loading / Unloading</td><td>₹<?= number_format($t['loading_charges']+$t['unloading_charges'],2) ?></td>
</tr>
<tr>
    <td class="label">Other Expenses</td><td>₹<?= number_format($t['other_expenses'],2) ?></td>
    <td class="label">Total Trip Expenses</td><td><strong>₹<?= number_format($total_exp,2) ?></strong></td>
</tr>
</table>

<?php if ($t['remarks']): ?>
<div class="section-title">Remarks</div>
<div style="padding:4px 6px;border:1px solid #ccc;font-size:11px"><?= htmlspecialchars($t['remarks']) ?></div>
<?php endif; ?>

<div class="sign-row">
    <div class="sign-box">Driver Signature<br><small><?= htmlspecialchars($t['driver_name']) ?></small></div>
    <div class="sign-box">Supervisor / Auth. By<?php if ($t['supervisor_name']): ?><br><small><?= htmlspecialchars($t['supervisor_name']) ?></small><?php endif; ?></div>
    <div class="sign-box">Consignee Signature<br><small><?= htmlspecialchars($t['customer_name']) ?></small></div>
</div>
</div>
</body>
</html>
