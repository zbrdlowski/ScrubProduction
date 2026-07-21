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
  $hasPhpSpreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
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

/*
|--------------------------------------------------------------------------
| eBay tracking upload CSV
|--------------------------------------------------------------------------
| Po importe EOD reportu sa pre objednavky so zdrojom EBAY vygeneruje CSV
| v tvare, aky ocakava eBay bulk upload (Shipping Status, Order Number,
| Item Number, Item Title, Custom Label, Transaction ID,
| Shipping Carrier Used, Tracking Number). Hodnota nizsie je text, ktory
| eBay pozna ako nazov dopravcu (nie interny nazov sluzby z FedEx EOD).
*/
$EBAY_CARRIER_LABEL = 'FedEx';

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

function resolveXlsxWorksheetTarget(?string $workbookXml, ?string $relsXml): ?string
{
  if ($workbookXml === null || $workbookXml === '' || $relsXml === null || $relsXml === '') {
    return null;
  }

  $workbookXml = (string) stripBom($workbookXml);
  $relsXml = (string) stripBom($relsXml);

  libxml_clear_errors();
  $prevSetting = libxml_use_internal_errors(true);

  $workbookDoc = new DOMDocument();
  $workbookLoaded = @$workbookDoc->loadXML($workbookXml);
  $relsDoc = new DOMDocument();
  $relsLoaded = @$relsDoc->loadXML($relsXml);

  libxml_clear_errors();
  libxml_use_internal_errors($prevSetting);

  if (!$workbookLoaded || !$relsLoaded) {
    return null;
  }

  $workbookXPath = new DOMXPath($workbookDoc);
  $sheetNodes = $workbookXPath->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');
  if ($sheetNodes === false || $sheetNodes->length === 0) {
    return null;
  }

  $firstSheetNode = $sheetNodes->item(0);
  $firstRid = null;
  foreach ($firstSheetNode->attributes as $attr) {
    if ($attr->localName === 'id') {
      $firstRid = $attr->nodeValue;
      break;
    }
  }
  if ($firstRid === null || $firstRid === '') {
    return null;
  }

  $relsXPath = new DOMXPath($relsDoc);
  $relNodes = $relsXPath->query('//*[local-name()="Relationship"][@Id="' . $firstRid . '"]');
  if ($relNodes === false || $relNodes->length === 0) {
    return null;
  }

  $target = ltrim((string) $relNodes->item(0)->getAttribute('Target'), '/');
  if ($target === '') {
    return null;
  }
  if (strpos($target, 'worksheets/') !== 0) {
    $target = 'worksheets/' . basename($target);
  }

  return 'xl/' . $target;
}

function listXlsxWorksheetEntriesViaShell(string $xlsxPath): array
{
  $pathArg = escapeshellarg($xlsxPath);
  $commands = [
    'unzip -Z1 ' . $pathArg,
    'bsdtar -tf ' . $pathArg,
    'tar -tf ' . $pathArg,
  ];

  foreach ($commands as $command) {
    $exitCode = 1;
    $output = runExternalCommand($command, $exitCode);
    if ($exitCode !== 0 || $output === '') {
      continue;
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
    $entries = array_values(array_filter($lines, function ($line) {
      return (bool) preg_match('#^xl/worksheets/sheet\d+\.xml$#', trim($line));
    }));
    if ($entries) {
      return $entries;
    }
  }

  return [];
}

function parseXlsxFallback(string $xlsxPath): array
{
  if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) === true) {
      $workbookXml = $zip->getFromName('xl/workbook.xml');
      $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
      $entryName = resolveXlsxWorksheetTarget(
        $workbookXml !== false ? (string) $workbookXml : null,
        $relsXml !== false ? (string) $relsXml : null
      ) ?? 'xl/worksheets/sheet1.xml';

      $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
      $sheetXml = $zip->getFromName($entryName);

      if ($sheetXml === false) {
        // Posledny pokus - najdi akykolvek worksheet v archive priamym vypisom entries.
        for ($i = 0; $i < $zip->numFiles; $i++) {
          $name = $zip->getNameIndex($i);
          if ($name !== false && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            $candidate = $zip->getFromName($name);
            if ($candidate !== false) {
              $sheetXml = $candidate;
              break;
            }
          }
        }
      }

      $zip->close();

      if ($sheetXml !== false) {
        return parseXlsxFromXmlStrings($sharedXml !== false ? (string) $sharedXml : null, (string) $sheetXml);
      }
    }
  }

  $workbookXml = readZipEntryViaShell($xlsxPath, 'xl/workbook.xml');
  $relsXml = readZipEntryViaShell($xlsxPath, 'xl/_rels/workbook.xml.rels');
  $entryName = resolveXlsxWorksheetTarget($workbookXml, $relsXml) ?? 'xl/worksheets/sheet1.xml';

  $sharedXml = readZipEntryViaShell($xlsxPath, 'xl/sharedStrings.xml');
  $sheetXml = readZipEntryViaShell($xlsxPath, $entryName);

  if ($sheetXml === null || $sheetXml === '') {
    foreach (listXlsxWorksheetEntriesViaShell($xlsxPath) as $candidateName) {
      $candidate = readZipEntryViaShell($xlsxPath, $candidateName);
      if ($candidate !== null && $candidate !== '') {
        $sheetXml = $candidate;
        break;
      }
    }
  }

  if ($sheetXml !== null && $sheetXml !== '') {
    return parseXlsxFromXmlStrings($sharedXml, $sheetXml);
  }

  if (DIRECTORY_SEPARATOR === '\\') {
    return parseXlsxViaPowerShell($xlsxPath);
  }

  throw new RuntimeException('Unable to parse XLSX: ZipArchive and shell unzip methods are unavailable.');
}

