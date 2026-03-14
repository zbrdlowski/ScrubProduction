<?php
/**
 * Expected result:
 *   $rows = [
 *     ['barcode' => 'ABC123', 'quantity' => 10],
 *     ...
 *   ];
 */

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    logMsg('ERROR', 'No file uploaded or upload error');
    http_response_code(400);
    exit('File upload failed');
}

$tmpFile = $_FILES['file']['tmp_name'];
$origName = $_FILES['file']['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

logMsg('INFO', "Parsing uploaded file: {$origName}");

$rows = [];

/* ================= CSV ================= */
if ($ext === 'csv') {

    if (($handle = fopen($tmpFile, 'r')) === false) {
        logMsg('ERROR', 'Cannot open CSV file');
        http_response_code(400);
        exit('Cannot read CSV file');
    }

    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
        $barcode = trim($data[0] ?? '');
        $qty = (int)($data[1] ?? 0);

        if ($barcode === '' || $qty <= 0) {
            logMsg('WARN', "CSV row skipped (barcode='{$barcode}', qty='{$qty}')");
            continue;
        }

        $rows[] = [
            'barcode'  => $barcode,
            'quantity' => $qty
        ];
    }

    fclose($handle);

/* ================= XLSX ================= */
} elseif ($ext === 'xlsx') {

    require_once __DIR__ . '/../vendor/autoload.php';

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
    } catch (Throwable $e) {
        logMsg('ERROR', 'XLSX read error: ' . $e->getMessage());
        http_response_code(400);
        exit('Invalid XLSX file');
    }

    $sheet = $spreadsheet->getActiveSheet();

    foreach ($sheet->toArray(null, true, true, true) as $row) {
        $barcode = trim((string)($row['A'] ?? ''));
        $qty = (int)($row['B'] ?? 0);

        if ($barcode === '' || $qty <= 0) {
            logMsg('WARN', "XLSX row skipped (barcode='{$barcode}', qty='{$qty}')");
            continue;
        }

        $rows[] = [
            'barcode'  => $barcode,
            'quantity' => $qty
        ];
    }

/* ================= INVALID ================= */
} else {
    logMsg('ERROR', "Unsupported file type: {$ext}");
    http_response_code(400);
    exit('Unsupported file type (use CSV or XLSX)');
}

/* ================= FINAL VALIDATION ================= */

if (empty($rows)) {
    logMsg('ERROR', 'Parsed file contained no valid rows');
    http_response_code(400);
    exit('File contains no valid rows');
}

logMsg('INFO', 'File parsed successfully, rows=' . count($rows));
?>