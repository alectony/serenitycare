<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

// Capture search query parameter
$search = trim($_GET['q'] ?? '');

$patients = get_counselor_patients($uid, $token);
$allowedIds = array_column($patients, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = $_POST['patient_id'] ?? '';
    $notes = trim($_POST['private_notes'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $recs = trim($_POST['recommendations'] ?? '');
    $shared = isset($_POST['shared_with_patient']);

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif (!is_uuid($patientId) || !in_array($patientId, $allowedIds, true)) {
        $err = 'Please choose one of your patients.';
    } elseif ($notes === '' || $diagnosis === '' || $recs === '') {
        $err = 'Please fill in all fields.';
    } else {
        // Private notes and the shareable diagnosis live in separate tables,
        // so a patient can never read the private notes through the API.
        $n = sb_request('POST', 'session_notes', '', [
            'patient_id' => $patientId, 'counselor_id' => $uid, 'notes' => $notes,
        ], $token);
        $d = sb_request('POST', 'diagnoses', '', [
            'patient_id' => $patientId, 'counselor_id' => $uid,
            'diagnosis' => $diagnosis, 'recommendations' => $recs, 'shared_with_patient' => $shared,
        ], $token);

        if ($n['status'] === 201 && $d['status'] === 201) {
            $msg = 'Patient record saved successfully.';
            audit_from($me, 'record_saved', 'patient', $patientId, $shared ? 'Diagnosis shared with patient' : 'Diagnosis kept private');
        } else {
            $err = 'Could not save the record. Please try again.';
        }
    }
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
    <form method="GET" action="/counselor/record.php" class="dashboard-search-form">
        <div class="dashboard-search-wrapper">
            <input type="text" name="q" value="' . e($search) . '" placeholder="Search patients by name..." class="dashboard-search-input">
            <svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
        ' . ($search !== '' ? '<a href="/counselor/record.php" class="btn-sm">Clear</a>' : '') . '
    </form>
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

$selected = $_POST['patient_id'] ?? ($_GET['patient_id'] ?? '');

page_start('counselor', 'Patient Records', '/counselor/record.php', 'Session Notes & Diagnosis Editor', 'Counselor care management and student sessions portal.', $rightContent);
?>
            <!-- Patient Search Results Pop-up Modal Window -->
            <?php if ($search !== ''): ?>
                <div class="modal-overlay">
                    <div class="modal-card">
                        <div class="modal-header">
                            <h2>Patient Search Results (<?php echo count($patientResults); ?>)</h2>
                            <a href="/counselor/record.php" class="modal-close-btn" title="Close">&times;</a>
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
                <div class="card-head"><div><h2>New Record</h2><p class="card-sub">Private notes stay with you; only shared diagnoses reach the patient</p></div></div>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/counselor/record.php">
                    <?php echo csrf_field(); ?>

                    <div class="field">
                        <label for="patient_id">Select patient</label>
                        <select id="patient_id" name="patient_id" required>
                            <option value="">-- Choose patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo e($p['id']); ?>"<?php echo $selected === $p['id'] ? ' selected' : ''; ?>><?php echo e($p['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="private_notes">Session notes (private to counselor)</label>
                        <textarea id="private_notes" name="private_notes" rows="4" required></textarea>
                    </div>

                    <div class="field">
                        <label for="diagnosis">Diagnosis</label>
                        <input type="text" id="diagnosis" name="diagnosis" required>
                    </div>

                    <div class="field">
                        <label for="recommendations">Recommendations / results</label>
                        <input type="text" id="recommendations" name="recommendations" required>
                    </div>

                    <div class="checkbox-row">
                        <input type="checkbox" id="shared_with_patient" name="shared_with_patient" value="1">
                        <label for="shared_with_patient">Share diagnosis with patient</label>
                    </div>

                    <button type="submit" class="btn">Save record</button>
                </form>
            </div>
<?php page_end();?>