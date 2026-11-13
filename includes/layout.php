<?php
// Shared helpers + page shell for every dashboard page (counselor, patient, admin).
require_once __DIR__ . '/audit.php';

date_default_timezone_set('Asia/Manila');

function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function is_uuid($v) {
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
}

function fmt_dt($iso, $format = 'M d, Y g:i A') {
    $t = strtotime((string) $iso);
    return $t ? date($format, $t) : '-';
}

function greeting() {
    $h = (int) date('G');
    return $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
}

/* ---- CSRF (double-submit cookie; works without PHP sessions) ---- */
function csrf_token() {
    if (empty($_COOKIE['csrf'])) {
        $t = bin2hex(random_bytes(16));
        setcookie('csrf', $t, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
        $_COOKIE['csrf'] = $t;
    }
    return $_COOKIE['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_valid() {
    $c = $_COOKIE['csrf'] ?? '';
    $p = $_POST['csrf'] ?? '';
    return $c !== '' && is_string($p) && hash_equals($c, $p);
}

/* ---- Data helpers ---- */
// Patients who have at least one appointment with this counselor.
function get_counselor_patients($uid, $token) {
    $r = sb_request('GET', 'appointments',
        'select=patient_id,patient:profiles!patient_id(id,full_name,email,patient_type,grade_or_dept)'
        . '&counselor_id=eq.' . urlencode($uid), null, $token);
    $out = [];
    if ($r['status'] === 200 && is_array($r['data'])) {
        foreach ($r['data'] as $row) {
            if (!empty($row['patient']['id'])) {
                $out[$row['patient']['id']] = $row['patient'];
            }
        }
    }
    usort($out, fn($a, $b) => strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? ''));
    return $out;
}

// Notifications of one user (newest first). Returns [] on error.
function get_notifications($uid, $token, $limit = 50, $onlyUnread = false) {
    $q = 'select=id,title,message,type,is_read,created_at&user_id=eq.' . urlencode($uid)
       . ($onlyUnread ? '&is_read=eq.false' : '')
       . '&order=created_at.desc&limit=' . (int) $limit;
    $r = sb_request('GET', 'notifications', $q, null, $token);
    return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : [];
}

/* ---- Patient dashboard header: search bar + profile button (top right) ---- */
function patient_header($uid, $token) {
    $r   = sb_request('GET', 'profiles', 'select=full_name,details:patient_details(avatar)&id=eq.' . urlencode($uid), null, $token);
    $row = ($r['status'] === 200 && !empty($r['data'][0])) ? $r['data'][0] : [];
    $det = $row['details'] ?? [];
    if (isset($det[0])) { $det = $det[0]; }          // handles both object and array results
    $name = $row['full_name'] ?? 'Patient';
    $ini  = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)), 0, 2) as $w) { $ini .= mb_strtoupper(mb_substr($w, 0, 1)); }
    $avatar = !empty($det['avatar'])
        ? '<img class="profile-avatar" src="' . e($det['avatar']) . '" alt="">'
        : '<span class="profile-avatar">' . e($ini) . '</span>';

    return '<form method="get" action="/patient/" class="dashboard-search-form">'
        . '<div class="dashboard-search-wrapper">'
        . '<svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>'
        . '<input type="text" name="q" class="dashboard-search-input" placeholder="Search counselors by name..." value="' . e($_GET['q'] ?? '') . '">'
        . '</div></form>'
        . '<a href="/patient/profile.php" class="profile-interactable">' . $avatar
        . '<span class="profile-info"><strong>' . e($name) . '</strong><small>Patient</small></span></a>';
}

