<?php
// admin/admin_dashboard.php  (No KPIs, with Refresh)
include("config\\db.php");

if (!isset($_SESSION['user_id'])) { header('Location: /index.php?route=login'); exit; }
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403); echo "<h2 style='font-family:system-ui'>403 – Forbidden</h2><p>Admin only.</p>"; exit;
}
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- Data ---------- */

/* Category distribution (last 7 days) */
$cntOrg=0; $cntRec=0; $cntHaz=0; $total7=0;
$sql = "SELECT 
          SUM(category='organic') AS org,
          SUM(category='recyclable') AS rec,
          SUM(category='hazardous') AS haz
        FROM reports
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
$res = mysqli_query($conn, $sql);
if ($res && ($row = mysqli_fetch_assoc($res))) {
  $cntOrg = (int)$row['org']; $cntRec = (int)$row['rec']; $cntHaz = (int)$row['haz'];
  $total7 = max(0, $cntOrg+$cntRec+$cntHaz);
}
$pcOrg = $total7 ? round($cntOrg*100/$total7) : 0;
$pcRec = $total7 ? round($cntRec*100/$total7) : 0;
$pcHaz = $total7 ? round($cntHaz*100/$total7) : 0;

/* Recent reports (latest 20) */
$recent = [];
$sql = "SELECT r.id, r.description, r.status, r.category, r.created_at,
               r.collector_id, u.full_name AS collector_name
        FROM reports r
        LEFT JOIN users u ON u.id = r.collector_id
        ORDER BY r.created_at DESC
        LIMIT 20";
$res = mysqli_query($conn, $sql);
while ($res && ($row = mysqli_fetch_assoc($res))) { $recent[] = $row; }

/* Top collectors (7 days) */
$topCollectors = [];
$sql = "SELECT r.collector_id,
               COALESCE(u.full_name, CONCAT('WC-', r.collector_id)) AS collector_name,
               COUNT(*) AS completed_count,
               SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND, r.assigned_at, r.completed_at))) AS avg_close_time
        FROM reports r
        LEFT JOIN users u ON u.id = r.collector_id
        WHERE r.status='completed'
          AND r.completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND r.collector_id IS NOT NULL
        GROUP BY r.collector_id, collector_name
        ORDER BY completed_count DESC
        LIMIT 5";
$res = mysqli_query($conn, $sql);
while ($res && ($row = mysqli_fetch_assoc($res))) { $topCollectors[] = $row; }

/* Citizen activity (7 days) */
$citizens = [];
$sql = "SELECT r.citizen_id,
               COALESCE(u.username, CONCAT('U-', r.citizen_id)) AS citizen_name,
               COUNT(*) AS reports_count,
               ROUND(100 * SUM(r.status='completed') / COUNT(*), 0) AS completed_pct
        FROM reports r
        LEFT JOIN users u ON u.id = r.citizen_id
        WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY r.citizen_id, citizen_name
        ORDER BY reports_count DESC
        LIMIT 5";
