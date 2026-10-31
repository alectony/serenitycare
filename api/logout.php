<?php
require_once __DIR__ . '/../includes/auth.php';

clear_token_cookie();   // the login lives in a cookie, not a PHP session
header('Location: /login.php');
exit;
?>