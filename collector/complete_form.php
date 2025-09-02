<?php
// collector/complete_form.php
// Finish a claimed report, call Flask model, and persist AI results.

include("config\db.php");

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'collector') {
  http_response_code(403);
  echo "<h3>403 — Collector only</h3>";
  exit;
}

$collectorId = (int)($_SESSION['user_id'] ?? 0);
$reportId    = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$claimId     = isset($_GET['claim_id'])  ? (int)$_GET['claim_id']  : 0;

if ($reportId <= 0 || $claimId <= 0) {
  echo "<p>Invalid request: report or claim missing.</p>";
  exit;
}

/* ------------------ helpers ------------------ */
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Resolve a waste category name to its DB id (waste_categories.id).
 * Falls back to simple map Organic=1, Recyclable=2, Hazardous=3 if name not found.
 */
function category_name_to_id(mysqli $conn, string $name): int {
  $name = trim(strtolower($name));
  $sql  = "SELECT id FROM waste_categories WHERE LOWER(name)=? LIMIT 1";
  if ($st = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($st, 's', $name);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
      mysqli_stmt_close($st);
      return (int)$row['id'];
    }
    mysqli_stmt_close($st);
  }

  // fallback fixed mapping (ensure these IDs exist in your DB)
  if ($name === 'organic')     return 1;
  if ($name === 'recyclable')  return 2;
  if ($name === 'hazardous')   return 3;
  return 0; // unknown
}

/**
 * Call Flask model API with verified text.
 * Returns ['label' => string, 'confidence' => float] or null on failure.
 */
function call_flask_predict(string $text): ?array {
  // Change this if your Flask service is on a different host/port.
  $flaskUrl = "http://127.0.0.1:8000/predict";

  $ch = curl_init($flaskUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["text" => $text]));
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || !empty($err)) {
    // Log error in server logs if needed
    return null;
  }
  $data = json_decode($resp, true);
  if (!$data || empty($data['ok'])) return null;

  // API returns either { result: {...} } or { results: [...] }
  if (!empty($data['result']) && is_array($data['result'])) {
    $r = $data['result'];
    if (isset($r['label']) && isset($r['confidence'])) {
      return ['label' => (string)$r['label'], 'confidence' => (float)$r['confidence']];
    }
  }
  return null;
}

/**
 * Confirm this claim belongs to the current collector and the report id matches.
 */
