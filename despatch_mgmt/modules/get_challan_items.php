<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo '[]'; exit; }

$db    = getDB();
$items = $db->query("SELECT di.qty, di.unit_price, di.gst_rate,
    i.item_name, i.hsn_code, i.uom
    FROM despatch_items di
    JOIN items i ON di.item_id = i.id
    WHERE di.despatch_id = $id")->fetch_all(MYSQLI_ASSOC);

echo json_encode($items);
