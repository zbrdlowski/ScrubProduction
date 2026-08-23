<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/get_order_detail_product_spec_selects.php';
require_once dirname(__DIR__, 2) . '/includes/orders_status_helpers.php';
require_once dirname(__DIR__) . '/orders/department_config.php';

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

function customOrdersItemTypeToDepartment(string $type): string
{
  $type = strtoupper(trim($type));
  switch ($type) {
    case 'G':
      return 'G';
    case 'S':
      return 'S';
    case 'F':
      return 'F';
    case 'P':
    case 'T':
    case 'M':
      return 'P';
    default:
      return 'G';
  }
}

function customOrdersItemStatusDefinitions(mysqli $conn, array $item, bool $activeOnly = true): array
{
  return ordersGetItemStatusDefinitionsForItem($conn, $item, $activeOnly);
}

function customOrdersResolveItemStatus(mysqli $conn, array $item, ?string $requestedStatus = null): string
{
  $activeDefinitions = customOrdersItemStatusDefinitions($conn, $item, true);
  $allDefinitions = customOrdersItemStatusDefinitions($conn, $item, false);
  $requestedStatus = strtoupper(trim((string) $requestedStatus));

  if ($requestedStatus !== '' && isset($allDefinitions[$requestedStatus])) {
    return $requestedStatus;
  }

  // Custom orders historically stored DRAFT/NEW. The production catalogue calls
  // the actual initial draft state DRAFT_✗, so use it whenever it is available.
  if (isset($activeDefinitions['DRAFT_✗'])) {
    return 'DRAFT_✗';
  }

  return (string) (array_key_first($activeDefinitions) ?? ($requestedStatus !== '' ? $requestedStatus : 'NEW'));
}

function customOrdersDraftStatusDefinitions(mysqli $conn): array
{
  $drafts = [];
  foreach (['G', 'P', 'S', 'F'] as $department) {
    foreach (ordersGetItemStatusDefinitions($conn, $department, true) as $code => $meta) {
      $label = trim((string) ($meta['label'] ?? $code));
      if (strpos(strtoupper((string) $code), 'DRAFT') !== 0 && stripos($label, 'Draft') !== 0) {
        continue;
      }
      if (!isset($drafts[$code]) || (int) ($meta['sort_order'] ?? 0) < (int) ($drafts[$code]['sort_order'] ?? PHP_INT_MAX)) {
        $drafts[$code] = $meta;
      }
    }
  }

  uasort($drafts, static function (array $left, array $right): int {
    return [(int) ($left['sort_order'] ?? 0), (string) ($left['label'] ?? '')]
      <=> [(int) ($right['sort_order'] ?? 0), (string) ($right['label'] ?? '')];
  });
  return $drafts;
}

function customOrdersGraphicsSubcategoryLabels(): array
{
  return defined('GRAPHICS_SUBCAT_LABELS') && is_array(GRAPHICS_SUBCAT_LABELS)
    ? GRAPHICS_SUBCAT_LABELS
    : [];
}

function customOrdersNormalizeGraphicsSubcategory(?string $subcat): string
{
  $subcat = strtoupper(trim((string) $subcat));
  $labels = customOrdersGraphicsSubcategoryLabels();
  return isset($labels[$subcat]) ? $subcat : '';
}

function customOrdersGraphicsSubcategoryFromSpecKey(string $specKey, string $department): string
{
  if (strtoupper(trim($department)) !== 'G') {
    return '';
  }

  static $slugMap = null;
  if ($slugMap === null) {
    $slugMap = [];
    foreach (customOrdersGraphicsSubcategoryLabels() as $subCategoryCode => $_label) {
      $slugMap[(string) $subCategoryCode] = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $subCategoryCode));
    }
  }

  $normalizedSpecKey = strtolower(trim($specKey));
  foreach ($slugMap as $subCategoryCode => $subCategorySlug) {
    $prefix = 'graphics_' . $subCategorySlug . '_';
    if (strpos($normalizedSpecKey, $prefix) === 0) {
      return customOrdersNormalizeGraphicsSubcategory((string) $subCategoryCode);
    }
  }

  return '';
}

