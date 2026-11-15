<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('patient');
$uid = $me['user']['id'];
$token = $me['token'];

$id = $_GET['id'] ?? '';
if (!is_uuid($id)) {
    die('Invalid appointment reference.');
}

$ar = sb_request('GET', 'appointments',
    'select=id,scheduled_at,status,reason,patient_id,counselor:profiles!counselor_id(id,full_name,email)&id=eq.' . urlencode($id) . '&patient_id=eq.' . urlencode($uid),
    null, $token);

$appt = ($ar['status'] === 200 && !empty($ar['data'][0])) ? $ar['data'][0] : null;
if (!$appt) {
    die('Appointment record not found or access denied.');
}

// Student profile details
$pr = sb_request('GET', 'profiles', 'select=full_name,email,details:patient_details(*)&id=eq.' . urlencode($uid), null, $token);
$student = ($pr['status'] === 200 && !empty($pr['data'][0])) ? $pr['data'][0] : [];
$details = $student['details'][0] ?? ($student['details'] ?? []);

// Parse session mode & link
$reason = $appt['reason'] ?? '';
$isOnline = false;
$meetLink = '';
$cleanReason = $reason;

if (preg_match('/\[Online Video Call - Google Meet:\s*(https:\/\/meet\.google\.com\/[a-z0-9-]+)\]\s*(.*)/is', $reason, $m)) {
    $isOnline = true;
    $meetLink = trim($m[1]);
    $cleanReason = trim($m[2]);
} elseif (stripos($reason, 'google meet') !== false || stripos($reason, 'online') !== false) {
    $isOnline = true;
    if (preg_match('/(https:\/\/meet\.google\.com\/[a-z0-9-]+)/i', $reason, $m)) {
        $meetLink = $m[1];
    }
} elseif (preg_match('/\[In-Person Consultation[^\]]*\]\s*(.*)/is', $reason, $m)) {
    $cleanReason = trim($m[1]);
}

