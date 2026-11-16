<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');

function within_office_hours(DateTime $when, array $settings) {
    $mins = (int) $when->format('G') * 60 + (int) $when->format('i');
    [$oh, $om] = array_map('intval', explode(':', $settings['office_open']));
    [$ch, $cm] = array_map('intval', explode(':', $settings['office_close']));
    return $mins >= $oh * 60 + $om && $mins + (int) $settings['session_minutes'] <= $ch * 60 + $cm;
}
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

$cr = sb_request('GET', 'profiles', 'select=id,full_name&role=eq.counselor&is_active=eq.true&order=full_name.asc', null, $token);
$counselors = ($cr['status'] === 200 && is_array($cr['data'])) ? $cr['data'] : [];

// Only counselors an admin has verified can be booked.
$vr = sb_request('GET', 'counselor_verifications', 'select=counselor_id&status=eq.verified', null, $token);
$verifiedIds = ($vr['status'] === 200 && is_array($vr['data'])) ? array_column($vr['data'], 'counselor_id') : [];
$counselors = array_values(array_filter($counselors, fn($c) => in_array($c['id'], $verifiedIds, true)));
$counselorIds = array_column($counselors, 'id');

// Office hours / session length / booking window set by the admin.
$settings = get_settings($token);
$workDays = settings_days($settings);
$sessionMin = (int) $settings['session_minutes'];


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
            audit_from($me, 'appointment_cancelled', 'appointment', $id);
        }
    } else {
        $counselorId = $_POST['counselor_id'] ?? '';
        $sessionMode = $_POST['session_mode'] ?? 'in_person';
        $rawReason   = trim($_POST['reason'] ?? '');
        $when = DateTime::createFromFormat('Y-m-d\TH:i', $_POST['scheduled_at'] ?? '', new DateTimeZone('Asia/Manila'));

        if (!in_array($counselorId, $counselorIds, true)) {
            $err = 'Please choose a counselor.';
        } elseif (!$when || $when->getTimestamp() <= time()) {
            $err = 'Please choose a date and time in the future.';
        } elseif (!in_array($when->format('l'), $workDays, true)) {
            $err = 'Appointments are only available on: ' . implode(', ', $workDays) . '.';
        } elseif (!within_office_hours($when, $settings)) {
            $err = 'Please choose a time between ' . $settings['office_open'] . ' and ' . $settings['office_close']
                 . ' (sessions last ' . $sessionMin . ' minutes).';
        } elseif ($when->getTimestamp() > time() + 86400 * (int) $settings['booking_window_days']) {
            $err = 'You can book up to ' . (int) $settings['booking_window_days'] . ' days ahead.';
        } elseif ($rawReason === '' || strlen($rawReason) > 500) {
            $err = 'Please tell us briefly why you want to book (up to 500 characters).';
        } else {
            // Encode session mode & optional Google Meet link into reason
            if ($sessionMode === 'online') {
                $meetLink = generate_google_meet_link($uid . $counselorId . time());
                $reason = '[Online Video Call - Google Meet: ' . $meetLink . '] ' . $rawReason;
            } else {
                $reason = '[In-Person Consultation - Room 204] ' . $rawReason;
            }

            $r = sb_request('POST', 'appointments', '', [
                'patient_id' => $uid, 'counselor_id' => $counselorId,
                'scheduled_at' => $when->format('c'), 'reason' => $reason, 'status' => 'pending',
            ], $token);
            if ($r['status'] === 201) {
                $msg = 'Request sent! Your counselor will confirm it soon.';
                audit_from($me, 'appointment_booked', 'appointment', $r['data'][0]['id'] ?? '', $when->format('M d, Y g:i A'));
                
                // Notify the counselor
                $patientName = $me['profile']['full_name'] ?? 'A student';
                create_notification(
                    $counselorId,
                    'New Appointment Request: ' . $patientName,
                    $patientName . ' requested a ' . ($sessionMode === 'online' ? 'Google Meet Video' : 'In-Person') . ' session on ' . $when->format('M d, Y g:i A') . '.',
                    'appointment',
                    $token
                );
            }
            else { $err = 'Could not book the appointment. Please try again.'; }
        }
    }
}

$ar = sb_request('GET', 'appointments',
    'select=id,scheduled_at,status,reason,counselor:profiles!counselor_id(full_name)'
    . '&patient_id=eq.' . urlencode($uid) . '&order=scheduled_at.desc', null, $token);
$mine = ($ar['status'] === 200 && is_array($ar['data'])) ? $ar['data'] : [];

