<?php
class drugs {
private $conn;
private $table_name = "drugs";

public $id;
public $code;
public $name;
public $description;
public $stock;
public $reorder_level;
public $unit;
public $created_at;
public $updated_at;

public function __construct($db) {
$this->conn = $db;
}

public function read() {
$query = "SELECT * FROM " . $this->table_name . " ORDER BY name ASC";
$stmt = $this->conn->prepare($query);
$stmt->execute();
return $stmt;

}

public function readOne() {
$query = "SELECT * FROM " . $this->table_name . " WHERE id = ? LIMIT 0,1";

$stmt = $this->conn->prepare($query);
$stmt->bindParam(1, $this->id);
$stmt->execute();

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if($row) {
$this->code = $row['code'];
$this->name = $row['name'];
$this->description = $row['description'];
$this->stock = $row ['stock'];
$this->reorder_level = $row['reorder_level'];
$this->unit = $row['unit'];
$this->created_at = $row['created_at'];
$this->updated_at = $row['update_at'];
return true;
}
return false;
}

public function create () {
$query = "INSERT INTO" . $this->table_name . "
SET code=:code, name=:name, description, stock=:stock, reorder_level=reorder_level, unit=:unit";

$stmt = $this->conn->prepare($query);

$this->code = htmlspecialchars(strip_tags($this->code));
$this->name = htmlspecialchars(strip_tags($this->name));
$this->description = htmlspecialchars(strip_tags($this->description));
$this->stock = htmlspecialchars(strip_tags($this->stock));
$this->reorder_level = htmlspecialchars(strip_tags($this->reorder_level));
$this->unit = htmlspecialchars(strip_tags($this->unit));

$stmt->bindParam(":code", $this->code);
$stmt->bindParam(":name", $this->name);
$stmt->bindParam(":description", $this->description);
$stmt->bindParam(":stock", $this->stock);
$stmt->bindParam(":reorder_level", $this->reorder_level);
$stmt->bindParam(":unit", $this->unit);

if($stmt->execute()){
return true;
}
return false;
}

public function update () {
$query = "UPDATE" . $this->table_name . "
SET code=:code, name=:name, description, stock=:stock, reorder_level=reorder_level, unit=:unit  WHERE id=:id";

$stmt = $this->conn->prepare($query);
$this->code = htmlspecialchars(strip_tags($this->code));
$this->name = htmlspecialchars(strip_tags($this->name));
$this->description = htmlspecialchars(strip_tags($this->description));
$this->stock = htmlspecialchars(strip_tags($this->stock));
$this->reorder_level = htmlspecialchars(strip_tags($this->reorder_level));
$this->unit = htmlspecialchars(strip_tags($this->unit));
$this->id= htmlspecialchars(strip_tags($this->id));

$stmt->bindParam(":code", $this->code);
$stmt->bindParam(":name", $this->name);
$stmt->bindParam(":description", $this->description);
$stmt->bindParam(":stock", $this->stock);
$stmt->bindParam(":reorder_level", $this->reorder_level);
$stmt->bindParam(":unit", $this->unit);
$stmt->bindParam(":id", $this->id);

if($stmt->execute()){
return true;
}
return false;
}

public function delete() {
$query = "DELETE FROM" . $this->table_name . "WHERE id = ?";
$stmt = $this->conn->prepare($query);
$stmt->bindParam(1, $this->id);

if($stmt->execute()){
return true;
}
return false;
}

public function search($keywords){
$query = "SELECT * FROM" . $this->table_name . " WHERE name LIKE ? OR code Like ? OR description LIKE ? ORDER BY name ASC";

$stmt = $this->conn->prepare($query);
$keywords = "%{$keywords}%";
$stmt->bindParam(1, $keywords);
$stmt->bindParam(2, $keywords);
$stmt->bindParam(3, $keywords);
$stmt->execute();

return $stmt;
}

public function updateStock($drug_id, $quantity) {
$query = "UPDATE " . $this->table_name . " SET stock = stock - :quantity
WHERE id = :drug_id AND stock >= :quantity";

$stmt = $this->conn->prepare($query);
$stmt->bindParam(":quantity", $quantity);
$stmt->bindParam(":drug_id", $drug_id);

return $stmt->execute();

}
}
?>

