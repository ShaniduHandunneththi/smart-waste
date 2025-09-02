<?php
// admin/reports_overview.php
include("config\db.php");

if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo "<h3>403 — Admin only</h3>";
  exit;
}

/**
 * We’ll fetch each report with its latest claim (if any) to display:
 * - Report info (id, description, status, created_at, result_path)
 * - Citizen who submitted
 * - Latest claim (collector, claimed/completed times, ai_category_id/confidence)
 * - AI category name via waste_categories
 */

// Pull latest 200 reports (tweak LIMIT as needed)
$sql = "
  SELECT
    r.id               AS report_id,
    r.description      AS report_desc,
    r.status           AS report_status,
    r.photo_path       AS before_photo,
    r.created_at       AS report_created,
    r.result_path      AS result_path,
    r.citizen_id       AS citizen_id,
    cu.full_name       AS citizen_name,
    cu.username        AS citizen_username,

    rc.id              AS claim_id,
    rc.collector_id    AS collector_id,
    rc.status          AS claim_status,
    rc.claimed_at      AS claimed_at,
    rc.completed_at    AS completed_at,
    rc.ai_category_id  AS ai_category_id,
    rc.ai_confidence   AS ai_confidence,

    co.full_name       AS collector_name,
    co.username        AS collector_username,

    wc.name            AS ai_category_name
  FROM reports r
  LEFT JOIN users cu ON cu.id = r.citizen_id
  LEFT JOIN report_claims rc
    ON rc.id = (
      SELECT id FROM report_claims
      WHERE report_id = r.id
      ORDER BY id DESC
      LIMIT 1
    )
  LEFT JOIN users co ON co.id = rc.collector_id
  LEFT JOIN waste_categories wc ON wc.id = rc.ai_category_id
  ORDER BY r.created_at DESC
  LIMIT 200
";

