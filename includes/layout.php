<?php
// Shared helpers + page shell for every dashboard page.
date_default_timezone_set('Asia/Manila');

function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function is_uuid($v) {
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
}

function fmt_dt($iso, $format = 'M d, Y g:i A') {
    $t = strtotime((string) $iso);
    return $t ? date($format, $t) : '-';
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

/* ---- Page shell ---- */
function nav_for($role) {
    $nav = [
        'counselor' => [
            '/counselor/' => 'Dashboard',
            '/counselor/schedule.php' => 'My Schedule',
            '/counselor/requests.php' => 'Appointment Requests',
            '/counselor/patients.php' => 'Patient List',
            '/counselor/record.php' => 'Patient Records',
            '/counselor/availability.php' => 'Availability',
            '/counselor/profile.php' => 'My Profile',
        ],
        'patient' => [
            '/patient/' => 'Dashboard',
            '/patient/appointment.php' => 'Appointments',
        ],
        'admin' => [
            '/admin/' => 'Dashboard',
        ],
    ];
    return $nav[$role] ?? [];
}

function page_start($role, $title, $current, $heading = null) {
    csrf_token();   // must run before any output (it may set a cookie)
    $labels = ['counselor' => 'COUNSELOR PORTAL', 'patient' => 'PATIENT PORTAL', 'admin' => 'ADMIN PORTAL'];
    $heading = $heading ?? $title;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> - SerenityCare</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="dashboard-body">
    <div class="app-layout">
        <aside class="sidebar">
            <div class="brand"><h2>SerenityCare</h2><span class="badge-role"><?php echo e($labels[$role] ?? ''); ?></span></div>
            <nav class="nav-links">
                <?php foreach (nav_for($role) as $href => $label): ?>
                    <a href="<?php echo e($href); ?>"<?php echo $href === $current ? ' class="active"' : ''; ?>><?php echo e($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar"><h1><?php echo e($heading); ?></h1></header>
<?php
}

function page_end() {
    echo "        </main>\n    </div>\n</body>\n</html>\n";
}