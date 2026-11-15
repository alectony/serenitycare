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
        'gmail_smtp_host'     => 'smtp.gmail.com',
        'gmail_smtp_user'     => '',
        'gmail_smtp_pass'     => '',
        'otp_auth_mode'       => 'hybrid', // 'supabase', 'gmail', or 'hybrid'
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

/**
 * Creates an in-app notification for a user.
 */
function create_notification($userId, $title, $message, $type = 'system', $token = null) {
    if (!$userId) return false;
    $res = sb_request('POST', 'notifications', '', [
        'user_id'    => $userId,
        'title'      => mb_substr((string) $title, 0, 150),
        'message'    => mb_substr((string) $message, 0, 500),
        'type'       => $type,
        'is_read'    => false,
        'created_at' => date('c'),
    ], $token, 'return=minimal');

    // Also persist in local notifications backup
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/notifications.json';
    $notifs = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $notifs[] = [
        'id'         => bin2hex(random_bytes(8)),
        'user_id'    => $userId,
        'title'      => $title,
        'message'    => $message,
        'type'       => $type,
        'is_read'    => false,
        'created_at' => date('c'),
    ];
    if (count($notifs) > 200) {
        $notifs = array_slice($notifs, -200);
    }
    @file_put_contents($file, json_encode($notifs, JSON_PRETTY_PRINT));

    return ($res['status'] >= 200 && $res['status'] < 300);
}

/**
 * Returns count of unread notifications for a user.
 */
function get_unread_notification_count($userId, $token) {
    if (!$userId) return 0;
    $r = sb_request('GET', 'notifications', 'select=id&user_id=eq.' . urlencode($userId) . '&is_read=eq.false', null, $token);
    if ($r['status'] === 200 && is_array($r['data'])) {
        return count($r['data']);
    }
    // Check local fallback
    $file = __DIR__ . '/data/notifications.json';
    if (file_exists($file)) {
        $notifs = json_decode(file_get_contents($file), true) ?: [];
        $count = 0;
        foreach ($notifs as $n) {
            if ($n['user_id'] === $userId && empty($n['is_read'])) {
                $count++;
            }
        }
        return $count;
    }
    return 0;
}

/**
 * Generate a clean, unique Google Meet link for online appointments.
 */
function generate_google_meet_link($seed = '') {
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $p1 = ''; for ($i = 0; $i < 3; $i++) { $p1 .= $chars[random_int(0, 25)]; }
    $p2 = ''; for ($i = 0; $i < 4; $i++) { $p2 .= $chars[random_int(0, 25)]; }
    $p3 = ''; for ($i = 0; $i < 3; $i++) { $p3 .= $chars[random_int(0, 25)]; }
    return 'https://meet.google.com/' . $p1 . '-' . $p2 . '-' . $p3;
}

?>