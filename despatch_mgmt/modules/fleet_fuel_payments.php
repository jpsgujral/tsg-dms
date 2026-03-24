<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_fuel_payments', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id INT NOT NULL,
    payment_date    DATE NOT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    payment_mode    VARCHAR(40) DEFAULT 'NEFT',
    reference_no    VARCHAR(80),
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_fuel_log (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    fuel_company_id   INT NOT NULL,
    vehicle_id        INT NOT NULL,
    driver_id         INT DEFAULT NULL,
    fuel_date         DATE NOT NULL,
    litres            DECIMAL(10,2) DEFAULT 0,
    rate_per_litre    DECIMAL(8,2) DEFAULT 0,
    amount            DECIMAL(12,2) DEFAULT 0,
    odometer          INT DEFAULT 0,
    payment_mode      ENUM('Credit','Cash') DEFAULT 'Credit',
    bill_no           VARCHAR(60),
    notes             TEXT,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete payment ── */
if (isset($_GET['delete']) && isAdmin()) {
    $db->query("DELETE FROM fleet_fuel_payments WHERE id=".(int)$_GET['delete']);
    showAlert('success','Payment deleted.');
    redirect('fleet_fuel_payments.php');
}

/* ── Save payment ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    requirePerm('fleet_fuel_payments','create');
    $fc_id  = (int)$_POST['fuel_company_id'];
    $date   = sanitize($_POST['payment_date']);
    $amount = (float)$_POST['amount'];
    $mode   = sanitize($_POST['payment_mode'] ?? 'NEFT');
    $ref    = sanitize($_POST['reference_no'] ?? '');
    $rem    = sanitize($_POST['remarks'] ?? '');

    if (!$fc_id || !$date || $amount <= 0) {
        showAlert('danger','Fuel Company, Date and Amount are required.');
        redirect('fleet_fuel_payments.php?action=add');
    }
    $db->query("INSERT INTO fleet_fuel_payments (fuel_company_id,payment_date,amount,payment_mode,reference_no,remarks)
        VALUES ($fc_id,'$date',$amount,'$mode','$ref','$rem')");
    showAlert('success','Payment recorded.');
    redirect('fleet_fuel_payments.php');
}

$fuel_companies = $db->query("SELECT id,company_name,credit_terms FROM fleet_fuel_companies WHERE status='Active' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-cash-coin me-2"></i>Fuel Payments';</script>
<?php

/* ── LIST ── */
if ($action === 'list'):
$companies_ledger = $db->query("SELECT fc.id, fc.company_name, fc.credit_terms,
    COALESCE((SELECT SUM(fl.amount) FROM fleet_fuel_log fl WHERE fl.fuel_company_id=fc.id AND fl.payment_mode='Credit'),0) AS total_credit,
    COALESCE((SELECT SUM(fp.amount) FROM fleet_fuel_payments fp WHERE fp.fuel_company_id=fc.id),0) AS total_paid
    FROM fleet_fuel_companies fc
    WHERE fc.status='Active'
    ORDER BY fc.company_name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fuel Payments</h5>
    <?php if (canDo('fleet_fuel_payments','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Record Payment</a>
    <?php endif; ?>
</div>

<!-- Ledger per fuel company -->
<?php foreach ($companies_ledger as $cl):
    $outstanding = $cl['total_credit'] - $cl['total_paid'];
    $payments = $db->query("SELECT * FROM fleet_fuel_payments WHERE fuel_company_id=".$cl['id']." ORDER BY payment_date DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#e5f5eb;border-top:2px solid #1a5632">
    <span>
        <i class="bi bi-fuel-pump me-1 text-success"></i>
        <strong><?= htmlspecialchars($cl['company_name']) ?></strong>
        <span class="badge bg-info ms-2"><?= $cl['credit_terms'] ?></span>
    </span>
    <span class="d-flex gap-3 flex-wrap">
        <span class="text-muted" style="font-size:.85rem">Credit: <strong>₹<?= number_format($cl['total_credit'],2) ?></strong></span>
        <span class="text-success" style="font-size:.85rem">Paid: <strong>₹<?= number_format($cl['total_paid'],2) ?></strong></span>
        <span class="<?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>" style="font-size:.85rem">Outstanding: <strong>₹<?= number_format($outstanding,2) ?></strong></span>
    </span>
</div>
<div class="card-body p-0">
<?php if ($payments): ?>
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead><tr><th>Date</th><th>Amount</th><th>Mode</th><th>Reference</th><th>Remarks</th><th></th></tr></thead>
<tbody>
<?php foreach ($payments as $p): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($p['payment_date'])) ?></td>
    <td class="text-success fw-bold">₹<?= number_format($p['amount'],2) ?></td>
    <td><?= htmlspecialchars($p['payment_mode']) ?></td>
    <td><?= htmlspecialchars($p['reference_no']??'—') ?></td>
    <td><?= htmlspecialchars($p['remarks']??'') ?></td>
    <td>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $p['id'] ?>" onclick="return confirm('Delete payment?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<p class="text-muted p-3 mb-0">No payments recorded yet.</p>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>

<?php
/* ── ADD ── */
else:
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Record Fuel Payment</h5>
    <a href="fleet_fuel_payments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_payment" value="1">
<div class="card"><div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Fuel Company *</label>
        <select name="fuel_company_id" class="form-select" required onchange="loadOutstanding(this.value)">
            <option value="">— Select Company —</option>
            <?php foreach ($fuel_companies as $fc): ?>
            <option value="<?= $fc['id'] ?>"><?= htmlspecialchars($fc['company_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Outstanding Balance</label>
        <input type="text" id="outstanding" class="form-control bg-light" readonly placeholder="Select company first">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Payment Date *</label>
        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount (₹) *</label>
        <input type="number" name="amount" class="form-control" step="0.01" required placeholder="0.00">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['NEFT','RTGS','IMPS','Cheque','Cash','UPI'] as $m): ?>
            <option><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Reference No</label>
        <input type="text" name="reference_no" class="form-control" placeholder="UTR / Cheque No">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control">
    </div>
    <div class="col-12 text-end">
        <a href="fleet_fuel_payments.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Record Payment</button>
    </div>
</div></div></div>
</form>
<script>
var ledger = <?php
$ld = [];
foreach ($fuel_companies as $fc) {
    $cred = $db->query("SELECT COALESCE(SUM(amount),0) s FROM fleet_fuel_log WHERE fuel_company_id=".$fc['id']." AND payment_mode='Credit'")->fetch_assoc()['s'];
    $paid = $db->query("SELECT COALESCE(SUM(amount),0) s FROM fleet_fuel_payments WHERE fuel_company_id=".$fc['id'])->fetch_assoc()['s'];
    $ld[$fc['id']] = round($cred - $paid, 2);
}
echo json_encode($ld);
?>;
function loadOutstanding(id) {
    var el = document.getElementById('outstanding');
    if (id && ledger[id] !== undefined) {
        el.value = '₹' + parseFloat(ledger[id]).toLocaleString('en-IN', {minimumFractionDigits:2});
    } else { el.value = ''; }
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
