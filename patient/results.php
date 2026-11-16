<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user  = require_role('patient');
$uid   = $user['user']['id'] ?? ($user['id'] ?? null);
$token = $user['token'];

function rows($r) { return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : []; }

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));

// Check both 'diagnoses' and 'results' tables for maximum compatibility
$list = rows(sb_request('GET', 'diagnoses',
    'select=id,diagnosis,recommendations,created_at,counselor:profiles!counselor_id(full_name)&patient_id=eq.' . urlencode($uid) . '&shared_with_patient=eq.true&order=created_at.desc&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage),
    null, $token));

if (empty($list)) {
    $list = rows(sb_request('GET', 'results',
        'select=id,diagnosis,recommendation,created_at,counselor:profiles!counselor_id(full_name)&patient_id=eq.' . urlencode($uid) . '&shared=eq.true&order=created_at.desc&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage),
        null, $token));
}

$hasNext = count($list) > $perPage;
$list    = array_slice($list, 0, $perPage);

// The result currently open (?id=...)
$open = null;
$id   = $_GET['id'] ?? ($list[0]['id'] ?? '');
if (is_uuid($id)) {
    $openRes = rows(sb_request('GET', 'diagnoses',
        'select=id,diagnosis,recommendations,created_at,counselor:profiles!counselor_id(full_name)&id=eq.' . urlencode($id) . '&patient_id=eq.' . urlencode($uid),
        null, $token));
    if (!empty($openRes)) {
        $open = $openRes[0];
    } else {
        $openRes2 = rows(sb_request('GET', 'results',
            'select=id,diagnosis,recommendation,created_at,counselor:profiles!counselor_id(full_name)&id=eq.' . urlencode($id) . '&patient_id=eq.' . urlencode($uid),
            null, $token));
        $open = $openRes2[0] ?? null;
    }
}

$rightHeader = patient_header($uid, $token);
page_start('patient', 'Diagnosis & Results', '/patient/results.php', 'Guidance Diagnoses & Results', 'Official counseling assessments and mental health recommendations shared with you.', $rightHeader);
?>
<?= patient_search_modal($token) ?>
<div class="content-grid">

  <section class="card">
    <div class="card-head">
      <div><h2>Shared Guidance Records</h2><p class="card-sub">Confidential assessments from your counselor</p></div>
    </div>

    <?php if (!$list): ?>
      <p class="empty" style="padding:28px 0;text-align:center;">No diagnostic reports have been shared yet. When your counselor completes an assessment and shares it, it will appear here.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Date</th><th>Assessment / Diagnosis</th><th>Counselor</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): $t = strtotime($r['created_at']); $isOpen = ($open && $open['id'] === $r['id']); ?>
          <tr class="<?= $isOpen ? 'active-row' : '' ?>">
            <td>
              <div class="when">
                <span class="date-chip"><small><?= strtoupper(date('M', $t)) ?></small><strong><?= date('j', $t) ?></strong></span>
                <small><?= date('Y', $t) ?></small>
              </div>
            </td>
            <td><strong><?= e($r['diagnosis']) ?></strong></td>
            <td><?= e($r['counselor']['full_name'] ?? 'Counselor') ?></td>
            <td class="right"><a class="btn-sm" href="?id=<?= e($r['id']) ?>">View Report</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?= pager($page, $hasNext) ?>
    <?php endif; ?>
  </section>

  <div class="stack">
    <?php if ($open): 
        $rec = $open['recommendations'] ?? ($open['recommendation'] ?? '');
    ?>
      <section class="card details-panel print-report-container">
        <div class="card-head">
          <div>
            <h2>Clinical Assessment</h2>
            <small style="color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;font-size:11px;">Official Guidance Record</small>
          </div>
          <button type="button" class="btn-sm" onclick="window.print()" title="Print Diagnostic Report">
            <?= icon('printer') ?> Print Report
          </button>
        </div>
        
        <div class="print-doc-header" style="display:none;">
            <div style="text-align:center;border-bottom:2px solid #334155;padding-bottom:12px;margin-bottom:16px;">
                <h2 style="margin:0;color:#0f172a;font-size:20px;">SERENITYCARE GUIDANCE SERVICES</h2>
                <p style="margin:4px 0 0;font-size:12px;color:#475569;">STUDENT MENTAL HEALTH &amp; CLINICAL ASSESSMENT REPORT</p>
                <small style="font-size:11px;color:#64748b;">Strictly Confidential &bull; Official Student Document</small>
            </div>
        </div>

        <div class="detail-row"><span>Date Evaluated</span><strong><?= e(fmt_dt($open['created_at'], 'F d, Y - g:i A')) ?></strong></div>
        <div class="detail-row"><span>Assessing Counselor</span><strong><?= e($open['counselor']['full_name'] ?? 'Guidance Counselor') ?></strong></div>
        <div class="detail-row"><span>Clinical Diagnosis</span><strong style="color:var(--ink);"><?= e($open['diagnosis']) ?></strong></div>
        
        <div style="margin-top:16px;">
            <p class="card-sub" style="font-weight:700;margin-bottom:6px;">Guidance Recommendations &amp; Action Plan</p>
            <div style="padding:14px;background:var(--surface-sunken);border-radius:var(--radius-sm);font-size:13.5px;line-height:1.6;border:1px solid var(--border-soft);">
                <?= !empty($rec) ? nl2br(e($rec)) : 'No specific home recommendations were attached to this record.' ?>
            </div>
        </div>

        <div class="details-actions" style="margin-top:20px;display:flex;gap:10px;">
          <a class="btn" href="/patient/appointment.php"><?= icon('calendar') ?> Book Follow-up Session</a>
          <button type="button" class="btn btn-secondary" onclick="window.print()"><?= icon('printer') ?> Print Official Slip</button>
        </div>

        <div class="print-doc-footer" style="display:none;margin-top:40px;padding-top:20px;border-top:1px dashed #94a3b8;">
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <div>
                    <p style="margin-bottom:30px;">Counselor Signature:</p>
                    <p>___________________________________<br><strong><?= e($open['counselor']['full_name'] ?? 'Guidance Counselor') ?></strong><br>Licensed Guidance Counselor (RGC)</p>
                </div>
                <div style="text-align:right;">
                    <p style="margin-bottom:30px;">Student Signature:</p>
                    <p>___________________________________<br>Student Signature over Printed Name</p>
                </div>
            </div>
        </div>
      </section>
    <?php elseif ($list): ?>
      <div class="note-box">
        <strong>Select a diagnostic assessment</strong>
        <small>Click "View Report" on any assessment to inspect recommendations and print your official certificate.</small>
      </div>
    <?php endif; ?>

    <div class="note-box">
      <strong>Confidential Student Health Record</strong>
      <small>All counseling assessments and diagnostic files are protected by clinical confidentiality laws. Only you and your authorized guidance counselor can access these records.</small>
    </div>
  </div>

</div>
<?php page_end(); ?>
