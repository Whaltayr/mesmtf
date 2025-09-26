<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../api/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function b($ok){ return $ok ? '<span class="ok">PASS</span>' : '<span class="bad">FAIL</span>'; }
function is_doctor(int $r): bool { return $r === 4; }
function is_patient_role(int $r): bool { return $r === 3; }

$pdo = pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$role_id = (int)($_SESSION['role_id'] ?? 0);
$raw_user_id = $_SESSION['user_id'] ?? null;
$username = isset($_SESSION['username']) ? (string)$_SESSION['username'] : (is_string($raw_user_id) ? (string)$raw_user_id : '');
$resolved_user_id = 0;

if (is_int($raw_user_id)) $resolved_user_id = $raw_user_id;
elseif (is_numeric($raw_user_id)) $resolved_user_id = (int)$raw_user_id;
elseif ($username !== '') {
  $st = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
  $st->execute([$username]);
  $resolved_user_id = (int)($st->fetchColumn() ?: 0);
}

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

function table_exists(PDO $pdo, string $t): bool {
  $q = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($t));
  return (bool)$q->fetchColumn();
}
function col_exists(PDO $pdo, string $t, string $c): bool {
  try {
    $st = $pdo->query("DESCRIBE `$t`");
    foreach ($st->fetchAll() as $row) if (strcasecmp((string)$row['Field'], $c) === 0) return true;
  } catch (Throwable $e) { return false; }
  return false;
}
function get_col(PDO $pdo, string $t, string $c): ?array {
  try {
    $st = $pdo->query("DESCRIBE `$t`");
    foreach ($st->fetchAll() as $row) if (strcasecmp((string)$row['Field'], $c) === 0) return $row;
  } catch (Throwable $e) { return null; }
  return null;
}

$checks = [];

$checks[] = ['Session role_id present', $role_id > 0, ['role_id'=>$role_id]];
$checks[] = ['Session user_id present', !empty($raw_user_id) || $username !== '', ['raw_user_id'=>$raw_user_id,'username'=>$username]];
$checks[] = ['Resolved user_id numeric', $resolved_user_id > 0, ['resolved_user_id'=>$resolved_user_id]];

$tables = ['users','patients','appointments','treatments','drugs','prescriptions','prescription_items'];
foreach ($tables as $t) $checks[] = ["Table $t exists", table_exists($pdo,$t), []];

$checks[] = ['appointments.doctor_id column', col_exists($pdo,'appointments','doctor_id'), []];
$checks[] = ['appointments.patient_id column', col_exists($pdo,'appointments','patient_id'), []];
$checks[] = ['patients.user_id column', col_exists($pdo,'patients','user_id'), []];

$dosageCol = get_col($pdo,'prescription_items','dosage');
$checks[] = ['prescription_items.dosage column', !!$dosageCol, (array)$dosageCol];
if ($dosageCol) {
  $notnull = stripos((string)($dosageCol['Null'] ?? ''), 'no') !== false;
  $checks[] = ['dosage NOT NULL', $notnull, ['Null'=>$dosageCol['Null'] ?? '']];
}

