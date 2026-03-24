<?php
require_once '../includes/config.php';
require_once '../includes/r2_helper.php';
require_once __DIR__ . '/../includes/auth.php';

/* Only Admin can manage users */
if (!isAdmin()) {
    $_SESSION['alert'] = ['type'=>'danger','message'=>'<i class="bi bi-shield-lock me-2"></i>Only Administrators can manage users.'];
    header('Location: ../index.php'); exit;
}

$db = getDB();

/* ── Module definitions for permissions ── */
$modules = [
    'vendors'              => ['label'=>'Vendor Master',          'icon'=>'bi-building'],
    'items'                => ['label'=>'Item Master',            'icon'=>'bi-box-seam'],
    'transporters'         => ['label'=>'Transporter Master',     'icon'=>'bi-truck-front'],
    'source_of_material'   => ['label'=>'Source of Material',     'icon'=>'bi-geo-fill'],
    'purchase_orders'      => ['label'=>'Purchase Orders',        'icon'=>'bi-file-earmark-text'],
    'despatch'             => ['label'=>'Despatch Orders',        'icon'=>'bi-send-check'],
    'delivery_challans'    => ['label'=>'Delivery Challans',      'icon'=>'bi-receipt'],
    'sales_invoices'       => ['label'=>'Sales Invoices',         'icon'=>'bi-receipt-cutoff'],
    'transporter_payments' => ['label'=>'Transporter Payments',   'icon'=>'bi-cash-coin'],
    'companies'            => ['label'=>'Companies',              'icon'=>'bi-buildings'],
    'company_settings'     => ['label'=>'Company Settings',       'icon'=>'bi-gear'],
    'export_excel'         => ['label'=>'Export to Excel',        'icon'=>'bi-file-earmark-excel'],
    'agent_commissions'    => ['label'=>'Agent Commissions',         'icon'=>'bi-percent'],
    'fleet_dashboard'      => ['label'=>'Fleet — Dashboard',           'icon'=>'bi-speedometer2'],
    'fleet_vehicles'       => ['label'=>'Fleet — Vehicle Master',      'icon'=>'bi-truck'],
    'fleet_drivers'        => ['label'=>'Fleet — Driver Master',        'icon'=>'bi-person-badge'],
    'fleet_customers_master'=> ['label'=>'Fleet — Customer Master',     'icon'=>'bi-person-lines-fill'],
    'fleet_purchase_orders'=> ['label'=>'Fleet — Customer POs',      'icon'=>'bi-file-earmark-text'],
    'fleet_fuel_companies' => ['label'=>'Fleet — Fuel Companies',    'icon'=>'bi-fuel-pump'],
    'fleet_trips'          => ['label'=>'Fleet — Trip Orders',       'icon'=>'bi-signpost-split'],
    'fleet_fuel'           => ['label'=>'Fleet — Fuel Management',   'icon'=>'bi-droplet-fill'],
    'fleet_fuel_payments'  => ['label'=>'Fleet — Fuel Payments',     'icon'=>'bi-cash-coin'],
    'fleet_expenses'       => ['label'=>'Fleet — Vehicle Expenses',  'icon'=>'bi-tools'],
    'fleet_tyres'          => ['label'=>'Fleet — Tyre Tracking',     'icon'=>'bi-circle'],
    'fleet_salary'         => ['label'=>'Fleet — Driver Salary',     'icon'=>'bi-wallet2'],
];
$actions = ['view'=>'View','create'=>'Create','update'=>'Update','delete'=>'Delete'];

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Commission columns (auto-create) ── */
if (!function_exists('safeAddColumn')) {
    function safeAddColumn($db, $table, $column, $definition) {
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}
safeAddColumn($db, 'app_users', 'is_agent',       "TINYINT(1) DEFAULT 0");
safeAddColumn($db, 'app_users', 'slab1_upto',     "DECIMAL(10,2) DEFAULT 100.00 COMMENT 'Profit/MT threshold'");
safeAddColumn($db, 'app_users', 'slab1_pct',      "DECIMAL(5,2) DEFAULT 0.00");
safeAddColumn($db, 'app_users', 'slab2_pct',      "DECIMAL(5,2) DEFAULT 0.00");

/* ── DELETE ── */
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($del_id === (int)$_SESSION['user_id']) {
        showAlert('danger', 'You cannot delete your own account.');
    } else {
        $db->query("DELETE FROM app_users WHERE id=$del_id");
        showAlert('success', 'User deleted.');
    }
    header('Location: users.php'); exit;
}

