<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

// Capture search and filter parameters (matching the admin style)
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$statuses = ['pending', 'confirmed', 'completed', 'declined', 'cancelled'];

// Build query restricted strictly to this counselor
$query = 'select=id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=scheduled_at.desc';

if (in_array($status, $statuses, true)) { $query .= '&status=eq.' . $status; }
if ($from !== '') { $query .= '&scheduled_at=gte.' . rawurlencode($from . 'T00:00:00+08:00'); }
if ($to !== '') { $query .= '&scheduled_at=lte.' . rawurlencode($to . 'T23:59:59+08:00'); }
suppress_query_limit: 
$query .= '&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage);

$res = sb_request('GET', 'appointments', $query, null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$rows = $failed ? [] : $res['data'];
$hasNext = count($rows) > $perPage;
$rows = array_slice($rows, 0, $perPage);

// Direct patient profile search for the top-bar modal window
$patientResults = [];
if ($search !== '') {
    $patientQuery = 'select=id,full_name,email,patient_type,grade_or_dept,created_at,role&role=eq.patient'
        . '&or=(full_name.ilike.*' . urlencode($search) . '*,email.ilike.*' . urlencode($search) . '*)'
        . '&order=full_name.asc';
    $pRes = sb_request('GET', 'profiles', $patientQuery, null, $token);
    if ($pRes['status'] === 200 && is_array($pRes['data'])) {
        $patientResults = $pRes['data'];
    }
}

// Prepare counselor profile initials for avatar display
$fullName = $me['profile']['full_name'] ?? 'Ms. Elena Reyes';
$nameParts = explode(' ', $fullName);
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Construct top-bar right content matching layout structures exactly
$rightContent = '
    <form method="GET" action="/counselor/schedule.php" class="dashboard-search-form">
        ' . ($status !== '' ? '<input type="hidden" name="status" value="' . e($status) . '">' : '') . '
        ' . ($from !== '' ? '<input type="hidden" name="from" value="' . e($from) . '">' : '') . '
        ' . ($to !== '' ? '<input type="hidden" name="to" value="' . e($to) . '">' : '') . '
        <div class="dashboard-search-wrapper">
            <input type="text" name="q" value="' . e($search) . '" placeholder="Search patients by name..." class="dashboard-search-input">
            <svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
        ' . ($search !== '' ? '<a href="/counselor/schedule.php" class="btn-sm">Clear</a>' : '') . '
    </form>
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

page_start('counselor', 'My Schedule', '/counselor/schedule.php', 'All Appointments', 'Overview of your scheduled counseling sessions.', $rightContent);
?>
            <!-- Patient Search Results Pop-up Modal Window -->
            <?php if ($search !== ''): ?>
                <div class="modal-overlay">
                    <div class="modal-card">
                        <div class="modal-header">
                            <h2>Patient Search Results (<?php echo count($patientResults); ?>)</h2>
                            <a href="/counselor/schedule.php" class="modal-close-btn" title="Close">&times;</a>
                        </div>
                        <div class="modal-body">
                            <p style="margin-bottom: 14px; font-size: 13.5px; color: var(--muted);">
                                Showing patient matches for query: <strong><?php echo e($search); ?></strong>
                            </p>
                            <?php if (empty($patientResults)): ?>
                                <p class="empty" style="text-align: center; padding: 24px 0;">No patients found matching "<?php echo e($search); ?>".</p>
                            <?php else: ?>
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <?php foreach ($patientResults as $pat): ?>
                                        <div class="patient-popup-item">
                                            <div class="patient-popup-info">
                                                <strong><?php echo e($pat['full_name']); ?></strong>
                                                <small><?php echo e($pat['email']); ?> &middot; <?php echo e(ucfirst($pat['patient_type'] ?? 'Student')); ?></small>
                                            </div>
                                            <a href="/counselor/record.php?patient_id=<?php echo e($pat['id']); ?>" class="btn-sm">View Record</a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-head">
                    <div><h2>Appointments</h2><p class="card-sub">Filter by status or date</p></div>
                </div>
                <?php if ($failed): ?><div class="alert error">Could not load appointments. Please refresh.</div><?php endif; ?>

                <form method="GET" action="/counselor/schedule.php" class="filter-bar">
                    <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo e($search); ?>"><?php endif; ?>
                    <select name="status" aria-label="Status">
                        <option value="">Any status</option>
                        <?php foreach ($statuses as $s): ?><option value="<?php echo e($s); ?>"<?php echo $status === $s ? ' selected' : ''; ?>><?php echo e(ucfirst($s)); ?></option><?php endforeach; ?>
                    </select>
                    <label class="inline-label">From <input type="date" name="from" value="<?php echo e($from); ?>"></label>
                    <label class="inline-label">To <input type="date" name="to" value="<?php echo e($to); ?>"></label>
                    <button type="submit" class="btn">Filter</button>
                    <?php if ($status !== '' || $from !== '' || $to !== ''): ?>
                        <a href="/counselor/schedule.php" class="btn-sm" style="display:inline-flex;align-items:center;">Reset</a>
                    <?php endif; ?>
                </form>

                <table class="data-table">
                    <thead><tr><th>Date / Time</th><th>Patient</th><th>Reason</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="4">No appointments match your filters.</td></tr>
                    <?php else: foreach ($rows as $a): $ts = strtotime($a['scheduled_at']); ?>
                        <tr>
                            <td><div class="when"><span class="date-chip"><small><?php echo e(strtoupper(date('M', $ts))); ?></small><strong><?php echo e(date('d', $ts)); ?></strong></span><span><?php echo e(date('g:i A', $ts)); ?></span></div></td>
                            <td><strong><?php echo e($a['patient']['full_name'] ?? 'Unknown'); ?></strong><br><small><?php echo e(ucfirst($a['patient']['patient_type'] ?? '-')); ?></small></td>
                            <td><?php echo e($a['reason'] ?? '-'); ?></td>
                            <td><?php echo status_badge($a['status']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php echo pager($page, $hasNext); ?>
            </div>
<?php page_end(); ?>