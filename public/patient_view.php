<?php
// public/patient_view.php (styles moved to head <style> block)
declare(strict_types=1);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/csrf.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// require login
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$meId   = (int)($_SESSION['user_id']);
$meRole = (int)($_SESSION['role_id'] ?? 0);
$meFull = $_SESSION['full_name'] ?? '(unknown)';

$pdo = pdo();

// role helpers
$isAdminOrClinician = in_array($meRole, [1,4,5,2], true); // admin, doctor, nurse, receptionist
$isPatient = ($meRole === 3);

// determine requested patient id
$reqId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// if viewer is patient, figure their patient id and override request
if ($isPatient) {
    if (empty($_SESSION['patient_id'])) {
        $st = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
        $st->execute([$meId]);
        $_SESSION['patient_id'] = (int)$st->fetchColumn();
    }
    $reqId = (int)($_SESSION['patient_id'] ?? 0);
}

if ($reqId <= 0) {
    http_response_code(404);
    echo "Patient not found.";
    exit;
}

// load patient
$stmt = $pdo->prepare("SELECT id, user_id, external_identifier, first_name, last_name, gender, date_of_birth, contact_phone, address, created_by, created_at FROM patients WHERE id = ? LIMIT 1");
$stmt->execute([$reqId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    http_response_code(404);
    echo "Patient not found.";
    exit;
}

if (! $isAdminOrClinician && !($isPatient && (int)$patient['id'] === (int)($_SESSION['patient_id'] ?? 0))) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

// fetch diagnoses (recent)
$diagStmt = $pdo->prepare(
    "SELECT d.id, d.uuid, d.result, d.result_disease_id, d.confidence, d.notes, d.created_at,
            COALESCE(u.full_name,u.username) AS evaluator
     FROM diagnoses d
     LEFT JOIN users u ON u.id = d.evaluator_user_id
     WHERE d.patient_id = ?
     ORDER BY d.created_at DESC
     LIMIT 200"
);
$diagStmt->execute([$patient['id']]);
$diagnoses = $diagStmt->fetchAll(PDO::FETCH_ASSOC);

// fetch appointments (past and upcoming)
$appStmt = $pdo->prepare(
    "SELECT a.id, a.scheduled_at, a.duration_minutes, a.status,
            COALESCE(u.full_name,u.username) AS doctor
     FROM appointments a
     LEFT JOIN users u ON u.id = a.doctor_id
     WHERE a.patient_id = ?
     ORDER BY a.scheduled_at DESC
     LIMIT 200"
);
$appStmt->execute([$patient['id']]);
$appointments = $appStmt->fetchAll(PDO::FETCH_ASSOC);

// fetch prescriptions with items (batch)
$presStmt = $pdo->prepare(
    "SELECT pr.id, pr.created_at, pr.status, COALESCE(u.full_name,u.username) AS doctor
     FROM prescriptions pr
     LEFT JOIN users u ON u.id = pr.doctor_id
     WHERE pr.patient_id = ?
     ORDER BY pr.created_at DESC
     LIMIT 200"
);
$presStmt->execute([$patient['id']]);
$prescriptions = $presStmt->fetchAll(PDO::FETCH_ASSOC);

// load prescription items for these prescriptions in batch
$presIds = array_map(function($r){ return (int)$r['id']; }, $prescriptions);
$presItemsMap = [];
if (!empty($presIds)) {
    $place = implode(',', array_fill(0, count($presIds), '?'));
    $sql = "SELECT pi.prescription_id, pi.id AS item_id, pi.dosage, pi.quantity, d.name AS drug_name, d.code AS drug_code
            FROM prescription_items pi
            JOIN drugs d ON d.id = pi.drug_id
            WHERE pi.prescription_id IN ($place)
            ORDER BY pi.id";
    $stm = $pdo->prepare($sql);
    $stm->execute($presIds);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $presItemsMap[(int)$r['prescription_id']][] = $r;
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Patient: <?= h($patient['first_name'].' '.$patient['last_name']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#fff;
      --primary:#246;
      --muted:#666;
      --accent:#16a34a;
      --danger:#7f1d1d;
      --border:#eef2f8;
      --shadow: 0 6px 18px rgba(0,0,0,.06);
      font-family: system-ui, Arial, sans-serif;
    }

    body{
      background:var(--bg);
      color:#111;
      margin:0;
      padding:20px;
    }

    .container{ max-width:1100px; margin:0 auto; }

    header.site-header{
      display:flex;
      justify-content:space-between;
      align-items:center;
      margin-bottom:12px;
    }

    h1{ margin:0; font-size:20px; }
    .muted{ color:var(--muted); font-size:13px; }

    .btn{ background:var(--primary); color:#fff; padding:8px 12px; border-radius:8px; border:0; cursor:pointer; text-decoration:none; display:inline-block; }
    .btn-ghost{ background:transparent; border:1px solid #c8d0e6; color:#123; padding:6px 8px; border-radius:6px; text-decoration:none; display:inline-block; }
    .danger{ color:var(--danger); }

    .card{
      background:var(--card);
      padding:14px;
      border-radius:10px;
      box-shadow:var(--shadow);
      margin-bottom:12px;
    }

    .row{ display:flex; gap:12px; align-items:flex-start; }
    .col{ flex:1; }

    .label{ font-weight:700; color:#222; margin-bottom:6px; }
    .small{ font-size:13px; color:#444; }

    .list-item{ padding:10px; border-radius:8px; border:1px solid var(--border); margin-bottom:8px; background:var(--card); }

    pre.mono{ background:#f7f8fb; padding:8px; border-radius:6px; white-space:pre-wrap; }

    /* responsive */
    @media (max-width:900px){
      .row { flex-direction:column; }
    }
  </style>
</head>
<body>
  <div class="container">
    <header class="site-header">
      <div>
        <h1>Patient Record</h1>
        <div class="muted">Viewing: <?= h($patient['first_name'].' '.$patient['last_name']) ?> • Requested by: <?= h($meFull) ?></div>
      </div>

      <div class="actions">
        <a class="btn-ghost" href="medical_records.php">Back to Records</a>
        <?php if ($isAdminOrClinician || ($isPatient && (int)$patient['id'] === (int)($_SESSION['patient_id'] ?? 0))): ?>
          <a class="btn" href="medical_records.php?edit_id=<?= (int)$patient['id'] ?>">Edit</a>
        <?php endif; ?>
        <form method="post" action="logout.php" style="display:inline-block;margin:0">
          <?= csrf_input() ?>
          <button class="btn-ghost" type="submit">Logout</button>
        </form>
      </div>
    </header>

    <!-- Patient details -->
    <section class="card">
      <div class="row">
        <div class="col">
          <div class="label">Name</div>
          <div class="small"><?= h($patient['first_name'].' '.$patient['last_name']) ?></div>
        </div>

        <div class="col">
          <div class="label">External ID</div>
          <div class="small"><?= h($patient['external_identifier'] ?? '-') ?></div>
        </div>

        <div class="col">
          <div class="label">Gender</div>
          <div class="small"><?= h($patient['gender'] ?? '-') ?></div>
        </div>
      </div>

      <div class="row" style="margin-top:10px;">
        <div class="col">
          <div class="label">Date of birth</div>
          <div class="small"><?= h($patient['date_of_birth'] ?? '-') ?></div>
        </div>
        <div class="col">
          <div class="label">Phone</div>
          <div class="small"><?= h($patient['contact_phone'] ?? '-') ?></div>
        </div>
        <div class="col">
          <div class="label">Created at</div>
          <div class="small"><?= h($patient['created_at'] ?? '-') ?></div>
        </div>
      </div>

      <div style="margin-top:10px;">
        <div class="label">Address</div>
        <div class="small"><?= nl2br(h($patient['address'] ?? '-')) ?></div>
      </div>

      <?php if ($isAdminOrClinician): ?>
        <div style="margin-top:12px;">
          <form method="post" action="medical_records.php" onsubmit="return confirm('Delete this patient and related records?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$patient['id'] ?>">
            <button class="btn-ghost danger" type="submit">Delete Patient</button>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <!-- Diagnoses -->
    <section class="card">
      <h2 style="margin-top:0">Diagnoses</h2>
      <?php if (empty($diagnoses)): ?>
        <div class="muted">No diagnoses recorded for this patient.</div>
      <?php else: ?>
        <?php foreach ($diagnoses as $d): ?>
          <div class="list-item">
            <div style="display:flex;justify-content:space-between;">
              <div>
                <div style="font-weight:700"><?= h($d['result'] ?: '—') ?></div>
                <div class="muted">By: <?= h($d['evaluator'] ?? '-') ?> • <?= h($d['created_at']) ?></div>
              </div>
              <div class="muted"><?= $d['confidence'] !== null ? h((string)$d['confidence']) . '%' : '' ?></div>
            </div>

            <?php if (!empty($d['notes'])): ?>
              <div style="margin-top:8px"><strong>Notes:</strong> <?= h($d['notes']) ?></div>
            <?php endif; ?>

            <?php
              if (!empty($d['result_disease_id'])) {
                  $q = $pdo->prepare("SELECT dr.name, dr.code FROM disease_recommended_drugs ddr JOIN drugs dr ON dr.id = ddr.drug_id WHERE ddr.disease_id = ?");
                  $q->execute([(int)$d['result_disease_id']]);
                  $rdr = $q->fetchAll(PDO::FETCH_ASSOC);
                  if ($rdr):
            ?>
              <div style="margin-top:8px">
                <strong>Recommended drugs:</strong>
                <ul>
                  <?php foreach ($rdr as $rd): ?><li><?= h($rd['name']) ?> (<?= h($rd['code']) ?>)</li><?php endforeach; ?>
                </ul>
              </div>
            <?php
                  endif;
              }
            ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- Appointments -->
    <section class="card">
      <h2 style="margin-top:0">Appointments</h2>
      <?php if (empty($appointments)): ?>
        <div class="muted">No appointments for this patient.</div>
      <?php else: ?>
        <?php foreach ($appointments as $a): ?>
          <div class="list-item">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-weight:700"><?= h($a['doctor'] ?: '—') ?></div>
                <div class="muted"><?= h($a['scheduled_at']) ?> • <?= (int)$a['duration_minutes'] ?> min</div>
              </div>
              <div style="display:flex;gap:8px;align-items:center;">
                <div class="small"><?= h($a['status']) ?></div>
                <?php if ($a['status'] === 'booked' && ($isAdminOrClinician || ($isPatient && (int)$patient['id']===(int)$_SESSION['patient_id']))): ?>
                  <form method="post" action="../api/appointments.php" onsubmit="return confirm('Cancel this appointment?')">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="btn-ghost" type="submit">Cancel</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- Prescriptions -->
    <section class="card">
      <h2 style="margin-top:0">Prescriptions</h2>
      <?php if (empty($prescriptions)): ?>
        <div class="muted">No prescriptions found for this patient.</div>
      <?php else: ?>
        <?php foreach ($prescriptions as $pr): ?>
          <div class="list-item">
            <div style="display:flex;justify-content:space-between;">
              <div>
                <div style="font-weight:700">Prescription #<?= (int)$pr['id'] ?> — <?= h($pr['status']) ?></div>
                <div class="muted">By: <?= h($pr['doctor'] ?? '-') ?> • <?= h($pr['created_at']) ?></div>
              </div>
            </div>
            <?php if (!empty($presItemsMap[(int)$pr['id']])): ?>
              <div style="margin-top:8px">
                <ul>
                  <?php foreach ($presItemsMap[(int)$pr['id']] as $it): ?>
                    <li><?= h($it['drug_name']) ?> (<?= h($it['drug_code']) ?>) — <?= h($it['dosage']) ?> • Qty: <?= (int)$it['quantity'] ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

  </div>
</body>
</html>
