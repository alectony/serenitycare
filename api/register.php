<?php
require_once __DIR__ . '/../supabase.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($fullName) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } else {
        // Call Supabase registration helper
        $result = sb_register($email, $password, $fullName);

        if (isset($result['error'])) {
            $error = is_array($result['error']) ? ($result['error']['msg'] ?? 'Registration failed') : $result['error'];
        } else {
            $success = 'Registration successful! You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SerenityCare - Register</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Create Account</h2>
        
        <?php if (!empty($error)): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="/register.php" method="POST">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn-primary">Register</button>
        </form>

        <p style="text-align: center; margin-top: 1rem; font-size: 0.875rem; color: var(--text-muted);">
            Already have an account? <a href="/login.php" style="color: var(--primary); text-decoration: none;">Login here</a>
        </p>
    </div>
</body>
</html>
```[cite: 1, 2, 4, 6]

---

### 2. Verify Vercel Routes Mapping
Your `vercel.json` routing rules already map `/register.php` directly to `/api/register.php`[cite: 4]:
```json
{ "src": "^/register\\.php$", "dest": "/api/register.php" }