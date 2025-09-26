<?php
declare(strict_types=1);

class PharmacyActions {
    private PDO $conn;
    private string $table = 'pharmacy_actions';

    public int $id;
    public int $prescription_item_id;
    public int $pharmacist_id;
    public string $action; // 'dispensed', 'reversed', 'adjusted'
    public ?string $notes;

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    public function create(): bool {
        $sql = "INSERT INTO {$this->table} (prescription_item_id, pharmacist_id, action, notes)
                VALUES (:prescription_item_id, :pharmacist_id, :action, :notes)";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':prescription_item_id' => (int)$this->prescription_item_id,
            ':pharmacist_id' => (int)$this->pharmacist_id,
            ':action' => $this->action,
            ':notes' => $this->notes ?? null,
        ]);
    }

    public function read(): PDOStatement {
        $sql = "SELECT pa.*, pi.id AS prescription_item_id, d.name AS drug_name, pr.id AS prescription_id,
                       p.first_name, p.last_name, u.full_name AS pharmacist_name
                FROM {$this->table} pa
                LEFT JOIN prescription_items pi ON pa.prescription_item_id = pi.id
                LEFT JOIN prescriptions pr ON pi.prescription_id = pr.id
                LEFT JOIN drugs d ON pi.drug_id = d.id
                LEFT JOIN patients p ON pr.patient_id = p.id
                LEFT JOIN users u ON pa.pharmacist_id = u.id
                ORDER BY pa.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function readByPrescriptionItem(int $prescription_item_id): PDOStatement {
        $sql = "SELECT pa.*, u.full_name AS pharmacist_name
                FROM {$this->table} pa
                LEFT JOIN users u ON pa.pharmacist_id = u.id
                WHERE pa.prescription_item_id = ?
                ORDER BY pa.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$prescription_item_id]);
        return $stmt;
    }

    public function getPendingPrescriptions(): PDOStatement {
        $sql = "SELECT pr.id AS prescription_id, pi.id AS item_id, pi.drug_id, pi.dosage, pi.quantity,
                       d.name AS drug_name, d.stock, p.first_name, p.last_name, u.full_name AS doctor_name
                FROM prescriptions pr
                JOIN prescription_items pi ON pr.id = pi.prescription_id
                JOIN drugs d ON pi.drug_id = d.id
                JOIN patients p ON pr.patient_id = p.id
                LEFT JOIN users u ON pr.doctor_id = u.id
                WHERE pr.status IN ('pending','partially_dispensed')
                ORDER BY pr.created_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function getSummary(?string $start_date = null, ?string $end_date = null): PDOStatement {
        $sql = "SELECT action, COUNT(*) AS count FROM {$this->table}";
        $params = [];
        if ($start_date && $end_date) {
            $sql .= " WHERE DATE(created_at) BETWEEN ? AND ?";
            $params = [$start_date, $end_date];
        }
        $sql .= " GROUP BY action ORDER BY count DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