/* ── TOGGLE STATUS ── */
if (isset($_GET['toggle'])) {
    $tog_id = (int)$_GET['toggle'];
    if ($tog_id !== (int)$_SESSION['user_id']) {
        $db->query("UPDATE app_users SET status=IF(status='Active','Inactive','Active') WHERE id=$tog_id");
    }
    header('Location: users.php'); exit;
}

/* ── SAVE / UPDATE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? 'User';
    $status    = $_POST['status'] ?? 'Active';
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    /* ── Handle signature upload → Cloudflare R2 ── */
    $sig_clause = '';

    // Load current signature from DB
    $cur_sig_row = $id > 0 ? $db->query("SELECT signature_path FROM app_users WHERE id=$id")->fetch_assoc() : [];
    $cur_sig_key = $cur_sig_row['signature_path'] ?? '';

    // Delete if requested
    if (!empty($_POST['delete_signature']) && !empty($cur_sig_key)) {
        r2_delete($cur_sig_key);
        $sig_clause  = ', signature_path=NULL';
        $cur_sig_key = '';
    }

    // Upload new signature
    if (!empty($_FILES['signature']['name'])) {
        $upload_err = $_FILES['signature']['error'];
        if ($upload_err !== UPLOAD_ERR_OK) {
            $err_msgs = [1=>'File too large (server limit)',2=>'File too large',3=>'Partially uploaded',4=>'No file',6=>'No temp dir',7=>'Cannot write to disk',8=>'Extension stopped upload'];
            showAlert('danger', 'Upload error: ' . ($err_msgs[$upload_err] ?? "Code $upload_err"));
            goto skip_save;
        }
        $imgInfo = @getimagesize($_FILES['signature']['tmp_name']);
        if (!$imgInfo) {
            showAlert('danger', 'Signature must be a valid image file (JPG, PNG, GIF or WEBP).');
            goto skip_save;
        }
        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (!in_array($imgInfo[2], $allowedTypes)) {
            showAlert('danger', 'Signature must be JPG, PNG, GIF or WEBP image.');
            goto skip_save;
        }
        if ($_FILES['signature']['size'] > 2 * 1024 * 1024) {
            showAlert('danger', 'Signature image must be under 2MB.');
            goto skip_save;
        }
        $new_sig = r2_handle_upload('signature', 'signatures/sig_' . ($id ?: 'new'), $cur_sig_key);
        if ($new_sig !== '') {
            $sig_path   = $db->real_escape_string($new_sig);
            $sig_clause = ", signature_path='$sig_path'";
        } else {
            showAlert('danger', 'Failed to upload signature to cloud storage.');
            goto skip_save;
        }
    }

    /* Build permissions JSON */
    $perms = [];
    foreach ($modules as $mod => $info) {
        foreach (array_keys($actions) as $act) {
            if (!empty($_POST['perm'][$mod][$act])) {
                $perms[$mod][$act] = 1;
            }
        }
    }

    $errors = [];
    if ($username === '')   $errors[] = 'Username is required.';
    if ($full_name === '')  $errors[] = 'Full Name is required.';
    if ($id === 0 && $password === '') $errors[] = 'Password is required for new users.';
    if ($password !== '') {
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';
        if (strlen($password) < 6)  $errors[] = 'Password must be at least 6 characters.';
    }

    /* Check duplicate username */
    $uEsc = $db->real_escape_string($username);
    $dupCheck = $db->query("SELECT id FROM app_users WHERE username='$uEsc'" . ($id > 0 ? " AND id!=$id" : ""))->num_rows;
    if ($dupCheck > 0) $errors[] = 'Username already exists.';

    if (!empty($errors)) {
        showAlert('danger', implode('<br>', $errors));
    } else {
        $esc   = fn($v) => $db->real_escape_string($v);
        $permsJson = $db->real_escape_string(json_encode($perms));

        if ($id > 0) {
            $pwClause = '';
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pwClause = ", password='" . $db->real_escape_string($hash) . "'";
            }
            $is_agent  = isset($_POST['is_agent']) ? 1 : 0;
            $slab1_upto = (float)($_POST['slab1_upto'] ?? 100);
            $slab1_pct  = (float)($_POST['slab1_pct']  ?? 0);
            $slab2_pct  = (float)($_POST['slab2_pct']  ?? 0);
            $db->query("UPDATE app_users SET
                username='{$esc($username)}', full_name='{$esc($full_name)}',
                email='{$esc($email)}', role='{$esc($role)}', status='{$esc($status)}',
                permissions='$permsJson',
                is_agent=$is_agent, slab1_upto=$slab1_upto,
                slab1_pct=$slab1_pct, slab2_pct=$slab2_pct
                $pwClause $sig_clause WHERE id=$id");
            /* Refresh session if editing self */
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['full_name'] = $full_name;
                $_SESSION['perms']     = $perms;
            }
            showAlert('success', 'User updated successfully.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $is_agent   = isset($_POST['is_agent']) ? 1 : 0;
            $slab1_upto = (float)($_POST['slab1_upto'] ?? 100);
            $slab1_pct  = (float)($_POST['slab1_pct']  ?? 0);
            $slab2_pct  = (float)($_POST['slab2_pct']  ?? 0);
            $db->query("INSERT INTO app_users
                (username,full_name,email,role,password,permissions,status,
                 is_agent,slab1_upto,slab1_pct,slab2_pct)
                VALUES ('{$esc($username)}','{$esc($full_name)}','{$esc($email)}',
                        '{$esc($role)}','" . $db->real_escape_string($hash) . "',
                        '$permsJson','{$esc($status)}',
                        $is_agent,$slab1_upto,$slab1_pct,$slab2_pct)");
            showAlert('success', 'User created successfully.');
        }
        header('Location: users.php'); exit;
    }
    skip_save:;
}

