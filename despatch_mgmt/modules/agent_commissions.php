<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requirePerm('agent_commissions', 'view');
$db = getDB();
// Ensure notes column exists in agent_commissions
if (!function_exists('safeAddColumn')) {
    function safeAddColumn($db, $table, $col, $def) {
        if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->num_rows)
            $db->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
}
safeAddColumn($db, 'agent_commissions', 'notes', "TEXT DEFAULT NULL");

/* ── AJAX: Mark commissions as Paid ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_pay'])) {
    header('Content-Type: application/json');
    $agent_id  = (int)($_POST['agent_id'] ?? 0);
    $ids       = array_map('intval', $_POST['commission_ids'] ?? []);
    $paid_date = $db->real_escape_string($_POST['paid_date']  ?? date('Y-m-d'));
    $reference = $db->real_escape_string($_POST['reference']  ?? '');
    $notes     = $db->real_escape_string($_POST['notes']      ?? '');
    $amount    = (float)($_POST['amount'] ?? 0);

    if (!$agent_id || empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Invalid data']); exit; }

    // Calculate total commission for selected ids
    $id_list   = implode(',', $ids);
    $total_row = $db->query("SELECT COALESCE(SUM(commission_amt),0) AS tot FROM agent_commissions WHERE id IN ($id_list)")->fetch_assoc();
    $total_due = (float)$total_row['tot'];

    $db->query("INSERT INTO agent_commission_payments (agent_id,amount,paid_date,reference,notes)
        VALUES ($agent_id,$amount,'$paid_date','$reference','$notes')");
    $pay_id = $db->insert_id;
    foreach ($ids as $cid)
        $db->query("INSERT IGNORE INTO agent_payment_commissions (payment_id,commission_id) VALUES ($pay_id,$cid)");

    // Only mark as Paid if full amount paid (within ₹1 tolerance)
    if ($amount >= ($total_due - 1)) {
        $db->query("UPDATE agent_commissions SET status='Paid' WHERE id IN ($id_list)");
        $status = 'full';
    } else {
        // Partial payment — keep Pending, note in commission record
        $db->query("UPDATE agent_commissions SET notes=CONCAT(IFNULL(notes,''),' | Part paid ₹$amount on $paid_date') WHERE id IN ($id_list)");
        $status = 'partial';
    }
    echo json_encode(['ok'=>true,'pay_id'=>$pay_id,'status'=>$status,'total_due'=>$total_due,'paid'=>$amount]);
    exit;
}

/* ── Agents with pending commissions ── */
$agents = $db->query("
    SELECT u.id, u.full_name,
           COUNT(ac.id)            AS pending_count,
           SUM(ac.commission_amt)  AS pending_amt
    FROM app_users u
    JOIN agent_commissions ac ON ac.agent_id=u.id AND ac.status='Pending'
    GROUP BY u.id, u.full_name
    ORDER BY u.full_name
")->fetch_all(MYSQLI_ASSOC);

/* ── Pending rows per agent ── */
$pending = [];
$res = $db->query("
    SELECT ac.*, d.despatch_no
    FROM agent_commissions ac
    JOIN despatch_orders d ON ac.despatch_id=d.id
    WHERE ac.status='Pending'
    ORDER BY ac.agent_id, ac.despatch_date DESC
");
while ($row = $res->fetch_assoc())
    $pending[(int)$row['agent_id']][] = $row;

/* ── Summary cards ── */
$totals = $db->query("SELECT
    COUNT(CASE WHEN status='Pending' THEN 1 END)                              AS pend_count,
    COALESCE(SUM(CASE WHEN status='Pending' THEN commission_amt END), 0)      AS pend_amt,
    COUNT(CASE WHEN status='Paid'    THEN 1 END)                              AS paid_count,
    COALESCE(SUM(CASE WHEN status='Paid'    THEN commission_amt END), 0)      AS paid_amt
    FROM agent_commissions")->fetch_assoc();

/* ── Payment history — grouped by agent ── */
$pay_rows = $db->query("
    SELECT p.id AS pay_id, p.agent_id, p.amount, p.paid_date, p.reference, p.notes,
           u.full_name,
           ac.id AS comm_id, ac.challan_no, ac.despatch_date, ac.commission_amt,
           ac.vendor_name, ac.received_weight, ac.profit_per_mt, ac.commission_pct
    FROM agent_commission_payments p
    JOIN app_users u ON p.agent_id=u.id
    LEFT JOIN agent_payment_commissions apc ON apc.payment_id=p.id
    LEFT JOIN agent_commissions ac ON ac.id=apc.commission_id
    ORDER BY u.full_name, p.paid_date DESC, p.id DESC, ac.despatch_date
")->fetch_all(MYSQLI_ASSOC);

// Group: agent_id -> payment_id -> payment + challans
$pay_by_agent = [];
foreach ($pay_rows as $r) {
    $aid = (int)$r['agent_id'];
    $pid = (int)$r['pay_id'];
    if (!isset($pay_by_agent[$aid])) {
        $pay_by_agent[$aid] = ['full_name'=>$r['full_name'], 'total'=>0, 'payments'=>[]];
    }
    if (!isset($pay_by_agent[$aid]['payments'][$pid])) {
        $pay_by_agent[$aid]['payments'][$pid] = [
            'pay_id'    => $pid,
            'amount'    => (float)$r['amount'],
            'paid_date' => $r['paid_date'],
            'reference' => $r['reference'],
            'notes'     => $r['notes'],
            'challans'  => [],
        ];
        $pay_by_agent[$aid]['total'] += (float)$r['amount'];
    }
    if ($r['comm_id']) {
        $pay_by_agent[$aid]['payments'][$pid]['challans'][] = [
            'challan_no'     => $r['challan_no'],
            'despatch_date'  => $r['despatch_date'],
            'commission_amt' => (float)$r['commission_amt'],
            'vendor_name'    => $r['vendor_name'],
            'weight'         => (float)$r['received_weight'],
            'profit'         => (float)$r['profit_per_mt'],
            'pct'            => (float)$r['commission_pct'],
        ];
    }
}

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML = '<i class="bi bi-percent me-2"></i>Agent Commissions';</script>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-warning h-100">
            <div class="card-body text-center py-3">
                <div class="fs-1 fw-bold text-warning"><?= number_format($totals['pend_count']) ?></div>
                <div class="text-muted small">Pending Challans</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-danger h-100">
            <div class="card-body text-center py-3">
                <div class="fs-4 fw-bold text-danger">₹<?= number_format($totals['pend_amt'],2) ?></div>
                <div class="text-muted small">Pending Amount</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body text-center py-3">
                <div class="fs-1 fw-bold text-success"><?= number_format($totals['paid_count']) ?></div>
                <div class="text-muted small">Paid Challans</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-success h-100">
            <div class="card-body text-center py-3">
                <div class="fs-4 fw-bold text-success">₹<?= number_format($totals['paid_amt'],2) ?></div>
                <div class="text-muted small">Total Paid</div>
            </div>
        </div>
    </div>
</div>

<!-- Pending Commissions -->
<div class="card mb-4">
    <div class="card-header fw-bold">
        <i class="bi bi-clock-history me-2 text-warning"></i>Pending Commissions
    </div>
    <div class="card-body p-0">
    <?php if (empty($agents)): ?>
        <div class="p-4 text-center text-muted">No pending commissions.</div>
    <?php else: foreach ($agents as $ag):
        $ag_id   = (int)$ag['id'];
        $ag_rows = $pending[$ag_id] ?? [];
    ?>
    <div class="border-bottom">
        <!-- Agent header row -->
        <div class="d-flex justify-content-between align-items-center p-3 bg-light"
             style="cursor:pointer" onclick="toggleAgent(<?= $ag_id ?>)">
            <div>
                <i class="bi bi-person-circle me-2 text-primary"></i>
                <strong><?= htmlspecialchars($ag['full_name']) ?></strong>
                <span class="badge bg-warning text-dark ms-2"><?= $ag['pending_count'] ?> challan(s)</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-danger fs-6">₹<?= number_format((float)$ag['pending_amt'],2) ?></span>
                <button type="button" class="btn btn-sm btn-success"
                    onclick="event.stopPropagation(); openPayModal(<?= $ag_id ?>,
                        '<?= htmlspecialchars($ag['full_name'], ENT_QUOTES) ?>',
                        <?= (float)$ag['pending_amt'] ?>)">
                    <i class="bi bi-cash-coin me-1"></i>Record Payment
                </button>
                <i class="bi bi-chevron-down" id="chevron-<?= $ag_id ?>"></i>
            </div>
        </div>
        <!-- Detail rows -->
        <div id="agent-<?= $ag_id ?>" style="display:none">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th><input type="checkbox" onclick="toggleAllAgent(<?= $ag_id ?>, this)" title="Select all"></th>
                    <th>Challan No</th>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th class="text-end">Weight (MT)</th>
                    <th class="text-end">Vendor Rate</th>
                    <th class="text-end">Trans Rate</th>
                    <th class="text-end">Profit/MT</th>
                    <th class="text-center">Slab</th>
                    <th class="text-end">Comm %</th>
                    <th class="text-end">Commission ₹</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ag_rows as $r): ?>
            <tr>
                <td><input type="checkbox" class="agent-chk-<?= $ag_id ?>"
                    value="<?= $r['id'] ?>" data-amt="<?= $r['commission_amt'] ?>"
                    onchange="updateSelAmt(<?= $ag_id ?>)"></td>
                <td>
                    <a href="despatch.php?action=edit&id=<?= $r['despatch_id'] ?>" target="_blank">
                        <?= htmlspecialchars($r['challan_no']) ?>
                    </a>
                </td>
                <td><?= $r['despatch_date'] ? date('d-m-Y', strtotime($r['despatch_date'])) : '-' ?></td>
                <td><small><?= htmlspecialchars($r['vendor_name']) ?></small></td>
                <td class="text-end"><?= number_format((float)$r['received_weight'],3) ?></td>
                <td class="text-end">₹<?= number_format((float)$r['vendor_rate'],2) ?></td>
                <td class="text-end">₹<?= number_format((float)$r['transporter_rate'],2) ?></td>
                <td class="text-end">₹<?= number_format((float)$r['profit_per_mt'],2) ?></td>
                <td class="text-center">
                    <span class="badge <?= $r['slab_applied']==1 ? 'bg-info' : 'bg-primary' ?>">
                        Slab <?= $r['slab_applied'] ?>
                    </span>
                </td>
                <td class="text-end"><?= $r['commission_pct'] ?>%</td>
                <td class="text-end fw-bold text-danger">₹<?= number_format((float)$r['commission_amt'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="10" class="text-end fw-bold">Total Pending:</td>
                    <td class="text-end fw-bold text-danger">
                        ₹<?= number_format(array_sum(array_column($ag_rows,'commission_amt')),2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- Payment History -->
<div class="card">
    <div class="card-header fw-bold">
        <i class="bi bi-receipt me-2 text-success"></i>Payment History
    </div>
    <div class="card-body p-0">
    <?php if (empty($pay_by_agent)): ?>
        <div class="p-4 text-center text-muted">No payments recorded yet.</div>
    <?php else: foreach ($pay_by_agent as $aid => $ag): ?>
    <div class="border-bottom">
        <!-- Agent header -->
        <div class="d-flex justify-content-between align-items-center p-3 bg-light"
             style="cursor:pointer" onclick="togglePayHistory(<?= $aid ?>)">
            <div>
                <i class="bi bi-person-circle me-2 text-success"></i>
                <strong><?= htmlspecialchars($ag['full_name']) ?></strong>
                <span class="badge bg-secondary ms-2"><?= count($ag['payments']) ?> payment(s)</span>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="fw-bold text-success">₹<?= number_format($ag['total'],2) ?> total paid</span>
                <i class="bi bi-chevron-down" id="ph-chevron-<?= $aid ?>"></i>
            </div>
        </div>
        <!-- Payments for this agent -->
        <div id="ph-agent-<?= $aid ?>" style="display:none">
        <?php foreach ($ag['payments'] as $p): ?>
        <div class="border-top ms-3 me-3 mt-2 mb-2">
            <!-- Payment header -->
            <div class="d-flex justify-content-between align-items-center py-2 px-2 rounded bg-white">
                <div>
                    <i class="bi bi-cash-coin me-1 text-success"></i>
                    <strong><?= date('d-m-Y', strtotime($p['paid_date'])) ?></strong>
                    <?php if ($p['reference']): ?>
                    <span class="badge bg-light text-dark border ms-2"><?= htmlspecialchars($p['reference']) ?></span>
                    <?php endif; ?>
                    <?php if ($p['notes']): ?>
                    <small class="text-muted ms-2"><?= htmlspecialchars($p['notes']) ?></small>
                    <?php endif; ?>
                </div>
                <span class="fw-bold text-success fs-6">₹<?= number_format($p['amount'],2) ?></span>
            </div>
            <!-- Challan breakdown -->
            <?php if (!empty($p['challans'])): ?>
            <div class="table-responsive ms-2">
            <table class="table table-sm table-borderless mb-1">
                <thead><tr class="text-muted" style="font-size:0.78rem">
                    <th>Challan No</th><th>Date</th><th>Vendor</th>
                    <th class="text-end">Weight</th><th class="text-end">Profit/MT</th>
                    <th class="text-end">Comm%</th><th class="text-end">Commission</th>
                </tr></thead>
                <tbody>
                <?php foreach ($p['challans'] as $ch): ?>
                <tr style="font-size:0.82rem">
                    <td><span class="text-primary fw-semibold"><?= htmlspecialchars($ch['challan_no']) ?></span></td>
                    <td><?= $ch['despatch_date'] ? date('d-m-Y', strtotime($ch['despatch_date'])) : '-' ?></td>
                    <td><small><?= htmlspecialchars($ch['vendor_name']) ?></small></td>
                    <td class="text-end"><?= number_format($ch['weight'],3) ?> MT</td>
                    <td class="text-end">₹<?= number_format($ch['profit'],2) ?></td>
                    <td class="text-end"><?= $ch['pct'] ?>%</td>
                    <td class="text-end fw-semibold text-success">₹<?= number_format($ch['commission_amt'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="border-top">
                    <td colspan="6" class="text-end fw-bold text-muted" style="font-size:0.82rem">Total Commission:</td>
                    <td class="text-end fw-bold text-success">₹<?= number_format(array_sum(array_column($p['challans'],'commission_amt')),2) ?></td>
                </tr></tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Record Commission Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="payAgentId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Agent</label>
                    <div id="payAgentName" class="form-control bg-light"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Selected Challans</label>
                    <div id="payChallansInfo" class="form-text text-muted"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Amount to Pay (₹)</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" id="payAmount" class="form-control fw-bold" step="0.01">
                    </div>
                    <div class="form-text">Auto-calculated from selected challans. Edit if partial payment.</div>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Payment Date *</label>
                        <input type="date" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Reference / Cheque No</label>
                        <input type="text" id="payRef" class="form-control" placeholder="Optional">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Notes</label>
                    <textarea id="payNotes" class="form-control" rows="2" placeholder="Optional"></textarea>
                </div>
                <div class="alert alert-warning mt-3 py-2 mb-0" id="payNoSelect" style="display:none">
                    <i class="bi bi-exclamation-triangle me-1"></i>Please select at least one challan.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitPayment()">
                    <i class="bi bi-check-circle me-1"></i>Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAgent(id) {
    var el  = document.getElementById('agent-' + id);
    var chv = document.getElementById('chevron-' + id);
    var open = el.style.display === 'none';
    el.style.display = open ? 'block' : 'none';
    chv.className    = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}

function toggleAllAgent(agId, master) {
    document.querySelectorAll('.agent-chk-' + agId).forEach(function(cb) {
        cb.checked = master.checked;
    });
    updateSelAmt(agId);
}

function updateSelAmt(agId) {
    var openId = parseInt(document.getElementById('payAgentId').value || '0');
    if (openId !== agId) return;
    var total = 0, ids = [];
    document.querySelectorAll('.agent-chk-' + agId + ':checked').forEach(function(cb) {
        total += parseFloat(cb.dataset.amt || 0);
        ids.push(cb.value);
    });
    document.getElementById('payAmount').value = total.toFixed(2);
    document.getElementById('payChallansInfo').textContent =
        ids.length + ' challan(s) selected — ₹' + total.toFixed(2);
}

function openPayModal(agId, agName, totalAmt) {
    document.getElementById('payAgentId').value      = agId;
    document.getElementById('payAgentName').textContent = agName;
    document.getElementById('payNoSelect').style.display = 'none';
    // Select all checkboxes for this agent
    document.querySelectorAll('.agent-chk-' + agId).forEach(function(cb) { cb.checked = true; });
    var count = document.querySelectorAll('.agent-chk-' + agId).length;
    document.getElementById('payAmount').value = parseFloat(totalAmt).toFixed(2);
    document.getElementById('payChallansInfo').textContent =
        count + ' challan(s) selected — ₹' + parseFloat(totalAmt).toFixed(2);
    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function submitPayment() {
    var agId  = parseInt(document.getElementById('payAgentId').value);
    var ids   = [];
    var total = 0;
    document.querySelectorAll('.agent-chk-' + agId + ':checked').forEach(function(cb) {
        ids.push(cb.value);
        total += parseFloat(cb.dataset.amt || 0);
    });
    if (!ids.length) {
        document.getElementById('payNoSelect').style.display = 'block';
        return;
    }
    var amount = parseFloat(document.getElementById('payAmount').value) || total;
    var date   = document.getElementById('payDate').value;
    var ref    = document.getElementById('payRef').value;
    var notes  = document.getElementById('payNotes').value;
    if (!date) { alert('Please enter payment date.'); return; }

    var fd = new FormData();
    fd.append('ajax_pay', '1');
    fd.append('agent_id', agId);
    fd.append('amount',   amount);
    fd.append('paid_date', date);
    fd.append('reference', ref);
    fd.append('notes',     notes);
    ids.forEach(function(id) { fd.append('commission_ids[]', id); });

    fetch('agent_commissions.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(function(res) {
            if (res.ok) {
                // Safe modal hide
                var modalEl = document.getElementById('payModal');
                var modalInst = bootstrap.Modal.getInstance(modalEl);
                if (modalInst) modalInst.hide();
                if (res.status === 'partial') {
                    var bal = (parseFloat(res.total_due) - parseFloat(res.paid)).toFixed(2);
                    setTimeout(function() {
                        alert('Partial payment of \u20b9' + parseFloat(res.paid).toFixed(2) + ' recorded successfully.\nBalance \u20b9' + bal + ' remains pending.');
                        location.reload();
                    }, 300);
                } else {
                    location.reload();
                }
            } else {
                alert('Error: ' + (res.msg || 'Unknown'));
            }
        })
        .catch(function(err) {
            alert('Network error: ' + err.message);
        });
}

function togglePayHistory(aid) {
    var el  = document.getElementById('ph-agent-' + aid);
    var chv = document.getElementById('ph-chevron-' + aid);
    var open = el.style.display === 'none';
    el.style.display = open ? 'block' : 'none';
    chv.className    = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}

function toggleHistory() {
    var sec = document.getElementById('historySection');
    var btn = document.getElementById('histBtn');
    var open = sec.style.display === 'none';
    sec.style.display = open ? 'block' : 'none';
    btn.textContent   = open ? 'Hide History' : 'Show History';
}
</script>
<?php include '../includes/footer.php'; ?>
