<?php
// collector/report_detail.php
// Raw, no helpers; accepts both collector and admin.
include("config\\db.php");

// ---- access guard (collector or admin) ----
if (empty($_SESSION['user_id'])) {
  header('Location: index.php?route=login'); exit;
}
$role       = $_SESSION['role'] ?? '';
$isAdmin    = ($role === 'admin');
$isCollector= ($role === 'collector');

if (!$isAdmin && !$isCollector) {
  http_response_code(403);
  echo "<h3>403 — Collector or Admin only</h3>";
  exit;
}

$viewerId  = (int)$_SESSION['user_id'];            // admin OR collector user id
$reportId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$claimId   = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : 0;

if ($reportId <= 0) { echo "<p>Invalid report id.</p>"; exit; }

/* -------- Fetch report -------- */
$report = null;
$qr = "SELECT id, citizen_id, collector_id, description, photo_path, gps_lat, gps_lng, status, created_at, assigned_at, completed_at
       FROM reports WHERE id=? LIMIT 1";
$st = mysqli_prepare($conn, $qr);
mysqli_stmt_bind_param($st, 'i', $reportId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$report = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);
if (!$report) { echo "<p>Report not found.</p>"; exit; }

/* -------- Fetch claim ----------
   Priority:
   1) If claim_id is provided → fetch that claim (if belongs to this report).
   2) Else:
      - Admin     : last claim of this report (any collector).
      - Collector : last claim BY THIS collector for this report.
*/
$claim = null;

