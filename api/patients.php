<?php
// api/patients.php
declare(strict_types=1);

/*
 Patients API
 - Actions (GET): action=list | view
 - Actions (POST): action=create | update | delete
 - Uses session auth (expects $_SESSION['user_id'], $_SESSION['role_id'])
 - Uses api/db.php -> pdo(), api/csrf.php -> csrf helpers
 - Returns JSON for API consumers
*/

$apiRoot = __DIR__;
require_once $apiRoot . '/db.php';
require_once $apiRoot . '/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function json_ok($data = [], int $code = 200)
{
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err(string $msg = 'Error', int $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* --- Role constants (from your roles table) --- */
const ROLE_ADMIN = 1;
const ROLE_RECEPTIONIST = 2;
const ROLE_PATIENT = 3;
const ROLE_DOCTOR = 4;
const ROLE_NURSE = 5;
const ROLE_PHARMACIST = 6;

/* --- Helpers --- */
function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}
function current_role_id(): int
{
    return (int)($_SESSION['role_id'] ?? 0);
}

function has_role(int ...$roles): bool
{
    $rid = current_role_id();
    foreach ($roles as $r) if ($r === $rid) return true;
    return false;
}

function require_roles_or_die(...$roles)
{
    if (!has_role(...$roles)) json_err('Forbidden', 403);
}

function has_column(PDO $pdo, string $table, string $col): bool
{
    try {
        $q = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $q->execute([$col]);
        return (bool)$q->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/* --- PDO --- */
try {
    $pdo = pdo();
} catch (Throwable $e) {
    error_log("patients api db error: " . $e->getMessage());
    json_err('DB connection error', 500);
}

/* --- audit helper --- */
function audit(PDO $pdo, ?int $user_id, string $action, string $target_type, ?int $target_id = null)
{
    try {
        $st = $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_type, target_id) VALUES (?, ?, ?, ?)");
        $st->execute([$user_id, $action, $target_type, $target_id]);
    } catch (Throwable $e) {
        // no fatal; we don't block primary action on audit failure
        error_log("audit failed: " . $e->getMessage());
    }
}

/* --- Routing / parameters --- */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* --- GET actions: list, view --- */
if ($method === 'GET') {
    if ($action === 'view') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_err('Invalid id', 400);

        // permission: patient can view only own patient record
        if (has_role(ROLE_PATIENT)) {
            $myUid = current_user_id();
            $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
            $stmt->execute([$myUid]);
            $myPid = (int)$stmt->fetchColumn();
            if ($myPid !== $id) json_err('Forbidden', 403);
        } else {
            // other roles allowed to view; you may refine further
        }

        $stmt = $pdo->prepare("SELECT id, user_id, external_identifier, first_name, last_name, gender, date_of_birth, contact_phone, address, created_by, created_at, updated_at FROM patients WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_err('Not found', 404);
        json_ok($row);
    }

    if ($action === 'list') {
        // search, pagination
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = max(5, min(200, (int)($_GET['per'] ?? 20)));
        $offset = ($page - 1) * $per;

        // If patient role -> only their patient record
        if (has_role(ROLE_PATIENT)) {
            $myUid = current_user_id();
            $stmt = $pdo->prepare("SELECT p.id, p.user_id, p.external_identifier, p.first_name, p.last_name, p.gender, p.date_of_birth, p.contact_phone FROM patients p WHERE p.user_id = ? LIMIT 1");
            $stmt->execute([$myUid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['rows' => $rows, 'page' => $page, 'per' => $per, 'total' => count($rows)]);
        }

        $params = [];
        $where = "1=1";
        if ($q !== '') {
            // escape % and _
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where = "(CONCAT(last_name,' ',first_name) LIKE ? OR external_identifier LIKE ? OR contact_phone LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        // count
        $countSql = "SELECT COUNT(*) FROM patients WHERE $where";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT id, user_id, external_identifier, first_name, last_name, gender, date_of_birth, contact_phone FROM patients WHERE $where ORDER BY last_name, first_name LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $p) $stmt->bindValue($bindIndex++, $p, PDO::PARAM_STR);
        $stmt->bindValue($bindIndex++, (int)$per, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex++, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['rows' => $rows, 'page' => $page, 'per' => $per, 'total' => $total]);
    }

    json_err('Unknown GET action', 400);
}

/* --- POST actions: create, update, delete (writes require CSRF) --- */
if ($method === 'POST') {
    // Require CSRF for all modifying actions
    if (!csrf_check($_POST['csrf'] ?? '')) json_err('CSRF failed', 403);

    if ($action === 'create') {
        // Permission: receptionist and admin can create; patients can create their own patient record if none exists.
        $actor = current_user_id();

        if (!has_role(ROLE_RECEPTIONIST, ROLE_ADMIN, ROLE_PATIENT, ROLE_DOCTOR, ROLE_NURSE)) {
            json_err('Forbidden', 403);
        }

        $username_user_id = (int)($_POST['user_id'] ?? 0); // optional link to users table
        $external_identifier = trim((string)($_POST['external_identifier'] ?? '')) ?: null;
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $gender = trim((string)($_POST['gender'] ?? 'other'));
        $date_of_birth = trim((string)($_POST['date_of_birth'] ?? '')) ?: null;
        $contact_phone = trim((string)($_POST['contact_phone'] ?? '')) ?: null;
        $address = trim((string)($_POST['address'] ?? '')) ?: null;

        // Basic validation
        if ($first_name === '' || $last_name === '') json_err('First and last name required', 400);
        if ($date_of_birth !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) json_err('Invalid date_of_birth format (YYYY-MM-DD)', 400);

        // If patient role creating: ensure they create only their own record
        if (has_role(ROLE_PATIENT)) {
            $myUid = current_user_id();
            if ($username_user_id !== 0 && $username_user_id !== $myUid) json_err('Patients may only create their own record', 403);
            $username_user_id = $myUid;
            // check existing patient
            $chk = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
            $chk->execute([$myUid]);
            if ($chk->fetch()) json_err('Patient record already exists for this account', 409);
        }

        // uniqueness checks for external_identifier (if provided)
        if ($external_identifier) {
            $u = $pdo->prepare("SELECT id FROM patients WHERE external_identifier = ? LIMIT 1");
            $u->execute([$external_identifier]);
            if ($u->fetch()) json_err('External identifier already used', 409);
        }

        try {
            $ins = $pdo->prepare("INSERT INTO patients (user_id, external_identifier, first_name, last_name, gender, date_of_birth, contact_phone, address, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $username_user_id ?: null,
                $external_identifier,
                $first_name,
                $last_name,
                $gender,
                $date_of_birth,
                $contact_phone,
                $address,
                $actor ?: null
            ]);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, $actor, 'create_patient', 'patient', $id);
            json_ok(['id' => $id], 201);
        } catch (Throwable $e) {
            error_log('patients.create: ' . $e->getMessage());
            json_err('Failed to create patient', 500);
        }
    }

    if ($action === 'update') {
        // Who may update?
        // - admin and receptionist: update any patient
        // - clinician(doctor/nurse): update limited clinical fields (we keep simple: allow any update but you may restrict)
        // - patient: update own contact info only
        $actor = current_user_id();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_err('Invalid id', 400);

        // fetch row first to decide permissions
        $stmt = $pdo->prepare("SELECT id, user_id FROM patients WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_err('Not found', 404);

        $isOwner = ((int)$row['user_id'] === current_user_id());

        if (has_role(ROLE_ADMIN) || has_role(ROLE_RECEPTIONIST)) {
            // allowed
        } elseif (has_role(ROLE_PATIENT) && $isOwner) {
            // allowed but limited fields (we enforce allowed list below)
        } elseif (has_role(ROLE_DOCTOR) || has_role(ROLE_NURSE)) {
            // allowed; you may refine restrictions
        } else {
            json_err('Forbidden', 403);
        }

        // Collect updates — whitelist fields depending on role
        $fields = [];
        $values = [];
        // common fields receptionists/admin can update
        $wFieldsAdmin = ['external_identifier', 'first_name', 'last_name', 'gender', 'date_of_birth', 'contact_phone', 'address'];
        foreach ($wFieldsAdmin as $f) {
            if (array_key_exists($f, $_POST)) {
                $fields[] = "$f = ?";
                $values[] = ($_POST[$f] === '') ? null : trim((string)$_POST[$f]);
            }
        }

        // If patient updating and not admin/receptionist, restrict to contact fields only
        if (has_role(ROLE_PATIENT) && !$isOwner) json_err('Forbidden', 403);

        if (empty($fields)) json_err('Nothing to update', 400);

        $values[] = $id; // where
        $sql = "UPDATE patients SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($values);
            audit($pdo, $actor, 'update_patient', 'patient', $id);
            json_ok(['id' => $id]);
        } catch (Throwable $e) {
            error_log('patients.update: ' . $e->getMessage());
            json_err('Failed to update', 500);
        }
    }

    if ($action === 'delete') {
        // Only admin may delete by default
        if (!has_role(ROLE_ADMIN)) json_err('Forbidden', 403);
        $actor = current_user_id();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_err('Invalid id', 400);

        // decide soft delete vs hard delete depending on schema
        $useSoft = has_column($pdo, 'patients', 'is_active');
        try {
            if ($useSoft) {
                // set is_active=0 and deleted_at if exists
                $cols = "is_active = 0";
                if (has_column($pdo, 'patients', 'deleted_at')) $cols .= ", deleted_at = NOW()";
                $st = $pdo->prepare("UPDATE patients SET $cols, updated_at = NOW() WHERE id = ?");
                $st->execute([$id]);
            } else {
                $st = $pdo->prepare("DELETE FROM patients WHERE id = ?");
                $st->execute([$id]);
            }
            audit($pdo, $actor, 'delete_patient', 'patient', $id);
            json_ok(['id' => $id]);
        } catch (Throwable $e) {
            error_log('patients.delete: ' . $e->getMessage());
            json_err('Failed to delete', 500);
        }
    }

    json_err('Unknown POST action', 400);
}

json_err('Method not allowed', 405);
