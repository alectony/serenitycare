<?php
// Lives at: counselor/schedule.php  (project root, NOT inside api/)
require_once __DIR__ . '/../includes/auth.php';

date_default_timezone_set('Asia/Manila');

$me = require_role('counselor');          // redirects away if not a counselor
$token = $me['token'];
$counselorId = $me['user']['id'];

// Supabase REST (RLS applies because we pass the user's token).
// "patient:profiles!patient_id(...)" pulls the patient's name from the profiles table.
$res = sb_request(
    'GET',
    'appointments',
    'select=*,patient:profiles!patient_id(full_name,patient_type)'
        . '&counselor_id=eq.' . urlencode($counselorId)
        . '&order=scheduled_at.desc',
    null,
    $token
);

$loadFailed = ($res['status'] !== 200 || !is_array($res['data']));
$appointments = $loadFailed ? [] : $res['data'];

$nav = [
    '/counselor/'                    => 'Dashboard',
    '/counselor/schedule.php'        => 'My Schedule',
    '/counselor/requests.php'        => 'Appointment Requests',
    '/counselor/patients.php'        => 'Patient List',
    '/counselor/record.php'          => 'Patient Records',
    '/counselor/availability.php'    => 'Availability',
    '/counselor/profile.php'         => 'My Profile',
];
$current = '/counselor/schedule.php';

function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - SerenityCare</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="dashboard-body">
    <div class="app-layout">
        <aside class="sidebar">
            <div class="brand"><h2>SerenityCare</h2><span class="badge-role">COUNSELOR PORTAL</span></div>
            <nav class="nav-links">
                <?php foreach ($nav as $href => $label): ?>
                    <a href="<?php echo e($href); ?>"<?php echo $href === $current ? ' class="active"' : ''; ?>><?php echo e($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>

        <main class="main-content">
            <header class="top-bar">
                <h1>My Schedule &amp; Calendar</h1>
            </header>

            <div class="card">
                <h2>All Appointments</h2>

                <?php if ($loadFailed): ?>
                    <div class="alert error">Could not load appointments. Please try again.</div>
                <?php endif; ?>

                <table class="data-table">
                    <thead>
                        <tr><th>Date &amp; Time</th><th>Patient Name</th><th>Type</th><th>Topic</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr><td colspan="5">No appointments found.</td></tr>
                    <?php else: foreach ($appointments as $a): ?>
                        <tr>
                            <td><?php echo e(date('M d, Y g:i A', strtotime($a['scheduled_at']))); ?></td>
                            <td><?php echo e($a['patient']['full_name'] ?? 'Unknown'); ?></td>
                            <td><?php echo e(ucfirst($a['patient']['patient_type'] ?? '-')); ?></td>
                            <td><?php echo e($a['reason'] ?? '-'); ?></td>
                            <td><span class="status <?php echo e(strtolower($a['status'] ?? '')); ?>"><?php echo e(ucfirst($a['status'] ?? '')); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
