<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

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
        } else {
            $err = 'Could not save the record. Please try again.';
        }
    }
}

$selected = $_POST['patient_id'] ?? ($_GET['patient_id'] ?? '');

page_start('counselor', 'Patient Records', '/counselor/record.php', 'Session Notes & Diagnosis Editor');
?>
            <div class="card">
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>
                <form method="POST" action="/counselor/record.php">
                    <?php echo csrf_field(); ?>
                    <label>Select Patient:</label>
                    <select name="patient_id" required style="display:block; margin-bottom:15px; padding:8px; width:100%;">
                        <option value="">-- Choose Patient --</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?php echo e($p['id']); ?>" <?php echo $selected === $p['id'] ? 'selected' : ''; ?>><?php echo e($p['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Session Notes (Private to Counselor):</label>
                    <textarea name="private_notes" rows="4" style="width:100%; margin-bottom:15px; padding:8px;" required></textarea>

                    <label>Diagnosis:</label>
                    <input type="text" name="diagnosis" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <label>Recommendations / Results:</label>
                    <input type="text" name="recommendations" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <div style="margin-bottom:15px;">
                        <label><input type="checkbox" name="shared_with_patient" value="1"> Share diagnosis with patient</label>
                    </div>

                    <button type="submit" class="btn" style="background:#2A9D8F; color:white; padding:10px 20px;">Save record</button>
                </form>
            </div>
<?php page_end(); ?>