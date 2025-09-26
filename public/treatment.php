<?php

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_doctor(int $r): bool
{
  return $r === 4;
}
function is_patient_role(int $r): bool
{
  return $r === 3;
}
function ensure_csrf_or_die(array $p)
{
  if (!csrf_check($p['csrf'] ?? '')) throw new RuntimeException('CSRF fail');
}

$ALLOW_VIEW_IF_DOCTOR_MISMATCH = true;

$pdo = pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('set_flash')) {
  function set_flash(string $m)
  {
    $_SESSION['flash'] = $m;
  }
  function get_flash(): ?string
  {
    $f = $_SESSION['flash'] ?? null;
    if ($f) unset($_SESSION['flash']);
    return $f;
  }
}

function resolve_user_id(PDO $pdo): int
{
  $raw = $_SESSION['user_id'] ?? null;
  if (is_int($raw)) return $raw;
  if (is_numeric($raw)) return (int)$raw;
  $uname = $_SESSION['username'] ?? (is_string($raw) ? $raw : '');
  if ($uname !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $st->execute([$uname]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) {
      $_SESSION['user_id'] = $id;
      return $id;
    }
  }
  return 0;
}

$role_id = (int)($_SESSION['role_id'] ?? 0);
$user_id = resolve_user_id($pdo);
// aceitar vários nomes de parâmetro
$appointment_id = 0;
foreach (['appointment_id', 'id', 'appt_id'] as $k) {
  if (isset($_GET[$k]) && is_numeric($_GET[$k])) {
    $appointment_id = (int)$_GET[$k];
    break;
  }
}

// fallback: se médico e sem ID, vai para o último agendado do médico
if ($appointment_id <= 0 && $role_id === 4) {
  $st = $pdo->prepare("
    SELECT id FROM appointments
    WHERE doctor_id = ? AND status IN ('booked','confirmed')
    ORDER BY scheduled_at DESC
    LIMIT 1
  ");
  $st->execute([$user_id]);
  $aid = (int)($st->fetchColumn() ?: 0);
  if ($aid > 0) {
    header('Location: ' . $_SERVER['PHP_SELF'] . '?appointment_id=' . $aid);
    exit;
  }
}


$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

if ($appointment_id <= 0) {
  if (is_patient_role($role_id)) {
    header('Location: /appointments.php');
    exit;
  }
?>
  <!doctype html>
  <html lang="en">

  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Treatment • Appointment required</title>
    <style>
      body {
        font-family: system-ui, Arial, sans-serif;
        margin: 0;
        background: #f6f8fb;
        color: #111
      }

      .wrap {
        max-width: 720px;
        margin: 10vh auto;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, .08);
        padding: 24px
      }

      a.btn {
        display: inline-block;
        margin-top: 12px;
        background: #2b5a89;
        color: #fff;
        padding: 8px 12px;
        border-radius: 8px;
        text-decoration: none
      }
    </style>
  </head>

  <body>
    <div class="wrap">
      <h1>Appointment required</h1>
      <p>Abra a partir da agenda (link Treat/View).</p><a class="btn" href="/appointments.php">Go to Appointments</a>
    </div>
  </body>

  </html>
<?php exit;
}