$refCode = 'SC-PASS-' . strtoupper(substr(md5($appt['id']), 0, 8));
$ts = strtotime($appt['scheduled_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Slip - <?= e($refCode) ?> - SerenityCare</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .print-screen-bar {
            background: var(--surface);
            border-bottom: 1px solid var(--border-soft);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .slip-outer {
            max-width: 780px;
            margin: 28px auto;
            padding: 0 20px;
        }
        .official-slip {
            background: #ffffff;
            color: #0f172a;
            border: 2px solid #334155;
            border-radius: 8px;
            padding: 36px 42px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            position: relative;
        }
        .official-header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .official-header h1 {
            font-size: 20px;
            margin: 0;
            color: #0f172a;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .official-header h2 {
            font-size: 14px;
            margin: 6px 0 0;
            color: #475569;
            font-weight: 600;
            letter-spacing: 0.04em;
        }
        .official-header .slip-title {
            display: inline-block;
            margin-top: 12px;
            padding: 4px 14px;
            background: #0f172a;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.1em;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .slip-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 24px;
            margin-bottom: 24px;
        }
        .slip-item {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
        }
        .slip-item-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.05em;
            display: block;
            margin-bottom: 3px;
        }
        .slip-item-value {
            font-size: 14.5px;
            font-weight: 600;
            color: #0f172a;
        }
        .slip-notice {
            background: #f8fafc;
            border-left: 4px solid #0284c7;
            padding: 12px 16px;
            font-size: 12px;
            color: #334155;
            line-height: 1.5;
            margin: 20px 0;
            border-radius: 0 4px 4px 0;
        }
        .slip-barcode-area {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 2px dashed #cbd5e1;
            padding-top: 20px;
            margin-top: 24px;
        }
        .simulated-barcode {
            font-family: monospace;
            letter-spacing: 3px;
            font-size: 16px;
            background: repeating-linear-gradient(90deg, #0f172a 0, #0f172a 2px, transparent 2px, transparent 4px, #0f172a 4px, #0f172a 7px, transparent 7px, transparent 9px);
            color: transparent;
            height: 38px;
            width: 200px;
            display: block;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 10px;
        }
        .sig-block {
            text-align: center;
            width: 220px;
        }
        .sig-line {
            border-bottom: 1px solid #0f172a;
            margin-bottom: 6px;
            height: 32px;
        }
        .sig-name {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .sig-title {
            font-size: 11px;
            color: #64748b;
        }
        @media print {
            .print-screen-bar { display: none !important; }
            body { background: #ffffff !important; margin: 0; padding: 0; }
            .slip-outer { margin: 0; padding: 0; max-width: 100%; }
            .official-slip { border: 1px solid #000; box-shadow: none !important; padding: 24px; }
        }
    </style>
</head>
<body style="background:var(--bg);margin:0;min-height:100vh;">
    <!-- Screen Action Bar -->
    <header class="print-screen-bar">
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="/patient/appointment.php" class="btn-sm" style="text-decoration:none;">&larr; Back to Appointments</a>
            <span style="font-weight:600;font-size:14px;color:var(--ink);">Official Consultation Slip &bull; <?= e($refCode) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($isOnline && !empty($meetLink)): ?>
                <a href="<?= e($meetLink) ?>" target="_blank" rel="noopener" class="btn-sm btn-success-sm" style="display:inline-flex;align-items:center;gap:4px;">
                    <?= icon('video') ?> Open Google Meet
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-sm" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;">
                <?= icon('printer') ?> Print Slip (Ctrl+P)
            </button>
        </div>
    </header>

    <div class="slip-outer">
        <article class="official-slip">
            <header class="official-header">
                <h1>SERENITYCARE STUDENT GUIDANCE CENTER</h1>
                <h2>Institutional Mental Health &amp; Student Wellness Services</h2>
                <div class="slip-title">Official Consultation Pass &amp; Excuse Slip</div>
            </header>

            <div class="slip-grid">
                <div class="slip-item">
                    <span class="slip-item-label">Student Name</span>
                    <span class="slip-item-value"><?= e($student['full_name'] ?? 'Student') ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Student ID Number</span>
                    <span class="slip-item-value"><?= e($details['id_number'] ?? 'On File') ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Grade / Year &amp; Section</span>
                    <span class="slip-item-value"><?= e(($details['grade_or_dept'] ?? 'Student') . ' &bull; ' . ($details['section'] ?? 'Main')) ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Class Adviser</span>
                    <span class="slip-item-value"><?= e($details['adviser'] ?? 'Guidance Department') ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Assigned Guidance Counselor</span>
                    <span class="slip-item-value"><?= e($appt['counselor']['full_name'] ?? 'Counselor') ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Consultation Schedule</span>
                    <span class="slip-item-value"><?= e(date('l, F d, Y', $ts)) ?> at <?= e(date('g:i A', $ts)) ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Session Format &amp; Location</span>
                    <span class="slip-item-value">
                        <?php if ($isOnline): ?>
                            🟢 Online Video Call (Google Meet)<br>
                            <small style="font-weight:normal;color:#0284c7;word-break:break-all;"><?= e($meetLink ?: 'Link provided on portal') ?></small>
                        <?php else: ?>
                            🏢 In-Person (Guidance Counseling Office - Room 204)
                        <?php endif; ?>
                    </span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Appointment Status</span>
                    <span class="slip-item-value" style="text-transform:capitalize;"><?= e($appt['status']) ?></span>
                </div>
            </div>

            <div class="slip-item" style="border:1px solid #e2e8f0;padding:12px;border-radius:6px;background:#f8fafc;margin-bottom:18px;">
                <span class="slip-item-label">Purpose of Counseling / Consultation</span>
                <span class="slip-item-value" style="font-size:13.5px;font-weight:500;line-height:1.5;color:#334155;"><?= e($cleanReason ?: 'Individual student wellness and guidance consultation.') ?></span>
            </div>

            <div class="slip-notice">
                <strong>ACADEMIC NOTICE &amp; EXCUSED ABSENCE AUTHORIZATION:</strong><br>
                This pass certifies that the student named above has an official guidance appointment. Under institutional student wellness regulations, faculty and staff are respectfully requested to excuse the student from class attendance for the duration of this appointment (including reasonable transit time).
            </div>

            <div class="signatures">
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-name"><?= e($student['full_name'] ?? 'Student') ?></div>
                    <div class="sig-title">Student Signature</div>
                </div>
                <div class="sig-block">
                    <div class="sig-line"></div>
                    <div class="sig-name"><?= e($appt['counselor']['full_name'] ?? 'Guidance Counselor') ?></div>
                    <div class="sig-title">Registered Guidance Counselor (RGC)</div>
                </div>
            </div>

            <div class="slip-barcode-area">
                <div>
                    <span class="simulated-barcode" aria-hidden="true"></span>
                    <small style="font-family:monospace;color:#64748b;font-size:11px;display:block;margin-top:4px;"><?= e($refCode) ?></small>
                </div>
                <div style="text-align:right;">
                    <small style="color:#64748b;font-size:11px;display:block;">SerenityCare Electronic Verification</small>
                    <small style="color:#94a3b8;font-size:10px;">Generated: <?= date('Y-m-d H:i:s') ?> PHT</small>
                </div>
            </div>
        </article>
    </div>
</body>
</html>