$selectedCounselorId = $_POST['counselor_id'] ?? ($_GET['counselor_id'] ?? '');
$rightHeader = patient_header($uid, $token);
page_start('patient', 'Appointments', '/patient/appointment.php', 'Book & Manage Appointments', 'Schedule and track your counseling sessions.', $rightHeader);
?>
            <?php echo patient_search_modal($token); ?>
            <div class="card">
                <div class="card-head"><div><h2>Book an Appointment</h2><p class="card-sub">Your counselor will confirm the request</p></div></div>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/patient/appointment.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="book">

                    <div class="field">
                        <label for="counselor_id">Counselor</label>
                        <select id="counselor_id" name="counselor_id" required>
                            <option value="">-- Choose a verified counselor --</option>
                            <?php foreach ($counselors as $c): ?>
                                <option value="<?php echo e($c['id']); ?>" <?php echo ($selectedCounselorId === $c['id']) ? 'selected' : ''; ?>><?php echo e($c['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="scheduled_at">Date &amp; time</label>
                        <input type="datetime-local" id="scheduled_at" name="scheduled_at" value="<?php echo e($_POST['scheduled_at'] ?? ''); ?>" min="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    <p class="form-hint">Office hours <?php echo e($settings['office_open']); ?>–<?php echo e($settings['office_close']); ?> · <?php echo e(implode(', ', array_map(fn($d) => substr($d, 0, 3), $workDays))); ?> · sessions last <?php echo (int) $sessionMin; ?> minutes</p>

                    <div class="field">
                        <label for="session_mode">Session Format / Venue</label>
                        <select id="session_mode" name="session_mode" required>
                            <option value="in_person" <?php echo (($_POST['session_mode'] ?? 'in_person') === 'in_person') ? 'selected' : ''; ?>>🏢 In-Person Consultation (Guidance Office - Room 204)</option>
                            <option value="online" <?php echo (($_POST['session_mode'] ?? '') === 'online') ? 'selected' : ''; ?>>🟢 Online Video Call (via Google Meet)</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="reason">Reason for visit</label>
                        <textarea id="reason" name="reason" rows="3" maxlength="500" required placeholder="Describe your topic or questions for your guidance counselor..."><?php echo e($_POST['reason'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn">Request appointment</button>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h2>My Appointments</h2></div>
                <table class="data-table">
                    <thead><tr><th>Date / Time</th><th>Counselor</th><th>Format &amp; Reason</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$mine): ?>
                        <tr><td colspan="5">You have no appointments yet.</td></tr>
                    <?php else: foreach ($mine as $a): 
                        $ts = strtotime($a['scheduled_at']);
                        $rawR = $a['reason'] ?? '-';
                        $isOnline = false;
                        $meetUrl = '';
                        $displayReason = $rawR;

                        if (preg_match('/\[Online Video Call - Google Meet:\s*(https:\/\/meet\.google\.com\/[a-z0-9-]+)\]\s*(.*)/is', $rawR, $m)) {
                            $isOnline = true;
                            $meetUrl = trim($m[1]);
                            $displayReason = trim($m[2]);
                        } elseif (stripos($rawR, 'google meet') !== false || stripos($rawR, 'online') !== false) {
                            $isOnline = true;
                            if (preg_match('/(https:\/\/meet\.google\.com\/[a-z0-9-]+)/i', $rawR, $m)) {
                                $meetUrl = $m[1];
                            }
                        } elseif (preg_match('/\[In-Person Consultation[^\]]*\]\s*(.*)/is', $rawR, $m)) {
                            $displayReason = trim($m[1]);
                        }
                    ?>
                        <tr>
                            <td>
                                <div class="when">
                                    <span class="date-chip"><small><?php echo e(strtoupper(date('M', $ts))); ?></small><strong><?php echo e(date('d', $ts)); ?></strong></span>
                                    <span><?php echo e(date('g:i A', $ts)); ?></span>
                                </div>
                            </td>
                            <td><strong><?php echo e($a['counselor']['full_name'] ?? 'Counselor'); ?></strong></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                                    <?php if ($isOnline): ?>
                                        <span class="status pending" style="font-size:11px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px;"><?php echo icon('video'); ?> Google Meet</span>
                                    <?php else: ?>
                                        <span class="status active" style="font-size:11px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px;"><?php echo icon('users'); ?> In-Person</span>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size:13px;color:var(--ink-secondary);"><?php echo e($displayReason); ?></span>
                            </td>
                            <td><?php echo status_badge($a['status']); ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <?php if ($a['status'] === 'confirmed' && $isOnline && !empty($meetUrl)): ?>
                                        <a href="<?php echo e($meetUrl); ?>" target="_blank" rel="noopener" class="btn-sm btn-success-sm" style="display:inline-flex;align-items:center;gap:4px;" title="Join Google Meet Call">
                                            <?php echo icon('video'); ?> Meet
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="/patient/print-appointment.php?id=<?php echo e($a['id']); ?>" target="_blank" class="btn-sm" style="display:inline-flex;align-items:center;gap:4px;" title="Print Consultation Pass">
                                        <?php echo icon('printer'); ?> Slip
                                    </a>

                                    <?php if (in_array($a['status'], ['pending', 'confirmed'], true)): ?>
                                        <form method="POST" action="/patient/appointment.php" class="inline-form" onsubmit="return confirm('Cancel this appointment?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="appointment_id" value="<?php echo e($a['id']); ?>">
                                            <button type="submit" class="btn-outline-danger-sm">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end();?>