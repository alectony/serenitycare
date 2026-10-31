<?php
require_once __DIR__ . '/../includes/auth.php';

$error = '';

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

            setcookie('supabase_token', $token, [
                'expires' => time() + 3600,   // Supabase tokens last 1 hour by default
                'path' => '/',
                'secure' => is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $profile = get_user_profile($userId, $token);
            header('Location: ' . home_for_role($profile['role'] ?? 'patient'));
            exit;
        }
        $error = $auth['message'] ?? 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SerenityCare</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>SerenityCare Login</h2>
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
            <button type="button" class="btn-secondary" onclick="window.location.href='/register.php'">Register</button>
        </form>
    </div>
</body>
</html>