<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/auth.php';

function kitDissJson(array $payload, int $statusCode = 200): void
{
  http_response_code($statusCode);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  kitDissJson(['ok' => false, 'error' => 'Invalid request method.'], 405);
}

if (!auth_can('plastics.work')) {
  kitDissJson(['ok' => false, 'error' => 'No permission for this action.'], 403);
}

$user = trim((string) ($_SESSION['name'] ?? ''));
if ($user === '') {
  $user = 'unknown';
}

$barcode = strtoupper(trim((string) ($_POST['barcode'] ?? '')));
$missing = strtoupper(trim((string) ($_POST['missing_barcode'] ?? '')));
$qty = max(1, (int) ($_POST['quantity'] ?? 1));
$order = trim((string) ($_POST['order_number'] ?? ''));

if ($barcode === '' || $missing === '') {
  kitDissJson(['ok' => false, 'error' => 'Kit P/N and Missing part P/N are required.'], 422);
}

try {
  $stmt = $pdo->prepare("
    INSERT INTO disassembled_kits (user, barcode, missing_barcode, quantity, order_number)
    VALUES (?, ?, ?, ?, ?)
  ");
  $stmt->execute([$user, $barcode, $missing, $qty, $order]);

  kitDissJson(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $e) {
  kitDissJson(['ok' => false, 'error' => 'Unable to add disassembled kit: ' . $e->getMessage()], 500);
}
?>
