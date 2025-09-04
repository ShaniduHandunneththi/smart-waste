<?php
// citizen/my_reports.php
include("config\db.php");

// ---- Auth guard: only logged-in citizens ----
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'citizen') {
  header('Location: index.php?route=login');
  exit;
}

// tiny escape helper
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// current citizen id
$cid = (int)($_SESSION['user_id'] ?? 0);

// fetch this citizen's reports
$stmt = $conn->prepare("
  SELECT id, description, status, created_at, photo_path, category, completed_at
  FROM reports
  WHERE citizen_id = ?
  ORDER BY created_at DESC
");
$stmt->bind_param('i', $cid);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// map status to CSS class
function status_class(string $s): string {
  $s = strtolower($s);
  if ($s === 'completed') return 's-completed';
  if ($s === 'claimed')   return 's-claimed';
  return 's-pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – My Reports</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --pending:#ffb300; --claimed:#0277bd; --completed:#2E7D32;
  }
  *{box-sizing:border-box}
  body{ margin:0; background:var(--bg); color:var(--text);
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;}
  .wrap{max-width:1100px; margin:28px auto; padding:0 16px;}
  .top{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
  .brand{display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800;}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
  .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid transparent;
       font-weight:700; cursor:pointer; font-size:14px; text-decoration:none;}
  .btn-outline{background:#fff; color:var(--green); border-color:var(--green)}
  .btn-primary{background:var(--green); color:#fff}
  .btn-outline:hover{background:#eef5ef}
  .btn-primary:hover{background:var(--green-dark)}
  .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px;}
  h2{margin:0 0 10px; font-size:20px}
  .filters{display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;}
  .input,.select{padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; font-size:14px;}
  .legend{margin-left:auto; display:flex; align-items:center; gap:10px; font-size:12px; color:var(--muted)}
  .dot{width:10px;height:10px;border-radius:999px;display:inline-block}
  .d-pending{background:var(--pending)} .d-claimed{background:var(--claimed)} .d-completed{background:var(--completed)}
  .table{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border:1px solid var(--border); border-radius:12px;}
  .table th,.table td{padding:12px; text-align:left; font-size:14px; border-bottom:1px solid var(--border); vertical-align:middle;}
  .table thead th{background:#f9fbfb; font-weight:700; color:#455a64;}
  .table tbody tr:hover{background:#fcfdfd;}
  .thumb{width:64px; height:48px; object-fit:cover; border-radius:8px; border:1px solid var(--border);}
  .status{padding:4px 10px; font-size:12px; font-weight:700; border-radius:999px; color:#fff; display:inline-block;}
  .s-pending{ background:var(--pending) } .s-claimed{ background:var(--claimed) } .s-completed{ background:var(--completed) }
  .actions a{text-decoration:none; font-weight:700; font-size:13px; margin-right:8px;}
  .a-view{ color:#0277bd } .a-open{ color:#2E7D32 } .a-open.disabled{ color:#9e9e9e; pointer-events:none; opacity:.6 }
  .empty{margin-top:12px; padding:24px; background:#fff; border:1px dashed var(--border); border-radius:12px; text-align:center; color:var(--muted);}
</style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="brand"><span class="logo"></span><span>Smart Waste – Citizen</span></div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn btn-outline" href="index.php?route=citizen.dashboard">Dashboard</a>
        <a class="btn btn-primary" href="index.php?route=citizen.submit_report">Submit New Report</a>
      </div>
    </div>

    <div class="card">
      <h2>My Reports</h2>

      <!-- Table -->
      <table class="table" id="reportsTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>Before Photo</th>
            <th>Description</th>
            <th>Status</th>
            <th>Created</th>
            <th style="width:160px">Action</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php if ($rows): ?>
            <?php foreach ($rows as $r): ?>
              <tr data-status="<?= h(strtolower($r['status'])) ?>">
                <td>#<?= h($r['id']) ?></td>
                <td>
                  <?php if (!empty($r['photo_path'])): ?>
                    <img class="thumb" src="<?= h($r['photo_path']) ?>" alt="before">
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td><?= h($r['description']) ?></td>
                <td><span class="status <?= h(status_class($r['status'])) ?>"><?= strtoupper(h($r['status'])) ?></span></td>
                <td><?= h($r['created_at']) ?></td>
                <td class="actions">
                  <!-- FIX: raw link instead of url_to() -->
                  <a class="a-view" href="index.php?route=citizen.report_view&id=<?= (int)$r['id'] ?>">View</a>

                  <?php
                    $isCompleted = (strtolower($r['status']) === 'completed') || !empty($r['completed_at']);
                    $reportLink = 'uploads/reports/report_' . (int)$r['id'] . '.html';
                  ?>
                  <?php if ($isCompleted): ?>
                    <a href="index.php?route=citizen.view_result&id=<?= (int)$r['id'] ?>">Open Result</a>

                  <?php else: ?>
                    <a class="a-open disabled" href="#" title="Result available after completion">Open Result</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div id="empty" class="empty" style="<?= empty($rows) ? '' : 'display:none;' ?>">
        No reports found. Try changing filters or submit a new report.
      </div>
    </div>
  </div>
</body>
</html>
