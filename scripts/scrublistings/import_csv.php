<?php
// scripts/scrublistings/import_csv.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/conn.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

session_start();
if (!isset($_SESSION['permission']) || (int)$_SESSION['permission'] < 300) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : 'merge';
if ($mode !== 'merge' && $mode !== 'replace') $mode = 'merge';

if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing csv_file upload']);
  exit;
}

$tmp = $_FILES['csv_file']['tmp_name'];
$size = (int)($_FILES['csv_file']['size'] ?? 0);
if ($size <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Empty file']);
  exit;
}

// basic size limit (napr. 5MB)
if ($size > 5 * 1024 * 1024) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'File too large (max 5MB)']);
  exit;
}

$conn->set_charset('utf8mb4');

$errors = [];
$listingsUpserted = 0;
$itemsInserted = 0;
$itemsSkipped = 0;

$fh = fopen($tmp, 'rb');
if (!$fh) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Cannot open uploaded file']);
  exit;
}

// read header line and detect delimiter
$firstLine = fgets($fh);
if ($firstLine === false) {
  fclose($fh);
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Cannot read header']);
  exit;
}

$delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
rewind($fh);

$header = fgetcsv($fh, 0, $delimiter);
if (!$header) {
  fclose($fh);
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid CSV header']);
  exit;
}

$header = array_map(function($h) {
  $h = trim((string)$h);
  $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // strip BOM
  return strtolower($h);
}, $header);

$required = ['listing_code','listing_name'];
$expected = ['listing_code','listing_name','model_code','price','barcode','sort_order'];

$map = [];
foreach ($header as $idx => $name) {
  $map[$name] = $idx;
}
foreach ($required as $r) {
  if (!array_key_exists($r, $map)) {
    fclose($fh);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Missing required column: {$r}"]);
    exit;
  }
}

// prepared statements
$stmtUpsert = $conn->prepare(
  "INSERT INTO scrub_listings (listing_code, listing_name, model_code, price, is_active, discontinued_at, discontinued_reason)
   VALUES (?, ?, ?, ?, 1, NULL, NULL)
   ON DUPLICATE KEY UPDATE
     listing_name = VALUES(listing_name),
     model_code = VALUES(model_code),
     price = VALUES(price),
     is_active = 1,
     discontinued_at = NULL,
     discontinued_reason = NULL"
);

$stmtGetId = $conn->prepare("SELECT id FROM scrub_listings WHERE listing_code = ? LIMIT 1");

$stmtMaxSort = $conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 AS next_sort FROM scrub_listing_items WHERE listing_id=?");

$stmtInsertItem = $conn->prepare(
  "INSERT INTO scrub_listing_items (listing_id, barcode, sort_order)
   VALUES (?, ?, ?)
   ON DUPLICATE KEY UPDATE sort_order = sort_order"
);

$stmtDeleteItems = $conn->prepare("DELETE FROM scrub_listing_items WHERE listing_id = ?");

if (!$stmtUpsert || !$stmtGetId || !$stmtMaxSort || !$stmtInsertItem || !$stmtDeleteItems) {
  fclose($fh);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB prepare failed', 'detail' => $conn->error]);
  exit;
}

// In replace mode: nechceme mazať items 100x (pre každý riadok), tak si pamätáme ktoré listingy už boli "cleared"
$cleared = [];

$conn->begin_transaction();

