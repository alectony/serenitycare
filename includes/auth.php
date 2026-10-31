<?php
require_once __DIR__ . '/supabase.php';

function is_https() {
    return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || !empty($_SERVER['HTTPS']);
}

function get_user_token() {
    return $_COOKIE['supabase_token'] ?? null;
}

function clear_token_cookie() {
    setcookie('supabase_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function require_login() {
    $token = get_user_token();
    if (!$token) {
        header('Location: /login.php');
        exit;
    }
    return $token;
}

function get_user_profile($user_id, $token) {
    $response = sb_request('GET', 'profiles', 'id=eq.' . $user_id, null, $token);
    if (!empty($response['data'])) {
        return $response['data'][0];
    }
    
    // Fallback auto-create profile if missing
    $defaultProfile = [
        'id' => $user_id,
        'full_name' => 'User',
        'role' => 'patient',
        'is_active' => true
    ];
    sb_request('POST', 'profiles', '', $defaultProfile, $token);
    return $defaultProfile;
}

function home_for_role($role) {
    if ($role === 'admin') return '/admin/';
    if ($role === 'counselor') return '/counselor/';
    return '/patient/';
}

/**
 * Put at the top of every protected page:
 *   $me = require_role('counselor');          // or require_role(['admin','counselor'])
 * Returns ['token' => ..., 'user' => ..., 'profile' => ..., 'role' => ...]
 */
function require_role($roles) {
    $token = require_login();
    $user = sb_get_user($token);

    if (!$user) {            // expired or tampered token
        clear_token_cookie();
        header('Location: /login.php');
        exit;
    }

    $profile = get_user_profile($user['id'], $token);
    $role = $profile['role'] ?? 'patient';

    if (!in_array($role, (array) $roles, true)) {
        header('Location: ' . home_for_role($role));   // send them to their own area
        exit;
    }
    return ['token' => $token, 'user' => $user, 'profile' => $profile, 'role' => $role];
}
?>