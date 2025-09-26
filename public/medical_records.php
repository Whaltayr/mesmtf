<?php
// public/medical_records.php
declare(strict_types=1);

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/csrf.php';

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

// roles allowed to manage patients (create/edit/delete/search)
$manageRoles = [1, 2, 4, 5]; // admin, receptionist, doctor, nurse
$isManager = in_array($meRole, $manageRoles, true);
$isPatient = ($meRole === 3);

$pdo = pdo();

// ensure patient mapping for patient role
if ($isPatient && empty($_SESSION['patient_id'])) {
    $st = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
    $st->execute([$meId]);
    $_SESSION['patient_id'] = (int)$st->fetchColumn();
}
$myPatientId = (int)($_SESSION['patient_id'] ?? 0);

// helpers
function validate_date(string $d): bool
{
    return $d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create' && $isManager) {
            $external = trim((string)($_POST['external_identifier'] ?? '')) ?: null;
            $first = trim((string)($_POST['first_name'] ?? ''));
            $last = trim((string)($_POST['last_name'] ?? ''));
            $gender = in_array($_POST['gender'] ?? 'other', ['male', 'female', 'other'], true) ? $_POST['gender'] : 'other';
            $dob = trim((string)($_POST['date_of_birth'] ?? '')) ?: null;
            $phone = trim((string)($_POST['contact_phone'] ?? '')) ?: null;
            $address = trim((string)($_POST['address'] ?? '')) ?: null;
            $created_by = $meId;

            if ($first === '' || $last === '') {
                $error = 'First and last name are required.';
            } elseif ($dob !== null && !validate_date($dob)) {
                $error = 'Date of birth must be YYYY-MM-DD.';
            } else {
                try {
                    $ins = $pdo->prepare("INSERT INTO patients (user_id, external_identifier, first_name, last_name, gender, date_of_birth, contact_phone, address, created_by) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$external, $first, $last, $gender, $dob, $phone, $address, $created_by]);
                    $_SESSION['flash'] = 'Patient created.';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } catch (Throwable $e) {
                    error_log('Patient create error: ' . $e->getMessage());
                    $error = 'Failed to create patient. Check logs.';
                }
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid patient id.';
            } elseif (!($isManager || ($isPatient && $id === $myPatientId))) {
                $error = 'Permission denied.';
            } else {
                $external = trim((string)($_POST['external_identifier'] ?? '')) ?: null;
                $first = trim((string)($_POST['first_name'] ?? ''));
                $last = trim((string)($_POST['last_name'] ?? ''));
                $gender = in_array($_POST['gender'] ?? 'other', ['male', 'female', 'other'], true) ? $_POST['gender'] : 'other';
                $dob = trim((string)($_POST['date_of_birth'] ?? '')) ?: null;
                $phone = trim((string)($_POST['contact_phone'] ?? '')) ?: null;
                $address = trim((string)($_POST['address'] ?? '')) ?: null;

                if ($first === '' || $last === '') {
                    $error = 'First and last name are required.';
                } elseif ($dob !== null && !validate_date($dob)) {
                    $error = 'Date of birth must be YYYY-MM-DD.';
                } else {
                    try {
                        $upd = $pdo->prepare("UPDATE patients SET external_identifier = ?, first_name = ?, last_name = ?, gender = ?, date_of_birth = ?, contact_phone = ?, address = ?, updated_at = current_timestamp() WHERE id = ?");
                        $upd->execute([$external, $first, $last, $gender, $dob, $phone, $address, $id]);
                        $_SESSION['flash'] = 'Patient updated.';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    } catch (Throwable $e) {
                        error_log('Patient update error: ' . $e->getMessage());
                        $error = 'Failed to update patient. Check logs.';
                    }
                }
            }
        } elseif ($action === 'delete' && $isManager) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'Invalid id.';
            } else {
                try {
                    $del = $pdo->prepare("DELETE FROM patients WHERE id = ?");
                    $del->execute([$id]);
                    $_SESSION['flash'] = 'Patient deleted.';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } catch (Throwable $e) {
                    error_log('Patient delete error: ' . $e->getMessage());
                    $error = 'Failed to delete patient. Check logs.';
                }
            }
        } else {
            $error = 'Unknown action or permission denied.';
        }
    }
}


