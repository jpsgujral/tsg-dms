<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requirePerm('company_settings', 'view'); // Admin/manager only

$db = getDB();

$date_from = sanitize($_POST['date_from'] ?? '');
$date_to   = sanitize($_POST['date_to']   ?? '');

// ── Show form if not POSTed ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    include '../includes/header.php';
    ?>
    <script>document.getElementById('page-title').innerHTML='<i class="bi bi-file-earmark-excel me-2"></i>Export to Excel';</script>
    <div class="row justify-content-center">
    <div class="col-12 col-md-7 col-lg-5">
    <div class="card">
    <div class="card-header"><i class="bi bi-file-earmark-excel me-2"></i>Export Database to Excel</div>
    <div class="card-body">
        <p class="text-muted mb-3">Exports all data into a single Excel file with separate sheets for each module.</p>
        <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-bold">Date Range <small class="text-muted fw-normal">(applies to PO, Despatch, Invoices, Payments)</small></label>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="">
                </div>
                <div class="col-6">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="">
                </div>
            </div>
            <div class="form-text">Leave blank to export all records.</div>
        </div>
        <div class="mb-3">
            <label class="form-label fw-bold">Sheets to include</label>
            <div class="row g-1">
                <?php
                $sheets = [
                    'purchase_orders'     => 'Purchase Orders',
                    'despatch_orders'     => 'Despatch Orders',
                    'delivery_challans'   => 'Delivery Challans (Items)',
                    'sales_invoices'      => 'Sales Invoices',
                    'transporter_payments'=> 'Transporter Payments',
                    'vendors'             => 'Vendor Master',
                    'items'               => 'Item Master',
                    'transporters'        => 'Transporter Master',
                ];
                foreach ($sheets as $k => $label):
                ?>
                <div class="col-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sheets[]" value="<?= $k ?>" id="sh_<?= $k ?>" checked>
                        <label class="form-check-label" for="sh_<?= $k ?>"><?= $label ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-success w-100 py-2">
            <i class="bi bi-download me-2"></i>Download Excel File
        </button>
        </form>
    </div></div>
    </div></div>
    <?php
    include '../includes/footer.php';
    exit;
}

// ── Build Excel ───────────────────────────────────────────────
$selected = $_POST['sheets'] ?? array_keys([
    'purchase_orders'=>1,'despatch_orders'=>1,'delivery_challans'=>1,
    'sales_invoices'=>1,'transporter_payments'=>1,
    'vendors'=>1,'items'=>1,'transporters'=>1
]);

$df = $date_from ? "AND DATE(%%DATE_COL%%) >= '$date_from'" : '';
$dt = $date_to   ? "AND DATE(%%DATE_COL%%) <= '$date_to'"   : '';
$dfilter = $df . ' ' . $dt;

function dWhere($col, $df, $dt) {
    $w = '';
    if ($df) $w .= " AND DATE($col) >= '$df'";
    if ($dt) $w .= " AND DATE($col) <= '$dt'";
    return $w;
}
$dw = function($col) use ($date_from, $date_to) { return dWhere($col, $date_from, $date_to); };

// ── Collect all sheet data ────────────────────────────────────
$workbook = [];

if (in_array('purchase_orders', $selected)) {
    $po_sql = "SELECT p.id, p.po_number, p.po_date, c.company_name,
        v.vendor_name, v.gstin AS vendor_gstin,
        p.gst_type, p.payment_terms, p.validity_date,
        p.subtotal, p.cgst_amount, p.sgst_amount, p.igst_amount,
        p.gst_amount, p.discount, p.total_amount, p.status, p.remarks
        FROM purchase_orders p
        LEFT JOIN vendors v ON p.vendor_id=v.id
        LEFT JOIN companies c ON p.company_id=c.id
        WHERE 1=1 " . $dw('p.po_date') . "
        ORDER BY p.po_date DESC";
    $po_res = $db->query($po_sql);
    $rows = $po_res ? $po_res->fetch_all(MYSQLI_ASSOC) : [];
    $workbook['Purchase Orders'] = [
        'headers' => ['ID','PO Number','PO Date','Company','Vendor','Vendor GSTIN',
                      'GST Type','Payment Terms','Validity Date',
                      'Subtotal','CGST','SGST','IGST','GST Total','Discount','Total Amount','Status','Remarks'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['po_number'],$r['po_date'],$r['company_name'],$r['vendor_name'],$r['vendor_gstin'],
            $r['gst_type'],$r['payment_terms'],$r['validity_date'],
            $r['subtotal'],$r['cgst_amount'],$r['sgst_amount'],$r['igst_amount'],
            $r['gst_amount'],$r['discount'],$r['total_amount'],$r['status'],$r['remarks']
        ], $rows)
    ];
}