// Popup with the counselors that match ?q=... (shown on top of the dashboard)
function patient_search_modal($token) {
    $q = pg_term($_GET['q'] ?? '');
    if ($q === '') { return ''; }
    $r = sb_request('GET', 'profiles',
        'select=id,full_name&role=eq.counselor&full_name=ilike.' . rawurlencode('*' . $q . '*') . '&order=full_name.asc&limit=20', null, $token);
    $found = ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : [];

    $h = '<div class="modal-overlay"><div class="modal-card"><div class="modal-header">'
       . '<h2>Counselors matching &ldquo;' . e($q) . '&rdquo;</h2>'
       . '<a href="/patient/" class="modal-close-btn" aria-label="Close">&times;</a></div><div class="modal-body">';
    if (!$found) {
        $h .= '<p class="empty">No counselors found.</p>';
    }
    foreach ($found as $c) {
        $h .= '<div class="patient-popup-item"><div class="patient-popup-info"><strong>' . e($c['full_name']) . '</strong><small>Counselor</small></div>'
            . '<a class="btn-sm" href="/patient/book.php?counselor_id=' . e($c['id']) . '">Book</a></div>';
    }
    return $h . '</div></div></div>';
}

/* ---- UI components (same markup on every dashboard = consistent look) ---- */
function icon($name) {
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'user'      => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'settings'  => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'chart'     => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'list'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'key'       => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        'check'     => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'sun'       => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
        'moon'      => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'sparkles'  => '<path d="m12 3 1.912 5.813a2 2 0 0 0 1.275 1.275L21 12l-5.813 1.912a2 2 0 0 0-1.275 1.275L12 21l-1.912-5.813a2 2 0 0 0-1.275-1.275L3 12l5.813-1.912a2 2 0 0 0 1.275-1.275L12 3Z"/>',
    ];
    $p = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function theme_toggle_button() {
    return '<button type="button" class="theme-toggle-btn" data-theme-toggle title="Toggle Dark/Light Mode" aria-label="Toggle Dark/Light Mode">'
        . '<svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
        . '<svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
        . '</button>';
}

function skeleton_demo_markup() {
    return '
    <div class="skeleton-shown-on-preview" aria-hidden="true">
        <div class="stats">
            <div class="skeleton-card"><div class="skeleton skeleton-title" style="width:40%;"></div><div class="skeleton skeleton-stat"></div><div class="skeleton skeleton-text short"></div></div>
            <div class="skeleton-card"><div class="skeleton skeleton-title" style="width:45%;"></div><div class="skeleton skeleton-stat"></div><div class="skeleton skeleton-text short"></div></div>
            <div class="skeleton-card"><div class="skeleton skeleton-title" style="width:38%;"></div><div class="skeleton skeleton-stat"></div><div class="skeleton skeleton-text short"></div></div>
        </div>
        <div class="card" style="margin-top:22px;">
            <div class="skeleton skeleton-title" style="width:28%;margin-bottom:18px;"></div>
            <div class="skeleton-row"><div class="skeleton skeleton-avatar"></div><div style="flex:1"><div class="skeleton skeleton-text medium"></div><div class="skeleton skeleton-text short"></div></div><div class="skeleton skeleton-badge"></div></div>
            <div class="skeleton-row"><div class="skeleton skeleton-avatar"></div><div style="flex:1"><div class="skeleton skeleton-text medium"></div><div class="skeleton skeleton-text short"></div></div><div class="skeleton skeleton-badge"></div></div>
            <div class="skeleton-row"><div class="skeleton skeleton-avatar"></div><div style="flex:1"><div class="skeleton skeleton-text medium"></div><div class="skeleton skeleton-text short"></div></div><div class="skeleton skeleton-badge"></div></div>
            <div class="skeleton-row"><div class="skeleton skeleton-avatar"></div><div style="flex:1"><div class="skeleton skeleton-text medium"></div><div class="skeleton skeleton-text short"></div></div><div class="skeleton skeleton-badge"></div></div>
        </div>
    </div>';
}

function stat_card($label, $value, $note = '', $iconName = 'dashboard') {
    return '<div class="stat-card"><div class="stat-top"><span class="stat-label">' . e($label) . '</span>'
        . '<span class="stat-icon">' . icon($iconName) . '</span></div>'
        . '<div class="stat-value">' . e($value) . '</div>'
        . ($note !== '' ? '<div class="stat-note">' . e($note) . '</div>' : '') . '</div>';
}

function status_badge($status) {
    $s = strtolower((string) $status);
    return '<span class="status ' . e($s) . '">' . e(ucfirst($s)) . '</span>';
}

