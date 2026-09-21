<?php
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
  session_start();
  header('Content-Type: application/json; charset=utf-8');
  if ((int) ($_SESSION['permission'] ?? 0) < 400) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No permission'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/fedex_tracking_api.php';
require_once __DIR__ . '/activity_helper.php';

function fedexSyncOut(array $payload, bool $isCli, int $statusCode = 200): void
{
  if (!$isCli) {
    http_response_code($statusCode);
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . ($isCli ? PHP_EOL : '');
  exit;
}

function fedexSyncOptions(bool $isCli): array
{
  $input = [];
  if ($isCli) {
    global $argv;
    foreach (array_slice($argv ?? [], 1) as $arg) {
      if (strpos($arg, '--') !== 0) {
        continue;
      }
      $arg = substr($arg, 2);
      if (strpos($arg, '=') === false) {
        $input[$arg] = '1';
        continue;
      }
      [$key, $value] = explode('=', $arg, 2);
      $input[$key] = $value;
    }
  } else {
    $input = $_REQUEST;
  }

  foreach ($input as $key => $value) {
    $normalizedKey = str_replace('-', '_', (string) $key);
    if ($normalizedKey !== (string) $key && !array_key_exists($normalizedKey, $input)) {
      $input[$normalizedKey] = $value;
    }
  }

  $boolValue = static function ($value): bool {
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
  };

  $limit = max(1, min(300, (int) ($input['limit'] ?? fedexConfigValue('FEDEX_TRACKING_SYNC_LIMIT', '90'))));
  $pollMinutes = max(5, (int) ($input['poll_minutes'] ?? fedexConfigValue('FEDEX_TRACKING_POLL_MINUTES', '360')));

  return [
    'limit' => $limit,
    'poll_minutes' => $pollMinutes,
    'force' => $boolValue($input['force'] ?? '0'),
    'dry_run' => $boolValue($input['dry_run'] ?? '0'),
    'allow_sandbox_write' => $boolValue($input['allow_sandbox_write'] ?? '0'),
    'actor_user_id' => max(0, (int) ($input['user_id'] ?? fedexConfigValue('FEDEX_SYNC_USER_ID', (string) ($_SESSION['user_id'] ?? 0)))),
  ];
}

function fedexSyncEnvFlag(string $key): bool
{
  return in_array(strtolower(fedexConfigValue($key)), ['1', 'true', 'yes', 'on'], true);
}

function fedexSyncTableColumns(mysqli $conn, string $tableName): array
{
  $columns = [];
  $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  if (!$stmt) {
    return $columns;
  }
  $stmt->bind_param('s', $tableName);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $columns[] = (string) $row['COLUMN_NAME'];
  }
  $stmt->close();
  return $columns;
}

function fedexSyncAssertSchema(mysqli $conn): void
{
  $orderColumns = fedexSyncTableColumns($conn, 'orders');
  $trackingColumns = fedexSyncTableColumns($conn, 'order_tracking_numbers');

  $missing = [];
  foreach (['delivered_at'] as $column) {
    if (!in_array($column, $orderColumns, true)) {
      $missing[] = 'orders.' . $column;
    }
  }
  foreach ([
    'fedex_status_code',
    'fedex_status_detail',
    'fedex_last_event_at',
    'fedex_last_checked_at',
    'delivered_at',
    'fedex_last_error',
    'fedex_raw_response',
  ] as $column) {
    if (!in_array($column, $trackingColumns, true)) {
      $missing[] = 'order_tracking_numbers.' . $column;
    }
  }

  if ($missing) {
    throw new RuntimeException('FedEx sync DB schema is not installed. Run db/orders/fedex_delivery_tracking.sql. Missing: ' . implode(', ', $missing));
  }
}

function fedexSyncFetchTrackingRows(mysqli $conn, int $limit, int $pollMinutes, bool $force): array
{
  $pollWhere = $force
    ? '1=1'
    : '(otn.fedex_last_checked_at IS NULL OR otn.fedex_last_checked_at < DATE_SUB(NOW(), INTERVAL ' . (int) $pollMinutes . ' MINUTE))';

  $sql = "
    SELECT
      otn.id AS tracking_id,
      otn.order_id,
      otn.tracking_number,
      otn.carrier,
      o.status AS order_status,
      o.order_number,
      o.external_order_id
    FROM order_tracking_numbers otn
    JOIN orders o ON o.id = otn.order_id
    WHERE otn.deleted_at IS NULL
      AND otn.tracking_number <> ''
      AND otn.delivered_at IS NULL
      AND UPPER(o.status) NOT IN ('DELIVERED', 'CANCELLED')
      AND (
        LOWER(COALESCE(otn.carrier, '')) LIKE '%fedex%'
        OR LOWER(COALESCE(o.shipping_method, '')) LIKE '%fedex%'
      )
      AND $pollWhere
    ORDER BY
      CASE WHEN otn.fedex_last_checked_at IS NULL THEN 0 ELSE 1 END ASC,
      otn.created_at ASC,
      otn.id ASC
    LIMIT ?
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException('Unable to prepare tracking query: ' . $conn->error);
  }
  $stmt->bind_param('i', $limit);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
  }
  $stmt->close();

  return $rows;
}

