<?php
declare(strict_types=1);

session_start();
header('Content-Type: text/html; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/activity_helper.php';

if ((int) ($_SESSION['permission'] ?? 0) < 400) {
  http_response_code(403);
  echo '<div class="alert alert-danger mb-0">No permission.</div>';
  exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  http_response_code(400);
  echo '<div class="alert alert-danger mb-0">No file uploaded.</div>';
  exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo '<div class="alert alert-danger mb-0">Upload failed.</div>';
  exit;
}

$ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'], true)) {
  http_response_code(400);
  echo '<div class="alert alert-danger mb-0">Only XLSX/XLS files are supported.</div>';
  exit;
}

$hasPhpSpreadsheet = false;
if (file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
  require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
  $hasPhpSpreadsheet = class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');
}

/*
|--------------------------------------------------------------------------
| Service Type mapping
|--------------------------------------------------------------------------
| Uprav si mapovanie podla toho, co FedEx vracia v stlpci "Service Type".
| Kluc = hodnota z XLSX, value = text, ktory sa ulozi ako carrier.
*/
$SERVICE_TYPE_MAP = [
  '3' => 'FedEx International Economy',
  'FEDEX INTERNATIONAL ECONOMY' => 'FedEx International Economy',
  'FEDEX ECONOMY' => 'FedEx Economy',
  'FEDEX EXPRESS' => 'FedEx Express',
];
$SERVICE_TYPE_DEFAULT = 'FedEx';

function h(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $tableName): bool
{
  $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  if (!$stmt) {
    return false;
  }
  $stmt->bind_param('s', $tableName);
  $stmt->execute();
  $exists = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

function getTableColumns(mysqli $conn, string $tableName): array
{
  $cols = [];
  $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  if (!$stmt) {
    return $cols;
  }
  $stmt->bind_param('s', $tableName);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $cols[] = (string) ($row['COLUMN_NAME'] ?? '');
  }
  $stmt->close();
  return $cols;
}

function normalizeHeader(string $value): string
{
  $value = trim(strtolower($value));
  $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
  return trim($value, '_');
}

function getCellValue($sheet, int $row, int $col)
{
  return $sheet->getCellByColumnAndRow($col, $row)->getValue();
}

function columnLettersToIndex(string $letters): int
{
  $letters = strtoupper(trim($letters));
  $num = 0;
  $len = strlen($letters);
  for ($i = 0; $i < $len; $i++) {
    $num = ($num * 26) + (ord($letters[$i]) - 64);
  }
  return $num;
}

function parseXlsxFallback(string $xlsxPath): array
{
  if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) === true) {
      $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
      $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
      $zip->close();

      if ($sheetXml !== false) {
        return parseXlsxFromXmlStrings($sharedXml !== false ? (string) $sharedXml : null, (string) $sheetXml);
      }
    }
  }

  $sharedXml = readZipEntryViaShell($xlsxPath, 'xl/sharedStrings.xml');
  $sheetXml = readZipEntryViaShell($xlsxPath, 'xl/worksheets/sheet1.xml');
  if ($sheetXml !== null && $sheetXml !== '') {
    return parseXlsxFromXmlStrings($sharedXml, $sheetXml);
  }

  if (DIRECTORY_SEPARATOR === '\\') {
    return parseXlsxViaPowerShell($xlsxPath);
  }

  throw new RuntimeException('Unable to parse XLSX: ZipArchive and shell unzip methods are unavailable.');
}

function parseXlsxFromXmlStrings(?string $sharedXml, string $sheetXml): array
{
  $sharedStrings = [];
  if ($sharedXml !== null && $sharedXml !== '') {
    $shared = @simplexml_load_string($sharedXml);
    if ($shared !== false && isset($shared->si)) {
      foreach ($shared->si as $item) {
        if (isset($item->t)) {
          $sharedStrings[] = (string) $item->t;
          continue;
        }

        $text = '';
        if (isset($item->r)) {
          foreach ($item->r as $run) {
            $text .= (string) ($run->t ?? '');
          }
        }
        $sharedStrings[] = $text;
      }
    }
  }

  $sheet = @simplexml_load_string($sheetXml);
  if ($sheet === false || !isset($sheet->sheetData)) {
    throw new RuntimeException('Unable to parse worksheet XML.');
  }

  $rows = [];
  foreach ($sheet->sheetData->row as $rowNode) {
    $rowIndex = (int) ($rowNode['r'] ?? 0);
    $rowData = [];

    foreach ($rowNode->c as $cell) {
      $cellRef = (string) ($cell['r'] ?? '');
      if ($cellRef === '' || !preg_match('/^([A-Z]+)\d+$/', $cellRef, $m)) {
        continue;
      }

      $colIndex = columnLettersToIndex($m[1]);
      $type = (string) ($cell['t'] ?? '');
      $value = '';

      if ($type === 'inlineStr') {
        $value = (string) ($cell->is->t ?? '');
      } else {
        $raw = isset($cell->v) ? (string) $cell->v : '';
        if ($type === 's') {
          $sharedIndex = (int) $raw;
          $value = $sharedStrings[$sharedIndex] ?? '';
        } else {
          $value = $raw;
        }
      }

      $rowData[$colIndex] = $value;
    }

    if ($rowIndex > 0) {
      $rows[$rowIndex] = $rowData;
    }
  }

  return $rows;
}

