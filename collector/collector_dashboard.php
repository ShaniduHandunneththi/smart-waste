<?php
// collector/collector_dashboard.php
include("config\db.php");

// ---- Guard: only logged-in collectors ----
if (!isset($_SESSION['user_id'])) { header('Location: /index.php?route=login'); exit; }
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'collector') {
  http_response_code(403);
  echo "<h2 style='font-family:system-ui'>403 – Forbidden</h2><p>Collector access only.</p>";
  exit;
}

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$collectorId   = (int)($_SESSION['user_id'] ?? 0);
$collectorName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Collector');
$initials = '';
foreach (preg_split('/\s+/', trim($collectorName)) as $p){
  if ($p!==''){ $initials .= mb_strtoupper(mb_substr($p,0,1)); }
}
$initials = mb_substr($initials,0,2);

/* ========================= KPIs ========================= */
$kPendingNear   = 0; // unassigned pending
$kClaimedToday  = 0; // claimed today by me
$kCompletedWeek = 0; // completed last 7 days by me

// pending unassigned
$sql = "SELECT COUNT(*) AS n 
        FROM reports 
        WHERE status='pending' AND (collector_id IS NULL OR collector_id=0)";
$res = mysqli_query($conn,$sql);
if ($res && ($row=mysqli_fetch_assoc($res))) $kPendingNear = (int)$row['n'];

// claimed today by me
$sql = "SELECT COUNT(*) AS n 
        FROM reports 
        WHERE status='claimed' AND collector_id=? AND DATE(assigned_at)=CURDATE()";
$stmt = mysqli_prepare($conn,$sql);
if ($stmt){
  mysqli_stmt_bind_param($stmt,'i',$collectorId);
  mysqli_stmt_execute($stmt);
  $r= mysqli_stmt_get_result($stmt);
  if ($r && ($row=mysqli_fetch_assoc($r))) $kClaimedToday = (int)$row['n'];
  mysqli_stmt_close($stmt);
}

// completed last 7 days by me
$sql = "SELECT COUNT(*) AS n 
        FROM reports 
        WHERE status='completed' AND collector_id=? AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
$stmt = mysqli_prepare($conn,$sql);
if ($stmt){
  mysqli_stmt_bind_param($stmt,'i',$collectorId);
  mysqli_stmt_execute($stmt);
  $r= mysqli_stmt_get_result($stmt);
  if ($r && ($row=mysqli_fetch_assoc($r))) $kCompletedWeek = (int)$row['n'];
  mysqli_stmt_close($stmt);
}

/* ========== Recently Claimed by me (with claim_id) ========== 
   We join report_claims to get the ACTIVE claim in my name
   so that the "Complete" button can pass both report_id and claim_id.
*/
$claimed = [];
$sql = "SELECT r.id, r.description, r.assigned_at, r.gps_lat, r.gps_lng,
               rc.id AS claim_id
        FROM reports r
        LEFT JOIN report_claims rc
          ON rc.report_id  = r.id
         AND rc.collector_id = ?
         AND rc.status    = 'claimed'
        WHERE r.status='claimed' AND r.collector_id=?
        ORDER BY r.assigned_at DESC
        LIMIT 10";