function fedexSyncUpdateTracking(mysqli $conn, int $trackingId, array $summary, array $raw): void
{
  $statusCode = trim((string) ($summary['status_code'] ?? ''));
  $statusDetail = mb_substr(trim((string) ($summary['status_detail'] ?? '')), 0, 255);
  $latestEventAt = $summary['latest_event_at'] ?? null;
  $deliveredAt = $summary['delivered_at'] ?? null;
  $error = mb_substr(trim((string) ($summary['error'] ?? '')), 0, 255);
  $rawJson = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($rawJson === false) {
    $rawJson = '{}';
  }

  $stmt = $conn->prepare("
    UPDATE order_tracking_numbers
    SET fedex_status_code = NULLIF(?, ''),
        fedex_status_detail = NULLIF(?, ''),
        fedex_last_event_at = ?,
        fedex_last_checked_at = NOW(),
        delivered_at = COALESCE(delivered_at, ?),
        fedex_last_error = NULLIF(?, ''),
        fedex_raw_response = ?
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException('Unable to prepare tracking update: ' . $conn->error);
  }
  $stmt->bind_param('ssssssi', $statusCode, $statusDetail, $latestEventAt, $deliveredAt, $error, $rawJson, $trackingId);
  $stmt->execute();
  $stmt->close();
}

function fedexSyncMarkTrackingError(mysqli $conn, int $trackingId, string $error): void
{
  $error = mb_substr($error, 0, 255);
  $stmt = $conn->prepare("
    UPDATE order_tracking_numbers
    SET fedex_last_checked_at = NOW(),
        fedex_last_error = NULLIF(?, '')
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    return;
  }
  $stmt->bind_param('si', $error, $trackingId);
  $stmt->execute();
  $stmt->close();
}

function fedexSyncMarkOrderDelivered(mysqli $conn, int $orderId, int $actorUserId, string $deliveredAt, string $trackingNumber, string $statusDetail): array
{
  $stmt = $conn->prepare("SELECT status, delivered_at FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
  if (!$stmt) {
    throw new RuntimeException('Unable to prepare order lock: ' . $conn->error);
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$order) {
    throw new RuntimeException('Order not found.');
  }

  $oldStatus = strtoupper(trim((string) ($order['status'] ?? '')));
  if ($oldStatus === 'CANCELLED') {
    return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'message' => 'Order is CANCELLED.'];
  }

  if ($oldStatus === 'DELIVERED' && !empty($order['delivered_at'])) {
    return ['changed' => false, 'old_status' => $oldStatus, 'new_status' => $oldStatus, 'message' => 'Already delivered.'];
  }

  $newStatus = 'DELIVERED';
  $stmt = $conn->prepare("
    UPDATE orders
    SET status = ?,
        delivered_at = COALESCE(delivered_at, ?),
        status_override = 1,
        status_override_by = NULLIF(?, 0),
        status_override_at = NOW(),
        status_override_note = 'FedEx delivery sync'
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    throw new RuntimeException('Unable to prepare order delivered update: ' . $conn->error);
  }
  $stmt->bind_param('ssii', $newStatus, $deliveredAt, $actorUserId, $orderId);
  $stmt->execute();
  $stmt->close();

  log_order_activity(
    $conn,
    $orderId,
    $actorUserId,
    'fedex_delivered',
    'tracking',
    0,
    [
      'old_status' => $oldStatus,
      'new_status' => $newStatus,
      'tracking_number' => $trackingNumber,
      'delivered_at' => $deliveredAt,
      'fedex_status_detail' => $statusDetail,
    ],
    'FedEx delivered: ' . $trackingNumber
  );

  return ['changed' => $oldStatus !== $newStatus, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'message' => 'Marked delivered.'];
}

