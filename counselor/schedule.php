<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');

$res = sb_request('GET', 'appointments',
    'select=*,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($me['user']['id']) . '&order=scheduled_at.desc', null, $me['token']);

$failed = ($res['status'] !== 200 || !is_array($res['data']));
$appointments = $failed ? [] : $res['data'];

page_start('counselor', 'My Schedule', '/counselor/schedule.php', 'My Schedule & Calendar');
?>
            <div class="card">
                <h2>All Appointments</h2>
                <?php if ($failed): ?><div class="alert error">Could not load appointments. Please try again.</div><?php endif; ?>
                <table class="data-table">
                    <thead><tr><th>Date &amp; Time</th><th>Patient Name</th><th>Type</th><th>Topic</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$appointments): ?>
                        <tr><td colspan="5">No appointments found.</td></tr>
                    <?php else: foreach ($appointments as $a): ?>
                        <tr>
                            <td><?php echo e(fmt_dt($a['scheduled_at'])); ?></td>
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