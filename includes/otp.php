<?php
// One-Time Password (OTP) & Gmail/Supabase Auth Helper for SerenityCare
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/audit.php';

function get_otp_store_file() {
    // Vercel (and most serverless) has a read-only filesystem except /tmp
    if (isset($_SERVER['VERCEL']) || !is_writable(__DIR__)) {
        return '/tmp/sc_otp_codes.json';
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/otp_codes.json';
}

function read_otp_store() {
    $file = get_otp_store_file();
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode((string) $content, true);
    return is_array($data) ? $data : [];
}

function write_otp_store(array $data) {
    $file = get_otp_store_file();
    // Prune codes older than 15 minutes
    $now = time();
    $pruned = [];
    foreach ($data as $email => $record) {
        if (!empty($record['expires_at']) && $record['expires_at'] > $now) {
            $pruned[$email] = $record;
        }
    }
    file_put_contents($file, json_encode($pruned, JSON_PRETTY_PRINT));
}

/**
 * Generate a 6-digit OTP code for an email and store it with 10 minutes expiry.
 */
function create_local_otp($email, $purpose = 'login') {
    $email = strtolower(trim($email));
    $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $store = read_otp_store();
    $store[$email] = [
        'code'       => $code,
        'purpose'    => $purpose,
        'created_at' => time(),
        'expires_at' => time() + 600, // 10 minutes
        'attempts'   => 0,
    ];
    write_otp_store($store);
    return $code;
}

/**
 * Verify a 6-digit OTP code. Returns true if valid and deletes it.
 */
function verify_local_otp($email, $code, $purpose = 'login') {
    $email = strtolower(trim($email));
    $store = read_otp_store();
    if (empty($store[$email])) {
        return false;
    }
    $rec = $store[$email];
    if (time() > $rec['expires_at']) {
        unset($store[$email]);
        write_otp_store($store);
        return false;
    }
    if ($rec['purpose'] !== $purpose) {
        return false;
    }
    if ((string) $rec['code'] === trim((string) $code)) {
        unset($store[$email]);
        write_otp_store($store);
        return true;
    }
    // Increment attempts
    $store[$email]['attempts'] = ($store[$email]['attempts'] ?? 0) + 1;
    if ($store[$email]['attempts'] >= 5) {
        unset($store[$email]);
    }
    write_otp_store($store);
    return false;
}

/**
 * Send OTP via Supabase Auth or Gmail SMTP or local fallback.
 * Returns ['success' => bool, 'message' => string, 'code_preview' => string|null]
 */
function dispatch_otp($email, $purpose = 'login') {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please provide a valid email address.'];
    }

    // First generate local fallback code so verification always succeeds even if email is delayed
    $code = create_local_otp($email, $purpose);

    // Try Supabase OTP endpoint
    $sbSent = false;
    if (function_exists('sb_send_otp')) {
        $sbRes = sb_send_otp($email);
        if ($sbRes['status'] === 200 || $sbRes['status'] === 201) {
            $sbSent = true;
        }
    }

    // Try sending via Gmail/PHP mail() if configured
    $subject = 'Your SerenityCare One-Time Password (OTP): ' . $code;
    $body = "Hello,\n\nYour 6-digit SerenityCare verification code is:\n\n    " . $code . "\n\nThis code will expire in 10 minutes. If you did not request this code, please ignore this email.\n\nWarm regards,\nSerenityCare Student Guidance Office";
    
    // Send email using standard mail()
    $headers = "From: SerenityCare Guidance <no-reply@serenitycare.edu>\r\n" .
               "Reply-To: no-reply@serenitycare.edu\r\n" .
               "X-Mailer: PHP/" . phpversion();
    @mail($email, $subject, $body, $headers);

    return [
        'success' => true,
        'message' => 'A verification code has been dispatched to ' . htmlspecialchars($email) . '. Please check your email inbox (and spam folder).',
    ];
}
