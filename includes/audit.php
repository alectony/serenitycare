<?php
// Audit trail + system settings helpers (shared by every role).
require_once __DIR__ . '/supabase.php';

/** Append one audit entry about the signed-in user. Failures never break the page. */
function audit_log($token, $actorId, $actorName, $action, $targetType = '', $targetId = '', $detail = '') {
    if (!$token || !$actorId) {
        return false;
    }
    $r = sb_request('POST', 'audit_logs', '', [
        'actor_id'    => $actorId,
        'actor_name'  => mb_substr((string) $actorName, 0, 120),
        'action'      => $action,
        'target_type' => $targetType !== '' ? $targetType : null,
        'target_id'   => $targetId !== '' ? (string) $targetId : null,
        'detail'      => $detail !== '' ? mb_substr((string) $detail, 0, 500) : null,
    ], $token, 'return=minimal');
    return $r['status'] >= 200 && $r['status'] < 300;
}

/** Shortcut when you already have the $me array from require_role(). */
function audit_from($me, $action, $targetType = '', $targetId = '', $detail = '') {
    return audit_log($me['token'], $me['user']['id'], $me['profile']['full_name'] ?? '', $action, $targetType, $targetId, $detail);
}

function settings_defaults() {
    return [
        'office_open'         => '08:00',
        'office_close'        => '17:00',
        'working_days'        => 'Monday,Tuesday,Wednesday,Thursday,Friday',
        'session_minutes'     => '50',
        'booking_window_days' => '60',
        'idle_timeout'        => '30',
        'require_2fa'         => '0',
        'email_notifications' => '1',
        'sms_reminders'       => '0',
        'audit_retention'     => '90',
        'patient_reg_mode'    => 'open',
        'default_theme'       => 'system',
        'buffer_minutes'      => '15',
        'max_daily_sessions'  => '6',
        'auto_share_dx'       => '0',
        'emergency_banner'    => '1',
    ];
}

function get_settings($token) {
    $s = settings_defaults();
    $r = sb_request('GET', 'system_settings', 'select=key,value', null, $token);
    if ($r['status'] === 200 && is_array($r['data'])) {
        foreach ($r['data'] as $row) {
            $s[$row['key']] = (string) $row['value'];
        }
    }
    return $s;
}

function settings_days($settings) {
    return array_values(array_filter(array_map('trim', explode(',', (string) ($settings['working_days'] ?? '')))));
}
?>