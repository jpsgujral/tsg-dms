<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_expenses', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    trip_id         INT DEFAULT NULL,
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

/* ── Auto-migrate: add trip_id if missing ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'trip_id'    => "INT DEFAULT NULL AFTER vehicle_id",
        'created_by' => "INT DEFAULT 0",
    ] as $col => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_expenses'
            AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) $db->query("ALTER TABLE fleet_expenses ADD COLUMN `$col` $def");
    }
})($db);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

$expense_types = ['Tyre','Service/Oil Change','Repair','Permit Renewal','Insurance Renewal',
    'Fitness Renewal','PUC Renewal','Battery','Brake','Clutch','Electrical','Body Work',
    'Toll/RTO','Driver Allowance','Supervisor Salary','Miscellaneous'];

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $back = (int)($_GET['back'] ?? 0);
    $db->query("DELETE FROM fleet_expenses WHERE id=".(int)$_GET['delete']);
    showAlert('success','Expense deleted.');
    redirect($back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_expenses.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    requirePerm('fleet_expenses', $id > 0 ? 'update' : 'create');
    $veh_id  = (int)$_POST['vehicle_id'];
    $trip_id = (int)($_POST['trip_id'] ?? 0);
    $date    = sanitize($_POST['expense_date']);
    $type    = sanitize($_POST['expense_type']);
    $vendor  = sanitize($_POST['vendor_name'] ?? '');
    $desc    = sanitize($_POST['description'] ?? '');
    $amount  = (float)$_POST['amount'];
    $mode    = sanitize($_POST['payment_mode'] ?? 'Cash');
    $bill    = sanitize($_POST['bill_no'] ?? '');
    $odo     = (int)($_POST['odometer'] ?? 0);
    $back    = sanitize($_POST['back'] ?? '');
    $trip_sql = $trip_id ? $trip_id : 'NULL';

    if (!$veh_id || !$date || !$type || $amount <= 0) {
        showAlert('danger','Vehicle, Date, Type and Amount are required.');
        redirect("fleet_expenses.php?action=".($id>0?"edit&id=$id":'add'));
    }
    if ($id > 0) {
        $db->query("UPDATE fleet_expenses SET vehicle_id=$veh_id, trip_id=$trip_sql,
            expense_date='$date', expense_type='$type', vendor_name='$vendor',
            description='$desc', amount=$amount, payment_mode='$mode',
            bill_no='$bill', odometer=$odo WHERE id=$id");
        showAlert('success','Expense updated.');
    } else {
        $uid_exp = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO fleet_expenses
            (vehicle_id,trip_id,expense_date,expense_type,vendor_name,description,amount,payment_mode,bill_no,odometer,created_by)
            VALUES ($veh_id,$trip_sql,'$date','$type','$vendor','$desc',$amount,'$mode','$bill',$odo,$uid_exp)");
        showAlert('success','Expense added.');
    }
    if ($back) redirect('fleet_trips.php?action=view&id='.$back);
    redirect('fleet_expenses.php');
}

$vehicles = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$trips    = $db->query("SELECT t.id, t.trip_no, t.trip_date, v.reg_no
    FROM fleet_trips t LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    WHERE t.status != 'Cancelled' ORDER BY t.trip_date DESC, t.id DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-tools me-2"></i>Vehicle Expenses';</script>
<?php

/* ════════ LIST ════════ */
if ($action === 'list'):
$filter_veh  = (int)($_GET['vehicle'] ?? 0);
$filter_mo   = sanitize($_GET['month'] ?? date('Y-m'));
$filter_type = sanitize($_GET['type'] ?? '');
$filter_trip = (int)($_GET['trip_id'] ?? 0);

$where = "WHERE e.expense_date LIKE '".substr($filter_mo,0,7)."%'";
if ($filter_veh)  $where .= " AND e.vehicle_id=$filter_veh";
if ($filter_type) $where .= " AND e.expense_type='".($db->real_escape_string($filter_type))."'";
if ($filter_trip) $where .= " AND e.trip_id=$filter_trip";
if (!isAdmin())   $where .= " AND e.created_by=".(int)($_SESSION['user_id']??0);

