<?php
// citizen/report_view.php
// Shows ONE report that belongs to the logged-in citizen.
// Uses: reports, report_claims, waste_categories, report_documents

include("config\db.php");
// tiny escaper
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------------- role + input checks ---------------- */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'citizen') {
  http_response_code(403);
  echo "<h3>403 — Citizen only</h3>";
  exit;
}

$citizenId = (int)$_SESSION['user_id'];
$reportId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($reportId <= 0) {
  echo "<p>Invalid report id.</p>";
  exit;
}

/* ---------------- 1) fetch the report (ensure ownership) ---------------- */
$report = null;
$sql = "SELECT id, citizen_id, description, photo_path, gps_lat, gps_lng,
               status, result_path, created_at
        FROM reports
        WHERE id = ? AND citizen_id = ?
        LIMIT 1";
if ($st = mysqli_prepare($conn, $sql)) {
  mysqli_stmt_bind_param($st, 'ii', $reportId, $citizenId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $report = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}

if (!$report) {
  echo "<p>Report not found (or not your report).</p>";
  exit;
}

/* ---------------- 2) latest claim for this report (any collector) ---------------- */
$claim = null;
$sqlc = "SELECT id, collector_id, claimed_at, status, verified_waste_text,
                ai_category_id, ai_confidence, cleanup_photo_path,
                completed_at, notes
         FROM report_claims
         WHERE report_id = ?
         ORDER BY id DESC
         LIMIT 1";
if ($stc = mysqli_prepare($conn, $sqlc)) {
  mysqli_stmt_bind_param($stc, 'i', $reportId);
  mysqli_stmt_execute($stc);
  $resc = mysqli_stmt_get_result($stc);
  $claim = mysqli_fetch_assoc($resc);
  mysqli_stmt_close($stc);
}

/* ---------------- category name (optional) ---------------- */
$catName = '';
if ($claim && !empty($claim['ai_category_id'])) {
  $cid = (int)$claim['ai_category_id'];
  $rc  = mysqli_query($conn, "SELECT name FROM waste_categories WHERE id = {$cid} LIMIT 1");
  if ($rc && mysqli_num_rows($rc)) {
    $catName = (string)mysqli_fetch_row($rc)[0];
  }
}

/* ---------------- 3) find a shareable result ----------------
   Priority:
   - reports.result_path
   - latest report_documents (doc_type html/pdf)
----------------------------------------------------------------*/
$resultLink = '';
if (!empty($report['result_path'])) {
  $resultLink = (string)$report['result_path'];
} else {
  $rd = mysqli_query(
    $conn,
    "SELECT file_path
     FROM report_documents
     WHERE report_id = {$reportId}
       AND doc_type IN ('html','pdf')
     ORDER BY id DESC
     LIMIT 1"
  );
  if ($rd && mysqli_num_rows($rd)) {
    $resultLink = (string)mysqli_fetch_row($rd)[0];
  }
}

// convenience vars
$photoBefore = $report['photo_path'] ?? '';
$photoAfter  = ($claim && !empty($claim['cleanup_photo_path'])) ? $claim['cleanup_photo_path'] : '';
$gpsLat      = $report['gps_lat'] ?? '';
$gpsLng      = $report['gps_lng'] ?? '';
$desc        = $report['description'] ?? '';
$status      = $report['status'] ?? 'pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – View Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --pending:#ffb300; --claimed:#0277bd; --completed:#2E7D32;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif}
  .wrap{max-width:1100px;margin:28px auto;padding:0 16px;}
  .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
  .brand{display:flex;align-items:center;gap:10px;color:var(--green);font-weight:800;}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
  .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid var(--border);text-decoration:none;font-weight:700;background:#fff;color:#0277bd}
  .btn:hover{background:#f2f9ff}
  .btn-primary{border-color:var(--green);color:#fff;background:var(--green)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:12px;}
  h2{margin:0 0 10px;font-size:20px}
  .grid{display:grid;gap:16px;grid-template-columns:7fr 5fr}
  .photo{width:100%;height:360px;object-fit:cover;border-radius:12px;border:1px solid var(--border);background:#fafafa}
  dl{display:grid;grid-template-columns:140px 1fr;gap:6px 10px;margin:0}
  dt{color:#607d8b;font-weight:700;font-size:13px}
  dd{margin:0;color:#37474f;font-size:14px}
  .status{display:inline-block;padding:4px 10px;border-radius:999px;color:#fff;font-weight:800;font-size:12px}
  .s-pending{background:var(--pending)} .s-claimed{background:var(--claimed)} .s-completed{background:var(--completed)}
  @media (max-width:980px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><span class="logo"></span><span>Smart Waste – Citizen</span></div>
    <div>
      <a class="btn" href="index.php?route=citizen.my_reports">Back to My Reports</a>
      <a class="btn" href="index.php?route=citizen.notifications">Notifications</a>
    </div>
  </div>

  <div class="card">
    <h2>Report #<?= (int)$report['id'] ?></h2>
    <div style="color:#6b7b83; font-size:13px">
      Submitted: <?= h($report['created_at']) ?> •
      Status:
      <?php
        if ($status === 'completed') echo '<span class="status s-completed">COMPLETED</span>';
        elseif ($status === 'claimed') echo '<span class="status s-claimed">CLAIMED</span>';
        else echo '<span class="status s-pending">PENDING</span>';
      ?>
    </div>
  </div>

  <div class="grid">
    <!-- Left: photos & description -->
    <div class="card">
      <h3 style="margin:0 0 8px">Before Photo</h3>
      <?php if ($photoBefore): ?>
        <img class="photo" src="<?= h($photoBefore) ?>" alt="">
      <?php else: ?>
        <div class="photo"></div>
      <?php endif; ?>

      <h3 style="margin:16px 0 8px">Description</h3>
      <div style="border:1px dashed var(--border); border-radius:12px; padding:12px; background:#fff;">
        <?= nl2br(h($desc)) ?>
      </div>

      <?php if ($photoAfter): ?>
        <h3 style="margin:16px 0 8px">After-cleanup Photo</h3>
        <img class="photo" src="<?= h($photoAfter) ?>" alt="">
      <?php endif; ?>
    </div>

    <!-- Right: location + result -->
    <div class="card">
      <h3 style="margin:0 0 8px">Location</h3>
      <dl>
        <dt>Latitude</dt><dd><?= h($gpsLat) ?></dd>
        <dt>Longitude</dt><dd><?= h($gpsLng) ?></dd>
        <dt>Map</dt>
        <dd>
          <?php if ($gpsLat !== '' && $gpsLng !== ''): ?>
            <a href="https://maps.google.com/?q=<?= urlencode($gpsLat.','.$gpsLng) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
          <?php else: ?>—<?php endif; ?>
        </dd>
      </dl>

      <h3 style="margin:16px 0 8px">Latest Result</h3>
      <?php if ($claim): ?>
        <dl>
          <dt>Claim status</dt><dd><?= h($claim['status'] ?? '') ?></dd>
          <dt>AI Category</dt><dd><?= h($catName ?: '-') ?></dd>
          <dt>Confidence</dt><dd><?= h($claim['ai_confidence'] ?? '') ?></dd>
          <dt>Collector notes</dt><dd><?= nl2br(h($claim['notes'] ?? '')) ?></dd>
          <dt>Shareable file</dt>
          <dd>
            <?php if ($resultLink): ?>
              <a class="btn btn-primary" href="<?= h($resultLink) ?>" target="_blank" rel="noopener">Open Result</a>
            <?php else: ?>
              —
            <?php endif; ?>
          </dd>
        </dl>
      <?php else: ?>
        <div style="color:#6b7b83;">Not claimed yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
