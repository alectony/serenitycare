<?php
// Chat API. Every conversation lives in the Supabase table public.chat_messages.
//   GET  /api/chat.php?with=<user-uuid>            -> { messages: [...] } oldest first (by date sent)
//   POST /api/chat.php  {receiver_id, message}     -> saves the message
// The database trigger keeps only each account's 3 newest messages per conversation.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';   // is_uuid()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function chat_json($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/**
 * REST call. Uses the server-side service key when it is set (works for normal AND OTP logins;
 * safe because the caller was already verified below and every query is filtered by that user).
 * Falls back to the user's own token + Row Level Security when no service key exists.
 */
function chat_rest($method, $table, $query = '', $body = null, $prefer = 'return=representation') {
    global $token;
    $key = sb_service_key();
    if ($key === '') {
        return sb_request($method, $table, $query, $body, $token, $prefer);
    }
    $c = get_supabase_config();
    $r = sb_http($method, $c['url'] . '/rest/v1/' . $table . ($query !== '' ? '?' . $query : ''), [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: ' . $prefer,
    ], $body);
    return ['status' => $r['status'], 'data' => $r['data']];
}

// ---- Who is calling? (same checks as require_role, but answers in JSON) ----
$token = get_user_token();
if (!$token) chat_json(401, ['error' => 'Please log in again.']);

$user = sb_get_user($token);
if (!$user || empty($user['id'])) chat_json(401, ['error' => 'Your session expired. Please log in again.']);

$uid = $user['id'];
$profile = get_user_profile($uid, $token);
if (($profile['is_active'] ?? true) === false) chat_json(403, ['error' => 'This account is deactivated.']);

$role = $profile['role'] ?? 'patient';
if (!in_array($role, ['patient', 'counselor'], true)) {
    chat_json(403, ['error' => 'Chat is only available to patients and counselors.']);
}
$otherRole = ($role === 'patient') ? 'counselor' : 'patient';

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------- GET
if ($method === 'GET') {
    $with = $_GET['with'] ?? '';
    if (!is_uuid($with)) chat_json(400, ['error' => 'Missing or invalid conversation partner.']);

    $pair = "or=(and(sender_id.eq.$uid,receiver_id.eq.$with),and(sender_id.eq.$with,receiver_id.eq.$uid))";
    $r = chat_rest('GET', 'chat_messages',
        "select=id,sender_id,receiver_id,message,is_read,created_at&$pair&order=created_at.asc&limit=50");

    if ($r['status'] !== 200 || !is_array($r['data'])) {
        error_log('chat GET failed: HTTP ' . $r['status'] . ' ' . substr(json_encode($r['data']), 0, 300));
        chat_json(500, ['error' => 'Could not load messages.', 'code' => $r['data']['code'] ?? $r['status']]);
    }

    // mark what the partner sent me as read
    foreach ($r['data'] as $m) {
        if ($m['receiver_id'] === $uid && empty($m['is_read'])) {
            chat_rest('PATCH', 'chat_messages', "receiver_id=eq.$uid&sender_id=eq.$with&is_read=eq.false",
                ['is_read' => true], 'return=minimal');
            break;
        }
    }
    chat_json(200, ['messages' => $r['data']]);
}

// ---------------------------------------------------------------- POST
if ($method === 'POST') {
    // JSON-only: a cross-site HTML form cannot send this content type (basic CSRF defence)
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        chat_json(415, ['error' => 'Unsupported request.']);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    $receiver = is_array($in) ? ($in['receiver_id'] ?? '') : '';
    $text = is_array($in) ? trim((string) ($in['message'] ?? '')) : '';

    if (!is_uuid($receiver) || $receiver === $uid) chat_json(400, ['error' => 'Invalid recipient.']);
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len < 1) chat_json(400, ['error' => 'Message is empty.']);
    if ($len > 1000) chat_json(400, ['error' => 'Message is too long (max 1000 characters).']);

    // the recipient must exist, be active, and have the opposite role
    $pr = chat_rest('GET', 'profiles', 'select=id,role,is_active&id=eq.' . urlencode($receiver));
    $partner = ($pr['status'] === 200 && !empty($pr['data'][0])) ? $pr['data'][0] : null;
    if (!$partner || ($partner['role'] ?? '') !== $otherRole || ($partner['is_active'] ?? true) === false) {
        chat_json(403, ['error' => 'You can only message ' . $otherRole . 's.']);
    }

    // sender_id always comes from the verified login, never from the browser
    $r = chat_rest('POST', 'chat_messages', '', [
        'sender_id'   => $uid,
        'receiver_id' => $receiver,
        'message'     => $text,
    ]);

    if ($r['status'] === 201 || $r['status'] === 200) {
        chat_json(201, ['ok' => true, 'message' => $r['data'][0] ?? null]);
    }
    error_log('chat POST failed: HTTP ' . $r['status'] . ' ' . substr(json_encode($r['data']), 0, 300));
    $code = is_array($r['data']) ? ($r['data']['code'] ?? '') : '';
    $hint = ($code === 'PGRST205' || $code === '42P01') ? ' (chat table missing: run chat_schema.sql in Supabase)' : '';
    chat_json(500, ['error' => 'Could not send your message.' . $hint, 'code' => $code ?: $r['status']]);
}

chat_json(405, ['error' => 'Method not allowed.']);