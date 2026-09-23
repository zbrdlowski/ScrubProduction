<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/get_order_detail_product_spec_selects.php';
require_once dirname(__DIR__, 2) . '/includes/orders_status_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/orders_plastics_gate_helpers.php';
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

function customOrdersRedirectContextParams(): array
{
  $preservedKeys = [
    'tab',
    'draft_status',
    'q',
    'difficulty',
    'owner',
    'country',
    'source',
    'payment',
    'shipping',
    'item_type',
    'date_from',
    'date_to',
    'help_lang',
  ];

  $sources = [];
  $refererQuery = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
  if (is_string($refererQuery) && $refererQuery !== '') {
    $refererParams = [];
    parse_str($refererQuery, $refererParams);
    if (is_array($refererParams)) {
      $sources[] = $refererParams;
    }
  }
  $sources[] = $_GET;
  $sources[] = $_POST;

  $params = [];
  foreach ($sources as $source) {
    if (!is_array($source)) {
      continue;
    }
    foreach ($preservedKeys as $key) {
      if (!array_key_exists($key, $source) || is_array($source[$key])) {
        continue;
      }
      $value = trim((string) $source[$key]);
      if ($value === '') {
        unset($params[$key]);
        continue;
      }

      switch ($key) {
        case 'tab':
          $value = strtolower($value);
          if ($value === 'all' || !preg_match('/^[a-z0-9_]+$/', $value)) {
            unset($params[$key]);
            continue 2;
          }
          break;
        case 'difficulty':
        case 'owner':
          $value = (string) max(0, (int) $value);
          if ($value === '0') {
            unset($params[$key]);
            continue 2;
          }
          break;
        case 'country':
        case 'item_type':
        case 'draft_status':
          $value = strtoupper($value);
          break;
        case 'date_from':
        case 'date_to':
          if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            unset($params[$key]);
            continue 2;
          }
          break;
        case 'help_lang':
          $value = strtolower($value);
          if (!in_array($value, ['sk', 'en'], true)) {
            unset($params[$key]);
            continue 2;
          }
          break;
      }

      $params[$key] = $value;
    }
  }

  return $params;
}

function customOrdersRedirect(int $orderId = 0, int $focusNoteId = 0): void
{
  $params = ['page' => 'custom_orders'] + customOrdersRedirectContextParams();
  if ($orderId > 0) {
    $params['custom_order_id'] = (string) $orderId;
  }
  if ($orderId > 0 && $focusNoteId > 0) {
    $params['focus_note_id'] = (string) $focusNoteId;
  }

  header('Location: ../../index.php?' . http_build_query($params));
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
    'CZ' => 'CZ',
    'SK' => 'SK',
    'DE' => 'DE',
    'AT' => 'AT',
    'FR' => 'FR',
    'IT' => 'IT',
    'CA' => 'CA',
    'US' => 'US',
    'CH' => 'CH',
    'USA' => 'US',
    'UNITED STATES' => 'US',
    'UNITED STATES OF AMERICA' => 'US',
    'UNITED KINGDOM' => 'GB',
    'GREAT BRITAIN' => 'GB',
    'GERMANY' => 'DE',
    'SLOVAKIA' => 'SK',
    'SLOVAK REPUBLIC' => 'SK',
    'CZECHIA' => 'CZ',
    'CZECH REPUBLIC' => 'CZ',
    'MEXICO' => 'MX',
    'AUSTRIA' => 'AT',
    'POLAND' => 'PL',
    'CANADA' => 'CA',
    'AUSTRALIA' => 'AU',
    'FRANCE' => 'FR',
    'ITALY' => 'IT',
    'SWITZERLAND' => 'CH',
    'NETHERLANDS' => 'NL',
    'THE NETHERLANDS' => 'NL',
    'BELGIUM' => 'BE',
    'SPAIN' => 'ES',
  ];

  if (isset($map[$country])) {
    return $map[$country];
  }

  return preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
}

function customOrdersNormalizeState(?string $state): ?string
{
  $state = strtoupper(trim((string) $state));
  if ($state === '') {
    return null;
  }
  return preg_match('/^[A-Z0-9]{2,3}$/', $state) ? $state : null;
}

function customOrdersCountryRequiresState(?string $country): bool
{
  return in_array(customOrdersNormalizeCountry($country), ['US', 'CA', 'AU'], true);
}
function customOrdersOrderStatuses(): array
{
  return [
    'LEAD' => 'Lead',
    'DEPOSIT_PAID' => 'Deposit Paid',
    'DRAFT_X' => 'Draft ✗',
    'DRAFT_AD_CHANGES' => 'Draft Ad.changes',
    'DRAFT_READY' => 'Draft Ready',
    'DRAFT_READY_NOTES' => 'Draft Ready + notes',
    'DRAFT_SENT' => 'Draft Sent',
    'CONTACT_CUSTOMER' => 'Contact Customer',
    'CUSTOMER_CONTACTED' => 'Customer Contacted',
    'EXPORTED' => 'Exported',

    // Legacy statuses can still exist on older custom orders.
    'DEPOSIT_PENDING' => 'Deposit Pending',
    'IN_PROGRESS' => 'In Progress',
    'READY_TO_EXPORT' => 'Ready To Export',
    'CANCELLED' => 'Cancelled',
    'DEAD' => 'Dead Order',
  ];
}

function customOrdersCustomerServiceOnlyStatusCodes(): array
{
  return ['DEPOSIT_PAID', 'CONTACT_CUSTOMER', 'CUSTOMER_CONTACTED', 'DEAD'];
}

function customOrdersWorkerEditableStatusCodes(): array
{
  return ['LEAD', 'DRAFT_X', 'DRAFT_AD_CHANGES', 'DRAFT_READY', 'DRAFT_READY_NOTES', 'DRAFT_SENT'];
}

