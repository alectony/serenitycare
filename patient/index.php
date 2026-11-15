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
$rightContent = patient_header($uid, $token);
page_start('patient', 'Dashboard', '/patient/', greeting() . ', ' . $name, null, $rightContent);
?>
            <?php echo patient_search_modal($token); ?>
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
                            <thead><tr><th>Date / Time</th><th>Counselor</th><th>Details</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php if (!$upcoming): ?>
                                <tr><td colspan="5">No upcoming appointments.</td></tr>
                            <?php else: foreach (array_slice($upcoming, 0, 5) as $a): 
                                $ts = strtotime($a['scheduled_at']);
                                $rawR = $a['reason'] ?? '-';
                                $isOnline = false;
                                $meetUrl = '';
                                $displayR = $rawR;
                                if (preg_match('/\[Online Video Call - Google Meet:\s*(https:\/\/meet\.google\.com\/[a-z0-9-]+)\]\s*(.*)/is', $rawR, $m)) {
                                    $isOnline = true;
                                    $meetUrl = trim($m[1]);
                                    $displayR = trim($m[2]);
                                } elseif (stripos($rawR, 'google meet') !== false || stripos($rawR, 'online') !== false) {
                                    $isOnline = true;
                                    if (preg_match('/(https:\/\/meet\.google\.com\/[a-z0-9-]+)/i', $rawR, $m)) {
                                        $meetUrl = $m[1];
                                    }
                                } elseif (preg_match('/\[In-Person Consultation[^\]]*\]\s*(.*)/is', $rawR, $m)) {
                                    $displayR = trim($m[1]);
                                }
                            ?>
                                <tr>
                                    <td><div class="when"><span class="date-chip"><small><?php echo e(strtoupper(date('M', $ts))); ?></small><strong><?php echo e(date('d', $ts)); ?></strong></span><span><?php echo e(date('g:i A', $ts)); ?></span></div></td>
                                    <td><strong><?php echo e($a['counselor']['full_name'] ?? 'Counselor'); ?></strong></td>
                                    <td>
                                        <div style="font-size:11.5px;margin-bottom:2px;">
                                            <?php if ($isOnline): ?>
                                                <span class="status pending" style="padding:1px 6px;font-size:10.5px;"><?php echo icon('video'); ?> Google Meet</span>
                                            <?php else: ?>
                                                <span class="status active" style="padding:1px 6px;font-size:10.5px;"><?php echo icon('users'); ?> In-Person</span>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-size:12.5px;color:var(--ink-secondary);"><?php echo e($displayR); ?></span>
                                    </td>
                                    <td><?php echo status_badge($a['status']); ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:4px;">
                                            <?php if ($a['status'] === 'confirmed' && $isOnline && !empty($meetUrl)): ?>
                                                <a href="<?php echo e($meetUrl); ?>" target="_blank" rel="noopener" class="btn-sm btn-success-sm" title="Join Video Session"><?php echo icon('video'); ?> Meet</a>
                                            <?php endif; ?>
                                            <a href="/patient/print-appointment.php?id=<?php echo e($a['id']); ?>" target="_blank" class="btn-sm" title="Print Slip"><?php echo icon('printer'); ?></a>
                                        </div>
                                    </td>
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
