<?
include('../includes/conn.php');
$brand = $_GET['brand'] ?? '';
$stmt = $conn->prepare("SELECT DISTINCT barcode FROM items WHERE brand = ? ORDER BY barcode");
$stmt->bind_param("s", $brand);
$stmt->execute();
$result = $stmt->get_result();

echo '<option value="">Select Barcode</option>';
while ($row = $result->fetch_assoc()) {
  echo '<option value="'.htmlspecialchars($row['barcode']).'">'.$row['barcode'].'</option>';
}
?>