<?php
// admin/manage_users.php
include("config\db.php");

// ---- Guard: only admin ----
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo "<h3>403 — Admin only</h3>";
  exit;
}

// Handle role update
if (isset($_POST['update_role'])) {
  $uid = (int)$_POST['user_id'];
  $newRole = $_POST['role'];
  $stmt = mysqli_prepare($conn, "UPDATE users SET role=? WHERE id=?");
  mysqli_stmt_bind_param($stmt, 'si', $newRole, $uid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  header("Location: index.php?route=admin.manage_users");
  exit;
}

// Handle delete
if (isset($_POST['delete_user'])) {
  $uid = (int)$_POST['user_id'];
  $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id=?");
  mysqli_stmt_bind_param($stmt, 'i', $uid);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);
  header("Location: index.php?route=admin.manage_users");
  exit;
}

// Fetch all users
$users = [];
$res = mysqli_query($conn, "SELECT id, username, full_name, email, role, created_at FROM users ORDER BY id DESC");
while ($row = mysqli_fetch_assoc($res)) {
  $users[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – Manage Users</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --danger:#c62828;
  }
  *{box-sizing:border-box}
  body{margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;}
  .wrap{max-width:1100px; margin:28px auto; padding:0 16px;}

  .top{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
  .brand{display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800;}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
  .nav{display:flex; gap:8px;}
  .btn{padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-weight:700; font-size:14px; cursor:pointer; text-decoration:none;}
  .btn-outline{background:#fff; color:var(--green); border-color:var(--green);}
  .btn-danger{background:#fff; color:var(--danger); border:1px solid #f0b9b9;}
  .btn-outline:hover{background:#eef5ef}
  .btn-danger:hover{background:#ffeaea}

  .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px;}
  h2{margin:0 0 10px; font-size:20px}

  .table{width:100%; border-collapse:separate; border-spacing:0; border:1px solid var(--border); border-radius:12px; overflow:hidden;}
  .table th, .table td{padding:12px; font-size:14px; border-bottom:1px solid var(--border);}
  .table thead th{background:#f9fbfb; color:#455a64; font-weight:700;}
  .table tbody tr:hover{background:#fcfdfd;}
  form{display:inline;}
  select{padding:6px 8px; border-radius:6px; border:1px solid var(--border); font-size:13px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><span class="logo"></span><span>Smart Waste – Admin</span></div>
    <div class="nav">
      <a class="btn btn-outline" href="index.php?route=admin.dashboard">Dashboard</a>
      <a class="btn btn-outline" href="index.php?route=admin.reports_overview">Reports Overview</a>
      <a class="btn btn-outline" href="index.php?route=admin.analytics">Analytics</a>
    </div>
  </div>

  <div class="card">
    <h2>Manage Users</h2>
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Joined</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--muted);">No users found</td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($u['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <form method="post" style="margin:0">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <select name="role" onchange="this.form.submit()">
                <option value="citizen"  <?= $u['role']==='citizen'?'selected':'' ?>>Citizen</option>
                <option value="collector"<?= $u['role']==='collector'?'selected':'' ?>>Collector</option>
                <option value="admin"    <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
              </select>
              <input type="hidden" name="update_role" value="1">
            </form>
          </td>
          <td><?= htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <form method="post" onsubmit="return confirm('Delete user <?= htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>?');">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
              </form>
            <?php else: ?>
              <span style="color:var(--muted);font-size:12px;">(you)</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