function runExternalCommand(string $command, ?int &$exitCode = null): string
{
  $output = [];
  $exitCode = 1;
  @exec($command . ' 2>&1', $output, $exitCode);
  return implode("\n", $output);
}

function readZipEntryViaShell(string $xlsxPath, string $entryName): ?string
{
  if (!function_exists('exec')) {
    return null;
  }

  $pathArg = escapeshellarg($xlsxPath);
  $entryArg = escapeshellarg($entryName);
  $commands = [];

  if (DIRECTORY_SEPARATOR === '\\') {
    $commands[] = 'tar -xOf ' . $pathArg . ' ' . $entryArg;
  } else {
    $commands[] = 'unzip -p ' . $pathArg . ' ' . $entryArg;
    $commands[] = 'bsdtar -xOf ' . $pathArg . ' ' . $entryArg;
    $commands[] = '7z e -so ' . $pathArg . ' ' . $entryArg;
    $commands[] = 'tar -xOf ' . $pathArg . ' ' . $entryArg;
  }

  foreach ($commands as $command) {
    $exitCode = 1;
    $output = runExternalCommand($command, $exitCode);
    if ($exitCode === 0 && $output !== '') {
      return $output;
    }
  }

  return null;
}

function parseXlsxViaPowerShell(string $xlsxPath): array
{
  $safePath = str_replace("'", "''", $xlsxPath);
  $script = <<<'PS'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Path = '__XLSX_PATH__'
if (-not $Path) { throw 'Missing XLSX path.' }

function Get-EntryText {
  param($Zip, [string]$Name)
  $entry = $Zip.GetEntry($Name)
  if ($null -eq $entry) { return $null }
  $stream = $entry.Open()
  $reader = New-Object System.IO.StreamReader($stream)
  try { return $reader.ReadToEnd() } finally { $reader.Dispose(); $stream.Dispose() }
}

function Get-SharedText {
  param($SiNode)
  if ($SiNode.t) { return [string]$SiNode.t }
  $text = ''
  foreach ($run in $SiNode.r) { $text += [string]$run.t }
  return $text
}

$zip = [System.IO.Compression.ZipFile]::OpenRead($Path)
try {
  $sharedStrings = @()
  $sharedXmlText = Get-EntryText -Zip $zip -Name 'xl/sharedStrings.xml'
  if ($sharedXmlText) {
    [xml]$sharedXml = $sharedXmlText
    foreach ($si in $sharedXml.sst.si) {
      $sharedStrings += (Get-SharedText -SiNode $si)
    }
  }

  $sheetXmlText = Get-EntryText -Zip $zip -Name 'xl/worksheets/sheet1.xml'
  if (-not $sheetXmlText) { throw 'Worksheet sheet1.xml not found.' }
  [xml]$sheetXml = $sheetXmlText

  $outRows = @()
  foreach ($row in $sheetXml.worksheet.sheetData.row) {
    $cells = @{}
    foreach ($cell in $row.c) {
      $ref = [string]$cell.r
      if ($ref -notmatch '^([A-Z]+)\d+$') { continue }
      $colLetters = $matches[1]
      $col = 0
      foreach ($ch in $colLetters.ToCharArray()) { $col = ($col * 26) + ([int][char]$ch - 64) }

      $type = [string]$cell.t
      $val = ''
      if ($type -eq 'inlineStr') {
        $val = [string]$cell.is.t
      } else {
        $raw = [string]$cell.v
        if ($type -eq 's') {
          $idx = 0
          [void][int]::TryParse($raw, [ref]$idx)
          if ($idx -ge 0 -and $idx -lt $sharedStrings.Count) { $val = $sharedStrings[$idx] }
        } else {
          $val = $raw
        }
      }
      $cells["$col"] = $val
    }

    $rowObj = [ordered]@{ row = [int]$row.r; cells = $cells }
    $outRows += $rowObj
  }

  $result = [ordered]@{ rows = $outRows }
  $result | ConvertTo-Json -Depth 6 -Compress
}
finally {
  $zip.Dispose()
}
PS;
  $script = str_replace('__XLSX_PATH__', $safePath, $script);

  $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
  $command = 'powershell -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . escapeshellarg($encoded);

  $output = [];
  $exitCode = 1;
  @exec($command, $output, $exitCode);

  if ($exitCode !== 0 || !$output) {
    throw new RuntimeException('Unable to parse XLSX via PowerShell fallback.');
  }

  $json = implode("\n", $output);
  $decoded = json_decode($json, true);
  if (!is_array($decoded) || !isset($decoded['rows']) || !is_array($decoded['rows'])) {
    throw new RuntimeException('Invalid PowerShell XLSX parser output.');
  }

  $rows = [];
  foreach ($decoded['rows'] as $row) {
    $rowIndex = (int) ($row['row'] ?? 0);
    $cells = $row['cells'] ?? [];
    if ($rowIndex > 0 && is_array($cells)) {
      $normalized = [];
      foreach ($cells as $col => $value) {
        $normalized[(int) $col] = (string) $value;
      }
      $rows[$rowIndex] = $normalized;
    }
  }

  return $rows;
}

