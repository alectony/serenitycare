<?php
// Minimal Gmail SMTP sender (no libraries needed, works on Vercel).
// Env vars (Vercel -> Settings -> Environment Variables):
//   GMAIL_USER          e.g. yourschool.guidance@gmail.com
//   GMAIL_APP_PASSWORD  16-character Google "App password" (NOT your normal password)
//   MAIL_FROM_NAME      optional, default "SerenityCare Guidance"

function mail_env($name) {
    return trim((string) (getenv($name) ?: ($_ENV[$name] ?? '')));
}

function mime_header_text($s) {
    $s = str_replace(["\r", "\n"], ' ', (string) $s);
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/** Returns ['ok' => bool, 'error' => string]. Details go to error_log (Vercel function logs). */
function smtp_send($to, $subject, $text, $html = '') {
    $user = mail_env('GMAIL_USER');
    $pass = preg_replace('/\s+/', '', mail_env('GMAIL_APP_PASSWORD'));
    if ($user === '' || $pass === '') {
        error_log('smtp_send: GMAIL_USER or GMAIL_APP_PASSWORD is not set');
        return ['ok' => false, 'error' => 'Email is not configured.'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient.'];
    }
    $fromName = mail_env('MAIL_FROM_NAME') ?: 'SerenityCare Guidance';

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("smtp_send: cannot connect to smtp.gmail.com:465 ($errno) $errstr");
        return ['ok' => false, 'error' => 'Cannot reach the mail server.'];
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;   // last line of a reply
        }
        return $data;
    };
    $step = function ($command, $expect) use ($fp, $read) {
        if ($command !== null) fwrite($fp, $command . "\r\n");
        $reply = $read();
        $ok = in_array(substr($reply, 0, 3), (array) $expect, true);
        return [$ok, trim($reply)];
    };
    $fail = function ($what, $reply) use ($fp) {
        error_log("smtp_send: $what failed: $reply");
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return ['ok' => false, 'error' => 'The mail server rejected the message.'];
    };

    [$ok, $r] = $step(null, '220');                          if (!$ok) return $fail('greeting', $r);
    [$ok, $r] = $step('EHLO serenitycare.local', '250');     if (!$ok) return $fail('EHLO', $r);
    [$ok, $r] = $step('AUTH LOGIN', '334');                  if (!$ok) return $fail('AUTH', $r);
    [$ok, $r] = $step(base64_encode($user), '334');          if (!$ok) return $fail('username', $r);
    [$ok, $r] = $step(base64_encode($pass), '235');          if (!$ok) return $fail('login (check the App password)', $r);
    [$ok, $r] = $step("MAIL FROM:<$user>", '250');           if (!$ok) return $fail('MAIL FROM', $r);
    [$ok, $r] = $step("RCPT TO:<$to>", ['250', '251']);      if (!$ok) return $fail('RCPT TO', $r);
    [$ok, $r] = $step('DATA', '354');                        if (!$ok) return $fail('DATA', $r);

    $boundary = 'sc_' . bin2hex(random_bytes(8));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mime_header_text($fromName) . ' <' . $user . '>',
        'To: <' . $to . '>',
        'Subject: ' . mime_header_text($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@serenitycare.local>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $msg = implode("\r\n", $headers) . "\r\n\r\n"
         . "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($text))
         . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($html !== '' ? $html : nl2br(htmlspecialchars($text))))
         . "--$boundary--\r\n";

    fwrite($fp, $msg . "\r\n.\r\n");
    $reply = $read();
    $sent = substr($reply, 0, 3) === '250';
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    if (!$sent) {
        error_log('smtp_send: message not accepted: ' . trim($reply));
        return ['ok' => false, 'error' => 'The mail server did not accept the message.'];
    }
    return ['ok' => true, 'error' => ''];
}