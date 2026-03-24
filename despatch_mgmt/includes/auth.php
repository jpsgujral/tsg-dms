<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('isAdmin')) {

    function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'Admin';
    }

    function userPerms() {
        return isset($_SESSION['perms']) && is_array($_SESSION['perms']) ? $_SESSION['perms'] : [];
    }

    function canDo($module, $action) {
        if (isAdmin()) return true;
        $perms = userPerms();
        return isset($perms[$module][$action]) && $perms[$module][$action] == 1;
    }

    function requirePerm($module, $action) {
        if (!canDo($module, $action)) {
            $_SESSION['alert'] = [
                'type'    => 'danger',
                'message' => '<i class="bi bi-shield-lock me-2"></i>You do not have permission to access this section.'
            ];
            $back = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false) ? '../index.php' : 'index.php';
            header('Location: ' . $back);
            exit;
        }
    }

    function ensureUsersTable($db) {
        $db->query("CREATE TABLE IF NOT EXISTS app_users (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            username    VARCHAR(60)  NOT NULL UNIQUE,
            full_name   VARCHAR(120) NOT NULL DEFAULT '',
            email       VARCHAR(120) DEFAULT '',
            role        ENUM('Admin','User') NOT NULL DEFAULT 'User',
            password    VARCHAR(255) NOT NULL,
            permissions TEXT,
            status      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            last_login  DATETIME DEFAULT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $row = $db->query("SELECT COUNT(*) AS c FROM app_users")->fetch_assoc();
        if ((int)$row['c'] === 0) {
            $hash = password_hash('@Summer97', PASSWORD_DEFAULT);
            $safe = $db->real_escape_string($hash);
            $db->query("INSERT INTO app_users (username, full_name, role, password, permissions, status)
                        VALUES ('admin', 'Administrator', 'Admin', '$safe', '{}', 'Active')");
        }
    }

} // end if !function_exists

/* Ensure table exists */
ensureUsersTable(getDB());

/* Auto-migrate: add signature_path column if not present */
(function($db){
    $cols = $db->query("SHOW COLUMNS FROM app_users LIKE 'signature_path'");
    if ($cols && $cols->num_rows === 0) {
        $db->query("ALTER TABLE app_users ADD COLUMN signature_path VARCHAR(255) DEFAULT NULL");
    }
})(getDB());

/* Redirect unauthenticated — skip on login.php */
if (basename($_SERVER['PHP_SELF']) !== 'login.php' && empty($_SESSION['user_id'])) {
    $loginUrl = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false) ? '../login.php' : 'login.php';
    header('Location: ' . $loginUrl);
    exit;
}

/* ALWAYS reload role + permissions fresh from DB every request */
if (!empty($_SESSION['user_id'])) {
    $__uid = (int)$_SESSION['user_id'];
    $__row = getDB()->query("SELECT role, permissions, status FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();

    if (!$__row || $__row['status'] !== 'Active') {
        session_destroy();
        $loginUrl = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false) ? '../login.php' : 'login.php';
        header('Location: ' . $loginUrl);
        exit;
    }

    $_SESSION['role']  = $__row['role'];
    $decoded = json_decode($__row['permissions'], true);
    $_SESSION['perms'] = (is_array($decoded)) ? $decoded : [];

    unset($__uid, $__row, $decoded);
}
