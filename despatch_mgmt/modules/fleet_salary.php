<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_salary', 'view');

/* ── Regular salary table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_driver_salary (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT NOT NULL,
    salary_month    VARCHAR(7) NOT NULL,
    basic_salary    DECIMAL(10,2) DEFAULT 0,
    trip_count      INT DEFAULT 0,
    other_deductions DECIMAL(10,2) DEFAULT 0,
    other_allowances DECIMAL(10,2) DEFAULT 0,
    deduction_notes TEXT,
    allowance_notes TEXT,
    net_payable     DECIMAL(10,2) DEFAULT 0,
    paid_amount     DECIMAL(10,2) DEFAULT 0,
    payment_date    DATE DEFAULT NULL,
    payment_mode    VARCHAR(40) DEFAULT 'Cash',
    reference_no    VARCHAR(80),
    status          ENUM('Draft','Paid') DEFAULT 'Draft',
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_driver_month (driver_id, salary_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Remove total_advance column if exists (cleanup) ── */
(function($db){
    $c = $db->query("SHOW COLUMNS FROM fleet_driver_salary LIKE 'total_advance'");
    if ($c && $c->num_rows > 0)
        $db->query("ALTER TABLE fleet_driver_salary DROP COLUMN total_advance");
})($db);

/* ── Retro salary table ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_driver_retro_salary (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT NOT NULL,
    salary_month    VARCHAR(7) NOT NULL COMMENT 'Month this retro relates to (YYYY-MM)',
    held_amount     DECIMAL(10,2) DEFAULT 0 COMMENT 'Amount held back this month',
    deduction_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Deducted from retro (challan/accident)',
    deduction_notes TEXT,
    released_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Amount released/paid out',
    release_date    DATE DEFAULT NULL,
    release_mode    VARCHAR(40) DEFAULT 'Cash',
    release_ref     VARCHAR(80) DEFAULT '',
    balance         DECIMAL(10,2) DEFAULT 0 COMMENT 'held - deducted - released',
    status          ENUM('Holding','Partially Released','Released','Forfeited') DEFAULT 'Holding',
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_retro_driver_month (driver_id, salary_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$tab    = $_GET['tab']    ?? 'regular';   /* regular | retro */
$id     = (int)($_GET['id'] ?? 0);

