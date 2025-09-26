<?php
declare(strict_types=1);

ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/csrf.php';
require_once __DIR__ . '/../models/DrugAdministration.php';
require_once __DIR__ . '/../models/PharmacyActions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$pdo = pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function is_pharmacist(): bool { return (int)($_SESSION['role_id'] ?? 0) === 6; }
function is_nurse_doctor_admin(): bool { return in_array((int)($_SESSION['role_id'] ?? 0), [1,4,5], true); }
function ensure_csrf_or_die(array $p){ if(!csrf_check($p['csrf'] ?? '')) throw new RuntimeException('CSRF check failed'); }

if(!function_exists('set_flash')){
  function set_flash(string $m){ $_SESSION['flash']=$m; }
  function get_flash():?string{ $f=$_SESSION['flash']??null; if($f) unset($_SESSION['flash']); return $f; }
}

$flash=null;
$posted_patient_id = null;
$posted_item_id = null;

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    ensure_csrf_or_die($_POST);

    if ($action === 'record_administration') {
      if (!is_nurse_doctor_admin()) throw new RuntimeException('Forbidden');

      $patient_id = (int)($_POST['patient_id'] ?? 0);
      $pres_item  = (int)($_POST['prescription_item_id'] ?? 0);
      $dose = trim((string)($_POST['dose'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $admin_at = trim((string)($_POST['administered_at'] ?? date('Y-m-d H:i:s')));

      // keep for re-fill on error
      $posted_patient_id = $patient_id;
      $posted_item_id = $pres_item;

      if ($patient_id <= 0) throw new RuntimeException('Missing or invalid patient');
      if ($pres_item <= 0) throw new RuntimeException('Missing or invalid prescription item');

      $chk = $pdo->prepare("
        SELECT COUNT(*) FROM prescription_items pi
        JOIN prescriptions pr ON pr.id = pi.prescription_id
        WHERE pi.id = ? AND pr.patient_id = ?
      ");
      $chk->execute([$pres_item, $patient_id]);
      if ((int)$chk->fetchColumn() === 0) throw new RuntimeException('Item does not belong to patient');

      $da = new DrugAdministration($pdo);
      $da->patient_id = $patient_id;
      $da->nurse_id = (int)($_SESSION['user_id'] ?? 0);
      $da->prescription_item_id = $pres_item;
      $da->administered_at = $admin_at;
      $da->dose = $dose !== '' ? $dose : null;
      $da->notes = $notes !== '' ? $notes : null;

      if (!$da->create()) throw new RuntimeException('Could not save');

      set_flash('Administration recorded');
      header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    if ($action === 'dispense') {
      if (!is_pharmacist()) throw new RuntimeException('Forbidden');
      $prescription_id = (int)($_POST['prescription_id'] ?? 0);
      if ($prescription_id <= 0) throw new RuntimeException('Missing prescription');

      $items = $_POST['items'] ?? null;

      $pdo->beginTransaction();
      try{
        $stmt = $pdo->prepare("
          SELECT pi.id, pi.drug_id, pi.quantity, d.stock
          FROM prescription_items pi
          JOIN drugs d ON d.id = pi.drug_id
          WHERE pi.prescription_id = ?
          FOR UPDATE
        ");
        $stmt->execute([$prescription_id]);
        $rows = $stmt->fetchAll();
        if (!$rows) throw new RuntimeException('No items');

        $updDrug = $pdo->prepare("UPDATE drugs SET stock = stock - ? WHERE id = ?");
        $insAction = $pdo->prepare("INSERT INTO pharmacy_actions (prescription_item_id, pharmacist_id, action, notes) VALUES (?, ?, 'dispensed', ?)");

        foreach ($rows as $r) {
          $itemId=(int)$r['id']; $req=(int)$r['quantity']; $stk=(int)$r['stock'];
          $disp = $req;
          if (is_array($items) && array_key_exists($itemId,$items)) $disp = max(0, min($req, (int)$items[$itemId]));
          if ($disp>0 && $stk<$disp) throw new RuntimeException('Insufficient stock');
          if ($disp>0){
            $updDrug->execute([$disp, (int)$r['drug_id']]);
            $insAction->execute([$itemId, (int)($_SESSION['user_id'] ?? 0), "Dispensed {$disp}"]);
          }
        }

        $pdo->prepare("UPDATE prescriptions SET status='dispensed' WHERE id=?")->execute([$prescription_id]);
        $pdo->commit();

        set_flash('Prescription dispensed');
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
      } catch(Throwable $e){
        $pdo->rollBack(); throw $e;
      }
    }

    if ($action === 'reverse') {
      if (!is_pharmacist()) throw new RuntimeException('Forbidden');
      $pres_item_id = (int)($_POST['prescription_item_id'] ?? 0);
      $qty = (int)($_POST['quantity'] ?? 0);
      if ($pres_item_id<=0 || $qty<=0) throw new RuntimeException('Missing params');

      $pdo->beginTransaction();
      try{
        $r = $pdo->prepare("SELECT drug_id, prescription_id FROM prescription_items WHERE id=? LIMIT 1");
        $r->execute([$pres_item_id]);
        $row = $r->fetch();
        if (!$row) throw new RuntimeException('Item not found');

        $pdo->prepare("UPDATE drugs SET stock = stock + ? WHERE id=?")->execute([$qty, (int)$row['drug_id']]);
        $pdo->prepare("INSERT INTO pharmacy_actions (prescription_item_id, pharmacist_id, action, notes) VALUES (?, ?, 'reversed', ?)")->execute([$pres_item_id, (int)($_SESSION['user_id'] ?? 0), "Reversed {$qty}"]);
        $pdo->prepare("UPDATE prescriptions SET status='pending' WHERE id=?")->execute([(int)$row['prescription_id']]);

        $pdo->commit();
        set_flash('Reversal recorded');
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
      } catch(Throwable $e){
        $pdo->rollBack(); throw $e;
      }
    }

    throw new RuntimeException('Unknown action');
  }
} catch (Throwable $e) {
  error_log('Pharmacy POST err: '.$e->getMessage());
  $flash = 'Error: ' . ($e->getMessage() ?: 'Operation failed');
}

try {
  $drugs = $pdo->query("SELECT id, code, name, stock, reorder_level, unit FROM drugs ORDER BY name")->fetchAll();
  $lowStock = $pdo->query("SELECT id, code, name, stock, reorder_level FROM drugs WHERE stock <= reorder_level ORDER BY stock ASC")->fetchAll();

  $daModel = new DrugAdministration($pdo);
  $administrations = $daModel->read()->fetchAll();

  $paModel = new PharmacyActions($pdo);
  $pharmacyActions = $paModel->read()->fetchAll();
  $pendingPrescriptions = $paModel->getPendingPrescriptions()->fetchAll();

  $patients = $pdo->query("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name LIMIT 500")->fetchAll();

  $itemsByPatient = [];
  $stmt = $pdo->query("
    SELECT pi.id AS item_id, pr.patient_id, d.name AS drug_name, COALESCE(pi.dosage,'') AS dosage, pi.quantity, pr.status
    FROM prescriptions pr
    JOIN prescription_items pi ON pr.id = pi.prescription_id
    JOIN drugs d ON d.id = pi.drug_id
    WHERE pr.status IN ('pending','partially_dispensed','dispensed')
    ORDER BY pr.created_at DESC, pi.id DESC
  ");
  foreach ($stmt->fetchAll() as $row) {
    $itemsByPatient[(int)$row['patient_id']][] = $row;
  }

} catch (Throwable $e) {
  error_log('Pharmacy LOAD err: '.$e->getMessage());
  $flash = $flash ?? 'Failed loading data';
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Pharmacy</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,Arial,sans-serif;background:#f5f7fb;margin:0}
    .container{max-width:1200px;margin:0 auto;padding:20px}
    header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
    .grid{display:grid;grid-template-columns:1fr 420px;gap:16px}
    .card{background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:12px}
    table{width:100%;border-collapse:collapse;font-size:14px}
    th,td{padding:8px;border-bottom:1px solid #eef2f8;text-align:left}
    .btn{background:#2b5a89;color:#fff;border:0;padding:8px 10px;border-radius:8px;cursor:pointer}
    .btn-ghost{background:transparent;border:1px solid #cfd6ea;color:#2b5a89;padding:6px 8px;border-radius:6px;cursor:pointer}
    input,select,textarea{padding:8px;border:1px solid #d3dae8;border-radius:6px;font-size:14px}
    form.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .notice{background:#e6ffef;color:#064e3b;padding:10px;border-radius:8px;margin-bottom:12px}
    .error{background:#fff1f2;color:#7f1d1d;padding:10px;border-radius:8px;margin-bottom:12px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <?php include __DIR__ . '/../includes/header.php' ?>

  <div class="container">
    <header>
      <div><h2 style="margin:0">Pharmacy — Drugs & Administration</h2><div style="font-size:13px;color:#666">User: <?= h($_SESSION['full_name'] ?? 'guest') ?> (role <?= (int)($_SESSION['role_id'] ?? 0) ?>)</div></div>
      <div style="display:flex;gap:8px">
        <a class="btn-ghost" href="appointments.php">Appointments</a>
        <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn-ghost" type="submit">Logout</button></form>
      </div>
    </header>

    <?php if ($f = get_flash()): ?><div class="notice"><?= h($f) ?></div><?php endif; ?>
    <?php if ($flash && strncmp($flash,'Error:',6)===0): ?><div class="error"><?= h($flash) ?></div><?php endif; ?>

    <div class="grid">
      <div>
        <div class="card" style="margin-bottom:12px">
          <h3 style="margin:0 0 8px 0">Drugs</h3>
          <table><thead><tr><th>Code</th><th>Name</th><th>Stock</th><th>Unit</th></tr></thead>
            <tbody>
              <?php foreach($drugs as $d): ?>
              <tr><td><?= h($d['code']) ?></td><td><?= h($d['name']) ?></td><td><?= (int)$d['stock'] ?> (reorder <?= (int)$d['reorder_level'] ?>)</td><td><?= h($d['unit']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card" style="margin-bottom:12px">
          <h3 style="margin:0 0 8px 0">Low stock</h3>
          <?php if(!$lowStock): ?>
            <div style="font-size:13px;color:#666">No low stock items.</div>
          <?php else: ?>
            <table><thead><tr><th>Code</th><th>Name</th><th>Stock</th></tr></thead>
              <tbody>
                <?php foreach($lowStock as $l): ?>
                <tr><td><?= h($l['code']) ?></td><td><?= h($l['name']) ?></td><td><?= (int)$l['stock'] ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="margin:0 0 8px 0">Recent administrations</h3>
          <?php if(!$administrations): ?>
            <div style="font-size:13px;color:#666">No records.</div>
          <?php else: ?>
            <table>
              <thead><tr><th>When</th><th>Patient</th><th>Nurse</th><th>Drug</th><th>Dose</th></tr></thead>
              <tbody>
                <?php foreach($administrations as $a): ?>
                <tr>
                  <td><?= h($a['administered_at']) ?></td>
                  <td><?= h($a['first_name'].' '.$a['last_name']) ?></td>
                  <td><?= h($a['nurse_name'] ?? '-') ?></td>
                  <td><?= h($a['drug_name'] ?? '-') ?></td>
                  <td><?= h($a['dose'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <aside>
        <div class="card" style="margin-bottom:12px">
          <h3 style="margin:0 0 8px 0">Record administration</h3>
          <?php if(!is_nurse_doctor_admin()): ?>
            <div style="font-size:13px;color:#666">Only nurse/doctor/admin may record.</div>
          <?php else: ?>
            <form method="post" class="inline" id="adminForm">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="record_administration">

              <label>Patient
                <select name="patient_id" id="patient_id" required>
                  <option value="">-- choose --</option>
                  <?php foreach($patients as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= ($posted_patient_id && (int)$p['id']===(int)$posted_patient_id)?'selected':'' ?>>
                    <?= h($p['last_name'].', '.$p['first_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label>Prescription item
                <select id="prescription_item_id" name="prescription_item_id" required>
                  <?php
                    if ($posted_patient_id && !empty($itemsByPatient[$posted_patient_id])) {
                      echo '<option value="">-- choose item --</option>';
                      foreach($itemsByPatient[$posted_patient_id] as $it){
                        $sel = ($posted_item_id && (int)$posted_item_id === (int)$it['item_id']) ? 'selected' : '';
                        $label = $it['drug_name'].' — '.($it['dosage']??'').' x '.(int)$it['quantity'].' ('.$it['status'].') #'.(int)$it['item_id'];
                        echo '<option value="'.(int)$it['item_id'].'" '.$sel.'>'.h($label).'</option>';
                      }
                    } else {
                      echo '<option value="">-- choose patient first --</option>';
                    }
                  ?>
                </select>
              </label>

              <label>Dose
                <input name="dose" value="<?= h($_POST['dose'] ?? '') ?>" placeholder="500mg">
              </label>

              <label>When
                <input name="administered_at" type="datetime-local" value="<?= h($_POST['administered_at'] ?? date('Y-m-d\TH:i')) ?>">
              </label>

              <label style="flex-basis:100%">Notes
                <textarea name="notes" rows="2" style="width:100%"><?= h($_POST['notes'] ?? '') ?></textarea>
              </label>

              <div style="width:100%;text-align:right">
                <button class="btn" type="submit">Record</button>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <div class="card" style="margin-bottom:12px">
          <h3 style="margin:0 0 8px 0">Pending prescriptions</h3>
          <?php if(!is_pharmacist()): ?>
            <div style="font-size:13px;color:#666">Only pharmacists can dispense.</div>
          <?php else: ?>
            <?php if(!$pendingPrescriptions): ?>
              <div style="font-size:13px;color:#666">No pending prescriptions.</div>
            <?php else: ?>
              <table>
                <thead><tr><th>Prescription</th><th>Patient</th><th>Drug</th><th>Qty</th><th>Action</th></tr></thead>
                <tbody>
                  <?php foreach($pendingPrescriptions as $pr): ?>
                  <tr>
                    <td><?= (int)$pr['prescription_id'] ?></td>
                    <td><?= h($pr['first_name'].' '.$pr['last_name']) ?></td>
                    <td><?= h($pr['drug_name']) ?></td>
                    <td><?= (int)$pr['quantity'] ?></td>
                    <td>
                      <form method="post" style="display:inline-block" onsubmit="return confirm('Dispense?')">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="dispense">
                        <input type="hidden" name="prescription_id" value="<?= (int)$pr['prescription_id'] ?>">
                        <button class="btn" type="submit">Dispense</button>
                      </form>
                      <form method="post" style="display:inline-block" onsubmit="return confirm('Reverse 1?')">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="reverse">
                        <input type="hidden" name="prescription_item_id" value="<?= (int)$pr['item_id'] ?>">
                        <input type="hidden" name="quantity" value="1">
                        <button class="btn-ghost" type="submit">Reverse 1</button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="margin:0 0 8px 0">Pharmacy actions</h3>
          <?php if(!$pharmacyActions): ?>
            <div style="font-size:13px;color:#666">No actions recorded.</div>
          <?php else: ?>
            <table>
              <thead><tr><th>When</th><th>Action</th><th>Item</th><th>By</th></tr></thead>
              <tbody>
                <?php foreach($pharmacyActions as $pa): ?>
                <tr>
                  <td><?= h($pa['created_at'] ?? '') ?></td>
                  <td><?= h($pa['action']) ?></td>
                  <td><?= h($pa['drug_name'] ?? ('item '.$pa['prescription_item_id'])) ?></td>
                  <td><?= h($pa['pharmacist_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </div>

  <script>
    // tiny glue. dont breaky plz
    const itemsByPatient = <?= json_encode($itemsByPatient ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const patientSel = document.getElementById('patient_id');
    const itemSel = document.getElementById('prescription_item_id');
    const form = document.getElementById('adminForm');
    const postedPatientId = <?= json_encode($posted_patient_id, JSON_NUMERIC_CHECK) ?>;
    const postedItemId = <?= json_encode($posted_item_id, JSON_NUMERIC_CHECK) ?>;

    function fillItems(pid, preselectId){
      itemSel.innerHTML = '';
      const items = itemsByPatient[pid] || [];
      if (!pid || items.length===0){
        itemSel.insertAdjacentHTML('beforeend','<option value="">No prescription items</option>');
        // keep enabled so browser always posts a value (even empty) to avoid silent miss
        itemSel.disabled = false;
        return;
      }
      itemSel.disabled = false;
      itemSel.insertAdjacentHTML('beforeend','<option value="">-- choose item --</option>');
      for (const it of items){
        const o = document.createElement('option');
        o.value = it.item_id;
        o.textContent = `${it.drug_name} — ${it.dosage || ''} x ${it.quantity} (${it.status}) #${it.item_id}`;
        if (preselectId && String(preselectId) === String(it.item_id)) o.selected = true;
        itemSel.appendChild(o);
      }
    }

    if (patientSel){
      patientSel.addEventListener('change', ()=> fillItems(parseInt(patientSel.value||'0',10), null));
      window.addEventListener('DOMContentLoaded', ()=>{
        if (postedPatientId){
          patientSel.value = String(postedPatientId);
          fillItems(parseInt(patientSel.value||'0',10), postedItemId || null);
        } else {
          // start enabled w/ placeholder so field always posts
          itemSel.disabled = false;
        }
      });
    }

    // ensure not disabled when submitting (some browsers may submit too fast)
    if (form){
      form.addEventListener('submit', ()=>{
        if (itemSel.disabled) itemSel.disabled = false;
      });
    }
  </script>
</body>
</html>