/* ── Fetch for edit ── */
$u = [];
$u_perms = [];
if ($action === 'edit' && $id > 0) {
    $u = $db->query("SELECT * FROM app_users WHERE id=$id")->fetch_assoc();
    $u_perms = json_decode($u['permissions'] ?? '{}', true) ?: [];
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-people me-2"></i>User Management';</script>

<?php if ($action === 'list'): ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">All Users</h5>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add User</a>
</div>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover datatable mb-0">
    <thead><tr>
        <th>#</th><th>Username</th><th>Full Name</th><th>Email</th>
        <th>Role</th><th>Signature</th><th>Last Login</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php
    $list = $db->query("SELECT * FROM app_users ORDER BY role ASC, username ASC");
    $i = 1;
    while ($v = $list->fetch_assoc()):
        $isSelf = ($v['id'] == $_SESSION['user_id']);
    ?>
    <tr>
        <td><?= $i++ ?></td>
        <td>
            <strong><?= htmlspecialchars($v['username']) ?></strong>
            <?php if ($isSelf): ?><span class="badge bg-info ms-1">You</span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($v['full_name']) ?></td>
        <td><?= htmlspecialchars($v['email'] ?: '—') ?></td>
        <td>
            <?php if ($v['role'] === 'Admin'): ?>
                <span class="badge bg-danger"><i class="bi bi-shield-fill me-1"></i>Admin</span>
            <?php else: ?>
                <span class="badge bg-secondary"><i class="bi bi-person me-1"></i>User</span>
            <?php endif; ?>
        </td>
        <td class="text-center">
            <?php if (!empty($v['signature_path'])): ?>
            <img src="<?= htmlspecialchars(r2_url($v['signature_path'])) ?>" alt="Signature"
                 style="max-height:36px;max-width:80px;object-fit:contain;border:1px solid #eee;border-radius:4px;padding:2px;background:#fff">
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
        </td>
        <td><?= $v['last_login'] ? date('d/m/Y H:i', strtotime($v['last_login'])) : '<span class="text-muted">Never</span>' ?></td>
        <td>
            <a href="?toggle=<?= $v['id'] ?>" class="badge text-decoration-none
               bg-<?= $v['status']==='Active'?'success':'secondary' ?>"
               <?= $isSelf ? 'onclick="return false" style="opacity:.6;cursor:not-allowed"' : "onclick=\"return confirm('Toggle status for {$v['username']}?')\"" ?>>
                <?= $v['status'] ?>
            </a>
        </td>
        <td>
            <a href="?action=edit&id=<?= $v['id'] ?>" class="btn btn-action btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>
            <?php if (!$isSelf): ?>
            <button onclick="confirmDelete(<?= $v['id'] ?>,'users.php')" class="btn btn-action btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
            <?php else: ?>
            <button class="btn btn-action btn-outline-secondary" disabled title="Cannot delete own account"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>
</div>
</div>

<?php else: /* ADD / EDIT form */ ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $action==='edit'?'Edit User — '.htmlspecialchars($u['username']??''):'Add New User' ?></h5>
    <a href="users.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="POST" enctype="multipart/form-data">
<div class="row g-3">

<!-- Account Details -->
<div class="col-12"><div class="card">
    <div class="card-header"><i class="bi bi-person-badge me-2"></i>Account Details</div>
    <div class="card-body"><div class="row g-3">

        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label">Username *</label>
            <input type="text" name="username" class="form-control" required
                   value="<?= htmlspecialchars($u['username'] ?? '') ?>"
                   <?= ($action==='edit' && ($u['id']??0)==(int)$_SESSION['user_id'] && ($u['role']??'')=='Admin') ? '' : '' ?>>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <label class="form-label">Full Name *</label>
            <input type="text" name="full_name" class="form-control" required
                   value="<?= htmlspecialchars($u['full_name'] ?? '') ?>">
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($u['email'] ?? '') ?>">
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Role</label>
            <select name="role" id="roleSelect" class="form-select" onchange="onRoleChange()">
                <option value="User"  <?= ($u['role']??'User')==='User' ?'selected':'' ?>>User</option>
                <option value="Admin" <?= ($u['role']??'')==='Admin'?'selected':'' ?>>Admin</option>
            </select>
        </div>
        <div class="col-6 col-sm-4 col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="Active"   <?= ($u['status']??'Active')==='Active'  ?'selected':'' ?>>Active</option>
                <option value="Inactive" <?= ($u['status']??'')==='Inactive'?'selected':'' ?>>Inactive</option>
            </select>
        </div>

        <!-- Password -->
        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label"><?= $action==='edit'?'New Password <small class="text-muted fw-normal">(leave blank to keep)</small>':'Password *' ?></label>
            <div class="input-group">
                <input type="password" name="password" id="pw1" class="form-control"
                       placeholder="<?= $action==='edit'?'Leave blank to keep current':'' ?>"
                       autocomplete="new-password"
                       <?= $action==='add'?'required':'' ?>
                       <?= $action==='edit'?' oninput="this.minLength=this.value.length>0?6:0"':'minlength="6"' ?>>
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw1','eye1')">
                    <i class="bi bi-eye" id="eye1"></i>
                </button>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label">Confirm Password<?= $action==='edit' ? ' <small class="text-muted fw-normal">(if changing)</small>' : ' *' ?></label>
            <div class="input-group">
                <input type="password" name="confirm_password" id="pw2" class="form-control"
                       placeholder="<?= $action==='edit' ? 'Leave blank to keep current' : 'Repeat password' ?>"
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('pw2','eye2')">
                    <i class="bi bi-eye" id="eye2"></i>
                </button>
            </div>
        </div>

    </div></div>
</div></div>

<!-- Signature Upload -->
<div class="col-12"><div class="card">
    <div class="card-header"><i class="bi bi-pen me-2"></i>Authorised Signatory Signature
        <small class="text-muted fw-normal ms-2">— Used on Delivery Challan &amp; Email PDF</small>
    </div>
    <div class="card-body">
        <div class="row align-items-center g-3">
            <!-- Current signature preview -->
            <div class="col-12 col-md-4 text-center">
                <?php
                $cur_sig = $u['signature_path'] ?? '';
                $sig_web = !empty($cur_sig) ? htmlspecialchars(r2_url($cur_sig)) : '';
                ?>
                <div id="sigPreviewWrap" style="min-height:100px;border:2px dashed #ccc;border-radius:8px;
                     background:#f8f9fa;display:flex;align-items:center;justify-content:center;padding:10px">
                    <?php if ($sig_web): ?>
                    <img id="sigPreview" src="<?= $sig_web ?>" alt="Current Signature"
                         style="max-height:90px;max-width:100%;object-fit:contain">
                    <?php else: ?>
                    <span id="sigPreviewPlaceholder" class="text-muted small">
                        <i class="bi bi-image fs-2 d-block mb-1"></i>No signature uploaded
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($sig_web && $action === 'edit'): ?>
                <div class="mt-2">
                    <label class="form-check-label">
                        <input type="checkbox" name="delete_signature" value="1" class="form-check-input me-1">
                        <span class="text-danger small">Remove current signature</span>
                    </label>
                </div>
                <?php endif; ?>
            </div>
            <!-- Upload controls -->
            <div class="col-12 col-md-8">
                <label class="form-label fw-semibold">Upload Signature Image</label>
                <input type="file" name="signature" id="sigFile" class="form-control"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       onchange="previewSig(this)">
                <div class="form-text mt-1">
                    <i class="bi bi-info-circle me-1"></i>
                    Accepted: JPG, PNG, GIF, WEBP &nbsp;|&nbsp; Max size: 2MB<br>
                    <strong>Tip:</strong> Scan your signature on white paper, crop tightly,
                    and save as PNG with transparent background for best results.
                </div>
            </div>
        </div>
    </div>
</div></div>

<!-- Commission Settings -->
<div class="col-12" id="commissionSection">
<div class="card">
    <div class="card-header"><i class="bi bi-percent me-2"></i>Commission Settings
        <small class="text-muted fw-normal ms-2">— Enable if this user earns commission on deliveries</small>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_agent" id="isAgent" value="1"
                        <?= !empty($u['is_agent']) ? 'checked' : '' ?>
                        onchange="toggleCommission(this)">
                    <label class="form-check-label fw-semibold" for="isAgent">
                        This user is an Agent &amp; eligible for commission
                    </label>
                </div>
            </div>
            <div id="commissionSlabs" style="<?= empty($u['is_agent']) ? 'display:none' : '' ?>">
            <div class="row g-3">
                <div class="col-12">
                    <div class="alert alert-info py-2 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>How slabs work:</strong> Profit/MT = (Freight Amount ÷ Received Weight) − Transporter Rate.<br>
                        Slab 1 applies when Profit/MT &lt; Threshold. Slab 2 applies when Profit/MT &ge; Threshold.
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label">Slab 1 Threshold (Profit/MT &lt; ₹)</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="slab1_upto" class="form-control" step="0.01" min="0"
                               value="<?= number_format((float)($u['slab1_upto'] ?? 100), 2, '.', '') ?>"
                               placeholder="e.g. 100">
                        <span class="input-group-text">/MT</span>
                    </div>
                    <div class="form-text">Profit below this → Slab 1 rate applies</div>
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label">Slab 1 Commission Rate (%)</label>
                    <div class="input-group">
                        <input type="number" name="slab1_pct" class="form-control" step="0.01" min="0" max="100"
                               value="<?= number_format((float)($u['slab1_pct'] ?? 0), 2, '.', '') ?>"
                               placeholder="e.g. 10">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">% of Profit when Profit &lt; Threshold</div>
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label">Slab 2 Commission Rate (%)</label>
                    <div class="input-group">
                        <input type="number" name="slab2_pct" class="form-control" step="0.01" min="0" max="100"
                               value="<?= number_format((float)($u['slab2_pct'] ?? 0), 2, '.', '') ?>"
                               placeholder="e.g. 15">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">% of Profit when Profit &ge; Threshold</div>
                </div>
            </div>
            </div>
        </div>
    </div>
</div></div>

<!-- Module Permissions -->
<div class="col-12" id="permissionsSection">
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shield-check me-2"></i>Module Permissions</span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" onclick="setAllPerms(true)">
                <i class="bi bi-check-all me-1"></i>Grant All
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="setAllPerms(false)">
                <i class="bi bi-x-lg me-1"></i>Revoke All
            </button>
        </div>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-bordered mb-0 align-middle" id="permTable">
        <thead class="table-light">
        <tr>
            <th style="width:220px">Module</th>
            <?php foreach ($actions as $act => $lbl): ?>
            <th class="text-center">
                <div><?= $lbl ?></div>
                <button type="button" class="btn btn-sm btn-link p-0 mt-1 text-primary"
                        onclick="toggleCol('<?= $act ?>', true)" title="Grant all <?= $lbl ?>">
                    <i class="bi bi-check-all"></i>
                </button>
                <button type="button" class="btn btn-sm btn-link p-0 text-danger"
                        onclick="toggleCol('<?= $act ?>', false)" title="Revoke all <?= $lbl ?>">
                    <i class="bi bi-x"></i>
                </button>
            </th>
            <?php endforeach; ?>
            <th class="text-center">Row</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($modules as $mod => $minfo): ?>
        <tr>
            <td>
                <i class="bi <?= $minfo['icon'] ?> me-2 text-primary"></i>
                <?= $minfo['label'] ?>
            </td>
            <?php foreach (array_keys($actions) as $act): ?>
            <td class="text-center">
                <div class="form-check d-flex justify-content-center mb-0">
                    <input class="form-check-input perm-cb" type="checkbox"
                           name="perm[<?= $mod ?>][<?= $act ?>]" value="1"
                           data-mod="<?= $mod ?>" data-act="<?= $act ?>"
                           id="p_<?= $mod ?>_<?= $act ?>"
                           <?= !empty($u_perms[$mod][$act]) ? 'checked' : '' ?>>
                </div>
            </td>
            <?php endforeach; ?>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-success p-0 me-1"
                        onclick="toggleRow('<?= $mod ?>',true)"><i class="bi bi-check-all"></i></button>
                <button type="button" class="btn btn-sm btn-link text-danger p-0"
                        onclick="toggleRow('<?= $mod ?>',false)"><i class="bi bi-x"></i></button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <div class="card-footer bg-light">
        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>
        Admin role automatically has full access to all modules regardless of checkboxes.</small>
    </div>
</div>
</div>

<div class="col-12 text-end">
    <a href="users.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-check2 me-1"></i><?= $action==='edit'?'Update User':'Create User' ?>
    </button>
</div>

</div>
</form>

<script>
function togglePw(fid, eid) {
    var f = document.getElementById(fid);
    var e = document.getElementById(eid);
    f.type = f.type === 'password' ? 'text' : 'password';
    e.className = f.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function onRoleChange() {
    var role = document.getElementById('roleSelect').value;
    var sec  = document.getElementById('permissionsSection');
    if (sec) sec.style.opacity = role === 'Admin' ? '0.45' : '1';
}

function setAllPerms(val) {
    document.querySelectorAll('.perm-cb').forEach(function(cb) { cb.checked = val; });
}
function toggleRow(mod, val) {
    document.querySelectorAll('[data-mod="'+mod+'"]').forEach(function(cb) { cb.checked = val; });
}
function toggleCol(act, val) {
    document.querySelectorAll('[data-act="'+act+'"]').forEach(function(cb) { cb.checked = val; });
}

document.addEventListener('DOMContentLoaded', function() { onRoleChange(); });

function toggleCommission(cb) {
    document.getElementById('commissionSlabs').style.display = cb.checked ? '' : 'none';
}

function previewSig(input) {
    var wrap = document.getElementById('sigPreviewWrap');
    var ph   = document.getElementById('sigPreviewPlaceholder');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            wrap.innerHTML = '<img src="' + e.target.result + '" style="max-height:90px;max-width:100%;object-fit:contain">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>
