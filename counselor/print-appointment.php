<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

$id = $_GET['id'] ?? '';
if (!is_uuid($id)) {
    die('Invalid appointment reference.');
}

$ar = sb_request('GET', 'appointments',
    'select=id,scheduled_at,status,reason,patient_id,patient:profiles!patient_id(id,full_name,email)&id=eq.' . urlencode($id) . '&counselor_id=eq.' . urlencode($uid),
    null, $token);

$appt = ($ar['status'] === 200 && !empty($ar['data'][0])) ? $ar['data'][0] : null;
if (!$appt) {
    die('Appointment record not found or access denied.');
}

$patientId = $appt['patient_id'];
$pr = sb_request('GET', 'profiles', 'select=full_name,email,details:patient_details(*)&id=eq.' . urlencode($patientId), null, $token);
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

$refCode = 'SC-CASE-' . strtoupper(substr(md5($appt['id']), 0, 8));
$ts = strtotime($appt['scheduled_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Counselor Session Intake - <?= e($refCode) ?> - SerenityCare</title>
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
            max-width: 820px;
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
            font-size: 13.5px;
            margin: 6px 0 0;
            color: #475569;
            font-weight: 600;
        }
        .slip-title {
            display: inline-block;
            margin-top: 12px;
            padding: 4px 14px;
            background: #047857;
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
            gap: 16px 24px;
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
        .clinical-notes-box {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 16px;
            min-height: 140px;
            margin-top: 20px;
            background: #fdfdfd;
        }
        .clinical-notes-box h4 {
            margin: 0 0 10px;
            font-size: 12.5px;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
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
    <header class="print-screen-bar">
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="/counselor/schedule.php" class="btn-sm" style="text-decoration:none;">&larr; Back to Schedule</a>
            <span style="font-weight:600;font-size:14px;color:var(--ink);">Counselor Intake Slip &bull; <?= e($refCode) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($isOnline && !empty($meetLink)): ?>
                <a href="<?= e($meetLink) ?>" target="_blank" rel="noopener" class="btn-sm btn-success-sm" style="display:inline-flex;align-items:center;gap:4px;">
                    <?= icon('video') ?> Start Google Meet
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-sm" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;">
                <?= icon('printer') ?> Print Intake Sheet
            </button>
        </div>
    </header>

    <div class="slip-outer">
        <article class="official-slip">
            <header class="official-header">
                <h1>SERENITYCARE COUNSELING SERVICES</h1>
                <h2>Clinical Intake &amp; Session Management Docket</h2>
                <div class="slip-title">CONFIDENTIAL COUNSELOR DOCKET</div>
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
                    <span class="slip-item-label">Grade / Section</span>
                    <span class="slip-item-value"><?= e(($details['grade_or_dept'] ?? 'Student') . ' - ' . ($details['section'] ?? 'Main')) ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Contact / Guardian</span>
                    <span class="slip-item-value"><?= e($details['contact'] ?? 'N/A') ?> (<?= e($details['guardian_name'] ?? 'Guardian') ?>)</span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Session Schedule</span>
                    <span class="slip-item-value"><?= e(date('F d, Y - g:i A', $ts)) ?></span>
                </div>
                <div class="slip-item">
                    <span class="slip-item-label">Session Format</span>
                    <span class="slip-item-value">
                        <?= $isOnline ? '🟢 Online (Google Meet: ' . e($meetLink ?: 'Active') . ')' : '🏢 In-Person (Room 204)' ?>
                    </span>
                </div>
            </div>

            <div class="slip-item" style="border:1px solid #e2e8f0;padding:12px;border-radius:6px;background:#f8fafc;">
                <span class="slip-item-label">Stated Student Concern</span>
                <span class="slip-item-value" style="font-size:13.5px;font-weight:500;line-height:1.5;color:#334155;"><?= e($cleanReason ?: 'General mental health guidance & consultation.') ?></span>
            </div>

            <div class="clinical-notes-box">
                <h4>Counselor Observations &amp; Session Notes</h4>
                <div style="border-bottom: 1px dotted #94a3b8; height: 32px;"></div>
                <div style="border-bottom: 1px dotted #94a3b8; height: 32px;"></div>
                <div style="border-bottom: 1px dotted #94a3b8; height: 32px;"></div>
                <div style="border-bottom: 1px dotted #94a3b8; height: 32px;"></div>
            </div>

            <div style="display:flex;justify-content:space-between;margin-top:36px;padding-top:14px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                <div>Docket ID: <strong><?= e($refCode) ?></strong></div>
                <div>Counselor: <strong><?= e($me['profile']['full_name'] ?? 'Guidance Counselor') ?></strong></div>
                <div>Status: <strong><?= e(ucfirst($appt['status'])) ?></strong></div>
            </div>
        </article>
    </div>
</body>
</html>
