<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/util.php';

$pdo = pdo();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'diseases') {
        $rows = $pdo->query("SELECT id,name FROM diseases ORDER BY name")->fetchAll();
        json_ok(['rows' => $rows]);
    }
    if ($action === 'recent') {
        $sql = "SELECT pr.id, pr.created_at, pr.status, CONCAT(p.first_name,' ',p.last_name) AS patient
                FROM prescriptions pr
                JOIN patients p ON p.id=pr.patient_id
                ORDER BY pr.id DESC LIMIT 20";
        $rows = $pdo->query($sql)->fetchAll();
        json_ok(['rows' => $rows]);
    }
    json_err('Unknown action', 400);
}

require_post_with_csrf();

if ($action === 'create_prescription') {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $disease_id = (int)($_POST['disease_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($patient_id <= 0) redirect_with_msg('/doctor/public/treatment.php', '', 'Select patient.');
    if ($disease_id <= 0) {
        // Try to infer last diagnosis for patient
        $last = $pdo->prepare("SELECT result_disease_id FROM diagnoses WHERE patient_id=? AND result_disease_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $last->execute([$patient_id]);
        $disease_id = (int)($last->fetchColumn() ?: 0);
    }
    if ($disease_id <= 0) redirect_with_msg('/doctor/public/treatment.php', '', 'No disease selected or inferred.');

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO prescriptions(patient_id, doctor_id, appointment_id, notes, status) VALUES(?, ?, NULL, ?, 'pending')");
        $doctor_id = null; // keep null/not used here
        $ins->execute([$patient_id, $doctor_id, ($notes ?: null)]);
        $rxId = (int)$pdo->lastInsertId();

        // Recommend drugs for disease
        $rec = $pdo->prepare("SELECT drd.drug_id, d.name FROM disease_recommended_drugs drd JOIN drugs d ON d.id=drd.drug_id WHERE drd.disease_id=?");
        $rec->execute([$disease_id]);
        $recs = $rec->fetchAll();

        foreach ($recs as $item) {
            $dosage = 'As directed'; // simple default
            $qty = 10;               // simple default
            $insI = $pdo->prepare("INSERT INTO prescription_items(prescription_id, drug_id, dosage, quantity) VALUES(?,?,?,?)");
            $insI->execute([$rxId, (int)$item['drug_id'], $dosage, $qty]);
        }
        $pdo->commit();
        redirect_with_msg('/doctor/public/treatment.php', 'Prescription created with recommended items.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        redirect_with_msg('/doctor/public/treatment.php', '', 'Failed to create prescription.');
    }
}

json_err('Unknown action', 400);
