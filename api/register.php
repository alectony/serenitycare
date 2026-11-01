<?php
require_once __DIR__ . '/../includes/supabase.php';

$error = '';
$success = '';

$types = [
    'student'   => 'Student',
    'teacher'   => 'Teacher',
    'counselor' => 'Counselor (invite code required)',
    'admin'     => 'Administrator (invite code required)',
];

function invite_code_for($type) {
    $key = $type === 'counselor' ? 'COUNSELOR_INVITE_CODE' : 'ADMIN_INVITE_CODE';
    return (string) (getenv($key) ?: ($_ENV[$key] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $type = $_POST['account_type'] ?? 'student';
    $dept = trim($_POST['grade_or_dept'] ?? '');
    $code = trim($_POST['invite_code'] ?? '');
    $privileged = in_array($type, ['counselor', 'admin'], true);

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!isset($types[$type])) {
        $error = 'Please choose an account type.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($privileged && invite_code_for($type) === '') {
        $error = 'Registration for this account type is not enabled.';
    } elseif ($privileged && !hash_equals(invite_code_for($type), $code)) {
        $error = 'Invalid invite code.';
    } else {
        $extra = $privileged ? [] : ['patient_type' => $type, 'grade_or_dept' => $dept];
        $result = sb_register($email, $password, $fullName, $extra);

        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $uid = $result['user']['id'] ?? ($result['id'] ?? '');
            $validUid = preg_match('/^[0-9a-f-]{36}$/i', (string) $uid) === 1;

            // The database trigger normally creates the profile row. This service-role call
            // makes sure it exists (and, for counselor/admin, sets the role after the invite
            // code check). Roles are never taken from the form or from user metadata.
            $profile = [
                'email' => $email,
                'full_name' => $fullName,
                'role' => $privileged ? $type : 'patient',
                'is_active' => true,
            ];
            if (!$privileged) {
                $profile['patient_type'] = $type;
                $profile['grade_or_dept'] = $dept !== '' ? mb_substr($dept, 0, 100) : null;
            }

            if ($privileged) {
                if ($validUid && sb_service_upsert_profile($uid, $profile) && sb_service_get_role($uid) === $type) {
                    $success = 'Registration successful! You can now log in as ' . $types[$type] . '.';
                } else {
                    $error = 'Your account was created, but the ' . $type . ' role could not be assigned, so it is still a patient account. Please contact an administrator.';
                }
            } else {
                if ($validUid) { sb_service_upsert_profile($uid, $profile); }   // safety net only
                $success = 'Registration successful! You can now log in.';
            }
        }
    }
}
$selectedType = $_POST['account_type'] ?? 'student';
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
        <div class="auth-brand"><span class="brand-mark">S</span><span>SerenityCare</span></div>
        <h2>Create your account</h2>
        <p class="auth-sub">Students, teachers, counselors and administrators.</p>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="/register.php" method="POST">
            <div class="form-group">
                <label for="account_type">I am a</label>
                <select id="account_type" name="account_type">
                    <?php foreach ($types as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo $selectedType === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="grade_or_dept">Grade / Department (students &amp; teachers, optional)</label>
                <input type="text" id="grade_or_dept" name="grade_or_dept" maxlength="100" value="<?php echo htmlspecialchars($_POST['grade_or_dept'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="invite_code">Invite code (counselors &amp; administrators only)</label>
                <input type="password" id="invite_code" name="invite_code" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="password">Password (min. 8 characters)</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>

            <button type="submit" class="btn-primary">Register</button>
        </form>

        <p class="auth-footer">
            Already have an account? <a href="/login.php">Login here</a>
        </p>
    </div>

</body>
</html>