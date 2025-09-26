<?php
declare(strict_types=1);

class DrugAdministration {
    private PDO $conn;
    private string $table = 'drug_administrations';

    public int $id;
    public int $patient_id;
    public int $nurse_id;
    public int $prescription_item_id;
    public string $administered_at;
    public ?string $dose;
    public ?string $notes;

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    public function create(): bool {
        $sql = "INSERT INTO {$this->table}
                (patient_id, nurse_id, prescription_item_id, administered_at, dose, notes)
                VALUES (:patient_id,:nurse_id,:prescription_item_id,:administered_at,:dose,:notes)";
        $stmt = $this->conn->prepare($sql);

        $params = [
            ':patient_id' => (int)$this->patient_id,
            ':nurse_id' => (int)$this->nurse_id,
            ':prescription_item_id' => (int)$this->prescription_item_id,
            ':administered_at' => $this->administered_at,
            ':dose' => $this->dose,
            ':notes' => $this->notes,
        ];

        return $stmt->execute($params);
    }

    public function read(): PDOStatement {
        $sql = "SELECT da.*, p.first_name, p.last_name, u.full_name AS nurse_name,
                       d.name AS drug_name, pi.dosage
                FROM {$this->table} da
                LEFT JOIN patients p ON da.patient_id = p.id
                LEFT JOIN users u ON da.nurse_id = u.id
                LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
                LEFT JOIN drugs d ON pi.drug_id = d.id
                ORDER BY da.administered_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function readByPatient(int $patient_id): PDOStatement {
        $sql = "SELECT da.*, p.first_name, p.last_name, u.full_name AS nurse_name, d.name AS drug_name, pi.dosage
                FROM {$this->table} da
                LEFT JOIN patients p ON da.patient_id = p.id
                LEFT JOIN users u ON da.nurse_id = u.id
                LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
                LEFT JOIN drugs d ON pi.drug_id = d.id
                WHERE da.patient_id = ?
                ORDER BY da.administered_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$patient_id]);
        return $stmt;
    }

    public function readOne(int $id): ?array {
        $sql = "SELECT da.*, p.first_name, p.last_name, u.full_name AS nurse_name, d.name AS drug_name, pi.dosage
                FROM {$this->table} da
                LEFT JOIN patients p ON da.patient_id = p.id
                LEFT JOIN users u ON da.nurse_id = u.id
                LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
                LEFT JOIN drugs d ON pi.drug_id = d.id
                WHERE da.id = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
