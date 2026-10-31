<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

$cr = sb_request('GET', 'profiles', 'select=id,full_name&role=eq.counselor&is_active=eq.true&order=full_name.asc', null, $token);
$counselors = ($cr['status'] === 200 && is_array($cr['data'])) ? $cr['data'] : [];
$counselorIds = array_column($counselors, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'book';

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif ($action === 'cancel') {
        $id = $_POST['appointment_id'] ?? '';
        if (is_uuid($id)) {
            sb_request('PATCH', 'appointments',
                'id=eq.' . urlencode($id) . '&patient_id=eq.' . urlencode($uid) . '&status=in.(pending,confirmed)',
                ['status' => 'cancelled'], $token);
            $msg = 'Appointment cancelled.';
        }
    } else {
        $counselorId = $_POST['counselor_id'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $when = DateTime::createFromFormat('Y-m-d\TH:i', $_POST['scheduled_at'] ?? '', new DateTimeZone('Asia/Manila'));

        if (!in_array($counselorId, $counselorIds, true)) {
            $err = 'Please choose a counselor.';
        } elseif (!$when || $when->getTimestamp() <= time()) {
            $err = 'Please choose a date and time in the future.';
        } elseif ($reason === '' || strlen($reason) > 500) {
            $err = 'Please tell us briefly why you want to book (up to 500 characters).';
        } else {
            $r = sb_request('POST', 'appointments', '', [
                'patient_id' => $uid, 'counselor_id' => $counselorId,
                'scheduled_at' => $when->format('c'), 'reason' => $reason, 'status' => 'pending',
            ], $token);
            if ($r['status'] === 201) { $msg = 'Request sent! Your counselor will confirm it soon.'; }
            else { $err = 'Could not book the appointment. Please try again.'; }
        }
    }
}

$ar = sb_request('GET', 'appointments',
    'select=id,scheduled_at,status,reason,counselor:profiles!counselor_id(full_name)'
    . '&patient_id=eq.' . urlencode($uid) . '&order=scheduled_at.desc', null, $token);
$mine = ($ar['status'] === 200 && is_array($ar['data'])) ? $ar['data'] : [];

page_start('patient', 'Appointments', '/patient/appointment.php', 'Book & Manage Appointments');
?>
            <div class="card" style="margin-bottom:16px;">
                <h2>Book an Appointment</h2>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/patient/appointment.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="book">

                    <label>Counselor:</label>
                    <select name="counselor_id" required style="display:block; margin-bottom:15px; padding:8px; width:100%;">
                        <option value="">-- Choose counselor --</option>
                        <?php foreach ($counselors as $c): ?>
                            <option value="<?php echo e($c['id']); ?>"><?php echo e($c['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Date &amp; time:</label>
                    <input type="datetime-local" name="scheduled_at" required style="display:block; margin-bottom:15px; padding:8px;">

                    <label>Reason for visit:</label>
                    <textarea name="reason" rows="3" maxlength="500" required style="width:100%; margin-bottom:15px; padding:8px;"></textarea>

                    <button type="submit" class="btn">Request appointment</button>
                </form>
            </div>

            <div class="card">
                <h2>My Appointments</h2>
                <table class="data-table">
                    <thead><tr><th>Date &amp; Time</th><th>Counselor</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$mine): ?>
                        <tr><td colspan="5">You have no appointments yet.</td></tr>
                    <?php else: foreach ($mine as $a): ?>
                        <tr>
                            <td><?php echo e(fmt_dt($a['scheduled_at'])); ?></td>
                            <td><?php echo e($a['counselor']['full_name'] ?? 'Counselor'); ?></td>
                            <td><?php echo e($a['reason'] ?? '-'); ?></td>
                            <td><span class="status <?php echo e($a['status']); ?>"><?php echo e(ucfirst($a['status'])); ?></span></td>
                            <td>
                                <?php if (in_array($a['status'], ['pending', 'confirmed'], true)): ?>
                                    <form method="POST" action="/patient/appointment.php" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="appointment_id" value="<?php echo e($a['id']); ?>">
                                        <button type="submit" class="btn-sm">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end(); ?>