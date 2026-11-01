<?php
// Shared helpers + page shell for every dashboard page (counselor, patient, admin).
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
        'check'     => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    ];
    $p = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
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
            '/counselor/requests.php'     => ['Appointment Requests', 'bell'],
            '/counselor/patients.php'     => ['Patient List', 'users'],
            '/counselor/record.php'       => ['Patient Records', 'file'],
            '/counselor/availability.php' => ['Availability', 'clock'],
            '/counselor/profile.php'      => ['My Profile', 'user'],
        ],
        'patient' => [
            '/patient/'                   => ['Dashboard', 'dashboard'],
            '/patient/appointment.php'    => ['Appointments', 'calendar'],
        ],
        'admin' => [
            '/admin/'                     => ['Dashboard', 'dashboard'],
        ],
    ];
    return $nav[$role] ?? [];
}

function page_start($role, $title, $current, $heading = null, $subtitle = null) {
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
    <link rel="stylesheet" href="/css/style.css">
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
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn"><?php echo icon('logout'); ?><span>Log out</span></a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar">
                <h1><?php echo e($heading); ?></h1>
                <?php if ($subtitle !== ''): ?><p class="subtitle"><?php echo e($subtitle); ?></p><?php endif; ?>
            </header>
<?php
}

function page_end() {
    echo "        </main>\n    </div>\n</body>\n</html>\n";
}
?>