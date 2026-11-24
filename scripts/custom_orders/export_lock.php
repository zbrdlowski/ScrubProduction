<?php
declare(strict_types=1);

function customOrdersIsExported(array $order): bool
{
  return (int) ($order['production_order_id'] ?? 0) > 0 || !empty($order['exported_at']);
}

function customOrdersExportLockMessage(): string
{
  return 'This custom order has been exported to Production. Fields that are not synchronized are locked. Make these changes in the Production order.';
}

/** These values are copied only at export, or belong solely to the custom workflow. */
function customOrdersExportLockedHeaderFields(): array
{
  return [
    'official_order_number', 'official_prefix', 'status', 'complexity_level',
    'customer_country', 'bike_brand', 'bike_model', 'bike_year', 'bike_details',
    'rider_name', 'rider_number', 'currency', 'deposit_revision_limit',
    'deposit_revision_used', 'graphics_brief', 'internal_notes',
    'last_contact_at', 'next_followup_at', 'dead_order_flag',
  ];
}

function customOrdersExportLockedHeaderChanges(array $existing, array $posted): array
{
  if (!customOrdersIsExported($existing)) {
    return [];
  }
  $changes = [];
  foreach (customOrdersExportLockedHeaderFields() as $field) {
    if (!array_key_exists($field, $posted)) {
      continue;
    }
    $before = trim((string) ($existing[$field] ?? ''));
    $after = trim((string) ($posted[$field] ?? ''));
    if (in_array($field, ['last_contact_at', 'next_followup_at'], true)) {
      $before = str_replace('T', ' ', substr($before, 0, 16));
      $after = str_replace('T', ' ', substr($after, 0, 16));
    }
    if ($before !== $after) {
      $changes[] = $field;
    }
  }
  return $changes;
}

function customOrdersPreserveExportLockedHeader(array $existing, array $data): array
{
  if (customOrdersIsExported($existing)) {
    foreach (customOrdersExportLockedHeaderFields() as $field) {
      if (array_key_exists($field, $data)) {
        $data[$field] = $existing[$field] ?? null;
      }
    }
  }
  return $data;
}

function customOrdersRequireUnexported(mysqli $conn, int $orderId): void
{
  $stmt = $conn->prepare('SELECT production_order_id, exported_at FROM custom_orders WHERE id = ? LIMIT 1');
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$order) {
    throw new RuntimeException('Custom order not found.');
  }
  if (customOrdersIsExported($order)) {
    throw new RuntimeException(customOrdersExportLockMessage());
  }
}
