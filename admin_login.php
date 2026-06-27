<?php
// ============================================================
// admin_login.php  — Admin authentication
// ============================================================
session_start();
require_once 'includes/db.php';

// If already logged in, redirect
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $admin = dbFetchOne(
            "SELECT * FROM admin_users WHERE (username=? OR email=?) AND status='Active' LIMIT 1",
            [$username, $username]
        );

        if ($admin && password_verify($password, $admin['password_hash'])) {
    $_SESSION['admin_id']   = $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_role'] = $admin['role'];
    if (!empty($_POST['remember'])) {
        issueRememberToken('admin', $admin['id']);
    }
    logActivity('Admin login', 'Auth');
    header('Location: dashboard.php');
    exit;
} else {
    $error = 'Invalid username or password.';
}
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login — Barangay San Isidro</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-left">
      <div class="brand">
        <div class="logo"><img src="<?= getSiteLogo() ?>" alt="Logo"></div>
        <div>
          <h2>Local Assistance for Barangay Services</h2>
          <p class="text-muted">Barangay administration portal</p>
        </div>
      </div>
      <div style="margin-top:28px">
        <h3>Admin Access</h3>
        <p>Sign in with admin credentials to access the management dashboard.</p>
      </div>
    </div>
    <div class="login-right">
      <h3>Admin Sign In</h3>
      <?php if ($error): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <input class="form-control" name="username" placeholder="Admin Username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="form-group">
          <input class="form-control" name="password" placeholder="Password" type="password" required>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="remember"><input type="checkbox" name="remember" value="1" checked> Remember me</label>
          <a class="text-muted" href="forgot_password.php">Forgot Password</a>
        </div>
        <div style="display:flex;gap:10px;align-items:center";>
          <button type="submit" class="btn btn-primary">Login</button>
        </div>
        <div class="login-footer text-muted">
          Are you a resident? <a href="index.php" style="color:var(--primary);font-weight:600;text-decoration:none">Resident Login</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
