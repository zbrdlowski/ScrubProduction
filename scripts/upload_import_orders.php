<?php
declare(strict_types=1);

session_start();
//echo json_encode(['session' => $_SESSION]); exit;
header('Content-Type: application/json; charset=utf-8');

if ((int)($_SESSION['permission'] ?? 0) < 500) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/importers/import_lib.php';
require_once __DIR__ . '/importers/import_ebay.php';
require_once __DIR__ . '/importers/import_shoptet.php';
require_once __DIR__ . '/importers/import_mxlocker.php';

oi_set_utf8mb4($conn);

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
  }

  $source = strtoupper(trim((string)($_POST['source'] ?? '')));
  if (!in_array($source, ['EBAY','SHOPTET','MX_LOCKER'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid source']);
    exit;
  }

  if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File upload error']);
    exit;
  }

  $tmp = $_FILES['file']['tmp_name'];
  $origName = $_FILES['file']['name'] ?? 'upload.csv';
  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

  if ($ext !== 'csv') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Only CSV files allowed']);
    exit;
  }

  $dir = __DIR__ . '/../uploads/imports';
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }

  $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
  $filename = date('Ymd_His') . '_' . $source . '_' . $safeName;
  $dest = $dir . '/' . $filename;

  if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
  }

  $conn->begin_transaction();

  if ($source === 'EBAY') {
    $stats = import_ebay_csv($conn, $dest);
  } elseif ($source === 'SHOPTET') {
    $stats = import_shoptet_csv($conn, $dest);
  } else {
    $stats = import_mxlocker_csv($conn, $dest);
  }

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'source' => $source,
    'filename' => $filename,
    'orders' => $stats['orders'] ?? 0,
    'items'  => $stats['items'] ?? 0,
    'note'   => $stats['note'] ?? null,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($conn) && $conn instanceof mysqli) {
    @$conn->rollback();
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>