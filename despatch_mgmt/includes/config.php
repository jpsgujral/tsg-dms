<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'tsgimpex_tsg');
define('DB_PASS', ';l%r07dDBIgeUBrr');
define('DB_NAME', 'tsgimpex_despatch_mgmt');

define('APP_NAME', 'Despatch Management System');
define('APP_VERSION', '1.0');

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");

        $db_name = DB_NAME;

        // Auto-migrate despatch_orders
        $cols = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='despatch_orders'");
        if ($cols) {
            $existing = [];
            while ($r = $cols->fetch_row()) $existing[] = $r[0];
            if (!in_array('created_by', $existing))
                $conn->query("ALTER TABLE despatch_orders ADD COLUMN created_by INT DEFAULT 0 AFTER id");
            if (!in_array('company_id', $existing))
                $conn->query("ALTER TABLE despatch_orders ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        }

        // Auto-migrate purchase_orders
        $pocols = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='purchase_orders'");
        if ($pocols) {
            $po_existing = [];
            while ($r = $pocols->fetch_row()) $po_existing[] = $r[0];
            if (!in_array('company_id', $po_existing))
                $conn->query("ALTER TABLE purchase_orders ADD COLUMN company_id INT DEFAULT 1 AFTER id");
        }

        // Create companies table if not exists
        $conn->query("CREATE TABLE IF NOT EXISTS companies (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            company_name        VARCHAR(200) NOT NULL,
            address             TEXT,
            city                VARCHAR(60),
            state               VARCHAR(60),
            pincode             VARCHAR(10),
            phone               VARCHAR(30),
            email               VARCHAR(120),
            gstin               VARCHAR(20),
            pan                 VARCHAR(15),
            bank_name           VARCHAR(120),
            account_no          VARCHAR(30),
            ifsc_code           VARCHAR(20),
            smtp_host           VARCHAR(120) DEFAULT '',
            smtp_port           SMALLINT DEFAULT 587,
            smtp_user           VARCHAR(120) DEFAULT '',
            smtp_pass           VARCHAR(255) DEFAULT '',
            smtp_secure         VARCHAR(10) DEFAULT 'tls',
            smtp_from_name      VARCHAR(120) DEFAULT '',
            seal_path           VARCHAR(255) DEFAULT NULL,
            mtc_sig_path        VARCHAR(255) DEFAULT NULL,
            checked_by_sig_path VARCHAR(255) DEFAULT NULL,
            is_active           TINYINT DEFAULT 1,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed companies from company_settings if table is empty
        $cc = $conn->query("SELECT COUNT(*) AS c FROM companies")->fetch_assoc();
        if ($cc && (int)$cc['c'] === 0) {
            $cs = $conn->query("SELECT * FROM company_settings LIMIT 1")->fetch_assoc();
            if ($cs) {
                $e     = function($v) use ($conn) { return $conn->real_escape_string($v ?? ''); };
                $seal  = !empty($cs['seal_path'])           ? "'" . $e($cs['seal_path'])           . "'" : 'NULL';
                $mtcs  = !empty($cs['mtc_sig_path'])        ? "'" . $e($cs['mtc_sig_path'])        . "'" : 'NULL';
                $chks  = !empty($cs['checked_by_sig_path']) ? "'" . $e($cs['checked_by_sig_path']) . "'" : 'NULL';
                $port  = (int)($cs['smtp_port'] ?? 587);
                $sql   = "INSERT INTO companies
                    (company_name, address, city, state, pincode, phone, email, gstin, pan,
                     bank_name, account_no, ifsc_code, smtp_host, smtp_port, smtp_user, smtp_pass,
                     smtp_secure, smtp_from_name, seal_path, mtc_sig_path, checked_by_sig_path)
                    VALUES (
                     '" . $e($cs['company_name'])   . "',
                     '" . $e($cs['address'])         . "',
                     '" . $e($cs['city'])            . "',
                     '" . $e($cs['state'])           . "',
                     '" . $e($cs['pincode'])         . "',
                     '" . $e($cs['phone'])           . "',
                     '" . $e($cs['email'])           . "',
                     '" . $e($cs['gstin'])           . "',
                     '" . $e($cs['pan'])             . "',
                     '" . $e($cs['bank_name'])       . "',
                     '" . $e($cs['account_no'])      . "',
                     '" . $e($cs['ifsc_code'])       . "',
                     '" . $e($cs['smtp_host'])       . "',
                     $port,
                     '" . $e($cs['smtp_user'])       . "',
                     '" . $e($cs['smtp_pass'])       . "',
                     '" . $e($cs['smtp_secure'])     . "',
                     '" . $e($cs['smtp_from_name'])  . "',
                     $seal, $mtcs, $chks
                    )";
                $conn->query($sql);
            }
        }
    }
    return $conn;
}

function sanitize($data) {
    $db = getDB();
    return $db->real_escape_string(trim(htmlspecialchars($data)));
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function showAlert($type, $message) {
    $_SESSION['alert'] = ['type' => $type, 'message' => $message];
}

function displayAlert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo "<div class='alert alert-{$alert['type']} alert-dismissible fade show' role='alert'>
                {$alert['message']}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
        unset($_SESSION['alert']);
    }
}

/**
 * Return the appropriate decimal places for a given UOM.
 *  3 dp  — MT, Kg, Gm, Litre, Mtr, Cm
 *  2 dp  — Bundle, Bag
 *  0 dp  — Nos, Set, Box, Carton, Pair, Dozen
 */
function uomDecimals(string $uom): int {
    $three = ['MT','Kg','Gm','Litre','Mtr','Cm'];
    $zero  = ['Nos','Set','Box','Carton','Pair','Dozen'];
    if (in_array($uom, $three)) return 3;
    if (in_array($uom, $zero))  return 0;
    return 2;
}

/** Format a quantity value with the correct decimals for its UOM. */
function fmtQty($qty, string $uom): string {
    return number_format((float)$qty, uomDecimals($uom));
}

/** Return active company id from session (default 1). */
function activeCompanyId(): int {
    return (int)($_SESSION['active_company_id'] ?? 1);
}

/** Fetch one company row by id (or active company). */
function getCompany(int $id = 0): array {
    $db = getDB();
    $id = $id > 0 ? $id : activeCompanyId();
    $row = $db->query("SELECT * FROM companies WHERE id=$id LIMIT 1")->fetch_assoc();
    if (!$row) $row = $db->query("SELECT * FROM companies ORDER BY id LIMIT 1")->fetch_assoc();
    return $row ?? [];
}

/** Get all active companies. */
function getAllCompanies(): array {
    return getDB()->query("SELECT * FROM companies WHERE is_active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}
