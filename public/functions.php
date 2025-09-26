<?php
require_once __DIR__ . '/api/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* User & Session Helpers */

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function is_admin() {
    return isset($_SESSION['user']) && (int)($_SESSION['user']['role_id'] ?? 0) === 1;
}

function require_role($roles = []) {
    require_login();
    $user = current_user();

    $user_role = strtolower($user['role_name'] ?? '');

    if (
        !in_array($user_role, array_map('strtolower', (array)$roles), true) &&
        !in_array($user['role_id'], (array)$roles, true)
    ) {
        http_response_code(403);
        echo "Forbidden: insufficient privileges";
        exit;
    }
}

/**
 * Save user info in the session after successful login.
 * Accepts a row from users table.
 */
function login_user_by_dbrow(array $user): void {
    global $pdo;

    // Remove sensitive fields
    unset($user['password_hash']);

    // Attach role_name
    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
    $stmt->execute([(int)$user['role_id']]);
    $user['role_name'] = $stmt->fetchColumn() ?: 'unknown';

    // Store only necessary fields for the app
    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'username'  => $user['username'] ?? null,
        'email'     => $user['email'] ?? null,
        'full_name' => $user['full_name'] ?? null,
        'phone'     => $user['phone'] ?? null,
        'role_id'   => (int)$user['role_id'],
        'role_name' => $user['role_name'],
    ];
}

function logout() {
    session_unset();
    session_destroy();
}

/* CSRF */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function check_csrf($token) {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* Permissions */
function current_user_permissions(): array {
    if (!isset($_SESSION['user'])) return [];
    global $pdo;
    $role_id = (int)($_SESSION['user']['role_id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT p.code
        FROM permissions p
        JOIN role_permissions rp ON rp.permission_id = p.id
        WHERE rp.role_id = ?
    ");
    try {
        $stmt->execute([$role_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        // If permissions tables don't exist yet, return empty
        return [];
    }
}

function user_has_permission(string $code): bool {
    $perms = current_user_permissions();
    return in_array($code, $perms, true);
}