function customOrdersGraphicsSubcategoryFromItemData(?string $storedSubcat, ?string $customLabel, ?string $sku): string
{
  $storedSubcat = customOrdersNormalizeGraphicsSubcategory($storedSubcat);
  if ($storedSubcat !== '') {
    return $storedSubcat;
  }

  $detected = dept_get_graphics_subcat($customLabel, $sku);
  return customOrdersNormalizeGraphicsSubcategory($detected);
}

function customOrdersFilterSpecDefinitionsForBuilder(array $definitions, string $department, string $itemSubcat = ''): array
{
  $department = strtoupper(trim($department));
  $itemSubcat = customOrdersNormalizeGraphicsSubcategory($itemSubcat);
  $filtered = [];

  foreach ($definitions as $definition) {
    $fieldSubcategory = customOrdersGraphicsSubcategoryFromSpecKey(
      (string) ($definition['spec_key'] ?? ''),
      $department
    );
    $fieldAppliesToSubcategories = (int) ($definition['apply_to_subcategories'] ?? 0) === 1;

    if ($department === 'G' && $itemSubcat !== '') {
      if ($fieldSubcategory === '' && !$fieldAppliesToSubcategories) {
        continue;
      }
    }

    if ($fieldSubcategory !== '' && $fieldSubcategory !== $itemSubcat) {
      continue;
    }

    $filtered[] = $definition;
  }

  usort($filtered, static function (array $a, array $b): int {
    $ao = (int) ($a['field_sort_order'] ?? 999);
    $bo = (int) ($b['field_sort_order'] ?? 999);
    if ($ao !== $bo) {
      return $ao <=> $bo;
    }

    return strcmp((string) ($a['spec_key'] ?? ''), (string) ($b['spec_key'] ?? ''));
  });

  return $filtered;
}

function customOrdersTableExists(mysqli $conn, string $table, bool $refresh = false): bool
{
  static $cache = [];
  $table = trim($table);
  if ($table === '') {
    return false;
  }
  if ($refresh) {
    unset($cache[$table]);
  }
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  if (!$stmt) {
    return $cache[$table] = false;
  }
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $exists = (bool) $stmt->get_result()->fetch_row();
  $stmt->close();
  return $cache[$table] = $exists;
}

function customOrdersTableColumns(mysqli $conn, string $table, bool $refresh = false): array
{
  static $cache = [];
  $table = trim($table);
  if ($table === '') {
    return [];
  }
  if ($refresh) {
    unset($cache[$table]);
  }
  if (isset($cache[$table])) {
    return $cache[$table];
  }

  $columns = [];
  $stmt = $conn->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  if (!$stmt) {
    return $cache[$table] = [];
  }
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $name = trim((string) ($row['COLUMN_NAME'] ?? ''));
    if ($name !== '') {
      $columns[$name] = true;
    }
  }
  $stmt->close();
  return $cache[$table] = $columns;
}

