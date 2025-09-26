<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/util.php';

$pdo = pdo();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'ready_items') {
        // Items are considered ready if prescription is 'dispensed'
        $sql = "SELECT pi.id, pi.prescription_id, pi.dosage, pi.quantity, d.name AS drug_name,
                       CONCAT(p.first_name,' ',p.last_name) AS patient
                FROM prescription_items pi
                JOIN prescriptions pr ON pr.id = pi.prescription_id
                JOIN patients p ON p.id = pr.patient_id
                JOIN drugs d ON d.id = pi.drug_id
                WHERE pr.status = 'dispensed'
                ORDER BY pr.id DESC";
        $rows = $pdo->query($sql)->fetchAll();
        json_ok(['rows' => $rows]);
    }
    json_err('Unknown action', 400);
}

require_post_with_csrf();

if ($action === 'administer') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $dose = trim($_POST['dose'] ?? '');
    if ($item_id <= 0) redirect_with_msg('/doctor/public/drug_administration.php', '', 'Invalid item.');
    try {
        // Find patient_id for the item
        $row = $pdo->prepare("SELECT pr.patient_id FROM prescription_items pi JOIN prescriptions pr ON pr.id=pi.prescription_id WHERE pi.id=?");
        $row->execute([$item_id]);
        $patient_id = (int)($row->fetchColumn() ?: 0);
        if ($patient_id <= 0) throw new RuntimeException('Patient not found for item');

        $ins = $pdo->prepare("INSERT INTO drug_administrations(patient_id, nurse_id, prescription_item_id, dose, notes) VALUES(?, NULL, ?, ?, NULL)");
        $ins->execute([$patient_id, $item_id, ($dose ?: null)]);
        redirect_with_msg('/doctor/public/drug_administration.php', 'Administration recorded.');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        redirect_with_msg('/doctor/public/drug_administration.php', '', 'Failed to record.');
    }
}

json_err('Unknown action', 400);