$stmt = mysqli_prepare($conn,$sql);
if ($stmt){
  mysqli_stmt_bind_param($stmt,'ii',$collectorId,$collectorId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($res && ($row = mysqli_fetch_assoc($res))) $claimed[] = $row;
  mysqli_stmt_close($stmt);
}

/* ========== Latest Completed by me ========== */
$completed = [];
$sql = "SELECT id, description, category, completed_at
        FROM reports
        WHERE status='completed' AND collector_id=?
        ORDER BY completed_at DESC
        LIMIT 10";
$stmt = mysqli_prepare($conn,$sql);
if ($stmt){
  mysqli_stmt_bind_param($stmt,'i',$collectorId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($res && ($row = mysqli_fetch_assoc($res))) $completed[] = $row;
  mysqli_stmt_close($stmt);
}

/* ========== Pending Preview (unassigned newest) ========== */
$pendingPreview = [];
$sql = "SELECT id, description, created_at
        FROM reports
        WHERE status='pending' AND (collector_id IS NULL OR collector_id=0)
        ORDER BY created_at DESC
        LIMIT 5";
$res = mysqli_query($conn,$sql);
while ($res && ($row = mysqli_fetch_assoc($res))) $pendingPreview[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – Collector Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --pending:#ffb300; --claimed:#0277bd; --completed:#2E7D32;
  }
  *{box-sizing:border-box}
  body{ margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif; }
  .wrap{max-width:1120px; margin:28px auto; padding:0 16px;}

  .top{display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;}
  .brand{display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800;}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
  .top-actions{display:flex; gap:8px; flex-wrap:wrap;}
  .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid transparent; font-weight:700; cursor:pointer; font-size:14px; text-decoration:none; transition:.15s transform,.15s filter;}
  .btn-outline{background:#fff; color:var(--green); border-color:var(--green)}
  .btn-primary{background:var(--green); color:#fff}
  .btn:hover{transform:translateY(-1px); filter:brightness(1.02)}
  .avatar{width:36px;height:36px;border-radius:50%; background:#c8e6c9; display:inline-flex; align-items:center; justify-content:center; font-weight:800; color:#1B5E20;}

  .grid{display:grid; gap:16px; grid-template-columns:repeat(12,1fr);}
  .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px;}
  .kpi{grid-column:span 4; display:flex; gap:12px; align-items:center;}
  .kpi .num{font-size:28px; font-weight:900; color:#37474f; line-height:1}
  .kpi .lbl{font-size:12px; color:var(--muted)}
  .kpi .chip{font-size:11px; padding:4px 8px; border-radius:999px; border:1px solid var(--border); color:#607d8b}
  .kpi .accent-p{background:#fff7e1; border-color:#ffe2a8; color:#8a6b00}
  .kpi .accent-c{background:#e6f3fb; border-color:#bfe0f6; color:#0b5b83}
  .kpi .accent-d{background:#e9f5eb; border-color:#cfe5d3; color:#1b5e20}

  .wide{grid-column:span 8;}
  .narrow{grid-column:span 4;}
  .section-title{margin:0 0 8px; font-size:15px; color:#455a64; font-weight:900; letter-spacing:.2px}

  .actions{display:flex; gap:10px; flex-wrap:wrap;}
  .btn-icon{display:flex; align-items:center; gap:8px; padding:10px 12px; border-radius:12px; border:1px solid var(--border); background:#fff; text-decoration:none; color:#0277bd; font-weight:800; font-size:14px;}
  .btn-icon:hover{background:#f2f9ff}
  .ic{width:18px;height:18px;border-radius:4px; background:#e3f2fd; display:inline-block}

  .list{display:flex; flex-direction:column; gap:10px;}
  .item{border:1px solid var(--border); border-radius:12px; background:#fff; padding:12px; display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center;}
  .title{font-weight:800; color:#37474f; margin-bottom:4px;}
  .muted{color:var(--muted); font-size:12px}
  .status{padding:4px 10px; font-size:12px; font-weight:800; border-radius:999px; color:#fff; display:inline-block;}
  .s-pending{ background:var(--pending) }
  .s-claimed{ background:var(--claimed) }
  .s-completed{ background:var(--completed) }
  .acts{display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;}
  .link{text-decoration:none; font-weight:800; font-size:13px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:#fff; color:#2E7D32;}
  .link:hover{background:#eef5ef}

  .map{height:260px; border:1px dashed var(--border); border-radius:12px; background:#fafafa; display:flex; align-items:center; justify-content:center; color:#90a4ae; font-weight:700;}

  @media (max-width: 960px){ .kpi{grid-column:span 6;} .wide{grid-column:span 12;} .narrow{grid-column:span 12;} }
  @media (max-width: 640px){ .kpi{grid-column:span 12;} .item{grid-template-columns:1fr;} .acts{justify-content:flex-start;} }
</style>
</head>
<body>
  <div class="wrap">
    <!-- Topbar -->
    <div class="top">
      <div class="brand"><span class="logo"></span><span>Smart Waste – Collector</span></div>
      <div class="top-actions">
        <a class="btn btn-outline" href="index.php?route=collector.task_history">Task History</a>
        <a class="btn btn-primary" href="index.php?route=collector.reports_list">Pending Reports</a>
        <span class="avatar" title="<?= h($collectorName) ?>"><?= h($initials) ?></span>
      </div>
    </div>

    <!-- KPI row -->
    <div class="grid">
      <div class="card kpi">
        <div><div class="num"><?= $kPendingNear ?></div><div class="lbl">Pending near you</div></div>
        <span class="chip accent-p">Queue</span>
      </div>
      <div class="card kpi">
        <div><div class="num"><?= $kClaimedToday ?></div><div class="lbl">Claimed today</div></div>
        <span class="chip accent-c">In progress</span>
      </div>
      <div class="card kpi">
        <div><div class="num"><?= $kCompletedWeek ?></div><div class="lbl">Completed this week</div></div>
        <span class="chip accent-d">Cleaned</span>
      </div>

      <!-- Quick actions + Recently Claimed -->
      <div class="card wide">
        <h4 class="section-title">Quick Actions</h4>
        <div class="actions" style="margin-bottom:12px;">
          <a class="btn-icon" href="index.php?route=collector.reports_list"><span class="ic"></span> Find Pending Nearby</a>
          <a class="btn-icon" href="index.php?route=collector.task_history"><span class="ic"></span> View My Task History</a>
        </div>

        <h4 class="section-title">Recently Claimed</h4>
        <div class="list">
          <?php if (!$claimed): ?>
            <div class="item">
              <div>
                <div class="title">No recently claimed reports</div>
                <div class="muted">Claim one from the Pending list.</div>
              </div>
              <div class="acts"><a class="link" href="index.php?route=collector.reports_list">Open Pending List</a></div>
            </div>
          <?php else: foreach ($claimed as $c): ?>
            <div class="item">
              <div>
                <div class="title">Report #<?= (int)$c['id'] ?> – “<?= h(mb_strimwidth($c['description'] ?? '', 0, 80, '…')) ?>”</div>
                <div class="muted">Claimed <?= h($c['assigned_at'] ? date('M j, H:i', strtotime($c['assigned_at'])) : '—') ?></div>
              </div>
              <div class="acts">
                <span class="status s-claimed">CLAIMED</span>
                <a class="link" href="index.php?route=collector.report_detail&id=<?= (int)$c['id'] ?>&claim_id=<?= (int)($c['claim_id'] ?? 0) ?>">Open</a>

                <?php if (!empty($c['claim_id'])): ?>
                  <a class="link"
                     href="index.php?route=collector.complete_form&report_id=<?= (int)$c['id'] ?>&claim_id=<?= (int)$c['claim_id'] ?>">
                     Complete
                  </a>
                <?php else: ?>
                  <span class="muted">No active claim id</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Map (placeholder) -->
      <div class="card narrow">
        <h4 class="section-title">Map – Pending Reports</h4>
        <div class="map">Map placeholder (embed Leaflet/Google Maps later)</div>
        <div class="actions" style="margin-top:12px;">
          <a class="btn-outline btn" href="index.php?route=collector.reports_list">Open List</a>
        </div>
      </div>

      <!-- Completed (latest) -->
      <div class="card wide">
        <h4 class="section-title">Latest Completed</h4>
        <div class="list">
          <?php if (!$completed): ?>
            <div class="item">
              <div><div class="title">No completed reports yet</div><div class="muted">Finish a claimed report to see it here.</div></div>
              <div class="acts"><span class="status s-pending">PENDING</span></div>
            </div>
          <?php else: foreach ($completed as $d): ?>
            <div class="item">
              <div>
                <div class="title">Report #<?= (int)$d['id'] ?> – Completed (<?= h(ucfirst($d['category'] ?? '—')) ?>)</div>
                <div class="muted">Finished <?= h($d['completed_at'] ? date('M j, H:i', strtotime($d['completed_at'])) : '—') ?> • Link shared with citizen & admin</div>
              </div>
              <div class="acts">
                <span class="status s-completed">COMPLETED</span>
                <a class="link" href="index.php?route=collector.task_history">View in History</a>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Pending preview -->
      <div class="card narrow">
        <h4 class="section-title">Pending Preview</h4>
        <div class="list">
          <?php if (!$pendingPreview): ?>
            <div class="item">
              <div><div class="title">No pending reports</div><div class="muted">All caught up. Check again later.</div></div>
              <div class="acts"><span class="status s-completed">OK</span></div>
            </div>
          <?php else: foreach ($pendingPreview as $p): ?>
            <div class="item">
              <div>
                <div class="title">Report #<?= (int)$p['id'] ?> – “<?= h(mb_strimwidth($p['description'] ?? '', 0, 80, '…')) ?>”</div>
                <div class="muted">Submitted <?= h($p['created_at'] ? date('M j, H:i', strtotime($p['created_at'])) : '—') ?></div>
              </div>
              <div class="acts">
                <span class="status s-pending">PENDING</span>
                <a class="link" href="index.php?route=collector.reports_list">Claim</a>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div> <!-- /grid -->
  </div>
</body>
</html>
