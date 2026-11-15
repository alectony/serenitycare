<?php
// One-Time Password (OTP) helper for SerenityCare.
// Codes live in the Supabase table public.otp_codes (see otp_schema.sql) so they work on
// serverless hosting, are stored hashed, expire after 10 minutes and allow 5 tries.
// The email is sent through Gmail SMTP (includes/mailer.php).
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';

const OTP_TTL_SECONDS = 600;     // code valid for 10 minutes
const OTP_RESEND_SECONDS = 60;   // minimum wait between two codes for the same email
const OTP_MAX_ATTEMPTS = 5;

/** REST call to otp_codes with the server-side service key. */
function otp_rest($method, $query = '', $body = null, $prefer = 'return=minimal') {
    $key = sb_service_key();
    if ($key === '') return ['status' => 0, 'data' => null];
    $c = get_supabase_config();
    return sb_http($method, $c['url'] . '/rest/v1/otp_codes' . ($query !== '' ? '?' . $query : ''), [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: ' . $prefer,
    ], $body);
}

function otp_hash($email, $purpose, $code) {
    return hash_hmac('sha256', $email . '|' . $purpose . '|' . $code, sb_service_key());
}

/** Find a profile by exact (lower-case) email using the service key. */
function otp_find_profile($email) {
    $email = strtolower(trim((string) $email));
    $key = sb_service_key();
    $c = get_supabase_config();
    $headers = $key !== '' ? ['apikey: ' . $key, 'Authorization: Bearer ' . $key] : ['apikey: ' . $c['anon_key']];
    $r = sb_http('GET', $c['url'] . '/rest/v1/profiles?select=id,full_name,role,is_active,email&limit=1&email=eq.' . urlencode($email), $headers);
    return ($r['status'] === 200 && !empty($r['data'][0])) ? $r['data'][0] : null;
}

/** Create + store a fresh 6-digit code. Returns the code, or false if it could not be stored. */
function create_local_otp($email, $purpose = 'login') {
    $email = strtolower(trim($email));
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    otp_rest('DELETE', 'expires_at=lt.' . urlencode(gmdate('c')));   // tidy up old rows
    $r = otp_rest('POST', 'on_conflict=email,purpose', [[
        'email'      => $email,
        'purpose'    => $purpose,
        'code_hash'  => otp_hash($email, $purpose, $code),
        'expires_at' => gmdate('c', time() + OTP_TTL_SECONDS),
        'attempts'   => 0,
        'created_at' => gmdate('c'),
    ]], 'resolution=merge-duplicates,return=minimal');
    if ($r['status'] < 200 || $r['status'] >= 300) {
        error_log('create_local_otp failed: HTTP ' . $r['status'] . ' ' . substr(json_encode($r['data']), 0, 300));
        return false;
    }
    return $code;
}

/** Check a code. Returns true once, then the code is deleted. */
function verify_local_otp($email, $code, $purpose = 'login') {
    $email = strtolower(trim($email));
    $code = trim((string) $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;

    $q = 'select=id,code_hash,expires_at,attempts&email=eq.' . urlencode($email) . '&purpose=eq.' . urlencode($purpose);
    $r = otp_rest('GET', $q, null, 'return=representation');
    $row = ($r['status'] === 200 && !empty($r['data'][0])) ? $r['data'][0] : null;
    if (!$row) return false;

    $rowFilter = 'id=eq.' . urlencode($row['id']);
    if (strtotime($row['expires_at']) < time() || (int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        otp_rest('DELETE', $rowFilter);
        return false;
    }
    if (hash_equals($row['code_hash'], otp_hash($email, $purpose, $code))) {
        otp_rest('DELETE', $rowFilter);          // single use
        return true;
    }
    otp_rest('PATCH', $rowFilter, ['attempts' => (int) $row['attempts'] + 1]);
    return false;
}

/**
 * Email a 6-digit code. Returns ['success' => bool, 'message' => string].
 * For unknown emails it answers the same way but sends nothing (so nobody can probe which emails exist).
 */
function dispatch_otp($email, $purpose = 'login') {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please provide a valid email address.'];
    }
    if (sb_service_key() === '') {
        error_log('dispatch_otp: SUPABASE_SERVICE_ROLE_KEY is not set');
        return ['success' => false, 'message' => 'Email sign-in is not set up yet. Please contact the administrator.'];
    }

    $generic = 'If this email is registered, a 6-digit code has been sent to ' . htmlspecialchars($email)
             . '. It expires in 10 minutes. Please check your inbox and spam folder.';

    if (!otp_find_profile($email)) {
        return ['success' => true, 'message' => $generic];
    }

    // throttle: one code per minute per email
    $q = 'select=created_at&email=eq.' . urlencode($email) . '&purpose=eq.' . urlencode($purpose);
    $prev = otp_rest('GET', $q, null, 'return=representation');
    if ($prev['status'] === 200 && !empty($prev['data'][0]['created_at'])) {
        $wait = OTP_RESEND_SECONDS - (time() - strtotime($prev['data'][0]['created_at']));
        if ($wait > 0) {
            return ['success' => false, 'message' => 'Please wait ' . $wait . ' seconds before requesting another code.'];
        }
    }

    $code = create_local_otp($email, $purpose);
    if ($code === false) {
        return ['success' => false, 'message' => 'Could not create a verification code. Please try again later.'];
    }

    $what = $purpose === 'recovery' ? 'reset your password' : 'sign in';
    $subject = $purpose === 'recovery' ? 'SerenityCare password reset code' : 'SerenityCare sign-in code';
    $text = "Hello,\n\nUse this code to $what on SerenityCare:\n\n    $code\n\n"
          . "It expires in 10 minutes. If you did not request it, you can ignore this email.\n\n"
          . "Warm regards,\nSerenityCare Student Guidance Office";
    $html = '<div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:24px;border:1px solid #e3ebe8;border-radius:12px">'
          . '<h2 style="margin:0 0 12px;color:#1f7a44">SerenityCare</h2>'
          . '<p>Use this code to ' . $what . ':</p>'
          . '<p style="font-size:32px;font-weight:700;letter-spacing:8px;margin:16px 0">' . $code . '</p>'
          . '<p style="color:#6b7a80;font-size:13px">It expires in 10 minutes. If you did not request it, you can ignore this email.</p></div>';

    $sent = smtp_send($email, $subject, $text, $html);
    if (!$sent['ok']) {
        otp_rest('DELETE', 'email=eq.' . urlencode($email) . '&purpose=eq.' . urlencode($purpose));
        return ['success' => false, 'message' => 'We could not send the email right now. Please try again in a minute or contact the administrator.'];
    }
    return ['success' => true, 'message' => $generic];
}