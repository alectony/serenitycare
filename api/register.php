<?php
require_once __DIR__ . '/../includes/supabase.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $gradeOrLevel = trim($_POST['grade_or_dept'] ?? '');

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        // Everyone who signs up through the portal registration is a student
        $extra = [
            'patient_type'  => 'student',
            'grade_or_dept' => $gradeOrLevel !== '' ? mb_substr($gradeOrLevel, 0, 100) : null,
        ];

        $result = sb_register($email, $password, $fullName, $extra);

        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $uid = $result['user']['id'] ?? ($result['id'] ?? '');
            $validUid = preg_match('/^[0-9a-f-]{36}$/i', (string) $uid) === 1;

            $profile = [
                'email'         => $email,
                'full_name'     => $fullName,
                'role'          => 'patient',
                'is_active'     => true,
                'patient_type'  => 'student',
                'grade_or_dept' => $gradeOrLevel !== '' ? mb_substr($gradeOrLevel, 0, 100) : null,
            ];

            if ($validUid) {
                sb_service_upsert_profile($uid, $profile);
            }
            $success = 'Account created successfully! You can now log in to access your student portal.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - SerenityCare</title>
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
        <h2>Create student account</h2>
        <p class="auth-sub">Your confidential space for guidance and mental wellness support.</p>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="/register.php" method="POST">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required placeholder="e.g. Maria Santos" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">School or Personal Email Address</label>
                <input type="email" id="email" name="email" required placeholder="student@school.edu.ph" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="grade_or_dept">Grade / Year Level (optional)</label>
                <input type="text" id="grade_or_dept" name="grade_or_dept" maxlength="100" placeholder="e.g. Grade 11 - STEM or 2nd Year College" value="<?php echo htmlspecialchars($_POST['grade_or_dept'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password (minimum 8 characters)</label>
                <input type="password" id="password" name="password" required minlength="8" placeholder="Create a strong password">
            </div>

            <button type="submit" class="btn-primary" style="margin-top:8px;">Sign Up as Student</button>
        </form>

        <p class="auth-footer">
            Already have an account? <a href="/login.php">Sign in here</a>
        </p>
    </div>
</body>
</html>