try {
  /** @var mysqli $conn */
  fedexSyncAssertSchema($conn);
  $options = fedexSyncOptions($isCli);
  if (fedexSyncEnvFlag('FEDEX_SANDBOX') && !$options['dry_run'] && !$options['allow_sandbox_write']) {
    throw new RuntimeException('Refusing to write with FEDEX_SANDBOX=1. Sandbox FedEx responses are test data; use --dry-run or switch to production credentials with FEDEX_SANDBOX=0.');
  }
  $rows = fedexSyncFetchTrackingRows($conn, $options['limit'], $options['poll_minutes'], $options['force']);

  $rowsByTracking = [];
  foreach ($rows as $row) {
    $trackingNumber = trim((string) ($row['tracking_number'] ?? ''));
    if ($trackingNumber === '') {
      continue;
    }
    $rowsByTracking[$trackingNumber][] = $row;
  }

  $summary = [
    'ok' => true,
    'dry_run' => $options['dry_run'],
    'checked_tracking_numbers' => 0,
    'checked_rows' => 0,
    'delivered_orders' => 0,
    'updated_tracking_rows' => 0,
    'errors' => 0,
    'rows' => [],
  ];

  foreach (array_chunk(array_keys($rowsByTracking), 30) as $trackingBatch) {
    $summary['checked_tracking_numbers'] += count($trackingBatch);

    $trackResponse = fedexTrackByTrackingNumbers($trackingBatch, true);
    $fedexResults = fedexTrackResultsByNumber($trackResponse);

    foreach ($trackingBatch as $trackingNumber) {
      $trackingNumber = (string) $trackingNumber;
      $trackingResult = $fedexResults[$trackingNumber] ?? null;
      if ($trackingResult === null) {
        foreach ($rowsByTracking[$trackingNumber] as $row) {
          $summary['checked_rows']++;
          $summary['errors']++;
          if (!$options['dry_run']) {
            fedexSyncMarkTrackingError($conn, (int) $row['tracking_id'], 'No FedEx tracking result returned.');
          }
          $summary['rows'][] = [
            'order_id' => (int) $row['order_id'],
            'tracking_number' => $trackingNumber,
            'result' => 'error',
            'message' => 'No FedEx tracking result returned.',
          ];
        }
        continue;
      }

      $trackSummary = $trackingResult['summary'];
      foreach ($rowsByTracking[$trackingNumber] as $row) {
        $summary['checked_rows']++;
        $orderResult = null;

        if (!$options['dry_run']) {
          $conn->begin_transaction();
          try {
            fedexSyncUpdateTracking($conn, (int) $row['tracking_id'], $trackSummary, $trackingResult['raw']);
            $summary['updated_tracking_rows']++;

            if (!empty($trackSummary['delivered']) && !empty($trackSummary['delivered_at'])) {
              $conn->query('SET @app_user_id = ' . (int) $options['actor_user_id']);
              $orderResult = fedexSyncMarkOrderDelivered(
                $conn,
                (int) $row['order_id'],
                (int) $options['actor_user_id'],
                (string) $trackSummary['delivered_at'],
                $trackingNumber,
                (string) ($trackSummary['status_detail'] ?? '')
              );
              if (!empty($orderResult['changed'])) {
                $summary['delivered_orders']++;
              }
            }

            $conn->commit();
          } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
          }
        } elseif (!empty($trackSummary['delivered']) && !empty($trackSummary['delivered_at'])) {
          $orderResult = ['changed' => true, 'old_status' => $row['order_status'] ?? '', 'new_status' => 'DELIVERED', 'message' => 'Dry run.'];
        }

        $summary['rows'][] = [
          'order_id' => (int) $row['order_id'],
          'order_number' => (string) (($row['order_number'] ?? '') ?: ($row['external_order_id'] ?? '')),
          'tracking_id' => (int) $row['tracking_id'],
          'tracking_number' => $trackingNumber,
          'fedex_status_code' => (string) ($trackSummary['status_code'] ?? ''),
          'fedex_status_detail' => (string) ($trackSummary['status_detail'] ?? ''),
          'delivered' => !empty($trackSummary['delivered']),
          'delivered_at' => (string) ($trackSummary['delivered_at'] ?? ''),
          'order_result' => $orderResult,
        ];
      }
    }
  }

  fedexSyncOut($summary, $isCli);
} catch (Throwable $e) {
  fedexSyncOut([
    'ok' => false,
    'error' => $e->getMessage(),
  ], $isCli, 500);
}
