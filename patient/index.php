<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');
$uid = $me['user']['id'];
$token = $me['token'];

$ap = sb_request('GET', 'appointments',
    'select=id,scheduled_at,status,reason,counselor:profiles!counselor_id(full_name)'
    . '&patient_id=eq.' . urlencode($uid) . '&order=scheduled_at.asc', null, $token);
$failed = ($ap['status'] !== 200 || !is_array($ap['data']));
$appointments = $failed ? [] : $ap['data'];

$now = time();
$upcoming = array_values(array_filter($appointments, fn($a) =>
    strtotime($a['scheduled_at']) >= $now && in_array($a['status'], ['pending', 'confirmed'], true)));

// RLS only returns diagnoses the counselor chose to share; the filter is belt and braces.
$dx = sb_request('GET', 'diagnoses',
    'select=diagnosis,recommendations,created_at,counselor:profiles!counselor_id(full_name)'
    . '&patient_id=eq.' . urlencode($uid) . '&shared_with_patient=eq.true&order=created_at.desc&limit=5', null, $token);
$results = ($dx['status'] === 200 && is_array($dx['data'])) ? $dx['data'] : [];

$name = $me['profile']['full_name'] ?? 'there';
page_start('patient', 'Dashboard', '/patient/', greeting() . ', ' . $name);
?>
            <?php if ($failed): ?><div class="alert error">Could not load your appointments. Please refresh.</div><?php endif; ?>

            <div class="stats">
                <?php echo stat_card('Next appointment', $upcoming ? fmt_dt($upcoming[0]['scheduled_at'], 'M d, g:i A') : 'None scheduled',
                    $upcoming ? 'with ' . ($upcoming[0]['counselor']['full_name'] ?? 'your counselor') : 'Book a session below', 'calendar'); ?>
                <?php echo stat_card('Upcoming sessions', count($upcoming), 'Pending or confirmed', 'clock'); ?>
                <?php echo stat_card('Shared results', count($results), 'Visible only to you', 'file'); ?>
            </div>

            <div class="content-grid">
                <div class="stack">
                    <div class="card">
                        <div class="card-head"><div><h2>Upcoming Appointments</h2><p class="card-sub">Your next sessions with counselors</p></div><a class="link" href="/patient/appointment.php">View all</a></div>
                        <table class="data-table">
                            <thead><tr><th>Date / Time</th><th>Counselor</th><th>Reason</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (!$upcoming): ?>
                                <tr><td colspan="4">No upcoming appointments.</td></tr>
                            <?php else: foreach (array_slice($upcoming, 0, 5) as $a): $ts = strtotime($a['scheduled_at']); ?>
                                <tr>
                                    <td><div class="when"><span class="date-chip"><small><?php echo e(strtoupper(date('M', $ts))); ?></small><strong><?php echo e(date('d', $ts)); ?></strong></span><span><?php echo e(date('g:i A', $ts)); ?></span></div></td>
                                    <td><strong><?php echo e($a['counselor']['full_name'] ?? 'Counselor'); ?></strong></td>
                                    <td><?php echo e($a['reason'] ?? '-'); ?></td>
                                    <td><?php echo status_badge($a['status']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cta">
                        <div><h3>Need someone to talk to?</h3><p>Book a private session with an available counselor.</p></div>
                        <a href="/patient/appointment.php" class="btn btn-light">Book appointment</a>
                    </div>
                </div>

                <div class="stack">
                    <div class="card">
                        <div class="card-head"><h2>Latest Results</h2></div>
                        <?php if (!$results): ?>
                            <p class="card-sub">No results have been shared with you yet.</p>
                        <?php else: foreach ($results as $r): ?>
                            <div class="result-item">
                                <strong><?php echo e($r['diagnosis']); ?></strong>
                                <small><?php echo e($r['counselor']['full_name'] ?? 'Counselor'); ?> · <?php echo e(fmt_dt($r['created_at'], 'M d, Y')); ?></small>
                                <?php if (!empty($r['recommendations'])): ?><small>Recommendation: <?php echo e($r['recommendations']); ?></small><?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                        <div class="note-box"><strong>Your privacy</strong><small>Only you and your counselor can see these records.</small></div>
                    </div>
                </div>
            </div>
<?php page_end(); ?>