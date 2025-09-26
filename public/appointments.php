<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/csrf.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$pdo = pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$meFull = $_SESSION['full_name'] ?? '';
$meRole = (int)($_SESSION['role_id'] ?? 0);

if (($meRole === 3) && empty($_SESSION['patient_id'])) {
  $st = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
  $st->execute([$meId]);
  $pid = $st->fetchColumn();
  $_SESSION['patient_id'] = $pid ? (int)$pid : 0;
}
$session_patient_id = (int)($_SESSION['patient_id'] ?? 0);

$q = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? 'doctor';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

$roleMap = ['doctor' => 4, 'nurse' => 5];
$clinRows = [];

try {
  if ($q !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    if ($roleFilter === 'all') {
      $stmt = $pdo->prepare("SELECT id, username, full_name, specialty FROM users WHERE is_active=1 AND (full_name LIKE ? OR username LIKE ? OR COALESCE(specialty,'') LIKE ?) ORDER BY full_name LIMIT 100");
      $stmt->execute([$like, $like, $like]);
    } else {
      $rid = $roleMap[$roleFilter] ?? $roleMap['doctor'];
      $stmt = $pdo->prepare("SELECT id, username, full_name, specialty FROM users WHERE is_active=1 AND role_id=? AND (full_name LIKE ? OR username LIKE ? OR COALESCE(specialty,'') LIKE ?) ORDER BY full_name LIMIT 100");
      $stmt->execute([$rid, $like, $like, $like]);
    }
  } else {
    $stmt = $pdo->prepare("SELECT id, username, full_name, specialty FROM users WHERE is_active=1 AND role_id=? ORDER BY full_name LIMIT 20");
    $stmt->execute([$roleMap['doctor']]);
  }
  $clinRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log("clinicians load error: " . $e->getMessage());
  $clinRows = [];
}

$patients = [];
try {
  if ($meRole === 3) {
    if ($session_patient_id > 0) {
      $ps = $pdo->prepare("SELECT id, first_name, last_name FROM patients WHERE id = ? LIMIT 1");
      $ps->execute([$session_patient_id]);
      $row = $ps->fetch(PDO::FETCH_ASSOC);
      if ($row) $patients[] = $row;
    }
  } else {
    $per = 500;
    $patients = $pdo->query("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name LIMIT {$per}")->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  error_log("patients load error: " . $e->getMessage());
  $patients = [];
}

$upcoming = [];
try {
  if ($meRole === 3 && $session_patient_id > 0) {
    $sql = "SELECT a.id, a.scheduled_at, a.duration_minutes, a.status,
                       CONCAT(p.first_name,' ',p.last_name) AS patient,
                       COALESCE(u.full_name, u.username) AS doctor
                FROM appointments a
                JOIN patients p ON p.id = a.patient_id
                JOIN users u ON u.id = a.doctor_id
                WHERE a.scheduled_at >= NOW() AND a.patient_id = ?
                ORDER BY a.scheduled_at ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$session_patient_id]);
    $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($meRole === 4) { // doctor
    $sql = "SELECT a.id, a.scheduled_at, a.duration_minutes, a.status,
                       CONCAT(p.first_name,' ',p.last_name) AS patient,
                       COALESCE(u.full_name, u.username) AS doctor
                FROM appointments a
                JOIN patients p ON p.id = a.patient_id
                JOIN users u ON u.id = a.doctor_id
                WHERE a.scheduled_at >= NOW() AND a.doctor_id = ?
                ORDER BY a.scheduled_at ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$meId]);
    $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $sql = "SELECT a.id, a.scheduled_at, a.duration_minutes, a.status,
                       CONCAT(p.first_name,' ',p.last_name) AS patient,
                       COALESCE(u.full_name, u.username) AS doctor
                FROM appointments a
                JOIN patients p ON p.id = a.patient_id
                JOIN users u ON u.id = a.doctor_id
                WHERE a.scheduled_at >= NOW()
                ORDER BY a.scheduled_at ASC LIMIT 50";
    $upcoming = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  error_log("upcoming load error: " . $e->getMessage());
  $upcoming = [];
}

$csrf = csrf_token();

// echo "<pre>DEBUG meId={$meId} meRole={$meRole} DB=" . ($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '?') . "</pre>";

