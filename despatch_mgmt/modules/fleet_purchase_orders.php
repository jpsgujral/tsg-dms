<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_purchase_orders', 'view');

/* ── Tables ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_purchase_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    po_number       VARCHAR(30) NOT NULL UNIQUE,
    po_date         DATE NOT NULL,
    vendor_id       INT NOT NULL,
    validity_date   DATE DEFAULT NULL,
    delivery_address TEXT,
    payment_terms   VARCHAR(120) DEFAULT '',
    subtotal        DECIMAL(12,2) DEFAULT 0,
    total_amount    DECIMAL(12,2) DEFAULT 0,
    status          ENUM('Draft','Approved','Partially Received','Received','Cancelled') DEFAULT 'Draft',
    company_id      INT DEFAULT 1,
    remarks         TEXT,
    created_by      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_po_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    po_id       INT NOT NULL,
    item_name   VARCHAR(200) NOT NULL,
    description VARCHAR(200) DEFAULT '',
    uom         VARCHAR(20) DEFAULT 'MT',
    qty         DECIMAL(12,3) DEFAULT 0,
    unit_price  DECIMAL(12,2) DEFAULT 0,
    amount      DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── PO number generator (FY-aware) ── */
function generateFleetPONo($db) {
    $month    = (int)date('m');
    $year     = (int)date('Y');
    $fy_start = $month >= 4 ? $year : $year - 1;
    $fy_end   = $fy_start + 1;
    $fy_label = ($fy_start % 100) . '-' . str_pad($fy_end % 100, 2, '0', STR_PAD_LEFT);
    $prefix   = "FPO/$fy_label/";
    $db->query("LOCK TABLES fleet_purchase_orders WRITE");
    $row  = $db->query("SELECT po_number FROM fleet_purchase_orders WHERE po_number LIKE '$prefix%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $next = $row ? (int)substr($row['po_number'], strrpos($row['po_number'], '/') + 1) + 1 : 1;
    $po_no = $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    $db->query("UNLOCK TABLES");
    return $po_no;
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $refs = [];
    $r = $db->query("SELECT COUNT(*) c FROM fleet_trips WHERE po_id=$did");
    if ($r && $r->fetch_assoc()['c'] > 0) $refs[] = 'Trip Orders';
    if (!empty($refs)) {
        showAlert('danger', 'Cannot delete — PO has linked records in: ' . implode(', ', $refs) . '. Cancel the PO instead.');
    } else {
        $db->query("DELETE FROM fleet_po_items WHERE po_id=$did");
        $db->query("DELETE FROM fleet_purchase_orders WHERE id=$did");
        showAlert('success', 'PO deleted.');
    }
    redirect('fleet_purchase_orders.php');
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_po'])) {
    requirePerm('fleet_purchase_orders', $id > 0 ? 'update' : 'create');
    $po_no    = $id > 0 ? sanitize($_POST['po_number']) : generateFleetPONo($db);
    $po_date  = sanitize($_POST['po_date']);
    $vend_id  = (int)$_POST['vendor_id'];
    $val_date = sanitize($_POST['validity_date'] ?? '');
    $del_addr = sanitize($_POST['delivery_address'] ?? '');
    $pay_terms= sanitize($_POST['payment_terms'] ?? '');
    $status   = sanitize($_POST['status'] ?? 'Draft');
    $remarks  = sanitize($_POST['remarks'] ?? '');
    $co_id    = (int)($_POST['company_id'] ?? activeCompanyId());
    $val_sql  = $val_date ? "'$val_date'" : 'NULL';

    $item_names = $_POST['item_name']  ?? [];
    $item_descs = $_POST['item_desc']  ?? [];
    $item_uoms  = $_POST['item_uom']   ?? [];
    $item_qtys  = $_POST['item_qty']   ?? [];
    $item_prices= $_POST['item_price'] ?? [];

    $subtotal = 0;
    $valid_items = [];
    foreach ($item_names as $idx => $iname) {
        $iname = trim($iname);
        if (!$iname) continue;
        $qty   = (float)($item_qtys[$idx]   ?? 0);
        $price = (float)($item_prices[$idx] ?? 0);
        $amt   = round($qty * $price, 2);
        $subtotal += $amt;
        $valid_items[] = [
            'item_name'  => $db->real_escape_string($iname),
            'description'=> $db->real_escape_string($item_descs[$idx] ?? ''),
            'uom'        => $db->real_escape_string($item_uoms[$idx] ?? 'MT'),
            'qty'        => $qty,
            'unit_price' => $price,
            'amount'     => $amt,
        ];
    }

    if (!$po_date || !$vend_id) {
        showAlert('danger', 'PO Date and Customer are required.');
        redirect("fleet_purchase_orders.php?action=".($id>0?"edit&id=$id":'add'));
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_purchase_orders SET
            po_date='$po_date', vendor_id=$vend_id, validity_date=$val_sql,
            delivery_address='$del_addr', payment_terms='$pay_terms',
            subtotal=$subtotal, total_amount=$subtotal, status='$status', remarks='$remarks'
            WHERE id=$id");
        $db->query("DELETE FROM fleet_po_items WHERE po_id=$id");
    } else {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO fleet_purchase_orders
            (po_number,po_date,vendor_id,validity_date,delivery_address,payment_terms,
             subtotal,total_amount,status,remarks,created_by)
            VALUES ('$po_no','$po_date',$vend_id,$val_sql,'$del_addr','$pay_terms',
            $subtotal,$subtotal,'$status','$remarks',$uid)");
        $id = $db->insert_id;
    }

    foreach ($valid_items as $row) {
        $db->query("INSERT INTO fleet_po_items (po_id,item_name,description,uom,qty,unit_price,amount)
            VALUES ($id,'{$row['item_name']}','{$row['description']}','{$row['uom']}',
            {$row['qty']},{$row['unit_price']},{$row['amount']})");
    }

    showAlert('success', $id > 0 ? 'PO updated.' : 'PO created.');
    redirect('fleet_purchase_orders.php?action=view&id='.$id);
}

$vendors = $db->query("SELECT id,vendor_name FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$all_companies = getAllCompanies();

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-file-earmark-text me-2"></i>Fleet Sales Orders (Customer POs)';</script>
<?php
$status_colors = ['Draft'=>'secondary','Approved'=>'success','Partially Received'=>'info','Received'=>'primary','Cancelled'=>'danger'];

/* ── LIST ── */
if ($action === 'list'):
$pos = $db->query("SELECT p.*, v.vendor_name, co.company_name FROM fleet_purchase_orders p
    LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
    LEFT JOIN companies co ON p.company_id=co.id
    ORDER BY p.po_date DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);
$counts = [];
foreach ($pos as $p) $counts[$p['status']] = ($counts[$p['status']] ?? 0) + 1;
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fleet Sales Orders (Customer POs)</h5>
    <?php if (canDo('fleet_purchase_orders','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>New PO</a>
    <?php endif; ?>
</div>
<!-- Filter -->
<div class="card mb-3">
<div class="card-body py-2 d-flex gap-1 flex-wrap">
    <button class="btn btn-sm btn-dark fpo-filter active" data-status="All" onclick="fpoFilter('All',this)">All <span class="badge bg-secondary ms-1"><?= count($pos) ?></span></button>
    <?php foreach ($status_colors as $st => $sc): if (!isset($counts[$st])) continue; ?>
    <button class="btn btn-sm btn-outline-<?= $sc ?> fpo-filter" data-status="<?= $st ?>" onclick="fpoFilter('<?= $st ?>',this)">
        <?= $st ?> <span class="badge bg-<?= $sc ?> ms-1"><?= $counts[$st] ?></span>
    </button>
    <?php endforeach; ?>
</div>
</div>
<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0" id="fpoTable">
<thead><tr>
    <th>PO No</th><th>Date</th><th>Company</th><th>Customer / Buyer</th><th>Payment Terms</th>
    <th class="text-end">Amount</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($pos as $p):
    $sc = $status_colors[$p['status']] ?? 'secondary';
?>
<tr data-status="<?= $p['status'] ?>">
    <td><strong><?= htmlspecialchars($p['po_number']) ?></strong></td>
    <td><?= date('d/m/Y', strtotime($p['po_date'])) ?></td>
    <td><?php if (count($all_companies)>1): ?><span class='badge bg-primary' style='font-size:.7rem'><?= htmlspecialchars($p['company_name']??'-') ?></span><?php else: ?>—<?php endif; ?></td>
    <td><?= htmlspecialchars($p['vendor_name'] ?? '—') ?></td>
    <td><?= htmlspecialchars($p['payment_terms'] ?: '—') ?></td>
    <td class="text-end">₹<?= number_format($p['total_amount'], 2) ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $p['status'] ?></span></td>
    <td>
        <a href="?action=view&id=<?= $p['id'] ?>" class="btn btn-action btn-outline-info me-1"><i class="bi bi-eye"></i></a>
        <?php if (canDo('fleet_purchase_orders','update') && !in_array($p['status'],['Received','Cancelled'])): ?>
        <a href="?action=edit&id=<?= $p['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?delete=<?= $p['id'] ?>" onclick="return confirm('Delete PO <?= htmlspecialchars(addslashes($p['po_number'])) ?>?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>
<style>.fpo-filter.active{box-shadow:0 0 0 2px rgba(30,58,95,.5)}</style>
<script>
function fpoFilter(status, btn) {
    document.querySelectorAll('.fpo-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#fpoTable tbody tr').forEach(r => {
        r.style.display = (status === 'All' || r.dataset.status === status) ? '' : 'none';
    });
}
</script>

<?php
/* ── VIEW ── */
elseif ($action === 'view' && $id > 0):
$po = $db->query("SELECT p.*, v.vendor_name, v.address AS v_address, v.city AS v_city,
    v.gstin AS v_gstin, v.phone AS v_phone
    FROM fleet_purchase_orders p LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
    WHERE p.id=$id LIMIT 1")->fetch_assoc();
if (!$po) { echo '<div class="alert alert-danger">PO not found.</div>'; include '../includes/footer.php'; exit; }
$items = $db->query("SELECT * FROM fleet_po_items WHERE po_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$sc = $status_colors[$po['status']] ?? 'secondary';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">PO: <?= htmlspecialchars($po['po_number']) ?>
        <span class="badge bg-<?= $sc ?> ms-2"><?= $po['status'] ?></span>
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($po['status'] === 'Draft' && canDo('fleet_purchase_orders','update')): ?>
        <a href="?approve=<?= $id ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this PO?')"><i class="bi bi-check-circle me-1"></i>Approve</a>
        <?php endif; ?>
        <?php if (canDo('fleet_purchase_orders','update') && !in_array($po['status'],['Received','Cancelled'])): ?>
        <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="fleet_purchase_orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>
<?php
// Handle approve
if (isset($_GET['approve']) && canDo('fleet_purchase_orders','update')) {
    $db->query("UPDATE fleet_purchase_orders SET status='Approved' WHERE id=$id AND status='Draft'");
    showAlert('success','PO Approved.');
    redirect('fleet_purchase_orders.php?action=view&id='.$id);
}
?>
<div class="row g-3">
<div class="col-12 col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-info-circle me-2"></i>PO Details</div>
<div class="card-body"><table class="table table-sm mb-0">
    <tr><td class="text-muted" style="width:45%">Company</td><td><?php if (count($all_companies)>1): ?><span class="badge bg-primary"><?= htmlspecialchars($po['company_name']??'—') ?></span><?php endif; ?></td></tr>
    <tr><td class="text-muted">PO Number</td><td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td></tr>
    <tr><td class="text-muted">PO Date</td><td><?= date('d/m/Y',strtotime($po['po_date'])) ?></td></tr>
    <tr><td class="text-muted">Validity</td><td><?= $po['validity_date'] ? date('d/m/Y',strtotime($po['validity_date'])) : '—' ?></td></tr>
    <tr><td class="text-muted">Payment Terms</td><td><?= htmlspecialchars($po['payment_terms'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">Delivery Address</td><td><?= htmlspecialchars($po['delivery_address'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">Total Amount</td><td><strong>₹<?= number_format($po['total_amount'],2) ?></strong></td></tr>
</table></div></div></div>
<div class="col-12 col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Customer / Buyer</div>
<div class="card-body"><table class="table table-sm mb-0">
    <tr><td class="text-muted" style="width:45%">Name</td><td><strong><?= htmlspecialchars($po['vendor_name']) ?></strong></td></tr>
    <tr><td class="text-muted">Phone</td><td><?= htmlspecialchars($po['v_phone'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">City</td><td><?= htmlspecialchars($po['v_city'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">GSTIN</td><td><?= htmlspecialchars($po['v_gstin'] ?: '—') ?></td></tr>
</table></div></div></div>
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-list-ul me-2"></i>Items</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<thead><tr><th>#</th><th>Item</th><th>Description</th><th>UOM</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr></thead>
<tbody>
<?php $ri=1; foreach ($items as $it): ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($it['item_name']) ?></td>
    <td><?= htmlspecialchars($it['description'] ?: '—') ?></td>
    <td><?= htmlspecialchars($it['uom']) ?></td>
    <td class="text-end"><?= number_format($it['qty'],3) ?></td>
    <td class="text-end">₹<?= number_format($it['unit_price'],2) ?></td>
    <td class="text-end"><strong>₹<?= number_format($it['amount'],2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light"><tr>
    <td colspan="6" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold">₹<?= number_format($po['total_amount'],2) ?></td>
</tr></tfoot>
</table>
</div></div></div></div>
<?php if ($po['remarks']): ?>
<div class="col-12"><div class="card"><div class="card-body"><strong>Remarks:</strong> <?= htmlspecialchars($po['remarks']) ?></div></div></div>
<?php endif; ?>
</div>

<?php
/* ── ADD / EDIT ── */
else:
$po = [];
$po_items = [];
if ($id > 0) {
    $po = $db->query("SELECT * FROM fleet_purchase_orders WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
    $po_items = $db->query("SELECT * FROM fleet_po_items WHERE po_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'New' ?> Customer PO</h5>
    <a href="fleet_purchase_orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
<input type="hidden" name="save_po" value="1">
<?php if ($id > 0): ?><input type="hidden" name="po_number" value="<?= htmlspecialchars($po['po_number']) ?>"><?php endif; ?>
<div class="row g-3">

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>PO Details</div>
<div class="card-body"><div class="row g-3">
    <?php if ($id > 0): ?>
    <div class="col-6 col-md-2">
        <label class="form-label">PO Number</label>
        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($po['po_number']) ?>" readonly>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">PO Date *</label>
        <input type="date" name="po_date" class="form-control" value="<?= $po['po_date'] ?? date('Y-m-d') ?>" required>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Customer / Buyer *</label>
        <select name="vendor_id" class="form-select" required>
            <option value="">— Select Customer —</option>
            <?php foreach ($vendors as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($po['vendor_id'] ?? 0) == $v['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($v['vendor_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Validity Date</label>
        <input type="date" name="validity_date" class="form-control" value="<?= $po['validity_date'] ?? '' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (['Draft','Approved','Partially Received','Received','Cancelled'] as $s): ?>
            <option <?= ($po['status'] ?? 'Draft') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Payment Terms</label>
        <input type="text" name="payment_terms" class="form-control" value="<?= htmlspecialchars($po['payment_terms'] ?? '') ?>" placeholder="e.g. 30 days">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Delivery Address</label>
        <input type="text" name="delivery_address" class="form-control" value="<?= htmlspecialchars($po['delivery_address'] ?? '') ?>">
    </div>
</div></div></div></div>

<!-- Items -->
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Items</span>
    <button type="button" class="btn btn-success btn-sm" onclick="addFpoRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0" id="fpoItemsTable">
<thead class="table-light"><tr>
    <th>Item Name</th><th>Description</th><th>UOM</th>
    <th style="width:100px">Qty</th><th style="width:110px">Rate (₹)</th>
    <th style="width:110px">Amount (₹)</th><th style="width:36px"></th>
</tr></thead>
<tbody id="fpoItemsBody">
<?php if ($po_items): foreach ($po_items as $it): ?>
<tr class="fpo-item-row">
    <td><input type="text" name="item_name[]" class="form-control form-control-sm" value="<?= htmlspecialchars($it['item_name']) ?>" required></td>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($it['description'] ?? '') ?>"></td>
    <td><select name="item_uom[]" class="form-select form-select-sm">
        <?php foreach (['MT','Kg','Litre','Nos','Bags','Set'] as $u): ?>
        <option <?= ($it['uom'] ?? 'MT') === $u ? 'selected' : '' ?>><?= $u ?></option>
        <?php endforeach; ?>
    </select></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm fpo-qty" step="0.001" value="<?= $it['qty'] ?>" onchange="calcFpoRow(this)"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm fpo-price" step="0.01" value="<?= $it['unit_price'] ?>" onchange="calcFpoRow(this)"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm fpo-amt bg-light" readonly value="<?= $it['amount'] ?>"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFpoRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endforeach; else: ?>
<tr class="fpo-item-row">
    <td><input type="text" name="item_name[]" class="form-control form-control-sm" required></td>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm"></td>
    <td><select name="item_uom[]" class="form-select form-select-sm">
        <?php foreach (['MT','Kg','Litre','Nos','Bags','Set'] as $u): ?><option><?= $u ?></option><?php endforeach; ?>
    </select></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm fpo-qty" step="0.001" onchange="calcFpoRow(this)"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm fpo-price" step="0.01" onchange="calcFpoRow(this)"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm fpo-amt bg-light" readonly></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFpoRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endif; ?>
</tbody>
<tfoot class="table-light"><tr>
    <td colspan="5" class="text-end fw-bold">Total</td>
    <td><strong id="fpoFootTotal">₹0.00</strong></td>
    <td></td>
</tr></tfoot>
</table>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Remarks</label>
    <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($po['remarks'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_purchase_orders.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save PO</button>
</div>
</div>
</form>
<script>
function addFpoRow() {
    var tbody = document.getElementById('fpoItemsBody');
    var tr = document.createElement('tr');
    tr.className = 'fpo-item-row';
    var uomOpts = ['MT','Kg','Litre','Nos','Bags','Set'].map(u => '<option>'+u+'</option>').join('');
    tr.innerHTML =
        '<td><input type="text" name="item_name[]" class="form-control form-control-sm" required></td>'+
        '<td><input type="text" name="item_desc[]" class="form-control form-control-sm"></td>'+
        '<td><select name="item_uom[]" class="form-select form-select-sm">'+uomOpts+'</select></td>'+
        '<td><input type="number" name="item_qty[]" class="form-control form-control-sm fpo-qty" step="0.001" onchange="calcFpoRow(this)"></td>'+
        '<td><input type="number" name="item_price[]" class="form-control form-control-sm fpo-price" step="0.01" onchange="calcFpoRow(this)"></td>'+
        '<td><input type="text" name="item_amount[]" class="form-control form-control-sm fpo-amt bg-light" readonly></td>'+
        '<td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFpoRow(this)"><i class="bi bi-x"></i></button></td>';
    tbody.appendChild(tr);
}
function removeFpoRow(btn) {
    var rows = document.querySelectorAll('.fpo-item-row');
    if (rows.length > 1) { btn.closest('tr').remove(); calcFpoTotal(); }
}
function calcFpoRow(el) {
    var tr  = el.closest('tr');
    var qty = parseFloat(tr.querySelector('.fpo-qty').value)   || 0;
    var prc = parseFloat(tr.querySelector('.fpo-price').value) || 0;
    tr.querySelector('.fpo-amt').value = (qty * prc).toFixed(2);
    calcFpoTotal();
}
function calcFpoTotal() {
    var total = 0;
    document.querySelectorAll('.fpo-amt').forEach(function(el) { total += parseFloat(el.value) || 0; });
    document.getElementById('fpoFootTotal').textContent = '₹' + total.toFixed(2);
}
calcFpoTotal();
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