/* ---- Page shell ---- */
function nav_for($role) {
    $nav = [
        'counselor' => [
            '/counselor/'                 => ['Dashboard', 'dashboard'],
            '/counselor/schedule.php'     => ['My Schedule', 'calendar'],
            '/counselor/appointments.php' => ['All Appointments', 'list'],
            '/counselor/requests.php'     => ['Appointment Requests', 'bell'],
            '/counselor/patients.php'     => ['Patient List', 'users'],
            '/counselor/record.php'       => ['Patient Records', 'file'],
            '/counselor/availability.php' => ['Availability', 'clock'],
            '/counselor/reports.php'      => ['Reports & Export', 'chart'],
            '/counselor/profile.php'      => ['My Profile', 'user'],
            '/counselor/settings.php'     => ['Settings', 'settings'],
        ],
        'patient' => [
            '/patient/'                   => ['Dashboard', 'dashboard'],
            '/patient/appointment.php'    => ['Appointments', 'calendar'],
            '/patient/profile.php'        => ['My Profile', 'user'],
            '/patient/settings.php'       => ['Settings', 'settings'],
            '/patient/results.php'        => ['Diagnosis & Results', 'file'],
            '/patient/notification.php'   => ['Notifications', 'bell'],
            '/patient/session.php'      => ['Session Summaries', 'list'],
        ],
        'admin' => [
            '/admin/'                     => ['Dashboard', 'dashboard'],
            '/admin/users.php'            => ['User Management', 'users'],
            '/admin/roles.php'            => ['Role Assignment', 'key'],
            '/admin/counselors.php'       => ['Counselor Verification', 'shield'],
            '/admin/appointments.php'     => ['All Appointments', 'calendar'],
            '/admin/reports.php'          => ['Reports & Export', 'chart'],
            '/admin/audit.php'            => ['Audit Logs', 'list'],
            '/admin/settings.php'         => ['System Settings', 'settings'],
        ],
    ];
    return $nav[$role] ?? [];
}
function page_start($role, $title, $current, $heading = null, $subtitle = null, $rightContent = null) {
    csrf_token();   // must run before any output (it may set a cookie)
    $labels = ['counselor' => 'COUNSELOR PORTAL', 'patient' => 'PATIENT PORTAL', 'admin' => 'ADMIN PORTAL'];
    $subs = [
        'counselor' => 'Counselor care management and student sessions portal.',
        'patient'   => 'Your appointments, results and care in one place.',
        'admin'     => 'Manage users, appointments and activity across SerenityCare.',
    ];
    $heading = $heading ?? $title;
    $subtitle = $subtitle ?? ($subs[$role] ?? '');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> - SerenityCare</title>
    <script>
    (function(){
        var t = localStorage.getItem('serenity_theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="stylesheet" href="/css/style.css">
    <script src="/js/serenity.js" defer></script>
</head>
<body class="dashboard-body role-<?php echo e($role); ?>">
    <div class="app-layout">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand-mark">S</span>
                <div><h2>SerenityCare</h2><span class="badge-role"><?php echo e($labels[$role] ?? ''); ?></span></div>
            </div>
            <nav class="nav-links">
                <?php foreach (nav_for($role) as $href => $item): ?>
                    <a href="<?php echo e($href); ?>"<?php echo $href === $current ? ' class="active"' : ''; ?>><?php echo icon($item[1]); ?><span><?php echo e($item[0]); ?></span></a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer">
                <a href="/logout.php" class="logout-btn"><?php echo icon('logout'); ?><span>Log out</span></a>
            </div>
        </aside>
        <main class="main-content">
            <header class="top-bar top-bar-flex">
                <div class="top-bar-left">
                    <h1><?php echo e($heading); ?></h1>
                    <?php if ($subtitle !== ''): ?><p class="subtitle"><?php echo e($subtitle); ?></p><?php endif; ?>
                </div>
                <div class="top-bar-right">
                    <?php if ($rightContent !== null): ?>
                        <?php echo $rightContent; ?>
                    <?php endif; ?>
                    <?php echo theme_toggle_button(); ?>
                </div>
            </header>
            <?php echo skeleton_demo_markup(); ?>
            <div class="skeleton-hidden-on-preview">
<?php
}

function page_end() {
    echo "            </div>\n        </main>\n    </div>\n</body>\n</html>\n";
}

/* ---- Filters, paging, charts (plain PHP/HTML/SVG - no JavaScript) ---- */

/** Make a user-typed search term safe to place inside a PostgREST ilike filter. */
function pg_term($s) { return trim(preg_replace('/[^\p{L}\p{N} @._+\-]/u', '', (string) $s)); }

function valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false; }

/** Current URL query string with some values replaced; empty values are dropped. */
function qs(array $override = []) {
    $q = array_merge($_GET, $override);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return http_build_query($q);
}

function pager($page, $hasNext) {
    if ($page <= 1 && !$hasNext) { return ''; }
    $h = '<div class="pager">';
    $h .= $page > 1 ? '<a class="btn-sm" href="?' . e(qs(['page' => $page - 1])) . '">&larr; Previous</a>' : '<span></span>';
    $h .= '<small>Page ' . (int) $page . '</small>';
    $h .= $hasNext ? '<a class="btn-sm" href="?' . e(qs(['page' => $page + 1])) . '">Next &rarr;</a>' : '<span></span>';
    return $h . '</div>';
}

/** [['key' => '2026-06', 'label' => 'Jun'], ...] for the last $n months, oldest first. */
function month_buckets($n = 6) {
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime('first day of -' . $i . ' month', strtotime(date('Y-m-01')));
        $out[] = ['key' => date('Y-m', $t), 'label' => date('M', $t)];
    }
    return $out;
}

/** Count rows per month bucket using a timestamp field. Returns values in bucket order. */
function count_by_month(array $rows, $field, array $buckets) {
    $counts = array_fill_keys(array_column($buckets, 'key'), 0);
    foreach ($rows as $r) {
        $k = date('Y-m', strtotime((string) ($r[$field] ?? '')));
        if (isset($counts[$k])) { $counts[$k]++; }
    }
    return array_values($counts);
}

/** Simple column chart as inline SVG (styled by CSS classes). */
function svg_columns(array $labels, array $values, $title = 'Chart') {
    $w = 520; $h = 210; $padL = 30; $padR = 8; $padT = 18; $padB = 28;
    $n = max(1, count($values));
    $max = max(2, max($values ?: [0]));
    if ($max % 2) { $max++; }   // even top value so the middle gridline is a whole number
    $plotH = $h - $padT - $padB;
    $slot = ($w - $padL - $padR) / $n;
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '">';
    foreach ([0, 0.5, 1] as $g) {
        $y = round($padT + $plotH * (1 - $g), 1);
        $svg .= '<line class="chart-grid" x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . $y . '" y2="' . $y . '"/>';
        $svg .= '<text class="chart-axis" x="' . ($padL - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' . round($max * $g) . '</text>';
    }
    foreach ($values as $i => $v) {
        $bw = $slot * 0.55;
        $x = round($padL + $slot * $i + ($slot - $bw) / 2, 1);
        $bh = round($plotH * ($v / $max), 1);
        $y = round($padT + $plotH - $bh, 1);
        $cx = round($x + $bw / 2, 1);
        $svg .= '<rect class="chart-bar" x="' . $x . '" y="' . $y . '" width="' . round($bw, 1) . '" height="' . $bh . '" rx="5"/>';
        $svg .= '<text class="chart-value" x="' . $cx . '" y="' . ($y - 5) . '" text-anchor="middle">' . (int) $v . '</text>';
        $svg .= '<text class="chart-label" x="' . $cx . '" y="' . ($h - 8) . '" text-anchor="middle">' . e($labels[$i] ?? '') . '</text>';
    }
    return $svg . '</svg>';
}

/** One labelled progress bar row (native <progress>, styled in CSS). */
function bar_row($label, $value, $total) {
    return '<div class="bar-row"><span>' . e($label) . '</span>'
        . '<progress max="' . max(1, (int) $total) . '" value="' . (int) $value . '"></progress>'
        . '<strong>' . (int) $value . '</strong></div>';
}
?>