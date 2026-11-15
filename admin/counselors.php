<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];
$msg = '';
$err = '';

// Load counselor job applications
$appsFile = __DIR__ . '/../includes/data/counselor_applications.json';
$applications = file_exists($appsFile) ? (json_decode(file_get_contents($appsFile), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'app_status') {
        $appId = $_POST['app_id'] ?? '';
        $newAppStatus = $_POST['new_status'] ?? 'reviewed';
        $updated = false;
        foreach ($applications as &$ap) {
            if ($ap['id'] === $appId) {
                $ap['status'] = $newAppStatus;
                $updated = true;
                break;
            }
        }
        if ($updated) {
            @file_put_contents($appsFile, json_encode($applications, JSON_PRETTY_PRINT));
            $msg = 'Application ' . $appId . ' status changed to ' . ucfirst($newAppStatus) . '.';
            audit_from($me, 'counselor_app_' . $newAppStatus, 'application', $appId);
        }
    } else {
        $id = $_POST['counselor_id'] ?? '';
        $action = $_POST['action'] ?? '';
        $note = trim($_POST['note'] ?? '');
        $map = ['verify' => 'verified', 'reject' => 'rejected', 'reset' => 'pending'];

        if (!is_uuid($id) || !isset($map[$action])) {
            $err = 'Invalid request.';
        } else {
            $newStatus = $map[$action];
            $ok = true;

            if ($newStatus === 'verified') {   // a counselor must have submitted credentials first
                $d = sb_request('GET', 'counselor_details', 'select=license_number&counselor_id=eq.' . urlencode($id), null, $token);
                $ok = ($d['status'] === 200 && !empty($d['data'][0]['license_number']));
                if (!$ok) { $err = 'This counselor has not submitted a license number yet, so they cannot be verified.'; }
            }
            if ($ok) {
                $r = sb_request('POST', 'counselor_verifications', 'on_conflict=counselor_id', [
                    'counselor_id' => $id, 'status' => $newStatus,
                    'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
                    'reviewed_by' => $me['user']['id'], 'reviewed_at' => date('c'),
                ], $token, 'resolution=merge-duplicates,return=representation');
                if ($r['status'] === 200 || $r['status'] === 201) {
                    audit_from($me, 'counselor_' . $newStatus, 'user', $id, $note);
                    $msg = 'Counselor marked as ' . $newStatus . '.';
                } else {
                    $err = 'Could not save the decision.';
                }
            }
        }
    }
}

$cr = sb_request('GET', 'profiles', 'select=id,full_name,email,is_active&role=eq.counselor&order=full_name.asc', null, $token);
$failed = ($cr['status'] !== 200 || !is_array($cr['data']));
$counselors = $failed ? [] : $cr['data'];

$details = [];
$dr = sb_request('GET', 'counselor_details', 'select=counselor_id,specialization,license_number', null, $token);
if ($dr['status'] === 200 && is_array($dr['data'])) { foreach ($dr['data'] as $d) { $details[$d['counselor_id']] = $d; } }

$verif = [];
$vr = sb_request('GET', 'counselor_verifications', 'select=counselor_id,status,note,reviewed_at', null, $token);
if ($vr['status'] === 200 && is_array($vr['data'])) { foreach ($vr['data'] as $v) { $verif[$v['counselor_id']] = $v; } }

$rows = [];
foreach ($counselors as $c) {
    $c['status'] = $verif[$c['id']]['status'] ?? 'pending';
    $c['note'] = $verif[$c['id']]['note'] ?? '';
    $c['reviewed_at'] = $verif[$c['id']]['reviewed_at'] ?? '';
    $c['details'] = $details[$c['id']] ?? null;
    $rows[] = $c;
}
$counts = ['pending' => 0, 'verified' => 0, 'rejected' => 0];
foreach ($rows as $r) { $counts[$r['status']]++; }

$filter = $_GET['status'] ?? 'pending';
$allowedFilters = ['all', 'pending', 'verified', 'rejected', 'applications'];
if (!in_array($filter, $allowedFilters, true)) { $filter = 'pending'; }
$shown = $filter === 'all' ? $rows : array_values(array_filter($rows, fn($r) => $r['status'] === $filter));

