<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
requirePerm('company_settings', 'view');

/* Ensure columns exist */
function csAddCol($db, $col, $def) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='company_settings' AND COLUMN_NAME='$col' LIMIT 1")->num_rows)
        $db->query("ALTER TABLE company_settings ADD COLUMN $col $def");
}
csAddCol($db,'smtp_host',             "VARCHAR(120) DEFAULT ''");
csAddCol($db,'smtp_port',             "SMALLINT DEFAULT 587");
csAddCol($db,'smtp_user',             "VARCHAR(120) DEFAULT ''");
csAddCol($db,'smtp_pass',             "VARCHAR(255) DEFAULT ''");
csAddCol($db,'smtp_secure',           "VARCHAR(10) DEFAULT 'tls'");
csAddCol($db,'smtp_from_name',        "VARCHAR(120) DEFAULT ''");
csAddCol($db,'fy_start_no',           "INT DEFAULT 1 COMMENT 'FY challan starting number'");

/* ── Clear any stale R2 keys (don't start with uploads/) ── */

/* ════ CLEANUP HANDLER ════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cleanup_data') {
    requirePerm('company_settings', 'update');
    if (($_POST['confirm_cleanup'] ?? '') === 'YES_DELETE_NOW') {
        $tables_to_clean = [
            'despatch_orders'            => 'Despatch Orders',
            'despatch_items'             => 'Despatch Items',
            'agent_commissions'          => 'Agent Commissions',
            'agent_commission_payments'  => 'Agent Commission Payments',
            'agent_payment_commissions'  => 'Agent Payment Commission Links',
            'sales_invoices'             => 'Sales Invoices',
            'sales_invoice_payments'     => 'Sales Invoice Payments',
            'transporter_payments'       => 'Transporter Payments',
            'doc_sequences'              => 'Challan Sequences',
        ];
        $db->query("SET FOREIGN_KEY_CHECKS = 0");
        $cleaned = 0;
        foreach ($tables_to_clean as $table => $label) {
            if ($db->query("SHOW TABLES LIKE '$table'")->num_rows) {
                $cleaned += (int)$db->query("SELECT COUNT(*) c FROM `$table`")->fetch_assoc()['c'];
                $db->query("TRUNCATE TABLE `$table`");
            }
        }
        $db->query("SET FOREIGN_KEY_CHECKS = 1");
        // Clean uploaded files
        $files_deleted = 0;
        foreach (['/uploads/delivery_docs','/uploads/freight_invoices','/uploads/mtc_docs'] as $d) {
            $dir = dirname(__DIR__) . $d;
            if (is_dir($dir)) foreach (glob("$dir/*") as $f) { if (is_file($f)) { unlink($f); $files_deleted++; } }
        }
        showAlert('success', "Cleanup complete! $cleaned records deleted, $files_deleted files removed. Challan numbering reset. Set your starting number below.");
        redirect('company_settings.php');
    }
}

/* ════ POST HANDLER ════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePerm('company_settings', 'update');

    $existing = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();

    /* Text fields */
    $text_fields = ['company_name','address','city','state','pincode','phone','email',
                    'gstin','pan','bank_name','account_no','ifsc_code',
                    'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure','smtp_from_name'];
    $parts = [];
    foreach ($text_fields as $f) {
        $parts[] = "$f='" . $db->real_escape_string(sanitize($_POST[$f] ?? '')) . "'";
    }
    $parts[] = "fy_start_no=" . (int)($_POST['fy_start_no'] ?? 1);

    if ($existing) {
        $db->query("UPDATE company_settings SET " . implode(',', $parts) . " WHERE id={$existing['id']}");
    } else {
        $cols = implode(',', $text_fields);
        $vals = implode(',', array_map(fn($f) => "'" . $db->real_escape_string(sanitize($_POST[$f] ?? '')) . "'", $text_fields));
        $db->query("INSERT INTO company_settings ($cols) VALUES ($vals)");
        $existing = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();
    }

    // ── Sync doc_sequences: set challan sequence to (fy_start_no - 1) ──
    // so the NEXT generated challan will be fy_start_no
    $new_start = (int)($_POST['fy_start_no'] ?? 1);
    if ($new_start >= 1) {
        $m = (int)date('m'); $y = (int)date('Y');
        $fs = $m >= 4 ? $y : $y - 1;
        $fe = $fs + 1;
        $fy = str_pad($fs % 100, 2, '0', STR_PAD_LEFT) . str_pad($fe % 100, 2, '0', STR_PAD_LEFT);
        $seq_key = "challan_fy{$fy}";
        $set_val = $new_start - 1;
        $db->query("CREATE TABLE IF NOT EXISTS doc_sequences (seq_key VARCHAR(50) PRIMARY KEY, last_val INT UNSIGNED NOT NULL DEFAULT 0)");
        $db->query("INSERT INTO doc_sequences (seq_key, last_val) VALUES ('$seq_key', $set_val)
                    ON DUPLICATE KEY UPDATE last_val = $set_val");
    }


    showAlert('success', 'Company settings saved successfully.');
    redirect('company_settings.php');
}

