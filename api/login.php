<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

$error = '';
if (isset($_GET['inactive'])) {
    $error = 'This account has been deactivated. Please contact an administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
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
                    'expires' => time() + 3600,   // Supabase tokens last 1 hour by default
                    'path' => '/',
                    'secure' => is_https(),
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
            <div class="auth-brand" style="margin-bottom:0;"><span class="brand-mark">S</span><span>SerenityCare</span></div>
            <button type="button" class="theme-toggle-btn" data-theme-toggle title="Toggle Dark/Light Mode" aria-label="Toggle Dark/Light Mode">
                <svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>
        </div>
        <h2>Welcome back</h2>
        <p class="auth-sub">Sign in to continue to your portal.</p>
        <?php if ($error !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="/login.php">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-primary">Sign In</button>
            <a href="/register.php" class="btn-secondary">Create an account</a>
        </form>
    </div>
</body>
</html>
