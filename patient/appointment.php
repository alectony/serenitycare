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

function get_session_slots(array $settings): array {
    $slots = [];
    [$oh, $om] = array_map('intval', explode(':', $settings['office_open'] ?? '08:00'));
    [$ch, $cm] = array_map('intval', explode(':', $settings['office_close'] ?? '17:00'));
    $sessMin = max(15, (int) ($settings['session_minutes'] ?? 50));
    $step = ($sessMin <= 50) ? 60 : $sessMin;

    for ($mins = $oh * 60 + $om; $mins + $sessMin <= $ch * 60 + $cm; $mins += $step) {
        $h = floor($mins / 60);
        $m = $mins % 60;
        $val = sprintf('%02d:%02d', $h, $m);

        $endMins = $mins + $sessMin;
        $endH = floor($endMins / 60);
        $endMin = $endMins % 60;
        $endVal = sprintf('%02d:%02d', $endH, $endMin);

        $startFmt = date('g:i A', strtotime("2000-01-01 $val:00"));
        $endFmt   = date('g:i A', strtotime("2000-01-01 $endVal:00"));

        $slots[] = [
            'value' => $val,
            'label' => "$startFmt – $endFmt ({$sessMin} mins)"
        ];
    }
    return $slots;
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

        // Support separate date + time slot or unified scheduled_at
        $postDate     = trim($_POST['appointment_date'] ?? '');
        $postTime     = trim($_POST['appointment_time'] ?? '');
        $rawSchedule  = trim($_POST['scheduled_at'] ?? '');

        if (!empty($postDate) && !empty($postTime)) {
            $rawSchedule = $postDate . 'T' . $postTime;
        }

        $when = DateTime::createFromFormat('Y-m-d\TH:i', $rawSchedule, new DateTimeZone('Asia/Manila'));

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

$sessionSlots = get_session_slots($settings);
$selectedDate = $_POST['appointment_date'] ?? '';
$selectedTime = $_POST['appointment_time'] ?? '';
$rawSchedule  = $_POST['scheduled_at'] ?? '';
if (empty($selectedDate) && !empty($rawSchedule)) {
    $sParts = explode('T', $rawSchedule);
    $selectedDate = $sParts[0] ?? '';
    $selectedTime = substr($sParts[1] ?? '', 0, 5);
}

$selectedCounselorId = $_POST['counselor_id'] ?? ($_GET['counselor_id'] ?? '');
$rightHeader = patient_header($uid, $token);
page_start('patient', 'Appointments', '/patient/appointment.php', 'Book & Manage Appointments', 'Schedule and track your counseling sessions.', $rightHeader);
?>
            <?php echo patient_search_modal($token); ?>
            <div class="card">
                <div class="card-head"><div><h2>Book an Appointment</h2><p class="card-sub">Your counselor will confirm the request</p></div></div>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/patient/appointment.php" id="appointmentForm">
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

                    <div class="field schedule-group">
                        <label>Appointment Schedule (Date &amp; Time)</label>
                        <div class="date-time-picker-grid">
                            <div class="picker-col">
                                <label for="appointment_date">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    Preferred Date
                                </label>
                                <input type="date" id="appointment_date" name="appointment_date" 
                                       min="<?php echo date('Y-m-d'); ?>" 
                                       max="<?php echo date('Y-m-d', strtotime('+' . (int) $settings['booking_window_days'] . ' days')); ?>" 
                                       value="<?php echo e($selectedDate); ?>" required onchange="handleDateChange()">
                                
                                <div id="date-status-msg" class="date-status-msg"></div>

                                <div class="date-quick-chips">
                                    <span class="date-chip-btn" onclick="setQuickDate('today')">Today</span>
                                    <span class="date-chip-btn" onclick="setQuickDate('tomorrow')">Tomorrow</span>
                                    <span class="date-chip-btn" onclick="setQuickDate('next_work')">Next Clinic Day</span>
                                </div>
                            </div>

                            <div class="picker-col">
                                <label for="appointment_time">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Session Time Slot
                                </label>
                                <select id="appointment_time" name="appointment_time" required onchange="handleTimeChange()">
                                    <option value="">-- Choose session time --</option>
                                    <?php foreach ($sessionSlots as $slot): ?>
                                        <option value="<?php echo e($slot['value']); ?>" <?php echo ($selectedTime === $slot['value']) ? 'selected' : ''; ?>>
                                            <?php echo e($slot['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size:12px;color:var(--muted);margin-top:6px;">Sessions last <?php echo (int) $sessionMin; ?> mins per student</div>
                            </div>
                        </div>

                        <!-- Hidden sync for legacy/fallback APIs -->
                        <input type="hidden" id="scheduled_at" name="scheduled_at" value="<?php echo e($rawSchedule); ?>">

                        <!-- Operating Hours Info Banner -->
                        <div class="schedule-banner-box">
                            <div class="schedule-banner-item">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span>Office Hours: <strong><?php echo e($settings['office_open']); ?>–<?php echo e($settings['office_close']); ?></strong></span>
                            </div>
                            <div class="schedule-banner-item">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>Clinic Days: <strong><?php echo e(implode(', ', array_map(fn($d) => substr($d, 0, 3), $workDays))); ?></strong></span>
                            </div>
                            <div class="schedule-banner-item">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                <span>Booking Window: Up to <strong><?php echo (int) $settings['booking_window_days']; ?> days ahead</strong></span>
                            </div>
                        </div>
                    </div>

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

            <script>
            (function() {
                const clinicWorkDays = <?php echo json_encode($workDays); ?>;

                window.handleDateChange = function() {
                    const dInput = document.getElementById('appointment_date');
                    const msgEl  = document.getElementById('date-status-msg');
                    const syncEl = document.getElementById('scheduled_at');
                    const tSelect= document.getElementById('appointment_time');
                    if (!dInput || !dInput.value) {
                        if (msgEl) msgEl.innerHTML = '';
                        return;
                    }

                    const parts = dInput.value.split('-').map(Number);
                    const dObj = new Date(parts[0], parts[1] - 1, parts[2]);
                    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    const dayName = days[dObj.getDay()];

                    if (msgEl) {
                        if (clinicWorkDays.includes(dayName)) {
                            msgEl.className = 'date-status-msg valid';
                            msgEl.innerHTML = '🟢 ' + dayName + ' — Guidance Office is open';
                        } else {
                            msgEl.className = 'date-status-msg invalid';
                            msgEl.innerHTML = '⚠️ Guidance Office is closed on ' + dayName + 's. Open days: ' + clinicWorkDays.join(', ');
                        }
                    }

                    if (syncEl && tSelect && tSelect.value) {
                        syncEl.value = dInput.value + 'T' + tSelect.value;
                    }
                };

                window.handleTimeChange = function() {
                    const dInput = document.getElementById('appointment_date');
                    const syncEl = document.getElementById('scheduled_at');
                    const tSelect= document.getElementById('appointment_time');
                    if (syncEl && dInput && dInput.value && tSelect && tSelect.value) {
                        syncEl.value = dInput.value + 'T' + tSelect.value;
                    }
                };

                window.setQuickDate = function(type) {
                    const dInput = document.getElementById('appointment_date');
                    if (!dInput) return;
                    const now = new Date();
                    let target = new Date();

                    if (type === 'today') {
                        target = now;
                    } else if (type === 'tomorrow') {
                        target.setDate(now.getDate() + 1);
                    } else if (type === 'next_work') {
                        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                        for (let i = 0; i < 7; i++) {
                            const check = new Date();
                            check.setDate(now.getDate() + i);
                            const dn = days[check.getDay()];
                            if (clinicWorkDays.includes(dn)) {
                                target = check;
                                break;
                            }
                        }
                    }

                    const yyyy = target.getFullYear();
                    const mm = String(target.getMonth() + 1).padStart(2, '0');
                    const dd = String(target.getDate()).padStart(2, '0');
                    dInput.value = `${yyyy}-${mm}-${dd}`;
                    window.handleDateChange();
                };

                document.addEventListener('DOMContentLoaded', function() {
                    if (window.handleDateChange) window.handleDateChange();
                });
            })();
            </script>
<?php page_end();?>