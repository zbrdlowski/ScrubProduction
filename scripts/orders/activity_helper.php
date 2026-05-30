<?php
declare(strict_types=1);

function log_order_activity(
  mysqli $conn,
  ?int $orderId,
  ?int $actorEmployeeId,
  string $action,
  string $entityType = '',
  int $entityId = 0,
  array $payload = [],
  string $note = ''
): void {
  $orderIdDb = $orderId !== null && $orderId > 0 ? $orderId : null;
  $actorEmployeeIdDb = $actorEmployeeId !== null && $actorEmployeeId > 0 ? $actorEmployeeId : null;
  $entityTypeDb = $entityType !== '' ? $entityType : null;
  $entityIdDb = $entityId > 0 ? $entityId : null;
  $payloadJson = !empty($payload)
    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;
  $noteDb = $note !== '' ? $note : null;
  $stmt = null;

  try {
    $stmt = $conn->prepare("
      INSERT INTO order_activity
        (order_id, actor_employee_id, action, entity_type, entity_id, payload, note)
      VALUES
        (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
      return;
    }

    $stmt->bind_param(
      'iississ',
      $orderIdDb,
      $actorEmployeeIdDb,
      $action,
      $entityTypeDb,
      $entityIdDb,
      $payloadJson,
      $noteDb
    );

    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    if ($stmt instanceof mysqli_stmt) {
      $stmt->close();
    }
    error_log('Failed to log order activity: ' . $e->getMessage());
  }
}