$company = $db->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc() ?? [];
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-gear me-2"></i>Company Settings';</script>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Company Settings</h5>
</div>
<form method="POST">
<div class="row g-3">

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-building me-2"></i>Company Information</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label">Company Name *</label>
            <input type="text" name="company_name" class="form-control" required value="<?= htmlspecialchars($company['company_name']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($company['phone']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($company['email']??'') ?>">
        </div>
        <div class="col-12 col-md-8">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($company['address']??'') ?></textarea>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($company['city']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">State</label>
            <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($company['state']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Pincode</label>
            <input type="text" name="pincode" class="form-control" value="<?= htmlspecialchars($company['pincode']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">GSTIN</label>
            <input type="text" name="gstin" class="form-control" maxlength="15" value="<?= htmlspecialchars($company['gstin']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">PAN</label>
            <input type="text" name="pan" class="form-control" maxlength="10" value="<?= htmlspecialchars($company['pan']??'') ?>">
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-calendar2-check me-2"></i>Financial Year &amp; Challan Settings</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label fw-semibold">FY Challan Starting Number</label>
            <div class="input-group">
                <span class="input-group-text bg-success text-white">DC/<?php
                    $m=(int)date('m'); $y=(int)date('Y');
                    $fs=$m>=4?$y:$y-1; $fe=$fs+1;
                    echo str_pad($fs%100,2,'0',STR_PAD_LEFT).str_pad($fe%100,2,'0',STR_PAD_LEFT);
                ?>/</span>
                <input type="number" name="fy_start_no" class="form-control" min="1" max="9999"
                       value="<?= (int)($company['fy_start_no'] ?? 1) ?>">
            </div>
            <div class="form-text">Set to <strong>1</strong> for fresh FY start. Set higher if continuing from old system (e.g. 151 means first challan will be DC/2526/0151).</div>
        </div>
        <div class="col-12 col-md-8">
            <div class="alert alert-info py-2 mb-0 mt-4">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Note:</strong> Changing this only affects the <em>next new</em> challan if the current FY sequence has not started yet. 
                To reset mid-year, contact your system administrator to update the <code>doc_sequences</code> table directly.
            </div>
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-bank me-2"></i>Bank Details</div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-sm-6 col-md-5">
            <label class="form-label">Bank Name</label>
            <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($company['bank_name']??'') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Account Number</label>
            <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($company['account_no']??'') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-3">
            <label class="form-label">IFSC Code</label>
            <input type="text" name="ifsc_code" class="form-control" value="<?= htmlspecialchars($company['ifsc_code']??'') ?>">
        </div>
    </div></div></div></div>

    <div class="col-12"><div class="card">
        <div class="card-header"><i class="bi bi-envelope-at me-2"></i>Email / SMTP Settings
            <small class="ms-2 text-muted fw-normal" style="font-size:.75rem">Used for sending Despatch Order emails</small>
        </div>
    <div class="card-body"><div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label">SMTP Host</label>
            <input type="text" name="smtp_host" class="form-control" placeholder="e.g. smtp.gmail.com" value="<?= htmlspecialchars($company['smtp_host']??'') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">SMTP Port</label>
            <input type="number" name="smtp_port" class="form-control" placeholder="587" value="<?= htmlspecialchars($company['smtp_port']??'587') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Security</label>
            <select name="smtp_secure" class="form-select">
                <option value="tls" <?= ($company['smtp_secure']??'tls')==='tls'?'selected':'' ?>>STARTTLS (587)</option>
                <option value="ssl" <?= ($company['smtp_secure']??'')==='ssl'?'selected':'' ?>>SSL (465)</option>
                <option value=""    <?= ($company['smtp_secure']??'')==''?'selected':'' ?>>None (25)</option>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">From Name</label>
            <input type="text" name="smtp_from_name" class="form-control" placeholder="e.g. Despatch Team" value="<?= htmlspecialchars($company['smtp_from_name']??'') ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">SMTP Username</label>
            <input type="text" name="smtp_user" class="form-control" placeholder="your@email.com" value="<?= htmlspecialchars($company['smtp_user']??'') ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">SMTP Password</label>
            <input type="password" name="smtp_pass" class="form-control" value="<?= htmlspecialchars($company['smtp_pass']??'') ?>" autocomplete="new-password">
            <div class="form-text">For Gmail use an App Password (not your login password)</div>
        </div>
    </div></div></div></div>


    <!-- ── Data Cleanup (Beta/Testing) ── -->
    <div class="col-12"><div class="card border-danger">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-trash3 me-2"></i>Data Cleanup <span class="badge bg-warning text-dark ms-2">BETA MODE</span></span>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="document.getElementById('cleanupSection').style.display = document.getElementById('cleanupSection').style.display === 'none' ? 'block' : 'none'">
                <i class="bi bi-chevron-down me-1"></i>Expand
            </button>
        </div>
        <div id="cleanupSection" style="display:none">
        <div class="card-body">
            <div class="alert alert-warning py-2 mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Warning:</strong> This permanently deletes all despatch orders, invoices, payments, commissions, and uploaded documents. This action cannot be undone.
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <h6 class="fw-bold text-danger"><i class="bi bi-trash3 me-1"></i>Will be DELETED:</h6>
                    <ul class="list-group list-group-flush small">
                        <?php
                        $cleanup_tables = [
                            'despatch_orders'           => 'Despatch Orders',
                            'despatch_items'            => 'Despatch Items',
                            'agent_commissions'         => 'Agent Commissions',
                            'agent_commission_payments' => 'Agent Commission Payments',
                            'agent_payment_commissions' => 'Agent Payment Links',
                            'sales_invoices'            => 'Sales Invoices',
                            'sales_invoice_payments'    => 'Sales Invoice Payments',
                            'transporter_payments'      => 'Transporter Payments',
                            'doc_sequences'             => 'Challan Sequences',
                        ];
                        foreach ($cleanup_tables as $ct => $cl):
                            $cex = $db->query("SHOW TABLES LIKE '$ct'")->num_rows;
                            $cc  = $cex ? (int)$db->query("SELECT COUNT(*) c FROM `$ct`")->fetch_assoc()['c'] : 0;
                        ?>
                        <li class="list-group-item d-flex justify-content-between py-1 px-0 border-0">
                            <span><?= $cl ?></span>
                            <span class="badge bg-danger"><?= $cc ?></span>
                        </li>
                        <?php endforeach; ?>
                        <li class="list-group-item py-1 px-0 border-0 text-muted"><small>+ uploaded files (delivery docs, freight invoices, MTC docs)</small></li>
                    </ul>
                </div>
                <div class="col-12 col-md-6">
                    <h6 class="fw-bold text-success"><i class="bi bi-shield-check me-1"></i>Will be KEPT (untouched):</h6>
                    <ul class="list-group list-group-flush small">
                        <?php
                        $keep_tables = [
                            'items'=>'Item Master','purchase_orders'=>'Purchase Orders','po_items'=>'PO Items',
                            'transporters'=>'Transporter Master','transporter_rates'=>'Rate Cards',
                            'vendors'=>'Vendor Master','companies'=>'Companies','company_settings'=>'Company Settings',
                            'app_users'=>'Users & Roles','source_of_material'=>'Source of Material',
                        ];
                        foreach ($keep_tables as $kt => $kl):
                            $kex = $db->query("SHOW TABLES LIKE '$kt'")->num_rows;
                            $kc  = $kex ? (int)$db->query("SELECT COUNT(*) c FROM `$kt`")->fetch_assoc()['c'] : 0;
                        ?>
                        <li class="list-group-item d-flex justify-content-between py-1 px-0 border-0">
                            <span><?= $kl ?></span>
                            <span class="badge bg-success"><?= $kc ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="card-footer bg-light">
            <button type="button" class="btn btn-danger" onclick="confirmCleanup()">
                <i class="bi bi-trash3 me-1"></i>Delete All Data Listed Above &amp; Reset
            </button>
        </div>
        </div>
    </div></div>

    <div class="col-12 text-end">
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Settings</button>
    </div>
</div>
</form>

<!-- Cleanup form is OUTSIDE the main settings form to prevent accidental submission -->
<form method="POST" id="cleanupForm" style="display:none">
    <input type="hidden" name="action" value="cleanup_data">
    <input type="hidden" name="confirm_cleanup" value="YES_DELETE_NOW">
</form>

<script>
function confirmCleanup() {
    var step1 = confirm('WARNING!\n\nThis will permanently delete ALL despatch orders, invoices, payments, commissions, and uploaded documents.\n\nThis action CANNOT be undone.\n\nAre you sure you want to continue?');
    if (!step1) return;
    var typed = prompt('Type  DELETE  (in capitals) to confirm permanent data deletion:');
    if (typed !== 'DELETE') {
        alert('Cancelled. You must type DELETE exactly to proceed.');
        return;
    }
    document.getElementById('cleanupForm').submit();
}
</script>

<?php include '../includes/footer.php'; ?>
