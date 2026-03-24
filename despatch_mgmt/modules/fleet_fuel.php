<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_fuel', 'view');

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

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $db->query("DELETE FROM fleet_fuel_log WHERE id=".(int)$_GET['delete']);
    showAlert('success','Fuel entry deleted.');
    redirect('fleet_fuel.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fuel'])) {
    requirePerm('fleet_fuel', $id > 0 ? 'update' : 'create');
    $fc_id   = (int)$_POST['fuel_company_id'];
    $veh_id  = (int)$_POST['vehicle_id'];
    $drv_id  = (int)($_POST['driver_id'] ?? 0);
    $date    = sanitize($_POST['fuel_date']);
    $litres  = (float)$_POST['litres'];
    $rate    = (float)$_POST['rate_per_litre'];
    $amount  = round($litres * $rate, 2);
    $odo     = (int)($_POST['odometer'] ?? 0);
    $mode    = sanitize($_POST['payment_mode'] ?? 'Credit');
    $bill    = sanitize($_POST['bill_no'] ?? '');
    $notes   = sanitize($_POST['notes'] ?? '');
    $drv_sql = $drv_id ? $drv_id : 'NULL';

    if (!$fc_id || !$veh_id || !$date || $litres <= 0) {
        showAlert('danger','Fuel Company, Vehicle, Date and Litres are required.');
        redirect("fleet_fuel.php?action=".($id>0?"edit&id=$id":'add'));
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_fuel_log SET fuel_company_id=$fc_id, vehicle_id=$veh_id,
            driver_id=$drv_sql, fuel_date='$date', litres=$litres, rate_per_litre=$rate,
            amount=$amount, odometer=$odo, payment_mode='$mode', bill_no='$bill', notes='$notes'
            WHERE id=$id");
        showAlert('success','Fuel entry updated.');
    } else {
        $db->query("INSERT INTO fleet_fuel_log
            (fuel_company_id,vehicle_id,driver_id,fuel_date,litres,rate_per_litre,amount,odometer,payment_mode,bill_no,notes)
            VALUES ($fc_id,$veh_id,$drv_sql,'$date',$litres,$rate,$amount,$odo,'$mode','$bill','$notes')");
        showAlert('success','Fuel entry added.');
    }
    redirect('fleet_fuel.php');
}

$vehicles      = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$drivers       = $db->query("SELECT id,full_name FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$fuel_companies= $db->query("SELECT id,company_name,credit_terms FROM fleet_fuel_companies WHERE status='Active' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-droplet-fill me-2"></i>Fuel Management';</script>
<?php

/* ── LIST ── */
if ($action === 'list'):
$filter_veh = (int)($_GET['vehicle'] ?? 0);
$filter_mo  = sanitize($_GET['month'] ?? date('Y-m'));
$where = "WHERE fl.fuel_date LIKE '".substr($filter_mo,0,7)."%'";
if ($filter_veh) $where .= " AND fl.vehicle_id=$filter_veh";

$entries = $db->query("SELECT fl.*, v.reg_no, v.make, v.model,
    d.full_name AS driver_name, fc.company_name AS fuel_company
    FROM fleet_fuel_log fl
    LEFT JOIN fleet_vehicles v ON fl.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON fl.driver_id=d.id
    LEFT JOIN fleet_fuel_companies fc ON fl.fuel_company_id=fc.id
    $where ORDER BY fl.fuel_date DESC, fl.id DESC")->fetch_all(MYSQLI_ASSOC);

$totals = ['litres'=>0,'amount'=>0,'credit'=>0,'cash'=>0];
foreach ($entries as $e) {
    $totals['litres'] += $e['litres'];
    $totals['amount'] += $e['amount'];
    if ($e['payment_mode']==='Credit') $totals['credit'] += $e['amount'];
    else $totals['cash'] += $e['amount'];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fuel Management</h5>
    <?php if (canDo('fleet_fuel','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Fuel Entry</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
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
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div>
</div>

<!-- Summary cards -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Litres</small><div class="fw-bold fs-6"><?= number_format($totals['litres'],2) ?> L</div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Amount</small><div class="fw-bold fs-6 text-primary">₹<?= number_format($totals['amount'],2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Credit</small><div class="fw-bold fs-6 text-danger">₹<?= number_format($totals['credit'],2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Cash</small><div class="fw-bold fs-6 text-success">₹<?= number_format($totals['cash'],2) ?></div></div></div>
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Date</th><th>Vehicle</th><th>Driver</th><th>Fuel Company</th>
    <th class="text-end">Litres</th><th class="text-end">Rate</th>
    <th class="text-end">Amount</th><th>Odometer</th>
    <th>Mode</th><th>Bill No</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($entries as $e): ?>
<tr>
    <td><?= date('d/m/Y',strtotime($e['fuel_date'])) ?></td>
    <td><span class="badge bg-dark"><?= htmlspecialchars($e['reg_no']) ?></span></td>
    <td><?= htmlspecialchars($e['driver_name']??'—') ?></td>
    <td><?= htmlspecialchars($e['fuel_company']) ?></td>
    <td class="text-end"><?= number_format($e['litres'],2) ?></td>
    <td class="text-end">₹<?= number_format($e['rate_per_litre'],2) ?></td>
    <td class="text-end fw-bold">₹<?= number_format($e['amount'],2) ?></td>
    <td><?= $e['odometer'] > 0 ? number_format($e['odometer']) : '—' ?></td>
    <td><span class="badge bg-<?= $e['payment_mode']==='Credit'?'warning text-dark':'success' ?>"><?= $e['payment_mode'] ?></span></td>
    <td><?= htmlspecialchars($e['bill_no']??'—') ?></td>
    <td>
        <?php if (canDo('fleet_fuel','update')): ?>
        <a href="?action=edit&id=<?= $e['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $e['id'] ?>" onclick="return confirm('Delete this entry?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php
/* ── ADD/EDIT ── */
else:
$e = [];
if ($id > 0) $e = $db->query("SELECT * FROM fleet_fuel_log WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Fuel Entry</h5>
    <a href="fleet_fuel.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_fuel" value="1">
<div class="row g-3">
<div class="col-12"><div class="card"><div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Fuel Company *</label>
        <select name="fuel_company_id" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($fuel_companies as $fc): ?>
            <option value="<?= $fc['id'] ?>" <?= ($e['fuel_company_id']??0)==$fc['id']?'selected':'' ?>>
                <?= htmlspecialchars($fc['company_name']) ?> (<?= $fc['credit_terms'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Vehicle *</label>
        <select name="vehicle_id" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($e['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' '.$v['make']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Driver</label>
        <select name="driver_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($e['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Date *</label>
        <input type="date" name="fuel_date" class="form-control" value="<?= $e['fuel_date']??date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Litres *</label>
        <input type="number" name="litres" class="form-control" step="0.01" value="<?= $e['litres']??'' ?>" required onchange="calcAmt()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Rate/Litre (₹)</label>
        <input type="number" name="rate_per_litre" class="form-control" step="0.01" value="<?= $e['rate_per_litre']??'' ?>" onchange="calcAmt()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Amount (₹)</label>
        <input type="number" name="amount_display" id="amtDisplay" class="form-control bg-light" step="0.01" value="<?= $e['amount']??'' ?>" readonly>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Odometer (km)</label>
        <input type="number" name="odometer" class="form-control" value="<?= $e['odometer']??0 ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <option <?= ($e['payment_mode']??'Credit')==='Credit'?'selected':'' ?>>Credit</option>
            <option <?= ($e['payment_mode']??'')==='Cash'?'selected':'' ?>>Cash</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Bill No</label>
        <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($e['bill_no']??'') ?>">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($e['notes']??'') ?>">
    </div>
</div></div></div></div>
<div class="col-12 text-end">
    <a href="fleet_fuel.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save</button>
</div>
</div>
</form>
<script>
function calcAmt() {
    var l = parseFloat(document.querySelector('[name=litres]').value)||0;
    var r = parseFloat(document.querySelector('[name=rate_per_litre]').value)||0;
    document.getElementById('amtDisplay').value = (l*r).toFixed(2);
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
