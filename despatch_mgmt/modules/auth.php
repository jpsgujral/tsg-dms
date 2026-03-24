<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('isAdmin')) {

    function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'Admin';
    }

    function userPerms() {
        return isset($_SESSION['perms']) ? $_SESSION['perms'] : [];
    }

    function canDo($module, $action) {
        if (isAdmin()) return true;
        $perms = userPerms();
        return !empty($perms[$module][$action]);
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

/* ── Ensure table exists ── */
ensureUsersTable(getDB());

/* ── Redirect unauthenticated users — skip on login.php ── */
if (basename($_SERVER['PHP_SELF']) !== 'login.php' && empty($_SESSION['user_id'])) {
    $loginUrl = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false) ? '../login.php' : 'login.php';
    header('Location: ' . $loginUrl);
    exit;
}

/* ── ALWAYS reload permissions fresh from DB on every request ──
   This ensures permission changes take effect immediately without
   requiring the user to log out and back in. ── */
if (!empty($_SESSION['user_id'])) {
    $__db  = getDB();
    $__uid = (int)$_SESSION['user_id'];
    $__row = $__db->query("SELECT role, permissions, status FROM app_users WHERE id=$__uid LIMIT 1")->fetch_assoc();

    if (!$__row || $__row['status'] !== 'Active') {
        /* Account deleted or deactivated — force logout */
        session_destroy();
        $loginUrl = (strpos($_SERVER['PHP_SELF'], '/modules/') !== false) ? '../login.php' : 'login.php';
        header('Location: ' . $loginUrl);
        exit;
    }

    /* Refresh role + permissions from DB every page load */
    $_SESSION['role']  = $__row['role'];
    $_SESSION['perms'] = json_decode($__row['permissions'] ?? '{}', true);
    if (!is_array($_SESSION['perms'])) $_SESSION['perms'] = [];

    unset($__db, $__uid, $__row);
}
