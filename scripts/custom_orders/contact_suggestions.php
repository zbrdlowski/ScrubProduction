<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$query = trim((string) ($_GET['q'] ?? ''));
$excludeOrderId = max(0, (int) ($_GET['exclude_id'] ?? 0));
if (mb_strlen($query, 'UTF-8') < 2) {
  echo json_encode(['ok' => true, 'results' => []]);
  exit;
}

$like = '%' . $query . '%';
$stmt = $conn->prepare("
  SELECT
    id, source_channel, social_handle, customer_name, customer_email, customer_phone,
    payment_method, shipping_method, shipping_price,
    billing_name, billing_company, billing_company_id, billing_street, billing_city,
    billing_zip, billing_country, billing_email, billing_phone,
    shipping_name, shipping_company, shipping_company_id, shipping_street, shipping_city,
    shipping_zip, shipping_country, shipping_email, shipping_phone, updated_at
  FROM custom_orders
  WHERE CONCAT_WS(' ',
    social_handle, customer_name, customer_email, customer_phone,
    billing_name, billing_company, billing_company_id, billing_street, billing_city,
    billing_zip, billing_country, billing_email, billing_phone,
    shipping_name, shipping_company, shipping_company_id, shipping_street, shipping_city,
    shipping_zip, shipping_country, shipping_email, shipping_phone
  ) LIKE ?
    AND id <> ?
  ORDER BY updated_at DESC, id DESC
  LIMIT 40
");
$stmt->bind_param('si', $like, $excludeOrderId);
$stmt->execute();
$result = $stmt->get_result();

$suggestions = [];
$seen = [];
$normalizeIdentityText = static function (?string $value): string {
  $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
  return mb_strtolower($value, 'UTF-8');
};
$contactIdentityKey = static function (
  string $company,
  string $email,
  string $phone,
  string $socialHandle,
  string $name,
  string $zip,
  string $country,
  string $city
) use ($normalizeIdentityText): string {
  $email = preg_replace('/\s+/u', '', $normalizeIdentityText($email)) ?? '';
  if ($email !== '') {
    return 'email:' . $email;
  }

  $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
  if (strlen($phoneDigits) >= 6) {
    return 'phone:' . $phoneDigits;
  }

  $company = $normalizeIdentityText($company);
  if ($company !== '') {
    return 'company:' . $company . '|' . $normalizeIdentityText($zip) . '|' . strtoupper(trim($country));
  }

  $socialHandle = ltrim($normalizeIdentityText($socialHandle), '@');
  if ($socialHandle !== '') {
    return 'nick:' . $socialHandle;
  }

  $name = $normalizeIdentityText($name);
  if ($name !== '') {
    return 'name:' . $name . '|' . $normalizeIdentityText($city) . '|' . strtoupper(trim($country));
  }

  return '';
};
while ($row = $result->fetch_assoc()) {
  $billingName = trim((string) ($row['billing_name'] ?: $row['customer_name']));
  $billingEmail = trim((string) ($row['billing_email'] ?: $row['customer_email']));
  $billingPhone = trim((string) ($row['billing_phone'] ?: $row['customer_phone']));
  $shippingName = trim((string) ($row['shipping_name'] ?: $billingName));
  $shippingEmail = trim((string) ($row['shipping_email'] ?: $billingEmail));
  $shippingPhone = trim((string) ($row['shipping_phone'] ?: $billingPhone));
  $company = trim((string) ($row['billing_company'] ?: $row['shipping_company']));
  $identityKey = $contactIdentityKey(
    $company,
    $billingEmail,
    $billingPhone,
    trim((string) $row['social_handle']),
    $billingName,
    trim((string) ($row['billing_zip'] ?: $row['shipping_zip'])),
    trim((string) ($row['billing_country'] ?: $row['shipping_country'])),
    trim((string) ($row['billing_city'] ?: $row['shipping_city']))
  );
  if ($identityKey === '' || isset($seen[$identityKey])) {
    continue;
  }
  $seen[$identityKey] = true;

  $primaryLabel = $company !== '' ? $company : ($billingName !== '' ? $billingName : $shippingName);
  $secondaryParts = array_values(array_filter([
    trim((string) $row['social_handle']),
    $billingEmail,
    trim((string) ($row['billing_city'] ?: $row['shipping_city'])),
    trim((string) ($row['billing_country'] ?: $row['shipping_country'])),
  ], static fn(string $value): bool => $value !== ''));

  $suggestions[] = [
    'id' => (int) $row['id'],
    'label' => $primaryLabel !== '' ? $primaryLabel : ('Custom order #' . (int) $row['id']),
    'detail' => implode(' · ', array_unique($secondaryParts)),
    'profile' => [
      'source_channel' => (string) $row['source_channel'],
      'social_handle' => (string) $row['social_handle'],
      'payment_method' => (string) $row['payment_method'],
      'shipping_method' => (string) $row['shipping_method'],
      'shipping_price' => number_format((float) $row['shipping_price'], 2, '.', ''),
      'billing_name' => $billingName,
      'billing_company' => (string) $row['billing_company'],
      'billing_company_id' => (string) $row['billing_company_id'],
      'billing_street' => (string) $row['billing_street'],
      'billing_city' => (string) $row['billing_city'],
      'billing_zip' => (string) $row['billing_zip'],
      'billing_country' => (string) $row['billing_country'],
      'billing_email' => $billingEmail,
      'billing_phone' => $billingPhone,
      'shipping_name' => $shippingName,
      'shipping_company' => (string) $row['shipping_company'],
      'shipping_company_id' => (string) $row['shipping_company_id'],
      'shipping_street' => (string) $row['shipping_street'],
      'shipping_city' => (string) $row['shipping_city'],
      'shipping_zip' => (string) $row['shipping_zip'],
      'shipping_country' => (string) $row['shipping_country'],
      'shipping_email' => $shippingEmail,
      'shipping_phone' => $shippingPhone,
    ],
  ];

  if (count($suggestions) >= 10) {
    break;
  }
}
$stmt->close();

// Also search the established production customer/address history. This makes
// the picker useful for existing dealers before they have a Custom Order record.
if (count($suggestions) < 10) {
  $stmt = $conn->prepare("
    SELECT
      o.id, os.code AS source_channel, o.source_meta, o.payment_method, o.shipping_method,
      cu.name AS customer_name, cu.email AS customer_email, cu.phone AS customer_phone,
      bill.name AS billing_name, bill.company AS billing_company, bill.company_id AS billing_company_id,
      bill.street AS billing_street, bill.city AS billing_city, bill.zip AS billing_zip,
      bill.country AS billing_country, bill.email AS billing_email, bill.phone AS billing_phone,
      ship.name AS shipping_name, ship.company AS shipping_company, ship.company_id AS shipping_company_id,
      ship.street AS shipping_street, ship.city AS shipping_city, ship.zip AS shipping_zip,
      ship.country AS shipping_country, ship.email AS shipping_email, ship.phone AS shipping_phone
    FROM orders o
    INNER JOIN order_sources os ON os.id = o.source_id
    LEFT JOIN customers cu ON cu.id = o.customer_id
    LEFT JOIN order_addresses bill ON bill.order_id = o.id AND UPPER(bill.type) = 'BILLING'
    LEFT JOIN order_addresses ship ON ship.order_id = o.id AND UPPER(ship.type) = 'SHIPPING'
    WHERE CONCAT_WS(' ',
      o.source_meta, cu.name, cu.email, cu.phone,
      bill.name, bill.company, bill.company_id, bill.street, bill.city, bill.zip,
      bill.country, bill.email, bill.phone,
      ship.name, ship.company, ship.company_id, ship.street, ship.city, ship.zip,
      ship.country, ship.email, ship.phone
    ) LIKE ?
    ORDER BY COALESCE(o.order_date, o.imported_at) DESC, o.id DESC
    LIMIT 40
  ");
  $stmt->bind_param('s', $like);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $sourceMeta = json_decode((string) ($row['source_meta'] ?? ''), true);
    $socialHandle = is_array($sourceMeta) ? trim((string) ($sourceMeta['social_handle'] ?? '')) : '';
    $billingName = trim((string) ($row['billing_name'] ?: $row['customer_name']));
    $billingEmail = trim((string) ($row['billing_email'] ?: $row['customer_email']));
    $billingPhone = trim((string) ($row['billing_phone'] ?: $row['customer_phone']));
    $shippingName = trim((string) ($row['shipping_name'] ?: $billingName));
    $shippingEmail = trim((string) ($row['shipping_email'] ?: $billingEmail));
    $shippingPhone = trim((string) ($row['shipping_phone'] ?: $billingPhone));
    $company = trim((string) ($row['billing_company'] ?: $row['shipping_company']));
    $identityKey = $contactIdentityKey(
      $company,
      $billingEmail,
      $billingPhone,
      $socialHandle,
      $billingName,
      trim((string) ($row['billing_zip'] ?: $row['shipping_zip'])),
      trim((string) ($row['billing_country'] ?: $row['shipping_country'])),
      trim((string) ($row['billing_city'] ?: $row['shipping_city']))
    );
    if ($identityKey === '' || isset($seen[$identityKey])) {
      continue;
    }
    $seen[$identityKey] = true;

    $secondaryParts = array_values(array_filter([
      $socialHandle,
      $billingEmail,
      trim((string) ($row['billing_city'] ?: $row['shipping_city'])),
      trim((string) ($row['billing_country'] ?: $row['shipping_country'])),
    ], static fn(string $value): bool => $value !== ''));
    $suggestions[] = [
      'id' => 'order-' . (int) $row['id'],
      'label' => $company !== '' ? $company : ($billingName !== '' ? $billingName : $shippingName),
      'detail' => implode(' · ', array_unique($secondaryParts)),
      'profile' => [
        'source_channel' => (string) $row['source_channel'],
        'social_handle' => $socialHandle,
        'payment_method' => (string) $row['payment_method'],
        'shipping_method' => (string) $row['shipping_method'],
        'billing_name' => $billingName,
        'billing_company' => (string) $row['billing_company'],
        'billing_company_id' => (string) $row['billing_company_id'],
        'billing_street' => (string) $row['billing_street'],
        'billing_city' => (string) $row['billing_city'],
        'billing_zip' => (string) $row['billing_zip'],
        'billing_country' => (string) $row['billing_country'],
        'billing_email' => $billingEmail,
        'billing_phone' => $billingPhone,
        'shipping_name' => $shippingName,
        'shipping_company' => (string) $row['shipping_company'],
        'shipping_company_id' => (string) $row['shipping_company_id'],
        'shipping_street' => (string) $row['shipping_street'],
        'shipping_city' => (string) $row['shipping_city'],
        'shipping_zip' => (string) $row['shipping_zip'],
        'shipping_country' => (string) $row['shipping_country'],
        'shipping_email' => $shippingEmail,
        'shipping_phone' => $shippingPhone,
      ],
    ];
    if (count($suggestions) >= 10) {
      break;
    }
  }
  $stmt->close();
}

echo json_encode([
  'ok' => true,
  'results' => $suggestions,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
