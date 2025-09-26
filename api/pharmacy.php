<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/util.php';

require_once __DIR__ . '/../models/DrugAdministration.php';
require_once __DIR__ . '/../models/PharmacyActions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = pdo(); // must exist in api/db.php
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function require_role(array $allowed) {
    $r = (int)($_SESSION['role_id'] ?? 0);
    if (!in_array($r, $allowed, true)) json_err('Forbidden', 403);
}

// GET endpoints
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'search_drugs') {
        $q = trim($_GET['q'] ?? '');
        $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
        $stmt = $pdo->prepare("SELECT id, code, name, stock, unit FROM drugs WHERE name LIKE ? OR code LIKE ? ORDER BY name LIMIT 50");
        $stmt->execute([$like, $like]);
        json_ok(['rows' => $stmt->fetchAll()]);
    }

    if ($action === 'pending_prescriptions') {
        require_role([6]); // pharmacist-only
        $pa = new PharmacyActions($pdo);
        $stmt = $pa->getPendingPrescriptions();
        json_ok(['rows' => $stmt->fetchAll()]);
    }

    json_err('Unknown action', 400);
}

// POST endpoints require CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) json_err('CSRF failed', 403);

    // dispense full/partial prescription
    if ($action === 'dispense') {
        require_role([6]); // pharmacist
        $prescription_id = (int)($_POST['prescription_id'] ?? 0);
        if ($prescription_id <= 0) json_err('Missing prescription id', 400);

        $items = $_POST['items'] ?? null; // optional array item_id=>qty

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT pi.id, pi.drug_id, pi.quantity, d.stock
                                   FROM prescription_items pi
                                   JOIN drugs d ON d.id = pi.drug_id
                                   WHERE pi.prescription_id = ?
                                   FOR UPDATE");
            $stmt->execute([$prescription_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) throw new RuntimeException('No items found');

            $toDispense = [];
            foreach ($rows as $r) {
                $itemId = (int)$r['id'];
                $reqQty = (int)$r['quantity'];
                if (is_array($items) && array_key_exists($itemId, $items)) {
                    $dispQty = max(0, min($reqQty, (int)$items[$itemId]));
                } else {
                    $dispQty = $reqQty;
                }
                $toDispense[$itemId] = [
                    'drug_id' => (int)$r['drug_id'],
                    'dispense_qty' => $dispQty,
                    'stock' => (int)$r['stock']
                ];
            }

            foreach ($toDispense as $info) {
                if ($info['dispense_qty'] > 0 && $info['stock'] < $info['dispense_qty']) {
                    throw new RuntimeException("Insufficient stock for drug id {$info['drug_id']}");
                }
            }

            $updDrug = $pdo->prepare("UPDATE drugs SET stock = stock - ? WHERE id = ?");
            $insAction = $pdo->prepare("INSERT INTO pharmacy_actions (prescription_item_id, pharmacist_id, action, notes) VALUES (?, ?, 'dispensed', ?)");
            foreach ($toDispense as $itemId => $info) {
                if ($info['dispense_qty'] <= 0) continue;
                $updDrug->execute([$info['dispense_qty'], $info['drug_id']]);
                $insAction->execute([$itemId, (int)$_SESSION['user_id'], "Dispensed {$info['dispense_qty']}"]);
            }

            $pdo->prepare("UPDATE prescriptions SET status = 'dispensed' WHERE id = ?")->execute([$prescription_id]);

            $pdo->commit();
            json_ok(['ok' => true, 'message' => 'Dispensed']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Dispense error: ' . $e->getMessage());
            json_err('Failed to dispense: ' . $e->getMessage(), 500);
        }
    }

    // reverse dispense
    if ($action === 'reverse') {
        require_role([6]); // pharmacist
        $prescription_item_id = (int)($_POST['prescription_item_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);
        if ($prescription_item_id <= 0 || $qty <= 0) json_err('Missing params', 400);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT drug_id, prescription_id FROM prescription_items WHERE id = ? LIMIT 1");
            $stmt->execute([$prescription_item_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Item not found');

            $drug_id = (int)$row['drug_id'];
            $pdo->prepare("UPDATE drugs SET stock = stock + ? WHERE id = ?")->execute([$qty, $drug_id]);

            $ins = $pdo->prepare("INSERT INTO pharmacy_actions (prescription_item_id, pharmacist_id, action, notes) VALUES (?, ?, 'reversed', ?)");
            $ins->execute([$prescription_item_id, (int)$_SESSION['user_id'], "Reversed {$qty}"]);

            $pdo->prepare("UPDATE prescriptions SET status = 'pending' WHERE id = ?")->execute([(int)$row['prescription_id']]);

            $pdo->commit();
            json_ok(['ok' => true, 'message' => 'Reverse done']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Reverse error: ' . $e->getMessage());
            json_err('Reverse failed', 500);
        }
    }

    // record administration
    if ($action === 'record_administration') {
        require_role([5,4,1]); // nurse, doctor, admin
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $pres_item = (int)($_POST['prescription_item_id'] ?? 0);
        $dose = trim((string)($_POST['dose'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $admin_at = trim((string)($_POST['administered_at'] ?? date('Y-m-d H:i:s')));

        if ($patient_id <= 0 || $pres_item <= 0) json_err('Missing params', 400);

        $da = new DrugAdministration($pdo);
        $da->patient_id = $patient_id;
        $da->nurse_id = (int)$_SESSION['user_id'];
        $da->prescription_item_id = $pres_item;
        $da->administered_at = $admin_at;
        $da->dose = $dose ?: null;
        $da->notes = $notes ?: null;

        if ($da->create()) {
            json_ok(['ok' => true, 'message' => 'Administration recorded.']);
        } else {
            json_err('Failed to record administration', 500);
        }
    }

    json_err('Unknown action', 400);
}
