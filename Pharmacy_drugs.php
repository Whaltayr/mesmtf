<?php
include_once 'db.php';
include_once 'drugs.php';
include_once 'DrugAdministration.php';
include_once 'PharmacyActions.php';

$database = new Database();
$db = $database->getConnection();

$drug = new drugs($db);
$drug_admin = new DrugAdministration($db);
$pharmacy = new PharmacyActions($db);

$drugs_data = array();
$low_stock_data = array();
$administration_data = array();
$pharmacy_actions_data = array();
$pending_prescriptions_data = array();
$pharmacy_summary_data = array();

if($_POST){
    if(isset($_POST['add_drug'])){
        $drug->code = $_POST ['code'];
        $drug->name = $_POST ['name'];
        $drug->description = $_POST ['description'];
        $drug->stock = $_POST ['stock'];
        $drug->reorder_level = $_POST ['reorder_level'];
        $drug->unit = $_POST ['unit'];

        if($drug->create()){
            $message = array('type' => 'success', 'text' => 'Drug added successfully.');
        } else {
            $message = array('type' => 'error', 'text' => 'unable to add drug.');
        }
    }
}
    if(isset($_POST['record_administration'])){
       $drug_admin->patient_id = $_POST['patient_id'];
       $drug_admin->nurse_id = $_POST['nurse_id'];
       $drug_admin->prescription_item_id = $_POST['prescription_item_id']; 
       $drug_admin->administered_at = $_POST['administered_at'];
       $drug_admin->dose= $_POST['dose'];
       $drug_admin->notes = $_POST['notes'];

       if($drug_admin->create()){
        $message = array('type' => 'success', 'text' => 'Drug administration recorded successfully.');
       }else {
            $message = array('type' => 'error', 'text' => 'unable to record administration.');
        }
    }

    if(isset($_POST['pharmacy_action'])){
       $pharmacy-> = $_POST['patient_id'];
       $pharmacy->prescription_item_id = $_POST['prescription_item_id'];
       $pharmacy->pharmacist_id = $_POST['pharmacist_id']; 
       $pharmacy->action = $_POST['action'];
       $pharmacy->notes = $_POST['notes'];

       if($pharmacy->create()){
        if($_POST['action']== 'dispensed'){
            $updade_query = "UPDATE prescriptions pr JOIN prescription_items pi ON pr.id = pi.prescription_id SET pr.status = 'dispensed' WHERE pi.id = ?";
            $stmt = $db->prepare($update_query);
            $stmt->bindParam(2, $_POST['prescription_item_id']);
            $stmt->execute();
        }
             $message = array ('type' => 'success', 'text' => 'Pharmacy action recorded successfully.');
            }else{
                $message = array('type' => 'error', 'text'=> 'Unable to record pharmacy action.');
            }

}
try {
    $stmt = $drug->read();
    if($stmt->rowCount() > 0){
        $drugs_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $drug->getLowStock();
    if($stmt->rowCount() > 0){
        $low_stock_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $drug_admin->read();
    if($stmt->rowCount()> 0){
        $administration_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

     $stmt = $pharmacy->read();
    if($stmt->rowCount() > 0){
        $pharmacy_actions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

     $stmt = $pharmacy->getPendingPrescriptions();
    if($stmt->rowCount() > 0){
        $pending_prescriptions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

     $stmt = $pharmacy->getSummary();
    if($stmt->rowCount() > 0){
        $pharmacy_summary_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

   $nurses = $db->query("SELECT id, full_name FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'nurse')")->fetchAll(PDO::FETCH_ASSOC);
   $pharmacists = $db->query ("SELECT id, full_name FROM users WHERE role_id= (SELECT id FROM roles WHERE name = 'pharmacist')")->fetchAll(PDO::FETCH_ASSOC);
   $patients = $db->query("SELECT id, first_name, last_name FROM patients")->fetchAll(PDO::FETCH_ASSOC); 
} catch(PDOException $exception){
    $message = array('type' => 'error', 'text' => 'Database error:' . $exception->getMessage());
}
include 'pharmacy_drugs_template.html';
?>