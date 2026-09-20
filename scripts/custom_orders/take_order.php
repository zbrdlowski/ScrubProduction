<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$orderId = (int) ($_POST['custom_order_id'] ?? $_POST['order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$permission = (int) ($_SESSION['permission'] ?? 0);

$finish = static function (bool $ok, string $message, array $extra = []) use ($orderId): void {
  if (!$ok) {
    http_response_code(422);
  }
  echo json_encode(array_merge([
    'ok' => $ok,
    'message' => $message,
    'custom_order_id' => $orderId,
  ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
};

if ($orderId <= 0 || $userId <= 0 || $permission < 1) {
  $finish(false, 'Invalid custom order take request.');
}

try {
  $assignment = customOrdersAssignOrder($conn, $orderId, $userId, $userId);
  $assignmentRow = [
    'custom_order_assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
    'assigned_employee_id' => (int) ($assignment['employee_id'] ?? 0),
    'assigned_employee_name' => (string) ($assignment['employee_name'] ?? ''),
    'assigned_employee_photo' => (string) ($assignment['employee_photo'] ?? ''),
  ];

  $finish(true, 'Custom order taken.', [
    'assignment_html' => customOrdersRenderOrderAssignmentHtml($assignmentRow, $orderId, true, $userId),
  ]);
} catch (Throwable $e) {
  $finish(false, $e->getMessage());
}