if (in_array('despatch_orders', $selected)) {
    $rows = $db->query("SELECT d.id, d.challan_no, d.despatch_date, c.company_name,
        d.consignee_name, d.consignee_city, d.consignee_state, d.consignee_gstin,
        t.transporter_name, d.vehicle_no, d.lr_number,
        d.freight_paid_by, d.freight_amount, d.vendor_freight_amount, d.total_weight,
        d.subtotal, d.gst_amount, d.total_amount, d.status,
        po.po_number, v.vendor_name, s.source_name,
        d.agent_id, ag.full_name AS agent_name
        FROM despatch_orders d
        LEFT JOIN transporters t ON d.transporter_id=t.id
        LEFT JOIN companies c ON d.company_id=c.id
        LEFT JOIN purchase_orders po ON d.po_id=po.id
        LEFT JOIN vendors v ON d.vendor_id=v.id
        LEFT JOIN source_of_material s ON d.source_of_material_id=s.id
        LEFT JOIN app_users ag ON d.agent_id=ag.id AND ag.is_agent=1
        WHERE 1=1 " . $dw('d.despatch_date') . "
        ORDER BY d.despatch_date DESC")->fetch_all(MYSQLI_ASSOC);
    $workbook['Despatch Orders'] = [
        'headers' => ['ID','Challan No','Date','Company','Vendor','Source','Consignee','City','State','Consignee GSTIN',
                      'Transporter','Vehicle No','LR Number','Freight By','Freight Amt (Trans)','Freight Amt (Vendor)',
                      'Total Weight','Subtotal','GST Amount','Total Amount','Status','PO Number','Agent'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['challan_no'],$r['despatch_date'],$r['company_name'],
            $r['vendor_name'],$r['source_name'],
            $r['consignee_name'],$r['consignee_city'],$r['consignee_state'],$r['consignee_gstin'],
            $r['transporter_name'],$r['vehicle_no'],$r['lr_number'],
            $r['freight_paid_by'],$r['freight_amount'],$r['vendor_freight_amount'],$r['total_weight'],
            $r['subtotal'],$r['gst_amount'],$r['total_amount'],$r['status'],$r['po_number'],$r['agent_name']??''
        ], $rows)
    ];
}

