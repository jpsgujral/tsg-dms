<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_expenses', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    expense_date    DATE NOT NULL,
    expense_type    VARCHAR(60) NOT NULL,
    vendor_name     VARCHAR(200),
    description     TEXT,
    amount          DECIMAL(12,2) DEFAULT 0,
    payment_mode    VARCHAR(40) DEFAULT 'Cash',
    bill_no         VARCHAR(80),
    odometer        INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

$expense_types = ['Tyre','Service/Oil Change','Repair','Permit Renewal','Insurance Renewal',
    'Fitness Renewal','PUC Renewal','Battery','Brake','Clutch','Electrical','Body Work',
    'Toll/RTO','Driver Allowance','Supervisor Salary','Miscellaneous'];

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $db->query("DELETE FROM fleet_expenses WHERE id=".(int)$_GET['delete']);
    showAlert('success','Expense deleted.');
    redirect('fleet_expenses.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    requirePerm('fleet_expenses', $id > 0 ? 'update' : 'create');
    $veh_id  = (int)$_POST['vehicle_id'];
    $date    = sanitize($_POST['expense_date']);
    $type    = sanitize($_POST['expense_type']);
    $vendor  = sanitize($_POST['vendor_name'] ?? '');
    $desc    = sanitize($_POST['description'] ?? '');
    $amount  = (float)$_POST['amount'];
    $mode    = sanitize($_POST['payment_mode'] ?? 'Cash');
    $bill    = sanitize($_POST['bill_no'] ?? '');
    $odo     = (int)($_POST['odometer'] ?? 0);

    if (!$veh_id || !$date || !$type || $amount <= 0) {
        showAlert('danger','Vehicle, Date, Type and Amount are required.');
        redirect("fleet_expenses.php?action=".($id>0?"edit&id=$id":'add'));
    }
    if ($id > 0) {
        $db->query("UPDATE fleet_expenses SET vehicle_id=$veh_id, expense_date='$date',
            expense_type='$type', vendor_name='$vendor', description='$desc', amount=$amount,
            payment_mode='$mode', bill_no='$bill', odometer=$odo WHERE id=$id");
        showAlert('success','Expense updated.');
    } else {
        $db->query("INSERT INTO fleet_expenses (vehicle_id,expense_date,expense_type,vendor_name,description,amount,payment_mode,bill_no,odometer)
            VALUES ($veh_id,'$date','$type','$vendor','$desc',$amount,'$mode','$bill',$odo)");
        showAlert('success','Expense added.');
    }
    redirect('fleet_expenses.php');
}

$vehicles = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-tools me-2"></i>Vehicle Expenses';</script>
<?php

/* ── LIST ── */
if ($action === 'list'):
$filter_veh  = (int)($_GET['vehicle'] ?? 0);
$filter_mo   = sanitize($_GET['month'] ?? date('Y-m'));
$filter_type = sanitize($_GET['type'] ?? '');
$where = "WHERE e.expense_date LIKE '".substr($filter_mo,0,7)."%'";
if ($filter_veh)  $where .= " AND e.vehicle_id=$filter_veh";
if ($filter_type) $where .= " AND e.expense_type='$filter_type'";

$expenses = $db->query("SELECT e.*, v.reg_no, v.make, v.model
    FROM fleet_expenses e
    LEFT JOIN fleet_vehicles v ON e.vehicle_id=v.id
    $where ORDER BY e.expense_date DESC, e.id DESC")->fetch_all(MYSQLI_ASSOC);

$total = array_sum(array_column($expenses,'amount'));

// Group totals by type
$by_type = [];
foreach ($expenses as $ex) $by_type[$ex['expense_type']] = ($by_type[$ex['expense_type']] ?? 0) + $ex['amount'];
arsort($by_type);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Vehicle Expenses</h5>
    <?php if (canDo('fleet_expenses','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Expense</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter_mo ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Vehicle</label>
        <select name="vehicle" class="form-select form-select-sm">
            <option value="">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filter_veh==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Expense Type</label>
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            <?php foreach ($expense_types as $et): ?>
            <option <?= $filter_type===$et?'selected':'' ?>><?= $et ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Expenses</small><div class="fw-bold text-danger fs-6">₹<?= number_format($total,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Entries</small><div class="fw-bold fs-6"><?= count($expenses) ?></div></div></div>
    <?php if ($by_type): $top = array_key_first($by_type); ?>
    <div class="col-12 col-md-6"><div class="card p-2"><small class="text-muted">Top Expense Type</small><div class="fw-bold"><?= $top ?> — ₹<?= number_format($by_type[$top],2) ?></div></div></div>
    <?php endif; ?>
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Date</th><th>Vehicle</th><th>Type</th><th>Vendor</th>
    <th>Description</th><th class="text-end">Amount</th>
    <th>Mode</th><th>Bill No</th><th>Odometer</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($expenses as $ex): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($ex['expense_date'])) ?></td>
    <td><span class="badge bg-dark"><?= htmlspecialchars($ex['reg_no']) ?></span></td>
    <td><span class="badge bg-secondary"><?= htmlspecialchars($ex['expense_type']) ?></span></td>
    <td><?= htmlspecialchars($ex['vendor_name']??'—') ?></td>
    <td><?= htmlspecialchars($ex['description']??'') ?></td>
    <td class="text-end fw-bold">₹<?= number_format($ex['amount'],2) ?></td>
    <td><?= htmlspecialchars($ex['payment_mode']) ?></td>
    <td><?= htmlspecialchars($ex['bill_no']??'—') ?></td>
    <td><?= $ex['odometer'] > 0 ? number_format($ex['odometer']) : '—' ?></td>
    <td>
        <?php if (canDo('fleet_expenses','update')): ?>
        <a href="?action=edit&id=<?= $ex['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $ex['id'] ?>" onclick="return confirm('Delete?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ── ADD/EDIT ── */
else:
$ex = [];
if ($id > 0) $ex = $db->query("SELECT * FROM fleet_expenses WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Vehicle Expense</h5>
    <a href="fleet_expenses.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_expense" value="1">
<div class="card"><div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Vehicle *</label>
        <select name="vehicle_id" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($ex['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' '.$v['make']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date *</label>
        <input type="date" name="expense_date" class="form-control" value="<?= $ex['expense_date']??date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Expense Type *</label>
        <select name="expense_type" class="form-select" required>
            <option value="">— Select Type —</option>
            <?php foreach ($expense_types as $et): ?>
            <option <?= ($ex['expense_type']??'')===$et?'selected':'' ?>><?= $et ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount (₹) *</label>
        <input type="number" name="amount" class="form-control" step="0.01" value="<?= $ex['amount']??'' ?>" required>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Vendor / Workshop</label>
        <input type="text" name="vendor_name" class="form-control" value="<?= htmlspecialchars($ex['vendor_name']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','Cheque','UPI'] as $m): ?>
            <option <?= ($ex['payment_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Bill No</label>
        <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($ex['bill_no']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Odometer (km)</label>
        <input type="number" name="odometer" class="form-control" value="<?= $ex['odometer']??0 ?>">
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($ex['description']??'') ?></textarea>
    </div>
    <div class="col-12 text-end">
        <a href="fleet_expenses.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Expense</button>
    </div>
</div></div></div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
