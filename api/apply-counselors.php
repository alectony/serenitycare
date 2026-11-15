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
        $email       = trim($_POST['email'] ?? '');
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
            $err = 'Please enter a valid email address.';
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

                    // Store in local backup store
                    $dataFile = __DIR__ . '/../includes/data/counselor_applications.json';
                    $dataDir = dirname($dataFile);
                    if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
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
</head>
<body class="auth-page">
    <div class="auth-wrap" style="max-width: 680px; margin: 32px auto;">
        <div class="brand" style="justify-content:center;margin-bottom:20px;">
            <a href="/landing.php" style="display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:inherit;">
                <span class="brand-mark">S</span>
                <div>
                    <h2 style="margin:0;">SerenityCare</h2>
                    <span class="badge-role">GUIDANCE COUNSELOR CAREERS</span>
                </div>
            </a>
        </div>

        <div class="card auth-card" style="padding: 32px 36px;">
            <?php if ($msg): ?>
                <div style="text-align: center; padding: 20px 0;">
                    <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); color: #10b981; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                        <?= icon('check') ?>
                    </div>
                    <h2 style="font-size: 22px; color: var(--ink); margin-bottom: 8px;">Application Received</h2>
                    <p style="color: var(--ink-secondary); font-size: 14.5px; line-height: 1.6; max-width: 520px; margin: 0 auto 18px;">
                        <?= e($msg) ?>
                    </p>
                    <div style="display: inline-block; background: var(--surface-sunken); border: 1px solid var(--border-soft); border-radius: 8px; padding: 12px 24px; margin-bottom: 24px;">
                        <span style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); display: block;">Application Reference Number</span>
                        <strong style="font-size: 18px; color: var(--primary); letter-spacing: 0.05em;"><?= e($appRef) ?></strong>
                    </div>
                    <div>
                        <p style="font-size: 13px; color: var(--muted); margin-bottom: 20px;">Our administration and counseling committee will review your license and credentials within 2–3 business days.</p>
                        <a href="/landing.php" class="btn" style="text-decoration: none;">&larr; Return to SerenityCare Home</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card-head" style="margin-bottom: 20px;">
                    <div>
                        <h1 style="font-size: 22px; margin: 0 0 6px;">Counselor Career Application</h1>
                        <p class="card-sub" style="margin: 0;">Join our institutional mental health team to provide compassionate, confidential care.</p>
                    </div>
                    <a href="/landing.php#hiring" class="btn-sm" style="text-decoration: none;">&larr; Back to Home</a>
                </div>

                <?php if ($err): ?>
                    <div class="alert error" style="margin-bottom: 18px;"><?= e($err) ?></div>
                <?php endif; ?>

                <form method="POST" action="/apply-counselor.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="form-row">
                        <div class="field grow">
                            <label for="full_name">Full Legal Name</label>
                            <input type="text" id="full_name" name="full_name" required placeholder="e.g. Maria Santos, RGC" value="<?= e($_POST['full_name'] ?? '') ?>">
                        </div>
                        <div class="field grow">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required placeholder="counselor@domain.edu" value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field grow">
                            <label for="phone">Contact Mobile Number</label>
                            <input type="tel" id="phone" name="phone" required placeholder="+63 9XX XXX XXXX" value="<?= e($_POST['phone'] ?? '') ?>">
                        </div>
                        <div class="field grow">
                            <label for="degree">Highest Educational Degree</label>
                            <input type="text" id="degree" name="degree" placeholder="e.g. MA in Guidance & Counseling" value="<?= e($_POST['degree'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field grow">
                            <label for="license_type">Professional Credential</label>
                            <select id="license_type" name="license_type" required>
                                <option value="RGC" <?= ($_POST['license_type'] ?? '') === 'RGC' ? 'selected' : '' ?>>Registered Guidance Counselor (PRC - RGC)</option>
                                <option value="Psychologist" <?= ($_POST['license_type'] ?? '') === 'Psychologist' ? 'selected' : '' ?>>Licensed Clinical Psychologist (PRC)</option>
                                <option value="Psychometrician" <?= ($_POST['license_type'] ?? '') === 'Psychometrician' ? 'selected' : '' ?>>Registered Psychometrician (PRC)</option>
                                <option value="Intern" <?= ($_POST['license_type'] ?? '') === 'Intern' ? 'selected' : '' ?>>Counseling Intern / Graduate Student</option>
                            </select>
                        </div>
                        <div class="field grow">
                            <label for="license_no">PRC License / Registration No.</label>
                            <input type="text" id="license_no" name="license_no" required placeholder="e.g. 0012345" value="<?= e($_POST['license_no'] ?? '') ?>">
                        </div>
                        <div class="field" style="max-width: 130px;">
                            <label for="experience_years">Experience</label>
                            <input type="number" id="experience_years" name="experience_years" min="0" max="50" value="<?= e($_POST['experience_years'] ?? '2') ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label for="specializations">Core Counseling Specializations</label>
                        <input type="text" id="specializations" name="specializations" placeholder="e.g. Adolescent Mental Health, Academic Stress, Anxiety, Crisis Intervention" value="<?= e($_POST['specializations'] ?? '') ?>">
                        <small class="form-hint">Separate areas of expertise with commas.</small>
                    </div>

                    <div class="field">
                        <label for="resume">Attach Resume / Curriculum Vitae (PDF, DOCX, or Image)</label>
                        <input type="file" id="resume" name="resume" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <small class="form-hint">Maximum file size: 5 MB. Please include references and education history.</small>
                    </div>

                    <div class="field">
                        <label for="cover_note">Cover Statement / Philosophy of Care (Optional)</label>
                        <textarea id="cover_note" name="cover_note" rows="3" placeholder="Briefly introduce your counseling background and approach to student mental wellness..."><?= e($_POST['cover_note'] ?? '') ?></textarea>
                    </div>

                    <div style="margin-top: 24px; display: flex; align-items: center; justify-content: space-between;">
                        <button type="submit" class="btn" style="min-width: 200px;">Submit Application &amp; Resume</button>
                        <a href="/landing.php" class="link" style="font-size: 13.5px;">Cancel and go back</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
