<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');
$uid = $me['user']['id'];
$token = $me['token'];

$ap = sb_request('GET', 'appointments',
    'select=id,scheduled_at,status,reason,counselor:profiles!counselor_id(full_name)'
    . '&patient_id=eq.' . urlencode($uid) . '&order=scheduled_at.asc', null, $token);
$appointments = ($ap['status'] === 200 && is_array($ap['data'])) ? $ap['data'] : [];

$now = time();
$upcoming = array_values(array_filter($appointments, fn($a) =>
    strtotime($a['scheduled_at']) >= $now && in_array($a['status'], ['pending', 'confirmed'], true)));

// RLS only returns diagnoses the counselor chose to share; the filter is belt and braces.
$dx = sb_request('GET', 'diagnoses',
    'select=diagnosis,recommendations,created_at,counselor:profiles!counselor_id(full_name)'
    . '&patient_id=eq.' . urlencode($uid) . '&shared_with_patient=eq.true&order=created_at.desc&limit=5', null, $token);
$results = ($dx['status'] === 200 && is_array($dx['data'])) ? $dx['data'] : [];

page_start('patient', 'Dashboard', '/patient/', 'Welcome, ' . ($me['profile']['full_name'] ?? 'Patient'));
?>
            <div style="display:flex; gap:16px; margin-bottom:16px;">
                <div class="card" style="flex:1;">
                    <small>Next appointment</small>
                    <h2><?php echo $upcoming ? e(fmt_dt($upcoming[0]['scheduled_at'])) : 'None scheduled'; ?></h2>
                    <?php if ($upcoming): ?><small>with <?php echo e($upcoming[0]['counselor']['full_name'] ?? 'your counselor'); ?></small><?php endif; ?>
                </div>
                <div class="card" style="flex:1;"><small>Upcoming sessions</small><h2><?php echo count($upcoming); ?></h2></div>
                <div class="card" style="flex:1;"><small>Shared results</small><h2><?php echo count($results); ?></h2></div>
            </div>

            <div class="card" style="margin-bottom:16px;">
                <h2>Need someone to talk to?</h2>
                <p>Book a private session with an available counselor.</p>
                <a href="/patient/appointment.php" class="btn">Book appointment</a>
            </div>

            <div class="card">
                <h2>Latest Results</h2>
                <?php if (!$results): ?>
                    <p>No results have been shared with you yet.</p>
                <?php else: foreach ($results as $r): ?>
                    <div style="padding:12px 0; border-bottom:1px solid #eee;">
                        <strong><?php echo e($r['diagnosis']); ?></strong>
                        <small>(<?php echo e(fmt_dt($r['created_at'], 'M d, Y')); ?> · <?php echo e($r['counselor']['full_name'] ?? 'Counselor'); ?>)</small><br>
                        <small>Recommendation: <?php echo e($r['recommendations']); ?></small>
                    </div>
                <?php endforeach; endif; ?>
                <p><small>Only you and your counselor can see these records.</small></p>
            </div>
<?php page_end(); ?>