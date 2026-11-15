<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/otp.php';

$error = '';
$msg = '';
$loginMode = $_GET['mode'] ?? ($_POST['login_mode'] ?? 'password');
$otpStep = $_POST['otp_step'] ?? 'send';
$email = strtolower(trim($_POST['email'] ?? ($_GET['email'] ?? '')));

if (isset($_GET['inactive'])) {
    $error = 'This account has been deactivated. Please contact an administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($loginMode === 'otp') {
        if ($otpStep === 'send') {
            if ($email === '') {
                $error = 'Please enter your registered email address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address (e.g., student@school.edu.ph or name@gmail.com).';
            } else {
                $res = dispatch_otp($email, 'login');
                if ($res['success']) {
                    $msg = $res['message'];
                    $otpStep = 'verify';
                } else {
                    $error = $res['message'];
                }
            }
        } elseif ($otpStep === 'verify') {
            $otp = trim($_POST['otp'] ?? '');
            if ($email === '' || $otp === '') {
                $error = 'Please enter both your email and the verification code.';
                $otpStep = 'verify';
            } else {
                // Try Supabase OTP verification first (supports both 6 and 8 digit tokens)
                $valid = false;
                $sbToken = null;
                $sbUser = null;

                $sbRes = sb_verify_otp($email, $otp, 'email');
                if (($sbRes['status'] === 200 || $sbRes['status'] === 201) && !empty($sbRes['data']['access_token'])) {
                    $valid = true;
                    $sbToken = $sbRes['data']['access_token'];
                    $sbUser = $sbRes['data']['user'] ?? null;
                }

                // If Supabase didn't verify, try local OTP store
                if (!$valid) {
                    $valid = verify_local_otp($email, $otp, 'login');
                }

                if ($valid) {
                    // Look up profile by email
                    $c = get_supabase_config();
                    $find = sb_http('GET', $c['url'] . '/rest/v1/profiles?email=eq.' . urlencode($email), [
                        'apikey' => $c['anon_key'],
                    ]);
                    $profile = ($find['status'] === 200 && !empty($find['data'][0])) ? $find['data'][0] : null;

                    if ($profile && ($profile['is_active'] ?? true) === false) {
                        $error = 'This account has been deactivated. Please contact an administrator.';
                        $otpStep = 'send';
                    } elseif ($profile) {
                        // Generate or use token
                        $token = $sbToken ?: ('otp_' . $profile['id'] . '_' . bin2hex(random_bytes(16)));
                        setcookie('supabase_token', $token, [
                            'expires'  => time() + 3600 * 2,
                            'path'     => '/',
                            'secure'   => is_https(),
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                        audit_log($token, $profile['id'], $profile['full_name'] ?? $email, 'login_otp', 'user', $profile['id']);
                        header('Location: ' . home_for_role($profile['role'] ?? 'patient'));
                        exit;
                    } else {
                        $error = 'No registered SerenityCare account found with this email.';
                        $otpStep = 'send';
                    }
                } else {
                    $error = 'Invalid or expired OTP code. Please try again.';
                    $otpStep = 'verify';
                }
            }
        }
    } else {
        // Standard Password Login
        $password = $_POST['password'] ?? '';
        if ($email === '' || $password === '') {
            $error = 'Please fill in all fields.';
        } else {
            $auth = sb_login($email, $password);

            if (isset($auth['access_token'])) {
                $token = $auth['access_token'];
                $userId = $auth['user']['id'];
                $profile = get_user_profile($userId, $token);

                if (($profile['is_active'] ?? true) === false) {
                    $error = 'This account has been deactivated. Please contact an administrator.';
                } else {
                    setcookie('supabase_token', $token, [
                        'expires'  => time() + 3600 * 4,
                        'path'     => '/',
                        'secure'   => is_https(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    audit_log($token, $userId, $profile['full_name'] ?? '', 'login');
                    header('Location: ' . home_for_role($profile['role'] ?? 'patient'));
                    exit;
                }
            } else {
                $error = $auth['message'] ?? 'Invalid email or password.';
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
    <title>Login - SerenityCare</title>
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

        <!-- Login Method Tabs -->
        <div class="tabs" style="width:100%;display:flex;margin-bottom:20px;">
            <a href="/login.php?mode=password" class="tab <?php echo $loginMode === 'password' ? 'active' : ''; ?>" style="flex:1;text-align:center;">
                Password
            </a>
            <a href="/login.php?mode=otp" class="tab <?php echo $loginMode === 'otp' ? 'active' : ''; ?>" style="flex:1;text-align:center;">
                One-Time PIN (OTP)
            </a>
        </div>

        <h2>Welcome back</h2>
        <p class="auth-sub"><?php echo $loginMode === 'otp' ? 'Sign in quickly with an email verification code.' : 'Sign in to continue to your guidance portal.'; ?></p>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($msg !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if ($loginMode === 'otp'): ?>
            <!-- OTP Form -->
            <form method="POST" action="/login.php?mode=otp">
                <input type="hidden" name="login_mode" value="otp">
                <input type="hidden" name="otp_step" value="<?php echo htmlspecialchars($otpStep); ?>">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="name@domain.edu" value="<?php echo htmlspecialchars($email); ?>" <?php echo $otpStep === 'verify' ? 'readonly' : ''; ?>>
                </div>

                <?php if ($otpStep === 'verify'): ?>
                    <div class="form-group">
                        <label for="otp">Enter Verification Code (OTP)</label>
                        <input type="text" id="otp" name="otp" required minlength="6" maxlength="8" pattern="[0-9]{6,8}" placeholder="6 or 8-digit code" autocomplete="one-time-code" style="letter-spacing:4px;font-size:18px;font-weight:700;text-align:center;">
                        <small style="display:block;margin-top:6px;font-size:12px;color:var(--muted);text-align:center;">Please check your email inbox and spam folder for your code.</small>
                    </div>
                    <button type="submit" class="btn-primary" style="width:100%;">Verify &amp; Sign In</button>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:13px;">
                        <a href="/login.php?mode=otp&email=<?php echo urlencode($email); ?>" class="link">&larr; Change Email / Resend</a>
                        <a href="/register.php" class="link">Create an account</a>
                    </div>
                <?php else: ?>
                    <button type="submit" class="btn-primary" style="width:100%;">Send One-Time Password</button>
                    <a href="/register.php" class="btn-secondary">Create an account</a>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <!-- Standard Password Form -->
            <form method="POST" action="/login.php">
                <input type="hidden" name="login_mode" value="password">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($email); ?>">
                </div>
                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label for="password" style="margin:0;">Password</label>
                        <a href="/forgot-password.php" class="link" style="font-size:12.5px;">Forgot password?</a>
                    </div>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn-primary">Sign In</button>
                <a href="/register.php" class="btn-secondary">Create an account</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