$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 12;
$offset = ($page - 1) * $per;

$params = [];
$where = "1=1";
if ($q !== '') {
    $where = "(first_name LIKE ? OR last_name LIKE ? OR external_identifier LIKE ? OR contact_phone LIKE ?)";
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $params = [$like, $like, $like, $like];
}
if ($isPatient) {
    $where = ($where === "1=1") ? "id = ?" : $where . " AND id = ?";
    $params[] = $myPatientId;
}
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$sql = "SELECT id, user_id, external_identifier, first_name, last_name, gender, date_of_birth, contact_phone, address, created_by, created_at
        FROM patients
        WHERE $where
        ORDER BY last_name, first_name
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$bindIndex = 1;
foreach ($params as $p) {
    $stmt->bindValue($bindIndex++, $p, PDO::PARAM_STR);
}
$stmt->bindValue($bindIndex++, (int)$per, PDO::PARAM_INT);
$stmt->bindValue($bindIndex++, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editRecord = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    if ($edit_id > 0) {
        if ($isManager || ($isPatient && $edit_id === $myPatientId)) {
            $s = $pdo->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
            $s->execute([$edit_id]);
            $editRecord = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $error = 'Permission denied for editing that patient.';
        }
    }
}

$pages = (int)ceil($total / $per);
$csrf = csrf_token();
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Medical Records</title>
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

        .grid {
            display: grid;
            grid-template-columns: 420px 1fr;
            gap: 16px
        }

        @media(max-width:900px) {
            .grid {
                grid-template-columns: 1fr
            }
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600
        }

        input[type="text"],
        input[type="date"],
        label > select,
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-top: 6px
        }
        a{
            text-decoration: none;
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

        .patient-row {
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #eef2f8;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .small {
            font-size: 13px;
            color: #555
        }

        .pager {
            display: flex;
            gap: 6px;
            margin-top: 8px
        }

        .danger {
            color: #7f1d1d
        }
    </style>
</head>

<body>
<?php include __DIR__ . '/../includes/header.php' ?>

    <div class="container">
        <header style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div>
                <h1>Medical Records</h1>
                <div class="muted">User: <?= h($meFull) ?> • Role: <?= h((string)$meRole) ?></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <a class="btn-ghost" href="appointments.php">Appointments</a>
                <form method="post" action="logout.php" style="display:inline-block;margin:0"><?= csrf_input() ?><button class="btn-ghost" type="submit">Logout</button></form>
            </div>
        </header>

        <?php if ($flash): ?><div class="card" style="border-left:4px solid:#16a34a;color:#064e3b"><?= h($flash) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="card" style="border-left:4px solid:#dc2626;color:#7f1d1d"><?= h($error) ?></div><?php endif; ?>

        <div class="card" style="margin-bottom:16px">
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <input name="q" type="text" placeholder="Search by name, external id or phone" value="<?= h($q) ?>" style="flex:1;padding:8px;border:1px solid #e2e8f0;border-radius:6px">
                <button class="btn-ghost" type="submit">Search</button>
                <?php if ($isManager): ?><a class="btn" href="#createForm" onclick="document.getElementById('createForm').scrollIntoView({behavior:'smooth'})">Create</a><?php endif; ?>
            </form>
        </div>

        <div class="grid">
            <aside class="card" id="createForm">
                <?php if ($editRecord): ?>
                    <h2>Edit patient</h2>
                    <form method="post" action="<?= h($_SERVER['PHP_SELF']) ?>">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= (int)$editRecord['id'] ?>">
                        <label>External ID
                            <input type="text" name="external_identifier" value="<?= h($editRecord['external_identifier'] ?? '') ?>">
                        </label>
                        <label>First name
                            <input type="text" name="first_name" required value="<?= h($editRecord['first_name'] ?? '') ?>">
                        </label>
                        <label>Last name
                            <input type="text" name="last_name" required value="<?= h($editRecord['last_name'] ?? '') ?>">
                        </label>
                        <label>Gender
                            <select name="gender">
                                <option value="male" <?= (isset($editRecord['gender']) && $editRecord['gender'] === 'male') ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= (isset($editRecord['gender']) && $editRecord['gender'] === 'female') ? 'selected' : '' ?>>Female</option>
                                <option value="other" <?= (isset($editRecord['gender']) && $editRecord['gender'] === 'other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </label>
                        <label>DOB
                            <input type="date" name="date_of_birth" value="<?= h($editRecord['date_of_birth'] ?? '') ?>">
                        </label>
                        <label>Phone
                            <input type="text" name="contact_phone" value="<?= h($editRecord['contact_phone'] ?? '') ?>">
                        </label>
                        <label>Address
                            <textarea name="address" rows="3"><?= h($editRecord['address'] ?? '') ?></textarea>
                        </label>
                        <div style="display:flex;gap:8px">
                            <button class="btn" type="submit">Save</button>
                            <a href="<?= h($_SERVER['PHP_SELF']) ?>" class="btn-ghost">Cancel</a>
                        </div>
                    </form>

                <?php else: ?>
                    <?php if ($isManager): ?>
                        <h2>Create patient</h2>
                        <form method="post" action="<?= h($_SERVER['PHP_SELF']) ?>">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="create">
                            <label>External ID
                                <input type="text" name="external_identifier">
                            </label>
                            <label>First name
                                <input type="text" name="first_name" required>
                            </label>
                            <label>Last name
                                <input type="text" name="last_name" required>
                            </label>
                            <label>Gender
                                <select name="gender">
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other" selected>Other</option>
                                </select>
                            </label>
                            <label>DOB
                                <input type="date" name="date_of_birth">
                            </label>
                            <label>Phone
                                <input type="text" name="contact_phone">
                            </label>
                            <label>Address
                                <textarea name="address" rows="3"></textarea>
                            </label>
                            <div style="display:flex;gap:8px">
                                <button class="btn" type="submit">Create</button>
                                <button class="btn-ghost" type="reset">Reset</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="muted">You do not have permission to create patients. Contact reception.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </aside>

            <main>
                <section class="card">
                    <h2 style="margin-top:0">Patients (<?= $total ?>)</h2>
                    <?php if (empty($patients)): ?>
                        <div class="muted">No patients found.</div>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                            <div class="patient-row">
                                <div>
                                    <div style="font-weight:700"><?= h($p['last_name'] . ', ' . $p['first_name']) ?> <?php if ($p['external_identifier']): ?><span class="small">(#<?= h($p['external_identifier']) ?>)</span><?php endif; ?></div>
                                    <div class="small">DOB: <?= h($p['date_of_birth'] ?? '-') ?> • Phone: <?= h($p['contact_phone'] ?? '-') ?></div>
                                </div>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <a class="btn-ghost" href="patient_view.php?id=<?= (int)$p['id'] ?>">View</a>
                                    <?php if ($isManager || ($isPatient && $p['id'] == $myPatientId)): ?>
                                        <a class="btn" href="<?= h($_SERVER['PHP_SELF']) . '?edit_id=' . (int)$p['id'] ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($isManager): ?>
                                        <form method="post" action="<?= h($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Delete this patient? This will remove related patient records.')">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button class="btn-ghost danger" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($pages > 1): ?>
                        <div class="pager">
                            <?php for ($i = 1; $i <= $pages; $i++): ?>
                                <a class="<?= $i == $page ? 'btn' : 'btn-ghost' ?>" href="?q=<?= urlencode($q) ?>&page=<?= $i ?>"><?= $i ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                </section>
            </main>
        </div>
    </div>
</body>

</html>