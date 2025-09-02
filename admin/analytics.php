<?php
// admin/analytics.php
include("config\db.php");

if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo "<h3>403 — Admin only</h3>";
  exit;
}

// mini escape
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Time window (days)
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1) $days = 7;
if ($days > 60) $days = 60;

// 1) CATEGORY DISTRIBUTION (latest claim per report in the window)
$catData = []; // [name => count]
$sqlCat = "
  SELECT wc.name AS cat_name, COUNT(*) AS cc
  FROM reports r
  LEFT JOIN report_claims rc
    ON rc.id = (
      SELECT id FROM report_claims
      WHERE report_id = r.id
      ORDER BY id DESC
      LIMIT 1
    )
  LEFT JOIN waste_categories wc ON wc.id = rc.ai_category_id
  WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
    AND rc.ai_category_id IS NOT NULL
  GROUP BY wc.name
";
$res = mysqli_query($conn, $sqlCat);
$totalCat = 0;
if ($res){
  while($row = mysqli_fetch_assoc($res)){
    $name = $row['cat_name'] ?: 'Unknown';
    $catData[$name] = (int)$row['cc'];
    $totalCat += (int)$row['cc'];
  }
}

// 2) DAILY SUBMISSIONS TREND (last $days days)
$trend = []; // [['d'=>date,'c'=>count], ...]
$sqlTrend = "
  SELECT DATE(r.created_at) AS d, COUNT(*) AS c
  FROM reports r
  WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  GROUP BY DATE(r.created_at)
  ORDER BY DATE(r.created_at)
";
$res = mysqli_query($conn, $sqlTrend);
if ($res){
  while($row = mysqli_fetch_assoc($res)){
    $trend[] = ['d'=>$row['d'], 'c'=>(int)$row['c']];
  }
}
$maxTrend = 0;
foreach ($trend as $t){ if ($t['c'] > $maxTrend) $maxTrend = $t['c']; }

// 3) TOP COLLECTORS (completed within window) w/ avg close time
$topCollectors = [];
$sqlTop = "
  SELECT
    u.id AS collector_id,
    COALESCE(u.full_name, u.username) AS collector_name,
    COUNT(*) AS completed_cnt,
    AVG(TIMESTAMPDIFF(MINUTE, rc.claimed_at, rc.completed_at)) AS avg_minutes
  FROM report_claims rc
  INNER JOIN users u ON u.id = rc.collector_id
  WHERE rc.status = 'completed'
    AND rc.completed_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  GROUP BY u.id
  ORDER BY completed_cnt DESC
  LIMIT 10
";
$res = mysqli_query($conn, $sqlTop);
if ($res){
  while($row = mysqli_fetch_assoc($res)){
    $topCollectors[] = [
      'id' => (int)$row['collector_id'],
      'name' => $row['collector_name'] ?: ('ID '.$row['collector_id']),
      'cnt' => (int)$row['completed_cnt'],
      'avg' => $row['avg_minutes'] !== null ? (float)$row['avg_minutes'] : null
    ];
  }
}
function fmt_minutes($m){
  if ($m === null) return '—';
  $m = (int)round($m);
  $h = intdiv($m, 60);
  $mm= $m % 60;
  if ($h>0) return "{$h}h {$mm}m";
  return "{$mm}m";
}

// 4) CITIZEN ACTIVITY (created within window) + completion rate
$citizens = [];
$sqlCit = "
  SELECT
    u.id AS cid,
    COALESCE(u.full_name,u.username) AS cname,
    COUNT(r.id) AS tot,
    SUM(r.status='completed') AS comp
  FROM reports r
  INNER JOIN users u ON u.id = r.citizen_id
  WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  GROUP BY u.id
  ORDER BY tot DESC
  LIMIT 10
