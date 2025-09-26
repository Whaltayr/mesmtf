<?php
// logout.php — secure logout
declare(strict_types=1);

$api = __DIR__ . '/../api';
require_once $api . '/csrf.php';   // for csrf_check() (optional)
if (session_status() === PHP_SESSION_NONE) {
    // ensure secure session cookie settings were used elsewhere
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        // 'cookie_secure' => true, // enable on HTTPS
    ]);
}

// Prefer POST logout with CSRF for safety
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!function_exists('csrf_check') || !csrf_check($token)) {
        // invalid CSRF — do not logout silently; show message or redirect
        http_response_code(403);
        echo "CSRF inválido.";
        exit;
    }
}

$_SESSION = [];


if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'] ?? '/', $params['domain'] ?? '', 
        $params['secure'] ?? false, $params['httponly'] ?? true
    );
}

session_destroy();

if (function_exists('session_regenerate_id')) {
    session_regenerate_id(true);
}

header('Location: login.php');
exit;
