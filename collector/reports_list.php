<?php
// collector/reports_list.php
include("config\\db.php");

if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'collector') {
  http_response_code(403);
  echo "<h3>403 — Collector only</h3>";
  exit;
}

$collectorId = (int)$_SESSION['user_id'];
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ----------------- CLAIM action ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_report_id'])) {
  $rid = (int)$_POST['claim_report_id'];

  // 1) Verify report is still pending + unassigned
  $q = "SELECT id, citizen_id, status
        FROM reports
        WHERE id=? AND (collector_id IS NULL OR collector_id=0) AND status='pending'
        LIMIT 1";
  $st = mysqli_prepare($conn, $q);
  mysqli_stmt_bind_param($st, 'i', $rid);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok  = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);

  if ($ok) {
    // 2) Assign report to this collector
    $st = mysqli_prepare($conn, "UPDATE reports SET status='claimed', collector_id=?, assigned_at=NOW() WHERE id=?");
    mysqli_stmt_bind_param($st, 'ii', $collectorId, $rid);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    // 3) Create claim row
    $st = mysqli_prepare($conn, "INSERT INTO report_claims (report_id, collector_id, claimed_at, status)
                                 VALUES (?, ?, NOW(), 'claimed')");
    mysqli_stmt_bind_param($st, 'ii', $rid, $collectorId);
    mysqli_stmt_execute($st);
    $newClaimId = mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    // 4) Notify citizen (optional)
    $citizenId = (int)$ok['citizen_id'];
    $msg = "Your report #{$rid} was claimed by a collector.";
    $typ = 'claimed';
    $st  = mysqli_prepare($conn, "INSERT INTO notifications (user_id, report_id, type, message, is_read, created_at)
                                  VALUES (?, ?, ?, ?, 0, NOW())");
    mysqli_stmt_bind_param($st, 'iiss', $citizenId, $rid, $typ, $msg);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    // 5) Go to detail page with both IDs
    header("Location: index.php?route=collector.report_detail&id={$rid}&claim_id={$newClaimId}");
    exit;
  }
}

/* ----------- Fetch PENDING (unassigned) ----------- */
$pending = [];
$q = "SELECT r.id, r.description, r.photo_path, r.gps_lat, r.gps_lng, r.created_at
      FROM reports r
      WHERE (r.collector_id IS NULL OR r.collector_id=0)
        AND r.status='pending'
      ORDER BY r.created_at DESC";
$re = mysqli_query($conn, $q);
if ($re) { while ($row = mysqli_fetch_assoc($re)) $pending[] = $row; }

/* ----------- Fetch MY CLAIMED (latest claim per report) ----------- 
   We join a subquery that finds the *latest claim id* for each report
   made by this collector, where claim status is 'claimed'. */
$claimed = [];
$q = "
  SELECT r.id AS report_id,
         r.description,
         r.photo_path,
         r.assigned_at,
         rc.id         AS claim_id,
         rc.status     AS claim_status,
         rc.claimed_at AS claimed_at
  FROM reports r
  JOIN (
       SELECT rc1.report_id, rc1.id, rc1.status, rc1.claimed_at
       FROM report_claims rc1
       JOIN (
            SELECT report_id, MAX(id) AS maxid
            FROM report_claims
            WHERE collector_id=?
            GROUP BY report_id
       ) t ON t.report_id = rc1.report_id AND t.maxid = rc1.id
       WHERE rc1.collector_id=? AND rc1.status='claimed'
  ) rc ON rc.report_id = r.id
  WHERE r.collector_id=? AND r.status='claimed'
  ORDER BY r.assigned_at DESC
";
$st = mysqli_prepare($conn, $q);
mysqli_stmt_bind_param($st, 'iii', $collectorId, $collectorId, $collectorId);
mysqli_stmt_execute($st);
$rs = mysqli_stmt_get_result($st);
while ($row = mysqli_fetch_assoc($rs)) $claimed[] = $row;
mysqli_stmt_close($st);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Smart Waste – Pending Reports</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83; --bg:#f5f7f8;
    --card:#ffffff; --border:#e6eaee; --pending:#ffb300; --claimed:#0277bd;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,Arial,sans-serif}
  .wrap{max-width:1120px;margin:28px auto;padding:0 16px}
  .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
  .brand{display:flex;gap:10px;font-weight:800;color:var(--green)}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark))}
  .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid transparent;font-weight:700;text-decoration:none;cursor:pointer}
  .btn-outline{background:#fff;color:var(--green);border-color:var(--green)}
  .btn-primary{background:var(--green);color:#fff}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:12px}
  h2{margin:0 0 10px;font-size:20px}
  .table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--border);border-radius:12px;overflow:hidden}
  .table th,.table td{padding:12px;border-bottom:1px solid var(--border);font-size:14px;vertical-align:middle}
  .table thead th{background:#f9fbfb;color:#455a64;font-weight:700}
  .thumb{width:84px;height:64px;object-fit:cover;border:1px solid var(--border);border-radius:8px;background:#fafafa}
  .status{padding:4px 10px;border-radius:999px;color:#fff;font-weight:800;font-size:12px}
  .s-pending{background:var(--pending)} .s-claimed{background:var(--claimed)}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><span class="logo"></span><span>Smart Waste – Collector</span></div>
    <div>
      <a class="btn btn-outline" href="index.php?route=collector.dashboard">Dashboard</a>
      <a class="btn btn-primary" href="index.php?route=collector.task_history">Task History</a>
    </div>
  </div>

  <!-- Pending Unassigned -->
  <div class="card">
    <h2>Pending (Unassigned) Reports</h2>
    <table class="table">
      <thead>
        <tr>
          <th style="width:100px">Photo</th>
          <th>Description</th>
          <th style="width:130px">Submitted</th>
          <th style="width:180px">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pending): ?>
        <tr><td colspan="4" style="color:#8a8a8a">No pending reports found.</td></tr>
      <?php else: foreach ($pending as $r): ?>
        <tr>
          <td>
            <?php if (!empty($r['photo_path'])): ?>
              <img class="thumb" src="<?= h($r['photo_path']) ?>" alt="">
            <?php else: ?>
              <div class="thumb"></div>
            <?php endif; ?>
          </td>
          <td><?= h($r['description']) ?></td>
          <td><?= h($r['created_at']) ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="claim_report_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-primary" type="submit">Claim</button>
            </form>
            <a class="btn btn-outline" href="index.php?route=collector.report_detail&id=<?= (int)$r['id'] ?>">View</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- My Claimed -->
  <div class="card">
    <h2>Your Claimed Reports</h2>
    <table class="table">
      <thead>
        <tr>
          <th style="width:100px">Photo</th>
          <th>Description</th>
          <th style="width:110px">Status</th>
          <th style="width:220px">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$claimed): ?>
        <tr><td colspan="4" style="color:#8a8a8a">No claimed reports yet.</td></tr>
      <?php else: foreach ($claimed as $c): ?>
        <tr>
          <td>
            <?php if (!empty($c['photo_path'])): ?>
              <img class="thumb" src="<?= h($c['photo_path']) ?>" alt="">
            <?php else: ?>
              <div class="thumb"></div>
            <?php endif; ?>
          </td>
          <td><?= h($c['description']) ?></td>
          <td><span class="status s-claimed">CLAIMED</span></td>
          <td>
            <a class="btn btn-outline"
               href="index.php?route=collector.report_detail&id=<?= (int)$c['report_id'] ?>&claim_id=<?= (int)$c['claim_id'] ?>">
               Open
            </a>
            <a class="btn btn-primary"
               href="index.php?route=collector.complete_form&report_id=<?= (int)$c['report_id'] ?>&claim_id=<?= (int)$c['claim_id'] ?>">
               Complete
            </a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
