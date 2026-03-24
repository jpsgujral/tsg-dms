<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_tyres', 'view');

$db->query("CREATE TABLE IF NOT EXISTS fleet_tyres (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tyre_serial     VARCHAR(80) NOT NULL,
    brand           VARCHAR(80),
    size            VARCHAR(40),
    type            ENUM('New','Old','Retreaded') DEFAULT 'New',
    purchase_date   DATE DEFAULT NULL,
    purchase_invoice VARCHAR(80),
    purchase_amount DECIMAL(10,2) DEFAULT 0,
    amount_paid     DECIMAL(10,2) DEFAULT 0,
    payment_status  ENUM('Unpaid','Partial','Paid') DEFAULT 'Unpaid',
    payment_date    DATE DEFAULT NULL,
    payment_mode    VARCHAR(40) DEFAULT '',
    payment_ref     VARCHAR(80) DEFAULT '',
    vendor_name     VARCHAR(200),
    vehicle_id      INT DEFAULT NULL,
    position        VARCHAR(30),
    fit_date        DATE DEFAULT NULL,
    fit_odometer    INT DEFAULT 0,
    remove_date     DATE DEFAULT NULL,
    remove_odometer INT DEFAULT 0,
    status          ENUM('In Use','In Store','Retreading','Scrapped') DEFAULT 'In Store',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Auto-migrate existing table ── */
(function($db) {
    $cols = $db->query("SHOW COLUMNS FROM fleet_tyres");
    $existing = [];
    while ($r = $cols->fetch_assoc()) $existing[] = $r['Field'];
    if (!in_array('amount_paid', $existing))
        $db->query("ALTER TABLE fleet_tyres ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0 AFTER purchase_amount");
    if (!in_array('payment_status', $existing))
        $db->query("ALTER TABLE fleet_tyres ADD COLUMN payment_status ENUM('Unpaid','Partial','Paid') DEFAULT 'Unpaid' AFTER amount_paid");
    if (!in_array('payment_date', $existing))
        $db->query("ALTER TABLE fleet_tyres ADD COLUMN payment_date DATE DEFAULT NULL AFTER payment_status");
    if (!in_array('payment_mode', $existing))
        $db->query("ALTER TABLE fleet_tyres ADD COLUMN payment_mode VARCHAR(40) DEFAULT '' AFTER payment_date");
    if (!in_array('payment_ref', $existing))
        $db->query("ALTER TABLE fleet_tyres ADD COLUMN payment_ref VARCHAR(80) DEFAULT '' AFTER payment_mode");
    $db->query("ALTER TABLE fleet_tyres MODIFY COLUMN type ENUM('New','Old','Retreaded') DEFAULT 'New'");
})($db);

$db->query("CREATE TABLE IF NOT EXISTS fleet_tyre_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tyre_id         INT NOT NULL,
    vehicle_id      INT DEFAULT NULL,
    position        VARCHAR(30),
    fit_date        DATE DEFAULT NULL,
    fit_odometer    INT DEFAULT 0,
    remove_date     DATE DEFAULT NULL,
    remove_odometer INT DEFAULT 0,
    km_run          INT DEFAULT 0,
    action_type     ENUM('Fitted','Removed','Retreaded','Scrapped') DEFAULT 'Fitted',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Fit tyre to vehicle ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fit_tyre'])) {
    requirePerm('fleet_tyres','update');
    $tid    = (int)$_POST['tyre_id'];
    $vid    = (int)$_POST['vehicle_id'];
    $pos    = sanitize($_POST['position']);
    $fdate  = sanitize($_POST['fit_date']);
    $fodo   = (int)$_POST['fit_odometer'];
    $db->query("UPDATE fleet_tyres SET vehicle_id=$vid, position='$pos', fit_date='$fdate', fit_odometer=$fodo, status='In Use' WHERE id=$tid");
    $db->query("INSERT INTO fleet_tyre_history (tyre_id,vehicle_id,position,fit_date,fit_odometer,action_type) VALUES ($tid,$vid,'$pos','$fdate',$fodo,'Fitted')");
    showAlert('success','Tyre fitted to vehicle.');
    redirect('fleet_tyres.php');
}

/* ── Remove tyre ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_tyre'])) {
    requirePerm('fleet_tyres','update');
    $tid    = (int)$_POST['tyre_id'];
    $rdate  = sanitize($_POST['remove_date']);
    $rodo   = (int)$_POST['remove_odometer'];
    $nstatus= sanitize($_POST['new_status'] ?? 'In Store');
    $notes  = sanitize($_POST['notes'] ?? '');
    $tyre   = $db->query("SELECT * FROM fleet_tyres WHERE id=$tid LIMIT 1")->fetch_assoc();
    $km     = $rodo - ($tyre['fit_odometer'] ?? 0);
    $db->query("UPDATE fleet_tyres SET vehicle_id=NULL, position=NULL, remove_date='$rdate', remove_odometer=$rodo, status='$nstatus' WHERE id=$tid");
    $db->query("INSERT INTO fleet_tyre_history (tyre_id,vehicle_id,position,fit_date,fit_odometer,remove_date,remove_odometer,km_run,action_type,notes)
        VALUES ($tid,".($tyre['vehicle_id']??'NULL').",'".$tyre['position']."','".$tyre['fit_date']."',".$tyre['fit_odometer'].",'$rdate',$rodo,$km,'Removed','$notes')");
    showAlert('success','Tyre removed from vehicle.');
    redirect('fleet_tyres.php');
}

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $db->query("DELETE FROM fleet_tyre_history WHERE tyre_id=".(int)$_GET['delete']);
    $db->query("DELETE FROM fleet_tyres WHERE id=".(int)$_GET['delete']);
    showAlert('success','Tyre deleted.');
    redirect('fleet_tyres.php');
}

/* ── Save new/edit tyre ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tyre'])) {
    requirePerm('fleet_tyres', $id > 0 ? 'update' : 'create');
    $serial   = sanitize($_POST['tyre_serial']);
    $brand    = sanitize($_POST['brand'] ?? '');
    $size     = sanitize($_POST['size'] ?? '');
    $type     = sanitize($_POST['type'] ?? 'New');
    $pdate    = sanitize($_POST['purchase_date'] ?? '');
    $pinv     = sanitize($_POST['purchase_invoice'] ?? '');
    $pamt     = (float)($_POST['purchase_amount'] ?? 0);
    $paid     = (float)($_POST['amount_paid'] ?? 0);
    $pstatus  = sanitize($_POST['payment_status'] ?? 'Unpaid');
    $pmode    = sanitize($_POST['payment_mode'] ?? '');
    $pref     = sanitize($_POST['payment_ref'] ?? '');
    $paydate  = sanitize($_POST['payment_date'] ?? '');
    $vendor   = sanitize($_POST['vendor_name'] ?? '');
    $vid_fit  = (int)($_POST['vehicle_id'] ?? 0);
    $status   = sanitize($_POST['status'] ?? 'In Store');
    $notes    = sanitize($_POST['notes'] ?? '');
    $pdate_sql   = $pdate   ? "'$pdate'"   : 'NULL';
    $paydate_sql = $paydate ? "'$paydate'" : 'NULL';
    $vid_sql     = $vid_fit > 0 ? $vid_fit : 'NULL';

    /* Auto-compute payment status if not manually set */
    if ($pamt > 0) {
        if ($paid <= 0)         $pstatus = 'Unpaid';
        elseif ($paid >= $pamt) $pstatus = 'Paid';
        else                    $pstatus = 'Partial';
    }

    if (!$serial) { showAlert('danger','Tyre Serial No is required.'); redirect("fleet_tyres.php?action=$action&id=$id"); }

    if ($id > 0) {
        $db->query("UPDATE fleet_tyres SET tyre_serial='$serial', brand='$brand', size='$size', type='$type',
            purchase_date=$pdate_sql, purchase_invoice='$pinv', purchase_amount=$pamt,
            amount_paid=$paid, payment_status='$pstatus', payment_date=$paydate_sql,
            payment_mode='$pmode', payment_ref='$pref',
            vendor_name='$vendor', vehicle_id=$vid_sql, status='$status', notes='$notes' WHERE id=$id");
        showAlert('success','Tyre updated.');
    } else {
        $db->query("INSERT INTO fleet_tyres
            (tyre_serial,brand,size,type,purchase_date,purchase_invoice,purchase_amount,
             amount_paid,payment_status,payment_date,payment_mode,payment_ref,
             vendor_name,vehicle_id,status,notes)
            VALUES ('$serial','$brand','$size','$type',$pdate_sql,'$pinv',$pamt,
            $paid,'$pstatus',$paydate_sql,'$pmode','$pref',
            '$vendor',$vid_sql,'$status','$notes')");
        showAlert('success','Tyre added.');
    }
    redirect('fleet_tyres.php');
}

$vehicles = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
// Tyre positions for a bulker (22 tyres)
$positions = ['Front-L','Front-R','Axle2-L-Outer','Axle2-L-Inner','Axle2-R-Inner','Axle2-R-Outer',
    'Axle3-L-Outer','Axle3-L-Inner','Axle3-R-Inner','Axle3-R-Outer',
    'Axle4-L-Outer','Axle4-L-Inner','Axle4-R-Inner','Axle4-R-Outer',
    'Axle5-L-Outer','Axle5-L-Inner','Axle5-R-Inner','Axle5-R-Outer',
    'Stepney-1','Stepney-2','Stepney-3','Stepney-4'];

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-circle me-2"></i>Tyre Tracking';</script>
<?php
$status_colors = ['In Use'=>'success','In Store'=>'primary','Retreading'=>'warning','Scrapped'=>'danger'];

/* ── LIST ── */
if ($action === 'list'):
$filter_veh    = (int)($_GET['vehicle'] ?? 0);
$filter_status = sanitize($_GET['status'] ?? '');
$where = "WHERE 1";
if ($filter_veh)    $where .= " AND t.vehicle_id=$filter_veh";
if ($filter_status) $where .= " AND t.status='$filter_status'";

$tyres = $db->query("SELECT t.*, v.reg_no FROM fleet_tyres t LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id $where ORDER BY t.status ASC, t.tyre_serial ASC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Tyre Tracking</h5>
    <div class="d-flex gap-2">
        <?php if (canDo('fleet_tyres','create')): ?>
        <a href="?action=add" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Tyre</a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
        <select name="vehicle" class="form-select form-select-sm">
            <option value="">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filter_veh==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            <?php foreach (array_keys($status_colors) as $s): ?>
            <option <?= $filter_status===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div>
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
<thead><tr>
    <th>Serial No</th><th>Brand/Size</th><th>Type</th>
    <th>Purchase</th><th>Payment</th>
    <th>Vehicle</th><th>Position</th><th>Fit Date</th>
    <th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php
$pay_colors = ['Paid'=>'success','Partial'=>'warning','Unpaid'=>'danger'];
foreach ($tyres as $ty):
    $sc  = $status_colors[$ty['status']] ?? 'secondary';
    $pc  = $pay_colors[$ty['payment_status'] ?? 'Unpaid'] ?? 'secondary';
    $bal = ($ty['purchase_amount'] ?? 0) - ($ty['amount_paid'] ?? 0);
?>
<tr>
    <td><strong><?= htmlspecialchars($ty['tyre_serial']) ?></strong></td>
    <td><?= htmlspecialchars($ty['brand'].' '.$ty['size']) ?></td>
    <td><span class="badge bg-<?= $ty['type']==='New'?'success':($ty['type']==='Old'?'secondary':'info') ?>"><?= $ty['type'] ?></span></td>
    <td>
        <?= $ty['purchase_date'] ? date('d/m/Y',strtotime($ty['purchase_date'])) : '—' ?><br>
        <?= $ty['purchase_amount'] > 0 ? '<small class="text-muted">₹'.number_format($ty['purchase_amount'],0).'</small>' : '' ?>
    </td>
    <td>
        <span class="badge bg-<?= $pc ?>"><?= $ty['payment_status'] ?? 'Unpaid' ?></span>
        <?php if (($ty['payment_status'] ?? '') === 'Partial' && $bal > 0): ?>
        <br><small class="text-danger">Bal: ₹<?= number_format($bal,0) ?></small>
        <?php endif; ?>
    </td>
    <td><?= $ty['reg_no'] ? '<span class="badge bg-dark">'.$ty['reg_no'].'</span>' : '<span class="text-muted">—</span>' ?></td>
    <td><?= htmlspecialchars($ty['position']??'—') ?></td>
    <td><?= $ty['fit_date'] ? date('d/m/Y',strtotime($ty['fit_date'])) : '—' ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $ty['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_tyres','update')): ?>
        <?php if ($ty['status'] === 'In Store' || $ty['status'] === 'Retreading'): ?>
        <button class="btn btn-action btn-outline-success me-1" onclick="showFitModal(<?= $ty['id'] ?>)" title="Fit to Vehicle"><i class="bi bi-plus-circle"></i></button>
        <?php endif; ?>
        <?php if ($ty['status'] === 'In Use'): ?>
        <button class="btn btn-action btn-outline-warning me-1" onclick="showRemoveModal(<?= $ty['id'] ?>, '<?= htmlspecialchars($ty['tyre_serial']) ?>')" title="Remove"><i class="bi bi-dash-circle"></i></button>
        <?php endif; ?>
        <a href="?action=history&id=<?= $ty['id'] ?>" class="btn btn-action btn-outline-info me-1" title="History"><i class="bi bi-clock-history"></i></a>
        <a href="?action=edit&id=<?= $ty['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $ty['id'] ?>" onclick="return confirm('Delete tyre?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<!-- Fit Modal -->
<div class="modal fade" id="fitModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Fit Tyre to Vehicle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<form method="POST">
<input type="hidden" name="fit_tyre" value="1">
<input type="hidden" name="tyre_id" id="fitTyreId">
<div class="modal-body"><div class="row g-3">
    <div class="col-12">
        <label class="form-label fw-bold">Vehicle</label>
        <select name="vehicle_id" class="form-select" required>
            <option value="">— Select Vehicle —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['reg_no'].' '.$v['make']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label fw-bold">Position</label>
        <select name="position" class="form-select" required>
            <?php foreach ($positions as $p): ?>
            <option><?= $p ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6">
        <label class="form-label">Fit Date</label>
        <input type="date" name="fit_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-6">
        <label class="form-label">Odometer (km)</label>
        <input type="number" name="fit_odometer" class="form-control" value="0">
    </div>
</div></div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-success">Fit Tyre</button>
</div>
</form>
</div></div></div>

<!-- Remove Modal -->
<div class="modal fade" id="removeModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Remove Tyre</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<form method="POST">
<input type="hidden" name="remove_tyre" value="1">
<input type="hidden" name="tyre_id" id="removeTyreId">
<div class="modal-body"><div class="row g-3">
    <div class="col-12"><p class="text-muted">Removing tyre: <strong id="removeTyreSerial"></strong></p></div>
    <div class="col-6">
        <label class="form-label">Remove Date</label>
        <input type="date" name="remove_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-6">
        <label class="form-label">Odometer (km)</label>
        <input type="number" name="remove_odometer" class="form-control" value="0">
    </div>
    <div class="col-12">
        <label class="form-label">New Status</label>
        <select name="new_status" class="form-select">
            <option>In Store</option>
            <option>Retreading</option>
            <option>Scrapped</option>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" placeholder="Reason for removal">
    </div>
</div></div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-warning">Remove Tyre</button>
</div>
</form>
</div></div></div>

<script>
function showFitModal(tid) { document.getElementById('fitTyreId').value=tid; new bootstrap.Modal(document.getElementById('fitModal')).show(); }
function showRemoveModal(tid,serial) { document.getElementById('removeTyreId').value=tid; document.getElementById('removeTyreSerial').textContent=serial; new bootstrap.Modal(document.getElementById('removeModal')).show(); }
</script>

<?php
/* ── HISTORY ── */
elseif ($action === 'history' && $id > 0):
$tyre = $db->query("SELECT * FROM fleet_tyres WHERE id=$id LIMIT 1")->fetch_assoc();
$history = $db->query("SELECT h.*, v.reg_no FROM fleet_tyre_history h LEFT JOIN fleet_vehicles v ON h.vehicle_id=v.id WHERE h.tyre_id=$id ORDER BY h.id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Tyre History — <?= htmlspecialchars($tyre['tyre_serial']) ?></h5>
    <a href="fleet_tyres.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<div class="card mb-3"><div class="card-body">
<div class="row g-2">
    <div class="col-6 col-md-2"><small class="text-muted">Brand/Size</small><div><?= htmlspecialchars($tyre['brand'].' '.$tyre['size']) ?></div></div>
    <div class="col-6 col-md-2"><small class="text-muted">Type</small><div><?= $tyre['type'] ?></div></div>
    <div class="col-6 col-md-2"><small class="text-muted">Purchase Date</small><div><?= $tyre['purchase_date'] ? date('d/m/Y',strtotime($tyre['purchase_date'])) : '—' ?></div></div>
    <div class="col-6 col-md-2"><small class="text-muted">Purchase Amount</small><div>₹<?= number_format($tyre['purchase_amount'],0) ?></div></div>
    <div class="col-6 col-md-2">
        <small class="text-muted">Payment</small>
        <?php
        $pc = ['Paid'=>'success','Partial'=>'warning','Unpaid'=>'danger'];
        $ps = $tyre['payment_status'] ?? 'Unpaid';
        $bal = ($tyre['purchase_amount']??0) - ($tyre['amount_paid']??0);
        ?>
        <div>
            <span class="badge bg-<?= $pc[$ps] ?? 'secondary' ?>"><?= $ps ?></span>
            <?php if ($tyre['amount_paid'] > 0): ?>
            <span class="text-muted small"> Paid: ₹<?= number_format($tyre['amount_paid'],0) ?></span>
            <?php endif; ?>
            <?php if ($ps === 'Partial'): ?>
            <div class="text-danger small">Bal: ₹<?= number_format($bal,0) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-2"><small class="text-muted">Total KM Run</small><div><strong><?= number_format(array_sum(array_column($history,'km_run'))) ?> km</strong></div></div>
</div>
</div></div>
<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead><tr><th>Action</th><th>Vehicle</th><th>Position</th><th>Fit Date</th><th>Remove Date</th><th class="text-end">KM Run</th><th>Notes</th></tr></thead>
<tbody>
<?php foreach ($history as $h): ?>
<tr>
    <td><span class="badge bg-<?= $h['action_type']==='Fitted'?'success':($h['action_type']==='Removed'?'warning text-dark':'danger') ?>"><?= $h['action_type'] ?></span></td>
    <td><?= $h['reg_no'] ? '<span class="badge bg-dark">'.$h['reg_no'].'</span>' : '—' ?></td>
    <td><?= htmlspecialchars($h['position']??'—') ?></td>
    <td><?= $h['fit_date'] ? date('d/m/Y',strtotime($h['fit_date'])) : '—' ?></td>
    <td><?= $h['remove_date'] ? date('d/m/Y',strtotime($h['remove_date'])) : '—' ?></td>
    <td class="text-end"><?= $h['km_run'] > 0 ? number_format($h['km_run']) : '—' ?></td>
    <td><?= htmlspecialchars($h['notes']??'') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>

<?php
/* ── ADD/EDIT ── */
else:
$ty = [];
if ($id > 0) $ty = $db->query("SELECT * FROM fleet_tyres WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Tyre</h5>
    <a href="fleet_tyres.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_tyre" value="1">

<!-- Tyre Details -->
<div class="card mb-3">
<div class="card-header"><i class="bi bi-circle me-2"></i>Tyre Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label fw-bold">Tyre Serial No *</label>
        <input type="text" name="tyre_serial" class="form-control" value="<?= htmlspecialchars($ty['tyre_serial']??'') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Brand</label>
        <input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($ty['brand']??'') ?>" placeholder="MRF, CEAT...">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Size</label>
        <input type="text" name="size" class="form-control" value="<?= htmlspecialchars($ty['size']??'') ?>" placeholder="295/80 R22.5">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Type</label>
        <select name="type" class="form-select">
            <?php foreach (['New','Old','Retreaded'] as $t): ?>
            <option <?= ($ty['type']??'New')===$t?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (array_keys($status_colors) as $s): ?>
            <option <?= ($ty['status']??'In Store')===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Installed on Vehicle</label>
        <select name="vehicle_id" class="form-select">
            <option value="">— Not installed —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($ty['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].($v['make'] ? ' — '.$v['make'] : '')) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($ty['notes']??'') ?></textarea>
    </div>
</div></div>
</div>

<!-- Purchase Details -->
<div class="card mb-3">
<div class="card-header"><i class="bi bi-receipt me-2"></i>Purchase Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Purchase Date</label>
        <input type="date" name="purchase_date" class="form-control" value="<?= $ty['purchase_date']??'' ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Purchase Invoice No</label>
        <input type="text" name="purchase_invoice" class="form-control" value="<?= htmlspecialchars($ty['purchase_invoice']??'') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Vendor / Supplier</label>
        <input type="text" name="vendor_name" class="form-control" value="<?= htmlspecialchars($ty['vendor_name']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Purchase Amount (₹)</label>
        <input type="number" name="purchase_amount" id="f_pamt" class="form-control" step="0.01"
               value="<?= $ty['purchase_amount']??0 ?>" oninput="calcPayStatus()">
    </div>
</div></div>
</div>

<!-- Payment Details -->
<div class="card mb-3">
<div class="card-header"><i class="bi bi-cash-coin me-2"></i>Payment Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Amount Paid (₹)</label>
        <input type="number" name="amount_paid" id="f_paid" class="form-control" step="0.01"
               value="<?= $ty['amount_paid']??0 ?>" oninput="calcPayStatus()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Balance</label>
        <input type="text" id="f_balance" class="form-control bg-light" readonly
               value="₹<?= number_format(($ty['purchase_amount']??0)-($ty['amount_paid']??0),2) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Status</label>
        <select name="payment_status" id="f_pstatus" class="form-select">
            <?php foreach (['Unpaid','Partial','Paid'] as $ps): ?>
            <option <?= ($ty['payment_status']??'Unpaid')===$ps?'selected':'' ?>><?= $ps ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Date</label>
        <input type="date" name="payment_date" class="form-control" value="<?= $ty['payment_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['','Cash','Bank Transfer','Cheque','UPI','Other'] as $pm): ?>
            <option <?= ($ty['payment_mode']??'')===$pm?'selected':'' ?>><?= $pm ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Ref / Cheque No</label>
        <input type="text" name="payment_ref" class="form-control" value="<?= htmlspecialchars($ty['payment_ref']??'') ?>" placeholder="UTR / Cheque no">
    </div>
</div></div>
</div>

<div class="text-end">
    <a href="fleet_tyres.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Tyre</button>
</div>
</form>
<script>
function calcPayStatus() {
    var pamt = parseFloat(document.getElementById('f_pamt').value) || 0;
    var paid = parseFloat(document.getElementById('f_paid').value) || 0;
    var bal  = pamt - paid;
    document.getElementById('f_balance').value = '₹' + bal.toFixed(2);
    var ps = 'Unpaid';
    if (pamt > 0) {
        if (paid >= pamt) ps = 'Paid';
        else if (paid > 0) ps = 'Partial';
    }
    document.getElementById('f_pstatus').value = ps;
}
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
