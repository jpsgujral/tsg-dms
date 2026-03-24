<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
/* ── Page-level view permission check ── */
requirePerm('transporter_payments', 'view');

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Safe ALTER ── */
function safeAddColumn($db, $table, $column, $definition) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'
        LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
safeAddColumn($db, 'transporter_payments', 'base_amount',    'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'gst_type',       "VARCHAR(20) DEFAULT ''");
safeAddColumn($db, 'transporter_payments', 'gst_rate',       'DECIMAL(5,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'gst_amount',     'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'gst_held',       "ENUM('No','Yes') DEFAULT 'No'");
safeAddColumn($db, 'transporter_payments', 'tds_rate',       'DECIMAL(5,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'tds_amount',     'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'net_payable',    'DECIMAL(12,2) DEFAULT 0');
safeAddColumn($db, 'transporter_payments', 'is_gst_release', "ENUM('No','Yes') DEFAULT 'No'");

/* ── Ensure payment_type is VARCHAR (not ENUM) so 'GST Release' is accepted ── */
(function() use ($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $col = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='transporter_payments'
        AND COLUMN_NAME='payment_type' LIMIT 1")->fetch_row();
    if ($col && strtolower($col[0]) === 'enum') {
        $db->query("ALTER TABLE transporter_payments
            MODIFY COLUMN payment_type VARCHAR(50) DEFAULT ''");
    }
})();

function generatePaymentNo($db) {
    $year = date('Y'); $month = date('m');
    $c = $db->query("SELECT COUNT(*) c FROM transporter_payments
                     WHERE YEAR(payment_date)=$year AND MONTH(payment_date)=$month")
            ->fetch_assoc()['c'] + 1;
    return "TP/{$year}/{$month}/" . str_pad($c, 4, '0', STR_PAD_LEFT);
}

/* ── DELETE ── */
if (isset($_GET['delete'])) {
    requirePerm('transporter_payments', 'delete');
    $db->query("DELETE FROM transporter_payments WHERE id=" . (int)$_GET['delete']);
    showAlert('success', 'Payment deleted.');
    redirect('transporter_payments.php');
}

/* ── SAVE / UPDATE ── */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requirePerm('transporter_payments', $id > 0 ? 'update' : 'create');
    $payment_no      = sanitize($_POST['payment_no']      ?? generatePaymentNo($db));
    $payment_date    = sanitize($_POST['payment_date']    ?? date('Y-m-d'));
    $transporter_id  = (int)($_POST['transporter_id']     ?? 0);
    $despatch_id_raw = (int)($_POST['despatch_id']        ?? 0);
    $despatch_val    = $despatch_id_raw > 0 ? $despatch_id_raw : 'NULL';
    $payment_type    = sanitize($_POST['payment_type']    ?? '');
    $payment_mode    = sanitize($_POST['payment_mode']    ?? '');
    $reference_no    = sanitize($_POST['reference_no']    ?? '');
    $bank_name       = sanitize($_POST['bank_name']       ?? '');
    $remarks         = sanitize($_POST['remarks']         ?? '');
    $status          = sanitize($_POST['status']          ?? 'Pending');
    $is_gst_release  = sanitize($_POST['is_gst_release']  ?? 'No');

    // All tax figures come from hidden computed fields (read-only display, server recomputes)
    $gst_type  = sanitize($_POST['gst_type']  ?? '');
    $gst_rate  = (float)($_POST['gst_rate']   ?? 0);
    $gst_held  = sanitize($_POST['gst_held']  ?? 'No');
    $tds_rate  = (float)($_POST['tds_rate']   ?? 0);

    // Amount being paid this transaction (user-entered)
    $amount_this_payment = (float)($_POST['amount_this_payment'] ?? 0);

    $errors = [];
    if ($transporter_id < 1)      $errors[] = 'Transporter is required.';
    if ($despatch_id_raw < 1)     $errors[] = 'Despatch / Challan must be selected.';
    if ($amount_this_payment <= 0) $errors[] = 'Amount being paid must be greater than 0.';

    if ($despatch_id_raw > 0 && empty($errors)) {
        $excl = $id > 0 ? "AND tp.id != $id" : '';
        $bal = $db->query("
            SELECT d.freight_amount,
                   t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' $excl THEN tp.base_amount ELSE 0 END),0) paid_base,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' $excl THEN tp.gst_amount  ELSE 0 END),0) paid_gst,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' $excl THEN tp.gst_amount ELSE 0 END),0) gst_on_hold,
                   COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' $excl THEN tp.gst_amount ELSE 0 END),0) gst_released
            FROM despatch_orders d
            LEFT JOIN transporters t ON d.transporter_id=t.id
            LEFT JOIN transporter_payments tp ON tp.despatch_id=d.id
            WHERE d.id=$despatch_id_raw GROUP BY d.id
        ")->fetch_assoc();

        if ($bal) {
            $freight      = (float)$bal['freight_amount'];
            $t_gst_type   = $bal['gst_type']   ?? $gst_type;
            $t_gst_rate   = (float)($bal['gst_rate']   ?? $gst_rate);
            $t_tds_rate   = (float)($bal['tds_rate']   ?? $tds_rate);
            $t_tds_ok     = ($bal['tds_applicable'] ?? 'No') === 'Yes';
            $paid_base    = (float)$bal['paid_base'];
            $gst_on_hold  = (float)$bal['gst_on_hold'];

            // Total GST on full freight
            $full_gst = ($t_gst_type !== 'RCM') ? round($freight * $t_gst_rate / 100, 2) : 0;
            $full_tds = $t_tds_ok ? round($freight * $t_tds_rate / 100, 2) : 0;
            $rem_base = round($freight - $paid_base, 2);

            $gst_released_amt = (float)$bal['gst_released'];
            $net_gst_hold = max(0, round($gst_on_hold - $gst_released_amt, 2));
            if ($is_gst_release === 'Yes') {
                // GST release: paying only net remaining held GST
                if ($net_gst_hold < 0.01) $errors[] = 'No GST is currently on hold for this challan (already released).';
                elseif ($amount_this_payment > $net_gst_hold + 0.005)
                    $errors[] = 'GST release amount ₹'.number_format($amount_this_payment,2).' exceeds remaining held GST ₹'.number_format($net_gst_hold,2).'.';
                // For GST release: base=0, gst_amount = amount_this_payment, tds=0
                $base_amount = 0;
                $gst_amount  = $amount_this_payment; // actual amount being released
                $tds_amount  = 0;
                $net_payable = $amount_this_payment;
                $gst_held    = 'No'; // releasing, so not held
                $gst_type    = $t_gst_type;
                $gst_rate    = $t_gst_rate;
            } else {
                // Normal payment: paying freight base (+ GST optionally, - TDS)
                if ($rem_base < 0.01) $errors[] = 'Freight base is already fully paid for this challan.';
                elseif ($amount_this_payment > $rem_base + 0.005)
                    $errors[] = 'Amount ₹'.number_format($amount_this_payment,2).' exceeds remaining freight ₹'.number_format($rem_base,2).'.';

                $base_amount = $amount_this_payment;
                $gst_amount  = ($t_gst_type !== 'RCM') ? round($base_amount * $t_gst_rate / 100, 2) : 0;
                $tds_amount  = $t_tds_ok ? round($base_amount * $t_tds_rate / 100, 2) : 0;
                $gst_type    = $t_gst_type;
                $gst_rate    = $t_gst_rate;
                $tds_rate    = $t_tds_rate;
                $net_payable = round($base_amount + ($gst_held === 'Yes' ? 0 : $gst_amount) - $tds_amount, 2);
            }
            $amount = $net_payable;
        } else {
            $errors[] = 'Despatch record not found.';
        }
    } else {
        $base_amount = $gst_amount = $tds_amount = $net_payable = $amount = 0;
    }

    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        $esc = fn($v) => $db->real_escape_string($v);
        if ($id > 0) {
            $db->query("UPDATE transporter_payments SET
                payment_no='{$esc($payment_no)}', payment_date='{$esc($payment_date)}',
                transporter_id=$transporter_id, despatch_id=$despatch_val,
                payment_type='{$esc($payment_type)}', amount=$amount,
                base_amount=$base_amount, gst_type='{$esc($gst_type)}',
                gst_rate=$gst_rate, gst_amount=$gst_amount, gst_held='{$esc($gst_held)}',
                tds_rate=$tds_rate, tds_amount=$tds_amount, net_payable=$net_payable,
                is_gst_release='{$esc($is_gst_release)}',
                payment_mode='{$esc($payment_mode)}', reference_no='{$esc($reference_no)}',
                bank_name='{$esc($bank_name)}', remarks='{$esc($remarks)}', status='{$esc($status)}'
                WHERE id=$id");
            showAlert('success', 'Payment updated.');
        } else {
            $db->query("INSERT INTO transporter_payments
                (payment_no,payment_date,transporter_id,despatch_id,payment_type,
                 amount,base_amount,gst_type,gst_rate,gst_amount,gst_held,is_gst_release,
                 tds_rate,tds_amount,net_payable,payment_mode,reference_no,bank_name,remarks,status)
                VALUES
                ('{$esc($payment_no)}','{$esc($payment_date)}',$transporter_id,$despatch_val,
                 '{$esc($payment_type)}',$amount,$base_amount,'{$esc($gst_type)}',
                 $gst_rate,$gst_amount,'{$esc($gst_held)}','{$esc($is_gst_release)}',
                 $tds_rate,$tds_amount,$net_payable,
                 '{$esc($payment_mode)}','{$esc($reference_no)}',
                 '{$esc($bank_name)}','{$esc($remarks)}','{$esc($status)}')");
            showAlert('success', 'Payment recorded.');
        }
        redirect('transporter_payments.php');
    }
}

/* ── Edit fetch ── */
$payment = [];
if ($action == 'edit' && $id > 0) {
    $payment = $db->query("SELECT * FROM transporter_payments WHERE id=$id")->fetch_assoc();
}

/* ── Transporters ── */
$transporters = $db->query("
    SELECT id, transporter_name, gst_type, gst_rate, tds_applicable, tds_rate
    FROM transporters WHERE status='Active' ORDER BY transporter_name
")->fetch_all(MYSQLI_ASSOC);

/* ── Outstanding due rows for due table ── */
$due_rows = $db->query("
    SELECT d.id, d.challan_no, d.despatch_no, d.despatch_date,
        d.consignee_name, d.consignee_city, d.status AS despatch_status,
        d.lr_number, d.freight_amount, d.freight_paid_by,
        t.transporter_name, t.id AS transporter_id,
        t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
        COALESCE(t.credit_days, 0) AS credit_days,
        COALESCE((SELECT tr.rate FROM transporter_rates tr
                  WHERE tr.transporter_id=d.transporter_id AND tr.vendor_id=d.vendor_id
                  AND tr.status='Active' LIMIT 1), 0) AS rate_card_rate,
        COALESCE((SELECT tr.uom FROM transporter_rates tr
                  WHERE tr.transporter_id=d.transporter_id AND tr.vendor_id=d.vendor_id
                  AND tr.status='Active' LIMIT 1), '') AS rate_card_uom,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.base_amount ELSE 0 END),0) AS paid_base,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.gst_amount  ELSE 0 END),0) AS paid_gst_total,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' THEN tp.gst_amount ELSE 0 END),0) AS gst_on_hold,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' THEN tp.gst_amount ELSE 0 END),0) AS gst_released,
        COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.net_payable  ELSE 0 END),0) AS paid_net
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    LEFT JOIN transporter_payments tp ON tp.despatch_id = d.id
    WHERE d.freight_amount > 0 AND d.transporter_id IS NOT NULL AND d.status = 'Delivered'
    GROUP BY d.id
    HAVING (d.freight_amount - paid_base) > 0.009 OR (gst_on_hold - gst_released) > 0.009
    ORDER BY d.despatch_date ASC
