<?php
// citizen/notifications.php
// List & filter notifications; allow marking as read.

include("config\db.php");

// ---- guard: only logged-in citizens ----
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'citizen') {
  http_response_code(403);
  echo "<h2 style='font-family:system-ui'>403 – Forbidden</h2><p>Citizen access only.</p>";
  exit;
}
$userId = (int)$_SESSION['user_id'];

// tiny escaper
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ============================================================
   AJAX: mark notification as read
   POST -> _ajax=1, action=mark_read, id=notif_id
   ============================================================ */
if (isset($_GET['_ajax']) && $_GET['_ajax'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  $action = $_POST['action'] ?? '';
  if ($action === 'mark_read') {
    $nid = (int)($_POST['id'] ?? 0);
    if ($nid > 0) {
      $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
      if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, 'ii', $nid, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
      }
    }
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false, 'error'=>'unknown action']); exit;
}

/* ============================================================
   FETCH notifications for this user
   Left-join reports to get status + result_path for "Open Result".
   ============================================================ */
$rows = [];
$sql = "SELECT
          n.id, n.report_id, n.type, n.message, n.is_read, n.created_at,
          r.status AS r_status, r.result_path AS r_result
        FROM notifications n
        LEFT JOIN reports r ON r.id = n.report_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 200";
if ($stmt = mysqli_prepare($conn, $sql)) {
  mysqli_stmt_bind_param($stmt, 'i', $userId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
  mysqli_stmt_close($stmt);
}

// helpers for badges
function badgeClass(string $type): string {
  $type = strtolower($type ?: 'general');
  if ($type === 'claimed')   return 'b-claimed';
  if ($type === 'completed') return 'b-completed';
  return 'b-general';
}
function badgeLabel(string $type): string {
  $type = strtoupper($type ?: 'INFO');
  return $type;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – Notifications</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --info:#0277bd; --success:#2E7D32; --warn:#ffb300;
  }
  *{box-sizing:border-box}
  body{ margin:0; background:var(--bg); color:var(--text);
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif; }
  .wrap{max-width:900px; margin:28px auto; padding:0 16px;}
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
  .toolbar{display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;}
  .select, .input{padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; font-size:14px;}
  .legend{margin-left:auto; display:flex; align-items:center; gap:12px; font-size:12px; color:var(--muted)}
  .dot{width:10px;height:10px;border-radius:999px;display:inline-block}
  .d-claimed{background:var(--info)} .d-completed{background:var(--success)} .d-general{background:var(--warn)}
  .list{ display:flex; flex-direction:column; gap:10px; }
  .item{ border:1px solid var(--border); border-radius:12px; background:#fff; padding:12px 14px;
         display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center; }
  .item.unread{ border-color:#cfe5d3; background:#f7fbf8; }
  .meta{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:12px; color:var(--muted); }
  .badge{ font-size:12px; font-weight:800; letter-spacing:.2px; padding:4px 8px; border-radius:999px; color:#fff; display:inline-block; }
  .b-claimed{ background:var(--info) } .b-completed{ background:var(--success) } .b-general{ background:var(--warn) }
  .title{ font-weight:800; color:#37474f; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
  .msg{ color:#455a64; font-size:14px; }
  .acts{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
  .link{ text-decoration:none; font-weight:700; font-size:13px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); color:#0277bd; background:#fff;}
  .link:hover{ background:#f2f9ff }
  .link.primary{ border-color:var(--green); color:#2E7D32 }
  .link.primary:hover{ background:#eef5ef }
  .small{ font-size:12px; color:var(--muted) }
  .empty{ margin-top:12px; padding:24px; background:#fff; border:1px dashed var(--border); border-radius:12px; text-align:center; color:var(--muted); display:none; }
  @media (max-width:680px){ .item{ grid-template-columns: 1fr; } .acts{ justify-content:flex-start; } }
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
      <h2>Notifications</h2>

      <!-- Toolbar -->
      <div class="toolbar">
        <input id="q" class="input" type="text" placeholder="Search notifications…">
        <select id="fType" class="select">
          <option value="">All types</option>
          <option value="claimed">Claimed</option>
          <option value="completed">Completed</option>
        </select>
        <select id="fRead" class="select">
          <option value="">All</option>
          <option value="unread">Unread only</option>
          <option value="read">Read only</option>
        </select>
        <div class="legend">
          <span><span class="dot d-claimed"></span> Claimed</span>
          <span><span class="dot d-completed"></span> Completed</span>
          <span><span class="dot d-general"></span> General</span>
        </div>
      </div>

      <!-- Render notifications from DB -->
      <div id="list" class="list">
        <?php if ($rows): ?>
          <?php foreach ($rows as $n): 
              $nid    = (int)($n['id'] ?? 0);
              $rid    = isset($n['report_id']) ? (int)$n['report_id'] : 0;
              $type   = strtolower($n['type'] ?? 'general');
              $isRead = (int)($n['is_read'] ?? 0);
              $when   = !empty($n['created_at']) ? date('Y-m-d H:i', strtotime($n['created_at'])) : '';
              $badgeC = badgeClass($type);
              $badgeL = badgeLabel($type);

              $viewLink   = $rid ? "index.php?route=citizen.report_view&id=".$rid : "";
              $resultLink = "";
              if (($n['r_status'] ?? '') === 'completed' && !empty($n['r_result'])) {
                $resultLink = $n['r_result'];
              }
          ?>
          <div class="item <?= $isRead ? '' : 'unread' ?>"
               data-type="<?= h($type) ?>" data-read="<?= $isRead ? '1':'0' ?>"
               data-term="<?= h(($n['message'] ?? '').' report '.($rid ?: '')) ?>">
            <div>
              <div class="title">
                <span class="badge <?= $badgeC ?>"><?= h($badgeL) ?></span>
                <?= $rid ? 'Report #'.(int)$rid : 'Notification' ?>
              </div>
              <div class="msg"><?= nl2br(h($n['message'] ?? '')) ?></div>
              <div class="meta">
                <?php if ($rid): ?><span class="small">ID: <?= (int)$rid ?></span><?php endif; ?>
                <span class="small"><?= h($when) ?></span>
              </div>
            </div>
            <div class="acts">
              <?php if ($rid): ?>
                <a class="link" href="<?= h($viewLink) ?>">View report</a>
              <?php endif; ?>
              <?php if ($resultLink): ?>
                <a class="link primary" href="<?= h($resultLink) ?>" target="_blank" rel="noopener">Open Result</a>
              <?php else: ?>
                <?php if ($type === 'completed'): ?>
                  <span class="small">Result not uploaded yet</span>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!$isRead): ?>
                <a class="link" href="#" data-markread data-id="<?= (int)$nid ?>">Mark as read</a>
              <?php else: ?>
                <span class="small">Read</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div id="empty" class="empty">No notifications found. Try changing filters.</div>
    </div>
  </div>

<script>
  // Client-side filter & AJAX mark-as-read
  const q      = document.getElementById('q');
  const fType  = document.getElementById('fType');
  const fRead  = document.getElementById('fRead');
  const list   = document.getElementById('list');
  const empty  = document.getElementById('empty');

  function applyFilters(){
    const term = q.value.trim().toLowerCase();
    const type = fType.value;
    const read = fRead.value; // "", "unread", "read"
    const items = Array.from(list.children);
    let shown = 0;

    items.forEach(it => {
      const itType = it.getAttribute('data-type');
      const itRead = it.getAttribute('data-read'); // "0" or "1"
      const itTerm = (it.getAttribute('data-term') || it.textContent).toLowerCase();

      const okType = !type || itType === type;
      const okRead = !read || (read === 'unread' ? itRead === '0' : itRead === '1');
      const okTerm = !term || itTerm.includes(term);

      const ok = okType && okRead && okTerm;
      it.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });

    empty.style.display = shown ? 'none' : 'block';
  }

  // Mark as read (AJAX)
  list.addEventListener('click', async (e) => {
    const link = e.target.closest('[data-markread]');
    if (!link) return;
    e.preventDefault();

    const id = link.getAttribute('data-id');
    try {
      const resp = await fetch('index.php?route=citizen.notifications&_ajax=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action:'mark_read', id })
      });
      const data = await resp.json();
      if (data.ok) {
        const item = link.closest('.item');
        item.classList.remove('unread');
        item.setAttribute('data-read', '1');
        link.remove();
        applyFilters();
      }
    } catch(err){ console.error(err); }
  });

  q.addEventListener('input', applyFilters);
  fType.addEventListener('change', applyFilters);
  fRead.addEventListener('change', applyFilters);
  applyFilters();
</script>
</body>
</html>