try {
  $st = $pdo->prepare("
    SELECT a.*,
           p.first_name AS pf, p.last_name AS pl, p.id AS pid,
           u.full_name AS doctor_name, COALESCE(u.full_name,u.username) AS doctor_label,
           u.username AS doctor_username
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    JOIN users u    ON u.id = a.doctor_id
    WHERE a.id = ?
    LIMIT 1
  ");
  $st->execute([$appointment_id]);
  $appt = $st->fetch();
  if (!$appt) {
    http_response_code(404);
    echo 'appointment not found';
    exit;
  }

  if ($user_id <= 0) {
    http_response_code(403);
    echo 'forbidden (no session id)';
    exit;
  }

  $doctor_mismatch = is_doctor($role_id) && ((int)$appt['doctor_id'] !== $user_id);

  if (is_patient_role($role_id)) {
    $stp = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
    $stp->execute([$user_id]);
    $my_pid = (int)($stp->fetchColumn() ?: 0);
    if ($my_pid <= 0 || $my_pid !== (int)$appt['patient_id']) {
      http_response_code(403);
      echo 'forbidden (patient mismatch)';
      exit;
    }
  }

  if (is_doctor($role_id) && !$ALLOW_VIEW_IF_DOCTOR_MISMATCH && $doctor_mismatch) {
    http_response_code(403);
    echo 'forbidden (doctor mismatch)';
    exit;
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo 'load error';
  exit;
}

try {
  $drugs = $pdo->query("SELECT id, name, unit, stock FROM drugs ORDER BY name")->fetchAll();
  $plans = $pdo->query("SELECT id, name FROM treatments ORDER BY name")->fetchAll();
} catch (Throwable $e) {
  $drugs = [];
  $plans = [];
}

$csrf = csrf_token();

$form_treatment_id = (string)($_POST['treatment_id'] ?? '');
$form_plan_notes   = (string)($_POST['plan_notes'] ?? '');
$item_drug = is_array($_POST['drug_id'] ?? null) ? array_values($_POST['drug_id']) : [''];
$item_qty  = is_array($_POST['quantity'] ?? null) ? array_values($_POST['quantity']) : [''];
$item_dose = is_array($_POST['dosage'] ?? null) ? array_values($_POST['dosage']) : [''];

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    ensure_csrf_or_die($_POST);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'reassign_to_me') {
      if (!is_doctor($role_id)) throw new RuntimeException('Only doctors');
      if ((int)$appt['doctor_id'] === $user_id) {
        set_flash('Already assigned to you.');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?appointment_id=' . $appointment_id);
        exit;
      }
      $upd = $pdo->prepare("UPDATE appointments SET doctor_id = ?, updated_at = NOW() WHERE id = ?");
      $upd->execute([$user_id, $appointment_id]);
      set_flash('Appointment reassigned to you.');
      header('Location: ' . $_SERVER['PHP_SELF'] . '?appointment_id=' . $appointment_id);
      exit;
    }

    if ($action === 'add_row') {
      $item_drug[] = '';
      $item_qty[] = '';
      $item_dose[] = '';
    } elseif (preg_match('/^remove_row_(\d+)$/', $action, $m)) {
      $idx = (int)$m[1];
      if (isset($item_drug[$idx])) {
        array_splice($item_drug, $idx, 1);
        array_splice($item_qty,  $idx, 1);
        array_splice($item_dose, $idx, 1);
      }
      if (!$item_drug) {
        $item_drug = [''];
        $item_qty = [''];
        $item_dose = [''];
      }
    } elseif ($action === 'save_treatment') {
      if (!is_doctor($role_id)) throw new RuntimeException('Only doctors can edit treatment');
      if ((int)$appt['doctor_id'] !== $user_id) throw new RuntimeException('You are not assigned to this appointment');

      $doctor_id = $user_id;
      $patient_id = (int)$appt['patient_id'];
      $treatment_id = (int)$form_treatment_id;
      $plan_notes = trim($form_plan_notes);

      $pdo->beginTransaction();
      try {
        $saved_plan_name = null;
        if ($treatment_id > 0) {
          $stp = $pdo->prepare("SELECT name FROM treatments WHERE id = ? LIMIT 1");
          $stp->execute([$treatment_id]);
          $saved_plan_name = (string)($stp->fetchColumn() ?: '');
        }

        $items = [];
        $n = max(count($item_drug), count($item_qty), count($item_dose));
        for ($i = 0; $i < $n; $i++) {
          $did = (int)($item_drug[$i] ?? 0);
          $qty = (int)($item_qty[$i] ?? 0);
          $dos = trim((string)($item_dose[$i] ?? ''));
          if ($did > 0 && $qty > 0) $items[] = ['drug_id' => $did, 'qty' => $qty, 'dosage' => $dos];
        }

        $created_rx_id = null;
        if ($items) {
          $rxNotes = [];
          if ($saved_plan_name) $rxNotes[] = 'Plan: ' . $saved_plan_name;
          if ($plan_notes !== '') $rxNotes[] = 'Notes: ' . $plan_notes;
          $rxNotesStr = $rxNotes ? implode(' | ', $rxNotes) : null;

          $insRx = $pdo->prepare("
            INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, notes, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
          ");
          $insRx->execute([$patient_id, $doctor_id, $appointment_id, $rxNotesStr]);
          $created_rx_id = (int)$pdo->lastInsertId();

          $insItem = $pdo->prepare("
            INSERT INTO prescription_items (prescription_id, drug_id, quantity, dosage)
            VALUES (?, ?, ?, ?)
          ");
          foreach ($items as $it) {
            $insItem->execute([$created_rx_id, $it['drug_id'], $it['qty'], $it['dosage'] !== '' ? $it['dosage'] : '']);
          }
        }

        $pdo->commit();
        set_flash('Treatment saved' . ($created_rx_id ? ' • Rx #' . $created_rx_id : ''));
        header('Location: ' . $_SERVER['PHP_SELF'] . '?appointment_id=' . $appointment_id);
        exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
    }
  } catch (Throwable $e) {
    error_log('TREATMENT POST: ' . $e->getMessage());
    $flash = 'Error: ' . ($e->getMessage() ?: 'failed');
  }
}

$rx = [];
$itemsByRx = [];
try {
  $st = $pdo->prepare("
    SELECT pr.id, pr.patient_id, pr.doctor_id, pr.appointment_id, pr.notes, pr.status, pr.created_at
    FROM prescriptions pr
    WHERE pr.appointment_id = ?
    ORDER BY pr.created_at DESC
  ");
  $st->execute([$appointment_id]);
  $rx = $st->fetchAll();

  if ($rx) {
    $ids = array_map(fn($r) => (int)$r['id'], $rx);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sti = $pdo->prepare("
      SELECT pi.id, pi.prescription_id, pi.drug_id, pi.quantity, pi.dosage, d.name AS drug_name
      FROM prescription_items pi
      JOIN drugs d ON d.id = pi.drug_id
      WHERE pi.prescription_id IN ($in)
      ORDER BY pi.id ASC
    ");
    $sti->execute($ids);
    foreach ($sti->fetchAll() as $row) {
      $itemsByRx[(int)$row['prescription_id']][] = $row;
    }
  }
} catch (Throwable $e) {
}

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Treatment • Appt #<?= (int)$appointment_id ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root {
      --bg: #f5f7fb;
      --card: #fff;
      --ink: #111;
      --muted: #666;
      --bd: #eef2f8;
      --accent: #2b5a89;
    }

    body {
      font-family: system-ui, Arial, sans-serif;
      background: var(--bg);
      color: var(--ink);
      margin: 0;
    }

    .container {
      max-width: 1100px;
      margin: 0 auto;
      padding: 20px;
    }

    .page-title {
      margin: 0 0 12px 0;
      font-size: 22px;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .card {
      background: var(--card);
      border-radius: 10px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
      padding: 12px;
    }

    .notice {
      background: #e6ffef;
      color: #064e3b;
      padding: 10px;
      border-radius: 8px;
      margin: 12px 0;
    }

    .warn {
      background: #fff8e1;
      color: #7a4f01;
      padding: 10px;
      border-radius: 8px;
      margin: 12px 0;
    }

    .error {
      background: #fff1f2;
      color: #7f1d1d;
      padding: 10px;
      border-radius: 8px;
      margin: 12px 0;
    }

    .muted {
      color: var(--muted);
      font-size: 13px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }

    th,
    td {
      padding: 8px;
      border-bottom: 1px solid var(--bd);
      text-align: left;
    }

    .btn {
      background: var(--accent);
      color: #fff;
      border: 0;
      padding: 8px 10px;
      border-radius: 8px;
      cursor: pointer;
    }

    .btn-ghost {
      background: transparent;
      border: 1px solid #cfd6ea;
      color: var(--accent);
      padding: 6px 8px;
      border-radius: 6px;
      cursor: pointer;
    }

    @media (max-width: 900px) {
      .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
  <?php include __DIR__ . '/../includes/header.php' ?>

  <div class="container">
    <h2 class="page-title">Treatment • Appt #<?= (int)$appt['id'] ?> • <?= h($appt['pl'] . ', ' . $appt['pf']) ?> • <?= h($appt['status']) ?></h2>

    <?php if ($f = get_flash()): ?><div class="notice"><?= h($f) ?></div><?php endif; ?>
    <?php if (!empty($flash) && strncmp($flash, 'Error:', 6) === 0): ?><div class="error"><?= h($flash) ?></div><?php endif; ?>

    <?php if (is_doctor($role_id) && (int)$appt['doctor_id'] !== $user_id): ?>
      <div class="warn">
        Consulta atribuída a <?= h($appt['doctor_label']) ?> (users.id <?= (int)$appt['doctor_id'] ?>).
        Você está logado como users.id <?= (int)$user_id ?>.
        <?php if ($ALLOW_VIEW_IF_DOCTOR_MISMATCH): ?>
          Você pode visualizar, mas só salva se assumir a consulta.
          <form method="post" action="<?= h($_SERVER['PHP_SELF'] . '?appointment_id=' . $appointment_id) ?>" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button class="btn-ghost" name="action" value="reassign_to_me">Reassign to me</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="grid">
      <div class="card">
        <h3>Plan & Prescription</h3>
        <?php if (is_doctor($role_id)): ?>
          <form method="post" action="<?= h($_SERVER['PHP_SELF'] . '?appointment_id=' . $appointment_id) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <table>
              <tr>
                <th>Patient</th>
                <td><input value="<?= h($appt['pl'] . ', ' . $appt['pf']) ?>" readonly></td>
                <th>Doctor</th>
                <td><input value="<?= h($appt['doctor_label']) ?>" readonly></td>
              </tr>
              <tr>
                <th>Treatment plan</th>
                <td colspan="3">
                  <select name="treatment_id">
                    <option value="">-- none --</option>
                    <?php foreach ($plans as $pl): ?>
                      <option value="<?= (int)$pl['id'] ?>" <?= ($form_treatment_id !== '' && (int)$form_treatment_id === (int)$pl['id']) ? 'selected' : '' ?>><?= h($pl['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th>Notes</th>
                <td colspan="3"><textarea name="plan_notes" placeholder="brief notes for this encounter"><?= h($form_plan_notes) ?></textarea></td>
              </tr>
            </table>

            <h4>Prescription (optional)</h4>
            <table>
              <thead>
                <tr>
                  <th style="width:50%;">Drug</th>
                  <th style="width:15%;">Qty</th>
                  <th style="width:25%;">Dosage</th>
                  <th style="width:10%;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $rows = max(1, count($item_drug));
                for ($i = 0; $i < $rows; $i++):
                  $valDrug = (int)($item_drug[$i] ?? 0);
                  $valQty  = (string)($item_qty[$i] ?? '');
                  $valDose = (string)($item_dose[$i] ?? '');
                ?>
                  <tr>
                    <td>
                      <select name="drug_id[]">
                        <option value="">-- drug --</option>
                        <?php foreach ($drugs as $d): ?>
                          <option value="<?= (int)$d['id'] ?>" <?= $valDrug === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= h($d['name']) ?> (<?= h($d['unit']) ?> • stk <?= (int)$d['stock'] ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input name="quantity[]" type="number" min="1" step="1" value="<?= h($valQty) ?>"></td>
                    <td><input name="dosage[]" value="<?= h($valDose) ?>"></td>
                    <td><button class="btn-ghost" name="action" value="remove_row_<?= (int)$i ?>">Remove</button></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
            <div style="margin:8px 0;">
              <button class="btn-ghost" name="action" value="add_row">+ Add item</button>
            </div>

            <div>
              <button type="submit" class="btn" name="action" value="save_treatment">Save</button>
            </div>
          </form>
        <?php else: ?>
          <div class="muted">Read-only para pacientes.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>Prescriptions for this appointment</h3>
        <?php
        $rx = [];
        $itemsByRx = [];
        try {
          $st = $pdo->prepare("
            SELECT pr.id, pr.patient_id, pr.doctor_id, pr.appointment_id, pr.notes, pr.status, pr.created_at
            FROM prescriptions pr
            WHERE pr.appointment_id = ?
            ORDER BY pr.created_at DESC
          ");
          $st->execute([$appointment_id]);
          $rx = $st->fetchAll();

          if ($rx) {
            $ids = array_map(fn($r) => (int)$r['id'], $rx);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sti = $pdo->prepare("
              SELECT pi.id, pi.prescription_id, pi.drug_id, pi.quantity, pi.dosage, d.name AS drug_name
              FROM prescription_items pi
              JOIN drugs d ON d.id = pi.drug_id
              WHERE pi.prescription_id IN ($in)
              ORDER BY pi.id ASC
            ");
            $sti->execute($ids);
            foreach ($sti->fetchAll() as $row) {
              $itemsByRx[(int)$row['prescription_id']][] = $row;
            }
          }
        } catch (Throwable $e) {
        }
        ?>

        <?php if (!$rx): ?>
          <div class="muted">None yet.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Notes</th>
                <th>Status</th>
                <th>When</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rx as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= h($r['notes'] ?? '-') ?></td>
                  <td><?= h($r['status']) ?></td>
                  <td><?= h($r['created_at']) ?></td>
                </tr>
                <?php $its = $itemsByRx[(int)$r['id']] ?? [];
                if ($its): ?>
                  <tr>
                    <td colspan="4">
                      <table>
                        <thead>
                          <tr>
                            <th>Drug</th>
                            <th>Qty</th>
                            <th>Dosage</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($its as $it): ?>
                            <tr>
                              <td><?= h($it['drug_name']) ?></td>
                              <td><?= (int)$it['quantity'] ?></td>
                              <td><?= h($it['dosage'] ?? '-') ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>

</html>