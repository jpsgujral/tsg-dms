<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
requirePerm('despatch', 'view');


/* ── Safe ALTER: works on MySQL 5.6+ (no IF NOT EXISTS support) ── */
function safeAddColumn($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '$dbname'
          AND TABLE_NAME   = '$table'
          AND COLUMN_NAME  = '$column'
        LIMIT 1
    ")->num_rows;
    if (!$exists) {
        $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

/* ── Auto-number generators (MAX-based — safe if records deleted) ── */
/* Peek — returns next number WITHOUT incrementing sequence (safe for display) */
/* ── FY-based Challan Number Generator ── */
function currentFY() {
    $m = (int)date('m'); $y = (int)date('Y');
    $fy_start = $m >= 4 ? $y : $y - 1;
    $fy_end   = $fy_start + 1;
    return str_pad($fy_start % 100, 2, '0', STR_PAD_LEFT) . str_pad($fy_end % 100, 2, '0', STR_PAD_LEFT);
}

/* Ensure sequence table and row exist (safe to call multiple times) */
function _fySeqEnsure($db, $key) {
    static $done = [];
    if (isset($done[$key])) return;
    $db->query("CREATE TABLE IF NOT EXISTS doc_sequences (seq_key VARCHAR(50) PRIMARY KEY, last_val INT UNSIGNED NOT NULL DEFAULT 0)");
    // Only insert if missing — no UPDATE on duplicate
    $db->query("INSERT IGNORE INTO doc_sequences (seq_key, last_val) VALUES ('$key', 0)");
    // Seed from company_settings if still 0
    $cur = (int)$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc()['last_val'];
    if ($cur === 0) {
        $res = $db->query("SELECT fy_start_no FROM company_settings LIMIT 1");
        $seed = $res ? (int)($res->fetch_assoc()['fy_start_no'] ?? 0) : 0;
        if ($seed > 1) {
            $db->query("UPDATE doc_sequences SET last_val=" . ($seed - 1) . " WHERE seq_key='$key' AND last_val=0");
        }
    }
    $done[$key] = true;
}

/* Peek — returns next number WITHOUT incrementing (safe for form display) */
function peekChallanNo($db) {
    $fy  = currentFY();
    $key = "challan_fy{$fy}";
    _fySeqEnsure($db, $key);
    $cur = (int)$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc()['last_val'];
    return "DC/{$fy}/" . str_pad($cur + 1, 4, '0', STR_PAD_LEFT);
}

/* Generate — atomic increment, returns BOTH challan_no and despatch_no in one call.
   This is the ONLY function that increments the sequence. Called ONCE per INSERT. */
function generateChallanAndDespatchNo($db) {
    $fy  = currentFY();
    $key = "challan_fy{$fy}";
    _fySeqEnsure($db, $key);
    // Atomic increment — single UPDATE, then read
    $db->query("UPDATE doc_sequences SET last_val = last_val + 1 WHERE seq_key='$key'");
    $next = (int)$db->query("SELECT last_val FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc()['last_val'];
    $num  = str_pad($next, 4, '0', STR_PAD_LEFT);
    return [
        'challan_no'  => "DC/{$fy}/{$num}",
        'despatch_no' => "DSP/{$fy}/{$num}",
    ];
}

/* Peek despatch no (display only) */
function peekDespatchNo($db) { return str_replace('DC/', 'DSP/', peekChallanNo($db)); }



// Ensure consignee_contact column exists (added in this update)
safeAddColumn($db, 'despatch_orders', 'consignee_contact', 'VARCHAR(150) AFTER consignee_gstin');
// Allow NULL for despatch qty (reference only, not used in calculations)
$db->query("ALTER TABLE despatch_items MODIFY COLUMN qty DECIMAL(10,2) DEFAULT NULL");

// Source of material + MTC columns
safeAddColumn($db, 'despatch_orders', 'source_of_material_id', 'INT DEFAULT 0');
safeAddColumn($db, 'despatch_orders', 'mtc_required',       "ENUM('No','Yes') NOT NULL DEFAULT 'No'");
safeAddColumn($db, 'despatch_orders', 'mtc_source',          "VARCHAR(120) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'mtc_item_name',       "VARCHAR(120) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'mtc_test_date',       "DATE DEFAULT NULL");
safeAddColumn($db, 'despatch_orders', 'mtc_ros_45',          "VARCHAR(30) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'mtc_moisture',        "VARCHAR(30) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'mtc_loi',             "VARCHAR(30) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'mtc_fineness',        "VARCHAR(30) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'mtc_remarks',         "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'doc_mtc',             "VARCHAR(255) DEFAULT ''");

// Delivery document upload columns
safeAddColumn($db, 'despatch_orders', 'doc_delivery_challan',   "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'doc_vendor_receipt',     "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'doc_weightbridge',       "VARCHAR(255) DEFAULT ''");
// Transporter Freight Invoice columns
safeAddColumn($db, 'despatch_orders', 'freight_inv_no',       "VARCHAR(60) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'freight_inv_date',     "DATE DEFAULT NULL");
safeAddColumn($db, 'despatch_orders', 'freight_inv_amount',   "DECIMAL(10,2) DEFAULT 0");
safeAddColumn($db, 'despatch_orders', 'freight_inv_type',     "ENUM('','Scan','Digital') DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'freight_inv_file',     "VARCHAR(255) DEFAULT ''");
safeAddColumn($db, 'despatch_orders', 'freight_inv_hardcopy', "TINYINT(1) DEFAULT 0");
safeAddColumn($db, 'despatch_orders', 'agent_id',              "INT DEFAULT NULL COMMENT 'FK app_users.id'");
safeAddColumn($db, 'despatch_orders', 'vendor_freight_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Freight charged to vendor'");
// Fix any NULL vendor_freight_amount from before NOT NULL was set
$db->query("UPDATE despatch_orders SET vendor_freight_amount=0 WHERE vendor_freight_amount IS NULL");

/* ── Commission ledger tables ── */
$db->query("CREATE TABLE IF NOT EXISTS agent_commissions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    despatch_id     INT NOT NULL,
    agent_id        INT NOT NULL,
    challan_no      VARCHAR(60) DEFAULT '',
    despatch_date   DATE DEFAULT NULL,
    vendor_name     VARCHAR(150) DEFAULT '',
    received_weight DECIMAL(10,3) DEFAULT 0,
    vendor_rate     DECIMAL(10,4) DEFAULT 0  COMMENT 'freight_amount / weight',
    transporter_rate DECIMAL(10,4) DEFAULT 0,
    profit_per_mt   DECIMAL(10,4) DEFAULT 0,
    slab_applied    TINYINT(1) DEFAULT 1 COMMENT '1=Slab1, 2=Slab2',
    commission_pct  DECIMAL(5,2) DEFAULT 0,
    commission_amt  DECIMAL(10,2) DEFAULT 0,
    status          ENUM('Pending','Paid') DEFAULT 'Pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_despatch_agent (despatch_id, agent_id)
)");

$db->query("CREATE TABLE IF NOT EXISTS agent_commission_payments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    agent_id      INT NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    paid_date     DATE NOT NULL,
    reference     VARCHAR(100) DEFAULT '',
    notes         TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$db->query("CREATE TABLE IF NOT EXISTS agent_payment_commissions (
    payment_id    INT NOT NULL,
    commission_id INT NOT NULL,
    PRIMARY KEY (payment_id, commission_id)
)");

// Ensure uploads directory exists
$uploads_dir = dirname(__DIR__) . '/uploads/delivery_docs';
if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

/* ── Helper: handle a single file upload, return stored filename or existing ── */
function handleDocUpload($field, $existing, $despatch_id, $suffix, $uploads_dir) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $existing; // keep existing
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) return $existing;
    if ($file['size'] > 10 * 1024 * 1024) return $existing; // 10MB max
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['pdf','jpg','jpeg','png'];
    if (!in_array($ext, $allowed_ext)) return $existing;
    $fname = 'D'.$despatch_id.'_'.$suffix.'_'.date('Ymd_His').'.'.$ext;
    if (move_uploaded_file($file['tmp_name'], $uploads_dir.'/'.$fname)) {
        // Delete old file if replaced
        if ($existing && file_exists($uploads_dir.'/'.$existing)) unlink($uploads_dir.'/'.$existing);
        return $fname;
    }
    return $existing;
}

if (isset($_GET['delete'])) {
    requirePerm('despatch', 'delete');
    $del_id = (int)$_GET['delete'];
    $db->query("DELETE FROM despatch_orders WHERE id=$del_id");
    $db->query("DELETE FROM despatch_items WHERE despatch_id=$del_id");
    $db->query("DELETE FROM agent_commissions WHERE despatch_id=$del_id AND status='Pending'");
    showAlert('success', 'Despatch Order deleted.');
    redirect('despatch.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requirePerm('despatch', $id > 0 ? 'update' : 'create');
    // Delivered orders: only allow document updates (delivery docs + freight invoice)
    if ($id > 0) {
        $existing_status = $db->query("SELECT status FROM despatch_orders WHERE id=$id")->fetch_assoc()['status'] ?? '';
        if ($existing_status === 'Delivered') {
            if (!empty($_POST['docs_only'])) {
                // ── Process ONLY delivery documents and freight invoice ──
                $despatch = $db->query("SELECT * FROM despatch_orders WHERE id=$id")->fetch_assoc();
                $uploads_dir = dirname(__DIR__) . '/uploads/delivery_docs';
                if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

                // Delivery document uploads/removals
                $removeDoc = function($field, $existing) use ($uploads_dir) {
                    if (!empty($_POST['remove_'.$field]) && $existing) {
                        $fp = $uploads_dir.'/'.$existing;
                        if (file_exists($fp)) unlink($fp);
                        return '';
                    }
                    return $existing;
                };
                $cur_dc = $removeDoc('doc_delivery_challan', $despatch['doc_delivery_challan'] ?? '');
                $cur_vr = $removeDoc('doc_vendor_receipt',   $despatch['doc_vendor_receipt']   ?? '');
                $cur_wb = $removeDoc('doc_weightbridge',     $despatch['doc_weightbridge']     ?? '');
                $doc_dc = handleDocUpload('doc_delivery_challan', $cur_dc, $id, 'DC', $uploads_dir);
                $doc_vr = handleDocUpload('doc_vendor_receipt',   $cur_vr, $id, 'VR', $uploads_dir);
                $doc_wb = handleDocUpload('doc_weightbridge',     $cur_wb, $id, 'WB', $uploads_dir);
                $esc = fn($v) => $db->real_escape_string($v);
                $db->query("UPDATE despatch_orders SET
                    doc_delivery_challan='{$esc($doc_dc)}',
                    doc_vendor_receipt='{$esc($doc_vr)}',
                    doc_weightbridge='{$esc($doc_wb)}'
                    WHERE id=$id");

                // Freight invoice fields
                $inv_dir = dirname(__DIR__) . '/uploads/freight_invoices';
                if (!is_dir($inv_dir)) mkdir($inv_dir, 0755, true);
                $fi_no     = $db->real_escape_string(sanitize($_POST['freight_inv_no']     ?? ''));
                $fi_date   = sanitize($_POST['freight_inv_date']   ?? ''); if ($fi_date === '') $fi_date = null;
                $fi_amount = (float)($_POST['freight_inv_amount']  ?? 0);
                $fi_type   = in_array($_POST['freight_inv_type'] ?? '', ['Scan','Digital']) ? $_POST['freight_inv_type'] : '';
                $fi_hc     = ($fi_type === 'Scan') ? (int)(!empty($_POST['freight_inv_hardcopy'])) : 0;
                if ($fi_type === 'Digital') $fi_hc = 1;
                $fi_existing = $despatch['freight_inv_file'] ?? '';
                $fi_file = handleDocUpload('freight_inv_file', $fi_existing, $id, 'FI', $inv_dir);
                $fi_date_sql = $fi_date ? "'".$db->real_escape_string($fi_date)."'" : 'NULL';
                $db->query("UPDATE despatch_orders SET
                    freight_inv_no='$fi_no', freight_inv_date=$fi_date_sql,
                    freight_inv_amount=$fi_amount, freight_inv_type='$fi_type',
                    freight_inv_file='".$db->real_escape_string($fi_file)."',
                    freight_inv_hardcopy=$fi_hc
                    WHERE id=$id");

                showAlert('success', 'Documents updated successfully.');
                redirect("despatch.php?action=edit&id=$id");
            } else {
                showAlert('danger', 'This Despatch Order is Delivered and locked for editing.');
                redirect('despatch.php');
            }
        }
    }
    $f = [
        'despatch_date', 'consignee_name', 'consignee_address',
        'consignee_city', 'consignee_state', 'consignee_pincode', 'consignee_gstin', 'consignee_contact',
        'vehicle_no', 'driver_name', 'driver_mobile',
        'freight_paid_by', 'status'
    ];
    $data = [];
    foreach ($f as $key) $data[$key] = sanitize($_POST[$key] ?? '');
    // Strip backslashes from address fields (prevent DB corruption from over-escaping)
    foreach (['consignee_name','consignee_address','consignee_city','consignee_state','consignee_pincode','consignee_gstin','consignee_contact'] as $af) {
        $data[$af] = str_replace('\\', '', $data[$af]);
    }
    // lr_date always equals despatch_date (removed from form)
    $data['lr_date'] = $data['despatch_date'];
    $data['transporter_id'] = (int)($_POST['transporter_id'] ?? 0);
    $data['vendor_id']              = (int)($_POST['vendor_id']              ?? 0);
    $data['source_of_material_id']  = (int)($_POST['source_of_material_id']  ?? 0);
    $data['mtc_required']  = in_array($_POST['mtc_required']??'No',['Yes','No']) ? $_POST['mtc_required'] : 'No';
    $data['mtc_source']    = sanitize($_POST['mtc_source']    ?? '');
    $data['mtc_item_name'] = sanitize($_POST['mtc_item_name'] ?? '');
    $data['mtc_test_date'] = sanitize($_POST['mtc_test_date'] ?? '');
    $data['mtc_ros_45']    = sanitize($_POST['mtc_ros_45']    ?? '');
    $data['mtc_moisture']  = sanitize($_POST['mtc_moisture']  ?? '');
    $data['mtc_loi']       = sanitize($_POST['mtc_loi']       ?? '');
    $data['mtc_fineness']  = sanitize($_POST['mtc_fineness']  ?? '');
    $data['mtc_remarks']   = sanitize($_POST['mtc_remarks']   ?? '');
    $data['po_id']          = (int)($_POST['po_id']          ?? 0);
    // po_id and transporter_id are already in $data for the SET/INSERT loop
    $data['total_weight'] = (float)($_POST['total_weight'] ?? 0);
    $data['freight_amount']        = (float)($_POST['freight_amount']        ?? 0);
    $data['vendor_freight_amount'] = (float)($_POST['vendor_freight_amount'] ?? 0);
    $data['company_id']   = (int)($_POST['company_id'] ?? activeCompanyId());
    $data['agent_id']     = (int)($_POST['agent_id'] ?? 0) ?: 'NULL';

    // Items
    $item_ids = $_POST['item_id'] ?? [];
    $descs = $_POST['desc'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $uoms = $_POST['uom'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $gst_rates = $_POST['gst_rate'] ?? [];
    $weights = $_POST['weight'] ?? [];

    $subtotal = 0; $gst_total = 0;
    $valid_items = [];
    foreach ($item_ids as $idx => $iid) {
        $iid = (int)$iid;
        $raw_qty = trim($qtys[$idx] ?? '');
        $qty = ($raw_qty === '') ? null : (float)$raw_qty;
        $price = (float)($prices[$idx] ?? 0);
        $gst_rate = (float)($gst_rates[$idx] ?? 0);
        $weight = (float)($weights[$idx] ?? 0);
        $desc = sanitize($descs[$idx] ?? '');
        $uom = sanitize($uoms[$idx] ?? '');
        if ($iid > 0) {
            // Total = PO Unit Rate × Received Weight (+ GST if applicable)
            $line    = $price * $weight;
            $gst_amt = $line * ($gst_rate / 100);
            $total   = $line + $gst_amt;
            $subtotal   += $line;
            $gst_total  += $gst_amt;
            $valid_items[] = compact('iid','desc','qty','uom','price','gst_rate','gst_amt','total','weight');
        }
    }
    $grand_total = $subtotal + $gst_total;

    $errors = [];
    if (empty($data['consignee_name']))       $errors[] = 'Consignee Name is required.';
    if ($data['source_of_material_id'] < 1)   $errors[] = 'Source of Material is required.';
    if ($data['po_id'] < 1)                                      $errors[] = 'PO Reference is required.';
    if ($data['transporter_id'] < 1)                             $errors[] = 'Transporter is required.';
    if (empty($data['vehicle_no']))           $errors[] = 'Vehicle No is required.';
    if (empty($valid_items))                  $errors[] = 'At least one Despatch Item is required.';
    if ($data['status'] === 'Delivered') {
        $total_weight_entered = array_sum(array_column($valid_items, 'weight'));
        if ($total_weight_entered <= 0) $errors[] = 'Received Weight is required when status is Delivered.';
        // Each item must have Received Weight
        foreach ($valid_items as $vi_idx => $vi) {
            if ((float)$vi['weight'] <= 0) {
                $errors[] = 'Item #'.($vi_idx+1).': Received Weight is required for every item when status is Delivered.';
                break;
            }
        }
    }

    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        // Convert empty DATE fields to NULL to avoid MySQL strict mode errors
        $date_fields = ['mtc_test_date', 'lr_date', 'despatch_date'];
        foreach ($date_fields as $df) {
            if (isset($data[$df]) && $data[$df] === '') $data[$df] = null;
        }

        if ($id > 0) {
            // Set lr_number = challan_no (Challan No cum LR No)
        $data['lr_number'] = $despatch['challan_no'] ?? '';
        // UPDATE — never change despatch_no or challan_no
            $set = [];
            foreach ($data as $k => $v) {
                if (is_null($v))             $set[] = "$k=NULL";
                elseif (is_int($v) || is_float($v)) $set[] = "$k=$v";
                else $set[] = "$k='" . $db->real_escape_string($v) . "'";
            }
            $set[] = "subtotal=$subtotal";
            $set[] = "gst_amount=$gst_total";
            $set[] = "total_amount=$grand_total";
            $db->query("UPDATE despatch_orders SET " . implode(',', $set) . " WHERE id=$id");
            $db->query("DELETE FROM despatch_items WHERE despatch_id=$id");
        } else {
            // INSERT — generate BOTH numbers in ONE atomic increment
            $nums = generateChallanAndDespatchNo($db);
            $new_challan_no  = $nums['challan_no'];
            $new_despatch_no = $nums['despatch_no'];
            $data['lr_number'] = $new_challan_no; // Challan No cum LR No

            $cols = 'despatch_no,challan_no,created_by,' . implode(',', array_keys($data)) . ',subtotal,gst_amount,total_amount';
            $vals = [];
            $vals[] = "'" . $db->real_escape_string($new_despatch_no) . "'";
            $vals[] = "'" . $db->real_escape_string($new_challan_no)  . "'";
            $vals[] = (int)($_SESSION['user_id'] ?? 0);
            foreach ($data as $v) {
                if (is_null($v))             $vals[] = 'NULL';
                elseif (is_int($v) || is_float($v)) $vals[] = $v;
                else $vals[] = "'" . $db->real_escape_string($v) . "'";
            }
            $vals[] = $subtotal;
            $vals[] = $gst_total;
            $vals[] = $grand_total;
            $db->query("INSERT INTO despatch_orders ($cols) VALUES (" . implode(',', $vals) . ")");
            $id = $db->insert_id;
        }
        // Link despatch to the latest active rate card for this transporter+vendor pair
        $rc_tid = (int)$data['transporter_id'];
        $rc_vid = (int)($data['vendor_id'] ?? 0);
        if ($rc_tid && $rc_vid) {
            $rc_row = $db->query("SELECT id FROM transporter_rates
                WHERE transporter_id=$rc_tid AND vendor_id=$rc_vid AND status='Active'
                ORDER BY id DESC LIMIT 1")->fetch_assoc();
            if ($rc_row) {
                $db->query("UPDATE despatch_orders SET rate_card_id=".(int)$rc_row['id']." WHERE id=$id");
            }
        }
        foreach ($valid_items as $vi) {
            $qty_sql = is_null($vi['qty']) ? 'NULL' : $vi['qty'];
            $db->query("INSERT INTO despatch_items (despatch_id,item_id,description,qty,uom,unit_price,gst_rate,gst_amount,total_price,weight)
                VALUES ($id,{$vi['iid']},'{$vi['desc']}',$qty_sql,'{$vi['uom']}',{$vi['price']},{$vi['gst_rate']},{$vi['gst_amt']},{$vi['total']},{$vi['weight']})");
        }
        // Handle MTC document upload (always, not just on Delivered)
        $mtc_uploads_dir = dirname(__DIR__) . '/uploads/mtc_docs';
        if (!is_dir($mtc_uploads_dir)) mkdir($mtc_uploads_dir, 0755, true);
        if (!empty($_POST['remove_doc_mtc']) && !empty($despatch['doc_mtc'])) {
            $fp = $mtc_uploads_dir.'/'.$despatch['doc_mtc'];
            if (file_exists($fp)) unlink($fp);
            $doc_mtc = '';
        } else {
            $doc_mtc = handleDocUpload('doc_mtc', $despatch['doc_mtc'] ?? '', $id, 'MTC', $mtc_uploads_dir);
        }
        $esc2 = fn($v) => $db->real_escape_string($v);
        $db->query("UPDATE despatch_orders SET doc_mtc='{$esc2($doc_mtc)}' WHERE id=$id");

        // Handle document uploads/removals (only meaningful when status=Delivered)
        if ($data['status'] === 'Delivered') {
            // Helper: if remove checkbox ticked, delete file and return ''
            $removeDoc = function($field, $existing) use ($uploads_dir) {
                if (!empty($_POST['remove_'.$field]) && $existing) {
                    $fp = $uploads_dir.'/'.$existing;
                    if (file_exists($fp)) unlink($fp);
                    return '';
                }
                return $existing;
            };
            $cur_dc = $removeDoc('doc_delivery_challan', $despatch['doc_delivery_challan'] ?? '');
            $cur_vr = $removeDoc('doc_vendor_receipt',   $despatch['doc_vendor_receipt']   ?? '');
            $cur_wb = $removeDoc('doc_weightbridge',     $despatch['doc_weightbridge']     ?? '');
            $doc_dc = handleDocUpload('doc_delivery_challan', $cur_dc, $id, 'DC', $uploads_dir);
            $doc_vr = handleDocUpload('doc_vendor_receipt',   $cur_vr, $id, 'VR', $uploads_dir);
            $doc_wb = handleDocUpload('doc_weightbridge',     $cur_wb, $id, 'WB', $uploads_dir);
            $esc = fn($v) => $db->real_escape_string($v);
            $db->query("UPDATE despatch_orders SET
                doc_delivery_challan='{$esc($doc_dc)}',
                doc_vendor_receipt='{$esc($doc_vr)}',
                doc_weightbridge='{$esc($doc_wb)}'
                WHERE id=$id");
        }
        // Handle Transporter Freight Invoice
        if ($data['status'] === 'Delivered') {
            $inv_dir = dirname(__DIR__) . '/uploads/freight_invoices';
            if (!is_dir($inv_dir)) mkdir($inv_dir, 0755, true);
            $fi_no     = $db->real_escape_string(sanitize($_POST['freight_inv_no']     ?? ''));
            $fi_date   = sanitize($_POST['freight_inv_date']   ?? ''); if ($fi_date === '') $fi_date = null;
            $fi_amount = (float)($_POST['freight_inv_amount']  ?? 0);
            $fi_type   = in_array($_POST['freight_inv_type'] ?? '', ['Scan','Digital']) ? $_POST['freight_inv_type'] : '';
            $fi_hc     = ($fi_type === 'Scan') ? (int)(!empty($_POST['freight_inv_hardcopy'])) : 0;
            if ($fi_type === 'Digital') $fi_hc = 1; // digital = no hard copy needed
            // Upload invoice file
            $fi_existing = $despatch['freight_inv_file'] ?? '';
            $fi_file = handleDocUpload('freight_inv_file', $fi_existing, $id, 'FI', $inv_dir);
            $fi_date_sql = $fi_date ? "'".$db->real_escape_string($fi_date)."'" : 'NULL';
            $db->query("UPDATE despatch_orders SET
                freight_inv_no='$fi_no', freight_inv_date=$fi_date_sql,
                freight_inv_amount=$fi_amount, freight_inv_type='$fi_type',
                freight_inv_file='".$db->real_escape_string($fi_file)."',
                freight_inv_hardcopy=$fi_hc
                WHERE id=$id");
        }
        // ── Commission Calculation on Delivered ──
        if ($data['status'] === 'Delivered') {
            $agent_id = (int)($_POST['agent_id'] ?? 0);
            if ($agent_id > 0) {
                // Get agent slab settings
                $agent = $db->query("SELECT full_name, is_agent, slab1_upto, slab1_pct, slab2_pct
                    FROM app_users WHERE id=$agent_id AND is_agent=1")->fetch_assoc();
                if ($agent) {
                    $weight = (float)$data['total_weight'];
                    // Vendor rate = PO unit price for this despatch's PO
                    $vendor_rate = 0;
                    $po_id_comm  = (int)$data['po_id'];
                    $v_id        = (int)$data['vendor_id'];
                    if ($po_id_comm > 0) {
                        // Get unit_price from PO items (first item's price as rate)
                        $po_rate_row = $db->query("SELECT unit_price FROM po_items
                            WHERE po_id=$po_id_comm LIMIT 1")->fetch_assoc();
                        $vendor_rate = (float)($po_rate_row['unit_price'] ?? 0);
                    }
                    // Transporter rate from rate card
                    $trans_rate = 0;
                    $t_id = (int)$data['transporter_id'];
                    if ($t_id && $v_id) {
                        $tr_row = $db->query("SELECT rate FROM transporter_rates
                            WHERE transporter_id=$t_id AND vendor_id=$v_id AND status='Active' ORDER BY id DESC LIMIT 1")->fetch_assoc();
                        $trans_rate = (float)($tr_row['rate'] ?? 0);
                    }
                    $profit_per_mt = round($vendor_rate - $trans_rate, 4);
                    $threshold     = (float)$agent['slab1_upto'];
                    $slab          = ($profit_per_mt <= $threshold) ? 1 : 2;
                    $pct           = $slab === 1 ? (float)$agent['slab1_pct'] : (float)$agent['slab2_pct'];
                    $comm_amt      = round($profit_per_mt * ($pct / 100) * $weight, 2);
                    $vendor_name   = $db->real_escape_string($data['consignee_name'] ?? '');
                    $challan_no    = $db->real_escape_string($data['challan_no'] ?? '');
                    if (empty($challan_no) && $id > 0) {
                        // For existing records, fetch challan_no from DB
                        $cn_row = $db->query("SELECT challan_no, despatch_date FROM despatch_orders WHERE id=$id")->fetch_assoc();
                        $challan_no = $db->real_escape_string($cn_row['challan_no'] ?? '');
                        $data['despatch_date'] = $data['despatch_date'] ?: ($cn_row['despatch_date'] ?? '');
                    }
                    $desp_date     = $data['despatch_date'] ?? null;
                    $desp_date_sql = $desp_date ? "'".$db->real_escape_string($desp_date)."'" : 'NULL';
                    // Upsert commission record
                    $db->query("INSERT INTO agent_commissions
                        (despatch_id, agent_id, challan_no, despatch_date, vendor_name,
                         received_weight, vendor_rate, transporter_rate, profit_per_mt,
                         slab_applied, commission_pct, commission_amt, status)
                        VALUES ($id, $agent_id, '$challan_no', $desp_date_sql, '$vendor_name',
                                $weight, $vendor_rate, $trans_rate, $profit_per_mt,
                                $slab, $pct, $comm_amt, 'Pending')
                        ON DUPLICATE KEY UPDATE
                            agent_id=$agent_id,
                            challan_no='$challan_no', despatch_date=$desp_date_sql,
                            vendor_name='$vendor_name', received_weight=$weight,
                            vendor_rate=$vendor_rate, transporter_rate=$trans_rate,
                            profit_per_mt=$profit_per_mt, slab_applied=$slab,
                            commission_pct=$pct, commission_amt=$comm_amt,
                            status=IF(status='Paid','Paid','Pending')");
                }
            } else {
                // Agent removed or not set — remove any pending commission for this despatch
                $db->query("DELETE FROM agent_commissions WHERE despatch_id=$id AND status='Pending'");
            }
        } else {
            // Status is NOT Delivered — clean up any pending commissions and reset weight/totals
            $db->query("DELETE FROM agent_commissions WHERE despatch_id=$id AND status='Pending'");
            // Reset weight and financial fields to 0 since not yet delivered
            $db->query("UPDATE despatch_orders SET total_weight=0, subtotal=0, gst_amount=0, total_amount=0 WHERE id=$id");
            $db->query("UPDATE despatch_items SET weight=0, unit_price=0, gst_rate=0, gst_amount=0, total_price=0 WHERE despatch_id=$id");
        }

        showAlert('success', 'Despatch Order saved successfully.');
        redirect('despatch.php');
    }
}

/* ── AJAX: fetch transporter-vendor rate ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_get_rate'])) {
    header('Content-Type: application/json');
    $tid = (int)($_POST['transporter_id'] ?? 0);
    $vid = (int)($_POST['vendor_id']      ?? 0);
    if ($tid && $vid) {
        $row = $db->query("SELECT tr.rate, tr.uom, v.vendor_name
            FROM transporter_rates tr
            JOIN vendors v ON tr.vendor_id = v.id
            WHERE tr.transporter_id=$tid AND tr.vendor_id=$vid AND tr.status='Active'
            ORDER BY tr.id DESC LIMIT 1")->fetch_assoc();
        if ($row) {
            echo json_encode(['ok'=>true, 'rate'=>(float)$row['rate'], 'uom'=>$row['uom'], 'vendor'=>$row['vendor_name']]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>'No rate defined for this transporter-vendor combination.']);
        }
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'Invalid parameters.']);
    }
    exit;
}

$despatch = [];
$despatch_items = [];
if (($action == 'edit') && $id > 0) {
    $despatch = $db->query("SELECT * FROM despatch_orders WHERE id=$id")->fetch_assoc();
    $despatch_items = $db->query("SELECT di.*, i.item_name FROM despatch_items di JOIN items i ON di.item_id=i.id WHERE di.despatch_id=$id")->fetch_all(MYSQLI_ASSOC);
}
// Lock form if existing record is Delivered
$is_locked = ($action === 'edit' && !empty($despatch) && ($despatch['status'] ?? '') === 'Delivered');

/* ── Sources of material ── */
$sources_list = $db->query("SELECT id, source_name FROM source_of_material WHERE status='Active' ORDER BY source_name")->fetch_all(MYSQLI_ASSOC);
/* ── Users list for email modal ── */
$email_users = $db->query("SELECT id, full_name, email, role FROM app_users WHERE status='Active' AND email != '' ORDER BY role ASC, full_name ASC")->fetch_all(MYSQLI_ASSOC);

/* ── PO balance quantities ── */
$po_balance_map = []; // po_id -> item_id -> ['po_qty'=>x,'despatched'=>x,'balance'=>x]
$po_bal_res = $db->query("
    SELECT pi.po_id, pi.item_id, i.item_name, pi.qty AS po_qty,
           COALESCE(SUM(di.weight),0) AS despatched_qty
    FROM po_items pi
    JOIN items i ON pi.item_id = i.id
    LEFT JOIN despatch_items di ON di.item_id = pi.item_id
        AND di.despatch_id IN (
            SELECT id FROM despatch_orders
            WHERE po_id = pi.po_id AND status NOT IN ('Cancelled','Draft')
        )
    GROUP BY pi.po_id, pi.item_id
");
if ($po_bal_res) {
    while ($pbr = $po_bal_res->fetch_assoc()) {
        $po_balance_map[(int)$pbr['po_id']][(int)$pbr['item_id']] = [
            'item_name'  => $pbr['item_name'],
            'po_qty'     => (float)$pbr['po_qty'],
            'despatched' => (float)$pbr['despatched_qty'],
            'balance'    => max(0, (float)$pbr['po_qty'] - (float)$pbr['despatched_qty']),
        ];
    }
}

$vendors = $db->query("
    SELECT id, vendor_code, vendor_name,
           ship_name, ship_address, ship_city, ship_state, ship_pincode, ship_country, ship_gstin,
           ship_contact, ship_phone,
           bill_address, bill_city, bill_state, bill_pincode, bill_country, bill_gstin,
           address, city, state, pincode, country, gstin
    FROM vendors WHERE status='Active' ORDER BY vendor_name
")->fetch_all(MYSQLI_ASSOC);
$transporters = $db->query("SELECT id, transporter_code, transporter_name FROM transporters WHERE status='Active' ORDER BY transporter_name")->fetch_all(MYSQLI_ASSOC);
$pos = $db->query("SELECT id, po_number, vendor_id FROM purchase_orders WHERE status IN ('Approved','Partially Received') ORDER BY po_date DESC")->fetch_all(MYSQLI_ASSOC);

// Build PO->vendor map for JS
$po_vendor_map = [];
foreach ($pos as $p) { $po_vendor_map[(int)$p['id']] = (int)$p['vendor_id']; }

// Build PO->items unit_price and gst_rate maps for JS
$po_items_map    = [];  // po_id -> item_id -> unit_price
$po_gst_map      = [];  // po_id -> item_id -> gst_rate
$po_items_detail = [];  // po_id -> [ {item_id, item_name, item_code, uom, unit_price, gst_rate, qty} ]
$po_items_res = $db->query("SELECT pi.po_id, pi.item_id, pi.unit_price, pi.gst_rate, pi.qty,
    i.item_name, i.item_code, i.uom
    FROM po_items pi JOIN items i ON pi.item_id=i.id");
if ($po_items_res) {
    while ($pirow = $po_items_res->fetch_assoc()) {
        $pid = (int)$pirow['po_id'];
        $iid = (int)$pirow['item_id'];
        $po_items_map[$pid][$iid]    = (float)$pirow['unit_price'];
        $po_gst_map[$pid][$iid]      = (float)$pirow['gst_rate'];
        $po_items_detail[$pid][]     = [
            'item_id'   => $iid,
            'item_name' => $pirow['item_name'],
            'item_code' => $pirow['item_code'],
            'uom'       => $pirow['uom'],
            'unit_price'=> (float)$pirow['unit_price'],
            'gst_rate'  => (float)$pirow['gst_rate'],
            'qty'       => (float)$pirow['qty'],
        ];
    }
}

$items_list  = $db->query("SELECT id, item_code, item_name, uom FROM items WHERE status='Active' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);
$agents_list = $db->query("SELECT id, full_name FROM app_users WHERE is_agent=1 AND status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Use peek (no increment) for display — generate only fires on actual INSERT
$chl_no = $despatch['challan_no']  ?? peekChallanNo($db);
$dsp_no = $despatch['despatch_no'] ?? peekDespatchNo($db);
$despatch_date_val = $despatch['despatch_date'] ?? date('Y-m-d');

$all_companies = $db->query("SELECT id, company_name FROM companies ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-send-check me-2"></i>Despatch Orders';</script>

<?php if ($action == 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">All Despatch Orders</h5>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> New Despatch</a>
</div>
<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0" id="despatchTable">
    <thead><tr><th>#</th><th>Challan No</th><th>Date</th><th>Consignee</th><th>Transporter</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php
    $list = $db->query("SELECT d.*,t.transporter_name,s.source_name,c.company_name AS co_name FROM despatch_orders d LEFT JOIN transporters t ON d.transporter_id=t.id LEFT JOIN source_of_material s ON d.source_of_material_id=s.id LEFT JOIN companies c ON d.company_id=c.id ORDER BY d.consignee_name ASC, d.despatch_date DESC, d.id DESC");
    $rows = $list->fetch_all(MYSQLI_ASSOC);

    // Group by consignee_name
    $groups = [];
    foreach ($rows as $v) {
        $key = trim($v['consignee_name']);
        $groups[$key][] = $v;
    }
    $gi = 0; $rowNum = 1;
    foreach ($groups as $consignee => $orders):
        $gi++;
        $groupId   = 'grp_d_' . $gi;
        $totalAmt  = array_sum(array_column($orders, 'total_amount'));
        $count     = count($orders);
        $city      = $orders[0]['consignee_city'] ?? '';
    ?>
    <!-- Consignee group header row -->
    <tr class="table-primary consignee-group-header" style="cursor:pointer" onclick="toggleGroup('<?= $groupId ?>', this)">
        <td colspan="6">
            <span class="group-toggle-icon me-2">▾</span>
            <strong><?= htmlspecialchars($consignee) ?></strong>
            <?php if ($city): ?><small class="text-muted ms-2"><?= htmlspecialchars($city) ?></small><?php endif; ?>
            <span class="badge bg-secondary ms-2"><?= $count ?> order<?= $count>1?'s':'' ?></span>
        </td>
        <td class="text-end"><strong>₹<?= number_format($totalAmt,2) ?></strong></td>
        <td colspan="2"></td>
    </tr>
    <?php foreach ($orders as $v):
        $b=['Draft'=>'secondary','Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'][$v['status']] ?? 'secondary';
    ?>
    <tr class="group-row <?= $groupId ?>">
        <td class="ps-4 text-muted"><?= $rowNum++ ?></td>
        <td><strong><?= htmlspecialchars($v['challan_no']) ?></strong></td>
        <td><?= date('d/m/Y', strtotime($v['despatch_date'])) ?><?php if(!empty($v['co_name'])): ?><br><span class="badge bg-primary" style="font-size:.65rem"><?= htmlspecialchars($v['co_name']) ?></span><?php endif; ?></td>
        <td><small class="text-muted"><?= htmlspecialchars($v['consignee_city']) ?></small></td>
        <td><?= htmlspecialchars($v['transporter_name'] ?? '-') ?></td>
        <td>₹<?= number_format((float)($v['total_amount'] ?? 0),2) ?></td>
        <td><span class="badge bg-<?= $b ?>"><?= $v['status'] ?></span></td>
        <td>
            <a href="print_challan.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-action btn-outline-success me-1" title="Print Challan"><i class="bi bi-printer"></i></a>
            <a href="export_challan_pdf.php?id=<?= $v['id'] ?>&download" class="btn btn-action btn-outline-danger me-1" title="Download PDF"><i class="bi bi-file-earmark-pdf"></i></a>
            <button onclick="openEmailModal(<?= $v['id'] ?>,<?= htmlspecialchars(json_encode($v['challan_no'])) ?>,<?= htmlspecialchars(json_encode($v['consignee_name'])) ?>)"
                    class="btn btn-action btn-outline-info me-1" title="Send Email"><i class="bi bi-envelope"></i></button>
            <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
            <button onclick="confirmDelete(<?= $v['id'] ?>,'despatch.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<style>
.consignee-group-header td { background: #e5f5eb !important; border-top: 2px solid #1a5632 !important; }
.consignee-group-header:hover td { background: #d5e3f0 !important; }
.group-row td { background: #fff; }
.group-row:hover td { background: #f8f9fa !important; }
</style>
<script>
function toggleGroup(id, headerRow) {
    var rows   = document.querySelectorAll('.' + id);
    var icon   = headerRow.querySelector('.group-toggle-icon');
    var hidden = rows.length && rows[0].style.display === 'none';
    rows.forEach(function(r){ r.style.display = hidden ? '' : 'none'; });
    icon.textContent = hidden ? '▾' : '▸';
}
// Expand all groups by default
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.consignee-group-header').forEach(function(h){
        // all expanded on load — nothing to do
    });
    // Add search box
    var card = document.querySelector('#despatchTable').closest('.card');
    var bar = document.createElement('div');
    bar.className = 'px-3 py-2 border-bottom bg-light';
    bar.innerHTML = '<input type="text" id="despatchSearch" class="form-control form-control-sm" placeholder="🔍  Search consignee, challan no, transporter…" style="max-width:360px">';
    card.querySelector('.card-body').insertBefore(bar, card.querySelector('.table-responsive'));
    document.getElementById('despatchSearch').addEventListener('input', function(){
        var q = this.value.toLowerCase();
        document.querySelectorAll('.consignee-group-header').forEach(function(hdr){
            var groupId = hdr.querySelector('.group-toggle-icon').closest('tr').className.match(/grp_d_\d+/) || [];
            // get all sibling data rows
            var sibs = [];
            var next = hdr.nextElementSibling;
            while (next && next.classList.contains('group-row')) { sibs.push(next); next = next.nextElementSibling; }
            var anyMatch = false;
            sibs.forEach(function(r){
                var txt = r.textContent.toLowerCase();
                var match = !q || txt.includes(q);
                r.style.display = match ? '' : 'none';
                if (match) anyMatch = true;
            });
            hdr.style.display = (!q || anyMatch || hdr.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
    });
});
</script>
</div>
</div>
</div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action=='edit'?'Edit':'New' ?> Despatch Order</h5>
    <a href="despatch.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" id="despatchForm" enctype="multipart/form-data">
<?php if ($is_locked): ?>
<input type="hidden" name="docs_only" value="1">
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3 py-2 border-warning" style="background:linear-gradient(135deg,#fff3cd,#fff8e1)">
    <i class="bi bi-lock-fill fs-5 text-warning"></i>
    <div>
        <strong>This Despatch Order is Delivered — main details are locked.</strong><br>
        <small class="text-muted">You can still upload/edit <strong>Delivery Documents</strong> and <strong>Transporter Freight Invoice</strong> below.</small>
    </div>
</div>
<?php endif; ?>
<div class="row g-3">
    <!-- Basic Info -->
    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Despatch Information</div>
    <div class="card-body"><div class="row g-3">
        <?php if (count($all_companies) > 1): ?>
        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label">Company *</label>
            <select name="company_id" class="form-select" required>
                <?php foreach ($all_companies as $co): ?>
                <option value="<?= $co['id'] ?>"
                    <?= ($despatch['company_id'] ?? activeCompanyId()) == $co['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($co['company_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="company_id" value="<?= activeCompanyId() ?>">
        <?php endif; ?>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Challan No cum LR No</label>
            <div class="input-group">
                <span class="input-group-text bg-success text-white"><i class="bi bi-receipt"></i></span>
                <input type="text" class="form-control bg-light fw-bold text-success"
                       value="<?= htmlspecialchars($chl_no) ?>" readonly tabindex="-1"
                       style="letter-spacing:0.5px">
            </div>
            <?php if ($action == 'add'): ?>
            <div class="form-text text-muted"><i class="bi bi-lock-fill me-1"></i>Auto-generated on save</div>
            <?php endif; ?>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Despatch Date *</label>
            <input type="date" name="despatch_date" id="despatch_date" class="form-control" value="<?= $despatch_date_val ?>" required>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="onStatusChange(this)">
                <?php foreach(['Draft','Despatched','In Transit','Delivered','Cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($despatch['status']??'Draft')==$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label">Vendor</label>
            <select name="vendor_id" id="vendorSelect" class="form-select" onchange="fillConsigneeFromVendor(this); filterPOByVendor(this.value); filterTransportersByVendor(this.value);">
                <option value="">-- Select Vendor --</option>
                <?php foreach($vendors as $v): ?>
                <option value="<?= $v['id'] ?>" <?= ($despatch['vendor_id']??0)==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['vendor_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">PO Reference</label>
            <select name="po_id" id="poRefSelect" class="form-select" onchange="fillVendorFromPO(this); renderPOBalance(parseInt(this.value)||0);">
                <option value="">-- None --</option>
                <?php foreach($pos as $p): ?>
                <option value="<?= $p['id'] ?>" data-vendor="<?= $p['vendor_id'] ?>" <?= ($despatch['po_id']??0)==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['po_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label fw-semibold">Source of Material *
                <a href="source_of_material.php?action=add" target="_blank" class="ms-1 text-primary small" title="Add new source">
                    <i class="bi bi-plus-circle"></i>
                </a>
            </label>
            <select name="source_of_material_id" id="sourceOfMaterial" class="form-select" required>
                <option value="">-- Select Source --</option>
                <?php foreach($sources_list as $src): ?>
                <option value="<?= $src['id'] ?>" <?= ($despatch['source_of_material_id']??0)==$src['id']?'selected':'' ?>>
                    <?= htmlspecialchars($src['source_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- PO Balance Quantity -->
        <div class="col-12" id="poBalanceWrap" style="display:none">
            <label class="form-label fw-semibold text-primary">
                <i class="bi bi-bar-chart-steps me-1"></i>PO Balance Quantities
            </label>
            <div id="poBalanceTable"></div>
        </div>

    </div></div></div></div>



    <!-- Consignee -->
    <div class="col-12 col-md-6 d-flex flex-column"><div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-geo-alt me-2"></i>Consignee Details</span>
        <span id="autofillBadge" class="badge bg-success d-none">
            <i class="bi bi-magic me-1"></i>Auto-filled from Vendor Ship-To
        </span>
    </div>
    <div class="card-body"><div class="row g-2">
        <!-- Auto-fill notice strip (hidden until triggered) -->
        <div class="col-12" id="autofillNotice" style="display:none">
            <div class="alert alert-success alert-dismissible py-2 mb-2 d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <span>Consignee details auto-populated from <strong id="autofillVendorName"></strong>'s Ship-To address.
                You can edit any field below.</span>
                <button type="button" class="btn-close ms-auto" onclick="document.getElementById('autofillNotice').style.display='none'"></button>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label">Consignee Name *</label>
            <input type="text" name="consignee_name" id="consignee_name" class="form-control" required value="<?= htmlspecialchars($despatch['consignee_name']??'') ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Address</label>
            <textarea name="consignee_address" id="consignee_address" class="form-control" rows="1"><?= htmlspecialchars($despatch['consignee_address']??'') ?></textarea>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">City</label>
            <input type="text" name="consignee_city" id="consignee_city" class="form-control" value="<?= htmlspecialchars($despatch['consignee_city']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">State</label>
            <input type="text" name="consignee_state" id="consignee_state" class="form-control" value="<?= htmlspecialchars($despatch['consignee_state']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">GSTIN</label>
            <input type="text" name="consignee_gstin" id="consignee_gstin" class="form-control" value="<?= htmlspecialchars($despatch['consignee_gstin']??'') ?>">
        </div>
        <div class="col-12 col-md-6" id="consignee_pincode_wrap">
            <label class="form-label">Pincode</label>
            <input type="text" name="consignee_pincode" id="consignee_pincode" class="form-control" value="<?= htmlspecialchars($despatch['consignee_pincode']??'') ?>">
        </div>
        <div class="col-12 col-md-6" id="consignee_contact_wrap">
            <label class="form-label">Contact at Delivery</label>
            <input type="text" name="consignee_contact" id="consignee_contact" class="form-control"
                   placeholder="Name / Phone" value="<?= htmlspecialchars($despatch['consignee_contact']??'') ?>">
        </div>
        <div class="col-12 text-end">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearConsignee()" title="Clear all consignee fields">
                <i class="bi bi-eraser me-1"></i>Clear
            </button>
        </div>
    </div></div></div></div>

    <!-- Transport -->
    <div class="col-12 col-md-6 d-flex flex-column"><div class="card h-100"><div class="card-header"><i class="bi bi-truck me-2"></i>Transport Details</div>
    <div class="card-body"><div class="row g-2">
        <div class="col-12 col-md-6">
            <label class="form-label">Transporter *</label>
            <select name="transporter_id" id="transporterSelect" class="form-select" required onchange="updateFreightCalc()">
                <option value="">-- Select Transporter --</option>
                <?php foreach($transporters as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($despatch['transporter_id']??0)==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['transporter_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Vehicle No *</label>
            <input type="text" name="vehicle_no" class="form-control" placeholder="MH-01-XX-1234" required value="<?= htmlspecialchars($despatch['vehicle_no']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Driver Name</label>
            <input type="text" name="driver_name" class="form-control" value="<?= htmlspecialchars($despatch['driver_name']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Driver Mobile</label>
            <input type="text" name="driver_mobile" class="form-control" value="<?= htmlspecialchars($despatch['driver_mobile']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-4">
            <label class="form-label">Received Weight</label>
            <input type="number" name="total_weight" id="totalWeightDisplay" step="0.001" class="form-control bg-light"
                   value="<?= $despatch['total_weight']??0 ?>" readonly tabindex="-1">
            <div class="form-text text-muted">Auto-summed from items</div>
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Freight Amount (₹)</label>
            <div class="input-group">
                <span class="input-group-text bg-light">₹</span>
                <input type="number" name="freight_amount" id="freightAmount" step="0.01" class="form-control"
                       value="<?= $despatch['freight_amount']??0 ?>">
            </div>
            <div class="form-text" id="freightFormula"><span class="text-muted">Auto from rate card</span></div>
        </div>

        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Freight Paid By</label>
            <select name="freight_paid_by" class="form-select">
                <option value="Consignee" <?= ($despatch['freight_paid_by']??'Consignee')=='Consignee'?'selected':'' ?>>Consignee</option>
                <option value="Consignor" <?= ($despatch['freight_paid_by']??'')=='Consignor'?'selected':'' ?>>Consignor</option>
            </select>
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Agent / Salesman</label>
            <select name="agent_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($agents_list as $ag): ?>
                <option value="<?= $ag['id'] ?>" <?= ($despatch['agent_id']??0)==$ag['id']?'selected':'' ?>>
                    <?= htmlspecialchars($ag['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Commission calculated on Delivered</div>
        </div>


    </div></div></div></div>

    <!-- Items -->
    <div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between">
        <span><i class="bi bi-list-ul me-2"></i>Despatch Items</span>
        <button type="button" class="btn btn-sm btn-light" onclick="addDRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
    </div>
    <div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0" id="dItemsTable">
        <thead class="table-light"><tr>
            <th>Item</th><th>Description</th><th>UOM</th>
            <th>Despatched Qty <small class="text-muted fw-normal">(Ref)</small></th><th>PO Rate</th><th class="d-price-col">Unit Price</th><th class="d-price-col">GST%</th>
            <th class="d-weight-col">Received Weight</th><th class="d-price-col">Total ₹</th><th></th>
        </tr></thead>
        <tbody id="dItemsBody">
        <?php if (!empty($despatch_items)): foreach($despatch_items as $di): ?>
        <tr>
            <td><select name="item_id[]" class="form-select form-select-sm d-item-select" required onchange="dFillItem(this)">
                <option value="">-- Select --</option>
                <?php foreach($items_list as $il): ?>
                <option value="<?= $il['id'] ?>" data-uom="<?= $il['uom'] ?>"
                    <?= $di['item_id']==$il['id']?'selected':'' ?>><?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?></option>
                <?php endforeach; ?>
            </select></td>
            <td><input type="text" name="desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($di['description']??'') ?>"></td>
            <td>
                <input type="hidden" name="uom[]" class="d-uom-val" value="<?= htmlspecialchars($di['uom']) ?>">
                <input type="text" class="form-control form-control-sm bg-light d-uom-display" value="<?= htmlspecialchars($di['uom']) ?>" readonly tabindex="-1">
            </td>
            <td><input type="number" name="qty[]" class="form-control form-control-sm" value="<?= ($di['qty'] > 0) ? $di['qty'] : '' ?>" step="0.01" placeholder="Optional"></td>
            <td><?php
                $po_rate = $despatch['po_id'] ? ($po_items_map[(int)$despatch['po_id']][(int)$di['item_id']] ?? '') : '';
            ?><input type="text" class="form-control form-control-sm bg-light text-primary fw-semibold d-po-rate" value="<?= $po_rate !== '' ? '₹'.number_format((float)$po_rate,2) : '-' ?>" readonly tabindex="-1"></td>
            <td class="d-price-col"><input type="number" name="unit_price[]" class="form-control form-control-sm d-unit-price" value="<?= $di['unit_price'] ?>" step="0.01" onchange="dCalcRow(this)"></td>
            <td class="d-price-col"><input type="number" name="gst_rate[]" class="form-control form-control-sm" value="<?= $di['gst_rate'] ?>" step="0.01" onchange="dCalcRow(this)"></td>
            <td class="d-weight-col"><input type="number" name="weight[]" class="form-control form-control-sm d-weight" value="<?= $di['weight'] ?>" step="0.001" onchange="dCalcRow(this)"></td>
            <td class="d-price-col"><input type="text" name="total_price[]" class="form-control form-control-sm bg-light fw-semibold d-row-total" value="<?= number_format((float)$di['total_price'],2) ?>" readonly tabindex="-1"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="dRemove(this)"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
            <td><select name="item_id[]" class="form-select form-select-sm d-item-select" onchange="dFillItem(this)">
                <option value="">-- Select --</option>
                <?php foreach($items_list as $il): ?>
                <option value="<?= $il['id'] ?>" data-uom="<?= $il['uom'] ?>"><?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?></option>
                <?php endforeach; ?>
            </select></td>
            <td><input type="text" name="desc[]" class="form-control form-control-sm"></td>
            <td>
                <input type="hidden" name="uom[]" class="d-uom-val">
                <input type="text" class="form-control form-control-sm bg-light d-uom-display" readonly tabindex="-1">
            </td>
            <td><input type="number" name="qty[]" class="form-control form-control-sm" step="0.01" placeholder="Optional"></td>
            <td><input type="text" class="form-control form-control-sm bg-light text-primary fw-semibold d-po-rate" value="-" readonly tabindex="-1"></td>
            <td class="d-price-col"><input type="number" name="unit_price[]" class="form-control form-control-sm d-unit-price" step="0.01" onchange="dCalcRow(this)"></td>
            <td class="d-price-col"><input type="number" name="gst_rate[]" class="form-control form-control-sm" step="0.01" onchange="dCalcRow(this)"></td>
            <td class="d-weight-col"><input type="number" name="weight[]" class="form-control form-control-sm d-weight" step="0.001" value="0" onchange="dCalcRow(this)"></td>
            <td class="d-price-col"><input type="text" name="total_price[]" class="form-control form-control-sm bg-light fw-semibold d-row-total" value="0.00" readonly tabindex="-1"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="dRemove(this)"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endif; ?>
        </tbody>
        <tfoot class="table-light">
            <tr><td colspan="8" class="text-end fw-bold">Grand Total (incl. GST):</td><td colspan="2"><strong id="dGrandTotal">₹0.00</strong></td></tr>
        </tfoot>
    </table>
    <!-- Hidden template row for JS cloning (disabled so not submitted) -->
    <table id="dRowTemplate" style="display:none">
    <tbody><tr>
        <td><select class="form-select form-select-sm d-item-select" disabled>
            <option value="">-- Select --</option>
            <?php foreach($items_list as $il): ?>
            <option value="<?= $il['id'] ?>" data-uom="<?= $il['uom'] ?>"><?= htmlspecialchars($il['item_code'].' - '.$il['item_name']) ?></option>
            <?php endforeach; ?>
        </select></td>
        <td><input type="text" class="form-control form-control-sm" disabled></td>
        <td>
            <input type="text" class="d-uom-val" style="display:none" disabled>
            <input type="text" class="form-control form-control-sm bg-light d-uom-display" readonly tabindex="-1" disabled>
        </td>
        <td><input type="number" class="form-control form-control-sm" step="0.001" placeholder="Optional" disabled></td>
        <td><input type="text" class="form-control form-control-sm bg-light text-primary fw-semibold d-po-rate" value="-" readonly tabindex="-1" disabled></td>
        <td class="d-price-col"><input type="number" class="form-control form-control-sm d-unit-price" step="0.01" disabled></td>
        <td class="d-price-col"><input type="number" class="form-control form-control-sm" step="0.01" disabled></td>
        <td class="d-weight-col"><input type="number" class="form-control form-control-sm d-weight" step="0.001" value="0" disabled></td>
        <td class="d-price-col"><input type="text" class="form-control form-control-sm d-row-total" readonly disabled></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></td>
    </tr></tbody>
    </table>
    </div></div></div></div>

    <!-- MTC Section -->
    <div class="col-12">
    <div class="card border-warning">
        <div class="card-header" style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff">
            <i class="bi bi-patch-check me-2"></i>Material Test Certificate (MTC)
        </div>
        <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-3">
                <label class="form-label fw-bold">MTC Required?</label>
                <div class="d-flex gap-3 mt-1">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mtc_required" id="mtcNo" value="No"
                               <?= ($despatch['mtc_required']??'No')==='No'?'checked':'' ?> onchange="toggleMTC()">
                        <label class="form-check-label fw-semibold text-secondary" for="mtcNo">No</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mtc_required" id="mtcYes" value="Yes"
                               <?= ($despatch['mtc_required']??'')=='Yes'?'checked':'' ?> onchange="toggleMTC()">
                        <label class="form-check-label fw-semibold text-success" for="mtcYes">Yes</label>
                    </div>
                </div>
                <div class="form-text text-warning fw-semibold mt-1" id="mtcChallanNote" style="display:none">
                    <i class="bi bi-info-circle me-1"></i>MTC copy will be attached to Original (Consignee) challan
                </div>
            </div>
        </div>

        <!-- MTC Details — shown only when Yes -->
        <div id="mtcDetails" style="display:none">
        <hr class="my-3">

        <!-- Preview of MTC format header -->
        <div class="alert alert-warning py-2 mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill"></i>
            <span>Fill in the test results below. These will print as a <strong>Material Test Certificate</strong> attached to the Original (Consignee) copy of the Delivery Challan.</span>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label">
                    Source of Material
                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">
                        <i class="bi bi-arrow-up-circle me-1"></i>Auto from Despatch Info
                    </span>
                </label>
                <input type="text" name="mtc_source" id="mtc_source" class="form-control bg-light"
                       readonly tabindex="-1"
                       value="<?= htmlspecialchars($despatch['mtc_source']??'') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">
                    Item Name
                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">
                        <i class="bi bi-arrow-up-circle me-1"></i>Auto from Despatch Items
                    </span>
                </label>
                <input type="text" name="mtc_item_name" id="mtc_item_name" class="form-control bg-light"
                       readonly tabindex="-1"
                       value="<?= htmlspecialchars($despatch['mtc_item_name']??'') ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">
                    Test Date
                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem">
                        <i class="bi bi-arrow-up-circle me-1"></i>= Despatch Date
                    </span>
                </label>
                <input type="date" name="mtc_test_date" id="mtc_test_date" class="form-control bg-light"
                       readonly tabindex="-1"
                       value="<?= htmlspecialchars($despatch['mtc_test_date'] ?: ($despatch['despatch_date'] ?? date('Y-m-d'))) ?>">
            </div>
        </div>

        <h6 class="fw-bold mt-3 mb-2 text-warning">
            <i class="bi bi-table me-1"></i>Test Results
            <small class="text-muted fw-normal ms-2" style="font-size:.75rem">Six random samples — average results</small>
        </h6>

        <!-- Results table matching IS 3812 format -->
        <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0" style="font-size:.88rem">
            <thead class="table-warning">
            <tr>
                <th style="width:40%">TEST</th>
                <th style="width:25%">RESULTS (%)</th>
                <th style="width:25%">Requirements as per IS 3812</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="fw-semibold">ROS 45 Micron Sieve</td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="text" name="mtc_ros_45" class="form-control" placeholder="e.g. 28.5"
                               value="<?= htmlspecialchars($despatch['mtc_ros_45']??'') ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </td>
                <td class="text-muted">&lt; 34%</td>
            </tr>
            <tr>
                <td class="fw-semibold">Moisture</td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="text" name="mtc_moisture" class="form-control" placeholder="e.g. 0.8"
                               value="<?= htmlspecialchars($despatch['mtc_moisture']??'') ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </td>
                <td class="text-muted">&lt; 2%</td>
            </tr>
            <tr>
                <td class="fw-semibold">Loss on Ignition</td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="text" name="mtc_loi" class="form-control" placeholder="e.g. 3.2"
                               value="<?= htmlspecialchars($despatch['mtc_loi']??'') ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </td>
                <td class="text-muted">&lt; 5%</td>
            </tr>
            <tr>
                <td class="fw-semibold">Fineness – Specific Surface Area<br><small class="text-muted">by Blaine's Permeability Method</small></td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="text" name="mtc_fineness" class="form-control" placeholder="e.g. 380"
                               value="<?= htmlspecialchars($despatch['mtc_fineness']??'') ?>">
                        <span class="input-group-text">m²/kg</span>
                    </div>
                </td>
                <td class="text-muted">&gt; 320 m²/kg</td>
            </tr>
            </tbody>
        </table>
        </div>

        <div class="row g-3 mt-2">
            <div class="col-12 col-md-6">
                <label class="form-label">Remarks / Observations</label>
                <input type="text" name="mtc_remarks" class="form-control"
                       value="<?= htmlspecialchars($despatch['mtc_remarks']??'') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">
                    <i class="bi bi-paperclip me-1 text-warning"></i>Upload MTC Document (optional)
                </label>
                <?php $mtc_file = $despatch['doc_mtc'] ?? ''; ?>
                <?php if (!empty($mtc_file)): ?>
                <div class="card border-warning mb-2 p-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-warning text-dark"><i class="bi bi-check2 me-1"></i>Uploaded</span>
                        <a href="../uploads/mtc_docs/<?= htmlspecialchars($mtc_file) ?>" target="_blank"
                           class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View</a>
                        <small class="text-muted text-truncate" style="max-width:120px"><?= htmlspecialchars($mtc_file) ?></small>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input border-danger" type="checkbox" name="remove_doc_mtc" value="1"
                               id="rm_doc_mtc" onchange="toggleRemoveDoc(this,'upload_doc_mtc')">
                        <label class="form-check-label text-danger fw-semibold" for="rm_doc_mtc">
                            <i class="bi bi-trash me-1"></i>Remove this document
                        </label>
                    </div>
                </div>
                <?php endif; ?>
                <div id="upload_doc_mtc">
                    <input type="file" name="doc_mtc" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text"><?= empty($mtc_file)?'PDF or image, max 10MB':'Upload new to replace'?></div>
                </div>
            </div>
        </div>
        </div><!-- /mtcDetails -->
        </div>
    </div>
    </div>




    <!-- Delivery Documents — shown only when status = Delivered -->
    <div class="col-12" id="deliveryDocsSection" style="display:none">
    <div class="card border-success">
        <div class="card-header bg-success text-white">
            <i class="bi bi-paperclip me-2"></i>Delivery Documents
            <small class="ms-2 opacity-75">Upload scan copies after delivery confirmation</small>
        </div>
        <div class="card-body">
        <div class="row g-3">

            <?php
            $doc_slots = [
                ['field'=>'doc_delivery_challan', 'label'=>'Delivery Challan',  'icon'=>'bi-file-earmark-text', 'color'=>'text-primary'],
                ['field'=>'doc_vendor_receipt',   'label'=>'Vendor Receipt',    'icon'=>'bi-receipt',           'color'=>'text-warning'],
                ['field'=>'doc_weightbridge',     'label'=>'Weightbridge Slip', 'icon'=>'bi-speedometer',       'color'=>'text-danger'],
            ];
            foreach ($doc_slots as $slot):
                $fname = $despatch[$slot['field']] ?? '';
            ?>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">
                    <i class="bi <?= $slot['icon'] ?> me-1 <?= $slot['color'] ?>"></i><?= $slot['label'] ?>
                </label>
                <?php if (!empty($fname)): ?>
                <div class="card border-success mb-2 p-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>Uploaded</span>
                        <a href="../uploads/delivery_docs/<?= htmlspecialchars($fname) ?>"
                           target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>View
                        </a>
                        <small class="text-muted text-truncate" style="max-width:120px" title="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></small>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input border-danger" type="checkbox"
                               name="remove_<?= $slot['field'] ?>" value="1"
                               id="rm_<?= $slot['field'] ?>"
                               onchange="toggleRemoveDoc(this,'upload_<?= $slot['field'] ?>')">
                        <label class="form-check-label text-danger fw-semibold" for="rm_<?= $slot['field'] ?>">
                            <i class="bi bi-trash me-1"></i>Remove this document
                        </label>
                    </div>
                </div>
                <?php endif; ?>
                <div id="upload_<?= $slot['field'] ?>">
                    <input type="file" name="<?= $slot['field'] ?>" class="form-control form-control-sm"
                           accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text"><?= empty($fname) ? 'PDF or image, max 10MB' : 'Upload a new file to replace existing' ?></div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
        </div>
    </div>
    </div>

    <!-- Transporter Freight Invoice — shown only when status = Delivered -->
    <div class="col-12" id="freightInvoiceSection" style="display:none">
    <div class="card border-warning">
        <div class="card-header" style="background:linear-gradient(135deg,#92400e,#b45309);color:#fff">
            <i class="bi bi-receipt-cutoff me-2"></i>Transporter Freight Invoice
            <small class="ms-2 opacity-75">Record transporter's freight bill for this delivery</small>
        </div>
        <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label fw-semibold">Invoice No</label>
                <input type="text" name="freight_inv_no" class="form-control"
                       placeholder="e.g. FI/2526/001"
                       value="<?= htmlspecialchars($despatch['freight_inv_no'] ?? '') ?>">
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label fw-semibold">Invoice Date</label>
                <input type="date" name="freight_inv_date" class="form-control"
                       value="<?= htmlspecialchars($despatch['freight_inv_date'] ?? '') ?>">
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label fw-semibold">Invoice Amount (₹)</label>
                <div class="input-group">
                    <span class="input-group-text">₹</span>
                    <input type="number" name="freight_inv_amount" step="0.01" class="form-control"
                           value="<?= $despatch['freight_inv_amount'] ?? 0 ?>">
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label fw-semibold">Invoice Type</label>
                <select name="freight_inv_type" id="freightInvType" class="form-select"
                        onchange="toggleHardCopy()">
                    <option value="" <?= ($despatch['freight_inv_type']??'')==''?'selected':'' ?>>-- Select --</option>
                    <option value="Scan"    <?= ($despatch['freight_inv_type']??'')==='Scan'   ?'selected':'' ?>>Scan Copy</option>
                    <option value="Digital" <?= ($despatch['freight_inv_type']??'')==='Digital'?'selected':'' ?>>Digitally Signed</option>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label fw-semibold">Upload Invoice</label>
                <?php $fi_file = $despatch['freight_inv_file'] ?? ''; ?>
                <?php if (!empty($fi_file)): ?>
                <div class="mb-1">
                    <a href="../uploads/freight_invoices/<?= htmlspecialchars($fi_file) ?>"
                       target="_blank" class="btn btn-sm btn-outline-success w-100">
                        <i class="bi bi-eye me-1"></i>View Uploaded
                    </a>
                </div>
                <?php endif; ?>
                <input type="file" name="freight_inv_file" class="form-control form-control-sm"
                       accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-6 col-sm-4 col-md-2" id="hardCopyWrap" style="display:none">
                <label class="form-label fw-semibold">Hard Copy</label>
                <div class="form-check mt-1 p-3 border rounded <?= ($despatch['freight_inv_hardcopy']??0) ? 'border-success bg-success bg-opacity-10' : 'border-warning bg-warning bg-opacity-10' ?>">
                    <input class="form-check-input" type="checkbox" name="freight_inv_hardcopy"
                           value="1" id="freightHardcopy"
                           <?= ($despatch['freight_inv_hardcopy']??0) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="freightHardcopy">
                        <?php if ($despatch['freight_inv_hardcopy']??0): ?>
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Received</span>
                        <?php else: ?>
                        <span class="text-warning"><i class="bi bi-clock me-1"></i>Pending</span>
                        <?php endif; ?>
                    </label>
                </div>
            </div>
        </div>
        <!-- Status badge for existing record -->
        <?php if (!empty($despatch['freight_inv_type'])): ?>
        <div class="mt-3">
            <?php if ($despatch['freight_inv_type'] === 'Digital'): ?>
            <span class="badge bg-success fs-6"><i class="bi bi-patch-check me-1"></i>Digitally Signed Invoice — No hard copy required</span>
            <?php elseif ($despatch['freight_inv_hardcopy']): ?>
            <span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>Hard Copy Received</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark fs-6"><i class="bi bi-clock me-1"></i>Hard Copy Pending</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>
    </div>

    <div class="col-12 text-end">
        <a href="despatch.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <?php if (!$is_locked): ?>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send-check me-1"></i>Save Despatch Order</button>
        <?php else: ?>
        <button type="submit" class="btn btn-success px-4"><i class="bi bi-cloud-upload me-1"></i>Update Documents</button>
        <?php endif; ?>
    </div>
</div>
</form>

<script>
// Client-side validation: Delivered requires Received Weight on every item row
(function() {
    var form = document.getElementById('despatchForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        var statusSel = form.querySelector('[name="status"]');
        if (!statusSel || statusSel.value !== 'Delivered') return; // allow non-Delivered
        var weights = form.querySelectorAll('#dItemsBody [name="weight[]"]');
        var missing = false;
        weights.forEach(function(w) {
            w.classList.remove('is-invalid');
            if ((parseFloat(w.value) || 0) <= 0) {
                w.classList.add('is-invalid');
                missing = true;
            }
        });
        if (missing) {
            e.preventDefault();
            alert('Received Weight is required for every item when status is Delivered.');
            var first = form.querySelector('#dItemsBody .is-invalid');
            if (first) first.focus();
        }
    });
})();
</script>

<script>
/* ── PO -> Vendor map ── */
const poVendorMap = <?php echo json_encode($po_vendor_map, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ── PO balance map ── */
const poBalanceMap = <?php echo json_encode($po_balance_map, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ── PO -> Item -> unit_price and gst_rate maps ── */
const poItemsMap = <?php echo json_encode($po_items_map, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const poGstMap      = <?php echo json_encode($po_gst_map,      JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const poItemsDetail = <?php echo json_encode($po_items_detail, JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ── Transporter-Vendor Rate Card map (vendor_id -> [{tid, name, rate, uom}]) ── */
const vendorTransporterRates = <?php
    $vtmap = [];
    $vtSeen = []; // track vendor+transporter combos to keep only latest
    $rc = $db->query("SELECT tr.vendor_id, tr.transporter_id, tr.rate, tr.uom, t.transporter_name
        FROM transporter_rates tr
        JOIN transporters t ON tr.transporter_id = t.id
        WHERE tr.status='Active' AND t.status='Active'
        ORDER BY tr.id DESC");
    if ($rc) while ($rr = $rc->fetch_assoc()) {
        $combo = (int)$rr['vendor_id'].'-'.(int)$rr['transporter_id'];
        if (isset($vtSeen[$combo])) continue; // skip older duplicate
        $vtSeen[$combo] = true;
        $vtmap[(int)$rr['vendor_id']][] = [
            'tid'  => (int)$rr['transporter_id'],
            'name' => $rr['transporter_name'],
            'rate' => (float)$rr['rate'],
            'uom'  => $rr['uom'],
        ];
    }
    echo json_encode($vtmap);
?>;

var currentTransporterRate = 0;
var currentRateUom = '';

/* ── Vendor lookup map (ship-to data embedded server-side) ── */
const vendorShipData = <?php
    $cleanV = function($s) { return trim(str_replace('\\', '', preg_replace('/\s+/', ' ', (string)($s ?? '')))); };
    $map = [];
    foreach ($vendors as $v) {
        // Prefer ship_address; fall back to bill then legacy address
        $name    = $cleanV($v['vendor_name']);
        $addr    = $cleanV(($v['ship_name'] ? $v['ship_name'].', ' : '') . ($v['ship_address'] ?: ($v['bill_address'] ?: $v['address'])));
        $city    = $cleanV($v['ship_city']    ?: ($v['bill_city']    ?: $v['city']));
        $state   = $cleanV($v['ship_state']   ?: ($v['bill_state']   ?: $v['state']));
        $pin     = $cleanV($v['ship_pincode'] ?: ($v['bill_pincode'] ?: $v['pincode']));
        $gstin   = $cleanV($v['ship_gstin']   ?: ($v['bill_gstin']   ?: $v['gstin']));
        $contact = $cleanV(($v['ship_contact'] ?? '') . ($v['ship_phone'] ? ' / '.$v['ship_phone'] : ''));
        $map[$v['id']] = [
            'name'    => $name,
            'address' => $addr,
            'city'    => $city,
            'state'   => $state,
            'pincode' => $pin,
            'gstin'   => $gstin,
            'contact' => $contact,
            'vendor_name' => $cleanV($v['vendor_name']),
        ];
    }
    echo json_encode($map);
?>;

/* ── Filter PO dropdown by selected vendor ── */
function filterPOByVendor(vendorId) {
    vendorId = parseInt(vendorId, 10) || 0;
    var poSel = document.getElementById('poRefSelect');
    if (!poSel) return;
    var current = parseInt(poSel.value, 10) || 0;
    Array.from(poSel.options).forEach(function(opt) {
        if (!opt.value) return; // keep "-- None --"
        var optVendor = parseInt(opt.getAttribute('data-vendor'), 10) || 0;
        opt.style.display = (!vendorId || optVendor === vendorId) ? '' : 'none';
    });
    // If current PO no longer belongs to selected vendor, reset it
    if (current) {
        var selOpt = poSel.querySelector('option[value="' + current + '"]');
        if (selOpt && selOpt.style.display === 'none') {
            poSel.value = '';
            renderPOBalance(0);
        }
    }
}

/* ── Auto-populate item rows from PO ── */
function populatePOItems(poId) {
    if (!poId) return;
    var items = poItemsDetail[poId];
    if (!items || !items.length) return;
    var tbody     = document.getElementById('dItemsBody');
    var statusSel = document.querySelector('[name="status"]');
    var isDeliv   = statusSel && statusSel.value === 'Delivered';

    // Check if rows already have items selected
    var hasItems = false;
    tbody.querySelectorAll('[name="item_id[]"]').forEach(function(s) { if (s.value) hasItems = true; });
    if (hasItems) {
        applyPOItemPrices(poId);
        return;
    }

    // Clear all existing rows
    tbody.innerHTML = '';

    // Build fresh rows from template
    items.forEach(function(it) {
        var row = makeNewRow();

        // Select the matching item
        var itemSel = row.querySelector('[name="item_id[]"]');
        if (itemSel) {
            for (var i = 0; i < itemSel.options.length; i++) {
                if (parseInt(itemSel.options[i].value) === it.item_id) {
                    itemSel.selectedIndex = i;
                    break;
                }
            }
        }

        // UOM
        var uomVal  = row.querySelector('.d-uom-val');
        var uomDisp = row.querySelector('.d-uom-display');
        if (uomVal)  uomVal.value  = it.uom || '';
        if (uomDisp) uomDisp.value = it.uom || '';

        // PO Rate (always visible reference)
        var poRateEl = row.querySelector('.d-po-rate');
        if (poRateEl) poRateEl.value = it.unit_price ? '₹' + parseFloat(it.unit_price).toFixed(2) : '-';

        // Despatched Qty — leave empty (reference only, user fills manually if needed)
        var qtyEl = row.querySelector('[name="qty[]"]');
        if (qtyEl) qtyEl.value = '';

        // Unit price + GST only when Delivered
        if (isDeliv) {
            var priceEl = row.querySelector('[name="unit_price[]"]');
            var gstEl   = row.querySelector('[name="gst_rate[]"]');
            if (priceEl) priceEl.value = parseFloat(it.unit_price || 0).toFixed(2);
            if (gstEl)   gstEl.value   = parseFloat(it.gst_rate  || 0).toFixed(2);
        }

        tbody.appendChild(row);
    });

    dCalcTotal();
    dUpdateWeightAndFreight();
}

/* ── Fill vendor dropdown from PO selection ── */
function fillVendorFromPO(sel) {
    var poId = parseInt(sel.value, 10);
    var vendorId = poVendorMap[poId];
    if (vendorId) {
        var vSel = document.getElementById('vendorSelect');
        if (vSel && !vSel.value) {
            vSel.value = vendorId;
            fillConsigneeFromVendor(vSel);
        }
    }
    // Auto-populate item rows from PO
    populatePOItems(poId);
    renderPOBalance(poId);
    // Re-filter transporters since vendor may have changed
    var vFilled = document.getElementById('vendorSelect');
    if (vFilled) filterTransportersByVendor(vFilled.value);
}

function renderPOBalance(poId) {
    var wrap  = document.getElementById('poBalanceWrap');
    var table = document.getElementById('poBalanceTable');
    if (!wrap || !table) return;
    var items = poBalanceMap[poId];
    if (!items || Object.keys(items).length === 0) {
        wrap.style.display = 'none';
        return;
    }
    var rows = '';
    var allFulfilled = true;
    Object.values(items).forEach(function(it) {
        var bal = parseFloat(it.balance) || 0;
        var pct = it.po_qty > 0 ? Math.min(100, Math.round((it.despatched / it.po_qty) * 100)) : 0;
        var badgeCls = bal <= 0 ? 'bg-success' : (pct >= 50 ? 'bg-warning text-dark' : 'bg-danger');
        var balLbl   = bal <= 0 ? 'Fulfilled' : bal.toFixed(3) + ' pending';
        if (bal > 0) allFulfilled = false;
        rows += '<tr>'
            + '<td class="py-1">' + it.item_name + '</td>'
            + '<td class="text-end py-1">' + parseFloat(it.po_qty).toFixed(3) + '</td>'
            + '<td class="text-end py-1 text-success">' + parseFloat(it.despatched).toFixed(3) + '</td>'
            + '<td class="text-end py-1 fw-bold ' + (bal > 0 ? 'text-danger' : 'text-success') + '">' + (bal > 0 ? bal.toFixed(3) : '0.000') + '</td>'
            + '<td class="py-1" style="min-width:120px">'
            +   '<div class="progress" style="height:14px">'
            +     '<div class="progress-bar ' + badgeCls + '" style="width:' + pct + '%">' + (pct > 15 ? pct + '%' : '') + '</div>'
            +   '</div>'
            + '</td>'
            + '<td class="py-1"><span class="badge ' + badgeCls + '">' + balLbl + '</span></td>'
            + '</tr>';
    });
    table.innerHTML = '<table class="table table-sm table-bordered mb-0">'
        + '<thead class="table-primary"><tr>'
        + '<th>Item</th><th class="text-end">PO Qty</th><th class="text-end">Despatched</th>'
        + '<th class="text-end">Balance</th><th>Progress</th><th>Status</th>'
        + '</tr></thead><tbody>' + rows + '</tbody>'
        + (allFulfilled ? '<tfoot><tr><td colspan="6" class="text-center text-success py-1 fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>All PO quantities fulfilled</td></tr></tfoot>' : '')
        + '</table>';
    wrap.style.display = 'block';
}

/* Fill unit_price (and UOM) in each despatch item row from the linked PO */
function applyPOItemPrices(poId) {
    var priceMap    = poItemsMap[poId] || {};
    var gstMap      = poGstMap[poId]   || {};
    var statusSel   = document.querySelector('[name="status"]');
    var isDelivered = statusSel && statusSel.value === 'Delivered';
    document.querySelectorAll('#dItemsBody > tr').forEach(function(row) {
        var selEl   = row.querySelector('[name="item_id[]"]');
        var priceEl = row.querySelector('[name="unit_price[]"]');
        var gstEl   = row.querySelector('[name="gst_rate[]"]');
        if (!selEl || !priceEl) return;
        var itemId = parseInt(selEl.value, 10);
        if (!itemId) return;
        // Always update PO Rate reference column
        var poRateEl = row.querySelector('.d-po-rate');
        if (poRateEl) {
            var pr = priceMap[itemId];
            poRateEl.value = pr !== undefined ? '₹' + parseFloat(pr).toFixed(2) : '-';
        }
        if (isDelivered) {
            if (priceMap[itemId] !== undefined) priceEl.value = parseFloat(priceMap[itemId]).toFixed(2);
            if (gstMap[itemId]   !== undefined && gstEl) gstEl.value = parseFloat(gstMap[itemId]).toFixed(2);
        }
        // Also ensure UOM is set from the selected option data
        var opt = selEl.options[selEl.selectedIndex];
        if (opt && opt.dataset.uom) {
            var uomVal  = row.querySelector('.d-uom-val');
            var uomDisp = row.querySelector('.d-uom-display');
            if (uomVal)  uomVal.value  = opt.dataset.uom;
            if (uomDisp) uomDisp.value = opt.dataset.uom;
        }
        dCalcRow(row.querySelector('[name="weight[]"]'));
    });
}



/* ── Filter transporter dropdown based on selected vendor ── */
function filterTransportersByVendor(vendorId) {
    vendorId = parseInt(vendorId, 10) || 0;
    var tSel = document.getElementById('transporterSelect');
    if (!tSel) return;

    var rates = vendorId ? (vendorTransporterRates[vendorId] || []) : [];
    var allowedTids = rates.map(function(r) { return r.tid; });

    // Reset transporter selection
    tSel.value = '';
    currentTransporterRate = 0;
    currentRateUom = '';

    // Show/hide options based on rate card
    Array.from(tSel.options).forEach(function(opt) {
        if (!opt.value) return; // keep placeholder
        var tid = parseInt(opt.value, 10);
        if (!vendorId || allowedTids.includes(tid)) {
            opt.style.display = '';
            opt.disabled = false;
        } else {
            opt.style.display = 'none';
            opt.disabled = true;
        }
    });

    // Update formula hint
    var formulaEl = document.getElementById('freightFormula');
    if (formulaEl) {
        if (!vendorId) {
            formulaEl.innerHTML = '<span class="text-muted">Select Vendor first to filter transporters</span>';
        } else if (allowedTids.length === 0) {
            formulaEl.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>'
                + 'No transporters with rate card for this vendor. '
                + '<a href="transporters.php" target="_blank" class="text-warning fw-semibold">Set up Rate Card <i class="bi bi-box-arrow-up-right"></i></a></span>';
        } else {
            formulaEl.innerHTML = '<span class="text-info"><i class="bi bi-funnel me-1"></i>'
                + allowedTids.length + ' transporter(s) available for this vendor</span>';
        }
    }

    dUpdateWeightAndFreight();
}

/* ── Set freight rate when transporter is selected ── */
function updateFreightCalc() {
    var tid = parseInt((document.getElementById('transporterSelect')||{}).value, 10) || 0;
    var vid = parseInt((document.getElementById('vendorSelect')||{}).value, 10) || 0;

    if (!tid || !vid) {
        currentTransporterRate = 0;
        currentRateUom = '';
        dUpdateWeightAndFreight();
        return;
    }

    // Look up rate from pre-loaded map (no AJAX needed)
    var rates = vendorTransporterRates[vid] || [];
    var match = rates.find(function(r) { return r.tid === tid; });

    if (match) {
        currentTransporterRate = match.rate;
        currentRateUom = match.uom || '';
    } else {
        currentTransporterRate = 0;
        currentRateUom = '';
    }
    dUpdateWeightAndFreight();
}

function getTotalWeight() {
    var total = 0;
    document.querySelectorAll('#dItemsBody [name="weight[]"]').forEach(function(el) {
        total += parseFloat(el.value) || 0;
    });
    return total;
}

/* ── Auto-fill consignee from selected vendor's ship-to ── */
function fillConsigneeFromVendor(sel) {
    const vid  = sel.value;
    const data = vendorShipData[vid];
    if (!data) return;

    // Only auto-fill if consignee name is empty (new form) OR user explicitly selected a vendor
    // On edit mode, still fill so user can refresh from vendor data
    const isNew = !document.getElementById('consignee_name').value.trim();
    const isEdit = <?= $action === 'edit' ? 'true' : 'false' ?>;

    if (isNew || (!isEdit)) {
        applyConsigneeData(data);
    } else {
        // On edit, ask before overwriting
        if (confirm('Replace current consignee details with vendor\'s Ship-To address?\n\nVendor: ' + data.vendor_name)) {
            applyConsigneeData(data);
        }
    }
}

function applyConsigneeData(data) {
    document.getElementById('consignee_name').value    = data.name    || '';
    document.getElementById('consignee_address').value = data.address || '';
    document.getElementById('consignee_city').value    = data.city    || '';
    document.getElementById('consignee_state').value   = data.state   || '';
    document.getElementById('consignee_pincode').value = data.pincode || '';
    document.getElementById('consignee_gstin').value   = data.gstin   || '';
    document.getElementById('consignee_contact').value = data.contact || '';

    // Show success notice
    document.getElementById('autofillVendorName').textContent = data.vendor_name;
    document.getElementById('autofillNotice').style.display = 'block';
    document.getElementById('autofillBadge').classList.remove('d-none');

    // Highlight filled fields briefly
    ['consignee_name','consignee_address','consignee_city',
     'consignee_state','consignee_pincode','consignee_gstin','consignee_contact'].forEach(fid => {
        const el = document.getElementById(fid);
        if (el && el.value) {
            el.classList.add('border-success');
            setTimeout(() => el.classList.remove('border-success'), 2500);
        }
    });
}

function clearConsignee() {
    ['consignee_name','consignee_address','consignee_city',
     'consignee_state','consignee_pincode','consignee_gstin','consignee_contact'].forEach(fid => {
        const el = document.getElementById(fid);
        if (el) { el.tagName === 'TEXTAREA' ? el.value = '' : el.value = ''; }
    });
    document.getElementById('autofillNotice').style.display = 'none';
    document.getElementById('autofillBadge').classList.add('d-none');
}

/* ── On page load: if vendor already selected (add form with pre-fill or edit),
      auto-fill only if consignee is empty ─────────────────────────────────── */
/* ── Toggle upload field when Remove checkbox is ticked ── */
function toggleRemoveDoc(chk, uploadWrapperId) {
    var wrap = document.getElementById(uploadWrapperId);
    if (!wrap) return;
    if (chk.checked) {
        wrap.style.opacity = '0.4';
        wrap.style.pointerEvents = 'none';
        chk.closest('.card').style.borderColor = '#dc3545';
        chk.closest('.card').style.background  = '#fff5f5';
    } else {
        wrap.style.opacity = '';
        wrap.style.pointerEvents = '';
        chk.closest('.card').style.borderColor = '';
        chk.closest('.card').style.background  = '';
    }
}

/* ── Sources of material map (id → name) ── */
const sourcesMap = <?php
    $sm = [];
    foreach ($sources_list as $s) $sm[(int)$s['id']] = $s['source_name'];
    echo json_encode($sm);
?>;

/* ── MTC toggle ── */
function toggleMTC() {
    var yes   = document.getElementById('mtcYes').checked;
    var det   = document.getElementById('mtcDetails');
    var note  = document.getElementById('mtcChallanNote');
    if (det)  det.style.display  = yes ? 'block' : 'none';
    if (note) note.style.display = yes ? 'block' : 'none';
    if (yes)  syncMTCFields();   // populate whenever opened
}

/* ── Sync MTC auto-populated fields ── */
function syncMTCFields() {
    // 1. Source — from Source of Material dropdown
    var srcSel = document.getElementById('sourceOfMaterial');
    var srcFld = document.getElementById('mtc_source');
    if (srcSel && srcFld) {
        var sid = parseInt(srcSel.value) || 0;
        srcFld.value = (sid && sourcesMap[sid]) ? sourcesMap[sid] : '';
    }

    // 2. Item Name — first item selected in Despatch Items table
    var itemFld = document.getElementById('mtc_item_name');
    if (itemFld) {
        var firstSel = document.querySelector('select.d-item-select');
        if (firstSel && firstSel.selectedOptions[0] && firstSel.selectedOptions[0].value) {
            itemFld.value = firstSel.selectedOptions[0].text.trim();
        } else {
            itemFld.value = '';
        }
    }

    // 3. Test Date — same as Despatch Date
    var dDate = document.getElementById('despatch_date');
    var mDate = document.getElementById('mtc_test_date');
    if (dDate && mDate) mDate.value = dDate.value || '';
}

/* ── Bind Source dropdown change to sync MTC source field ── */
function bindMTCSyncListeners() {
    var srcSel = document.getElementById('sourceOfMaterial');
    if (srcSel) srcSel.addEventListener('change', syncMTCFields);

    var dDate = document.getElementById('despatch_date');
    if (dDate) dDate.addEventListener('change', syncMTCFields);
}

/* ── Show/hide delivery docs section based on status ── */
function onStatusChange(sel) {
    var delivered = sel.value === 'Delivered';
    var sec = document.getElementById('deliveryDocsSection');
    if (sec) sec.style.display = delivered ? 'block' : 'none';
    var fiSec = document.getElementById('freightInvoiceSection');
    if (fiSec) fiSec.style.display = delivered ? 'block' : 'none';
    if (delivered) toggleHardCopy();
    // Show/hide price columns based on Delivered status
    document.querySelectorAll('.d-price-col').forEach(function(el) {
        el.style.display = delivered ? '' : 'none';
    });
    // Show/hide Received Weight column based on Delivered status
    document.querySelectorAll('.d-weight-col').forEach(function(el) {
        el.style.display = delivered ? '' : 'none';
    });
    // Auto-populate price fields when switching to Delivered
    if (delivered) {
        var poSel = document.getElementById('poRefSelect');
        var poId  = poSel ? parseInt(poSel.value, 10) : 0;
        if (poId) applyPOItemPrices(poId);
    }
    // Clear unit prices AND weights when moving away from Delivered
    if (!delivered) {
        document.querySelectorAll('[name="unit_price[]"]').forEach(function(el) { el.value = ''; });
        document.querySelectorAll('[name="gst_rate[]"]').forEach(function(el) { el.value = ''; });
        document.querySelectorAll('[name="weight[]"]').forEach(function(el) { el.value = '0'; });
        // Reset total weight display
        var twEl = document.getElementById('totalWeightDisplay');
        if (twEl) twEl.value = '0';
        // Recalculate totals
        dCalcTotal();
    }
}

function toggleHardCopy() {
    var type = (document.getElementById('freightInvType')||{}).value;
    var wrap = document.getElementById('hardCopyWrap');
    if (wrap) wrap.style.display = type === 'Scan' ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    // ── Partial lock if Delivered: lock main form, keep doc sections editable ──
    <?php if ($is_locked): ?>
    (function() {
        var form = document.getElementById('despatchForm');
        if (!form) return;
        // Sections that stay editable
        var docSec = document.getElementById('deliveryDocsSection');
        var fiSec  = document.getElementById('freightInvoiceSection');
        function isInEditableSection(el) {
            return (docSec && docSec.contains(el)) || (fiSec && fiSec.contains(el));
        }
        // Disable all inputs EXCEPT those inside delivery docs / freight invoice
        form.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (isInEditableSection(el)) return;
            el.disabled = true;
            el.style.pointerEvents = 'none';
        });
        // Keep docs_only hidden field enabled
        var docsOnly = form.querySelector('input[name="docs_only"]');
        if (docsOnly) { docsOnly.disabled = false; }
        // Disable action buttons outside editable sections
        form.querySelectorAll('button[onclick]').forEach(function(el) {
            if (isInEditableSection(el)) return;
            el.disabled = true;
            el.style.opacity = '0.5';
            el.style.pointerEvents = 'none';
        });
        // Hide add-item and remove buttons in items table
        var itemsCard = document.getElementById('dItemsTable');
        if (itemsCard) {
            itemsCard.closest('.card').querySelectorAll('.btn-outline-danger, [onclick*="addDRow"]').forEach(function(el) {
                el.style.display = 'none';
            });
        }
        // Force show delivery docs + freight invoice sections (they start hidden until status=Delivered)
        if (docSec) docSec.style.display = 'block';
        if (fiSec)  fiSec.style.display  = 'block';
    })();
    <?php endif; ?>

    // Wire all pre-rendered item rows (edit mode)
    document.querySelectorAll('#dItemsBody > tr').forEach(function(row) { wireRow(row); });

    var vSel = document.getElementById('vendorSelect');
    if (vSel && vSel.value) filterPOByVendor(vSel.value);
    toggleMTC();
    bindMTCSyncListeners();
    syncMTCFields();   // populate on page load (edit mode)
    // Show delivery docs if status already Delivered (edit mode)
    var statusSel = document.querySelector('[name="status"]');
    if (statusSel) {
        statusSel.addEventListener('change', function() { onStatusChange(this); });
        onStatusChange(statusSel); // run on load
    }
    toggleHardCopy();
    // Vendor consignee autofill
    var vSel = document.getElementById('vendorSelect');
    if (vSel && vSel.value) {
        var data = vendorShipData[vSel.value];
        var hasConsignee = document.getElementById('consignee_name').value.trim();
        if (data && !hasConsignee) applyConsigneeData(data);
    }

    // Sync despatch date field → LR date on change
    var despatchDateEl = document.querySelector('[name="despatch_date"]');
    if (despatchDateEl) {
        despatchDateEl.addEventListener('change', function() {
            var lrDate = document.getElementById('lr_date');
            if (lrDate) lrDate.value = this.value;
        });
    }

    // Apply PO unit prices and balance on load if PO already selected
    var poSelEl = document.getElementById('poRefSelect');
    if (poSelEl && poSelEl.value) {
        var poId = parseInt(poSelEl.value, 10);
        applyPOItemPrices(poId);
        renderPOBalance(poId);
    }

    // Filter transporters and set freight rate on load (edit mode)
    var vSelInit = document.getElementById('vendorSelect');
    var tSelInit = document.getElementById('transporterSelect');
    if (vSelInit && vSelInit.value) {
        var vid = parseInt(vSelInit.value, 10);
        var rates = vendorTransporterRates[vid] || [];
        var allowedTids = rates.map(function(r) { return r.tid; });
        // Filter transporter options silently (keep current selection if valid)
        var currentTid = tSelInit ? parseInt(tSelInit.value, 10) : 0;
        Array.from(tSelInit ? tSelInit.options : []).forEach(function(opt) {
            if (!opt.value) return;
            var tid = parseInt(opt.value, 10);
            if (!allowedTids.includes(tid)) {
                opt.style.display = 'none';
                opt.disabled = true;
            }
        });
        // Set rate if transporter already selected
        if (currentTid) {
            var match = rates.find(function(r) { return r.tid === currentTid; });
            if (match) {
                currentTransporterRate = match.rate;
                currentRateUom = match.uom || '';
            }
        }
        dUpdateWeightAndFreight();
    }
});

/* ── Items table functions ──────────────────────────────── */
function makeNewRow() {
    var tpl = document.querySelector('#dRowTemplate tbody tr');
    var row = tpl.cloneNode(true);

    // Re-enable all inputs and assign proper name attributes
    var itemSel = row.querySelector('.d-item-select');
    if (itemSel) { itemSel.removeAttribute('disabled'); itemSel.name = 'item_id[]'; }

    var inputs = row.querySelectorAll('input');
    var nameMap = [
        [1, 'desc[]'],
        [3, 'qty[]'],
        [5, 'unit_price[]'],
        [6, 'gst_rate[]'],
        [7, 'weight[]'],
        [8, 'total_price[]']
    ];
    // Re-enable by class/position
    row.querySelectorAll('.d-uom-val').forEach(function(el) {
        el.removeAttribute('disabled');
        el.name = 'uom[]';
        el.type = 'hidden';
        el.style.display = '';
    });
    row.querySelectorAll('.d-uom-display').forEach(function(el) { el.removeAttribute('disabled'); });
    row.querySelectorAll('.d-unit-price').forEach(function(el) { el.removeAttribute('disabled'); el.name = 'unit_price[]'; });
    row.querySelectorAll('.d-weight').forEach(function(el) { el.removeAttribute('disabled'); el.name = 'weight[]'; });
    row.querySelectorAll('.d-row-total').forEach(function(el) { el.removeAttribute('disabled'); el.name = 'total_price[]'; });
    row.querySelectorAll('.d-po-rate').forEach(function(el) { el.removeAttribute('disabled'); });

    // Re-enable remaining inputs by td position
    var tds = row.querySelectorAll('td');
    // td[1]=desc, td[3]=qty, td[6]=gst_rate
    if (tds[1]) { var inp = tds[1].querySelector('input'); if (inp) { inp.removeAttribute('disabled'); inp.name = 'desc[]'; } }
    if (tds[3]) { var inp = tds[3].querySelector('input'); if (inp) { inp.removeAttribute('disabled'); inp.name = 'qty[]'; } }
    if (tds[6]) { var inp = tds[6].querySelector('input'); if (inp) { inp.removeAttribute('disabled'); inp.name = 'gst_rate[]'; } }

    wireRow(row);

    // Hide price cols and weight col if not delivered
    var statusSel = document.querySelector('[name="status"]');
    var isDeliv = statusSel && statusSel.value === 'Delivered';
    row.querySelectorAll('.d-price-col').forEach(function(td) {
        td.style.display = isDeliv ? '' : 'none';
    });
    row.querySelectorAll('.d-weight-col').forEach(function(td) {
        td.style.display = isDeliv ? '' : 'none';
    });
    return row;
}
function wireRow(row) {
    var itemSel = row.querySelector('.d-item-select');
    if (itemSel) itemSel.onchange = function() { dFillItem(this); };
    row.querySelectorAll('.d-weight, .d-unit-price, [name="gst_rate[]"]').forEach(function(f) {
        f.onchange = function() { dCalcRow(this); };
    });
    var btn = row.querySelector('button');
    if (btn) btn.onclick = function() { dRemove(this); };
}
function addDRow() {
    var tbody = document.getElementById('dItemsBody');
    tbody.appendChild(makeNewRow());
}
function dRemove(btn) {
    if (document.getElementById('dItemsBody').rows.length > 1) btn.closest('tr').remove();
    dCalcTotal();
    dUpdateWeightAndFreight();
}
function dFillItem(sel) {
    var row = sel.closest('tr');
    var opt = sel.selectedOptions[0];
    if (!opt || !opt.value) return;
    var uomVal  = row.querySelector('.d-uom-val');
    var uomDisp = row.querySelector('.d-uom-display');
    if (uomVal)  uomVal.value  = opt.dataset.uom || '';
    if (uomDisp) uomDisp.value = opt.dataset.uom || '';

    var itemId   = parseInt(opt.value, 10);
    var poSel    = document.getElementById('poRefSelect');
    var poId     = poSel ? parseInt(poSel.value, 10) : 0;
    var priceMap = (poId && poItemsMap[poId]) ? poItemsMap[poId] : {};
    var gstMap   = (poId && poGstMap[poId])   ? poGstMap[poId]   : {};

    // Always show PO Rate as reference
    var poRateEl = row.querySelector('.d-po-rate');
    if (poRateEl) {
        var pr = priceMap[itemId];
        poRateEl.value = pr !== undefined ? '₹' + parseFloat(pr).toFixed(2) : '-';
    }

    // Unit price + GST only on Delivered
    var statusSel   = document.querySelector('[name="status"]');
    var isDelivered = statusSel && statusSel.value === 'Delivered';
    if (isDelivered) {
        row.querySelector('[name="unit_price[]"]').value = priceMap[itemId] !== undefined ? parseFloat(priceMap[itemId]).toFixed(2) : '';
        row.querySelector('[name="gst_rate[]"]').value   = gstMap[itemId]   !== undefined ? parseFloat(gstMap[itemId]).toFixed(2)   : '';
    }

    dCalcRow(row.querySelector('[name="weight[]"]'));
    syncMTCFields();
}
function dCalcRow(el) {
    if (!el) return;
    var row    = el.closest('tr');
    var price  = parseFloat(row.querySelector('[name="unit_price[]"]')?.value) || 0;
    var weight = parseFloat(row.querySelector('[name="weight[]"]')?.value)     || 0;
    var gst    = parseFloat(row.querySelector('[name="gst_rate[]"]')?.value)   || 0;
    var totalEl = row.querySelector('.d-row-total');
    // Total = PO Unit Rate × Received Weight + GST
    if (price > 0 && weight > 0) {
        var base  = price * weight;
        var total = base + (base * gst / 100);
        if (totalEl) totalEl.value = total.toFixed(2);
    } else {
        if (totalEl) totalEl.value = '0.00';
    }
    dCalcTotal();
    dUpdateWeightAndFreight();
}
function dCalcTotal() {
    let t = 0;
    document.querySelectorAll('#dItemsBody .d-row-total').forEach(f => t += parseFloat(f.value) || 0);
    document.getElementById('dGrandTotal').textContent = '₹' + t.toFixed(2);
}
function dUpdateWeightAndFreight() {
    var totalW  = getTotalWeight();
    var wDisp   = document.getElementById('totalWeightDisplay');
    if (wDisp) wDisp.value = totalW.toFixed(3);

    var rate    = currentTransporterRate || 0;
    var freight = rate * totalW;

    var fEl = document.getElementById('freightAmount');
    var formulaEl = document.getElementById('freightFormula');

    if (rate > 0) {
        if (fEl) fEl.value = freight.toFixed(2);
        var uomLabel = currentRateUom ? '/' + currentRateUom : '/Kg';
        if (formulaEl) formulaEl.innerHTML = '<span class="text-success fw-semibold">'
            + '<i class="bi bi-check-circle me-1"></i>Rate Card: ₹'
            + rate.toFixed(4) + uomLabel + ' × ' + totalW.toFixed(3) + ' = ₹' + freight.toFixed(2) + '</span>';
    } else {
        if (formulaEl) formulaEl.innerHTML = '<span class="text-muted">Select Transporter &amp; Vendor to auto-fill rate</span>';
    }
}

// Run after DOM fully loaded so all weight fields exist
document.addEventListener('DOMContentLoaded', function() {
    dCalcTotal();
    dUpdateWeightAndFreight();
});
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     EMAIL MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
    <div class="modal-header" style="background:linear-gradient(135deg,#1a5632,#2563a8);color:#fff">
        <h5 class="modal-title" id="emailModalLabel">
            <i class="bi bi-envelope-fill me-2"></i>Send Despatch Email
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <!-- Challan reference banner -->
        <div class="alert alert-primary py-2 mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-receipt"></i>
            <span>Challan: <strong id="emailChallanNo"></strong> &nbsp;|&nbsp; Consignee: <strong id="emailConsignee"></strong></span>
            <span class="badge bg-success ms-auto"><i class="bi bi-paperclip me-1"></i>PDF attached</span>
        </div>

        <div class="row g-3">
            <!-- Recipients -->
            <div class="col-12">
                <label class="form-label fw-semibold">
                    <i class="bi bi-people me-1"></i>Send To (Registered Users)
                    <small class="text-muted fw-normal ms-1">— Admin will always receive a CC copy</small>
                </label>
                <div class="border rounded p-2" style="max-height:180px;overflow-y:auto;background:#f8f9fa">
                <?php if (empty($email_users)): ?>
                    <div class="text-muted small p-2">No active users with email addresses found. Please add email addresses in User Management.</div>
                <?php else: ?>
                    <?php foreach ($email_users as $eu): ?>
                    <div class="form-check py-1 border-bottom">
                        <input class="form-check-input email-user-check" type="checkbox"
                               value="<?= $eu['id'] ?>"
                               id="eu_<?= $eu['id'] ?>"
                               data-email="<?= htmlspecialchars($eu['email']) ?>">
                        <label class="form-check-label d-flex align-items-center gap-2" for="eu_<?= $eu['id'] ?>">
                            <span class="fw-semibold"><?= htmlspecialchars($eu['full_name']) ?></span>
                            <span class="text-muted small"><?= htmlspecialchars($eu['email']) ?></span>
                            <span class="badge bg-<?= $eu['role']==='Admin'?'danger':'secondary' ?> ms-auto" style="font-size:.65rem"><?= $eu['role'] ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
                <div class="mt-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllEmailUsers(true)">Select All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="toggleAllEmailUsers(false)">Clear All</button>
                </div>
            </div>

            <!-- Extra email -->
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold"><i class="bi bi-at me-1"></i>Additional Email Address</label>
                <input type="email" id="extraEmailInput" class="form-control" placeholder="other@email.com (optional)">
            </div>

            <!-- Custom note -->
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold"><i class="bi bi-sticky me-1"></i>Custom Note (shown in email body)</label>
                <input type="text" id="emailCustomNote" class="form-control" placeholder="e.g. Please arrange unloading crew (optional)">
            </div>
        </div>

        <!-- Preview of selected emails -->
        <div class="mt-3" id="selectedEmailsPreview" style="display:none">
            <label class="form-label text-muted small">Will be sent to:</label>
            <div id="selectedEmailsList" class="d-flex flex-wrap gap-1"></div>
        </div>

        <!-- Status / result -->
        <div id="emailSendStatus" class="mt-3" style="display:none"></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="emailSendBtn" onclick="sendDespatchEmail()">
            <i class="bi bi-send me-1"></i>Send Email with PDF
        </button>
    </div>
</div>
</div>
</div>

<script>
var emailDespatchId = 0;

function openEmailModal(id, challanNo, consignee) {
    emailDespatchId = id;
    document.getElementById('emailChallanNo').textContent  = challanNo;
    document.getElementById('emailConsignee').textContent  = consignee;
    document.getElementById('emailSendStatus').style.display = 'none';
    document.getElementById('emailSendStatus').innerHTML   = '';
    document.getElementById('extraEmailInput').value       = '';
    document.getElementById('emailCustomNote').value       = '';
    // Uncheck all
    document.querySelectorAll('.email-user-check').forEach(cb => cb.checked = false);
    updateSelectedEmailsPreview();
    var modal = new bootstrap.Modal(document.getElementById('emailModal'));
    modal.show();
}

function toggleAllEmailUsers(state) {
    document.querySelectorAll('.email-user-check').forEach(cb => cb.checked = state);
    updateSelectedEmailsPreview();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.email-user-check').forEach(cb => {
        cb.addEventListener('change', updateSelectedEmailsPreview);
    });
    var extraInp = document.getElementById('extraEmailInput');
    if (extraInp) extraInp.addEventListener('input', updateSelectedEmailsPreview);
});

function updateSelectedEmailsPreview() {
    var list    = document.getElementById('selectedEmailsList');
    var preview = document.getElementById('selectedEmailsPreview');
    if (!list) return;
    list.innerHTML = '';
    var any = false;
    document.querySelectorAll('.email-user-check:checked').forEach(cb => {
        list.innerHTML += '<span class="badge bg-primary">' + cb.dataset.email + '</span>';
        any = true;
    });
    var extra = document.getElementById('extraEmailInput');
    if (extra && extra.value.includes('@')) {
        list.innerHTML += '<span class="badge bg-secondary">' + extra.value + '</span>';
        any = true;
    }
    preview.style.display = any ? 'block' : 'none';
}

function sendDespatchEmail() {
    var checked = document.querySelectorAll('.email-user-check:checked');
    var extra   = document.getElementById('extraEmailInput').value.trim();
    var note    = document.getElementById('emailCustomNote').value.trim();

    if (checked.length === 0 && extra === '') {
        showEmailStatus('warning','<i class="bi bi-exclamation-triangle me-2"></i>Please select at least one recipient or enter an email address.');
        return;
    }

    var recipIds = [];
    checked.forEach(cb => recipIds.push(cb.value));

    var btn = document.getElementById('emailSendBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating PDF & Sending...';
    showEmailStatus('info','<span class="spinner-border spinner-border-sm me-2"></span>Generating PDF and sending email, please wait...');

    var fd = new FormData();
    fd.append('despatch_id', emailDespatchId);
    recipIds.forEach(id => fd.append('recipient_ids[]', id));
    if (extra) fd.append('extra_email', extra);
    if (note)  fd.append('custom_note', note);

    fetch('send_despatch_email.php', { method:'POST', body:fd })
    .then(r => {
        // Capture raw text first — if PHP crashes it returns HTML not JSON
        return r.text().then(text => {
            try {
                return JSON.parse(text);
            } catch(e) {
                // PHP returned an error page — show it
                throw new Error('PHP error: ' + text.replace(/<[^>]+>/g,'').substring(0,300));
            }
        });
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Email with PDF';
        if (data.ok) {
            showEmailStatus('success','<i class="bi bi-check-circle-fill me-2"></i>' + data.msg);
            setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide(); }, 3000);
        } else {
            showEmailStatus('danger','<i class="bi bi-x-circle-fill me-2"></i>' + data.msg);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Email with PDF';
        showEmailStatus('danger','<i class="bi bi-x-circle-fill me-2"></i>' + err.message);
    });
}

function showEmailStatus(type, html) {
    var el = document.getElementById('emailSendStatus');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-' + type + ' py-2 mb-0">' + html + '</div>';
}
</script>

<?php include '../includes/footer.php'; ?>
