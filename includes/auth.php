<?php
require_once __DIR__ . '/supabase.php';


function get_user_token() {
    return $_COOKIE['supabase_token'] ?? null;
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
    if ($response['status'] === 200 && !empty($response['data'])) {
        return $response['data'][0];
    }
    return null;
}