<?php
// public/diagnosis.php — cleaned: no payload shown, explicit role-checks
declare(strict_types=1);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/csrf.php';
require_once __DIR__ . '/../lib/diagnosis_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// require login
if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$meId   = (int)($_SESSION['user_id']);
$meRole = (int)($_SESSION['role_id'] ?? 0);
$meFull = $_SESSION['full_name'] ?? '(unknown)';

// Allowed roles to access this page (including read-only)
$allowedRoles = [1, 2, 3, 4, 5, 6]; // admin, receptionist, patient, doctor, nurse, pharmacist
if (!in_array($meRole, $allowedRoles, true)) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}

// Only clinicians/admin can create/run diagnoses
$isClinicianOrAdmin = in_array($meRole, [1, 4, 5], true); // admin, doctor, nurse
$isPatient = ($meRole === 3);

$pdo = pdo();

// ensure patient mapping for patient role (so we can show only their records)
if ($isPatient && empty($_SESSION['patient_id'])) {
  $st = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
  $st->execute([$meId]);
  $_SESSION['patient_id'] = (int)$st->fetchColumn();
}

// load UI data via helpers
$catMap = loadSymptomsByCategory($pdo);
$drData = loadDiseasesAndRules($pdo);
$diseases = $drData['diseases'];
$rules = $drData['rules'];

// patients list for form (clinicians) — patients will not see full list
$patientsList = [];
if ($isClinicianOrAdmin) {
  $patientsList = $pdo->query("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isPatient) {
  $pid = (int)($_SESSION['patient_id'] ?? 0);
  if ($pid > 0) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM patients WHERE id = ? LIMIT 1");
    $stmt->execute([$pid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) $patientsList[] = $r;
  }
}

// handle POST (Run & Save) — only clinicians/admin allowed to run_save
$flash = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_save') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } elseif (!$isClinicianOrAdmin) {
    $error = 'Only clinicians / admin can run diagnoses.';
  } else {
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $selected = $_POST['symptoms'] ?? [];
    $notes = trim((string)($_POST['notes'] ?? ''));

    // sanitize and require at least one symptom
    $selected = array_values(array_unique(array_map('intval', is_array($selected) ? $selected : [])));

    if ($patientId <= 0) {
      $error = 'Please select a patient.';
    } elseif (!ensurePatientExists($pdo, $patientId)) {
      $error = 'Patient not found.';
    } elseif (empty($selected)) {
      $error = 'Select at least one symptom.';
    } else {
      try {
        $perDisease = computeConfidences($diseases, $rules, $selected);
        $dec = decidePrimary($perDisease);
        $primaryId = $dec['primary'];
        $primaryConf = $dec['confidence'];

        $resultText = $primaryId ? ("Likely: " . ($diseases[$primaryId]['name'] ?? '')) : 'Undetermined';
        $payload = [
          'symptom_ids' => $selected,
          'per_disease' => array_values($perDisease),
          'computed_at' => date('c'),
          'computed_by' => $meId
        ];

        $diagId = saveDiagnosis($pdo, $patientId, $meId, $payload, $resultText, $primaryId, $primaryConf, $notes);
        $_SESSION['flash'] = 'Diagnosis computed & saved.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
      } catch (Throwable $e) {
        error_log("diagnosis save error: " . $e->getMessage());
        $error = 'Failed to save diagnosis. Check logs.';
      }
    }
  }
}

// flash
if (!empty($_SESSION['flash'])) {
  $flash = $_SESSION['flash'];
  unset($_SESSION['flash']);
}

// fetch recent diagnoses (apply patient filter automatically for patient role)
$filterPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : null;
if ($isPatient) $filterPatientId = (int)($_SESSION['patient_id'] ?? $filterPatientId);

$params = [];
$where = "1=1";
if ($filterPatientId) {
  $where = "d.patient_id = ?";
  $params[] = $filterPatientId;
}

$sql = "SELECT d.id, d.uuid, d.patient_id, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
               d.evaluator_user_id, COALESCE(u.full_name,u.username) AS evaluator,
               d.result, d.result_disease_id, d.confidence, d.notes, d.created_at
        FROM diagnoses d
        JOIN patients p ON p.id = d.patient_id
        LEFT JOIN users u ON u.id = d.evaluator_user_id
        WHERE $where
        ORDER BY d.created_at DESC
        LIMIT 200";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$diagnoses = $stm->fetchAll(PDO::FETCH_ASSOC);

