<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// inclui db e csrf (assumo que estão em mesmo diretório que já usa)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function json_err($msg = 'error', $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok($data = [])
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$pdo = pdo(); // from api/db.php
$meId = (int)($_SESSION['user_id'] ?? 0);
$meRole = (int)($_SESSION['role_id'] ?? 0);

// helper role check
function is_clinician_or_admin(int $role): bool
{
    return in_array($role, [1, 4, 5], true); // admin, doctor, nurse
}

// create diagnosis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_err('CSRF inválido', 403);

    if (!is_clinician_or_admin($meRole)) json_err('Somente clínicos podem criar diagnósticos', 403);

    $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
    $payload = trim((string)($_POST['payload'] ?? ''));
    $result = trim((string)($_POST['result'] ?? ''));
    $result_disease_id = ($_POST['result_disease_id'] ?? '') === '' ? null : (int)$_POST['result_disease_id'];
    $confidence = is_numeric($_POST['confidence'] ?? null) ? (float)$_POST['confidence'] : null;
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($patient_id <= 0) json_err('Paciente obrigatório', 400);
    if ($payload === '' && $result === '') json_err('Payload ou resultado obrigatório', 400);

    try {
        // ensure patient exists
        $chk = $pdo->prepare("SELECT id FROM patients WHERE id = ? LIMIT 1");
        $chk->execute([$patient_id]);
        if (!$chk->fetch()) json_err('Paciente não encontrado', 404);

        // uuid v4
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x', str_split($data, 1));

        $ins = $pdo->prepare("INSERT INTO diagnoses (uuid, patient_id, evaluator_user_id, payload, result, result_disease_id, confidence, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $uuid,
            $patient_id,
            $meId ?: null,
            $payload,
            $result,
            $result_disease_id,
            $confidence,
            $notes
        ]);

        json_ok(['message' => 'Diagnóstico criado', 'id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        error_log("api/diagnoses.php create error: " . $e->getMessage());
        json_err('Erro ao criar diagnóstico', 500);
    }
}

// get the recommende drugs
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'recommended_drugs') {
    $did = isset($_GET['disease_id']) ? (int)$_GET['disease_id'] : 0;
    if ($did <= 0) json_ok(['drugs' => []]);

    try {
        $q = $pdo->prepare("SELECT dr.id, dr.code, dr.name, dr.unit, dr.description FROM disease_recommended_drugs ddr JOIN drugs dr ON dr.id = ddr.drug_id WHERE ddr.disease_id = ? ORDER BY dr.name");
        $q->execute([$did]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['drugs' => $rows]);
    } catch (Throwable $e) {
        error_log("api/diagnoses.php recommended_drugs error: " . $e->getMessage());
        json_err('Erro ao carregar medicamentos', 500);
    }
}

// get a list
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : null;
    try {
        $sql = "SELECT d.id, d.uuid, d.patient_id, CONCAT(p.first_name,' ',p.last_name) AS patient_name, d.evaluator_user_id, COALESCE(u.full_name,u.username) AS evaluator, d.payload, d.result, d.result_disease_id, d.confidence, d.notes, d.created_at FROM diagnoses d JOIN patients p ON p.id = d.patient_id LEFT JOIN users u ON u.id = d.evaluator_user_id";
        $params = [];
        if ($patient_id) {
            $sql .= " WHERE d.patient_id = ?";
            $params[] = $patient_id;
        }
        $sql .= " ORDER BY d.created_at DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['rows' => $rows]);
    } catch (Throwable $e) {
        error_log("api/diagnoses.php list error: " . $e->getMessage());
        json_err('Erro ao listar diagnósticos', 500);
    }
}

json_err('Ação desconhecida', 400);
