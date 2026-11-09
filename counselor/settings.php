<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

// Load counselor details to preserve/read existing preferences
$cDet = sb_request('GET', 'counselor_details', 'counselor_id=eq.' . urlencode($uid), null, $token);
$counselorDetails = ($cDet['status'] === 200 && !empty($cDet['data'][0])) ? $cDet['data'][0] : [];

// Default counselor preferences
$prefs = [
    'buffer_time'       => '15',
    'max_daily_appts'   => '6',
    'notice_hours'      => '4',
    'crisis_priority'   => '1',
    'notes_autosave'    => '30',
    'auto_share_dx'     => '0',
    'sound_alerts'      => '1',
    'email_alerts'      => '1',
    'session_reminders' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } else {
        $buffer = (int) ($_POST['buffer_time'] ?? 15);
        $maxAppts = (int) ($_POST['max_daily_appts'] ?? 6);
        $notice = (int) ($_POST['notice_hours'] ?? 4);
        $crisis = isset($_POST['crisis_priority']) ? '1' : '0';
        $autosave = (int) ($_POST['notes_autosave'] ?? 30);
        $shareDx = isset($_POST['auto_share_dx']) ? '1' : '0';
        $sound = isset($_POST['sound_alerts']) ? '1' : '0';
        $email = isset($_POST['email_alerts']) ? '1' : '0';
        $remind = isset($_POST['session_reminders']) ? '1' : '0';

        $newPrefs = [
            'buffer_time'       => (string) $buffer,
            'max_daily_appts'   => (string) $maxAppts,
            'notice_hours'      => (string) $notice,
            'crisis_priority'   => $crisis,
            'notes_autosave'    => (string) $autosave,
            'auto_share_dx'     => $shareDx,
            'sound_alerts'      => $sound,
            'email_alerts'      => $email,
            'session_reminders' => $remind,
        ];

        // Store counselor preferences in cookie and audit log
        setcookie('counselor_prefs_' . substr($uid, 0, 8), json_encode($newPrefs), [
            'expires'  => time() + (86400 * 365),
            'path'     => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure'   => is_https(),
        ]);

        audit_from($me, 'counselor_settings_updated', 'counselor_prefs', $uid, 'Updated clinical buffer, notifications, and documentation preferences.');
        $prefs = array_merge($prefs, $newPrefs);
        $msg = 'Counselor preferences and schedule buffers saved successfully.';
    }
} else {
    // Read from cookie if previously saved
    $cookieName = 'counselor_prefs_' . substr($uid, 0, 8);
    if (!empty($_COOKIE[$cookieName])) {
        $saved = json_decode($_COOKIE[$cookieName], true);
        if (is_array($saved)) {
            $prefs = array_merge($prefs, $saved);
        }
    }
}

// Counselor header
$fullName = $me['profile']['full_name'] ?? 'Counselor';
$nameParts = explode(' ', $fullName);
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

$rightContent = '
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

