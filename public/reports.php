<?php
require_once __DIR__.'/../api/csrf.php';
require_once __DIR__.'/../api/util.php';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$qs = http_build_query(['action'=>'diagnosis_counts','from'=>$from,'to'=>$to]);
$diagJson = @file_get_contents("http://localhost/doctor/api/reports.php?{$qs}");
$diag = $diagJson ? (json_decode($diagJson, true)['data']['rows'] ?? []) : [];
$stockJson = @file_get_contents("http://localhost/doctor/api/reports.php?action=low_stock");
$stock = $stockJson ? (json_decode($stockJson, true)['data']['rows'] ?? []) : [];
$rxJson = @file_get_contents("http://localhost/doctor/api/reports.php?action=rx_counts");
$rx = $rxJson ? (json_decode($rxJson, true)['data']['rows'] ?? []) : [];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reports</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 p-6">
  <div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-semibold mb-4">Reports</h1>
    <form class="flex gap-2 mb-4">
      <input name="from" type="date" value="<?=h($from)?>" class="border px-3 py-2 rounded">
      <input name="to" type="date" value="<?=h($to)?>" class="border px-3 py-2 rounded">
      <button class="bg-blue-600 text-white px-4 rounded">Filter</button>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Diagnosis Counts</h2>
        <div class="space-y-2">
          <?php foreach ($diag as $r): ?>
            <div class="border rounded p-2 flex justify-between">
              <span><?=h($r['disease'] ?? 'Undetermined')?></span><span><?= (int)$r['cnt'] ?></span>
            </div>
          <?php endforeach; if (!$diag): ?>
            <div class="p-3 bg-gray-100 rounded">No data.</div>
          <?php endif; ?>
        </div>
      </section>

      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Prescriptions by Status</h2>
        <div class="space-y-2">
          <?php foreach ($rx as $r): ?>
            <div class="border rounded p-2 flex justify-between">
              <span><?=h($r['status'])?></span><span><?= (int)$r['cnt'] ?></span>
            </div>
          <?php endforeach; if (!$rx): ?>
            <div class="p-3 bg-gray-100 rounded">No data.</div>
          <?php endif; ?>
        </div>
      </section>

      <section class="bg-white border rounded p-4">
        <h2 class="font-medium mb-2">Low Stock</h2>
        <div class="space-y-2">
          <?php foreach ($stock as $s): ?>
            <div class="border rounded p-2">
              <div class="font-medium"><?=h($s['name'])?></div>
              <div class="text-sm text-gray-600">Stock: <?= (int)$s['stock'] ?> • Reorder: <?= (int)$s['reorder_level'] ?></div>
            </div>
          <?php endforeach; if (!$stock): ?>
            <div class="p-3 bg-gray-100 rounded">All good.</div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</body>
</html>