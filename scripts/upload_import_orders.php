<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

if ((int)($_SESSION['permission'] ?? 0) < 500) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/order_import_lib.php';
require_once __DIR__ . '/import_darkscrub_unified.php';

oi_set_utf8mb4($conn);

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
  }

  $source = strtoupper(trim((string)($_POST['source'] ?? 'DARKSCRUB')));
  if (!in_array($source, ['DARKSCRUB','UNIFIED','DARKSCRUB_UNIFIED'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid source. Upload DARKSCRUB_IMPORT.csv as DARKSCRUB.']);
    exit;
  }

  // Import mode: 'skip' (default) leaves already-imported orders untouched,
  // 'update' fully overwrites them from the CSV (still respecting the reimport lock).
  $mode = strtolower(trim((string)($_POST['mode'] ?? 'skip')));
  if (!in_array($mode, ['skip', 'update'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid mode. Use "skip" or "update".']);
    exit;
  }

  if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File upload error']);
    exit;
  }

  $tmp = $_FILES['file']['tmp_name'];
  $origName = $_FILES['file']['name'] ?? 'DARKSCRUB_IMPORT.csv';
  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
  if ($ext !== 'csv') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Only CSV files allowed']);
    exit;
  }

  $dir = __DIR__ . '/../uploads/imports';
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $origName);
  $filename = date('Ymd_His') . '_DARKSCRUB_' . $safeName;
  $dest = $dir . '/' . $filename;

  if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
  }

  $conn->begin_transaction();
  $stats = import_darkscrub_unified_csv($conn, $dest, $mode);
  $conn->commit();

  echo json_encode([
    'ok' => true,
    'source' => 'DARKSCRUB',
    'mode' => $stats['mode'] ?? $mode,
    'filename' => $filename,
    'orders' => $stats['orders'] ?? 0,
    'created' => $stats['created'] ?? 0,
    'updated' => $stats['updated'] ?? 0,
    'items' => $stats['items'] ?? 0,
    'skipped_shipping_items' => $stats['skipped_shipping_items'] ?? 0,
    'skipped_locked_orders' => $stats['skipped_locked_orders'] ?? 0,
    'skipped_locked_order_refs' => $stats['skipped_locked_order_refs'] ?? [],
    'skipped_existing_orders' => $stats['skipped_existing_orders'] ?? 0,
    'skipped_existing_order_refs' => $stats['skipped_existing_order_refs'] ?? [],
    'note' => $stats['note'] ?? null,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($conn) && $conn instanceof mysqli) @$conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>