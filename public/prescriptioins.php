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
function ensure_csrf_or_die(array $p)
{
    if (!csrf_check($p['csrf'] ?? '')) throw new RuntimeException('CSRF fail');
}

$role_id = (int)($_SESSION['role_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

function is_doctor(int $r): bool
{
    return $r === 4;
}      // tweak if ur ids differ
function is_patient_role(int $r): bool
{
    return $r === 3;
}

$flash = null;

// map current user to patient (for patient view)
$logged_patient_id = null;
if ($user_id > 0) {
    try {
        $q = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
        $q->execute([$user_id]);
        $logged_patient_id = (int)($q->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ensure_csrf_or_die($_POST);
        $action = $_POST['action'] ?? '';

        if ($action === 'create_prescription') {
            if (!is_doctor($role_id)) throw new RuntimeException('Only doctors can create');

            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $appointment_id = trim((string)($_POST['appointment_id'] ?? ''));
            $appointment_id = ($appointment_id === '') ? null : max(0, (int)$appointment_id);
            $notes = trim((string)($_POST['notes'] ?? ''));

            $item_drug = $_POST['drug_id'] ?? [];
            $item_qty  = $_POST['quantity'] ?? [];
            $item_dose = $_POST['dosage'] ?? [];

            if ($patient_id <= 0) throw new RuntimeException('Pick a patient');
            if (!is_array($item_drug) || !is_array($item_qty) || count($item_drug) === 0) {
                throw new RuntimeException('Add at least one item');
            }

            $items = [];
            for ($i = 0; $i < count($item_drug); $i++) {
                $did = (int)($item_drug[$i] ?? 0);
                $qty = (int)($item_qty[$i] ?? 0);
                $dos = trim((string)($item_dose[$i] ?? ''));
                if ($did > 0 && $qty > 0) $items[] = ['drug_id' => $did, 'qty' => $qty, 'dosage' => $dos];
            }
            if (!$items) throw new RuntimeException('Items invalid');

            $doctor_id = $user_id;
            if ($doctor_id <= 0) throw new RuntimeException('Doctor not logged in');

            $pdo->beginTransaction();
            try {
                // insert matches table cols exactly
                $stmt = $pdo->prepare("
          INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, notes, status, created_at)
          VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
                $stmt->execute([
                    $patient_id,
                    $doctor_id,
                    $appointment_id ?: null,
                    ($notes !== '') ? $notes : null
                ]);
                $prescription_id = (int)$pdo->lastInsertId();

                // items (assuming you have prescription_items)
                $ins = $pdo->prepare("INSERT INTO prescription_items (prescription_id, drug_id, quantity, dosage) VALUES (?, ?, ?, ?)");
                foreach ($items as $it) {
                    $ins->execute([$prescription_id, $it['drug_id'], $it['qty'], $it['dosage'] !== '' ? $it['dosage'] : null]);
                }

                $pdo->commit();
                set_flash('Prescription created (#' . $prescription_id . ')');
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    error_log('RX POST: ' . $e->getMessage());
    $flash = 'Error: ' . ($e->getMessage() ?: 'failed');
}

// dropdown lists
try {
    $patients = $pdo->query("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name LIMIT 500")->fetchAll();
    $drugs = $pdo->query("SELECT id, code, name, stock, unit FROM drugs ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $patients = [];
    $drugs = [];
    error_log('RX lists: ' . $e->getMessage());
    $flash = $flash ?? 'Failed loading lists';
}

// recents per role
$recent = [];
$rxItemsByRx = [];

try {
    if (is_doctor($role_id)) {
        $st = $pdo->prepare("
      SELECT pr.id AS prescription_id, pr.patient_id, pr.doctor_id, pr.appointment_id, pr.notes,
             pr.status, pr.created_at,
             p.first_name, p.last_name
      FROM prescriptions pr
      JOIN patients p ON p.id = pr.patient_id
      WHERE pr.doctor_id = ?
      ORDER BY pr.created_at DESC
      LIMIT 50
    ");
        $st->execute([$user_id]);
        $recent = $st->fetchAll();
    } elseif (is_patient_role($role_id) && $logged_patient_id) {
        $st = $pdo->prepare("
      SELECT pr.id AS prescription_id, pr.patient_id, pr.doctor_id, pr.appointment_id, pr.notes,
             pr.status, pr.created_at,
             p.first_name, p.last_name
      FROM prescriptions pr
      JOIN patients p ON p.id = pr.patient_id
      WHERE pr.patient_id = ?
      ORDER BY pr.created_at DESC
      LIMIT 50
    ");
        $st->execute([$logged_patient_id]);
        $recent = $st->fetchAll();
    } else {
        $recent = $pdo->query("
      SELECT pr.id AS prescription_id, pr.patient_id, pr.doctor_id, pr.appointment_id, pr.notes,
             pr.status, pr.created_at,
             p.first_name, p.last_name
      FROM prescriptions pr
      JOIN patients p ON p.id = pr.patient_id
      ORDER BY pr.created_at DESC
      LIMIT 50
    ")->fetchAll();
    }

    if ($recent) {
        $ids = array_map(fn($r) => (int)$r['prescription_id'], $recent);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("
        SELECT pi.id, pi.prescription_id, pi.drug_id, pi.quantity, pi.dosage, d.name AS drug_name
        FROM prescription_items pi
        JOIN drugs d ON d.id = pi.drug_id
        WHERE pi.prescription_id IN ($in)
        ORDER BY pi.id DESC
      ");
            $st->execute($ids);
            foreach ($st->fetchAll() as $row) {
                $rxItemsByRx[(int)$row['prescription_id']][] = $row;
            }
        }
    }
} catch (Throwable $e) {
    error_log('RX recents: ' . $e->getMessage());
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Prescriptions</title>
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

        * {
            box-sizing: border-box;
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

        .card h3 {
            margin: 0 0 8px 0;
        }

        .notice {
            background: #e6ffef;
            color: #064e3b;
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

        form {
            display: block;
        }

        .rx-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 14px;
        }

        select,
        input,
        textarea {
            padding: 8px;
            border: 1px solid #d3dae8;
            border-radius: 6px;
            font-size: 14px;
        }

        textarea {
            min-height: 70px;
        }

        .items {
            margin-top: 6px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .rx-item {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .rx-item select {
            min-width: 240px;
        }

        .rx-item .qty {
            width: 100px;
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

        .rx-card {
            border: 1px solid var(--bd);
            border-radius: 8px;
            padding: 8px;
            background: #fbfdff;
            margin-bottom: 10px;
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

        .muted {
            color: var(--muted);
            font-size: 13px;
        }

        .meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--muted);
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .rx-item {
                flex-direction: column;
                align-items: stretch;
            }

            .rx-item .qty {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../includes/header.php' ?>

    <div class="container">
        <h2 class="page-title">Prescriptions</h2>

        <?php if ($f = get_flash()): ?><div class="notice"><?= h($f) ?></div><?php endif; ?>
        <?php if ($flash && strncmp($flash, 'Error:', 6) === 0): ?><div class="error"><?= h($flash) ?></div><?php endif; ?>

        <div class="grid">
            <div class="card">
                <h3>Create new</h3>
                <?php if (is_doctor($role_id)): ?>
                    <form method="post" id="rxForm" class="rx-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create_prescription">

                        <div class="row">
                            <label>Patient
                                <select name="patient_id" id="patient_id" required>
                                    <option value="">-- choose --</option>
                                    <?php foreach ($patients as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>"><?= h($p['last_name'] . ', ' . $p['first_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Appointment id (opt)
                                <input name="appointment_id" type="number" min="1" step="1" placeholder="e.g. 123">
                            </label>
                        </div>

                        <label>Notes (opt)
                            <textarea name="notes" placeholder="notes about rx"></textarea>
                        </label>

                        <div id="items" class="items">
                            <div class="rx-item">
                                <select name="drug_id[]" required>
                                    <option value="">-- drug --</option>
                                    <?php foreach ($drugs as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?> (<?= h($d['unit']) ?> • stk <?= (int)$d['stock'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="qty" name="quantity[]" type="number" min="1" step="1" placeholder="qty" required>
                                <input name="dosage[]" placeholder="dosage (opt)">
                                <button type="button" class="btn-ghost rm">Remove</button>
                            </div>
                        </div>

                        <div class="row">
                            <button type="button" class="btn-ghost" id="addItem">+ Add item</button>
                            <button type="submit" class="btn">Save</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="muted">Only doctors can create new prescriptions.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Recent</h3>
                <?php if (is_patient_role($role_id) && !$logged_patient_id): ?>
                    <div class="muted">cant find patient profile for this user.</div>
                <?php endif; ?>

                <?php if (!$recent): ?>
                    <div class="muted">No prescriptions.</div>
                <?php else: ?>
                    <?php foreach ($recent as $r): ?>
                        <div class="rx-card">
                            <div><strong>#<?= (int)$r['prescription_id'] ?></strong> • <?= h($r['last_name'] . ', ' . $r['first_name']) ?> • <?= h($r['status']) ?></div>
                            <div class="meta">
                                <span>patient_id: <?= (int)$r['patient_id'] ?></span>
                                <span>doctor_id: <?= (int)$r['doctor_id'] ?></span>
                                <span>appointment_id: <?= $r['appointment_id'] !== null ? (int)$r['appointment_id'] : 'NULL' ?></span>
                                <span>created_at: <?= h($r['created_at']) ?></span>
                            </div>
                            <?php if ($r['notes'] !== null && $r['notes'] !== ''): ?>
                                <div class="muted">notes: <?= h($r['notes']) ?></div>
                            <?php endif; ?>
                            <?php $its = $rxItemsByRx[(int)$r['prescription_id']] ?? []; ?>
                            <?php if ($its): ?>
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
                            <?php else: ?>
                                <div class="muted">no items? hmm</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // add/remove rows. simple, dont overthink
        const addBtn = document.getElementById('addItem');
        const itemsWrap = document.getElementById('items');

        addBtn?.addEventListener('click', () => {
            const first = itemsWrap.querySelector('.rx-item');
            const clone = first.cloneNode(true);
            clone.querySelector('select[name="drug_id[]"]').value = '';
            clone.querySelector('input[name="quantity[]"]').value = '';
            clone.querySelector('input[name="dosage[]"]').value = '';
            itemsWrap.appendChild(clone);
        });

        itemsWrap?.addEventListener('click', (e) => {
            if (e.target && e.target.classList.contains('rm')) {
                const rows = itemsWrap.querySelectorAll('.rx-item');
                if (rows.length > 1) e.target.closest('.rx-item').remove();
            }
        });
    </script>
</body>

</html>