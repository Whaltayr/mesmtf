<?php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

$api = __DIR__ . '/../api';
require_once $api . '/db.php';
if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? 'all');
$results = ['doctors' => [], 'drugs' => []];

if ($q !== '') {
  $pdo = pdo();
  $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

  if ($type === 'all' || $type === 'doctors') {
    $stmt = $pdo->prepare(
      "SELECT id, username, full_name, specialty, role_id
             FROM users
             WHERE is_active = 1 AND role_id IN (4,5) AND (full_name LIKE ? OR username LIKE ? OR COALESCE(specialty,'') LIKE ?)
             ORDER BY full_name
             LIMIT 50"
    );
    $stmt->execute([$like, $like, $like]);
    $results['doctors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  if ($type === 'all' || $type === 'drugs') {
    $stmt = $pdo->prepare(
      "SELECT id, code, name, description, stock, unit
             FROM drugs
             WHERE (name LIKE ? OR code LIKE ?)
             ORDER BY name
             LIMIT 50"
    );
    $stmt->execute([$like, $like]);
    $results['drugs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}
?>


<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/css/dashboard.css">

</head>

<body class="bg-gray-50 p-6">
  <header class="header">
    <div class="header-left">
      <img src="assets/img/logo.png" alt="Namibia Logo" class="logo">
      <div>
        <h1>MHSS</h1>
        <h4>Dashboard</h4>
      </div>
    </div>

    <!-- Search  -->
    <div class="header-search" style="flex:1; max-width:720px; margin:0 16px; position:relative;">
      <form id="globalSearchForm" method="get" action="<?= h($_SERVER['PHP_SELF']) ?>" role="search" aria-label="Global search">
        <div style="display:flex; gap:8px;">
          <input id="globalSearchInput" name="q" type="search" placeholder="Search doctors, drugs, nurses..." value="<?= h($q) ?>" aria-label="Search" autocomplete="off" />
          <select id="globalSearchType" name="type" aria-label="Search type">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All</option>
            <option value="doctors" <?= $type === 'doctors' ? 'selected' : '' ?>>Doctors</option>
            <option value="drugs" <?= $type === 'drugs' ? 'selected' : '' ?>>Drugs</option>
          </select>
          <button id="globalSearchBtn" type="submit">Search</button>
        </div>
      </form>

      <!-- Results  -->
      <?php if ($q !== ''): ?>
        <div id="globalSearchResults" class="search-results" role="region" aria-live="polite">
          <?php if (empty($results['doctors']) && empty($results['drugs'])): ?>
            <div class="search-empty">No results for "<?= h($q) ?>"</div>
          <?php else: ?>
            <?php if (!empty($results['doctors'])): ?>
              <div style="margin-bottom:8px;font-weight:700">Doctors & Nurses (<?= count($results['doctors']) ?>)</div>
              <?php foreach ($results['doctors'] as $d): ?>
                <div class="search-card">
                  <div class="meta">
                    <div class="search-avatar"><?= h(mb_strtoupper(substr($d['full_name'] ?: $d['username'], 0, 1))) ?></div>
                    <div class="info">
                      <div class="title"><?= h($d['full_name'] ?: $d['username']) ?></div>
                      <div class="sub"><?= h($d['specialty'] ?? '') ?> ID: <?= (int)$d['id'] ?></div>
                    </div>
                  </div>

                  <div class="actions" style="display:flex;gap:6px;">
                    <!-- 1) link to the appointement with the doctor selected -->
                    <a class="btn" href="/doctor/public/appointments.php?prefill_doctor_id=<?= (int)$d['id'] ?>&prefill_doctor_name=<?= urlencode($d['full_name'] ?: $d['username']) ?>">Select</a>


                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($results['drugs'])): ?>
              <div style="margin-top:8px;margin-bottom:8px;font-weight:700">Drugs (<?= count($results['drugs']) ?>)</div>
              <?php foreach ($results['drugs'] as $r): ?>
                <div class="search-card">
                  <div class="meta">
                    <div class="search-avatar"><?= h(mb_strtoupper(substr($r['name'] ?: $r['code'], 0, 1))) ?></div>
                    <div class="info">
                      <div class="title"><?= h($r['name']) ?> <small style="color:#666">[<?= h($r['code']) ?>]</small></div>
                      <div class="sub"><?= h(substr($r['description'], 0, 120)) ?> <?= $r['unit'] ? '• ' . h($r['unit']) : '' ?></div>
                    </div>
                  </div>
                  <div class="actions">
                    <a class="btn" href="/doctor/public/drug.php?id=<?= (int)$r['id'] ?>">Open</a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <a href="logout.php" class="logout">Log Out</a>
  </header>


  <main class="dashboard">

    <section class="card large">
      <a href="diagnosis.php">
        <h3>Diagnosis</h3>
        <img src="assets/img/record.jpg" alt="Prescriptions">
      </a>
    </section>

    <section class="card medium">
      <a href="medical_records.php">
        <h3>Medical Records</h3>
        <img src="assets/img/Medical Records.jpg" alt="Medical Records">
      </a>
    </section>

    <section class="card small">
      <a href="appointments.php">
        <h3>Appointments</h3>
        <img src="assets/img/Appointments.jpg" alt="Appointments">
      </a>
    </section>

    <section class="card small">
      <a href="pharmacy.php">
        <h3>Pharmacy</h3>
        <img src="assets/img/Prescription.jpeg" alt="Calendar">
      </a>
    </section>
        <section class="card small">
      <a href="prescriptioins.php">
        <h3>Prescriptions</h3>
        <img src="assets/img/Prescription.jpeg" alt="Calendar">
      </a>
    </section>

    <section class="card small">
      <h3>Treatment</h3>
      <img src="assets/img/treatment.webp" alt="Calendar">
    </section>


  </main>

  <footer class="footer">
    <p>Ministry of Health &amp; Social Services © 2025 | Patient Dashboard</p>
  </footer>

</body>

</html>