<?php
/**
 * fillup.php - Counselor application form (fill in details + upload resume).
 * Public page: applicants do not need an account.
 *
 * Uses the service_role key on the server to (1) upload the resume to the private
 * "resumes" Supabase Storage bucket and (2) insert a row in "counselor_applications".
 * Nothing is written to the local disk (Vercel's filesystem is read-only).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

const RESUME_MAX_BYTES = 4 * 1024 * 1024;   // 4 MB (Vercel rejects request bodies over ~4.5 MB)
const RESUME_BUCKET    = 'resumes';

$positions      = ['Guidance Counselor', 'Counseling Intern', 'Psychologist'];
$licenses       = ['Registered Guidance Counselor (RGC)', 'Registered Psychometrician (RPm)', 'Licensed Psychologist', 'Not yet licensed / Intern'];
$educations     = ["Bachelor's degree", "Master's degree (ongoing)", "Master's degree", 'Doctorate'];
$availabilities = ['Online only', 'On-campus only', 'Online and on-campus'];

/** Checks the uploaded resume. Returns ['ext','mime'] on success or ['error' => '...']. */
function resume_inspect(array $f) {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Please attach your resume.'];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['error' => 'Your resume is too large. The limit is 4 MB.'];
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return ['error' => 'The resume could not be uploaded. Please try again.'];
    }
    if ($f['size'] <= 0 || $f['size'] > RESUME_MAX_BYTES) {
        return ['error' => 'Your resume must be between 1 byte and 4 MB.'];
    }
    $map = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!isset($map[$ext])) {
        return ['error' => 'Resume must be a PDF, DOC or DOCX file.'];
    }
    // Check the real file signature, not just the extension.
    $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 8);
    $ok = ($ext === 'pdf'  && strncmp($head, '%PDF', 4) === 0)
       || ($ext === 'docx' && strncmp($head, "PK\x03\x04", 4) === 0)
       || ($ext === 'doc'  && strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0);
    if (!$ok) {
        return ['error' => 'That file does not look like a valid ' . strtoupper($ext) . ' document.'];
    }
    return ['ext' => $ext, 'mime' => $map[$ext]];
}

