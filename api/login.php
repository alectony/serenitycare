<?php
require_once __DIR__ . '/../includes/supabase.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        // Authenticate via Supabase Auth REST API
        $authResult = sb_login($email, $password);

        if (isset($authResult['access_token'])) {
            $token = $authResult['access_token'];
            $userId = $authResult['user']['id'];

            setcookie('supabase_token', $token, [
                'expires' => time() + 86400,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            $profileResponse = sb_request('GET', 'profiles', 'id=eq.' . $userId, null, $token);
            
            // Default role to patient if profile table doesn't have a record yet
            $role = 'patient';
            if (!isset($profileResponse['http_code']) || $profileResponse['http_code'] === 200) {
                $profileData = isset($profileResponse[0]) ? $profileResponse[0] : ($profileResponse['data'][0] ?? null);
                if (isset($profileData['role'])) {
                    $role = $profileData['role'];
                }
            }

            if ($role === 'admin') {
                header('Location: /admin/');
            } elseif ($role === 'counselor') {
                header('Location: /counselor/');
            } else {
                header('Location: /patient/');
            }
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
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
        <?php if (!empty($error)): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="login.php">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-primary">Sign In</button>
        </form>
    </div>
</body>
</html>