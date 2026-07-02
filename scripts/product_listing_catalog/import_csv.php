<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/conn.php';

if (!isset($_SESSION['permission']) || (int) $_SESSION['permission'] < 300) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
    http_response_code(400);
    exit('Missing CSV file.');
}

$tmpFile = $_FILES['csv_file']['tmp_name'];
$size = (int) ($_FILES['csv_file']['size'] ?? 0);

if ($size <= 0) {
    http_response_code(400);
    exit('Empty CSV file.');
}

$fileHandle = fopen($tmpFile, 'rb');
if (!$fileHandle) {
    http_response_code(500);
    exit('Cannot open uploaded CSV file.');
}

$firstLine = fgets($fileHandle);
if ($firstLine === false) {
    fclose($fileHandle);
    http_response_code(400);
    exit('Cannot read CSV header.');
}

$delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
$header = str_getcsv($firstLine, $delimiter);
if (!$header) {
    fclose($fileHandle);
    http_response_code(400);
    exit('Invalid CSV header.');
}

$header = array_map(static function ($value): string {
    $value = trim((string) $value);
    return strtolower($value);
}, $header);

$required = ['product_type', 'product_code', 'product_name', 'model_code', 'marketplace'];
$map = [];

foreach ($header as $index => $columnName) {
    $map[$columnName] = $index;
}

foreach ($required as $columnName) {
    if (!array_key_exists($columnName, $map)) {
        fclose($fileHandle);
        http_response_code(400);
        exit('Missing required column: ' . $columnName);
    }
}

$productStmt = $conn->prepare(
    "INSERT INTO scrub_catalog_products (product_type, product_code, product_name)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE product_name = VALUES(product_name)"
);

$productIdStmt = $conn->prepare(
    "SELECT id FROM scrub_catalog_products WHERE product_code = ? LIMIT 1"
);

$listingStmt = $conn->prepare(
    "INSERT INTO scrub_catalog_product_listings
        (product_id, model_code, marketplace, external_code, external_url, listing_title, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        external_code = VALUES(external_code),
        external_url = VALUES(external_url),
        listing_title = VALUES(listing_title),
        is_active = VALUES(is_active)"
);

if (!$productStmt || !$productIdStmt || !$listingStmt) {
    fclose($fileHandle);
    http_response_code(500);
    exit('Database prepare failed: ' . $conn->error);
}

$allowedTypes = ['design', 'seatcover'];
$allowedMarketplaces = ['shoptet', 'ebay'];

$importedProducts = 0;
$importedListings = 0;
$errors = [];

$conn->begin_transaction();

try {
    $rowNumber = 1;

    while (($row = fgetcsv($fileHandle, 0, $delimiter)) !== false) {
        $rowNumber++;

        $allEmpty = true;
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                $allEmpty = false;
                break;
            }
        }

        if ($allEmpty) {
            continue;
        }

        $productType = strtolower(trim((string) ($row[$map['product_type']] ?? '')));
        $productCode = trim((string) ($row[$map['product_code']] ?? ''));
        $productName = trim((string) ($row[$map['product_name']] ?? ''));
        $modelCode = trim((string) ($row[$map['model_code']] ?? ''));
        $rowMarketplace = strtolower(trim((string) ($row[$map['marketplace']] ?? '')));
        $externalCode = array_key_exists('external_code', $map) ? trim((string) ($row[$map['external_code']] ?? '')) : '';
        $externalUrl = array_key_exists('external_url', $map) ? trim((string) ($row[$map['external_url']] ?? '')) : '';
        $listingTitle = array_key_exists('listing_title', $map) ? trim((string) ($row[$map['listing_title']] ?? '')) : '';
        $isActiveValue = array_key_exists('is_active', $map) ? trim((string) ($row[$map['is_active']] ?? '1')) : '1';

        if (!in_array($productType, $allowedTypes, true)) {
            $errors[] = "Row {$rowNumber}: invalid product_type '{$productType}'";
            continue;
        }

        if (!in_array($rowMarketplace, $allowedMarketplaces, true)) {
            $errors[] = "Row {$rowNumber}: invalid marketplace '{$rowMarketplace}'";
            continue;
        }

        if ($productCode === '' || $productName === '' || $modelCode === '') {
            $errors[] = "Row {$rowNumber}: missing required values";
            continue;
        }

        $productCode = mb_substr($productCode, 0, 64);
        $productName = mb_substr($productName, 0, 255);
        $modelCode = mb_substr($modelCode, 0, 32);
        $externalCode = $externalCode !== '' ? mb_substr($externalCode, 0, 64) : null;
        $externalUrl = $externalUrl !== '' ? mb_substr($externalUrl, 0, 1000) : null;
        $listingTitle = $listingTitle !== '' ? mb_substr($listingTitle, 0, 255) : null;
        $isActive = in_array(strtolower($isActiveValue), ['0', 'no', 'false', 'inactive'], true) ? 0 : 1;

        $productStmt->bind_param('sss', $productType, $productCode, $productName);
        if (!$productStmt->execute()) {
            $errors[] = "Row {$rowNumber}: product upsert failed ({$productStmt->error})";
            continue;
        }
        $importedProducts++;

        $productIdStmt->bind_param('s', $productCode);
        $productIdStmt->execute();
        $productIdResult = $productIdStmt->get_result();
        $productId = 0;
        if ($productIdResult && ($productIdRow = $productIdResult->fetch_assoc())) {
            $productId = (int) $productIdRow['id'];
        }

        if ($productId <= 0) {
            $errors[] = "Row {$rowNumber}: cannot resolve product id for {$productCode}";
            continue;
        }

        $listingStmt->bind_param(
            'isssssi',
            $productId,
            $modelCode,
            $rowMarketplace,
            $externalCode,
            $externalUrl,
            $listingTitle,
            $isActive
        );

        if (!$listingStmt->execute()) {
            $errors[] = "Row {$rowNumber}: listing upsert failed ({$listingStmt->error})";
            continue;
        }
        $importedListings++;
    }

    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    fclose($fileHandle);
    http_response_code(500);
    exit('Import failed: ' . $exception->getMessage());
}

fclose($fileHandle);

$message = 'Import finished. Products processed: ' . $importedProducts . '. Listings processed: ' . $importedListings . '.';
if ($errors) {
    $message .= ' Warnings: ' . implode(' | ', array_slice($errors, 0, 20));
}

header('Location: ../../index.php?page=product_listing_catalog&import_message=' . urlencode($message));
exit;