/* ════════════════════════════════
   REGULAR SALARY — DELETE / SAVE
════════════════════════════════ */
if (isset($_GET['delete']) && isAdmin()) {
    $db->query("DELETE FROM fleet_driver_salary WHERE id=".(int)$_GET['delete']." AND status='Draft'");
    showAlert('success','Salary record deleted.');
    redirect('fleet_salary.php?tab=regular');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_salary'])) {
    requirePerm('fleet_salary', $id > 0 ? 'update' : 'create');
    $drv_id  = (int)$_POST['driver_id'];
    $month   = sanitize($_POST['salary_month']);
    $basic   = (float)$_POST['basic_salary'];
    $ded     = (float)($_POST['other_deductions'] ?? 0);
    $allow   = (float)($_POST['other_allowances'] ?? 0);
    $ded_n   = sanitize($_POST['deduction_notes'] ?? '');
    $allow_n = sanitize($_POST['allowance_notes'] ?? '');
    $net     = $basic + $allow - $ded;
    $paid    = (float)($_POST['paid_amount'] ?? 0);
    $pdate   = sanitize($_POST['payment_date'] ?? '');
    $pmode   = sanitize($_POST['payment_mode'] ?? 'Cash');
    $pref    = sanitize($_POST['reference_no'] ?? '');
    $status  = sanitize($_POST['status'] ?? 'Draft');
    $remarks = sanitize($_POST['remarks'] ?? '');
    $pdate_sql = $pdate ? "'$pdate'" : 'NULL';

    $trip_count = $db->query("SELECT COUNT(*) c FROM fleet_trips
        WHERE driver_id=$drv_id AND DATE_FORMAT(trip_date,'%Y-%m')='$month'
        AND status='Completed'")->fetch_assoc()['c'] ?? 0;

    if ($id > 0) {
        $db->query("UPDATE fleet_driver_salary SET driver_id=$drv_id, salary_month='$month',
            basic_salary=$basic, trip_count=$trip_count,
            other_deductions=$ded, other_allowances=$allow,
            deduction_notes='$ded_n', allowance_notes='$allow_n',
            net_payable=$net, paid_amount=$paid, payment_date=$pdate_sql,
            payment_mode='$pmode', reference_no='$pref', status='$status', remarks='$remarks'
            WHERE id=$id");
        showAlert('success','Salary updated.');
    } else {
        $check = $db->query("SELECT id FROM fleet_driver_salary WHERE driver_id=$drv_id AND salary_month='$month' LIMIT 1")->fetch_assoc();
        if ($check) { showAlert('danger','Salary already exists for this driver and month.'); redirect('fleet_salary.php?action=add&tab=regular'); }
        $db->query("INSERT INTO fleet_driver_salary
            (driver_id,salary_month,basic_salary,trip_count,other_deductions,other_allowances,
             deduction_notes,allowance_notes,net_payable,paid_amount,payment_date,payment_mode,reference_no,status,remarks)
            VALUES ($drv_id,'$month',$basic,$trip_count,$ded,$allow,'$ded_n','$allow_n',
            $net,$paid,$pdate_sql,'$pmode','$pref','$status','$remarks')");
        showAlert('success','Salary record created.');
    }
    redirect('fleet_salary.php?tab=regular');
}

/* ════════════════════════════════
   RETRO SALARY — DELETE / SAVE
════════════════════════════════ */
if (isset($_GET['delete_retro']) && isAdmin()) {
    $db->query("DELETE FROM fleet_driver_retro_salary WHERE id=".(int)$_GET['delete_retro']." AND status='Holding'");
    showAlert('success','Retro record deleted.');
    redirect('fleet_salary.php?tab=retro');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retro'])) {
    requirePerm('fleet_salary', $id > 0 ? 'update' : 'create');
    $rid      = (int)($_POST['retro_id'] ?? 0);
    $drv_id   = (int)$_POST['driver_id'];
    $month    = sanitize($_POST['retro_month']);
    $held     = (float)($_POST['held_amount'] ?? 0);
    $ded      = (float)($_POST['deduction_amount'] ?? 0);
    $ded_n    = sanitize($_POST['deduction_notes'] ?? '');
    $released = (float)($_POST['released_amount'] ?? 0);
    $rdate    = sanitize($_POST['release_date'] ?? '');
    $rmode    = sanitize($_POST['release_mode'] ?? 'Cash');
    $rref     = sanitize($_POST['release_ref'] ?? '');
    $remarks  = sanitize($_POST['remarks'] ?? '');
    $rdate_sql = $rdate ? "'$rdate'" : 'NULL';

    $balance = $held - $ded - $released;
    /* Auto status */
    if ($balance <= 0 && $held > 0)     $status = 'Released';
    elseif ($released > 0)              $status = 'Partially Released';
    elseif ($ded >= $held && $held > 0) $status = 'Forfeited';
    else                                $status = 'Holding';

    if ($rid > 0) {
        $db->query("UPDATE fleet_driver_retro_salary SET
            driver_id=$drv_id, salary_month='$month', held_amount=$held,
            deduction_amount=$ded, deduction_notes='$ded_n',
            released_amount=$released, release_date=$rdate_sql,
            release_mode='$rmode', release_ref='$rref',
            balance=$balance, status='$status', remarks='$remarks'
            WHERE id=$rid");
        showAlert('success','Retro record updated.');
    } else {
        $check = $db->query("SELECT id FROM fleet_driver_retro_salary WHERE driver_id=$drv_id AND salary_month='$month' LIMIT 1")->fetch_assoc();
        if ($check) { showAlert('danger','Retro record already exists for this driver and month.'); redirect('fleet_salary.php?action=add_retro&tab=retro'); }
        $db->query("INSERT INTO fleet_driver_retro_salary
            (driver_id,salary_month,held_amount,deduction_amount,deduction_notes,
             released_amount,release_date,release_mode,release_ref,balance,status,remarks)
            VALUES ($drv_id,'$month',$held,$ded,'$ded_n',$released,$rdate_sql,'$rmode','$rref',$balance,'$status','$remarks')");
        showAlert('success','Retro record created.');
    }
    redirect('fleet_salary.php?tab=retro');
}

$drivers = $db->query("SELECT id,full_name,role,basic_salary FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-wallet2 me-2"></i>Driver Salary';</script>

<?php
$retro_status_colors = [
    'Holding'            => 'warning',
    'Partially Released' => 'info',
    'Released'           => 'success',
    'Forfeited'          => 'danger',
];

/* ══════════════════════════════════
   LIST VIEW
══════════════════════════════════ */
if ($action === 'list'):
$filter_mo  = sanitize($_GET['month'] ?? date('Y-m'));
$filter_drv = (int)($_GET['driver'] ?? 0);

/* Regular records */
$where = "WHERE s.salary_month='$filter_mo'";
if ($filter_drv) $where .= " AND s.driver_id=$filter_drv";
$records = $db->query("SELECT s.*, d.full_name, d.role
    FROM fleet_driver_salary s LEFT JOIN fleet_drivers d ON s.driver_id=d.id
    $where ORDER BY d.full_name")->fetch_all(MYSQLI_ASSOC);
$total_net  = array_sum(array_column($records,'net_payable'));
$total_paid = array_sum(array_column($records,'paid_amount'));

/* Retro records */
$rwhere = "WHERE r.salary_month='$filter_mo'";
if ($filter_drv) $rwhere .= " AND r.driver_id=$filter_drv";
$retro_records = $db->query("SELECT r.*, d.full_name, d.role
    FROM fleet_driver_retro_salary r LEFT JOIN fleet_drivers d ON r.driver_id=d.id
    $rwhere ORDER BY d.full_name")->fetch_all(MYSQLI_ASSOC);
$total_held     = array_sum(array_column($retro_records,'held_amount'));
$total_deducted = array_sum(array_column($retro_records,'deduction_amount'));
$total_released = array_sum(array_column($retro_records,'released_amount'));
$total_balance  = array_sum(array_column($retro_records,'balance'));
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Driver Salary</h5>
    <div class="d-flex gap-2">
        <?php if (canDo('fleet_salary','create')): ?>
        <a href="?action=add&tab=regular" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Salary</a>
        <a href="?action=add_retro&tab=retro" class="btn btn-outline-warning btn-sm"><i class="bi bi-piggy-bank me-1"></i>Add Retro</a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
<input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Month</label>
        <input type="month" name="month" class="form-control form-control-sm" value="<?= $filter_mo ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label form-label-sm">Driver</label>
        <select name="driver" class="form-select form-select-sm">
            <option value="">All Drivers</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $filter_drv==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>
</div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="salaryTabs">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='regular'?'active':'' ?>" href="?tab=regular&month=<?= $filter_mo ?>&driver=<?= $filter_drv ?>">
            <i class="bi bi-wallet2 me-1"></i>Regular Salary
            <span class="badge bg-primary ms-1"><?= count($records) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='retro'?'active':'' ?>" href="?tab=retro&month=<?= $filter_mo ?>&driver=<?= $filter_drv ?>">
            <i class="bi bi-piggy-bank me-1"></i>Retro Salary
            <span class="badge bg-warning text-dark ms-1"><?= count($retro_records) ?></span>
        </a>
    </li>
</ul>

<?php if ($tab === 'regular'): ?>

<!-- Regular Summary -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Records</small><div class="fw-bold"><?= count($records) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Net Payable</small><div class="fw-bold text-primary">₹<?= number_format($total_net,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Paid</small><div class="fw-bold text-success">₹<?= number_format($total_paid,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Pending</small><div class="fw-bold text-danger">₹<?= number_format($total_net-$total_paid,2) ?></div></div></div>
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0" id="tblRegular">
<thead><tr>
    <th>Driver</th><th>Role</th><th>Month</th>
    <th class="text-end">Basic</th><th class="text-end">Deductions</th>
    <th class="text-end">Allowances</th><th class="text-end">Net Payable</th>
    <th class="text-end">Paid</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($records as $r): ?>
<tr>
    <td class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></td>
    <td><span class="badge bg-secondary"><?= htmlspecialchars($r['role']) ?></span></td>
    <td><?= $r['salary_month'] ?></td>
    <td class="text-end">₹<?= number_format($r['basic_salary'],0) ?></td>
    <td class="text-end text-danger">₹<?= number_format($r['other_deductions'],0) ?></td>
    <td class="text-end text-success">₹<?= number_format($r['other_allowances'],0) ?></td>
    <td class="text-end fw-bold">₹<?= number_format($r['net_payable'],0) ?></td>
    <td class="text-end text-success">₹<?= number_format($r['paid_amount'],0) ?></td>
    <td><span class="badge bg-<?= $r['status']==='Paid'?'success':'warning text-dark' ?>"><?= $r['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_salary','update')): ?>
        <a href="?action=edit&id=<?= $r['id'] ?>&tab=regular" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin() && $r['status']==='Draft'): ?>
        <a href="?delete=<?= $r['id'] ?>" onclick="return confirm('Delete salary record?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($records)): ?>
<tr><td colspan="10" class="text-center text-muted py-4">No salary records for this month.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div></div>

<?php else: /* RETRO TAB */ ?>

<!-- Retro Summary -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Held</small><div class="fw-bold text-warning">₹<?= number_format($total_held,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Total Deducted</small><div class="fw-bold text-danger">₹<?= number_format($total_deducted,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Released</small><div class="fw-bold text-success">₹<?= number_format($total_released,2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card text-center p-2"><small class="text-muted">Balance Held</small><div class="fw-bold text-primary">₹<?= number_format($total_balance,2) ?></div></div></div>
</div>

<!-- Retro info box -->
<div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Retro Salary</strong> — Monthly salary amounts held back as security against traffic challans, accidents, or other liabilities. Record each month's hold separately and release when appropriate.
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0" id="tblRetro">
<thead><tr>
    <th>Driver</th><th>Month</th>
    <th class="text-end">Held</th><th class="text-end">Deducted</th>
    <th>Deduction Reason</th>
    <th class="text-end">Released</th><th class="text-end">Balance</th>
    <th>Release Date</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($retro_records as $r): ?>
<tr>
    <td class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= $r['salary_month'] ?></td>
    <td class="text-end text-warning fw-bold">₹<?= number_format($r['held_amount'],0) ?></td>
    <td class="text-end text-danger">₹<?= number_format($r['deduction_amount'],0) ?></td>
    <td><small><?= htmlspecialchars($r['deduction_notes'] ?? '—') ?></small></td>
    <td class="text-end text-success">₹<?= number_format($r['released_amount'],0) ?></td>
    <td class="text-end fw-bold <?= $r['balance'] > 0 ? 'text-primary' : 'text-muted' ?>">₹<?= number_format($r['balance'],0) ?></td>
    <td><?= $r['release_date'] ? date('d/m/Y',strtotime($r['release_date'])) : '—' ?></td>
    <td><span class="badge bg-<?= $retro_status_colors[$r['status']] ?? 'secondary' ?>"><?= $r['status'] ?></span></td>
    <td>
        <?php if (canDo('fleet_salary','update')): ?>
        <a href="?action=edit_retro&id=<?= $r['id'] ?>&tab=retro" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin() && $r['status']==='Holding'): ?>
        <a href="?delete_retro=<?= $r['id'] ?>" onclick="return confirm('Delete retro record?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($retro_records)): ?>
<tr><td colspan="10" class="text-center text-muted py-4">No retro records for this month.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div></div></div>

<!-- Driver-wise retro balance summary (all time) -->
<?php
$retro_summary = $db->query("SELECT d.full_name, d.role,
    COUNT(r.id) months_count,
    SUM(r.held_amount) total_held,
    SUM(r.deduction_amount) total_deducted,
    SUM(r.released_amount) total_released,
    SUM(r.balance) total_balance
    FROM fleet_driver_retro_salary r
    LEFT JOIN fleet_drivers d ON r.driver_id=d.id
    WHERE r.status IN ('Holding','Partially Released')
    GROUP BY r.driver_id ORDER BY total_balance DESC")->fetch_all(MYSQLI_ASSOC);
if (!empty($retro_summary)):
?>
<div class="card mt-3">
<div class="card-header fw-semibold"><i class="bi bi-piggy-bank me-2"></i>All-Time Retro Balance (Active Holdings)</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr>
    <th>Driver</th><th class="text-end">Months</th>
    <th class="text-end">Total Held</th><th class="text-end">Deducted</th>
    <th class="text-end">Released</th><th class="text-end">Balance</th>
</tr></thead>
<tbody>
<?php foreach ($retro_summary as $rs): ?>
<tr>
    <td><?= htmlspecialchars($rs['full_name']) ?> <span class="badge bg-secondary ms-1"><?= $rs['role'] ?></span></td>
    <td class="text-end"><?= $rs['months_count'] ?></td>
    <td class="text-end text-warning">₹<?= number_format($rs['total_held'],0) ?></td>
    <td class="text-end text-danger">₹<?= number_format($rs['total_deducted'],0) ?></td>
    <td class="text-end text-success">₹<?= number_format($rs['total_released'],0) ?></td>
    <td class="text-end fw-bold text-primary">₹<?= number_format($rs['total_balance'],0) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>
<?php endif; ?>

<?php endif; /* end retro tab */ ?>

<?php
/* ══════════════════════════════════
   ADD/EDIT — REGULAR SALARY
══════════════════════════════════ */
elseif ($action === 'add' || $action === 'edit'):
$r = [];
if ($id > 0) $r = $db->query("SELECT * FROM fleet_driver_salary WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Salary Record</h5>
    <a href="fleet_salary.php?tab=regular" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_salary" value="1">
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-wallet2 me-2"></i>Salary Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-4">
        <label class="form-label fw-bold">Driver *</label>
        <select name="driver_id" class="form-select" required onchange="fillBasic(this)">
            <option value="">— Select Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" data-salary="<?= $d['basic_salary'] ?>" <?= ($r['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Month *</label>
        <input type="month" name="salary_month" class="form-control" value="<?= $r['salary_month']??date('Y-m') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Basic Salary (₹)</label>
        <input type="number" name="basic_salary" id="basicSalary" class="form-control" step="0.01"
               value="<?= $r['basic_salary']??'' ?>" oninput="calcNet()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Other Deductions (₹)</label>
        <input type="number" name="other_deductions" class="form-control" step="0.01"
               value="<?= $r['other_deductions']??0 ?>" oninput="calcNet()">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Deduction Notes</label>
        <input type="text" name="deduction_notes" class="form-control" value="<?= htmlspecialchars($r['deduction_notes']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Other Allowances (₹)</label>
        <input type="number" name="other_allowances" class="form-control" step="0.01"
               value="<?= $r['other_allowances']??0 ?>" oninput="calcNet()">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Allowance Notes</label>
        <input type="text" name="allowance_notes" class="form-control" value="<?= htmlspecialchars($r['allowance_notes']??'') ?>">
    </div>
    <!-- Net summary bar -->
    <div class="col-12">
        <div class="p-3 rounded" style="background:#e5f5eb">
            <div class="row text-center g-2">
                <div class="col-6 col-md-4"><small class="text-muted">Basic</small><div class="fw-bold" id="dispBasic">₹0</div></div>
                <div class="col-6 col-md-4"><small class="text-muted">+ Allowances</small><div class="fw-bold text-success" id="dispAllow">₹0</div></div>
                <div class="col-6 col-md-4"><small class="text-muted">− Deductions</small><div class="fw-bold text-danger" id="dispDed">₹0</div></div>
            </div>
            <hr class="my-2">
            <div class="text-center">
                <small class="text-muted">Net Payable</small>
                <div class="fw-bold fs-4 text-primary" id="dispNet">₹0</div>
            </div>
        </div>
    </div>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-cash-coin me-2"></i>Payment Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Paid Amount (₹)</label>
        <input type="number" name="paid_amount" class="form-control" step="0.01" value="<?= $r['paid_amount']??0 ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Date</label>
        <input type="date" name="payment_date" class="form-control" value="<?= $r['payment_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="payment_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','UPI','Cheque'] as $m): ?>
            <option <?= ($r['payment_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Reference No</label>
        <input type="text" name="reference_no" class="form-control" value="<?= htmlspecialchars($r['reference_no']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option <?= ($r['status']??'Draft')==='Draft'?'selected':'' ?>>Draft</option>
            <option <?= ($r['status']??'')==='Paid'?'selected':'' ?>>Paid</option>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($r['remarks']??'') ?>">
    </div>
</div></div></div></div>

<div class="col-12 text-end">
    <a href="fleet_salary.php?tab=regular" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save</button>
</div>
</div>
</form>
<script>
var driverSalaries = {};
<?php foreach ($drivers as $d): ?>
driverSalaries[<?= $d['id'] ?>] = <?= (float)$d['basic_salary'] ?>;
<?php endforeach; ?>
function fillBasic(sel) {
    var sid = parseInt(sel.value);
    if (driverSalaries[sid]) { document.getElementById('basicSalary').value = driverSalaries[sid]; calcNet(); }
}
function calcNet() {
    var basic = parseFloat(document.querySelector('[name=basic_salary]').value)||0;
    var ded   = parseFloat(document.querySelector('[name=other_deductions]').value)||0;
    var allow = parseFloat(document.querySelector('[name=other_allowances]').value)||0;
    var net   = basic + allow - ded;
    document.getElementById('dispBasic').textContent = '₹'+basic.toFixed(0);
    document.getElementById('dispAllow').textContent = '₹'+allow.toFixed(0);
    document.getElementById('dispDed').textContent   = '₹'+ded.toFixed(0);
    document.getElementById('dispNet').textContent   = '₹'+net.toFixed(0);
}
calcNet();
</script>

<?php
/* ══════════════════════════════════
   ADD/EDIT — RETRO SALARY
══════════════════════════════════ */
elseif ($action === 'add_retro' || $action === 'edit_retro'):
$r = [];
if ($id > 0) $r = $db->query("SELECT * FROM fleet_driver_retro_salary WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id>0?'Edit':'Add' ?> Retro Salary Record</h5>
    <a href="fleet_salary.php?tab=retro" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-piggy-bank me-1"></i>
    Record the salary amount held back for this driver for the selected month. Update this record when a deduction (challan/accident) is made or when the amount is released.
</div>

<form method="POST">
<input type="hidden" name="save_retro" value="1">
<input type="hidden" name="retro_id" value="<?= $r['id'] ?? 0 ?>">
<div class="row g-3">

<!-- Hold Details -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-piggy-bank me-2"></i>Retro Hold Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-4">
        <label class="form-label fw-bold">Driver *</label>
        <select name="driver_id" class="form-select" required>
            <option value="">— Select Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($r['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Month *</label>
        <input type="month" name="retro_month" class="form-control" value="<?= $r['salary_month']??date('Y-m') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Amount Held (₹)</label>
        <input type="number" name="held_amount" id="heldAmt" class="form-control" step="0.01"
               value="<?= $r['held_amount']??0 ?>" oninput="calcRetro()">
    </div>
    <div class="col-12">
        <label class="form-label">Remarks</label>
        <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($r['remarks']??'') ?>"
               placeholder="e.g. Retro hold for Feb 2025 salary">
    </div>
</div></div></div></div>

<!-- Deduction Details -->
<div class="col-12"><div class="card border-danger"><div class="card-header text-danger"><i class="bi bi-dash-circle me-2"></i>Deductions (Challans / Accidents)</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Deduction Amount (₹)</label>
        <input type="number" name="deduction_amount" id="dedAmt" class="form-control" step="0.01"
               value="<?= $r['deduction_amount']??0 ?>" oninput="calcRetro()">
    </div>
    <div class="col-12 col-md-9">
        <label class="form-label">Deduction Reason</label>
        <input type="text" name="deduction_notes" class="form-control"
               value="<?= htmlspecialchars($r['deduction_notes']??'') ?>"
               placeholder="e.g. Traffic challan #12345 — ₹5000 on 15 Mar 2025">
    </div>
</div></div></div></div>

<!-- Release Details -->
<div class="col-12"><div class="card border-success"><div class="card-header text-success"><i class="bi bi-check-circle me-2"></i>Release Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Released Amount (₹)</label>
        <input type="number" name="released_amount" id="relAmt" class="form-control" step="0.01"
               value="<?= $r['released_amount']??0 ?>" oninput="calcRetro()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Release Date</label>
        <input type="date" name="release_date" class="form-control" value="<?= $r['release_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Payment Mode</label>
        <select name="release_mode" class="form-select">
            <?php foreach (['Cash','NEFT','RTGS','UPI','Cheque'] as $m): ?>
            <option <?= ($r['release_mode']??'Cash')===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Ref / UTR No</label>
        <input type="text" name="release_ref" class="form-control" value="<?= htmlspecialchars($r['release_ref']??'') ?>">
    </div>
</div></div></div></div>

<!-- Balance summary -->
<div class="col-12">
    <div class="p-3 rounded" style="background:#fff8e1;border:1px solid #ffe082">
        <div class="row text-center g-2">
            <div class="col-6 col-md-3">
                <small class="text-muted">Held</small>
                <div class="fw-bold text-warning fs-5" id="rDispHeld">₹0</div>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted">− Deducted</small>
                <div class="fw-bold text-danger fs-5" id="rDispDed">₹0</div>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted">− Released</small>
                <div class="fw-bold text-success fs-5" id="rDispRel">₹0</div>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted">Balance Remaining</small>
                <div class="fw-bold text-primary fs-4" id="rDispBal">₹0</div>
            </div>
        </div>
    </div>
</div>

<div class="col-12 text-end">
    <a href="fleet_salary.php?tab=retro" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-warning px-4"><i class="bi bi-check2 me-1"></i>Save Retro Record</button>
</div>
</div>
</form>

<script>
function calcRetro() {
    var held = parseFloat(document.getElementById('heldAmt').value)||0;
    var ded  = parseFloat(document.getElementById('dedAmt').value)||0;
    var rel  = parseFloat(document.getElementById('relAmt').value)||0;
    var bal  = held - ded - rel;
    document.getElementById('rDispHeld').textContent = '₹'+held.toFixed(0);
    document.getElementById('rDispDed').textContent  = '₹'+ded.toFixed(0);
    document.getElementById('rDispRel').textContent  = '₹'+rel.toFixed(0);
    document.getElementById('rDispBal').textContent  = '₹'+bal.toFixed(0);
}
calcRetro();
</script>

<?php endif; ?>
<script>
$(document).ready(function () {
    var dtConfig = {
        responsive: true, pageLength: 25,
        language: { search: '<i class="bi bi-search me-1"></i>', searchPlaceholder: 'Search...', emptyTable: 'No records found', paginate: { previous: '<i class="bi bi-chevron-left"></i>', next: '<i class="bi bi-chevron-right"></i>' } },
        dom: "<'row mb-2'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-sm-5'i><'col-sm-7'p>>"
    };
    if ($('#tblRegular').length) $('#tblRegular').DataTable(dtConfig);
    if ($('#tblRetro').length)   $('#tblRetro').DataTable(dtConfig);
});
</script>
<?php include '../includes/footer.php'; ?>
