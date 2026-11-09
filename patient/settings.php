<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

// Default patient preferences
$prefs = [
    'email_reminders'   => '1',
    'sms_reminders'     => '0',
    'reminder_lead'     => '24',
    'show_crisis_banner'=> '1',
    'privacy_pseudonym' => '0',
    'share_history'     => '1',
    'high_legibility'   => '0',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } else {
        $emailRemind = isset($_POST['email_reminders']) ? '1' : '0';
        $smsRemind = isset($_POST['sms_reminders']) ? '1' : '0';
        $lead = (int) ($_POST['reminder_lead'] ?? 24);
        $crisis = isset($_POST['show_crisis_banner']) ? '1' : '0';
        $pseudo = isset($_POST['privacy_pseudonym']) ? '1' : '0';
        $shareHist = isset($_POST['share_history']) ? '1' : '0';
        $legibility = isset($_POST['high_legibility']) ? '1' : '0';

        $newPrefs = [
            'email_reminders'   => $emailRemind,
            'sms_reminders'     => $smsRemind,
            'reminder_lead'     => (string) $lead,
            'show_crisis_banner'=> $crisis,
            'privacy_pseudonym' => $pseudo,
            'share_history'     => $shareHist,
            'high_legibility'   => $legibility,
        ];

        setcookie('patient_prefs_' . substr($uid, 0, 8), json_encode($newPrefs), [
            'expires'  => time() + (86400 * 365),
            'path'     => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure'   => is_https(),
        ]);

        audit_from($me, 'patient_settings_updated', 'patient_prefs', $uid, 'Updated reminder preferences, dark mode, and privacy controls.');
        $prefs = array_merge($prefs, $newPrefs);
        $msg = 'Your personal preferences and privacy options have been updated.';
    }
} else {
    $cookieName = 'patient_prefs_' . substr($uid, 0, 8);
    if (!empty($_COOKIE[$cookieName])) {
        $saved = json_decode($_COOKIE[$cookieName], true);
        if (is_array($saved)) {
            $prefs = array_merge($prefs, $saved);
        }
    }
}

$rightContent = patient_header($uid, $token);
page_start('patient', 'Settings', '/patient/settings.php', 'My Settings', 'Manage dark mode, appointment reminders, and student privacy controls.', $rightContent);
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="settings-hub">
                <!-- Settings Tabs -->
                <div class="settings-header-tabs">
                    <button type="button" class="settings-tab-btn active" data-target="patient-reminders"><?php echo icon('bell'); ?> Reminders &amp; Alerts</button>
                    <button type="button" class="settings-tab-btn" data-target="patient-ui"><?php echo icon('sparkles'); ?> Appearance &amp; UI</button>
                    <button type="button" class="settings-tab-btn" data-target="patient-privacy"><?php echo icon('shield'); ?> Privacy &amp; Anonymity</button>
                    <button type="button" class="settings-tab-btn" data-target="patient-support"><?php echo icon('user'); ?> Crisis &amp; Support</button>
                </div>

                <form method="POST" action="/patient/settings.php">
                    <?php echo csrf_field(); ?>

                    <!-- Tab 1: Reminders & Alerts -->
                    <div id="patient-reminders" class="settings-section-pane">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Session Reminders &amp; Scheduling Alerts</h3>
                                <p>Choose how and when you want to be reminded about your upcoming counseling appointments.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Email Appointment Reminders</div>
                                    <p class="settings-desc">Receive an email reminder before your scheduled session with the date, time, and counselor details.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="email_reminders" value="1"<?php echo ($prefs['email_reminders'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">SMS Text Message Reminders</div>
                                    <p class="settings-desc">Receive an SMS alert on your registered phone number prior to your appointment.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sms_reminders" value="1"<?php echo ($prefs['sms_reminders'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Reminder Timing Lead Time</div>
                                    <p class="settings-desc">How far in advance would you like to receive your appointment reminder notification?</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="reminder_lead">
                                        <option value="2" <?php echo ($prefs['reminder_lead'] === '2') ? 'selected' : ''; ?>>2 Hours Before</option>
                                        <option value="12" <?php echo ($prefs['reminder_lead'] === '12') ? 'selected' : ''; ?>>12 Hours Before</option>
                                        <option value="24" <?php echo ($prefs['reminder_lead'] === '24') ? 'selected' : ''; ?>>24 Hours Before (1 Day)</option>
                                        <option value="48" <?php echo ($prefs['reminder_lead'] === '48') ? 'selected' : ''; ?>>48 Hours Before (2 Days)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Appearance & Neumorphism UI -->
                    <div id="patient-ui" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Appearance &amp; Dark Mode</h3>
                                <p>Personalize your portal theme and experience the smooth neumorphic skeleton loading frames.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Dark Mode Theme</div>
                                    <p class="settings-desc">Switch between clean Silver/Blue-White neumorphism and calming Obsidian Slate dark mode for evening sessions.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="dark_mode_switch_patient" class="theme-toggle-input">
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Preview Skeleton Loading Frames</div>
                                    <p class="settings-desc">See the shimmering animated skeleton loading state designed to provide tactile feedback while records load.</p>
                                </div>
                                <div class="settings-action">
                                    <button type="button" class="btn btn-secondary" data-skeleton-preview>
                                        <?php echo icon('sparkles'); ?> Preview Skeletons
                                    </button>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Enhanced Text Legibility</div>
                                    <p class="settings-desc">Increase font contrast and spacing for clearer readability across diagnostic results.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="high_legibility" value="1"<?php echo ($prefs['high_legibility'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Privacy & Anonymity -->
                    <div id="patient-privacy" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Student Privacy &amp; Anonymity Controls</h3>
                                <p>Your mental health confidentiality is strictly protected by ethical counseling standards and data regulations.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Display Name Anonymity During Booking</div>
                                    <p class="settings-desc">Show only your initials and grade level to counselors until your appointment request has been officially accepted.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="privacy_pseudonym" value="1"<?php echo ($prefs['privacy_pseudonym'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Counseling History Continuity</div>
                                    <p class="settings-desc">Allow newly booked guidance counselors to view previously shared diagnostic summaries to ensure continuous care.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="share_history" value="1"<?php echo ($prefs['share_history'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 4: Crisis & Support -->
                    <div id="patient-support" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>24/7 Crisis Hotline &amp; Emergency Support</h3>
                                <p>Immediate resources available anytime you need urgent guidance or mental health support.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Display Crisis Hotline Banner on Dashboard</div>
                                    <p class="settings-desc">Keep the 24/7 guidance and mental health crisis hotline shortcut readily available on your dashboard overview.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="show_crisis_banner" value="1"<?php echo ($prefs['show_crisis_banner'] === '1') ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="note-box" style="margin-top: 14px;">
                                <strong>Always Remember</strong>
                                <small>If you or someone you know is in immediate danger or experiencing an emotional crisis, you can reach the National Center for Mental Health (NCMH) Crisis Hotline at 1553 or 0917-899-8727 (USAP) 24/7.</small>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn">Save Preferences</button>
                    </div>
                </form>
            </div>
<?php page_end(); ?>
