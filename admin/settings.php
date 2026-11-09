<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];
$msg = '';
$err = '';
$allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

$settings = get_settings($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $open = $_POST['office_open'] ?? '';
    $close = $_POST['office_close'] ?? '';
    $days = array_values(array_intersect($allDays, (array) ($_POST['working_days'] ?? [])));
    $session = (int) ($_POST['session_minutes'] ?? 0);
    $window = (int) ($_POST['booking_window_days'] ?? 0);
    $timeout = (int) ($_POST['idle_timeout'] ?? 30);
    $req2fa = isset($_POST['require_2fa']) ? '1' : '0';
    $emailNotif = isset($_POST['email_notifications']) ? '1' : '0';
    $smsRemind = isset($_POST['sms_reminders']) ? '1' : '0';
    $auditRet = (int) ($_POST['audit_retention'] ?? 90);
    $regMode = in_array($_POST['patient_reg_mode'] ?? '', ['open', 'invite'], true) ? $_POST['patient_reg_mode'] : 'open';
    $emgBanner = isset($_POST['emergency_banner']) ? '1' : '0';

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close)) {
        $err = 'Please enter valid opening and closing times.';
    } elseif (strcmp($close, $open) <= 0) {
        $err = 'Closing time must be after opening time.';
    } elseif (!$days) {
        $err = 'Choose at least one working day.';
    } elseif ($session < 15 || $session > 180) {
        $err = 'Session length must be between 15 and 180 minutes.';
    } elseif ($window < 1 || $window > 365) {
        $err = 'Booking window must be between 1 and 365 days.';
    } else {
        $new = [
            'office_open'         => $open,
            'office_close'        => $close,
            'working_days'        => implode(',', $days),
            'session_minutes'     => (string) $session,
            'booking_window_days' => (string) $window,
            'idle_timeout'        => (string) $timeout,
            'require_2fa'         => $req2fa,
            'email_notifications' => $emailNotif,
            'sms_reminders'       => $smsRemind,
            'audit_retention'     => (string) $auditRet,
            'patient_reg_mode'    => $regMode,
            'emergency_banner'    => $emgBanner,
        ];

        $rows = [];
        foreach ($new as $k => $v) {
            $rows[] = ['key' => $k, 'value' => $v, 'updated_by' => $me['user']['id'], 'updated_at' => date('c')];
        }

        $r = sb_request('POST', 'system_settings', 'on_conflict=key', $rows, $token, 'resolution=merge-duplicates,return=minimal');
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $changed = [];
            foreach ($new as $k => $v) { if (($settings[$k] ?? '') !== $v) { $changed[] = $k; } }
            audit_from($me, 'settings_updated', 'settings', '', $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved without changes');
            $settings = array_merge($settings, $new);
            $msg = 'System settings updated and saved successfully.';
        } else {
            $err = 'Could not save the settings. Please verify database connectivity.';
        }
    }
}
$activeDays = settings_days($settings);

