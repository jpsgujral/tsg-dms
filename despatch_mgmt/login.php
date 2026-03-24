<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → go home
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db       = getDB();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $u = htmlspecialchars_decode($username);
        $stmt = $db->prepare("SELECT * FROM app_users WHERE username = ? AND status = 'Active' LIMIT 1");
        $stmt->bind_param('s', $u);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['perms']      = json_decode($user['permissions'] ?? '{}', true) ?: [];
            // Update last login
            $db->query("UPDATE app_users SET last_login=NOW() WHERE id={$user['id']}");
            header('Location: index.php'); exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$db = getDB();
$company = $db->query("SELECT company_name FROM company_settings LIMIT 1")->fetch_assoc();
$app_name = $company['company_name'] ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= htmlspecialchars($app_name) ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #1e3a5f 0%, #2c5f8a 50%, #1a7a6e 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', system-ui, sans-serif;
}
.login-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 24px 64px rgba(0,0,0,0.28);
    width: 100%;
    max-width: 420px;
    padding: 40px 36px 32px;
}
.login-brand {
    text-align: center;
    margin-bottom: 28px;
}
.login-brand .icon-wrap {
    width: 68px; height: 68px;
    background: linear-gradient(135deg, #1e3a5f, #2980b9);
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 14px;
    box-shadow: 0 8px 24px rgba(30,58,95,0.3);
}
.login-brand .icon-wrap i { color: #fff; font-size: 2rem; }
.login-brand h4 { font-weight: 800; color: #1e3a5f; margin: 0; font-size: 1.15rem; }
.login-brand p  { color: #888; font-size: 0.82rem; margin: 4px 0 0; }
.form-label { font-weight: 600; font-size: 0.83rem; color: #444; }
.form-control {
    border-radius: 10px;
    border: 1.5px solid #dde4f0;
    font-size: 0.9rem;
    padding: 10px 14px;
    min-height: 44px;
}
.form-control:focus { border-color: #2980b9; box-shadow: 0 0 0 3px rgba(41,128,185,.15); }
.btn-login {
    background: linear-gradient(135deg, #1e3a5f, #2980b9);
    border: none; color: #fff;
    width: 100%; padding: 12px;
    border-radius: 10px; font-weight: 700;
    font-size: 0.95rem; letter-spacing: 0.3px;
    transition: opacity .2s, transform .1s;
    min-height: 48px;
}
.btn-login:hover { opacity: .9; transform: translateY(-1px); color: #fff; }
.input-group-text {
    background: #f4f7fb;
    border: 1.5px solid #dde4f0;
    border-right: none;
    border-radius: 10px 0 0 10px;
    color: #888;
}
.input-group .form-control { border-left: none; border-radius: 0 10px 10px 0; }
.input-group .form-control:focus { border-left: none; }
.toggle-pw { cursor: pointer; background: #f4f7fb; border: 1.5px solid #dde4f0; border-left: none; border-radius: 0 10px 10px 0; color: #888; padding: 0 12px; }
.toggle-pw:hover { color: #1e3a5f; }
.footer-note { text-align: center; color: #aaa; font-size: 0.75rem; margin-top: 20px; }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-brand">
        <div class="icon-wrap"><i class="bi bi-truck"></i></div>
        <h4><?= htmlspecialchars($app_name) ?></h4>
        <p>Despatch Management System</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3" style="border-radius:10px;font-size:.87rem">
        <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input type="text" name="username" class="form-control" placeholder="Enter username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pwField" class="form-control" placeholder="Enter password" required>
                <button type="button" class="toggle-pw" onclick="togglePw()" tabindex="-1">
                    <i class="bi bi-eye" id="pwEye"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-login"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In</button>
    </form>
    <div class="footer-note">© <?= date('Y') ?> <?= htmlspecialchars($app_name) ?></div>
</div>
<script>
function togglePw() {
    var f = document.getElementById('pwField');
    var e = document.getElementById('pwEye');
    if (f.type === 'password') { f.type = 'text'; e.className = 'bi bi-eye-slash'; }
    else { f.type = 'password'; e.className = 'bi bi-eye'; }
}
</script>
</body>
</html>
