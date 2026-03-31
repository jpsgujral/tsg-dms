<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
if (file_exists('../includes/r2_helper.php')) require_once '../includes/r2_helper.php';
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
    gst_type        ENUM('IGST','CGST+SGST') DEFAULT 'IGST',
    subtotal        DECIMAL(12,2) DEFAULT 0,
    gst_amount      DECIMAL(12,2) DEFAULT 0,
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
    uom         VARCHAR(20) DEFAULT 'MT',
    qty         DECIMAL(12,3) DEFAULT 0,
    unit_price  DECIMAL(12,2) DEFAULT 0,
    gst_rate    DECIMAL(5,2) DEFAULT 0,
    gst_amount  DECIMAL(12,2) DEFAULT 0,
    amount      DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Auto-migrate: add gst_type, gst_amount to fleet_purchase_orders ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_purchase_orders'")->fetch_all(MYSQLI_ASSOC);
    $existing = array_column($cols, 'COLUMN_NAME');
    if (!in_array('gst_type',   $existing)) $db->query("ALTER TABLE fleet_purchase_orders ADD COLUMN gst_type ENUM('IGST','CGST+SGST') DEFAULT 'IGST' AFTER payment_terms");
    if (!in_array('gst_amount', $existing)) $db->query("ALTER TABLE fleet_purchase_orders ADD COLUMN gst_amount DECIMAL(12,2) DEFAULT 0 AFTER subtotal");
})($db);

