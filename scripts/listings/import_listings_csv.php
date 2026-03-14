<?php
require_once __DIR__ . '/../../includes/conn.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'No file uploaded']);
  exit;
}

$tmp = $_FILES['file']['tmp_name'];
$fh = fopen($tmp, 'r');
if (!$fh) {
  echo json_encode(['ok'=>false,'error'=>'Cannot read uploaded file']);
  exit;
}

$upserted = 0;
$skipped = 0;

// optional: detect header
$first = fgetcsv($fh);
if ($first === false) {
  echo json_encode(['ok'=>false,'error'=>'Empty CSV']);
  exit;
}

function norm($s){ return strtolower(trim((string)$s)); }
$hasHeader = (norm($first[0] ?? '') === 'barcode');

if (!$hasHeader) {
  // process first as data
  $row = $first;
  $rows = [$row];
} else {
  $rows = [];
}

$stmt = $conn->prepare("  INSERT INTO listings (barcode, listed_price, listed_platform)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE
    listed_price = VALUES(listed_price),
    listed_platform = VALUES(listed_platform)
");

while (true) {
  if (!empty($rows)) {
    $data = array_shift($rows);
  } else {
    $data = fgetcsv($fh);
    if ($data === false) break;
  }

  $barcode = trim((string)($data[0] ?? ''));
  $priceRaw = trim((string)($data[1] ?? ''));
  $platform = trim((string)($data[2] ?? ''));

  if ($barcode === '') { $skipped++; continue; }

  $price = null;
  if ($priceRaw !== '') {
    $priceRaw = str_replace(',', '.', $priceRaw);
    if (!is_numeric($priceRaw)) { $skipped++; continue; }
    $price = (float)$priceRaw;
  }

  if ($platform === '') $platform = null;

  // bind: barcode (s), listed_price (d) OR null handling
  // MySQLi needs workaround for null decimal: use "s" and cast, or set to null via variable & bind_param with "d" won't accept null.
  // simplest: treat as string and let MySQL cast, but keep NULL when empty:
  if ($price === null) {
    $priceStr = null;
    $stmt2 = $conn->prepare("
      INSERT INTO listings (barcode, listed_price, listed_platform)
      VALUES (?, NULL, ?)
      ON DUPLICATE KEY UPDATE
        listed_price = NULL,
        listed_platform = VALUES(listed_platform)
    ");
    $stmt2->bind_param("ss", $barcode, $platform);
    $stmt2->execute();
    $upserted++;
    continue;
  }

  $stmt->bind_param("sds", $barcode, $price, $platform);
  $stmt->execute();
  $upserted++;
}

fclose($fh);

echo json_encode(['ok'=>true,'upserted'=>$upserted,'skipped'=>$skipped]);
?>