$res = mysqli_query($conn, $sql);
while ($res && ($row = mysqli_fetch_assoc($res))) { $citizens[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --pending:#ffb300; --claimed:#0277bd; --completed:#2E7D32;
    --organic:#6abd4b; --recyclable:#1e88e5; --hazardous:#d81b60;
    --danger:#c62828;
  }
  *{box-sizing:border-box}
  body{ margin:0; background:var(--bg); color:var(--text);
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif; }
  .wrap{max-width:1200px; margin:28px auto; padding:0 16px;}

  .top{display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;}
  .brand{display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800;}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
  .nav{display:flex; gap:8px; flex-wrap:wrap; align-items:center;}
  .btn{display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid transparent;
       font-weight:700; cursor:pointer; font-size:14px; text-decoration:none;}
  .btn-outline{background:#fff; color:var(--green); border-color:var(--green)}
  .btn-primary{background:var(--green); color:#fff}
  .btn-ghost{background:#fff; color:#0277bd; border:1px solid var(--border)}
  .btn-danger{background:var(--danger); color:#fff; border-color:var(--danger)}
  .btn-outline:hover{background:#eef5ef}
  .btn-primary:hover{background:var(--green-dark)}
  .btn-ghost:hover{background:#f2f9ff}

  .toolbar{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
  .refresh{margin-left:auto}

  .grid{display:grid; gap:16px; grid-template-columns:repeat(12,1fr);}
  .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px;}
  .section-title{margin:0 0 8px; font-size:15px; color:#455a64; font-weight:900; letter-spacing:.2px}
  .col-8{grid-column:span 8;} .col-4{grid-column:span 4;} .col-6{grid-column:span 6;}

  .input, .select{padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; font-size:14px;}
  .export{display:flex; gap:8px; flex-wrap:wrap;}

  .table{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border:1px solid var(--border); border-radius:12px;}
  .table th, .table td{padding:12px; text-align:left; font-size:14px; border-bottom:1px solid var(--border); vertical-align:middle;}
  .table thead th{ background:#f9fbfb; font-weight:700; color:#455a64; }
  .table tbody tr:hover{ background:#fcfdfd; }

  .status{padding:4px 10px; font-size:12px; font-weight:800; border-radius:999px; color:#fff; display:inline-block;}
  .s-pending{ background:var(--pending) } .s-claimed{ background:var(--claimed) } .s-completed{ background:var(--completed) }

  .pill{display:inline-block; padding:4px 10px; border-radius:999px; font-weight:800; font-size:12px; color:#fff;}
  .p-organic{ background:var(--organic) } .p-recyclable{ background:var(--recyclable) } .p-hazardous{ background:var(--hazardous) }
  .muted{color:var(--muted); font-size:12px}

  .bars{display:flex; gap:8px; align-items:center; margin-top:8px;}
  .bar{height:10px; border-radius:999px; background:#eee; flex:1; overflow:hidden; position:relative; border:1px solid #e4e7eb}
  .bar > span{display:block; height:100%}
  .b-org{background:var(--organic)} .b-rec{background:var(--recyclable)} .b-haz{background:var(--hazardous)}
  .legend{display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--muted); margin-top:8px;}

  .dot{width:10px;height:10px;border-radius:999px;display:inline-block}
  .d-org{background:var(--organic)} .d-rec{background:var(--recyclable)} .d-haz{background:var(--hazardous)}

  .map{height:300px; border:1px dashed var(--border); border-radius:12px; background:#fafafa;
       display:flex; align-items:center; justify-content:center; color:#90a4ae; font-weight:700;}

  @media (max-width: 1000px){
    .col-8, .col-4, .col-6{grid-column:span 12;}
  }
</style>
</head>
<body>
  <div class="wrap">
    <!-- Topbar -->
    <div class="top">
      <div class="brand"><span class="logo"></span><span>Smart Waste – Admin</span></div>
      <div class="nav">
        <a class="btn btn-outline" href="index.php?route=admin.manage_users">Manage Users</a>
        <a class="btn btn-outline" href="index.php?route=admin.reports_overview">Reports Overview</a>
        <a class="btn btn-primary" href="index.php?route=admin.analytics">Analytics</a>
        <!-- NEW: Logout -->
        <a class="btn btn-danger" href="index.php?route=logout" title="Log out">Logout</a>
      </div>
    </div>

    <!-- Toolbar with Refresh -->
    <div class="card" style="margin-bottom:16px;">
      <div class="toolbar">
        <div class="muted">Admin dashboard — live data</div>
        <div class="refresh">
          <a class="btn btn-primary" href="index.php?route=admin.dashboard&ts=<?= time() ?>">Refresh</a>
        </div>
      </div>
    </div>

    <div class="grid">
      <!-- Recent Reports -->
      <div class="card col-8">
        <h4 class="section-title">Recent Reports</h4>
        <div class="toolbar">
          <input class="input" id="q" type="text" placeholder="Search description / ID…">
          <select class="select" id="fStatus">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="claimed">Claimed</option>
            <option value="completed">Completed</option>
          </select>
          <select class="select" id="fCat">
            <option value="">All categories</option>
            <option value="organic">Organic</option>
            <option value="recyclable">Recyclable</option>
            <option value="hazardous">Hazardous</option>
          </select>
          <input class="input" id="dFrom" type="date">
          <input class="input" id="dTo" type="date">
          <div class="export">
            <a class="btn-ghost btn" href="index.php?route=admin.export&type=csv">Export CSV</a>
            <a class="btn-ghost btn" href="index.php?route=admin.export&type=pdf" target="_blank" rel="noopener">Export PDF</a>
          </div>
        </div>

        <table class="table" id="tbl">
          <thead>
            <tr>
              <th style="width:90px">Report</th>
              <th>Description</th>
              <th style="width:120px">Status</th>
              <th style="width:160px">Category</th>
              <th style="width:180px">Submitted</th>
              <th style="width:200px">Assigned / Actions</th>
            </tr>
          </thead>
          <tbody id="tbody">
            <?php if (!$recent): ?>
              <tr><td colspan="6" class="muted">No reports yet.</td></tr>
            <?php else: ?>
              <?php foreach ($recent as $r): 
                $st   = $r['status'] ?? '';
                $cat  = $r['category'] ?? '';
                $sCls = $st==='completed' ? 's-completed' : ($st==='claimed' ? 's-claimed' : 's-pending');
                $cHtml= $cat ? '<span class="pill p-'.h($cat).'">'.strtoupper(h($cat)).'</span>' : '<span class="muted">—</span>';
                $collector = $r['collector_name'] ? h($r['collector_name']) : '—';
              ?>
              <tr data-status="<?= h($st) ?>" data-cat="<?= h($cat) ?>"
                  data-term="<?= h($r['id'].' '.$r['description']) ?>"
                  data-date="<?= h(date('Y-m-d', strtotime($r['created_at']))) ?>">
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= h($r['description']) ?></td>
                <td><span class="status <?= $sCls ?>"><?= strtoupper(h($st)) ?></span></td>
                <td><?= $cHtml ?></td>
                <td><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                <td><?= $collector ?> • <a class="btn-ghost" href="index.php?route=admin.reports_overview&open=<?= (int)$r['id'] ?>">View</a></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        <div id="empty" class="muted" style="padding:10px; display:none;">No reports match your filters.</div>
      </div>

      <!-- Category distribution + Map -->
      <div class="card col-4">
        <h4 class="section-title">Category Distribution (week)</h4>
        <div class="bars" aria-label="category bars">
          <div class="bar" title="Organic <?= $pcOrg ?>%"><span class="b-org" style="width:<?= $pcOrg ?>%"></span></div>
          <div class="bar" title="Recyclable <?= $pcRec ?>%"><span class="b-rec" style="width:<?= $pcRec ?>%"></span></div>
          <div class="bar" title="Hazardous <?= $pcHaz ?>%"><span class="b-haz" style="width:<?= $pcHaz ?>%"></span></div>
        </div>
        <div class="legend">
          <span><span class="dot d-org"></span> Organic <?= $pcOrg ?>%</span>
          <span><span class="dot d-rec"></span> Recyclable <?= $pcRec ?>%</span>
          <span><span class="dot d-haz"></span> Hazardous <?= $pcHaz ?>%</span>
        </div>

       
      </div>

      <!-- Top Collectors -->
      <div class="card col-6">
        <h4 class="section-title">Top Collectors (7 days)</h4>
        <table class="table">
          <thead>
            <tr><th>Collector</th><th style="width:120px">Completed</th><th style="width:160px">Avg. Close Time</th></tr>
          </thead>
          <tbody>
          <?php if (!$topCollectors): ?>
            <tr><td colspan="3" class="muted">No completed tasks yet.</td></tr>
          <?php else: foreach ($topCollectors as $tc): ?>
            <tr>
              <td><?= h($tc['collector_name']) ?></td>
              <td><?= (int)$tc['completed_count'] ?></td>
              <td><?= h($tc['avg_close_time'] ?? '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Citizen Activity -->
      <div class="card col-6">
        <h4 class="section-title">Citizen Activity (7 days)</h4>
        <table class="table">
          <thead>
            <tr><th>Citizen</th><th style="width:120px">Reports</th><th style="width:160px">Completed %</th></tr>
          </thead>
          <tbody>
          <?php if (!$citizens): ?>
            <tr><td colspan="3" class="muted">No reports yet.</td></tr>
          <?php else: foreach ($citizens as $cz): ?>
            <tr>
              <td><?= h($cz['citizen_name']) ?></td>
              <td><?= (int)$cz['reports_count'] ?></td>
              <td><?= (int)$cz['completed_pct'] ?>%</td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<script>
  // Client-side filters for Recent Reports
  const q = document.getElementById('q');
  const fStatus = document.getElementById('fStatus');
  const fCat = document.getElementById('fCat');
  const dFrom = document.getElementById('dFrom');
  const dTo = document.getElementById('dTo');
  const tbody = document.getElementById('tbody');
  const empty = document.getElementById('empty');

  function inDateRange(dateStr){
    if (!dateStr) return true;
    const d = new Date(dateStr + 'T00:00:00');
    if (dFrom.value){
      const from = new Date(dFrom.value + 'T00:00:00');
      if (d < from) return false;
    }
    if (dTo.value){
      const to = new Date(dTo.value + 'T23:59:59');
      if (d > to) return false;
    }
    return true;
  }
  function applyFilters(){
    const term = q.value.trim().toLowerCase();
    const st = fStatus.value;
    const cat = fCat.value;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    let shown = 0;

    rows.forEach(r => {
      const rSt  = r.getAttribute('data-status') || '';
      const rCat = r.getAttribute('data-cat') || '';
      const rTerm= (r.getAttribute('data-term') || r.textContent).toLowerCase();
      const rDate= r.getAttribute('data-date') || '';

      let ok = true;
      if (term && !rTerm.includes(term)) ok = false;
      if (st && rSt !== st) ok = false;
      if (cat && rCat !== cat) ok = false;
      if (!inDateRange(rDate)) ok = false;

      r.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });

    empty.style.display = shown ? 'none' : 'block';
  }

  q.addEventListener('input', applyFilters);
  fStatus.addEventListener('change', applyFilters);
  fCat.addEventListener('change', applyFilters);
  dFrom.addEventListener('change', applyFilters);
  dTo.addEventListener('change', applyFilters);
  applyFilters();
</script>
</body>
</html>
