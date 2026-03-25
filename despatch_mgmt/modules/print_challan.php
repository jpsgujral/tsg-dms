<?php
require_once '../includes/config.php';
require_once '../includes/r2_helper.php';

/* ── Resolve image path: local uploads/ → relative URL, R2 key → r2_url() ── */
function img_display_url(string $path): string {
    if (empty($path)) return '';
    if (strpos($path, 'uploads/') === 0) {
        $rel = substr($path, strlen('uploads/'));
        return '../modules/img.php?f=' . urlencode($rel);
    }
    return htmlspecialchars(r2_url($path));
}
$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) die('Invalid request');

$despatch = $db->query("
    SELECT d.*, 
           t.transporter_name, t.transporter_code, t.phone as t_phone, t.gstin as t_gstin,
           v.vendor_name, v.address as v_address, v.city as v_city, v.gstin as v_gstin2,
           u.full_name as prepared_by_name,
           po.po_number,
           au.signature_path as auth_sig_path
    FROM despatch_orders d
    LEFT JOIN transporters t      ON d.transporter_id = t.id
    LEFT JOIN vendors v           ON d.vendor_id = v.id
    LEFT JOIN app_users u         ON d.created_by = u.id
    LEFT JOIN purchase_orders po  ON d.po_id = po.id
    LEFT JOIN app_users au        ON au.id = (
                    SELECT id FROM app_users
                    WHERE status='Active' AND signature_path IS NOT NULL AND signature_path != ''
                    ORDER BY (id = d.created_by) DESC, id ASC
                    LIMIT 1)
    WHERE d.id = $id
")->fetch_assoc();

if (!$despatch) die('Despatch order not found');
$is_draft = ($despatch['status'] === 'Draft');

$items = $db->query("
    SELECT di.*, i.item_name, i.item_code, i.hsn_code, i.uom as i_uom
    FROM despatch_items di
    JOIN items i ON di.item_id = i.id
    WHERE di.despatch_id = $id
")->fetch_all(MYSQLI_ASSOC);

$company = getCompany((int)($despatch['company_id'] ?? 0));

/* ── Clean address text: strip ALL backslashes, literal \n, normalize whitespace ── */
function cleanAddress($str) {
    if (empty($str)) return '';
    // Remove ALL backslash characters (never valid in addresses)
    $str = str_replace('\\', '', $str);
    // Remove literal \r\n or \n text
    $str = str_replace(['\r\n', '\n', '\r'], ' ', $str);
    // Remove actual newlines/carriage returns
    $str = str_replace(["\r\n", "\n", "\r"], ' ', $str);
    // Collapse multiple spaces/whitespace to single space
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Challan - <?= htmlspecialchars($despatch['challan_no']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #222;
            background: #fff;
        }
        .challan-wrapper {
            max-width: 210mm;
            margin: 0 auto;
            padding: 8mm 10mm;
        }
        /* Header */
        .challan-header {
            border: 2px solid #1a5632;
            border-bottom: none;
        }
        .header-top {
            background: #1a5632;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
        }
        .company-name { font-size: 18pt; font-weight: bold; letter-spacing: 1px; }
        .company-tagline { font-size: 8pt; opacity: 0.8; }
        .challan-title {
            text-align: right;
        }
        .challan-title h2 {
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .challan-title p { font-size: 8pt; opacity: 0.85; }
        
        .header-info {
            display: flex;
            border-bottom: 1.5px solid #1a5632;
        }
        .company-details {
            flex: 1;
            padding: 8px 15px;
            border-right: 1.5px solid #1a5632;
            font-size: 8.5pt;
            line-height: 1.6;
        }
        .company-details strong { font-size: 9pt; }
        .challan-meta {
            width: 200px;
            padding: 8px 12px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #e0e0e0;
            padding: 3px 0;
            font-size: 8.5pt;
        }
        .meta-row:last-child { border-bottom: none; }
        .meta-label { color: #555; font-weight: bold; }
        .meta-value { text-align: right; font-weight: 600; }
        
        /* Copy Banner */
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
            letter-spacing: 2px;
        }

        /* Address Section */
        .address-section {
            display: flex;
            border: 1.5px solid #1a5632;
            border-top: none;
        }
        .address-box {
            flex: 1;
            padding: 8px 12px;
            border-right: 1.5px solid #1a5632;
            font-size: 8.5pt;
            line-height: 1.5;
        }
        .address-box:last-child { border-right: none; }
        .address-box .box-title {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #1a5632;
            background: #e5f5eb;
            margin: -8px -12px 6px;
            padding: 3px 12px;
            letter-spacing: 0.5px;
        }
        .address-box strong { font-size: 9.5pt; display: block; margin-bottom: 2px; }

        /* Transport Section */
        .transport-section {
            border: 1.5px solid #1a5632;
            border-top: none;
            display: flex;
        }
        .transport-field {
            flex: 1;
            padding: 5px 10px;
            border-right: 1.5px solid #1a5632;
            font-size: 8pt;
        }
        .transport-field:last-child { border-right: none; }
        .transport-field .t-label { 
            font-size: 7pt; color: #555; font-weight: bold; 
            text-transform: uppercase; letter-spacing: 0.3px; 
        }
        .transport-field .t-value { font-weight: 600; font-size: 9pt; margin-top: 1px; }

        /* Items Table */
        .items-section {
            border: 1.5px solid #1a5632;
            border-top: none;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table th {
            background: #1a5632;
            color: white;
            padding: 5px 8px;
            text-align: center;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 0.3px;
        }
        .items-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #ddd;
            font-size: 8.5pt;
            vertical-align: middle;
        }
        .items-table tr:nth-child(even) td { background: #f9f9f9; }
        .items-table .num { text-align: center; }
        .items-table .right { text-align: right; }
        .items-table tfoot td {
            font-weight: bold;
            background: #f0f8f3;
            border-top: 2px solid #1a5632;
            padding: 5px 8px;
        }

        /* Totals Section */
        .totals-section {
            display: flex;
            border: 1.5px solid #1a5632;
            border-top: none;
        }
        .amount-words {
            flex: 1;
            padding: 8px 12px;
            border-right: 1.5px solid #1a5632;
            font-size: 8.5pt;
        }
        .amount-words .label { font-size: 7pt; color: #555; font-weight: bold; text-transform: uppercase; }
        .amount-words .words { font-weight: 600; font-style: italic; }
        .totals-table {
            width: 200px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 12px;
            border-bottom: 1px solid #ddd;
            font-size: 8.5pt;
        }
        .total-row.grand {
            background: #1a5632;
            color: white;
            font-weight: bold;
            font-size: 10pt;
            padding: 5px 12px;
        }

        /* Remarks */
        .remarks-section {
            border: 1.5px solid #1a5632;
            border-top: none;
            padding: 6px 12px;
            font-size: 8pt;
        }

        /* Signatures */
        .signature-section {
            border: 1.5px solid #1a5632;
            border-top: none;
            display: flex;
        }
        .sig-box {
            flex: 1;
            padding: 30px 15px 10px;
            border-right: 1.5px solid #1a5632;
            text-align: center;
            font-size: 8pt;
            min-height: 70px;
        }
        .sig-box:last-child { border-right: none; }
        .sig-box .sig-title {
            font-size: 7pt;
            color: #555;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1px solid #999;
            padding-top: 5px;
        }

        /* Footer note */
        .challan-footer {
            text-align: center;
            padding: 8px;
            font-size: 7.5pt;
            color: #666;
            border: 1.5px solid #1a5632;
            border-top: 2px solid #1a5632;
            background: #f8f9fa;
        }

        /* Print Controls */
        .print-controls {
            padding: 12px 16px;
            text-align: center;
            background: #1a5632;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .print-controls span {
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
            margin-right: 6px;
        }
        .print-controls button {
            background: #27ae60;
            color: white;
            border: none;
            padding: 9px 22px;
            font-size: 0.9rem;
            border-radius: 7px;
            cursor: pointer;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .print-controls button:hover { background: #2ecc71; }
        .print-controls .close-btn  { background: #7f8c8d; }
        .print-controls .pdf-btn   { background: #c0392b; }
        .print-controls .pdf-btn:hover { background: #e74c3c; }
        .print-controls .dl-btn    { background: #27ae60; }
        .print-controls .dl-btn:hover  { background: #2ecc71; }

        .copy-separator {
            margin: 20px 0;
            border: none;
            border-top: 2px dashed #aaa;
            page-break-after: always;
        }
        .challan-wrapper {
            max-width: 210mm;
            margin: 0 auto;
            padding: 8mm 10mm;
            page-break-inside: avoid;
        }

        /* ── Mobile screen ── */
        @media screen and (max-width: 800px) {
            body { background: #e5f5eb; }
            .challan-wrapper {
                padding: 3mm 2mm;
                max-width: 100%;
                overflow-x: auto;
            }
            /* Bigger tap targets for buttons */
            .print-controls { padding: 10px 8px; gap: 6px; }
            .print-controls button {
                padding: 10px 12px;
                font-size: 0.78rem;
                min-height: 44px;
                border-radius: 8px;
            }
            /* Stack buttons on very small screens */
            @media (max-width: 480px) {
                .print-controls { flex-direction: column; align-items: stretch; }
                .print-controls button { width: 100%; justify-content: center; }
                .print-controls span { text-align: center; }
            }
        }

        /* ── Print ── */
        @media print {
            .print-controls { display: none !important; }
            body { margin: 0; background: white; }
            .challan-wrapper {
                padding: 5mm 8mm;
                max-width: 100%;
                page-break-inside: avoid;
            }
            .copy-separator {
                border: none;
                page-break-after: always;
                margin: 0;
                height: 0;
            }
        }
    </style>
</head>
<body>

<div class="print-controls">
    <span>📄 Challan: <strong><?= htmlspecialchars($despatch['challan_no']) ?></strong></span>
    <button onclick="window.print()">🖨️ Print All 3 Copies</button>
    <button class="pdf-btn" onclick="window.open('export_challan_pdf.php?id=<?= $id ?>', '_blank')">📄 View PDF</button>
    <button class="dl-btn"  onclick="window.location='export_challan_pdf.php?id=<?= $id ?>&download'">⬇️ Download PDF</button>
    <button class="close-btn" onclick="window.history.back()">← Back</button>
</div>

<?php
// Print 3 copies
$copies = ['Original (Consignee)', 'Duplicate (Transporter)', 'Triplicate (Office)'];
foreach ($copies as $idx => $copyLabel):
?>

<div class="challan-wrapper" <?= $idx < 2 ? '' : '' ?>>
    <!-- Header -->
    <div class="challan-header">
        <div class="header-top">
            <div>
                <div class="company-name"><?= htmlspecialchars($company['company_name']) ?></div>
                <div class="company-tagline">
                    <?= htmlspecialchars($company['address']) ?>, <?= htmlspecialchars($company['city']) ?>
                    <?= $company['state'] ? ', '.$company['state'] : '' ?> - <?= htmlspecialchars($company['pincode']) ?><br>
                    📞 <?= htmlspecialchars($company['phone']) ?> | ✉ <?= htmlspecialchars($company['email']) ?><br>
                    GSTIN: <?= htmlspecialchars($company['gstin']) ?> | PAN: <?= htmlspecialchars($company['pan']) ?>
                </div>
            </div>
            <div class="challan-title">
                <h2>Delivery Challan</h2>
                <p>Challan No: <strong><?= htmlspecialchars($despatch['challan_no']) ?></strong><br>
                Date: <strong><?= date('d/m/Y', strtotime($despatch['despatch_date'])) ?></strong></p>
            </div>
        </div>
        <div class="header-info">
            <div class="company-details">
                <?php if ($despatch['vendor_name']): ?>
                Vendor/Consignor: <strong><?= htmlspecialchars($despatch['vendor_name']) ?></strong>
                <?php endif; ?>
            </div>
            <div class="challan-meta">
                <div class="meta-row">
                    <span class="meta-label">Despatch Date:</span>
                    <span class="meta-value"><?= date('d/m/Y', strtotime($despatch['despatch_date'])) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">No. of Pkgs:</span>
                    <span class="meta-value"><?= $despatch['no_of_packages'] ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Total Weight:</span>
                    <?php
                    $wt_uom = !empty($items[0]['uom']) ? $items[0]['uom'] : (!empty($items[0]['i_uom']) ? $items[0]['i_uom'] : 'Kg');
                    $wt_dp  = uomDecimals($wt_uom);
                    ?>
                    <span class="meta-value"><?= $is_draft ? '—' : number_format((float)($despatch['total_weight']??0), $wt_dp).' '.htmlspecialchars($wt_uom) ?></span>
                </div>

                <?php if (!empty($despatch['expected_delivery'])): ?>
                <div class="meta-row">
                    <span class="meta-label">Exp. Delivery:</span>
                    <span class="meta-value"><?= date('d/m/Y', strtotime($despatch['expected_delivery'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="copy-banner"><?= $copyLabel ?></div>

    <!-- Address Section -->
    <div class="address-section">
        <div class="address-box">
            <div class="box-title">Consignee (Ship To)</div>
            <strong><?= htmlspecialchars(cleanAddress($despatch['consignee_name'])) ?></strong>
            <?= htmlspecialchars(cleanAddress($despatch['consignee_address'])) ?><br>
            <?= htmlspecialchars(cleanAddress($despatch['consignee_city'])) ?>
            <?= $despatch['consignee_state'] ? ', '.htmlspecialchars(cleanAddress($despatch['consignee_state'])) : '' ?>
            <?= $despatch['consignee_pincode'] ? ' - '.htmlspecialchars(cleanAddress($despatch['consignee_pincode'])) : '' ?><br>
            <?php if ($despatch['consignee_gstin']): ?>GSTIN: <strong><?= htmlspecialchars(cleanAddress($despatch['consignee_gstin'])) ?></strong><?php endif; ?>
        </div>
        <div class="address-box">
            <div class="box-title">PO &amp; Transporter Details</div>
            <?php if ($despatch['po_number']): ?><strong>PO No: <?= htmlspecialchars($despatch['po_number']) ?></strong><br><?php endif; ?>
            <?php if ($despatch['transporter_code']): ?>
            Transporter: <strong><?= htmlspecialchars($despatch['transporter_code']) ?></strong><br>
            <?php else: ?>
            <em style="color:#999">Self Transport / Direct</em><br>
            <?php endif; ?>
            <?php if ($despatch['lr_number']): ?>LR No: <strong><?= htmlspecialchars($despatch['lr_number']) ?></strong><?php endif; ?>
            <?php if ($despatch['lr_date']): ?> | LR Date: <?= date('d/m/Y', strtotime($despatch['lr_date'])) ?><?php endif; ?>
        </div>
        <div class="address-box" style="border-right:none">
            <div class="box-title">Vehicle & Driver</div>
            <?php if ($despatch['vehicle_no']): ?>
            Vehicle No: <strong><?= htmlspecialchars($despatch['vehicle_no']) ?></strong><br>
            <?php endif; ?>
            <?php if ($despatch['driver_name']): ?>
            Driver: <strong><?= htmlspecialchars($despatch['driver_name']) ?></strong><br>
            <?php endif; ?>
            <?php if ($despatch['driver_mobile']): ?>
            Mobile: <?= htmlspecialchars($despatch['driver_mobile']) ?><br>
            <?php endif; ?>
            Freight: <strong><?= htmlspecialchars($despatch['freight_paid_by']) ?> Pay</strong>
        </div>
    </div>

    <!-- Items Table -->
    <div class="items-section">
    <table class="items-table">
        <thead>
            <tr>
                <th width="3%" class="num">S.No</th>
                <th width="7%">Item Code</th>
                <th width="21%">Item Description</th>
                <th width="6%">HSN Code</th>
                <th width="5%">UOM</th>
                <th width="7%">Desp Qty</th>
                <th width="7%">Rcvd Wt</th>
                <th width="9%">Unit Price</th>
                <th width="5%">GST%</th>
                <th width="8%">GST Amt</th>
                <th width="10%">Total Value</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $subtotal = 0; $gst_total = 0; $total_qty = 0; $total_weight = 0;
        foreach ($items as $idx2 => $item):
            $subtotal += ($item['qty'] * $item['unit_price']);
            $gst_total += $item['gst_amount'];
            $total_qty += (float)($item['qty'] ?? 0);
            $total_weight += (float)($item['weight'] ?? 0);
            $item_uom  = $item['uom'] ?: ($item['i_uom'] ?? '');
            $item_dp   = uomDecimals($item_uom);
        ?>
        <tr>
            <td class="num"><?= $idx2+1 ?></td>
            <td><?= htmlspecialchars($item['item_code']) ?></td>
            <td>
                <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                <?php if ($item['description']): ?><br><span style="font-size:7.5pt;color:#666"><?= htmlspecialchars($item['description']) ?></span><?php endif; ?>
            </td>
            <td class="num"><?= htmlspecialchars($item['hsn_code']) ?></td>
            <td class="num"><?= htmlspecialchars($item['uom'] ?: $item['i_uom']) ?></td>
            <td class="num"><?= ((float)($item['qty']??0) > 0) ? number_format((float)$item['qty'], $item_dp) : '—' ?></td>
            <td class="num"><strong><?= $is_draft ? '—' : (((float)($item['weight']??0) > 0) ? number_format((float)$item['weight'], 3) : '—') ?></strong></td>
            <td class="right"><?= $is_draft ? '—' : '₹'.number_format((float)($item['unit_price']??0),2) ?></td>
            <td class="num"><?= $is_draft ? '—' : $item['gst_rate'].'%' ?></td>
            <td class="right"><?= $is_draft ? '—' : '₹'.number_format((float)($item['gst_amount']??0),2) ?></td>
            <td class="right"><strong><?= $is_draft ? '—' : '₹'.number_format((float)($item['total_price']??0),2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="right">Total:</td>
                <td class="num"><?= $total_qty > 0 ? number_format($total_qty, 3) : '—' ?></td>
                <td class="num"><strong><?= $is_draft ? '—' : ($total_weight > 0 ? number_format($total_weight, 3) : '—') ?></strong></td>
                <td></td>
                <td></td>
                <td class="right"><strong><?= $is_draft ? '—' : '₹'.number_format($gst_total,2) ?></strong></td>
                <td class="right"><strong><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['total_amount']??0),2) ?></strong></td>
            </tr>
        </tfoot>
    </table>
    </div>

    <!-- Totals -->
    <div class="totals-section">
        <div class="amount-words">
            <div class="label">Amount in Words:</div>
            <div class="words"><?= $is_draft ? '—' : numberToWords((float)($despatch['total_amount']??0)).' Only' ?></div>

        </div>
        <div class="totals-table">
            <div class="total-row">
                <span>Sub Total:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['subtotal']??0),2) ?></span>
            </div>
            <div class="total-row">
                <span>GST Amount:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['gst_amount']??0),2) ?></span>
            </div>
            <div class="total-row">
                <span>Freight:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['freight_amount']??0),2) ?></span>
            </div>
            <div class="total-row grand">
                <span>GRAND TOTAL:</span>
                <span><?= $is_draft ? '—' : '₹'.number_format((float)($despatch['total_amount']??0),2) ?></span>
            </div>
        </div>
    </div>

    <!-- Terms -->
    <div class="remarks-section">
        <strong>Terms & Conditions:</strong> 1. Goods once sold will not be taken back. &nbsp;|&nbsp;
        2. Interest @18% p.a. will be charged if payment is not made within due date. &nbsp;|&nbsp;
        3. All disputes subject to local jurisdiction only. &nbsp;|&nbsp;
        4. E. & O.E.
    </div>

    <!-- Signatures -->
    <div class="signature-section">
        <div class="sig-box">
            <div class="sig-title">Prepared By</div>
            <?php if (!empty($despatch['prepared_by_name'])): ?>
            <div style="margin-top:4px;font-size:8pt;font-weight:600;color:#1a5632"><?= htmlspecialchars($despatch['prepared_by_name']) ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box">
            <?php
            $cb_img = $company['seal_path'] ?? '';
            if ($cb_img): ?>
            <div style="text-align:center;padding:3px 0">
                <img src="<?= img_display_url($cb_img) ?>" alt="Checked By"
                     style="max-height:40px;max-width:90%;object-fit:contain">
            </div>
            <?php endif; ?>
            <div class="sig-title">Checked By</div>
        </div>
        <div class="sig-box">
            <div class="sig-title">Driver's Signature<br>(Goods Received in Good Condition)</div>
        </div>
        <div class="sig-box">
            <?php if (!empty($despatch['auth_sig_path'])): ?>
            <div style="text-align:center;padding:4px 0">
                <img src="<?= img_display_url($despatch['auth_sig_path']) ?>"
                     alt="Signature"
                     style="max-height:45px;max-width:90%;object-fit:contain">
            </div>
            <?php endif; ?>
            <div class="sig-title">Authorised Signatory<br>For <?= htmlspecialchars($company['company_name']) ?></div>
        </div>
    </div>

    <div class="challan-footer">
        This is a computer generated Delivery Challan | <?= htmlspecialchars($company['company_name']) ?> | 
        GSTIN: <?= htmlspecialchars($company['gstin']) ?> | Generated on: <?= date('d/m/Y H:i:s') ?>
    </div>
</div>


<?php if ($copyLabel === 'Original (Consignee)' && ($despatch['mtc_required'] ?? 'No') === 'Yes'): ?>
<div class="challan-wrapper" style="page-break-before:always">
<style>
.mtc-wrap { font-family: Arial, sans-serif; font-size: 11px; }
.mtc-title { text-align:center; font-size:15px; font-weight:700; border:2px solid #333; padding:8px; background:#fff8e1; letter-spacing:1px; margin-bottom:0; }
.mtc-info-table { width:100%; border-collapse:collapse; }
.mtc-info-table td { border:1px solid #999; padding:5px 8px; vertical-align:top; }
.mtc-info-table .lbl { font-weight:700; background:#fffbea; width:28%; }
.mtc-results-table { width:100%; border-collapse:collapse; margin-top:0; }
.mtc-results-table th { border:1px solid #555; padding:6px 8px; background:#f5e642; font-weight:700; text-align:center; font-size:11px; }
.mtc-results-table td { border:1px solid #999; padding:6px 8px; text-align:center; font-size:11px; }
.mtc-results-table td.test-name { text-align:left; font-weight:600; }
.mtc-sig { display:flex; justify-content:space-between; margin-top:20px; }
.mtc-sig-box { text-align:center; width:45%; }
.mtc-sig-line { border-top:1px solid #333; margin-top:40px; padding-top:5px; font-size:10px; }
</style>
<div class="mtc-wrap">
    <!-- MTC Header -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:0">
        <tr>
            <td style="width:25%;border:2px solid #333;padding:8px;text-align:center;vertical-align:middle">
                <?php if (!empty($company['logo'])): ?>
                <img src="<?= htmlspecialchars($company['logo']) ?>" style="max-height:50px">
                <?php else: ?>
                <div style="font-size:18px;font-weight:900;color:#1a5632"><?= strtoupper(substr($company['company_name'],0,3)) ?></div>
                <?php endif; ?>
            </td>
            <td style="border:2px solid #333;padding:8px;text-align:center;vertical-align:middle">
                <div style="font-size:14px;font-weight:700;letter-spacing:1px">MATERIAL TEST CERTIFICATE (MTC)</div>
                <div style="font-size:10px;color:#555;margin-top:3px"><?= htmlspecialchars($company['company_name']) ?></div>
                <div style="font-size:10px;color:#555"><?= htmlspecialchars($company['address']) ?>, <?= htmlspecialchars($company['city']) ?><?= $company['state']?', '.$company['state']:'' ?> | GSTIN: <?= htmlspecialchars($company['gstin']) ?></div>
            </td>
        </tr>
    </table>

    <!-- Info Block -->
    <table class="mtc-info-table" style="margin-top:0">
        <tr>
            <td class="lbl">Challan No &amp; Vehicle No.</td>
            <td><?= htmlspecialchars($despatch['challan_no']) ?> &nbsp;|&nbsp; <?= htmlspecialchars($despatch['vehicle_no']) ?></td>
            <td class="lbl">Despatch Date</td>
            <td><?= date('d/m/Y', strtotime($despatch['despatch_date'])) ?></td>
        </tr>
        <tr>
            <td class="lbl">Item Name</td>
            <td><?= htmlspecialchars($despatch['mtc_item_name'] ?: ($despatch['consignee_name'] ?? '-')) ?></td>
            <td class="lbl">Test Date</td>
            <td><?= $despatch['mtc_test_date'] ? date('d/m/Y', strtotime($despatch['mtc_test_date'])) : '-' ?></td>
        </tr>
        <tr>
            <td class="lbl">Vendor Name</td>
            <td colspan="3"><?= htmlspecialchars($despatch['vendor_name'] ?? '-') ?></td>
        </tr>
    </table>

    <!-- Results section -->
    <table class="mtc-info-table" style="margin-top:0">
        <tr>
            <td colspan="4" style="background:#fff8e1;padding:7px 8px;border:1px solid #999;font-size:10.5px">
                Six random samples of Fly Ash were collected at one hour interval &amp; average results are as under:&nbsp;&nbsp;
                <strong>Source: <?= htmlspecialchars($despatch['mtc_source'] ?? '-') ?></strong>
            </td>
        </tr>
    </table>

    <!-- Test Results Table -->
    <table class="mtc-results-table">
        <thead>
        <tr>
            <th style="width:50%;text-align:left">TEST</th>
            <th style="width:25%">RESULTS %</th>
            <th style="width:25%">Requirements as per IS 3812</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="test-name">ROS 45 Micron Sieve</td>
            <td><?= htmlspecialchars($despatch['mtc_ros_45'] ?: '-') ?>%</td>
            <td>&lt; 34%</td>
        </tr>
        <tr>
            <td class="test-name">Moisture</td>
            <td><?= htmlspecialchars($despatch['mtc_moisture'] ?: '-') ?>%</td>
            <td>&lt; 2%</td>
        </tr>
        <tr>
            <td class="test-name">Loss on Ignition</td>
            <td><?= htmlspecialchars($despatch['mtc_loi'] ?: '-') ?>%</td>
            <td>&lt; 5%</td>
        </tr>
        <tr>
            <td class="test-name">Fineness – Specific Surface Area by Blaine's Permeability Method</td>
            <td><?= htmlspecialchars($despatch['mtc_fineness'] ?: '-') ?> m²/kg</td>
            <td>&gt; 320 m²/kg</td>
        </tr>
        </tbody>
    </table>

    <?php if (!empty($despatch['mtc_remarks'])): ?>
    <div style="margin-top:8px;font-size:11px"><strong>Remarks:</strong> <?= htmlspecialchars($despatch['mtc_remarks']) ?></div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="mtc-sig">
        <div class="mtc-sig-box">
            <div style="border:1px dashed #aaa;min-height:60px;margin-bottom:6px;background:#fafafa;
                        display:flex;align-items:center;justify-content:center;padding:4px">
                <?php if (!empty($company['mtc_sig_path'])): ?>
                <img src="<?= img_display_url($company['mtc_sig_path']) ?>" alt="MTC Signature"
                     style="max-height:52px;max-width:100%;object-fit:contain">
                <?php endif; ?>
            </div>
            <div style="font-size:10px;font-weight:600">For <?= htmlspecialchars($company['company_name']) ?></div>
            <div style="font-size:10px;color:#555">(Manager Technical)</div>
        </div>
        <div class="mtc-sig-box">
            <div style="border:1px dashed #aaa;min-height:60px;margin-bottom:6px;background:#fafafa;
                        display:flex;align-items:center;justify-content:center;padding:4px">
                <?php if (!empty($company['seal_path'])): ?>
                <img src="<?= img_display_url($company['seal_path']) ?>" alt="Company Seal"
                     style="max-height:52px;max-width:100%;object-fit:contain">
                <?php else: ?>
                <span style="color:#ccc;font-size:10px">SEAL</span>
                <?php endif; ?>
            </div>
            <div style="font-size:10px;color:#555;text-align:center">Company Seal</div>
        </div>
    </div>

    <div style="text-align:center;margin-top:10px;font-size:9px;color:#888;border-top:1px solid #ddd;padding-top:5px">
        This MTC is issued as per IS 3812 requirements | Attached to Delivery Challan: <strong><?= htmlspecialchars($despatch['challan_no']) ?></strong> | Original – Consignee Copy
    </div>
</div>
</div>
<?php endif; ?>
<?php if ($idx < count($copies) - 1): ?>
<div class="copy-separator"></div>
<?php endif; ?>

<?php endforeach; ?>

</body>
</html>

<?php
function numberToWords($num) {
    $num = (int)round($num);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    if ($num == 0) return 'Zero Rupees';

    // Use closure so PHP does not globally declare helper() - avoids "Cannot redeclare" on 3 copies
    $helper = null;
    $helper = function($n) use (&$helper, $ones, $tens) {
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[(int)($n/10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        return $ones[(int)($n/100)] . ' Hundred' . ($n % 100 ? ' And ' . $helper($n % 100) : '');
    };

    $result = '';
    if ($num >= 10000000) { $result .= $helper((int)($num/10000000)) . ' Crore ';    $num %= 10000000; }
    if ($num >= 100000)   { $result .= $helper((int)($num/100000))   . ' Lakh ';     $num %= 100000;   }
    if ($num >= 1000)     { $result .= $helper((int)($num/1000))     . ' Thousand '; $num %= 1000;     }
    if ($num > 0)         { $result .= $helper($num); }
    return trim($result) . ' Rupees';
}
?>