function stripBom(?string $value): ?string
{
  if ($value === null) {
    return null;
  }
  if (substr($value, 0, 3) === "\xEF\xBB\xBF") {
    return substr($value, 3);
  }
  return $value;
}

function parseXlsxFromXmlStrings(?string $sharedXml, string $sheetXml): array
{
  $sharedXml = stripBom($sharedXml);
  $sheetXml = (string) stripBom($sheetXml);

  $sharedStrings = [];
  if ($sharedXml !== null && $sharedXml !== '') {
    $sharedDoc = new DOMDocument();
    libxml_clear_errors();
    $prevSetting = libxml_use_internal_errors(true);
    $sharedLoaded = @$sharedDoc->loadXML($sharedXml);
    libxml_clear_errors();
    libxml_use_internal_errors($prevSetting);

    if ($sharedLoaded) {
      $sharedXPath = new DOMXPath($sharedDoc);
      $siNodes = $sharedXPath->query('//*[local-name()="si"]');
      if ($siNodes !== false) {
        foreach ($siNodes as $siNode) {
          $tNodes = $sharedXPath->query('.//*[local-name()="t"]', $siNode);
          $text = '';
          if ($tNodes !== false) {
            foreach ($tNodes as $tNode) {
              $text .= $tNode->textContent;
            }
          }
          $sharedStrings[] = $text;
        }
      }
    }
  }

  libxml_clear_errors();
  $previousLibxmlSetting = libxml_use_internal_errors(true);
  $sheetDoc = new DOMDocument();
  $sheetLoaded = @$sheetDoc->loadXML($sheetXml);
  $libxmlErrors = libxml_get_errors();
  libxml_clear_errors();
  libxml_use_internal_errors($previousLibxmlSetting);

  if (!$sheetLoaded) {
    $errorMessages = array_map(function ($e) {
      return trim((string) $e->message);
    }, $libxmlErrors);
    $errorSummary = $errorMessages ? implode(' | ', array_slice($errorMessages, 0, 3)) : 'no libxml error reported';
    throw new RuntimeException(sprintf(
      'Unable to parse worksheet XML (bytes=%d, libxml=%s, preview=%s).',
      strlen($sheetXml),
      $errorSummary,
      substr($sheetXml, 0, 150)
    ));
  }

  $xpath = new DOMXPath($sheetDoc);
  $rowNodes = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');

  if ($rowNodes === false) {
    throw new RuntimeException(sprintf(
      'Unable to parse worksheet XML: XPath query failed (bytes=%d, preview=%s).',
      strlen($sheetXml),
      substr($sheetXml, 0, 150)
    ));
  }

  if ($rowNodes->length === 0) {
    $sheetDataNodes = $xpath->query('//*[local-name()="sheetData"]');
    if ($sheetDataNodes === false || $sheetDataNodes->length === 0) {
      throw new RuntimeException(sprintf(
        'Unable to parse worksheet XML: <sheetData> element not found (bytes=%d, preview=%s).',
        strlen($sheetXml),
        substr($sheetXml, 0, 150)
      ));
    }
    // <sheetData> existuje, ale je prazdne - hárok naozaj neobsahuje žiadne riadky.
    return [];
  }

  $rows = [];
  foreach ($rowNodes as $rowNode) {
    $rowIndex = (int) $rowNode->getAttribute('r');
    $rowData = [];

    foreach ($rowNode->childNodes as $cell) {
      if (!($cell instanceof DOMElement) || $cell->localName !== 'c') {
        continue;
      }

      $cellRef = $cell->getAttribute('r');
      if ($cellRef === '' || !preg_match('/^([A-Z]+)\d+$/', $cellRef, $m)) {
        continue;
      }

      $colIndex = columnLettersToIndex($m[1]);
      $type = $cell->getAttribute('t');
      $value = '';

      if ($type === 'inlineStr') {
        $isTextNodes = $xpath->query('.//*[local-name()="is"]/*[local-name()="t"]', $cell);
        if ($isTextNodes !== false) {
          foreach ($isTextNodes as $isTextNode) {
            $value .= $isTextNode->textContent;
          }
        }
      } else {
        $vNodes = $xpath->query('./*[local-name()="v"]', $cell);
        $raw = ($vNodes !== false && $vNodes->length > 0) ? $vNodes->item(0)->textContent : '';
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
  $exitCode = 1;

  if (!function_exists('proc_open')) {
    return '';
  }

  $descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $process = @proc_open($command, $descriptors, $pipes);
  if (!is_resource($process)) {
    return '';
  }

  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  // stderr sa musí vyčítať (inak sa proces môže zaseknúť pri väčšom výstupe),
  // ale obsah sa vedome zahadzuje - nesmie sa miešať do parsovaného súboru
  stream_get_contents($pipes[2]);
  fclose($pipes[2]);

  $exitCode = proc_close($process);

  return $stdout === false ? '' : $stdout;
}

function readZipEntryViaShell(string $xlsxPath, string $entryName): ?string
{
  if (!function_exists('proc_open')) {
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
    $phpSpreadsheetDateClass = 'PhpOffice\\PhpSpreadsheet\\Shared\\Date';
    if (class_exists($phpSpreadsheetDateClass)) {
      try {
        return call_user_func([$phpSpreadsheetDateClass, 'excelToDateTimeObject'], (float) $value)->format('Y-m-d H:i:s');
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

function getOrderEbaySourceMeta(mysqli $conn, int $orderId): array
{
  $result = ['source_code' => '', 'transaction_id' => ''];

  if (!tableExists($conn, 'order_sources')) {
    return $result;
  }

  $sql = "SELECT os.code AS source_code, o.source_meta
          FROM orders o
          JOIN order_sources os ON os.id = o.source_id
          WHERE o.id = ?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return $result;
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    return $result;
  }

  $result['source_code'] = strtoupper(trim((string) ($row['source_code'] ?? '')));

  $meta = json_decode((string) ($row['source_meta'] ?? ''), true);
  if (is_array($meta) && !empty($meta['transaction_id'])) {
    $result['transaction_id'] = trim((string) $meta['transaction_id']);
  }

  return $result;
}

function getOrderEbayItemNumber(mysqli $conn, int $orderId): string
{
  if (!tableExists($conn, 'order_items')) {
    return '';
  }

  $itemColumns = getTableColumns($conn, 'order_items');
  if (!in_array('options_json', $itemColumns, true)) {
    return '';
  }

  $hasDeletedAt = in_array('deleted_at', $itemColumns, true);
  $sql = "SELECT options_json FROM order_items WHERE order_id = ?"
    . ($hasDeletedAt ? " AND deleted_at IS NULL" : "")
    . " ORDER BY id ASC";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return '';
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $options = json_decode((string) ($row['options_json'] ?? ''), true);
    if (!is_array($options)) {
      continue;
    }
    foreach ($options as $key => $value) {
      if (strcasecmp(trim((string) $key), 'Item number') === 0) {
        $value = trim((string) $value);
        if ($value !== '') {
          $stmt->close();
          return $value;
        }
      }
    }
  }
  $stmt->close();

  return '';
}

function csvEscapeField(string $value): string
{
  if (preg_match('/[",\r\n]/', $value)) {
    return '"' . str_replace('"', '""', $value) . '"';
  }
  return $value;
}

function writeEbayExportCsvFile(array $rows): ?string
{
  $dir = dirname(__DIR__, 2) . '/docs/ebay_exports';
  if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    return null;
  }

  $filename = 'ebay_tracking_upload_' . date('Ymd_His') . '.csv';
  $path = $dir . '/' . $filename;
  $fh = fopen($path, 'wb');
  if ($fh === false) {
    return null;
  }

  $header = [
    'Shipping Status',
    'Order Number',
    'Item Number',
    'Item Title',
    'Custom Label',
    'Transaction ID',
    'Shipping Carrier Used',
    'Tracking Number',
  ];
  fwrite($fh, implode(',', $header) . "\r\n");

  foreach ($rows as $row) {
    $line = [
      '', // Shipping Status - necháme prazdne, eBay to doplní samo
      (string) $row['order_number'],
      (string) $row['item_number'],
      '', // Item Title - nepovinné, necháme prazdne
      '', // Custom Label - nepovinné, necháme prazdne
      (string) $row['transaction_id'],
      (string) $row['carrier'],
      (string) $row['tracking_number'],
    ];
    $line = array_map('csvEscapeField', $line);
    fwrite($fh, implode(',', $line) . "\r\n");
  }

  fclose($fh);

  return 'docs/ebay_exports/' . $filename;
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
  $phpSpreadsheetIoFactory = 'PhpOffice\\PhpSpreadsheet\\IOFactory';
  $reader = call_user_func([$phpSpreadsheetIoFactory, 'createReaderForFile'], $tmpPath);
  $reader->setReadDataOnly(true);
  $sheet = $reader->load($tmpPath)->getActiveSheet();

  $highestRow = (int) $sheet->getHighestDataRow();
  $phpSpreadsheetCoordinate = 'PhpOffice\\PhpSpreadsheet\\Cell\\Coordinate';
  $highestCol = call_user_func([$phpSpreadsheetCoordinate, 'columnIndexFromString'], $sheet->getHighestDataColumn());

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
$ebayExportRows = [];
$ebaySkippedRows = [];

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

    $ebaySourceMeta = getOrderEbaySourceMeta($conn, $orderId);
    if ($ebaySourceMeta['source_code'] === 'EBAY') {
      $ebayItemNumber = getOrderEbayItemNumber($conn, $orderId);
      $ebayTransactionId = $ebaySourceMeta['transaction_id'];

      if ($ebayTransactionId !== '' && $ebayItemNumber !== '') {
        $ebayExportRows[] = [
          'order_number' => (string) ($order['order_number'] ?? $orderKey),
          'item_number' => $ebayItemNumber,
          'transaction_id' => $ebayTransactionId,
          'carrier' => $EBAY_CARRIER_LABEL,
          'tracking_number' => $trackingNumber,
        ];
      } else {
        $ebaySkippedRows[] = [
          'order_number' => (string) ($order['order_number'] ?? $orderKey),
          'reason' => $ebayTransactionId === '' ? 'Missing Transaction ID' : 'Missing Item Number',
        ];
      }
    }
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
$ebayExportPath = $ebayExportRows ? writeEbayExportCsvFile($ebayExportRows) : null;

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

<?php if ($ebayExportPath !== null): ?>
  <div class="alert alert-success py-2 px-3">
    <strong>eBay tracking CSV pripravené.</strong>
    <?= count($ebayExportRows) ?> objednávka(-ok) pripravená(-ých) na upload trackingu do eBay.
    <a href="<?= h(str_replace('\\', '/', $ebayExportPath)) ?>" target="_blank" rel="noopener" class="alert-link">
      <i class="fas fa-file-csv mr-1"></i>Download eBay CSV
    </a>
  </div>
<?php elseif ($ebayExportRows): ?>
  <div class="alert alert-warning py-2 px-3">
    eBay tracking CSV sa nepodarilo uložiť na disk (skontroluj práva k priečinku <code>docs/ebay_exports</code>).
  </div>
<?php endif; ?>

<?php if ($ebaySkippedRows): ?>
  <div class="alert alert-warning py-2 px-3 mb-0">
    <strong>Pre tieto eBay objednávky sa nepridal riadok do eBay CSV (chýba Transaction ID alebo Item Number):</strong>
    <ul class="mb-0">
      <?php foreach ($ebaySkippedRows as $s): ?>
        <li><?= h($s['order_number']) ?> — <?= h($s['reason']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

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