/* ── Auto-migrate: add gst_rate, gst_amount to fleet_po_items, drop description ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_po_items'")->fetch_all(MYSQLI_ASSOC);
    $existing = array_column($cols, 'COLUMN_NAME');
    if (!in_array('gst_rate',   $existing)) $db->query("ALTER TABLE fleet_po_items ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT 0 AFTER unit_price");
    if (!in_array('gst_amount', $existing)) $db->query("ALTER TABLE fleet_po_items ADD COLUMN gst_amount DECIMAL(12,2) DEFAULT 0 AFTER gst_rate");
    // Drop description column if exists (no longer needed)
    if (in_array('description', $existing)) $db->query("ALTER TABLE fleet_po_items DROP COLUMN description");
})($db);

/* ── Add company_id if upgrading ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_purchase_orders'
        AND COLUMN_NAME='company_id' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_purchase_orders ADD COLUMN company_id INT DEFAULT 1");
})($db);

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
/* ── Auto-add po_document column if missing ── */
(function($db){
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='fleet_purchase_orders'
        AND COLUMN_NAME='po_document' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE fleet_purchase_orders ADD COLUMN po_document VARCHAR(255) DEFAULT NULL");
})($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_po'])) {
    requirePerm('fleet_purchase_orders', $id > 0 ? 'update' : 'create');
    $po_no    = sanitize($_POST['po_number'] ?? '');
    $po_date  = sanitize($_POST['po_date']);
    if (!$po_no) {
        showAlert('danger', 'PO Number is required.');
        redirect("fleet_purchase_orders.php?action=".($id>0?"edit&id=$id":'add'));
    }
    $vend_id  = (int)$_POST['vendor_id'];
    $val_date = sanitize($_POST['validity_date'] ?? '');
    $del_addr = sanitize($_POST['delivery_address'] ?? '');
    $pay_terms= sanitize($_POST['payment_terms'] ?? '');
    $gst_type = in_array($_POST['gst_type'] ?? '', ['IGST','CGST+SGST']) ? $_POST['gst_type'] : 'IGST';
    $status   = sanitize($_POST['status'] ?? 'Draft');
    $remarks  = sanitize($_POST['remarks'] ?? '');
    $co_id    = (int)($_POST['company_id'] ?? activeCompanyId());
    $val_sql  = $val_date ? "'$val_date'" : 'NULL';

    $item_names = $_POST['item_name']  ?? [];
    $item_uoms  = $_POST['item_uom']   ?? [];
    $item_qtys  = $_POST['item_qty']   ?? [];
    $item_prices= $_POST['item_price'] ?? [];
    $item_gsts  = $_POST['item_gst']   ?? [];

    $subtotal  = 0;
    $gst_total = 0;
    $valid_items = [];
    foreach ($item_names as $idx => $iname) {
        $iname = trim($iname);
        if (!$iname) continue;
        $qty      = (float)($item_qtys[$idx]   ?? 0);
        $price    = (float)($item_prices[$idx] ?? 0);
        $gst_rate = (float)($item_gsts[$idx]   ?? 0);
        $base     = round($qty * $price, 2);
        $gst_amt  = round($base * $gst_rate / 100, 2);
        $amt      = $base + $gst_amt;
        $subtotal  += $base;
        $gst_total += $gst_amt;
        $valid_items[] = [
            'item_name' => $db->real_escape_string($iname),
            'uom'       => $db->real_escape_string($item_uoms[$idx] ?? 'MT'),
            'qty'       => $qty,
            'unit_price'=> $price,
            'gst_rate'  => $gst_rate,
            'gst_amount'=> $gst_amt,
            'amount'    => $amt,
        ];
    }
    $grand_total = $subtotal + $gst_total;

    if (!$po_date || !$vend_id) {
        showAlert('danger', 'PO Date and Customer are required.');
        redirect("fleet_purchase_orders.php?action=".($id>0?"edit&id=$id":'add'));
    }

    /* ── Handle PO document upload via R2 ── */
    $po_doc_sql = '';
    $old_po_doc = $id > 0 ? ($db->query("SELECT po_document FROM fleet_purchase_orders WHERE id=$id LIMIT 1")->fetch_assoc()['po_document'] ?? '') : '';
    if (!empty($_POST['delete_po_document']) && $old_po_doc) {
        r2_delete($old_po_doc);
        $po_doc_sql = ", po_document=NULL";
        $old_po_doc = '';
    }
    $new_key = r2_handle_upload('po_document', 'po_docs/po', $old_po_doc);
    if ($new_key) {
        $po_doc_sql = ", po_document='".$db->real_escape_string($new_key)."'";
        $doc_path   = $new_key;
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_purchase_orders SET
            po_number='$po_no', po_date='$po_date', vendor_id=$vend_id, validity_date=$val_sql,
            delivery_address='$del_addr', payment_terms='$pay_terms', gst_type='$gst_type',
            subtotal=$subtotal, gst_amount=$gst_total, total_amount=$grand_total,
            status='$status', remarks='$remarks'$po_doc_sql
            WHERE id=$id");
        $db->query("DELETE FROM fleet_po_items WHERE po_id=$id");
    } else {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $doc_col = $po_doc_sql ? ',po_document' : '';
        $doc_val = isset($doc_path) ? (",'".$db->real_escape_string($doc_path)."'") : '';
        $db->query("INSERT INTO fleet_purchase_orders
            (po_number,po_date,vendor_id,validity_date,delivery_address,payment_terms,gst_type,
             subtotal,gst_amount,total_amount,status,remarks,created_by,company_id$doc_col)
            VALUES ('$po_no','$po_date',$vend_id,$val_sql,'$del_addr','$pay_terms','$gst_type',
            $subtotal,$gst_total,$grand_total,'$status','$remarks',$uid,$co_id$doc_val)");
        $id = $db->insert_id;
    }

    foreach ($valid_items as $row) {
        $db->query("INSERT INTO fleet_po_items (po_id,item_name,uom,qty,unit_price,gst_rate,gst_amount,amount)
            VALUES ($id,'{$row['item_name']}','{$row['uom']}',
            {$row['qty']},{$row['unit_price']},{$row['gst_rate']},{$row['gst_amount']},{$row['amount']})");
    }

    showAlert('success', $id > 0 ? 'PO updated.' : 'PO created.');
    redirect('fleet_purchase_orders.php?action=view&id='.$id);
}

/* ── Load customer data for JS auto-fill ── */
$vendors_raw = $db->query("SELECT id, vendor_name, ship_address, ship_city, ship_state, ship_pincode,
    ship_name, ship_gstin FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$vendors = $vendors_raw;

/* ── Load items master for JS auto-fill ── */
$items_master = $db->query("SELECT id, item_name, uom FROM items WHERE status='Active' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);

$all_companies = getAllCompanies();

include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-file-earmark-text me-2"></i>Fleet Sales Orders (Customer POs)';</script>
<?php
$status_colors = ['Draft'=>'secondary','Approved'=>'success','Partially Received'=>'info','Received'=>'primary','Cancelled'=>'danger'];

/* ── LIST ── */
if ($action === 'list'):
$pos = $db->query("SELECT p.*, v.vendor_name, co.company_name,
    COALESCE((SELECT SUM(pi.qty) FROM fleet_po_items pi WHERE pi.po_id=p.id),0) AS po_qty,
    COALESCE((SELECT SUM(ti.weight) FROM fleet_trip_items ti
              JOIN fleet_trips t ON ti.trip_id=t.id
              WHERE t.po_id=p.id AND t.status NOT IN ('Cancelled')),0) AS despatched_qty
    FROM fleet_purchase_orders p
    LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
    LEFT JOIN companies co ON p.company_id=co.id
    ORDER BY co.company_name ASC, p.po_date DESC, p.id DESC")->fetch_all(MYSQLI_ASSOC);

$grouped = [];
foreach ($pos as $p) {
    $key = $p['company_name'] ?? 'Unassigned';
    $grouped[$key][] = $p;
}
$counts = [];
foreach ($pos as $p) $counts[$p['status']] = ($counts[$p['status']] ?? 0) + 1;
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Fleet Sales Orders (Customer POs)</h5>
    <?php if (canDo('fleet_purchase_orders','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>New PO</a>
    <?php endif; ?>
</div>
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

<?php foreach ($grouped as $companyName => $list):
    $grpTotal = array_sum(array_column($list, 'total_amount'));
?>
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center py-2">
    <span><i class="bi bi-buildings me-2"></i><strong><?= htmlspecialchars($companyName) ?></strong>
        <span class="badge bg-secondary ms-2"><?= count($list) ?> PO<?= count($list)>1?'s':'' ?></span>
    </span>
    <span class="text-white fw-semibold">₹<?= number_format($grpTotal,2) ?></span>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0 fpo-table">
<thead><tr>
    <th>PO No</th><th>Date</th><th>Customer / Buyer</th><th>Payment Terms</th>
    <th class="text-end">PO Qty (MT)</th><th class="text-end">Despatched (MT)</th><th class="text-end">Balance (MT)</th>
    <th class="text-end">Amount</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($list as $p):
    $sc = $status_colors[$p['status']] ?? 'secondary';
    $po_qty   = (float)($p['po_qty'] ?? 0);
    $desp_qty = (float)($p['despatched_qty'] ?? 0);
    $bal_qty  = $po_qty - $desp_qty;
    $bal_color = $bal_qty <= 0 ? 'text-success' : ($desp_qty > 0 ? 'text-warning' : 'text-muted');
?>
<tr data-status="<?= $p['status'] ?>">
    <td><strong><?= htmlspecialchars($p['po_number']) ?></strong></td>
    <td><?= date('d/m/Y', strtotime($p['po_date'])) ?></td>
    <td><?= htmlspecialchars($p['vendor_name'] ?? '—') ?></td>
    <td><?= htmlspecialchars($p['payment_terms'] ?: '—') ?></td>
    <td class="text-end"><?= number_format($po_qty, 3) ?></td>
    <td class="text-end"><?= number_format($desp_qty, 3) ?></td>
    <td class="text-end fw-bold <?= $bal_color ?>"><?= number_format($bal_qty, 3) ?></td>
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
<?php endforeach; ?>

<style>.fpo-filter.active{box-shadow:0 0 0 2px rgba(30,58,95,.5)}</style>
<script>
function fpoFilter(status, btn) {
    document.querySelectorAll('.fpo-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.fpo-table tbody tr').forEach(r => {
        r.style.display = (status === 'All' || r.dataset.status === status) ? '' : 'none';
    });
    document.querySelectorAll('.fpo-table').forEach(tbl => {
        var card = tbl.closest('.card');
        var visible = Array.from(tbl.querySelectorAll('tbody tr')).some(r => r.style.display !== 'none');
        if (card) card.style.display = visible ? '' : 'none';
    });
}
</script>

<?php
/* ── VIEW ── */
elseif ($action === 'view' && $id > 0):
$po = $db->query("SELECT p.*, v.vendor_name, v.city AS v_city, v.gstin AS v_gstin, v.phone AS v_phone, co.company_name
    FROM fleet_purchase_orders p
    LEFT JOIN fleet_customers_master v ON p.vendor_id=v.id
    LEFT JOIN companies co ON p.company_id=co.id
    WHERE p.id=$id LIMIT 1")->fetch_assoc();
if (!$po) { echo '<div class="alert alert-danger">PO not found.</div>'; include '../includes/footer.php'; exit; }
$items = $db->query("SELECT * FROM fleet_po_items WHERE po_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$sc = $status_colors[$po['status']] ?? 'secondary';
$is_igst = ($po['gst_type'] ?? 'IGST') === 'IGST';
if (isset($_GET['approve']) && canDo('fleet_purchase_orders','update')) {
    $db->query("UPDATE fleet_purchase_orders SET status='Approved' WHERE id=$id AND status='Draft'");
    showAlert('success','PO Approved.');
    redirect('fleet_purchase_orders.php?action=view&id='.$id);
}
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
<div class="row g-3">
<div class="col-12 col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-info-circle me-2"></i>PO Details</div>
<div class="card-body"><table class="table table-sm mb-0">
    <?php if (count($all_companies)>1): ?>
    <tr><td class="text-muted" style="width:45%">Company</td><td><span class="badge bg-primary"><?= htmlspecialchars($po['company_name']??'—') ?></span></td></tr>
    <?php endif; ?>
    <tr><td class="text-muted">PO Number</td><td><strong><?= htmlspecialchars($po['po_number']) ?></strong></td></tr>
    <tr><td class="text-muted">PO Date</td><td><?= date('d/m/Y',strtotime($po['po_date'])) ?></td></tr>
    <tr><td class="text-muted">Validity</td><td><?= $po['validity_date'] ? date('d/m/Y',strtotime($po['validity_date'])) : '—' ?></td></tr>
    <tr><td class="text-muted">Payment Terms</td><td><?= htmlspecialchars($po['payment_terms'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">GST Type</td><td><span class="badge bg-info text-dark"><?= htmlspecialchars($po['gst_type'] ?? 'IGST') ?></span></td></tr>
    <tr><td class="text-muted">Delivery Address</td><td><?= htmlspecialchars($po['delivery_address'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">Subtotal</td><td>₹<?= number_format($po['subtotal'],2) ?></td></tr>
    <tr><td class="text-muted">GST Amount</td><td>₹<?= number_format($po['gst_amount'],2) ?></td></tr>
    <tr><td class="text-muted">Total Amount</td><td><strong class="text-success fs-6">₹<?= number_format($po['total_amount'],2) ?></strong></td></tr>
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
<thead class="table-light"><tr>
    <th>#</th><th>Item</th><th>UOM</th><th class="text-end">Qty</th><th class="text-end">Rate</th>
    <?php if ($is_igst): ?>
    <th class="text-end">IGST%</th><th class="text-end">IGST Amt</th>
    <?php else: ?>
    <th class="text-end">CGST%</th><th class="text-end">CGST Amt</th>
    <th class="text-end">SGST%</th><th class="text-end">SGST Amt</th>
    <?php endif; ?>
    <th class="text-end">Amount</th>
</tr></thead>
<tbody>
<?php $ri=1; foreach ($items as $it):
    $half_gst = ($it['gst_rate'] ?? 0) / 2;
    $half_amt = ($it['gst_amount'] ?? 0) / 2;
?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($it['item_name']) ?></td>
    <td><?= htmlspecialchars($it['uom']) ?></td>
    <td class="text-end"><?= number_format($it['qty'],3) ?></td>
    <td class="text-end">₹<?= number_format($it['unit_price'],2) ?></td>
    <?php if ($is_igst): ?>
    <td class="text-end"><?= $it['gst_rate'] ?>%</td>
    <td class="text-end">₹<?= number_format($it['gst_amount'],2) ?></td>
    <?php else: ?>
    <td class="text-end"><?= $half_gst ?>%</td><td class="text-end">₹<?= number_format($half_amt,2) ?></td>
    <td class="text-end"><?= $half_gst ?>%</td><td class="text-end">₹<?= number_format($half_amt,2) ?></td>
    <?php endif; ?>
    <td class="text-end"><strong>₹<?= number_format($it['amount'],2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light">
    <tr><td colspan="<?= $is_igst ? 6 : 8 ?>" class="text-end fw-bold">Subtotal</td><td class="text-end fw-bold">₹<?= number_format($po['subtotal'],2) ?></td></tr>
    <tr><td colspan="<?= $is_igst ? 6 : 8 ?>" class="text-end fw-bold">GST</td><td class="text-end fw-bold">₹<?= number_format($po['gst_amount'],2) ?></td></tr>
    <tr class="table-success"><td colspan="<?= $is_igst ? 6 : 8 ?>" class="text-end fw-bold">Grand Total</td><td class="text-end fw-bold">₹<?= number_format($po['total_amount'],2) ?></td></tr>
</tfoot>
</table>
</div></div></div></div>
<?php if ($po['remarks']): ?>
<div class="col-12"><div class="card"><div class="card-body"><strong>Remarks:</strong> <?= htmlspecialchars($po['remarks']) ?></div></div></div>
<?php endif; ?>
<?php if (!empty($po['po_document'])): ?>
<div class="col-12"><div class="card"><div class="card-body">
    <strong><i class="bi bi-paperclip me-1"></i>PO Document:</strong>
    <a href="<?= htmlspecialchars(r2_url($po['po_document'])) ?>" target="_blank" class="btn btn-outline-success btn-sm ms-2">
        <i class="bi bi-file-earmark-arrow-down me-1"></i>View / Download
    </a>
</div></div></div>
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
// Build customer JS map
$customer_map = [];
foreach ($vendors_raw as $v) {
    $addr = trim(implode(', ', array_filter([
        $v['ship_name'] ?? '',
        $v['ship_address'] ?? '',
        $v['ship_city'] ?? '',
        $v['ship_state'] ?? '',
        $v['ship_pincode'] ?? '',
    ])));
    $customer_map[$v['id']] = ['address' => $addr];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'New' ?> Customer PO</h5>
    <a href="fleet_purchase_orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="save_po" value="1">
<div class="row g-3">

<!-- PO Details -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>PO Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">PO Date *</label>
        <input type="date" name="po_date" class="form-control" value="<?= $po['po_date'] ?? date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">PO Number *</label>
        <input type="text" name="po_number" class="form-control" required
               value="<?= htmlspecialchars($po['po_number'] ?? '') ?>"
               placeholder="Enter customer PO no.">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Customer / Buyer *</label>
        <select name="vendor_id" id="vendorSelect" class="form-select" required onchange="fillCustomerData(this)">
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
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">GST Type</label>
        <select name="gst_type" id="gstTypeSelect" class="form-select" onchange="updateGstHeaders()">
            <option value="IGST"      <?= ($po['gst_type'] ?? 'IGST') === 'IGST'       ? 'selected' : '' ?>>IGST</option>
            <option value="CGST+SGST" <?= ($po['gst_type'] ?? '') === 'CGST+SGST' ? 'selected' : '' ?>>CGST + SGST</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Payment Terms</label>
        <input type="text" name="payment_terms" class="form-control" value="<?= htmlspecialchars($po['payment_terms'] ?? '') ?>" placeholder="e.g. 30 days">
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label">Delivery Address <small class="text-muted">(auto-filled from Ship-To)</small></label>
        <input type="text" name="delivery_address" id="deliveryAddress" class="form-control bg-light"
               value="<?= htmlspecialchars($po['delivery_address'] ?? '') ?>" readonly>
    </div>
</div></div></div></div>

<!-- Items -->
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Items</span>
    <button type="button" class="btn btn-success btn-sm" onclick="addFpoRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0" id="fpoItemsTable">
<thead class="table-light" id="fpoItemsHead"><tr>
    <th>Item</th><th>UOM</th>
    <th style="width:90px">Qty</th>
    <th style="width:100px">Rate (₹)</th>
    <th style="width:80px" id="gstHeader">GST%</th>
    <th style="width:100px" id="gstAmtHeader">GST Amt</th>
    <th style="width:110px">Amount (₹)</th>
    <th style="width:36px"></th>
</tr></thead>
<tbody id="fpoItemsBody">
<?php if ($po_items): foreach ($po_items as $it): ?>
<tr class="fpo-item-row">
    <td><select name="item_name[]" class="form-select form-select-sm fpo-item-select" onchange="fpoItemSelected(this)" required>
        <option value="">-- Select Item --</option>
        <?php foreach ($items_master as $im): ?>
        <option value="<?= htmlspecialchars($im['item_name']) ?>" data-uom="<?= htmlspecialchars($im['uom']) ?>"
            <?= $it['item_name'] === $im['item_name'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($im['item_name']) ?>
        </option>
        <?php endforeach; ?>
    </select></td>
    <td><input type="text" name="item_uom[]" class="form-control form-control-sm bg-light fpo-uom" value="<?= htmlspecialchars($it['uom']) ?>" readonly></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm fpo-qty" step="0.001" value="<?= $it['qty'] ?>" onchange="calcFpoRow(this)" required></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm fpo-price" step="0.01" value="<?= $it['unit_price'] ?>" onchange="calcFpoRow(this)"></td>
    <td><input type="number" name="item_gst[]" class="form-control form-control-sm fpo-gst" step="0.01" value="<?= $it['gst_rate'] ?? 0 ?>" onchange="calcFpoRow(this)"></td>
    <td><input type="text" class="form-control form-control-sm fpo-gst-amt bg-light" readonly value="<?= number_format($it['gst_amount'] ?? 0, 2) ?>"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm fpo-amt bg-light fw-semibold" readonly value="<?= $it['amount'] ?>"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFpoRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endforeach; else: ?>
<tr class="fpo-item-row">
    <td><select name="item_name[]" class="form-select form-select-sm fpo-item-select" onchange="fpoItemSelected(this)" required>
        <option value="">-- Select Item --</option>
        <?php foreach ($items_master as $im): ?>
        <option value="<?= htmlspecialchars($im['item_name']) ?>" data-uom="<?= htmlspecialchars($im['uom']) ?>">
            <?= htmlspecialchars($im['item_name']) ?>
        </option>
        <?php endforeach; ?>
    </select></td>
    <td><input type="text" name="item_uom[]" class="form-control form-control-sm bg-light fpo-uom" readonly></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm fpo-qty" step="0.001" onchange="calcFpoRow(this)" required></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm fpo-price" step="0.01" onchange="calcFpoRow(this)"></td>
    <td><input type="number" name="item_gst[]" class="form-control form-control-sm fpo-gst" step="0.01" value="0" onchange="calcFpoRow(this)"></td>
    <td><input type="text" class="form-control form-control-sm fpo-gst-amt bg-light" readonly value="0.00"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm fpo-amt bg-light fw-semibold" readonly></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFpoRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endif; ?>
</tbody>
<tfoot class="table-light">
    <tr>
        <td colspan="5" class="text-end fw-bold">Subtotal</td>
        <td colspan="2"><strong id="fpoSubTotal">₹0.00</strong></td>
        <td></td>
    </tr>
    <tr>
        <td colspan="5" class="text-end fw-bold">GST Total</td>
        <td colspan="2"><strong id="fpoGstTotal">₹0.00</strong></td>
        <td></td>
    </tr>
    <tr class="table-success">
        <td colspan="5" class="text-end fw-bold">Grand Total</td>
        <td colspan="2"><strong id="fpoFootTotal">₹0.00</strong></td>
        <td></td>
    </tr>
</tfoot>
</table>
</div></div></div></div>

<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Remarks</label>
    <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($po['remarks'] ?? '') ?></textarea>
</div></div></div>

<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-paperclip me-2"></i>PO Document (PDF / Image)</div>
<div class="card-body">
    <?php $cur_doc = $po['po_document'] ?? ''; ?>
    <?php if ($cur_doc): ?>
    <div class="mb-2">
        <a href="<?= htmlspecialchars(r2_url($cur_doc)) ?>" target="_blank" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-arrow-down me-1"></i>View Current Document
        </a>
        <label class="ms-3 form-check-label text-danger">
            <input type="checkbox" name="delete_po_document" value="1" class="form-check-input me-1">
            Remove document
        </label>
    </div>
    <?php endif; ?>
    <input type="file" name="po_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
    <div class="form-text">Accepted: PDF, JPG, PNG, WEBP (max 5MB)</div>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_purchase_orders.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save PO</button>
</div>
</div>
</form>

<script>
// ── Customer & Items data from server ──
const customerMap  = <?= json_encode($customer_map, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const itemsMaster  = <?= json_encode($items_master, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

// ── Fill delivery address + items when customer selected ──
function fillCustomerData(sel) {
    var vid  = parseInt(sel.value) || 0;
    var data = customerMap[vid] || {};

    // 1. Delivery Address (readonly, from ship address)
    document.getElementById('deliveryAddress').value = data.address || '';

    // 2. Reset to single blank item row
    if (vid) {
        var tbody = document.getElementById('fpoItemsBody');
        tbody.innerHTML = '';
        tbody.appendChild(makeFpoRow('', ''));
        calcFpoTotal();
    }
}

// ── Make a new item row ──
function makeFpoRow(itemName, uom) {
    var tr = document.createElement('tr');
    tr.className = 'fpo-item-row';
    var opts = '<option value="">-- Select Item --</option>';
    itemsMaster.forEach(function(it) {
        var sel = (it.item_name === itemName) ? ' selected' : '';
        opts += '<option value="' + it.item_name + '" data-uom="' + it.uom + '"' + sel + '>' + it.item_name + '</option>';
    });
    tr.innerHTML =
        '<td><select name="item_name[]" class="form-select form-select-sm fpo-item-select" onchange="fpoItemSelected(this)" required>' + opts + '</select></td>' +
        '<td><input type="text" name="item_uom[]" class="form-control form-control-sm bg-light fpo-uom" value="' + (uom||'') + '" readonly></td>' +
        '<td><input type="number" name="item_qty[]" class="form-control form-control-sm fpo-qty" step="0.001" onchange="calcFpoRow(this)" required></td>' +
        '<td><input type="number" name="item_price[]" class="form-control form-control-sm fpo-price" step="0.01" onchange="calcFpoRow(this)"></td>' +
        '<td><input type="number" name="item_gst[]" class="form-control form-control-sm fpo-gst" step="0.01" value="0" onchange="calcFpoRow(this)"></td>' +
        '<td><input type="text" class="form-control form-control-sm fpo-gst-amt bg-light" readonly value="0.00"></td>' +
        '<td><input type="text" name="item_amount[]" class="form-control form-control-sm fpo-amt bg-light fw-semibold" readonly></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFpoRow(this)"><i class="bi bi-x"></i></button></td>';
    return tr;
}

function fpoItemSelected(sel) {
    var opt = sel.options[sel.selectedIndex];
    var uom = opt ? (opt.getAttribute('data-uom') || '') : '';
    var tr  = sel.closest('tr');
    var uomEl = tr.querySelector('.fpo-uom');
    if (uomEl) uomEl.value = uom;
}

function addFpoRow() {
    document.getElementById('fpoItemsBody').appendChild(makeFpoRow('', ''));
}

function removeFpoRow(btn) {
    var rows = document.querySelectorAll('.fpo-item-row');
    if (rows.length > 1) { btn.closest('tr').remove(); calcFpoTotal(); }
}

function calcFpoRow(el) {
    var tr    = el.closest('tr');
    var qty   = parseFloat(tr.querySelector('.fpo-qty').value)   || 0;
    var prc   = parseFloat(tr.querySelector('.fpo-price').value) || 0;
    var gst   = parseFloat(tr.querySelector('.fpo-gst').value)   || 0;
    var base  = qty * prc;
    var gstAmt= base * gst / 100;
    tr.querySelector('.fpo-gst-amt').value = gstAmt.toFixed(2);
    tr.querySelector('.fpo-amt').value     = (base + gstAmt).toFixed(2);
    calcFpoTotal();
}

function calcFpoTotal() {
    var subtotal = 0, gstTotal = 0;
    document.querySelectorAll('.fpo-item-row').forEach(function(tr) {
        var qty  = parseFloat(tr.querySelector('.fpo-qty')?.value)   || 0;
        var prc  = parseFloat(tr.querySelector('.fpo-price')?.value) || 0;
        var gst  = parseFloat(tr.querySelector('.fpo-gst')?.value)   || 0;
        var base = qty * prc;
        subtotal  += base;
        gstTotal  += base * gst / 100;
    });
    document.getElementById('fpoSubTotal').textContent  = '₹' + subtotal.toFixed(2);
    document.getElementById('fpoGstTotal').textContent  = '₹' + gstTotal.toFixed(2);
    document.getElementById('fpoFootTotal').textContent = '₹' + (subtotal + gstTotal).toFixed(2);
}

function updateGstHeaders() {
    var isIgst = document.getElementById('gstTypeSelect').value === 'IGST';
    document.getElementById('gstHeader').textContent    = isIgst ? 'IGST%' : 'GST%';
    document.getElementById('gstAmtHeader').textContent = isIgst ? 'IGST Amt' : 'GST Amt';
}

// Init on load
calcFpoTotal();
updateGstHeaders();

// If editing existing PO, trigger address fill silently
<?php if ($id > 0 && !empty($po['vendor_id'])): ?>
(function() {
    var sel = document.getElementById('vendorSelect');
    if (sel) {
        var vid = <?= (int)$po['vendor_id'] ?>;
        var data = customerMap[vid] || {};
        // Only fill address if delivery_address is empty (don't override saved value)
        var addrEl = document.getElementById('deliveryAddress');
        if (addrEl && !addrEl.value && data.address) addrEl.value = data.address;
    }
})();
<?php endif; ?>
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
