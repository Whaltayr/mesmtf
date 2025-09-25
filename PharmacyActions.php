<?php
class PharmacyActions{
    private $conn;
    private $table_name = "pharmacy_actions";

    public $id;
    public $prescription_item_id;
    public $pharmacist_id;
    public $action;
    public $notes;
    public $created_at;

    public function __construct($db){
        $this->conn = $db;
    }

    public function create () {
        $query = "INSERT INTO " . $this->table_name . "SET prescription_item_id=:prescription_item_id, pharmacist_id=:pharmacist_id,action=:action, notes=:notes";

        $stmt = $this->conn->prepare ($query);

        $this->prescription_item_id = htmlspecialchars(strip_tags($this->prescription_item_id));
        $this->pharmacist_id = htmlspecialchars(strip_tags($this->pharmacist_id));
        $this->action = htmlspecialchars(strip_tags($this->action));
        $this->notes= htmlspecialchars(strip_tags($this->notes));

        $stmt->bindParam(":prescription_item_id", $this->prescription_item_id);
        $stmt->bindParam(":pharmacist_id", $this->pharmacist_id);
        $stmt->bindParam(":action", $this->action);
        $stmt->bindParam(":notes", $this->notes);

        if($stmt->execute()){
            return true;
        }
        return false;
    }

    public function read(){
        $query = "SELECT pa.*, pi.id as prescription_item_id, d.name as drug_name, pat.first_name, pat.last_name, u.full_name as pharmacist_name,pr.id as prescription_id FROM " . $this->table_name . "pa LEFT JOIN prescription_items pi ON pa.prescription_item_id = pi.id LEFT JOIN drugs d ON pi.prescription_id = pr.id LEFT JOIN patients pat ON pr.patient_id = pat.id LEFT JOIN users u ON pa. pharmacist_id = u.id ORDER BY pa.created_at DESC";

        $stmt = &this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function readByprescriptionItem($prescription_item_id){
        $query = "SELECT pa.*, u.full_name as pharmacist_name FROM " . $this->table_name . " pa LEFT JOIN useres u ON pa.pharmacist_id = u.id WHERE pa.prescription_item_id = ? ORDER BY pa.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $prescription_item_id);
        $stmt->execute();
        return $stmt;
    }

    public function readOne(){
        $query = "SELECT pa.*, pi.id as prescription_item_id, d.name as drug_name, pat.first_name, pat.last_name, u.full_name as pharmacist_name,pr.id as prescription_id FROM " . $this->table_name . "pa LEFT JOIN prescription_items pi ON pa.prescription_item_id = pi.id LEFT JOIN drugs d ON pi.prescription_id = pr.id LEFT JOIN patients pat ON pr.patient_id = pat.id LEFT JOIN users u ON pa. pharmacist_id = u.id WHERE pa.id = ? LIMIT 0,1";
       
         $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row) {
            $this->prescription_item_id = $row['prescription_item_id'];
            $this->pharmacist_id = $row['pharmacist_id'];
            $this->action= $row['action'];
            $this->notes= $row['notes'];
            $this->created_at = $row['created_at'];
            return true;
        }
        return false;
    }

    public function update(){
        $query = "UPDATE " . $this->table_name . "SET prescription_item_id=:prescription_item_id, pharmacist_id=:pharmacist_id,action=:action, notes=:notes WHERE id=:id";

        $stmt = $this->conn->prepare($query);

        $this->prescription_item_id = htmlspecialchars(strip_tags($this->prescription_item_id));
        $this->pharmacist_id = htmlspecialchars(strip_tags($this->pharmacist_id));~
        $this->action = htmlspecialchars(strip_tags($this->action));
        $this->notes = htmlspecialchars(strip_tags($this->notes));
        $this->id = htmlspecialchars(strip_tags($this->id));

        $stmt->bindParam(":prescription_item_id", $this->prescription_item_id);
        $stmt->bindParam(":pharmacist_id", $this->pharmacist_id);
        $stmt->bindParam(":action", $this->action);  
        $stmt->bindParam(":notes", $this->notes); 
        $stmt->bindParam(":id", $this->id); 

        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    public function getSummary($start_date = null, $end_date = null) {
        $query = "SELECT action, COUNT(*) as count FROM " . $this->table_name;

        if($start_date && $end_date){
            $query .= "WHERE DATE(created_at) BETWEEN ? AND ?";
        }

        $query .= " GROUP BY action ORDER BY count DESC";

        $stmt = $this->conn->prepare($query);

        if($start_date && $end_date){
            $stmt->bindParam(1, $start_date);
            $stmt->bindParam(2, $end_date);
        }

        $stmt->execute();
        return $stmt;
    }

    public function getPendingPrescriptions(){
    $query= "SELECT pr.*, pi.id as item_id, pi.drug_id, pi.dosage, pi.quantity, d.name as drug_name, d.stock, p.first_name, p.last_name, u.full_name as doctor_name FROM prescriptions pr LEFT JOIN prescription_items pi ON pr.id = pi.prescription_id LEFT JOIN drugs d ON pi.drug_id = d.id LEFT JOIN patients p ON pr.patient_id = p.id LEFT JOIN users u ON pr.doctor_id = u.id WHERE pr.status = 'pending' OR pr.status = 'partially_dispensed ORDER BY pr.created_at ASC";

    $stmt = $this->conn->prepare($query);
    $stmt->execute();
    return $stmt;

    }
}
?>