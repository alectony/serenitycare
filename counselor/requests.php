<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

// Capture search query parameter
$search = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['appointment_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (csrf_valid() && is_uuid($id) && in_array($action, ['approve', 'decline'], true)) {
        $status = $action === 'approve' ? 'confirmed' : 'declined';
        sb_request('PATCH', 'appointments',
            'id=eq.' . urlencode($id) . '&counselor_id=eq.' . urlencode($uid) . '&status=eq.pending',
            ['status' => $status], $token);
        audit_from($me, $action === 'approve' ? 'appointment_confirmed' : 'appointment_declined', 'appointment', $id);
    }
    header('Location: /counselor/requests.php');
    exit;
}

// Direct patient profile search for the pop-up modal window
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
    <form method="GET" action="/counselor/requests.php" class="dashboard-search-form">
        <div class="dashboard-search-wrapper">
            <input type="text" name="q" value="' . e($search) . '" placeholder="Search patients by name..." class="dashboard-search-input">
            <svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
        ' . ($search !== '' ? '<a href="/counselor/requests.php" class="btn-sm">Clear</a>' : '') . '
    </form>
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

$res = sb_request('GET', 'appointments',
    'select=id,scheduled_at,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&status=eq.pending&order=created_at.asc', null, $token);

$failed = ($res['status'] !== 200 || !is_array($res['data']));
$requests = $failed ? [] : $res['data'];

page_start('counselor', 'Appointment Requests', '/counselor/requests.php', 'Pending Appointment Requests', 'Counselor care management and student sessions portal.', $rightContent);
?>
            <!-- Patient Search Results Pop-up Modal Window -->
            <?php if ($search !== ''): ?>
                <div class="modal-overlay">
                    <div class="modal-card">
                        <div class="modal-header">
                            <h2>Patient Search Results (<?php echo count($patientResults); ?>)</h2>
                            <a href="/counselor/requests.php" class="modal-close-btn" title="Close">&times;</a>
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
                <div class="card-head"><div><h2>Waiting for your decision</h2><p class="card-sub">Approve or decline each request</p></div></div>
                <?php if ($failed): ?><div class="alert error">Could not load requests. Please try again.</div><?php endif; ?>

                <?php if (!$requests && !$failed): ?>
                    <p class="empty">No pending appointment requests at this time.</p>
                <?php else: ?>
                    <div class="request-list">
                    <?php foreach ($requests as $req): ?>
                        <div class="request-item">
                            <div>
                                <strong><?php echo e($req['patient']['full_name'] ?? 'Unknown'); ?></strong>
                                <small><?php echo e(ucfirst($req['patient']['patient_type'] ?? '-')); ?> · <?php echo e(fmt_dt($req['scheduled_at'], 'F d, Y - g:i A')); ?></small>
                                <?php if (!empty($req['reason'])): ?><small>Reason: <?php echo e($req['reason']); ?></small><?php endif; ?>
                            </div>
                            <form method="POST" action="/counselor/requests.php" class="request-actions">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="appointment_id" value="<?php echo e($req['id']); ?>">
                                <button type="submit" name="action" value="approve" class="btn btn-success-sm">Approve</button>
                                <button type="submit" name="action" value="decline" class="btn btn-outline-danger-sm">Decline</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
<?php page_end();?>