function parseReferenceOrderKey(string $referenceNotes): string
{
  $referenceNotes = trim($referenceNotes);
  if ($referenceNotes === '') {
    return '';
  }

  $parts = explode(' - ', $referenceNotes, 2);
  if (count($parts) === 2) {
    return trim((string) $parts[1]);
  }

  return $referenceNotes;
}

function parseShippingDateValue($value): ?string
{
  if ($value instanceof DateTimeInterface) {
    return $value->format('Y-m-d H:i:s');
  }

  if (is_numeric($value)) {
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Shared\\Date')) {
      try {
        $dtObj = forward_static_call(['PhpOffice\\PhpSpreadsheet\\Shared\\Date', 'excelToDateTimeObject'], (float) $value);
        return $dtObj->format('Y-m-d H:i:s');
      } catch (Throwable $e) {
      }
    }

    try {
      $base = new DateTimeImmutable('1899-12-30 00:00:00');
      return $base->modify('+' . (float) $value . ' days')->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
      return null;
    }
  }

  $raw = trim((string) $value);
  if ($raw === '') {
    return null;
  }

  $formats = [
    'n/j/Y',
    'm/d/Y',
    'j.n.Y',
    'Y-m-d',
    'Y-m-d H:i:s',
    'n/j/Y H:i',
    'm/d/Y H:i',
  ];

  foreach ($formats as $format) {
    $dt = DateTimeImmutable::createFromFormat($format, $raw);
    if ($dt instanceof DateTimeImmutable) {
      return $dt->format('Y-m-d H:i:s');
    }
  }

  try {
    return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function resolveCarrierLabel(string $serviceType, array $map, string $default): string
{
  $serviceType = trim($serviceType);
  if ($serviceType === '') {
    return $default;
  }

  $upper = strtoupper($serviceType);
  return $map[$upper] ?? $map[$serviceType] ?? $serviceType;
}

function findOrderByReference(mysqli $conn, string $orderKey): ?array
{
  $sql = "SELECT id, order_number, external_order_id, status
          FROM orders
          WHERE order_number = ? OR external_order_id = ?
          ORDER BY id DESC
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return null;
  }
  $stmt->bind_param('ss', $orderKey, $orderKey);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function trackingAlreadyExists(mysqli $conn, int $orderId, string $trackingNumber): bool
{
  if (!tableExists($conn, 'order_tracking_numbers')) {
    return false;
  }

  $stmt = $conn->prepare("SELECT id FROM order_tracking_numbers WHERE order_id = ? AND tracking_number = ? AND deleted_at IS NULL LIMIT 1");
  if (!$stmt) {
    return false;
  }
  $stmt->bind_param('is', $orderId, $trackingNumber);
  $stmt->execute();
  $exists = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

function insertTracking(mysqli $conn, int $orderId, string $trackingNumber, string $carrier, int $userId, ?string $createdAt = null): ?int
{
  if (!tableExists($conn, 'order_tracking_numbers')) {
    return null;
  }

  if ($createdAt !== null) {
    $stmt = $conn->prepare("
      INSERT INTO order_tracking_numbers (order_id, tracking_number, carrier, created_by, created_at)
      VALUES (?, ?, ?, ?, ?)
    ");
  } else {
    $stmt = $conn->prepare("
      INSERT INTO order_tracking_numbers (order_id, tracking_number, carrier, created_by)
      VALUES (?, ?, ?, ?)
    ");
  }
  if (!$stmt) {
    return null;
  }
  if ($createdAt !== null) {
    $stmt->bind_param('issis', $orderId, $trackingNumber, $carrier, $userId, $createdAt);
  } else {
    $stmt->bind_param('issi', $orderId, $trackingNumber, $carrier, $userId);
  }
  $stmt->execute();
  $trackingId = (int) $conn->insert_id;
  $stmt->close();
  return $trackingId > 0 ? $trackingId : null;
}

function updateOrderShipmentData(mysqli $conn, int $orderId, string $trackingNumber, ?string $shippedAt): bool
{
  $columns = getTableColumns($conn, 'orders');
  if (!$columns) {
    return false;
  }

  $set = [];
  $types = '';
  $params = [];

  if ($shippedAt !== null && in_array('shipped_at', $columns, true)) {
    $set[] = 'shipped_at = ?';
    $types .= 's';
    $params[] = $shippedAt;
  }

  if (!$set) {
    return false;
  }

  $types .= 'i';
  $params[] = $orderId;

  $sql = "UPDATE orders SET " . implode(', ', $set) . " WHERE id = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return false;
  }
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->close();
  return true;
}

function insertOrderStatusHistoryIfAvailable(mysqli $conn, int $orderId, string $oldStatus, string $newStatus, int $userId, ?string $changedAt): void
{
  if (!tableExists($conn, 'order_status_history')) {
    return;
  }

  $columns = getTableColumns($conn, 'order_status_history');
  if (!in_array('order_id', $columns, true) || !in_array('new_status', $columns, true)) {
    return;
  }

  $hasOldStatus = in_array('old_status', $columns, true);
  $hasActor = in_array('actor_employee_id', $columns, true);
  $hasChangedAt = in_array('changed_at', $columns, true);

  $fields = ['order_id', 'new_status'];
  $placeholders = ['?', '?'];
  $types = 'is';
  $params = [$orderId, $newStatus];

  if ($hasOldStatus) {
    $fields[] = 'old_status';
    $placeholders[] = '?';
    $types .= 's';
    $params[] = $oldStatus;
  }
  if ($hasActor) {
    $fields[] = 'actor_employee_id';
    $placeholders[] = '?';
    $types .= 'i';
    $params[] = $userId;
  }
  if ($hasChangedAt && $changedAt !== null) {
    $fields[] = 'changed_at';
    $placeholders[] = '?';
    $types .= 's';
    $params[] = $changedAt;
  }

  $sql = "INSERT INTO order_status_history (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return;
  }
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->close();
}

function setOrderStatusShipped(mysqli $conn, int $orderId, string $oldStatus, int $userId, ?string $shippedAt): void
{
  $stmt = $conn->prepare("
    UPDATE orders
    SET status = 'SHIPPED',
        status_override = 1,
        status_override_by = ?,
        status_override_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    return;
  }
  $stmt->bind_param('ii', $userId, $orderId);
  $stmt->execute();
  $stmt->close();

  log_order_activity(
    $conn,
    $orderId,
    $userId,
    'status_changed',
    'order',
    $orderId,
    ['old' => $oldStatus, 'new' => 'SHIPPED'],
    'Status changed: ' . str_replace('_', ' ', $oldStatus) . ' → SHIPPED'
  );

  insertOrderStatusHistoryIfAvailable($conn, $orderId, $oldStatus, 'SHIPPED', $userId, $shippedAt);
}

function logTrackingImported(mysqli $conn, int $orderId, int $userId, ?int $trackingId, string $trackingNumber, string $carrier): void
{
  log_order_activity(
    $conn,
    $orderId,
    $userId,
    'tracking_added',
    'tracking',
    $trackingId ?? 0,
    [
      'tracking_number' => $trackingNumber,
      'carrier' => $carrier,
    ],
    'Tracking added'
  );
}

function writeImportLogFile(array $logRows): ?string
{
  $dir = dirname(__DIR__, 2) . '/docs/fedex_logs';
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    return null;
  }

  $filename = 'fedex_eod_import_' . date('Ymd_His') . '.csv';
  $path = $dir . '/' . $filename;
  $fh = fopen($path, 'wb');
  if ($fh === false) {
    return null;
  }

  fputcsv($fh, ['row', 'reference_notes', 'order_key', 'tracking_number', 'shipping_date', 'service_type', 'result', 'message']);
  foreach ($logRows as $row) {
    fputcsv($fh, [
      $row['row_no'],
      $row['reference_notes'],
      $row['order_key'],
      $row['tracking_number'],
      $row['shipping_date'],
      $row['service_type'],
      $row['result'],
      $row['message'],
    ]);
  }
  fclose($fh);

  return 'docs/fedex_logs/' . $filename;
}

$tmpPath = (string) $file['tmp_name'];
$sheetRows = [];
$headerMap = [];
$highestRow = 0;

if ($hasPhpSpreadsheet) {
  $reader = forward_static_call(['PhpOffice\\PhpSpreadsheet\\IOFactory', 'createReaderForFile'], $tmpPath);
  $reader->setReadDataOnly(true);
  $sheet = $reader->load($tmpPath)->getActiveSheet();

  $highestRow = (int) $sheet->getHighestDataRow();
  $highestCol = forward_static_call(['PhpOffice\\PhpSpreadsheet\\Cell\\Coordinate', 'columnIndexFromString'], $sheet->getHighestDataColumn());

  for ($col = 1; $col <= $highestCol; $col++) {
    $header = normalizeHeader((string) getCellValue($sheet, 1, $col));
    if ($header !== '') {
      $headerMap[$header] = $col;
    }
  }
} else {
  if ($ext !== 'xlsx') {
    http_response_code(500);
    echo '<div class="alert alert-danger mb-0">XLS import requires PhpSpreadsheet. Please use XLSX.</div>';
    exit;
  }

  $sheetRows = parseXlsxFallback($tmpPath);
  $highestRow = $sheetRows ? max(array_keys($sheetRows)) : 0;

  foreach (($sheetRows[1] ?? []) as $col => $value) {
    $header = normalizeHeader((string) $value);
    if ($header !== '') {
      $headerMap[$header] = (int) $col;
    }
  }
}

$requiredHeaders = [
  'reference_notes',
  'shipping_date',
  'master_tracking_number',
  'service_type',
];

foreach ($requiredHeaders as $header) {
  if (!isset($headerMap[$header])) {
    http_response_code(400);
    echo '<div class="alert alert-danger mb-0">Missing required column: ' . h($header) . '.</div>';
    exit;
  }
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$logRows = [];
$successCount = 0;
$skipCount = 0;
$errorCount = 0;

for ($rowNo = 2; $rowNo <= $highestRow; $rowNo++) {
  $referenceValue = $hasPhpSpreadsheet
    ? getCellValue($sheet, $rowNo, $headerMap['reference_notes'])
    : ($sheetRows[$rowNo][$headerMap['reference_notes']] ?? '');
  $trackingValue = $hasPhpSpreadsheet
    ? getCellValue($sheet, $rowNo, $headerMap['master_tracking_number'])
    : ($sheetRows[$rowNo][$headerMap['master_tracking_number']] ?? '');
  $serviceValue = $hasPhpSpreadsheet
    ? getCellValue($sheet, $rowNo, $headerMap['service_type'])
    : ($sheetRows[$rowNo][$headerMap['service_type']] ?? '');
  $shippingValue = $hasPhpSpreadsheet
    ? getCellValue($sheet, $rowNo, $headerMap['shipping_date'])
    : ($sheetRows[$rowNo][$headerMap['shipping_date']] ?? '');

  $referenceNotes = trim((string) $referenceValue);
  $trackingNumber = trim((string) $trackingValue);
  $serviceTypeRaw = trim((string) $serviceValue);
  $shippingDate = parseShippingDateValue($shippingValue);
  $orderKey = parseReferenceOrderKey($referenceNotes);

  if ($referenceNotes === '' && $trackingNumber === '' && $serviceTypeRaw === '') {
    continue;
  }

  $result = 'error';
  $message = '';

  try {
    if ($orderKey === '') {
      throw new RuntimeException('Order reference not found in Reference Notes.');
    }
    if ($trackingNumber === '') {
      throw new RuntimeException('Master Tracking Number is empty.');
    }
    if ($shippingDate === null) {
      throw new RuntimeException('Shipping Date could not be parsed.');
    }

    $order = findOrderByReference($conn, $orderKey);
    if (!$order) {
      throw new RuntimeException('Order not found.');
    }

    $orderId = (int) ($order['id'] ?? 0);
    $oldStatus = strtoupper(trim((string) ($order['status'] ?? '')));
    $carrier = resolveCarrierLabel($serviceTypeRaw, $SERVICE_TYPE_MAP, $SERVICE_TYPE_DEFAULT);

    if ($oldStatus === 'CANCELLED') {
      $result = 'skipped';
      $message = 'Order is CANCELLED, import was skipped.';
      $skipCount++;
      $logRows[] = [
        'row_no' => $rowNo,
        'reference_notes' => $referenceNotes,
        'order_key' => $orderKey,
        'tracking_number' => $trackingNumber,
        'shipping_date' => $shippingDate ?? '',
        'service_type' => $serviceTypeRaw,
        'result' => $result,
        'message' => $message,
      ];
      continue;
    }

    $trackingInserted = false;
    if (!trackingAlreadyExists($conn, $orderId, $trackingNumber)) {
      $trackingId = insertTracking($conn, $orderId, $trackingNumber, $carrier, $userId, $shippingDate);
      logTrackingImported($conn, $orderId, $userId, $trackingId, $trackingNumber, $carrier);
      $trackingInserted = true;
    }

    updateOrderShipmentData($conn, $orderId, $trackingNumber, $shippingDate);

    if ($oldStatus !== 'SHIPPED') {
      setOrderStatusShipped($conn, $orderId, $oldStatus !== '' ? $oldStatus : 'UNKNOWN', $userId, $shippingDate);
      $message = $trackingInserted
        ? 'Tracking imported and status changed to SHIPPED.'
        : 'Tracking already existed, shipment data updated and status changed to SHIPPED.';
    } else {
      $message = $trackingInserted
        ? 'Tracking imported. Order was already SHIPPED.'
        : 'Tracking already existed. Order was already SHIPPED.';
    }

    $result = 'success';
    $successCount++;
  } catch (Throwable $e) {
    $message = $e->getMessage();
    if (stripos($message, 'already existed') !== false) {
      $result = 'skipped';
      $skipCount++;
    } else {
      $errorCount++;
    }
  }

  $logRows[] = [
    'row_no' => $rowNo,
    'reference_notes' => $referenceNotes,
    'order_key' => $orderKey,
    'tracking_number' => $trackingNumber,
    'shipping_date' => $shippingDate ?? '',
    'service_type' => $serviceTypeRaw,
    'result' => $result,
    'message' => $message,
  ];
}

$logPath = writeImportLogFile($logRows);

?>
<div class="alert alert-info py-2 px-3">
  <strong>Import finished.</strong>
  Success: <?= (int) $successCount ?> |
  Skipped: <?= (int) $skipCount ?> |
  Errors: <?= (int) $errorCount ?>
  <?php if ($logPath !== null): ?>
    | <a href="<?= h(str_replace('\\', '/', $logPath)) ?>" target="_blank" rel="noopener">Download log</a>
  <?php endif; ?>
</div>

<?php if (!$logRows): ?>
  <div class="alert alert-warning mb-0">No data rows were found in the uploaded file.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-dark table-sm table-bordered mb-0">
      <thead>
        <tr>
          <th>Row</th>
          <th>Reference Notes</th>
          <th>Order Key</th>
          <th>Tracking</th>
          <th>Shipping Date</th>
          <th>Service Type</th>
          <th>Result</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logRows as $row): ?>
          <?php
          $badgeClass = $row['result'] === 'success'
            ? 'badge-success'
            : ($row['result'] === 'skipped' ? 'badge-warning' : 'badge-danger');
          ?>
          <tr>
            <td><?= (int) $row['row_no'] ?></td>
            <td><?= h((string) $row['reference_notes']) ?></td>
            <td><?= h((string) $row['order_key']) ?></td>
            <td><?= h((string) $row['tracking_number']) ?></td>
            <td><?= h((string) $row['shipping_date']) ?></td>
            <td><?= h((string) $row['service_type']) ?></td>
            <td><span class="badge <?= h($badgeClass) ?>"><?= h(strtoupper((string) $row['result'])) ?></span></td>
            <td><?= h((string) $row['message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
