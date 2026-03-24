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

/* ── Add columns if upgrading from old schema ── */
fleetSafeAddCol($db, 'fleet_trips', 'po_id',             'INT DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'vendor_id',          'INT DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'subtotal',           'DECIMAL(12,2) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'total_amount',       'DECIMAL(12,2) DEFAULT 0');
fleetSafeAddCol($db, 'fleet_trips', 'mtc_required',       "ENUM('No','Yes') DEFAULT 'No'");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_source',         "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_item_name',      "VARCHAR(120) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_test_date',      'DATE DEFAULT NULL');
fleetSafeAddCol($db, 'fleet_trips', 'mtc_ros_45',         "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_moisture',       "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_loi',            "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_fineness',       "VARCHAR(30) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'mtc_remarks',        "VARCHAR(255) DEFAULT ''");
fleetSafeAddCol($db, 'fleet_trips', 'company_id',          'INT DEFAULT 1');

$db->query("CREATE TABLE IF NOT EXISTS fleet_trip_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    trip_id     INT NOT NULL,
    item_id     INT DEFAULT NULL,
    item_name   VARCHAR(200),
    description VARCHAR(200),
    qty         DECIMAL(12,3) DEFAULT 0,
    uom         VARCHAR(20) DEFAULT 'MT',
    unit_price  DECIMAL(12,2) DEFAULT 0,
    weight      DECIMAL(10,3) DEFAULT 0,
    amount      DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS fleet_customers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    address     TEXT,
    city        VARCHAR(80),
    state       VARCHAR(80),
    gstin       VARCHAR(20),
    phone       VARCHAR(20),
    status      ENUM('Active','Inactive') DEFAULT 'Active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Trip number generator (FY-aware, format TR/25-26/0001) ── */
function generateTripNo($db) {
    $month    = (int)date('m');
    $year     = (int)date('Y');
    $fy_start = $month >= 4 ? $year : $year - 1;
    $fy_end   = $fy_start + 1;
    $fy_label = ($fy_start % 100) . '-' . str_pad($fy_end % 100, 2, '0', STR_PAD_LEFT);
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
    $allowed = ['Planned', 'In Transit', 'Completed', 'Cancelled'];
    if (in_array($ns, $allowed)) {
        $extra = '';
        if ($ns === 'In Transit') $extra = ", start_date='" . date('Y-m-d') . "'";
        if ($ns === 'Completed')  $extra = ", end_date='"   . date('Y-m-d') . "'";
        $db->query("UPDATE fleet_trips SET status='$ns'$extra WHERE id=$id");
        // Update PO status when trip completed
        if ($ns === 'Completed') {
            $trip = $db->query("SELECT po_id FROM fleet_trips WHERE id=$id LIMIT 1")->fetch_assoc();
            if (!empty($trip['po_id'])) {
                $po_id = (int)$trip['po_id'];
                $db->query("UPDATE fleet_purchase_orders SET status='Partially Received'
                    WHERE id=$po_id AND status='Approved'");
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
    $po_id     = (int)($_POST['po_id'] ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $veh_id    = (int)$_POST['vehicle_id'];
    $drv_id    = (int)$_POST['driver_id'];
    $sup_id    = (int)($_POST['supervisor_id'] ?? 0);
    $from      = sanitize($_POST['from_location'] ?? '');
    $to        = sanitize($_POST['to_location'] ?? '');
    $cust_name = sanitize($_POST['customer_name'] ?? '');
    $cust_addr = sanitize($_POST['customer_address'] ?? '');
    $cust_city = sanitize($_POST['customer_city'] ?? '');
    $cust_state= sanitize($_POST['customer_state'] ?? '');
    $cust_gst  = sanitize($_POST['customer_gstin'] ?? '');
    $uom       = sanitize($_POST['uom'] ?? 'MT');
    $start_odo = (int)($_POST['start_odometer'] ?? 0);
    $end_odo   = (int)($_POST['end_odometer'] ?? 0);
    $start_dt  = sanitize($_POST['start_date'] ?? '');
    $end_dt    = sanitize($_POST['end_date'] ?? '');
    $freight   = (float)($_POST['freight_amount'] ?? 0);
    $advance   = (float)($_POST['driver_advance'] ?? 0);
    $toll      = (float)($_POST['toll_amount'] ?? 0);
    $loading   = (float)($_POST['loading_charges'] ?? 0);
    $unloading = (float)($_POST['unloading_charges'] ?? 0);
    $other     = (float)($_POST['other_expenses'] ?? 0);
    $status    = sanitize($_POST['status'] ?? 'Planned');
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
    $item_ids   = $_POST['item_id']    ?? [];
    $item_names = $_POST['item_name']  ?? [];
    $item_descs = $_POST['item_desc']  ?? [];
    $item_qtys  = $_POST['item_qty']   ?? [];
    $item_uoms  = $_POST['item_uom']   ?? [];
    $item_prices= $_POST['item_price'] ?? [];
    $item_wts   = $_POST['item_weight']?? [];

    $subtotal    = 0;
    $total_weight= 0;
    $valid_items = [];
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
            'description'=> $db->real_escape_string($item_descs[$idx] ?? ''),
            'qty'       => $qty,
            'uom'       => $db->real_escape_string($item_uoms[$idx] ?? 'MT'),
            'unit_price'=> $price,
            'weight'    => $wt,
            'amount'    => $amt,
        ];
    }
    $total_amount = round($subtotal, 2);

    $po_sql    = $po_id     ? $po_id     : 'NULL';
    $vend_sql  = $vendor_id ? $vendor_id : 'NULL';
    $sup_sql   = $sup_id    ? $sup_id    : 'NULL';
    $start_sql = $start_dt  ? "'$start_dt'" : 'NULL';
    $end_sql   = $end_dt    ? "'$end_dt'"   : 'NULL';

    if (!$trip_date || !$veh_id || !$drv_id) {
        showAlert('danger', 'Trip Date, Vehicle and Driver are required.');
        redirect("fleet_trips.php?action=" . ($id > 0 ? "edit&id=$id" : 'add'));
    }

    /* Save customer to master */
    if ($cust_name && !empty($_POST['save_customer'])) {
        $ec = $db->query("SELECT id FROM fleet_customers WHERE name='" . $db->real_escape_string($cust_name) . "' LIMIT 1")->fetch_assoc();
        if (!$ec) $db->query("INSERT INTO fleet_customers (name,address,city,state,gstin)
            VALUES ('$cust_name','$cust_addr','$cust_city','$cust_state','$cust_gst')");
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
        $db->query("INSERT INTO fleet_trip_items
            (trip_id,item_id,item_name,description,qty,uom,unit_price,weight,amount)
            VALUES ($id,$iid,'{$row['item_name']}','{$row['description']}',
            {$row['qty']},'{$row['uom']}',{$row['unit_price']},{$row['weight']},{$row['amount']})");
    }

    showAlert('success', $id > 0 ? 'Trip updated.' : 'Trip created.');
    redirect('fleet_trips.php?action=view&id=' . $id);
}

/* ── Data for dropdowns ── */
$vehicles    = $db->query("SELECT id,reg_no,make,model FROM fleet_vehicles WHERE status='Active' ORDER BY reg_no")->fetch_all(MYSQLI_ASSOC);
$drivers     = $db->query("SELECT id,full_name,role FROM fleet_drivers WHERE status='Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$supervisors = array_filter($drivers, fn($d) => in_array($d['role'], ['Supervisor', 'Driver+Supervisor']));
$customers   = $db->query("SELECT * FROM fleet_customers WHERE status='Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$vendors     = $db->query("SELECT id,vendor_name FROM fleet_customers_master WHERE status='Active' ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$pos         = $db->query("SELECT id,po_number,vendor_id FROM fleet_purchase_orders WHERE status IN ('Approved','Partially Received') ORDER BY po_date DESC")->fetch_all(MYSQLI_ASSOC);
$items_list  = $db->query("SELECT id,item_code,item_name,uom FROM items WHERE status='Active' ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);

/* PO items map for auto-fill */
$po_items_map = [];
$po_vendor_map = [];
foreach ($pos as $p) $po_vendor_map[$p['id']] = $p['vendor_id'];
$po_items_res = $db->query("SELECT pi.po_id, pi.item_name, pi.unit_price, pi.qty, pi.uom
    FROM fleet_po_items pi")->fetch_all(MYSQLI_ASSOC);
foreach ($po_items_res as $pr) {
    $po_items_map[$pr['po_id']][] = $pr;
}

$all_companies = getAllCompanies();
include '../includes/header.php';
?>
<script>document.getElementById('page-title').innerHTML='<i class="bi bi-signpost-split me-2"></i>Trip Orders';</script>
<?php
$status_colors = ['Planned'=>'secondary','In Transit'=>'warning','Completed'=>'success','Cancelled'=>'danger'];

/* ══════════════════════════════════
   LIST
══════════════════════════════════ */
if ($action === 'list'):
$trips = $db->query("SELECT t.*, v.reg_no, v.make, v.model,
    d.full_name AS driver_name, s.full_name AS supervisor_name,
    p.po_number, vn.vendor_name, co.company_name
    FROM fleet_trips t
    LEFT JOIN fleet_vehicles v ON t.vehicle_id=v.id
    LEFT JOIN fleet_drivers d ON t.driver_id=d.id
    LEFT JOIN fleet_drivers s ON t.supervisor_id=s.id
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
<div class="card-body py-2 d-flex gap-1 flex-wrap align-items-center">
    <span class="text-muted me-1" style="font-size:.85rem">Filter:</span>
    <button class="btn btn-sm btn-dark trip-filter active" data-status="All" onclick="tripFilter('All',this)">All <span class="badge bg-secondary ms-1"><?= count($trips) ?></span></button>
    <?php foreach ($status_colors as $st => $sc): if (!isset($counts[$st])) continue; ?>
    <button class="btn btn-sm btn-outline-<?= $sc ?> trip-filter" data-status="<?= $st ?>" onclick="tripFilter('<?= $st ?>',this)">
        <?= $st ?> <span class="badge bg-<?= $sc ?> ms-1"><?= $counts[$st] ?></span>
    </button>
    <?php endforeach; ?>
</div>
</div>
<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover mb-0" id="tripsTable">
<thead><tr>
    <th>Trip No</th><th>Date</th><th>Company</th><th>Customer PO</th><th>Customer</th>
    <th>Vehicle</th><th>Driver</th><th>From → To</th>
    <th>Customer</th><th class="text-end">Weight</th>
    <th class="text-end">Freight</th><th>Status</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($trips as $t):
    $sc = $status_colors[$t['status']] ?? 'secondary';
?>
<tr data-status="<?= $t['status'] ?>">
    <td><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td>
    <td><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
    <td><?php if (count($all_companies)>1): ?><span class='badge bg-primary' style='font-size:.7rem'><?= htmlspecialchars($t['company_name']??'-') ?></span><?php else: ?>—<?php endif; ?></td>
    <td><?= $t['po_number'] ? '<span class="badge bg-info text-dark">'.htmlspecialchars($t['po_number']).'</span>' : '—' ?></td>
    <td><?= htmlspecialchars($t['vendor_name'] ?? '—') ?></td>
    <td><span class="badge bg-dark"><?= htmlspecialchars($t['reg_no']) ?></span></td>
    <td><?= htmlspecialchars($t['driver_name']) ?></td>
    <td><?= htmlspecialchars($t['from_location']) ?><br><small class="text-muted"><?= htmlspecialchars($t['to_location']) ?></small></td>
    <td><?= htmlspecialchars($t['customer_name']) ?><br><small class="text-muted"><?= htmlspecialchars($t['customer_city'] ?? '') ?></small></td>
    <td class="text-end"><?= number_format($t['total_weight'],3) ?> MT</td>
    <td class="text-end">₹<?= number_format($t['freight_amount'],2) ?></td>
    <td><span class="badge bg-<?= $sc ?>"><?= $t['status'] ?></span></td>
    <td>
        <a href="?action=view&id=<?= $t['id'] ?>" class="btn btn-action btn-outline-info me-1"><i class="bi bi-eye"></i></a>
        <?php if (canDo('fleet_trips','update') && !in_array($t['status'],['Completed','Cancelled'])): ?>
        <a href="?action=edit&id=<?= $t['id'] ?>" class="btn btn-action btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <a href="fleet_trip_challan.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-action btn-outline-secondary me-1" title="Print"><i class="bi bi-printer"></i></a>
        <?php if (isAdmin() && $t['status'] === 'Planned'): ?>
        <a href="?delete=<?= $t['id'] ?>" onclick="return confirm('Delete this trip?')" class="btn btn-action btn-outline-danger"><i class="bi bi-trash"></i></a>
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
/* ══════════════════════════════════
   VIEW
══════════════════════════════════ */
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
$total_exp = $t['toll_amount'] + $t['loading_charges'] + $t['unloading_charges'] + $t['other_expenses'];
$km  = ($t['end_odometer'] > $t['start_odometer']) ? ($t['end_odometer'] - $t['start_odometer']) : 0;
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
        <a href="fleet_trip_challan.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print Challan</a>
        <?php if (canDo('fleet_trips','update') && !in_array($t['status'],['Completed','Cancelled'])): ?>
        <a href="?action=edit&id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="fleet_trips.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>
<div class="row g-3">

<div class="col-12 col-md-6">
<div class="card h-100"><div class="card-header"><i class="bi bi-info-circle me-2"></i>Trip Details</div>
<div class="card-body"><table class="table table-sm mb-0">
    <?php if (count($all_companies)>1): ?><tr><td class="text-muted" style="width:45%">Company</td><td><span class="badge bg-primary"><?= htmlspecialchars($t['company_name']??'—') ?></span></td></tr><?php endif; ?>
    <tr><td class="text-muted">Trip No</td><td><strong><?= htmlspecialchars($t['trip_no']) ?></strong></td></tr>
    <tr><td class="text-muted">Trip Date</td><td><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td></tr>
    <?php if ($t['po_number']): ?>
    <tr><td class="text-muted">PO Reference</td><td><span class="badge bg-info text-dark"><?= htmlspecialchars($t['po_number']) ?></span></td></tr>
    <?php endif; ?>
    <?php if ($t['vendor_name']): ?>
    <tr><td class="text-muted">Customer / Buyer</td><td><?= htmlspecialchars($t['vendor_name']) ?></td></tr>
    <?php endif; ?>
    <tr><td class="text-muted">Vehicle</td><td><strong><?= htmlspecialchars($t['reg_no']) ?></strong> <?= htmlspecialchars($t['make'].' '.$t['model']) ?></td></tr>
    <tr><td class="text-muted">Driver</td><td><?= htmlspecialchars($t['driver_name']) ?><?= $t['driver_phone'] ? ' <small class="text-muted">('.$t['driver_phone'].')</small>' : '' ?></td></tr>
    <?php if ($t['supervisor_name']): ?><tr><td class="text-muted">Supervisor</td><td><?= htmlspecialchars($t['supervisor_name']) ?></td></tr><?php endif; ?>
    <tr><td class="text-muted">From</td><td><?= htmlspecialchars($t['from_location']) ?></td></tr>
    <tr><td class="text-muted">To</td><td><?= htmlspecialchars($t['to_location']) ?></td></tr>
    <tr><td class="text-muted">Start Date</td><td><?= $t['start_date'] ? date('d/m/Y',strtotime($t['start_date'])) : '—' ?></td></tr>
    <tr><td class="text-muted">End Date</td><td><?= $t['end_date'] ? date('d/m/Y',strtotime($t['end_date'])) : '—' ?></td></tr>
    <tr><td class="text-muted">Odometer</td><td><?= number_format($t['start_odometer']) ?> → <?= number_format($t['end_odometer']) ?><?= $km > 0 ? " <span class='badge bg-info ms-1'>$km km</span>" : '' ?></td></tr>
</table></div></div></div>

<div class="col-12 col-md-6">
<div class="card h-100"><div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Customer Details</div>
<div class="card-body"><table class="table table-sm mb-0">
    <tr><td class="text-muted" style="width:45%">Customer</td><td><strong><?= htmlspecialchars($t['customer_name']) ?></strong></td></tr>
    <tr><td class="text-muted">Address</td><td><?= htmlspecialchars($t['customer_address'] ?? '—') ?></td></tr>
    <tr><td class="text-muted">City / State</td><td><?= htmlspecialchars(($t['customer_city']??'').' '.($t['customer_state']??'')) ?></td></tr>
    <tr><td class="text-muted">GSTIN</td><td><?= htmlspecialchars($t['customer_gstin'] ?? '—') ?></td></tr>
    <tr><td class="text-muted">Total Weight</td><td><strong><?= number_format($t['total_weight'],3) ?> MT</strong></td></tr>
</table></div></div></div>

<?php if ($trip_items): ?>
<div class="col-12">
<div class="card"><div class="card-header"><i class="bi bi-list-ul me-2"></i>Items / Materials</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0">
<thead><tr><th>#</th><th>Item</th><th>Description</th><th>UOM</th><th class="text-end">Qty</th><th class="text-end">Weight (MT)</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr></thead>
<tbody>
<?php $ri=1; foreach ($trip_items as $ti): ?>
<tr>
    <td><?= $ri++ ?></td>
    <td><?= htmlspecialchars($ti['item_name']) ?><?= $ti['item_code'] ? '<br><small class="text-muted">'.$ti['item_code'].'</small>' : '' ?></td>
    <td><?= htmlspecialchars($ti['description']) ?></td>
    <td><?= htmlspecialchars($ti['uom']) ?></td>
    <td class="text-end"><?= number_format($ti['qty'],3) ?></td>
    <td class="text-end"><?= number_format($ti['weight'],3) ?></td>
    <td class="text-end">₹<?= number_format($ti['unit_price'],2) ?></td>
    <td class="text-end"><strong>₹<?= number_format($ti['amount'],2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot class="table-light">
<tr><td colspan="5" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold"><?= number_format($t['total_weight'],3) ?> MT</td>
    <td></td>
    <td class="text-end fw-bold">₹<?= number_format($t['subtotal'],2) ?></td>
</tr>
</tfoot>
</table>
</div></div></div></div>
<?php endif; ?>

<div class="col-12">
<div class="card"><div class="card-header"><i class="bi bi-cash-coin me-2"></i>Financial Summary</div>
<div class="card-body">
<div class="row g-2 text-center mb-3">
    <div class="col-6 col-md-2"><div class="card bg-light p-2"><small class="text-muted">Freight</small><div class="fw-bold text-success">₹<?= number_format($t['freight_amount'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card bg-light p-2"><small class="text-muted">Driver Advance</small><div class="fw-bold text-warning">₹<?= number_format($t['driver_advance'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card bg-light p-2"><small class="text-muted">Toll</small><div class="fw-bold">₹<?= number_format($t['toll_amount'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card bg-light p-2"><small class="text-muted">Loading</small><div class="fw-bold">₹<?= number_format($t['loading_charges'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card bg-light p-2"><small class="text-muted">Unloading</small><div class="fw-bold">₹<?= number_format($t['unloading_charges'],2) ?></div></div></div>
    <div class="col-6 col-md-2"><div class="card bg-light p-2"><small class="text-muted">Other</small><div class="fw-bold">₹<?= number_format($t['other_expenses'],2) ?></div></div></div>
</div>
<div class="p-3 rounded" style="background:#e5f5eb">
<div class="row text-center g-2">
    <div class="col-6 col-md-3"><small class="text-muted">Total Expenses</small><div class="fw-bold text-danger fs-6">₹<?= number_format($total_exp,2) ?></div></div>
    <div class="col-6 col-md-3"><small class="text-muted">Net (Freight − Expenses)</small><div class="fw-bold text-success fs-6">₹<?= number_format($t['freight_amount'] - $total_exp,2) ?></div></div>
</div>
</div>
</div></div></div>

<?php if ($t['mtc_required'] === 'Yes'): ?>
<div class="col-12">
<div class="card border-info"><div class="card-header bg-info text-white"><i class="bi bi-clipboard-check me-2"></i>Material Test Certificate (MTC)</div>
<div class="card-body"><div class="row g-2">
    <div class="col-6 col-md-3"><small class="text-muted">Source</small><div><?= htmlspecialchars($t['mtc_source']??'—') ?></div></div>
    <div class="col-6 col-md-3"><small class="text-muted">Item Name</small><div><?= htmlspecialchars($t['mtc_item_name']??'—') ?></div></div>
    <div class="col-6 col-md-2"><small class="text-muted">Test Date</small><div><?= $t['mtc_test_date'] ? date('d/m/Y',strtotime($t['mtc_test_date'])) : '—' ?></div></div>
    <div class="col-6 col-md-1"><small class="text-muted">RoS 45μ</small><div><?= htmlspecialchars($t['mtc_ros_45']??'—') ?></div></div>
    <div class="col-6 col-md-1"><small class="text-muted">Moisture</small><div><?= htmlspecialchars($t['mtc_moisture']??'—') ?></div></div>
    <div class="col-6 col-md-1"><small class="text-muted">LOI</small><div><?= htmlspecialchars($t['mtc_loi']??'—') ?></div></div>
    <div class="col-6 col-md-1"><small class="text-muted">Fineness</small><div><?= htmlspecialchars($t['mtc_fineness']??'—') ?></div></div>
    <?php if ($t['mtc_remarks']): ?><div class="col-12"><small class="text-muted">MTC Remarks</small><div><?= htmlspecialchars($t['mtc_remarks']) ?></div></div><?php endif; ?>
</div></div></div></div>
<?php endif; ?>

<?php if ($t['remarks']): ?>
<div class="col-12"><div class="card"><div class="card-body"><strong>Remarks:</strong> <?= htmlspecialchars($t['remarks']) ?></div></div></div>
<?php endif; ?>
</div>

<?php
/* ══════════════════════════════════
   ADD / EDIT
══════════════════════════════════ */
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

<!-- Trip Info -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-signpost-split me-2"></i>Trip Information</div>
<div class="card-body"><div class="row g-3">
    <?php if (count($all_companies) > 1): ?>
    <div class="col-6 col-md-3">
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
        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($t['trip_no']) ?>" readonly>
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
        <select name="vendor_id" id="vendorSelect" class="form-select">
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
        <select name="vehicle_id" class="form-select" required>
            <option value="">— Select Vehicle —</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= ($t['vehicle_id']??0)==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['reg_no'].' '.$v['make'].' '.$v['model']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label fw-bold">Driver *</label>
        <select name="driver_id" class="form-select" required>
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
    <div class="col-6 col-md-3">
        <label class="form-label">From Location</label>
        <input type="text" name="from_location" class="form-control" value="<?= htmlspecialchars($t['from_location']??'') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">To Location</label>
        <input type="text" name="to_location" class="form-control" value="<?= htmlspecialchars($t['to_location']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Start Date</label>
        <input type="date" name="start_date" class="form-control" value="<?= $t['start_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">End Date</label>
        <input type="date" name="end_date" class="form-control" value="<?= $t['end_date']??'' ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Start Odometer (km)</label>
        <input type="number" name="start_odometer" class="form-control" value="<?= $t['start_odometer']??0 ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">End Odometer (km)</label>
        <input type="number" name="end_odometer" class="form-control" value="<?= $t['end_odometer']??0 ?>">
    </div>
</div></div></div></div>

<!-- Customer -->
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-person-lines-fill me-2"></i>Customer / Consignee</span>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#custModal"><i class="bi bi-search me-1"></i>Select</button>
</div>
<div class="card-body"><div class="row g-3">
    <div class="col-12 col-md-4">
        <label class="form-label fw-bold">Customer Name</label>
        <input type="text" name="customer_name" id="custName" class="form-control" value="<?= htmlspecialchars($t['customer_name']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">City</label>
        <input type="text" name="customer_city" id="custCity" class="form-control" value="<?= htmlspecialchars($t['customer_city']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">State</label>
        <input type="text" name="customer_state" id="custState" class="form-control" value="<?= htmlspecialchars($t['customer_state']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">GSTIN</label>
        <input type="text" name="customer_gstin" id="custGst" class="form-control" value="<?= htmlspecialchars($t['customer_gstin']??'') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">Address</label>
        <textarea name="customer_address" id="custAddr" class="form-control" rows="2"><?= htmlspecialchars($t['customer_address']??'') ?></textarea>
    </div>
    <div class="col-12">
        <div class="form-check">
            <input type="checkbox" name="save_customer" class="form-check-input" id="saveCust" value="1">
            <label class="form-check-label text-muted" for="saveCust">Save to customer master</label>
        </div>
    </div>
</div></div></div></div>

<!-- Items -->
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Items / Materials</span>
    <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm mb-0" id="itemsTable">
<thead class="table-light"><tr>
    <th>Item</th><th>Description</th><th>UOM</th>
    <th style="width:90px">Qty</th><th style="width:100px">Weight (MT)</th>
    <th style="width:110px">Rate (₹)</th><th style="width:110px">Amount (₹)</th>
    <th style="width:36px"></th>
</tr></thead>
<tbody id="itemsBody">
<?php if ($trip_items): foreach ($trip_items as $ti): ?>
<tr class="item-row">
    <td>
        <select name="item_id[]" class="form-select form-select-sm" onchange="fillItemName(this)">
            <option value="">— Select —</option>
            <?php foreach ($items_list as $il): ?>
            <option value="<?= $il['id'] ?>" data-name="<?= htmlspecialchars($il['item_name']) ?>" data-uom="<?= $il['uom'] ?>"
                <?= ($ti['item_id']??0)==$il['id']?'selected':'' ?>>
                <?= htmlspecialchars($il['item_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="item_name[]" class="form-control form-control-sm mt-1" placeholder="Or type name" value="<?= htmlspecialchars($ti['item_name']) ?>">
    </td>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($ti['description']??'') ?>"></td>
    <td><select name="item_uom[]" class="form-select form-select-sm">
        <?php foreach (['MT','Kg','Litre','Nos','Bags'] as $u): ?>
        <option <?= ($ti['uom']??'MT')===$u?'selected':'' ?>><?= $u ?></option>
        <?php endforeach; ?>
    </select></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm row-qty" step="0.001" value="<?= $ti['qty']??'' ?>" onchange="calcRow(this)"></td>
    <td><input type="number" name="item_weight[]" class="form-control form-control-sm row-wt" step="0.001" value="<?= $ti['weight']??'' ?>" onchange="calcTotals()"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm row-price" step="0.01" value="<?= $ti['unit_price']??'' ?>" onchange="calcRow(this)"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm row-amt bg-light" readonly value="<?= $ti['amount']??'' ?>"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endforeach; else: ?>
<tr class="item-row">
    <td>
        <select name="item_id[]" class="form-select form-select-sm" onchange="fillItemName(this)">
            <option value="">— Select —</option>
            <?php foreach ($items_list as $il): ?>
            <option value="<?= $il['id'] ?>" data-name="<?= htmlspecialchars($il['item_name']) ?>" data-uom="<?= $il['uom'] ?>"><?= htmlspecialchars($il['item_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="item_name[]" class="form-control form-control-sm mt-1" placeholder="Or type name">
    </td>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm"></td>
    <td><select name="item_uom[]" class="form-select form-select-sm">
        <?php foreach (['MT','Kg','Litre','Nos','Bags'] as $u): ?><option><?= $u ?></option><?php endforeach; ?>
    </select></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm row-qty" step="0.001" onchange="calcRow(this)"></td>
    <td><input type="number" name="item_weight[]" class="form-control form-control-sm row-wt" step="0.001" onchange="calcTotals()"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm row-price" step="0.01" onchange="calcRow(this)"></td>
    <td><input type="text" name="item_amount[]" class="form-control form-control-sm row-amt bg-light" readonly></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
</tr>
<?php endif; ?>
</tbody>
<tfoot class="table-light">
<tr>
    <td colspan="4" class="text-end fw-bold">Totals</td>
    <td><strong id="footWeight">0.000 MT</strong></td>
    <td></td>
    <td><strong id="footAmount">₹0.00</strong></td>
    <td></td>
</tr>
</tfoot>
</table>
</div></div></div></div>

<!-- Financial -->
<div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-cash-coin me-2"></i>Financial Details</div>
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-2">
        <label class="form-label">Freight Amount (₹)</label>
        <input type="number" name="freight_amount" class="form-control" step="0.01" value="<?= $t['freight_amount']??0 ?>" onchange="calcExp()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Driver Advance (₹)</label>
        <input type="number" name="driver_advance" class="form-control" step="0.01" value="<?= $t['driver_advance']??0 ?>" onchange="calcExp()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Toll (₹)</label>
        <input type="number" name="toll_amount" class="form-control" step="0.01" value="<?= $t['toll_amount']??0 ?>" onchange="calcExp()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Loading (₹)</label>
        <input type="number" name="loading_charges" class="form-control" step="0.01" value="<?= $t['loading_charges']??0 ?>" onchange="calcExp()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Unloading (₹)</label>
        <input type="number" name="unloading_charges" class="form-control" step="0.01" value="<?= $t['unloading_charges']??0 ?>" onchange="calcExp()">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Other (₹)</label>
        <input type="number" name="other_expenses" class="form-control" step="0.01" value="<?= $t['other_expenses']??0 ?>" onchange="calcExp()">
    </div>
    <div class="col-12">
        <div class="p-2 rounded bg-light d-flex gap-4 flex-wrap">
            <span>Total Expenses: <strong id="totalExpDisp">₹0.00</strong></span>
            <span>Net: <strong id="netAmtDisp">₹0.00</strong></span>
        </div>
    </div>
</div></div></div></div>

<!-- MTC -->
<div class="col-12"><div class="card"><div class="card-header">
    <div class="d-flex align-items-center gap-3">
        <span><i class="bi bi-clipboard-check me-2"></i>Material Test Certificate (MTC)</span>
        <div class="form-check form-switch mb-0">
            <input type="checkbox" name="mtc_required" class="form-check-input" id="mtcToggle" value="Yes"
                <?= ($t['mtc_required']??'No')==='Yes'?'checked':'' ?> onchange="toggleMTC()">
            <label class="form-check-label" for="mtcToggle">Required</label>
        </div>
    </div>
</div>
<div id="mtcSection" style="<?= ($t['mtc_required']??'No')==='Yes'?'':'display:none' ?>">
<div class="card-body"><div class="row g-3">
    <div class="col-6 col-md-3">
        <label class="form-label">Source / Plant</label>
        <input type="text" name="mtc_source" class="form-control" value="<?= htmlspecialchars($t['mtc_source']??'') ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Item Name</label>
        <input type="text" name="mtc_item_name" class="form-control" value="<?= htmlspecialchars($t['mtc_item_name']??'') ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label">Test Date</label>
        <input type="date" name="mtc_test_date" class="form-control" value="<?= $t['mtc_test_date']??'' ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">RoS 45μ (%)</label>
        <input type="text" name="mtc_ros_45" class="form-control" value="<?= htmlspecialchars($t['mtc_ros_45']??'') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Moisture (%)</label>
        <input type="text" name="mtc_moisture" class="form-control" value="<?= htmlspecialchars($t['mtc_moisture']??'') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">LOI (%)</label>
        <input type="text" name="mtc_loi" class="form-control" value="<?= htmlspecialchars($t['mtc_loi']??'') ?>">
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label">Fineness</label>
        <input type="text" name="mtc_fineness" class="form-control" value="<?= htmlspecialchars($t['mtc_fineness']??'') ?>">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label">MTC Remarks</label>
        <input type="text" name="mtc_remarks" class="form-control" value="<?= htmlspecialchars($t['mtc_remarks']??'') ?>">
    </div>
</div></div>
</div></div></div>

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

<!-- Customer Modal -->
<div class="modal fade" id="custModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Select Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body p-0">
    <input type="text" id="custSearch" class="form-control rounded-0" placeholder="Search..." oninput="filterCusts()">
    <table class="table table-hover mb-0" id="custTable"><tbody>
    <?php foreach ($customers as $cu): ?>
    <tr style="cursor:pointer" onclick="selectCust(<?= htmlspecialchars(json_encode($cu)) ?>)">
        <td><strong><?= htmlspecialchars($cu['name']) ?></strong></td>
        <td><?= htmlspecialchars($cu['city'].' '.$cu['state']) ?></td>
        <td><?= htmlspecialchars($cu['gstin']??'') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
</div></div></div>

<script>
var poItemsMap  = <?= json_encode($po_items_map) ?>;
var poVendorMap = <?= json_encode($po_vendor_map) ?>;
var itemsData   = <?php
    $idata = [];
    foreach ($items_list as $il) $idata[$il['id']] = ['name'=>$il['item_name'],'uom'=>$il['uom']];
    echo json_encode($idata);
?>;

/* PO auto-fill */
function fillFromPO(pid) {
    pid = parseInt(pid)||0;
    /* Set vendor */
    if (poVendorMap[pid]) {
        document.getElementById('vendorSelect').value = poVendorMap[pid];
    }
    /* Fill items */
    if (pid && poItemsMap[pid] && poItemsMap[pid].length > 0) {
        if (!confirm('Fill items from selected PO? This will replace current items.')) return;
        document.getElementById('itemsBody').innerHTML = '';
        poItemsMap[pid].forEach(function(it) {
            addItemRow(it);
        });
        calcTotals();
    }
}

/* Item row management */
function addItemRow(data) {
    data = data || {};
    var tbody = document.getElementById('itemsBody');
    var uoms  = ['MT','Kg','Litre','Nos','Bags'];
    var uomOpts = uoms.map(u => '<option'+(u===(data.uom||'MT')?' selected':'')+'>'+u+'</option>').join('');
    var itemOpts = '<option value="">— Select —</option>' +
        <?= json_encode(array_map(fn($il) => null, $items_list)) ?>.length > -1 ?
        Object.entries(itemsData).map(([id,it]) =>
            '<option value="'+id+'" data-name="'+it.name+'" data-uom="'+it.uom+'"'+(parseInt(data.item_id||0)==id?' selected':'')+'>'+it.name+'</option>'
        ).join('') : '';

    var tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
        '<td><select name="item_id[]" class="form-select form-select-sm" onchange="fillItemName(this)"><option value="">— Select —</option>'+
        Object.entries(itemsData).map(([id,it]) => '<option value="'+id+'" data-name="'+it.name+'" data-uom="'+it.uom+'"'+(parseInt(data.item_id||0)==parseInt(id)?' selected':'')+'>'+it.name+'</option>').join('')+
        '</select><input type="text" name="item_name[]" class="form-control form-control-sm mt-1" placeholder="Or type name" value="'+(data.item_name||'')+'"></td>'+
        '<td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="'+(data.description||'')+'"></td>'+
        '<td><select name="item_uom[]" class="form-select form-select-sm">'+uomOpts+'</select></td>'+
        '<td><input type="number" name="item_qty[]" class="form-control form-control-sm row-qty" step="0.001" value="'+(data.qty||'')+'" onchange="calcRow(this)"></td>'+
        '<td><input type="number" name="item_weight[]" class="form-control form-control-sm row-wt" step="0.001" value="" onchange="calcTotals()"></td>'+
        '<td><input type="number" name="item_price[]" class="form-control form-control-sm row-price" step="0.01" value="'+(data.unit_price||'')+'" onchange="calcRow(this)"></td>'+
        '<td><input type="text" name="item_amount[]" class="form-control form-control-sm row-amt bg-light" readonly value="'+(data.amount||'')+'"></td>'+
        '<td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>';
    tbody.appendChild(tr);
    if (data.qty && data.unit_price) calcRow(tr.querySelector('.row-qty'));
}

function fillItemName(sel) {
    var opt = sel.options[sel.selectedIndex];
    var tr  = sel.closest('tr');
    if (opt.value) {
        tr.querySelector('[name="item_name[]"]').value = opt.dataset.name || '';
        var uomSel = tr.querySelector('[name="item_uom[]"]');
        if (opt.dataset.uom) {
            for (var i=0; i<uomSel.options.length; i++) {
                if (uomSel.options[i].value === opt.dataset.uom) { uomSel.selectedIndex = i; break; }
            }
        }
    }
}

function removeRow(btn) {
    var rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) { btn.closest('tr').remove(); calcTotals(); }
}

function calcRow(el) {
    var tr  = el.closest('tr');
    var qty = parseFloat(tr.querySelector('.row-qty').value)   || 0;
    var prc = parseFloat(tr.querySelector('.row-price').value) || 0;
    tr.querySelector('.row-amt').value = (qty * prc).toFixed(2);
    calcTotals();
}

function calcTotals() {
    var totalWt = 0, totalAmt = 0;
    document.querySelectorAll('.item-row').forEach(function(tr) {
        totalWt  += parseFloat(tr.querySelector('.row-wt').value)  || 0;
        totalAmt += parseFloat(tr.querySelector('.row-amt').value) || 0;
    });
    document.getElementById('footWeight').textContent = totalWt.toFixed(3) + ' MT';
    document.getElementById('footAmount').textContent = '₹' + totalAmt.toFixed(2);
    calcExp();
}

function calcExp() {
    var frt  = parseFloat(document.querySelector('[name=freight_amount]').value)   || 0;
    var toll = parseFloat(document.querySelector('[name=toll_amount]').value)       || 0;
    var load = parseFloat(document.querySelector('[name=loading_charges]').value)   || 0;
    var unld = parseFloat(document.querySelector('[name=unloading_charges]').value) || 0;
    var oth  = parseFloat(document.querySelector('[name=other_expenses]').value)    || 0;
    var total= toll + load + unld + oth;
    document.getElementById('totalExpDisp').textContent = '₹' + total.toFixed(2);
    document.getElementById('netAmtDisp').textContent   = '₹' + (frt - total).toFixed(2);
}

function toggleMTC() {
    var checked = document.getElementById('mtcToggle').checked;
    document.getElementById('mtcSection').style.display = checked ? '' : 'none';
    if (!checked) document.querySelector('[name=mtc_required]').value = 'No';
}

function selectCust(c) {
    document.getElementById('custName').value  = c.name  || '';
    document.getElementById('custAddr').value  = c.address || '';
    document.getElementById('custCity').value  = c.city  || '';
    document.getElementById('custState').value = c.state || '';
    document.getElementById('custGst').value   = c.gstin || '';
    bootstrap.Modal.getInstance(document.getElementById('custModal')).hide();
}

function filterCusts() {
    var q = document.getElementById('custSearch').value.toLowerCase();
    document.querySelectorAll('#custTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

calcTotals();
calcExp();
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