if (in_array('delivery_challans', $selected)) {
    $rows = $db->query("SELECT d.challan_no, d.despatch_date, d.consignee_name,
        i.item_name, i.item_code, i.hsn_code,
        di.description, di.qty, di.weight, di.uom, di.unit_price,
        di.gst_rate, di.gst_amount, di.total_price,
        d.total_weight, t.transporter_name, d.vehicle_no
        FROM despatch_items di
        JOIN despatch_orders d ON di.despatch_id=d.id
        JOIN items i ON di.item_id=i.id
        LEFT JOIN transporters t ON d.transporter_id=t.id
        WHERE 1=1 " . $dw('d.despatch_date') . "
        ORDER BY d.despatch_date DESC, d.id")->fetch_all(MYSQLI_ASSOC);
    $workbook['Delivery Challans'] = [
        'headers' => ['Challan No','Date','Consignee','Item Name','Item Code','HSN Code',
                      'Description','Desp Qty','Rcvd Weight','UOM','Unit Price','GST Rate%','GST Amount',
                      'Total Amount','Total Weight','Transporter','Vehicle No'],
        'rows'    => array_map(fn($r) => [
            $r['challan_no'],$r['despatch_date'],$r['consignee_name'],
            $r['item_name'],$r['item_code'],$r['hsn_code'],$r['description'],
            $r['qty'],$r['weight'],$r['uom'],$r['unit_price'],$r['gst_rate'],
            $r['gst_amount'],$r['total_price'],$r['total_weight'],
            $r['transporter_name'],$r['vehicle_no']
        ], $rows)
    ];
}

if (in_array('sales_invoices', $selected)) {
    $rows = $db->query("SELECT si.id, si.invoice_number, si.invoice_date,
        c.company_name, si.consignee_name, si.consignee_city, si.consignee_state,
        si.consignee_gstin, si.gst_type,
        si.subtotal, si.cgst_amount, si.sgst_amount, si.igst_amount, si.total_amount,
        si.payment_terms, si.due_date,
        si.mrn_number, si.mrn_date,
        si.invoice_reg_number, si.invoice_reg_date,
        si.status, si.remarks,
        do.challan_no,
        COALESCE((SELECT SUM(amount) FROM sales_invoice_payments WHERE invoice_id=si.id),0) AS paid_amount
        FROM sales_invoices si
        LEFT JOIN companies c ON si.company_id=c.id
        LEFT JOIN despatch_orders do ON si.challan_id=do.id
        WHERE 1=1 " . $dw('si.invoice_date') . "
        ORDER BY si.invoice_date DESC")->fetch_all(MYSQLI_ASSOC);
    $workbook['Sales Invoices'] = [
        'headers' => ['ID','Invoice No','Invoice Date','Company','Consignee','City','State',
                      'Consignee GSTIN','GST Type','Subtotal','CGST','SGST','IGST','Total Amount',
                      'Payment Terms','Due Date','MRN Number','MRN Date',
                      'Invoice Reg No','Invoice Reg Date','Status','Remarks',
                      'Linked Challan','Amount Paid','Outstanding'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['invoice_number'],$r['invoice_date'],
            $r['company_name'],$r['consignee_name'],$r['consignee_city'],$r['consignee_state'],
            $r['consignee_gstin'],$r['gst_type'],
            $r['subtotal'],$r['cgst_amount'],$r['sgst_amount'],$r['igst_amount'],$r['total_amount'],
            $r['payment_terms'],$r['due_date'],
            $r['mrn_number'],$r['mrn_date'],
            $r['invoice_reg_number'],$r['invoice_reg_date'],
            $r['status'],$r['remarks'],$r['challan_no'],
            $r['paid_amount'],round($r['total_amount']-$r['paid_amount'],2)
        ], $rows)
    ];
}

if (in_array('transporter_payments', $selected)) {
    $rows = $db->query("SELECT tp.id, tp.payment_date, t.transporter_name,
        d.challan_no, d.despatch_date,
        tp.payment_no, tp.payment_type, tp.amount, tp.base_amount,
        tp.gst_type, tp.gst_rate, tp.gst_amount, tp.gst_held,
        tp.tds_rate, tp.tds_amount, tp.net_payable,
        tp.payment_mode, tp.reference_no,
        tp.status, tp.remarks
        FROM transporter_payments tp
        LEFT JOIN transporters t ON tp.transporter_id=t.id
        LEFT JOIN despatch_orders d ON tp.despatch_id=d.id
        WHERE 1=1 " . $dw('tp.payment_date') . "
        ORDER BY tp.payment_date DESC")->fetch_all(MYSQLI_ASSOC);
    $workbook['Transporter Payments'] = [
        'headers' => ['ID','Payment Date','Transporter','Challan No','Despatch Date',
                      'Payment No','Payment Type','Amount','Base Amount',
                      'GST Type','GST Rate%','GST Amount','GST Held',
                      'TDS Rate%','TDS Amount','Net Payable',
                      'Payment Mode','Reference No','Status','Remarks'],
        'rows'    => array_map(fn($r) => [
            $r['id'],$r['payment_date'],$r['transporter_name'],
            $r['challan_no'],$r['despatch_date'],
            $r['payment_no'],$r['payment_type'],$r['amount'],$r['base_amount'],
            $r['gst_type'],$r['gst_rate'],$r['gst_amount'],$r['gst_held'],
            $r['tds_rate'],$r['tds_amount'],$r['net_payable'],
            $r['payment_mode'],$r['reference_no'],
            $r['status'],$r['remarks']
        ], $rows)
    ];
}

if (in_array('vendors', $selected)) {
    $rows = $db->query("SELECT id, vendor_code, vendor_name,
        address, city, state, pincode, gstin,
        ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_gstin,
        bill_address, bill_city, bill_state, bill_pincode, bill_gstin,
        status
        FROM vendors ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
    $workbook['Vendors'] = [
        'headers' => ['ID','Code','Vendor Name',
                      'Address','City','State','Pincode','GSTIN',
                      'Ship Name','Ship Address','Ship City','Ship State','Ship Pincode','Ship GSTIN',
                      'Bill Address','Bill City','Bill State','Bill Pincode','Bill GSTIN',
                      'Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

if (in_array('items', $selected)) {
    $rows = $db->query("SELECT id, item_code, item_name, description, uom, hsn_code, status
        FROM items ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);
    $workbook['Items'] = [
        'headers' => ['ID','Item Code','Item Name','Description','UOM','HSN Code','Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

if (in_array('transporters', $selected)) {
    $rows = $db->query("SELECT id, transporter_code, transporter_name, contact_person,
        phone, email, address, city, state, gstin, pan,
        gst_type, tds_applicable, status
        FROM transporters ORDER BY transporter_name")->fetch_all(MYSQLI_ASSOC);
    $workbook['Transporters'] = [
        'headers' => ['ID','Code','Transporter Name','Contact Person','Phone','Email',
                      'Address','City','State','GSTIN','PAN','GST Type','TDS Applicable','Status'],
        'rows'    => array_map(fn($r) => array_values($r), $rows)
    ];
}

// ── Generate XLSX ─────────────────────────────────────────────
$company = getCompany();
$fname   = 'DMS_Export_' . date('Y-m-d') . '.xlsx';

// Header colours
$COL_HEADER_BG = 'FF1A5632'; // dark navy
$COL_HEADER_FG = 'FFFFFFFF'; // white
$COL_ALT_BG    = 'FFF0F8F3'; // light blue-grey

function xlsxEsc($v) {
    return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function buildSheet($sheetName, $headers, $rows) {
    $COL_HEADER_BG = '1A5632';
    $COL_ALT_BG    = 'F0F8F3';
    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetData>';

    // Header row
    $xml .= '<row r="1">';
    $col = 0;
    foreach ($headers as $h) {
        $col++;
        $cellRef = colLetter($col) . '1';
        $xml .= '<c r="'.$cellRef.'" t="inlineStr" s="1"><is><t>'.xlsxEsc($h).'</t></is></c>';
    }
    $xml .= '</row>';

    // Data rows
    $ri = 2;
    foreach ($rows as $row) {
        $isAlt = ($ri % 2 === 0);
        $xml .= '<row r="'.$ri.'">';
        $col = 0;
        foreach ($row as $val) {
            $col++;
            $cellRef = colLetter($col) . $ri;
            $styleIdx = $isAlt ? 3 : 2;
            if ($val === null || $val === '') {
                $xml .= '<c r="'.$cellRef.'" s="'.$styleIdx.'"/>';
            } elseif (is_numeric($val) && !preg_match('/^0\d/', (string)$val)) {
                $xml .= '<c r="'.$cellRef.'" s="'.$styleIdx.'"><v>'.xlsxEsc($val).'</v></c>';
            } else {
                $xml .= '<c r="'.$cellRef.'" t="inlineStr" s="'.$styleIdx.'"><is><t>'.xlsxEsc($val).'</t></is></c>';
            }
        }
        $xml .= '</row>';
        $ri++;
    }

    $xml .= '</sheetData>';

    // Auto filter
    if (count($headers) > 0 && count($rows) > 0) {
        $lastCol = colLetter(count($headers));
        $lastRow = count($rows) + 1;
        $xml .= '<autoFilter ref="A1:'.$lastCol.$lastRow.'"/>';
    }

    $xml .= '</worksheet>';
    return $xml;
}

function colLetter($n) {
    $letters = '';
    while ($n > 0) {
        $rem = ($n - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $n = (int)(($n - 1) / 26);
    }
    return $letters;
}

// Styles XML
function buildStyles() {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts>
    <font><sz val="10"/><name val="Arial"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><sz val="10"/><name val="Arial"/></font>
    <font><sz val="10"/><name val="Arial"/></font>
  </fonts>
  <fills>
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1A5632"/></patternFill></fill>
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F8F3"/></patternFill></fill>
  </fills>
  <borders>
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFCCCCCC"/></left>
      <right style="thin"><color rgb="FFCCCCCC"/></right>
      <top style="thin"><color rgb="FFCCCCCC"/></top>
      <bottom style="thin"><color rgb="FFCCCCCC"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">
      <alignment horizontal="left" vertical="center" wrapText="0"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1">
      <alignment vertical="center"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1">
      <alignment vertical="center"/>
    </xf>
  </cellXfs>
</styleSheet>';
}

// Build ZIP (XLSX is a ZIP file)
$tmpdir = sys_get_temp_dir() . '/dms_xlsx_' . uniqid();
mkdir($tmpdir);
mkdir("$tmpdir/_rels");
mkdir("$tmpdir/xl");
mkdir("$tmpdir/xl/_rels");
mkdir("$tmpdir/xl/worksheets");
mkdir("$tmpdir/docProps");

// Write sheet XMLs and collect references
$sheetMeta = [];
$sheetIdx  = 1;
foreach ($workbook as $sheetName => $sheetData) {
    $xml = buildSheet($sheetName, $sheetData['headers'], $sheetData['rows']);
    file_put_contents("$tmpdir/xl/worksheets/sheet{$sheetIdx}.xml", $xml);
    $sheetMeta[] = ['name' => $sheetName, 'idx' => $sheetIdx, 'count' => count($sheetData['rows'])];
    $sheetIdx++;
}

// workbook.xml
$wbXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
$wbXml .= '<sheets>';
foreach ($sheetMeta as $sm) {
    $wbXml .= '<sheet name="'.xlsxEsc($sm['name']).'" sheetId="'.$sm['idx'].'" r:id="rId'.$sm['idx'].'"/>';
}
$wbXml .= '</sheets></workbook>';
file_put_contents("$tmpdir/xl/workbook.xml", $wbXml);

// xl/_rels/workbook.xml.rels
$relsXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$relsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
foreach ($sheetMeta as $sm) {
    $relsXml .= '<Relationship Id="rId'.$sm['idx'].'"
        Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
        Target="worksheets/sheet'.$sm['idx'].'.xml"/>';
}
$relsXml .= '<Relationship Id="rId'.($sheetIdx).'"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>';
$relsXml .= '</Relationships>';
file_put_contents("$tmpdir/xl/_rels/workbook.xml.rels", $relsXml);

// styles.xml
file_put_contents("$tmpdir/xl/styles.xml", buildStyles());

// [Content_Types].xml
$ctXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$ctXml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
$ctXml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
$ctXml .= '<Default Extension="xml" ContentType="application/xml"/>';
$ctXml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
foreach ($sheetMeta as $sm) {
    $ctXml .= '<Override PartName="/xl/worksheets/sheet'.$sm['idx'].'.xml"
        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$ctXml .= '<Override PartName="/xl/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
$ctXml .= '</Types>';
file_put_contents("$tmpdir/[Content_Types].xml", $ctXml);

// _rels/.rels
$rootRels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$rootRels .= '<Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="xl/workbook.xml"/>';
$rootRels .= '</Relationships>';
file_put_contents("$tmpdir/_rels/.rels", $rootRels);

// Create ZIP
$zipfile = sys_get_temp_dir() . '/' . $fname;
if (file_exists($zipfile)) unlink($zipfile);

$zip = new ZipArchive();
$zip->open($zipfile, ZipArchive::CREATE);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpdir));
foreach ($files as $file) {
    if ($file->isDir()) continue;
    $localPath = str_replace($tmpdir . '/', '', $file->getPathname());
    $zip->addFile($file->getPathname(), $localPath);
}
$zip->close();

// Cleanup temp dir
array_map('unlink', glob("$tmpdir/xl/worksheets/*.xml"));
array_map('unlink', glob("$tmpdir/xl/*.xml"));
array_map('unlink', glob("$tmpdir/xl/_rels/*.rels"));
array_map('unlink', glob("$tmpdir/_rels/.rels"));
array_map('unlink', glob("$tmpdir/*.xml"));
@rmdir("$tmpdir/xl/worksheets");
@rmdir("$tmpdir/xl/_rels");
@rmdir("$tmpdir/xl");
@rmdir("$tmpdir/_rels");
@rmdir("$tmpdir");

// Send file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($zipfile));
header('Cache-Control: no-cache');
readfile($zipfile);
unlink($zipfile);
exit;
