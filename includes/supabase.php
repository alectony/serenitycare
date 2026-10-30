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
    return [
        'url' => getenv('SUPABASE_URL'),
        'anon_key' => getenv('SUPABASE_ANON_KEY')
    ];
}


function sb_login($email, $password) {
    $config = get_supabase_config();
    $endpoint = $config['url'] . '/auth/v1/token?grant_type=password';

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
        'apikey: ' . $config['anon_key']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return json_decode($response, true); // Returns access_token, user object, etc.
    }
    
    return ['error' => json_decode($response, true) ?: 'Authentication failed'];
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

    return [
        'status' => $httpCode,
        'data' => json_decode($response, true)
    ];
}