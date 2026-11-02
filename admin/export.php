<?php
// CSV download (admin only). No HTML output.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];

$type = $_GET['type'] ?? '';
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';

function range_filter($field, $from, $to) {
    $q = '';
    if ($from !== '') { $q .= '&' . $field . '=gte.' . rawurlencode($from . 'T00:00:00+08:00'); }
    if ($to !== '') { $q .= '&' . $field . '=lte.' . rawurlencode($to . 'T23:59:59+08:00'); }
    return $q;
}
// Stop spreadsheets from running a cell that starts with = + - @ as a formula.
function csv_cell($v) {
    $v = (string) $v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

$header = [];
$rows = [];
$failed = false;

if ($type === 'users') {
    $r = sb_request('GET', 'profiles', 'select=full_name,email,role,patient_type,grade_or_dept,phone,is_active,created_at&order=created_at.desc&limit=10000' . range_filter('created_at', $from, $to), null, $token);
    $failed = $r['status'] !== 200 || !is_array($r['data']);
    $header = ['Name', 'Email', 'Role', 'Patient type', 'Grade/Department', 'Phone', 'Active', 'Joined'];
    foreach ($failed ? [] : $r['data'] as $u) {
        $rows[] = [$u['full_name'] ?? '', $u['email'] ?? '', $u['role'], $u['patient_type'] ?? '', $u['grade_or_dept'] ?? '', $u['phone'] ?? '', ($u['is_active'] ?? true) ? 'yes' : 'no', fmt_dt($u['created_at'], 'Y-m-d H:i')];
    }
} elseif ($type === 'appointments') {
    $query = 'select=scheduled_at,status,reason,created_at,patient:profiles!patient_id(full_name),counselor:profiles!counselor_id(full_name)&order=scheduled_at.desc&limit=10000' . range_filter('scheduled_at', $from, $to);
    if (in_array($_GET['status'] ?? '', ['pending', 'confirmed', 'completed', 'declined', 'cancelled'], true)) { $query .= '&status=eq.' . $_GET['status']; }
    if (is_uuid($_GET['counselor_id'] ?? '')) { $query .= '&counselor_id=eq.' . urlencode($_GET['counselor_id']); }
    $r = sb_request('GET', 'appointments', $query, null, $token);
    $failed = $r['status'] !== 200 || !is_array($r['data']);
    $header = ['Date & time', 'Patient', 'Counselor', 'Status', 'Reason', 'Requested on'];
    foreach ($failed ? [] : $r['data'] as $a) {
        $rows[] = [fmt_dt($a['scheduled_at'], 'Y-m-d H:i'), $a['patient']['full_name'] ?? '', $a['counselor']['full_name'] ?? '', $a['status'], $a['reason'] ?? '', fmt_dt($a['created_at'], 'Y-m-d H:i')];
    }
} elseif ($type === 'audit') {
    $r = sb_request('GET', 'audit_logs', 'select=created_at,actor_name,action,target_type,target_id,detail&order=created_at.desc&limit=10000' . range_filter('created_at', $from, $to), null, $token);
    $failed = $r['status'] !== 200 || !is_array($r['data']);
    $header = ['Time', 'Actor', 'Action', 'Target type', 'Target id', 'Detail'];
    foreach ($failed ? [] : $r['data'] as $l) {
        $rows[] = [fmt_dt($l['created_at'], 'Y-m-d H:i:s'), $l['actor_name'] ?? '', $l['action'], $l['target_type'] ?? '', $l['target_id'] ?? '', $l['detail'] ?? ''];
    }
} else {
    http_response_code(400);
    exit('Unknown export type.');
}

if ($failed) {
    http_response_code(502);
    exit('Could not load the data for this export.');
}

audit_from($me, 'export', $type, '', count($rows) . ' rows' . ($from || $to ? ' (' . ($from ?: '…') . ' to ' . ($to ?: '…') . ')' : ''));

$filename = 'serenitycare-' . $type . '-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows accents correctly
fputcsv($out, $header, ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($out, array_map('csv_cell', $row), ',', '"', '\\');
}
fclose($out);
?>