";
$res = mysqli_query($conn, $sqlCit);
if ($res){
  while($row = mysqli_fetch_assoc($res)){
    $tot = (int)$row['tot']; $comp = (int)$row['comp'];
    $pct = $tot>0 ? round(100*$comp/$tot,1) : 0;
    $citizens[] = [
      'id'   => (int)$row['cid'],
      'name' => $row['cname'] ?: ('User #'.$row['cid']),
      'tot'  => $tot,
      'pct'  => $pct
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Smart Waste – Admin · Analytics</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --green:#2E7D32; --green-dark:#1B5E20;
    --text:#263238; --muted:#6b7b83;
    --bg:#f5f7f8; --card:#ffffff; --border:#e6eaee;
    --organic:#6abd4b; --recyclable:#1e88e5; --hazardous:#d81b60;
  }
  *{box-sizing:border-box}
  body{ margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif; }
  .wrap{max-width:1200px; margin:28px auto; padding:0 16px;}

  /* Topbar */
  .top{display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;}
  .brand{display:flex; align-items:center; gap:10px; color:var(--green); font-weight:800;}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green-dark));}
  .nav{display:flex; gap:8px; flex-wrap:wrap;}
  .btn{
    display:inline-block; padding:10px 14px; border-radius:10px; border:1px solid transparent;
    font-weight:700; cursor:pointer; font-size:14px; text-decoration:none;
  }
  .btn-outline{background:#fff; color:var(--green); border-color:var(--green)}
  .btn-primary{background:var(--green); color:#fff}
  .btn-ghost{background:#fff; color:#0277bd; border:1px solid var(--border)}
  .btn-outline:hover{background:#eef5ef}
  .btn-primary:hover{background:var(--green-dark)}
  .btn-ghost:hover{background:#f2f9ff}

  .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px;}
  .section-title{margin:0 0 8px; font-size:15px; color:#455a64; font-weight:900; letter-spacing:.2px}
  .grid{display:grid; gap:16px; grid-template-columns:repeat(12,1fr);}

  .col-8{grid-column:span 8;}
  .col-4{grid-column:span 4;}
  .col-6{grid-column:span 6;}

  /* Category bars */
  .bars{display:flex; gap:8px; align-items:center;}
  .bar{height:10px; border-radius:999px; background:#eee; flex:1; overflow:hidden; position:relative; border:1px solid #e4e7eb}
  .bar > span{display:block; height:100%}
  .b-org{background:var(--organic)}
  .b-rec{background:var(--recyclable)}
  .b-haz{background:var(--hazardous)}
  .legend{display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--muted); margin-top:8px;}
  .dot{width:10px;height:10px;border-radius:999px;display:inline-block}
  .d-org{background:var(--organic)} .d-rec{background:var(--recyclable)} .d-haz{background:var(--hazardous)}

  /* Trend bars */
  .trend{display:flex; gap:6px; align-items:flex-end; height:120px; padding:8px; border:1px dashed var(--border); border-radius:12px; background:#fff;}
  .tb{width:20px; background:#e3f2fd; border:1px solid #bde0fb; border-radius:6px; display:flex; align-items:flex-end; justify-content:center; overflow:hidden}
  .tb span{display:block; width:100%; background:#0277bd;}

  /* Tables */
  .table{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border:1px solid var(--border); border-radius:12px;}
  .table th, .table td{padding:12px; text-align:left; font-size:14px; border-bottom:1px solid var(--border); vertical-align:middle;}
  .table thead th{ background:#f9fbfb; font-weight:700; color:#455a64; }

  @media (max-width:1000px){
    .col-8, .col-4, .col-6{grid-column:span 12;}
  }

  .toolbar{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;}
  .select{padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; font-size:14px;}
</style>
</head>
<body>
  <div class="wrap">
    <!-- Topbar -->
    <div class="top">
      <div class="brand"><span class="logo"></span><span>Smart Waste – Admin</span></div>
      <div class="nav">
        <a class="btn btn-outline" href="index.php?route=admin.dashboard">Dashboard</a>
        <a class="btn btn-ghost" href="index.php?route=admin.reports_overview">Reports Overview</a>
        <a class="btn btn-primary" href="index.php?route=admin.analytics">Analytics</a>
      </div>
    </div>

    <!-- Toolbar: time window + refresh -->
    <div class="card" style="margin-bottom:12px;">
      <div class="toolbar">
        <div style="font-weight:800;color:#37474f;">Analytics Window</div>
        <select class="select" onchange="location.href='index.php?route=admin.analytics&days='+this.value">
          <option value="7"  <?= $days===7?'selected':'' ?>>Last 7 days</option>
          <option value="30" <?= $days===30?'selected':'' ?>>Last 30 days</option>
          <option value="60" <?= $days===60?'selected':'' ?>>Last 60 days</option>
        </select>

        <button class="btn btn-outline" style="margin-left:auto"
                onclick="window.location.href='index.php?route=admin.analytics&days=<?= (int)$days ?>'">
          Refresh
        </button>
      </div>
    </div>

    <div class="grid">
      <!-- Category Distribution + Trend -->
      <div class="card col-8">
        <h4 class="section-title">Category Distribution (last <?= (int)$days ?> days)</h4>
        <?php
          // normalize category keys (adapt if your table uses lowercase strings)
          $org = $catData['Organic']     ?? 0;
          $rec = $catData['Recyclable']  ?? 0;
          $haz = $catData['Hazardous']   ?? 0;
          $tot = max(1, $totalCat);
          $orgW = round(100*$org/$tot,1);
          $recW = round(100*$rec/$tot,1);
          $hazW = round(100*$haz/$tot,1);
        ?>
        <div class="bars" style="margin-bottom:8px;">
          <div class="bar"><span class="b-org" style="width:<?= $orgW ?>%"></span></div>
          <div class="bar"><span class="b-rec" style="width:<?= $recW ?>%"></span></div>
          <div class="bar"><span class="b-haz" style="width:<?= $hazW ?>%"></span></div>
        </div>
        <div class="legend">
          <span><span class="dot d-org"></span> Organic <?= (int)$org ?> (<?= $orgW ?>%)</span>
          <span><span class="dot d-rec"></span> Recyclable <?= (int)$rec ?> (<?= $recW ?>%)</span>
          <span><span class="dot d-haz"></span> Hazardous <?= (int)$haz ?> (<?= $hazW ?>%)</span>
        </div>

        <h4 class="section-title" style="margin-top:16px;">Daily Submissions</h4>
        <div class="trend" title="Daily submissions in the selected window">
          <?php if (!$trend): ?>
            <div style="color:#90a4ae; font-weight:700;">No data in this window</div>
          <?php else: ?>
            <?php foreach ($trend as $t): 
              $h = ($maxTrend>0) ? max(4, round(100*$t['c']/$maxTrend)) : 0; // bar height %
            ?>
              <div class="tb" title="<?= h($t['d']) ?>: <?= (int)$t['c'] ?>">
                <span style="height:<?= $h ?>%"></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="legend" style="margin-top:6px;">
          <span>Max/day: <strong><?= (int)$maxTrend ?></strong></span>
        </div>
      </div>

      <!-- Top Collectors -->
      <div class="card col-4">
        <h4 class="section-title">Top Collectors (last <?= (int)$days ?> days)</h4>
        <table class="table">
          <thead>
            <tr>
              <th>Collector</th>
              <th style="width:110px">Completed</th>
              <th style="width:140px">Avg. Close</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$topCollectors): ?>
            <tr><td colspan="3" style="color:#6b7b83;">No completed claims in this window.</td></tr>
          <?php else: ?>
            <?php foreach ($topCollectors as $tc): ?>
              <tr>
                <td><?= h($tc['name']) ?></td>
                <td><?= (int)$tc['cnt'] ?></td>
                <td><?= h(fmt_minutes($tc['avg'])) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Citizen Activity -->
      <div class="card col-6">
        <h4 class="section-title">Citizen Activity (last <?= (int)$days ?> days)</h4>
        <table class="table">
          <thead>
            <tr>
              <th>Citizen</th>
              <th style="width:120px">Reports</th>
              <th style="width:160px">Completed %</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$citizens): ?>
            <tr><td colspan="3" style="color:#6b7b83;">No citizen activity in this window.</td></tr>
          <?php else: ?>
            <?php foreach ($citizens as $c): ?>
              <tr>
                <td><?= h($c['name']) ?></td>
                <td><?= (int)$c['tot'] ?></td>
                <td><?= number_format($c['pct'],1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Placeholder: Hotspot map -->
      <div class="card col-6">
        <h4 class="section-title">Hotspot Map (placeholder)</h4>
        <div style="height:260px; border:1px dashed var(--border); border-radius:12px; background:#fafafa; display:flex; align-items:center; justify-content:center; color:#90a4ae; font-weight:700;">
          Embed Leaflet/Google Maps + heat layer (using report lat/lng)
        </div>
        <div style="color:#6b7b83; font-size:12px; margin-top:8px;">
          Tip: Filter to last 7–30 days to see recent dumping clusters.
        </div>
      </div>
    </div>
  </div>
</body>
</html>