page_start('admin', 'System Settings', '/admin/settings.php', 'System Settings', 'Configure institutional booking rules, security policies, appearance, and alerts.');
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="settings-hub">
                <!-- Settings Navigation Tabs -->
                <div class="settings-header-tabs">
                    <button type="button" class="settings-tab-btn active" data-target="tab-booking"><?php echo icon('calendar'); ?> Booking &amp; Clinic</button>
                    <button type="button" class="settings-tab-btn" data-target="tab-appearance"><?php echo icon('sparkles'); ?> Appearance &amp; UI</button>
                    <button type="button" class="settings-tab-btn" data-target="tab-security"><?php echo icon('shield'); ?> Security &amp; Access</button>
                    <button type="button" class="settings-tab-btn" data-target="tab-notifications"><?php echo icon('bell'); ?> Notifications</button>
                </div>

                <form method="POST" action="/admin/settings.php">
                    <?php echo csrf_field(); ?>

                    <!-- Tab 1: Booking Rules -->
                    <div id="tab-booking" class="settings-section-pane">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Booking &amp; Office Hours</h3>
                                <p>Set clinic hours and appointment windows visible to students and faculty.</p>
                            </div>

                            <div class="form-row">
                                <div class="field grow">
                                    <label for="office_open">Office Opens</label>
                                    <input type="time" id="office_open" name="office_open" value="<?php echo e($settings['office_open'] ?? '08:00'); ?>" required>
                                </div>
                                <div class="field grow">
                                    <label for="office_close">Office Closes</label>
                                    <input type="time" id="office_close" name="office_close" value="<?php echo e($settings['office_close'] ?? '17:00'); ?>" required>
                                </div>
                                <div class="field grow">
                                    <label for="session_minutes">Session Length (Minutes)</label>
                                    <input type="number" id="session_minutes" name="session_minutes" min="15" max="180" step="5" value="<?php echo e($settings['session_minutes'] ?? '50'); ?>" required>
                                </div>
                                <div class="field grow">
                                    <label for="booking_window_days">Advance Booking Window (Days)</label>
                                    <input type="number" id="booking_window_days" name="booking_window_days" min="1" max="365" value="<?php echo e($settings['booking_window_days'] ?? '60'); ?>" required>
                                </div>
                            </div>

                            <fieldset class="day-picker">
                                <legend>Working Days</legend>
                                <?php foreach ($allDays as $d): ?>
                                    <label class="check-chip">
                                        <input type="checkbox" name="working_days[]" value="<?php echo e($d); ?>"<?php echo in_array($d, $activeDays, true) ? ' checked' : ''; ?>>
                                        <?php echo e($d); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Emergency Crisis Banner</div>
                                    <p class="settings-desc">Display the immediate 24/7 Guidance &amp; Suicide Prevention hotline banner on patient dashboards.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="emergency_banner" value="1"<?php echo ($settings['emergency_banner'] ?? '1') === '1' ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Appearance & Neumorphism UI -->
                    <div id="tab-appearance" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Neumorphic Interface &amp; Theme</h3>
                                <p>Personalize display preferences, tactile contrast, and preview skeleton loading frames.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Dark Mode</div>
                                    <p class="settings-desc">Switch between professional Silver/Blue-White neumorphism and deep Obsidian Slate dark mode.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="dark_mode_switch" class="theme-toggle-input">
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Neumorphic Skeleton Loading Preview</div>
                                    <p class="settings-desc">Test and preview shimmering neumorphic skeleton placeholder frames across all cards, stats, and tables.</p>
                                </div>
                                <div class="settings-action">
                                    <button type="button" class="btn btn-secondary" data-skeleton-preview>
                                        <?php echo icon('sparkles'); ?> Test Skeleton Frames
                                    </button>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Neumorphic Surface Styling</div>
                                    <p class="settings-desc">Soft extruded bevels and dual lighting highlights optimized for clinical clarity and reduced eye strain.</p>
                                </div>
                                <div class="settings-action">
                                    <span class="status active">Active &bull; Soft Silver/Slate</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Security & Session Management -->
                    <div id="tab-security" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Security &amp; Access Controls</h3>
                                <p>Enforce session timeouts, administrative two-factor authentication, and user enrollment rules.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Enforce Two-Factor Authentication (2FA) for Staff</div>
                                    <p class="settings-desc">Require administrators and counselors to complete 2FA verification upon signing in.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="require_2fa" value="1"<?php echo ($settings['require_2fa'] ?? '0') === '1' ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Idle Session Timeout</div>
                                    <p class="settings-desc">Automatically log out inactive portal sessions to protect patient confidentiality.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="idle_timeout">
                                        <option value="15" <?php echo ($settings['idle_timeout'] ?? '') === '15' ? 'selected' : ''; ?>>15 Minutes</option>
                                        <option value="30" <?php echo ($settings['idle_timeout'] ?? '') === '30' ? 'selected' : ''; ?>>30 Minutes (Recommended)</option>
                                        <option value="60" <?php echo ($settings['idle_timeout'] ?? '') === '60' ? 'selected' : ''; ?>>60 Minutes</option>
                                        <option value="120" <?php echo ($settings['idle_timeout'] ?? '') === '120' ? 'selected' : ''; ?>>2 Hours</option>
                                    </select>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Patient Registration Policy</div>
                                    <p class="settings-desc">Control whether students and teachers can self-register or require a counselor invite.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="patient_reg_mode">
                                        <option value="open" <?php echo ($settings['patient_reg_mode'] ?? '') === 'open' ? 'selected' : ''; ?>>Open Self-Registration</option>
                                        <option value="invite" <?php echo ($settings['patient_reg_mode'] ?? '') === 'invite' ? 'selected' : ''; ?>>Invitation Code Only</option>
                                    </select>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Audit Trail Retention</div>
                                    <p class="settings-desc">Number of days security and access audit events are preserved in the system log.</p>
                                </div>
                                <div class="settings-action" style="min-width: 170px;">
                                    <select name="audit_retention">
                                        <option value="30" <?php echo ($settings['audit_retention'] ?? '') === '30' ? 'selected' : ''; ?>>30 Days</option>
                                        <option value="90" <?php echo ($settings['audit_retention'] ?? '') === '90' ? 'selected' : ''; ?>>90 Days</option>
                                        <option value="180" <?php echo ($settings['audit_retention'] ?? '') === '180' ? 'selected' : ''; ?>>180 Days</option>
                                        <option value="365" <?php echo ($settings['audit_retention'] ?? '') === '365' ? 'selected' : ''; ?>>1 Year</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 4: Notifications & Automated Alerts -->
                    <div id="tab-notifications" class="settings-section-pane" style="display:none;">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3>Automated Notifications &amp; Alerts</h3>
                                <p>Manage automated emails and SMS dispatch gateways for appointments and updates.</p>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">Email Notifications for New Bookings</div>
                                    <p class="settings-desc">Automatically dispatch an email confirmation to patients and counselors when a slot is requested.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="email_notifications" value="1"<?php echo ($settings['email_notifications'] ?? '1') === '1' ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-info">
                                    <div class="settings-title">SMS Appointment Reminders</div>
                                    <p class="settings-desc">Send 24-hour SMS reminder texts to students for confirmed counseling sessions.</p>
                                </div>
                                <div class="settings-action">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sms_reminders" value="1"<?php echo ($settings['sms_reminders'] ?? '0') === '1' ? ' checked' : ''; ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn">Save All Settings</button>
                    </div>
                </form>
            </div>
<?php page_end(); ?>