if ($claimId > 0) {
  $qc = "SELECT id, report_id, collector_id, claimed_at, status,
                verified_waste_text, ai_category_id, ai_confidence,
                cleanup_photo_path, completed_at, notes
         FROM report_claims
         WHERE id=? AND report_id=? LIMIT 1";
  $st = mysqli_prepare($conn, $qc);
  mysqli_stmt_bind_param($st, 'ii', $claimId, $reportId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $claim = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}

if (!$claim) {
  if ($isAdmin) {
    // latest claim (any collector)
    $qc = "SELECT id, report_id, collector_id, claimed_at, status,
                  verified_waste_text, ai_category_id, ai_confidence,
                  cleanup_photo_path, completed_at, notes
           FROM report_claims
           WHERE report_id=?
           ORDER BY claimed_at DESC
           LIMIT 1";
    $st = mysqli_prepare($conn, $qc);
    mysqli_stmt_bind_param($st, 'i', $reportId);
  } else {
    // latest claim only by viewing collector
    $qc = "SELECT id, report_id, collector_id, claimed_at, status,
                  verified_waste_text, ai_category_id, ai_confidence,
                  cleanup_photo_path, completed_at, notes
           FROM report_claims
           WHERE report_id=? AND collector_id=?
           ORDER BY claimed_at DESC
           LIMIT 1";
    $st = mysqli_prepare($conn, $qc);
    mysqli_stmt_bind_param($st, 'ii', $reportId, $viewerId);
  }
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $claim = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}

/* -------- AI category name (optional) -------- */
$catName = '';
if (!empty($claim['ai_category_id'])) {
  $cid = (int)$claim['ai_category_id'];
  $r = mysqli_query($conn, "SELECT name FROM waste_categories WHERE id={$cid} LIMIT 1");
  if ($r && mysqli_num_rows($r)) $catName = (string)mysqli_fetch_row($r)[0];
}

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---- Optional Google Maps API key (for Google basemap via Leaflet.GoogleMutant) ----
   If you don't need Google tiles, leave it empty ('') and we keep OSM only.
*/
$GOOGLE_MAPS_API_KEY = ''; // e.g., 'AIza...'; keep '' to disable Google basemap

// Prepare coordinates for the map (cast to float if present)
$lat = isset($report['gps_lat']) ? (float)$report['gps_lat'] : 0.0;
$lng = isset($report['gps_lng']) ? (float)$report['gps_lng'] : 0.0;
$hasCoords = ($lat || $lng);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Smart Waste – Report Detail</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
/>
<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
  crossorigin="">
</script>

<?php if ($GOOGLE_MAPS_API_KEY !== ''): ?>
  <!-- Optional: GoogleMutant for Leaflet (Google tiles) -->
  <script src="https://unpkg.com/leaflet.gridlayer.googlemutant@0.13.5/Leaflet.GoogleMutant.js"></script>
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= h($GOOGLE_MAPS_API_KEY) ?>"></script>
<?php endif; ?>

<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20; --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --pending:#ffb300; --claimed:#0277bd; --completed:#2E7D32;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif}
  .wrap{max-width:1120px;margin:28px auto;padding:0 16px}
  .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
  .brand{display:flex;gap:10px;align-items:center;color:var(--green);font-weight:800}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark))}
  .btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid transparent;font-weight:700;text-decoration:none;cursor:pointer}
  .btn-outline{background:#fff;color:var(--green);border-color:var(--green)}
  .btn-primary{background:var(--green);color:#fff}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:12px}
  h2{margin:0 0 10px;font-size:20px}
  .grid{display:grid;gap:16px;grid-template-columns:7fr 5fr}
  .photo{width:100%;height:360px;object-fit:cover;border-radius:12px;border:1px solid var(--border);background:#fafafa}
  dl{display:grid;grid-template-columns:140px 1fr;gap:6px 10px;margin:0}
  dt{color:#607d8b;font-weight:700;font-size:13px}
  dd{margin:0;color:#37474f;font-size:14px}
  .status{display:inline-block;padding:4px 10px;border-radius:999px;color:#fff;font-weight:800;font-size:12px}
  .s-pending{background:var(--pending)} .s-claimed{background:var(--claimed)} .s-completed{background:var(--completed)}
  .map-box{margin-top:8px}
  #map { height: 260px; width: 100%; border:1px solid var(--border); border-radius:12px; }
  .base-switch{display:flex; gap:8px; margin-top:8px}
  .note { font-size: 12px; color: var(--muted); margin-top: 6px; }
  @media (max-width:980px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><span class="logo"></span><span>Smart Waste – <?php echo $isAdmin?'Admin (read-only)':'Collector'; ?></span></div>
    <div>
      <?php if ($isAdmin): ?>
        <a class="btn btn-outline" href="index.php?route=admin.reports_overview">Back to Reports</a>
      <?php else: ?>
        <a class="btn btn-outline" href="index.php?route=collector.reports_list">Back to Reports</a>
        <a class="btn btn-primary" href="index.php?route=collector.task_history">Task History</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
      <div>
        <h2 style="margin:0">Report #<?php echo (int)$report['id']; ?> – Detail</h2>
        <div style="margin-top:6px;color:#6b7b83;font-size:13px">
          Submitted: <?php echo h($report['created_at']); ?>
          &nbsp;•&nbsp; Status:
          <?php
            $st = $report['status'];
            if ($st === 'completed') echo '<span class="status s-completed">COMPLETED</span>';
            elseif ($st === 'claimed') echo '<span class="status s-claimed">CLAIMED</span>';
            else echo '<span class="status s-pending">PENDING</span>';
          ?>
        </div>
      </div>
      <div>
        <?php
        // Only show complete button to the collector who owns the claim and when report is claimed
        if (!$isAdmin && $report['status']==='claimed' && $claim && (int)$claim['collector_id']===$viewerId): ?>
          <a class="btn btn-primary"
             href="index.php?route=collector.complete_form&report_id=<?php echo (int)$report['id']; ?>&claim_id=<?php echo (int)$claim['id']; ?>">
             Complete Report
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="grid">
    <!-- Left column -->
    <div class="card">
      <h3 style="margin:0 0 8px">Before Photo</h3>
      <?php if (!empty($report['photo_path'])): ?>
        <img class="photo" src="<?php echo h($report['photo_path']); ?>" alt="">
      <?php else: ?>
        <div class="photo"></div>
      <?php endif; ?>

      <h3 style="margin:16px 0 8px">Citizen Description</h3>
      <div style="border:1px dashed var(--border);border-radius:12px;padding:12px;background:#fff;">
        <?php echo nl2br(h($report['description'])); ?>
      </div>
    </div>

    <!-- Right column -->
    <div class="card">
      <h3 style="margin:0 0 8px">Location</h3>
      <dl>
        <dt>Latitude</dt><dd><?php echo h($report['gps_lat']); ?></dd>
        <dt>Longitude</dt><dd><?php echo h($report['gps_lng']); ?></dd>
        <dt>Map</dt>
        <dd>
          <?php if ($hasCoords): ?>
            <a href="https://maps.google.com/?q=<?php echo urlencode($lat.','.$lng); ?>"
               target="_blank" rel="noopener">Open in Google Maps</a>
          <?php else: ?>
            —
          <?php endif; ?>
        </dd>
      </dl>

      <div class="map-box">
        <?php if ($hasCoords): ?>
          <div id="map"></div>
          <div class="base-switch">
            <button type="button" id="btnOSM" class="btn btn-outline">OSM</button>
            <?php if ($GOOGLE_MAPS_API_KEY !== ''): ?>
              <button type="button" id="btnGoogle" class="btn btn-outline">Google</button>
            <?php endif; ?>
          </div>
          <div class="note">Click marker for details. Use buttons to switch basemap.</div>
        <?php else: ?>
          <div style="padding:12px;border:1px dashed var(--border);border-radius:12px;background:#fff;color:#6b7b83">
            No GPS coordinates available for this report.
          </div>
        <?php endif; ?>
      </div>

      <h3 style="margin:16px 0 8px">Claim Info</h3>
      <?php if ($claim): ?>
        <dl>
          <dt>Claim ID</dt><dd><?php echo (int)$claim['id']; ?></dd>
          <dt>Claimed at</dt><dd><?php echo h($claim['claimed_at']); ?></dd>
          <dt>Claim Status</dt><dd><?php echo h($claim['status']); ?></dd>
          <dt>AI Category</dt><dd><?php echo h($catName ?: '-'); ?></dd>
          <dt>AI Confidence</dt><dd><?php echo h($claim['ai_confidence']); ?></dd>
          <dt>Cleanup Photo</dt>
          <dd>
            <?php if (!empty($claim['cleanup_photo_path'])): ?>
              <a href="<?php echo h($claim['cleanup_photo_path']); ?>" target="_blank" rel="noopener">Open</a>
            <?php else: ?>—<?php endif; ?>
          </dd>
          <dt>Notes</dt><dd><?php echo nl2br(h($claim['notes'])); ?></dd>
        </dl>
      <?php else: ?>
        <div style="color:#8a8a8a">
          <?php echo $isAdmin ? 'No claim found for this report.' : 'No claim in your name for this report.'; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($hasCoords): ?>
<script>
  // Report coords from PHP
  const REPORT_LAT = <?= json_encode($lat) ?>;
  const REPORT_LNG = <?= json_encode($lng) ?>;
  const REPORT_ID  = <?= (int)$report['id'] ?>;
  const DESC       = <?= json_encode($report['description'] ?? '') ?>;
  const HAS_GOOGLE = <?= $GOOGLE_MAPS_API_KEY !== '' ? 'true' : 'false' ?>;

  // Basemap: OSM
  const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  });

  // Init map
  const map = L.map('map', {
    center: [REPORT_LAT, REPORT_LNG],
    zoom: 15,
    layers: [osm]
  });

  // Optional Google basemap
  let googleLayer = null;
  if (HAS_GOOGLE && L.gridLayer && L.gridLayer.googleMutant) {
    googleLayer = L.gridLayer.googleMutant({ type: 'roadmap' });
  }

  // Marker
  const m = L.marker([REPORT_LAT, REPORT_LNG]).addTo(map);
  const gLink = `https://maps.google.com/?q=${REPORT_LAT},${REPORT_LNG}`;
  m.bindPopup(`
    <div style="min-width:200px">
      <b>Report #${REPORT_ID}</b><br/>
      <div style="color:#546e7a">${escapeHtml(DESC)}</div>
      <div style="margin-top:6px">
        <a class="btn btn-outline" href="${gLink}" target="_blank" rel="noopener">Open in Google Maps</a>
      </div>
    </div>
  `);

  // Basemap switch
  document.getElementById('btnOSM')?.addEventListener('click', () => {
    if (!map.hasLayer(osm)) map.addLayer(osm);
    if (googleLayer && map.hasLayer(googleLayer)) map.removeLayer(googleLayer);
  });
  document.getElementById('btnGoogle')?.addEventListener('click', () => {
    if (!googleLayer) return;
    if (!map.hasLayer(googleLayer)) map.addLayer(googleLayer);
    if (map.hasLayer(osm)) map.removeLayer(osm);
  });

  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, ch => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[ch]));
  }
</script>
<?php endif; ?>
</body>
</html>
