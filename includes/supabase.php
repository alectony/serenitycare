<?php
function load_env($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, '"\'');
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}

load_env(__DIR__ . '/../.env');

function get_supabase_config() {
    // Fallback directly to your project credentials so Vercel can't miss them
    $url = getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? ($_SERVER['SUPABASE_URL'] ?? 'https://cjjipdjfhtoairxwydg.supabase.co'));
    $anon_key = getenv('SUPABASE_ANON_KEY') ?: ($_ENV['SUPABASE_ANON_KEY'] ?? ($_SERVER['SUPABASE_ANON_KEY'] ?? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNqamlwZGpmaHRvYWlyeHJ3eWRnIiwicm9sZSI6ImFub24iLCJpYXQiOjE3OTMzMjUxNDAsImV4cCI6MjEwODkwMTE0MH0.VV5VWR0_oOu_u2ixB7YG08eRtgr-AKYeACeKnQFpCaM'));

    return [
        'url' => is_string($url) ? trim($url) : '',
        'anon_key' => is_string($anon_key) ? trim($anon_key) : ''
    ];
}

function sb_login($email, $password) {
    $config = get_supabase_config();
    $url = trim($config['url']);
    $anon_key = trim($config['anon_key']);
    
    // Ensure clean endpoint string
    $endpoint = rtrim($url, '/') . '/auth/v1/token?grant_type=password';

    $payload = json_encode([
        'email' => $email,
        'password' => $password
    ]);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $anon_key
    ]);
    
    // Robust serverless cURL options
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Temporarily bypass SSL verify for debug if needed
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    return [
        'error' => 'Authentication failed',
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'endpoint_called' => $endpoint,
        'response_body' => json_decode($response, true) ?: $response
    ];
}

function sb_register($email, $password, $fullName = '') {
    $config = get_supabase_config();
    $endpoint = $config['url'] . '/auth/v1/signup';

    $payload = json_encode([
        'email' => $email,
        'password' => $password,
        'data' => [
            'full_name' => $fullName
        ]
    ]);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $config['anon_key']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        return json_decode($response, true);
    }

    return ['error' => json_decode($response, true) ?: 'Registration failed'];
}

/**
 * Make REST API requests to Supabase tables (Enforces RLS via user access token)
 */
function sb_request($method, $table, $query = '', $body = null, $userToken = null) {
    $config = get_supabase_config();
    $endpoint = $config['url'] . '/rest/v1/' . $table;
    
    if (!empty($query)) {
        $endpoint .= '?' . $query;
    }

    $headers = [
        'apikey: ' . $config['anon_key'],
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    if ($userToken) {
        $headers[] = 'Authorization: Bearer ' . $userToken;
    } else {
        $headers[] = 'Authorization: Bearer ' . $config['anon_key'];
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    return [
        'http_code' => $httpCode,
        'raw_response' => json_decode($response, true),
        'response_string' => $response
    ];
}