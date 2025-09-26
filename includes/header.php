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
<style>
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #c6c7eb;
        padding: 1rem 1.5rem;
        margin-bottom: 3rem;
        border-bottom: 2px solid #003366;
        flex-wrap: wrap;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
    }
    .header-text{
        color: #111;
        & h1{
            margin: 0;
        }
         & h4{
            margin: 0;
        }
    }

    .logo {
        width: 80px;
        height: 50px;
    }
header >.btn-ghost{
    text-decoration: none;
    background-color: #eef2ff;
    
}

    .header-search input[type="search"] {
        width: 100%;
        padding: 8px 10px;
        border-radius: 6px;
        border: 1px solid #cfcfe6;
        outline: none;
        background: #fff;
    }

    .header-search select,
    .header-search button {
        padding: 8px 10px;
        border-radius: 6px;
        border: 1px solid #cfcfe6;
        background: #fff;
    }

    .search-results {
        position: absolute;
        left: 0;
        right: 0;
        top: 58px;
        z-index: 60;
        background: #fff;
        border: 1px solid #e6e6f0;
        box-shadow: 0 10px 30px rgba(20, 20, 40, 0.06);
        border-radius: 8px;
        max-height: 320px;
        overflow: auto;
        padding: 10px;
    }

    .search-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 8px;
        border-radius: 6px;
        margin-bottom: 6px;
        border: 1px solid #f0f0f6;
        background: #fff;
    }

    .search-card .meta {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .search-avatar {
        width: 44px;
        height: 44px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #eef2ff;
        color: #123;
        font-weight: 600;
    }

    .search-card .info {
        display: flex;
        flex-direction: column;
    }

    .search-card .info .title {
        font-weight: 600;
        color: #0b2a4a;
    }

    .search-card .info .sub {
        font-size: 13px;
        color: #666;
    }

    .search-card .btn,
    .search-card a.btn {
        padding: 6px 10px;
        border-radius: 6px;
        border: 0;
        background: #2b5a89;
        color: #fff;
        text-decoration: none;
        display: inline-block;
    }

    .search-empty {
        padding: 14px;
        color: #666;
        text-align: center;
    }
</style>
<header class="header">

    <a class="header-left" href="../public/dashboard.php">
        <img src="assets/img/logo.png" alt="Namibia Logo" class="logo">
        <div class="header-text">
            <h1>MHSS</h1>
            <h4>Dashboard</h4>
        </div>
    </a>


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
                                    <!-- link to the appointement with the doctor selected -->
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

    <a href="../public/logout.php" class="btn-ghost logout">Log Out</a>
</header>