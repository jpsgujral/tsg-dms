<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
$db = getDB();
requirePerm('fleet_trips', 'view');

/* ── Safe ALTER helper ── */
function fleetSafeAddCol($db, $table, $col, $def) {
    $dbname = $db->query("SELECT DATABASE()")->fetch_row()[0];
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table' AND COLUMN_NAME='$col' LIMIT 1")->num_rows;
    if (!$exists) $db->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

/* ── Tables ── */
$db->query("CREATE TABLE IF NOT EXISTS fleet_trips (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    trip_no             VARCHAR(30) NOT NULL UNIQUE,
    trip_date           DATE NOT NULL,
    po_id               INT DEFAULT NULL,
    vendor_id           INT DEFAULT NULL,
    vehicle_id          INT NOT NULL,
    driver_id           INT NOT NULL,
    supervisor_id       INT DEFAULT NULL,
    from_location       VARCHAR(200),
    to_location         VARCHAR(200),
    customer_name       VARCHAR(200),
    customer_address    TEXT,
    customer_city       VARCHAR(80),
    customer_state      VARCHAR(80),
    customer_gstin      VARCHAR(20),
    total_weight        DECIMAL(10,3) DEFAULT 0,
    uom                 VARCHAR(20) DEFAULT 'MT',
    start_odometer      INT DEFAULT 0,
    end_odometer        INT DEFAULT 0,
    start_date          DATE DEFAULT NULL,
    end_date            DATE DEFAULT NULL,
    freight_amount      DECIMAL(12,2) DEFAULT 0,
    driver_advance      DECIMAL(10,2) DEFAULT 0,
    toll_amount         DECIMAL(10,2) DEFAULT 0,
    loading_charges     DECIMAL(10,2) DEFAULT 0,
    unloading_charges   DECIMAL(10,2) DEFAULT 0,
    other_expenses      DECIMAL(10,2) DEFAULT 0,
    subtotal            DECIMAL(12,2) DEFAULT 0,
    total_amount        DECIMAL(12,2) DEFAULT 0,
    mtc_required        ENUM('No','Yes') DEFAULT 'No',
    mtc_source          VARCHAR(120) DEFAULT '',
    mtc_item_name       VARCHAR(120) DEFAULT '',
    mtc_test_date       DATE DEFAULT NULL,
    mtc_ros_45          VARCHAR(30) DEFAULT '',
    mtc_moisture        VARCHAR(30) DEFAULT '',
    mtc_loi             VARCHAR(30) DEFAULT '',
    mtc_fineness        VARCHAR(30) DEFAULT '',
    mtc_remarks         VARCHAR(255) DEFAULT '',
    status              ENUM('Planned','In Transit','Completed','Cancelled') DEFAULT 'Planned',
    remarks             TEXT,
    company_id          INT DEFAULT 1,
    created_by          INT DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

fleetSafeAddCol($db, 'fleet_trips', 'po_id',        'INT DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'vendor_id',     'INT DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'subtotal',      'DECIMAL(12,2) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'total_amount',  'DECIMAL(12,2) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'mtc_required',  "ENUM('No','Yes') DEFAULT 'No'");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_source',    "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_item_name', "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_test_date', 'DATE DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'mtc_ros_45',    "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_moisture',  "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_loi',       "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_fineness',  "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_remarks',   "VARCHAR(255) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'company_id',    'INT DEFAULT 1');

$db->query("CREATE TABLE IF NOT EXISTS fleet_trip_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    trip_id     INT NOT NULL,
    item_id     INT DEFAULT NULL,
    item_name   VARCHAR(200),
    qty         DECIMAL(12,3) DEFAULT 0,
    uom         VARCHAR(20) DEFAULT 'MT',
    unit_price  DECIMAL(12,2) DEFAULT 0,
    weight      DECIMAL(10,3) DEFAULT 0,
    amount      DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Trip number generator ── */
function generateTripNo($db) {
    $month    = (int)date('m'); $year = (int)date('Y');
    $fy_start = $month >= 4 ? $year : $year - 1;
    $fy_label = ($fy_start % 100) . '-' . str_pad(($fy_start+1) % 100, 2, '0', STR_PAD_LEFT);
    $prefix   = "TR/$fy_label/";
    $db->query("LOCK TABLES fleet_trips WRITE");
    $row  = $db->query("SELECT trip_no FROM fleet_trips WHERE trip_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $next = $row ? (int)substr($row['trip_no'], strrpos($row['trip_no'], '/') + 1) + 1 : 1;
    $trip_no = $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    $db->query("UNLOCK TABLES");
    return $trip_no;
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

/* ── Delete ── */
if (isset($_GET['delete']) && isAdmin()) {
    $did = (int)$_GET['delete'];
    $db->query("DELETE FROM fleet_trip_items WHERE trip_id=$did");
    $db->query("DELETE FROM fleet_trips WHERE id=$did AND status='Planned'");
    showAlert('success', 'Trip deleted.');
    redirect('fleet_trips.php');
}

/* ── Quick status update ── */
if (isset($_GET['setstatus']) && $id) {
    requirePerm('fleet_trips', 'update');
    $ns      = sanitize($_GET['setstatus']);
    $allowed = ['Planned','In Transit','Completed','Cancelled'];
    if (in_array($ns, $allowed)) {
        $extra = '';
        if ($ns === 'In Transit') $extra = ", start_date='" . date('Y-m-d') . "'";
        if ($ns === 'Completed')  $extra = ", end_date='"   . date('Y-m-d') . "'";
        $db->query("UPDATE fleet_trips SET status='$ns'$extra WHERE id=$id");
        if ($ns === 'Completed') {
            $trip = $db->query("SELECT po_id FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
            if (!empty($trip['po_id'])) {
                $po_id = (int)$trip['po_id'];
                $db->query("UPDATE fleet_purchase_orders SET status='Partially Received' WHERE id=$po_id AND status='Approved'");
            }
        }
        showAlert('success', "Status updated to $ns.");
    }
    redirect('fleet_trips.php?action=view&id=' . $id);
}

/* ── Save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_trip'])) {
    requirePerm('fleet_trips', $id > 0 ? 'update' : 'create');

    $trip_no   = $id > 0 ? sanitize($_POST['trip_no']) : generateTripNo($db);
    $trip_date = sanitize($_POST['trip_date']);
    $po_id     = (int)($_POST['po_id']    ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $veh_id    = (int)$_POST['vehicle_id'];
    $drv_id    = (int)$_POST['driver_id'];
    $sup_id    = (int)($_POST['supervisor_id'] ?? 0);
    $from      = sanitize($_POST['from_location']    ?? '');
    $to        = sanitize($_POST['to_location']      ?? '');
    $cust_name = sanitize($_POST['customer_name']    ?? '');
    $cust_addr = sanitize($_POST['customer_address'] ?? '');
    $cust_city = sanitize($_POST['customer_city']    ?? '');
    $cust_state= sanitize($_POST['customer_state']   ?? '');
    $cust_gst  = sanitize($_POST['customer_gstin']   ?? '');
    $uom       = sanitize($_POST['uom']  ?? 'MT');
    $start_odo = (int)($_POST['start_odometer'] ?? 0);
    $end_odo   = (int)($_POST['end_odometer']   ?? 0);
    $start_dt  = sanitize($_POST['start_date']  ?? '');
    $end_dt    = sanitize($_POST['end_date']    ?? '');
    $freight   = (float)($_POST['freight_amount']  ?? 0);
    $advance   = (float)($_POST['driver_advance']  ?? 0);
    $toll      = (float)($_POST['toll_amount']     ?? 0);
    $loading   = (float)($_POST['loading_charges'] ?? 0);
    $unloading = (float)($_POST['unloading_charges'] ?? 0);
    $other     = (float)($_POST['other_expenses']  ?? 0);
    $status    = sanitize($_POST['status']  ?? 'Planned');
    $remarks   = sanitize($_POST['remarks'] ?? '');
    $co_id     = (int)($_POST['company_id'] ?? activeCompanyId());

    /* MTC */
    $mtc_req   = in_array($_POST['mtc_required'] ?? 'No', ['Yes','No']) ? $_POST['mtc_required'] : 'No';
    $mtc_src   = sanitize($_POST['mtc_source']    ?? '');
    $mtc_item  = sanitize($_POST['mtc_item_name'] ?? '');
    $mtc_tdate = sanitize($_POST['mtc_test_date'] ?? '');
    $mtc_ros   = sanitize($_POST['mtc_ros_45']    ?? '');
    $mtc_moist = sanitize($_POST['mtc_moisture']  ?? '');
    $mtc_loi   = sanitize($_POST['mtc_loi']       ?? '');
    $mtc_fine  = sanitize($_POST['mtc_fineness']  ?? '');
    $mtc_rem   = sanitize($_POST['mtc_remarks']   ?? '');
    $mtc_tdate_sql = $mtc_tdate ? "'$mtc_tdate'" : 'NULL';

    /* Items */
    $item_ids   = $_POST['item_id']     ?? [];
    $item_names = $_POST['item_name']   ?? [];
    $item_qtys  = $_POST['item_qty']    ?? [];
    $item_uoms  = $_POST['item_uom']    ?? [];
    $item_prices= $_POST['item_price']  ?? [];
    $item_wts   = $_POST['item_weight'] ?? [];

    $subtotal = 0; $total_weight = 0; $valid_items = [];
    foreach ($item_names as $idx => $iname) {
        $iname = trim($iname);
        if (!$iname) continue;
        $qty   = (float)($item_qtys[$idx]   ?? 0);
        $price = (float)($item_prices[$idx] ?? 0);
        $wt    = (float)($item_wts[$idx]    ?? 0);
        $amt   = round($qty * $price, 2);
        $subtotal     += $amt;
        $total_weight += $wt;
        $valid_items[] = [
            'item_id'   => (int)($item_ids[$idx] ?? 0),
            'item_name' => $db->real_escape_string($iname),
            'qty'       => $qty,
            'uom'       => $db->real_escape_string($item_uoms[$idx] ?? 'MT'),
            'unit_price'=> $price,
            'weight'    => $wt,
            'amount'    => $amt,
        ];
    }
    $total_amount = round($subtotal, 2);

    $po_sql   = $po_id    ? $po_id    : 'NULL';
    $vend_sql = $vendor_id? $vendor_id: 'NULL';
    $sup_sql  = $sup_id   ? $sup_id   : 'NULL';
    $start_sql= $start_dt ? "'$start_dt'" : 'NULL';
    $end_sql  = $end_dt   ? "'$end_dt'"   : 'NULL';

    if (!$trip_date || !$veh_id || !$drv_id) {
        showAlert('danger', 'Trip Date, Vehicle and Driver are required.');
        redirect("fleet_trips.php?action=" . ($id > 0 ? "edit&id=$id" : 'add'));
    }

    if ($id > 0) {
        $db->query("UPDATE fleet_trips SET
            trip_date='$trip_date', po_id=$po_sql, vendor_id=$vend_sql,
            vehicle_id=$veh_id, driver_id=$drv_id, supervisor_id=$sup_sql,
            from_location='$from', to_location='$to',
            customer_name='$cust_name', customer_address='$cust_addr',
            customer_city='$cust_city', customer_state='$cust_state', customer_gstin='$cust_gst',
            total_weight=$total_weight, uom='$uom',
            start_odometer=$start_odo, end_odometer=$end_odo,
            start_date=$start_sql, end_date=$end_sql,
            freight_amount=$freight, driver_advance=$advance,
            toll_amount=$toll, loading_charges=$loading, unloading_charges=$unloading,
            other_expenses=$other, subtotal=$subtotal, total_amount=$total_amount,
            mtc_required='$mtc_req', mtc_source='$mtc_src', mtc_item_name='$mtc_item',
            mtc_test_date=$mtc_tdate_sql, mtc_ros_45='$mtc_ros', mtc_moisture='$mtc_moist',
            mtc_loi='$mtc_loi', mtc_fineness='$mtc_fine', mtc_remarks='$mtc_rem',
            company_id=$co_id, status='$status', remarks='$remarks'
            WHERE id=$id");
        $db->query("DELETE FROM fleet_trip_items WHERE trip_id=$id");
    } else {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $db->query("INSERT INTO fleet_trips
            (trip_no,trip_date,po_id,vendor_id,vehicle_id,driver_id,supervisor_id,
             from_location,to_location,customer_name,customer_address,customer_city,
             customer_state,customer_gstin,total_weight,uom,start_odometer,end_odometer,
             start_date,end_date,freight_amount,driver_advance,toll_amount,loading_charges,
             unloading_charges,other_expenses,subtotal,total_amount,
             mtc_required,mtc_source,mtc_item_name,mtc_test_date,mtc_ros_45,mtc_moisture,
             mtc_loi,mtc_fineness,mtc_remarks,company_id,status,remarks,created_by)
            VALUES ('$trip_no','$trip_date',$po_sql,$vend_sql,$veh_id,$drv_id,$sup_sql,
            '$from','$to','$cust_name','$cust_addr','$cust_city','$cust_state','$cust_gst',
            $total_weight,'$uom',$start_odo,$end_odo,$start_sql,$end_sql,
            $freight,$advance,$toll,$loading,$unloading,$other,$subtotal,$total_amount,
            '$mtc_req','$mtc_src','$mtc_item',$mtc_tdate_sql,'$mtc_ros','$mtc_moist',
            '$mtc_loi','$mtc_fine','$mtc_rem',$co_id,'$status','$remarks',$uid)");
        $id = $db->insert_id;
    }

    foreach ($valid_items as $row) {
        $iid = $row['item_id'] ? $row['item_id'] : 'NULL';
        $db->query("INSERT INTO fleet_trip_items (trip_id,item_id,item_name,qty,uom,unit_price,weight,amount)
            VALUES ($id,$iid,'{$row['item_name']}',{$row['qty']},'{$row['uom']}',
            {$row['unit_price']},{$row['weight']},{$row['amount']})");
    }

    showAlert('success', $id > 0 ? 'Trip updated.' : 'Trip created.');
    redirect('fleet_trips.php?action=view&id=' . $id);
}

/* ── Data for dropdowns ── */
$vehicles  = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$drivers   = $db->query("SELECT id,full_name,role FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$supervisors = array_filter($drivers, fn($d) => in_array($d['role'], ['Supervisor','Driver+Supervisor']));
$vendors   = $db->query("SELECT id,vendor_name,ship_address,ship_city,ship_state,ship_pincode,ship_gstin,ship_name FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$pos       = $db->query("SELECT p.id,p.po_number,p.vendor_id,p.delivery_address FROM fleet_purchase_orders p WHERE p.status IN ('Approved','Partially Received') ORDER BY p.po_date DESC")->fetch_all(MYSQLI_ASSOC);
$items_list= $db->query("SELECT id,item_code,item_name,uom FROM items WHERE status='Active' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);
$sources_list = $db->query("SELECT id,source_name FROM source_of_material WHERE status='Active' ORDER BY source_name")->fetch_all(MYSQLI_ASSOC);

/* Vehicle → Driver map — empty by default, driver auto-fill via JS not supported without DB column */
$veh_driver_map = [];

/* PO → vendor + delivery address + freight rate map */
$po_vendor_map = [];
$po_addr_map   = [];
$po_rate_map   = [];
foreach ($pos as $p) {
    $po_vendor_map[$p['id']] = $p['vendor_id'];
    $po_addr_map[$p['id']]   = $p['delivery_address'] ?? '';
}
// Get freight rate from fleet_po_items (unit_price of first item = rate per MT)
$po_rates_res = $db->query("SELECT po_id, unit_price FROM fleet_po_items ORDER BY po_id, id");
if ($po_rates_res) while ($pr = $po_rates_res->fetch_assoc()) {
    if (!isset($po_rate_map[$pr['po_id']])) $po_rate_map[$pr['po_id']] = (float)$pr['unit_price'];
}

/* Vendor → ship address map for JS */
$vendor_data_map = [];
foreach ($vendors as $v) {
    $addr = trim(implode(', ', array_filter([
        $v['ship_name']    ?? '',
        $v['ship_address'] ?? '',
        $v['ship_city']    ?? '',
        $v['ship_state']   ?? '',
        $v['ship_pincode'] ?? '',
    ])));
    $vendor_data_map[$v['id']] = [
        'name'    => $v['vendor_name'],
        'address' => $addr,
        'city'    => $v['ship_city']   ?? '',
        'state'   => $v['ship_state']  ?? '',
        'gstin'   => $v['ship_gstin']  ?? '',
    ];
}

$all_companies = getAllCompanies();
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-signpost-split me-2"></i>Trip Orders';</script>
<?php
$status_colors = ['Planned'=>'secondary','In Transit'=>'warning','Completed'=>'success','Cancelled'=>'danger'];

/* ══════════════════════════════════ LIST ══════════════════════════════════ */
if ($action === 'list'):
$trips = $db->query("SELECT t.*, v.reg_no, d.full_name AS driver_name,
    p.po_number, vn.vendor_name, co.company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    LEFT JOIN companies co ON t.company_id=co.id
    ORDER BY t.id DESC")->fetch_all(MYSQLI_ASSOC);
$counts = [];
foreach ($trips as $t) $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Trip Orders</h5>
    <?php if (canDo('fleet_trips','create')): ?>
    <a href="?action=add" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>New Trip</a>
    <?php endif; ?>
</div>
<div class="card mb-3">
<div class="card-body py-2 d-flex gap-1 flex-wrap">
    <button class="btn btn-sm btn-dark trip-filter active" data-status="All" onclick="tripFilter('All',this)">All <span class="badge bg-secondary ms-1"><?= count($trips) ?></span></button>
    <?php foreach ($status_colors as $st => $sc): if (!isset($counts[$st])) continue; ?>
    <button class="btn btn-sm btn-outline-<?= $sc ?> trip-filter" data-status="<?= $st ?>" onclick="tripFilter('<?= $st ?>',this)">
        <?= $st ?> <span class="badge bg-<?= $sc ?> ms-1"><?= $counts[$st] ?></span>
    </button>
    <?php endforeach; ?>
</div>
</div>
<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0 datatable" id="tripsTable">
<thead><tr>
    <th>Trip No</th><th>Date</th><?php if(count($all_companies)>1): ?><th>Company</th><?php endif; ?>
    <th>Customer PO</th><th>Customer</th><th>Vehicle</th><th>Driver</th>
    <th>From → To</th><th class="text-end">Weight</th>
    <th class="text-end">Freight</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($trips as $t):
    $sc = $status_colors[$t['status']] ?? 'secondary';
?>
<tr data-status="<?= $t['status'] ?>">
    <td><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td>
    <td><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
    <?php if(count($all_companies)>1): ?><td><span class='badge bg-primary' style='font-size:.7rem'><?= htmlspecialchars($t['company_name']??'-') ?></span></td><?php endif; ?>
    <td><?= $t['po_number'] ? '<span class="badge bg-info text-dark">'.htmlspecialchars($t['po_number']).'</span>' : '—' ?></td>
    <td><?= htmlspecialchars($t['vendor_name'] ?? $t['customer_name'] ?? '—') ?><br><small class="text-muted"><?= htmlspecialchars($t['customer_city']??'') ?></small></td>
    <td><span class="badge bg-dark"><?= htmlspecialchars($t['reg_no']) ?></span></td>
    <td><?= htmlspecialchars($t['driver_name']) ?></td>
    <td><?= htmlspecialchars($t['from_location']) ?><br><small class="text-muted"><?= htmlspecialchars($t['to_location']) ?></small></td>
    <td class="text-end"><?= number_format($t['total_weight'],3) ?> MT</td>
    <td class="text-end">₹<?= number_format($t['freight_amount'],2) ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $t['status'] ?></span></td>
    <td>
        <a href="?action=view&id=<?= $t['id'] ?>" class="btn btn-action btn-outline-info me-1" title="View"><i class="bi bi-eye"></i></a>
        <?php if (canDo('fleet_trips','update') && !in_array($t['status'],['Completed','Cancelled'])): ?>
        <a href="?action=edit&id=<?= $t['id'] ?>" class="btn btn-action btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <a href="fleet_trip_challan.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-action btn-outline-success me-1" title="Print"><i class="bi bi-printer"></i></a>
        <a href="export_trip_pdf.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-action btn-outline-danger me-1" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        <?php if (isAdmin() && $t['status'] === 'Planned'): ?>
        <a href="?delete=<?= $t['id'] ?>" onclick="return confirm('Delete this trip?')" class="btn btn-action btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div></div>
<style>.trip-filter.active{box-shadow:0 0 0 2px rgba(30,58,95,.5)}</style>
<script>
function tripFilter(status, btn) {
    document.querySelectorAll('.trip-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#tripsTable tbody tr').forEach(r => {
        r.style.display = (status === 'All' || r.dataset.status === status) ? '' : 'none';
    });
}
</script>

<?php
/* ══════════════════════════════════ VIEW ══════════════════════════════════ */
elseif ($action === 'view' && $id > 0):
$t = $db->query("SELECT t.*, v.reg_no, v.make, v.model,
    d.full_name AS driver_name, d.phone AS driver_phone,
    s.full_name AS supervisor_name,
    p.po_number, vn.vendor_name, co.company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    LEFT JOIN fleet_drivers s ON t.supervisor_id=s.id
    LEFT JOIN fleet_purchase_orders p ON t.po_id=p.id
    LEFT JOIN fleet_customers_master vn ON t.vendor_id=vn.id
    LEFT JOIN companies co ON t.company_id=co.id
    WHERE t.id=$id LIMIT 1")->fetch_assoc();
if (!$t) { echo '<div class="alert alert-danger">Trip not found.</div>'; include '../includes/footer.php'; exit; }
$trip_items = $db->query("SELECT ti.*, i.item_code FROM fleet_trip_items ti LEFT JOIN items i ON ti.item_id=i.id WHERE ti.trip_id=$id")->fetch_all(MYSQLI_ASSOC);
$sc  = $status_colors[$t['status']] ?? 'secondary';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0 fw-bold">Trip: <?= htmlspecialchars($t['trip_no']) ?>
        <span class="badge bg-<?= $sc ?> ms-2"><?= $t['status'] ?></span>
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($t['status'] === 'Planned'): ?>
        <a href="?setstatus=In+Transit&id=<?= $id ?>" class="btn btn-warning btn-sm" onclick="return confirm('Start trip?')"><i class="bi bi-truck me-1"></i>Start Trip</a>
        <?php elseif ($t['status'] === 'In Transit'): ?>
        <a href="?setstatus=Completed&id=<?= $id ?>" class="btn btn-success btn-sm" onclick="return confirm('Mark as Completed?')"><i class="bi bi-check-circle me-1"></i>Complete Trip</a>
        <?php endif; ?>
        <a href="fleet_trip_challan.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
        <a href="export_trip_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <?php if (canDo('fleet_trips','update') && !in_array($t['status'],['Completed','Cancelled'])): ?>
        <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="fleet_trips.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>
<div class="row g-3">
<div class="col-12 col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Trip Details</div>
<div class="card-body"><table class="table table-sm mb-0">
    <?php if (count($all_companies)>1): ?><tr><td class="text-muted" style="width:45%">Company</td><td><span class="badge bg-primary"><?= htmlspecialchars($t['company_name']??'—') ?></span></td></tr><?php endif; ?>
    <tr><td class="text-muted">Trip No</td><td><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td></tr>
    <tr><td class="text-muted">Trip Date</td><td><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td></tr>
    <?php if ($t['po_number']): ?><tr><td class="text-muted">PO Reference</td><td><span class="badge bg-info text-dark"><?= htmlspecialchars($t['po_number']) ?></span></td></tr><?php endif; ?>
    <?php if ($t['vendor_name']): ?><tr><td class="text-muted">Customer / Buyer</td><td><?= htmlspecialchars($t['vendor_name']) ?></td></tr><?php endif; ?>
    <tr><td class="text-muted">Vehicle</td><td><strong><?= htmlspecialchars($t['reg_no']) ?></strong> <?= htmlspecialchars($t['make'].' '.$t['model']) ?></td></tr>
    <tr><td class="text-muted">Driver</td><td><?= htmlspecialchars($t['driver_name']) ?><?= $t['driver_phone'] ? ' <small class="text-muted">('.$t['driver_phone'].')</small>' : '' ?></td></tr>
    <?php if ($t['supervisor_name']): ?><tr><td class="text-muted">Supervisor</td><td><?= htmlspecialchars($t['supervisor_name']) ?></td></tr><?php endif; ?>
    <tr><td class="text-muted">From → To</td><td><?= htmlspecialchars($t['from_location']) ?> → <?= htmlspecialchars($t['to_location']) ?></td></tr>
    <tr><td class="text-muted">Driver Advance</td><td><strong class="text-warning">₹<?= number_format($t['driver_advance'],2) ?></strong></td></tr>
    <tr><td class="text-muted">Freight Amount</td><td><strong class="text-success">₹<?= number_format($t['freight_amount'],2) ?></strong></td></tr>
</table></div></div></div>

<div class="col-12 col-md-6"><div class="card h-100"><div class="card-header"><i class="bi bi-geo-alt me-2"></i>Consignee Details</div>
<div class="card-body"><table class="table table-sm mb-0">
    <tr><td class="text-muted" style="width:45%">Name</td><td><strong><?= htmlspecialchars($t['customer_name']) ?></strong></td></tr>
    <tr><td class="text-muted">Address</td><td><?= htmlspecialchars($t['customer_address']??'—') ?></td></tr>
    <tr><td class="text-muted">City / State</td><td><?= htmlspecialchars(trim(($t['customer_city']??'').' '.($t['customer_state']??''))) ?: '—' ?></td></tr>
    <tr><td class="text-muted">GSTIN</td><td><?= htmlspecialchars($t['customer_gstin']??'—') ?></td></tr>
    <tr><td class="text-muted">Total Weight</td><td><strong><?= number_format($t['total_weight'],3) ?> MT</strong></td></tr>
</table></div></div></div>

<?php if ($trip_items): ?>
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-list-ul me-2"></i>Items / Materials</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0">
<thead class="table-light"><tr><th>#</th><th>Item</th><th>UOM</th><th class="text-end">Qty</th><th class="text-end">Weight (MT)</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr></thead>
<tbody>
<?php $ri=1; foreach ($trip_items as $ti): ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($ti['item_name']) ?></td>
    <td><?= htmlspecialchars($ti['uom']) ?></td>
    <td class="text-end"><?= number_format($ti['qty'],3) ?></td>
    <td class="text-end"><?= number_format($ti['weight'],3) ?></td>
    <td class="text-end">₹<?= number_format($ti['unit_price'],2) ?></td>
    <td class="text-end"><strong>₹<?= number_format($ti['amount'],2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light"><tr>
    <td colspan="4" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold"><?= number_format($t['total_weight'],3) ?> MT</td>
    <td></td>
    <td class="text-end fw-bold">₹<?= number_format($t['subtotal'],2) ?></td>
</tr></tfoot>
</table>
</div></div></div></div>
<?php endif; ?>

<?php if ($t['mtc_required'] === 'Yes'): ?>
<div class="col-12">
<div class="card border-warning">
    <div class="card-header" style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff">
        <i class="bi bi-patch-check me-2"></i>Material Test Certificate (MTC)
    </div>
    <div class="card-body">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><small class="text-muted d-block">Source</small><strong><?= htmlspecialchars($t['mtc_source']??'—') ?></strong></div>
        <div class="col-6 col-md-3"><small class="text-muted d-block">Item Name</small><strong><?= htmlspecialchars($t['mtc_item_name']??'—') ?></strong></div>
        <div class="col-6 col-md-2"><small class="text-muted d-block">Test Date</small><?= $t['mtc_test_date'] ? date('d/m/Y',strtotime($t['mtc_test_date'])) : '—' ?></div>
    </div>
    <div class="table-responsive">
    <table class="table table-bordered table-sm mb-0" style="font-size:.88rem">
    <thead class="table-warning"><tr><th style="width:40%">TEST</th><th>RESULTS</th><th>IS 3812 Requirement</th></tr></thead>
    <tbody>
        <tr><td>ROS 45 Micron Sieve</td><td><?= htmlspecialchars($t['mtc_ros_45']??'—') ?>%</td><td>&lt; 34%</td></tr>
        <tr><td>Moisture</td><td><?= htmlspecialchars($t['mtc_moisture']??'—') ?>%</td><td>&lt; 2%</td></tr>
        <tr><td>Loss on Ignition</td><td><?= htmlspecialchars($t['mtc_loi']??'—') ?>%</td><td>&lt; 5%</td></tr>
        <tr><td>Fineness (Blaine)</td><td><?= htmlspecialchars($t['mtc_fineness']??'—') ?> m²/kg</td><td>&gt; 320 m²/kg</td></tr>
    </tbody>
    </table>
    </div>
    <?php if ($t['mtc_remarks']): ?><div class="mt-2"><small class="text-muted">Remarks:</small> <?= htmlspecialchars($t['mtc_remarks']) ?></div><?php endif; ?>
    </div>
</div></div>
<?php endif; ?>

<?php if ($t['remarks']): ?>
<div class="col-12"><div class="card"><div class="card-body"><strong>Remarks:</strong> <?= htmlspecialchars($t['remarks']) ?></div></div></div>
<?php endif; ?>
</div><!-- /row -->

<?php
/* ══════════════════════════════════ ADD/EDIT ══════════════════════════════ */
else:
$t = [];
$trip_items = [];
if ($id > 0) {
    $t = $db->query("SELECT * FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc() ?? [];
    $trip_items = $db->query("SELECT * FROM fleet_trip_items WHERE trip_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><?= $id > 0 ? 'Edit' : 'New' ?> Trip Order</h5>
    <a href="fleet_trips.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<form method="POST" id="tripForm">
<input type="hidden" name="save_trip" value="1">
<?php if ($id > 0): ?><input type="hidden" name="trip_no" value="<?= htmlspecialchars($t['trip_no']) ?>"><?php endif; ?>
<div class="row g-3">

<!-- Trip Information -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-signpost-split me-2"></i>Trip Information</div>
<div class="card-body"><div class="row g-3">
    <?php if (count($all_companies) > 1): ?>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Company *</label>
        <select name="company_id" class="form-select" required>
            <?php foreach ($all_companies as $co): ?>
            <option value="<?= $co['id'] ?>" <?= ($t['company_id'] ?? activeCompanyId()) == $co['id'] ? 'selected' : '' ?>><?= htmlspecialchars($co['company_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" name="company_id" value="<?= activeCompanyId() ?>">
    <?php endif; ?>
    <?php if ($id > 0): ?>
    <div class="col-6 col-md-2">
        <label class="form-label">Trip No</label>
        <input type="text" class="form-control bg-light fw-bold text-success" value="<?= htmlspecialchars($t['trip_no']) ?>" readonly>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-2">
        <label class="form-label fw-bold">Trip Date *</label>
        <input type="date" name="trip_date" class="form-control" value="<?= $t['trip_date'] ?? date('Y-m-d') ?>" required>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">PO Reference</label>
        <select name="po_id" id="poSelect" class="form-select" onchange="fillFromPO(this.value)">
            <option value="">— Select PO (optional) —</option>
            <?php foreach ($pos as $p): ?>
            <option value="<?= $p['id'] ?>" data-vendor="<?= $p['vendor_id'] ?>" <?= ($t['po_id']??0)==$p['id']?'selected':'' ?>>
                <?= htmlspecialchars($p['po_number']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Customer / Buyer</label>
        <select name="vendor_id" id="vendorSelect" class="form-select" onchange="fillFromVendor(this.value)">
            <option value="">— Select Customer —</option>
            <?php foreach ($vendors as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($t['vendor_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['vendor_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <?php foreach (['Planned','In Transit','Completed','Cancelled'] as $s): ?>
            <option <?= ($t['status']??'Planned')===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label fw-bold">Vehicle *</label>
        <select name="vehicle_id" id="vehicleSelect" class="form-select" required onchange="fillDriverFromVehicle(this.value)">
            <option value="">— Select Vehicle —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($t['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' — '.$v['make'].' '.$v['model']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label fw-bold">Driver *</label>
        <select name="driver_id" id="driverSelect" class="form-select" required>
            <option value="">— Select Driver —</option>
            <?php foreach ($drivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($t['driver_id']??0)==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?> (<?= $d['role'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label">Supervisor</label>
        <select name="supervisor_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($supervisors as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($t['supervisor_id']??0)==$s['id']?'selected':'' ?>>
                <?= htmlspecialchars($s['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Driver Advance (₹)</label>
        <div class="input-group">
            <span class="input-group-text bg-light">₹</span>
            <input type="number" name="driver_advance" step="0.01" class="form-control" value="<?= $t['driver_advance']??0 ?>">
        </div>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Source of Material (From)</label>
        <select name="from_location" id="fromLocation" class="form-select">
            <option value="">— Select Source —</option>
            <?php foreach ($sources_list as $src): ?>
            <option value="<?= htmlspecialchars($src['source_name']) ?>"
                <?= ($t['from_location']??'')===$src['source_name']?'selected':'' ?>>
                <?= htmlspecialchars($src['source_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">To Location</label>
        <input type="text" name="to_location" class="form-control" value="<?= htmlspecialchars($t['to_location']??'') ?>">
    </div>
</div></div></div></div>

<!-- Consignee -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-geo-alt me-2"></i>Consignee Details <small class="text-muted fw-normal">(auto-filled from Customer Ship-To)</small></div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Consignee Name</label>
        <input type="text" name="customer_name" id="custName" class="form-control" value="<?= htmlspecialchars($t['customer_name']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">GSTIN</label>
        <input type="text" name="customer_gstin" id="custGst" class="form-control" value="<?= htmlspecialchars($t['customer_gstin']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="customer_city" id="custCity" class="form-control" value="<?= htmlspecialchars($t['customer_city']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="customer_state" id="custState" class="form-control" value="<?= htmlspecialchars($t['customer_state']??'') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Address</label>
        <textarea name="customer_address" id="custAddr" class="form-control" rows="2"><?= htmlspecialchars($t['customer_address']??'') ?></textarea>
    </div>
</div></div></div></div>

<!-- Items -->
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Items / Materials</span>
    <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm mb-0" id="itemsTable">
<thead class="table-light"><tr>
    <th>Item</th><th>UOM</th>
    <th style="width:90px">Qty</th>
    <th style="width:100px">Weight (MT)</th>
    <th style="width:110px">Rate (₹/MT)</th>
    <th style="width:110px">Amount (₹)</th>
    <th style="width:36px"></th>
</tr></thead>
<tbody id="itemsBody">
<?php if ($trip_items): foreach ($trip_items as $ti): ?>
<tr class="item-row">
    <td>
        <select name="item_id[]" class="form-select form-select-sm" onchange="fillItemUom(this)">
            <option value="">— Select —</option>
            <?php foreach ($items_list as $il): ?>
            <option value="<?= $il['id'] ?>" data-name="<?= htmlspecialchars($il['item_name']) ?>" data-uom="<?= $il['uom'] ?>"
                <?= ($ti['item_id']??0)==$il['id']?'selected':'' ?>>
                <?= htmlspecialchars($il['item_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="item_name[]" class="item-name-hidden" value="<?= htmlspecialchars($ti['item_name']) ?>">
    </td>
    <td><input type="text" name="item_uom[]" class="form-control form-control-sm bg-light item-uom" value="<?= htmlspecialchars($ti['uom']??'MT') ?>" readonly></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" step="0.001" value="<?= $ti['qty'] ?>" onchange="calcItemRow(this)"></td>
    <td><input type="number" name="item_weight[]" class="form-control form-control-sm item-wt" step="0.001" value="<?= $ti['weight'] ?>" onchange="updateTotalWeight()"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" value="<?= $ti['unit_price'] ?>" onchange="calcItemRow(this)"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm bg-light item-amt" readonly value="<?= $ti['amount'] ?>"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endforeach; else: ?>
<tr class="item-row">
    <td>
        <select name="item_id[]" class="form-select form-select-sm" onchange="fillItemUom(this)">
            <option value="">— Select —</option>
            <?php foreach ($items_list as $il): ?>
            <option value="<?= $il['id'] ?>" data-name="<?= htmlspecialchars($il['item_name']) ?>" data-uom="<?= $il['uom'] ?>"><?= htmlspecialchars($il['item_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="item_name[]" class="item-name-hidden" value="">
    </td>
    <td><input type="text" name="item_uom[]" class="form-control form-control-sm bg-light item-uom" readonly></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" step="0.001" value="0" onchange="calcItemRow(this)"></td>
    <td><input type="number" name="item_weight[]" class="form-control form-control-sm item-wt" step="0.001" value="0" onchange="updateTotalWeight()"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" value="0" onchange="calcItemRow(this)"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm bg-light item-amt" readonly value="0.00"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeItemRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endif; ?>
</tbody>
<tfoot class="table-light">
    <tr>
        <td colspan="3" class="text-end fw-bold">Total Weight:</td>
        <td><input type="number" name="total_weight" id="totalWeight" class="form-control form-control-sm bg-light fw-bold" readonly value="<?= $t['total_weight']??0 ?>"></td>
        <td class="text-end fw-bold">Total:</td>
        <td><strong id="itemsTotal">₹0.00</strong></td>
        <td></td>
    </tr>
</tfoot>
</table>
</div></div></div></div>

<!-- Hidden financial fields — kept for DB compatibility -->
<input type="hidden" name="freight_amount" id="freightAmount" value="<?= $t['freight_amount']??0 ?>">
<input type="hidden" name="toll_amount" value="0">
<input type="hidden" name="loading_charges" value="0">
<input type="hidden" name="unloading_charges" value="0">
<input type="hidden" name="other_expenses" value="0">

<!-- MTC — matching despatch module style -->
<div class="col-12">
<div class="card border-warning">
    <div class="card-header" style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff">
        <i class="bi bi-patch-check me-2"></i>Material Test Certificate (MTC)
    </div>
    <div class="card-body">
    <div class="row g-3 align-items-center">
        <div class="col-12 col-md-3">
            <label class="form-label fw-bold">MTC Required?</label>
            <div class="d-flex gap-3 mt-1">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mtc_required" id="mtcNo" value="No"
                           <?= ($t['mtc_required']??'No')==='No'?'checked':'' ?> onchange="toggleMTC()">
                    <label class="form-check-label fw-semibold text-secondary" for="mtcNo">No</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mtc_required" id="mtcYes" value="Yes"
                           <?= ($t['mtc_required']??'')==='Yes'?'checked':'' ?> onchange="toggleMTC()">
                    <label class="form-check-label fw-semibold text-success" for="mtcYes">Yes</label>
                </div>
            </div>
        </div>
    </div>
    <div id="mtcDetails" style="display:<?= ($t['mtc_required']??'No')==='Yes'?'block':'none' ?>">
    <hr class="my-3">
    <div class="alert alert-warning py-2 mb-3 d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>Fill in test results. These will print as a <strong>Material Test Certificate</strong> attached to the Trip Challan.</span>
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label">Source of Material</label>
            <input type="text" name="mtc_source" class="form-control" value="<?= htmlspecialchars($t['mtc_source']??'') ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">Item Name</label>
            <input type="text" name="mtc_item_name" class="form-control" value="<?= htmlspecialchars($t['mtc_item_name']??'') ?>">
        </div>
        <div class="col-12 col-md-2">
            <label class="form-label">Test Date</label>
            <input type="date" name="mtc_test_date" class="form-control" value="<?= htmlspecialchars($t['mtc_test_date']??date('Y-m-d')) ?>">
        </div>
    </div>
    <h6 class="fw-bold mt-3 mb-2 text-warning"><i class="bi bi-table me-1"></i>Test Results</h6>
    <div class="table-responsive">
    <table class="table table-bordered align-middle mb-0" style="font-size:.88rem">
    <thead class="table-warning">
    <tr><th style="width:40%">TEST</th><th style="width:25%">RESULTS (%)</th><th>IS 3812 Requirement</th></tr>
    </thead>
    <tbody>
    <tr>
        <td class="fw-semibold">ROS 45 Micron Sieve</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_ros_45" class="form-control" placeholder="e.g. 28.5" value="<?= htmlspecialchars($t['mtc_ros_45']??'') ?>"><span class="input-group-text">%</span></div></td>
        <td class="text-muted">&lt; 34%</td>
    </tr>
    <tr>
        <td class="fw-semibold">Moisture</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_moisture" class="form-control" placeholder="e.g. 0.8" value="<?= htmlspecialchars($t['mtc_moisture']??'') ?>"><span class="input-group-text">%</span></div></td>
        <td class="text-muted">&lt; 2%</td>
    </tr>
    <tr>
        <td class="fw-semibold">Loss on Ignition</td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_loi" class="form-control" placeholder="e.g. 3.2" value="<?= htmlspecialchars($t['mtc_loi']??'') ?>"><span class="input-group-text">%</span></div></td>
        <td class="text-muted">&lt; 5%</td>
    </tr>
    <tr>
        <td class="fw-semibold">Fineness – Specific Surface Area<br><small class="text-muted">by Blaine's Permeability Method</small></td>
        <td><div class="input-group input-group-sm"><input type="text" name="mtc_fineness" class="form-control" placeholder="e.g. 380" value="<?= htmlspecialchars($t['mtc_fineness']??'') ?>"><span class="input-group-text">m²/kg</span></div></td>
        <td class="text-muted">&gt; 320 m²/kg</td>
    </tr>
    </tbody>
    </table>
    </div>
    <div class="row g-3 mt-2">
        <div class="col-12 col-md-6">
            <label class="form-label">Remarks / Observations</label>
            <input type="text" name="mtc_remarks" class="form-control" value="<?= htmlspecialchars($t['mtc_remarks']??'') ?>">
        </div>
    </div>
    </div><!-- /mtcDetails -->
    </div>
</div>
</div>

<!-- Remarks -->
<div class="col-12"><div class="card"><div class="card-body">
    <label class="form-label">Remarks</label>
    <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($t['remarks']??'') ?></textarea>
</div></div></div>

<div class="col-12 text-end">
    <a href="fleet_trips.php" class="btn btn-outline-secondary me-2">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Save Trip</button>
</div>
</div>
</form>

<script>
/* ── Data maps from server ── */
const poVendorMap  = <?= json_encode($po_vendor_map,   JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const poAddrMap    = <?= json_encode($po_addr_map,     JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const poRateMap    = <?= json_encode($po_rate_map,     JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const vendorDataMap= <?= json_encode($vendor_data_map, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const vehDriverMap = <?= json_encode($veh_driver_map,  JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

/* ── PO selected → auto-fill vendor + delivery address + freight rate ── */
function fillFromPO(poId) {
    poId = parseInt(poId) || 0;
    if (!poId) return;
    // Set vendor dropdown
    var vid = poVendorMap[poId] || 0;
    if (vid) {
        var vSel = document.getElementById('vendorSelect');
        if (vSel) { vSel.value = vid; fillFromVendor(vid); }
    }
    // Set To Location from PO delivery address
    var addr = poAddrMap[poId] || '';
    if (addr) {
        var toEl = document.querySelector('[name="to_location"]');
        if (toEl && !toEl.value) toEl.value = addr;
    }
    // Set rate in all item rows
    var rate = poRateMap[poId] || 0;
    window._poFreightRate = rate;
    if (rate > 0) {
        document.querySelectorAll('.item-price').forEach(function(el) {
            el.value = rate.toFixed(2);
        });
        calcItemsTotal();
    }
    calcFreightFromWeight();
}

/* ── Vendor selected → auto-fill consignee ── */
function fillFromVendor(vid) {
    vid = parseInt(vid) || 0;
    var data = vendorDataMap[vid] || {};
    document.getElementById('custName').value  = data.name    || '';
    document.getElementById('custAddr').value  = data.address || '';
    document.getElementById('custCity').value  = data.city    || '';
    document.getElementById('custState').value = data.state   || '';
    document.getElementById('custGst').value   = data.gstin   || '';
}

/* ── Vehicle selected → auto-fill driver ── */
function fillDriverFromVehicle(vid) {
    vid = parseInt(vid) || 0;
    var did = vehDriverMap[vid] || 0;
    if (did) {
        var dSel = document.getElementById('driverSelect');
        if (dSel) dSel.value = did;
    }
}

/* ── Item selected → fill UOM + name ── */
function fillItemUom(sel) {
    var opt  = sel.options[sel.selectedIndex];
    var tr   = sel.closest('tr');
    var uom  = opt ? (opt.getAttribute('data-uom') || '') : '';
    var name = opt ? (opt.getAttribute('data-name') || '') : '';
    var uomEl  = tr.querySelector('.item-uom');
    var nameEl = tr.querySelector('.item-name-hidden');
    if (uomEl)  uomEl.value  = uom;
    if (nameEl) nameEl.value = name;
}

/* ── Row calculations ── */
function calcItemRow(el) {
    var tr  = el.closest('tr');
    var qty = parseFloat(tr.querySelector('.item-qty').value)   || 0;
    var prc = parseFloat(tr.querySelector('.item-price').value) || 0;
    tr.querySelector('.item-amt').value = (qty * prc).toFixed(2);
    calcItemsTotal();
}

function updateTotalWeight() {
    var total = 0;
    document.querySelectorAll('.item-wt').forEach(function(el) { total += parseFloat(el.value) || 0; });
    var twEl = document.getElementById('totalWeight');
    if (twEl) twEl.value = total.toFixed(3);
    calcFreightFromWeight();
}

function calcFreightFromWeight() {
    var rate = window._poFreightRate || 0;
    if (!rate) return;
    var wt = parseFloat(document.getElementById('totalWeight')?.value) || 0;
    if (wt > 0) {
        var freightEl = document.getElementById('freightAmount');
        if (freightEl) freightEl.value = (rate * wt).toFixed(2);
    }
}

function calcItemsTotal() {
    var total = 0;
    document.querySelectorAll('.item-amt').forEach(function(el) { total += parseFloat(el.value) || 0; });
    document.getElementById('itemsTotal').textContent = '₹' + total.toFixed(2);
}

/* ── Add item row ── */
function addItemRow() {
    var tbody = document.getElementById('itemsBody');
    var tmpl  = tbody.querySelector('.item-row');
    var tr    = tmpl.cloneNode(true);
    tr.querySelector('select').selectedIndex = 0;
    tr.querySelectorAll('input[type="number"]').forEach(function(i) { i.value = '0'; });
    tr.querySelectorAll('input[type="text"]').forEach(function(i) { i.value = ''; });
    tr.querySelectorAll('input[type="hidden"]').forEach(function(i) { i.value = ''; });
    // Pre-fill rate from current PO
    var rate = window._poFreightRate || 0;
    if (rate > 0) {
        var priceEl = tr.querySelector('.item-price');
        if (priceEl) priceEl.value = rate.toFixed(2);
    }
    tbody.appendChild(tr);
}

function removeItemRow(btn) {
    var rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) { btn.closest('tr').remove(); calcItemsTotal(); updateTotalWeight(); }
}

/* ── MTC toggle ── */
function toggleMTC() {
    var yes = document.getElementById('mtcYes').checked;
    document.getElementById('mtcDetails').style.display = yes ? 'block' : 'none';
}

/* ── Init ── */
window._poFreightRate = 0;
<?php if ($id > 0 && !empty($t['po_id'])): ?>
window._poFreightRate = <?= (float)($po_rate_map[$t['po_id']] ?? 0) ?>;
<?php endif; ?>
calcItemsTotal();
updateTotalWeight();
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