")->fetch_all(MYSQLI_ASSOC);

/* ── All despatches for dropdown (all with freight) ── */
$all_despatches = $db->query("
    SELECT d.id, d.challan_no, d.consignee_name, d.freight_amount, d.despatch_date, d.transporter_id,
           COALESCE(t.rate_per_kg, 0) rate_per_kg,
           COALESCE(d.total_weight, 0) total_weight,
           t.gst_type, t.gst_rate, t.tds_applicable, t.tds_rate,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.base_amount ELSE 0 END),0) paid_base,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' THEN tp.gst_amount  ELSE 0 END),0) paid_gst,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.gst_held='Yes' THEN tp.gst_amount ELSE 0 END),0) gst_on_hold,
           COALESCE(SUM(CASE WHEN tp.status!='Cancelled' AND tp.is_gst_release='Yes' THEN tp.gst_amount ELSE 0 END),0) gst_released
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id=t.id
    LEFT JOIN transporter_payments tp ON tp.despatch_id=d.id
    WHERE d.freight_amount > 0 AND d.status = 'Delivered'
    GROUP BY d.id
    ORDER BY d.despatch_date DESC LIMIT 400
")->fetch_all(MYSQLI_ASSOC);

/* ── All payments keyed by despatch_id ── */
$all_payments_by_despatch = [];
if (!empty($all_despatches)) {
    $dids = implode(',', array_column($all_despatches, 'id'));
    $pmts = $db->query("
        SELECT tp.id, tp.despatch_id, tp.payment_no, tp.payment_date, tp.base_amount,
               tp.gst_amount, tp.gst_held, tp.is_gst_release, tp.tds_amount, tp.net_payable,
               tp.payment_type, tp.payment_mode, tp.status, tp.gst_type, tp.gst_rate, tp.tds_rate, tp.remarks
        FROM transporter_payments tp
        WHERE tp.despatch_id IN ($dids)
        ORDER BY tp.payment_date ASC, tp.id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($pmts as $pm) {
        $all_payments_by_despatch[(int)$pm['despatch_id']][] = $pm;
    }
}

/* ── Summary stats ── */
$total_paid     = (float)$db->query("SELECT COALESCE(SUM(net_payable),0) s FROM transporter_payments WHERE status='Paid'")->fetch_assoc()['s'];
$total_pending  = (float)$db->query("SELECT COALESCE(SUM(net_payable),0) s FROM transporter_payments WHERE status='Pending'")->fetch_assoc()['s'];
$this_month     = (float)$db->query("SELECT COALESCE(SUM(net_payable),0) s FROM transporter_payments WHERE status='Paid' AND MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())")->fetch_assoc()['s'];
$gst_held_total = (float)$db->query("SELECT COALESCE(SUM(gst_amount),0) s FROM transporter_payments WHERE gst_held='Yes' AND status!='Cancelled'")->fetch_assoc()['s'];
$total_outstanding = array_sum(array_map(fn($r) => max(0,$r['freight_amount'] - $r['paid_base']) + max(0,$r['gst_on_hold'] - $r['gst_released']), $due_rows));

/* Pre-fill for Pay Now */
$pf_despatch_id = (int)($_GET['pay_despatch'] ?? 0);
$pf_transporter = 0;
if ($pf_despatch_id > 0) {
    foreach ($due_rows as $dr) {
        if ($dr['id'] == $pf_despatch_id) { $pf_transporter = $dr['transporter_id']; break; }
    }
}

/* Edit defaults */
$edit_gst_type      = $payment['gst_type']       ?? '';
$edit_gst_held      = $payment['gst_held']        ?? 'No';
$edit_is_gst_release= $payment['is_gst_release']  ?? 'No';

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-cash-coin me-2"></i>Transporter Payments';</script>

<?php if ($action == 'edit'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Edit Payment — <?= htmlspecialchars($payment['payment_no']) ?></h5>
    <a href="transporter_payments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Transporter Payments</h5>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-danger fs-3"><i class="bi bi-exclamation-circle"></i></div>
            <h5 class="text-danger mb-0">₹<?= number_format($total_outstanding,2) ?></h5>
            <small class="text-muted fw-semibold">Total Outstanding</small>
            <div class="mt-1"><span class="badge bg-danger"><?= count($due_rows) ?> despatch(es)</span></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-success fs-3"><i class="bi bi-check-circle"></i></div>
            <h5 class="text-success mb-0">₹<?= number_format($total_paid,2) ?></h5>
            <small class="text-muted fw-semibold">Total Paid (Net)</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-warning fs-3"><i class="bi bi-shield-exclamation"></i></div>
            <h5 class="text-warning mb-0">₹<?= number_format($gst_held_total,2) ?></h5>
            <small class="text-muted fw-semibold">GST On Hold</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center border-0 shadow-sm">
            <div class="text-primary fs-3"><i class="bi bi-calendar-check"></i></div>
            <h5 class="text-primary mb-0">₹<?= number_format($this_month,2) ?></h5>
            <small class="text-muted fw-semibold">Paid This Month</small>
        </div>
    </div>
</div>

<!-- Record Payment Card -->
<div class="card border-primary mb-4" id="recordPaymentCard">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"
         style="cursor:pointer" onclick="togglePaymentForm()">
        <span><i class="bi bi-cash-coin me-2"></i>Record Payment</span>
        <span id="toggleIcon"><i class="bi bi-chevron-up"></i></span>
    </div>
    <div id="paymentFormBody">
    <div class="card-body">
<?php endif; ?>

<!-- ══ FORM (shared: list inline + edit page) ══ -->
<form method="POST" id="paymentForm" onsubmit="return validatePayment()">
<input type="hidden" name="gst_type"      id="fGstType"     value="<?= htmlspecialchars($edit_gst_type) ?>">
<input type="hidden" name="gst_rate"      id="fGstRate"     value="0">
<input type="hidden" name="gst_held"      id="fGstHeld"     value="<?= htmlspecialchars($edit_gst_held) ?>">
<input type="hidden" name="tds_rate"      id="fTdsRate"     value="0">
<input type="hidden" name="is_gst_release" id="fIsGstRelease" value="<?= htmlspecialchars($edit_is_gst_release) ?>">

<div class="row g-3">

<!-- ══ Section 1: Reference ══ -->
<div class="col-6 col-sm-3 col-md-2">
    <label class="form-label">Payment No</label>
    <input type="text" name="payment_no" class="form-control form-control-sm"
           value="<?= htmlspecialchars($action=='edit' ? ($payment['payment_no']??'') : generatePaymentNo($db)) ?>">
</div>
<div class="col-6 col-sm-3 col-md-2">
    <label class="form-label">Date *</label>
    <input type="date" name="payment_date" class="form-control form-control-sm" required
           value="<?= $action=='edit' ? ($payment['payment_date']??date('Y-m-d')) : date('Y-m-d') ?>">
</div>
<div class="col-12 col-sm-6 col-md-3">
    <label class="form-label">Transporter *</label>
    <select name="transporter_id" id="transporterSel" class="form-select form-select-sm"
            required onchange="onTransporterChange(this)">
        <option value="">-- Select Transporter --</option>
        <?php foreach($transporters as $tr): ?>
        <option value="<?= $tr['id'] ?>"
            data-gst-type="<?= htmlspecialchars($tr['gst_type']??'') ?>"
            data-gst-rate="<?= (float)($tr['gst_rate']??0) ?>"
            data-tds-applicable="<?= $tr['tds_applicable']??'No' ?>"
            data-tds-rate="<?= (float)($tr['tds_rate']??0) ?>"
            <?= (($action=='edit'?($payment['transporter_id']??0):$pf_transporter)==$tr['id'])?'selected':'' ?>>
            <?= htmlspecialchars($tr['transporter_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-12 col-sm-6 col-md-4">
    <label class="form-label">Despatch / Challan *
        <small class="text-info" id="despatchFilterNote" style="display:none">— filtered by transporter</small>
    </label>
    <select name="despatch_id" id="despatchRef" class="form-select form-select-sm"
            required onchange="onDespatchChange(this)">
        <option value="">-- Select Challan --</option>
        <?php foreach($all_despatches as $d): ?>
        <option value="<?= $d['id'] ?>"
            data-transporter="<?= $d['transporter_id'] ?>"
            data-freight="<?= (float)$d['freight_amount'] ?>"
            data-paid-base="<?= (float)$d['paid_base'] ?>"
            data-gst-on-hold="<?= (float)$d['gst_on_hold'] ?>"
            data-gst-type="<?= htmlspecialchars($d['gst_type']??'') ?>"
            data-gst-rate="<?= (float)($d['gst_rate']??0) ?>"
            data-tds-applicable="<?= $d['tds_applicable']??'No' ?>"
            data-tds-rate="<?= (float)($d['tds_rate']??0) ?>"
            data-rate-per-kg="<?= (float)($d['rate_per_kg']??0) ?>"
            data-total-weight="<?= (float)($d['total_weight']??0) ?>"
            <?= (($action=='edit'?($payment['despatch_id']??0):$pf_despatch_id)==$d['id'])?'selected':'' ?>>
            <?= htmlspecialchars($d['challan_no'].' — '.$d['consignee_name']) ?>
            (₹<?= number_format($d['freight_amount'],2) ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-6 col-sm-3 col-md-1">
    <label class="form-label">Status</label>
    <select name="status" class="form-select form-select-sm">
        <?php foreach(['Paid','Pending','Cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= (($action=='edit'?($payment['status']??'Pending'):'Pending')==$s)?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- ══ Section 2: Tax & Amount — READ ONLY display (totals for full challan) ══ -->
<div class="col-12" id="taxAmountSection" style="display:none">
<hr class="my-1">
<small class="text-muted fw-semibold text-uppercase">Freight &amp; Tax Summary — <span class="text-primary" id="roChallanlabel"></span></small>

<!-- Challan info row: Rate & Weight -->
<div class="row g-2 mt-1 mb-2">
    <div class="col-6 col-sm-3 col-md-2">
        <label class="form-label text-muted small mb-1">Freight Rate</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-light">₹</span>
            <div class="form-control form-control-sm bg-light text-secondary" id="roRatePerKg">—</div>
            <span class="input-group-text bg-light text-muted" style="font-size:10px">/kg</span>
        </div>
    </div>
    <div class="col-6 col-sm-3 col-md-2">
        <label class="form-label text-muted small mb-1">Total Weight</label>
        <div class="input-group input-group-sm">
            <div class="form-control form-control-sm bg-light text-secondary" id="roTotalWeight">—</div>
            <span class="input-group-text bg-light text-muted" style="font-size:10px">MT</span>
        </div>
    </div>
</div>

<!-- Top row: full challan totals -->
<div class="row g-2 mt-1 align-items-end">
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label text-muted small mb-1">Total Freight</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-light">₹</span>
            <div class="form-control form-control-sm bg-light fw-semibold text-dark" id="roFreight">—</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md-2" id="roGstWrap">
        <label class="form-label text-muted small mb-1" id="roGstLabel">+ GST</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-light text-success">+₹</span>
            <div class="form-control form-control-sm bg-light text-success fw-semibold" id="roGstAmt">0.00</div>
        </div>
        <div class="form-text text-muted" id="roGstDesc" style="font-size:10px"></div>
    </div>
    <div class="col-6 col-sm-4 col-md-2" id="roTdsWrap" style="display:none">
        <label class="form-label text-muted small mb-1">− TDS</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-light text-danger">−₹</span>
            <div class="form-control form-control-sm bg-light text-danger fw-semibold" id="roTdsAmt">0.00</div>
        </div>
        <div class="form-text text-danger" id="roTdsDesc" style="font-size:10px"></div>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label text-muted small mb-1 fw-semibold">= Total Due</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text" style="background:#e8f0fe">₹</span>
            <div class="form-control form-control-sm fw-bold text-primary" style="background:#e8f0fe" id="roTotalDue">—</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label text-muted small mb-1">Total Paid</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-light text-success">₹</span>
            <div class="form-control form-control-sm bg-light text-success fw-semibold" id="roTotalPaid">—</div>
        </div>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label text-muted small mb-1 fw-bold text-danger">Balance Remaining</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-danger text-white">₹</span>
            <div class="form-control form-control-sm fw-bold text-danger" style="border-color:#dc3545" id="roBalance">—</div>
        </div>
    </div>
</div>

<!-- GST Hold alert -->
<div class="mt-2" id="roGstHoldWrap" style="display:none">
    <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-3">
        <i class="bi bi-shield-exclamation fs-5 flex-shrink-0"></i>
        <div class="flex-grow-1">
            <strong>GST On Hold: <span id="roGstOnHold">₹0.00</span></strong>
            — withheld pending transporter GST compliance.
        </div>
        <button type="button" class="btn btn-sm btn-warning flex-shrink-0" onclick="activateGstRelease()">
            <i class="bi bi-unlock me-1"></i>Release GST Now
        </button>
    </div>
</div>

<!-- GST Release mode banner -->
<div class="mt-2" id="gstReleaseNotice" style="display:none">
    <div class="alert alert-success py-2 px-3 mb-0 d-flex align-items-center gap-3">
        <i class="bi bi-check-circle-fill fs-5 flex-shrink-0 text-success"></i>
        <div class="flex-grow-1">
            <strong>GST Release Mode</strong> — Recording payment to release held GST of
            <strong id="roGstReleaseAmt">₹0.00</strong> to the transporter.
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="deactivateGstRelease()">
            <i class="bi bi-x me-1"></i>Cancel
        </button>
    </div>
</div>

</div><!-- taxAmountSection -->

<!-- ══ Section 3: Payment Amount ══ -->
<div class="col-12" id="paymentAmountSection" style="display:none">
<hr class="my-1">
<small class="text-muted fw-semibold text-uppercase">Payment Details</small>
<div class="row g-3 mt-1">

    <!-- Amount this payment -->
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label fw-semibold" id="amtLabel">Amount Being Paid (₹) *</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text">₹</span>
            <input type="number" name="amount_this_payment" id="amountThisPayment"
                   step="0.01" min="0.01" class="form-control" required
                   oninput="onAmountInput()">
        </div>
        <div class="form-text" id="amtHint"></div>
    </div>

    <!-- GST Hold toggle (only for normal payments, not GST release) -->
    <div class="col-12 col-sm-6 col-md-3" id="gstHoldToggleWrap" style="display:none">
        <label class="form-label fw-semibold">Hold GST on This Payment</label>
        <select id="gstHoldToggle" class="form-select form-select-sm" onchange="onGstHoldChange()">
            <option value="No">No — Pay GST now</option>
            <option value="Yes">Yes — Hold GST until compliance</option>
        </select>
        <div class="form-text text-warning" id="gstHoldNote" style="display:none">
            <i class="bi bi-shield-exclamation me-1"></i>GST withheld pending compliance
        </div>
    </div>

    <!-- Net payable this transaction (computed, read-only) -->
    <div class="col-6 col-sm-4 col-md-2" id="netThisWrap" style="display:none">
        <label class="form-label fw-semibold">Net to Transfer (₹)</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text text-primary fw-bold">₹</span>
            <div class="form-control fw-bold text-primary" style="background:#e8f0fe" id="netThisPayment">0.00</div>
        </div>
        <div class="form-text" id="netThisBreakdown"></div>
    </div>

    <!-- Overpayment warning -->
    <div class="col-12" id="overPayWarn" style="display:none">
        <div class="alert alert-danger py-1 px-2 mb-0 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="overPayMsg"></span>
        </div>
    </div>

    <!-- Payment method fields -->
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Payment Type</label>
        <select name="payment_type" id="paymentTypeSel" class="form-select form-select-sm">
            <option value="Full Settlement" <?= (($payment['payment_type']??'')=='Full Settlement')?'selected':'' ?>>Full Settlement</option>
            <option value="Partial"         <?= (($payment['payment_type']??'')=='Partial')?'selected':'' ?>>Partial</option>
            <option value="Advance"         <?= (($payment['payment_type']??'')=='Advance')?'selected':'' ?>>Advance</option>
            <option value="Against LR"      <?= (($payment['payment_type']??'')=='Against LR')?'selected':'' ?>>Against LR</option>
            <option value="GST Release"     <?= (($payment['payment_type']??'')=='GST Release')?'selected':'' ?>>GST Release</option>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select form-select-sm">
            <?php foreach(['Bank Transfer','NEFT','RTGS','UPI','Cheque','Cash'] as $pm): ?>
            <option value="<?= $pm ?>" <?= (($payment['payment_mode']??'Bank Transfer')==$pm)?'selected':'' ?>><?= $pm ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label">Reference / UTR No</label>
        <input type="text" name="reference_no" class="form-control form-control-sm"
               value="<?= htmlspecialchars($payment['reference_no']??'') ?>">
    </div>
    <div class="col-6 col-sm-4 col-md-3">
        <label class="form-label">Bank Name</label>
        <input type="text" name="bank_name" class="form-control form-control-sm"
               value="<?= htmlspecialchars($payment['bank_name']??'') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control form-control-sm"
               value="<?= htmlspecialchars($payment['remarks']??'') ?>">
    </div>

    <div class="col-12 text-end">
        <?php if ($action=='edit'): ?>
        <a href="transporter_payments.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Update Payment</button>
        <?php else: ?>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Payment</button>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- ══ Section 4: Previous Payments for This Challan ══ -->
<div class="col-12" id="priorPaymentsWrap" style="display:none">
<hr class="my-1">
<small class="text-muted fw-semibold text-uppercase">Previous Payments for This Challan</small>
<div class="table-responsive mt-2">
<table class="table table-sm table-bordered mb-0 align-middle">
    <thead class="table-light"><tr>
        <th>#</th><th>Payment No</th><th>Date</th><th>Type</th>
        <th class="text-end">Freight Paid</th>
        <th class="text-end">GST</th>
        <th class="text-end">TDS</th>
        <th class="text-end">Net Paid</th>
        <th>Mode</th><th>Status</th>
    </tr></thead>
    <tbody id="priorPaymentsBody"></tbody>
    <tfoot class="table-light fw-bold" id="priorPaymentsFoot"></tfoot>
</table>
</div>
</div>

</div><!-- row -->
</form>

<?php if ($action != 'edit'): ?>
    </div><!-- card-body -->
    </div><!-- paymentFormBody -->
</div><!-- recordPaymentCard -->

<!-- ══ OUTSTANDING DUE TABLE ══ -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center"
         style="background:linear-gradient(135deg,#c0392b,#e74c3c)">
        <span class="text-white fw-semibold">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>Outstanding — Freight + GST Due
        </span>
        <span class="badge bg-light text-danger"><?= count($due_rows) ?> pending</span>
    </div>
    <?php if (empty($due_rows)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="bi bi-check2-all fs-2 text-success"></i><br>All freight payments fully cleared!
    </div>
    <?php else: ?>
    <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr>
            <th>#</th><th>Challan</th><th>Despatch Date</th><th>Due Date</th><th>Consignee</th><th>Transporter</th>
            <th class="text-end">Freight</th><th class="text-end">Paid</th>
            <th class="text-end">GST Hold</th><th class="text-end text-danger">Balance Due</th>
            <th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php $i=1; foreach ($due_rows as $row):
            $full_gst    = ($row['gst_type']!=='RCM') ? round($row['freight_amount']*($row['gst_rate']??0)/100,2) : 0;
            $full_tds    = ($row['tds_applicable']??'No')==='Yes' ? round($row['freight_amount']*($row['tds_rate']??0)/100,2) : 0;
            $bal_freight = $row['freight_amount'] - $row['paid_base'];
            $net_gst_hold= max(0, $row['gst_on_hold'] - $row['gst_released']);
            $bal_total   = $bal_freight + $net_gst_hold;
            $sb = ['Delivered'=>'success','In Transit'=>'warning','Despatched'=>'primary','Draft'=>'secondary','Cancelled'=>'danger'][$row['despatch_status']]??'secondary';
        ?>
        <?php
            $credit_days = (int)($row['credit_days'] ?? 0);
            $due_date    = $credit_days > 0
                ? date('d/m/Y', strtotime($row['despatch_date'] . " +{$credit_days} days"))
                : '—';
            $due_ts      = $credit_days > 0 ? strtotime($row['despatch_date'] . " +{$credit_days} days") : 0;
            $today       = time();
            $days_left   = $due_ts > 0 ? (int)ceil(($due_ts - $today) / 86400) : null;
            $due_badge   = '';
            if ($due_ts > 0 && $bal_total > 0) {
                if ($days_left < 0)
                    $due_badge = '<br><span class="badge bg-danger">Overdue '.abs($days_left).' days</span>';
                elseif ($days_left <= 7)
                    $due_badge = '<br><span class="badge bg-warning text-dark">Due in '.$days_left.' days</span>';
                else
                    $due_badge = '<br><span class="badge bg-success">'.$days_left.' days left</span>';
            }
            $row_class = ($row['despatch_status']==='Delivered' && $bal_total > 0 && $due_ts > 0 && $days_left < 0)
                ? 'table-danger'
                : (($row['despatch_status']==='Delivered' && $bal_total > 0 && $due_ts > 0 && $days_left <= 7)
                    ? 'table-warning' : '');
        ?>
        <tr class="<?= $row_class ?>">
            <td><?= $i++ ?></td>
            <td><strong><?= htmlspecialchars($row['challan_no']) ?></strong></td>
            <td><?= date('d/m/Y',strtotime($row['despatch_date'])) ?></td>
            <td><?= $due_date ?><?= $due_badge ?></td>
            <td><?= htmlspecialchars($row['consignee_name']) ?><?php if($row['consignee_city']): ?><br><small class="text-muted"><?= htmlspecialchars($row['consignee_city']) ?></small><?php endif; ?></td>
            <td><?= htmlspecialchars($row['transporter_name']??'—') ?><?php if($row['gst_type']): ?><br><small class="badge bg-light text-dark"><?= $row['gst_type']==='Central'?'IGST':($row['gst_type']==='Regular'?'CGST+SGST':'RCM') ?> <?= $row['gst_rate']>0?$row['gst_rate'].'%':'' ?></small><?php endif; ?><?php if(($row['rate_card_rate']??0)>0): ?><br><small class="text-primary"><i class="bi bi-tag me-1"></i>Rate: ₹<?= number_format($row['rate_card_rate'],2) ?>/<?= htmlspecialchars($row['rate_card_uom']??'') ?></small><?php endif; ?></td>
            <td class="text-end">₹<?= number_format($row['freight_amount'],2) ?><?php if($full_gst>0): ?><br><small class="text-success">+GST ₹<?= number_format($full_gst,2) ?></small><?php endif; ?><?php if($full_tds>0): ?><br><small class="text-danger">-TDS ₹<?= number_format($full_tds,2) ?></small><?php endif; ?></td>
            <td class="text-end text-success">₹<?= number_format($row['paid_base'],2) ?><?php if($row['paid_gst_total']-$row['gst_on_hold']>0): ?><br><small>+GST ₹<?= number_format($row['paid_gst_total']-$row['gst_on_hold'],2) ?></small><?php endif; ?></td>
            <td class="text-end"><?php if($net_gst_hold>0): ?><span class="badge bg-warning text-dark">₹<?= number_format($net_gst_hold,2) ?></span><?php else: ?>—<?php endif; ?></td>
            <td class="text-end fw-bold text-danger">₹<?= number_format($bal_total,2) ?><?php if($net_gst_hold>0&&$bal_freight<=0): ?><br><small class="badge bg-warning text-dark">GST only</small><?php endif; ?></td>
            <td><span class="badge bg-<?= $sb ?>"><?= htmlspecialchars($row['despatch_status']) ?></span></td>
            <td>
                <a href="?pay_despatch=<?= $row['id'] ?>" class="btn btn-sm btn-danger mb-1" onclick="scrollToForm()"><i class="bi bi-cash me-1"></i>Pay</a>
                <?php if ($net_gst_hold>0): ?>
                <a href="?pay_despatch=<?= $row['id'] ?>&release_gst=1" class="btn btn-sm btn-warning" onclick="scrollToForm()"><i class="bi bi-unlock me-1"></i>Release GST</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold"><tr>
            <td colspan="6" class="text-end">Totals:</td>
            <td class="text-end">₹<?= number_format(array_sum(array_column($due_rows,'freight_amount')),2) ?></td>
            <td class="text-end text-success">₹<?= number_format(array_sum(array_column($due_rows,'paid_base')),2) ?></td>
            <td class="text-end text-warning">₹<?= number_format(array_sum(array_column($due_rows,'gst_on_hold')),2) ?></td>
            <td class="text-end text-danger">₹<?= number_format($total_outstanding,2) ?></td>
            <td colspan="2"></td>
        </tr></tfoot>
    </table></div></div>
    <?php endif; ?>
</div>

<!-- ══ PAYMENT HISTORY — GROUPED BY TRANSPORTER ══ -->
<?php
/* Fetch all payments ordered by transporter then date */
$hist_all = $db->query("
    SELECT tp.*, t.transporter_name, t.gst_type AS tr_gst_type, t.gst_rate AS tr_gst_rate,
           d.challan_no, d.consignee_name
    FROM transporter_payments tp
    LEFT JOIN transporters t ON tp.transporter_id = t.id
    LEFT JOIN despatch_orders d ON tp.despatch_id = d.id
    ORDER BY t.transporter_name ASC, tp.payment_date DESC, tp.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* Group by transporter */
$grouped = [];   // [ tid => ['name'=>..., 'rows'=>[], 'totals'=>[]] ]
foreach ($hist_all as $v) {
    $tid  = (int)($v['transporter_id'] ?: 0);
    $name = $v['transporter_name'] ?: '(Unknown)';
    if (!isset($grouped[$tid])) {
        $grouped[$tid] = [
            'name'    => $name,
            'gst_type'=> $v['tr_gst_type'] ?? '',
            'gst_rate'=> $v['tr_gst_rate'] ?? 0,
            'rows'    => [],
            'tot_base'=> 0, 'tot_gst'=> 0, 'tot_tds'=> 0, 'tot_net'=> 0,
            'tot_paid'=> 0, 'tot_pend'=> 0, 'tot_held'=> 0, 'cnt'=> 0,
        ];
    }
    $grouped[$tid]['rows'][] = $v;
    if ($v['status'] !== 'Cancelled') {
        $net = (float)($v['net_payable'] ?: $v['amount']);
        $grouped[$tid]['tot_base'] += (float)($v['base_amount'] ?: $v['amount']);
        $grouped[$tid]['tot_gst']  += (float)$v['gst_amount'];
        $grouped[$tid]['tot_tds']  += (float)$v['tds_amount'];
        $grouped[$tid]['tot_net']  += $net;
        $grouped[$tid]['cnt']++;
        if ($v['status'] === 'Paid')    $grouped[$tid]['tot_paid'] += $net;
        if ($v['status'] === 'Pending') $grouped[$tid]['tot_pend'] += $net;
        if ($v['gst_held'] === 'Yes')   $grouped[$tid]['tot_held'] += (float)$v['gst_amount'];
    }
}

/* Expand/collapse state: default first group open */
$grp_idx = 0;
?>

<div class="mb-2 d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Payment History — By Transporter</h6>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllGroups(true)">
            <i class="bi bi-chevron-expand me-1"></i>Expand All
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAllGroups(false)">
            <i class="bi bi-chevron-contract me-1"></i>Collapse All
        </button>
    </div>
</div>

<?php if (empty($grouped)): ?>
<div class="card"><div class="card-body text-center text-muted py-4">
    <i class="bi bi-inbox fs-2 d-block mb-2"></i>No payment records found.
</div></div>
<?php else: ?>

<?php foreach ($grouped as $tid => $grp):
    $gst_lbl = $grp['gst_type']==='Central' ? 'IGST' : ($grp['gst_type']==='Regular' ? 'CGST+SGST' : ($grp['gst_type']==='RCM' ? 'RCM' : ''));
    $acc_id  = 'trGrp_'.$tid;
    $is_open = ($grp_idx === 0);
    $grp_idx++;
?>
<div class="card mb-2 shadow-sm">

    <!-- ── Group Header ── -->
    <div class="card-header p-0">
    <button class="btn w-100 text-start d-flex align-items-center justify-content-between gap-2 px-3 py-2"
            style="background:linear-gradient(90deg,#1a3c5e,#2563a8);border:none;border-radius:inherit"
            onclick="toggleGroup('<?= $acc_id ?>')">
        <span class="d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-truck text-white fs-5"></i>
            <strong class="text-white fs-6"><?= htmlspecialchars($grp['name']) ?></strong>
            <?php if($gst_lbl): ?>
            <span class="badge bg-light text-dark"><?= $gst_lbl ?><?= $grp['gst_rate']>0?' '.$grp['gst_rate'].'%':'' ?></span>
            <?php endif; ?>
            <span class="badge bg-white text-primary"><?= $grp['cnt'] ?> transaction<?= $grp['cnt']!=1?'s':'' ?></span>
        </span>
        <!-- Mini summary pills -->
        <span class="d-flex gap-2 align-items-center flex-wrap">
            <span class="badge bg-success">Paid ₹<?= number_format($grp['tot_paid'],2) ?></span>
            <?php if($grp['tot_pend']>0): ?>
            <span class="badge bg-warning text-dark">Pending ₹<?= number_format($grp['tot_pend'],2) ?></span>
            <?php endif; ?>
            <?php if($grp['tot_held']>0): ?>
            <span class="badge bg-danger">GST Hold ₹<?= number_format($grp['tot_held'],2) ?></span>
            <?php endif; ?>
            <span class="badge bg-primary">Net ₹<?= number_format($grp['tot_net'],2) ?></span>
            <i class="bi bi-chevron-down text-white" id="<?= $acc_id ?>_icon"></i>
        </span>
    </button>
    </div>

    <!-- ── Group Body ── -->
    <div id="<?= $acc_id ?>" style="display:<?= $is_open?'block':'none' ?>">
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light">
        <tr>
            <th>#</th>
            <th>Payment No</th>
            <th>Date</th>
            <th>Challan</th>
            <th>Type</th>
            <th class="text-end">Freight Base</th>
            <th class="text-end">GST</th>
            <th class="text-end">TDS</th>
            <th class="text-end">Net Paid</th>
            <th>Mode</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php $row_i=1; foreach ($grp['rows'] as $v):
            $b    = ['Paid'=>'success','Pending'=>'warning','Cancelled'=>'danger'][$v['status']]??'secondary';
            $held = ($v['gst_held']??'No')==='Yes';
            $rel  = ($v['is_gst_release']??'No')==='Yes';
            $canc = $v['status']==='Cancelled';
        ?>
        <tr<?= $canc?' class="text-muted opacity-75"':'' ?>>
            <td><?= $row_i++ ?></td>
            <td>
                <strong><?= htmlspecialchars($v['payment_no']) ?></strong>
                <?php if($rel): ?><br><span class="badge bg-success">GST Release</span><?php endif; ?>
            </td>
            <td style="white-space:nowrap"><?= date('d/m/Y',strtotime($v['payment_date'])) ?></td>
            <td>
                <?php if($v['challan_no']): ?>
                <strong class="text-primary"><?= htmlspecialchars($v['challan_no']) ?></strong>
                <?php if($v['consignee_name']): ?><br><small class="text-muted"><?= htmlspecialchars($v['consignee_name']) ?></small><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($v['payment_type']) ?></span></td>
            <td class="text-end">₹<?= number_format($v['base_amount']?:$v['amount'],2) ?></td>
            <td class="text-end">
                <?php if($v['gst_amount']>0): ?>
                <span class="<?= $held?'text-warning':'text-success' ?>">
                    +₹<?= number_format($v['gst_amount'],2) ?>
                    <?php if($v['gst_type']): ?><br><small><?= $v['gst_type']==='Central'?'IGST':($v['gst_type']==='Regular'?'CGST+SGST':'RCM') ?> <?= $v['gst_rate'] ?>%</small><?php endif; ?>
                    <?php if($held): ?><br><span class="badge bg-warning text-dark">On Hold</span><?php elseif($rel): ?><br><span class="badge bg-success">Released</span><?php endif; ?>
                </span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end">
                <?php if($v['tds_amount']>0): ?>
                <span class="text-danger">-₹<?= number_format($v['tds_amount'],2) ?><br><small>TDS <?= $v['tds_rate'] ?>%</small></span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end fw-bold">₹<?= number_format($v['net_payable']?:$v['amount'],2) ?></td>
            <td><?= htmlspecialchars($v['payment_mode']??'') ?></td>
            <td><span class="badge bg-<?= $b ?>"><?= $v['status'] ?></span></td>
            <td style="white-space:nowrap">
                <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                <button onclick="confirmDelete(<?= $v['id'] ?>,'transporter_payments.php')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-primary fw-semibold">
        <tr>
            <td colspan="5" class="text-end">Totals (excl. cancelled):</td>
            <td class="text-end">₹<?= number_format($grp['tot_base'],2) ?></td>
            <td class="text-end text-success">₹<?= number_format($grp['tot_gst'],2) ?><?= $grp['tot_held']>0?' <span class="badge bg-warning text-dark ms-1">Hold ₹'.number_format($grp['tot_held'],2).'</span>':'' ?></td>
            <td class="text-end text-danger">-₹<?= number_format($grp['tot_tds'],2) ?></td>
            <td class="text-end text-primary">₹<?= number_format($grp['tot_net'],2) ?></td>
            <td colspan="3"></td>
        </tr>
        </tfoot>
    </table>
    </div>
    </div><!-- group body -->

</div><!-- card -->
<?php endforeach; ?>
<?php endif; // end grouped ?>
<?php endif; // end list ?>

<script>
function toggleGroup(id) {
    var body = document.getElementById(id);
    var icon = document.getElementById(id+'_icon');
    if (!body) return;
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (icon) {
        icon.className = open ? 'bi bi-chevron-right text-white' : 'bi bi-chevron-down text-white';
    }
}
function toggleAllGroups(expand) {
    document.querySelectorAll('[id^="trGrp_"]').forEach(function(el) {
        if (!el.id.endsWith('_icon')) {
            el.style.display = expand ? 'block' : 'none';
        }
    });
    document.querySelectorAll('[id$="_icon"]').forEach(function(ic) {
        ic.className = expand ? 'bi bi-chevron-down text-white' : 'bi bi-chevron-right text-white';
    });
}
</script>

<!-- ══ JS ══ -->
<script>
/* PHP → JS: all payments keyed by despatch id (ALL statuses for display) */
const despatchPayments = <?php echo json_encode($all_payments_by_despatch, JSON_HEX_APOS); ?>;

/* State */
var D = null;          // current challan data object
var gstRelMode = false;
var maxAllowed = 0;    // max amount user may enter

/* ── Transporter change: filter challan dropdown ── */
function onTransporterChange(sel) {
    var tid = (sel && sel.value) ? sel.value : '';
    filterDespatchDropdown(tid);
    var dSel = document.getElementById('despatchRef');
    if (dSel) { dSel.value = ''; onDespatchChange(dSel); }
}

function filterDespatchDropdown(tid) {
    var dSel = document.getElementById('despatchRef');
    var note = document.getElementById('despatchFilterNote');
    if (!dSel) return;
    dSel.querySelectorAll('option').forEach(function(o) {
        if (!o.value) return;
        var match = !tid || o.dataset.transporter == tid;
        o.style.display = match ? '' : 'none';
        o.disabled = !match;
    });
    if (note) note.style.display = tid ? 'inline' : 'none';
}

/* ── Despatch / Challan change ── */
function onDespatchChange(sel) {
    var opt = sel ? sel.selectedOptions[0] : null;
    var show = opt && opt.value;

    el('taxAmountSection').style.display   = show ? 'block' : 'none';
    el('paymentAmountSection').style.display = show ? 'block' : 'none';
    el('priorPaymentsWrap').style.display  = show ? 'block' : 'none';

    if (!show) {
        D = null; gstRelMode = false; maxAllowed = 0;
        updateHidden();
        return;
    }

    /* Raw data from option data-attributes */
    var freight    = pf(opt.dataset.freight);
    var paidBase   = pf(opt.dataset.paidBase);   /* sum of non-cancelled base_amount payments */
    var gstOnHold  = pf(opt.dataset.gstOnHold);  /* GST still withheld */
    var gstType    = opt.dataset.gstType  || '';
    var gstRate    = pf(opt.dataset.gstRate);
    var tdsOk      = opt.dataset.tdsApplicable === 'Yes';
    var tdsRate    = pf(opt.dataset.tdsRate);
    var ratePerKg  = pf(opt.dataset.ratePerKg);
    var totalWeight= pf(opt.dataset.totalWeight);
    var isRCM      = gstType === 'RCM';

    /* Show Freight Rate & Weight */
    setText('roRatePerKg',   ratePerKg   > 0 ? fmt(ratePerKg)   : '—');
    setText('roTotalWeight', totalWeight > 0 ? totalWeight.toFixed(3) : '—');

    /* ── TOTAL figures for the full challan ── */
    var fullGst   = (!isRCM && gstRate > 0) ? r2(freight * gstRate / 100) : 0;
    var fullTds   = tdsOk ? r2(freight * tdsRate / 100) : 0;
    var totalDue  = r2(freight + fullGst - fullTds);   /* grand total owed by company */

    /* ── What has already been paid (net, non-cancelled) ── */
    var pmts = despatchPayments[parseInt(opt.value)] || [];
    var alreadyPaidNet = 0;
    pmts.forEach(function(p) {
        if (p.status !== 'Cancelled') alreadyPaidNet += pf(p.net_payable);
    });
    alreadyPaidNet = r2(alreadyPaidNet);

    /* remaining freight base still owed */
    var remBase   = Math.max(0, r2(freight - paidBase));
    /* remaining net balance = totalDue - alreadyPaidNet */
    var balance   = Math.max(0, r2(totalDue - alreadyPaidNet));

    D = { freight, paidBase, gstOnHold, gstType, gstRate, tdsOk, tdsRate,
          isRCM, fullGst, fullTds, totalDue, alreadyPaidNet, remBase, balance,
          ratePerKg, totalWeight };

    /* ── Populate read-only Tax & Amount section ── */
    setText('roFreight',   '₹' + fmt(freight));

    /* GST label + amount */
    var gstLbl = isRCM ? '+ GST (RCM — Nil)' :
                 gstType === 'Central' ? '+ IGST (' + gstRate + '%)' :
                 '+ GST CGST+SGST (' + gstRate + '%)';
    setText('roGstLabel', gstLbl);
    setText('roGstAmt',   isRCM ? '0.00' : fmt(fullGst));
    setText('roGstDesc',  (!isRCM && gstRate > 0) ? gstRate + '% on ₹' + fmt(freight) : (isRCM ? 'Reverse Charge — Nil' : ''));

    /* TDS */
    el('roTdsWrap').style.display = tdsOk ? '' : 'none';
    setText('roTdsAmt',  tdsOk ? fmt(fullTds) : '0.00');
    setText('roTdsDesc', tdsOk ? tdsRate + '% TDS on ₹' + fmt(freight) : '');

    /* Totals */
    setText('roTotalDue',  '₹' + fmt(totalDue));
    setText('roTotalPaid', '₹' + fmt(alreadyPaidNet));
    setText('roBalance',   balance > 0.005 ? '₹' + fmt(balance) : '₹0.00 (Cleared)');
    el('roBalance').style.color = balance > 0.005 ? '#dc3545' : '#198754';

    /* GST on hold banner */
    el('roGstHoldWrap').style.display   = gstOnHold > 0.005 ? 'block' : 'none';
    el('gstReleaseNotice').style.display = 'none';
    setText('roGstOnHold', '₹' + fmt(gstOnHold));

    /* GST hold toggle — show only if there IS GST (non-RCM) on remaining base */
    var remGst = (!isRCM && gstRate > 0) ? r2(remBase * gstRate / 100) : 0;
    el('gstHoldToggleWrap').style.display = (remGst > 0 && !gstRelMode) ? 'block' : 'none';

    /* Default amount = remaining balance */
    maxAllowed = gstRelMode ? gstOnHold : remBase;
    var amtEl = el('amountThisPayment');
    if (amtEl) {
        amtEl.value = gstRelMode ? fmt(gstOnHold) : (remBase > 0.005 ? fmt(remBase) : '');
    }
    setText('amtHint', gstRelMode
        ? 'Max: ₹' + fmt(gstOnHold) + ' (held GST)'
        : (remBase > 0.005 ? 'Max: ₹' + fmt(remBase) + ' (remaining freight)' : 'Freight fully paid'));

    updateHidden();
    onAmountInput();
    renderPriorPayments(parseInt(opt.value));

    <?php if (!empty($_GET['release_gst'])): ?>
    if (!gstRelMode && gstOnHold > 0.005) activateGstRelease();
    <?php endif; ?>
}

/* ── GST Release mode ── */
function activateGstRelease() {
    if (!D) return;
    gstRelMode = true;
    el('fIsGstRelease').value = 'Yes';
    el('gstReleaseNotice').style.display = 'block';
    el('roGstHoldWrap').style.display    = 'none';
    el('gstHoldToggleWrap').style.display = 'none';
    var ptSel = el('paymentTypeSel');
    if (ptSel) ptSel.value = 'GST Release';
    setText('amtLabel', 'GST Release Amount (₹) *');
    setText('roGstReleaseAmt', '₹' + fmt(D.gstOnHold));
    maxAllowed = D.gstOnHold;
    var amtEl = el('amountThisPayment');
    if (amtEl) amtEl.value = fmt(D.gstOnHold);
    setText('amtHint', 'Max: ₹' + fmt(D.gstOnHold) + ' (GST currently on hold)');
    onAmountInput();
}

function deactivateGstRelease() {
    gstRelMode = false;
    el('fIsGstRelease').value = 'No';
    el('gstReleaseNotice').style.display = 'none';
    var ptSel = el('paymentTypeSel');
    if (ptSel && ptSel.value === 'GST Release') ptSel.value = 'Full Settlement';
    setText('amtLabel', 'Amount Being Paid (₹) *');
    if (D) {
        el('roGstHoldWrap').style.display = D.gstOnHold > 0.005 ? 'block' : 'none';
        maxAllowed = D.remBase;
        var amtEl = el('amountThisPayment');
        if (amtEl) amtEl.value = D.remBase > 0.005 ? fmt(D.remBase) : '';
        setText('amtHint', D.remBase > 0.005 ? 'Max: ₹' + fmt(D.remBase) + ' (remaining freight)' : 'Freight fully paid');
        var remGst = (!D.isRCM && D.gstRate > 0) ? r2(D.remBase * D.gstRate / 100) : 0;
        el('gstHoldToggleWrap').style.display = remGst > 0 ? 'block' : 'none';
    }
    onAmountInput();
}

/* ── GST Hold toggle ── */
function onGstHoldChange() {
    var held = el('gstHoldToggle') && el('gstHoldToggle').value === 'Yes';
    el('fGstHeld').value = held ? 'Yes' : 'No';
    var note = el('gstHoldNote');
    if (note) note.style.display = held ? 'block' : 'none';
    onAmountInput();
}

/* ── Amount input → compute net this transaction ── */
function onAmountInput() {
    if (!D) return;
    var amtEl   = el('amountThisPayment');
    var netWrap = el('netThisWrap');
    if (!amtEl) return;
    var amt = pf(amtEl.value);

    var netThis, breakdown;
    if (gstRelMode) {
        netThis   = amt;
        breakdown = 'GST release — no freight, no TDS';
        maxAllowed = D.gstOnHold;
    } else {
        var held    = el('gstHoldToggle') && el('gstHoldToggle').value === 'Yes';
        var gstThis = (!D.isRCM && D.gstRate > 0) ? r2(amt * D.gstRate / 100) : 0;
        var tdsThis = D.tdsOk ? r2(amt * D.tdsRate / 100) : 0;
        netThis     = r2(amt + (held ? 0 : gstThis) - tdsThis);
        breakdown   = '₹' + fmt(amt)
            + (gstThis > 0 ? (held ? ' + GST ₹' + fmt(gstThis) + ' (held)' : ' + GST ₹' + fmt(gstThis)) : '')
            + (tdsThis > 0 ? ' − TDS ₹' + fmt(tdsThis) : '');
        maxAllowed = D.remBase;
    }

    if (netWrap) netWrap.style.display = amt > 0 ? 'block' : 'none';
    setText('netThisPayment',   fmt(netThis));
    setText('netThisBreakdown', breakdown);

    /* Overpayment guard */
    var warn = el('overPayWarn');
    var msg  = el('overPayMsg');
    if (amt > maxAllowed + 0.005) {
        if (warn) warn.style.display = 'block';
        if (msg)  msg.textContent = 'Amount ₹' + fmt(amt) + ' exceeds maximum allowed ₹' + fmt(maxAllowed) + '. Total payments cannot exceed total due.';
    } else {
        if (warn) warn.style.display = 'none';
    }
}

function updateHidden() {
    if (D) {
        el('fGstType').value = D.gstType || '';
        el('fGstRate').value = D.gstRate || 0;
        el('fTdsRate').value = D.tdsOk ? D.tdsRate : 0;
    }
}

/* ── Render prior payments table (ALL statuses) ── */
function renderPriorPayments(despatchId) {
    var wrap  = el('priorPaymentsWrap');
    var tbody = el('priorPaymentsBody');
    var tfoot = el('priorPaymentsFoot');
    if (!wrap || !tbody) return;
    var pmts = despatchPayments[despatchId] || [];

    if (!pmts.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3"><i class="bi bi-info-circle me-1"></i>No payments recorded yet for this challan.</td></tr>';
        tfoot.innerHTML = '';
        return;
    }

    /* Separate active vs cancelled for totals */
    var totBase=0, totGst=0, totTds=0, totNet=0;
    var rows = pmts.map(function(p, i) {
        var held  = p.gst_held      === 'Yes';
        var rel   = p.is_gst_release === 'Yes';
        var canc  = p.status        === 'Cancelled';
        var gstLbl = p.gst_type==='Central' ? 'IGST' : (p.gst_type==='Regular' ? 'CGST+SGST' : 'RCM');
        if (!canc) {
            totBase += pf(p.base_amount);
            totGst  += pf(p.gst_amount);
            totTds  += pf(p.tds_amount);
            totNet  += pf(p.net_payable);
        }
        var sb = p.status==='Paid' ? 'success' : p.status==='Pending' ? 'warning' : 'danger';
        var gstCell = pf(p.gst_amount) > 0
            ? '<span class="' + (held ? 'text-warning' : 'text-success') + '">'
              + '+₹' + fmt(p.gst_amount)
              + '<br><small>' + gstLbl + ' ' + p.gst_rate + '%</small>'
              + (held ? '<br><span class="badge bg-warning text-dark">On Hold</span>' : '')
              + (rel  ? '<br><span class="badge bg-success text-white">Released</span>' : '')
              + '</span>'
            : '—';
        var tdsCell = pf(p.tds_amount) > 0
            ? '<span class="text-danger">−₹' + fmt(p.tds_amount) + '<br><small>TDS ' + p.tds_rate + '%</small></span>'
            : '—';
        return '<tr' + (canc ? ' class="text-decoration-line-through text-muted opacity-75"' : '') + '>'
            + '<td>' + (i+1) + '</td>'
            + '<td><strong>' + esc(p.payment_no) + '</strong>'
              + (rel  ? '<br><span class="badge bg-success">GST Release</span>' : '')
              + (canc ? '<br><span class="badge bg-danger">Cancelled</span>' : '') + '</td>'
            + '<td>' + fmtDate(p.payment_date) + '</td>'
            + '<td><span class="badge bg-light text-dark border">' + esc(p.payment_type) + '</span></td>'
            + '<td class="text-end">₹' + fmt(p.base_amount) + '</td>'
            + '<td class="text-end">' + gstCell + '</td>'
            + '<td class="text-end">' + tdsCell + '</td>'
            + '<td class="text-end fw-bold' + (canc?' text-muted':'') + '">₹' + fmt(p.net_payable) + '</td>'
            + '<td>' + esc(p.payment_mode||'—') + '</td>'
            + '<td><span class="badge bg-' + sb + '">' + esc(p.status) + '</span></td>'
            + '</tr>';
    }).join('');
    tbody.innerHTML = rows;
    tfoot.innerHTML = '<tr class="table-primary">'
        + '<td colspan="4" class="text-end fw-bold">Totals (excl. cancelled):</td>'
        + '<td class="text-end fw-bold">₹' + fmt(totBase) + '</td>'
        + '<td class="text-end fw-bold text-success">₹' + fmt(totGst) + '</td>'
        + '<td class="text-end fw-bold text-danger">−₹' + fmt(totTds) + '</td>'
        + '<td class="text-end fw-bold text-primary">₹' + fmt(totNet) + '</td>'
        + '<td colspan="2"></td></tr>';
}

/* ── Form submit guard ── */
function validatePayment() {
    var amtEl = el('amountThisPayment');
    if (!amtEl) return true;
    var amt = pf(amtEl.value);
    if (amt <= 0) { alert('Please enter an amount greater than 0.'); amtEl.focus(); return false; }
    if (amt > maxAllowed + 0.005) {
        alert('Amount ₹' + fmt(amt) + ' exceeds the allowed maximum of ₹' + fmt(maxAllowed) + '.\nTotal payments cannot exceed total due.');
        amtEl.focus(); return false;
    }
    return true;
}

/* ── UI helpers ── */
function togglePaymentForm() {
    var body = el('paymentFormBody'), icon = el('toggleIcon');
    if (!body) return;
    var hidden = body.style.display === 'none';
    body.style.display = hidden ? 'block' : 'none';
    if (icon) icon.innerHTML = hidden ? '<i class="bi bi-chevron-up"></i>' : '<i class="bi bi-chevron-down"></i>';
}
function scrollToForm() {
    var card = el('recordPaymentCard');
    if (!card) return;
    var body = el('paymentFormBody');
    if (body) body.style.display = 'block';
    var icon = el('toggleIcon');
    if (icon) icon.innerHTML = '<i class="bi bi-chevron-up"></i>';
    setTimeout(function() { card.scrollIntoView({behavior:'smooth', block:'start'}); }, 50);
}

/* ── Micro utilities ── */
function el(id)    { return document.getElementById(id); }
function setText(id,v) { var e=el(id); if(e) e.textContent=v; }
function pf(v)     { return parseFloat(v) || 0; }
function r2(v)     { return Math.round(v * 100) / 100; }
function fmt(v)    { return pf(v).toFixed(2); }
function fmtDate(d){ if(!d) return '—'; var p=d.split('-'); return p.length===3 ? p[2]+'/'+p[1]+'/'+p[0] : d; }
function esc(s)    { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('DOMContentLoaded', function() {
    var tSel = el('transporterSel');
    var dSel = el('despatchRef');
    if (tSel && tSel.value) filterDespatchDropdown(tSel.value);
    if (dSel && dSel.value) onDespatchChange(dSel);
    <?php if ($pf_despatch_id > 0): ?>scrollToForm();<?php endif; ?>
});
</script>
<?php include '../includes/footer.php'; ?>
