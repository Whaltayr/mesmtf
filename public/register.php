<?php
// register.php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// === includes (note: api is one level above public) ===
$apiDir = __DIR__ . '/../api';
require_once $apiDir . '/db.php';    // must provide pdo()
require_once $apiDir . '/csrf.php';  // must provide csrf_token(), csrf_input(), csrf_check()

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF
        $posted = $_POST['csrf'] ?? '';
        if (!function_exists('csrf_check') || !csrf_check($posted)) {
            $error = 'Invalid CSRF token.';
        } else {
            // collect + sanitize
            $full_name = trim((string)($_POST['full_name'] ?? ''));
            $username  = trim((string)($_POST['username'] ?? ''));
            $email     = trim((string)($_POST['email'] ?? ''));
            $password  = $_POST['password'] ?? '';
            $confirm   = $_POST['confirm_password'] ?? '';

            // validation
            if ($full_name === '' || $username === '' || $email === '' || $password === '') {
                $error = 'Fill all required fields.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email.';
            } elseif ($password !== $confirm) {
                $error = 'Passwords do not match.';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } else {
                $pdo = pdo(); // from api/db.php

                // uniqueness
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                $check->execute([$username, $email]);
                if ($check->fetch()) {
                    $error = 'Username or email already exists.';
                } else {
                    // insert user
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO users (username, email, password_hash, role_id, full_name, is_active)
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");
                    $ins->execute([$username, $email, $hash, 3, $full_name]);
                    $userId = (int)$pdo->lastInsertId();

                    // create patient; ensure last_name not null and created_by is int
                    $parts = preg_split('/\s+/', $full_name, 2);
                    $first = $parts[0] ?? $full_name;
                    $last  = $parts[1] ?? '';

                    $pstmt = $pdo->prepare("
                        INSERT INTO patients (user_id, external_identifier, first_name, last_name, gender, created_by)
                        VALUES (?, NULL, ?, ?, ?, ?)
                    ");
                    $gender = 'other';
                    $created_by = $userId;
                    $pstmt->execute([$userId, $first, $last, $gender, $created_by]);
                    $patientId = (int)$pdo->lastInsertId();

                    // login
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['role_id'] = 3;
                    $_SESSION['patient_id'] = $patientId;

                    header('Location: appointments.php');
                    exit;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log("register error: " . $e->getMessage());
    if ($debug) {
        // safe debug output if explicitly requested
        echo "<pre>" . h($e->getMessage() . "\n\n" . $e->getTraceAsString()) . "</pre>";
        exit;
    }
    $error = $error ?? 'Internal error. Check logs.';
}
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Register</title><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:system-ui,Arial;background:#f7f7f7;padding:20px">
  <div style="max-width:520px;margin:24px auto;background:#fff;padding:18px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.06)">
    <h2>Create account</h2>
    <?php if ($error): ?><div style="background:#fee2e2;color:#9b1c1c;padding:8px;border-radius:4px;margin-bottom:8px;"><?=h($error)?></div><?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_input() ?>

      <label>Full name<br><input name="full_name" value="<?= h($_POST['full_name'] ?? '') ?>"></label><br><br>
      <label>Username<br><input name="username" value="<?= h($_POST['username'] ?? '') ?>"></label><br><br>
      <label>Email<br><input name="email" type="email" value="<?= h($_POST['email'] ?? '') ?>"></label><br><br>
      <label>Password<br><input name="password" type="password"></label><br><br>
      <label>Confirm password<br><input name="confirm_password" type="password"></label><br><br>

      <button type="submit" style="padding:8px 12px;background:#2b5a89;color:#fff;border-radius:6px;border:0;cursor:pointer">Create account</button>
    </form>
    <p style="margin-top:10px"><a href="login.php">Already have an account? Log in</a></p>
  </div>
</body>
</html>
