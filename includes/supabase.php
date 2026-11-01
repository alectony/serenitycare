<?php
function load_env($path) {
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim(trim($value), '"\'');
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}

load_env(__DIR__ . '/../.env');

function get_supabase_config() {
    $url = getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? '');
    $key = getenv('SUPABASE_ANON_KEY') ?: ($_ENV['SUPABASE_ANON_KEY'] ?? '');

    if ($url === '' || $key === '') {
        http_response_code(500);
        exit('Server error: SUPABASE_URL or SUPABASE_ANON_KEY is not set.');
    }
    return ['url' => rtrim(trim($url), '/'), 'anon_key' => trim($key)];
}

/**
 * One cURL helper for everything. Returns ['status' => int, 'data' => mixed, 'curl_error' => string]
 */
function sb_http($method, $endpoint, array $headers, $body = null) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string) $response, true);
    return ['status' => $status, 'data' => $data ?? $response, 'curl_error' => $err];
}

function sb_login($email, $password) {
    $c = get_supabase_config();
    $r = sb_http('POST', $c['url'] . '/auth/v1/token?grant_type=password', [
        'Content-Type: application/json',
        'apikey: ' . $c['anon_key'],
    ], ['email' => $email, 'password' => $password]);

    if ($r['status'] === 200 && is_array($r['data'])) {
        return $r['data'];
    }
    if ($r['curl_error'] !== '' || $r['status'] === 0) {
        return ['error' => 'network', 'message' => 'Could not reach the server. Please try again.'];
    }
    return ['error' => 'auth', 'message' => 'Invalid email or password.'];
}

function sb_register($email, $password, $fullName = '', array $extra = []) {
    $c = get_supabase_config();
    $r = sb_http('POST', $c['url'] . '/auth/v1/signup', [
        'Content-Type: application/json',
        'apikey: ' . $c['anon_key'],
    ], ['email' => $email, 'password' => $password, 'data' => array_merge(['full_name' => $fullName], $extra)]);

    if ($r['status'] === 200 || $r['status'] === 201) {
        return is_array($r['data']) ? $r['data'] : [];
    }
    if ($r['curl_error'] !== '' || $r['status'] === 0) {
        return ['error' => 'Could not reach the server. Please try again.'];
    }
    $msg = is_array($r['data']) ? ($r['data']['msg'] ?? $r['data']['error_description'] ?? 'Registration failed') : 'Registration failed';
    return ['error' => $msg];
}

/** Returns the auth user for a token, or null if the token is invalid/expired. */
function sb_get_user($token) {
    $c = get_supabase_config();
    $r = sb_http('GET', $c['url'] . '/auth/v1/user', [
        'apikey: ' . $c['anon_key'],
        'Authorization: Bearer ' . $token,
    ]);
    return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : null;
}

/**
 * REST call to a Supabase table. Using the user's token means RLS is enforced.
 * Always returns ['status' => int, 'data' => mixed].
 */
function sb_request($method, $table, $query = '', $body = null, $userToken = null, $prefer = 'return=representation') {
    $c = get_supabase_config();
    $endpoint = $c['url'] . '/rest/v1/' . $table . ($query !== '' ? '?' . $query : '');

    $r = sb_http($method, $endpoint, [
        'apikey: ' . $c['anon_key'],
        'Content-Type: application/json',
        'Prefer: ' . $prefer,
        'Authorization: Bearer ' . ($userToken ?: $c['anon_key']),
    ], $body);

    return ['status' => $r['status'], 'data' => $r['data']];
}

/**
 * Server-side only: create/update a profiles row with the service_role key.
 * Bypasses RLS, so call it only after our own checks (e.g. a valid invite code).
 * Returns false if SUPABASE_SERVICE_ROLE_KEY is not set or the write failed.
 */
function sb_service_upsert_profile($userId, array $fields) {
    $key = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ($_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
    if ($key === '') {
        return false;
    }
    if (isset($fields['role']) && !in_array($fields['role'], ['patient', 'counselor', 'admin'], true)) {
        return false;
    }
    $c = get_supabase_config();
    $r = sb_http('POST', $c['url'] . '/rest/v1/profiles?on_conflict=id', [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: resolution=merge-duplicates,return=representation',
    ], array_merge(['id' => $userId], $fields));
    $ok = $r['status'] >= 200 && $r['status'] < 300 && !empty($r['data']);
    if (!$ok) {
        error_log('sb_service_upsert_profile failed: HTTP ' . $r['status'] . ' ' . substr(json_encode($r['data']), 0, 300));
    }
    return $ok;
}

/** Server-side only: read a profile's role with the service_role key (used to double-check a role change). */
function sb_service_get_role($userId) {
    $key = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ($_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
    if ($key === '') {
        return null;
    }
    $c = get_supabase_config();
    $r = sb_http('GET', $c['url'] . '/rest/v1/profiles?select=role&id=eq.' . urlencode($userId), [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ]);
    return ($r['status'] === 200 && !empty($r['data'][0]['role'])) ? $r['data'][0]['role'] : null;
}
?>