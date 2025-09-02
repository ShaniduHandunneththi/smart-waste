<?php
// index.php — minimal front controller with safe redirects for subfolders
session_start();
require_once __DIR__ . '/config/db.php';

/* --------- Base URL helper (works in subfolders) --------- */
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($BASE === '' || $BASE === '.') $BASE = '';

/**
 * Build a URL to a route. Example:
 *   url_to('citizen.my_reports', ['page'=>2])
 * -> /your/subdir/index.php?route=citizen.my_reports&page=2
 */
function url_to(string $route, array $q = []) : string {
    global $BASE;
    $qs = http_build_query(array_merge(['route' => $route], $q));
    return ($BASE ? $BASE : '') . '/index.php' . ($qs ? ('?' . $qs) : '');
}

/* Tiny escaper for any inline output in this file */
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Serve a page relative to project root, with optional role guard.
 * $viewPath: path WITHOUT extension (e.g., 'citizen/citizen_dashboard').
 * Tries .php first, then .html.
 */
function serve(string $viewPath, $roleRequired = null){
    // ---- Role check (if required) ----
    if ($roleRequired !== null) {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . url_to('login'));
            exit;
        }
        $userRole = strtolower(trim($_SESSION['role'] ?? ''));
        $allowed  = is_array($roleRequired) ? $roleRequired : [$roleRequired];
        // normalize allowed roles too
        $allowed  = array_map(fn($r) => strtolower(trim($r)), $allowed);

        if (!in_array($userRole, $allowed, true)) {
            http_response_code(403);
            echo "<h2 style='font-family:system-ui'>403 — Forbidden</h2>
                  <p>You don't have access to this page.</p>";
            exit;
        }
    }

    $php  = __DIR__ . '/' . $viewPath . '.php';
    $html = __DIR__ . '/' . $viewPath . '.html';
    if (is_file($php))  { include $php;  exit; }
    if (is_file($html)) { include $html; exit; }

    http_response_code(404);
    echo "<h2 style='font-family:system-ui'>404 — Not Found</h2>
          <p>Missing view: ".e($viewPath)."</p>";
    exit;
}


/* --------- Routing --------- */
$route = $_GET['route'] ?? 'login';

switch ($route) {

    /* ---------- AUTH ---------- */
    case 'login':
        // login.php should:
        //  - verify credentials
        //  - set $_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name']
        //  - redirect to url_to('<role>.dashboard')
        include __DIR__ . '/login.php';
        break;

    case 'register':
        include __DIR__ . '/register.php';
        break;

    case 'logout':
        session_destroy();
        header('Location: ' . url_to('login'));
        break;

    /* ---------- CITIZEN ---------- */
    case 'citizen.dashboard':
        serve('citizen/citizen_dashboard', 'citizen'); break;

    case 'citizen.submit_report':
        serve('citizen/submit_report', 'citizen'); break;

    case 'citizen.my_reports':
        serve('citizen/my_reports', 'citizen'); break;

    case 'citizen.notifications':
        serve('citizen/notifications', 'citizen'); break;

    // NEW: view a single report (linked from My Reports & Notifications)
    case 'citizen.report_view':
        serve('citizen/report_view', 'citizen'); break;

    // NEW: mark a notification as read and return to notifications
    case 'citizen.mark_notification':
        if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'citizen') {
            http_response_code(403);
            echo "403 — Citizen only";
            exit;
        }
        $nid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($nid > 0) {
            $uid = (int)$_SESSION['user_id'];
            $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, 'ii', $nid, $uid);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
        }
        header('Location: ' . url_to('citizen.notifications'));
        break;

    /* ---------- COLLECTOR ---------- */
    case 'collector.dashboard':
        serve('collector/collector_dashboard', 'collector'); break;

    case 'collector.reports_list':
        serve('collector/reports_list', 'collector'); break;

    // Allow both collector and admin to open this page
    case 'collector.report_detail':
        serve('collector/report_detail', ['collector','admin']); break;

    case 'collector.complete_form':
        serve('collector/complete_form', 'collector'); break;

    case 'collector.task_history':
        serve('collector/task_history', 'collector'); break;

    /* ---------- ADMIN ---------- */
    case 'admin.dashboard':
        serve('admin/admin_dashboard', 'admin'); break;

    case 'admin.manage_users':
        serve('admin/manage_users', 'admin'); break;

    case 'admin.reports_overview':
        serve('admin/reports_overview', 'admin'); break;

    case 'admin.analytics':
        serve('admin/analytics', 'admin'); break;

    /* ---------- DEFAULT ---------- */
    default:
        http_response_code(404);
        echo "<h2 style='font-family:system-ui'>404 — Route not found</h2>
              <p>route: <code>".e($route)."</code></p>";
        break;
}
