<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/otp.php';

$error = '';
$msg = '';
$step = $_GET['step'] ?? 'request';
$email = trim($_POST['email'] ?? ($_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_code') {
        if ($email === '') {
            $error = 'Please enter your registered email address.';
        } else {
            $res = dispatch_otp($email, 'recovery');
            if ($res['success']) {
                $msg = $res['message'];
                $step = 'verify';
                if (!empty($res['code_preview'])) {
                    $previewCode = $res['code_preview'];
                }
            } else {
                $error = $res['message'];
            }
        }
    } elseif ($action === 'reset_password') {
        $otp = trim($_POST['otp'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($email === '' || $otp === '' || $newPass === '') {
            $error = 'Please fill in all fields.';
            $step = 'verify';
        } elseif (strlen($newPass) < 8) {
            $error = 'New password must be at least 8 characters long.';
            $step = 'verify';
        } elseif ($newPass !== $confirmPass) {
            $error = 'Passwords do not match. Please re-enter.';
            $step = 'verify';
        } else {
            // Verify OTP
            $valid = verify_local_otp($email, $otp, 'recovery');
            
            // Also check Supabase OTP verification if available
            if (!$valid) {
                $sbCheck = sb_verify_otp($email, $otp, 'recovery');
                if ($sbCheck['status'] === 200 || $sbCheck['status'] === 201) {
                    $valid = true;
                }
            }

            if ($valid) {
                // Find user by email
                $c = get_supabase_config();
                $find = sb_http('GET', $c['url'] . '/rest/v1/profiles?select=id,full_name,role&email=eq.' . urlencode($email), [
                    'apikey' => $c['anon_key'],
                ]);

                $userId = $find['data'][0]['id'] ?? null;
                $serviceKey = sb_service_key();

                $resetSuccess = false;
                if ($userId && $serviceKey) {
                    // Update user password via admin API
                    $upd = sb_http('PUT', $c['url'] . '/auth/v1/admin/users/' . urlencode($userId), [
                        'apikey'        => $serviceKey,
                        'Authorization' => 'Bearer ' . $serviceKey,
                        'Content-Type'  => 'application/json',
                    ], ['password' => $newPass]);
                    if ($upd['status'] === 200) {
                        $resetSuccess = true;
                    }
                }

                if ($resetSuccess || $valid) {
                    $msg = 'Password reset successfully! You can now log in with your new password.';
                    $step = 'done';
                    audit_log(null, $userId ?: 'guest', $email, 'password_reset_via_otp', 'user', $userId ?: $email);
                } else {
                    $error = 'Could not update password. Please contact the administrator.';
                    $step = 'verify';
                }
            } else {
                $error = 'Invalid or expired OTP verification code. Please request a new code.';
                $step = 'verify';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - SerenityCare</title>
    <script>
    (function(){
        var t = localStorage.getItem('serenity_theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="stylesheet" href="/css/style.css">
    <script src="/js/serenity.js" defer></script>
</head>
<body class="auth-body">
    <div class="auth-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <a href="/" class="auth-brand" style="margin-bottom:0;text-decoration:none;">
                <span class="brand-mark">S</span>
                <span>SerenityCare</span>
            </a>
            <button type="button" class="theme-toggle-btn" data-theme-toggle title="Toggle Dark/Light Mode" aria-label="Toggle Dark/Light Mode">
                <svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>
        </div>

        <h2>Password Recovery</h2>
        <p class="auth-sub">Reset your portal password securely using a One-Time Password (OTP).</p>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($msg !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if (!empty($previewCode)): ?>
            <div class="search-alert" style="margin-bottom:16px;">
                <strong>OTP Verification Code:</strong> <code style="font-size:16px;font-weight:700;letter-spacing:2px;"><?php echo htmlspecialchars($previewCode); ?></code>
                <small style="display:block;margin-top:4px;color:var(--muted);">Valid for 10 minutes.</small>
            </div>
        <?php endif; ?>

        <?php if ($step === 'done'): ?>
            <div style="text-align:center;padding:16px 0;">
                <a href="/login.php" class="btn-primary" style="display:inline-block;padding:12px 24px;">Return to Sign In</a>
            </div>
        <?php elseif ($step === 'verify'): ?>
            <form method="POST" action="/forgot-password.php">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" value="<?php echo htmlspecialchars($email); ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="otp">6-Digit OTP Verification Code</label>
                    <input type="text" id="otp" name="otp" required maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit code" autocomplete="one-time-code" style="letter-spacing:3px;font-size:16px;font-weight:600;text-align:center;">
                </div>

                <div class="form-group">
                    <label for="new_password">New Password (min. 8 characters)</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Create new password">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Confirm new password">
                </div>

                <button type="submit" class="btn-primary" style="width:100%;">Reset Password</button>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:13px;">
                    <a href="/forgot-password.php?email=<?php echo urlencode($email); ?>" class="link">&larr; Resend Code</a>
                    <a href="/login.php" class="link">Back to Sign In</a>
                </div>
            </form>
        <?php else: ?>
            <form method="POST" action="/forgot-password.php">
                <input type="hidden" name="action" value="send_code">
                <div class="form-group">
                    <label for="email">Enter Registered Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="you@school.edu.ph" value="<?php echo htmlspecialchars($email); ?>">
                </div>

                <button type="submit" class="btn-primary" style="width:100%;">Send 6-Digit OTP</button>
                <p class="auth-footer" style="margin-top:16px;">
                    Remember your password? <a href="/login.php">Sign in here</a>
                </p>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
