<?php
declare(strict_types=1);

function customOrdersFlash(string $type, string $message, array $meta = []): void
{
  $_SESSION['custom_orders_flash'] = ['type' => $type, 'message' => $message, 'meta' => $meta];
}

function customOrdersTakeFlash(): ?array
{
  if (!isset($_SESSION['custom_orders_flash']) || !is_array($_SESSION['custom_orders_flash'])) {
    return null;
  }
  $flash = $_SESSION['custom_orders_flash'];
  unset($_SESSION['custom_orders_flash']);
  return $flash;
}

function customOrdersRedirect(int $orderId = 0): void
{
  $location = '../../index.php?page=custom_orders';
  if ($orderId > 0) {
    $location .= '&custom_order_id=' . $orderId;
  }
  header('Location: ' . $location);
  exit;
}

function customOrdersNow(): string
{
  return date('Y-m-d H:i:s');
}

function customOrdersNormalizeCountry(?string $country): ?string
{
  $country = strtoupper(trim((string) $country));
  if ($country === '') {
    return null;
  }

  $map = [
    'UK' => 'GB',
    'EN' => 'GB',
    'NE' => 'NL',
    'CZ' => 'CZ',
    'SK' => 'SK',
    'DE' => 'DE',
    'AT' => 'AT',
    'FR' => 'FR',
    'IT' => 'IT',
    'CA' => 'CA',
    'US' => 'US',
    'CH' => 'CH',
  ];

  if (isset($map[$country])) {
    return $map[$country];
  }

  return preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
}

function customOrdersOrderStatuses(): array
{
  return [
    'LEAD' => 'Lead',
    'DEPOSIT_PENDING' => 'Deposit Pending',
    'DEPOSIT_PAID' => 'Deposit Paid',
    'IN_PROGRESS' => 'In Progress',
    'READY_TO_EXPORT' => 'Ready To Export',
    'EXPORTED' => 'Exported',
    'CANCELLED' => 'Cancelled',
    'DEAD' => 'Dead',
  ];
}

function customOrdersPaymentKinds(): array
{
  return [
    'DEPOSIT' => 'Deposit',
    'EXTRA_DEPOSIT' => 'Extra Deposit',
    'BALANCE' => 'Balance',
    'REFUND' => 'Refund',
  ];
}

function customOrdersAllowedItemTypes(): array
{
  return [
    'G' => 'Graphics',
    'P' => 'Plastics',
    'S' => 'Seat Cover',
    'F' => 'Fitting',
    'T' => 'Accessories',
    'M' => 'Misc / Upsell',
  ];
}

function customOrdersDepartmentOrder(string $types): string
{
  $weights = ['G' => 1, 'F' => 2, 'P' => 3, 'S' => 4, 'T' => 5, 'M' => 6];
  $parts = array_values(array_unique(str_split(strtoupper($types))));
  usort($parts, static function ($a, $b) use ($weights) {
    return ($weights[$a] ?? 99) <=> ($weights[$b] ?? 99);
  });
  return implode('', $parts);
}

function customOrdersGetSourceId(mysqli $conn, string $code): int
{
  $code = strtoupper(trim($code));
  $stmt = $conn->prepare('SELECT id FROM order_sources WHERE code = ? LIMIT 1');
  $stmt->bind_param('s', $code);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) {
    return (int) $row['id'];
  }

  $stmt = $conn->prepare('INSERT INTO order_sources (code) VALUES (?)');
  $stmt->bind_param('s', $code);
  $stmt->execute();
  $id = (int) $stmt->insert_id;
  $stmt->close();
  return $id;
}