// load recommended drugs for displayed diagnoses (batch)
$diseaseIds = [];
foreach ($diagnoses as $dd) {
  if (!empty($dd['result_disease_id'])) $diseaseIds[] = (int)$dd['result_disease_id'];
}
$recMap = loadRecommendedDrugsMap($pdo, $diseaseIds);

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Diagnoses</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body {
      font-family: system-ui, Arial;
      background: #f5f7fb;
      color: #111;
      margin: 0;
      padding: 0;
    }

    .container {
      max-width: 1100px;
      margin: 0 auto
    }

    .card {
      background: #fff;
      padding: 14px;
      border-radius: 10px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, .06);
      margin-bottom: 12px
    }

    .muted {
      color: #666;
      font-size: 13px
    }

    .wrapper {
      display: grid;
      grid-template-columns: 420px 1fr;
      gap: 1.5rem;
    }

    @media(max-width:900px) {
      .wrapper {
        grid-template-columns: 1fr
      }
    }

    .symp-cat {
      border: 1px solid #eef2f8;
      padding: 8px;
      border-radius: 8px;
      margin-bottom: 8px
    }

    .symp-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px
    }

    .symp-item {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 6px 8px;
      background: #fbfdff;
      border-radius: 6px;
      border: 1px solid #eef6ff
    }

    .mono {
      white-space: pre-wrap;
      background: #f7f8fb;
      padding: 8px;
      border-radius: 6px;
      font-family: monospace
    }

    .btn {
      background: #246;
      color: #fff;
      padding: 8px 12px;
      border-radius: 8px;
      border: 0;
      cursor: pointer
    }

    .btn-ghost {
      background: transparent;
      border: 1px solid #c8d0e6;
      color: #123;
      padding: 6px 8px;
      border-radius: 6px
    }

    .err_msg {
      border-left: 4px solid #16a34a;
      color: #064e3b;
    }
  </style>
</head>

<body>
  <?php include __DIR__ . '/../includes/header.php' ?>
  <div class="container">

    <?php if ($flash): ?><div class="card err_msg"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="card err_msg"><?= h($error) ?></div><?php endif; ?>

    <div class="wrapper">
      <aside class="card">
        <h2>Run & Save Diagnosis</h2>

        <?php if ($isClinicianOrAdmin): ?>
          <form method="post" action="<?= h($_SERVER['PHP_SELF']) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="run_save">

            <div style="margin-bottom:10px">
              <label style="font-weight:600">Patient</label>
              <select name="patient_id" required>
                <option value="">— choose patient —</option>
                <?php foreach ($patientsList as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"><?= h($p['last_name'] . ', ' . $p['first_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="margin-bottom:10px">
              <label style="font-weight:600">Select symptoms</label>
              <div class="muted" style="margin-bottom:6px">Tick relevant symptoms (grouped by category).</div>
              <?php foreach ($catMap as $cat): ?>
                <div class="symp-cat">
                  <div style="font-weight:700"><?= h($cat['meta']['code'] ?? '') ?> — <?= h($cat['meta']['description'] ?? '') ?></div>
                  <div class="symp-list" style="margin-top:8px">
                    <?php foreach ($cat['items'] as $s): ?>
                      <label class="symp-item"><input type="checkbox" name="symptoms[]" value="<?= (int)$s['id'] ?>"> <?= h($s['label']) ?></label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div style="margin-bottom:10px">
              <label style="font-weight:600">Clinical notes (optional)</label>
              <textarea name="notes" rows="3"></textarea>
            </div>

            <div style="display:flex;gap:8px">
              <button class="btn" type="submit">Run & Save Diagnosis</button>
              <button class="btn-ghost" type="reset">Reset</button>
            </div>

            <div style="margin-top:12px" class="muted">System computes weighted matches and saves the best match if it meets the configured threshold.</div>
          </form>
        <?php else: ?>
          <div class="muted">You do not have permission to create diagnoses. Clinicians (doctor / nurse) and admin can run diagnoses.</div>
        <?php endif; ?>
      </aside>

      <main>
        <section class="card">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <h2 style="margin:0">Recent Diagnoses</h2>
            <?php if ($isClinicianOrAdmin): ?>
              <form method="get" style="margin:0">
                <label class="muted">Filter patient:
                  <select name="patient_id" onchange="this.form.submit()">
                    <option value="">— all —</option>
                    <?php foreach ($patientsList as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" <?= (isset($filterPatientId) && $filterPatientId == (int)$p['id']) ? 'selected' : '' ?>><?= h($p['last_name'] . ', ' . $p['first_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </form>
            <?php endif; ?>
          </div>

          <?php if (empty($diagnoses)): ?>
            <div class="muted">No diagnoses yet.</div>
          <?php else: ?>
            <?php foreach ($diagnoses as $d): ?>
              <article style="padding:10px;border-radius:8px;border:1px solid #eef2f8;margin-bottom:12px">
                <div style="display:flex;justify-content:space-between">
                  <div>
                    <div style="font-weight:700"><?= h($d['result'] ?: '—') ?></div>
                    <div class="muted">Patient: <?= h($d['patient_name']) ?> • Evaluator: <?= h($d['evaluator'] ?? '-') ?> • <?= h($d['created_at']) ?></div>
                  </div>
                  <div class="muted"><?= $d['confidence'] !== null ? h((string)$d['confidence']) . '%' : '' ?></div>
                </div>

                <?php if ($d['notes']): ?><div style="margin-top:8px" class="muted"><strong>Notes:</strong> <?= h($d['notes']) ?></div><?php endif; ?>

                <?php if (!empty($d['result_disease_id']) && !empty($recMap[(int)$d['result_disease_id']])): ?>
                  <div style="margin-top:8px">
                    <strong>Recommended drugs:</strong>
                    <ul>
                      <?php foreach ($recMap[(int)$d['result_disease_id']] as $rd): ?>
                        <li><?= h($rd['name']) ?> (<?= h($rd['code']) ?>)</li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>

              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
      </main>
    </div>
  </div>
</body>

</html>