page_start('counselor', 'Settings', '/counselor/settings.php', 'Counselor Settings', 'Manage your clinical availability buffer, dark mode, note privacy, and notification preferences.', $rightContent);
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="settings-hub">
                <!-- Settings Tabs -->
                <div class="settings-header-tabs">
                    <button type="button" class="settings-tab-btn active" data-target="counselor-schedule"><?php echo icon('clock'); ?> Clinical Schedule</button>
                    <button type="button" class="settings-tab-btn" data-target="counselor-ui"><?php echo icon('sparkles'); ?> Appearance &amp; UI</button>
                    <button type="button" class="settings-tab-btn" data-target="counselor-notes"><?php echo icon('file'); ?> Notes &amp; Privacy</button>
                    <button type="button" class="settings-tab-btn" data-target="counselor-alerts"><?php echo icon('bell'); ?> Alerts &amp; Sounds</button>
                </div>

                <form method="POST" action="/counselor/settings.php">
                    <?php echo csrf_field(); ?>

                    <!-- Tab 1: Clinical Schedule & Buffers -->
                    <div id="counselor-schedule" class="settings-section-pane">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Clinical Schedule &amp; Intake Buffer</h3>
                                <p>Control appointment pacing, recovery intervals between students, and capacity limits.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Buffer Time Between Appointments</div>
                                    <p class="settings-desc">Enforce a restful decompression and note-writing buffer between consecutive sessions.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="buffer_time">
                                        <option value="0" <?php echo ($prefs['buffer_time'] === '0') ? 'selected' : ''; ?>>No Buffer (Back-to-back)</option>
                                        <option value="10" <?php echo ($prefs['buffer_time'] === '10') ? 'selected' : ''; ?>>10 Minutes</option>
                                        <option value="15" <?php echo ($prefs['buffer_time'] === '15') ? 'selected' : ''; ?>>15 Minutes (Recommended)</option>
                                        <option value="20" <?php echo ($prefs['buffer_time'] === '20') ? 'selected' : ''; ?>>20 Minutes</option>
                                        <option value="30" <?php echo ($prefs['buffer_time'] === '30') ? 'selected' : ''; ?>>30 Minutes</option>
                                    </select>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Daily Session Capacity Cap</div>
                                    <p class="settings-desc">Maximum number of counseling sessions scheduled per day to safeguard practitioner focus and care quality.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="max_daily_appts">
                                        <option value="4" <?php echo ($prefs['max_daily_appts'] === '4') ? 'selected' : ''; ?>>4 Sessions / Day</option>
                                        <option value="6" <?php echo ($prefs['max_daily_appts'] === '6') ? 'selected' : ''; ?>>6 Sessions / Day</option>
                                        <option value="8" <?php echo ($prefs['max_daily_appts'] === '8') ? 'selected' : ''; ?>>8 Sessions / Day</option>
                                        <option value="0" <?php echo ($prefs['max_daily_appts'] === '0') ? 'selected' : ''; ?>>Unlimited Capacity</option>
                                    </select>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Minimum Advance Booking Notice</div>
                                    <p class="settings-desc">Require students to request appointments at least this many hours in advance.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="notice_hours">
                                        <option value="2" <?php echo ($prefs['notice_hours'] === '2') ? 'selected' : ''; ?>>2 Hours Notice</option>
                                        <option value="4" <?php echo ($prefs['notice_hours'] === '4') ? 'selected' : ''; ?>>4 Hours Notice</option>
                                        <option value="12" <?php echo ($prefs['notice_hours'] === '12') ? 'selected' : ''; ?>>12 Hours Notice</option>
                                        <option value="24" <?php echo ($prefs['notice_hours'] === '24') ? 'selected' : ''; ?>>24 Hours (Next Day)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Urgent / Crisis Request Prioritization</div>
                                    <p class="settings-desc">Pin student requests tagged with urgent mental wellness distress to the top of your review queue.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="crisis_priority" value="1"<?php echo ($prefs['crisis_priority'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Appearance & Neumorphism UI -->
                    <div id="counselor-ui" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Neumorphic Workspace &amp; Dark Mode</h3>
                                <p>Tailor lighting, contrast, and test skeleton loading frames across your dashboard.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Dark Mode Theme</div>
                                    <p class="settings-desc">Toggle between soothing Silver/Blue-White neumorphism and deep Obsidian Slate dark mode.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="dark_mode_switch_counselor" class="theme-toggle-input">
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Test Neumorphic Skeleton Loading</div>
                                    <p class="settings-desc">Preview animated shimmering skeleton frames that display while patient lists and schedules load.</p>
                                </div>
                                <div class="settings-action">
                                    <button type="button" class="btn btn-secondary" data-skeleton-preview>
                                        <?php echo icon('sparkles'); ?> Preview Skeleton Frames
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Notes & Privacy -->
                    <div id="counselor-notes" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Clinical Documentation &amp; Privacy</h3>
                                <p>Safeguard student diagnosis records and streamline clinical documentation routines.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Auto-Save Draft Session Notes</div>
                                    <p class="settings-desc">Periodically auto-save consultation notes in progress to protect against accidental browser closing.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="notes_autosave">
                                        <option value="15" <?php echo ($prefs['notes_autosave'] === '15') ? 'selected' : ''; ?>>Every 15 Seconds</option>
                                        <option value="30" <?php echo ($prefs['notes_autosave'] === '30') ? 'selected' : ''; ?>>Every 30 Seconds</option>
                                        <option value="60" <?php echo ($prefs['notes_autosave'] === '60') ? 'selected' : ''; ?>>Every 1 Minute</option>
                                        <option value="0" <?php echo ($prefs['notes_autosave'] === '0') ? 'selected' : ''; ?>>Manual Save Only</option>
                                    </select>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Default Diagnostic Result Sharing</div>
                                    <p class="settings-desc">When creating a session diagnosis, leave "Share summary with patient" unchecked by default to preserve strict privacy.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="auto_share_dx" value="1"<?php echo ($prefs['auto_share_dx'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 4: Alerts & Reminders -->
                    <div id="counselor-alerts" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Alerts &amp; Reminders</h3>
                                <p>Manage notification triggers when student requests arrive or sessions are approaching.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Audio Notification Chime</div>
                                    <p class="settings-desc">Play a gentle auditory chime in the browser when a new student booking request arrives.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sound_alerts" value="1"<?php echo ($prefs['sound_alerts'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Email Notifications for Booking Requests</div>
                                    <p class="settings-desc">Receive an instant email summary whenever a student requests or cancels an appointment.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="email_alerts" value="1"<?php echo ($prefs['email_alerts'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">15-Minute Upcoming Session Reminder</div>
                                    <p class="settings-desc">Surface an in-portal banner 15 minutes before an upcoming student consultation.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="session_reminders" value="1"<?php echo ($prefs['session_reminders'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn">Save Counselor Preferences</button>
                    </div>
                </form>
            </div>
<?php page_end(); ?>
