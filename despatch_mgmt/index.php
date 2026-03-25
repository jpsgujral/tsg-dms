<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/auth.php';
$db = getDB();

// ── Handle AJAX status change ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_status_change'])) {
    header('Content-Type: application/json');
    $id         = (int)($_POST['id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $allowed    = ['In Transit', 'Delivered'];
    if (!$id || !in_array($new_status, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    $row = $db->query("SELECT status FROM despatch_orders WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Order not found']); exit; }
    $valid = ($row['status'] === 'Despatched' && $new_status === 'In Transit')
          || ($row['status'] === 'In Transit'  && $new_status === 'Delivered');
    if (!$valid) { echo json_encode(['success' => false, 'message' => 'Invalid transition']); exit; }
    $db->query("UPDATE despatch_orders SET status='".($db->real_escape_string($new_status))."' WHERE id=$id");
    echo json_encode(['success' => true, 'new_status' => $new_status]);
    exit;
}

// ── Check if updated_at column exists, use safe fallback ──────────────────
$dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
$has_updated_at = $db->query("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='despatch_orders'
    AND COLUMN_NAME='updated_at' LIMIT 1")->num_rows > 0;

// ── Today's stats ──────────────────────────────────────────────────────────
$today_count   = $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE DATE(despatch_date)=CURDATE() AND status!='Cancelled'")->fetch_assoc()['c'] ?? 0;
$today_mt      = $db->query("SELECT COALESCE(SUM(total_weight),0) w FROM despatch_orders WHERE DATE(despatch_date)=CURDATE() AND status!='Cancelled'")->fetch_assoc()['w'] ?? 0;
$transit_count = $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='In Transit'")->fetch_assoc()['c'] ?? 0;
$transit_mt    = $db->query("SELECT COALESCE(SUM(total_weight),0) w FROM despatch_orders WHERE status='In Transit'")->fetch_assoc()['w'] ?? 0;
$po_pending    = $db->query("SELECT COUNT(*) c FROM purchase_orders WHERE status IN ('Draft','Approved')")->fetch_assoc()['c'] ?? 0;
$month_freight = $db->query("SELECT COALESCE(SUM(freight_amount),0) f FROM despatch_orders WHERE MONTH(despatch_date)=MONTH(CURDATE()) AND YEAR(despatch_date)=YEAR(CURDATE()) AND status!='Cancelled'")->fetch_assoc()['f'] ?? 0;

// Delivered today — use updated_at if available, else despatch_date as fallback
if ($has_updated_at) {
    $delivered_today = $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Delivered' AND DATE(updated_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;
} else {
    $delivered_today = $db->query("SELECT COUNT(*) c FROM despatch_orders WHERE status='Delivered' AND DATE(despatch_date)=CURDATE()")->fetch_assoc()['c'] ?? 0;
}

// ── Last 7 days despatches ─────────────────────────────────────────────────
$recent_despatches = $db->query("
    SELECT d.*, t.transporter_name
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    WHERE d.despatch_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      AND d.status != 'Cancelled'
    ORDER BY d.despatch_date DESC, d.id DESC
")->fetch_all(MYSQLI_ASSOC);

$badges = ['Draft'=>'secondary','Despatched'=>'primary','In Transit'=>'warning','Delivered'=>'success','Cancelled'=>'danger'];

include 'includes/header.php';
?>

<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-speedometer2 me-2"></i>Dashboard';</script>

<!-- ══ TOP STATS ROW ══════════════════════════════════════════════════════ -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #1a5632 !important">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Today's Despatches</div>
                        <div class="fw-bold fs-4 text-success"><?= $today_count ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= number_format((float)$today_mt, 3) ?> MT</div>
                    </div>
                    <i class="bi bi-send-check fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #f39c12 !important">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">In Transit</div>
                        <div class="fw-bold fs-4 text-warning"><?= $transit_count ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= number_format((float)$transit_mt, 3) ?> MT</div>
                    </div>
                    <i class="bi bi-truck-flatbed fs-2 text-warning opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #27ae60 !important">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small">Delivered Today</div>
                        <div class="fw-bold fs-4 text-success"><?= $delivered_today ?></div>
                        <div class="text-muted" style="font-size:.75rem">Month Freight: ₹<?= number_format((float)$month_freight, 0) ?></div>
                    </div>
                    <i class="bi bi-check-circle fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #3498db !important">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <div>
                        <div class="text-muted small">Pending POs</div>
                        <div class="fw-bold fs-4 text-primary"><?= $po_pending ?></div>
                    </div>
                    <i class="bi bi-file-earmark-text fs-2 text-primary opacity-50"></i>
                </div>
                <div class="d-flex gap-1 flex-wrap">
                    <a href="modules/despatch.php?action=add" class="btn btn-success btn-sm py-0" style="font-size:.7rem"><i class="bi bi-plus"></i> Despatch</a>
                    <a href="modules/purchase_orders.php?action=add" class="btn btn-outline-primary btn-sm py-0" style="font-size:.7rem"><i class="bi bi-plus"></i> PO</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ LAST 7 DAYS DESPATCHES TABLE ══════════════════════════════════════ -->
<div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="bi bi-table me-1"></i>Despatches
            <small class="text-muted fw-normal">— Last 7 days</small>
            <span class="badge bg-secondary ms-1"><?= count($recent_despatches) ?></span>
        </span>
        <div class="d-flex gap-1 flex-wrap" id="statusFilters">
            <button class="btn btn-sm btn-dark active" onclick="filterStatus('All',this)">All</button>
            <?php
            $statusCounts = [];
            foreach ($recent_despatches as $r) $statusCounts[$r['status']] = ($statusCounts[$r['status']] ?? 0) + 1;
            foreach ($statusCounts as $st => $cnt):
            ?>
            <button class="btn btn-sm btn-outline-<?= $badges[$st] ?? 'secondary' ?>" onclick="filterStatus('<?= $st ?>',this)">
                <?= $st ?> <span class="badge bg-<?= $badges[$st] ?? 'secondary' ?>"><?= $cnt ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body p-0">
    <?php if (empty($recent_despatches)): ?>
        <div class="text-center text-muted p-4"><i class="bi bi-inbox me-2"></i>No despatches in last 7 days</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle" id="despTable">
            <thead class="table-light">
                <tr>
                    <th style="width:13%">Challan No</th>
                    <th style="width:9%">Date</th>
                    <th style="width:18%">Consignee</th>
                    <th style="width:15%">Transporter</th>
                    <th style="width:10%" class="text-end">MT</th>
                    <th style="width:10%" class="text-end">Freight ₹</th>
                    <th style="width:10%">Status</th>
                    <th style="width:15%">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_despatches as $row):
                $st = $row['status'];
                $id = (int)$row['id'];
            ?>
            <tr data-status="<?= htmlspecialchars($st) ?>" data-id="<?= $id ?>">
                <td><a href="modules/despatch.php?action=edit&id=<?= $id ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($row['challan_no']) ?></a></td>
                <td style="font-size:.8rem"><?= date('d/m/y', strtotime($row['despatch_date'])) ?></td>
                <td style="font-size:.82rem">
                    <?= htmlspecialchars($row['consignee_name']) ?>
                    <?php if (!empty($row['consignee_city'])): ?>
                    <br><span class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($row['consignee_city']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem"><?= htmlspecialchars($row['transporter_name'] ?? '—') ?></td>
                <td class="text-end" style="font-size:.82rem"><?= (float)$row['total_weight'] > 0 ? number_format((float)$row['total_weight'], 3) : '—' ?></td>
                <td class="text-end" style="font-size:.82rem"><?= (float)($row['freight_amount']??0) > 0 ? '₹'.number_format((float)$row['freight_amount'], 0) : '—' ?></td>
                <td><span class="badge bg-<?= $badges[$st] ?? 'secondary' ?> status-badge" style="font-size:.65rem"><?= $st ?></span></td>
                <td>
                    <?php if ($st === 'Despatched'): ?>
                        <button class="btn btn-warning btn-sm py-0 px-2" style="font-size:.7rem" onclick="changeStatus(<?= $id ?>,'In Transit',this)">
                            <i class="bi bi-truck"></i> In Transit
                        </button>
                    <?php elseif ($st === 'In Transit'): ?>
                        <button class="btn btn-success btn-sm py-0 px-2" style="font-size:.7rem" onclick="changeStatus(<?= $id ?>,'Delivered',this)">
                            <i class="bi bi-check-circle"></i> Delivered
                        </button>
                    <?php else: ?>
                        <a href="modules/despatch.php?action=edit&id=<?= $id ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem">
                            <i class="bi bi-eye"></i> View
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- ══ STATUS CHANGE CONFIRM MODAL ═══════════════════════════════════════ -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="statusModalTitle">Confirm Status Change</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2" id="statusModalBody"></div>
            <div class="modal-footer py-2">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="statusModalConfirm">Confirm</button>
            </div>
        </div>
    </div>
</div>

<style>
.card { border-radius: 8px; }
#despTable td, #despTable th { vertical-align: middle; }
#statusFilters .btn.active { box-shadow: 0 0 0 2px rgba(0,0,0,0.3); }
</style>

<script>
function filterStatus(status, btn) {
    document.querySelectorAll('#statusFilters .btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#despTable tbody tr').forEach(row => {
        row.style.display = (status === 'All' || row.dataset.status === status) ? '' : 'none';
    });
}

let _pendingId = null, _pendingStatus = null, _pendingBtn = null;

function changeStatus(id, newStatus, btn) {
    _pendingId     = id;
    _pendingStatus = newStatus;
    _pendingBtn    = btn;
    const icon = newStatus === 'Delivered' ? '✅' : '🚛';
    document.getElementById('statusModalBody').innerHTML =
        icon + ' Mark <strong>Challan #' + btn.closest('tr').querySelector('td:first-child').innerText + '</strong> as <strong>' + newStatus + '</strong>?';
    document.getElementById('statusModalTitle').textContent = 'Confirm: ' + newStatus;
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

document.getElementById('statusModalConfirm').addEventListener('click', function() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('statusModal'));
    modal.hide();
    const btn = _pendingBtn;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    const fd = new FormData();
    fd.append('ajax_status_change', '1');
    fd.append('id', _pendingId);
    fd.append('new_status', _pendingStatus);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = btn.closest('tr');
                const badges = {'Despatched':'primary','In Transit':'warning','Delivered':'success'};
                row.querySelector('.status-badge').className =
                    'badge bg-' + (badges[data.new_status] || 'secondary') + ' status-badge';
                row.querySelector('.status-badge').textContent = data.new_status;
                row.dataset.status = data.new_status;
                const td = btn.parentElement;
                if (data.new_status === 'In Transit') {
                    td.innerHTML = '<button class="btn btn-success btn-sm py-0 px-2" style="font-size:.7rem" onclick="changeStatus(' + _pendingId + ',\'Delivered\',this)"><i class="bi bi-check-circle"></i> Delivered</button>';
                } else if (data.new_status === 'Delivered') {
                    td.innerHTML = '<a href="modules/despatch.php?action=edit&id=' + _pendingId + '" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem"><i class="bi bi-eye"></i> View</a>';
                }
                setTimeout(() => location.reload(), 800);
            } else {
                alert('Error: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = _pendingStatus === 'In Transit'
                    ? '<i class="bi bi-truck"></i> In Transit'
                    : '<i class="bi bi-check-circle"></i> Delivered';
            }
        })
        .catch(() => {
            alert('Network error. Please refresh.');
            btn.disabled = false;
        });
});
</script>

<?php include 'includes/footer.php'; ?>
