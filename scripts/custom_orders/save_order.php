<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$existing = customOrdersGetOrder($conn, $orderId);
if (!$existing) {
  customOrdersFlash('danger', 'Custom order not found.');
  customOrdersRedirect();
}

$posted = static function (string $key): bool {
  return array_key_exists($key, $_POST);
};

$postString = static function (string $key, array $existing) use ($posted): string {
  if ($posted($key)) {
    return trim((string) ($_POST[$key] ?? ''));
  }
  return trim((string) ($existing[$key] ?? ''));
};

$postNullableDate = static function (string $key, array $existing) use ($posted): ?string {
  if ($posted($key)) {
    $value = trim((string) ($_POST[$key] ?? ''));
    return $value !== '' ? $value : null;
  }
  $value = trim((string) ($existing[$key] ?? ''));
  return $value !== '' ? $value : null;
};

$postInt = static function (string $key, array $existing, int $min, int $max): int {
  $value = array_key_exists($key, $_POST) ? (int) ($_POST[$key] ?? 0) : (int) ($existing[$key] ?? 0);
  return max($min, min($max, $value));
};

$postFloat = static function (string $key, array $existing): float {
  return array_key_exists($key, $_POST) ? (float) ($_POST[$key] ?? 0) : (float) ($existing[$key] ?? 0);
};

$data = [
  'status' => $postString('status', $existing) ?: 'LEAD',
  'complexity_level' => $postInt('complexity_level', $existing, 1, 10),
  'source_channel' => $postString('source_channel', $existing),
  'social_platform' => $postString('social_platform', $existing),
  'social_handle' => $postString('social_handle', $existing),
  'customer_name' => $postString('customer_name', $existing),
  'customer_email' => $postString('customer_email', $existing),
  'customer_phone' => $postString('customer_phone', $existing),
  'customer_country' => customOrdersNormalizeCountry($postString('customer_country', $existing)),
  'bike_brand' => $postString('bike_brand', $existing),
  'bike_model' => $postString('bike_model', $existing),
  'bike_year' => $postString('bike_year', $existing),
  'bike_details' => $postString('bike_details', $existing),
  'rider_name' => $postString('rider_name', $existing),
  'rider_number' => $postString('rider_number', $existing),
  'payment_method' => $postString('payment_method', $existing),
  'billing_name' => $postString('billing_name', $existing),
  'billing_company' => $postString('billing_company', $existing),
  'billing_company_id' => $postString('billing_company_id', $existing),
  'billing_street' => $postString('billing_street', $existing),
  'billing_city' => $postString('billing_city', $existing),
  'billing_zip' => $postString('billing_zip', $existing),
  'billing_country' => customOrdersNormalizeCountry($postString('billing_country', $existing)),
  'billing_email' => $postString('billing_email', $existing),
  'billing_phone' => $postString('billing_phone', $existing),
  'shipping_name' => $postString('shipping_name', $existing),
  'shipping_company' => $postString('shipping_company', $existing),
  'shipping_company_id' => $postString('shipping_company_id', $existing),
  'shipping_street' => $postString('shipping_street', $existing),
  'shipping_city' => $postString('shipping_city', $existing),
  'shipping_zip' => $postString('shipping_zip', $existing),
  'shipping_country' => customOrdersNormalizeCountry($postString('shipping_country', $existing)),
  'shipping_email' => $postString('shipping_email', $existing),
  'shipping_phone' => $postString('shipping_phone', $existing),
  'shipping_method' => $postString('shipping_method', $existing),
  'shipping_price' => $postFloat('shipping_price', $existing),
  'currency' => $postString('currency', $existing) ?: 'EUR',
  'deposit_revision_limit' => $postInt('deposit_revision_limit', $existing, 0, 20),
  'deposit_revision_used' => $postInt('deposit_revision_used', $existing, 0, 20),
  'graphics_brief' => $postString('graphics_brief', $existing),
  'customer_notes' => $postString('customer_notes', $existing),
  'internal_notes' => $postString('internal_notes', $existing),
  'bike_photo_urls' => $postString('bike_photo_urls', $existing),
  'reference_urls' => $postString('reference_urls', $existing),
  'last_contact_at' => $postNullableDate('last_contact_at', $existing),
  'next_followup_at' => $postNullableDate('next_followup_at', $existing),
  'dead_order_flag' => (int) ($_POST['dead_order_flag'] ?? 0) === 1 ? 1 : 0,
];

$contactId = customOrdersUpsertContactDirectory($conn, $data);

$availableColumns = customOrdersTableColumns($conn, 'custom_orders');
$updateValues = $data;
$updateValues['contact_directory_id'] = $contactId;
$updateValues['updated_by'] = $userId;

$assignments = [];
$params = [':id' => $orderId];
foreach ($updateValues as $column => $value) {
  if (!isset($availableColumns[$column])) {
    continue;
  }
  $assignments[] = $column . ' = :' . $column;
  $params[':' . $column] = $value;
}

if (!$assignments) {
  throw new RuntimeException('No compatible custom_orders columns found for header save.');
}

$stmt = $pdo->prepare('UPDATE custom_orders SET ' . implode(",\n      ", $assignments) . ' WHERE id = :id');
$saved = $stmt->execute($params);
if (!$saved) {
  throw new RuntimeException('Failed to save custom order header.');
}

$trackedFields = array_keys($data);
$changes = customOrdersActivityCollectChanges($existing, $data, $trackedFields);
customOrdersLog(
  $conn,
  $orderId,
  'header_updated',
  $userId,
  [
    'status' => $data['status'],
    'changes' => $changes,
  ],
  $changes ? ('Updated ' . count($changes) . ' field(s)') : 'No visible field changes'
);
customOrdersFlash('success', 'Custom order saved.');
customOrdersRedirect($orderId);