function customOrdersLog(mysqli $conn, int $orderId, string $action, ?int $actorEmployeeId = null, array $payload = [], string $note = ''): void
{
  $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
  $stmt = $conn->prepare('
    INSERT INTO custom_order_activity (custom_order_id, actor_employee_id, action, payload, note)
    VALUES (?, ?, ?, ?, ?)
  ');
  if (!$stmt) {
    return;
  }
  $stmt->bind_param('iisss', $orderId, $actorEmployeeId, $action, $payloadJson, $note);
  $stmt->execute();
  $stmt->close();
}

function customOrdersCreateSkeleton(mysqli $conn, int $userId): int
{
  $stmt = $conn->prepare('
    INSERT INTO custom_orders (internal_code, status, owner_employee_id, owner_assigned_by, owner_assigned_at, created_by, updated_by)
    VALUES (\'PENDING\', \'LEAD\', ?, ?, NOW(), ?, ?)
  ');
  $stmt->bind_param('iiii', $userId, $userId, $userId, $userId);
  $stmt->execute();
  $orderId = (int) $stmt->insert_id;
  $stmt->close();

  $internalCode = 'CO' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
  $stmt = $conn->prepare('UPDATE custom_orders SET internal_code = ? WHERE id = ?');
  $stmt->bind_param('si', $internalCode, $orderId);
  $stmt->execute();
  $stmt->close();

  customOrdersLog($conn, $orderId, 'created', $userId, ['internal_code' => $internalCode, 'owner_employee_id' => $userId], 'Custom order created');
  return $orderId;
}

function customOrdersAssignableEmployees(mysqli $conn): array
{
  $employees = [];
  $sql = "
    SELECT id, firstname, lastname, photo, personal_orders, active, position_id
    FROM employees
    WHERE active = 'Active'
    ORDER BY firstname ASC, lastname ASC
  ";
  $res = $conn->query($sql);
  if (!$res) {
    return $employees;
  }

  while ($row = $res->fetch_assoc()) {
    $employees[] = $row;
  }

  return $employees;
}

function customOrdersUpsertContactDirectory(mysqli $conn, array $orderData): ?int
{
  $displayName = trim((string) ($orderData['customer_name'] ?? ''));
  $socialPlatform = trim((string) ($orderData['social_platform'] ?? ''));
  $socialHandle = trim((string) ($orderData['social_handle'] ?? ''));
  $email = trim((string) ($orderData['customer_email'] ?? ''));
  $phone = trim((string) ($orderData['customer_phone'] ?? ''));
  $country = customOrdersNormalizeCountry((string) ($orderData['customer_country'] ?? ''));

  if ($displayName === '' && $socialHandle === '' && $email === '' && $phone === '') {
    return null;
  }

  $lookupSql = '
    SELECT id
    FROM custom_order_contacts
    WHERE (' . ($email !== '' ? 'email = ?' : '1 = 0') . ')
       OR (' . ($phone !== '' ? 'phone = ?' : '1 = 0') . ')
       OR (' . ($socialHandle !== '' ? 'social_handle = ?' : '1 = 0') . ')
    ORDER BY id ASC
    LIMIT 1
  ';
  $stmt = $conn->prepare($lookupSql);
  $types = '';
  $params = [];
  if ($email !== '') {
    $types .= 's';
    $params[] = $email;
  }
  if ($phone !== '') {
    $types .= 's';
    $params[] = $phone;
  }
  if ($socialHandle !== '') {
    $types .= 's';
    $params[] = $socialHandle;
  }
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
      $contactId = (int) $row['id'];
      $stmt = $conn->prepare('
        UPDATE custom_order_contacts
        SET display_name = COALESCE(NULLIF(?, \'\'), display_name),
            social_platform = COALESCE(NULLIF(?, \'\'), social_platform),
            social_handle = COALESCE(NULLIF(?, \'\'), social_handle),
            email = COALESCE(NULLIF(?, \'\'), email),
            phone = COALESCE(NULLIF(?, \'\'), phone),
            country = COALESCE(NULLIF(?, \'\'), country),
            last_used_at = NOW()
        WHERE id = ?
      ');
      $stmt->bind_param('ssssssi', $displayName, $socialPlatform, $socialHandle, $email, $phone, $country, $contactId);
      $stmt->execute();
      $stmt->close();
      return $contactId;
    }
  } else {
    $stmt->close();
  }

  $stmt = $conn->prepare('
    INSERT INTO custom_order_contacts
      (display_name, social_platform, social_handle, email, phone, country, last_used_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
  ');
  $stmt->bind_param('ssssss', $displayName, $socialPlatform, $socialHandle, $email, $phone, $country);
  $stmt->execute();
  $contactId = (int) $stmt->insert_id;
  $stmt->close();
  return $contactId;
}

function customOrdersNextLineNo(mysqli $conn, int $orderId): int
{
  $stmt = $conn->prepare('SELECT COALESCE(MAX(line_no), 0) + 1 AS next_line FROM custom_order_items WHERE custom_order_id = ?');
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int) ($row['next_line'] ?? 1);
}

function customOrdersItemPayloadFromPost(): array
{
  $options = [
    'category_info' => trim((string) ($_POST['category_info'] ?? '')),
    'name' => trim((string) ($_POST['option_name'] ?? '')),
    'number' => trim((string) ($_POST['option_number'] ?? '')),
    'base-material' => trim((string) ($_POST['option_material'] ?? '')),
    'graphics-finish' => trim((string) ($_POST['option_finish'] ?? '')),
    'grip' => trim((string) ($_POST['option_grip'] ?? '')),
    'tr-swingarms' => trim((string) ($_POST['option_tr_swingarms'] ?? '')),
    'patch-style' => trim((string) ($_POST['option_patch_style'] ?? '')),
    'waterproof-seams' => trim((string) ($_POST['option_waterproof_seams'] ?? '')),
    'enduro-pocket' => trim((string) ($_POST['option_enduro_pocket'] ?? '')),
    'side-brand-patches' => trim((string) ($_POST['option_side_brand_patches'] ?? '')),
    'note' => trim((string) ($_POST['option_note'] ?? '')),
  ];

  $options = array_filter($options, static fn($value) => $value !== '');
  $internal = [
    '_custom_source' => 'custom_orders_module',
  ];

  return [
    'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'internal_options_json' => json_encode($internal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ];
}

function customOrdersGetOrder(mysqli $conn, int $orderId): ?array
{
  $stmt = $conn->prepare("
    SELECT co.*,
           TRIM(CONCAT_WS(' ', eo.firstname, eo.lastname)) AS owner_name,
           eo.photo AS owner_photo,
           TRIM(CONCAT_WS(' ', eab.firstname, eab.lastname)) AS owner_assigned_by_name
    FROM custom_orders co
    LEFT JOIN employees eo ON eo.id = co.owner_employee_id
    LEFT JOIN employees eab ON eab.id = co.owner_assigned_by
    WHERE co.id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$order) {
    return null;
  }

  $order['items'] = [];
  $res = $conn->query('SELECT * FROM custom_order_items WHERE custom_order_id = ' . (int) $orderId . ' ORDER BY line_no ASC, id ASC');
  while ($row = $res->fetch_assoc()) {
    $order['items'][] = $row;
  }

  $order['payments'] = [];
  $res = $conn->query('SELECT * FROM custom_order_payments WHERE custom_order_id = ' . (int) $orderId . ' ORDER BY received_at DESC, id DESC');
  while ($row = $res->fetch_assoc()) {
    $order['payments'][] = $row;
  }

  $order['followups'] = [];
  $res = $conn->query('SELECT * FROM custom_order_followups WHERE custom_order_id = ' . (int) $orderId . ' ORDER BY contacted_at DESC, id DESC');
  while ($row = $res->fetch_assoc()) {
    $order['followups'][] = $row;
  }

  $order['activity'] = [];
  $res = $conn->query('SELECT * FROM custom_order_activity WHERE custom_order_id = ' . (int) $orderId . ' ORDER BY created_at DESC, id DESC LIMIT 30');
  while ($row = $res->fetch_assoc()) {
    $order['activity'][] = $row;
  }

  $order['summary'] = customOrdersComputeSummary($order);
  return $order;
}

function customOrdersAssignOwner(mysqli $conn, int $orderId, int $ownerEmployeeId, int $assignedBy): void
{
  $stmt = $conn->prepare('SELECT id FROM employees WHERE id = ? AND active = ? LIMIT 1');
  $active = 'Active';
  $stmt->bind_param('is', $ownerEmployeeId, $active);
  $stmt->execute();
  $exists = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$exists) {
    throw new RuntimeException('Selected employee is not active.');
  }

  $stmt = $conn->prepare('
    UPDATE custom_orders
    SET owner_employee_id = ?, owner_assigned_by = ?, owner_assigned_at = NOW(), updated_by = ?
    WHERE id = ?
  ');
  $stmt->bind_param('iiii', $ownerEmployeeId, $assignedBy, $assignedBy, $orderId);
  $stmt->execute();
  $stmt->close();

  customOrdersLog($conn, $orderId, 'owner_assigned', $assignedBy, ['owner_employee_id' => $ownerEmployeeId], 'Custom order owner updated');
}

function customOrdersComputeSummary(array $order): array
{
  $itemSubtotal = 0.0;
  $upsellSubtotal = 0.0;
  $types = [];
  foreach ((array) ($order['items'] ?? []) as $item) {
    $line = (float) ($item['qty'] ?? 0) * (float) ($item['unit_price'] ?? 0);
    $itemSubtotal += $line;
    $type = strtoupper(trim((string) ($item['item_type_code'] ?? '')));
    if ($type !== '') {
      $types[] = $type;
    }
    if ((int) ($item['is_upsell'] ?? 0) === 1) {
      $upsellSubtotal += $line;
    }
  }

  $depositTotal = 0.0;
  $paymentNet = 0.0;
  foreach ((array) ($order['payments'] ?? []) as $payment) {
    $amount = (float) ($payment['amount'] ?? 0);
    $kind = strtoupper((string) ($payment['payment_kind'] ?? ''));
    if ($kind === 'DEPOSIT' || $kind === 'EXTRA_DEPOSIT') {
      $depositTotal += $amount;
    }
    if ($kind === 'REFUND') {
      $paymentNet -= $amount;
    } else {
      $paymentNet += $amount;
    }
  }

  $shipping = (float) ($order['shipping_price'] ?? 0);
  $grossTotal = $itemSubtotal + $shipping;

  return [
    'item_subtotal' => $itemSubtotal,
    'shipping' => $shipping,
    'gross_total' => $grossTotal,
    'deposit_total' => $depositTotal,
    'payment_net' => $paymentNet,
    'balance_due' => $grossTotal - $depositTotal,
    'upsell_subtotal' => $upsellSubtotal,
    'types' => customOrdersDepartmentOrder(implode('', array_unique($types))),
  ];
}

function customOrdersAssignOfficialNumber(mysqli $conn, int $orderId, string $prefix, int $userId): string
{
  $prefix = strtoupper(trim($prefix));
  if (!in_array($prefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix');
  }

  $stmt = $conn->prepare('SELECT official_order_number FROM custom_orders WHERE id = ? LIMIT 1');
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row && !empty($row['official_order_number'])) {
    return (string) $row['official_order_number'];
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare('SELECT current_value FROM custom_order_number_sequences WHERE prefix_code = ? FOR UPDATE');
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
      throw new RuntimeException('Missing sequence for ' . $prefix);
    }

    $next = ((int) $row['current_value']) + 1;
    $number = $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

    $stmt = $conn->prepare('UPDATE custom_order_number_sequences SET current_value = ? WHERE prefix_code = ?');
    $stmt->bind_param('is', $next, $prefix);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('UPDATE custom_orders SET official_order_number = ?, official_prefix = ?, updated_by = ? WHERE id = ?');
    $stmt->bind_param('ssii', $number, $prefix, $userId, $orderId);
    $stmt->execute();
    $stmt->close();

    customOrdersLog($conn, $orderId, 'official_number_assigned', $userId, ['official_order_number' => $number], 'Official number assigned');
    $conn->commit();
    return $number;
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }
}

function customOrdersExportValidation(array $order): array
{
  $errors = [];
  $fields = [];
  $summary = $order['summary'] ?? customOrdersComputeSummary($order);
  if (trim((string) ($order['official_order_number'] ?? '')) === '') {
    $errors[] = 'Official order number is missing.';
    $fields[] = 'official_prefix';
  }
  if (empty($order['items'])) {
    $errors[] = 'At least one item is required.';
    $fields[] = 'items';
  }
  if (trim((string) ($order['customer_name'] ?? '')) === '' && trim((string) ($order['social_handle'] ?? '')) === '') {
    $errors[] = 'Customer name or social handle is required.';
    $fields[] = 'customer_name';
    $fields[] = 'social_handle';
  }
  if (trim((string) ($order['shipping_name'] ?? '')) === '') {
    $errors[] = 'Shipping name is required.';
    $fields[] = 'shipping_name';
  }
  if (trim((string) ($order['shipping_street'] ?? '')) === '' || trim((string) ($order['shipping_city'] ?? '')) === '' || trim((string) ($order['shipping_zip'] ?? '')) === '' || trim((string) ($order['shipping_country'] ?? '')) === '') {
    $errors[] = 'Complete shipping address is required.';
    if (trim((string) ($order['shipping_street'] ?? '')) === '') {
      $fields[] = 'shipping_street';
    }
    if (trim((string) ($order['shipping_city'] ?? '')) === '') {
      $fields[] = 'shipping_city';
    }
    if (trim((string) ($order['shipping_zip'] ?? '')) === '') {
      $fields[] = 'shipping_zip';
    }
    if (trim((string) ($order['shipping_country'] ?? '')) === '') {
      $fields[] = 'shipping_country';
    }
  }
  if (trim((string) ($order['customer_email'] ?? '')) === '' && trim((string) ($order['customer_phone'] ?? '')) === '' && trim((string) ($order['shipping_email'] ?? '')) === '' && trim((string) ($order['shipping_phone'] ?? '')) === '') {
    $errors[] = 'At least one contact field (email or phone) is required.';
    $fields[] = 'customer_email';
    $fields[] = 'customer_phone';
    $fields[] = 'shipping_email';
    $fields[] = 'shipping_phone';
  }
  if ((int) ($order['production_order_id'] ?? 0) > 0) {
    $errors[] = 'Order is already exported.';
  }
  if ((float) ($summary['gross_total'] ?? 0) <= 0) {
    $errors[] = 'Order total must be above zero.';
    $fields[] = 'shipping_price';
    $fields[] = 'items';
  }
  return [
    'messages' => $errors,
    'fields' => array_values(array_unique($fields)),
  ];
}

function customOrdersUpsertCustomer(mysqli $conn, array $order): ?int
{
  $name = trim((string) ($order['customer_name'] ?? $order['shipping_name'] ?? ''));
  $email = trim((string) ($order['customer_email'] ?? $order['shipping_email'] ?? ''));
  $phone = trim((string) ($order['customer_phone'] ?? $order['shipping_phone'] ?? ''));
  if ($name === '' && $email === '' && $phone === '') {
    return null;
  }

  if ($email !== '') {
    $stmt = $conn->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
      return (int) $row['id'];
    }
  }

  $stmt = $conn->prepare('INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)');
  $stmt->bind_param('sss', $name, $email, $phone);
  $stmt->execute();
  $customerId = (int) $stmt->insert_id;
  $stmt->close();
  return $customerId;
}

function customOrdersExportToProduction(mysqli $conn, int $customOrderId, int $userId): int
{
  $order = customOrdersGetOrder($conn, $customOrderId);
  if (!$order) {
    throw new RuntimeException('Custom order not found.');
  }
  $validation = customOrdersExportValidation($order);
  if (!empty($validation['messages'])) {
    $exceptionMessage = implode(' ', $validation['messages']);
    throw new RuntimeException($exceptionMessage . '||FIELDS||' . json_encode($validation['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  $summary = $order['summary'];
  $sourceId = customOrdersGetSourceId($conn, 'CUSTOM');
  $customerId = customOrdersUpsertCustomer($conn, $order);

  $externalOrderId = (string) $order['internal_code'];
  $sourceMeta = [
    'custom_order_id' => (int) $order['id'],
    'source_channel' => $order['source_channel'],
    'social_platform' => $order['social_platform'],
    'social_handle' => $order['social_handle'],
    'bike' => [
      'brand' => $order['bike_brand'],
      'model' => $order['bike_model'],
      'year' => $order['bike_year'],
      'details' => $order['bike_details'],
    ],
    'deposit_revision_limit' => (int) $order['deposit_revision_limit'],
    'deposit_revision_used' => (int) $order['deposit_revision_used'],
    'deposit_total' => (float) $summary['deposit_total'],
    'upsell_subtotal' => (float) $summary['upsell_subtotal'],
    'bike_photo_urls' => $order['bike_photo_urls'],
    'reference_urls' => $order['reference_urls'],
  ];

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare('
      INSERT INTO orders
        (source_id, external_order_id, order_number, imported_at, order_date, status, currency, total, payment_method, shipping_method, note, source_meta, customer_id, manual_types_override, manual_types_updated_by, manual_types_updated_at)
      VALUES
        (?, ?, ?, NOW(), NOW(), \'NEW\', ?, ?, \'CUSTOM\', ?, ?, ?, ?, ?, ?, NOW())
    ');
    $orderNumber = (string) $order['official_order_number'];
    $currency = (string) ($order['currency'] ?? 'EUR');
    $total = (float) ($summary['gross_total'] ?? 0);
    $shippingMethod = trim((string) ($order['shipping_method'] ?? ''));
    $note = trim((string) ($order['customer_notes'] ?? ''));
    $sourceMetaJson = json_encode($sourceMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $types = (string) ($summary['types'] ?? '');
    $stmt->bind_param('isssdsssisi', $sourceId, $externalOrderId, $orderNumber, $currency, $total, $shippingMethod, $note, $sourceMetaJson, $customerId, $types, $userId);
    $stmt->execute();
    $productionOrderId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare('
      INSERT INTO order_addresses (order_id, type, name, company, company_id, street, city, zip, country, email, phone)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $billingType = 'BILLING';
    $billingName = trim((string) ($order['customer_name'] ?: $order['shipping_name']));
    $billingCompany = trim((string) ($order['shipping_company'] ?? ''));
    $emptyCompanyId = '';
    $billingStreet = trim((string) ($order['shipping_street'] ?? ''));
    $billingCity = trim((string) ($order['shipping_city'] ?? ''));
    $billingZip = trim((string) ($order['shipping_zip'] ?? ''));
    $billingCountry = (string) customOrdersNormalizeCountry((string) ($order['shipping_country'] ?? ''));
    $billingEmail = trim((string) ($order['customer_email'] ?: $order['shipping_email']));
    $billingPhone = trim((string) ($order['customer_phone'] ?: $order['shipping_phone']));
    $stmt->bind_param('issssssssss', $productionOrderId, $billingType, $billingName, $billingCompany, $emptyCompanyId, $billingStreet, $billingCity, $billingZip, $billingCountry, $billingEmail, $billingPhone);
    $stmt->execute();

    $shippingType = 'SHIPPING';
    $shippingName = trim((string) ($order['shipping_name'] ?? ''));
    $shippingCompany = trim((string) ($order['shipping_company'] ?? ''));
    $shippingStreet = trim((string) ($order['shipping_street'] ?? ''));
    $shippingCity = trim((string) ($order['shipping_city'] ?? ''));
    $shippingZip = trim((string) ($order['shipping_zip'] ?? ''));
    $shippingCountry = (string) customOrdersNormalizeCountry((string) ($order['shipping_country'] ?? ''));
    $shippingEmail = trim((string) ($order['shipping_email'] ?: $order['customer_email']));
    $shippingPhone = trim((string) ($order['shipping_phone'] ?: $order['customer_phone']));
    $stmt->bind_param('issssssssss', $productionOrderId, $shippingType, $shippingName, $shippingCompany, $emptyCompanyId, $shippingStreet, $shippingCity, $shippingZip, $shippingCountry, $shippingEmail, $shippingPhone);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('
      INSERT INTO order_items
        (order_id, line_no, sku, title, custom_label, item_type_code, qty, unit_price, options_json, internal_options_json, created_by, updated_by, updated_at, status)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), \'NEW\')
    ');
    foreach ($order['items'] as $item) {
      $lineNo = (int) $item['line_no'];
      $sku = trim((string) ($item['sku'] ?? 'MANUAL'));
      if ($sku === '') {
        $sku = 'MANUAL';
      }
      $title = (string) $item['title'];
      $label = (string) ($item['custom_label'] ?? '');
      $typeCode = strtoupper(trim((string) ($item['item_type_code'] ?? 'M')));
      if ($typeCode === '') {
        $typeCode = 'M';
      }
      $qty = (int) $item['qty'];
      $unitPrice = (float) $item['unit_price'];
      $optionsJson = (string) ($item['options_json'] ?? '{}');
      $internalOptionsJson = (string) ($item['internal_options_json'] ?? '{}');
      $stmt->bind_param('iissssidssii', $productionOrderId, $lineNo, $sku, $title, $label, $typeCode, $qty, $unitPrice, $optionsJson, $internalOptionsJson, $userId, $userId);
      $stmt->execute();
    }
    $stmt->close();

    sync_order_categories($conn, $productionOrderId);
    recalculateOrderWorkflow($conn, $productionOrderId);

    log_order_activity(
      $conn,
      $productionOrderId,
      $userId,
      'custom_order_exported',
      'custom_order',
      $customOrderId,
      [
        'custom_order_id' => $customOrderId,
        'official_order_number' => $orderNumber,
      ],
      'Exported from custom orders module'
    );

    $stmt = $conn->prepare('
      UPDATE custom_orders
      SET status = \'EXPORTED\',
          production_order_id = ?,
          exported_at = NOW(),
          exported_by = ?,
          updated_by = ?
      WHERE id = ?
    ');
    $stmt->bind_param('iiii', $productionOrderId, $userId, $userId, $customOrderId);
    $stmt->execute();
    $stmt->close();

    customOrdersLog($conn, $customOrderId, 'exported', $userId, ['production_order_id' => $productionOrderId], 'Exported to production orders');
    $conn->commit();
    return $productionOrderId;
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }
}
