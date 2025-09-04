<?php
// citizen/view_result.php
include('config/db.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'citizen') {
  http_response_code(403);
  exit('403');
}

// --- base URL (works when app is in a sub-folder) ---
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($BASE === '' || $BASE === '.') $BASE = '';

// --- fetch report + completed claim info ---
$reportId = (int)($_GET['id'] ?? 0);
$sql = "SELECT r.id, r.description, r.category, r.created_at, r.completed_at,
               c.verified_waste_text, c.ai_category_id, c.ai_confidence, c.cleanup_photo_path
        FROM reports r
        LEFT JOIN report_claims c
          ON c.report_id = r.id AND c.status = 'completed'
        WHERE r.id = ? AND r.citizen_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $reportId, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$res  = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$data) { http_response_code(404); exit('Result not found'); }

// --- AI label/confidence ---
$labelMap = [1=>'Organic', 2=>'Recyclable', 3=>'Hazardous'];
$aiLabel  = $labelMap[(int)($data['ai_category_id'] ?? 0)] ?? '—';
$conf     = ($data['ai_confidence'] !== null)
          ? number_format((float)$data['ai_confidence'] * 100, 1) . '%'
          : '—';

// --- cleanup photo url (only if path exists) ---
$cleanupUrl = null;
if (!empty($data['cleanup_photo_path'])) {
  // stored like "uploads/cleanup/cleanup_4_...jpg"
  $rel = ltrim((string)$data['cleanup_photo_path'], '/'); // avoid double slashes
  $cleanupUrl = $BASE . '/' . $rel; // same-origin URL -> good for html2canvas
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Report #<?= (int)$data['id'] ?> — Result</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root{
      --green:#2E7D32; --green-dark:#1B5E20;
      --text:#263238; --muted:#6b7b83;
      --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    }
    *{box-sizing:border-box}
    body{margin:0; background:var(--bg); color:var(--text);
         font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;}
    .wrap{max-width:1100px; margin:28px auto; padding:0 16px;}
    .brand{display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800; margin-bottom:8px;}
    .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
    .top-right{display:flex; gap:8px; justify-content:flex-end; margin-bottom:10px;}
    .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid var(--green);
         text-decoration:none; font-weight:700; color:var(--green); background:#fff; cursor:pointer;}
    .btn:hover{background:#eef5ef}
    h1{margin:8px 0 10px; font-size:26px}
    .meta{color:var(--muted); font-size:14px; margin-bottom:14px;}
    .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; margin:14px 0;}
    .card h3{margin:0 0 8px; font-size:16px; color:#37474f}
    img{max-width:100%; border:1px solid #eee; border-radius:8px}

    /* Printable container (white background for clean PDF) */
    #resultDoc{background:#fff; padding:8px; border-radius:12px;}
    /* Optional: optimize for print */
    @media print {
      .top-right { display:none; }
      body { background:#fff; }
      #resultDoc { box-shadow:none; border:none; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand"><span class="logo"></span><span>Smart Waste – Citizen</span></div>

    <div class="top-right">
      <a class="btn" href="<?= h($BASE) ?>/index.php?route=citizen.my_reports">← Back to My Reports</a>
      <button class="btn" id="btnPdf">⬇ Download PDF</button>
      <button class="btn" onclick="window.print()">🖨️ Print</button>
    </div>

    <!-- Everything inside this container goes to PDF -->
    <div id="resultDoc">
      <h1>Report #<?= (int)$data['id'] ?> — Result</h1>
      <div class="meta">
        Submitted: <?= h($data['created_at']) ?> •
        Completed: <?= h($data['completed_at'] ?? '—') ?>
      </div>

      <div class="card">
        <h3>Description</h3>
        <p><?= h($data['description'] ?? '') ?></p>

        <h3>Verified Waste Text</h3>
        <p><?= h($data['verified_waste_text'] ?? '') ?></p>
      </div>

      <div class="card">
        <h3>Final Category</h3>
        <p>AI: <b><?= h($aiLabel) ?></b> (confidence: <?= h($conf) ?>)</p>
      </div>

      <div class="card">
        <h3>Cleanup Photo</h3>
        <?php if ($cleanupUrl): ?>
          <img src="<?= h($cleanupUrl) ?>" alt="Cleanup photo">
        <?php else: ?>
          <p style="color:var(--muted)">No cleanup photo uploaded.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Client-side PDF (no new server files): html2pdf bundle (html2canvas + jsPDF) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"
          integrity="sha512-YcsIPzFDa9bP8l1OEec3NvG4dVt8b0cN7g8nXqZQ7Hq3v3P3Jw1pG7v6s8m4+5lQd7o1rVxJQvO5T5oXn5QW1g=="
          crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    document.getElementById('btnPdf')?.addEventListener('click', () => {
      const reportId = <?= (int)$data['id'] ?>;
      const el = document.getElementById('resultDoc');

      // Options tuned for A4 and good quality
      const opt = {
        margin:       [10, 10, 10, 10],  // top, left, bottom, right (mm)
        filename:     `report_${reportId}_result.pdf`,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
      };

      // Convert the specific container to PDF and save
      html2pdf().set(opt).from(el).save();
    });
  </script>
</body>
</html>