$res = mysqli_query($conn, $sql);
$rows = [];
if ($res) {
  while ($row = mysqli_fetch_assoc($res)) { $rows[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Smart Waste – Admin · Reports Overview</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
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
  body{
    margin:0; background:var(--bg); color:var(--text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;
  }
  .wrap{max-width:1220px; margin:28px auto; padding:0 16px;}

  /* Topbar */
  .top{display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;}
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
  .btn-danger{background:#fff; color:var(--danger); border:1px solid #efb9b9}
  .btn-outline:hover{background:#eef5ef}
  .btn-primary:hover{background:var(--green-dark)}
  .btn-ghost:hover{background:#f2f9ff}

  /* Card + toolbar */
  .card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px;}
  h2{margin:0 0 10px; font-size:20px}
  .toolbar{
    display:grid; grid-template-columns: 1.2fr 1fr 1fr 1fr 1fr 1fr auto; gap:10px; align-items:center; margin-bottom:12px;
  }
  .input, .select{
    padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:#fff; font-size:14px;
  }
  .export{display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;}
  @media (max-width: 1100px){
    .toolbar{grid-template-columns:1fr 1fr 1fr 1fr; }
    .export{justify-content:flex-start;}
  }

  /* Table */
  .table{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border:1px solid var(--border); border-radius:12px;}
  .table th, .table td{padding:12px; text-align:left; font-size:14px; border-bottom:1px solid var(--border); vertical-align:middle;}
  .table thead th{ background:#f9fbfb; font-weight:700; color:#455a64; }
  .table tbody tr:hover{ background:#fcfdfd; }

  .status{
    padding:4px 10px; font-size:12px; font-weight:800; border-radius:999px; color:#fff; display:inline-block;
  }
  .s-pending{ background:var(--pending) }
  .s-claimed{ background:var(--claimed) }
  .s-completed{ background:var(--completed) }

  .pill{display:inline-block; padding:4px 10px; border-radius:999px; font-weight:800; font-size:12px; color:#fff;}
  .p-organic{ background:var(--organic) }
  .p-recyclable{ background:var(--recyclable) }
  .p-hazardous{ background:var(--hazardous) }

  .muted{color:var(--muted); font-size:12px}
  .link{
    text-decoration:none; font-weight:800; font-size:13px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:#fff; color:#0277bd;
  }
  .link:hover{background:#f2f9ff}

  /* Bulk row */
  .bulkbar{
    display:flex; gap:8px; align-items:center; margin:12px 0 0;
  }
  .bulkbar .select{min-width:180px}
  .note{font-size:12px; color:var(--muted)}

  /* Map placeholder */
  .map{
    height:320px; border:1px dashed var(--border); border-radius:12px; background:#fafafa;
    display:flex; align-items:center; justify-content:center; color:#90a4ae; font-weight:700; margin-top:12px;
  }

  /* Responsive table → cards */
  @media (max-width: 760px){
    .table thead{ display:none; }
    .table, .table tbody, .table tr, .table td{ display:block; width:100%; }
    .table tr{ background:#fff; margin-bottom:12px; border:1px solid var(--border); border-radius:12px; padding:10px; }
    .table td{ border-bottom:none; padding:8px 0; }
  }
</style>
</head>
<body>
  <div class="wrap">
    <!-- Topbar -->
    <div class="top">
      <div class="brand"><span class="logo"></span><span>Smart Waste – Admin</span></div>
      <div class="nav">
        <a class="btn btn-outline" href="index.php?route=admin.dashboard">Dashboard</a>
        <a class="btn btn-primary" href="index.php?route=admin.reports_overview">Reports Overview</a>
        <a class="btn btn-ghost" href="index.php?route=admin.manage_users">Manage Users</a>
        <a class="btn btn-ghost" href="index.php?route=admin.analytics">Analytics</a>
      </div>
    </div>

    <div class="card">
      <h2>Reports Overview</h2>
      <!-- Filters (client-side only for now) -->
      <div class="toolbar">
        <input id="q" class="input" type="text" placeholder="Search description / ID / citizen…">
        <select id="fStatus" class="select">
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="claimed">Claimed</option>
          <option value="completed">Completed</option>
        </select>
        <select id="fCat" class="select">
          <option value="">All categories</option>
          <option value="organic">Organic</option>
          <option value="recyclable">Recyclable</option>
          <option value="hazardous">Hazardous</option>
        </select>
        <select id="fCollector" class="select">
          <option value="">All collectors</option>
          <?php
            // Optional: show collectors that appear in current result set
            $seen = [];
            foreach ($rows as $r) {
              if (!empty($r['collector_id']) && !isset($seen[$r['collector_id']])) {
                $seen[$r['collector_id']] = true;
                $label = !empty($r['collector_name']) ? $r['collector_name'] : ('ID '.$r['collector_id']);
                echo '<option value="'.(int)$r['collector_id'].'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</option>';
              }
            }
          ?>
        </select>
        <input id="dFrom" class="input" type="date" />
        <input id="dTo" class="input" type="date" />
        <div class="export">
          <!-- Hook these to your real export routes when available -->
          <a class="btn-ghost" href="#">Export CSV</a>
          <a class="btn-ghost" href="#" target="_blank" rel="noopener">Export PDF</a>
        </div>
      </div>

      <!-- Table -->
      <table class="table" id="tbl">
        <thead>
          <tr>
            <th style="width:90px">Report</th>
            <th>Description</th>
            <th style="width:130px">Status</th>
            <th style="width:160px">Category (AI)</th>
            <th style="width:170px">Submitted</th>
            <th style="width:220px">Citizen</th>
            <th style="width:240px">Collector / Actions</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="muted">No reports found.</td></tr>
          <?php else: ?>
            <?php
              foreach ($rows as $r):
                $rid     = (int)$r['report_id'];
                $cid     = (int)$r['claim_id'];
                $status  = $r['report_status']; // pending | claimed | completed
                $catName = trim($r['ai_category_name'] ?? '');
                $catLower= strtolower($catName);
                $catClass= $catLower === 'hazardous' ? 'p-hazardous' : ($catLower === 'recyclable' ? 'p-recyclable' : ($catLower === 'organic' ? 'p-organic' : ''));
                $conf    = $r['ai_confidence'] !== null ? (float)$r['ai_confidence'] : null;
                $citizen = $r['citizen_name'] ?: $r['citizen_username'] ?: ('User #'.(int)$r['citizen_id']);
                $collector = $r['collector_name'] ?: $r['collector_username'] ?: ($r['collector_id'] ? ('ID '.(int)$r['collector_id']) : '—');
                $term = strtolower($rid.' '.$r['report_desc'].' '.$citizen.' '.$collector.' '.$status.' '.$catName);
                $date = substr($r['report_created'] ?? '', 0, 10);
                $timeIso = date('c', strtotime($r['report_created'] ?? 'now'));
            ?>
              <tr
                data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                data-cat="<?php echo htmlspecialchars($catLower, ENT_QUOTES, 'UTF-8'); ?>"
                data-collector="<?php echo (int)($r['collector_id'] ?? 0); ?>"
                data-term="<?php echo htmlspecialchars($term, ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>"
                data-time="<?php echo htmlspecialchars($timeIso, ENT_QUOTES, 'UTF-8'); ?>"
              >
                <td>#<?php echo $rid; ?></td>
                <td><?php echo htmlspecialchars($r['report_desc'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <?php if ($status==='completed'): ?>
                    <span class="status s-completed">COMPLETED</span>
                  <?php elseif ($status==='claimed'): ?>
                    <span class="status s-claimed">CLAIMED</span>
                  <?php else: ?>
                    <span class="status s-pending">PENDING</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($catClass): ?>
                    <span class="pill <?php echo $catClass; ?>"><?php echo htmlspecialchars(strtoupper($catName), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($conf !== null): ?>
                      <div class="muted">Confidence: <?php echo number_format($conf, 1); ?>%</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($r['report_created'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($citizen, ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <?php echo htmlspecialchars($collector, ENT_QUOTES, 'UTF-8'); ?>
                  <?php if ($rid): ?>
                    <?php if ($cid): ?>
                      <!-- Open detail (collector view) -->
                      <a class="link" href="index.php?route=collector.report_detail&id=<?php echo $rid; ?>&claim_id=<?php echo (int)$cid; ?>">View</a>
                    <?php else: ?>
                      <a class="link" href="index.php?route=collector.report_detail&id=<?php echo $rid; ?>">View</a>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (!empty($r['result_path'])): ?>
                    <a class="link" href="<?php echo htmlspecialchars($r['result_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open Result</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Bulk actions placeholder (non-functional demo) -->
      <div class="bulkbar">
        <span class="note">Tip: filter first, then assign with your admin tool if needed.</span>
      </div>

      <!-- Optional Map / Heatmap placeholder -->
      <div class="map">Map/Heatmap placeholder — embed Leaflet/Google Maps + markers/heat layer</div>
    </div>
  </div>

<script>
  // Simple client-side filters for the table
  const q = document.getElementById('q');
  const fStatus = document.getElementById('fStatus');
  const fCat = document.getElementById('fCat');
  const fCollector = document.getElementById('fCollector');
  const dFrom = document.getElementById('dFrom');
  const dTo = document.getElementById('dTo');
  const tbody = document.getElementById('tbody');

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
    const term = (q.value || '').trim().toLowerCase();
    const st = fStatus.value;
    const cat = fCat.value;
    const col = fCollector.value;
    const rows = Array.from(tbody.querySelectorAll('tr'));

    rows.forEach(r => {
      const rSt  = r.getAttribute('data-status');     // pending|claimed|completed
      const rCat = r.getAttribute('data-cat');        // organic|recyclable|hazardous|"" (lowercased)
      const rCol = r.getAttribute('data-collector');  // numeric id or "0"
      const rTerm= (r.getAttribute('data-term') || r.textContent).toLowerCase();
      const rDate= r.getAttribute('data-date');

      let ok = true;
      if (term && !rTerm.includes(term)) ok = false;
      if (st && rSt !== st) ok = false;
      if (cat && rCat !== cat) ok = false;
      if (col && rCol !== col) ok = false;
      if (!inDateRange(rDate)) ok = false;

      r.style.display = ok ? '' : 'none';
    });
  }

  q.addEventListener('input', applyFilters);
  fStatus.addEventListener('change', applyFilters);
  fCat.addEventListener('change', applyFilters);
  fCollector.addEventListener('change', applyFilters);
  dFrom.addEventListener('change', applyFilters);
  dTo.addEventListener('change', applyFilters);

  applyFilters();
</script>
</body>
</html>