function customOrdersEnsureSchema(mysqli $conn): void
{
  static $done = false;
  if ($done) {
    return;
  }

  if (!customOrdersTableExists($conn, 'custom_order_notes')) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS `custom_order_notes` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `custom_order_id` bigint(20) NOT NULL,
        `note_type` varchar(32) NOT NULL DEFAULT 'INTERNAL',
        `note_body` text NOT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `ix_custom_order_notes_order` (`custom_order_id`),
        CONSTRAINT `fk_custom_order_notes_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  }

  if (!customOrdersTableExists($conn, 'custom_order_photos')) {
    if ($conn->query("
      CREATE TABLE IF NOT EXISTS `custom_order_photos` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `custom_order_id` bigint(20) NOT NULL,
        `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
        `file_size` int(10) unsigned NOT NULL DEFAULT 0,
        `width` int(10) unsigned DEFAULT NULL,
        `height` int(10) unsigned DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `deleted_at` datetime DEFAULT NULL,
        `deleted_by` int(11) DEFAULT NULL,
        `production_photo_id` int(10) unsigned DEFAULT NULL,
        `exported_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_custom_order_photos_order` (`custom_order_id`),
        KEY `ix_custom_order_photos_deleted` (`deleted_at`),
        KEY `ix_custom_order_photos_production` (`production_photo_id`),
        CONSTRAINT `fk_custom_order_photos_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ")) {
      customOrdersTableExists($conn, 'custom_order_photos', true);
    }
  }

  $columns = customOrdersTableColumns($conn, 'custom_orders');
  if (!$columns) {
    $done = true;
    return;
  }

  $requiredColumns = [
    'payment_method' => "ADD COLUMN `payment_method` varchar(128) DEFAULT NULL AFTER `rider_number`",
    'billing_name' => "ADD COLUMN `billing_name` varchar(255) DEFAULT NULL AFTER `payment_method`",
    'billing_company' => "ADD COLUMN `billing_company` varchar(255) DEFAULT NULL AFTER `billing_name`",
    'billing_company_id' => "ADD COLUMN `billing_company_id` varchar(128) DEFAULT NULL AFTER `billing_company`",
    'billing_street' => "ADD COLUMN `billing_street` varchar(255) DEFAULT NULL AFTER `billing_company_id`",
    'billing_city' => "ADD COLUMN `billing_city` varchar(128) DEFAULT NULL AFTER `billing_street`",
    'billing_zip' => "ADD COLUMN `billing_zip` varchar(32) DEFAULT NULL AFTER `billing_city`",
    'billing_country' => "ADD COLUMN `billing_country` varchar(2) DEFAULT NULL AFTER `billing_zip`",
    'billing_email' => "ADD COLUMN `billing_email` varchar(255) DEFAULT NULL AFTER `billing_country`",
    'billing_phone' => "ADD COLUMN `billing_phone` varchar(64) DEFAULT NULL AFTER `billing_email`",
    'shipping_company_id' => "ADD COLUMN `shipping_company_id` varchar(128) DEFAULT NULL AFTER `shipping_company`",
  ];

  $alterParts = [];
  foreach ($requiredColumns as $column => $sql) {
    if (!isset($columns[$column])) {
      $alterParts[] = $sql;
    }
  }

  if ($alterParts) {
    $sql = 'ALTER TABLE `custom_orders` ' . implode(",\n  ", $alterParts);
    if ($conn->query($sql)) {
      customOrdersTableColumns($conn, 'custom_orders', true);
    }
  }

  $done = true;
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

function customOrdersActivityActionLabel(string $action): string
{
  $map = [
    'created' => 'Created',
    'header_updated' => 'Order updated',
    'item_added' => 'Item added',
    'item_updated' => 'Item updated',
    'item_deleted' => 'Item deleted',
    'payment_added' => 'Payment added',
    'payment_deleted' => 'Payment deleted',
    'followup_added' => 'Follow-up added',
    'note_added' => 'Note added',
    'owner_assigned' => 'Owner changed',
    'official_number_assigned' => 'Official number assigned',
    'exported' => 'Exported',
  ];

  $normalized = strtolower(trim($action));
  if (isset($map[$normalized])) {
    return $map[$normalized];
  }

  return ucwords(str_replace('_', ' ', $normalized));
}

function customOrdersActivityFieldLabels(): array
{
  return [
    'status' => 'Status',
    'complexity_level' => 'Complexity',
    'source_channel' => 'Source channel',
    'social_platform' => 'Communication platform',
    'social_handle' => 'Social handle',
    'customer_name' => 'Customer name',
    'customer_email' => 'Customer email',
    'customer_phone' => 'Customer phone',
    'customer_country' => 'Customer country',
    'bike_brand' => 'Bike brand',
    'bike_model' => 'Bike model',
    'bike_year' => 'Bike year',
    'bike_details' => 'Bike details',
    'rider_name' => 'Rider name',
    'rider_number' => 'Rider number',
    'payment_method' => 'Payment method',
    'billing_name' => 'Billing name',
    'billing_company' => 'Billing company',
    'billing_company_id' => 'Billing company ID',
    'billing_street' => 'Billing street',
    'billing_city' => 'Billing city',
    'billing_zip' => 'Billing ZIP',
    'billing_country' => 'Billing country',
    'billing_email' => 'Billing email',
    'billing_phone' => 'Billing phone',
    'shipping_name' => 'Shipping name',
    'shipping_company' => 'Shipping company',
    'shipping_company_id' => 'Shipping company ID',
    'shipping_street' => 'Shipping street',
    'shipping_city' => 'Shipping city',
    'shipping_zip' => 'Shipping ZIP',
    'shipping_country' => 'Shipping country',
    'shipping_email' => 'Shipping email',
    'shipping_phone' => 'Shipping phone',
    'shipping_method' => 'Shipping method',
    'shipping_price' => 'Shipping price',
    'currency' => 'Currency',
    'deposit_revision_limit' => 'Revisions included',
    'deposit_revision_used' => 'Revisions used',
    'graphics_brief' => 'Graphics brief',
    'customer_notes' => 'Customer notes',
    'internal_notes' => 'Internal notes',
    'bike_photo_urls' => 'Bike photo URLs',
    'reference_urls' => 'Reference URLs',
    'last_contact_at' => 'Last contact',
    'next_followup_at' => 'Next follow-up',
    'dead_order_flag' => 'Dead order',
    'item_type_code' => 'Item type',
    'sku' => 'SKU',
    'title' => 'Title',
    'custom_label' => 'Custom label',
    'qty' => 'Qty',
    'unit_price' => 'Unit price',
    'is_upsell' => 'Upsell',
    'upsell_source' => 'Upsell source',
    'status' => 'Action status',
  ];
}

function customOrdersActivityNormalizeValue($value): string
{
  if ($value === null) {
    return '';
  }
  if (is_bool($value)) {
    return $value ? '1' : '0';
  }
  if (is_int($value) || is_float($value)) {
    return (string) $value;
  }
  if (is_array($value)) {
    ksort($value);
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
  }
  return trim((string) $value);
}

function customOrdersActivityDisplayValue(string $field, $value): string
{
  if ($value === null || $value === '') {
    return 'empty';
  }

  if (in_array($field, ['dead_order_flag', 'is_upsell'], true)) {
    return ((int) $value) === 1 ? 'Yes' : 'No';
  }

  if (in_array($field, ['shipping_price', 'unit_price'], true) && is_numeric((string) $value)) {
    return number_format((float) $value, 2, '.', '');
  }

  return trim((string) $value);
}

function customOrdersActivityCollectChanges(array $before, array $after, array $fields): array
{
  $labels = customOrdersActivityFieldLabels();
  $changes = [];

  foreach ($fields as $field) {
    $beforeValue = $before[$field] ?? null;
    $afterValue = $after[$field] ?? null;
    if (customOrdersActivityNormalizeValue($beforeValue) === customOrdersActivityNormalizeValue($afterValue)) {
      continue;
    }

    $changes[] = [
      'field' => $field,
      'label' => $labels[$field] ?? ucwords(str_replace('_', ' ', $field)),
      'from' => customOrdersActivityDisplayValue($field, $beforeValue),
      'to' => customOrdersActivityDisplayValue($field, $afterValue),
    ];
  }

  return $changes;
}

function customOrdersActivityPayload(array $activity): array
{
  $payload = $activity['payload'] ?? [];
  if (is_array($payload)) {
    return $payload;
  }

  $decoded = json_decode((string) $payload, true);
  return is_array($decoded) ? $decoded : [];
}

function customOrdersActivityDetail(array $activity): string
{
  $payload = customOrdersActivityPayload($activity);
  $action = strtolower(trim((string) ($activity['action'] ?? '')));
  $note = trim((string) ($activity['note'] ?? ''));

  if (!empty($payload['changes']) && is_array($payload['changes'])) {
    $parts = [];
    foreach ($payload['changes'] as $change) {
      $label = trim((string) ($change['label'] ?? $change['field'] ?? 'Change'));
      $from = trim((string) ($change['from'] ?? 'empty'));
      $to = trim((string) ($change['to'] ?? 'empty'));
      $parts[] = $label . ': ' . $from . ' -> ' . $to;
    }
    if ($parts) {
      return implode(' | ', $parts);
    }
  }

  if ($action === 'item_added' || $action === 'item_updated') {
    $parts = [];
    if (!empty($payload['title'])) {
      $parts[] = 'Title: ' . trim((string) $payload['title']);
    }
    if (!empty($payload['item_type_code'])) {
      $parts[] = 'Type: ' . trim((string) $payload['item_type_code']);
    }
    if (isset($payload['qty'])) {
      $parts[] = 'Qty: ' . (int) $payload['qty'];
    }
    if (isset($payload['unit_price'])) {
      $parts[] = 'Price: ' . number_format((float) $payload['unit_price'], 2, '.', '');
    }
    if ($parts) {
      return implode(' | ', $parts);
    }
  }

  if ($action === 'item_deleted' && !empty($payload['title'])) {
    return 'Deleted: ' . trim((string) $payload['title']);
  }

  if ($action === 'payment_added') {
    $parts = [];
    if (!empty($payload['kind'])) {
      $parts[] = trim((string) $payload['kind']);
    }
    if (isset($payload['amount'])) {
      $parts[] = number_format((float) $payload['amount'], 2, '.', '') . ' ' . trim((string) ($payload['currency'] ?? ''));
    }
    if (!empty($payload['note'])) {
      $parts[] = trim((string) $payload['note']);
    }
    if ($parts) {
      return implode(' | ', array_filter($parts));
    }
  }

  if ($action === 'payment_deleted' && isset($payload['amount'])) {
    return 'Deleted payment: ' . number_format((float) $payload['amount'], 2, '.', '') . ' ' . trim((string) ($payload['currency'] ?? ''));
  }

  if ($action === 'followup_added') {
    $parts = [];
    if (!empty($payload['channel'])) {
      $parts[] = 'Channel: ' . trim((string) $payload['channel']);
    }
    if (!empty($payload['note'])) {
      $parts[] = trim((string) $payload['note']);
    }
    if ($parts) {
      return implode(' | ', $parts);
    }
  }

  if ($action === 'official_number_assigned' && !empty($payload['official_order_number'])) {
    return trim((string) $payload['official_order_number']);
  }

  if ($action === 'exported' && !empty($payload['production_order_id'])) {
    return 'Production order #' . (int) $payload['production_order_id'];
  }

  return $note !== '' ? $note : '-';
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

function customOrdersItemPayloadFromPost(mysqli $conn, string $type = 'G'): array
{
  $department = customOrdersItemTypeToDepartment($type);
  $selectedSubcat = '';
  if ($department === 'G') {
    $selectedSubcat = customOrdersNormalizeGraphicsSubcategory((string) ($_POST['graphics_subcategory'] ?? ''));
    if ($selectedSubcat === '') {
      $selectedSubcat = customOrdersGraphicsSubcategoryFromItemData(
        null,
        (string) ($_POST['custom_label'] ?? ''),
        (string) ($_POST['sku'] ?? '')
      );
    }
  }

  $definitions = customOrdersFilterSpecDefinitionsForBuilder(
    productSpecFieldDefinitions($conn, $department),
    $department,
    $selectedSubcat
  );

  $options = [
    'category_info' => trim((string) ($_POST['category_info'] ?? '')),
  ];

  foreach ([
    'category_brand' => 'category_brand',
    'category_model' => 'category_model',
    'category_year_range' => 'category_year_range',
    'category_modelcode' => 'category_modelcode',
  ] as $postKey => $optionKey) {
    $value = trim((string) ($_POST[$postKey] ?? ''));
    if ($value !== '') {
      $options[$optionKey] = $value;
    }
  }

  foreach ($definitions as $definition) {
    $specKey = trim((string) ($definition['spec_key'] ?? ''));
    $sourceKey = trim((string) ($definition['source_key'] ?? ''));
    if ($specKey === '' || $sourceKey === '') {
      continue;
    }

    $postKey = 'spec_' . $specKey;
    if (!array_key_exists($postKey, $_POST)) {
      continue;
    }

    $value = trim((string) $_POST[$postKey]);
    if ($value !== '') {
      $options[$sourceKey] = $value;
    }
  }

  $legacyMap = [
    'option_name' => 'name',
    'option_number' => 'number',
    'option_material' => 'base-material',
    'option_finish' => 'graphics-finish',
    'option_grip' => 'grip',
    'option_tr_swingarms' => 'tr-swingarms',
    'option_patch_style' => 'patch-style',
    'option_waterproof_seams' => 'waterproof-seams',
    'option_enduro_pocket' => 'enduro-pocket',
    'option_side_brand_patches' => 'side-brand-patches',
    'option_note' => 'note',
    'option_printer' => 'printer',
    'option_my_item_note' => 'my-item-note',
  ];
  foreach ($legacyMap as $postKey => $sourceKey) {
    if (isset($options[$sourceKey])) {
      continue;
    }
    $value = trim((string) ($_POST[$postKey] ?? ''));
    if ($value !== '') {
      $options[$sourceKey] = $value;
    }
  }

  $options = array_filter($options, static function ($value) {
    return $value !== '';
  });
  $internal = [
    '_custom_source' => 'custom_orders_module',
  ];
  if ($department === 'G' && $selectedSubcat !== '') {
    $internal['_subcat'] = $selectedSubcat;
  }

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
  $res = $conn->query("
    SELECT
      coa.*,
      TRIM(CONCAT_WS(' ', e.firstname, e.lastname)) AS actor_name
    FROM custom_order_activity coa
    LEFT JOIN employees e ON e.id = coa.actor_employee_id
    WHERE coa.custom_order_id = " . (int) $orderId . "
    ORDER BY coa.created_at DESC, coa.id DESC
    LIMIT 30
  ");
  while ($row = $res->fetch_assoc()) {
    $order['activity'][] = $row;
  }

  $order['notes'] = [];
  if (customOrdersTableExists($conn, 'custom_order_notes')) {
    $res = $conn->query('SELECT * FROM custom_order_notes WHERE custom_order_id = ' . (int) $orderId . ' ORDER BY created_at DESC, id DESC');
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $order['notes'][] = $row;
      }
    }
  }

  $order['photos'] = [];
  if (customOrdersTableExists($conn, 'custom_order_photos')) {
    $stmt = $conn->prepare('
      SELECT id, file_name, original_name, file_path, mime_type, file_size, width, height, created_at, production_photo_id
      FROM custom_order_photos
      WHERE custom_order_id = ? AND deleted_at IS NULL
      ORDER BY id DESC
    ');
    if ($stmt) {
      $stmt->bind_param('i', $orderId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $order['photos'][] = $row;
      }
      $stmt->close();
    }
  }

  $order['production_overview'] = customOrdersGetProductionOverview($conn, (int) ($order['production_order_id'] ?? 0));

  $order['summary'] = customOrdersComputeSummary($order);
  return $order;
}

function customOrdersGetProductionOverview(mysqli $conn, int $productionOrderId): array
{
  $overview = [
    'order' => null,
    'billing' => null,
    'shipping' => null,
    'invoices' => [],
    'tracking' => [],
  ];
  if ($productionOrderId <= 0) {
    return $overview;
  }

  $stmt = $conn->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('i', $productionOrderId);
    $stmt->execute();
    $overview['order'] = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
  }

  if (customOrdersTableExists($conn, 'order_addresses')) {
    $stmt = $conn->prepare('SELECT * FROM order_addresses WHERE order_id = ? ORDER BY id ASC');
    if ($stmt) {
      $stmt->bind_param('i', $productionOrderId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $type = strtoupper(trim((string) ($row['type'] ?? '')));
        if ($type === 'BILLING' && $overview['billing'] === null) {
          $overview['billing'] = $row;
        }
        if ($type === 'SHIPPING' && $overview['shipping'] === null) {
          $overview['shipping'] = $row;
        }
      }
      $stmt->close();
    }
  }

  if (customOrdersTableExists($conn, 'order_invoices')) {
    $stmt = $conn->prepare('SELECT id, invoice_number FROM order_invoices WHERE order_id = ? AND deleted_at IS NULL ORDER BY id DESC');
    if ($stmt) {
      $stmt->bind_param('i', $productionOrderId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $overview['invoices'][] = $row;
      }
      $stmt->close();
    }
  }

  if (customOrdersTableExists($conn, 'order_tracking_numbers')) {
    $stmt = $conn->prepare('SELECT id, tracking_number, carrier FROM order_tracking_numbers WHERE order_id = ? AND deleted_at IS NULL ORDER BY id DESC');
    if ($stmt) {
      $stmt->bind_param('i', $productionOrderId);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $overview['tracking'][] = $row;
      }
      $stmt->close();
    }
  }

  return $overview;
}

function customOrdersAddNote(mysqli $conn, int $orderId, string $noteType, string $noteBody, int $userId): void
{
  $noteType = strtoupper(trim($noteType));
  if ($orderId <= 0 || $noteBody === '' || !customOrdersTableExists($conn, 'custom_order_notes')) {
    return;
  }

  $allowedTypes = ['CUSTOMER', 'INTERNAL', 'REVISION'];
  if (!in_array($noteType, $allowedTypes, true)) {
    $noteType = 'INTERNAL';
  }

  $stmt = $conn->prepare('INSERT INTO custom_order_notes (custom_order_id, note_type, note_body, created_by) VALUES (?, ?, ?, ?)');
  if (!$stmt) {
    return;
  }
  $stmt->bind_param('issi', $orderId, $noteType, $noteBody, $userId);
  $stmt->execute();
  $stmt->close();

  customOrdersLog($conn, $orderId, 'note_added', $userId, ['note_type' => $noteType], 'Lead note appended');
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
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
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
      $productionItemStatus = customOrdersResolveItemStatus($conn, $item, (string) ($item['status'] ?? ''));
      $stmt->bind_param('iissssidssiis', $productionOrderId, $lineNo, $sku, $title, $label, $typeCode, $qty, $unitPrice, $optionsJson, $internalOptionsJson, $userId, $userId, $productionItemStatus);
      $stmt->execute();
    }
    $stmt->close();

    sync_order_categories($conn, $productionOrderId);
    recalculateOrderWorkflow($conn, $productionOrderId);

    if (customOrdersTableExists($conn, 'custom_order_photos') && customOrdersTableExists($conn, 'order_photos')) {
      $photoSelect = $conn->prepare('
        SELECT id, file_name, original_name, file_path, mime_type, file_size, width, height, created_by
        FROM custom_order_photos
        WHERE custom_order_id = ? AND deleted_at IS NULL AND production_photo_id IS NULL
        ORDER BY id ASC
      ');
      $photoInsert = $conn->prepare('
        INSERT INTO order_photos
          (order_id, file_name, original_name, file_path, mime_type, file_size, width, height, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ');
      $photoLink = $conn->prepare('
        UPDATE custom_order_photos
        SET production_photo_id = ?, exported_at = NOW()
        WHERE id = ? AND custom_order_id = ?
      ');
      if (!$photoSelect || !$photoInsert || !$photoLink) {
        throw new RuntimeException('Custom order photos could not be prepared for export.');
      }
      $photoSelect->bind_param('i', $customOrderId);
      $photoSelect->execute();
      $photoResult = $photoSelect->get_result();
      while ($photo = $photoResult->fetch_assoc()) {
        $photoId = (int) $photo['id'];
        $fileName = (string) $photo['file_name'];
        $originalName = (string) $photo['original_name'];
        $filePath = (string) $photo['file_path'];
        $mimeType = (string) $photo['mime_type'];
        $fileSize = (int) $photo['file_size'];
        $width = (int) $photo['width'];
        $height = (int) $photo['height'];
        $createdBy = (int) ($photo['created_by'] ?? $userId);
        $photoInsert->bind_param('issssiiii', $productionOrderId, $fileName, $originalName, $filePath, $mimeType, $fileSize, $width, $height, $createdBy);
        $photoInsert->execute();
        $productionPhotoId = (int) $photoInsert->insert_id;
        $photoLink->bind_param('iii', $productionPhotoId, $photoId, $customOrderId);
        $photoLink->execute();
      }
      $photoSelect->close();
      $photoInsert->close();
      $photoLink->close();
    }

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
