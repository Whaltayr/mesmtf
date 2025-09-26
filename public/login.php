<?php
// login.php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

$apiDir = __DIR__ . '/../api';
require_once $apiDir . '/db.php';
require_once $apiDir . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$error = null;
$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted = $_POST['csrf'] ?? '';
        if (!function_exists('csrf_check') || !csrf_check($posted)) {
            $error = 'Invalid CSRF token.';
        } else {
            $login = trim((string)($_POST['login'] ?? ''));
            $password = $_POST['password'] ?? '';

            if ($login === '' || $password === '') {
                $error = 'Please fill all fields.';
            } else {
                $pdo = pdo();
                $stmt = $pdo->prepare("SELECT id, username, email, password_hash, role_id, full_name FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
                $stmt->execute([$login, $login]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    $error = 'Invalid username/email or password.';
                } else {
                    // ensure patient exists
                    $userId = (int)$user['id'];
                    $fullName = $user['full_name'] ?: $user['username'];

                    $pstmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
                    $pstmt->execute([$userId]);
                    $pid = $pstmt->fetchColumn();

                    if (!$pid) {
                        // create patient record
                        $parts = preg_split('/\s+/', $fullName, 2);
                        $first = $parts[0] ?? $fullName;
                        $last  = $parts[1] ?? '';
                        $gender = 'other';
                        $createP = $pdo->prepare("INSERT INTO patients (user_id, external_identifier, first_name, last_name, gender, created_by) VALUES (?, NULL, ?, ?, ?, ?)");
                        $createP->execute([$userId, $first, $last, $gender, $userId]);
                        $pid = (int)$pdo->lastInsertId();
                    } else {
                        $pid = (int)$pid;
                    }

                    // set session
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['role_id'] = (int)$user['role_id'];
                    $_SESSION['patient_id'] = $pid;

                    switch ($_SESSION['role_id']) {
                        case 3:
                            header('Location: dashboard.php');
                            break;
                        case 4:
                            header('Location: dashboard.php');
                            break;

                        default:
                            header('Location: dashboard.php');
                            break;
                    }
                    exit;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log("login error: " . $e->getMessage());
    if ($debug) {
        echo "<pre>" . h($e->getMessage() . "\n\n" . $e->getTraceAsString()) . "</pre>";
        exit;
    }
    $error = $error ?? 'Login failed. Check logs.';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Login</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
</head>

<body style="font-family:system-ui,Arial;background:#f7f7f7;padding:20px">
    <div style="max-width:480px;margin:24px auto;background:#fff;padding:18px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.06)">
        <h2>Login</h2>
        <?php if ($error): ?><div style="background:#fee2e2;color:#9b1c1c;padding:8px;border-radius:4px;margin-bottom:8px;"><?= h($error) ?></div><?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_input() ?>

            <label>Username or Email<br><input name="login" value="<?= h($_POST['login'] ?? '') ?>"></label><br><br>
            <label>Password<br><input name="password" type="password"></label><br><br>
            <button type="submit" style="padding:8px 12px;background:#2b5a89;color:#fff;border-radius:6px;border:0;cursor:pointer">Log in</button>
        </form>
        <p style="margin-top:10px"><a href="register.php">Create account</a></p>
    </div>
</body>

</html>