page_start('admin', 'Counselor Verification', '/admin/counselors.php', 'Counselor Staffing & Verification', 'Manage counselor credentials, hiring applications, and booking permissions.');
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>
                
            <div class="stats">
                <?php echo stat_card('Pending review', $counts['pending'], 'Awaiting staff decision', 'clock'); ?>
                <?php echo stat_card('Verified', $counts['verified'], 'Can receive student bookings', 'shield'); ?>
                <?php echo stat_card('Job Applications', count($applications), 'Resumes submitted via landing page', 'file'); ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <div>
                        <h2><?php echo $filter === 'applications' ? 'Counselor Hiring Applications &amp; Resumes' : 'Guidance Counselors'; ?></h2>
                        <p class="card-sub"><?php echo $filter === 'applications' ? 'Review resumes, credentials, and experience submitted by job applicants' : 'Check counselor license, then verify or reject'; ?></p>
                    </div>
                    <div class="tabs">
                        <?php foreach (['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All Staff', 'applications' => 'Resumes / Hiring (' . count($applications) . ')'] as $k => $label): ?>
                            <a class="tab<?php echo $filter === $k ? ' active' : ''; ?>" href="/admin/counselors.php?status=<?php echo e($k); ?>"><?php echo e($label); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($filter === 'applications'): ?>
                    <?php if (empty($applications)): ?>
                        <p class="empty" style="text-align:center;padding:36px 0;">No counselor applications or resumes submitted yet. Applicants can apply via the Landing Page "We're Hiring" post-it.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Applicant Name &amp; Contact</th>
                                    <th>Credentials &amp; PRC License</th>
                                    <th>Experience &amp; Specialization</th>
                                    <th>Resume / CV</th>
                                    <th>Status &amp; Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($applications) as $app): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($app['full_name']); ?></strong><br>
                                            <small><?php echo e($app['email']); ?> &bull; <?php echo e($app['phone']); ?></small><br>
                                            <small style="color:var(--muted);"><?php echo e(fmt_dt($app['submitted_at'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="status active" style="font-size:11px;padding:2px 6px;"><?php echo e($app['license_type']); ?></span>
                                            <strong style="font-size:13px;display:block;margin-top:2px;">#<?php echo e($app['license_no']); ?></strong>
                                            <small style="color:var(--ink-secondary);"><?php echo e($app['degree'] ?: 'Degree on file'); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo (int) $app['experience_years']; ?> Years</strong><br>
                                            <small style="color:var(--ink-secondary);"><?php echo e($app['specializations'] ?: 'General counseling'); ?></small>
                                            <?php if (!empty($app['cover_note'])): ?>
                                                <div style="font-size:11.5px;color:var(--muted);margin-top:4px;max-width:240px;line-height:1.4;">&ldquo;<?php echo e(mb_strimwidth($app['cover_note'], 0, 90, '...')); ?>&rdquo;</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($app['resume_file'])): ?>
                                                <a href="<?php echo e($app['resume_file']); ?>" target="_blank" download="<?php echo e($app['resume_name'] ?: 'Resume'); ?>" class="btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
                                                    <?php echo icon('file'); ?> Download CV
                                                </a>
                                                <small style="display:block;font-size:10.5px;color:var(--muted);margin-top:2px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($app['resume_name']); ?></small>
                                            <?php else: ?>
                                                <small style="color:var(--muted);">No file attached</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;flex-direction:column;gap:6px;">
                                                <span class="status <?php echo in_array($app['status'] ?? '', ['interview', 'accepted']) ? 'active' : (($app['status'] ?? '') === 'rejected' ? 'rejected' : 'pending'); ?>" style="font-size:11px;">
                                                    <?php echo e(ucfirst(str_replace('_', ' ', $app['status'] ?? 'pending'))); ?>
                                                </span>
                                                <form method="POST" action="/admin/counselors.php?status=applications" style="display:flex;gap:4px;flex-wrap:wrap;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="app_status">
                                                    <input type="hidden" name="app_id" value="<?php echo e($app['id']); ?>">
                                                    <?php if (($app['status'] ?? '') !== 'interview'): ?>
                                                        <button type="submit" name="new_status" value="interview" class="btn-success-sm" title="Invite applicant for interview">Interview</button>
                                                    <?php endif; ?>
                                                    <?php if (($app['status'] ?? '') !== 'accepted'): ?>
                                                        <button type="submit" name="new_status" value="accepted" class="btn-sm" title="Accept candidate">Hire</button>
                                                    <?php endif; ?>
                                                    <?php if (($app['status'] ?? '') !== 'archived'): ?>
                                                        <button type="submit" name="new_status" value="archived" class="btn-outline-danger-sm" title="Archive application">&times;</button>
                                                    <?php endif; ?>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                <?php else: ?>
                    <?php if ($failed): ?><div class="alert error">Could not load counselors. Please refresh.</div><?php endif; ?>

                    <table class="data-table">
                        <thead><tr><th>Counselor</th><th>Specialization</th><th>License no.</th><th>Status</th><th>Decision</th></tr></thead>
                        <tbody>
                        <?php if (!$shown): ?>
                            <tr><td colspan="5">No counselors in this list.</td></tr>
                        <?php else: foreach ($shown as $c): ?>
                            <tr>
                                <td><strong><?php echo e($c['full_name'] ?? '-'); ?></strong><br><small><?php echo e($c['email'] ?? ''); ?></small></td>
                                <td><?php echo e($c['details']['specialization'] ?? '—'); ?></td>
                                <td><?php echo $c['details'] && $c['details']['license_number'] ? e($c['details']['license_number']) : '<small>Not submitted</small>'; ?></td>
                                <td><?php echo status_badge($c['status']); ?><?php if ($c['note']): ?><br><small><?php echo e($c['note']); ?></small><?php endif; ?></td>
                                <td>
                                    <form method="POST" action="/admin/counselors.php?status=<?php echo e($filter); ?>" class="decision-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="counselor_id" value="<?php echo e($c['id']); ?>">
                                        <input type="text" name="note" maxlength="300" placeholder="Optional note" aria-label="Note">
                                        <div class="row-actions">
                                            <?php if ($c['status'] !== 'verified'): ?><button type="submit" name="action" value="verify" class="btn-success-sm">Verify</button><?php endif; ?>
                                            <?php if ($c['status'] !== 'rejected'): ?><button type="submit" name="action" value="reject" class="btn-outline-danger-sm">Reject</button><?php endif; ?>
                                            <?php if ($c['status'] !== 'pending'): ?><button type="submit" name="action" value="reset" class="btn-sm">Reset</button><?php endif; ?>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
<?php page_end(); ?>