try {
  $rowNum = 1; // header = 1
  while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $rowNum++;

    // skip empty lines
    $allEmpty = true;
    foreach ($row as $cell) {
      if (trim((string)$cell) !== '') { $allEmpty = false; break; }
    }
    if ($allEmpty) continue;

    $listingCode = trim((string)($row[$map['listing_code']] ?? ''));
    $listingName = trim((string)($row[$map['listing_name']] ?? ''));

    $modelCode = isset($map['model_code']) ? trim((string)($row[$map['model_code']] ?? '')) : '';
    $priceStr  = isset($map['price']) ? trim((string)($row[$map['price']] ?? '')) : '';
    $barcode   = isset($map['barcode']) ? strtoupper(trim((string)($row[$map['barcode']] ?? ''))) : '';
    $sortStr   = isset($map['sort_order']) ? trim((string)($row[$map['sort_order']] ?? '')) : '';

    if ($listingCode === '' || $listingName === '') {
      $errors[] = "Row {$rowNum}: missing listing_code or listing_name";
      continue;
    }

    if (mb_strlen($listingCode) > 32) $listingCode = mb_substr($listingCode, 0, 32);
    if (mb_strlen($listingName) > 255) $listingName = mb_substr($listingName, 0, 255);

    $modelCode = ($modelCode === '') ? null : mb_substr($modelCode, 0, 32);

    $price = null;
    if ($priceStr !== '') {
      // allow "149,90" -> "149.90"
      $priceStr = str_replace(',', '.', $priceStr);
      if (is_numeric($priceStr)) $price = (float)$priceStr;
      else $errors[] = "Row {$rowNum}: invalid price '{$priceStr}' (ignored)";
    }

    // upsert listing
    $stmtUpsert->bind_param("sssd", $listingCode, $listingName, $modelCode, $price);
    if (!$stmtUpsert->execute()) {
      $errors[] = "Row {$rowNum}: upsert listing failed ({$stmtUpsert->error})";
      continue;
    }
    $listingsUpserted++;

    // get listing_id
    $stmtGetId->bind_param("s", $listingCode);
    $stmtGetId->execute();
    $rid = $stmtGetId->get_result();
    $listingId = 0;
    if ($rid && ($r = $rid->fetch_assoc())) $listingId = (int)$r['id'];
    if ($listingId <= 0) {
      $errors[] = "Row {$rowNum}: cannot resolve listing_id for {$listingCode}";
      continue;
    }

    // replace mode: clear items once per listing
    if ($mode === 'replace' && !isset($cleared[$listingId])) {
      $stmtDeleteItems->bind_param("i", $listingId);
      if (!$stmtDeleteItems->execute()) {
        $errors[] = "Row {$rowNum}: delete items failed for {$listingCode} ({$stmtDeleteItems->error})";
      } else {
        $cleared[$listingId] = true;
      }
    }

    // barcode optional
    if ($barcode === '') {
      $itemsSkipped++;
      continue;
    }

    if (!preg_match('/^[A-Z0-9_\-]+$/', $barcode)) {
      $errors[] = "Row {$rowNum}: invalid barcode '{$barcode}'";
      $itemsSkipped++;
      continue;
    }

    // sort_order optional
    $sortOrder = null;
    if ($sortStr !== '') {
      if (ctype_digit($sortStr)) $sortOrder = (int)$sortStr;
      else $errors[] = "Row {$rowNum}: invalid sort_order '{$sortStr}' (auto used)";
    }

    if ($sortOrder === null) {
      $stmtMaxSort->bind_param("i", $listingId);
      $stmtMaxSort->execute();
      $rs = $stmtMaxSort->get_result();
      $sortOrder = 1;
      if ($rs && ($rr = $rs->fetch_assoc())) $sortOrder = (int)$rr['next_sort'];
      if ($sortOrder <= 0) $sortOrder = 1;
    }

    $stmtInsertItem->bind_param("isi", $listingId, $barcode, $sortOrder);
    if (!$stmtInsertItem->execute()) {
      $errors[] = "Row {$rowNum}: insert item failed ({$stmtInsertItem->error})";
      $itemsSkipped++;
      continue;
    }

    // affected_rows 1 = inserted, 2 = updated (but we don't update), 0 = duplicate no-change
    if ($stmtInsertItem->affected_rows > 0) $itemsInserted++;
    else $itemsSkipped++;
  }

  $conn->commit();

} catch (Throwable $e) {
  $conn->rollback();
  fclose($fh);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Import failed', 'detail' => $e->getMessage()]);
  exit;
}

fclose($fh);

echo json_encode([
  'ok' => true,
  'mode' => $mode,
  'listings_upserted' => $listingsUpserted,
  'items_inserted' => $itemsInserted,
  'items_skipped' => $itemsSkipped,
  'errors' => $errors
]);
?>