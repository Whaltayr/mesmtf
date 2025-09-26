<?php
require_once __DIR__.'/../api/csrf.php';
require_once __DIR__.'/../api/util.php';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
// Load patients
$patJson = @file_get_contents("http://localhost/doctor/api/patients.php?action=list&per=500");
$patients = $patJson ? (json_decode($patJson, true)['data']['rows'] ?? []) : [];
// Load diseases (for manual selection if needed)
$dis = @file_get_contents("http://localhost/doctor/api/treatments.php?action=diseases");
$diseases = $dis ? (json_decode($dis, true)['data']['rows'] ?? []) : [];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Treatment</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 p-6">
  <div class="max-w-5xl mx-auto">
    <h1 class="text-2xl font-semibold mb-4">Treatment (Prescription)</h1>
    <?php if ($msg): ?><div class="mb-3 p-3 bg-green-50 border rounded"><?=h($msg)?></div><?php endif; ?>
    <?php if ($err): ?><div class="mb-3 p-3 bg-red-50 border rounded"><?=h($err)?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Create Prescription</h2>
        <form method="post" action="/doctor/api/treatments.php" id="rxForm">
          <?=csrf_input()?>
          <input type="hidden" name="action" value="create_prescription">
          <div class="mb-2">
            <label class="text-sm">Patient</label>
            <select name="patient_id" required class="border px-3 py-2 rounded w-full">
              <option value="">— Choose patient —</option>
              <?php foreach ($patients as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?=h($p['last_name'].', '.$p['first_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="text-sm">Disease (from last diagnosis or choose)</label>
            <select name="disease_id" class="border px-3 py-2 rounded w-full">
              <option value="">— Auto from last diagnosis if available —</option>
              <?php foreach ($diseases as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?=h($d['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="text-sm">Notes</label>
            <input name="notes" class="border px-3 py-2 rounded w-full">
          </div>
          <div class="mt-3">
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Create + Recommend Items</button>
          </div>
        </form>
      </section>

      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Recent Prescriptions</h2>
        <?php $rxs = @file_get_contents("http://localhost/doctor/api/treatments.php?action=recent");
              $recent = $rxs ? (json_decode($rxs, true)['data']['rows'] ?? []) : []; ?>
        <div class="space-y-2 max-h-[460px] overflow-auto">
          <?php foreach ($recent as $r): ?>
            <div class="border rounded p-2">
              <div class="font-medium"><?=h($r['patient'])?></div>
              <div class="text-sm text-gray-600">Status: <?=h($r['status'])?> • <?=h($r['created_at'])?></div>
            </div>
          <?php endforeach; if (!$recent): ?>
            <div class="p-3 bg-gray-100 rounded">No prescriptions yet.</div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</body>
</html>