function customOrdersCanWorkerSetOrderStatus(string $status): bool
{
  $status = strtoupper(trim($status));
  return in_array($status, customOrdersWorkerEditableStatusCodes(), true)
    && !in_array($status, customOrdersCustomerServiceOnlyStatusCodes(), true);
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
        `parent_note_id` bigint(20) DEFAULT NULL,
        `note_type` varchar(32) NOT NULL DEFAULT 'INTERNAL',
        `note_body` text NOT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_by` int(11) DEFAULT NULL,
        `updated_at` datetime DEFAULT NULL,
        `deleted_by` int(11) DEFAULT NULL,
        `deleted_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_custom_order_notes_order` (`custom_order_id`),
        KEY `ix_custom_order_notes_parent` (`parent_note_id`),
        CONSTRAINT `fk_custom_order_notes_order` FOREIGN KEY (`custom_order_id`) REFERENCES `custom_orders` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_custom_order_notes_parent` FOREIGN KEY (`parent_note_id`) REFERENCES `custom_order_notes` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  }

  $noteColumns = customOrdersTableColumns($conn, 'custom_order_notes');
  if ($noteColumns && !isset($noteColumns['parent_note_id'])) {
    if ($conn->query("
      ALTER TABLE `custom_order_notes`
        ADD COLUMN `parent_note_id` bigint(20) DEFAULT NULL AFTER `custom_order_id`
    ")) {
      customOrdersTableColumns($conn, 'custom_order_notes', true);

      // Keep the upgrade compatible with older Synology MariaDB builds. The
      // application validates the parent note itself, so a self-referencing
      // foreign key is not required for replies to work.
      $conn->query("
        ALTER TABLE `custom_order_notes`
          ADD KEY `ix_custom_order_notes_parent` (`parent_note_id`)
      ");
    }
  }

  $noteColumns = customOrdersTableColumns($conn, 'custom_order_notes', true);
  $noteAuditColumns = [
    'updated_by' => "ADD COLUMN `updated_by` int(11) DEFAULT NULL AFTER `created_at`",
    'updated_at' => "ADD COLUMN `updated_at` datetime DEFAULT NULL AFTER `updated_by`",
    'deleted_by' => "ADD COLUMN `deleted_by` int(11) DEFAULT NULL AFTER `updated_at`",
    'deleted_at' => "ADD COLUMN `deleted_at` datetime DEFAULT NULL AFTER `deleted_by`",
  ];
  foreach ($noteAuditColumns as $columnName => $columnSql) {
    if (!isset($noteColumns[$columnName]) && $conn->query("ALTER TABLE `custom_order_notes` {$columnSql}")) {
      $noteColumns[$columnName] = true;
    }
  }
  customOrdersTableColumns($conn, 'custom_order_notes', true);

  if (!customOrdersTableExists($conn, 'custom_order_note_revisions')) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS `custom_order_note_revisions` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `note_id` bigint(20) NOT NULL,
        `custom_order_id` bigint(20) NOT NULL,
        `revision_action` varchar(16) NOT NULL,
        `old_body` text NULL,
        `new_body` text NULL,
        `actor_employee_id` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `ix_custom_note_revisions_note` (`note_id`),
        KEY `ix_custom_note_revisions_order` (`custom_order_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    customOrdersTableExists($conn, 'custom_order_note_revisions', true);
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

  if (!customOrdersTableExists($conn, 'custom_order_item_assignments')) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS `custom_order_item_assignments` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `custom_order_id` bigint(20) NOT NULL,
        `custom_order_item_id` bigint(20) NOT NULL,
        `employee_id` int(11) NOT NULL,
        `assigned_by` int(11) DEFAULT NULL,
        `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_custom_order_item_assignment_item` (`custom_order_item_id`),
        KEY `ix_custom_order_item_assignments_order` (`custom_order_id`),
        KEY `ix_custom_order_item_assignments_employee` (`employee_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    customOrdersTableExists($conn, 'custom_order_item_assignments', true);
  }

  if (!customOrdersTableExists($conn, 'custom_order_assignments')) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS `custom_order_assignments` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `custom_order_id` bigint(20) NOT NULL,
        `employee_id` int(11) NOT NULL,
        `assigned_by` int(11) DEFAULT NULL,
        `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `ux_custom_order_assignment_order` (`custom_order_id`),
        KEY `ix_custom_order_assignments_employee` (`employee_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    customOrdersTableExists($conn, 'custom_order_assignments', true);
  }

  if (customOrdersTableExists($conn, 'order_addresses')) {
    $addressColumns = customOrdersTableColumns($conn, 'order_addresses');
    if ($addressColumns && !isset($addressColumns['state'])) {
      if ($conn->query("ALTER TABLE `order_addresses` ADD COLUMN `state` varchar(32) DEFAULT NULL AFTER `country`")) {
        customOrdersTableColumns($conn, 'order_addresses', true);
      }
    }
  }

  if (customOrdersTableExists($conn, 'custom_order_payments')) {
    $paymentColumns = customOrdersTableColumns($conn, 'custom_order_payments');
    if ($paymentColumns && !isset($paymentColumns['invoice_number'])) {
      if ($conn->query("ALTER TABLE `custom_order_payments` ADD COLUMN `invoice_number` varchar(128) DEFAULT NULL AFTER `received_at`")) {
        customOrdersTableColumns($conn, 'custom_order_payments', true);
      }
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
    'billing_state' => "ADD COLUMN `billing_state` varchar(3) DEFAULT NULL AFTER `billing_country`",
    'billing_email' => "ADD COLUMN `billing_email` varchar(255) DEFAULT NULL AFTER `billing_state`",
    'billing_phone' => "ADD COLUMN `billing_phone` varchar(64) DEFAULT NULL AFTER `billing_email`",
    'shipping_company_id' => "ADD COLUMN `shipping_company_id` varchar(128) DEFAULT NULL AFTER `shipping_company`",
    'shipping_state' => "ADD COLUMN `shipping_state` varchar(3) DEFAULT NULL AFTER `shipping_country`",
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
    'item_taken' => 'Item taken',
    'payment_added' => 'Payment added',
    'payment_updated' => 'Payment updated',
    'payment_deleted' => 'Payment deleted',
    'followup_added' => 'Follow-up added',
    'note_added' => 'Note added',
    'note_reply_added' => 'Note reply added',
    'note_edited' => 'Note edited',
    'note_deleted' => 'Note deleted',
    'owner_assigned' => 'Owner changed',
    'order_taken' => 'Order taken',
    'order_assignment_removed' => 'Order assignment removed',
    'official_number_assigned' => 'Official number assigned',
    'duplicated_from' => 'Duplicated from',
    'duplicated_to' => 'Duplicated to',
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
    'billing_state' => 'Billing state',
    'billing_email' => 'Billing email',
    'billing_phone' => 'Billing phone',
    'shipping_name' => 'Shipping name',
    'shipping_company' => 'Shipping company',
    'shipping_company_id' => 'Shipping company ID',
    'shipping_street' => 'Shipping street',
    'shipping_city' => 'Shipping city',
    'shipping_zip' => 'Shipping ZIP',
    'shipping_country' => 'Shipping country',
    'shipping_state' => 'Shipping state',
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
    'category_info' => 'Category Info',
    'category_brand' => 'Category brand',
    'category_model' => 'Category model',
    'category_year_range' => 'Category year',
    'category_modelcode' => 'Model code',
  ];
}

function customOrdersPaymentKindLabel(string $kind): string
{
  $kind = strtoupper(trim($kind));
  $labels = customOrdersPaymentKinds();
  if (isset($labels[$kind])) {
    return $labels[$kind];
  }

  return $kind !== '' ? ucwords(strtolower(str_replace('_', ' ', $kind))) : 'Payment';
}

function customOrdersPaymentLineDateLabel(?string $receivedAt): string
{
  $receivedAt = trim((string) $receivedAt);
  if ($receivedAt === '') {
    return '';
  }

  $timestamp = strtotime($receivedAt);
  return $timestamp !== false ? date('d.m.Y', $timestamp) : '';
}

function customOrdersPaymentBreakdownLines(array $payments): array
{
  $lines = [];
  foreach ($payments as $payment) {
    if (!is_array($payment)) {
      continue;
    }

    $amount = (float) ($payment['amount'] ?? 0);
    if (abs($amount) < 0.005) {
      continue;
    }

    $kind = strtoupper(trim((string) ($payment['payment_kind'] ?? $payment['kind'] ?? '')));
    $signedAmount = $kind === 'REFUND' ? -abs($amount) : $amount;
    $receivedAt = trim((string) ($payment['received_at'] ?? ''));
    $dateLabel = customOrdersPaymentLineDateLabel($receivedAt);
    $label = customOrdersPaymentKindLabel($kind);
    if ($dateLabel !== '') {
      $label .= ' ' . $dateLabel;
    }

    $lines[] = [
      'id' => (int) ($payment['id'] ?? 0),
      'kind' => $kind !== '' ? $kind : 'PAYMENT',
      'label' => $label,
      'amount' => $signedAmount,
      'currency' => strtoupper(trim((string) ($payment['currency'] ?? ''))),
      'received_at' => $receivedAt,
      'invoice_number' => trim((string) ($payment['invoice_number'] ?? '')),
      'paypal_transaction_id' => trim((string) ($payment['paypal_transaction_id'] ?? '')),
    ];
  }

  usort($lines, static function (array $a, array $b): int {
    $timeA = trim((string) ($a['received_at'] ?? '')) !== '' ? strtotime((string) $a['received_at']) : false;
    $timeB = trim((string) ($b['received_at'] ?? '')) !== '' ? strtotime((string) $b['received_at']) : false;
    $sortA = $timeA !== false ? $timeA : PHP_INT_MAX;
    $sortB = $timeB !== false ? $timeB : PHP_INT_MAX;
    if ($sortA === $sortB) {
      return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    }
    return $sortA <=> $sortB;
  });

  return $lines;
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
    if (!empty($payload['category_info'])) {
      $parts[] = 'Category: ' . trim((string) $payload['category_info']);
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
    if (!empty($payload['invoice_number'])) {
      $parts[] = 'Invoice: ' . trim((string) $payload['invoice_number']);
    }
    if (!empty($payload['note'])) {
      $parts[] = trim((string) $payload['note']);
    }
    if ($parts) {
      return implode(' | ', array_filter($parts));
    }
  }

  if ($action === 'payment_updated') {
    $parts = [];
    if (!empty($payload['kind'])) {
      $parts[] = trim((string) $payload['kind']);
    }
    if (isset($payload['amount'])) {
      $parts[] = number_format((float) $payload['amount'], 2, '.', '') . ' ' . trim((string) ($payload['currency'] ?? ''));
    }
    if (!empty($payload['invoice_number'])) {
      $parts[] = 'Invoice: ' . trim((string) $payload['invoice_number']);
    }
    if (!empty($payload['note'])) {
      $parts[] = trim((string) $payload['note']);
    }
    return $parts ? ('Updated payment: ' . implode(' | ', array_filter($parts))) : 'Payment updated';
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

function customOrdersFirstFilledOptionValue(array $options, array $keys): string
{
  $normalized = [];
  foreach ($options as $rawKey => $rawValue) {
    if (is_array($rawValue) || is_object($rawValue) || $rawValue === null) {
      continue;
    }

    $normalizedKey = strtolower(trim((string) $rawKey));
    $normalizedKey = preg_replace('/[^a-z0-9]+/', '-', $normalizedKey) ?? $normalizedKey;
    $normalizedKey = trim($normalizedKey, '-');
    if ($normalizedKey !== '') {
      $normalized[$normalizedKey] = trim((string) $rawValue);
    }
  }

  foreach ($keys as $key) {
    if (array_key_exists($key, $options)) {
      $value = $options[$key];
      if (!is_array($value) && !is_object($value) && $value !== null && trim((string) $value) !== '') {
        return trim((string) $value);
      }
    }

    $normalizedKey = strtolower(trim((string) $key));
    $normalizedKey = preg_replace('/[^a-z0-9]+/', '-', $normalizedKey) ?? $normalizedKey;
    $normalizedKey = trim($normalizedKey, '-');
    if ($normalizedKey !== '' && !empty($normalized[$normalizedKey])) {
      return $normalized[$normalizedKey];
    }
  }

  return '';
}

function customOrdersCategoryFieldsFromOptions(array $options): array
{
  $categoryInfo = customOrdersFirstFilledOptionValue($options, ['category_info', 'Category Info', 'category-info', 'category info', 'category', 'Category']);
  $brand = customOrdersFirstFilledOptionValue($options, ['category_brand', 'brand', 'Brand', 'bike-brand', 'manufacturer', 'Manufacturer']);
  $model = customOrdersFirstFilledOptionValue($options, ['category_model', 'model', 'Model', 'bike-model', 'Bike', 'bike']);
  $year = customOrdersFirstFilledOptionValue($options, ['category_year_range', 'year', 'Year', 'bike-year', 'model-year', 'Year Range']);
  $modelCode = customOrdersFirstFilledOptionValue($options, ['category_modelcode', 'modelcode', 'model_code', 'design_code', 'design-code', 'category_code', 'sku-code']);

  if ($categoryInfo !== '') {
    $parts = array_values(array_filter(array_map('trim', explode('|', $categoryInfo)), static function (string $value): bool {
      return $value !== '';
    }));
    if ($brand === '' && isset($parts[0])) {
      $brand = $parts[0];
    }
    if ($model === '' && isset($parts[1])) {
      $model = $parts[1];
    }
    if ($year === '' && isset($parts[2])) {
      $year = $parts[2];
    }
    if ($modelCode === '' && isset($parts[3])) {
      $modelCode = $parts[3];
    }
  }

  if ($categoryInfo === '') {
    $parts = array_values(array_filter([$brand, $model, $year], static function (string $value): bool {
      return $value !== '';
    }));
    if ($parts) {
      $categoryInfo = implode(' | ', $parts);
      if ($modelCode !== '') {
        $categoryInfo .= ' | ' . $modelCode;
      }
    }
  }

  return [
    'category_info' => $categoryInfo,
    'category_brand' => $brand,
    'category_model' => $model,
    'category_year_range' => $year,
    'category_modelcode' => $modelCode,
  ];
}

function customOrdersCategoryFieldsFromJson(string $optionsJson): array
{
  $options = json_decode(trim($optionsJson) !== '' ? $optionsJson : '{}', true);
  return customOrdersCategoryFieldsFromOptions(is_array($options) ? $options : []);
}

function customOrdersOptionsWithProductionCategoryAliases(string $optionsJson): string
{
  $options = json_decode(trim($optionsJson) !== '' ? $optionsJson : '{}', true);
  if (!is_array($options)) {
    $options = [];
  }

  $categoryFields = customOrdersCategoryFieldsFromOptions($options);
  $categoryInfo = $categoryFields['category_info'];
  $brand = $categoryFields['category_brand'];
  $model = $categoryFields['category_model'];
  $year = $categoryFields['category_year_range'];
  $modelCode = $categoryFields['category_modelcode'];

  if ($categoryInfo !== '') {
    $options['category_info'] = $categoryInfo;
    $options['Category Info'] = $categoryInfo;
  }
  if ($brand !== '') {
    $options['category_brand'] = $brand;
    $options['brand'] = $brand;
  }
  if ($model !== '') {
    $options['category_model'] = $model;
    $options['model'] = $model;
  }
  if ($year !== '') {
    $options['category_year_range'] = $year;
    $options['year'] = $year;
  }
  if ($modelCode !== '') {
    $options['category_modelcode'] = $modelCode;
    $options['modelcode'] = $modelCode;
    $options['model_code'] = $modelCode;
    $options['design_code'] = $modelCode;
  }

  $material = customOrdersFirstFilledOptionValue($options, [
    'base-material',
    'base_material',
    'material',
    'graphics-material',
    'graphics_material',
    'Material',
  ]);
  $finish = customOrdersFirstFilledOptionValue($options, [
    'graphics-finish',
    'graphics_finish',
    'finish',
    'graphics-finish-type',
    'Finish',
  ]);

  if ($material !== '') {
    $options['base-material'] = $material;
    $options['base_material'] = $material;
    $options['material'] = $material;
  }
  if ($finish !== '') {
    $options['graphics-finish'] = $finish;
    $options['graphics_finish'] = $finish;
    $options['finish'] = $finish;
  }

  $encoded = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  return $encoded !== false ? $encoded : '{}';
}

function customOrdersInternalOptionsWithProductionPrintAliases(string $internalOptionsJson, string $optionsJson): string
{
  $internal = json_decode(trim($internalOptionsJson) !== '' ? $internalOptionsJson : '{}', true);
  if (!is_array($internal)) {
    $internal = [];
  }

  $options = json_decode(trim($optionsJson) !== '' ? $optionsJson : '{}', true);
  if (!is_array($options)) {
    $options = [];
  }

  $material = customOrdersFirstFilledOptionValue($internal, ['_print_material']);
  if ($material === '') {
    $material = customOrdersFirstFilledOptionValue($options, [
      'base-material',
      'base_material',
      'material',
      'graphics-material',
      'graphics_material',
      'Material',
    ]);
  }

  $finish = customOrdersFirstFilledOptionValue($internal, ['_print_finish']);
  if ($finish === '') {
    $finish = customOrdersFirstFilledOptionValue($options, [
      'graphics-finish',
      'graphics_finish',
      'finish',
      'graphics-finish-type',
      'Finish',
    ]);
  }

  if ($material !== '') {
    $internal['_print_material'] = $material;
  }
  if ($finish !== '') {
    $internal['_print_finish'] = $finish;
  }

  $encoded = json_encode($internal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  return $encoded !== false ? $encoded : '{}';
}

function customOrdersGetOrder(mysqli $conn, int $orderId): ?array
{
  $stmt = $conn->prepare("
    SELECT co.*,
           TRIM(CONCAT_WS(' ', eo.firstname, eo.lastname)) AS owner_name,
           eo.photo AS owner_photo,
           TRIM(CONCAT_WS(' ', eab.firstname, eab.lastname)) AS owner_assigned_by_name,
           coa.id AS custom_order_assignment_id,
           coa.employee_id AS assigned_employee_id,
           TRIM(CONCAT_WS(' ', eca.firstname, eca.lastname)) AS assigned_employee_name,
           eca.photo AS assigned_employee_photo
    FROM custom_orders co
    LEFT JOIN employees eo ON eo.id = co.owner_employee_id
    LEFT JOIN employees eab ON eab.id = co.owner_assigned_by
    LEFT JOIN custom_order_assignments coa ON coa.custom_order_id = co.id
    LEFT JOIN employees eca ON eca.id = coa.employee_id
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

  $order['item_assignments'] = [];
  if (!empty($order['items']) && customOrdersTableExists($conn, 'custom_order_item_assignments')) {
    $itemIds = [];
    foreach ($order['items'] as $itemRow) {
      $itemId = (int) ($itemRow['id'] ?? 0);
      if ($itemId > 0) {
        $itemIds[] = $itemId;
      }
    }
    if ($itemIds) {
      $res = $conn->query("
        SELECT
          cia.*,
          TRIM(CONCAT_WS(' ', e.firstname, e.lastname)) AS employee_name,
          e.photo AS employee_photo
        FROM custom_order_item_assignments cia
        LEFT JOIN employees e ON e.id = cia.employee_id
        WHERE cia.custom_order_id = " . (int) $orderId . "
          AND cia.custom_order_item_id IN (" . implode(',', $itemIds) . ")
      ");
      if ($res) {
        while ($row = $res->fetch_assoc()) {
          $order['item_assignments'][(int) $row['custom_order_item_id']] = $row;
        }
      }
    }
  }
  if (!empty($order['item_assignments'])) {
    foreach ($order['items'] as $idx => $itemRow) {
      $itemId = (int) ($itemRow['id'] ?? 0);
      $order['items'][$idx]['assignment'] = $order['item_assignments'][$itemId] ?? null;
    }
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
    $res = $conn->query("
      SELECT
        con.*,
        TRIM(CONCAT_WS(' ', e.firstname, e.lastname)) AS author_name,
        e.photo AS author_photo
      FROM custom_order_notes con
      LEFT JOIN employees e ON e.id = con.created_by
      WHERE con.custom_order_id = " . (int) $orderId . "
      ORDER BY con.created_at ASC, con.id ASC
    ");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $order['notes'][] = $row;
      }
    }
  }

  $order['note_revisions'] = [];
  if (customOrdersTableExists($conn, 'custom_order_note_revisions')) {
    $res = $conn->query("
      SELECT
        conr.*,
        TRIM(CONCAT_WS(' ', e.firstname, e.lastname)) AS actor_name
      FROM custom_order_note_revisions conr
      LEFT JOIN employees e ON e.id = conr.actor_employee_id
      WHERE conr.custom_order_id = " . (int) $orderId . "
      ORDER BY conr.created_at ASC, conr.id ASC
    ");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $order['note_revisions'][(int) ($row['note_id'] ?? 0)][] = $row;
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

function customOrdersAddNote(mysqli $conn, int $orderId, string $noteType, string $noteBody, int $userId, int $parentNoteId = 0): int
{
  $noteType = strtoupper(trim($noteType));
  if ($orderId <= 0 || $noteBody === '' || !customOrdersTableExists($conn, 'custom_order_notes')) {
    return 0;
  }

  $allowedTypes = ['CUSTOMER', 'INTERNAL', 'REVISION'];
  if (!in_array($noteType, $allowedTypes, true)) {
    $noteType = 'INTERNAL';
  }

  $noteColumns = customOrdersTableColumns($conn, 'custom_order_notes');
  $supportsReplies = isset($noteColumns['parent_note_id']);
  $resolvedParentNoteId = 0;
  if ($supportsReplies && $parentNoteId > 0) {
    $parentStmt = $conn->prepare('SELECT id, parent_note_id, deleted_at FROM custom_order_notes WHERE id = ? AND custom_order_id = ? LIMIT 1');
    if (!$parentStmt) {
      return 0;
    }
    $parentStmt->bind_param('ii', $parentNoteId, $orderId);
    $parentStmt->execute();
    $parentRow = $parentStmt->get_result()->fetch_assoc();
    $parentStmt->close();

    if (!$parentRow || !empty($parentRow['deleted_at']) || (int) ($parentRow['parent_note_id'] ?? 0) > 0) {
      return 0;
    }

    $parentRootId = (int) $parentRow['id'];
    $replyStmt = $conn->prepare('SELECT id FROM custom_order_notes WHERE custom_order_id = ? AND parent_note_id = ? LIMIT 1');
    if (!$replyStmt) {
      return 0;
    }
    $replyStmt->bind_param('ii', $orderId, $parentRootId);
    $replyStmt->execute();
    $existingReply = $replyStmt->get_result()->fetch_assoc();
    $replyStmt->close();
    if ($existingReply) {
      return 0;
    }

    $resolvedParentNoteId = $parentRootId;
  }

  if ($supportsReplies) {
    $parentValue = $resolvedParentNoteId > 0 ? $resolvedParentNoteId : null;
    $stmt = $conn->prepare('INSERT INTO custom_order_notes (custom_order_id, parent_note_id, note_type, note_body, created_by) VALUES (?, ?, ?, ?, ?)');
  } else {
    $stmt = $conn->prepare('INSERT INTO custom_order_notes (custom_order_id, note_type, note_body, created_by) VALUES (?, ?, ?, ?)');
  }
  if (!$stmt) {
    return 0;
  }
  if ($supportsReplies) {
    $stmt->bind_param('iissi', $orderId, $parentValue, $noteType, $noteBody, $userId);
  } else {
    $stmt->bind_param('issi', $orderId, $noteType, $noteBody, $userId);
  }
  $stmt->execute();
  $newNoteId = (int) $stmt->insert_id;
  $stmt->close();

  customOrdersLog(
    $conn,
    $orderId,
    $resolvedParentNoteId > 0 ? 'note_reply_added' : 'note_added',
    $userId,
    ['note_id' => $newNoteId, 'note_type' => $noteType, 'parent_note_id' => $resolvedParentNoteId ?: null],
    $resolvedParentNoteId > 0 ? 'Lead note reply added' : 'Lead note appended'
  );
  return $newNoteId;
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

function customOrdersFetchOrderAssignment(mysqli $conn, int $orderId): ?array
{
  if ($orderId <= 0 || !customOrdersTableExists($conn, 'custom_order_assignments')) {
    return null;
  }

  $stmt = $conn->prepare("
    SELECT
      coa.id AS assignment_id,
      coa.custom_order_id,
      coa.employee_id,
      coa.assigned_by,
      coa.assigned_at,
      TRIM(CONCAT_WS(' ', e.firstname, e.lastname)) AS employee_name,
      e.photo AS employee_photo
    FROM custom_order_assignments coa
    LEFT JOIN employees e ON e.id = coa.employee_id
    WHERE coa.custom_order_id = ?
    LIMIT 1
  ");
  if (!$stmt) {
    return null;
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();

  return $row ?: null;
}

function customOrdersAssignOrder(mysqli $conn, int $orderId, int $employeeId, int $assignedBy): array
{
  if ($orderId <= 0 || $employeeId <= 0) {
    throw new InvalidArgumentException('Invalid custom order assignment.');
  }

  if (!customOrdersTableExists($conn, 'custom_order_assignments')) {
    customOrdersEnsureSchema($conn);
  }

  $stmt = $conn->prepare('SELECT id FROM custom_orders WHERE id = ? LIMIT 1');
  if (!$stmt) {
    throw new RuntimeException('Custom order lookup could not be prepared.');
  }
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $exists = (bool) $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$exists) {
    throw new RuntimeException('Custom order not found.');
  }

  $stmt = $conn->prepare('
    INSERT INTO custom_order_assignments
      (custom_order_id, employee_id, assigned_by)
    VALUES
      (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      employee_id = IF(employee_id = VALUES(employee_id), VALUES(employee_id), employee_id),
      assigned_by = IF(employee_id = VALUES(employee_id), VALUES(assigned_by), assigned_by),
      assigned_at = IF(employee_id = VALUES(employee_id), NOW(), assigned_at)
  ');
  if (!$stmt) {
    throw new RuntimeException('Custom order assignment could not be prepared.');
  }
  $stmt->bind_param('iii', $orderId, $employeeId, $assignedBy);
  $stmt->execute();
  $stmt->close();

  $assignment = customOrdersFetchOrderAssignment($conn, $orderId);
  $assignedEmployeeId = (int) ($assignment['employee_id'] ?? 0);
  if ($assignedEmployeeId > 0 && $assignedEmployeeId !== $employeeId) {
    $assignedName = trim((string) ($assignment['employee_name'] ?? 'another user'));
    throw new RuntimeException('This custom order is already taken by ' . ($assignedName !== '' ? $assignedName : 'another user') . '.');
  }
  if ($assignedEmployeeId !== $employeeId) {
    throw new RuntimeException('Custom order could not be taken.');
  }

  customOrdersLog(
    $conn,
    $orderId,
    'order_taken',
    $assignedBy,
    [
      'employee_id' => $employeeId,
      'assignment_id' => (int) ($assignment['assignment_id'] ?? 0),
    ],
    'Custom order taken'
  );

  return $assignment ?: [];
}

function customOrdersAssignmentFromRow(array $row): ?array
{
  $employeeId = (int) ($row['assigned_employee_id'] ?? $row['employee_id'] ?? 0);
  if ($employeeId <= 0) {
    return null;
  }

  return [
    'assignment_id' => (int) ($row['assignment_id'] ?? $row['custom_order_assignment_id'] ?? 0),
    'employee_id' => $employeeId,
    'employee_name' => (string) ($row['assigned_employee_name'] ?? $row['employee_name'] ?? ''),
    'employee_photo' => (string) ($row['assigned_employee_photo'] ?? $row['employee_photo'] ?? ''),
  ];
}

function customOrdersEmployeeInitials(string $name): string
{
  $initials = '';
  foreach (preg_split('/\s+/', trim($name)) as $namePart) {
    if ($namePart !== '') {
      $initials .= mb_strtoupper(mb_substr($namePart, 0, 1));
    }
  }
  return mb_substr($initials, 0, 2);
}

function customOrdersRenderOrderAssignmentHtml(array $row, int $orderId, bool $canTake, int $currentUserId): string
{
  $assignment = customOrdersAssignmentFromRow($row);

  if ($assignment) {
    $perm = (int) ($_SESSION['permission'] ?? 0);
    $name = trim((string) ($assignment['employee_name'] ?? ''));
    $photo = trim((string) ($assignment['employee_photo'] ?? ''));
    $title = $name !== '' ? $name : 'Assigned';
    $initials = customOrdersEmployeeInitials($name);
    $assignmentId = (int) ($assignment['assignment_id'] ?? 0);
    $employeeId = (int) ($assignment['employee_id'] ?? 0);
    $mineClass = (int) ($assignment['employee_id'] ?? 0) === $currentUserId ? ' is-mine' : '';
    $canRemove = $assignmentId > 0 && ($perm >= 300 || $employeeId === $currentUserId);
    $removeButton = $canRemove
      ? '<button type="button" class="custom-order-remove-assignment-btn" data-assignment-id="' . $assignmentId . '" data-custom-order-id="' . (int) $orderId . '" title="' . htmlspecialchars($employeeId === $currentUserId ? 'Remove my assignment' : 'Remove assignment', ENT_QUOTES, 'UTF-8') . '">&times;</button>'
      : '';

    if ($photo !== '') {
      return '<span class="custom-order-assigned-avatar-wrap' . $mineClass . '"><img src="images/' . htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') . '" class="custom-order-assigned-avatar" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $removeButton . '</span>';
    }

    return '<span class="custom-order-assigned-avatar-wrap' . $mineClass . '"><span class="custom-order-assigned-avatar custom-order-assigned-fallback" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES, 'UTF-8') . '</span>' . $removeButton . '</span>';
  }

  if ($canTake && $orderId > 0) {
    return '<button type="button" class="btn btn-xs btn-success custom-order-take-btn" data-action="scripts/custom_orders/take_order.php" data-custom-order-id="' . (int) $orderId . '" title="Take this custom order">Take</button>';
  }

  return '<span class="text-muted" title="Open custom order">-</span>';
}

function customOrdersComputeSummary(array $order): array
{
  $itemSubtotal = 0.0;
  $upsellSubtotal = 0.0;
  $typeTotals = ['G' => 0.0, 'P' => 0.0, 'S' => 0.0, 'F' => 0.0, 'T' => 0.0, 'M' => 0.0];
  $types = [];
  foreach ((array) ($order['items'] ?? []) as $item) {
    $line = (float) ($item['qty'] ?? 0) * (float) ($item['unit_price'] ?? 0);
    $itemSubtotal += $line;
    $rawType = strtoupper(trim((string) ($item['item_type_code'] ?? '')));
    $breakdownType = $rawType !== '' ? $rawType : 'M';
    if (!array_key_exists($breakdownType, $typeTotals)) {
      $breakdownType = 'M';
    }
    $typeTotals[$breakdownType] += $line;
    if ($rawType !== '') {
      $types[] = $rawType;
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
  $paymentLines = customOrdersPaymentBreakdownLines((array) ($order['payments'] ?? []));

  $shipping = (float) ($order['shipping_price'] ?? 0);
  $grossTotal = $itemSubtotal + $shipping;

  return [
    'item_subtotal' => $itemSubtotal,
    'shipping' => $shipping,
    'gross_total' => $grossTotal,
    'deposit_total' => $depositTotal,
    'payment_net' => $paymentNet,
    'balance_due' => $grossTotal - $paymentNet,
    'payment_lines' => $paymentLines,
    'upsell_subtotal' => $upsellSubtotal,
    'types' => customOrdersDepartmentOrder(implode('', array_unique($types))),
    'type_totals' => $typeTotals,
  ];
}

function customOrdersFinancialBreakdownSnapshot(array $order, ?array $summary = null): array
{
  $summary = $summary ?? customOrdersComputeSummary($order);
  $typeTotals = $summary['type_totals'] ?? ['G' => 0.0, 'P' => 0.0, 'S' => 0.0, 'F' => 0.0, 'T' => 0.0, 'M' => 0.0];
  $grossTotal = (float) ($summary['gross_total'] ?? 0);
  $paymentNet = (float) ($summary['payment_net'] ?? 0);

  return [
    'source' => 'custom_orders',
    'currency' => (string) ($order['currency'] ?? 'EUR'),
    'total' => $grossTotal,
    'graphics' => (float) ($typeTotals['G'] ?? 0),
    'plastics' => (float) ($typeTotals['P'] ?? 0),
    'seat_covers' => (float) ($typeTotals['S'] ?? 0),
    'fitting' => (float) ($typeTotals['F'] ?? 0),
    'accessories' => (float) ($typeTotals['T'] ?? 0),
    'other' => (float) ($typeTotals['M'] ?? 0),
    'shipping' => (float) ($summary['shipping'] ?? 0),
    'deposits' => (float) ($summary['deposit_total'] ?? 0),
    'paid_net' => $paymentNet,
    'balance_due' => $grossTotal - $paymentNet,
    'payment_lines' => (array) ($summary['payment_lines'] ?? customOrdersPaymentBreakdownLines((array) ($order['payments'] ?? []))),
  ];
}

function customOrdersFormatOfficialNumber(string $prefix, int $sequenceValue): string
{
  $prefix = strtoupper(trim($prefix));
  if (!in_array($prefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix');
  }
  if ($sequenceValue <= 0) {
    throw new RuntimeException('Official number must be greater than zero.');
  }

  return $prefix . str_pad((string) $sequenceValue, 5, '0', STR_PAD_LEFT);
}

function customOrdersParseOfficialNumberInput(string $selectedPrefix, string $rawValue): array
{
  $selectedPrefix = strtoupper(trim($selectedPrefix));
  if (!in_array($selectedPrefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix');
  }

  $value = strtoupper((string) preg_replace('/\s+/', '', trim($rawValue)));
  if ($value === '') {
    throw new RuntimeException('Enter an official number, for example 20930, 20930-3, or SO20930-3.');
  }

  $prefix = $selectedPrefix;
  $sequenceRaw = '';
  $suffix = '';
  if (preg_match('/^([A-Z]{2})(\d+)(-\d+)?$/', $value, $matches)) {
    $prefix = $matches[1];
    $sequenceRaw = $matches[2];
    $suffix = $matches[3] ?? '';
  } elseif (preg_match('/^(\d+)(-\d+)?$/', $value, $matches)) {
    $sequenceRaw = $matches[1];
    $suffix = $matches[2] ?? '';
  } else {
    throw new RuntimeException('Enter an official number, for example 20930, 20930-3, or SO20930-3.');
  }

  if (!in_array($prefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix');
  }
  if ($prefix !== $selectedPrefix) {
    throw new RuntimeException('Typed number prefix does not match the selected number branch.');
  }
  if (!ctype_digit($sequenceRaw) || (int) $sequenceRaw <= 0 || (int) $sequenceRaw > 2147483647) {
    throw new RuntimeException('Enter a whole number between 1 and 2147483647.');
  }
  if ($suffix !== '' && ((int) substr($suffix, 1) <= 0 || (int) substr($suffix, 1) > 2147483647)) {
    throw new RuntimeException('Enter a suffix like -1, -2, or -3.');
  }

  $sequenceValue = (int) $sequenceRaw;
  $number = customOrdersFormatOfficialNumber($prefix, $sequenceValue) . $suffix;

  return [
    'prefix' => $prefix,
    'sequence_value' => $sequenceValue,
    'suffix' => $suffix,
    'official_number' => $number,
  ];
}

function customOrdersOfficialNumberExists(mysqli $conn, string $number, int $excludeCustomOrderId = 0): bool
{
  $number = strtoupper(trim($number));
  if ($number === '') {
    return false;
  }

  $stmt = $conn->prepare('
    SELECT id
    FROM custom_orders
    WHERE UPPER(TRIM(official_order_number)) = ?
      AND (? <= 0 OR id <> ?)
    LIMIT 1
  ');
  if (!$stmt) {
    throw new RuntimeException('Could not prepare official number duplicate check.');
  }
  $stmt->bind_param('sii', $number, $excludeCustomOrderId, $excludeCustomOrderId);
  $stmt->execute();
  $exists = (bool) $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($exists) {
    return true;
  }

  if (!customOrdersTableExists($conn, 'orders')) {
    return false;
  }

  $stmt = $conn->prepare('
    SELECT id
    FROM orders
    WHERE UPPER(TRIM(order_number)) = ?
    LIMIT 1
  ');
  if (!$stmt) {
    throw new RuntimeException('Could not prepare production order number duplicate check.');
  }
  $stmt->bind_param('s', $number);
  $stmt->execute();
  $exists = (bool) $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $exists;
}

function customOrdersAssignOfficialNumber(mysqli $conn, int $orderId, string $prefix, int $userId, ?int $requestedSequenceValue = null, string $requestedSuffix = ''): string
{
  $prefix = strtoupper(trim($prefix));
  if (!in_array($prefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix');
  }
  $requestedSuffix = trim($requestedSuffix);
  if ($requestedSuffix !== '') {
    if ($requestedSequenceValue === null || !preg_match('/^-\d+$/', $requestedSuffix) || (int) substr($requestedSuffix, 1) <= 0 || (int) substr($requestedSuffix, 1) > 2147483647) {
      throw new RuntimeException('Enter a suffix like -1, -2, or -3.');
    }
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare('SELECT official_order_number FROM custom_orders WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
      throw new RuntimeException('Custom order not found.');
    }
    if (!empty($row['official_order_number'])) {
      $conn->commit();
      return (string) $row['official_order_number'];
    }

    $stmt = $conn->prepare('SELECT current_value FROM custom_order_number_sequences WHERE prefix_code = ? FOR UPDATE');
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $sequenceRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sequenceRow) {
      throw new RuntimeException('Missing sequence for ' . $prefix);
    }

    $currentValue = (int) $sequenceRow['current_value'];
    $sequenceValue = $requestedSequenceValue ?? ($currentValue + 1);
    $number = customOrdersFormatOfficialNumber($prefix, $sequenceValue) . $requestedSuffix;
    while ($requestedSequenceValue === null && customOrdersOfficialNumberExists($conn, $number, $orderId)) {
      ++$sequenceValue;
      $number = customOrdersFormatOfficialNumber($prefix, $sequenceValue);
    }
    if ($requestedSequenceValue !== null && customOrdersOfficialNumberExists($conn, $number, $orderId)) {
      throw new RuntimeException('Official number ' . $number . ' already exists in the database.');
    }

    if ($sequenceValue > $currentValue) {
      $stmt = $conn->prepare('UPDATE custom_order_number_sequences SET current_value = ? WHERE prefix_code = ?');
      $stmt->bind_param('is', $sequenceValue, $prefix);
      $stmt->execute();
      $stmt->close();
    }

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

function customOrdersReserveOfficialNumberInTransaction(mysqli $conn, string $prefix): string
{
  $prefix = strtoupper(trim($prefix));
  if (!in_array($prefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix');
  }

  $stmt = $conn->prepare('SELECT current_value FROM custom_order_number_sequences WHERE prefix_code = ? FOR UPDATE');
  $stmt->bind_param('s', $prefix);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    throw new RuntimeException('Missing sequence for ' . $prefix);
  }

  $next = ((int) $row['current_value']) + 1;
  $number = customOrdersFormatOfficialNumber($prefix, $next);
  while (customOrdersOfficialNumberExists($conn, $number)) {
    ++$next;
    $number = customOrdersFormatOfficialNumber($prefix, $next);
  }

  $stmt = $conn->prepare('UPDATE custom_order_number_sequences SET current_value = ? WHERE prefix_code = ?');
  $stmt->bind_param('is', $next, $prefix);
  $stmt->execute();
  $stmt->close();

  return $number;
}

function customOrdersDuplicateStatus(?string $sourceStatus): string
{
  $status = strtoupper(trim((string) $sourceStatus));
  if ($status === '') {
    return 'LEAD';
  }
  if (in_array($status, ['EXPORTED', 'DEAD', 'CANCELLED'], true)) {
    return 'DRAFT_X';
  }
  return $status;
}

function customOrdersBindDynamicParams(mysqli_stmt $stmt, string $types, array &$values): void
{
  $refs = [];
  foreach ($values as $idx => &$value) {
    $refs[$idx] = &$value;
  }
  $stmt->bind_param($types, ...$refs);
}

function customOrdersInsertDynamicRow(mysqli $conn, string $table, array $values): int
{
  $table = trim($table);
  if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    throw new RuntimeException('Invalid table name.');
  }
  if (!$values) {
    throw new RuntimeException('No data to insert.');
  }

  $columns = array_keys($values);
  foreach ($columns as $column) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $column)) {
      throw new RuntimeException('Invalid column name.');
    }
  }

  $placeholders = implode(', ', array_fill(0, count($columns), '?'));
  $quotedColumns = '`' . implode('`, `', $columns) . '`';
  $sql = 'INSERT INTO `' . $table . '` (' . $quotedColumns . ') VALUES (' . $placeholders . ')';
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException('Unable to prepare duplicate insert for ' . $table . '.');
  }

  $params = array_values($values);
  customOrdersBindDynamicParams($stmt, str_repeat('s', count($params)), $params);
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    throw new RuntimeException('Unable to create duplicate row in ' . $table . ': ' . $error);
  }
  $insertId = (int) $stmt->insert_id;
  $stmt->close();
  return $insertId;
}

function customOrdersDynamicValuesForTable(mysqli $conn, string $table, array $source, array $special, array $skip): array
{
  $columns = customOrdersTableColumns($conn, $table);
  $values = [];
  foreach ($columns as $column => $_exists) {
    if ($column === 'id') {
      continue;
    }
    if (array_key_exists($column, $special)) {
      $values[$column] = $special[$column];
      continue;
    }
    if (isset($skip[$column])) {
      continue;
    }
    if (array_key_exists($column, $source)) {
      $values[$column] = $source[$column];
    }
  }
  return $values;
}

function customOrdersDuplicateOrder(mysqli $conn, int $sourceOrderId, int $userId): int
{
  $source = customOrdersGetOrder($conn, $sourceOrderId);
  if (!$source) {
    throw new RuntimeException('Custom order not found.');
  }

  $now = customOrdersNow();
  $prefix = 'SO';
  $ownerEmployeeId = (int) ($source['owner_employee_id'] ?? 0);
  if ($ownerEmployeeId <= 0 && $userId > 0) {
    $ownerEmployeeId = $userId;
  }
  $actorId = $userId > 0 ? $userId : null;

  $conn->begin_transaction();
  try {
    $officialNumber = customOrdersReserveOfficialNumberInTransaction($conn, $prefix);
    $tempInternalCode = 'PENDING-' . bin2hex(random_bytes(4));
    $duplicateStatus = customOrdersDuplicateStatus((string) ($source['status'] ?? ''));

    $orderSpecial = [
      'internal_code' => $tempInternalCode,
      'official_order_number' => $officialNumber,
      'official_prefix' => $prefix,
      'owner_employee_id' => $ownerEmployeeId > 0 ? $ownerEmployeeId : null,
      'owner_assigned_by' => $actorId,
      'owner_assigned_at' => $now,
      'status' => $duplicateStatus,
      'dead_order_flag' => 0,
      'production_order_id' => null,
      'exported_at' => null,
      'exported_by' => null,
      'created_by' => $actorId,
      'updated_by' => $actorId,
    ];
    $orderSkip = array_fill_keys([
      'created_at',
      'updated_at',
      'last_contact_at',
      'next_followup_at',
    ], true);
    $orderValues = customOrdersDynamicValuesForTable($conn, 'custom_orders', $source, $orderSpecial, $orderSkip);
    $newOrderId = customOrdersInsertDynamicRow($conn, 'custom_orders', $orderValues);

    $internalCode = 'CO' . str_pad((string) $newOrderId, 6, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare('UPDATE custom_orders SET internal_code = ? WHERE id = ?');
    $stmt->bind_param('si', $internalCode, $newOrderId);
    $stmt->execute();
    $stmt->close();

    $itemSkip = array_fill_keys(['id', 'created_at', 'updated_at'], true);
    foreach ((array) ($source['items'] ?? []) as $item) {
      $itemSpecial = [
        'custom_order_id' => $newOrderId,
        'created_by' => $actorId,
        'updated_by' => $actorId,
      ];
      $itemValues = customOrdersDynamicValuesForTable($conn, 'custom_order_items', $item, $itemSpecial, $itemSkip);
      customOrdersInsertDynamicRow($conn, 'custom_order_items', $itemValues);
    }

    if (customOrdersTableExists($conn, 'custom_order_photos')) {
      $photoSkip = array_fill_keys([
        'id',
        'created_at',
        'deleted_at',
        'deleted_by',
        'production_photo_id',
        'exported_at',
      ], true);
      foreach ((array) ($source['photos'] ?? []) as $photo) {
        $photoSpecial = [
          'custom_order_id' => $newOrderId,
          'created_by' => $actorId,
          'deleted_at' => null,
          'deleted_by' => null,
          'production_photo_id' => null,
          'exported_at' => null,
        ];
        $photoValues = customOrdersDynamicValuesForTable($conn, 'custom_order_photos', $photo, $photoSpecial, $photoSkip);
        customOrdersInsertDynamicRow($conn, 'custom_order_photos', $photoValues);
      }
    }

    customOrdersLog($conn, $newOrderId, 'created', $actorId, ['internal_code' => $internalCode, 'owner_employee_id' => $ownerEmployeeId ?: null], 'Custom order duplicated');
    customOrdersLog($conn, $newOrderId, 'official_number_assigned', $actorId, ['official_order_number' => $officialNumber], 'Official number assigned');
    customOrdersLog(
      $conn,
      $newOrderId,
      'duplicated_from',
      $actorId,
      [
        'source_custom_order_id' => $sourceOrderId,
        'source_official_order_number' => (string) ($source['official_order_number'] ?? ''),
      ],
      'Duplicated from custom order ' . (string) ($source['official_order_number'] ?: $source['internal_code'] ?: ('#' . $sourceOrderId))
    );
    customOrdersLog(
      $conn,
      $sourceOrderId,
      'duplicated_to',
      $actorId,
      [
        'duplicate_custom_order_id' => $newOrderId,
        'duplicate_official_order_number' => $officialNumber,
      ],
      'Duplicated to custom order ' . $officialNumber
    );

    $conn->commit();
    return $newOrderId;
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
  if (customOrdersCountryRequiresState((string) ($order['shipping_country'] ?? '')) && trim((string) ($order['shipping_state'] ?? '')) === '') {
    $errors[] = 'Shipping state / province is required for this country.';
    $fields[] = 'shipping_state';
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
  if ((float) ($summary['gross_total'] ?? 0) < 0) {
    $errors[] = 'Order total cannot be below zero.';
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
  $name = trim((string) ($order['customer_name'] ?? ''));
  if ($name === '') {
    $name = trim((string) (($order['shipping_name'] ?? '') ?: ($order['billing_name'] ?? '')));
  }
  $email = trim((string) ($order['customer_email'] ?? ''));
  if ($email === '') {
    $email = trim((string) (($order['shipping_email'] ?? '') ?: ($order['billing_email'] ?? '')));
  }
  $phone = trim((string) ($order['customer_phone'] ?? ''));
  if ($phone === '') {
    $phone = trim((string) (($order['shipping_phone'] ?? '') ?: ($order['billing_phone'] ?? '')));
  }
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
      $customerId = (int) $row['id'];
      $stmt = $conn->prepare('
        UPDATE customers
        SET name = COALESCE(NULLIF(?, \'\'), name),
            email = COALESCE(NULLIF(?, \'\'), email),
            phone = COALESCE(NULLIF(?, \'\'), phone)
        WHERE id = ?
      ');
      $stmt->bind_param('sssi', $name, $email, $phone, $customerId);
      $stmt->execute();
      $stmt->close();
      return $customerId;
    }
  }

  $stmt = $conn->prepare('INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)');
  $stmt->bind_param('sss', $name, $email, $phone);
  $stmt->execute();
  $customerId = (int) $stmt->insert_id;
  $stmt->close();
  return $customerId;
}

function customOrdersUpsertProductionAddress(mysqli $conn, int $productionOrderId, string $type, array $address): void
{
  $type = strtoupper(trim($type));
  if ($productionOrderId <= 0 || !in_array($type, ['BILLING', 'SHIPPING'], true)) {
    return;
  }

  $columns = customOrdersTableColumns($conn, 'order_addresses');
  $hasState = isset($columns['state']);

  $name = trim((string) ($address['name'] ?? ''));
  $company = trim((string) ($address['company'] ?? ''));
  $companyId = trim((string) ($address['company_id'] ?? ''));
  $street = trim((string) ($address['street'] ?? ''));
  $city = trim((string) ($address['city'] ?? ''));
  $zip = trim((string) ($address['zip'] ?? ''));
  $country = (string) customOrdersNormalizeCountry((string) ($address['country'] ?? ''));
  $state = (string) customOrdersNormalizeState((string) ($address['state'] ?? ''));
  $email = trim((string) ($address['email'] ?? ''));
  $phone = trim((string) ($address['phone'] ?? ''));

  $check = $conn->prepare('
    SELECT id
    FROM order_addresses
    WHERE order_id = ? AND type = ?
    LIMIT 1
  ');
  $check->bind_param('is', $productionOrderId, $type);
  $check->execute();
  $existing = $check->get_result()->fetch_assoc();
  $check->close();

  if ($existing) {
    $addressId = (int) $existing['id'];
    if ($hasState) {
      $stmt = $conn->prepare('
        UPDATE order_addresses
        SET name = ?, company = ?, company_id = ?, street = ?, city = ?, zip = ?, country = ?, state = ?, email = ?, phone = ?
        WHERE id = ?
        LIMIT 1
      ');
      $stmt->bind_param('ssssssssssi', $name, $company, $companyId, $street, $city, $zip, $country, $state, $email, $phone, $addressId);
    } else {
      $stmt = $conn->prepare('
        UPDATE order_addresses
        SET name = ?, company = ?, company_id = ?, street = ?, city = ?, zip = ?, country = ?, email = ?, phone = ?
        WHERE id = ?
        LIMIT 1
      ');
      $stmt->bind_param('sssssssssi', $name, $company, $companyId, $street, $city, $zip, $country, $email, $phone, $addressId);
    }
    $stmt->execute();
    $stmt->close();
    return;
  }

  if ($hasState) {
    $stmt = $conn->prepare('
      INSERT INTO order_addresses (order_id, type, name, company, company_id, street, city, zip, country, state, email, phone)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('isssssssssss', $productionOrderId, $type, $name, $company, $companyId, $street, $city, $zip, $country, $state, $email, $phone);
  } else {
    $stmt = $conn->prepare('
      INSERT INTO order_addresses (order_id, type, name, company, company_id, street, city, zip, country, email, phone)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('issssssssss', $productionOrderId, $type, $name, $company, $companyId, $street, $city, $zip, $country, $email, $phone);
  }
  $stmt->execute();
  $stmt->close();
}

function customOrdersSyncProductionHeader(mysqli $conn, int $customOrderId, int $productionOrderId, array $orderData, int $userId): void
{
  if ($customOrderId <= 0 || $productionOrderId <= 0) {
    return;
  }

  $stmt = $conn->prepare('
    SELECT customer_id, source_meta
    FROM orders
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ');
  if (!$stmt) {
    throw new RuntimeException('Production order lookup could not be prepared.');
  }
  $stmt->bind_param('i', $productionOrderId);
  $stmt->execute();
  $productionOrder = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$productionOrder) {
    return;
  }

  $customerName = trim((string) ($orderData['customer_name'] ?? ''));
  if ($customerName === '') {
    $customerName = trim((string) (($orderData['shipping_name'] ?? '') ?: ($orderData['billing_name'] ?? '')));
  }
  $customerEmail = trim((string) ($orderData['customer_email'] ?? ''));
  if ($customerEmail === '') {
    $customerEmail = trim((string) (($orderData['shipping_email'] ?? '') ?: ($orderData['billing_email'] ?? '')));
  }
  $customerPhone = trim((string) ($orderData['customer_phone'] ?? ''));
  if ($customerPhone === '') {
    $customerPhone = trim((string) (($orderData['shipping_phone'] ?? '') ?: ($orderData['billing_phone'] ?? '')));
  }

  $customerId = (int) ($productionOrder['customer_id'] ?? 0);
  if ($customerId > 0) {
    $stmt = $conn->prepare('
      UPDATE customers
      SET name = ?,
          email = COALESCE(NULLIF(?, \'\'), email),
          phone = COALESCE(NULLIF(?, \'\'), phone)
      WHERE id = ?
    ');
    if (!$stmt) {
      throw new RuntimeException('Production customer update could not be prepared.');
    }
    $stmt->bind_param('sssi', $customerName, $customerEmail, $customerPhone, $customerId);
    $stmt->execute();
    $stmt->close();
  } elseif ($customerName !== '' || $customerEmail !== '' || $customerPhone !== '') {
    $customerId = customOrdersUpsertCustomer($conn, [
      'customer_name' => $customerName,
      'customer_email' => $customerEmail,
      'customer_phone' => $customerPhone,
    ]) ?? 0;
  }

  $sourceMeta = json_decode((string) ($productionOrder['source_meta'] ?? ''), true);
  if (!is_array($sourceMeta)) {
    $sourceMeta = [];
  }
  $sourceMeta['custom_order_id'] = $customOrderId;
  $sourceMeta['source_channel'] = (string) ($orderData['source_channel'] ?? '');
  $sourceMeta['social_platform'] = (string) ($orderData['social_platform'] ?? '');
  $sourceMeta['social_handle'] = (string) ($orderData['social_handle'] ?? '');
  $sourceMeta['bike_photo_urls'] = (string) ($orderData['bike_photo_urls'] ?? '');
  $sourceMeta['reference_urls'] = (string) ($orderData['reference_urls'] ?? '');
  $sourceMetaJson = json_encode($sourceMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($sourceMetaJson === false) {
    $sourceMetaJson = '{}';
  }

  $shippingMethod = trim((string) ($orderData['shipping_method'] ?? ''));
  $paymentMethod = trim((string) ($orderData['payment_method'] ?? ''));
  $note = trim((string) ($orderData['customer_notes'] ?? ''));

  $stmt = $conn->prepare('
    UPDATE orders
    SET shipping_method = ?,
        payment_method = ?,
        note = ?,
        source_meta = ?,
        customer_id = CASE WHEN ? > 0 THEN ? ELSE customer_id END
    WHERE id = ?
    LIMIT 1
  ');
  if (!$stmt) {
    throw new RuntimeException('Production order header sync could not be prepared.');
  }
  $stmt->bind_param('ssssiii', $shippingMethod, $paymentMethod, $note, $sourceMetaJson, $customerId, $customerId, $productionOrderId);
  $stmt->execute();
  $stmt->close();

  customOrdersUpsertProductionAddress($conn, $productionOrderId, 'BILLING', [
    'name' => (string) (($orderData['billing_name'] ?? '') ?: ($orderData['customer_name'] ?? '') ?: ($orderData['shipping_name'] ?? '')),
    'company' => (string) ($orderData['billing_company'] ?? ''),
    'company_id' => (string) ($orderData['billing_company_id'] ?? ''),
    'street' => (string) (($orderData['billing_street'] ?? '') ?: ($orderData['shipping_street'] ?? '')),
    'city' => (string) (($orderData['billing_city'] ?? '') ?: ($orderData['shipping_city'] ?? '')),
    'zip' => (string) (($orderData['billing_zip'] ?? '') ?: ($orderData['shipping_zip'] ?? '')),
    'country' => (string) (($orderData['billing_country'] ?? '') ?: ($orderData['shipping_country'] ?? '')),
    'state' => (string) (($orderData['billing_state'] ?? '') ?: ($orderData['shipping_state'] ?? '')),
    'email' => (string) (($orderData['billing_email'] ?? '') ?: ($orderData['customer_email'] ?? '') ?: ($orderData['shipping_email'] ?? '')),
    'phone' => (string) (($orderData['billing_phone'] ?? '') ?: ($orderData['customer_phone'] ?? '') ?: ($orderData['shipping_phone'] ?? '')),
  ]);

  customOrdersUpsertProductionAddress($conn, $productionOrderId, 'SHIPPING', [
    'name' => (string) ($orderData['shipping_name'] ?? ''),
    'company' => (string) ($orderData['shipping_company'] ?? ''),
    'company_id' => (string) ($orderData['shipping_company_id'] ?? ''),
    'street' => (string) ($orderData['shipping_street'] ?? ''),
    'city' => (string) ($orderData['shipping_city'] ?? ''),
    'zip' => (string) ($orderData['shipping_zip'] ?? ''),
    'country' => (string) ($orderData['shipping_country'] ?? ''),
    'state' => (string) ($orderData['shipping_state'] ?? ''),
    'email' => (string) (($orderData['shipping_email'] ?? '') ?: ($orderData['customer_email'] ?? '')),
    'phone' => (string) (($orderData['shipping_phone'] ?? '') ?: ($orderData['customer_phone'] ?? '')),
  ]);

  log_order_activity(
    $conn,
    $productionOrderId,
    $userId,
    'custom_order_header_synced',
    'custom_order',
    $customOrderId,
    [
      'custom_order_id' => $customOrderId,
      'customer_name' => $customerName,
      'social_handle' => (string) ($orderData['social_handle'] ?? ''),
    ],
    'Custom order header synchronized'
  );
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
    'shipping_price' => (float) ($summary['shipping'] ?? 0),
    'financial_breakdown' => customOrdersFinancialBreakdownSnapshot($order, $summary),
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

    $invoiceNumbers = [];
    foreach ((array) ($order['payments'] ?? []) as $payment) {
      $invoiceNumber = trim((string) ($payment['invoice_number'] ?? ''));
      if ($invoiceNumber !== '') {
        $invoiceNumbers[$invoiceNumber] = $invoiceNumber;
      }
    }
    if ($invoiceNumbers) {
      if (!customOrdersTableExists($conn, 'order_invoices')) {
        throw new RuntimeException('Production invoices table is missing.');
      }
      $stmt = $conn->prepare('
        INSERT INTO order_invoices (order_id, invoice_number, created_by)
        VALUES (?, ?, ?)
      ');
      if (!$stmt) {
        throw new RuntimeException('Production invoices could not be prepared for export.');
      }
      foreach ($invoiceNumbers as $invoiceNumber) {
        $stmt->bind_param('isi', $productionOrderId, $invoiceNumber, $userId);
        $stmt->execute();
      }
      $stmt->close();
    }

    $stmt = $conn->prepare('
      INSERT INTO order_addresses (order_id, type, name, company, company_id, street, city, zip, country, state, email, phone)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $billingType = 'BILLING';
    $billingName = trim((string) ($order['billing_name'] ?: $order['customer_name'] ?: $order['shipping_name']));
    $billingCompany = trim((string) ($order['billing_company'] ?? ''));
    $billingCompanyId = trim((string) ($order['billing_company_id'] ?? ''));
    $billingStreet = trim((string) ($order['billing_street'] ?: ($order['shipping_street'] ?? '')));
    $billingCity = trim((string) ($order['billing_city'] ?: ($order['shipping_city'] ?? '')));
    $billingZip = trim((string) ($order['billing_zip'] ?: ($order['shipping_zip'] ?? '')));
    $billingCountry = (string) customOrdersNormalizeCountry((string) ($order['billing_country'] ?: ($order['shipping_country'] ?? '')));
    $billingState = (string) customOrdersNormalizeState((string) ($order['billing_state'] ?: ($order['shipping_state'] ?? '')));
    $billingEmail = trim((string) ($order['customer_email'] ?: $order['billing_email'] ?: $order['shipping_email']));
    $billingPhone = trim((string) ($order['customer_phone'] ?: $order['billing_phone'] ?: $order['shipping_phone']));
    $stmt->bind_param('isssssssssss', $productionOrderId, $billingType, $billingName, $billingCompany, $billingCompanyId, $billingStreet, $billingCity, $billingZip, $billingCountry, $billingState, $billingEmail, $billingPhone);
    $stmt->execute();

    $shippingType = 'SHIPPING';
    $shippingName = trim((string) ($order['shipping_name'] ?? ''));
    $shippingCompany = trim((string) ($order['shipping_company'] ?? ''));
    $shippingCompanyId = trim((string) ($order['shipping_company_id'] ?? ''));
    $shippingStreet = trim((string) ($order['shipping_street'] ?? ''));
    $shippingCity = trim((string) ($order['shipping_city'] ?? ''));
    $shippingZip = trim((string) ($order['shipping_zip'] ?? ''));
    $shippingCountry = (string) customOrdersNormalizeCountry((string) ($order['shipping_country'] ?? ''));
    $shippingState = (string) customOrdersNormalizeState((string) ($order['shipping_state'] ?? ''));
    $shippingEmail = trim((string) ($order['shipping_email'] ?: $order['customer_email']));
    $shippingPhone = trim((string) ($order['shipping_phone'] ?: $order['customer_phone']));
    $stmt->bind_param('isssssssssss', $productionOrderId, $shippingType, $shippingName, $shippingCompany, $shippingCompanyId, $shippingStreet, $shippingCity, $shippingZip, $shippingCountry, $shippingState, $shippingEmail, $shippingPhone);
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
      $optionsJson = customOrdersOptionsWithProductionCategoryAliases((string) ($item['options_json'] ?? '{}'));
      $internalOptionsJson = customOrdersInternalOptionsWithProductionPrintAliases((string) ($item['internal_options_json'] ?? '{}'), $optionsJson);
      $productionItemStatus = ordersPlasticsGateDefaultStatusForItem($conn, [
        'item_type_code' => $typeCode,
        'sku' => $sku,
        'custom_label' => $label,
        'options_json' => $optionsJson,
        'internal_options_json' => $internalOptionsJson,
      ]);
      $stmt->bind_param('iissssidssiis', $productionOrderId, $lineNo, $sku, $title, $label, $typeCode, $qty, $unitPrice, $optionsJson, $internalOptionsJson, $userId, $userId, $productionItemStatus);
      $stmt->execute();
    }
    $stmt->close();

    ordersApplyPlasticsStockGate($conn, $productionOrderId);
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