$expenses = $db->query("SELECT e.*, v.reg_no, v.make, v.model, t.trip_no
    FROM fleet_expenses e
    LEFT JOIN fleet_vehicles v ON e.vehicle_id=v.id
    LEFT JOIN fleet_trips t ON e.trip_id=t.id
    $where ORDER BY e.expense_date DESC, e.id DESC")->fetch_all(MYSQLI_ASSOC);

$total = array_sum(array_column($expenses,'amount'));
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

<div class="card mb-3"><div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter_mo ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Vehicle</label>
        <select name="vehicle" class="form-select form-select-sm">
            <option value="">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filter_veh==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label form-label-sm">Expense Type</label>
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            <?php foreach ($expense_types as $et): ?>
            <option <?= $filter_type===$et?'selected':'' ?>><?= $et ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Trip</label>
        <select name="trip_id" class="form-select form-select-sm">
            <option value="">All Trips</option>
            <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>" <?= $filter_trip==$tr['id']?'selected':'' ?>>
                <?= htmlspecialchars($tr['trip_no']) ?> — <?= htmlspecialchars($tr['reg_no']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div></div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Expenses</small><div class="fw-bold text-danger fs-6">₹<?= number_format($total,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Entries</small><div class="fw-bold fs-6"><?= count($expenses) ?></div></div></div>
    <?php if ($by_type): $top = array_key_first($by_type); ?>
    <div class="col-12 col-md-6"><div class="card p-2"><small class="text-muted">Top Expense Type</small><div class="fw-bold"><?= htmlspecialchars($top) ?> — ₹<?= number_format($by_type[$top],2) ?></div></div></div>
    <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Date</th><th>Trip</th><th>Vehicle</th><th>Type</th><th>Vendor</th>
    <th class="text-end">Amount</th><th>Mode</th><th>Bill No</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($expenses as $ex): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($ex['expense_date'])) ?></td>
    <td><?= $ex['trip_no'] ? '<a href="fleet_trips.php?action=view&id='.$ex['trip_id'].'" class="badge bg-info text-dark text-decoration-none">'.htmlspecialchars($ex['trip_no']).'</a>' : '<span class="text-muted">—</span>' ?></td>
    <td><span class="badge bg-dark"><?= htmlspecialchars($ex['reg_no']) ?></span></td>
    <td><span class="badge bg-secondary"><?= htmlspecialchars($ex['expense_type']) ?></span></td>
    <td><?= htmlspecialchars($ex['vendor_name']??'—') ?></td>
    <td class="text-end fw-bold">₹<?= number_format($ex['amount'],2) ?></td>
    <td><?= htmlspecialchars($ex['payment_mode']) ?></td>
    <td><?= htmlspecialchars($ex['bill_no']??'—') ?></td>
    <td>
        <?php if (canDo('fleet_expenses','update')): ?>
        <a href="?action=edit&id=<?= $ex['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $ex['id'] ?><?= $ex['trip_id']?'&back='.$ex['trip_id']:'' ?>" onclick="return confirm('Delete?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ════════ ADD/EDIT ════════ */
else:
$ex   = [];
$back = (int)($_GET['back'] ?? 0);
if ($id > 0) $ex = $db->query("SELECT * FROM fleet_expenses WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
$prefill_trip = (int)($_GET['trip'] ?? ($ex['trip_id'] ?? 0));
$trip_prefill = [];
if ($prefill_trip) {
    $trip_prefill = $db->query("SELECT t.*, v.reg_no FROM fleet_trips t
        LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
        WHERE t.id=$prefill_trip LIMIT 1")->fetch_assoc() ?? [];
}

// Build trip→vehicle map for JS
$trip_details_res = $db->query("SELECT id,vehicle_id,trip_date FROM fleet_trips WHERE status!='Cancelled' ORDER BY id DESC LIMIT 200");
$trip_details_map = [];
if ($trip_details_res) while ($td = $trip_details_res->fetch_assoc()) {
    $trip_details_map[$td['id']] = $td;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Vehicle Expense
        <?php if ($prefill_trip && isset($trip_prefill['trip_no'])): ?>
        <span class="badge bg-info text-dark ms-2"><?= htmlspecialchars($trip_prefill['trip_no']) ?></span>
        <?php endif; ?>
    </h5>
    <a href="<?= $back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_expenses.php' ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>
<form method="POST">
<input type="hidden" name="save_expense" value="1">
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="back" value="<?= $back ?: $prefill_trip ?>">
<div class="card"><div class="card-body"><div class="row g-3">

    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Trip Reference</label>
        <select name="trip_id" id="tripRefExp" class="form-select" onchange="fillTripExp(this)">
            <option value="">— No Trip (General) —</option>
            <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>"
                <?= ($ex['trip_id']??$prefill_trip)==$tr['id']?'selected':'' ?>>
                <?= htmlspecialchars($tr['trip_no']) ?> — <?= htmlspecialchars($tr['reg_no']) ?> (<?= date('d/m/Y',strtotime($tr['trip_date'])) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Vehicle *</label>
        <select name="vehicle_id" id="vehSelExp" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($ex['vehicle_id']??$trip_prefill['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' '.$v['make']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date *</label>
        <input type="date" name="expense_date" class="form-control"
               value="<?= $ex['expense_date'] ?? $trip_prefill['trip_date'] ?? date('Y-m-d') ?>" required>
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
        <input type="number" name="amount" class="form-control" step="0.01" value="<?= $ex['amount']??'' ?>" required placeholder="0.00">
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
    <div class="col-6 col-md-2">
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
        <a href="<?= $back ? 'fleet_trips.php?action=view&id='.$back : 'fleet_expenses.php' ?>" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Expense</button>
    </div>
</div></div></div>
</form>
<script>
const tripDetailsMapExp = <?= json_encode($trip_details_map, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function fillTripExp(sel) {
    var tid = parseInt(sel.value) || 0;
    if (!tid) return;
    var td = tripDetailsMapExp[tid] || {};
    if (td.vehicle_id) document.getElementById('vehSelExp').value = td.vehicle_id;
    if (td.trip_date)  document.querySelector('[name="expense_date"]').value = td.trip_date;
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
