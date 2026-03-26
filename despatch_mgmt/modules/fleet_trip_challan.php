<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid trip ID.');

/* ── Fix double-encoded HTML entities from sanitize() ── */
function esc($val) {
    if ($val === null || $val === '') return '';
    // Decode first (in case already HTML-encoded from sanitize()), then re-encode once
    return htmlspecialchars(html_entity_decode((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery Challan — <?= esc($t['trip_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: Arial, sans-serif;
    font-size: 10pt;
    color: #222;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}

/* ── Screen: grey background, centred A4 ── */
@media screen {
    body { background: #6b7280; }
    .print-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 20px 0 40px;
        gap: 16px;
    }
}

/* ── Page (A4) ── */
.page {
    width: 210mm;
    min-height: 297mm;
    padding: 8mm 10mm;
    background: #fff;
    position: relative;
}
@media screen {
    .page { box-shadow: 0 4px 28px rgba(0,0,0,.45); }
}

/* ── Header ── */
.header-top {
    background: #1a5632;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 15px;
    border: 2px solid #1a5632;
}
.company-name { font-size: 18pt; font-weight: bold; letter-spacing: 1px; }
.company-sub  { font-size: 8pt; opacity: 0.8; margin-top: 2px; line-height: 1.5; }
.challan-ref  { text-align: right; }
.challan-ref h2 { font-size: 16pt; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; }
.challan-ref p  { font-size: 8pt; opacity: 0.85; margin-top: 2px; line-height: 1.7; }

/* ── Force backgrounds in print/PDF ── */
.header-top, .copy-banner, .section-title,
table.info td.lbl, table.items th, table.items tfoot td,
table.items tr:nth-child(even) td, .challan-footer,
.sign-section, .sign-box {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}

/* ── Copy banner ── */
.copy-banner {
    background: #f0f8f3;
    border: 1.5px solid #1a5632;
    border-top: none;
    border-bottom: 2px solid #1a5632;
    text-align: center;
    padding: 4px;
    font-size: 9pt;
    font-weight: bold;
    color: #1a5632;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ── Section title ── */
.section-title {
    font-size: 7pt;
    font-weight: bold;
    text-transform: uppercase;
    color: #1a5632;
    background: #e5f5eb;
    padding: 3px 12px;
    letter-spacing: 0.3px;
    border: 1.5px solid #1a5632;
    border-bottom: none;
    margin-top: 6px;
}

/* ── Info tables ── */
table.info {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.5pt;
    border: 1.5px solid #1a5632;
    border-top: none;
}
table.info td {
    padding: 5px 10px;
    border: 1px solid #ddd;
    vertical-align: top;
    line-height: 1.5;
}
table.info td.lbl {
    background: #e5f5eb;
    font-weight: bold;
    width: 18%;
    color: #1a5632;
    font-size: 8.5pt;
    white-space: nowrap;
}

/* ── Items table ── */
.items-wrap {
    border: 1.5px solid #1a5632;
    border-top: none;
}
table.items {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.5pt;
}
table.items th {
    background: #1a5632;
    color: #fff;
    padding: 5px 8px;
    text-align: center;
    font-size: 8pt;
    font-weight: bold;
    letter-spacing: 0.3px;
}
table.items th.r, table.items td.r { text-align: right; }
table.items th.l, table.items td.l { text-align: left; }
table.items td {
    padding: 5px 8px;
    border-bottom: 1px solid #ddd;
    vertical-align: middle;
}
table.items tr:nth-child(even) td { background: #f9f9f9; }
table.items tfoot td {
    background: #f0f8f3;
    font-weight: bold;
    border-top: 2px solid #1a5632;
    padding: 5px 8px;
}

/* ── Signatures ── */
.sign-section {
    border: 1.5px solid #1a5632;
    border-top: none;
    display: flex;
}
.sign-box {
    flex: 1;
    padding: 30px 15px 10px;
    border-right: 1.5px solid #1a5632;
    text-align: center;
    font-size: 8pt;
    min-height: 70px;
}
.sign-box:last-child { border-right: none; }
.sign-box .sig-title {
    font-size: 8pt;
    color: #333;
    font-weight: bold;
}
.sign-box small { font-weight: normal; color: #555; display: block; margin-top: 3px; }

/* ── Footer ── */
.challan-footer {
    border: 1.5px solid #1a5632;
    border-top: none;
    text-align: center;
    padding: 5px;
    font-size: 7.5pt;
    color: #555;
    background: #f0f8f3;
}

/* ── Draft stamp ── */
.draft-stamp {
    position: absolute; top: 60mm; left: 50%;
    transform: translateX(-50%) rotate(-30deg);
    font-size: 60px; font-weight: bold;
    color: rgba(200,0,0,0.08);
    white-space: nowrap; pointer-events: none;
}

/* ── Print controls bar ── */
.print-controls {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: #1a5632;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    z-index: 1000;
}
.print-controls .info-text {
    color: rgba(255,255,255,.8);
    font-size: .82rem;
    margin-right: 10px;
}
.print-controls button {
    color: #fff;
    border: none;
    padding: 8px 20px;
    font-size: .85rem;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-print  { background: #27ae60; }
.btn-print:hover  { background: #2ecc71; }
.btn-pdf    { background: #c0392b; }
.btn-pdf:hover    { background: #e74c3c; }
.btn-back   { background: #546e7a; }
.btn-back:hover   { background: #607d8b; }

/* ── Print ── */
@media print {
    body { background: #fff; }
    .print-controls { display: none !important; }
    .print-wrapper { padding: 0; gap: 0; }
    .page {
        width: 210mm;
        padding: 8mm 10mm;
        box-shadow: none;
        page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }
}
</style>
</head>
<body>

<div class="print-controls">
    <span class="info-text">
        🚛 <strong><?= esc($t['trip_no']) ?></strong>
        &nbsp;·&nbsp; <?= esc($t['driver_name']) ?>
        &nbsp;·&nbsp; <?= esc($t['reg_no']) ?>
    </span>
    <button class="btn-print" onclick="window.print()">
        🖨️ Print
    </button>
    <button class="btn-pdf" onclick="printPDF()">
        📄 Export PDF
    </button>
    <button class="btn-back" onclick="window.history.back()">
        ← Back
    </button>
</div>

<script>
function printPDF() {
    var title = document.title;
    document.title = 'Delivery_Challan_<?= addslashes($t['trip_no']) ?>';
    window.print();
    document.title = title;
}
</script>

<div class="print-wrapper">

<div class="page">
<?php if ($t['status'] === 'Planned'): ?>
<div class="draft-stamp">DRAFT</div>
<?php endif; ?>

<!-- Header -->
<div class="header-top">
    <div>
        <div class="company-name"><?= esc($company['company_name'] ?? '') ?></div>
        <div class="company-sub">
            <?= esc($company['address'] ?? '') ?><?= ($company['city']??'') ? ', '.esc($company['city']) : '' ?><br>
            GSTIN: <?= esc($company['gstin'] ?? '') ?> | Ph: <?= esc($company['phone'] ?? '') ?>
        </div>
    </div>
    <div class="challan-ref">
        <h2>Delivery Challan</h2>
        <p>
            Trip No: <strong><?= esc($t['trip_no']) ?></strong><br>
            Date: <strong><?= date('d/m/Y', strtotime($t['trip_date'])) ?></strong>
            <?php if ($t['po_number']): ?><br>PO Ref: <strong><?= esc($t['po_number']) ?></strong><?php endif; ?>
        </p>
    </div>
</div>

<div class="copy-banner">Delivery Challan — Original Copy</div>

<!-- Vehicle & Driver -->
<div class="section-title">Vehicle &amp; Driver Details</div>
<table class="info">
<tr>
    <td class="lbl">Vehicle Reg No</td><td><strong><?= esc($t['reg_no']) ?></strong></td>
    <td class="lbl">Make / Model</td><td><?= esc($t['make'].' '.$t['model']) ?></td>
</tr>
<tr>
    <td class="lbl">Driver Name</td><td><strong><?= esc($t['driver_name']) ?></strong></td>
    <td class="lbl">License No</td><td><?= esc($t['driver_license'] ?? '—') ?></td>
</tr>
<tr>
    <td class="lbl">Driver Mobile</td><td><?= esc($t['driver_phone'] ?? '—') ?></td>
    <td class="lbl"></td><td></td>
</tr>
<?php if ($t['supervisor_name']): ?>
<tr><td class="lbl">Supervisor</td><td colspan="3"><?= esc($t['supervisor_name']) ?></td></tr>
<?php endif; ?>
<?php if ($t['vendor_name']): ?>
<tr><td class="lbl">Customer / Buyer</td><td colspan="3"><strong><?= esc($t['vendor_name']) ?></strong></td></tr>
<?php endif; ?>
</table>

<!-- Route -->
<div class="section-title">Route Details</div>
<table class="info">
<tr>
    <td class="lbl">From (Source)</td><td><strong><?= esc($t['from_location']) ?></strong></td>
    <td class="lbl">To (Destination)</td><td><strong><?= esc($t['to_location']) ?></strong></td>
</tr>
<?php if ($t['customer_name']): ?>
<tr>
    <td class="lbl">Consignee</td><td colspan="3"><strong><?= esc($t['customer_name']) ?></strong>
    <?= ($t['customer_city']??'') ? ' — '.esc($t['customer_city']) : '' ?>
    <?= ($t['customer_gstin']??'') ? ' | GSTIN: '.esc($t['customer_gstin']) : '' ?>
    </td>
</tr>
<?php endif; ?>
</table>

<!-- Items -->
<?php if ($trip_items): ?>
<div class="section-title">Material / Items</div>
<div class="items-wrap">
<table class="items">
<thead><tr>
    <th style="width:5%">#</th>
    <th style="width:30%">Item / Material</th>
    <th style="width:8%">UOM</th>
    <th class="r" style="width:10%">Qty</th>
    <th class="r" style="width:12%">Weight (MT)</th>
    <th class="r" style="width:12%">Rate (₹)</th>
    <th class="r" style="width:13%">Amount (₹)</th>
</tr></thead>
<tbody>
<?php $ri=1; $tw=0; foreach ($trip_items as $ti): $tw += (float)($ti['weight']??0); ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><strong><?= esc($ti['item_name']) ?></strong></td>
    <td><?= esc($ti['uom']) ?></td>
    <td class="r"><?= number_format((float)($ti['qty']??0),3) ?></td>
    <td class="r"><?= number_format((float)($ti['weight']??0),3) ?></td>
    <td class="r">₹<?= number_format((float)($ti['unit_price']??0),2) ?></td>
    <td class="r">₹<?= number_format((float)($ti['amount']??0),2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="4" class="r">Total</td>
    <td class="r"><?= number_format($tw,3) ?> MT</td>
    <td></td>
    <td class="r">₹<?= number_format((float)($t['subtotal']??0),2) ?></td>
</tr></tfoot>
</table>
</div>
<?php else: ?>
<div class="section-title">Material Details</div>
<table class="info">
<tr>
    <td class="lbl">Total Weight</td><td><strong><?= number_format((float)($t['total_weight']??0),3) ?> MT</strong></td>
    <td class="lbl">UOM</td><td><?= esc($t['uom']??'MT') ?></td>
</tr>
</table>
<?php endif; ?>

<!-- Freight -->
<div class="section-title">Freight Details</div>
<table class="info">
<tr>
    <td class="lbl">Freight Amount</td><td><strong>₹<?= number_format((float)($t['freight_amount']??0),2) ?></strong></td>
    <td class="lbl">Driver Advance</td><td>₹<?= number_format((float)($t['driver_advance']??0),2) ?></td>
</tr>
</table>

<?php if ($t['remarks']): ?>
<div class="section-title">Remarks</div>
<table class="info">
<tr><td style="padding:5px 8px"><?= esc($t['remarks']) ?></td></tr>
</table>
<?php endif; ?>

<!-- Signatures -->
<div class="sign-section">
    <div class="sign-box">
        <div class="sig-title">Driver's Signature</div>
        <small><?= esc($t['driver_name']) ?></small>
    </div>
    <div class="sign-box">
        <div class="sig-title">Supervisor / Authorised By</div>
        <?php if ($t['supervisor_name']): ?><small><?= esc($t['supervisor_name']) ?></small><?php endif; ?>
    </div>
    <div class="sign-box">
        <div class="sig-title">Consignee Signature</div>
        <small>(Goods Received in Good Condition)</small>
    </div>
</div>

<div class="challan-footer">
    This is a computer generated Trip Challan | <?= esc($company['company_name'] ?? '') ?> | Generated on: <?= date('d/m/Y H:i') ?>
</div>
</div><!-- /page -->

<?php if ($t['mtc_required'] === 'Yes'): ?>
<!-- ══ MTC PAGE (page break before) ══ -->
<div class="page" style="page-break-before:always">

<!-- MTC Header -->
<table style="width:100%;border-collapse:collapse;margin-bottom:0">
<tr>
    <td style="width:25%;border:2px solid #1a5632;padding:8px;text-align:center;vertical-align:middle;background:#1a5632">
        <div style="font-size:18px;font-weight:900;color:#fff;letter-spacing:1px"><?= strtoupper(substr(esc($company['company_name']??''),0,3)) ?></div>
    </td>
    <td style="border:2px solid #1a5632;border-left:none;padding:8px;text-align:center;vertical-align:middle">
        <div style="font-size:14px;font-weight:700;letter-spacing:1px;color:#1a5632">MATERIAL TEST CERTIFICATE (MTC)</div>
        <div style="font-size:10px;color:#555;margin-top:3px"><?= esc($company['company_name']??'') ?></div>
        <div style="font-size:10px;color:#555"><?= esc($company['address']??'') ?><?= ($company['city']??'') ? ', '.esc($company['city']) : '' ?> | GSTIN: <?= esc($company['gstin']??'') ?></div>
    </td>
</tr>
</table>

<!-- Info Block -->
<table style="width:100%;border-collapse:collapse;border:2px solid #1a5632;border-top:none;font-size:11px">
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632;width:20%">Challan No &amp; Vehicle No</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><?= esc($t['trip_no']) ?> &nbsp;|&nbsp; <?= esc($t['reg_no']) ?></td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632;width:15%">Trip Date</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
</tr>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Item Name</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><strong><?= esc($t['mtc_item_name']??'—') ?></strong></td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Test Date</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px"><?= $t['mtc_test_date'] ? date('d/m/Y',strtotime($t['mtc_test_date'])) : '—' ?></td>
</tr>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;background:#e5f5eb;font-weight:bold;color:#1a5632">Customer / Buyer</td>
    <td colspan="3" style="border:1px solid #a8c8b0;padding:5px 8px"><?= esc($t['vendor_name']??'—') ?></td>
</tr>
</table>

<!-- Source note -->
<table style="width:100%;border-collapse:collapse;border:2px solid #1a5632;border-top:none;font-size:11px">
<tr>
    <td style="background:#fff8e1;padding:6px 8px;border:1px solid #a8c8b0">
        Six random samples of Fly Ash were collected at one hour interval &amp; average results are as under: &nbsp;&nbsp;
        <strong>Source: <?= esc($t['mtc_source']??'—') ?></strong>
    </td>
</tr>
</table>

<!-- Test Results Table -->
<table style="width:100%;border-collapse:collapse;border:2px solid #1a5632;border-top:none;font-size:11px">
<thead>
<tr>
    <th style="background:#1a5632;color:#fff;padding:6px 8px;text-align:left;width:50%;border-right:1px solid #27ae60">TEST</th>
    <th style="background:#1a5632;color:#fff;padding:6px 8px;text-align:center;width:25%;border-right:1px solid #27ae60">RESULTS %</th>
    <th style="background:#1a5632;color:#fff;padding:6px 8px;text-align:center;width:25%">Requirements as per IS 3812</th>
</tr>
</thead>
<tbody>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">ROS 45 Micron Sieve</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_ros_45']??'—') ?>%</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&lt; 34%</td>
</tr>
<tr style="background:#f5fbf7">
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">Moisture</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_moisture']??'—') ?>%</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&lt; 2%</td>
</tr>
<tr>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">Loss on Ignition</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_loi']??'—') ?>%</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&lt; 5%</td>
</tr>
<tr style="background:#f5fbf7">
    <td style="border:1px solid #a8c8b0;padding:5px 8px;font-weight:600">Fineness – Specific Surface Area by Blaine's Permeability Method</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;font-weight:bold;color:#1a5632"><?= esc($t['mtc_fineness']??'—') ?> m²/kg</td>
    <td style="border:1px solid #a8c8b0;padding:5px 8px;text-align:center;color:#555">&gt; 320 m²/kg</td>
</tr>
</tbody>
</table>

<?php if (!empty($t['mtc_remarks'])): ?>
<div style="border:2px solid #1a5632;border-top:none;padding:6px 8px;font-size:11px;background:#fff8e1">
    <strong>Remarks:</strong> <?= esc($t['mtc_remarks']) ?>
</div>
<?php endif; ?>

<!-- MTC Signatures -->
<div style="display:flex;justify-content:space-between;margin-top:20mm">
    <div style="text-align:center;width:45%">
        <div style="border:1px dashed #aaa;min-height:55px;background:#fafafa;margin-bottom:6px"></div>
        <div style="font-size:10px;font-weight:600">For <?= esc($company['company_name']??'') ?></div>
        <div style="font-size:10px;color:#555">(Manager Technical)</div>
    </div>
    <div style="text-align:center;width:45%">
        <div style="border:1px dashed #aaa;min-height:55px;background:#fafafa;margin-bottom:6px"></div>
        <div style="font-size:10px;color:#555">Company Seal</div>
    </div>
</div>

<div style="text-align:center;margin-top:10px;font-size:9px;color:#888;border-top:1px solid #ddd;padding-top:5px">
    This MTC is issued as per IS 3812 requirements | Attached to Delivery Challan: <strong><?= esc($t['trip_no']) ?></strong> | Original – Consignee Copy
</div>

</div><!-- /MTC page -->
<?php endif; ?>

</div><!-- /print-wrapper -->
</body>
</html>
