<?php
declare(strict_types=1);

// Always start session here so CSRF works both in UI and API includes
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        // 'cookie_secure' => true, // enable only if you serve over HTTPS
    ]);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function csrf_token(): string {
    return $_SESSION['csrf'];
}
function csrf_input(): string {
    return '<input type="hidden" name="csrf" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_check(string $t): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $t);
}
function require_post_with_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF validation failed']);
        exit;
    }
}