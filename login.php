<?php
// login.php
include("config\db.php");

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userInput = trim($_POST['username'] ?? '');
    $passInput = (string)($_POST['password'] ?? '');

    if ($userInput === '' || $passInput === '') {
        $err = 'Please enter both username/email and password.';
    } else {
        // Select only columns that exist in your table
        $sql = "
            SELECT
                id,
                COALESCE(NULLIF(full_name,''), username) AS full_name,
                username,
                email,
                password_hash,         -- <- only this; no 'password' column
                role,
                COALESCE(is_active, 1) AS is_active
            FROM users
            WHERE (username = ? OR email = ?)
            LIMIT 1
        ";

        $row = null;
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, 'ss', $userInput, $userInput);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
        }

        if (!$row) {
            $err = 'Invalid credentials.';
        } elseif ((int)$row['is_active'] !== 1) {
            $err = 'Your account is not active. Please contact admin.';
        } elseif (!isset($row['password_hash']) || !password_verify($passInput, $row['password_hash'])) {
            // Only password_hash is supported here
            $err = 'Invalid credentials.';
        } else {
            // ---- Successful login ----
            $_SESSION['user_id']   = (int)$row['id'];
            $_SESSION['full_name'] = (string)$row['full_name'];
            $_SESSION['role']      = strtolower(trim((string)$row['role'])); // normalize role

            // Route by role
            switch ($_SESSION['role']) {
                case 'admin':
                    header('Location: ./index.php?route=admin.dashboard'); break;
                case 'collector':
                    header('Location: ./index.php?route=collector.dashboard'); break;
                case 'citizen':
                default:
                    header('Location: ./index.php?route=citizen.dashboard'); break;
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Smart Waste – Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    *{box-sizing:border-box}
    body{background:linear-gradient(135deg,#2E7D32,#1B5E20);min-height:100vh;display:flex;justify-content:center;align-items:center;margin:0}
    .login-card{background:#fff;padding:40px 30px;border-radius:12px;box-shadow:0 8px 20px rgba(0,0,0,.2);width:360px}
    .title{margin-bottom:6px;color:#2E7D32;font-size:24px;font-weight:800}
    .subtitle{margin-bottom:20px;color:#666}
    .form-control:focus{border-color:#2E7D32;box-shadow:0 0 0 .2rem rgba(46,125,50,.15)}
    .btn-login{background:#2E7D32;border:none}
    .btn-login:hover{background:#1B5E20}
    .register-link a{color:#2E7D32;text-decoration:none}
    .register-link a:hover{text-decoration:underline}
  </style>
</head>
<body>
  <div class="login-card">
    <h2 class="title">Smart Waste System</h2>
    <p class="subtitle">Login to continue</p>

    <?php if ($err): ?>
      <div class="alert alert-danger py-2"><?= h($err) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['registered'])): ?>
      <div class="alert alert-success py-2">Registration successful. Please log in.</div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="mb-3">
        <label for="username" class="form-label">Username / Email</label>
        <input class="form-control" type="text" id="username" name="username"
               value="<?= h($_POST['username'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input class="form-control" type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-login w-100 text-white fw-bold">Login</button>
      <p class="register-link mt-3 mb-0 text-center">Don’t have an account? <a href="register.php">Register here</a></p>
    </form>
  </div>
</body>
</html>