function field_options(array $list, $current) {
    $h = '<option value="">Select...</option>';
    foreach ($list as $v) {
        $h .= '<option value="' . e($v) . '"' . ($current === $v ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $h;
}

csrf_token();   // sets the CSRF cookie before any output

$sent   = isset($_GET['sent']);
$errors = [];
$old = [
    'full_name' => '', 'email' => '', 'phone' => '', 'position' => '', 'license_type' => '',
    'prc_license_no' => '', 'years_experience' => '', 'education' => '', 'availability' => '', 'cover_letter' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $old['full_name']      = mb_substr($old['full_name'], 0, 120);
    $old['email']          = mb_substr($old['email'], 0, 160);
    $old['prc_license_no'] = mb_substr($old['prc_license_no'], 0, 40);
    $old['cover_letter']   = mb_substr($old['cover_letter'], 0, 2000);

    // Hidden "website" field: real people never see it, bots fill it in.
    if (!empty($_POST['website'])) {
        header('Location: /fillup.php?sent=1');
        exit;
    }

    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    }
    if (strlen($old['full_name']) < 2) {
        $errors[] = 'Please enter your full name.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $old['phone'])) {
        $errors[] = 'Please enter a valid contact number.';
    }
    if (!in_array($old['position'], $positions, true)) {
        $errors[] = 'Please choose the position you are applying for.';
    }
    if (!in_array($old['license_type'], $licenses, true)) {
        $errors[] = 'Please choose your license / registration status.';
    }
    if (!ctype_digit($old['years_experience']) || (int) $old['years_experience'] > 50) {
        $errors[] = 'Years of experience must be a number from 0 to 50.';
    }
    if (!in_array($old['education'], $educations, true)) {
        $errors[] = 'Please choose your highest education.';
    }
    if (!in_array($old['availability'], $availabilities, true)) {
        $errors[] = 'Please choose your consultation availability.';
    }
    if (empty($_POST['consent'])) {
        $errors[] = 'Please confirm that your information may be used to process your application.';
    }

    $file = resume_inspect($_FILES['resume'] ?? []);
    if (isset($file['error'])) {
        $errors[] = $file['error'];
    }
    if (!$errors && sb_service_key() === '') {
        error_log('fillup.php: SUPABASE_SERVICE_ROLE_KEY is not set');
        $errors[] = 'Applications are temporarily unavailable. Please email careers@serenitycare.edu instead.';
    }

    if (!$errors) {
        // Random storage name - never trust the user's file name for the path.
        $path = date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $file['ext'];

        if (!sb_service_upload_object(RESUME_BUCKET, $path, $_FILES['resume']['tmp_name'], $file['mime'])) {
            $errors[] = 'We could not save your resume. Please try again in a moment.';
        } else {
            $saved = sb_service_insert('counselor_applications', [
                'full_name'        => $old['full_name'],
                'email'            => strtolower($old['email']),
                'phone'            => $old['phone'],
                'position'         => $old['position'],
                'license_type'     => $old['license_type'],
                'prc_license_no'   => $old['prc_license_no'] !== '' ? $old['prc_license_no'] : null,
                'years_experience' => (int) $old['years_experience'],
                'education'        => $old['education'],
                'availability'     => $old['availability'],
                'cover_letter'     => $old['cover_letter'] !== '' ? $old['cover_letter'] : null,
                'resume_path'      => $path,
                'resume_filename'  => mb_substr(preg_replace('/[^\w.\- ]+/u', '_', (string) $_FILES['resume']['name']), 0, 120),
            ]);
            if (!$saved) {
                sb_service_delete_object(RESUME_BUCKET, $path);   // don't leave an orphan file behind
                $errors[] = 'We could not save your application. Please try again in a moment.';
            } else {
                header('Location: /fillup.php?sent=1');   // redirect so a refresh doesn't re-submit
                exit;
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
    <style>
        .apply-wrap { max-width: 760px; margin: 0 auto; padding: 48px 24px 80px; }
        .apply-card {
            background: var(--surface);
            border: 1px solid var(--nm-bevel-light);
            border-radius: var(--radius-lg);
            padding: 36px 40px;
            box-shadow: var(--shadow-raised-lg);
        }
        .apply-card h1 { font-family: var(--serif); font-weight: 600; font-size: 32px; margin: 0 0 6px; color: var(--ink); }
        .apply-sub { color: var(--muted); font-size: 14.5px; margin: 0 0 26px; line-height: 1.55; }
        .apply-section {
            font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            color: var(--primary); margin: 28px 0 14px; padding-bottom: 8px; border-bottom: 1px solid var(--line);
        }
        .apply-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        .apply-grid .full { grid-column: 1 / -1; }
        .apply-req { color: var(--danger); }
        .apply-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .apply-card .field input[type="file"] {
            background: var(--surface-inset); border: 1.5px dashed var(--line); color: var(--muted);
        }
        .apply-card .field input[type="file"]:hover { background: var(--green-soft); border-color: var(--green); }
        .apply-file-name { font-size: 13px; color: var(--muted); margin-top: 6px; }
        .apply-consent { display: flex; align-items: flex-start; gap: 10px; margin: 8px 0 22px; }
        .apply-consent input { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary); flex-shrink: 0; }
        .apply-consent label { margin: 0; font-weight: 500; font-size: 13.5px; line-height: 1.5; color: var(--ink-secondary); cursor: pointer; }
        .apply-actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .apply-actions button { font: inherit; cursor: pointer; border: 0; }
        .apply-success { text-align: center; padding: 20px 0 6px; }
        .apply-success-icon {
            width: 68px; height: 68px; border-radius: 50%; margin: 0 auto 18px;
            display: flex; align-items: center; justify-content: center;
            background: var(--success-soft); color: var(--success);
        }
        .apply-success-icon svg { width: 34px; height: 34px; }
        .apply-errors { display: block; }
        .apply-errors ul { margin: 6px 0 0 18px; padding: 0; }
        @media (max-width: 640px) {
            .apply-card { padding: 26px 20px; }
            .apply-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="landing-body">

    <header class="landing-nav-wrapper">
        <nav class="landing-nav">
            <a href="/" class="landing-brand">
                <span class="brand-mark">S</span>
                <span>SerenityCare</span>
            </a>
            <div class="landing-actions">
                <?php echo theme_toggle_button(); ?>
                <a href="/#hiring" class="btn-secondary" style="padding: 9px 18px; font-size: 14px;">&larr; Back to Home</a>
            </div>
        </nav>
    </header>

    <main class="apply-wrap">
        <div class="apply-card">

        <?php if ($sent): ?>
            <div class="apply-success">
                <div class="apply-success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1>Application Sent!</h1>
                <p class="apply-sub">
                    Thank you for your interest in joining SerenityCare. Our guidance office will review your resume
                    and contact you through the email or number you provided.
                </p>
                <a href="/" class="btn-primary">Back to Home</a>
            </div>

        <?php else: ?>
            <h1>Apply as a Counselor</h1>
            <p class="apply-sub">
                Fill out the form below and attach your resume. Fields marked <span class="apply-req">*</span> are required.
            </p>

            <?php if ($errors): ?>
                <div class="alert error apply-errors" role="alert">
                    <strong>Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/fillup.php" enctype="multipart/form-data" id="applyForm" novalidate>
                <?php echo csrf_field(); ?>

                <!-- Honeypot (hidden from people) -->
                <div class="apply-hp" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="apply-section">Personal Information</div>
                <div class="apply-grid">
                    <div class="field full">
                        <label for="full_name">Full name <span class="apply-req">*</span></label>
                        <input type="text" id="full_name" name="full_name" maxlength="120" required value="<?php echo e($old['full_name']); ?>" placeholder="e.g. Maria Santos">
                    </div>
                    <div class="field">
                        <label for="email">Email address <span class="apply-req">*</span></label>
                        <input type="email" id="email" name="email" maxlength="160" required value="<?php echo e($old['email']); ?>" placeholder="you@example.com">
                    </div>
                    <div class="field">
                        <label for="phone">Contact number <span class="apply-req">*</span></label>
                        <input type="tel" id="phone" name="phone" maxlength="20" required value="<?php echo e($old['phone']); ?>" placeholder="0917 123 4567">
                    </div>
                </div>

                <div class="apply-section">Professional Background</div>
                <div class="apply-grid">
                    <div class="field">
                        <label for="position">Position applying for <span class="apply-req">*</span></label>
                        <select id="position" name="position" required><?php echo field_options($positions, $old['position']); ?></select>
                    </div>
                    <div class="field">
                        <label for="license_type">License / registration <span class="apply-req">*</span></label>
                        <select id="license_type" name="license_type" required><?php echo field_options($licenses, $old['license_type']); ?></select>
                    </div>
                    <div class="field">
                        <label for="prc_license_no">PRC license no. <small>(if any)</small></label>
                        <input type="text" id="prc_license_no" name="prc_license_no" maxlength="40" value="<?php echo e($old['prc_license_no']); ?>">
                    </div>
                    <div class="field">
                        <label for="years_experience">Years of experience <span class="apply-req">*</span></label>
                        <input type="number" id="years_experience" name="years_experience" min="0" max="50" required value="<?php echo e($old['years_experience']); ?>">
                    </div>
                    <div class="field">
                        <label for="education">Highest education <span class="apply-req">*</span></label>
                        <select id="education" name="education" required><?php echo field_options($educations, $old['education']); ?></select>
                    </div>
                    <div class="field">
                        <label for="availability">Consultation availability <span class="apply-req">*</span></label>
                        <select id="availability" name="availability" required><?php echo field_options($availabilities, $old['availability']); ?></select>
                    </div>
                </div>

                <div class="apply-section">Resume &amp; Message</div>
                <div class="field">
                    <label for="resume">Resume <span class="apply-req">*</span> <small>(PDF, DOC or DOCX, up to 4 MB)</small></label>
                    <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                    <div class="apply-file-name" id="resumeInfo"></div>
                </div>
                <div class="field">
                    <label for="cover_letter">Short message to the guidance office <small>(optional)</small></label>
                    <textarea id="cover_letter" name="cover_letter" maxlength="2000" rows="5" placeholder="Tell us a little about yourself and why you want to join SerenityCare."><?php echo e($old['cover_letter']); ?></textarea>
                </div>

                <div class="apply-consent">
                    <input type="checkbox" id="consent" name="consent" value="1" required<?php echo !empty($_POST['consent']) ? ' checked' : ''; ?>>
                    <label for="consent">I confirm that the information I provided is true, and I agree that SerenityCare may use it and my resume to process my application.</label>
                </div>

                <div class="apply-actions">
                    <button type="submit" class="btn-primary" id="applyBtn">Submit Application</button>
                    <a href="/#hiring" class="btn-light">Cancel</a>
                </div>
            </form>

            <script>
            (function () {
                var MAX = <?php echo (int) RESUME_MAX_BYTES; ?>;
                var input = document.getElementById('resume');
                var info = document.getElementById('resumeInfo');
                var form = document.getElementById('applyForm');
                var btn = document.getElementById('applyBtn');

                input.addEventListener('change', function () {
                    var f = input.files && input.files[0];
                    if (!f) { info.textContent = ''; return; }
                    if (f.size > MAX) {
                        info.textContent = 'That file is ' + (f.size / 1048576).toFixed(1) + ' MB. The limit is 4 MB.';
                        input.value = '';
                        return;
                    }
                    info.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)';
                });

                form.addEventListener('submit', function () {
                    btn.disabled = true;
                    btn.textContent = 'Sending...';
                });
            })();
            </script>
        <?php endif; ?>

        </div>
    </main>

</body>
</html>