<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

// Capture search query parameter
$search = trim($_GET['q'] ?? '');

// Build query parameters for Supabase
$queryParams = 'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid);

if ($search !== '') {
    $queryParams .= '&or=(reason.ilike.*' . urlencode($search) . '*)';
}

$queryParams .= '&order=scheduled_at.desc';

$res = sb_request('GET', 'appointments', $queryParams, null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$all = $failed ? [] : $res['data'];

// Secondary filter in PHP for patient name matching when searching across relations
if ($search !== '' && !empty($all)) {
    $searchLower = strtolower($search);
    $all = array_filter($all, function($a) use ($searchLower) {
        $reasonMatch = isset($a['reason']) && strpos(strtolower($a['reason']), $searchLower) !== false;
        $nameMatch = isset($a['patient']['full_name']) && strpos(strtolower($a['patient']['full_name']), $searchLower) !== false;
        return $reasonMatch || $nameMatch;
    });
    $all = array_values($all);
}

$dx = sb_request('GET', 'diagnoses',
    'select=diagnosis,shared_with_patient,created_at,patient:profiles!patient_id(full_name)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=created_at.desc&limit=3', null, $token);
$records = ($dx['status'] === 200 && is_array($dx['data'])) ? $dx['data'] : [];

// Has an admin verified this counselor yet? (patients can only book verified counselors)
$vr = sb_request('GET', 'counselor_verifications', 'select=status,note&counselor_id=eq.' . urlencode($uid), null, $token);
$verification = ($vr['status'] === 200 && !empty($vr['data'][0])) ? $vr['data'][0] : ['status' => 'pending', 'note' => ''];

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

// Prepare counselor profile initials for avatar display
$fullName = $me['profile']['full_name'] ?? 'Ms. Elena Reyes';
$nameParts = explode(' ', $fullName);
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Construct top-bar right content (Search bar + Profile interactable)
$rightContent = '
    <form method="GET" action="/counselor/" class="dashboard-search-form">' .
    ($status !== 'all' ? '<input type="hidden" name="status" value="' . e($status) . '">' : '') . '
        <div class="dashboard-search-wrapper">
            <input type="text" name="q" value="' . e($search) . '" placeholder="Search student or appointment..." class="dashboard-search-input">
            <svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
        ' . ($search !== '' ? '<a href="/counselor/' . ($status !== 'all' ? '?status=' . e($status) : '') . '" class="btn-sm">Clear</a>' : '') . '
    </form>
    <a href="/counselor/profile.php" class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </a>
';

page_start('counselor', 'Dashboard', '/counselor/', greeting() . ', ' . $fullName, 'Here is a summary of your counseling care and upcoming student sessions.', $rightContent);
?>
            <?php if ($failed): ?><div class="alert error">Could not load your data. Please refresh.</div><?php endif; ?>
            <?php if ($verification['status'] !== 'verified'): ?>
                <div class="alert error">
                    <?php echo $verification['status'] === 'rejected' ? 'Your verification was not approved.' : 'Your account is waiting for admin verification.'; ?>
                    Patients cannot book you until you are verified.<?php if (!empty($verification['note'])): ?> Note from admin: <?php echo e($verification['note']); ?><?php endif; ?>
                    Make sure your <a href="/counselor/profile.php">license number</a> is filled in.
                </div>
            <?php endif; ?>

            <?php if ($search !== ''): ?>
                <div class="search-alert">
                    Showing database search results for: <strong><?php echo e($search); ?></strong> (<a href="/counselor/" style="font-weight: 600;">Reset search</a>)
                </div>
            <?php endif; ?>

            <div class="stats">
                <?php echo stat_card("Today's sessions", $todayCount, 'Scheduled for today', 'calendar'); ?>
                <?php echo stat_card('Pending requests', $pendingCount, 'Awaiting your review', 'clock'); ?>
                <?php echo stat_card('Active patients', $patientCount, 'Students & staff', 'users'); ?>
            </div>

            <div class="content-grid">
                <div class="stack">
                    <?php if ($detail): ?>
                        <div class="card details-panel" id="details">
                            <div class="card-head"><h2>Appointment Details</h2><a class="link" href="/counselor/?<?php echo $search !== '' ? 'q=' . urlencode($search) . '&amp;' : ''; ?>status=<?php echo e($status); ?>">Close</a></div>
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
                                <a class="tab<?php echo $status === 'all' ? ' active' : ''; ?>" href="/counselor/<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>">All</a>
                                <a class="tab<?php echo $status === 'confirmed' ? ' active' : ''; ?>" href="/counselor/?status=confirmed<?php echo $search !== '' ? '&amp;q=' . urlencode($search) : ''; ?>">Confirmed</a>
                                <a class="tab<?php echo $status === 'pending' ? ' active' : ''; ?>" href="/counselor/?status=pending<?php echo $search !== '' ? '&amp;q=' . urlencode($search) : ''; ?>">Pending</a>
                            </div>
                        </div>
                        <table class="data-table">
                            <thead><tr><th>Date / Time</th><th>Patient / Type</th><th>Topic / Reason</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="5">No appointments found matching your criteria.</td></tr>
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
                                    <td><a class="btn-sm" href="/counselor/?<?php echo $search !== '' ? 'q=' . urlencode($search) . '&amp;' : ''; ?><?php echo $status !== 'all' ? 'status=' . e($status) . '&amp;' : ''; ?>view=<?php echo e($a['id']); ?>#details">Manage</a></td>
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