<?php
// collector/task_history.php
include("config\db.php");

if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'collector') {
  http_response_code(403);
  echo "<h3>403 — Collector only</h3>";
  exit;
}

$collectorId = (int)$_SESSION['user_id'];

// Fetch task history (all claims by this collector)
$tasks = [];
$sql = "SELECT c.id AS claim_id, c.report_id, c.status AS claim_status, c.claimed_at, c.completed_at,
               c.ai_category_id, c.ai_confidence, c.cleanup_photo_path, c.verified_waste_text,
               r.description, r.photo_path, r.result_path
        FROM report_claims c
        JOIN reports r ON r.id=c.report_id
        WHERE c.collector_id=?
        ORDER BY COALESCE(c.completed_at, c.claimed_at) DESC";
$st = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($st, 'i', $collectorId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
while ($row = mysqli_fetch_assoc($res)) {
  $tasks[] = $row;
}
mysqli_stmt_close($st);

// Fetch categories for display
$catMap = [];
$r = mysqli_query($conn, "SELECT id,name FROM waste_categories");
while ($row = mysqli_fetch_assoc($r)) { $catMap[$row['id']] = $row['name']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Smart Waste – Task History</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20; --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#fff; --border:#e6eaee;
    --pending:#ffb300; --claimed:#0277bd; --completed:#2E7D32;
    --haz:#d81b60; --rec:#1e88e5; --org:#6abd4b;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,Arial,sans-serif}
  .wrap{max-width:1120px;margin:28px auto;padding:0 16px}
  .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
  .brand{display:flex;gap:10px;font-weight:800;color:var(--green)}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark))}
  .btn{display:inline-block;padding:8px 12px;border-radius:10px;font-weight:700;text-decoration:none;cursor:pointer;font-size:13px}
  .btn-outline{background:#fff;color:var(--green);border:1px solid var(--green)}
  .btn-primary{background:var(--green);color:#fff;border:1px solid var(--green-dark)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:12px}
  h2{margin:0 0 10px;font-size:20px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid var(--border);text-align:left;font-size:14px}
  thead th{background:#f9fbfb;color:#455a64;font-weight:700}
  .thumb{width:70px;height:54px;object-fit:cover;border:1px solid var(--border);border-radius:8px;background:#fafafa}
  .status{padding:4px 10px;border-radius:999px;color:#fff;font-weight:800;font-size:12px}
  .s-claimed{background:var(--claimed)} .s-completed{background:var(--completed)}
  .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;font-size:12px;color:#fff}
  .p-hazardous{background:var(--haz)} .p-recyclable{background:var(--rec)} .p-organic{background:var(--org)}
  .muted{color:var(--muted);font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><span class="logo"></span><span>Smart Waste – Collector</span></div>
    <div>
      <a class="btn btn-outline" href="index.php?route=collector.dashboard">Dashboard</a>
      <a class="btn btn-primary" href="index.php?route=collector.reports_list">Pending Reports</a>
    </div>
  </div>

  <div class="card">
    <h2>My Task History</h2>
    <table>
      <thead>
        <tr>
          <th style="width:90px">Report</th>
          <th>Description</th>
          <th>Status</th>
          <th>AI Category</th>
          <th>Completed @</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$tasks): ?>
          <tr><td colspan="6" class="muted">No tasks yet.</td></tr>
        <?php else: foreach ($tasks as $t): ?>
          <tr>
            <td>
              <?php if (!empty($t['cleanup_photo_path'])): ?>
                <img class="thumb" src="<?php echo $t['cleanup_photo_path']; ?>" alt="">
              <?php elseif (!empty($t['photo_path'])): ?>
                <img class="thumb" src="<?php echo $t['photo_path']; ?>" alt="">
              <?php else: ?>
                <div class="thumb"></div>
              <?php endif; ?>
              <div class="muted">#<?php echo (int)$t['report_id']; ?><br>Claim #<?php echo (int)$t['claim_id']; ?></div>
            </td>
            <td><?php echo htmlspecialchars($t['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <?php if ($t['claim_status']==='completed'): ?>
                <span class="status s-completed">COMPLETED</span>
              <?php else: ?>
                <span class="status s-claimed">CLAIMED</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($t['ai_category_id']): ?>
                <?php
                  $nm = $catMap[$t['ai_category_id']] ?? '';
                  $pillClass = '';
                  if (stripos($nm,'haz')!==false) $pillClass='p-hazardous';
                  elseif (stripos($nm,'rec')!==false) $pillClass='p-recyclable';
                  else $pillClass='p-organic';
                ?>
                <span class="pill <?php echo $pillClass; ?>"><?php echo htmlspecialchars($nm, ENT_QUOTES, 'UTF-8'); ?></span><br>
                <span class="muted"><?php echo (float)$t['ai_confidence']; ?>%</span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($t['completed_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <a class="btn btn-outline" href="index.php?route=collector.report_detail&id=<?php echo (int)$t['report_id']; ?>&claim_id=<?php echo (int)$t['claim_id']; ?>">Open</a>
              <?php if (!empty($t['result_path'])): ?>
                <a class="btn btn-primary" href="<?php echo $t['result_path']; ?>" target="_blank">Open Result</a>
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
