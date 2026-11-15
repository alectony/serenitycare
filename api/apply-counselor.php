<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$msg = '';
$err = '';
$appRef = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $err = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $name        = trim($_POST['full_name'] ?? '');
        $email       = strtolower(trim($_POST['email'] ?? ''));
        $phone       = trim($_POST['phone'] ?? '');
        $degree      = trim($_POST['degree'] ?? '');
        $licenseType = trim($_POST['license_type'] ?? 'RGC');
        $licenseNo   = trim($_POST['license_no'] ?? '');
        $experience  = (int) ($_POST['experience_years'] ?? 0);
        $specs       = trim($_POST['specializations'] ?? '');
        $coverNote   = trim($_POST['cover_note'] ?? '');

        if ($name === '' || strlen($name) < 3) {
            $err = 'Please provide your full legal name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address (e.g. counselor@domain.edu).';
        } elseif ($phone === '' || strlen($phone) < 7) {
            $err = 'Please provide a valid contact telephone or mobile number.';
        } elseif ($licenseNo === '') {
            $err = 'Please provide your PRC license registration number or accreditation ID.';
        } else {
            // Handle Resume File Upload
            $resumeFile = $_FILES['resume'] ?? null;
            $resumePath = '';
            $resumeName = '';
            $resumeDataUrl = '';

            if (!$resumeFile || $resumeFile['error'] === UPLOAD_ERR_NO_FILE) {
                $err = 'Please attach your updated Resume or Curriculum Vitae (PDF, DOCX, or Image).';
            } elseif ($resumeFile['error'] !== UPLOAD_ERR_OK) {
                $err = 'File upload error (Code: ' . $resumeFile['error'] . '). Please try again.';
            } elseif ($resumeFile['size'] > 5242880) { // 5MB limit
                $err = 'Resume file must be 5 MB or smaller.';
            } else {
                $ext = strtolower(pathinfo($resumeFile['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                if (!in_array($ext, $allowed, true)) {
                    $err = 'Please upload a valid document format (.pdf, .doc, .docx, .jpg, .png).';
                } else {
                    $uploadDir = __DIR__ . '/../uploads/resumes';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($resumeFile['name'], PATHINFO_FILENAME));
                    $targetFilename = 'resume_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $targetFilename;

                    if (@move_uploaded_file($resumeFile['tmp_name'], $targetPath)) {
                        $resumePath = '/uploads/resumes/' . $targetFilename;
                        $resumeName = $resumeFile['name'];
                    } else {
                        // Fallback: read directly and convert to data URI
                        $bin = @file_get_contents($resumeFile['tmp_name']);
                        if ($bin) {
                            $mime = $ext === 'pdf' ? 'application/pdf' : 'application/octet-stream';
                            $resumeDataUrl = 'data:' . $mime . ';base64,' . base64_encode($bin);
                            $resumeName = $resumeFile['name'];
                        }
                    }

                    $appId = 'APP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
                    $appRecord = [
                        'id'               => $appId,
                        'full_name'        => $name,
                        'email'            => $email,
                        'phone'            => $phone,
                        'degree'           => $degree,
                        'license_type'     => $licenseType,
                        'license_no'       => $licenseNo,
                        'experience_years' => $experience,
                        'specializations'  => $specs,
                        'cover_note'       => $coverNote,
                        'resume_file'      => $resumePath ?: $resumeDataUrl,
                        'resume_name'      => $resumeName,
                        'status'           => 'pending_review',
                        'submitted_at'     => date('c'),
                    ];

                    // Store in local backup store (handles Vercel read-only filesystem)
                    $dataFile = (isset($_SERVER['VERCEL']) || (!is_dir(__DIR__ . '/../includes/data') && !@mkdir(__DIR__ . '/../includes/data', 0755, true)))
                        ? '/tmp/sc_counselor_applications.json'
                        : __DIR__ . '/../includes/data/counselor_applications.json';
                    $dataDir = dirname($dataFile);
                    if ($dataDir !== '/tmp' && !is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
                    $allApps = file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?: []) : [];
                    $allApps[] = $appRecord;
                    @file_put_contents($dataFile, json_encode($allApps, JSON_PRETTY_PRINT));

                    // Attempt saving to Supabase if table exists
                    @sb_request('POST', 'counselor_applications', '', $appRecord);

                    // Notify Administrators
                    $admRes = @sb_request('GET', 'profiles', 'select=id&role=eq.admin&limit=5');
                    if ($admRes['status'] === 200 && is_array($admRes['data'])) {
                        foreach ($admRes['data'] as $adm) {
                            create_notification(
                                $adm['id'],
                                'New Counselor Applicant: ' . $name,
                                $name . ' (' . $licenseType . ' #' . $licenseNo . ') applied for a guidance counselor position and submitted their resume.',
                                'application'
                            );
                        }
                    }

                    $appRef = $appId;
                    $msg = 'Thank you for your application! Your credentials and resume have been submitted to the SerenityCare Academic Guidance Board.';
                }
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
    <title>Guidance Counselor Careers - SerenityCare</title>
    <script>
    (function(){
        var t = localStorage.getItem('serenity_theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="stylesheet" href="/css/style.css">
    <script src="/js/serenity.js" defer></script>
    <style>
    .apply-container {
        width: 100%;
        max-width: 820px;
        margin: 0 auto;
    }
    .apply-card {
        padding: 40px 48px;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-raised-lg);
        background: var(--surface);
        border: 1px solid var(--nm-bevel-light);
    }
    .apply-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 22px;
    }
    .apply-grid-3 {
        display: grid;
        grid-template-columns: 2fr 1.6fr 1fr;
        gap: 20px;
        margin-bottom: 22px;
    }
    .apply-field {
        margin-bottom: 0;
    }
    .apply-field-full {
        margin-bottom: 22px;
    }
    .apply-field label, .apply-field-full label {
        display: block;
        font-size: 13.5px;
        font-weight: 600;
        margin-bottom: 7px;
        color: var(--ink);
    }
    .apply-field input, .apply-field select, .apply-field textarea,
    .apply-field-full input, .apply-field-full select, .apply-field-full textarea {
        width: 100%;
        box-sizing: border-box;
    }
    .apply-file-box {
        border: 2px dashed var(--nm-bevel-dark);
        background: var(--surface-sunken);
        border-radius: var(--radius);
        padding: 16px 20px;
        transition: all 0.2s ease;
    }
    .apply-file-box:hover {
        border-color: var(--primary);
    }
    @media (max-width: 768px) {
        .apply-grid-2, .apply-grid-3 {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        .apply-card {
            padding: 26px 20px !important;
        }
    }
    </style>
</head>
<body class="auth-body" style="align-items: flex-start; padding: 40px 18px; min-height: 100vh;">
    <div class="apply-container">
        <!-- Top bar with brand and light/dark theme toggle -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
            <a href="/landing.php" class="auth-brand" style="margin-bottom: 0; text-decoration: none; display: inline-flex; align-items: center; gap: 12px;">
                <span class="brand-mark">S</span>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-weight: 700; font-size: 20px; color: var(--ink); line-height: 1.2;">SerenityCare</span>
                    <span style="font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--primary); font-weight: 700;">Guidance Counselor Careers</span>
                </div>
            </a>
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="/landing.php#hiring" class="btn-sm" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    &larr; Back to Home
                </a>
                <button type="button" class="theme-toggle-btn" data-theme-toggle title="Toggle Dark/Light Mode" aria-label="Toggle Dark/Light Mode">
                    <svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </button>
            </div>
        </div>

        <div class="card apply-card">
            <?php if ($msg): ?>
                <div style="text-align: center; padding: 24px 0;">
                    <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); color: #10b981; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 18px;">
                        <?= icon('check') ?>
                    </div>
                    <h2 style="font-size: 24px; color: var(--ink); margin-bottom: 8px;">Application Received</h2>
                    <p style="color: var(--ink-secondary); font-size: 15px; line-height: 1.6; max-width: 560px; margin: 0 auto 22px;">
                        <?= e($msg) ?>
                    </p>
                    <div style="display: inline-block; background: var(--surface-sunken); border: 1px solid var(--border-soft); border-radius: 10px; padding: 14px 28px; margin-bottom: 28px;">
                        <span style="font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); display: block; margin-bottom: 4px;">Application Reference Number</span>
                        <strong style="font-size: 20px; color: var(--primary); letter-spacing: 0.08em;"><?= e($appRef) ?></strong>
                    </div>
                    <div>
                        <p style="font-size: 13.5px; color: var(--muted); margin-bottom: 24px;">Our administration and counseling committee will review your license and credentials within 2 to 3 business days.</p>
                        <a href="/landing.php" class="btn-primary" style="text-decoration: none; padding: 12px 28px; display: inline-block;">&larr; Return to SerenityCare Home</a>
                    </div>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 28px; padding-bottom: 18px; border-bottom: 1px solid var(--line);">
                    <h1 style="font-size: 24px; font-weight: 700; margin: 0 0 6px; color: var(--ink);">Counselor Career Application</h1>
                    <p style="margin: 0; font-size: 14.5px; color: var(--muted); line-height: 1.5;">Join our institutional mental health team to provide compassionate, confidential care. Complete your professional and licensing details below.</p>
                </div>

                <?php if ($err): ?>
                    <div class="alert error" style="margin-bottom: 22px;"><?= e($err) ?></div>
                <?php endif; ?>

                <form method="POST" action="/apply-counselor.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="apply-grid-2">
                        <div class="apply-field">
                            <label for="full_name">Full Legal Name</label>
                            <input type="text" id="full_name" name="full_name" required placeholder="e.g. Maria Santos, RGC" value="<?= e($_POST['full_name'] ?? '') ?>">
                        </div>
                        <div class="apply-field">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required placeholder="counselor@domain.edu" value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="apply-grid-2">
                        <div class="apply-field">
                            <label for="phone">Contact Mobile Number</label>
                            <input type="tel" id="phone" name="phone" required placeholder="+63 9XX XXX XXXX" value="<?= e($_POST['phone'] ?? '') ?>">
                        </div>
                        <div class="apply-field">
                            <label for="degree">Highest Educational Degree</label>
                            <input type="text" id="degree" name="degree" placeholder="e.g. MA in Guidance &amp; Counseling" value="<?= e($_POST['degree'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="apply-grid-3">
                        <div class="apply-field">
                            <label for="license_type">Professional Credential</label>
                            <select id="license_type" name="license_type" required>
                                <option value="RGC" <?= ($_POST['license_type'] ?? '') === 'RGC' ? 'selected' : '' ?>>Registered Guidance Counselor (PRC - RGC)</option>
                                <option value="Psychologist" <?= ($_POST['license_type'] ?? '') === 'Psychologist' ? 'selected' : '' ?>>Licensed Clinical Psychologist (PRC)</option>
                                <option value="Psychometrician" <?= ($_POST['license_type'] ?? '') === 'Psychometrician' ? 'selected' : '' ?>>Registered Psychometrician (PRC)</option>
                                <option value="Intern" <?= ($_POST['license_type'] ?? '') === 'Intern' ? 'selected' : '' ?>>Counseling Intern / Graduate Student</option>
                            </select>
                        </div>
                        <div class="apply-field">
                            <label for="license_no">PRC License / Registration No.</label>
                            <input type="text" id="license_no" name="license_no" required placeholder="e.g. 0012345" value="<?= e($_POST['license_no'] ?? '') ?>">
                        </div>
                        <div class="apply-field">
                            <label for="experience_years">Experience (Yrs)</label>
                            <input type="number" id="experience_years" name="experience_years" min="0" max="50" value="<?= e($_POST['experience_years'] ?? '2') ?>">
                        </div>
                    </div>

                    <div class="apply-field-full">
                        <label for="specializations">Core Counseling Specializations</label>
                        <input type="text" id="specializations" name="specializations" placeholder="e.g. Adolescent Mental Health, Academic Stress, Anxiety, Crisis Intervention" value="<?= e($_POST['specializations'] ?? '') ?>">
                        <small class="form-hint" style="display:block;margin-top:6px;color:var(--muted);font-size:12.5px;">Separate areas of expertise with commas.</small>
                    </div>

                    <div class="apply-field-full">
                        <label for="resume">Attach Resume / Curriculum Vitae (PDF, DOCX, or Image)</label>
                        <div class="apply-file-box">
                            <input type="file" id="resume" name="resume" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="background:transparent;border:none;box-shadow:none;padding:0;">
                        </div>
                        <small class="form-hint" style="display:block;margin-top:6px;color:var(--muted);font-size:12.5px;">Maximum file size: 5 MB. Accepted formats: PDF, DOCX, DOC, JPG, PNG.</small>
                    </div>

                    <div class="apply-field-full">
                        <label for="cover_note">Cover Statement / Philosophy of Care (Optional)</label>
                        <textarea id="cover_note" name="cover_note" rows="3" placeholder="Briefly introduce your counseling background and approach to student mental wellness..."><?= e($_POST['cover_note'] ?? '') ?></textarea>
                    </div>

                    <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <button type="submit" class="btn-primary" style="min-width: 220px; padding: 13px 28px; font-size: 15px;">Submit Application &amp; Resume</button>
                        <a href="/landing.php" class="link" style="font-size: 14px;">Cancel and go back</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
