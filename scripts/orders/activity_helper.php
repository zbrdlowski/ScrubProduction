<?php
declare(strict_types=1);

function log_order_activity(
  mysqli $conn,
  int $orderId,
  int $actorEmployeeId,
  string $action,
  string $entityType = '',
  int $entityId = 0,
  array $payload = [],
  string $note = ''
): void {
  $entityTypeDb = $entityType !== '' ? $entityType : null;
  $entityIdDb = $entityId > 0 ? $entityId : null;
  $payloadJson = !empty($payload)
    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;
  $noteDb = $note !== '' ? $note : null;

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
    $orderId,
    $actorEmployeeId,
    $action,
    $entityTypeDb,
    $entityIdDb,
    $payloadJson,
    $noteDb
  );

  $stmt->execute();
  $stmt->close();
}