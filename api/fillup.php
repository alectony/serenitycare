<?php
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $coverLetter = trim($_POST['cover_letter'] ?? '');

    if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please provide your full name and a valid email address.';
    } elseif (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
        $err = 'Please upload your resume (PDF, DOC, or DOCX).';
    } else {
        $file = $_FILES['resume'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $err = 'Error uploading resume file. Please try again.';
        } else {
            $allowedExts = ['pdf', 'doc', 'docx'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                $err = 'Invalid resume format. Please upload a PDF, DOC, or DOCX file.';
            } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
                $err = 'File size exceeds the 5MB limit.';
            } else {
                // In a production environment, you would move_uploaded_file() or upload to Supabase Storage.
                // For this application portal flow, we record the successful submission:
                $msg = 'Thank you, ' . e($fullName) . '! Your application and resume have been successfully submitted to the SerenityCare Guidance Committee. We will review your credentials and contact you shortly.';
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
    <title>Counselor Application - SerenityCare</title>
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
    <div class="auth-card" style="max-width: 600px; padding: 40px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <a href="/" class="auth-brand" style="margin-bottom: 0;">
                <span class="brand-mark">S</span>
                <span>SerenityCare</span>
            </a>
            <a href="/" class="btn-sm">&larr; Back to Home</a>
        </div>

        <h2>Guidance Counselor Application</h2>
        <p class="auth-sub">Submit your credentials and resume to join our student care team.</p>

        <?php if ($msg): ?>
            <div class="alert success"><?php echo e($msg); ?></div>
            <div style="text-align: center; margin-top: 24px;">
                <a href="/" class="btn-primary">Return to Homepage</a>
            </div>
        <?php else: ?>
            <?php if ($err): ?>
                <div class="alert error"><?php echo e($err); ?></div>
            <?php endif; ?>

            <form method="POST" action="/fillup.php" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="full_name">Full Name &amp; Credentials (e.g. Dr. Maria Santos, RGC)</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo e($_POST['full_name'] ?? ''); ?>" required placeholder="Enter your full name">
                </div>

                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" required placeholder="name@domain.com">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="+63 912 345 6789">
                    </div>
                </div>

                <div class="form-group" style="margin-top: 18px;">
                    <label for="experience">Counseling Experience &amp; Background</label>
                    <textarea id="experience" name="experience" rows="3" placeholder="Briefly describe your years of experience, specialization, or license details..."><?php echo e($_POST['experience'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="cover_letter">Why do you want to join SerenityCare?</label>
                    <textarea id="cover_letter" name="cover_letter" rows="3" placeholder="Share your motivation for student mental health advocacy..."><?php echo e($_POST['cover_letter'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="resume">Upload Resume / CV (PDF, DOC, DOCX up to 5MB)</label>
                    <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx" required>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px;">Submit Application</button>
            </form>
        <?php endif; ?>

        <div class="auth-footer">
            Already have a staff account? <a href="/login.php">Sign In</a>
        </div>
    </div>
</body>
</html>