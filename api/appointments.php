<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/util.php';

$pdo = pdo();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'clinicians') {
        $q = trim($_GET['q'] ?? '');
        $role = $_GET['role'] ?? 'doctor';
        $roleMap = ['doctor' => 4, 'nurse' => 5];
        try {
            if ($q !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                if ($role === 'all') {
                    $stmt = $pdo->prepare("SELECT id, username, full_name, specialty FROM users WHERE is_active=1 AND (full_name LIKE ? OR username LIKE ? OR COALESCE(specialty,'') LIKE ?) ORDER BY full_name LIMIT 100");
                    $stmt->execute([$like, $like, $like]);
                } else {
                    $rid = $roleMap[$role] ?? 4;
                    $stmt = $pdo->prepare("SELECT id, username, full_name, specialty FROM users WHERE is_active=1 AND role_id=? AND (full_name LIKE ? OR username LIKE ? OR COALESCE(specialty,'') LIKE ?) ORDER BY full_name LIMIT 100");
                    $stmt->execute([$rid, $like, $like, $like]);
                }
            } else {
                $stmt = $pdo->prepare("SELECT id, username, full_name, specialty FROM users WHERE is_active=1 AND role_id=? ORDER BY full_name LIMIT 20");
                $stmt->execute([$roleMap['doctor']]);
            }
            $rows = $stmt->fetchAll();
            json_ok(['rows' => $rows]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            json_err('Failed to load clinicians', 500);
        }
    }

    if ($action === 'upcoming') {
        try {
            $sql = "SELECT a.id, a.scheduled_at, a.duration_minutes, a.status,
                           CONCAT(p.first_name,' ',p.last_name) AS patient,
                           COALESCE(u.full_name, u.username) AS doctor
                    FROM appointments a
                    JOIN patients p ON p.id = a.patient_id
                    JOIN users u ON u.id = a.doctor_id
                    WHERE a.scheduled_at >= NOW()
                    ORDER BY a.scheduled_at ASC
                    LIMIT 50";
            $rows = $pdo->query($sql)->fetchAll();
            json_ok(['rows' => $rows]);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            json_err('Failed to load upcoming', 500);
        }
    }

    json_err('Unknown action', 400);
}

require_post_with_csrf();

if ($action === 'book') {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id  = (int)($_POST['doctor_id'] ?? 0);
    $date       = trim($_POST['date'] ?? '');
    $time       = trim($_POST['time'] ?? '');
    $duration   = max(1, min(480, (int)($_POST['duration_minutes'] ?? 15)));

    if ($patient_id <= 0 || $doctor_id <= 0 || $date === '' || $time === '') {
        redirect_with_msg('/doctor/public/appointments.php', '', 'Invalid input.');
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$dt) redirect_with_msg('/doctor/public/appointments.php', '', 'Invalid date/time.');
    $hour = (int)$dt->format('H');
    if ($hour < 6 || $hour > 20) redirect_with_msg('/doctor/public/appointments.php', '', 'Outside hours (06:00-20:59).');

    // existence checks
    $chkP = $pdo->prepare("SELECT id FROM patients WHERE id=?");
    $chkP->execute([$patient_id]);
    if (!$chkP->fetch()) redirect_with_msg('/doctor/public/appointments.php', '', 'Patient not found.');
    $chkD = $pdo->prepare("SELECT id,is_active FROM users WHERE id=?");
    $chkD->execute([$doctor_id]);
    $doc = $chkD->fetch();
    if (!$doc || (int)$doc['is_active'] !== 1) redirect_with_msg('/doctor/public/appointments.php', '', 'Clinician not available.');

    $start = $dt->format('Y-m-d H:i:00');
    // rely on unique(doctor_id, scheduled_at) to avoid  conflicts
    try {
        $ins = $pdo->prepare("INSERT INTO appointments(patient_id,doctor_id,scheduled_at,duration_minutes,status,created_by) VALUES(?,?,?,?, 'booked', NULL)");
        $ins->execute([$patient_id, $doctor_id, $start, $duration]);
        redirect_with_msg('/doctor/public/appointments.php', 'Appointment booked.');
    } catch (Throwable $e) {
        // Could be duplicate unique slot
        error_log($e->getMessage());
        redirect_with_msg('/doctor/public/appointments.php', '', 'Slot unavailable or error.');
    }
}