// $test = $pdo->prepare("SELECT id, patient_id, doctor_id, scheduled_at, status FROM appointments WHERE doctor_id=? ORDER BY id DESC LIMIT 10");
// $test->execute([$meId]);
// echo "<pre>DEBUG myApptsForMe:\n" . print_r($test->fetchAll(PDO::FETCH_ASSOC), true) . "</pre>";
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Appointments</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 ">

  <?php include __DIR__ . '/../includes/header.php' ?>
  <div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-semibold mb-4">Appointments</h1>
    <?php if ($msg): ?><div class="mb-3 p-3 bg-green-50 border rounded"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="mb-3 p-3 bg-red-50 border rounded"><?= h($err) ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Search Clinicians</h2>
        <form method="get" class="flex gap-2 mb-3">
          <input name="q" value="<?= h($q) ?>" placeholder="Search by name/username/specialty" class="border px-3 py-2 rounded flex-1">

          <button class="bg-blue-600 text-white px-4 rounded">Search</button>
        </form>

        <div class="space-y-2 max-h-[440px] overflow-auto">
          <?php foreach ($clinRows as $c): ?>
            <div class="border rounded p-2 flex justify-between items-center">
              <div>
                <div class="font-medium"><?= h($c['full_name'] ?: $c['username']) ?></div>
                <div class="text-sm text-gray-600"><?= h($c['specialty'] ?? '') ?> | ID: <?= (int)$c['id'] ?></div>
              </div>
              <button type="button" class="px-3 py-1 bg-blue-600 text-white rounded" onclick="pickClin(<?= (int)$c['id'] ?>,'<?= h($c['full_name'] ?: $c['username']) ?>')">Select</button>
            </div>
          <?php endforeach;
          if (!$clinRows): ?>
            <div class="p-3 bg-gray-100 rounded">No clinicians found.</div>
          <?php endif; ?>
        </div>

        <div class="mt-4">
          <h3 class="font-medium mb-2">Upcoming</h3>
          <div class="space-y-2 max-h-[220px] overflow-auto">
            <?php foreach ($upcoming as $a): ?>
              <div class="border rounded p-2 flex justify-between">
                <div>
                  <div class="font-medium"><?= h($a['patient']) ?> — <?= h($a['doctor']) ?></div>
                  <div class="text-sm text-gray-600"><?= h($a['scheduled_at']) ?> • <?= (int)$a['duration_minutes'] ?> min | <?= h($a['status']) ?></div>
                </div>
                <?php if ($a['status'] === 'booked'): ?>
                  <form method="post" action="/doctor/api/appointments.php" onsubmit="return confirm('Cancel this appointment?')">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="px-2 py-1 border rounded text-red-700">Cancel</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach;
            if (!$upcoming): ?>
              <div class="p-3 bg-gray-100 rounded">No upcoming appointments.</div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Book Appointment</h2>
        <form method="post" action="/doctor/api/appointments.php" id="bookForm">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="book">

          <!-- Patient: hidden (from session) -->
          <?php if ($session_patient_id > 0): ?>
            <input type="hidden" name="patient_id" value="<?= h($session_patient_id) ?>">
            <div class="mb-3"><label class="block text-sm font-medium mb-1">Patient</label>
              <div class="border px-3 py-2 rounded bg-gray-50"><?= h($meFull ?: 'Patient') ?></div>
            </div>
          <?php else: ?>
            <!-- If not patient: show select -->
            <div class="mb-3">
              <label class="block text-sm font-medium mb-1">Patient</label>
              <select name="patient_id" required class="border px-3 py-2 rounded w-full">
                <option value="">— Choose patient —</option>
                <?php foreach ($patients as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"><?= h($p['last_name'] . ', ' . $p['first_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="block text-sm font-medium mb-1">Clinician</label>
            <div class="flex gap-2">
              <input id="clinName" type="text" readonly class="border px-3 py-2 rounded flex-1 bg-gray-50" placeholder="Select from left list">
              <input id="clinId" name="doctor_id" type="hidden" required>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3 mb-3">
            <div>
              <label class="block text-sm mb-1">Date</label>
              <input name="date" type="date" required class="border px-3 py-2 rounded w-full">
            </div>
            <div>
              <label class="block text-sm mb-1">Time</label>
              <input name="time" type="time" required class="border px-3 py-2 rounded w-full">
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3 mb-3">
            <div>
              <label class="block text-sm mb-1">Duration (min)</label>
              <select name="duration_minutes" class="border px-3 py-2 rounded w-full">
                <option>15</option>
                <option>30</option>
                <option>45</option>
                <option>60</option>
              </select>
            </div>
            <div>
              <label class="block text-sm mb-1">Reason (optional)</label>
              <input name="reason" class="border px-3 py-2 rounded w-full">
            </div>
          </div>

          <button class="bg-green-600 text-white px-4 py-2 rounded">Book</button>
        </form>
      </section>
    </div>
    <?php if ($meRole === 4): ?>
      <?php
      $myAppts = [];
      try {
        $sql = "SELECT a.id, a.scheduled_at, a.duration_minutes, a.status,
                       a.patient_id,
                       p.first_name, p.last_name, p.contact_phone,
                       COALESCE(u.full_name, u.username) AS doctor
                FROM appointments a
                JOIN patients p ON p.id = a.patient_id
                JOIN users u ON u.id = a.doctor_id
                WHERE a.doctor_id = ?
                ORDER BY a.scheduled_at DESC
                LIMIT 200";
        $st = $pdo->prepare($sql);
        $st->execute([$meId]);
        $myAppts = $st->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        error_log("manage list error: " . $e->getMessage());
        $myAppts = [];
      }
      ?>
      <div class="max-w-6xl mx-auto mt-8">
        <h2 class="text-xl font-semibold mb-3">Manage My Appointments</h2>
        <div class="overflow-x-auto bg-white border rounded">
          <table class="min-w-full">
            <thead class="bg-gray-100">
              <tr>
                <th class="text-left px-3 py-2">ID</th>
                <th class="text-left px-3 py-2">Patient</th>
                <th class="text-left px-3 py-2">Phone</th>
                <th class="text-left px-3 py-2">Scheduled</th>
                <th class="text-left px-3 py-2">Duration</th>
                <th class="text-left px-3 py-2">Status</th>
                <th class="text-left px-3 py-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($myAppts as $row): ?>
                <?php
                $dateVal = '';
                $timeVal = '';
                if (!empty($row['scheduled_at'])) {
                  try {
                    $d = new DateTime($row['scheduled_at']);
                    $dateVal = $d->format('Y-m-d');
                    $timeVal = $d->format('H:i');
                  } catch (Throwable $e) {
                  }
                }
                ?>
                <tr class="border-t">
                  <td class="px-3 py-2"><?= (int)$row['id'] ?></td>
                  <td class="px-3 py-2"><?= h($row['last_name'] . ', ' . $row['first_name']) ?></td>
                  <td class="px-3 py-2"><?= h($row['phone'] ?? '') ?></td>
                  <td class="px-3 py-2"><?= h($row['scheduled_at']) ?></td>
                  <td class="px-3 py-2"><?= (int)$row['duration_minutes'] ?> min</td>
                  <td class="px-3 py-2"><?= h($row['status']) ?></td>
                  <td class="px-3 py-2">
                    <form method="post" action="../api/appointments.php" class="flex items-center gap-2 flex-wrap">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <input type="date" name="date" value="<?= h($dateVal) ?>" class="border px-2 py-1 rounded">
                      <input type="time" name="time" value="<?= h($timeVal) ?>" class="border px-2 py-1 rounded">
                      <select name="duration_minutes" class="border px-2 py-1 rounded">
                        <?php foreach ([15, 30, 45, 60] as $dopt): ?>
                          <option value="<?= $dopt ?>" <?= ((int)$row['duration_minutes'] === $dopt ? 'selected' : '') ?>><?= $dopt ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="bg-blue-600 text-white px-3 py-1 rounded">Save</button>
                    </form>

                    <?php if ($row['status'] === 'booked' || $row['status'] === 'confirmed'): ?>
                      <form method="post" action="../api/appointments.php" class="inline-block mt-1"
                        onsubmit="return confirm('Cancel this appointment?')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button class="border px-3 py-1 rounded text-red-700">Cancel</button>
                      </form>
                    <?php endif; ?>

                    <form method="post" action="../api/appointments.php" class="inline-block mt-1"
                      onsubmit="return confirm('Delete permanently? This cannot be undone.')">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <button class="border px-3 py-1 rounded text-red-900">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach;
              if (!$myAppts): ?>
                <tr>
                  <td colspan="7" class="px-3 py-4 text-gray-600">No appointments found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <script>
    function pickClin(id, name) {
      document.getElementById('clinId').value = id;
      document.getElementById('clinName').value = name;
      document.getElementById('bookForm').scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      });
    }
  </script>
</body>

</html>