<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_vehicles', 'view');

/* ── Auto-create tables ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_vehicles (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    reg_no                  VARCHAR(20) NOT NULL UNIQUE,
    make                    VARCHAR(80),
    model                   VARCHAR(80),
    year                    YEAR,
    capacity_tons           DECIMAL(8,2) DEFAULT 0,
    fuel_type               VARCHAR(20) DEFAULT 'Diesel',
    default_driver_id       INT DEFAULT NULL,
    status                  ENUM('Active','In Repair','Idle','Disposed') DEFAULT 'Active',
    insurance_no            VARCHAR(80),
    insurance_expiry        DATE DEFAULT NULL,
    fitness_expiry          DATE DEFAULT NULL,
    permit_expiry           DATE DEFAULT NULL,
    puc_expiry              DATE DEFAULT NULL,
    national_permit_expiry  DATE DEFAULT NULL,
    chassis_no              VARCHAR(80),
    engine_no               VARCHAR(80),
    notes                   TEXT,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Auto-migrate ── */
(function($db) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    foreach ([
        'state_permit_no'    => "VARCHAR(80) DEFAULT ''",
        'national_permit_no' => "VARCHAR(80) DEFAULT ''",
        'default_driver_id'  => "INT DEFAULT NULL",
    ] as $col => $def) {
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_vehicles'
            AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
        if (!$exists) $db->query("ALTER TABLE fleet_vehicles ADD COLUMN `$col` $def");
    }
})($db);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $refs = [];
    $chk = ['fleet_trips'=>'Trip Orders','fleet_fuel_log'=>'Fuel Entries','fleet_expenses'=>'Vehicle Expenses','fleet_tyres'=>'Tyre Records'];
    foreach ($chk as $table => $label) {
        $res = $db->query("SELECT COUNT(*) c FROM `$table` WHERE vehicle_id=$did");
        if ($res && $res->fetch_assoc()['c'] > 0) $refs[] = $label;
    }
    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — this vehicle has linked records in: ' . implode(', ', $refs) . '. Remove those records first or mark the vehicle as Disposed instead.');
    } else {
        $db->query("DELETE FROM fleet_vehicles WHERE id=$did");
        showAlert('success', 'Vehicle deleted permanently.');
    }
    redirect('fleet_vehicles.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vehicle'])) {
    requirePerm('fleet_vehicles', $id > 0 ? 'update' : 'create');
    $reg     = strtoupper(trim(sanitize($_POST['reg_no'])));
    $make    = sanitize($_POST['make'] ?? '');
    $model   = sanitize($_POST['model'] ?? '');
    $year    = (int)($_POST['year'] ?? 0);
    $cap     = (float)($_POST['capacity_tons'] ?? 0);
    $fuel    = sanitize($_POST['fuel_type'] ?? 'Diesel');
    $def_drv = (int)($_POST['default_driver_id'] ?? 0);
    $status  = sanitize($_POST['status'] ?? 'Active');
    $ins_no  = sanitize($_POST['insurance_no'] ?? '');
    $ins_exp = sanitize($_POST['insurance_expiry'] ?? '');
    $fit_exp = sanitize($_POST['fitness_expiry'] ?? '');
    $sp_no   = sanitize($_POST['state_permit_no'] ?? '');
    $per_exp = sanitize($_POST['permit_expiry'] ?? '');
    $np_no   = sanitize($_POST['national_permit_no'] ?? '');
    $np_exp  = sanitize($_POST['national_permit_expiry'] ?? '');
    $puc_exp = sanitize($_POST['puc_expiry'] ?? '');
    $chassis = sanitize($_POST['chassis_no'] ?? '');
    $engine  = sanitize($_POST['engine_no'] ?? '');
    $notes   = sanitize($_POST['notes'] ?? '');

    $ins_sql = $ins_exp ? "'$ins_exp'" : 'NULL';
    $fit_sql = $fit_exp ? "'$fit_exp'" : 'NULL';
    $per_sql = $per_exp ? "'$per_exp'" : 'NULL';
    $puc_sql = $puc_exp ? "'$puc_exp'" : 'NULL';
    $np_sql  = $np_exp  ? "'$np_exp'"  : 'NULL';
    $drv_sql = $def_drv ? $def_drv     : 'NULL';

    if (!$reg) { showAlert('danger','Registration No is required.'); redirect("fleet_vehicles.php?action=$action&id=$id"); }

    if ($id > 0) {
        $db->query("UPDATE fleet_vehicles SET
            reg_no='$reg', make='$make', model='$model', year=" . ($year ?: 'NULL') . ",
            capacity_tons=$cap, fuel_type='$fuel', default_driver_id=$drv_sql, status='$status',
            insurance_no='$ins_no', insurance_expiry=$ins_sql, fitness_expiry=$fit_sql,
            state_permit_no='$sp_no', permit_expiry=$per_sql,
            national_permit_no='$np_no', national_permit_expiry=$np_sql,
            puc_expiry=$puc_sql, chassis_no='$chassis', engine_no='$engine', notes='$notes'
            WHERE id=$id");
        showAlert('success','Vehicle updated.');
    } else {
        $db->query("INSERT INTO fleet_vehicles
            (reg_no,make,model,year,capacity_tons,fuel_type,default_driver_id,status,insurance_no,
             insurance_expiry,fitness_expiry,state_permit_no,permit_expiry,
             national_permit_no,national_permit_expiry,puc_expiry,chassis_no,engine_no,notes)
            VALUES ('$reg','$make','$model'," . ($year ?: 'NULL') . ",$cap,'$fuel',$drv_sql,'$status','$ins_no',
            $ins_sql,$fit_sql,'$sp_no',$per_sql,'$np_no',$np_sql,$puc_sql,'$chassis','$engine','$notes')");
        showAlert('success','Vehicle added.');
    }
    redirect('fleet_vehicles.php');
}

$drivers = $db->query("SELECT id,full_name,role FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-truck me-2"></i>Vehicle Master';</script>

<?php
$status_colors = ['Active'=>'success','In Repair'=>'warning','Idle'=>'secondary','Disposed'=>'danger'];
$today  = date('Y-m-d');
$warn30 = date('Y-m-d', strtotime('+30 days'));

function expiryBadge($date, $today, $warn30) {
    if (!$date) return '<span class="badge bg-secondary">—</span>';
    $d = date('d/m/Y', strtotime($date));
    if ($date < $today)   return "<span class='badge bg-danger'>$d</span>";
    if ($date <= $warn30) return "<span class='badge bg-warning text-dark'>$d</span>";
    return "<span class='badge bg-success'>$d</span>";
}

/* ── LIST ── */
if ($action === 'list'):
$vehicles = $db->query("SELECT fv.*, fd.full_name AS driver_name
    FROM fleet_vehicles fv
    LEFT JOIN fleet_drivers fd ON fv.default_driver_id=fd.id
    ORDER BY fv.status ASC, fv.reg_no ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Vehicle Master</h5>
    <?php if (canDo('fleet_vehicles','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Vehicle</a>
    <?php endif; ?>
</div>

<?php
$expiring = array_filter($vehicles, fn($v) =>
    ($v['insurance_expiry']       && $v['insurance_expiry']       <= $warn30) ||
    ($v['fitness_expiry']         && $v['fitness_expiry']         <= $warn30) ||
    ($v['permit_expiry']          && $v['permit_expiry']          <= $warn30) ||
    ($v['national_permit_expiry'] && $v['national_permit_expiry'] <= $warn30) ||
    ($v['puc_expiry']             && $v['puc_expiry']             <= $warn30)
);
if ($expiring): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <span><strong><?= count($expiring) ?> vehicle(s)</strong> have documents expiring within 30 days.</span>
</div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Reg No</th><th>Make/Model</th><th>Capacity</th><th>Default Driver</th><th>Status</th>
    <th>Insurance</th><th>Fitness</th><th>State Permit</th><th>Nat. Permit</th><th>PUC</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($vehicles as $v):
    $sc = $status_colors[$v['status']] ?? 'secondary';
?>
<tr>
    <td><strong><?= htmlspecialchars($v['reg_no']) ?></strong></td>
    <td><?= htmlspecialchars($v['make'].' '.$v['model']) ?><br><small class="text-muted"><?= $v['year'] ?: '' ?></small></td>
    <td><?= $v['capacity_tons'] > 0 ? number_format($v['capacity_tons'],1).' MT' : '—' ?></td>
    <td><?= $v['driver_name'] ? '<span class="badge bg-info text-dark">'.htmlspecialchars($v['driver_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $v['status'] ?></span></td>
    <td><?= expiryBadge($v['insurance_expiry'],       $today, $warn30) ?></td>
    <td><?= expiryBadge($v['fitness_expiry'],         $today, $warn30) ?></td>
    <td><?= expiryBadge($v['permit_expiry'],          $today, $warn30) ?></td>
    <td><?= expiryBadge($v['national_permit_expiry'], $today, $warn30) ?></td>
    <td><?= expiryBadge($v['puc_expiry'],             $today, $warn30) ?></td>
    <td>
        <?php if (canDo('fleet_vehicles','update')): ?>
        <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $v['id'] ?>" onclick="return confirm('Permanently delete vehicle <?= htmlspecialchars(addslashes($v['reg_no'])) ?>?\n\nThis will fail if the vehicle has linked trips, fuel entries or expenses.\nTo retire a vehicle without deleting, edit it and set Status to Disposed.')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ── ADD / EDIT ── */
else:
$v = [];
if ($id > 0) $v = $db->query("SELECT * FROM fleet_vehicles WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'Add' ?> Vehicle</h5>
    <a href="fleet_vehicles.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_vehicle" value="1">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-truck me-2"></i>Vehicle Information</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Registration No *</label>
        <input type="text" name="reg_no" class="form-control text-uppercase" value="<?= htmlspecialchars($v['reg_no'] ?? '') ?>" required placeholder="e.g. MH12AB1234">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Make</label>
        <input type="text" name="make" class="form-control" value="<?= htmlspecialchars($v['make'] ?? '') ?>" placeholder="e.g. Tata, Ashok Leyland">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Model</label>
        <input type="text" name="model" class="form-control" value="<?= htmlspecialchars($v['model'] ?? '') ?>" placeholder="e.g. 2518, 4923">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Year</label>
        <input type="number" name="year" class="form-control" value="<?= $v['year'] ?? '' ?>" placeholder="2020" min="1990" max="2099">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Capacity (MT)</label>
        <input type="number" name="capacity_tons" class="form-control" step="0.01" value="<?= $v['capacity_tons'] ?? '' ?>" placeholder="25.00">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Fuel Type</label>
        <select name="fuel_type" class="form-select">
            <?php foreach (['Diesel','CNG','Petrol'] as $ft): ?>
            <option <?= ($v['fuel_type'] ?? 'Diesel') === $ft ? 'selected' : '' ?>><?= $ft ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Default Driver</label>
        <select name="default_driver_id" class="form-select">
            <option value="">— No Default Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($v['default_driver_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text text-muted">Auto-fills Driver when this vehicle is selected in Trip Order</div>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (['Active','In Repair','Idle','Disposed'] as $st): ?>
            <option <?= ($v['status'] ?? 'Active') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Chassis No</label>
        <input type="text" name="chassis_no" class="form-control" value="<?= htmlspecialchars($v['chassis_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Engine No</label>
        <input type="text" name="engine_no" class="form-control" value="<?= htmlspecialchars($v['engine_no'] ?? '') ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-file-earmark-check me-2"></i>Document Expiry Dates</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Insurance No</label>
        <input type="text" name="insurance_no" class="form-control" value="<?= htmlspecialchars($v['insurance_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Insurance Expiry</label>
        <input type="date" name="insurance_expiry" class="form-control" value="<?= $v['insurance_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Fitness Expiry</label>
        <input type="date" name="fitness_expiry" class="form-control" value="<?= $v['fitness_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">PUC Expiry</label>
        <input type="date" name="puc_expiry" class="form-control" value="<?= $v['puc_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">State Permit No</label>
        <input type="text" name="state_permit_no" class="form-control" value="<?= htmlspecialchars($v['state_permit_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">State Permit Expiry</label>
        <input type="date" name="permit_expiry" class="form-control" value="<?= $v['permit_expiry'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">National Permit No</label>
        <input type="text" name="national_permit_no" class="form-control" value="<?= htmlspecialchars($v['national_permit_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">National Permit Expiry</label>
        <input type="date" name="national_permit_expiry" class="form-control" value="<?= $v['national_permit_expiry'] ?? '' ?>">
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($v['notes'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_vehicles.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Vehicle</button>
</div>
</div>
</form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
