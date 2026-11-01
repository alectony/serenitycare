<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

$res = sb_request('GET', 'appointments',
    'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=scheduled_at.desc', null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$all = $failed ? [] : $res['data'];

$dx = sb_request('GET', 'diagnoses',
    'select=diagnosis,shared_with_patient,created_at,patient:profiles!patient_id(full_name)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=created_at.desc&limit=3', null, $token);
$records = ($dx['status'] === 200 && is_array($dx['data'])) ? $dx['data'] : [];

// Filter tabs + details panel are driven by the URL (?status=pending&view=<id>), no JavaScript.
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'confirmed', 'pending'], true)) { $status = 'all'; }

$detail = null;
$viewId = $_GET['view'] ?? '';
if (is_uuid($viewId)) {
    foreach ($all as $a) { if ($a['id'] === $viewId) { $detail = $a; break; } }
}

$rows = $status === 'all' ? $all : array_values(array_filter($all, fn($a) => $a['status'] === $status));
$rows = array_slice($rows, 0, 25);

$today = date('Y-m-d');
$todayCount = count(array_filter($all, fn($a) =>
    date('Y-m-d', strtotime($a['scheduled_at'])) === $today && in_array($a['status'], ['pending', 'confirmed', 'completed'], true)));
$pendingCount = count(array_filter($all, fn($a) => $a['status'] === 'pending'));
$patientCount = count(array_unique(array_column($all, 'patient_id')));

page_start('counselor', 'Dashboard', '/counselor/', greeting() . ', ' . ($me['profile']['full_name'] ?? 'Counselor'));
?>
            <?php if ($failed): ?><div class="alert error">Could not load your data. Please refresh.</div><?php endif; ?>

            <div class="stats">
                <?php echo stat_card("Today's sessions", $todayCount, 'Scheduled for today', 'calendar'); ?>
                <?php echo stat_card('Pending requests', $pendingCount, 'Awaiting your review', 'clock'); ?>
                <?php echo stat_card('Active patients', $patientCount, 'Students & staff', 'users'); ?>
            </div>

            <div class="content-grid">
                <div class="stack">
                    <?php if ($detail): ?>
                        <div class="card details-panel" id="details">
                            <div class="card-head"><h2>Appointment Details</h2><a class="link" href="/counselor/?status=<?php echo e($status); ?>">Close</a></div>
                            <div class="detail-row"><span>Patient</span><strong><?php echo e($detail['patient']['full_name'] ?? 'Unknown'); ?></strong></div>
                            <div class="detail-row"><span>Type</span><strong><?php echo e(ucfirst($detail['patient']['patient_type'] ?? '-')); ?></strong></div>
                            <div class="detail-row"><span>Scheduled</span><strong><?php echo e(fmt_dt($detail['scheduled_at'], 'M d, Y · g:i A')); ?></strong></div>
                            <div class="detail-row"><span>Session topic</span><strong><?php echo e($detail['reason'] ?? '-'); ?></strong></div>
                            <div class="detail-row"><span>Status</span><strong><?php echo status_badge($detail['status']); ?></strong></div>

                            <div class="details-actions">
                                <?php if ($detail['status'] === 'pending'): ?>
                                    <form method="POST" action="/counselor/requests.php" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="appointment_id" value="<?php echo e($detail['id']); ?>">
                                        <button type="submit" name="action" value="approve" class="btn btn-success-sm">Approve</button>
                                        <button type="submit" name="action" value="decline" class="btn btn-outline-danger-sm">Decline</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn-sm" href="/counselor/record.php?patient_id=<?php echo e($detail['patient_id']); ?>">Open patient record</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-head">
                            <div><h2>All Appointments &amp; Schedule</h2><p class="card-sub">Manage counseling sessions and review statuses</p></div>
                            <div class="tabs">
                                <a class="tab<?php echo $status === 'all' ? ' active' : ''; ?>" href="/counselor/">All</a>
                                <a class="tab<?php echo $status === 'confirmed' ? ' active' : ''; ?>" href="/counselor/?status=confirmed">Confirmed</a>
                                <a class="tab<?php echo $status === 'pending' ? ' active' : ''; ?>" href="/counselor/?status=pending">Pending</a>
                            </div>
                        </div>
                        <table class="data-table">
                            <thead><tr><th>Date / Time</th><th>Patient / Type</th><th>Topic / Reason</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="5">No appointments found.</td></tr>
                            <?php else: foreach ($rows as $a): $ts = strtotime($a['scheduled_at']); ?>
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
                                    <td><a class="btn-sm" href="/counselor/?<?php echo $status !== 'all' ? 'status=' . e($status) . '&amp;' : ''; ?>view=<?php echo e($a['id']); ?>#details">Manage</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cta">
                        <div><h3>Need to schedule an extra session?</h3><p>Update your time blocks so patients can book you.</p></div>
                        <a href="/counselor/availability.php" class="btn btn-light">Manage availability</a>
                    </div>
                </div>

                <div class="stack">
                    <div class="card">
                        <div class="card-head"><h2>Recent Records</h2><a class="link" href="/counselor/record.php">Add record</a></div>
                        <?php if (!$records): ?>
                            <p class="empty">No records yet.</p>
                        <?php else: foreach ($records as $r): ?>
                            <div class="result-item">
                                <strong><?php echo e($r['diagnosis']); ?></strong>
                                <small><?php echo e($r['patient']['full_name'] ?? 'Patient'); ?> · <?php echo !empty($r['shared_with_patient']) ? 'Shared' : 'Private'; ?> · <?php echo e(fmt_dt($r['created_at'], 'M d')); ?></small>
                            </div>
                        <?php endforeach; endif; ?>
                        <div class="note-box"><strong>Counselor note</strong><small>Session notes are private to you. Only diagnoses you mark as shared are visible to patients.</small></div>
                    </div>
                </div>
            </div>
<?php page_end(); ?>