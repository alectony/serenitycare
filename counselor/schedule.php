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
                <div class="card-head"><div><h2>All Appointments</h2><p class="card-sub">Every session, newest first</p></div></div>
                <?php if ($failed): ?><div class="alert error">Could not load appointments. Please try again.</div><?php endif; ?>
                <table class="data-table">
                    <thead><tr><th>Date / Time</th><th>Patient / Type</th><th>Topic</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$appointments): ?>
                        <tr><td colspan="4">No appointments found.</td></tr>
                    <?php else: foreach ($appointments as $a): $ts = strtotime($a['scheduled_at']); ?>
                        <tr>
                            <td>
                                <div class="when">
                                    <span class="date-chip"><small><?php echo e(strtoupper(date('M', $ts))); ?></small><strong><?php echo e(date('d', $ts)); ?></strong></span>
                                    <span><?php echo e(date('g:i A', $ts)); ?></span>
                                </div>
                            </td>
                            <td><strong><?php echo e($a['patient']['full_name'] ?? 'Unknown'); ?></strong><br><small><?php echo e(ucfirst($a['patient']['patient_type'] ?? '-')); ?></small></td>
                            <td><?php echo e($a['reason'] ?? '-'); ?></td>
                            <td><?php echo status_badge($a['status']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end(); ?>