<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_dashboard', 'view');

/* ── Status log table (only new table we add) ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_vehicle_status_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id  INT NOT NULL,
    old_status  VARCHAR(50) DEFAULT '',
    new_status  VARCHAR(50) NOT NULL,
    changed_by  INT DEFAULT 0,
    notes       TEXT,
    changed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Quick Status Update ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $vid        = (int)$_POST['vehicle_id'];
    $new_status = $db->real_escape_string($_POST['new_status']);
    $notes      = $db->real_escape_string($_POST['notes'] ?? '');
    $uid        = (int)($_SESSION['user_id'] ?? 0);

    $old        = $db->query("SELECT status FROM fleet_vehicles WHERE id=$vid")->fetch_assoc();
    $old_status = $db->real_escape_string($old['status'] ?? '');

    $db->query("UPDATE fleet_vehicles SET status='$new_status' WHERE id=$vid");
    $db->query("INSERT INTO fleet_vehicle_status_log (vehicle_id,old_status,new_status,changed_by,notes)
                VALUES ($vid,'$old_status','$new_status',$uid,'$notes')");
    showAlert('success', 'Vehicle status updated.');
    redirect('fleet_dashboard.php');
}

/* ═══════════════════════════════════════
   DATA FETCHING  (using actual column names)
═══════════════════════════════════════ */

/* All vehicles — actual columns: reg_no, make, model, capacity_tons, fuel_type
   status enum: Active | In Repair | Idle | Disposed */
