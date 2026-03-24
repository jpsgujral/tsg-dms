<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/auth.php';
$db = getDB();

// Stats
$stats = [
    'vendors'    => $db->query("SELECT COUNT(*) c FROM vendors WHERE status='Active'")->fetch_assoc()['c'] ?? 0,
    'items'      => $db->query("SELECT COUNT(*) c FROM items WHERE status='Active'")->fetch_assoc()['c'] ?? 0,
    'po_pending' => $db->query("SELECT COUNT(*) c FROM purchase_orders WHERE status IN ('Draft','Approved')")->fetch_assoc()['c'] ?? 0,
    'despatch'   => $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Despatched'")->fetch_assoc()['c'] ?? 0,
    'in_transit' => $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='In Transit'")->fetch_assoc()['c'] ?? 0,
    'delivered'  => $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Delivered'")->fetch_assoc()['c'] ?? 0,
];

// Recent Despatches — grouped by transporter (last 30 days, all statuses)
$recent_despatches = $db->query("
    SELECT d.*, t.transporter_name, t.id AS tid
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    WHERE d.despatch_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY t.transporter_name ASC, d.despatch_date DESC
")->fetch_all(MYSQLI_ASSOC);
// Group by transporter
$desp_groups = [];
foreach ($recent_despatches as $r) {
    $key = $r['transporter_name'] ?: 'Unassigned';
    $desp_groups[$key][] = $r;
}

// Recent POs — removed (access from PO master)

include 'includes/header.php';
?>

<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-speedometer2 me-2"></i>Dashboard';</script>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#1a5632,#27ae60)">
            <p>Active Vendors</p>
            <h3><?= $stats['vendors'] ?></h3>
            <i class="bi bi-building icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#1b7a3d,#2ecc71)">
            <p>Active Items</p>
            <h3><?= $stats['items'] ?></h3>
            <i class="bi bi-box-seam icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#145a2a,#1a8a42)">
            <p>Pending POs</p>
            <h3><?= $stats['po_pending'] ?></h3>
            <i class="bi bi-file-earmark-text icon"></i>
        </div>
    </div>
    <div class="col-6 col-sm-6 col-md-3">
        <div class="stat-card" style="background: linear-gradient(135deg,#0e4420,#196f35)">
            <p>Total Despatched</p>
            <h3><?= $stats['despatch'] ?></h3>
            <i class="bi bi-send-check icon"></i>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card text-center p-3">
            <div class="text-warning fs-2"><i class="bi bi-truck-flatbed"></i></div>
            <h4 class="text-warning"><?= $stats['in_transit'] ?></h4>
            <p class="text-muted mb-0">In Transit</p>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card text-center p-3">
            <div class="text-success fs-2"><i class="bi bi-check-circle"></i></div>
            <h4 class="text-success"><?= $stats['delivered'] ?></h4>
            <p class="text-muted mb-0">Delivered</p>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card text-center p-3">
            <div class="d-flex gap-2 justify-content-center flex-wrap mt-2">
                <a href="modules/despatch.php?action=add" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Despatch</a>
                <a href="modules/purchase_orders.php?action=add" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New PO</a>
            </div>
            <p class="text-muted mt-2 mb-0">Quick Actions</p>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- ── Recent Despatches grouped by Transporter ── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-send-check me-2"></i>Recent Despatches <small class="text-muted fw-normal">(Last 30 days)</small>
                    <span class="badge bg-primary ms-1" id="dashDespCount"><?= count($recent_despatches) ?></span>
                </span>
                <div class="d-flex gap-1 flex-wrap" id="dashDespFilters">
                    <button class="btn btn-sm btn-dark dash-status-btn active" data-status="All" onclick="dashFilterStatus('All',this)">All</button>
                    <?php
                    // Collect unique statuses
                    $allStatuses = [];
                    foreach ($recent_despatches as $r) $allStatuses[$r['status']] = ($allStatuses[$r['status']] ?? 0) + 1;
                    $badges_d = ['Draft'=>'secondary','Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'];
                    foreach ($allStatuses as $st => $cnt):
                    ?>
                    <button class="btn btn-sm btn-outline-<?= $badges_d[$st] ?? 'secondary' ?> dash-status-btn" data-status="<?= $st ?>" onclick="dashFilterStatus('<?= $st ?>',this)"><?= $st ?> <span class="badge bg-<?= $badges_d[$st] ?? 'secondary' ?> ms-1"><?= $cnt ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body p-0">
            <?php if (empty($desp_groups)): ?>
                <div class="text-center text-muted p-4"><i class="bi bi-inbox me-2"></i>No despatches in last 30 days</div>
            <?php else: ?>
                <div class="table-responsive">
                <table class="table table-hover mb-0" id="dashDespTable">
                    <thead><tr>
                        <th style="width:16%">Challan No</th>
                        <th style="width:10%">Date</th>
                        <th style="width:20%">Consignee</th>
                        <th style="width:12%" class="text-end">Weight</th>
                        <th style="width:10%" class="text-end">Freight ₹</th>
                        <th style="width:10%">Status</th>
                        <th style="width:4%"></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $dgi = 0;
                    foreach ($desp_groups as $transporter => $orders):
                        $dgi++;
                        $grpId   = 'dgrp_' . $dgi;
                        $count   = count($orders);
                        $grpWt   = array_sum(array_column($orders, 'total_weight'));
                        $grpAmt  = array_sum(array_column($orders, 'total_amount'));
                        $grpFrt  = array_sum(array_column($orders, 'freight_amount'));
                        // Collect statuses for this group
                        $grpStatuses = [];
                        foreach ($orders as $o) $grpStatuses[$o['status']] = true;
                        $grpStatusList = implode(',', array_keys($grpStatuses));
                    ?>
                    <tr class="dash-group-header" style="cursor:pointer;background:#e5f5eb" data-statuses="<?= htmlspecialchars($grpStatusList) ?>" onclick="dashToggle('<?= $grpId ?>', this)">
                        <td colspan="3">
                            <span class="dash-toggle-icon me-1">▾</span>
                            <i class="bi bi-truck me-1 text-primary"></i>
                            <strong><?= htmlspecialchars($transporter) ?></strong>
                            <span class="badge bg-secondary ms-2"><?= $count ?></span>
                            <?php
                            $sCounts = [];
                            foreach ($orders as $o) $sCounts[$o['status']] = ($sCounts[$o['status']] ?? 0) + 1;
                            foreach ($sCounts as $st => $sc): ?>
                            <span class="badge bg-<?= $badges_d[$st] ?? 'secondary' ?> ms-1" style="font-size:.6rem"><?= $sc ?> <?= $st ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end fw-semibold text-muted"><?= number_format($grpWt, 2) ?> MT</td>
                        <td class="text-end fw-semibold text-info">₹<?= number_format($grpFrt, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                    <?php foreach ($orders as $row): ?>
                    <tr class="dash-group-row <?= $grpId ?>" data-status="<?= htmlspecialchars($row['status']) ?>">
                        <td class="ps-4"><strong><?= htmlspecialchars($row['challan_no']) ?></strong></td>
                        <td><?= date('d/m/Y', strtotime($row['despatch_date'])) ?></td>
                        <td><?= htmlspecialchars($row['consignee_name']) ?><br><small class="text-muted"><?= htmlspecialchars($row['consignee_city'] ?? '') ?></small></td>
                        <td class="text-end"><?= (float)$row['total_weight'] > 0 ? number_format((float)$row['total_weight'], 3) : '—' ?></td>
                        <td class="text-end"><?= (float)($row['freight_amount']??0) > 0 ? '₹'.number_format((float)$row['freight_amount'], 2) : '—' ?></td>
                        <td><span class="badge bg-<?= $badges_d[$row['status']] ?? 'secondary' ?>" style="font-size:.65rem"><?= $row['status'] ?></span></td>
                        <td>
                            <a href="modules/despatch.php?action=edit&id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="View"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.dash-group-header td { border-top: 2px solid #1a5632 !important; }
.dash-group-header:hover td { filter: brightness(0.95); }
.dash-group-row td { font-size: 0.88rem; }
.dash-group-row:hover td { background: #f8f9fa !important; }
.dash-status-btn.active { box-shadow: 0 0 0 2px rgba(30,58,95,0.5); }
</style>
<script>
function dashToggle(id, headerRow) {
    var rows = document.querySelectorAll('.' + id);
    var icon = headerRow.querySelector('.dash-toggle-icon');
    var hidden = rows.length && rows[0].style.display === 'none';
    rows.forEach(function(r) { r.style.display = hidden ? '' : 'none'; });
    icon.textContent = hidden ? '▾' : '▸';
}

function dashFilterStatus(status, btn) {
    // Update active button
    document.querySelectorAll('.dash-status-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');

    var headers = document.querySelectorAll('#dashDespTable .dash-group-header');
    var rows    = document.querySelectorAll('#dashDespTable .dash-group-row');
    var visible = 0;

    if (status === 'All') {
        headers.forEach(function(h) { h.style.display = ''; });
        rows.forEach(function(r) { r.style.display = ''; visible++; });
    } else {
        // Show/hide individual rows by status
        rows.forEach(function(r) {
            if (r.dataset.status === status) {
                r.style.display = '';
                visible++;
            } else {
                r.style.display = 'none';
            }
        });
        // Show/hide group headers — show only if at least one child row is visible
        headers.forEach(function(h) {
            var statuses = (h.dataset.statuses || '').split(',');
            h.style.display = statuses.includes(status) ? '' : 'none';
            // Ensure group is expanded
            var icon = h.querySelector('.dash-toggle-icon');
            if (icon) icon.textContent = '▾';
        });
    }
    // Update count badge
    var countEl = document.getElementById('dashDespCount');
    if (countEl) countEl.textContent = visible;
}
</script>

<?php include 'includes/footer.php'; ?>