function can_complete(mysqli $conn, int $claimId, int $reportId, int $collectorId): bool {
  $q = "SELECT id FROM report_claims 
        WHERE id=? AND report_id=? AND collector_id=? AND status IN ('claimed','in_progress') 
        LIMIT 1";
  if ($st = mysqli_prepare($conn, $q)) {
    mysqli_stmt_bind_param($st, 'iii', $claimId, $reportId, $collectorId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok  = $res && mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    return (bool)$ok;
  }
  return false;
}

/* ---------------- pre-check: claim ownership ----------------- */
if (!can_complete($conn, $claimId, $reportId, $collectorId)) {
  echo "<p>Invalid claim or not authorized to complete this report.</p>";
  exit;
}

/* --------------- handle submit --------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $verifiedText = trim($_POST['verified_waste_text'] ?? '');
  $notes        = trim($_POST['notes'] ?? '');

  if ($verifiedText === '') {
    echo "<p>Please provide Verified Waste Text.</p>";
    exit;
  }

  // 1) Call Flask to classify the verified text
  $pred        = call_flask_predict($verifiedText);
  $aiLabel     = $pred['label'] ?? '';
  $aiConf      = isset($pred['confidence']) ? (float)$pred['confidence'] : 0.0;
  $aiCategoryId = 0;

  if ($aiLabel !== '') {
    $aiCategoryId = category_name_to_id($conn, $aiLabel);
  }

  // 2) Upload cleanup photo (optional)
  $cleanupPath = null;
  if (!empty($_FILES['cleanup_photo']['name']) && is_uploaded_file($_FILES['cleanup_photo']['tmp_name'])) {
    $uploadDir = __DIR__ . '/../uploads/cleanup/';
    if (!is_dir($uploadDir)) {
      @mkdir($uploadDir, 0777, true);
    }
    $ext   = strtolower(pathinfo($_FILES['cleanup_photo']['name'], PATHINFO_EXTENSION));
    if ($ext === '') $ext = 'jpg';
    $fname = 'cleanup_' . $reportId . '_' . time() . '.' . preg_replace('/[^a-z0-9]+/i', '', $ext);
    $target = $uploadDir . $fname;
    if (move_uploaded_file($_FILES['cleanup_photo']['tmp_name'], $target)) {
      $cleanupPath = 'uploads/cleanup/' . $fname;
    }
  }

  // 3) Update the claim row (persist AI outputs!)
  $sql = "UPDATE report_claims
             SET status='completed',
                 verified_waste_text=?,
                 ai_category_id=?,
                 ai_confidence=?,
                 cleanup_photo_path=?,
                 notes=?,
                 completed_at=NOW()
           WHERE id=? AND report_id=? AND collector_id=?";
  if ($st = mysqli_prepare($conn, $sql)) {
    // ai_confidence stored as decimal/float; ensure type 'd'
    mysqli_stmt_bind_param(
      $st,
      'sidssiii',
      $verifiedText,
      $aiCategoryId,
      $aiConf,
      $cleanupPath,
      $notes,
      $claimId,
      $reportId,
      $collectorId
    );
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
  }

  // 4) Update reports table summary status
  if ($st = mysqli_prepare($conn, "UPDATE reports SET status='completed', completed_at=NOW() WHERE id=? AND collector_id=?")) {
    mysqli_stmt_bind_param($st, 'ii', $reportId, $collectorId);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
  }

  // 5) Store cleanup photo as a document record (optional)
  if ($cleanupPath) {
    if ($st = mysqli_prepare($conn, "INSERT INTO report_documents (report_id, doc_type, file_path, created_at) VALUES (?, 'cleanup_photo', ?, NOW())")) {
      mysqli_stmt_bind_param($st, 'is', $reportId, $cleanupPath);
      mysqli_stmt_execute($st);
      mysqli_stmt_close($st);
    }
  }

  // 6) Notify citizen that the report is completed
  //    We also fetch citizen_id for this report to notify correctly.
  $citizenId = 0;
  if ($r = mysqli_query($conn, "SELECT citizen_id FROM reports WHERE id=".(int)$reportId." LIMIT 1")) {
    if ($row = mysqli_fetch_assoc($r)) $citizenId = (int)$row['citizen_id'];
  }
  if ($citizenId > 0) {
    $msg = "Your report #{$reportId} has been completed.";
    $typ = 'completed';
    if ($st = mysqli_prepare($conn, "INSERT INTO notifications (user_id, report_id, type, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())")) {
      mysqli_stmt_bind_param($st, 'iiss', $citizenId, $reportId, $typ, $msg);
      mysqli_stmt_execute($st);
      mysqli_stmt_close($st);
    }
  }

  // Done
  header("Location: index.php?route=collector.task_history");
  exit;
}

/* --------------- GET: show form --------------- */
// (Optional) Pull minimal report context for header display
$desc = '';
$photo = '';
if ($st = mysqli_prepare($conn, "SELECT description, photo_path FROM reports WHERE id=? LIMIT 1")) {
  mysqli_stmt_bind_param($st, 'i', $reportId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  if ($res && ($row = mysqli_fetch_assoc($res))) {
    $desc = (string)$row['description'];
    $photo = (string)$row['photo_path'];
  }
  mysqli_stmt_close($st);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Complete Report #<?php echo (int)$reportId; ?></title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;margin:24px;background:#f5f7f8;color:#263238}
    .wrap{max-width:860px;margin:0 auto}
    .header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
    .card{background:#fff;border:1px solid #e6eaee;border-radius:12px;padding:16px}
    .meta{color:#6b7b83;font-size:13px;margin-bottom:12px}
    label{display:block;margin-top:12px;font-weight:700}
    input,textarea,select{width:100%;padding:10px;margin-top:6px;border:1px solid #e1e6ea;border-radius:10px}
    .btn{margin-top:16px;padding:12px 16px;font-weight:800;border:none;border-radius:10px;cursor:pointer;background:#2E7D32;color:#fff}
    .muted{color:#6b7b83}
    .thumb{width:120px;height:90px;object-fit:cover;border:1px solid #e1e6ea;border-radius:8px}
    .row{display:flex;gap:12px;align-items:center}
  </style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h2>Complete Report #<?php echo (int)$reportId; ?></h2>
    <div><a href="index.php?route=collector.task_history" style="text-decoration:none;color:#0277bd;font-weight:800">Back to Task History</a></div>
  </div>

  <div class="card">
    <div class="meta">
      <?php if ($photo): ?>
        <div class="row"><img class="thumb" src="<?php echo h($photo); ?>" alt=""> <div class="muted"><?php echo h($desc); ?></div></div>
      <?php else: ?>
        <div class="muted"><?php echo h($desc ?: ''); ?></div>
      <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data">
      <label>Verified Waste Text <span class="muted">(what you found & removed)</span></label>
      <textarea name="verified_waste_text" rows="3" required></textarea>

      <!-- We don’t show category/confidence fields to avoid conflicts.
           They are filled from Flask automatically on submit and saved into DB. -->

      <label>Cleanup Photo (after cleanup) <span class="muted">(optional)</span></label>
      <input type="file" name="cleanup_photo" accept="image/*">

      <label>Notes <span class="muted">(optional)</span></label>
      <textarea name="notes" rows="2" placeholder="e.g., cleaned area, special handling, etc."></textarea>

      <button class="btn" type="submit">Mark Completed</button>
    </form>
    <p class="muted" style="margin-top:10px">
      On submit, the app sends the “Verified Waste Text” to the AI model, saves AI Category & Confidence, and completes the claim.
    </p>
  </div>
</div>
</body>
</html>
