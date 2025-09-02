<?php
// citizen/submit_report.php
// Convert of "submit_report.html" → PHP that writes to DB

// 1) DB + session
include("config\db.php");

// 2) Guard: citizen only
if (!isset($_SESSION['user_id'])) {
  header('Location: /index.php?route=login'); exit;
}
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'citizen') {
  http_response_code(403);
  echo "<h2 style='font-family:system-ui'>403 – Forbidden</h2><p>Citizen access only.</p>";
  exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'Citizen';

// helper
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$err = '';
$ok  = '';
$finalPath = '';

// 3) Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $desc   = trim($_POST['description'] ?? '');
  $gpsLat = trim($_POST['gps_lat'] ?? '');
  $gpsLng = trim($_POST['gps_lng'] ?? '');

  // basic validation
  if ($desc === '')           $err = 'Please add a short description.';
  if (!$err && $gpsLat === '') $err = 'GPS latitude missing (enable location or enter manually).';
  if (!$err && $gpsLng === '') $err = 'GPS longitude missing (enable location or enter manually).';

  // photo (optional but recommended) — you can make it required by checking empty($_FILES['photo']['name'])
  if (!$err && isset($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $allowed = ['image/jpeg','image/png','image/webp','image/jpg'];
    $type    = mime_content_type($_FILES['photo']['tmp_name']);
    $size    = (int)$_FILES['photo']['size'];
    if (!in_array($type, $allowed, true)) {
      $err = 'Only JPG/PNG/WEBP images are allowed.';
    } elseif ($size > 4 * 1024 * 1024) {
      $err = 'Image is too large (max 4MB).';
    } else {
      // move to /uploads/photos/
      $dir = __DIR__ . '/../uploads/photos/';
      if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
      }
      $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
      $name = 'cit_'.$userId.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
      $dest = $dir . $name;
      if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
        $err = 'Failed to save uploaded photo.';
      } else {
        // path to save in DB (web path)
        $finalPath = 'uploads/photos/'.$name;
      }
    }
  }

  // If all good → insert
  if (!$err) {
    $sql = "INSERT INTO reports
              (citizen_id, collector_id, description, photo_path, gps_lat, gps_lng, status, created_at, updated_at)
            VALUES
              (?, NULL, ?, ?, ?, ?, 'pending', NOW(), NOW())";
    if ($stmt = mysqli_prepare($conn, $sql)) {
      mysqli_stmt_bind_param($stmt, 'issss', $userId, $desc, $finalPath, $gpsLat, $gpsLng);
      if (mysqli_stmt_execute($stmt)) {
        $newId = mysqli_insert_id($conn);

        // optional: record a "general" notification for citizen
        // $n = mysqli_prepare($conn, "INSERT INTO notifications(user_id, report_id, type, message, is_read, created_at)
        //                             VALUES (?, ?, 'general', 'Your report has been submitted.', 0, NOW())");
        // mysqli_stmt_bind_param($n, 'ii', $userId, $newId); mysqli_stmt_execute($n); mysqli_stmt_close($n);

        $ok = "Report submitted successfully! (ID: #{$newId})";
      } else {
        $err = 'DB error while saving report.';
      }
      mysqli_stmt_close($stmt);
    } else {
      $err = 'DB prepare failed.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – Submit Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
  }
  *{box-sizing:border-box}
  body{
    margin:0; background:var(--bg); color:var(--text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;
  }
  .wrap{ max-width:900px; margin:28px auto; padding:0 16px; }
  .top{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
  .brand{ display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800; }
  .logo{ width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark)); }
  .btn{ display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid transparent; font-weight:700; cursor:pointer; text-decoration:none; }
  .btn-outline{ background:#fff; color:var(--green); border-color:var(--green) }
  .btn-primary{ background:var(--green); color:#fff }
  .btn-outline:hover{ background:#eef5ef }
  .btn-primary:hover{ background:var(--green-dark) }

  .card{ background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; }
  h2{ margin:0 0 12px; font-size:20px }

  .row{ display:grid; gap:12px; grid-template-columns: 1fr 1fr; }
  .row-1{ grid-template-columns: 1fr; }
  label{ font-weight:700; color:#37474f; display:block; margin-bottom:6px; }
  .input, .textarea{
    width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; font-size:14px;
  }
  .textarea{ min-height:120px; resize:vertical; }
  .muted{ color:var(--muted); font-size:12px }

  .hint{ margin-top:6px; font-size:12px; color:#607d8b }
  .banner-ok{ background:#e9f5eb; border:1px solid #cfe5d3; color:#1b5e20; padding:10px 12px; border-radius:10px; margin-bottom:12px; }
  .banner-err{ background:#fdecea; border:1px solid #f5c6cb; color:#c62828; padding:10px 12px; border-radius:10px; margin-bottom:12px; }

  .preview{
    margin-top:8px; height:160px; width:100%; border:1px dashed var(--border); border-radius:12px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#fafafa;
  }
  .preview img{ max-height:100%; max-width:100%; object-fit:cover; }
  .actions{ display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
  @media (max-width:700px){ .row{ grid-template-columns:1fr; } }
</style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="brand"><span class="logo"></span><span>Smart Waste – Citizen</span></div>
      <div>
        <a class="btn btn-outline" href="index.php?route=citizen.dashboard">Dashboard</a>
        <a class="btn btn-outline" href="index.php?route=citizen.my_reports">My Reports</a>
      </div>
    </div>

    <div class="card">
      <h2>Submit a Waste Report</h2>

      <?php if ($err): ?>
        <div class="banner-err"><?= h($err) ?></div>
      <?php elseif ($ok): ?>
        <div class="banner-ok"><?= h($ok) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <div class="row-1">
          <div>
            <label for="description">Description</label>
            <textarea class="textarea" id="description" name="description" placeholder="e.g., Plastic bottles near the park fence…" required><?= h($_POST['description'] ?? '') ?></textarea>
            <div class="hint">Add keywords like "bottles, wrappers" to help classification.</div>
          </div>
        </div>

        <div class="row">
          <div>
            <label for="photo">Photo (before cleanup)</label>
            <input class="input" type="file" id="photo" name="photo" accept="image/*" />
            <div class="preview" id="preview"><span class="muted">Image preview</span></div>
            <div class="hint">JPG/PNG/WEBP, max 4MB.</div>
          </div>

          <div>
            <label>GPS (auto)</label>
            <div class="row">
              <div>
                <label for="gps_lat" class="muted">Latitude</label>
                <input class="input" id="gps_lat" name="gps_lat" placeholder="lat" value="<?= h($_POST['gps_lat'] ?? '') ?>" />
              </div>
              <div>
                <label for="gps_lng" class="muted">Longitude</label>
                <input class="input" id="gps_lng" name="gps_lng" placeholder="lng" value="<?= h($_POST['gps_lng'] ?? '') ?>" />
              </div>
            </div>
            <div class="hint" id="gpsHint">Trying to get location… Allow location access.</div>
          </div>
        </div>

        <div class="actions">
          <button type="submit" class="btn btn-primary">Submit Report</button>
          <a class="btn btn-outline" href="index.php?route=citizen.my_reports">Cancel</a>
        </div>
      </form>
    </div>
  </div>

<script>
// photo preview
const photo = document.getElementById('photo');
const preview = document.getElementById('preview');
photo?.addEventListener('change', () => {
  preview.innerHTML = '';
  const f = photo.files && photo.files[0];
  if (!f) { preview.innerHTML = '<span class="muted">Image preview</span>'; return; }
  const img = document.createElement('img');
  img.src = URL.createObjectURL(f);
  preview.appendChild(img);
});

// geolocation
const gpsLat = document.getElementById('gps_lat');
const gpsLng = document.getElementById('gps_lng');
const gpsHint= document.getElementById('gpsHint');

if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      gpsLat.value = pos.coords.latitude.toFixed(6);
      gpsLng.value = pos.coords.longitude.toFixed(6);
      gpsHint.textContent = 'Location captured.';
    },
    () => { gpsHint.textContent = 'Could not fetch GPS automatically. You can type it manually.'; },
    {enableHighAccuracy:true, timeout:7000, maximumAge:0}
  );
} else {
  gpsHint.textContent = 'Geolocation not supported. Enter coordinates manually.';
}
</script>
</body>
</html>
