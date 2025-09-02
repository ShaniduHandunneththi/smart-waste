<?php
// citizen/citizen_dashboard.php
include("config\db.php");

// ---- guard: only logged-in citizens ----
if (!isset($_SESSION['user_id'])) {
  header('Location: index.php?route=login'); exit;
}
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'citizen') {
  http_response_code(403);
  echo "<h2 style='font-family:system-ui'>403 – Forbidden</h2><p>Citizen access only.</p>";
  exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'Citizen';

// tiny escaper
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---- Recent notifications (latest 5) ----
   Table (suggested):
   notifications(id, user_id, report_id, message, created_at, is_read)
*/
$notes = [];
$sql = "SELECT id, report_id, message, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5";
if ($stmt = mysqli_prepare($conn, $sql)) {
  mysqli_stmt_bind_param($stmt, 'i', $userId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($row = mysqli_fetch_assoc($res)) {
    $notes[] = $row;
  }
  mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Smart Waste – Citizen Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root{
      --green:#2E7D32; --green-dark:#1B5E20;
      --text:#263238; --muted:#607d8b;
      --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    }
    *{box-sizing:border-box}
    body{
      margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;
      background:var(--bg); color:var(--text);
    }
    .wrap{ max-width:1100px; margin:32px auto; padding:0 16px; }
    .topbar{ display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .brand{ display:flex; align-items:center; gap:10px; font-weight:800; color:var(--green); letter-spacing:.3px}
    .brand .logo{ width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg,var(--green),var(--green-dark)); display:inline-block; }
    .user{font-size:14px; color:var(--muted)}
    .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:18px; }

    .actions{ display:flex; gap:10px; flex-wrap:wrap; }
    .btn{
      display:inline-block; padding:10px 14px; border-radius:10px; text-decoration:none;
      font-weight:700; font-size:14px; transition:.15s transform, .15s filter; border:1px solid transparent;
      cursor:pointer;
    }
    .btn-primary{ background:var(--green); color:#fff; }
    .btn-outline{ border:1px solid var(--green); color:var(--green); background:#fff; }
    .btn-ghost{ border:1px solid var(--border); background:#fff; color:#0277bd; }
    .btn:hover{ transform:translateY(-1px); filter:brightness(1.02); }

    .section-title{ margin:0 0 10px; font-size:15px; color:#455a64; font-weight:800; letter-spacing:.2px }
    .item{ padding:10px 0; border-bottom:1px dashed var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:14px; }
    .item:last-child{ border-bottom:none }
    .pill{ font-size:12px; padding:4px 8px; border-radius:999px; background:#eef5ef; color:var(--green); border:1px solid #dce7de; }
    .muted{ color:var(--muted); font-size:12px }
    .empty{ text-align:center; padding:24px; border:1px dashed var(--border); border-radius:12px; color:var(--muted); background:#fff; }

    .grid{ display:grid; gap:16px; grid-template-columns: 2fr 1fr; }
    @media (max-width: 820px){
      .grid{ grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <!-- Topbar -->
    <div class="topbar">
      <div class="brand">
        <span class="logo"></span>
        <span>Smart Waste – Citizen</span>
      </div>
      <div class="user">
        Logged in as <strong><?= h($fullName) ?></strong>
        &nbsp;•&nbsp;
        <a href="index.php?route=logout" style="color:#c2185b;text-decoration:none;font-weight:700;">Logout</a>
      </div>
    </div>

    <!-- Quick actions (no KPIs) + Refresh -->
    <div class="card" style="margin-bottom:16px;">
      <div class="actions">
        <a class="btn btn-primary" href="index.php?route=citizen.submit_report">Submit Report</a>
        <a class="btn btn-outline" href="index.php?route=citizen.my_reports">My Reports</a>
        <a class="btn btn-outline" href="index.php?route=citizen.notifications">Notifications</a>
        <button class="btn btn-ghost" type="button" onclick="window.location.reload()">Refresh</button>
      </div>
    </div>

    <!-- Content: notifications + tips -->
    <div class="grid">
      <div class="card">
        <h4 class="section-title">Recent Notifications</h4>
        <?php if (!$notes): ?>
          <div class="empty">No notifications yet. Submit your first report to get started!</div>
        <?php else: ?>
          <?php foreach ($notes as $n): ?>
            <div class="item">
              <div>
                <?php if (!empty($n['report_id'])): ?>
                  Report #<?= (int)$n['report_id'] ?>:
                <?php endif; ?>
                <?= h($n['message']) ?>
              </div>
              <div class="muted"><?= h(date('Y-m-d H:i', strtotime($n['created_at']))) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="card">
        <h4 class="section-title">Helpful Tips</h4>
        <div class="item">
          <div>Use clear photos for faster processing.</div>
          <span class="pill">Tip</span>
        </div>
        <div class="item">
          <div>Add brief keywords (e.g., “bottles, wrappers”).</div>
          <span class="pill">Tip</span>
        </div>
        <div class="item">
          <div>Share accurate GPS for nearby collector assignment.</div>
          <span class="pill">Tip</span>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