if ($action === 'cancel') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) redirect_with_msg('/doctor/public/appointments.php', '', 'Invalid id.');
    try {
        $upd = $pdo->prepare("UPDATE appointments SET status='cancelled' WHERE id=?");
        $upd->execute([$id]);
        redirect_with_msg('/doctor/public/appointments.php', 'Appointment cancelled.');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        redirect_with_msg('/doctor/public/appointments.php', '', 'Failed to cancel.');
    }
}
if (true) {
    $sql = "SELECT * FROM appointments WHERE status = 'booked'";
}
// POST /doctor/api/appointments.php (action=update)
if ($action === 'update') {
    require_post_with_csrf();

    $id       = (int)($_POST['id'] ?? 0);
    $date     = trim($_POST['date'] ?? '');
    $time     = trim($_POST['time'] ?? '');
    $duration = max(1, min(480, (int)($_POST['duration_minutes'] ?? 15)));

    if ($id <= 0 || $date === '' || $time === '') {
        redirect_with_msg('/doctor/public/appointments.php', '', 'Invalid input.');
    }

    // Monta novo datetime e valida horário (06:00–20:59 como no book)
    $dt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$dt) redirect_with_msg('/doctor/public/appointments.php', '', 'Invalid date/time.');
    $hour = (int)$dt->format('H');
    if ($hour < 6 || $hour > 20) redirect_with_msg('/doctor/public/appointments.php', '', 'Outside hours (06:00-20:59).');

    // Carrega appointment
    $st = $pdo->prepare("SELECT id, doctor_id, status FROM appointments WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) redirect_with_msg('/doctor/public/appointments.php', '', 'Appointment not found.');

    // Segurança: se for médico (role_id=4), só pode editar os próprios
    $meRole = (int)($_SESSION['role_id'] ?? 0);
    $meId   = (int)($_SESSION['user_id'] ?? 0);
    if ($meRole === 4 && (int)$row['doctor_id'] !== $meId) {
        redirect_with_msg('/doctor/public/appointments.php', '', 'Not allowed.');
    }

    $newStart = $dt->format('Y-m-d H:i:00');

    try {
        $upd = $pdo->prepare("UPDATE appointments
                              SET scheduled_at=?, duration_minutes=?
                              WHERE id=?");
        $upd->execute([$newStart, $duration, $id]);
        redirect_with_msg('/doctor/public/appointments.php', 'Appointment updated.');
    } catch (Throwable $e) {
        // Pode falhar por conflito do unique(doctor_id, scheduled_at)
        error_log($e->getMessage());
        redirect_with_msg('/doctor/public/appointments.php', '', 'Slot unavailable or error.');
    }
}

// POST /doctor/api/appointments.php (action=delete)
if ($action === 'delete') {
    require_post_with_csrf();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) redirect_with_msg('/doctor/public/appointments.php', '', 'Invalid id.');

    // Carrega appointment
    $st = $pdo->prepare("SELECT id, doctor_id FROM appointments WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) redirect_with_msg('/doctor/public/appointments.php', '', 'Appointment not found.');

    // Segurança: se for médico, só pode apagar os próprios
    $meRole = (int)($_SESSION['role_id'] ?? 0);
    $meId   = (int)($_SESSION['user_id'] ?? 0);
    if ($meRole === 4 && (int)$row['doctor_id'] !== $meId) {
        redirect_with_msg('/doctor/public/appointments.php', '', 'Not allowed.');
    }

    try {
        $del = $pdo->prepare("DELETE FROM appointments WHERE id=?");
        $del->execute([$id]);
        redirect_with_msg('/doctor/public/appointments.php', 'Appointment deleted.');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        redirect_with_msg('/doctor/public/appointments.php', '', 'Failed to delete.');
    }
}


json_err('Unknown action', 400);
