<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

// Capture top-bar search query parameter
$search = trim($_GET['q'] ?? '');
// Also support existing table filter parameter if present
$tableSearch = trim($_GET['search'] ?? '');

$patients = get_counselor_patients($uid, $token);
if ($tableSearch !== '') {
    $patients = array_values(array_filter($patients, fn($p) =>
        stripos($p['full_name'] ?? '', $tableSearch) !== false || stripos($p['email'] ?? '', $tableSearch) !== false));
}

// Direct patient profile search for the top-bar pop-up modal window
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
    <form method="GET" action="/counselor/patients.php" class="dashboard-search-form">
        <div class="dashboard-search-wrapper">
            <input type="text" name="q" value="' . e($search) . '" placeholder="Search patients by name..." class="dashboard-search-input">
            <svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
        ' . ($search !== '' ? '<a href="/counselor/patients.php" class="btn-sm">Clear</a>' : '') . '
    </form>
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

page_start('counselor', 'Patient List', '/counselor/patients.php', 'Your Patients', 'Counselor care management and student sessions portal.', $rightContent);
?>
            <!-- Patient Search Results Pop-up Modal Window -->
            <?php if ($search !== ''): ?>
                <div class="modal-overlay">
                    <div class="modal-card">
                        <div class="modal-header">
                            <h2>Patient Search Results (<?php echo count($patientResults); ?>)</h2>
                            <a href="/counselor/patients.php" class="modal-close-btn" title="Close">&times;</a>
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
                <div class="card-head"><div><h2>Assigned Patients</h2><p class="card-sub">Students and staff who have booked with you</p></div></div>

                <form method="GET" action="/counselor/patients.php" class="search-form">
                    <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo e($search); ?>"><?php endif; ?>
                    <input type="text" name="search" value="<?php echo e($tableSearch); ?>" placeholder="Search by name or email...">
                    <button type="submit" class="btn">Search</button>
                    <?php if ($tableSearch !== ''): ?><a href="/counselor/patients.php" class="btn-sm" style="display:inline-flex;align-items:center;">Clear Filter</a><?php endif; ?>
                </form>

                <table class="data-table">
                    <thead><tr><th>Full Name</th><th>Type</th><th>Grade / Department</th><th>Email</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$patients): ?>
                        <tr><td colspan="5">No patients found.</td></tr>
                    <?php else: foreach ($patients as $p): ?>
                        <tr>
                            <td><strong><?php echo e($p['full_name']); ?></strong></td>
                            <td><?php echo e(ucfirst($p['patient_type'] ?? '-')); ?></td>
                            <td><?php echo e($p['grade_or_dept'] ?? 'N/A'); ?></td>
                            <td><?php echo e($p['email'] ?? ''); ?></td>
                            <td><a href="/counselor/record.php?patient_id=<?php echo e($p['id']); ?>" class="btn-sm">View Record</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end(); ?>