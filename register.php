<?php
// views/auth/register.php
session_start();
require_once __DIR__ . '/config/db.php';   // adjust path if needed

// Helper for safe output
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gather & sanitize
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $role      = trim($_POST['role'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    // Basic validation
    if ($full_name === '')       $errors[] = 'Full name is required.';
    if ($username === '')        $errors[] = 'Username is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!in_array($role, ['citizen','collector','admin'], true)) $errors[] = 'Please select a valid role.';
    if (strlen($password) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';

    // Prevent open self-registration for admin if you want (optional)
    // if ($role === 'admin') $errors[] = 'Admin accounts are created by the system only.';

    if (!$errors) {
        // Uniqueness checks
        // 1) email
        $sql = "SELECT id FROM users WHERE email = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $errors[] = 'This email is already registered.';
        }
        mysqli_stmt_close($stmt);

        // 2) username (only if not empty)
        if (!$errors && $username !== '') {
            $sql = "SELECT id FROM users WHERE username = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $errors[] = 'This username is already taken.';
            }
            mysqli_stmt_close($stmt);
        }

        if (!$errors) {
            // Hash password (bcrypt)
            $hash = password_hash($password, PASSWORD_BCRYPT);

            // Insert
            $ins = "INSERT INTO users
                    (full_name, username, email, phone, password_hash, role, is_active, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,1,NOW(),NOW())";
            $stmt = mysqli_prepare($conn, $ins);
            mysqli_stmt_bind_param($stmt, 'ssssss',
                $full_name, $username, $email, $phone, $hash, $role
            );

            if (mysqli_stmt_execute($stmt)) {
                $success = 'Account created successfully! You can now log in.';
                // Optionally redirect to login:
                // header('Location: /index.php?route=auth.login&registered=1'); exit;
                // Clear posted values
                $full_name = $username = $email = $phone = $role = '';
            } else {
                $errors[] = 'Database error: unable to create account.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Smart Waste – Register</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    * { margin:0; padding:0; box-sizing:border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, #2E7D32, #1B5E20);
      display: flex; justify-content: center; align-items: center;
      padding: 20px;
    }
    .reg-wrap { width: 100%; max-width: 860px; background: #fff; border-radius: 14px;
      box-shadow: 0 10px 28px rgba(0,0,0,.25); overflow: hidden; }
    .reg-header { background: #2E7D32; color: #fff; padding: 16px 22px; display: flex; align-items: center; justify-content: space-between; }
    .reg-header h2 { font-size: 20px; margin:0; font-weight: 700; }
    .reg-body { padding: 22px; }
    .subtitle { color:#666; margin-bottom:16px; }
    .form-label { font-weight:600; color:#333; }
    .form-control, .form-select { padding: 10px; border-radius: 8px; border:1px solid #d7d7d7; font-size: 14px; }
    .form-control:focus, .form-select:focus { border-color:#2E7D32; outline:none; box-shadow: 0 0 6px rgba(46,125,50,.35); }
    .row-gap { row-gap: 14px; }
    .note { font-size:12px; color:#6a6a6a; }
    .btn-primary { background:#2E7D32; border:none; font-weight:700; padding:10px 14px; border-radius:8px; }
    .btn-primary:hover { background:#1B5E20; }
    .btn-outline { border:1px solid #2E7D32; color:#2E7D32; background:#fff; }
    .btn-outline:hover { background:#eaf4eb; }
    .reg-footer { padding: 16px 22px; display:flex; justify-content: space-between; align-items:center; background:#fafafa; border-top:1px solid #eee; }
    .link { color:#2E7D32; text-decoration:none; font-weight:600; }
    .link:hover { text-decoration:underline; }
    .pwd-hint { font-size:12px; color:#777; margin-top:6px; }
    .pwd-error { color:#b00020; font-size:13px; display:none; margin-top:6px; }
    .match-ok { color:#2E7D32; font-size:13px; display:none; margin-top:6px; }
    .alert ul{ margin:0 0 0 18px; }
  </style>
</head>
<body>

  <div class="reg-wrap">
    <div class="reg-header">
      <h2>Smart Waste – Create Account</h2>
      <span class="small">Citizen • Collector • Admin</span>
    </div>

    <div class="reg-body">
      <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
          <strong>Couldn’t create account:</strong>
          <ul>
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php elseif ($success): ?>
        <div class="alert alert-success" role="alert">
          <?= h($success) ?>
        </div>
      <?php endif; ?>

      <p class="subtitle">Fill the details below to register. Choose your role appropriately. Admin access should be used only by authorized staff.</p>

      <!-- Register Form -->
      <form method="post" action="" id="registerForm" novalidate>
        <div class="row row-gap">
          <div class="col-md-6">
            <label class="form-label" for="full_name">Full Name</label>
            <input class="form-control" id="full_name" name="full_name" value="<?= h($full_name ?? '') ?>" required placeholder="e.g., John Perera">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" value="<?= h($username ?? '') ?>" required placeholder="e.g., johnp92">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" value="<?= h($email ?? '') ?>" required placeholder="e.g., john@example.com">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" id="phone" name="phone" value="<?= h($phone ?? '') ?>" placeholder="e.g., 0771234567">
          </div>

          <div class="col-md-6">
            <label class="form-label" for="role">Register As</label>
            <select class="form-select" id="role" name="role" required>
              <option value="" <?= empty($role) ? 'selected' : '' ?> disabled>— Select role —</option>
              <option value="citizen"   <?= (isset($role) && $role==='citizen')   ? 'selected':'' ?>>Citizen</option>
              <option value="collector" <?= (isset($role) && $role==='collector') ? 'selected':'' ?>>Waste Collector</option>
              <option value="admin"     <?= (isset($role) && $role==='admin')     ? 'selected':'' ?>>Admin</option>
            </select>
            <div class="note mt-1">Admins are typically assigned by the organization.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required placeholder="Min 8 chars, mix letters & numbers">
            <div class="pwd-hint">Use at least 8 characters, with letters and numbers.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label" for="confirm_password">Confirm Password</label>
            <input class="form-control" type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter password">
            <div class="pwd-error" id="pwdError">Passwords do not match.</div>
            <div class="match-ok" id="pwdOk">Passwords match ✔</div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-4">
          <button class="btn btn-primary" type="submit">Create Account</button>
          <a class="btn btn-outline" href="login.php">Back to Login</a>
        </div>
      </form>
    </div>

    <div class="reg-footer">
      <span class="small text-muted">By registering, you agree to follow local waste management policies.</span>
      <a class="link small" href="#">Need help?</a>
    </div>
  </div>

  <script>
    // Simple client-side checks (optional; server validates again)
    const form = document.getElementById('registerForm');
    const pwd  = document.getElementById('password');
    const cpwd = document.getElementById('confirm_password');
    const err  = document.getElementById('pwdError');
    const ok   = document.getElementById('pwdOk');

    function checkMatch(){
      if (!pwd.value || !cpwd.value) { err.style.display='none'; ok.style.display='none'; return; }
      if (pwd.value === cpwd.value){
        err.style.display='none'; ok.style.display='block';
      } else {
        err.style.display='block'; ok.style.display='none';
      }
    }
    pwd.addEventListener('input', checkMatch);
    cpwd.addEventListener('input', checkMatch);

    form.addEventListener('submit', function(e){
      if (pwd.value.length < 8){
        alert('Password must be at least 8 characters.');
        e.preventDefault(); return;
      }
      if (pwd.value !== cpwd.value){
        alert('Passwords do not match.');
        e.preventDefault(); return;
      }
      if (!document.getElementById('role').value){
        alert('Please select a role.');
        e.preventDefault(); return;
      }
    });
  </script>
</body>
</html>
