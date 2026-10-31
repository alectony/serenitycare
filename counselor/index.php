<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];

$res = sb_request('GET', 'appointments',
    'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=scheduled_at.asc', null, $me['token']);

$failed = ($res['status'] !== 200 || !is_array($res['data']));
$all = $failed ? [] : $res['data'];

$today = date('Y-m-d');
$todays = array_values(array_filter($all, fn($a) =>
    date('Y-m-d', strtotime($a['scheduled_at'])) === $today
    && in_array($a['status'], ['pending', 'confirmed', 'completed'], true)));
$pendingCount = count(array_filter($all, fn($a) => $a['status'] === 'pending'));
$patientCount = count(array_unique(array_column($all, 'patient_id')));

page_start('counselor', 'Dashboard', '/counselor/', 'Welcome, ' . ($me['profile']['full_name'] ?? 'Counselor'));
?>
            <?php if ($failed): ?><div class="alert error">Could not load your data. Please refresh.</div><?php endif; ?>

            <div style="display:flex; gap:16px; margin-bottom:16px;">
                <div class="card" style="flex:1;"><small>Today's sessions</small><h2><?php echo count($todays); ?></h2></div>
                <div class="card" style="flex:1;"><small>Pending requests</small><h2><?php echo $pendingCount; ?></h2></div>
                <div class="card" style="flex:1;"><small>Patients</small><h2><?php echo $patientCount; ?></h2></div>
            </div>

            <div class="card">
                <h2>Today's Schedule</h2>
                <table class="data-table">
                    <thead><tr><th>Time</th><th>Patient</th><th>Type</th><th>Topic</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$todays): ?>
                        <tr><td colspan="5">No sessions today.</td></tr>
                    <?php else: foreach ($todays as $a): ?>
                        <tr>
                            <td><?php echo e(fmt_dt($a['scheduled_at'], 'g:i A')); ?></td>
                            <td><?php echo e($a['patient']['full_name'] ?? 'Unknown'); ?></td>
                            <td><?php echo e(ucfirst($a['patient']['patient_type'] ?? '-')); ?></td>
                            <td><?php echo e($a['reason'] ?? '-'); ?></td>
                            <td><span class="status <?php echo e($a['status']); ?>"><?php echo e(ucfirst($a['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end(); ?>