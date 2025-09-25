<?php
class DrugAdministration{
    private $conn;
    private $table_name = "drug_administrations";

    public $id;
    public $patient_id;
    public $nurse_id;
    public $prescription_item_id;
    public $administered_at;
    public $dose;
    public $notes;

    public function __construct($db){
        $this->conn = $db;
    }

    public function create(){
        $query = "INSERT INTO" . $this->table_name . "SET patient_id=:patient_id, nurse_id=:nurse_id, prescription_item_id=:prescription_item_id, administered_at=:administered_at, dose=:dose, notes=:notes";

        $stmt= $this->conn->prepare($query);

        $this->patient_id = htmlspecialchars(strip_tags($this->patient_id));
        $this->nurse_id = htmlspecialchars(strip_tags($this->nurse_id));
        $this->prescription_item_id = htmlspecialchars(strip_tags($this->prescription_item_id));
        $this->administered_at = htmlspecialchars(strip_tags($this->administered_at));
        $this->dose = htmlspecialchars(strip_tags($this->dose));
        $this->notes = htmlspecialchars(strip_tags($this->notes));

        $stmt->bindParam(":patient_id", $this->patient_id);
        $stmt->bindParam(":nurse_id", $this->nurse_id);
        $stmt->bindParam(":prescription_item_id", $this->prescription_item_id);
        $stmt->bindParam(":administered_at", $this->administered_at);
        $stmt->bindParam(":dose", $this->dose);
        $stmt->bindParam(":notes", $this->notes);

        if($stmt->execute()){
            return true;
        }
        return false;
    }

    public function read() {
        $query = "SELECT da.*, p.first_name, p.last_name, u.full_name as nurse_name, d.name as drug_name, pi.dosage FROM" . $this->table_name . "da LEFT JOIN patientes p ON da.patient_id = p.id
        LEFT JOIN users u ON da.nurse_id = u.id
        LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
        LEFT JOIN drugs d ON pi.drug_id = d.id
        ORDER BY da. administered_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function readByPatient($patient_id){
        $query = "SELECT da.*, p.first_name, p.last_name, u.full_name as nurse_name, d.name as drug_name, pi.dosage FROM" . $this->table_name . " da LEFT JOIN patients p ON da.patient_id = p.id 
        LEFT JOIN users u ON da.nurse_id = u.id
        LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
        LEFT JOIN drugs d ON pi.drug_id = d.id
        WHERE da.patient_id = ?
        ORDER BY da.administered_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $patient_id);
        $stmt->execute();
        return $stmt;
    }

    public function readOne(){
        $query = "SELECT da.*, p.first_name, p.last_name, u.full_name as nurse_name, d.name as drug_name, pi.dosage FROM" . $this->table_name . " da LEFT JOIN patients p ON da.patient_id = p.id 
        LEFT JOIN users u ON da.nurse_id = u.id
        LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
        LEFT JOIN drugs d ON pi.drug_id = d.id
        WHERE da.id = ? LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row) {
            $this->patient_id = $row['patient_id'];
            $this->nurse_id = $row['nurse_id'];
            $this->prescription_item_id = $row['prescription_item_id'];
            $this->administered_at = $row['administered_at'];
            $this->dose = $row['dose'];
            $this->notes = $row['notes'];
            return true;
        }
        return false;
    }

    public function update() {
        $query = "UPDATE " . $this->table_name . " SET patient_id=:patient_id, nurse_id=: nurse_id, prescription_item_id=: prescription_item_id, administered_at=:administered_at, dose=:dose, notes=:notes WHERE id=:id";

        $stmt = $this->conn->prepare($query);

        $this->patient_id = htmlspecialchars(strip_tags($this->patient_id));
        $this->nurse_id = htmlspecialchars(strip_tags($this->nurse_id));
        $this->prescription_item_id = htmlspecialchars(strip_tags($this->prescription_item_id));
        $this->administered_at = htmlspecialchars(strip_tags($this->administered_at));
        $this->dose = htmlspecialchars(strip_tags($this->dose));
        $this->notes = htmlspecialchars(strip_tags($this->notes));
        $this->id = htmlspecialchars(strip_tags($this->id));

        $stmt->bindParam(":patient_id", $this->patient_id);
        $stmt->bindParam(":nurse_id", $this->nurse_id);
        $stmt->bindParam(":prescription_item_id", $this->prescription_item_id);
        $stmt->bindParam(":administered_at", $this->administered_at);
        $stmt->bindParam(":dose", $this->dose);
        $stmt->bindParam(":notes", $this->notes);
        $stmt->bindParam(":id", $this->id);

        if($stmt->execute()){
            return true;
        }
        return false;
    }

    public function getByDateRange($start_date, $end_date){
       $query = "SELECT da.*, p.first_name, p.last_name, u.full_name as nurse_name, d.name as drug_name FROM" . $this->table_name . " da LEFT JOIN patients p ON da.patient_id = p.id LEFT JOIN users u ON da.nurse_id = u.id LEFT JOIN prescription_items pi ON da.prescription_item_id = pi.id
        LEFT JOIN drugs d ON pi.drug_id = d.id WHERE DATE(da.administered_at) BETWEEN ? AND ? ORDER BY da.administered_at DESC"; 

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $start_date);
        $stmt->bindParam(2, $end_date);
        $stmt->execute();
        return $stmt;
    }
}
?>