$vehicles = $db->query("
    SELECT * FROM fleet_vehicles ORDER BY status, reg_no
")->fetch_all(MYSQLI_ASSOC);

/* Status counts */
$status_counts = [];
foreach ($vehicles as $v) {
    $status_counts[$v['status']] = ($status_counts[$v['status']] ?? 0) + 1;
}
$total_vehicles = count($vehicles);

/* PO stats */
$po_stats = $db->query("SELECT
    COUNT(*) total,
    SUM(CASE WHEN status='Draft'              THEN 1 ELSE 0 END) draft,
    SUM(CASE WHEN status='Approved'           THEN 1 ELSE 0 END) approved,
    SUM(CASE WHEN status='Partially Received' THEN 1 ELSE 0 END) partial,
    SUM(CASE WHEN status='Received'           THEN 1 ELSE 0 END) received,
    SUM(CASE WHEN status='Cancelled'          THEN 1 ELSE 0 END) cancelled,
    SUM(total_amount) total_value,
    SUM(CASE WHEN status='Approved' THEN total_amount ELSE 0 END) approved_value
    FROM fleet_purchase_orders")->fetch_assoc();

/* Recent POs */
$recent_pos = $db->query("SELECT p.*, v.vendor_name FROM fleet_purchase_orders p
    LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
    ORDER BY p.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

/* Expiry alerts — docs expiring within 30 days OR already expired, skip Disposed */
$expiry_alerts = $db->query("
    SELECT reg_no, make, model,
           insurance_expiry, fitness_expiry, permit_expiry,
           puc_expiry, national_permit_expiry
    FROM fleet_vehicles
    WHERE status != 'Disposed'
      AND (
           (insurance_expiry       IS NOT NULL AND insurance_expiry       <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (fitness_expiry         IS NOT NULL AND fitness_expiry         <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (permit_expiry          IS NOT NULL AND permit_expiry          <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (puc_expiry             IS NOT NULL AND puc_expiry             <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (national_permit_expiry IS NOT NULL AND national_permit_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
      )
    ORDER BY LEAST(
        COALESCE(insurance_expiry,       '9999-12-31'),
        COALESCE(fitness_expiry,         '9999-12-31'),
        COALESCE(permit_expiry,          '9999-12-31'),
        COALESCE(puc_expiry,             '9999-12-31'),
        COALESCE(national_permit_expiry, '9999-12-31')
    )
")->fetch_all(MYSQLI_ASSOC);

/* Recent status change log */
$status_log = $db->query("SELECT l.*, v.reg_no FROM fleet_vehicle_status_log l
    LEFT JOIN fleet_vehicles v ON l.vehicle_id=v.id
    ORDER BY l.changed_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-speedometer2 me-2"></i>Fleet Dashboard';</script>

<style>
.kpi-card { border:none; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); transition:transform .2s; }
.kpi-card:hover { transform:translateY(-3px); }
.kpi-icon { width:46px; height:46px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.35rem; flex-shrink:0; }
.vehicle-card { border-radius:10px; border:1px solid #e5e7eb; transition:box-shadow .2s; display:flex; overflow:hidden; }
.vehicle-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.12); }
.status-strip { width:5px; flex-shrink:0; }
.expiry-danger  { color:#dc2626; font-weight:600; }
.expiry-warning { color:#d97706; font-weight:600; }
.section-title  { font-weight:700; font-size:.95rem; letter-spacing:.3px; color:#1e293b; }
.doc-pill { font-size:.68rem; border-radius:20px; padding:2px 8px; border:1px solid; display:inline-block; }
</style>

<?php
/* ── Helpers ── */
function stripColor($s) {
    return match($s) {
        'Active'    => '#10b981',
        'In Repair' => '#f59e0b',
        'Idle'      => '#8b5cf6',
        'Disposed'  => '#6b7280',
        default     => '#9ca3af',
    };
}
function statusBadgeStyle($s) {
    $cfg = [
        'Active'    => ['#ecfdf5','#10b981'],
        'In Repair' => ['#fffbeb','#f59e0b'],
        'Idle'      => ['#f5f3ff','#8b5cf6'],
        'Disposed'  => ['#f9fafb','#6b7280'],
    ];
    [$bg,$col] = $cfg[$s] ?? ['#f1f5f9','#64748b'];
    return "background:$bg;color:$col;border:1px solid {$col}40";
}
function expiryInfo($date) {
    if (!$date || $date === '0000-00-00') return null;
    $days = (int)round((strtotime($date) - time()) / 86400);
    $fmt  = date('d M y', strtotime($date));
    if ($days < 0)   return ['label' => "$fmt (Exp)",   'color' => '#dc2626'];
    if ($days <= 15) return ['label' => "$fmt ({$days}d)", 'color' => '#dc2626'];
    if ($days <= 30) return ['label' => "$fmt ({$days}d)", 'color' => '#d97706'];
    return null;
}
function expiryClass($date) {
    if (!$date || $date === '0000-00-00') return '';
    $days = (int)round((strtotime($date) - time()) / 86400);
    if ($days < 0)   return 'expiry-danger';
    if ($days <= 15) return 'expiry-danger';
    if ($days <= 30) return 'expiry-warning';
    return '';
}
?>

<div class="container-fluid px-3 py-2">

<!-- ══════════ KPI CARDS ══════════ -->
<div class="row g-3 mb-3">
<?php
$kpis = [
    ['Total Vehicles', $total_vehicles,                '#eff6ff', 'bi-truck',                '#3b82f6'],
    ['Active',         $status_counts['Active']    ?? 0, '#ecfdf5', 'bi-check-circle',       '#10b981'],
    ['In Repair',      $status_counts['In Repair'] ?? 0, '#fffbeb', 'bi-tools',              '#f59e0b'],
    ['Idle',           $status_counts['Idle']      ?? 0, '#f5f3ff', 'bi-pause-circle',       '#8b5cf6'],
    ['Disposed',       $status_counts['Disposed']  ?? 0, '#f9fafb', 'bi-x-circle',           '#6b7280'],
    ['Doc Alerts',     count($expiry_alerts),           '#fff1f2', 'bi-exclamation-triangle','#dc2626'],
];
foreach ($kpis as [$label, $val, $bg, $icon, $col]):
?>
<div class="col-6 col-md-4 col-xl-2">
    <div class="card kpi-card h-100 p-3">
        <div class="d-flex align-items-center gap-3">
            <div class="kpi-icon" style="background:<?= $bg ?>">
                <i class="bi <?= $icon ?>" style="color:<?= $col ?>"></i>
            </div>
            <div>
                <div class="fs-4 fw-bold" style="color:<?= $col ?>"><?= $val ?></div>
                <div class="text-muted small"><?= $label ?></div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════ MAIN ROW ══════════ -->
<div class="row g-3">

<!-- LEFT: Vehicle Status Cards -->
<div class="col-12 col-xl-8">

    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
        <span class="section-title"><i class="bi bi-truck me-1"></i>Vehicle Status Report</span>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <div class="btn-group btn-group-sm" id="statusFilter">
                <button class="btn btn-outline-secondary active" data-filter="all">All (<?= $total_vehicles ?>)</button>
                <?php foreach (['Active','In Repair','Idle','Disposed'] as $sf): ?>
                <?php if (!empty($status_counts[$sf])): ?>
                <button class="btn btn-outline-secondary" data-filter="<?= $sf ?>"><?= $sf ?> (<?= $status_counts[$sf] ?>)</button>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <a href="fleet_vehicles.php" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Manage
            </a>
        </div>
    </div>

    <div class="row g-2" id="vehicleGrid">
    <?php if (empty($vehicles)): ?>
    <div class="col-12">
        <div class="card text-center p-5 text-muted">
            <i class="bi bi-truck fs-1 mb-2"></i><br>No vehicles found.
            <br><a href="fleet_vehicles.php" class="btn btn-primary btn-sm mt-2">Go to Vehicle Master</a>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($vehicles as $v):
        $make_model = trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? ''));
        $doc_map = [
            'Insur.'     => $v['insurance_expiry'],
            'Fitness'    => $v['fitness_expiry'],
            'Permit'     => $v['permit_expiry'],
            'PUC'        => $v['puc_expiry'],
            'Nat.Permit' => $v['national_permit_expiry'],
        ];
        $expiring_docs = [];
        foreach ($doc_map as $lbl => $dt) {
            $info = expiryInfo($dt);
            if ($info) $expiring_docs[] = ['label' => $lbl, 'info' => $info];
        }
    ?>
    <div class="col-12 col-md-6" data-vstatus="<?= htmlspecialchars($v['status']) ?>">
        <div class="vehicle-card">
            <div class="status-strip" style="background:<?= stripColor($v['status']) ?>"></div>
            <div class="p-3 flex-grow-1">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="fw-bold"><?= htmlspecialchars($v['reg_no']) ?></span>
                        <span class="doc-pill ms-1" style="<?= statusBadgeStyle($v['status']) ?>"><?= $v['status'] ?></span>
                        <div class="text-muted small mt-1">
                            <?= $make_model ?: '—' ?>
                            <?= $v['year']          ? ' · '.$v['year']           : '' ?>
                            <?= $v['capacity_tons'] > 0 ? ' · '.$v['capacity_tons'].'T' : '' ?>
                            <?= $v['fuel_type']     ? ' · '.$v['fuel_type']      : '' ?>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light py-0 px-2" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><h6 class="dropdown-header small">Change Status</h6></li>
                            <?php foreach (['Active','In Repair','Idle','Disposed'] as $ns):
                                if ($ns === $v['status']) continue; ?>
                            <li><a class="dropdown-item small" href="#"
                                onclick="openStatusModal(<?= $v['id'] ?>,'<?= addslashes($v['reg_no']) ?>','<?= addslashes($v['status']) ?>','<?= $ns ?>');return false">
                                <i class="bi bi-arrow-right me-1"></i>Mark <?= $ns ?>
                            </a></li>
                            <?php endforeach; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item small" href="fleet_vehicles.php?edit=<?= $v['id'] ?>">
                                <i class="bi bi-pencil me-1"></i>Edit Vehicle
                            </a></li>
                        </ul>
                    </div>
                </div>

                <!-- Doc expiry pills — only expiring soon/expired -->
                <?php if ($expiring_docs): ?>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    <?php foreach ($expiring_docs as $d): ?>
                    <span class="doc-pill" style="background:<?= $d['info']['color'] ?>18;color:<?= $d['info']['color'] ?>;border-color:<?= $d['info']['color'] ?>50">
                        <?= $d['label'] ?>: <?= $d['info']['label'] ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="mt-2 small text-success"><i class="bi bi-shield-check me-1"></i>All documents valid</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div><!-- /vehicleGrid -->
</div><!-- /left col -->

<!-- RIGHT: Summaries -->
<div class="col-12 col-xl-4">

    <!-- PO Summary -->
    <div class="card mb-3" style="border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.08)">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <span class="section-title"><i class="bi bi-file-earmark-text me-1"></i>Purchase Orders</span>
            <div class="d-flex gap-1">
                <a href="fleet_purchase_orders.php?action=add" class="btn btn-sm btn-outline-success py-0">+ New</a>
                <a href="fleet_purchase_orders.php" class="btn btn-sm btn-outline-primary py-0">All</a>
            </div>
        </div>
        <div class="card-body p-3">
            <?php
            $po_disp = [
                ['Draft',     $po_stats['draft']     ?? 0, '#6b7280'],
                ['Approved',  $po_stats['approved']  ?? 0, '#10b981'],
                ['Partial',   $po_stats['partial']   ?? 0, '#3b82f6'],
                ['Received',  $po_stats['received']  ?? 0, '#7c3aed'],
                ['Cancelled', $po_stats['cancelled'] ?? 0, '#dc2626'],
            ];
            $max_cnt = max(array_column($po_disp, 1) ?: [1]);
            ?>
            <div class="row g-2 text-center mb-3">
                <?php foreach ($po_disp as [$label, $cnt, $col]): ?>
                <div class="col">
                    <div style="font-size:1.2rem;font-weight:700;color:<?= $col ?>"><?= $cnt ?></div>
                    <div class="text-muted" style="font-size:.67rem"><?= $label ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php foreach ($po_disp as [$label, $cnt, $col]): ?>
            <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.8rem">
                <span style="width:68px;color:#64748b"><?= $label ?></span>
                <div class="flex-grow-1 rounded" style="height:7px;background:#f1f5f9">
                    <div style="height:7px;border-radius:4px;background:<?= $col ?>;width:<?= $max_cnt > 0 ? round($cnt/$max_cnt*100) : 0 ?>%"></div>
                </div>
                <span style="width:18px;text-align:right;font-weight:600"><?= $cnt ?></span>
            </div>
            <?php endforeach; ?>
            <div class="d-flex justify-content-between mt-2 pt-2 border-top small text-muted">
                <span>Total: <strong><?= $po_stats['total'] ?? 0 ?></strong></span>
                <span>Approved: <strong>₹<?= number_format(($po_stats['approved_value'] ?? 0)/100000, 2) ?>L</strong></span>
            </div>
        </div>
    </div>

    <!-- Compliance Alerts -->
    <?php if (!empty($expiry_alerts)): ?>
    <div class="card mb-3" style="border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.08)">
        <div class="card-header bg-white border-bottom py-2">
            <span class="section-title text-danger">
                <i class="bi bi-exclamation-triangle me-1"></i>Compliance Alerts
                <span class="badge bg-danger ms-1"><?= count($expiry_alerts) ?></span>
            </span>
        </div>
        <div style="max-height:240px;overflow-y:auto">
        <?php foreach ($expiry_alerts as $ea):
            $doc_map2 = [
                'Insurance'  => $ea['insurance_expiry'],
                'Fitness'    => $ea['fitness_expiry'],
                'Permit'     => $ea['permit_expiry'],
                'PUC'        => $ea['puc_expiry'],
                'Nat.Permit' => $ea['national_permit_expiry'],
            ];
        ?>
        <div class="px-3 py-2 border-bottom">
            <div class="fw-semibold small"><?= htmlspecialchars($ea['reg_no']) ?>
                <?php if ($ea['make'] || $ea['model']): ?>
                <span class="text-muted fw-normal"> · <?= htmlspecialchars(trim($ea['make'].' '.$ea['model'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-1">
                <?php foreach ($doc_map2 as $lbl => $dt):
                    $cls = expiryClass($dt);
                    if (!$cls) continue;
                ?>
                <span class="small <?= $cls ?>">
                    <i class="bi bi-clock me-1"></i><?= $lbl ?>: <?= $dt ? date('d M Y', strtotime($dt)) : '—' ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Status Log -->
    <?php if (!empty($status_log)): ?>
    <div class="card mb-3" style="border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.08)">
        <div class="card-header bg-white border-bottom py-2">
            <span class="section-title"><i class="bi bi-clock-history me-1"></i>Recent Status Changes</span>
        </div>
        <div style="max-height:200px;overflow-y:auto">
        <?php foreach ($status_log as $log): ?>
        <div class="px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold small"><?= htmlspecialchars($log['reg_no'] ?? '—') ?></span>
                <span class="text-muted" style="font-size:.7rem"><?= date('d M, H:i', strtotime($log['changed_at'])) ?></span>
            </div>
            <div class="small">
                <?php if ($log['old_status']): ?>
                <span class="text-muted"><?= htmlspecialchars($log['old_status']) ?></span>
                <i class="bi bi-arrow-right mx-1 text-muted" style="font-size:.7rem"></i>
                <?php endif; ?>
                <span class="doc-pill" style="background:<?= stripColor($log['new_status']) ?>20;color:<?= stripColor($log['new_status']) ?>;border-color:<?= stripColor($log['new_status']) ?>40">
                    <?= htmlspecialchars($log['new_status']) ?>
                </span>
                <?php if ($log['notes']): ?>
                <span class="text-muted ms-1">· <?= htmlspecialchars($log['notes']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent POs list -->
    <div class="card" style="border-radius:12px;border:none;box-shadow:0 2px 12px rgba(0,0,0,.08)">
        <div class="card-header bg-white border-bottom py-2">
            <span class="section-title"><i class="bi bi-clock me-1"></i>Recent POs</span>
        </div>
        <div style="max-height:280px;overflow-y:auto">
        <?php if (empty($recent_pos)): ?>
        <div class="text-center text-muted p-4 small">No purchase orders yet.</div>
        <?php endif; ?>
        <?php foreach ($recent_pos as $po):
            $pc = ['Draft'=>'#6b7280','Approved'=>'#10b981','Partially Received'=>'#3b82f6','Received'=>'#7c3aed','Cancelled'=>'#dc2626'];
            $sc = $pc[$po['status']] ?? '#6b7280';
        ?>
        <a href="fleet_purchase_orders.php?action=view&id=<?= $po['id'] ?>" class="d-block px-3 py-2 border-bottom text-decoration-none text-dark">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-semibold small"><?= htmlspecialchars($po['po_number']) ?></span>
                <span class="doc-pill" style="background:<?= $sc ?>18;color:<?= $sc ?>;border-color:<?= $sc ?>30"><?= $po['status'] ?></span>
            </div>
            <div class="d-flex justify-content-between text-muted" style="font-size:.74rem">
                <span><?= htmlspecialchars($po['vendor_name'] ?? '—') ?></span>
                <span>₹<?= number_format($po['total_amount'], 0) ?> · <?= date('d M', strtotime($po['po_date'])) ?></span>
            </div>
        </a>
        <?php endforeach; ?>
        </div>
    </div>

</div><!-- /right col -->
</div><!-- /main row -->
</div><!-- /container -->

<!-- ══════════ STATUS UPDATE MODAL ══════════ -->
<div class="modal fade" id="statusModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-arrow-repeat me-2"></i>Update Status</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
    <input type="hidden" name="update_status" value="1">
    <input type="hidden" name="vehicle_id" id="sm_vid">
    <div class="modal-body">
        <div class="mb-3">
            <div class="fw-bold" id="sm_vno"></div>
            <div class="text-muted small" id="sm_cur_status"></div>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">New Status</label>
            <select name="new_status" id="sm_status" class="form-select">
                <option>Active</option>
                <option>In Repair</option>
                <option>Idle</option>
                <option>Disposed</option>
            </select>
        </div>
        <div>
            <label class="form-label small fw-semibold">Notes / Reason</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Optional reason..."></textarea>
        </div>
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary px-3">Update</button>
    </div>
    </form>
</div>
</div>
</div>

<script>
document.querySelectorAll('#statusFilter .btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#statusFilter .btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const f = this.dataset.filter;
        document.querySelectorAll('#vehicleGrid [data-vstatus]').forEach(c => {
            c.style.display = (f === 'all' || c.dataset.vstatus === f) ? '' : 'none';
        });
    });
});

function openStatusModal(vid, reg_no, cur_status, new_status) {
    document.getElementById('sm_vid').value              = vid;
    document.getElementById('sm_vno').textContent        = reg_no;
    document.getElementById('sm_cur_status').textContent = 'Current: ' + cur_status;
    document.getElementById('sm_status').value           = new_status;
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