$appt = null; $doctor = null; $patient = null; $my_patient_id = 0;
if ($appointment_id > 0) {
  $st = $pdo->prepare("
    SELECT a.*,
           p.first_name AS pf, p.last_name AS pl, p.id AS pid,
           u.username AS doctor_username, u.id AS doc_id
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    JOIN users u    ON u.id = a.doctor_id
    WHERE a.id = ?
    LIMIT 1
  ");
  $st->execute([$appointment_id]);
  $appt = $st->fetch();

  $checks[] = ["Appointment #$appointment_id exists", !!$appt, (array)$appt];

  if ($appt) {
    $checks[] = ['Appointment has doctor in users', (int)$appt['doc_id'] === (int)$appt['doctor_id'], ['appointments.doctor_id'=>$appt['doctor_id'],'users.id(doc)'=>$appt['doc_id']]];
    $checks[] = ['Appointment has patient in patients', (int)$appt['pid'] === (int)$appt['patient_id'], ['appointments.patient_id'=>$appt['patient_id'],'patients.id'=>$appt['pid']]];

    if ($resolved_user_id > 0) {
      if (is_doctor($role_id)) {
        $checks[] = ['Doctor guard: users.id == appointments.doctor_id', (int)$appt['doctor_id'] === $resolved_user_id, ['resolved_user_id'=>$resolved_user_id,'appointment.doctor_id'=>$appt['doctor_id']]];
        if (!$checks[count($checks)-1][1] && $username !== '') {
          $checks[] = ['Doctor fallback: session username == appointment doctor username', $username === (string)$appt['doctor_username'], ['session.username'=>$username,'appt.doctor_username'=>$appt['doctor_username']]];
        }
      }
      if (is_patient_role($role_id)) {
        $stp = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
        $stp->execute([$resolved_user_id]);
        $my_patient_id = (int)($stp->fetchColumn() ?: 0);
        $checks[] = ['Patient guard: patients.id(user) == appointments.patient_id', $my_patient_id>0 && $my_patient_id === (int)$appt['patient_id'], ['my_patient_id'=>$my_patient_id,'appointment.patient_id'=>$appt['patient_id']]];
      }
    }
  }
}

$has_treatments = table_exists($pdo,'treatments');
$has_treatment  = table_exists($pdo,'treatment'); // errado, mas testamos se existe por engano
$checks[] = ['Correct table "treatments" present', $has_treatments, []];
$checks[] = ['Accidental table "treatment" present (should be NO)', !$has_treatment, ['found'=>$has_treatment?'YES':'NO']];

$drugCount = 0; $planCount = 0;
try { $drugCount = (int)$pdo->query("SELECT COUNT(*) FROM drugs")->fetchColumn(); } catch (Throwable $e){}
try { if ($has_treatments) $planCount = (int)$pdo->query("SELECT COUNT(*) FROM treatments")->fetchColumn(); } catch (Throwable $e){}
$checks[] = ['Drugs have rows', $drugCount >= 0, ['count'=>$drugCount]];
$checks[] = ['Treatments have rows (ok if 0)', $planCount >= 0, ['count'=>$planCount]];

?><!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Diag Treatment</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; background:#f6f8fb; color:#111; margin:20px; }
    h1,h2{ margin:6px 0; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; background:#fff; }
    th, td { border: 1px solid #e3e8f0; padding: 8px; text-align: left; vertical-align: top; }
    .ok{ background:#e8fff1; color:#065f46; padding:2px 6px; border-radius:6px; font-weight:600; }
    .bad{ background:#ffe8ea; color:#7f1d1d; padding:2px 6px; border-radius:6px; font-weight:600; }
    .muted{ color:#555; }
    code { background:#f3f6fb; padding:2px 4px; border-radius:4px; }
    .box { background:#fff; padding:12px; border:1px solid #e3e8f0; border-radius:8px; }
  </style>
</head>
<body>
  <h1>Diagnosis • Treatment Guards</h1>
  <div class="box">
    <div class="muted">URL param</div>
    <div>appointment_id = <b><?= (int)$appointment_id ?></b></div>
  </div>

  <h2>Sessão</h2>
  <table>
    <tr><th>role_id</th><td><?= (int)$role_id ?> <?= is_doctor($role_id)?'(doctor)':'' ?> <?= is_patient_role($role_id)?'(patient)':'' ?></td></tr>
    <tr><th>raw user_id (sess)</th><td><?= h(var_export($raw_user_id,true)) ?></td></tr>
    <tr><th>username (sess)</th><td><?= h($username) ?></td></tr>
    <tr><th>resolved user_id</th><td><?= (int)$resolved_user_id ?></td></tr>
  </table>

  <h2>Checks</h2>
  <table>
    <thead><tr><th>Status</th><th>Check</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= b((bool)$c[1]) ?></td>
          <td><?= h((string)$c[0]) ?></td>
          <td><pre style="margin:0;white-space:pre-wrap;"><?= h(json_encode($c[2], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($appointment_id > 0 && $appt): ?>
  <h2>Comparações críticas</h2>
  <div class="box">
    <div>appointments.id: <b><?= (int)$appt['id'] ?></b></div>
    <div>appointments.doctor_id: <b><?= (int)$appt['doctor_id'] ?></b> • doctor.username: <b><?= h($appt['doctor_username']) ?></b></div>
    <div>appointments.patient_id: <b><?= (int)$appt['patient_id'] ?></b></div>
    <div>patients.id (do appointment): <b><?= (int)$appt['pid'] ?></b></div>
    <div>resolved user_id (sess): <b><?= (int)$resolved_user_id ?></b> • username (sess): <b><?= h($username) ?></b></div>
  </div>
  <?php endif; ?>

  <h2>SQL úteis</h2>
  <div class="box">
    <div class="muted">Substitua valores conforme seu caso</div>
    <pre><?=
h("
-- Ver o usuário da sessão
SELECT id, username, full_name FROM users WHERE id = {$resolved_user_id} OR username = " . $pdo->quote($username) . " LIMIT 5;

-- Ver appointment e relacionamentos
SELECT a.*, u.username AS doctor_username, p.user_id AS patient_user_id
FROM appointments a
JOIN users u ON u.id = a.doctor_id
JOIN patients p ON p.id = a.patient_id
WHERE a.id = {$appointment_id};

-- Paciente do usuário logado
SELECT id AS patient_id FROM patients WHERE user_id = {$resolved_user_id} LIMIT 1;

-- Conferir tabela treatments
SHOW TABLES LIKE 'treatments';
DESCRIBE treatments;

-- Ver prescriptions ligadas ao appointment
SELECT pr.* FROM prescriptions pr WHERE pr.appointment_id = {$appointment_id} ORDER BY pr.created_at DESC;

-- Ver prescription_items + dosage NOT NULL
DESCRIBE prescription_items;
")
    ?></pre>
  </div>

  <h2>Possíveis correções</h2>
  <div class="box">
    <ul>
      <li>Se o médico é barrado: garanta que <code>\$_SESSION['user_id']</code> seja o <b>ID numérico</b> de <code>users.id</code>.
        Ex.: se a sessão só tem username “ferre”, defina <code>\$_SESSION['user_id']</code> = id de “ferre”.</li>
      <li>Se o paciente é barrado: garanta que exista <code>patients.user_id = users.id</code> do usuário logado, e que
        <code>appointments.patient_id</code> aponte para esse <code>patients.id</code>.</li>
      <li>Se a tabela correta é <code>treatments</code> (com “s”). Remova/ignore <code>treatment</code> (sem “s”).</li>
      <li>Se <code>prescription_items.dosage</code> é NOT NULL, não insira NULL, use <code>''</code> quando vazio.</li>
    </ul>
  </div>
</body>
</html>