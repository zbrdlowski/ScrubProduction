<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';

$customOrdersPermission = (int) ($_SESSION['permission'] ?? 0);
$customOrdersCanManage = $customOrdersPermission >= 300;
$customOrdersCanContribute = $customOrdersPermission >= 1;
$customOrdersCurrentUserId = (int) ($_SESSION['user_id'] ?? 0);
// CUSTOM ORDERS NOTE AUDIT: Sem dopln employee ID konatela alebo dalsich ludi,
// ktori smu vidiet vymazane poznamky a kompletne pred-editacne verzie.
// Permission 900 ma pristup automaticky.
$customOrderNoteAuditViewerEmployeeIds = [
  3,5
];
$customOrdersCanViewNoteAudit = $customOrdersPermission >= 900
  || in_array($customOrdersCurrentUserId, $customOrderNoteAuditViewerEmployeeIds, true);
if (!$customOrdersCanContribute) {
  http_response_code(403);
  echo '<div class="alert alert-danger">No permission to view Custom Orders.</div>';
  return;
}

customOrdersEnsureSchema($conn);

$flash = customOrdersTakeFlash();
$statuses = customOrdersOrderStatuses();
$allowedTypes = customOrdersAllowedItemTypes();
$paymentKinds = customOrdersPaymentKinds();
// CUSTOM ORDERS: Ak treba pridaj novy sposob platby, napr. Card, Bank Transfer, Cash, PayPal, Stripe.
$customOrderPaymentMethods = ['PayPal', 'Bank Transfer', 'Cash'];
// CUSTOM ORDERS: Ak treba pridaj novy sposob dopravy, napr. DHL, GLS, FedEx Economy, FedEx Express, Post, Pick Up.
$customOrderShippingMethods = ['FedEx Economy', 'FedEx Express', 'GLS', 'Post', 'Pick Up'];
// CUSTOM ORDERS: Konzistentne zdroje leadu pre hlavicky custom objednavky.
$customOrderSourceChannels = ['Email', 'WhatsApp', 'Instagram', 'Messenger', 'Phone call'];
$customOrderStatusChoiceCodes = [
  'LEAD',
  'DEPOSIT_PAID',
  'DRAFT_X',
  'DRAFT_AD_CHANGES',
  'DRAFT_READY',
  'DRAFT_READY_NOTES',
  'DRAFT_SENT',
  'CONTACT_CUSTOMER',
  'CUSTOMER_CONTACTED',
];
$customOrderStatusChoices = [];
foreach ($customOrderStatusChoiceCodes as $customOrderStatusChoiceCode) {
  if (isset($statuses[$customOrderStatusChoiceCode])) {
    $customOrderStatusChoices[$customOrderStatusChoiceCode] = $statuses[$customOrderStatusChoiceCode];
  }
}
$customOrderTabs = [
  'all' => ['label' => 'All'],
  'lead' => ['label' => 'Lead', 'status' => 'LEAD'],
  'open_so' => ['label' => 'Open SO'],
  'deposit_paid' => ['label' => 'Deposit Paid', 'status' => 'DEPOSIT_PAID'],
  'contact_customer' => ['label' => 'Contact Customer', 'status' => 'CONTACT_CUSTOMER'],
  'customer_contacted' => ['label' => 'Customer Contacted', 'status' => 'CUSTOMER_CONTACTED'],
];
$customOrderTabSets = [
  ['all', 'lead', 'open_so', 'deposit_paid'],
  ['contact_customer', 'customer_contacted'],
];
$customOrderDraftTabCodes = ['DRAFT_✗', 'DRAFT_AD_CHANGES', 'DRAFT_READY', 'DRAFT_SENT'];
$customOrderComplexityOptions = [
  1 => 'Standard',
  2 => 'Simple',
  3 => 'SO-Custom (same model)',
  4 => 'SO-Custom + logo changes',
  5 => 'SO-Custom (transfer)',
  6 => 'Advanced',
  7 => 'Complex',
];

// CUSTOM ORDERS: Predvoleny stav zbalovacich sekcii.
// true = po nacitani rozbalena, false = po nacitani zbalena.
$customOrderSectionDefaults = [
  'payments' => false,
  'notes' => true,
  'followups' => false,
];

$assignableEmployees = customOrdersAssignableEmployees($conn);
$customOrderOwnerOptions = [];
foreach ($assignableEmployees as $employee) {
  $employeeId = (int) ($employee['id'] ?? 0);
  if ($employeeId <= 0) {
    continue;
  }
  $employeeName = trim(((string) ($employee['firstname'] ?? '')) . ' ' . ((string) ($employee['lastname'] ?? '')));
  $customOrderOwnerOptions[$employeeId] = $employeeName !== '' ? $employeeName : ('Employee #' . $employeeId);
}
$invalidFields = [];
if (is_array($flash['meta']['invalid_fields'] ?? null)) {
  $invalidFields = array_fill_keys($flash['meta']['invalid_fields'], true);
}

$statusFilter = '';
$legacyStatusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$tabFilter = strtolower(trim((string) ($_GET['tab'] ?? '')));
if ($tabFilter === '' && $legacyStatusFilter === 'DEPOSIT_PAID') {
  $tabFilter = 'deposit_paid';
}
if (!isset($customOrderTabs[$tabFilter])) {
  $tabFilter = 'all';
}
$draftStatusFilter = strtoupper(trim((string) ($_GET['draft_status'] ?? '')));
$query = trim((string) ($_GET['q'] ?? ''));
$difficultyFilter = (int) ($_GET['difficulty'] ?? 0);
if (!isset($customOrderComplexityOptions[$difficultyFilter])) {
  $difficultyFilter = 0;
}
$ownerFilter = (int) ($_GET['owner'] ?? 0);
if ($ownerFilter > 0 && !isset($customOrderOwnerOptions[$ownerFilter])) {
  $ownerFilter = 0;
}
$countryFilter = strtoupper(trim((string) ($_GET['country'] ?? '')));
if ($countryFilter !== '' && !preg_match('/^[A-Z]{2}$/', $countryFilter)) {
  $countryFilter = '';
}
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$paymentFilter = trim((string) ($_GET['payment'] ?? ''));
$shippingFilter = trim((string) ($_GET['shipping'] ?? ''));
$itemTypeFilter = strtoupper(trim((string) ($_GET['item_type'] ?? '')));
if ($itemTypeFilter !== '' && !isset($allowedTypes[$itemTypeFilter])) {
  $itemTypeFilter = '';
}
$dateFromFilter = trim((string) ($_GET['date_from'] ?? ''));
if ($dateFromFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromFilter)) {
  $dateFromFilter = '';
}
$dateToFilter = trim((string) ($_GET['date_to'] ?? ''));
if ($dateToFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToFilter)) {
  $dateToFilter = '';
}
$selectedOrderId = (int) ($_GET['custom_order_id'] ?? 0);
$editItemId = (int) ($_GET['edit_item_id'] ?? 0);
$focusNoteId = max(0, (int) ($_GET['focus_note_id'] ?? 0));
$builderType = strtoupper(trim((string) ($_GET['builder_type'] ?? '')));

$listRows = [];
$selectedOrder = null;
$editItem = null;
$relatedOrders = [];
$moduleLoadError = null;

$where = [];
$sequences = ['SO' => 0, 'GO' => 0, 'SC' => 0];
$suggestions = [];
$tabCounts = array_fill_keys(array_keys($customOrderTabs), 0);
$draftStatusDefinitions = [];
$draftStatusCounts = [];
$draftLegacyItemTypes = [];

try {
  $draftStatusDefinitions = customOrdersDraftStatusDefinitions($conn);
  $draftStatusCounts = array_fill_keys(array_keys($draftStatusDefinitions), ['qty' => 0, 'orders' => 0]);
  foreach (array_keys($allowedTypes) as $legacyTypeCode) {
    $legacyStatusItem = [
      'item_type_code' => $legacyTypeCode,
      'sku' => 'MANUAL',
      'custom_label' => '',
      'internal_options_json' => '{}',
    ];
    if (isset(customOrdersItemStatusDefinitions($conn, $legacyStatusItem, true)['DRAFT_✗'])) {
      $draftLegacyItemTypes[] = $legacyTypeCode;
    }
  }
  if ($draftStatusFilter !== '' && !isset($draftStatusDefinitions[$draftStatusFilter])) {
    $draftStatusFilter = '';
  }

  $filterWhere = [];
  if ($query !== '') {
    $safe = '%' . $conn->real_escape_string($query) . '%';
    $filterWhere[] = "(co.internal_code LIKE '$safe' OR co.official_order_number LIKE '$safe' OR co.customer_name LIKE '$safe' OR co.social_handle LIKE '$safe' OR co.customer_email LIKE '$safe' OR co.customer_phone LIKE '$safe' OR co.billing_name LIKE '$safe' OR co.shipping_name LIKE '$safe' OR co.billing_email LIKE '$safe' OR co.shipping_email LIKE '$safe' OR co.billing_phone LIKE '$safe' OR co.shipping_phone LIKE '$safe' OR co.billing_company LIKE '$safe' OR co.shipping_company LIKE '$safe' OR co.billing_company_id LIKE '$safe' OR co.shipping_company_id LIKE '$safe')";
  }
  if ($difficultyFilter > 0) {
    $filterWhere[] = 'COALESCE(co.complexity_level, 1) = ' . (int) $difficultyFilter;
  }
  if ($ownerFilter > 0) {
    $filterWhere[] = 'co.owner_employee_id = ' . (int) $ownerFilter;
  }
  if ($countryFilter !== '') {
    $safeCountry = $conn->real_escape_string($countryFilter);
    $filterWhere[] = "UPPER(COALESCE(NULLIF(co.customer_country, ''), NULLIF(co.shipping_country, ''), NULLIF(co.billing_country, ''), '')) = '{$safeCountry}'";
  }
  if ($sourceFilter !== '') {
    $safeSource = $conn->real_escape_string($sourceFilter);
    $filterWhere[] = "LOWER(TRIM(co.source_channel)) = LOWER('{$safeSource}')";
  }
  if ($paymentFilter !== '') {
    $safePayment = $conn->real_escape_string($paymentFilter);
    $filterWhere[] = "LOWER(TRIM(co.payment_method)) = LOWER('{$safePayment}')";
  }
  if ($shippingFilter !== '') {
    $safeShipping = $conn->real_escape_string($shippingFilter);
    $filterWhere[] = "LOWER(TRIM(co.shipping_method)) = LOWER('{$safeShipping}')";
  }
  if ($itemTypeFilter !== '') {
    $safeItemType = $conn->real_escape_string($itemTypeFilter);
    $filterWhere[] = "EXISTS (SELECT 1 FROM custom_order_items filter_item WHERE filter_item.custom_order_id = co.id AND UPPER(filter_item.item_type_code) = '{$safeItemType}')";
  }
  if ($dateFromFilter !== '') {
    $safeDateFrom = $conn->real_escape_string($dateFromFilter);
    $filterWhere[] = "DATE(co.updated_at) >= '{$safeDateFrom}'";
  }
  if ($dateToFilter !== '') {
    $safeDateTo = $conn->real_escape_string($dateToFilter);
    $filterWhere[] = "DATE(co.updated_at) <= '{$safeDateTo}'";
  }

  $where = $filterWhere;
  if ($draftStatusFilter !== '') {
    $tabFilter = 'all';
    $safeDraftStatus = $conn->real_escape_string($draftStatusFilter);
    $legacyDraftTypesSql = implode(',', array_map(static function (string $typeCode) use ($conn): string {
      return "'" . $conn->real_escape_string($typeCode) . "'";
    }, $draftLegacyItemTypes));
    $legacyDraftClause = $draftStatusFilter === 'DRAFT_✗' && $legacyDraftTypesSql !== ''
      ? " OR (draft_item.status = 'DRAFT' AND UPPER(draft_item.item_type_code) IN ({$legacyDraftTypesSql}))"
      : '';
    $where[] = "EXISTS (
      SELECT 1 FROM custom_order_items draft_item
      WHERE draft_item.custom_order_id = co.id
        AND (draft_item.status = '{$safeDraftStatus}'{$legacyDraftClause})
    ) AND co.production_order_id IS NULL";
  } elseif ($tabFilter === 'open_so') {
    $where[] = "TRIM(COALESCE(co.official_order_number, '')) <> '' AND co.status NOT IN ('LEAD', 'EXPORTED', 'CANCELLED', 'DEAD') AND COALESCE(co.production_order_id, 0) <= 0";
  } else {
    $activeTabMeta = $customOrderTabs[$tabFilter] ?? [];
    $tabStatus = strtoupper(trim((string) ($activeTabMeta['status'] ?? '')));
    if ($tabStatus !== '') {
      $where[] = "co.status = '" . $conn->real_escape_string($tabStatus) . "'";
    }
  }
  $sql = "
    SELECT
      co.*,
      TRIM(CONCAT_WS(' ', eo.firstname, eo.lastname)) AS owner_name,
      eo.photo AS owner_photo,
      COALESCE(item_stats.item_count, 0) AS item_count,
      COALESCE(item_stats.item_total, 0) AS item_total,
      COALESCE(item_stats.upsell_total, 0) AS upsell_total,
      COALESCE(item_stats.item_types, '') AS item_types,
      COALESCE(payment_stats.deposit_total, 0) AS deposit_total,
      COALESCE(payment_stats.paid_total, 0) AS paid_total,
      po.traffic_light AS production_traffic_light,
      po.traffic_blocker AS production_traffic_blocker,
      po.traffic_summary_json AS production_traffic_summary_json
    FROM custom_orders co
    LEFT JOIN employees eo ON eo.id = co.owner_employee_id
    LEFT JOIN orders po ON po.id = co.production_order_id
    LEFT JOIN (
      SELECT
        custom_order_id,
        COUNT(*) AS item_count,
        SUM(qty * unit_price) AS item_total,
        SUM(CASE WHEN is_upsell = 1 THEN qty * unit_price ELSE 0 END) AS upsell_total,
        GROUP_CONCAT(DISTINCT UPPER(item_type_code) ORDER BY item_type_code SEPARATOR '') AS item_types
      FROM custom_order_items
      GROUP BY custom_order_id
    ) item_stats ON item_stats.custom_order_id = co.id
    LEFT JOIN (
      SELECT
        custom_order_id,
        SUM(CASE WHEN payment_kind IN ('DEPOSIT', 'EXTRA_DEPOSIT') THEN amount ELSE 0 END) AS deposit_total,
        SUM(CASE WHEN payment_kind = 'REFUND' THEN -amount ELSE amount END) AS paid_total
      FROM custom_order_payments
      GROUP BY custom_order_id
    ) payment_stats ON payment_stats.custom_order_id = co.id
  ";
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY co.updated_at DESC, co.id DESC LIMIT 300';
  $res = $conn->query($sql);
  if (!$res) {
    throw new RuntimeException('Custom orders list query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $listRows[] = $row;
  }

  $selectedOrder = $selectedOrderId > 0 ? customOrdersGetOrder($conn, $selectedOrderId) : null;
  if ($selectedOrder && $editItemId > 0) {
    foreach ($selectedOrder['items'] as $item) {
      if ((int) $item['id'] === $editItemId) {
        $editItem = $item;
        break;
      }
    }
  }

  $res = $conn->query('SELECT prefix_code, current_value FROM custom_order_number_sequences');
  if (!$res) {
    throw new RuntimeException('Custom order sequences query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $sequences[(string) $row['prefix_code']] = (int) $row['current_value'];
  }

  $res = $conn->query("
    SELECT display_name AS name, social_handle, email, phone, country
    FROM custom_order_contacts
    ORDER BY last_used_at DESC, updated_at DESC
    LIMIT 250
  ");
  if (!$res) {
    throw new RuntimeException('Custom order contacts query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $suggestions[] = $row;
  }

  $res = $conn->query("
    SELECT name, '' AS social_handle, email, phone, '' AS country
    FROM customers
    ORDER BY created_at DESC
    LIMIT 150
  ");
  if (!$res) {
    throw new RuntimeException('Customers suggestion query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $suggestions[] = $row;
  }

  $countWhereSql = $filterWhere ? ' WHERE ' . implode(' AND ', $filterWhere) : '';
  $res = $conn->query("
    SELECT
      COUNT(*) AS all_count,
      COALESCE(SUM(CASE WHEN co.status = 'LEAD' THEN 1 ELSE 0 END), 0) AS lead_count,
      COALESCE(SUM(CASE WHEN TRIM(COALESCE(co.official_order_number, '')) <> '' AND co.status NOT IN ('LEAD', 'EXPORTED', 'CANCELLED', 'DEAD') AND COALESCE(co.production_order_id, 0) <= 0 THEN 1 ELSE 0 END), 0) AS open_so_count,
      COALESCE(SUM(CASE WHEN co.status = 'DEPOSIT_PAID' THEN 1 ELSE 0 END), 0) AS deposit_paid_count,
      COALESCE(SUM(CASE WHEN co.status = 'CONTACT_CUSTOMER' THEN 1 ELSE 0 END), 0) AS contact_customer_count,
      COALESCE(SUM(CASE WHEN co.status = 'CUSTOMER_CONTACTED' THEN 1 ELSE 0 END), 0) AS customer_contacted_count
    FROM custom_orders co
    {$countWhereSql}
  ");
  if ($res && ($row = $res->fetch_assoc())) {
    $tabCounts['all'] = (int) ($row['all_count'] ?? 0);
    $tabCounts['lead'] = (int) ($row['lead_count'] ?? 0);
    $tabCounts['open_so'] = (int) ($row['open_so_count'] ?? 0);
    $tabCounts['deposit_paid'] = (int) ($row['deposit_paid_count'] ?? 0);
    $tabCounts['contact_customer'] = (int) ($row['contact_customer_count'] ?? 0);
    $tabCounts['customer_contacted'] = (int) ($row['customer_contacted_count'] ?? 0);
  }
  if ($draftStatusDefinitions) {
    $draftCodes = array_map(static function (string $code) use ($conn): string {
      return "'" . $conn->real_escape_string($code) . "'";
    }, array_keys($draftStatusDefinitions));
    $legacyDraftTypesSql = implode(',', array_map(static function (string $typeCode) use ($conn): string {
      return "'" . $conn->real_escape_string($typeCode) . "'";
    }, $draftLegacyItemTypes));
    $legacyDraftCountClause = $legacyDraftTypesSql !== ''
      ? " OR (coi.status = 'DRAFT' AND UPPER(coi.item_type_code) IN ({$legacyDraftTypesSql}))"
      : '';
    $draftCountWhere = $filterWhere;
    $draftCountWhere[] = "(coi.status IN (" . implode(',', $draftCodes) . "){$legacyDraftCountClause})";
    $draftCountWhere[] = 'co.production_order_id IS NULL';
    $res = $conn->query("
      SELECT coi.status, SUM(coi.qty) AS qty_count, COUNT(DISTINCT coi.custom_order_id) AS order_count
      FROM custom_order_items coi
      INNER JOIN custom_orders co ON co.id = coi.custom_order_id
      WHERE " . implode(' AND ', $draftCountWhere) . "
      GROUP BY coi.status
    ");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $code = strtoupper(trim((string) ($row['status'] ?? '')));
        if ($code === 'DRAFT' && isset($draftStatusDefinitions['DRAFT_✗'])) {
          $code = 'DRAFT_✗';
        }
        if (!isset($draftStatusCounts[$code])) {
          continue;
        }
        $draftStatusCounts[$code]['qty'] += (int) ($row['qty_count'] ?? 0);
        $draftStatusCounts[$code]['orders'] += (int) ($row['order_count'] ?? 0);
      }
      $res->free();
    }
  }

  if ($selectedOrder) {
    $relatedWhere = [];
    if ((int) ($selectedOrder['contact_directory_id'] ?? 0) > 0) {
      $relatedWhere[] = 'co.contact_directory_id = ' . (int) $selectedOrder['contact_directory_id'];
    }

    $relatedEmail = trim((string) ($selectedOrder['customer_email'] ?? ''));
    if ($relatedEmail !== '') {
      $relatedWhere[] = "LOWER(TRIM(co.customer_email)) = '" . $conn->real_escape_string(strtolower($relatedEmail)) . "'";
    }

    $relatedHandle = trim((string) ($selectedOrder['social_handle'] ?? ''));
    if ($relatedHandle !== '') {
      $relatedWhere[] = "LOWER(TRIM(co.social_handle)) = '" . $conn->real_escape_string(strtolower($relatedHandle)) . "'";
    }

    $relatedName = trim((string) ($selectedOrder['customer_name'] ?? ''));
    if ($relatedName !== '') {
      $relatedWhere[] = "LOWER(TRIM(co.customer_name)) = '" . $conn->real_escape_string(strtolower($relatedName)) . "'";
    }

    if ($relatedWhere) {
      $relatedSql = "
        SELECT
          co.id,
          co.internal_code,
          co.official_order_number,
          co.status,
          co.customer_name,
          co.social_handle,
          co.customer_email,
          co.customer_country,
          co.currency,
          co.shipping_price,
          co.updated_at,
          co.production_order_id,
          TRIM(CONCAT_WS(' ', eo.firstname, eo.lastname)) AS owner_name,
          COALESCE(item_stats.item_count, 0) AS item_count,
          COALESCE(item_stats.item_total, 0) AS item_total,
          COALESCE(payment_stats.paid_total, 0) AS paid_total
        FROM custom_orders co
        LEFT JOIN employees eo ON eo.id = co.owner_employee_id
        LEFT JOIN (
          SELECT
            custom_order_id,
            COUNT(*) AS item_count,
            SUM(qty * unit_price) AS item_total
          FROM custom_order_items
          GROUP BY custom_order_id
        ) item_stats ON item_stats.custom_order_id = co.id
        LEFT JOIN (
          SELECT
            custom_order_id,
            SUM(CASE WHEN payment_kind = 'REFUND' THEN -amount ELSE amount END) AS paid_total
          FROM custom_order_payments
          GROUP BY custom_order_id
        ) payment_stats ON payment_stats.custom_order_id = co.id
        WHERE (" . implode(' OR ', $relatedWhere) . ")
        ORDER BY co.updated_at DESC, co.id DESC
        LIMIT 25
      ";
      $res = $conn->query($relatedSql);
      if ($res) {
        while ($row = $res->fetch_assoc()) {
          $relatedOrders[] = $row;
        }
      }
    }
  }
} catch (Throwable $e) {
  $moduleLoadError = $e->getMessage();
}

function h($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function customOrderFormatMultiline(string $value): string
{
  $normalized = str_replace(["\r\n", "\r"], "\n", $value);
  $lines = array_map('trim', explode("\n", $normalized));
  return h(trim(implode("\n", $lines)));
}

function selectedText(array $map, string $key): string
{
  return $map[$key] ?? $key;
}

function customOrderComplexityLabel(int $level, array $options): string
{
  if ($level <= 0) {
    $level = 1;
  }
  return $options[$level] ?? ('Level ' . $level);
}

function customOrderFilterActive($value): string
{
  if (is_int($value) || is_float($value)) {
    return (int) $value !== 0 ? 'filter-active' : '';
  }
  return trim((string) $value) !== '' ? 'filter-active' : '';
}

function customOrderOptionsWithCurrent(array $options, ?string $currentValue): array
{
  $currentValue = trim((string) $currentValue);
  if ($currentValue !== '' && !in_array($currentValue, $options, true)) {
    $options[] = $currentValue;
  }
  return $options;
}

function customOrderBuildUrl(?int $orderId = null, array $extraParams = [], bool $includeOrder = true): string
{
  global $tabFilter, $draftStatusFilter, $query, $customOrderHelpLang, $editItemId, $difficultyFilter, $ownerFilter, $countryFilter, $sourceFilter, $paymentFilter, $shippingFilter, $itemTypeFilter, $dateFromFilter, $dateToFilter;

  $params = ['page' => 'custom_orders'];
  if ($tabFilter !== 'all') {
    $params['tab'] = $tabFilter;
  }
  if ($draftStatusFilter !== '') {
    $params['draft_status'] = $draftStatusFilter;
  }
  if ($query !== '') {
    $params['q'] = $query;
  }
  if ($difficultyFilter > 0) {
    $params['difficulty'] = (string) $difficultyFilter;
  }
  if ($ownerFilter > 0) {
    $params['owner'] = (string) $ownerFilter;
  }
  if ($countryFilter !== '') {
    $params['country'] = $countryFilter;
  }
  if ($sourceFilter !== '') {
    $params['source'] = $sourceFilter;
  }
  if ($paymentFilter !== '') {
    $params['payment'] = $paymentFilter;
  }
  if ($shippingFilter !== '') {
    $params['shipping'] = $shippingFilter;
  }
  if ($itemTypeFilter !== '') {
    $params['item_type'] = $itemTypeFilter;
  }
  if ($dateFromFilter !== '') {
    $params['date_from'] = $dateFromFilter;
  }
  if ($dateToFilter !== '') {
    $params['date_to'] = $dateToFilter;
  }
  if ($customOrderHelpLang !== '') {
    $params['help_lang'] = $customOrderHelpLang;
  }
  if ($includeOrder && $orderId !== null && $orderId > 0) {
    $params['custom_order_id'] = $orderId;
  }
  if ($includeOrder && $editItemId > 0 && !array_key_exists('edit_item_id', $extraParams) && $orderId !== null && $orderId > 0) {
    $params['edit_item_id'] = $editItemId;
  }
  foreach ($extraParams as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
      continue;
    }
    $params[$key] = (string) $value;
  }

  return 'index.php?' . http_build_query($params);
}

function customOrderResolveHelpLanguage(): string
{
  $allowed = ['sk', 'en'];

  if (isset($_GET['help_lang'])) {
    $requested = strtolower(trim((string) $_GET['help_lang']));
    if (in_array($requested, $allowed, true)) {
      $_SESSION['custom_orders_help_lang'] = $requested;
      return $requested;
    }
  }

  $sessionLang = strtolower(trim((string) ($_SESSION['custom_orders_help_lang'] ?? '')));
  if (in_array($sessionLang, $allowed, true)) {
    return $sessionLang;
  }

  return 'sk';
}

function customOrderHelpMap(string $lang = 'sk'): array
{
  $mapSk = [
    'search' => 'Hladaj podla lead cisla, official order number, mena, emailu, telefonu, nicku, firmy alebo company ID.',
    'status_filter' => 'Filtrovanie leadov podla pipeline stavu.',
    'seq_so' => 'Nastav posledne pouzite SO cislo. Dalsia SO objednavka dostane nasledujuce cislo.',
    'seq_go' => 'Nastav posledne pouzite GO cislo pre GrenzGaenger objednavky.',
    'seq_sc' => 'Nastav posledne pouzite SC cislo pre seat cover objednavky.',
    'owner' => 'Customer service clovek, ktory lead aktivne riesi a komunikuje so zakaznikom.',
    'official_prefix' => 'SO pre Scrub custom, GO pre GrenzGaenger, SC pre seat cover custom.',
    'status' => 'Lead = novy kontakt, Deposit Paid = deposit prijaty, Draft ✗ = poziadavka pre grafikov a automaticke SO cislo, dalsie Draft statusy sleduju pripravu navrhu, Contact Customer/Customer Contacted riesia komunikaciu.',
    'complexity_level' => 'Interna narocnost objednavky: Standard, Simple, SO-Custom varianty, Advanced alebo Complex.',
    'source_channel' => 'Odkial prisiel kontakt. Vyber konzistentny kanal zo zoznamu.',
    'social_platform' => 'Platforma, cez ktoru prebieha komunikacia.',
    'social_handle' => 'Nick alebo identifikator zakaznika na socialnej platforme. Po 2 znakoch mozes vybrat ulozeny kontakt a doplnit cely formular.',
    'customer_name' => 'Realne meno zakaznika, ak je zname. Pri zalozeni leadu moze ostat prazdne.',
    'customer_email' => 'Email zakaznika. Pred exportom musi byt vyplneny email alebo telefon.',
    'customer_phone' => 'Telefon zakaznika. Pred exportom musi byt vyplneny email alebo telefon.',
    'customer_country' => 'Pouzivaj 2-letter kod krajiny, napr. DE, FR, US, CA, GB.',
    'bike_brand' => 'Znacka motorky, napr. KTM, Husqvarna, Yamaha.',
    'bike_model' => 'Model motorky, napr. SX 250, TE 300, CRF 450R.',
    'bike_year' => 'Rocnik motorky. Ak treba, dopln presnejsi popis do Bike details.',
    'bike_details' => 'Dolezite doplnky k motorke: restyle plasty, specialna generacia, netypicky fitment, cierny ram a podobne.',
    'rider_name' => 'Meno na grafike, ak sa pouziva.',
    'rider_number' => 'Race number / startovne cislo na grafike.',
    'payment_method' => 'Sposob platby pre finalnu objednavku, napriklad PayPal, Card, Bank Transfer.',
    'billing_name' => 'Fakturacne meno alebo meno zakaznika na fakture.',
    'billing_company' => 'Fakturacna firma, ak sa pouziva.',
    'billing_company_id' => 'ICO / VAT / Company ID pre fakturacnu adresu, ak je potrebne.',
    'billing_street' => 'Ulica a cislo pre fakturacnu adresu.',
    'billing_city' => 'Mesto pre fakturacnu adresu.',
    'billing_zip' => 'PSC / ZIP pre fakturacnu adresu.',
    'billing_country' => '2-letter kod krajiny fakturacnej adresy.',
    'billing_email' => 'Fakturacny email, ak sa ma lisit od shipping alebo customer emailu.',
    'billing_phone' => 'Fakturacny telefon, ak sa ma lisit.',
    'currency' => 'Odporucane 3-letter kody meny: EUR, USD, GBP.',
    'shipping_name' => 'Meno prijemcu. Povinne pre export do production orders.',
    'shipping_company' => 'Volitelna firma prijemcu.',
    'shipping_company_id' => 'ICO / VAT / Company ID pre shipping adresu, ak je potrebne.',
    'shipping_street' => 'Ulica a cislo. Povinne pre export.',
    'shipping_city' => 'Mesto prijemcu. Povinne pre export.',
    'shipping_zip' => 'PSC / ZIP. Povinne pre export.',
    'shipping_country' => '2-letter kod krajiny prijemcu. Povinne pre export.',
    'shipping_email' => 'Volitelny email pre dorucenie, odporucany.',
    'shipping_phone' => 'Volitelny telefon pre dorucenie, odporucany.',
    'shipping_method' => 'Sposob dopravy, napr. FedEx Economy, FedEx Express, DHL.',
    'shipping_price' => 'Cena dopravy bez meny, napr. 14.90.',
    'deposit_revision_limit' => 'Kolko uprav dizajnu je zahrnutych v aktualnom deposite. Standardne 3.',
    'deposit_revision_used' => 'Kolko uprav uz zakaznik minul. Pri prekroceni treba dalsi extra deposit.',
    'last_contact_at' => 'Kedy prebehla posledna komunikacia so zakaznikom.',
    'next_followup_at' => 'Kedy sa ma support k leadu znovu vratit.',
    'dead_order_flag' => 'Oznac lead ako mrtvy, ak neodpoveda alebo z neho realne nebude objednavka.',
    'graphics_brief' => 'Ludsky citatelny brief pre grafika. Co chce zakaznik, styl, farby, smer dizajnu.',
    'bike_photo_urls' => 'Linky na fotky motorky. Idealne jeden link na riadok.',
    'reference_urls' => 'Inspiracie, screenshoty, cloud folder, predosle designy. Jeden zaznam na riadok.',
    'customer_notes' => 'Dolezite dohodnute body, ktore maju zmysel aj po exporte.',
    'internal_notes' => 'Interne poznamky teamu, co nemusite tahat do production poznamky.',
    'payment_kind' => 'DEPOSIT = prvy deposit, EXTRA_DEPOSIT = dalsi deposit, BALANCE = doplatok, REFUND = vratka.',
    'paypal_transaction_id' => 'Presne PayPal transaction ID pre spatne dohladanie platby.',
    'payment_amount' => 'Prijata alebo vracana suma bez meny.',
    'payment_currency' => 'Mena danej platby, najcastejsie EUR.',
    'payment_received_at' => 'Datum a cas prijatia platby.',
    'payment_note' => 'Krátka poznamka, napr. first design deposit alebo extra deposit after 3 revisions.',
    'followup_contacted_at' => 'Datum a cas kontaktovania zakaznika.',
    'followup_channel' => 'Kanal komunikacie, napr. Instagram, WhatsApp, Messenger, Email.',
    'followup_note' => 'Co ste od zakaznika pytali alebo co ste vyriesili.',
    'item_type_code' => 'G = graphics, P = plastics, S = seat cover, F = fitting, T = accessories, M = misc.',
    'item_sku' => 'Produktove oznacenie, ak je zname. Inak moze ostat MANUAL.',
    'item_qty' => 'Pocet kusov. Minimum je 1.',
    'item_unit_price' => 'Cena za 1 kus bez meny.',
    'item_title' => 'Najdolezitejsi nazov polozky. Musi byt jasne, co sa realne predava.',
    'item_custom_label' => 'Volitelny doplnkovy interny alebo customer-facing nazov.',
    'item_category_info' => 'Kompatibilita / orientacia, napr. KTM | SX 125 | 2023 | Restyle plastics.',
    'item_option_name' => 'Rider name pre konkretny graphics item.',
    'item_option_number' => 'Rider number pre konkretny graphics item.',
    'item_option_material' => 'Material graphics, napr. Standard, Chrome, More Layers.',
    'item_option_finish' => 'Finish graphics, napr. Gloss, Matte, Frozen.',
    'item_option_grip' => 'Grip parameter, ak dany item dava zmysel.',
    'item_option_tr_swingarms' => 'Doplnkovy transfer / swingarm detail pre item.',
    'item_option_patch_style' => 'Patch style pri seat cover itemoch, napr. No Patch.',
    'item_option_waterproof_seams' => 'Pouzi hlavne pri seat cover itemoch. Typicky Yes alebo No.',
    'item_option_enduro_pocket' => 'Pouzi hlavne pri seat cover itemoch. Typicky Yes alebo No.',
    'item_option_side_brand_patches' => 'Pouzi hlavne pri seat cover itemoch. Typicky Yes/No alebo styl patchov.',
    'item_upsell_source' => 'Preco je tato polozka upsell. Napr. converted from graphics-only.',
    'item_is_upsell' => 'Oznac, ak support dopredal dalsi produkt navyse oproti povodnemu zaujmu.',
    'item_option_note' => 'Volna poznamka ku konkretnemu itemu.',
    'contact_autocomplete' => 'Zadaj aspon 2 znaky z nicku, mena, firmy, emailu, telefonu alebo adresy. Vyber ulozeny kontakt a cely formular sa doplni automaticky.',
    'billing_address' => 'Fakturacne udaje. Pri znamom dealerovi staci zacat pisat do lubovolneho pola a vybrat ho z ponuky.',
    'shipping_address' => 'Adresa realneho dorucenia. Moze byt ina ako fakturacna; tato adresa sa pouzije pri exporte a doprave.',
    'draft_queues' => 'Pocet kusov v jednotlivych draft stavoch. Kliknutim zobrazis objednavky, ktore obsahuju item v danom stave.',
    'list_order_number' => 'Oficialne cislo objednavky a pod nim interny kod leadu.',
    'list_customer' => 'Meno zakaznika alebo firmy z hlavicky custom objednavky.',
    'list_nick' => 'Nick zakaznika na komunikacnej platforme.',
    'list_country' => 'Krajina dorucenia. Vlajka sa nacitava zo shipping country.',
    'list_traffic' => 'Production semafor po exporte. Farba ukazuje, ci workflow napreduje alebo je blokovany.',
    'list_owner' => 'Pracovnik Customer Service, ktory ma lead prideleny.',
    'list_items' => 'Pocet riadkovych poloziek v objednavke.',
    'list_total' => 'Sucet itemov a dopravy v EUR.',
    'list_updated' => 'Cas poslednej ulozenej zmeny custom objednavky.',
    'payments_block' => 'Evidencia prijatych depositov, doplatkov a vratiek. Ovplyvnuje Paid net a Balance due.',
    'production_snapshot' => 'Strucny prehlad produkcnej objednavky po exporte: production ID, faktury a tracking.',
    'order_photos' => 'Fotky priradene k objednavke. Mozes ich vlozit kliknutim alebo pretiahnutim; po exporte zostanu dostupne aj v production objednavke.',
    'followups_block' => 'Naplanovanie dalsieho kontaktu a historia komunikacie so zakaznikom.',
    'products_block' => 'Pridavanie a uprava produktov, ich cien, kompatibility, specifikacii a workflow statusov.',
    'item_assigned' => 'Priradeny pracovnik. V Custom Orders sa zobrazuje vlastnik leadu; produkcne priradenie sa riesi po exporte.',
    'item_category' => 'Vyber Brand, Model a Year range. Kliknutim mozes kompatibilitu kedykolvek opravit.',
    'item_link' => 'Odkaz na produkt alebo externy podklad, ak je pre tento item dostupny.',
    'item_detail' => 'Rozsireny detail itemu a vsetky ulozene hodnoty.',
    'item_action' => 'Aktualny workflow status itemu. Ponuka sa nacitava z Controls podla departmentu a graphics subcategory.',
    'item_waiting' => 'Co momentalne chyba alebo na co sa caka, spolu s ocakavanym datumom.',
    'item_save' => 'Ulozi vsetky zmeny v tomto iteme bez prepinania do osobitneho edit rezimu.',
    'item_delete' => 'Natrvalo odstrani tento item z custom objednavky.',
    'graphics_subcategory' => 'Spresni druh graphics produktu. Vyber meni dostupne specifikacie aj workflow statusy.',
    'category_brand' => 'Znacka motorky, pre ktoru je item urceny.',
    'category_model' => 'Model motorky v ramci vybranej znacky.',
    'category_year_range' => 'Generacia alebo rozsah rokov kompatibility. Po vybere sa zo scrubdata automaticky doplni aj Model Code.',
    'notes_block' => 'Diskusia k objednavke. Upravy a vymazania sa bezpecne ukladaju do obmedzeneho audit trailu a povodny text sa nikdy fyzicky nestrati.',
    'note_type' => 'Customer = poziadavka zakaznika, Internal = interna informacia, Revision = poziadavka na upravu.',
    'note_body' => 'Nova samostatna poznamka, ktora sa po ulozeni prida do historie objednavky.',
    'item_specification' => 'Vyrobna alebo personalizacna specifikacia konkretneho itemu. Dostupne polia zavisia od departmentu a graphics subcategory.',
    'activity_history' => 'Auditna historia ulozenych zmien. Ukazuje kedy, kto a aku operaciu vykonal.',
    'activity_when' => 'Datum a cas zaznamenanej operacie.',
    'activity_who' => 'Pouzivatel alebo system, ktory zmenu vykonal.',
    'activity_action' => 'Typ vykonanej operacie, napriklad uprava hlavicky, itemu alebo platby.',
    'activity_detail' => 'Strucny popis zmeny a upravenych hodnot.',
  ];

  $mapEn = [
    'search' => 'Search by lead number, official order number, name, email, phone, nick, company, or company ID.',
    'status_filter' => 'Filter leads by pipeline status.',
    'seq_so' => 'Set the last used SO number. The next SO order will use the following number.',
    'seq_go' => 'Set the last used GO number for GrenzGaenger orders.',
    'seq_sc' => 'Set the last used SC number for seat cover orders.',
    'owner' => 'Customer service person currently handling this lead and communicating with the customer.',
    'official_prefix' => 'SO for Scrub custom, GO for GrenzGaenger, SC for seat cover custom.',
    'status' => 'Lead = new contact, Deposit Paid = deposit received, Draft ✗ = graphics request and automatic SO number, later Draft statuses track proofing, Contact Customer/Customer Contacted track communication.',
    'complexity_level' => 'Internal order complexity: Standard, Simple, SO-Custom variants, Advanced, or Complex.',
    'source_channel' => 'Where the contact came from. Select a consistent channel from the list.',
    'social_platform' => 'Platform used for communication.',
    'social_handle' => 'Customer nickname or identifier on the social platform. After 2 characters you can select a saved contact and fill the whole form.',
    'customer_name' => 'Customer real name if known. It can stay empty when the lead is first created.',
    'customer_email' => 'Customer email. Email or phone must be filled before export.',
    'customer_phone' => 'Customer phone number. Email or phone must be filled before export.',
    'customer_country' => 'Use a 2-letter country code, for example DE, FR, US, CA, GB.',
    'bike_brand' => 'Bike brand, for example KTM, Husqvarna, Yamaha.',
    'bike_model' => 'Bike model, for example SX 250, TE 300, CRF 450R.',
    'bike_year' => 'Bike year. Add a more precise explanation in Bike details if needed.',
    'bike_details' => 'Important extra bike information such as restyle plastics, special generation, unusual fitment, black frame, and similar notes.',
    'rider_name' => 'Name to appear on the graphics if used.',
    'rider_number' => 'Race number to appear on the graphics.',
    'payment_method' => 'Final payment method for the order, for example PayPal, Card, Bank Transfer.',
    'billing_name' => 'Billing name or customer name to appear on the invoice.',
    'billing_company' => 'Billing company if used.',
    'billing_company_id' => 'VAT / Company ID for the billing address if needed.',
    'billing_street' => 'Street and house number for the billing address.',
    'billing_city' => 'City for the billing address.',
    'billing_zip' => 'Postal code / ZIP for the billing address.',
    'billing_country' => '2-letter country code for the billing address.',
    'billing_email' => 'Billing email if it should differ from customer or shipping email.',
    'billing_phone' => 'Billing phone if it should differ.',
    'currency' => 'Recommended 3-letter currency codes: EUR, USD, GBP.',
    'shipping_name' => 'Recipient name. Required for export to production orders.',
    'shipping_company' => 'Optional recipient company.',
    'shipping_company_id' => 'VAT / Company ID for the shipping address if needed.',
    'shipping_street' => 'Street and house number. Required for export.',
    'shipping_city' => 'Recipient city. Required for export.',
    'shipping_zip' => 'Postal code / ZIP. Required for export.',
    'shipping_country' => '2-letter recipient country code. Required for export.',
    'shipping_email' => 'Optional delivery email, recommended.',
    'shipping_phone' => 'Optional delivery phone number, recommended.',
    'shipping_method' => 'Shipping method, for example FedEx Economy, FedEx Express, DHL.',
    'shipping_price' => 'Shipping price without currency, for example 14.90.',
    'deposit_revision_limit' => 'How many design revisions are included in the current deposit. Standard is 3.',
    'deposit_revision_used' => 'How many revisions the customer has already used. If exceeded, an extra deposit is required.',
    'last_contact_at' => 'When the last communication with the customer happened.',
    'next_followup_at' => 'When support should come back to this lead again.',
    'dead_order_flag' => 'Mark the lead as dead if the customer is unresponsive or the order is no longer realistic.',
    'graphics_brief' => 'Human-readable brief for the designer: what the customer wants, style, colors, and design direction.',
    'bike_photo_urls' => 'Links to bike photos. Ideally one link per line.',
    'reference_urls' => 'Inspiration, screenshots, cloud folder, previous designs. One entry per line.',
    'customer_notes' => 'Important agreed points that should still matter after export.',
    'internal_notes' => 'Internal team notes that do not need to be pushed into production notes.',
    'payment_kind' => 'DEPOSIT = first deposit, EXTRA_DEPOSIT = another deposit, BALANCE = final payment, REFUND = refund.',
    'paypal_transaction_id' => 'Exact PayPal transaction ID for payment lookup.',
    'payment_amount' => 'Received or refunded amount without currency.',
    'payment_currency' => 'Currency of this payment, most often EUR.',
    'payment_received_at' => 'Date and time when the payment was received.',
    'payment_note' => 'Short note, for example first design deposit or extra deposit after 3 revisions.',
    'followup_contacted_at' => 'Date and time when the customer was contacted.',
    'followup_channel' => 'Communication channel, for example Instagram, WhatsApp, Messenger, Email.',
    'followup_note' => 'What you asked the customer for or what was clarified.',
    'item_type_code' => 'G = graphics, P = plastics, S = seat cover, F = fitting, T = accessories, M = misc.',
    'item_sku' => 'Product SKU if known. Otherwise it can stay MANUAL.',
    'item_qty' => 'Quantity. Minimum is 1.',
    'item_unit_price' => 'Price for one piece without currency.',
    'item_title' => 'Most important item name. It must be clear what is actually being sold.',
    'item_custom_label' => 'Optional additional internal or customer-facing label.',
    'item_category_info' => 'Compatibility / orientation, for example KTM | SX 125 | 2023 | Restyle plastics.',
    'item_option_name' => 'Rider name for this specific graphics item.',
    'item_option_number' => 'Rider number for this specific graphics item.',
    'item_option_material' => 'Graphics material, for example Standard, Chrome, More Layers.',
    'item_option_finish' => 'Graphics finish, for example Gloss, Matte, Frozen.',
    'item_option_grip' => 'Grip parameter if relevant for the item.',
    'item_option_tr_swingarms' => 'Additional transfer / swingarm detail for the item.',
    'item_option_patch_style' => 'Patch style for seat cover items, for example No Patch.',
    'item_option_waterproof_seams' => 'Mainly for seat cover items. Typically Yes or No.',
    'item_option_enduro_pocket' => 'Mainly for seat cover items. Typically Yes or No.',
    'item_option_side_brand_patches' => 'Mainly for seat cover items. Typically Yes/No or patch style.',
    'item_upsell_source' => 'Why this item is considered an upsell. For example converted from graphics-only.',
    'item_is_upsell' => 'Mark this if support sold an additional product beyond the customer original interest.',
    'item_option_note' => 'Free-form note for this specific item.',
    'contact_autocomplete' => 'Type at least 2 characters from a nickname, name, company, email, phone, or address. Select a saved contact to fill the whole form.',
    'billing_address' => 'Billing details. For a known dealer, start typing in any field and select the saved profile.',
    'shipping_address' => 'Actual delivery address. It may differ from billing and is used for export and shipping.',
    'draft_queues' => 'Number of items in each draft state. Click a badge to show orders containing an item in that state.',
    'list_order_number' => 'Official order number with the internal lead code below it.',
    'list_customer' => 'Customer or company name stored in the custom-order header.',
    'list_nick' => 'Customer nickname on the communication platform.',
    'list_country' => 'Delivery country. The flag is taken from shipping country.',
    'list_traffic' => 'Production traffic light after export. The color shows progress or a workflow blocker.',
    'list_owner' => 'Customer Service employee responsible for this lead.',
    'list_items' => 'Number of line items in the order.',
    'list_total' => 'Items plus shipping total in EUR.',
    'list_updated' => 'Time of the last saved custom-order change.',
    'payments_block' => 'Received deposits, balances, and refunds. These values affect Paid net and Balance due.',
    'production_snapshot' => 'Compact production overview after export: production ID, invoices, and tracking.',
    'order_photos' => 'Order photos. Click or drag files here; after export they remain available in the production order.',
    'followups_block' => 'Schedule the next contact and review the customer communication history.',
    'products_block' => 'Add and edit products, pricing, compatibility, specifications, and workflow statuses.',
    'item_assigned' => 'Responsible employee. Custom Orders shows the lead owner; production assignment happens after export.',
    'item_category' => 'Select Brand, Model, and Year range. Click again whenever compatibility needs correction.',
    'item_link' => 'Product or external reference link when available for this item.',
    'item_detail' => 'Expanded item detail and all stored values.',
    'item_action' => 'Current item workflow status, loaded from Controls for its department and graphics subcategory.',
    'item_waiting' => 'What is missing or being waited for, together with the expected date.',
    'item_save' => 'Saves every change in this item without opening a separate edit mode.',
    'item_delete' => 'Permanently removes this item from the custom order.',
    'graphics_subcategory' => 'Specifies the graphics product type and changes available specifications and workflow statuses.',
    'category_brand' => 'Bike brand for which the item is intended.',
    'category_model' => 'Bike model within the selected brand.',
    'category_year_range' => 'Compatible generation or year range. Selecting it also loads the Model Code from scrubdata.',
    'notes_block' => 'Order discussion. Edits and deletions are retained in a restricted audit trail, so original text is never physically lost.',
    'note_type' => 'Customer = customer request, Internal = internal information, Revision = requested change.',
    'note_body' => 'A new standalone note that is appended to the order history when saved.',
    'item_specification' => 'Production or personalization specification for this item. Available fields depend on its department and graphics subcategory.',
    'activity_history' => 'Audit history of saved changes, showing when, who, and what operation was performed.',
    'activity_when' => 'Date and time of the recorded operation.',
    'activity_who' => 'User or system process that made the change.',
    'activity_action' => 'Operation type, such as a header, item, or payment update.',
    'activity_detail' => 'Short explanation of the change and modified values.',
  ];

  return $lang === 'en' ? $mapEn : $mapSk;
}

function customOrderHelp(string $key): string
{
  $map = customOrderHelpMap(customOrderResolveHelpLanguage());
  if (empty($map[$key])) {
    return '';
  }

  $text = htmlspecialchars($map[$key], ENT_QUOTES, 'UTF-8');
  return ' <span class="custom-help-icon" data-help="' . $text . '" aria-label="Help" tabindex="0">i</span>';
}

function customOrderInvalid(array $invalidFields, string $key): string
{
  return isset($invalidFields[$key]) ? ' custom-field-invalid' : '';
}

function customOrderLoadSpecDropdownOptions(mysqli $conn, string $specKey): array
{
  $options = [];
  $stmt = $conn->prepare("
    SELECT label, value
    FROM product_spec_options
    WHERE spec_key = ? AND active = 1
    ORDER BY sort_order ASC, id ASC
  ");
  if (!$stmt) {
    return $options;
  }

  $stmt->bind_param('s', $specKey);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $value = trim((string) ($row['value'] ?? ''));
      $label = trim((string) ($row['label'] ?? ''));
      if ($value === '' || $label === '') {
        continue;
      }
      $options[] = ['value' => $value, 'label' => $label];
    }
  }
  $stmt->close();

  return $options;
}

function customOrderFlagIcon(string $countryCode, string $label): string
{
  $countryCode = strtolower(trim($countryCode));
  if (!preg_match('/^[a-z]{2}$/', $countryCode)) {
    return '';
  }

  return '<img src="plugins/flag-icon-css/flags/4x3/' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '.svg" '
    . 'alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" '
    . 'title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" '
    . 'style="width:18px;height:13px;object-fit:cover;border-radius:2px;vertical-align:-2px;box-shadow:0 0 0 1px rgba(255,255,255,.18);">';
}

function customOrderTruncate(string $value, int $limit = 36): string
{
  $value = trim($value);
  if ($value === '') {
    return '<span class="text-muted">-</span>';
  }

  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($value, 'UTF-8') <= $limit) {
      return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    $short = rtrim(mb_substr($value, 0, max(1, $limit - 3), 'UTF-8')) . '...';
  } else {
    if (strlen($value) <= $limit) {
      return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    $short = rtrim(substr($value, 0, max(1, $limit - 3))) . '...';
  }

  return '<span title="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span>';
}

function customOrderCopyButton(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  return ' <button type="button" class="btn btn-xs btn-copy-inline" data-copy="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" title="Copy">📋</button>';
}

function customOrderNoteAuthorAvatar(array $note): string
{
  $authorName = trim((string) ($note['author_name'] ?? ''));
  if ($authorName === '') {
    $authorName = (int) ($note['created_by'] ?? 0) > 0 ? ('Employee #' . (int) $note['created_by']) : 'System';
  }

  $photo = trim((string) ($note['author_photo'] ?? ''));
  if ($photo !== '') {
    return '<img src="images/' . htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') . '" class="custom-note-author-avatar" alt="' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '">';
  }

  $initials = '';
  foreach (preg_split('/\s+/', $authorName) as $namePart) {
    if ($namePart !== '') {
      $initials .= mb_strtoupper(mb_substr($namePart, 0, 1));
    }
  }
  $initials = mb_substr($initials, 0, 2);

  return '<span class="custom-note-author-avatar custom-note-author-fallback" title="' . htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES, 'UTF-8') . '</span>';
}

function customOrderNoteAuditHistoryHtml(array $revisions): string
{
  if (!$revisions) {
    return '';
  }

  $html = '<details class="custom-note-audit-history"><summary>Restricted audit history (' . count($revisions) . ')</summary>';
  foreach ($revisions as $revision) {
    $action = strtoupper(trim((string) ($revision['revision_action'] ?? 'CHANGE')));
    $actor = trim((string) ($revision['actor_name'] ?? ''));
    if ($actor === '') {
      $actor = (int) ($revision['actor_employee_id'] ?? 0) > 0
        ? ('Employee #' . (int) $revision['actor_employee_id'])
        : 'System';
    }
    $when = trim((string) ($revision['created_at'] ?? ''));
    $oldBody = (string) ($revision['old_body'] ?? '');
    $newBody = (string) ($revision['new_body'] ?? '');

    $html .= '<div class="custom-note-audit-revision">';
    $html .= '<strong>' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '</strong> by '
      . htmlspecialchars($actor, ENT_QUOTES, 'UTF-8') . ' at '
      . htmlspecialchars($when, ENT_QUOTES, 'UTF-8');
    $html .= '<div class="custom-note-audit-version"><strong>Before:</strong> '
      . htmlspecialchars($oldBody, ENT_QUOTES, 'UTF-8') . '</div>';
    if ($action !== 'DELETE') {
      $html .= '<div class="custom-note-audit-version"><strong>After:</strong> '
        . htmlspecialchars($newBody, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $html .= '</div>';
  }
  return $html . '</details>';
}

function customOrderBuilderSpecLabel(string $itemTypeCode, array $definition): string
{
  $label = trim((string) ($definition['label'] ?? $definition['spec_key'] ?? ''));
  $sourceKey = trim((string) ($definition['source_key'] ?? ''));
  $itemTypeCode = strtoupper(trim($itemTypeCode));

  if ($itemTypeCode === 'G' && $sourceKey === 'note') {
    return 'Buyer Note';
  }

  return $label;
}

function customOrderBuilderDefinitionIsTextarea(array $definition): bool
{
  foreach (['spec_key', 'source_key', 'label'] as $definitionKey) {
    $normalized = productSpecNormalizeSourceKey((string) ($definition[$definitionKey] ?? ''));
    if ($normalized === 'note' || preg_match('/(?:^|-)(?:buyer-note|my-item-note|note)$/', $normalized)) {
      return true;
    }
  }

  return false;
}

function customOrderItemOptionGroups(mysqli $conn, string $itemTypeCode, array $options): array
{
  $department = customOrdersItemTypeToDepartment($itemTypeCode);
  $sortMap = [];

  foreach (productSpecFieldDefinitions($conn, $department) as $definition) {
    $sourceKey = productSpecNormalizeSourceKey((string) ($definition['source_key'] ?? ''));
    if ($sourceKey === '') {
      continue;
    }

    $sortMap[$sourceKey] = (int) ($definition['field_sort_order'] ?? 999);
  }

  $groups = [
    'spec_rows' => [],
    'note_rows' => [],
  ];

  foreach ($options as $rawKey => $rawValue) {
    if (!is_scalar($rawValue) && $rawValue !== null) {
      continue;
    }

    $value = trim((string) $rawValue);
    if ($value === '') {
      continue;
    }

    $normalizedKey = productSpecNormalizeSourceKey((string) $rawKey);
    if ($normalizedKey === '' || strpos($normalizedKey, '_') === 0) {
      continue;
    }
    if (in_array($normalizedKey, ['category-info', 'name', 'number'], true)) {
      continue;
    }

    $row = [
      'label' => productSpecDisplayLabelForOptionKey($conn, (string) $rawKey, $department),
      'value' => $value,
      'sort_order' => $sortMap[$normalizedKey] ?? 999,
    ];

    if (in_array($normalizedKey, ['note', 'buyer-note', 'my-item-note'], true)) {
      $groups['note_rows'][] = $row;
      continue;
    }

    $groups['spec_rows'][] = $row;
  }

  $sortRows = static function (array &$rows): void {
    usort($rows, static function (array $a, array $b): int {
      $sortCompare = ((int) ($a['sort_order'] ?? 999)) <=> ((int) ($b['sort_order'] ?? 999));
      if ($sortCompare !== 0) {
        return $sortCompare;
      }

      return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
  };

  $sortRows($groups['spec_rows']);
  $sortRows($groups['note_rows']);

  return $groups;
}

function customOrderRenderSpecFieldInput(mysqli $conn, array $definition, array $editOptions, array $selectedOrder, string $cssClass = '', string $extraAttr = ''): string
{
  $specKey = trim((string) ($definition['spec_key'] ?? ''));
  $sourceKey = trim((string) ($definition['source_key'] ?? ''));
  $fieldName = 'spec_' . $specKey;
  $currentValue = trim((string) ($editOptions[$sourceKey] ?? ''));

  if ($currentValue === '' && $sourceKey === 'name') {
    $currentValue = trim((string) ($selectedOrder['rider_name'] ?? ''));
  }
  if ($currentValue === '' && $sourceKey === 'number') {
    $currentValue = trim((string) ($selectedOrder['rider_number'] ?? ''));
  }

  return renderProductSpecField(
    $conn,
    $specKey,
    $currentValue,
    [],
    $cssClass,
    trim('name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '" ' . $extraAttr)
  );
}

$customOrderHelpLang = customOrderResolveHelpLanguage();
$customOrderActiveFilterBadges = [];
if ($difficultyFilter > 0) {
  $customOrderActiveFilterBadges[] = ['label' => 'Difficulty', 'display' => customOrderComplexityLabel($difficultyFilter, $customOrderComplexityOptions)];
}
if ($ownerFilter > 0) {
  $customOrderActiveFilterBadges[] = ['label' => 'Owner', 'display' => $customOrderOwnerOptions[$ownerFilter] ?? ('Employee #' . $ownerFilter)];
}
if ($countryFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'Country', 'display' => $countryFilter];
}
if ($sourceFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'Source', 'display' => $sourceFilter];
}
if ($paymentFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'Payment', 'display' => $paymentFilter];
}
if ($shippingFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'Shipping', 'display' => $shippingFilter];
}
if ($itemTypeFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'Product Type', 'display' => selectedText($allowedTypes, $itemTypeFilter)];
}
if ($query !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'Search', 'display' => '"' . $query . '"'];
}
if ($dateFromFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'From', 'display' => $dateFromFilter];
}
if ($dateToFilter !== '') {
  $customOrderActiveFilterBadges[] = ['label' => 'To', 'display' => $dateToFilter];
}
$customOrdersHasActiveFilters = !empty($customOrderActiveFilterBadges);
$customOrdersFilterCollapseShow = $customOrdersHasActiveFilters ? 'show' : '';
$graphicsMaterialOptions = customOrderLoadSpecDropdownOptions($conn, 'graphics_material');
$graphicsFinishOptions = customOrderLoadSpecDropdownOptions($conn, 'graphics_finish');
$graphicsSubcategoryLabels = customOrdersGraphicsSubcategoryLabels();
$productSpecDefinitions = [
  'G' => productSpecFieldDefinitions($conn, 'G'),
  'P' => productSpecFieldDefinitions($conn, 'P'),
  'S' => productSpecFieldDefinitions($conn, 'S'),
  'F' => productSpecFieldDefinitions($conn, 'F'),
];
$customBuilderStatusMap = [];
foreach (array_keys($allowedTypes) as $statusTypeCode) {
  $statusScopes = $statusTypeCode === 'G' ? array_merge([''], array_keys($graphicsSubcategoryLabels)) : [''];
  foreach ($statusScopes as $statusSubcategory) {
    $statusDummyItem = [
      'item_type_code' => $statusTypeCode,
      'sku' => 'MANUAL',
      'custom_label' => '',
      'internal_options_json' => json_encode(['_subcat' => (string) $statusSubcategory]),
    ];
    $statusMapKey = $statusTypeCode . '|' . strtoupper((string) $statusSubcategory);
    foreach (customOrdersItemStatusDefinitions($conn, $statusDummyItem, true) as $statusCode => $statusMeta) {
      $customBuilderStatusMap[$statusMapKey][$statusCode] = [
        'label' => (string) ($statusMeta['label'] ?? $statusCode),
        'color' => (string) ($statusMeta['color'] ?? ''),
      ];
    }
  }
}
if (!isset(customOrdersAllowedItemTypes()[$builderType])) {
  $builderType = $editItem ? strtoupper((string) ($editItem['item_type_code'] ?? '')) : '';
}

// The regular page always stays in list mode. A selected id is opened below its
// table row through scripts/custom_orders/get_order_detail.php, just like Orders.
$customOrdersDetailRequest = defined('CUSTOM_ORDERS_DETAIL_REQUEST') && CUSTOM_ORDERS_DETAIL_REQUEST;
$customOrdersAutoOpenId = (!$customOrdersDetailRequest && $selectedOrder)
  ? (int) ($selectedOrder['id'] ?? 0)
  : 0;
$customOrdersAutoEditItemId = !$customOrdersDetailRequest ? $editItemId : 0;
$customOrdersAutoFocusNoteId = !$customOrdersDetailRequest ? $focusNoteId : 0;
if (!$customOrdersDetailRequest) {
  $selectedOrder = null;
  $editItem = null;
}
?>
<style>
  .custom-orders-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
  }

  .custom-orders-grid > .custom-orders-panel:first-child {
    display: none;
  }

  /*pozadie fieldsetov */
  .custom-orders-panel {
    border: 1px solid #495057;
    border-radius: 8px;
    background: #20252b;
  }

  .custom-orders-panel .panel-body {
    padding: 14px;
  }

  .custom-orders-list-table-wrap {
    overflow-x: auto;
    overflow-y: hidden;
  }

  .custom-order-list-row {
    border-bottom: 1px solid #343a40;
    padding: 10px 12px;
    display: block;
    color: #f8f9fa;
  }

  /*bočné karty*/
  .custom-order-list-row:hover,
  .custom-order-list-row.active {
    background: #2d343c;
    color: #fff;
    text-decoration: none;
  }

  .custom-order-meta {
    font-size: 12px;
    color: #adb5bd;
  }

  .custom-order-table-row {
    cursor: pointer;
    transition: opacity .2s ease;
  }

  /* Pri otvorenom detaile stlm ostatne objednavky rovnako ako v Orders. */
  #customOrdersTable.table-has-open .custom-order-table-row:not(.order-row-open) {
    opacity: .35;
  }

  #customOrdersTable.table-has-open .custom-order-detail-row {
    opacity: 1 !important;
  }

  .custom-order-table-row:hover td {
    background: rgba(60, 141, 188, .12);
  }

  .custom-order-table-row.order-row-open td {
    background: rgba(60, 141, 188, .18);
    border-bottom-color: transparent;
  }

  .custom-order-detail-row > td {
    padding: 0 !important;
    border-top: 0 !important;
    background: #171b20;
  }

  .custom-order-detail-wrap {
    display: none;
    padding: 14px;
    border: 1px solid rgba(60, 141, 188, .35);
    border-top: 0;
  }

  .custom-orders-toolbar {
    border: 1px solid #495057;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 14px;
    background: #20252b;
  }

  .custom-orders-quick-search form {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 10px;
  }

  .custom-orders-quick-search-field {
    flex: 1 1 420px;
    min-width: 280px;
  }

  .custom-orders-filter-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    margin-bottom: 0;
    border: 1px solid #495057;
    border-radius: 8px 8px 0 0;
    background: #2f363d;
  }

  .custom-orders-filter-toolbar .custom-active-pills {
    display: flex;
    align-items: center;
    flex: 1 1 auto;
    flex-wrap: wrap;
    gap: 6px;
    min-width: 0;
  }

  .custom-orders-filter-actions {
    display: inline-flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 6px;
  }

  .custom-active-filter-pill {
    display: inline-flex;
    align-items: center;
    max-width: 260px;
    padding: 2px 9px;
    border: 1px solid rgba(255, 193, 7, .42);
    border-radius: 999px;
    background: rgba(255, 193, 7, .13);
    color: #ffc107;
    font-size: 12px;
    line-height: 1.45;
    white-space: nowrap;
  }

  .custom-active-filter-pill .pill-label {
    margin-right: 4px;
    opacity: .72;
    font-weight: 400;
  }

  .custom-active-filter-pill .pill-value {
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 700;
  }

  #customOrdersFilterCollapse {
    margin-bottom: 14px;
    border: 1px solid #495057;
    border-top: 0;
    border-radius: 0 0 8px 8px;
    background: #2f363d;
  }

  #customOrdersFilterForm .filter-panel {
    padding: 12px 14px 8px;
  }

  #customOrdersFilterForm .filter-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(135px, 1fr));
    gap: 8px 12px;
    align-items: end;
  }

  #customOrdersFilterForm .filter-search {
    grid-column: span 2;
  }

  #customOrdersFilterForm .filter-active .form-control {
    border-color: #ffc107 !important;
    box-shadow: 0 0 0 1px rgba(255, 193, 7, .32) !important;
  }

  #customOrdersFilterForm .filter-active label {
    color: #ffc107 !important;
    font-weight: 700;
  }

  .custom-orders-filter-submit-row {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 10px;
  }

  .custom-complexity-pill {
    display: inline-flex;
    align-items: center;
    max-width: 190px;
    padding: 2px 8px;
    border: 1px solid rgba(23, 162, 184, .42);
    border-radius: 999px;
    background: rgba(23, 162, 184, .12);
    color: #d8f6fb;
    font-size: 12px;
    line-height: 1.35;
  }

  @media (max-width: 1199.98px) {
    #customOrdersFilterForm .filter-grid {
      grid-template-columns: repeat(3, minmax(140px, 1fr));
    }
  }

  @media (max-width: 767.98px) {
    .custom-orders-filter-toolbar {
      align-items: stretch;
      flex-direction: column;
    }
    .custom-orders-filter-actions {
      justify-content: flex-end;
      width: 100%;
    }
    #customOrdersFilterForm .filter-grid {
      grid-template-columns: 1fr;
    }
    #customOrdersFilterForm .filter-search {
      grid-column: auto;
    }
  }

  /* Custom detail intentionally mirrors scripts/orders/get_order_detail.php. */
  .custom-twin-order-card {
    --order-detail-accent: #f0ad00;
    border: 1px solid rgba(255, 255, 255, .12);
    border-left: 4px solid var(--order-detail-accent);
    border-radius: 14px;
    overflow: hidden;
    background: #3d454d;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .20);
    margin-bottom: 14px;
  }

  .custom-twin-order-header {
    padding: 12px 14px;
    background: rgba(255, 255, 255, .025);
    border-bottom: 1px solid rgba(255, 255, 255, .10);
  }

  .custom-twin-header-controls {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 6px;
    min-width: 0;
  }

  .custom-activity-header-btn {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    flex-direction: row;
    flex: 0 0 auto;
    gap: 4px;
    min-width: max-content;
    white-space: nowrap !important;
  }

  .custom-activity-header-btn .badge {
    min-width: 20px;
    margin: 0 !important;
    padding: 2px 5px;
    border-radius: 999px;
    font-size: 10px;
    line-height: 1.15;
  }

  .custom-twin-header-controls > .badge {
    flex: 0 0 auto;
    white-space: nowrap;
  }

  .custom-twin-header-controls > select {
    flex: 0 1 170px;
    width: 170px;
    min-width: 125px !important;
  }
  .custom-twin-header-controls > .custom-complexity-control {
    flex: 0 1 230px;
    width: 230px;
    min-width: 180px !important;
  }

  .custom-twin-header-controls > .custom-status-control {
    flex: 0 1 190px;
    width: 190px;
    min-width: 155px !important;
  }

  .custom-twin-order-body {
    padding: 14px;
  }

  .custom-twin-summary {
    margin-bottom: 12px;
    line-height: 1.45;
  }

  .custom-twin-edit-card {
    border: 1px solid rgba(255, 193, 7, .55);
    border-radius: 5px;
    overflow: hidden;
    background: #30363c;
  }

  .custom-twin-edit-title {
    padding: 9px 12px;
    background: rgba(0, 0, 0, .12);
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    font-weight: 700;
  }

  .custom-twin-edit-body {
    padding: 12px;
  }

  .custom-twin-edit-body label,
  .custom-twin-order-card label {
    margin-bottom: 3px;
    font-size: 12px;
    font-weight: 600;
  }

  .custom-twin-edit-body .form-control {
    border-color: #68727c;
    background: #30363c;
    color: #f8f9fa;
  }

  .custom-twin-edit-body .form-control::placeholder {
    color: #929ca6;
  }

  .custom-billing-sync-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 4px;
    margin-bottom: 6px;
  }

  .custom-billing-sync-row h6,
  .custom-billing-sync-row .form-check {
    margin: 0;
  }

  .custom-billing-sync-row .form-check {
    display: inline-flex;
    align-items: center;
    width: auto;
    min-height: 0;
    padding-left: 1.2rem;
    white-space: nowrap;
  }

  .custom-billing-fields.is-synced .form-control:disabled {
    opacity: 1;
    background: #2b3137;
    color: #d9e2ec;
    border-color: #58636e;
  }

  .custom-country-state-row {
    display: flex;
    flex-wrap: nowrap;
    gap: 6px;
    width: 100%;
    margin-left: 0;
    margin-right: 0;
  }

  .custom-country-state-row .custom-country-col,
  .custom-country-state-row .custom-state-col {
    min-width: 0;
    padding-left: 0;
    padding-right: 0;
  }

  .custom-country-state-row .custom-country-col {
    flex: 1 1 100%;
    max-width: 100%;
  }

  .custom-country-state-row.has-state .custom-country-col {
    flex: 0 0 calc(58.333333% - 3px);
    max-width: calc(58.333333% - 3px);
  }

  .custom-country-state-row.has-state .custom-state-col {
    flex: 0 0 calc(41.666667% - 3px);
    max-width: calc(41.666667% - 3px);
  }

  .custom-country-select-wrap {
    position: relative;
    width: 100%;
  }

  .custom-country-select-wrap .custom-country-select {
    padding-left: 35px;
  }

  .custom-country-select-wrap.no-flag .custom-country-select {
    padding-left: .5rem;
  }

  .custom-country-flag {
    position: absolute;
    left: 10px;
    top: 50%;
    z-index: 3;
    width: 18px;
    height: 13px;
    transform: translateY(-50%);
    border-radius: 2px;
    background-position: 50%;
    background-repeat: no-repeat;
    background-size: cover;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, .18);
    pointer-events: none;
  }

  .custom-country-flag.is-empty {
    display: none;
  }

  .custom-contact-suggestion-menu {
    position: absolute;
    z-index: 21000;
    display: none;
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #66717c;
    border-radius: 6px;
    background: #252c33;
    box-shadow: 0 12px 28px rgba(0, 0, 0, .45);
  }

  .custom-contact-suggestion-menu.is-open {
    display: block;
  }

  .custom-contact-suggestion-item {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    border-bottom: 1px solid rgba(255, 255, 255, .07);
    background: transparent;
    color: #f3f6f8;
    text-align: left;
  }

  .custom-contact-suggestion-item:hover,
  .custom-contact-suggestion-item:focus {
    outline: none;
    background: rgba(60, 141, 188, .24);
  }

  .custom-contact-suggestion-label {
    display: block;
    font-weight: 700;
  }

  .custom-contact-suggestion-detail {
    display: block;
    margin-top: 2px;
    color: #aab4bd;
    font-size: 11px;
  }

  .custom-twin-header-column-lead {
    padding-bottom: 10px;
    margin-bottom: 10px;
    border-bottom: 1px solid rgba(255, 255, 255, .09);
  }

  .custom-order-value-breakdown-card {
    width: 100%;
    min-height: 0;
    border: 1px solid rgba(23, 162, 184, .45);
    border-radius: 10px;
    background: rgba(23, 162, 184, .06);
    padding: 14px;
  }

  .custom-twin-column-stack {
    gap: 14px;
  }

  .custom-twin-column-stack>.custom-twin-edit-card,
  .custom-twin-column-stack>.custom-order-value-breakdown-card,
  .custom-twin-column-stack>.custom-twin-secondary-card {
    width: 100%;
  }

  .custom-twin-secondary-card {
    margin: 0;
  }

  .custom-order-value-breakdown-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 4px 0;
  }

  .custom-order-value-breakdown-total {
    margin-bottom: 6px;
    padding-bottom: 9px;
    border-bottom: 1px solid rgba(255, 255, 255, .14);
    font-weight: 700;
  }

  .custom-status-tabs {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
  }

  .custom-status-tab-set {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
  }

  .custom-status-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .12);
    background: rgba(255, 255, 255, .04);
    color: #e6edf3;
    font-size: 13px;
    text-decoration: none;
  }

  .custom-status-tab:hover {
    color: #fff;
    text-decoration: none;
    border-color: rgba(255, 255, 255, .24);
    background: rgba(255, 255, 255, .08);
  }

  .custom-status-tab.active {
    background: rgba(60, 141, 188, .24);
    border-color: rgba(60, 141, 188, .55);
    color: #fff;
  }

  .custom-status-tab-count {
    min-width: 20px;
    padding: 1px 7px;
    border-radius: 999px;
    background: rgba(17, 24, 39, .48);
    font-weight: 700;
    text-align: center;
  }

  .custom-order-section-title {
    font-size: 13px;
    letter-spacing: .05em;
    color: #adb5bd;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .custom-order-block {
    margin-bottom: 16px;
  }

  .custom-order-subgrid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }

.custom-field-cluster {
  position: relative;
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 10px;
  background: rgba(255,255,255,.025);
  padding: 34px 12px 12px;
}

.custom-field-cluster-title {
  position: absolute;
  top: 12px;
  left: 12px;

  display: block;
  width: auto;
  margin: 0;
  padding: 0;

  float: none;
  border: 0;

  font-size: 12px;
  font-weight: 700;
  color: #cfd6dc;
  letter-spacing: .05em;
  text-transform: uppercase;
  line-height: 1;
}

.custom-inline-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

  .custom-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(17, 24, 39, .28);
    border: 1px solid rgba(255, 255, 255, .08);
    font-size: 12px;
  }

  .custom-summary-list {
    display: grid;
    gap: 8px;
  }

  .custom-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 10px;
    border-radius: 8px;
    background: rgba(17, 24, 39, .24);
    border: 1px solid rgba(255, 255, 255, .06);
  }

  .custom-summary-row strong {
    color: #adb5bd;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .custom-status-tabs-divider {
    width: 1px;
    align-self: stretch;
    margin: 2px 3px;
    background: rgba(255, 255, 255, .16);
  }

  .custom-draft-status-tab {
    border-color: var(--draft-color, #17a2b8);
    box-shadow: inset 3px 0 0 var(--draft-color, #17a2b8);
  }

  .custom-draft-status-tab.active {
    border-color: var(--draft-color, #17a2b8);
    background: rgba(60, 141, 188, .24);
  }

  .custom-draft-tab-prefix {
    color: #89939d;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    align-self: center;
    margin: 0 2px 0 5px;
  }

  .custom-item-status-select {
    border-left: 4px solid var(--item-status-color, #17a2b8) !important;
  }

  .custom-payment-entry-grid {
    display: grid;
    grid-template-columns: 1.05fr 1.05fr .85fr .7fr 1.05fr;
    gap: 8px;
    align-items: end;
  }

  .custom-payment-note-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: end;
    margin-top: 7px;
  }

  .custom-payment-history {
    margin-top: 9px !important;
    margin-bottom: 0 !important;
  }

  .custom-payment-history td,
  .custom-payment-history th {
    padding-top: 4px !important;
    padding-bottom: 4px !important;
  }

  .custom-production-overview {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 6px;
    margin-bottom: 8px;
  }

  .custom-production-stat,
  .custom-production-record-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    min-width: 0;
    padding: 6px 8px;
    border: 1px solid rgba(255, 255, 255, .06);
    border-radius: 7px;
    background: rgba(17, 24, 39, .24);
  }

  .custom-production-stat strong,
  .custom-production-list-title,
  .custom-production-record-label {
    color: #adb5bd;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .custom-production-lists {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .custom-production-list-title {
    margin-bottom: 4px;
  }

  .custom-production-records {
    display: grid;
    gap: 4px;
  }

  .custom-order-photos-card {
    min-width: 0;
  }

  .custom-order-photo-dropzone {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 74px;
    padding: 9px;
    border: 2px dashed rgba(23, 162, 184, .48);
    border-radius: 8px;
    background: rgba(23, 162, 184, .06);
    text-align: center;
    cursor: pointer;
    transition: border-color .15s ease, background .15s ease;
  }

  .custom-order-photo-dropzone.is-dragover {
    border-color: #17a2b8;
    background: rgba(23, 162, 184, .18);
  }

  .custom-order-photo-progress {
    display: none;
    height: 4px;
    margin-top: 7px;
    overflow: hidden;
    border-radius: 999px;
    background: rgba(255, 255, 255, .12);
  }

  .custom-order-photo-progress > span {
    display: block;
    width: 0;
    height: 100%;
    background: #17a2b8;
  }

  .custom-order-photo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 8px;
  }

  .custom-order-photo-wrap {
    position: relative;
    width: 66px;
    height: 66px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 7px;
    background: rgba(0, 0, 0, .25);
  }

  .custom-order-photo-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: zoom-in;
  }

  .custom-order-photo-delete {
    position: absolute;
    top: 3px;
    right: 3px;
    width: 20px;
    height: 20px;
    padding: 0;
    border-radius: 50%;
    line-height: 18px;
    font-size: 13px;
    font-weight: 700;
  }

  .custom-order-photo-lightbox {
    position: fixed;
    inset: 0;
    z-index: 20000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 44px;
    background: rgba(0, 0, 0, .9);
  }

  .custom-order-photo-lightbox.is-open {
    display: flex;
  }

  .custom-order-photo-lightbox img {
    max-width: 92vw;
    max-height: 88vh;
    object-fit: contain;
  }

  .custom-order-photo-lightbox-close {
    position: absolute;
    top: 14px;
    right: 18px;
  }

  @media (max-width: 1600px) {
    .custom-payment-entry-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  .custom-followup-layout {
    display: grid;
    grid-template-columns: minmax(270px, .75fr) minmax(420px, 1.15fr) minmax(520px, 1.55fr);
    gap: 10px;
    align-items: start;
  }

  .custom-collapsible-panel {
    overflow: hidden;
  }

  .custom-collapsible-toggle {
    display: flex;
    width: 100%;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 14px;
    border: 0;
    background: rgba(60, 141, 188, .08);
    color: #f1f4f7;
    text-align: left;
    cursor: pointer;
  }

  .custom-collapsible-toggle:hover,
  .custom-collapsible-toggle:focus {
    outline: none;
    background: rgba(60, 141, 188, .16);
  }

  .custom-collapsible-toggle-title,
  .custom-collapsible-toggle-meta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .custom-collapsible-toggle-title {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .custom-collapsible-toggle-meta {
    margin-left: auto;
    color: #aeb8c2;
    font-size: 12px;
  }

  .custom-collapsible-toggle-chevron {
    transition: transform .18s ease;
  }

  .custom-collapsible-toggle[aria-expanded="true"] .custom-collapsible-toggle-chevron {
    transform: rotate(180deg);
  }

  .custom-collapsible-panel.is-expanded .custom-collapsible-toggle {
    border-bottom: 1px solid rgba(255, 255, 255, .10);
  }

  .custom-collapsible-body[hidden] {
    display: none !important;
  }

  .custom-followup-card {
    min-width: 0;
    padding: 8px;
    border: 1px solid rgba(255, 255, 255, .07);
    border-radius: 8px;
    background: rgba(17, 24, 39, .18);
  }

  .custom-followup-card-title {
    margin-bottom: 6px;
    color: #adb5bd;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .custom-followup-status-controls {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: end;
  }

  .custom-followup-add-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
  }

  .custom-followup-add-note {
    grid-column: 1 / -1;
  }

  .custom-followup-history-scroll {
    max-height: 142px;
    overflow: auto;
  }

  .custom-followup-history {
    margin: 0 !important;
  }

  .custom-followup-history td,
  .custom-followup-history th {
    padding-top: 4px !important;
    padding-bottom: 4px !important;
  }

  @media (max-width: 1600px) {
    .custom-followup-layout {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .custom-followup-history-card {
      grid-column: 1 / -1;
    }
  }

  @media (max-width: 900px) {
    .custom-followup-layout {
      grid-template-columns: 1fr;
    }

    .custom-followup-history-card {
      grid-column: auto;
    }
  }

  .custom-notes-timeline {
    display: grid;
    gap: 16px;
  }

  .custom-note-thread {
    display: grid;
    gap: 8px;
  }

  .custom-note-entry {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr);
    gap: 10px;
    width: calc(100% - 70px);
    border-left: 3px solid rgba(60, 141, 188, .65);
    border-radius: 8px;
    background: rgba(255, 255, 255, .03);
    padding: 10px 12px;
  }

  .custom-note-author-avatar {
    width: 34px;
    height: 34px;
    border: 2px solid rgba(60, 141, 188, .72);
    border-radius: 50%;
    object-fit: cover;
    background: #20262c;
  }

  .custom-note-author-fallback {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #e8edf2;
    font-size: 11px;
    font-weight: 700;
  }

  .custom-note-entry-content {
    min-width: 0;
  }

  .custom-note-entry-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
    color: #adb5bd;
    font-size: 12px;
  }

  .custom-note-entry-actions {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
  }

  .custom-note-entry-actions form {
    display: inline;
    margin: 0;
  }

  .custom-note-entry.is-deleted {
    border-left-color: rgba(220, 53, 69, .82);
    background: rgba(220, 53, 69, .06);
  }

  .custom-note-audit-badge {
    padding: 2px 6px;
    border: 1px solid rgba(255, 193, 7, .45);
    border-radius: 10px;
    color: #ffd75e;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
  }

  .custom-note-audit-badge.is-deleted {
    border-color: rgba(220, 53, 69, .62);
    color: #ff8e99;
  }

  .custom-note-reply-button {
    padding: 1px 7px;
    border: 0;
    background: transparent;
    color: #5bc0de;
    font-size: 12px;
  }

  .custom-note-reply-button:hover,
  .custom-note-reply-button:focus {
    outline: none;
    color: #9be5f7;
    text-decoration: underline;
  }

  .custom-note-entry-body {
    color: #f8f9fa;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.45;
  }

  .custom-note-replies {
    display: grid;
    gap: 8px;
    margin-left: 64px;
  }

  .custom-note-reply-wrap {
    position: relative;
    padding-left: 34px;
  }

  .custom-note-reply-wrap::before {
    content: "";
    position: absolute;
    left: 0;
    top: -9px;
    width: 28px;
    height: 32px;
    border-left: 2px solid rgba(93, 173, 226, .52);
    border-bottom: 2px solid rgba(93, 173, 226, .52);
    border-radius: 0 0 0 5px;
  }

  .custom-note-entry.is-reply {
    width: 100%;
    border-left-color: rgba(255, 193, 7, .78);
    background: rgba(255, 193, 7, .045);
  }

  .custom-note-reply-form {
    margin: 2px 0 0 98px;
    padding: 10px;
    border: 1px solid rgba(60, 141, 188, .28);
    border-radius: 8px;
    background: rgba(17, 24, 39, .22);
  }

  .custom-note-reply-form[hidden] {
    display: none !important;
  }

  .custom-note-reply-actions,
  .custom-note-compose-actions {
    display: flex;
    justify-content: flex-end;
    gap: 7px;
    margin-top: 7px;
  }

  .custom-note-compose {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid rgba(255, 255, 255, .10);
  }

  @media (max-width: 900px) {
    .custom-note-entry {
      width: 100%;
    }

    .custom-note-replies {
      margin-left: 24px;
    }

    .custom-note-reply-form {
      margin-left: 58px;
    }
  }

  .custom-item-spec-groups {
    display: grid;
    gap: 12px;
  }

  .custom-item-spec-group {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 10px;
    background: rgba(255, 255, 255, .03);
    padding: 12px;
  }

  .custom-item-spec-group[hidden] {
    display: none !important;
  }

  .custom-item-spec-group-title {
    font-size: 12px;
    font-weight: 700;
    color: #cfd6dc;
    letter-spacing: .05em;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .custom-optical-divider {
    height: 1px;
    margin: 18px 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .16), transparent);
  }

  .custom-item-builder-shell {
    border: 1px solid rgba(60, 141, 188, .28);
    border-radius: 12px;
    background: rgba(60, 141, 188, .06);
    padding: 12px;
  }

  .custom-item-builder-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }

  .custom-item-builder-title {
    font-size: 12px;
    font-weight: 700;
    color: #cfd6dc;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .custom-builder-picker {
    display: flex;
    justify-content: flex-start;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
  }

  .custom-builder-picker-label {
    flex: 0 0 220px;
    max-width: 220px;
  }

  .custom-builder-placeholder {
    margin-top: 8px;
    color: #8f9ba7;
    font-size: 13px;
  }

  .custom-builder-order-shell {
    border: 1px solid rgba(255, 255, 255, .1);
    border-radius: 10px;
    background: rgba(255, 255, 255, .015);
    overflow: hidden;
  }

  @media (min-width: 1500px) {
    .custom-builder-order-shell>.table-responsive {
      overflow-x: hidden;
    }
  }

  .custom-builder-order-shell[hidden] {
    display: none !important;
  }

  .custom-builder-subtitle {
    padding: 12px 14px 8px;
    color: #f4f6f8;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
  }

  .custom-builder-order-table {
    margin-bottom: 0;
  }

  .custom-builder-order-table td,
  .custom-builder-order-table th {
    outline: none !important;
    vertical-align: middle;
  }

  .custom-builder-order-table tr.item-repeat-header-row>th {
    background-color: #343a40 !important;
    font-weight: 600;
    font-size: .78rem;
    color: #f0f3f6;
    padding: .35rem .6rem !important;
    border-top: 2px solid rgba(255, 255, 255, .15) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .1) !important;
    border-left: 1px solid rgba(255, 255, 255, .14) !important;
    border-right: 1px solid rgba(255, 255, 255, .14) !important;
    white-space: nowrap;
  }

  .custom-builder-order-table tbody tr.item-info-row>td,
  .custom-builder-order-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .24) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .24) !important;
    background-clip: padding-box;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:first-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:first-child {
    border-left: 3px solid var(--item-accent, #8a8f98) !important;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:last-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:last-child {
    border-right: 1px solid rgba(255, 255, 255, .24) !important;
  }

  .custom-builder-order-table tbody tr.g-item-options-row>td[colspan] {
    border-left: 3px solid var(--item-accent, #17a2b8) !important;
    border-right: 1px solid rgba(255, 255, 255, .24) !important;
  }

  .custom-builder-order-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .32) !important;
    background: rgba(23, 162, 184, .045) !important;
    padding: 5px 8px 7px !important;
  }

  .custom-builder-order-table tbody tr.item-spacer-row>td {
    height: 8px !important;
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
  }

  .custom-builder-order-table tr.item-type-G {
    --item-accent: #28a745;
    --item-bg: rgba(40, 167, 69, .16);
  }

  .custom-builder-order-table tr.item-type-P {
    --item-accent: #17a2b8;
    --item-bg: rgba(23, 162, 184, .14);
  }

  .custom-builder-order-table tr.item-type-S {
    --item-accent: #ebd618;
    --item-bg: rgba(235, 214, 24, .12);
  }

  .custom-builder-order-table tr.item-type-F {
    --item-accent: #fd7e14;
    --item-bg: rgba(253, 126, 20, .13);
  }

  .custom-builder-order-table tr.item-type-T,
  .custom-builder-order-table tr.item-type-M {
    --item-accent: #ffc107;
    --item-bg: rgba(255, 193, 7, .13);
  }

  .custom-builder-order-table tbody tr.item-info-row>td,
  .custom-builder-order-table tbody tr.g-item-options-row>td {
    box-shadow: none !important;
    border-top: 1px solid rgba(255, 255, 255, .18) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .18) !important;
    border-left: 1px solid rgba(255, 255, 255, .18) !important;
    border-right: 0 !important;
    background: var(--item-bg, rgba(255, 255, 255, .035)) !important;
    background-clip: padding-box !important;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:last-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:last-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td[colspan] {
    border-right: 1px solid rgba(255, 255, 255, .18) !important;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:first-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:first-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td[colspan] {
    border-left: 10px solid var(--item-accent, #8a8f98) !important;
  }

  .custom-builder-order-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .26) !important;
  }

  .custom-builder-type-badge {
    min-width: 28px;
    height: 28px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #6c757d;
    color: #fff;
    font-weight: 700;
    text-align: center;
  }

  .custom-builder-assigned-placeholder {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .18);
    background: rgba(255, 255, 255, .04);
    color: #cdd6df;
    font-size: 11px;
    font-weight: 700;
  }

  .custom-builder-order-table .g-options-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
    gap: 6px;
    width: 100%;
  }

  .custom-builder-order-table .g-options-bar .product-spec-label {
    display: flex;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    margin: 0 !important;
    flex-direction: column;
    gap: 4px;
    padding: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 6px;
    background: rgba(255, 255, 255, .025);
    height: 100%;
  }

  .custom-builder-order-table .g-options-bar .product-spec-label select,
  .custom-builder-order-table .g-options-bar .product-spec-label input {
    flex: 1;
  }

  .custom-builder-order-table .product-spec-label-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    color: #d7dee7;
    line-height: 1.1;
  }

  .custom-builder-order-table .g-opt-note-display {
    flex: 1;
    min-height: 31px;
    padding: .25rem .5rem;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: .2rem;
    background-color: transparent;
    color: inherit;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .custom-builder-order-table .custom-builder-mini-btn {
    min-width: 44px;
  }

  .custom-builder-order-table .custom-builder-link-btn {
    padding: .2rem .5rem;
  }

  .custom-order-owner-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    object-fit: cover;
    border: 1px solid rgba(23, 162, 184, .75);
    background: rgba(23, 162, 184, .22);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    vertical-align: middle;
  }

  .custom-order-traffic-badges {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 3px;
    white-space: nowrap;
  }

  .custom-order-traffic-badge {
    min-width: 24px;
    padding: 4px 5px;
    border-radius: 5px;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    text-align: center;
  }

  .custom-order-traffic-badge.is-green { background: #28a745; }
  .custom-order-traffic-badge.is-orange { background: #ffc107; color: #212529; }
  .custom-order-traffic-badge.is-red { background: #dc3545; }

  .custom-existing-items .custom-builder-order-shell {
    margin-bottom: 18px;
  }

  .custom-existing-items .custom-builder-order-table .form-control:disabled,
  .custom-existing-items .custom-builder-order-table .form-control[readonly] {
    opacity: 1;
    background: #30363c;
    color: #f4f6f8;
    border-color: #65717b;
  }

  .custom-existing-items .custom-builder-order-table textarea.form-control {
    min-height: 34px;
    resize: vertical;
  }

  .custom-existing-item-actions {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
  }

  .custom-existing-item-meta-edit {
    display: grid;
    grid-template-columns: minmax(90px, .8fr) minmax(110px, 1.2fr);
    gap: 4px;
  }

  .custom-existing-item-meta-edit .form-control {
    height: calc(1.5em + .35rem + 2px);
    padding: .1rem .35rem;
    font-size: 11px;
  }

  .custom-existing-item-upsell-edit {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 6px;
  }

  .custom-existing-item-upsell-edit .form-check {
    white-space: nowrap;
  }

  .custom-category-info-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    min-height: 31px;
    padding: 3px 8px;
    overflow: hidden;
    text-align: left;
  }

  .custom-category-info-trigger .custom-category-info-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .custom-category-info-trigger.is-empty .custom-category-info-text {
    color: #9ba7b2;
  }

  .custom-category-picker-modal .modal-content {
    color: #f4f6f8;
    background: #252c33;
    border: 1px solid #56616b;
  }

  .custom-category-picker-modal .modal-header,
  .custom-category-picker-modal .modal-footer {
    border-color: #495057;
  }

  .custom-category-picker-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-category-picker-step label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 5px;
  }

  .custom-category-picker-step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    color: #fff;
    background: #337ab7;
    font-size: 11px;
    font-weight: 700;
  }

  .custom-category-picker-preview {
    min-height: 38px;
    margin-top: 14px;
    padding: 8px 10px;
    border: 1px solid #495057;
    border-radius: 4px;
    color: #dbe8f3;
    background: #1e252b;
  }

  @media (max-width: 767px) {
    .custom-category-picker-steps {
      grid-template-columns: 1fr;
    }
  }

  .custom-kpi {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }

  /*vrchné karty*/
  .custom-kpi-card {
    border: 1px solid #495057;
    border-radius: 8px;
    padding: 10px;
    background: #252c33;
  }

  .custom-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-form-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-form-full {
    grid-column: 1 / -1;
  }

  .custom-mini-table td,
  .custom-mini-table th {
    padding: 6px 8px;
    font-size: 13px;
  }

  .custom-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .custom-field-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 .12rem rgba(220, 53, 69, .25) !important;

    background: rgba(220, 53, 69, .10) !important;
  }

  label.custom-field-invalid,
  .form-check-label.custom-field-invalid {
    color: #ff9ea7 !important;
  }

  .custom-panel-invalid {
    border-color: rgba(220, 53, 69, .8) !important;
    box-shadow: inset 0 0 0 1px rgba(220, 53, 69, .25);
  }

  .custom-help-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    margin-left: 5px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, .35);
    color: #9ed6ff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    cursor: help;
    vertical-align: middle;
    background: rgba(60, 141, 188, .16);
  }

  .custom-help-icon::before {
    content: "";
    position: absolute;
    left: 50%;
    bottom: calc(100% + 3px);
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-top-color: rgba(17, 24, 39, .96);
    opacity: 0;
    visibility: hidden;
    transition: opacity .12s ease, visibility .12s ease;
    pointer-events: none;
    z-index: 1080;
  }

  .custom-help-icon::after {
    content: attr(data-help);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 14px);
    transform: translateX(-50%);
    min-width: 220px;
    max-width: 320px;
    padding: 8px 10px;
    border-radius: 8px;
    background: rgba(17, 24, 39, .96);
    color: #f8f9fa;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.35;
    text-align: left;
    white-space: normal;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .35);
    opacity: 0;
    visibility: hidden;
    transition: opacity .12s ease, visibility .12s ease;
    pointer-events: none;
    z-index: 1080;
  }

  .custom-help-icon:hover,
  .custom-help-icon:focus {
    color: #fff;
    border-color: rgba(255, 255, 255, .55);
    background: rgba(60, 141, 188, .42);
    outline: none;
  }

  .custom-help-icon:hover::before,
  .custom-help-icon:hover::after,
  .custom-help-icon:focus::before,
  .custom-help-icon:focus::after {
    opacity: 1;
    visibility: visible;
  }

  .custom-help-icon.help-align-left::before {
    left: 8px;
    transform: translateX(-50%);
  }

  .custom-help-icon.help-align-left::after {
    left: -8px;
    right: auto;
    transform: none;
  }

  .custom-help-icon.help-align-right::before {
    left: auto;
    right: 8px;
    transform: translateX(50%);
  }

  .custom-help-icon.help-align-right::after {
    left: auto;
    right: -8px;
    transform: none;
  }

  .custom-help-icon.help-align-bottom::before {
    top: calc(100% + 3px);
    bottom: auto;
    border-top-color: transparent;
    border-bottom-color: rgba(17, 24, 39, .96);
  }

  .custom-help-icon.help-align-bottom::after {
    top: calc(100% + 14px);
    bottom: auto;
  }

  /* Table headers live inside horizontally scrollable wrappers. Open their help
     bubbles downward so the explanation is not clipped above the table. */
  th .custom-help-icon::before {
    top: calc(100% + 3px);
    bottom: auto;
    border-top-color: transparent;
    border-bottom-color: rgba(17, 24, 39, .96);
  }

  th .custom-help-icon::after {
    top: calc(100% + 14px);
    bottom: auto;
  }

  .custom-note-edit-form {
    margin-top: 9px;
    padding: 9px;
    border: 1px solid rgba(255, 193, 7, .35);
    border-radius: 7px;
    background: rgba(17, 24, 39, .28);
  }

  .custom-note-edit-form[hidden] {
    display: none !important;
  }

  .custom-note-audit-history {
    margin-top: 9px;
    padding-top: 8px;
    border-top: 1px dashed rgba(255, 193, 7, .26);
    color: #c8cdd2;
    font-size: 11px;
  }

  .custom-note-audit-history summary {
    cursor: pointer;
    color: #ffd75e;
  }

  .custom-note-audit-revision {
    margin-top: 7px;
    padding: 7px 9px;
    border-left: 2px solid rgba(255, 193, 7, .55);
    background: rgba(0, 0, 0, .14);
  }

  .custom-note-audit-version {
    margin-top: 4px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  /* Payment history is at the bottom of its collapsible panel. Its table-header
     tooltips must open upwards, otherwise the panel clips their lower half. */
  #custom-order-payments-block .custom-payment-history th .custom-help-icon::before {
    top: auto;
    bottom: calc(100% + 3px);
    border-top-color: rgba(17, 24, 39, .96);
    border-bottom-color: transparent;
  }

  #custom-order-payments-block .custom-payment-history th .custom-help-icon::after {
    top: auto;
    bottom: calc(100% + 14px);
  }

  .custom-help-lang-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  /** design modalu. 
      Najdôležitejšie bloky pre modal sú:
    .custom-item-modal .modal-content
    .custom-item-modal .modal-header
    .custom-item-modal .modal-body
    .custom-item-section
    .custom-item-detail-row
    .custom-item-summary-card
    .custom-item-meta-pill
    .custom-item-note-box
    Ak budú frflať na kontrast, najrýchlejšie bude doladť hlavne tieto hodnoty:
    background
    border
    color
    prípadne box-shadow **/
    
  .custom-item-modal .modal-content {
    border: 1px solid #56606b;
    border-radius: 12px;
    overflow: hidden;
    background: #242a31;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .45);
  }

  .custom-item-modal .modal-header {
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    background: linear-gradient(180deg, rgba(255, 255, 255, .03), rgba(255, 255, 255, 0));
    align-items: flex-start;
  }

  .custom-item-modal .modal-title {
    font-size: 20px;
    font-weight: 700;
    line-height: 1.2;
  }

  .custom-item-modal .modal-subtitle {
    margin-top: 4px;
    color: #adb5bd;
    font-size: 12px;
    letter-spacing: .04em;
    text-transform: uppercase;
  }

  .custom-item-modal .modal-body {
    padding: 18px;
    background:
      radial-gradient(circle at top right, rgba(60, 141, 188, .10), transparent 34%),
      #242a31;
  }

  .custom-item-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
  }

  .custom-item-summary-card {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 10px;
    padding: 10px 12px;
    background: rgba(255, 255, 255, .03);
  }

  .custom-item-summary-card-label {
    color: #9aa4ad;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 4px;
  }

  .custom-item-summary-card-value {
    font-size: 15px;
    font-weight: 700;
    color: #f8f9fa;
    word-break: break-word;
  }

  .custom-item-sections {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 14px;
  }

  .custom-item-section {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    background: rgba(255, 255, 255, .03);
    padding: 14px;
  }

  .custom-item-section-title {
    color: #cfd6dc;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .custom-item-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .custom-item-detail-row {
    border: 1px solid rgba(255, 255, 255, .06);
    border-radius: 10px;
    padding: 10px 12px;
    background: rgba(17, 24, 39, .18);
  }

  .custom-item-detail-row.is-full {
    grid-column: 1 / -1;
  }

  .custom-item-detail-label {
    color: #98a3ad;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 4px;
  }

  .custom-item-detail-value {
    color: #f8f9fa;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.35;
    word-break: break-word;
  }

  .custom-item-meta-list {
    display: grid;
    gap: 8px;
  }

  .custom-item-meta-pill {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 999px;
    padding: 8px 12px;
    background: rgba(17, 24, 39, .18);
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13px;
  }

  .custom-item-meta-pill strong {
    color: #9aa4ad;
    font-weight: 600;
  }

  .custom-item-note-box {
    min-height: 90px;
    border: 1px dashed rgba(255, 255, 255, .12);
    border-radius: 10px;
    padding: 12px;
    background: rgba(17, 24, 39, .16);
    color: #f8f9fa;
    line-height: 1.45;
    white-space: pre-line;
  }

  @media (max-width: 1200px) {
    .custom-orders-grid {
      grid-template-columns: 1fr;
    }

    .custom-form-grid,
    .custom-form-grid-2,
    .custom-form-grid-4,
    .custom-kpi,
    .custom-order-subgrid {
      grid-template-columns: 1fr;
    }

    .custom-item-summary,
    .custom-item-sections,
    .custom-item-detail-grid,
    .custom-builder-picker {
      grid-template-columns: 1fr;
    }

    .custom-builder-picker {
      display: block;
    }

    .custom-builder-picker-label {
      max-width: none;
      margin-top: 10px;
    }

    .custom-builder-order-table .g-options-bar {
      flex-wrap: wrap;
    }
  }
</style>

<div class="container-fluid">
  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($moduleLoadError !== null): ?>
    <div class="alert alert-danger">
      Custom Orders module could not load database data.<br>
      <strong>Database error:</strong> <?= h($moduleLoadError) ?><br>
      Please run the latest custom orders SQL migration on this environment.
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center">
      <h3 class="mb-0 mr-3">Custom Orders</h3>
      <div class="btn-group btn-group-sm" role="group" aria-label="Tooltip language">
        <a href="<?= h(customOrderBuildUrl($selectedOrderId > 0 ? $selectedOrderId : null, ['help_lang' => 'sk'])) ?>"
          class="btn <?= $customOrderHelpLang === 'sk' ? 'btn-info' : 'btn-outline-light' ?>">
          <span class="custom-help-lang-btn"><?= customOrderFlagIcon('sk', 'Slovakia') ?><span>SK Help</span></span>
        </a>
        <a href="<?= h(customOrderBuildUrl($selectedOrderId > 0 ? $selectedOrderId : null, ['help_lang' => 'en'])) ?>"
          class="btn <?= $customOrderHelpLang === 'en' ? 'btn-info' : 'btn-outline-light' ?>">
          <span class="custom-help-lang-btn"><?= customOrderFlagIcon('gb', 'United Kingdom') ?><span>EN
              Help</span></span>
        </a>
      </div>
    </div>
    <?php if ($customOrdersCanManage): ?>
      <form method="post" action="scripts/custom_orders/create_order.php" class="mb-0">
        <button type="submit" class="btn btn-success">New Custom Lead</button>
      </form>
    <?php else: ?>
      <span class="badge badge-secondary"><i class="fas fa-eye mr-1"></i>Read-only access</span>
    <?php endif; ?>
  </div>

  <div class="custom-orders-toolbar custom-orders-quick-search">
    <form method="get" class="mb-0">
      <input type="hidden" name="page" value="custom_orders">
      <?php if ($tabFilter !== 'all'): ?><input type="hidden" name="tab" value="<?= h($tabFilter) ?>"><?php endif; ?>
      <?php if ($draftStatusFilter !== ''): ?><input type="hidden" name="draft_status" value="<?= h($draftStatusFilter) ?>"><?php endif; ?>
      <?php if ($customOrderHelpLang !== ''): ?><input type="hidden" name="help_lang" value="<?= h($customOrderHelpLang) ?>"><?php endif; ?>
      <?php if ($difficultyFilter > 0): ?><input type="hidden" name="difficulty" value="<?= (int) $difficultyFilter ?>"><?php endif; ?>
      <?php if ($ownerFilter > 0): ?><input type="hidden" name="owner" value="<?= (int) $ownerFilter ?>"><?php endif; ?>
      <?php if ($countryFilter !== ''): ?><input type="hidden" name="country" value="<?= h($countryFilter) ?>"><?php endif; ?>
      <?php if ($sourceFilter !== ''): ?><input type="hidden" name="source" value="<?= h($sourceFilter) ?>"><?php endif; ?>
      <?php if ($paymentFilter !== ''): ?><input type="hidden" name="payment" value="<?= h($paymentFilter) ?>"><?php endif; ?>
      <?php if ($shippingFilter !== ''): ?><input type="hidden" name="shipping" value="<?= h($shippingFilter) ?>"><?php endif; ?>
      <?php if ($itemTypeFilter !== ''): ?><input type="hidden" name="item_type" value="<?= h($itemTypeFilter) ?>"><?php endif; ?>
      <?php if ($dateFromFilter !== ''): ?><input type="hidden" name="date_from" value="<?= h($dateFromFilter) ?>"><?php endif; ?>
      <?php if ($dateToFilter !== ''): ?><input type="hidden" name="date_to" value="<?= h($dateToFilter) ?>"><?php endif; ?>
      <div class="form-group mb-0 custom-orders-quick-search-field">
        <label class="small mb-1">Search<?= customOrderHelp('search') ?></label>
        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($query) ?>" placeholder="Lead no., order no., email, name, company, company ID, phone, nick">
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search mr-1"></i>Search</button>
      <?php if ($query !== ''): ?>
        <a class="btn btn-secondary btn-sm" href="<?= h(customOrderBuildUrl(null, ['q' => null, 'custom_order_id' => null, 'edit_item_id' => null], false)) ?>"><i class="fas fa-times mr-1"></i>Clear search</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($customOrdersCanManage): ?>
  <div class="modal fade" id="custom-order-seeds-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content bg-dark">
        <div class="modal-header">
          <h5 class="modal-title">Custom Order Number Seeds</h5>
          <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
        </div>
        <form method="post" action="scripts/custom_orders/update_sequences.php">
          <div class="modal-body">
            <input type="hidden" name="custom_order_id" value="0">
            <div class="form-group"><label>SO next seed<?= customOrderHelp('seq_so') ?></label><input type="number" name="seq_so" class="form-control" value="<?= (int) $sequences['SO'] ?>"></div>
            <div class="form-group"><label>GO next seed<?= customOrderHelp('seq_go') ?></label><input type="number" name="seq_go" class="form-control" value="<?= (int) $sequences['GO'] ?>"></div>
            <div class="form-group mb-0"><label>SC next seed<?= customOrderHelp('seq_sc') ?></label><input type="number" name="seq_sc" class="form-control" value="<?= (int) $sequences['SC'] ?>"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Seeds</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="custom-status-tabs">
    <div class="custom-status-tab-set">
      <?php foreach ($customOrderTabSets[0] as $tabKey): ?>
        <?php $tabLabel = (string) ($customOrderTabs[$tabKey]['label'] ?? $tabKey); ?>
        <a href="<?= h(customOrderBuildUrl(null, ['tab' => $tabKey === 'all' ? null : $tabKey, 'draft_status' => null, 'custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
          class="custom-status-tab <?= $draftStatusFilter === '' && $tabFilter === $tabKey ? 'active' : '' ?>">
          <span><?= h($tabLabel) ?></span><span class="custom-status-tab-count"><?= (int) ($tabCounts[$tabKey] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($draftStatusDefinitions): ?>
      <span class="custom-status-tabs-divider" aria-hidden="true"></span>
      <div class="custom-status-tab-set">
        <span class="custom-draft-tab-prefix">Draft items<?= customOrderHelp('draft_queues') ?></span>
        <?php foreach ($customOrderDraftTabCodes as $code): ?>
          <?php if (!isset($draftStatusDefinitions[$code])) { continue; } ?>
          <?php $meta = $draftStatusDefinitions[$code]; ?>
          <?php $draftCount = $draftStatusCounts[$code] ?? ['qty' => 0, 'orders' => 0]; ?>
          <a href="<?= h(customOrderBuildUrl(null, ['tab' => null, 'draft_status' => $code, 'custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
            class="custom-status-tab custom-draft-status-tab <?= $draftStatusFilter === $code ? 'active' : '' ?>"
            style="--draft-color:<?= h((string) ($meta['color'] ?? '#17a2b8')) ?>"
            title="<?= (int) $draftCount['qty'] ?> pcs in <?= (int) $draftCount['orders'] ?> custom orders">
            <span><?= h((string) ($meta['label'] ?? $code)) ?></span>
            <span class="custom-status-tab-count"><?= (int) ($draftCount['orders'] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <span class="custom-status-tabs-divider" aria-hidden="true"></span>
    <div class="custom-status-tab-set">
      <?php foreach ($customOrderTabSets[1] as $tabKey): ?>
        <?php $tabLabel = (string) ($customOrderTabs[$tabKey]['label'] ?? $tabKey); ?>
        <a href="<?= h(customOrderBuildUrl(null, ['tab' => $tabKey, 'draft_status' => null, 'custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
          class="custom-status-tab <?= $draftStatusFilter === '' && $tabFilter === $tabKey ? 'active' : '' ?>">
          <span><?= h($tabLabel) ?></span><span class="custom-status-tab-count"><?= (int) ($tabCounts[$tabKey] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="custom-orders-filter-toolbar">
    <div class="custom-active-pills">
      <?php if ($customOrdersHasActiveFilters): ?>
        <span class="text-muted small mr-1" style="white-space:nowrap;">Filters:</span>
        <?php foreach ($customOrderActiveFilterBadges as $activeFilter): ?>
          <span class="custom-active-filter-pill" title="<?= h((string) $activeFilter['display']) ?>">
            <span class="pill-label"><?= h((string) $activeFilter['label']) ?>:</span>
            <span class="pill-value"><?= h((string) $activeFilter['display']) ?></span>
          </span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="text-muted small">No filters active</span>
      <?php endif; ?>
    </div>
    <div class="custom-orders-filter-actions">
      <?php if ($customOrdersCanManage): ?>
        <button type="button" class="btn btn-outline-light btn-sm" data-toggle="modal" data-target="#custom-order-seeds-modal">
          <i class="fas fa-sort-numeric-up mr-1"></i>Order Seeds
        </button>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse"
        data-target="#customOrdersFilterCollapse" aria-expanded="<?= $customOrdersFilterCollapseShow === 'show' ? 'true' : 'false' ?>">
        <i class="fas fa-filter mr-1"></i>+ Filters
      </button>
    </div>
  </div>

  <div class="collapse <?= $customOrdersFilterCollapseShow ?>" id="customOrdersFilterCollapse">
    <form method="get" id="customOrdersFilterForm" class="mb-0">
      <input type="hidden" name="page" value="custom_orders">
      <?php if ($tabFilter !== 'all'): ?><input type="hidden" name="tab" value="<?= h($tabFilter) ?>"><?php endif; ?>
      <?php if ($draftStatusFilter !== ''): ?><input type="hidden" name="draft_status" value="<?= h($draftStatusFilter) ?>"><?php endif; ?>
      <?php if ($customOrderHelpLang !== ''): ?><input type="hidden" name="help_lang" value="<?= h($customOrderHelpLang) ?>"><?php endif; ?>
      <div class="filter-panel">
        <div class="filter-grid">
          <div class="form-group mb-1 <?= customOrderFilterActive($difficultyFilter) ?>">
            <label class="small mb-1">Difficulty</label>
            <select class="form-control form-control-sm" name="difficulty">
              <option value="">-- All --</option>
              <?php foreach ($customOrderComplexityOptions as $level => $label): ?>
                <option value="<?= (int) $level ?>" <?= $difficultyFilter === (int) $level ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($ownerFilter) ?>">
            <label class="small mb-1">Owner</label>
            <select class="form-control form-control-sm" name="owner">
              <option value="">-- All --</option>
              <?php foreach ($customOrderOwnerOptions as $ownerId => $ownerName): ?>
                <option value="<?= (int) $ownerId ?>" <?= $ownerFilter === (int) $ownerId ? 'selected' : '' ?>><?= h($ownerName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($countryFilter) ?>">
            <label class="small mb-1">Country</label>
            <div class="custom-country-select-wrap no-flag">
              <span class="custom-country-flag is-empty" data-country-flag aria-hidden="true"></span>
              <select name="country" data-country-select data-country-placeholder="-- All --" class="form-control form-control-sm custom-country-select">
                <option value="<?= h($countryFilter) ?>" selected><?= h($countryFilter !== '' ? $countryFilter : '-- All --') ?></option>
              </select>
            </div>
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($sourceFilter) ?>">
            <label class="small mb-1">Source</label>
            <select class="form-control form-control-sm" name="source">
              <option value="">-- All --</option>
              <?php foreach ($customOrderSourceChannels as $sourceChannel): ?>
                <option value="<?= h($sourceChannel) ?>" <?= $sourceFilter === $sourceChannel ? 'selected' : '' ?>><?= h($sourceChannel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($paymentFilter) ?>">
            <label class="small mb-1">Payment</label>
            <select class="form-control form-control-sm" name="payment">
              <option value="">-- All --</option>
              <?php foreach ($customOrderPaymentMethods as $paymentMethod): ?>
                <option value="<?= h($paymentMethod) ?>" <?= $paymentFilter === $paymentMethod ? 'selected' : '' ?>><?= h($paymentMethod) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($shippingFilter) ?>">
            <label class="small mb-1">Shipping</label>
            <select class="form-control form-control-sm" name="shipping">
              <option value="">-- All --</option>
              <?php foreach ($customOrderShippingMethods as $shippingMethod): ?>
                <option value="<?= h($shippingMethod) ?>" <?= $shippingFilter === $shippingMethod ? 'selected' : '' ?>><?= h($shippingMethod) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($itemTypeFilter) ?>">
            <label class="small mb-1">Product Type</label>
            <select class="form-control form-control-sm" name="item_type">
              <option value="">-- All --</option>
              <?php foreach ($allowedTypes as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $itemTypeFilter === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-1 filter-search <?= customOrderFilterActive($query) ?>">
            <label class="small mb-1">Search<?= customOrderHelp('search') ?></label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= h($query) ?>" placeholder="Lead no., order no., email, name, company, company ID, phone, nick">
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($dateFromFilter) ?>">
            <label class="small mb-1">Updated From</label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?= h($dateFromFilter) ?>">
          </div>
          <div class="form-group mb-1 <?= customOrderFilterActive($dateToFilter) ?>">
            <label class="small mb-1">Updated To</label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?= h($dateToFilter) ?>">
          </div>
        </div>
        <?php
        $customOrdersFilterResetUrl = customOrderBuildUrl(null, [
          'difficulty' => null,
          'owner' => null,
          'country' => null,
          'source' => null,
          'payment' => null,
          'shipping' => null,
          'item_type' => null,
          'q' => null,
          'date_from' => null,
          'date_to' => null,
          'custom_order_id' => null,
          'edit_item_id' => null,
        ], false);
        ?>
        <div class="custom-orders-filter-submit-row">
          <a class="btn btn-secondary btn-sm" href="<?= h($customOrdersFilterResetUrl) ?>"><i class="fas fa-times mr-1"></i>Reset filters</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter mr-1"></i>Apply filters</button>
        </div>
      </div>
    </form>
  </div>

  <div class="custom-orders-grid">
    <div class="custom-orders-panel">
      <div class="panel-body">
        <form method="get" class="mb-3">
          <input type="hidden" name="page" value="custom_orders">
          <?php if ($tabFilter !== 'all'): ?><input type="hidden" name="tab" value="<?= h($tabFilter) ?>"><?php endif; ?>
          <?php if ($draftStatusFilter !== ''): ?><input type="hidden" name="draft_status" value="<?= h($draftStatusFilter) ?>"><?php endif; ?>
          <?php if ($customOrderHelpLang !== ''): ?><input type="hidden" name="help_lang" value="<?= h($customOrderHelpLang) ?>"><?php endif; ?>
          <div class="form-group">
            <label>Search<?= customOrderHelp('search') ?></label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= h($query) ?>"
              placeholder="Lead no., order no., email, name, company, company ID, phone, nick">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        </form>

        <div class="custom-order-section-title">Order Seeds</div>
        <form method="post" action="scripts/custom_orders/update_sequences.php" class="mb-3">
          <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrderId ?>">
          <div class="custom-form-grid-4">
            <div><label>SO next seed<?= customOrderHelp('seq_so') ?></label><input type="number" name="seq_so"
                class="form-control form-control-sm" value="<?= (int) $sequences['SO'] ?>"></div>
            <div><label>GO next seed<?= customOrderHelp('seq_go') ?></label><input type="number" name="seq_go"
                class="form-control form-control-sm" value="<?= (int) $sequences['GO'] ?>"></div>
            <div><label>SC next seed<?= customOrderHelp('seq_sc') ?></label><input type="number" name="seq_sc"
                class="form-control form-control-sm" value="<?= (int) $sequences['SC'] ?>"></div>
            <div class="d-flex align-items-end"><button type="submit" class="btn btn-outline-light btn-sm w-100">Save
                Seeds</button></div>
          </div>
        </form>

        <?php if ($selectedOrder): ?>
          <a href="<?= h(customOrderBuildUrl(null, ['custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
            class="btn btn-outline-light btn-sm btn-block mb-3">Back To Orders List</a>
          <div class="custom-order-section-title">Customer Context</div>
          <div class="custom-field-cluster mb-3">
            <div class="custom-summary-list">
              <div class="custom-summary-row"><strong>Customer</strong><span><?= h($selectedOrder['customer_name'] ?: 'Unnamed lead') ?></span></div>
              <div class="custom-summary-row"><strong>Handle</strong><span><?= h($selectedOrder['social_handle'] ?: '-') ?></span></div>
              <div class="custom-summary-row"><strong>Email</strong><span><?= h($selectedOrder['customer_email'] ?: '-') ?></span></div>
              <div class="custom-summary-row"><strong>Phone</strong><span><?= h($selectedOrder['customer_phone'] ?: $selectedOrder['shipping_phone'] ?: $selectedOrder['billing_phone'] ?: '-') ?></span></div>
              <div class="custom-summary-row"><strong>Country</strong><span><?= h($selectedOrder['customer_country'] ?: $selectedOrder['shipping_country'] ?: '-') ?></span></div>
            </div>
          </div>

          <div class="custom-order-section-title">Lead Actions</div>
          <div class="custom-field-cluster mb-3">
            <div class="custom-summary-list mb-3">
              <div class="custom-summary-row"><strong>Internal</strong><span><?= h($selectedOrder['internal_code']) ?></span></div>
              <div class="custom-summary-row"><strong>Official</strong><span><?= h($selectedOrder['official_order_number'] ?: 'Not assigned') ?></span></div>
              <div class="custom-summary-row"><strong>Owner</strong><span><?= h($selectedOrder['owner_name'] ?: 'Unassigned') ?></span></div>
            </div>
            <form method="post" action="scripts/custom_orders/assign_owner.php" class="mb-2">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="input-group input-group-sm">
                <select name="owner_employee_id" class="form-control<?= customOrderInvalid($invalidFields, 'owner') ?>">
                  <?php foreach ($assignableEmployees as $employee): ?>
                    <?php $employeeName = trim(((string) ($employee['firstname'] ?? '')) . ' ' . ((string) ($employee['lastname'] ?? ''))); ?>
                    <option value="<?= (int) $employee['id'] ?>" <?= (int) ($selectedOrder['owner_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= h($employeeName) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="input-group-append"><button type="submit" class="btn btn-info">Assign</button></div>
              </div>
            </form>
            <form method="post" action="scripts/custom_orders/assign_official_number.php" class="mb-2">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="input-group input-group-sm">
                <select name="official_prefix" class="form-control<?= customOrderInvalid($invalidFields, 'official_prefix') ?>">
                  <option value="SO" <?= ($selectedOrder['official_prefix'] ?? 'SO') === 'SO' ? 'selected' : '' ?>>SO</option>
                  <option value="GO" <?= ($selectedOrder['official_prefix'] ?? '') === 'GO' ? 'selected' : '' ?>>GO</option>
                  <option value="SC" <?= ($selectedOrder['official_prefix'] ?? '') === 'SC' ? 'selected' : '' ?>>SC</option>
                </select>
                <div class="input-group-append"><button type="submit" class="btn btn-warning">Official No.</button></div>
              </div>
            </form>
            <form method="post" action="scripts/custom_orders/export_order.php" class="mb-2">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <button type="submit" class="btn btn-primary btn-sm btn-block" <?= (int) ($selectedOrder['production_order_id'] ?? 0) > 0 ? 'disabled' : '' ?>>Export To Production</button>
            </form>
            <?php if ((int) ($selectedOrder['production_order_id'] ?? 0) > 0): ?>
              <a class="btn btn-outline-success btn-sm btn-block" href="index.php?page=orders&q=<?= urlencode((string) $selectedOrder['official_order_number']) ?>">Open Production Order</a>
            <?php endif; ?>
          </div>
          <div class="custom-order-section-title">Related Orders</div>
          <div style="max-height: 46vh; overflow:auto;">
            <?php foreach ($relatedOrders as $row): ?>
              <?php $isActive = (int) $row['id'] === $selectedOrderId; ?>
              <a class="custom-order-list-row <?= $isActive ? 'active' : '' ?>"
                href="<?= h(customOrderBuildUrl((int) $row['id'], ['edit_item_id' => null])) ?>">
                <div class="d-flex justify-content-between">
                  <strong><?= h($row['official_order_number'] ?: $row['internal_code']) ?></strong>
                  <span
                    class="badge badge-<?= $row['status'] === 'EXPORTED' ? 'success' : ($row['status'] === 'DEAD' ? 'danger' : 'warning') ?>"><?= h(selectedText($statuses, (string) $row['status'])) ?></span>
                </div>
                <div><?= h($row['customer_name'] ?: $row['social_handle'] ?: 'Unnamed lead') ?></div>
                <div class="custom-order-meta">
                  Owner <?= h($row['owner_name'] ?: '-') ?> | Updated <?= h(date('d.m.Y H:i', strtotime((string) $row['updated_at']))) ?>
                </div>
                <div class="custom-order-meta">
                  Items <?= (int) $row['item_count'] ?> | Total
                  <?= number_format((float) (($row['item_total'] ?? 0) + ($row['shipping_price'] ?? 0)), 2) ?>
                  <?= h($row['currency'] ?: '') ?>
                </div>
              </a>
            <?php endforeach; ?>
            <?php if (!$relatedOrders): ?>
              <div class="text-muted">No related orders found for this customer yet.</div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="custom-order-section-title">Workspace Mode</div>
          <div class="custom-field-cluster">
            <div class="text-muted small mb-2">List view is now the default landing mode for browsing all leads.</div>
            <div class="custom-summary-list">
              <div class="custom-summary-row"><strong>Filtered rows</strong><span><?= count($listRows) ?></span></div>
              <?php
              $activeCustomOrderScope = (string) ($customOrderTabs[$tabFilter]['label'] ?? 'All');
              if ($draftStatusFilter !== '') {
                $activeCustomOrderScope = 'Draft items: ' . (string) ($draftStatusDefinitions[$draftStatusFilter]['label'] ?? $draftStatusFilter);
              }
              ?>
              <div class="custom-summary-row"><strong>Status scope</strong><span><?= h($activeCustomOrderScope) ?></span></div>
              <div class="custom-summary-row"><strong>Search</strong><span><?= h($query !== '' ? $query : 'None') ?></span></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- CUSTOM_ORDER_DETAIL_START -->
    <div>
      <?php if (!$selectedOrder): ?>
        <div class="custom-orders-panel">
          <div class="panel-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="custom-order-section-title mb-0">Orders List</div>
              <div class="text-muted small">Showing up to 300 most recently updated rows</div>
            </div>
            <div class="table-responsive custom-orders-list-table-wrap">
              <table id="customOrdersTable" class="table table-sm table-dark table-striped custom-mini-table mb-0">
                <thead>
                  <tr>
                    <th>Official / Internal<?= customOrderHelp('list_order_number') ?></th>
                    <th>Customer<?= customOrderHelp('list_customer') ?></th>
                    <th>Nick<?= customOrderHelp('list_nick') ?></th>
                    <th>Country<?= customOrderHelp('list_country') ?></th>
                    <th>Status<?= customOrderHelp('status') ?></th>
                    <th>Complexity<?= customOrderHelp('complexity_level') ?></th>
                    <th class="text-center">Traffic<?= customOrderHelp('list_traffic') ?></th>
                    <th>Owner<?= customOrderHelp('list_owner') ?></th>
                    <th>Items<?= customOrderHelp('list_items') ?></th>
                    <th>Total<?= customOrderHelp('list_total') ?></th>
                    <th>Updated<?= customOrderHelp('list_updated') ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($listRows as $row): ?>
                    <?php $rowUrl = customOrderBuildUrl((int) $row['id'], ['edit_item_id' => null]); ?>
                    <tr class="custom-order-table-row" data-order-id="<?= (int) $row['id'] ?>" data-href="<?= h($rowUrl) ?>">
                      <td>
                        <div><strong><?= h($row['official_order_number'] ?: $row['internal_code']) ?></strong></div>
                        <div class="custom-order-meta"><?= h($row['official_order_number'] ? $row['internal_code'] : 'No official number yet') ?></div>
                      </td>
                      <td><?= h($row['customer_name'] ?: 'Unnamed lead') ?></td>
                      <td><?= h($row['social_handle'] ?: '-') ?></td>
                      <td>
                        <?php
                        $rowCountryCode = strtoupper(trim((string) ($row['customer_country'] ?: $row['shipping_country'] ?: '')));
                        if ($rowCountryCode === 'UM') {
                          $rowCountryCode = 'US';
                        }
                        ?>
                        <?php if ($rowCountryCode !== ''): ?>
                          <span style="white-space:nowrap;"><?= customOrderFlagIcon($rowCountryCode, $rowCountryCode) ?> <span><?= h($rowCountryCode) ?></span></span>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td><?= h(selectedText($statuses, (string) $row['status'])) ?></td>
                      <?php $rowComplexityLevel = (int) ($row['complexity_level'] ?? 1); ?>
                      <td><span class="custom-complexity-pill" title="<?= h(customOrderComplexityLabel($rowComplexityLevel, $customOrderComplexityOptions)) ?>"><?= h(customOrderComplexityLabel($rowComplexityLevel, $customOrderComplexityOptions)) ?></span></td>
                      <td class="text-center">
                        <?php
                        $rowTrafficSummary = json_decode((string) ($row['production_traffic_summary_json'] ?? ''), true);
                        if (!is_array($rowTrafficSummary)) {
                          $rowTrafficSummary = [];
                        }
                        $rowTrafficMissingTypes = [];
                        if ((int) ($row['production_order_id'] ?? 0) > 0) {
                          $rowTrafficTypes = str_split(str_replace([',', ' '], '', strtoupper((string) ($row['item_types'] ?? ''))));
                          foreach ($rowTrafficTypes as $rowTrafficType) {
                            if (in_array($rowTrafficType, ['G', 'F', 'P', 'S'], true) && !array_key_exists($rowTrafficType, $rowTrafficSummary)) {
                              $rowTrafficSummary[$rowTrafficType] = 'RED';
                              $rowTrafficMissingTypes[$rowTrafficType] = true;
                            }
                          }
                        }
                        ?>
                        <?php if ($rowTrafficSummary): ?>
                          <span class="custom-order-traffic-badges">
                            <?php foreach (['G', 'F', 'P', 'S'] as $rowTrafficType): ?>
                              <?php if (!array_key_exists($rowTrafficType, $rowTrafficSummary)) continue; ?>
                              <?php
                              $rowTrafficState = strtoupper((string) $rowTrafficSummary[$rowTrafficType]);
                              $rowTrafficClass = $rowTrafficState === 'GREEN' ? 'is-green' : ($rowTrafficState === 'ORANGE' ? 'is-orange' : 'is-red');
                              $rowTrafficTitle = $rowTrafficType . ' - ' . $rowTrafficState;
                              if (isset($rowTrafficMissingTypes[$rowTrafficType])) {
                                $rowTrafficTitle .= ' | Item exists in Custom Order but is not synchronized to Production Order';
                              } elseif (trim((string) ($row['production_traffic_blocker'] ?? '')) !== '') {
                                $rowTrafficTitle .= ' | ' . trim((string) $row['production_traffic_blocker']);
                              }
                              ?>
                              <span class="custom-order-traffic-badge <?= h($rowTrafficClass) ?>" title="<?= h($rowTrafficTitle) ?>"><?= h($rowTrafficType) ?></span>
                            <?php endforeach; ?>
                          </span>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-center">
                        <?php
                        $rowOwnerName = trim((string) ($row['owner_name'] ?? ''));
                        $rowOwnerPhoto = trim((string) ($row['owner_photo'] ?? ''));
                        $rowOwnerInitials = '';
                        foreach (preg_split('/\s+/', $rowOwnerName) as $ownerNamePart) {
                          if ($ownerNamePart !== '') {
                            $rowOwnerInitials .= mb_strtoupper(mb_substr($ownerNamePart, 0, 1));
                          }
                        }
                        $rowOwnerInitials = mb_substr($rowOwnerInitials, 0, 2);
                        ?>
                        <?php if ($rowOwnerPhoto !== ''): ?>
                          <img src="images/<?= h($rowOwnerPhoto) ?>" class="custom-order-owner-avatar" alt="<?= h($rowOwnerName ?: 'Owner') ?>" title="<?= h($rowOwnerName ?: 'Owner') ?>">
                        <?php else: ?>
                          <span class="custom-order-owner-avatar" title="<?= h($rowOwnerName !== '' ? $rowOwnerName : 'Unassigned') ?>"><?= h($rowOwnerInitials !== '' ? $rowOwnerInitials : '-') ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= (int) $row['item_count'] ?></td>
                      <td><?= number_format((float) (($row['item_total'] ?? 0) + ($row['shipping_price'] ?? 0)), 2) ?> <?= h($row['currency'] ?: '') ?></td>
                      <td><?= h(date('d.m.Y H:i', strtotime((string) $row['updated_at']))) ?></td>
                    </tr>
                    <tr class="custom-order-detail-row" data-detail-order-id="<?= (int) $row['id'] ?>">
                      <td colspan="11"><div class="custom-order-detail-wrap" id="custom-detail-<?= (int) $row['id'] ?>"></div></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$listRows): ?>
                    <tr>
                      <td colspan="11" class="text-muted">No custom orders found for the current filter.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php else: ?>
        <?php $summary = $selectedOrder['summary']; ?>
        <datalist id="custom-contact-suggestions">
          <?php foreach ($suggestions as $sg): ?>
            <?php $label = trim(implode(' | ', array_filter([(string) ($sg['name'] ?? ''), (string) ($sg['social_handle'] ?? ''), (string) ($sg['email'] ?? ''), (string) ($sg['phone'] ?? '')]))); ?>
            <?php if ($label !== ''): ?>
              <option value="<?= h($label) ?>"></option><?php endif; ?>
          <?php endforeach; ?>
        </datalist>

        <?php
        $customTypeTotals = ['G' => 0.0, 'P' => 0.0, 'S' => 0.0, 'F' => 0.0, 'T' => 0.0, 'M' => 0.0];
        foreach ((array) ($selectedOrder['items'] ?? []) as $breakdownItem) {
          $breakdownType = strtoupper((string) ($breakdownItem['item_type_code'] ?? 'M'));
          if (!array_key_exists($breakdownType, $customTypeTotals)) {
            $breakdownType = 'M';
          }
          $customTypeTotals[$breakdownType] += (float) ($breakdownItem['qty'] ?? 0) * (float) ($breakdownItem['unit_price'] ?? 0);
        }
        $customDisplayNumber = (string) ($selectedOrder['official_order_number'] ?: $selectedOrder['internal_code']);
        $customHeaderHasInvalid = (bool) array_intersect_key($invalidFields, array_flip([
          'customer_name', 'social_handle', 'shipping_name', 'shipping_street', 'shipping_city',
          'shipping_zip', 'shipping_country', 'shipping_state', 'customer_email', 'shipping_email', 'shipping_phone',
          'shipping_price',
        ]));
        $customBillingAddressFields = ['name', 'company', 'company_id', 'street', 'city', 'zip', 'country', 'state', 'email', 'phone'];
        $customBillingHasDifferentValue = false;
        foreach ($customBillingAddressFields as $customBillingField) {
          $billingValue = trim((string) ($selectedOrder['billing_' . $customBillingField] ?? ''));
          $shippingValue = trim((string) ($selectedOrder['shipping_' . $customBillingField] ?? ''));
          if ($billingValue !== '' && $billingValue !== $shippingValue) {
            $customBillingHasDifferentValue = true;
            break;
          }
        }
        $customBillingSameAsShipping = !$customBillingHasDifferentValue;
        $customBillingDisplay = [];
        foreach ($customBillingAddressFields as $customBillingField) {
          $customBillingDisplay[$customBillingField] = $customBillingSameAsShipping
            ? (string) ($selectedOrder['shipping_' . $customBillingField] ?? '')
            : (string) ($selectedOrder['billing_' . $customBillingField] ?? '');
        }
        ?>

        <div id="custom-order-accounting-panel" data-scroll-block class="custom-twin-order-card<?= $customHeaderHasInvalid ? ' custom-panel-invalid' : '' ?>">
          <div class="custom-twin-order-header d-flex justify-content-between align-items-center flex-wrap">
            <div class="d-flex align-items-center flex-wrap" style="gap:6px;">
              <b class="btn-copy-inline" data-copy="<?= h($customDisplayNumber) ?>" style="cursor:pointer;">#<?= h($customDisplayNumber) ?></b>
              <?php if ($customOrdersCanManage): ?>
                <button type="submit" form="custom-twin-header-form-<?= (int) $selectedOrder['id'] ?>" class="btn btn-warning btn-sm">
                  <i class="fas fa-save mr-1"></i>Save changes
                </button>
              <?php endif; ?>
              <?php if ($customOrdersCanManage && trim((string) ($selectedOrder['official_order_number'] ?? '')) === ''): ?>
                <form method="post" action="scripts/custom_orders/assign_official_number.php" class="d-inline-flex align-items-center mb-0" style="gap:4px;">
                  <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                  <select name="official_prefix" class="form-control form-control-sm" style="width:72px;">
                    <option value="SO" <?= ($selectedOrder['official_prefix'] ?? 'SO') === 'SO' ? 'selected' : '' ?>>SO</option>
                    <option value="GO" <?= ($selectedOrder['official_prefix'] ?? '') === 'GO' ? 'selected' : '' ?>>GO</option>
                    <option value="SC" <?= ($selectedOrder['official_prefix'] ?? '') === 'SC' ? 'selected' : '' ?>>SC</option>
                  </select>
                  <button type="submit" class="btn btn-outline-warning btn-sm" title="Generate the next sequential official number">
                    <i class="fas fa-hashtag mr-1"></i>Generate Number
                  </button>
                </form>
              <?php elseif ($customOrdersCanManage && (int) ($selectedOrder['production_order_id'] ?? 0) <= 0): ?>
                <form method="post" action="scripts/custom_orders/export_order.php" class="mb-0" onsubmit="return confirm('Export <?= h((string) $selectedOrder['official_order_number']) ?> to Production Orders? After export it will enter the standard production workflow.');">
                  <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                  <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-industry mr-1"></i>Export To Production
                  </button>
                </form>
              <?php elseif ((int) ($selectedOrder['production_order_id'] ?? 0) > 0): ?>
                <a class="btn btn-outline-success btn-sm" href="index.php?page=orders&amp;q=<?= urlencode((string) $selectedOrder['official_order_number']) ?>#order-<?= (int) $selectedOrder['production_order_id'] ?>">
                  <i class="fas fa-external-link-alt mr-1"></i>Open Production #<?= (int) $selectedOrder['production_order_id'] ?>
                </a>
              <?php endif; ?>
            </div>
            <div class="custom-twin-header-controls">
              <?php $currentComplexityLevel = (int) ($selectedOrder['complexity_level'] ?? 1); if ($currentComplexityLevel <= 0) { $currentComplexityLevel = 1; } ?>
              <select name="complexity_level" form="custom-twin-header-form-<?= (int) $selectedOrder['id'] ?>" class="form-control form-control-sm custom-complexity-control" title="Complexity Level" aria-label="Complexity Level" <?= $customOrdersCanManage ? '' : 'disabled' ?>>
                <?php if (!isset($customOrderComplexityOptions[$currentComplexityLevel])): ?>
                  <option value="<?= (int) $currentComplexityLevel ?>" selected><?= h(customOrderComplexityLabel($currentComplexityLevel, $customOrderComplexityOptions)) ?></option>
                <?php endif; ?>
                <?php foreach ($customOrderComplexityOptions as $level => $label): ?>
                  <option value="<?= (int) $level ?>" <?= $currentComplexityLevel === (int) $level ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-outline-info btn-sm custom-activity-header-btn" data-toggle="modal" data-target="#custom-order-activity-modal-<?= (int) $selectedOrder['id'] ?>">
                <i class="fas fa-history mr-1"></i>Activity
                <span class="badge badge-info"><?= count((array) ($selectedOrder['activity'] ?? [])) ?></span>
              </button>
              <select name="status" form="custom-twin-header-form-<?= (int) $selectedOrder['id'] ?>" class="form-control form-control-sm custom-status-control" <?= $customOrdersCanManage ? '' : 'disabled' ?>>
                <?php $currentOrderStatus = (string) ($selectedOrder['status'] ?? 'LEAD'); ?>
                <?php foreach ($customOrderStatusChoices as $code => $label): ?><option value="<?= h($code) ?>" <?= $currentOrderStatus === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                <?php if ($currentOrderStatus !== '' && !isset($customOrderStatusChoices[$currentOrderStatus])): ?>
                  <option value="<?= h($currentOrderStatus) ?>" selected><?= h(selectedText($statuses, $currentOrderStatus)) ?></option>
                <?php endif; ?>
              </select>
              <button type="button" class="btn btn-outline-light btn-sm btn-close-custom-order-detail" data-order-id="<?= (int) $selectedOrder['id'] ?>">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>

          <div class="custom-twin-order-body">
            <div class="row align-items-stretch">
              <div class="col-lg-8 d-flex flex-column custom-twin-column-stack">
                <div class="custom-twin-edit-card">
                  <div class="custom-twin-edit-title">Edit order header</div>
                  <div class="custom-twin-edit-body">
                    <form method="post" action="scripts/custom_orders/save_order.php" id="custom-twin-header-form-<?= (int) $selectedOrder['id'] ?>" data-scroll-target="#custom-order-accounting-panel">
                      <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                      <input type="hidden" name="dead_order_flag" value="<?= (int) $selectedOrder['dead_order_flag'] ?>">

                      <div class="row">
                        <div class="col-md-6">
                          <div class="form-group custom-twin-header-column-lead"><label>Nick<?= customOrderHelp('social_handle') ?></label><input name="social_handle" class="form-control form-control-sm" value="<?= h($selectedOrder['social_handle']) ?>" placeholder="Nick"></div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group custom-twin-header-column-lead"><label>Source channel<?= customOrderHelp('source_channel') ?></label><select name="source_channel" class="form-control form-control-sm"><option value="">Select source...</option><?php foreach (customOrderOptionsWithCurrent($customOrderSourceChannels, (string) $selectedOrder['source_channel']) as $sourceChannel): ?><option value="<?= h($sourceChannel) ?>" <?= (string) $selectedOrder['source_channel'] === $sourceChannel ? 'selected' : '' ?>><?= h($sourceChannel) ?></option><?php endforeach; ?></select></div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-6">
                          <h6 class="mt-1">Shipping<?= customOrderHelp('shipping_address') ?></h6>
                          <input name="shipping_name" data-shipping-field="name" class="form-control form-control-sm mb-1" placeholder="Name" value="<?= h($selectedOrder['shipping_name']) ?>">
                          <div class="form-row mb-1"><div class="col-md-8"><input name="shipping_company" data-shipping-field="company" class="form-control form-control-sm" placeholder="Company" value="<?= h($selectedOrder['shipping_company']) ?>"></div><div class="col-md-4"><input name="shipping_company_id" data-shipping-field="company_id" class="form-control form-control-sm" placeholder="Company ID" value="<?= h($selectedOrder['shipping_company_id']) ?>"></div></div>
                          <input name="shipping_street" data-shipping-field="street" class="form-control form-control-sm mb-1" placeholder="Street" value="<?= h($selectedOrder['shipping_street']) ?>">
                          <input name="shipping_city" data-shipping-field="city" class="form-control form-control-sm mb-1" placeholder="City" value="<?= h($selectedOrder['shipping_city']) ?>">
                          <input name="shipping_zip" data-shipping-field="zip" class="form-control form-control-sm mb-1" placeholder="ZIP" value="<?= h($selectedOrder['shipping_zip']) ?>">
                          <div class="mb-1 custom-country-state-row">
                            <div class="custom-country-col"><div class="custom-country-select-wrap no-flag"><span class="custom-country-flag is-empty" data-country-flag aria-hidden="true"></span><select name="shipping_country" data-shipping-field="country" data-country-select class="form-control form-control-sm custom-country-select"><option value="<?= h((string) ($selectedOrder['shipping_country'] ?? '')) ?>" selected><?= h((string) ($selectedOrder['shipping_country'] ?? '')) ?></option></select></div></div>
                            <div class="custom-state-col" data-state-wrap hidden><select name="shipping_state" data-shipping-field="state" data-state-select data-current-state="<?= h((string) ($selectedOrder['shipping_state'] ?? '')) ?>" class="form-control form-control-sm"><option value="">State</option></select></div>
                          </div>
                          <input name="shipping_email" data-shipping-field="email" class="form-control form-control-sm mb-1" placeholder="Email" value="<?= h($selectedOrder['shipping_email']) ?>">
                          <input name="shipping_phone" data-shipping-field="phone" class="form-control form-control-sm mb-1" placeholder="Phone" value="<?= h($selectedOrder['shipping_phone']) ?>">
                          <div class="form-row mt-1">
                            <div class="form-group col-md-8 mb-1"><label>Shipping Price<?= customOrderHelp('shipping_price') ?></label><input type="number" step="0.01" name="shipping_price" class="form-control form-control-sm" value="<?= h($selectedOrder['shipping_price']) ?>"></div>
                            <div class="form-group col-md-4 mb-1"><label>Shipping Method<?= customOrderHelp('shipping_method') ?></label><select name="shipping_method" class="form-control form-control-sm"><?php foreach (customOrderOptionsWithCurrent($customOrderShippingMethods, (string) $selectedOrder['shipping_method']) as $shippingMethod): ?><option value="<?= h($shippingMethod) ?>" <?= (string) $selectedOrder['shipping_method'] === $shippingMethod ? 'selected' : '' ?>><?= h($shippingMethod) ?></option><?php endforeach; ?></select></div>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="custom-billing-sync-row">
                            <h6>Billing<?= customOrderHelp('billing_address') ?></h6>
                            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="billing_same_as_shipping" id="billing-same-shipping-<?= (int) $selectedOrder['id'] ?>" value="1" data-billing-same-toggle <?= $customBillingSameAsShipping ? 'checked' : '' ?>><label class="form-check-label" for="billing-same-shipping-<?= (int) $selectedOrder['id'] ?>">Same as Shipping</label></div>
                          </div>
                          <div class="custom-billing-fields<?= $customBillingSameAsShipping ? ' is-synced' : '' ?>" data-billing-fields>
                            <input name="billing_name" data-billing-field="name" class="form-control form-control-sm mb-1" placeholder="Name" value="<?= h($customBillingDisplay['name']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>>
                            <div class="form-row mb-1"><div class="col-md-8"><input name="billing_company" data-billing-field="company" class="form-control form-control-sm" placeholder="Company" value="<?= h($customBillingDisplay['company']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>></div><div class="col-md-4"><input name="billing_company_id" data-billing-field="company_id" class="form-control form-control-sm" placeholder="Company ID" value="<?= h($customBillingDisplay['company_id']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>></div></div>
                            <input name="billing_street" data-billing-field="street" class="form-control form-control-sm mb-1" placeholder="Street" value="<?= h($customBillingDisplay['street']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>>
                            <input name="billing_city" data-billing-field="city" class="form-control form-control-sm mb-1" placeholder="City" value="<?= h($customBillingDisplay['city']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>>
                            <input name="billing_zip" data-billing-field="zip" class="form-control form-control-sm mb-1" placeholder="ZIP" value="<?= h($customBillingDisplay['zip']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>>
                            <div class="mb-1 custom-country-state-row">
                              <div class="custom-country-col"><div class="custom-country-select-wrap no-flag"><span class="custom-country-flag is-empty" data-country-flag aria-hidden="true"></span><select name="billing_country" data-billing-field="country" data-country-select class="form-control form-control-sm custom-country-select" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>><option value="<?= h((string) ($customBillingDisplay['country'] ?? '')) ?>" selected><?= h((string) ($customBillingDisplay['country'] ?? '')) ?></option></select></div></div>
                              <div class="custom-state-col" data-state-wrap hidden><select name="billing_state" data-billing-field="state" data-state-select data-current-state="<?= h((string) ($customBillingDisplay['state'] ?? '')) ?>" class="form-control form-control-sm" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>><option value="">State</option></select></div>
                            </div>
                            <input name="billing_email" data-billing-field="email" class="form-control form-control-sm mb-1" placeholder="Email" value="<?= h($customBillingDisplay['email']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>>
                            <input name="billing_phone" data-billing-field="phone" class="form-control form-control-sm mb-1" placeholder="Phone" value="<?= h($customBillingDisplay['phone']) ?>" <?= $customBillingSameAsShipping ? 'disabled' : '' ?>>
                          </div>
                          <div class="form-group mb-1"><label>Payment<?= customOrderHelp('payment_method') ?></label><select name="payment_method" class="form-control form-control-sm"><?php foreach (customOrderOptionsWithCurrent($customOrderPaymentMethods, (string) $selectedOrder['payment_method']) as $paymentMethod): ?><option value="<?= h($paymentMethod) ?>" <?= (string) $selectedOrder['payment_method'] === $paymentMethod ? 'selected' : '' ?>><?= h($paymentMethod) ?></option><?php endforeach; ?></select></div>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>

              </div>

              <div class="col-lg-4 mt-3 mt-lg-0 d-flex flex-column custom-twin-column-stack">
                <div class="custom-order-value-breakdown-card">
                  <div class="custom-order-value-breakdown-row custom-order-value-breakdown-total"><span>Total Order Value:</span><span><?= number_format((float) $summary['gross_total'], 2) ?> <?= h($selectedOrder['currency']) ?></span></div>
                  <?php foreach (['G' => 'Graphics', 'P' => 'Plastics', 'S' => 'Seat Covers', 'F' => 'Fitting', 'T' => 'Accessories', 'M' => 'Other'] as $typeCode => $typeLabel): ?>
                    <?php if ($customTypeTotals[$typeCode] > 0): ?><div class="custom-order-value-breakdown-row"><span><?= h($typeLabel) ?>:</span><span><?= number_format($customTypeTotals[$typeCode], 2) ?> <?= h($selectedOrder['currency']) ?></span></div><?php endif; ?>
                  <?php endforeach; ?>
                  <div class="custom-order-value-breakdown-row"><span>Shipping:</span><span><?= number_format((float) $summary['shipping'], 2) ?> <?= h($selectedOrder['currency']) ?></span></div>
                  <hr style="border-color:rgba(255,255,255,.14);">
                  <div class="custom-order-value-breakdown-row"><span>Deposits:</span><span><?= number_format((float) $summary['deposit_total'], 2) ?> <?= h($selectedOrder['currency']) ?></span></div>
                  <div class="custom-order-value-breakdown-row"><span>Paid net:</span><span><?= number_format((float) $summary['payment_net'], 2) ?> <?= h($selectedOrder['currency']) ?></span></div>
                  <div class="custom-order-value-breakdown-row font-weight-bold"><span>Balance due:</span><span><?= number_format((float) ($summary['gross_total'] - $summary['payment_net']), 2) ?> <?= h($selectedOrder['currency']) ?></span></div>
                </div>

                <fieldset class="custom-field-cluster custom-order-photos-card custom-twin-secondary-card" data-custom-order-photo-card data-order-id="<?= (int) $selectedOrder['id'] ?>">
                  <legend class="custom-field-cluster-title">Order Photos<?= customOrderHelp('order_photos') ?></legend>
                  <div class="custom-order-photo-dropzone" data-custom-order-photo-dropzone>
                    <input type="file" class="d-none" data-custom-order-photo-input accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                    <div>
                      <i class="fas fa-cloud-upload-alt d-block mb-1"></i>
                      <b>Drag &amp; drop photos</b>
                      <div class="small text-muted">or click to select · resize to max 1500 px</div>
                    </div>
                  </div>
                  <div class="custom-order-photo-progress" data-custom-order-photo-progress><span></span></div>
                  <?php if ((int) ($selectedOrder['production_order_id'] ?? 0) > 0): ?>
                    <div class="small text-info mt-1">New photos are synchronized directly with Production Order #<?= (int) $selectedOrder['production_order_id'] ?>.</div>
                  <?php endif; ?>
                  <div class="custom-order-photo-grid" data-custom-order-photo-grid>
                    <?php foreach (($selectedOrder['photos'] ?? []) as $photo): ?>
                      <div class="custom-order-photo-wrap" data-photo-id="<?= (int) $photo['id'] ?>">
                        <img src="<?= h((string) $photo['file_path']) ?>" class="custom-order-photo-thumb" data-full-src="<?= h((string) $photo['file_path']) ?>" alt="<?= h((string) ($photo['original_name'] ?? 'Order photo')) ?>">
                        <?php if ($customOrdersCanManage): ?><button type="button" class="btn btn-xs btn-danger custom-order-photo-delete" data-photo-id="<?= (int) $photo['id'] ?>" title="Delete photo">&times;</button><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                    <?php if (empty($selectedOrder['photos'])): ?><div class="text-muted small custom-order-photo-empty">No photos yet.</div><?php endif; ?>
                  </div>
                </fieldset>
              </div>
            </div>
          </div>
        </div>

        <?php $paymentsDefaultExpanded = !empty($customOrderSectionDefaults['payments']); ?>
        <div id="custom-order-payments-block" data-scroll-block data-custom-collapsible-panel data-section-key="payments" data-order-id="<?= (int) $selectedOrder['id'] ?>" data-default-expanded="<?= $paymentsDefaultExpanded ? '1' : '0' ?>" class="custom-orders-panel custom-collapsible-panel mt-3 mb-3<?= $paymentsDefaultExpanded ? ' is-expanded' : '' ?>">
          <button type="button" class="custom-collapsible-toggle" data-custom-collapsible-toggle aria-expanded="<?= $paymentsDefaultExpanded ? 'true' : 'false' ?>">
            <span class="custom-collapsible-toggle-title"><i class="fas fa-wallet" aria-hidden="true"></i>Payments And Deposits<?= customOrderHelp('payments_block') ?></span>
            <span class="custom-collapsible-toggle-meta">
              <span class="badge badge-info"><?= count((array) ($selectedOrder['payments'] ?? [])) ?></span>
              <span>records</span>
              <i class="fas fa-chevron-down custom-collapsible-toggle-chevron" aria-hidden="true"></i>
            </span>
          </button>
          <div class="panel-body custom-collapsible-body" data-custom-collapsible-body <?= $paymentsDefaultExpanded ? '' : 'hidden' ?>>
            <fieldset class="custom-field-cluster">
          <legend class="custom-field-cluster-title">Payment Details</legend>
          <form method="post" action="scripts/custom_orders/save_payment.php" data-scroll-target="#custom-order-payments-block">
            <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
            <div class="custom-payment-entry-grid">
              <div><label>Kind<?= customOrderHelp('payment_kind') ?></label><select name="payment_kind"
                  class="form-control form-control-sm"><?php foreach ($paymentKinds as $code => $label): ?>
                    <option value="<?= h($code) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select></div>
              <div><label>PayPal tx ID<?= customOrderHelp('paypal_transaction_id') ?></label><input type="text"
                  name="paypal_transaction_id" class="form-control form-control-sm"></div>
              <div><label>Amount<?= customOrderHelp('payment_amount') ?></label><input type="number" step="0.01"
                  name="amount" class="form-control form-control-sm" required></div>
              <div><label>Currency<?= customOrderHelp('payment_currency') ?></label><input type="text" name="currency"
                  class="form-control form-control-sm" value="<?= h($selectedOrder['currency']) ?>"></div>
              <div><label>Received at<?= customOrderHelp('payment_received_at') ?></label><input type="datetime-local"
                  name="received_at" class="form-control form-control-sm"></div>
            </div>
            <div class="custom-payment-note-row">
              <div><label>Note<?= customOrderHelp('payment_note') ?></label><input type="text" name="note" class="form-control form-control-sm"></div>
              <button type="submit" class="btn btn-outline-light btn-sm">Add Payment</button>
            </div>
          </form>
          <table class="table table-sm table-dark table-striped custom-mini-table custom-payment-history">
            <thead>
              <tr>
                <th>Kind<?= customOrderHelp('payment_kind') ?></th>
                <th>Amount<?= customOrderHelp('payment_amount') ?></th>
                <th>PayPal<?= customOrderHelp('paypal_transaction_id') ?></th>
                <th>Note<?= customOrderHelp('payment_note') ?></th>
                <th>At<?= customOrderHelp('payment_received_at') ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($selectedOrder['payments'] as $payment): ?>
                <tr>
                  <td><?= h($payment['payment_kind']) ?></td>
                  <td><?= number_format((float) $payment['amount'], 2) ?></td>
                  <td><?= h($payment['paypal_transaction_id']) ?></td>
                  <td><?= customOrderTruncate((string) ($payment['note'] ?? ''), 42) ?></td>
                  <td><?= h($payment['received_at']) ?></td>
                  <td>
                    <form method="post" action="scripts/custom_orders/delete_payment.php" data-scroll-target="#custom-order-payments-block" onsubmit="return confirm('Delete this payment record?');">
                      <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                      <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
            </fieldset>
          </div>
        </div>

        <div id="custom-order-builder-panel" data-scroll-block class="custom-orders-panel mb-3<?= isset($invalidFields['items']) ? ' custom-panel-invalid' : '' ?>">
          <div class="panel-body">
            <div class="custom-order-section-title">3. Products<?= customOrderHelp('products_block') ?></div>
            <?php $itemWorkflowStatusEnabled = (int) ($selectedOrder['production_order_id'] ?? 0) > 0; ?>
            <?php $editOptions = $editItem ? (json_decode((string) $editItem['options_json'], true) ?: []) : []; ?>
            <?php $editInternalOptions = $editItem ? (json_decode((string) ($editItem['internal_options_json'] ?? ''), true) ?: []) : []; ?>
            <?php $currentBuilderType = $editItem ? strtoupper((string) ($editItem['item_type_code'] ?? '')) : $builderType; ?>
            <?php $currentBuilderDepartment = customOrdersItemTypeToDepartment($currentBuilderType); ?>
            <?php $currentBuilderSubcategory = $currentBuilderDepartment === 'G' ? customOrdersGraphicsSubcategoryFromItemData((string) ($editInternalOptions['_subcat'] ?? ''), (string) ($editItem['custom_label'] ?? ''), (string) ($editItem['sku'] ?? '')) : ''; ?>
            <?php $builderTitleValue = $editItem ? (string) ($editItem['title'] ?? '') : ($currentBuilderType === 'F' ? 'Fitting' : ''); ?>
            <?php $builderUnitPriceValue = $editItem ? ($editItem['unit_price'] ?? 0) : ($currentBuilderType === 'F' ? '39.90' : 0); ?>
            <?php
            $builderStatusItem = [
              'item_type_code' => $currentBuilderType !== '' ? $currentBuilderType : 'G',
              'sku' => (string) ($editItem['sku'] ?? 'MANUAL'),
              'custom_label' => (string) ($editItem['custom_label'] ?? ''),
              'internal_options_json' => json_encode(['_subcat' => $currentBuilderSubcategory]),
            ];
            $builderStatusDefinitions = customOrdersItemStatusDefinitions($conn, $builderStatusItem, true);
            $builderCurrentStatus = customOrdersResolveItemStatus($conn, $builderStatusItem, (string) ($editItem['status'] ?? ''));
            if (!isset($builderStatusDefinitions[$builderCurrentStatus])) {
              $builderStatusDefinitions[$builderCurrentStatus] = ['label' => str_replace('_', ' ', $builderCurrentStatus), 'color' => '#6c757d'];
            }
            ?>
            <form method="post" action="scripts/custom_orders/save_item.php" class="custom-inline-item-edit-form custom-add-item-form" data-scroll-target="#custom-order-builder-panel">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <input type="hidden" name="custom_item_id" value="<?= (int) ($editItem['id'] ?? 0) ?>">
              <input type="hidden" name="inline_edit" value="1">
              <div class="custom-item-builder-shell">
                <div class="custom-builder-picker">
                  <div class="custom-builder-picker-label">
                    <label>Product Type<?= customOrderHelp('item_type_code') ?></label>
                    <select name="item_type_code" class="form-control form-control-sm custom-item-type-select">
                      <option value="">Select product type...</option>
                      <?php foreach ($allowedTypes as $code => $label): ?><option value="<?= h($code) ?>" <?= ($currentBuilderType === $code) ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="custom-builder-picker-label" data-graphics-subcategory-wrap <?= $currentBuilderDepartment === 'G' ? '' : 'hidden' ?>>
                    <label>Graphics subcategory<?= customOrderHelp('graphics_subcategory') ?></label>
                    <select name="graphics_subcategory" class="form-control form-control-sm custom-graphics-subcategory-select">
                      <option value="">Graphics Kit</option>
                      <?php foreach ($graphicsSubcategoryLabels as $subcatCode => $subcatLabel): ?>
                        <option value="<?= h((string) $subcatCode) ?>" <?= $currentBuilderSubcategory === (string) $subcatCode ? 'selected' : '' ?>><?= h((string) $subcatLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="custom-builder-order-shell" data-builder-body <?= $currentBuilderType === '' ? 'hidden' : '' ?>>
                  <div class="custom-builder-subtitle">Core Item Row</div>
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 custom-builder-order-table">
                      <tbody>
                        <tr class="item-repeat-header-row">
                          <th class="text-center">Assigned<?= customOrderHelp('item_assigned') ?></th>
                          <th>Type<?= customOrderHelp('item_type_code') ?></th>
                          <th class="text-center">Nazov<?= customOrderHelp('item_title') ?></th>
                          <th>Qty<?= customOrderHelp('item_qty') ?></th>
                          <th>Price<?= customOrderHelp('item_unit_price') ?></th>
                          <th>Category Info<?= customOrderHelp('item_category') ?></th>
                          <th>Link<?= customOrderHelp('item_link') ?></th>
                          <th class="text-center">Detail<?= customOrderHelp('item_detail') ?></th>
                          <th>Action<?= customOrderHelp('item_action') ?></th>
                          <th>Waiting<?= customOrderHelp('item_waiting') ?></th>
                          <th class="text-center">Save<?= customOrderHelp('item_save') ?></th>
                          <th class="text-center">Delete<?= customOrderHelp('item_delete') ?></th>
                        </tr>
                        <tr class="item-info-row <?= $currentBuilderType !== '' ? 'item-type-' . h($currentBuilderType) : '' ?>" data-builder-row>
                          <td class="text-center" style="width:56px;">
                            <span class="custom-builder-assigned-placeholder" title="Lead owner / production assignment context">
                              <?= h($selectedOrder['owner_name'] ? strtoupper(substr((string) $selectedOrder['owner_name'], 0, 1)) : '-') ?>
                            </span>
                          </td>
                          <td class="text-center" style="width:46px;">
                            <span class="custom-builder-type-badge" data-builder-type-badge><?= h($currentBuilderType !== '' ? $currentBuilderType : '?') ?></span>
                          </td>
                          <td style="min-width:280px;">
                            <input type="text" name="title" class="form-control form-control-sm mb-1" value="<?= h($builderTitleValue) ?>" required>
                            <div class="custom-existing-item-meta-edit">
                              <input type="text" name="sku" class="form-control form-control-sm" value="<?= h($editItem['sku'] ?? '') ?>" placeholder="SKU / MANUAL">
                              <input type="text" name="custom_label" class="form-control form-control-sm" value="<?= h($editItem['custom_label'] ?? '') ?>" placeholder="Custom label">
                            </div>
                          </td>
                          <td style="width:72px;">
                            <input type="number" min="1" name="qty" class="form-control form-control-sm" value="<?= h($editItem['qty'] ?? 1) ?>">
                          </td>
                          <td style="width:92px;">
                            <input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" value="<?= h($builderUnitPriceValue) ?>">
                          </td>
                          <td style="min-width:220px;">
                            <?php $builderCategoryInfo = trim((string) ($editOptions['category_info'] ?? '')); ?>
                            <input type="hidden" name="category_info" value="<?= h($builderCategoryInfo) ?>">
                            <input type="hidden" name="category_brand" value="<?= h($editOptions['category_brand'] ?? '') ?>">
                            <input type="hidden" name="category_model" value="<?= h($editOptions['category_model'] ?? '') ?>">
                            <input type="hidden" name="category_year_range" value="<?= h($editOptions['category_year_range'] ?? '') ?>">
                            <input type="hidden" name="category_modelcode" value="<?= h($editOptions['category_modelcode'] ?? '') ?>">
                            <button type="button" class="btn btn-sm btn-outline-info custom-category-info-trigger<?= $builderCategoryInfo === '' ? ' is-empty' : '' ?>" title="Select or change Brand, Model, Year range and Model Code">
                              <span class="custom-category-info-text"><?= h($builderCategoryInfo !== '' ? $builderCategoryInfo : 'Select Brand / Model / Year / Model Code') ?></span>
                              <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            </button>
                            <div class="custom-existing-item-upsell-edit">
                              <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="is_upsell" id="is_upsell" value="1" <?= (int) ($editItem['is_upsell'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_upsell">Upsell<?= customOrderHelp('item_is_upsell') ?></label>
                              </div>
                            </div>
                          </td>
                          <td class="text-center" style="width:76px;">
                            <button type="button" class="btn btn-sm btn-outline-info custom-builder-link-btn" disabled><i class="fas fa-external-link-alt"></i></button>
                          </td>
                          <td class="text-center" style="width:92px;">
                            <button type="button" class="btn btn-xs btn-outline-info custom-builder-mini-btn" disabled>Detail</button>
                          </td>
                          <td style="min-width:140px;">
                            <select name="item_status" class="form-control form-control-sm custom-item-status-select" data-status-dynamic="1" <?= $itemWorkflowStatusEnabled ? '' : 'disabled aria-disabled="true" title="Item workflow starts after export to Production."' ?>>
                              <?php foreach ($builderStatusDefinitions as $statusCode => $statusMeta): ?>
                                <option value="<?= h($statusCode) ?>" data-color="<?= h((string) ($statusMeta['color'] ?? '')) ?>" <?= $builderCurrentStatus === $statusCode ? 'selected' : '' ?>><?= h((string) ($statusMeta['label'] ?? $statusCode)) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td style="min-width:170px;">
                            <div class="input-group input-group-sm mb-1">
                              <input type="text" class="form-control form-control-sm" placeholder="Na co cakame?" disabled>
                              <div class="input-group-append">
                                <button type="button" class="btn btn-outline-success" disabled><i class="fas fa-save"></i></button>
                              </div>
                            </div>
                            <input type="date" class="form-control form-control-sm" disabled>
                          </td>
                          <td class="text-center" style="width:52px;">
                            <button type="submit" data-builder-body <?= $currentBuilderType === '' ? 'hidden' : '' ?> class="btn btn-xs btn-outline-success custom-builder-mini-btn">Save</button>
                          </td>
                          <td class="text-center" style="width:58px;">
                            <button type="button" class="btn btn-xs btn-outline-danger custom-builder-mini-btn" disabled>Delete</button>
                          </td>
                        </tr>

                        <?php foreach ($productSpecDefinitions as $departmentCode => $definitions): ?>
                          <?php
                          $subcatScopes = [''];
                          if ($departmentCode === 'G') {
                            $subcatScopes = array_merge($subcatScopes, array_keys($graphicsSubcategoryLabels));
                          }
                          ?>
                          <?php foreach ($subcatScopes as $subcatScope): ?>
                            <?php
                            $filteredDefinitions = customOrdersFilterSpecDefinitionsForBuilder($definitions, $departmentCode, (string) $subcatScope);
                            $departmentMainFields = [];
                            $departmentTextFields = [];
                            foreach ($filteredDefinitions as $definition) {
                              if (customOrderBuilderDefinitionIsTextarea($definition)) {
                                $departmentTextFields[] = $definition;
                              } else {
                                $departmentMainFields[] = $definition;
                              }
                            }
                            $rowVisible = $currentBuilderDepartment === $departmentCode;
                            if ($rowVisible && $departmentCode === 'G') {
                              $rowVisible = (string) $currentBuilderSubcategory === (string) $subcatScope;
                            }
                            ?>

                            <?php if (!empty($departmentMainFields)): ?>
                              <tr class="g-item-options-row <?= $currentBuilderType !== '' ? 'item-type-' . h($currentBuilderType) : '' ?> custom-item-spec-group" data-builder-row data-department="<?= h($departmentCode) ?>" data-subcategory="<?= h((string) $subcatScope) ?>" <?= $rowVisible ? '' : 'hidden' ?>>
                                <td colspan="99">
                                  <div class="g-options-bar">
                                    <?php foreach ($departmentMainFields as $definition): ?>
                                      <label class="product-spec-label">
                                        <span class="product-spec-label-title"><?= h(customOrderBuilderSpecLabel($departmentCode, $definition)) ?></span>
                                        <?= customOrderRenderSpecFieldInput($conn, $definition, $editOptions, $selectedOrder) ?>
                                      </label>
                                    <?php endforeach; ?>
                                  </div>
                                </td>
                              </tr>
                            <?php endif; ?>

                            <?php if (!empty($departmentTextFields)): ?>
                              <tr class="g-item-options-row <?= $currentBuilderType !== '' ? 'item-type-' . h($currentBuilderType) : '' ?> custom-item-spec-group" data-builder-row data-department="<?= h($departmentCode) ?>" data-subcategory="<?= h((string) $subcatScope) ?>" <?= $rowVisible ? '' : 'hidden' ?>>
                                <td colspan="99">
                                  <div class="g-options-bar">
                                    <?php foreach ($departmentTextFields as $definition): ?>
                                      <label class="product-spec-label">
                                        <span class="product-spec-label-title"><?= h(customOrderBuilderSpecLabel($departmentCode, $definition)) ?></span>
                                        <?= customOrderRenderSpecFieldInput($conn, $definition, $editOptions, $selectedOrder) ?>
                                      </label>
                                    <?php endforeach; ?>
                                  </div>
                                </td>
                              </tr>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        <?php endforeach; ?>

                        <tr class="item-spacer-row" aria-hidden="true">
                          <td colspan="99"></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

              </div>
            </form>
            <div class="custom-optical-divider"></div>

            <div class="custom-field-cluster-title">Existing Items And Upsells<?= customOrderHelp('products_block') ?></div>
            <div class="custom-existing-items">
              <?php foreach ($selectedOrder['items'] as $item): ?>
                <?php
                $itemOptions = json_decode((string) ($item['options_json'] ?? ''), true) ?: [];
                $itemInternalOptions = json_decode((string) ($item['internal_options_json'] ?? ''), true) ?: [];
                $itemTypeCode = strtoupper((string) ($item['item_type_code'] ?? 'M'));
                $itemDepartment = customOrdersItemTypeToDepartment($itemTypeCode);
                $itemSubcategory = $itemDepartment === 'G'
                  ? customOrdersGraphicsSubcategoryFromItemData(
                    (string) ($itemInternalOptions['_subcat'] ?? ''),
                    (string) ($item['custom_label'] ?? ''),
                    (string) ($item['sku'] ?? '')
                  )
                  : '';
                $itemDefinitions = customOrdersFilterSpecDefinitionsForBuilder(
                  $productSpecDefinitions[$itemDepartment] ?? [],
                  $itemDepartment,
                  $itemSubcategory
                );
                $itemMainDefinitions = [];
                $itemTextDefinitions = [];
                foreach ($itemDefinitions as $itemDefinition) {
                  if (customOrderBuilderDefinitionIsTextarea($itemDefinition)) {
                    $itemTextDefinitions[] = $itemDefinition;
                  } else {
                    $itemMainDefinitions[] = $itemDefinition;
                  }
                }
                $itemCategoryInfo = trim((string) ($itemOptions['category_info'] ?? ''));
                $itemStatusDefinitions = customOrdersItemStatusDefinitions($conn, $item, true);
                $itemCurrentStatus = customOrdersResolveItemStatus($conn, $item, (string) ($item['status'] ?? ''));
                if (!isset($itemStatusDefinitions[$itemCurrentStatus])) {
                  $itemStatusDefinitions[$itemCurrentStatus] = ['label' => str_replace('_', ' ', $itemCurrentStatus), 'color' => '#6c757d'];
                }
                $itemModalId = 'custom-item-modal-' . (int) $item['id'];
                $itemEditFormId = 'custom-item-inline-edit-' . (int) $item['id'];
                $itemDeleteFormId = 'custom-item-inline-delete-' . (int) $item['id'];
                ?>
                <form method="post" action="scripts/custom_orders/save_item.php" id="<?= h($itemEditFormId) ?>" class="mb-0 custom-inline-item-edit-form" data-scroll-target="#custom-order-builder-panel">
                  <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                  <input type="hidden" name="custom_item_id" value="<?= (int) $item['id'] ?>">
                  <input type="hidden" name="item_type_code" value="<?= h($itemTypeCode) ?>">
                  <input type="hidden" name="inline_edit" value="1">
                  <?php if ($itemDepartment === 'G'): ?><input type="hidden" name="graphics_subcategory" value="<?= h($itemSubcategory) ?>"><?php endif; ?>
                <div class="custom-builder-order-shell">
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 custom-builder-order-table">
                      <tbody>
                        <tr class="item-repeat-header-row">
                          <th class="text-center">Assigned<?= customOrderHelp('item_assigned') ?></th>
                          <th>Type<?= customOrderHelp('item_type_code') ?></th>
                          <th class="text-center">Nazov<?= customOrderHelp('item_title') ?></th>
                          <th>Qty<?= customOrderHelp('item_qty') ?></th>
                          <th>Price<?= customOrderHelp('item_unit_price') ?></th>
                          <th>Category Info<?= customOrderHelp('item_category') ?></th>
                          <th>Link<?= customOrderHelp('item_link') ?></th>
                          <th class="text-center">Detail<?= customOrderHelp('item_detail') ?></th>
                          <th>Action<?= customOrderHelp('item_action') ?></th>
                          <th>Waiting<?= customOrderHelp('item_waiting') ?></th>
                          <th class="text-center">Save<?= customOrderHelp('item_save') ?></th>
                          <th class="text-center">Delete<?= customOrderHelp('item_delete') ?></th>
                        </tr>
                        <tr class="item-info-row item-type-<?= h($itemTypeCode) ?>">
                          <td class="text-center" style="width:56px;">
                            <span class="custom-builder-assigned-placeholder" title="Custom order owner">
                              <?= h($selectedOrder['owner_name'] ? strtoupper(substr((string) $selectedOrder['owner_name'], 0, 1)) : '-') ?>
                            </span>
                          </td>
                          <td class="text-center" style="width:46px;"><span class="custom-builder-type-badge"><?= h($itemTypeCode) ?></span></td>
                          <td style="min-width:280px;">
                            <input name="title" class="form-control form-control-sm mb-1" value="<?= h($item['title']) ?>" required>
                            <div class="custom-existing-item-meta-edit">
                              <input name="sku" class="form-control form-control-sm" value="<?= h($item['sku'] ?: 'MANUAL') ?>" placeholder="SKU">
                              <input name="custom_label" class="form-control form-control-sm" value="<?= h($item['custom_label']) ?>" placeholder="Custom label">
                            </div>
                          </td>
                          <td style="width:72px;"><input type="number" min="1" name="qty" class="form-control form-control-sm" value="<?= (int) $item['qty'] ?>"></td>
                          <td style="width:92px;"><input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" value="<?= number_format((float) $item['unit_price'], 2, '.', '') ?>"></td>
                          <td style="min-width:220px;">
                            <input type="hidden" name="category_info" value="<?= h($itemCategoryInfo) ?>">
                            <input type="hidden" name="category_brand" value="<?= h($itemOptions['category_brand'] ?? '') ?>">
                            <input type="hidden" name="category_model" value="<?= h($itemOptions['category_model'] ?? '') ?>">
                            <input type="hidden" name="category_year_range" value="<?= h($itemOptions['category_year_range'] ?? '') ?>">
                            <input type="hidden" name="category_modelcode" value="<?= h($itemOptions['category_modelcode'] ?? '') ?>">
                            <button type="button" class="btn btn-sm btn-outline-info custom-category-info-trigger<?= $itemCategoryInfo === '' ? ' is-empty' : '' ?>" title="Select or change Brand, Model, Year range and Model Code">
                              <span class="custom-category-info-text"><?= h($itemCategoryInfo !== '' ? $itemCategoryInfo : 'Select Brand / Model / Year / Model Code') ?></span>
                              <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            </button>
                            <div class="custom-existing-item-upsell-edit">
                              <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_upsell" id="inline-upsell-<?= (int) $item['id'] ?>" value="1" <?= (int) $item['is_upsell'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="inline-upsell-<?= (int) $item['id'] ?>">Upsell<?= customOrderHelp('item_is_upsell') ?></label></div>
                            </div>
                          </td>
                          <td class="text-center" style="width:76px;"><button type="button" class="btn btn-sm btn-outline-info custom-builder-link-btn" disabled><i class="fas fa-external-link-alt"></i></button></td>
                          <td class="text-center" style="width:92px;"><button type="button" class="btn btn-xs btn-outline-info custom-builder-mini-btn" data-toggle="modal" data-target="#<?= h($itemModalId) ?>">Detail</button></td>
                          <td style="min-width:160px;">
                            <select name="item_status" class="form-control form-control-sm custom-item-status-select" <?= $itemWorkflowStatusEnabled ? '' : 'disabled aria-disabled="true" title="Item workflow starts after export to Production."' ?>>
                              <?php foreach ($itemStatusDefinitions as $statusCode => $statusMeta): ?>
                                <option value="<?= h($statusCode) ?>" data-color="<?= h((string) ($statusMeta['color'] ?? '')) ?>" <?= $itemCurrentStatus === $statusCode ? 'selected' : '' ?>><?= h((string) ($statusMeta['label'] ?? $statusCode)) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td style="min-width:170px;">
                            <div class="input-group input-group-sm mb-1"><input class="form-control form-control-sm" placeholder="Na čo čakáme?" disabled><div class="input-group-append"><button type="button" class="btn btn-outline-success" disabled><i class="fas fa-save"></i></button></div></div>
                            <input type="date" class="form-control form-control-sm" disabled>
                          </td>
                          <td class="text-center" style="width:58px;">
                            <button type="submit" class="btn btn-xs btn-outline-success">Save</button>
                          </td>
                          <td class="text-center" style="width:62px;">
                            <button type="submit" form="<?= h($itemDeleteFormId) ?>" class="btn btn-xs btn-outline-danger">Delete</button>
                          </td>
                        </tr>

                        <?php if ($itemMainDefinitions): ?>
                          <tr class="g-item-options-row item-type-<?= h($itemTypeCode) ?>">
                            <td colspan="99"><div class="g-options-bar">
                              <?php foreach ($itemMainDefinitions as $itemDefinition): ?><label class="product-spec-label"><span class="product-spec-label-title"><?= h(customOrderBuilderSpecLabel($itemDepartment, $itemDefinition)) ?></span><?= customOrderRenderSpecFieldInput($conn, $itemDefinition, $itemOptions, $selectedOrder) ?></label><?php endforeach; ?>
                            </div></td>
                          </tr>
                        <?php endif; ?>

                        <?php if ($itemTextDefinitions): ?>
                          <tr class="g-item-options-row item-type-<?= h($itemTypeCode) ?>">
                            <td colspan="99"><div class="g-options-bar">
                              <?php foreach ($itemTextDefinitions as $itemDefinition): ?><label class="product-spec-label"><span class="product-spec-label-title"><?= h(customOrderBuilderSpecLabel($itemDepartment, $itemDefinition)) ?></span><?= customOrderRenderSpecFieldInput($conn, $itemDefinition, $itemOptions, $selectedOrder) ?></label><?php endforeach; ?>
                            </div></td>
                          </tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                </form>
                <form method="post" action="scripts/custom_orders/delete_item.php" id="<?= h($itemDeleteFormId) ?>" class="d-none" data-scroll-target="#custom-order-builder-panel" onsubmit="return confirm('Delete this custom order item?');">
                  <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                  <input type="hidden" name="custom_item_id" value="<?= (int) $item['id'] ?>">
                </form>
              <?php endforeach; ?>
              <?php if (empty($selectedOrder['items'])): ?><div class="text-muted">No products added yet.</div><?php endif; ?>
            </div>

            <?php foreach ($selectedOrder['items'] as $item): ?>
              <?php $itemOptions = json_decode((string) ($item['options_json'] ?? ''), true) ?: []; ?>
              <?php $itemCategoryInfo = trim((string) ($itemOptions['category_info'] ?? '')); ?>
              <?php $itemOptionGroups = customOrderItemOptionGroups($conn, (string) ($item['item_type_code'] ?? ''), $itemOptions); ?>
              <?php $itemModalId = 'custom-item-modal-' . (int) $item['id']; ?>
              <div class="modal fade custom-item-modal" id="<?= h($itemModalId) ?>" tabindex="-1" role="dialog"
                aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                  <div class="modal-content bg-dark">
                    <div class="modal-header">
                      <div>
                        <h5 class="modal-title"><?= h($item['title']) ?></h5>
                        <div class="modal-subtitle">Custom item detail</div>
                      </div>
                      <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                      </button>
                    </div>
                    <div class="modal-body">
                      <div class="custom-item-summary">
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">Type</div>
                          <div class="custom-item-summary-card-value"><?= h($item['item_type_code']) ?></div>
                        </div>
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">SKU</div>
                          <div class="custom-item-summary-card-value"><?= h($item['sku'] ?: '-') ?></div>
                        </div>
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">Qty</div>
                          <div class="custom-item-summary-card-value"><?= (int) $item['qty'] ?></div>
                        </div>
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">Unit price</div>
                          <div class="custom-item-summary-card-value"><?= number_format((float) $item['unit_price'], 2) ?>
                          </div>
                        </div>
                      </div>

                      <div class="custom-item-sections">
                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Core Info</div>
                          <div class="custom-item-detail-grid">
                            <div class="custom-item-detail-row is-full">
                              <div class="custom-item-detail-label">Title</div>
                              <div class="custom-item-detail-value"><?= h($item['title']) ?></div>
                            </div>
                            <div class="custom-item-detail-row is-full">
                              <div class="custom-item-detail-label">Custom label</div>
                              <div class="custom-item-detail-value"><?= h($item['custom_label'] ?: '-') ?></div>
                            </div>
                            <div class="custom-item-detail-row is-full">
                              <div class="custom-item-detail-label">Category info</div>
                              <div class="custom-item-detail-value"><?= h($itemCategoryInfo ?: '-') ?></div>
                            </div>
                            <div class="custom-item-detail-row">
                              <div class="custom-item-detail-label">Rider name</div>
                              <div class="custom-item-detail-value"><?= h((string) ($itemOptions['name'] ?? '-')) ?></div>
                            </div>
                            <div class="custom-item-detail-row">
                              <div class="custom-item-detail-label">Rider number</div>
                              <div class="custom-item-detail-value"><?= h((string) ($itemOptions['number'] ?? '-')) ?></div>
                            </div>
                          </div>
                        </div>

                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Sales Flags</div>
                          <div class="custom-item-meta-list">
                            <div class="custom-item-meta-pill">
                              <strong>Upsell</strong><span><?= (int) $item['is_upsell'] === 1 ? 'Yes' : 'No' ?></span></div>
                            <div class="custom-item-meta-pill"><strong>Upsell
                                source</strong><span><?= h((string) ($item['upsell_source'] ?? '-')) ?></span></div>
                          </div>
                        </div>
                      </div>

                      <div class="custom-item-sections mt-3">
                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Specification</div>
                          <div class="custom-item-detail-grid">
                            <?php if (!empty($itemOptionGroups['spec_rows'])): ?>
                              <?php foreach ($itemOptionGroups['spec_rows'] as $specRow): ?>
                                <div class="custom-item-detail-row">
                                  <div class="custom-item-detail-label"><?= h((string) ($specRow['label'] ?? '')) ?></div>
                                  <div class="custom-item-detail-value"><?= h((string) ($specRow['value'] ?? '-')) ?></div>
                                </div>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <div class="custom-item-detail-row is-full">
                                <div class="custom-item-detail-label">Specification</div>
                                <div class="custom-item-detail-value">-</div>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>

                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Notes</div>
                          <?php if (!empty($itemOptionGroups['note_rows'])): ?>
                            <?php foreach ($itemOptionGroups['note_rows'] as $noteRow): ?>
                              <div class="custom-item-note-box mb-2">
                                <strong><?= h((string) ($noteRow['label'] ?? '')) ?>:</strong><br>
                                <?= customOrderFormatMultiline((string) ($noteRow['value'] ?? '-')) ?>
                              </div>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <div class="custom-item-note-box">-</div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="modal fade custom-category-picker-modal" tabindex="-1" role="dialog" aria-hidden="true" data-category-picker-modal>
              <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                  <div class="modal-header">
                    <div>
                      <h5 class="modal-title mb-1">Select Category Info</h5>
                      <div class="small text-muted">Choose compatibility in the same order as in Product Chart.</div>
                    </div>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                  </div>
                  <div class="modal-body">
                    <div class="custom-category-picker-steps">
                      <div class="custom-category-picker-step">
                        <label><span class="custom-category-picker-step-number">1</span> Brand<?= customOrderHelp('category_brand') ?></label>
                        <select class="form-control form-control-sm" data-category-brand>
                          <option value="">Loading brands...</option>
                        </select>
                      </div>
                      <div class="custom-category-picker-step">
                        <label><span class="custom-category-picker-step-number">2</span> Model<?= customOrderHelp('category_model') ?></label>
                        <select class="form-control form-control-sm" data-category-model disabled>
                          <option value="">Select brand first</option>
                        </select>
                      </div>
                      <div class="custom-category-picker-step">
                        <label><span class="custom-category-picker-step-number">3</span> Year range / Model Code<?= customOrderHelp('category_year_range') ?></label>
                        <select class="form-control form-control-sm" data-category-year disabled>
                          <option value="">Select model first</option>
                        </select>
                      </div>
                    </div>
                    <div class="custom-category-picker-preview" data-category-preview>Select Brand, Model and Year range to load Model Code.</div>
                    <div class="small text-danger mt-2" data-category-error hidden></div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-danger mr-auto" data-category-clear>Clear Category Info</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-success" data-category-apply disabled>Apply</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php
        $notesDefaultExpanded = !empty($customOrderSectionDefaults['notes']);
        $visibleDiscussionNotes = array_values(array_filter(
          (array) ($selectedOrder['notes'] ?? []),
          static function (array $note) use ($customOrdersCanViewNoteAudit, $focusNoteId): bool {
            return $customOrdersCanViewNoteAudit
              || empty($note['deleted_at'])
              || (int) ($note['id'] ?? 0) === $focusNoteId;
          }
        ));
        ?>
        <div id="custom-order-notes-panel" data-scroll-block data-custom-collapsible-panel data-section-key="notes" data-order-id="<?= (int) $selectedOrder['id'] ?>" data-focus-note-id="<?= (int) $focusNoteId ?>" data-default-expanded="<?= $notesDefaultExpanded ? '1' : '0' ?>" class="custom-orders-panel custom-collapsible-panel mb-3<?= $notesDefaultExpanded ? ' is-expanded' : '' ?>">
          <button type="button" class="custom-collapsible-toggle" data-custom-collapsible-toggle aria-expanded="<?= $notesDefaultExpanded ? 'true' : 'false' ?>">
            <span class="custom-collapsible-toggle-title"><i class="fas fa-comment-alt" aria-hidden="true"></i>4. Append-Only Notes<?= customOrderHelp('notes_block') ?></span>
            <span class="custom-collapsible-toggle-meta">
              <span class="badge badge-info"><?= count($visibleDiscussionNotes) ?></span>
              <span>messages</span>
              <i class="fas fa-chevron-down custom-collapsible-toggle-chevron" aria-hidden="true"></i>
            </span>
          </button>
          <div class="panel-body custom-collapsible-body" data-custom-collapsible-body <?= $notesDefaultExpanded ? '' : 'hidden' ?>>
            <?php
            $rootNotes = [];
            $noteReplies = [];
            foreach ($visibleDiscussionNotes as $discussionNote) {
              $discussionNoteId = (int) ($discussionNote['id'] ?? 0);
              $discussionParentId = (int) ($discussionNote['parent_note_id'] ?? 0);
              if ($discussionParentId > 0) {
                $noteReplies[$discussionParentId][] = $discussionNote;
              } else {
                $rootNotes[$discussionNoteId] = $discussionNote;
              }
            }
            foreach ($noteReplies as $discussionParentId => $orphanCheckReplies) {
              if (!isset($rootNotes[$discussionParentId])) {
                foreach ($orphanCheckReplies as $orphanReply) {
                  $rootNotes[(int) ($orphanReply['id'] ?? 0)] = $orphanReply;
                }
                unset($noteReplies[$discussionParentId]);
              }
            }
            ?>
            <div class="custom-notes-timeline">
              <?php foreach ($rootNotes as $noteId => $note): ?>
                <?php
                $replyFormId = 'custom-note-reply-' . (int) $noteId;
                $editFormId = 'custom-note-edit-' . (int) $noteId;
                $noteIsDeleted = !empty($note['deleted_at']);
                $noteCanModify = !$noteIsDeleted && ($customOrdersCanManage || (int) ($note['created_by'] ?? 0) === $customOrdersCurrentUserId);
                $noteAuditRevisions = (array) (($selectedOrder['note_revisions'] ?? [])[(int) $noteId] ?? []);
                ?>
                <div class="custom-note-thread">
                  <article id="custom-note-<?= (int) $noteId ?>" class="custom-note-entry<?= $noteIsDeleted ? ' is-deleted' : '' ?>">
                    <div><?= customOrderNoteAuthorAvatar($note) ?></div>
                    <div class="custom-note-entry-content">
                      <div class="custom-note-entry-meta">
                        <span><?= h((string) ($note['created_at'] ?? '')) ?></span>
                        <span class="custom-note-entry-actions">
                          <?php if (!empty($note['updated_at'])): ?><span class="custom-note-audit-badge">Edited</span><?php endif; ?>
                          <?php if ($noteIsDeleted): ?><span class="custom-note-audit-badge is-deleted">Deleted</span><?php endif; ?>
                          <?php if (!$noteIsDeleted): ?><button type="button" class="custom-note-reply-button" data-note-reply-toggle data-reply-form="#<?= h($replyFormId) ?>">Reply</button><?php endif; ?>
                          <?php if ($noteCanModify): ?>
                            <button type="button" class="custom-note-reply-button" data-note-edit-toggle data-edit-form="#<?= h($editFormId) ?>">Edit</button>
                            <form method="post" action="scripts/custom_orders/delete_note.php" data-scroll-target="#custom-order-notes-panel" onsubmit="return confirm('Delete this note?');">
                              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                              <input type="hidden" name="note_id" value="<?= (int) $noteId ?>">
                              <button type="submit" class="custom-note-reply-button text-danger">Delete</button>
                            </form>
                          <?php endif; ?>
                        </span>
                      </div>
                      <div class="custom-note-entry-body"><?= h($noteIsDeleted && !$customOrdersCanViewNoteAudit ? 'This note was deleted.' : (string) ($note['note_body'] ?? '')) ?></div>
                      <?php if ($noteCanModify): ?>
                        <form method="post" action="scripts/custom_orders/edit_note.php" id="<?= h($editFormId) ?>" class="custom-note-edit-form" data-scroll-target="#custom-order-notes-panel" hidden>
                          <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                          <input type="hidden" name="note_id" value="<?= (int) $noteId ?>">
                          <textarea name="note_body" rows="3" class="form-control form-control-sm" required><?= h((string) ($note['note_body'] ?? '')) ?></textarea>
                          <div class="custom-note-reply-actions">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-note-edit-cancel>Cancel</button>
                            <button type="submit" class="btn btn-outline-warning btn-sm">Save edit</button>
                          </div>
                        </form>
                      <?php endif; ?>
                      <?php if ($customOrdersCanViewNoteAudit): ?><?= customOrderNoteAuditHistoryHtml($noteAuditRevisions) ?><?php endif; ?>
                    </div>
                  </article>

                  <?php if (!empty($noteReplies[$noteId])): ?>
                    <div class="custom-note-replies">
                      <?php foreach ($noteReplies[$noteId] as $reply): ?>
                        <?php
                        $replyId = (int) ($reply['id'] ?? 0);
                        $replyEditFormId = 'custom-note-edit-' . $replyId;
                        $replyIsDeleted = !empty($reply['deleted_at']);
                        $replyCanModify = !$replyIsDeleted && ($customOrdersCanManage || (int) ($reply['created_by'] ?? 0) === $customOrdersCurrentUserId);
                        $replyAuditRevisions = (array) (($selectedOrder['note_revisions'] ?? [])[$replyId] ?? []);
                        ?>
                        <div class="custom-note-reply-wrap">
                          <article id="custom-note-<?= $replyId ?>" class="custom-note-entry is-reply<?= $replyIsDeleted ? ' is-deleted' : '' ?>">
                            <div><?= customOrderNoteAuthorAvatar($reply) ?></div>
                            <div class="custom-note-entry-content">
                              <div class="custom-note-entry-meta">
                                <span><?= h((string) ($reply['created_at'] ?? '')) ?></span>
                                <span class="custom-note-entry-actions">
                                  <?php if (!empty($reply['updated_at'])): ?><span class="custom-note-audit-badge">Edited</span><?php endif; ?>
                                  <?php if ($replyIsDeleted): ?><span class="custom-note-audit-badge is-deleted">Deleted</span><?php endif; ?>
                                  <?php if (!$replyIsDeleted): ?><button type="button" class="custom-note-reply-button" data-note-reply-toggle data-reply-form="#<?= h($replyFormId) ?>">Reply</button><?php endif; ?>
                                  <?php if ($replyCanModify): ?>
                                    <button type="button" class="custom-note-reply-button" data-note-edit-toggle data-edit-form="#<?= h($replyEditFormId) ?>">Edit</button>
                                    <form method="post" action="scripts/custom_orders/delete_note.php" data-scroll-target="#custom-order-notes-panel" onsubmit="return confirm('Delete this reply?');">
                                      <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                                      <input type="hidden" name="note_id" value="<?= $replyId ?>">
                                      <button type="submit" class="custom-note-reply-button text-danger">Delete</button>
                                    </form>
                                  <?php endif; ?>
                                </span>
                              </div>
                              <div class="custom-note-entry-body"><?= h($replyIsDeleted && !$customOrdersCanViewNoteAudit ? 'This reply was deleted.' : (string) ($reply['note_body'] ?? '')) ?></div>
                              <?php if ($replyCanModify): ?>
                                <form method="post" action="scripts/custom_orders/edit_note.php" id="<?= h($replyEditFormId) ?>" class="custom-note-edit-form" data-scroll-target="#custom-order-notes-panel" hidden>
                                  <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                                  <input type="hidden" name="note_id" value="<?= $replyId ?>">
                                  <textarea name="note_body" rows="3" class="form-control form-control-sm" required><?= h((string) ($reply['note_body'] ?? '')) ?></textarea>
                                  <div class="custom-note-reply-actions">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-note-edit-cancel>Cancel</button>
                                    <button type="submit" class="btn btn-outline-warning btn-sm">Save edit</button>
                                  </div>
                                </form>
                              <?php endif; ?>
                              <?php if ($customOrdersCanViewNoteAudit): ?><?= customOrderNoteAuditHistoryHtml($replyAuditRevisions) ?><?php endif; ?>
                            </div>
                          </article>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <form method="post" action="scripts/custom_orders/save_note.php" id="<?= h($replyFormId) ?>" class="custom-note-reply-form" data-scroll-target="#custom-order-notes-panel" hidden>
                    <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                    <input type="hidden" name="parent_note_id" value="<?= (int) $noteId ?>">
                    <label>Reply<?= customOrderHelp('note_body') ?></label>
                    <textarea name="note_body" rows="2" class="form-control form-control-sm" placeholder="Write a reply..." required></textarea>
                    <div class="custom-note-reply-actions">
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-note-reply-cancel>Cancel</button>
                      <button type="submit" class="btn btn-outline-info btn-sm">Submit</button>
                    </div>
                  </form>
                </div>
              <?php endforeach; ?>
              <?php if (!$rootNotes): ?><div class="text-muted">No notes yet. Start the discussion below.</div><?php endif; ?>
            </div>

            <form method="post" action="scripts/custom_orders/save_note.php" class="custom-note-compose" data-scroll-target="#custom-order-notes-panel">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <label>New note<?= customOrderHelp('note_body') ?></label>
              <textarea name="note_body" rows="3" class="form-control form-control-sm" placeholder="Write a new message..." required></textarea>
              <div class="custom-note-compose-actions"><button type="submit" class="btn btn-outline-light btn-sm">Submit</button></div>
            </form>
          </div>
        </div>

        <?php $followupsDefaultExpanded = !empty($customOrderSectionDefaults['followups']); ?>
        <div id="custom-order-service-panel" data-scroll-block data-custom-collapsible-panel data-section-key="followups" data-order-id="<?= (int) $selectedOrder['id'] ?>" data-default-expanded="<?= $followupsDefaultExpanded ? '1' : '0' ?>" class="custom-orders-panel custom-order-block custom-collapsible-panel<?= $followupsDefaultExpanded ? ' is-expanded' : '' ?>">
          <button type="button" class="custom-collapsible-toggle" data-custom-collapsible-toggle aria-expanded="<?= $followupsDefaultExpanded ? 'true' : 'false' ?>">
            <span class="custom-collapsible-toggle-title"><i class="fas fa-comments" aria-hidden="true"></i>Contact Attempts And Follow-ups<?= customOrderHelp('followups_block') ?></span>
            <span class="custom-collapsible-toggle-meta">
              <span class="badge badge-info"><?= count((array) ($selectedOrder['followups'] ?? [])) ?></span>
              <span>records</span>
              <?php if (!empty($selectedOrder['next_followup_at'])): ?><span>Next: <?= h(date('d.m.Y H:i', strtotime((string) $selectedOrder['next_followup_at']))) ?></span><?php endif; ?>
              <i class="fas fa-chevron-down custom-collapsible-toggle-chevron" aria-hidden="true"></i>
            </span>
          </button>
          <div class="panel-body custom-collapsible-body" data-custom-collapsible-body <?= $followupsDefaultExpanded ? '' : 'hidden' ?>>
            <fieldset class="custom-field-cluster" id="custom-order-followups-block" data-scroll-block>
              <legend class="custom-field-cluster-title">Follow-up Details<?= customOrderHelp('followups_block') ?></legend>
              <?php $deadOrderLocked = !empty($selectedOrder['exported_at']) || !empty($selectedOrder['production_order_id']) || (string) ($selectedOrder['status'] ?? '') === 'EXPORTED'; ?>
              <div class="custom-followup-layout">
                <div class="custom-followup-card">
                  <div class="custom-followup-card-title">Follow-up Status</div>
                  <form method="post" action="scripts/custom_orders/save_order.php" data-scroll-target="#custom-order-followups-block">
                    <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                    <input type="hidden" name="dead_order_flag" value="<?= $deadOrderLocked ? (int) $selectedOrder['dead_order_flag'] : 0 ?>">
                    <div class="custom-followup-status-controls">
                      <div><label>Next follow-up<?= customOrderHelp('next_followup_at') ?></label><input type="datetime-local" name="next_followup_at" class="form-control form-control-sm" value="<?= h($selectedOrder['next_followup_at'] ? date('Y-m-d\TH:i', strtotime((string) $selectedOrder['next_followup_at'])) : '') ?>"></div>
                      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="dead_order_flag" id="dead_order_flag_followups" value="1" <?= (int) $selectedOrder['dead_order_flag'] === 1 ? 'checked' : '' ?> <?= $deadOrderLocked ? 'disabled' : '' ?>><label class="form-check-label" for="dead_order_flag_followups">Dead order<?= customOrderHelp('dead_order_flag') ?></label></div>
                    </div>
                    <?php if ($deadOrderLocked): ?><div class="text-muted small mt-1">Locked after export to production.</div><?php endif; ?>
                    <button type="submit" class="btn btn-outline-light btn-sm mt-2">Save Status</button>
                  </form>
                </div>

                <div class="custom-followup-card">
                  <div class="custom-followup-card-title">Add Follow-up</div>
                  <form method="post" action="scripts/custom_orders/save_followup.php" data-scroll-target="#custom-order-followups-block">
                    <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                    <div class="custom-followup-add-grid">
                      <div><label>Contacted at<?= customOrderHelp('followup_contacted_at') ?></label><input type="datetime-local" name="contacted_at" class="form-control form-control-sm"></div>
                      <div><label>Channel<?= customOrderHelp('followup_channel') ?></label><input type="text" name="channel" class="form-control form-control-sm" placeholder="IG / WhatsApp / Email"></div>
                      <div class="custom-followup-add-note"><label>Note<?= customOrderHelp('followup_note') ?></label><input type="text" name="note" class="form-control form-control-sm" placeholder="Requested address, clarified plastics generation, etc."></div>
                    </div>
                    <button type="submit" class="btn btn-outline-light btn-sm mt-2">Add Follow-up</button>
                  </form>
                </div>

                <div class="custom-followup-card custom-followup-history-card">
                  <div class="custom-followup-card-title">History</div>
                  <div class="custom-followup-history-scroll">
                    <table class="table table-sm table-dark table-striped custom-mini-table custom-followup-history">
                      <thead><tr><th>When<?= customOrderHelp('followup_contacted_at') ?></th><th>Channel<?= customOrderHelp('followup_channel') ?></th><th>Note<?= customOrderHelp('followup_note') ?></th></tr></thead>
                      <tbody>
                        <?php foreach ($selectedOrder['followups'] as $followup): ?>
                          <tr><td><?= h($followup['contacted_at']) ?></td><td><?= h($followup['channel']) ?></td><td><?= h($followup['note']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($selectedOrder['followups'])): ?><tr><td colspan="3" class="text-muted">No follow-ups yet.</td></tr><?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </fieldset>
          </div>
        </div>


        <?php $recentActivity = $selectedOrder['activity'] ?? []; ?>
        <?php $recentActivityVisibleLimit = 5; ?>
        <div class="modal fade custom-order-activity-modal" id="custom-order-activity-modal-<?= (int) $selectedOrder['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
          <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content bg-dark text-light">
              <div class="modal-header">
                <div>
                  <h5 class="modal-title mb-1">Recent Activity<?= customOrderHelp('activity_history') ?></h5>
                  <div class="small text-muted"><?= h($customDisplayNumber) ?> · <?= count($recentActivity) ?> recorded actions</div>
                </div>
                <button type="button" class="close text-white custom-activity-modal-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
              </div>
              <div class="modal-body">
                <div class="table-responsive">
                  <table class="table table-sm table-dark table-striped custom-mini-table mb-0">
                    <thead>
                      <tr><th>When<?= customOrderHelp('activity_when') ?></th><th>Who<?= customOrderHelp('activity_who') ?></th><th>Action<?= customOrderHelp('activity_action') ?></th><th>Detail<?= customOrderHelp('activity_detail') ?></th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recentActivity as $activityIndex => $activity): ?>
                        <tr<?= $activityIndex >= $recentActivityVisibleLimit ? ' class="custom-activity-extra-row" hidden' : '' ?>>
                          <td style="white-space:nowrap;"><?= h($activity['created_at']) ?></td>
                          <td style="white-space:nowrap;"><?= h(trim((string) ($activity['actor_name'] ?? '')) !== '' ? (string) $activity['actor_name'] : ((int) ($activity['actor_employee_id'] ?? 0) > 0 ? ('Employee #' . (int) $activity['actor_employee_id']) : 'System')) ?></td>
                          <td style="white-space:nowrap;"><?= h(customOrdersActivityActionLabel((string) ($activity['action'] ?? ''))) ?></td>
                          <td><?= h(customOrdersActivityDetail($activity)) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!$recentActivity): ?><tr><td colspan="4" class="text-muted">No activity recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <div class="modal-footer">
                <?php if (count($recentActivity) > $recentActivityVisibleLimit): ?>
                  <button type="button" class="btn btn-outline-light btn-sm mr-auto custom-order-activity-toggle" aria-expanded="false">Show more</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm custom-activity-modal-close" data-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <!-- CUSTOM_ORDER_DETAIL_END -->
  </div>
</div>

<script>
  (function () {
    var customOrdersScrollStorageKey = 'custom-orders-scroll:' + window.location.pathname;
    var customOrdersHighlightStorageKey = 'custom-orders-highlight:' + window.location.pathname;
    var customBuilderStatusMap = <?= json_encode($customBuilderStatusMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var customOrdersCanManage = <?= $customOrdersCanManage ? 'true' : 'false' ?>;

    function applyCustomOrdersAccess(root) {
      if (!root || customOrdersCanManage) return;

      root.querySelectorAll('form[action^="scripts/custom_orders/"]').forEach(function (form) {
        var action = String(form.getAttribute('action') || '').toLowerCase();
        if (action.endsWith('/save_note.php') || action.endsWith('/edit_note.php') || action.endsWith('/delete_note.php')) return;

        form.classList.add('custom-orders-readonly-form');
        form.querySelectorAll('input, select, textarea, button').forEach(function (control) {
          var modalTarget = String(control.getAttribute('data-target') || '');
          if (control.matches('[data-toggle="modal"]') && modalTarget.indexOf('#custom-item-modal-') === 0) return;
          control.disabled = true;
        });
      });

      root.querySelectorAll('[form^="custom-twin-header-form-"]').forEach(function (control) {
        control.disabled = true;
      });
    }

    function syncCustomItemStatusColor(select) {
      if (!select) return;
      var option = select.options[select.selectedIndex];
      select.style.setProperty('--item-status-color', option ? (option.getAttribute('data-color') || '#17a2b8') : '#17a2b8');
    }

    function syncCustomBuilderStatusOptions(form, typeCode, subcategory) {
      if (!form) return;
      var select = form.querySelector('select[data-status-dynamic="1"]');
      if (!select) return;
      var key = String(typeCode || '').toUpperCase() + '|' + String(subcategory || '').toUpperCase();
      var definitions = customBuilderStatusMap[key] || customBuilderStatusMap[String(typeCode || '').toUpperCase() + '|'] || {};
      var previous = String(select.value || '').toUpperCase();
      select.innerHTML = '';
      Object.keys(definitions).forEach(function (code) {
        var option = document.createElement('option');
        option.value = code;
        option.textContent = definitions[code].label || code.replace(/_/g, ' ');
        option.setAttribute('data-color', definitions[code].color || '');
        select.appendChild(option);
      });
      if (Object.prototype.hasOwnProperty.call(definitions, previous)) select.value = previous;
      else if (Object.prototype.hasOwnProperty.call(definitions, 'DRAFT_✗')) select.value = 'DRAFT_✗';
      syncCustomItemStatusColor(select);
    }

    function resolveScrollTargetSelector(element) {
      if (!element) return '';
      var explicitTarget = String(element.getAttribute('data-scroll-target') || '').trim();
      if (explicitTarget !== '') {
        return explicitTarget;
      }
      var nearestBlock = element.closest('[data-scroll-block]');
      if (nearestBlock && nearestBlock.id) {
        return '#' + nearestBlock.id;
      }
      return '';
    }

    function ensureHighlightStyles() {
      if (document.getElementById('custom-orders-scroll-highlight-style')) {
        return;
      }
      var style = document.createElement('style');
      style.id = 'custom-orders-scroll-highlight-style';
      style.textContent = ''
        + '.custom-orders-scroll-highlight {'
        + ' box-shadow: 0 0 0 1px rgba(53, 208, 127, 0.55), 0 0 0 10px rgba(53, 208, 127, 0.10);'
        + ' transition: box-shadow 0.45s ease;'
        + '}'
        + '.custom-orders-scroll-highlight-fade {'
        + ' transition: box-shadow 1.4s ease;'
        + ' box-shadow: 0 0 0 1px rgba(53, 208, 127, 0), 0 0 0 0 rgba(53, 208, 127, 0);'
        + '}';
      document.head.appendChild(style);
    }

    function flashCustomOrdersTarget(target) {
      if (!target) return;
      ensureHighlightStyles();
      target.classList.remove('custom-orders-scroll-highlight', 'custom-orders-scroll-highlight-fade');
      void target.offsetWidth;
      target.classList.add('custom-orders-scroll-highlight');
      window.setTimeout(function () {
        target.classList.add('custom-orders-scroll-highlight-fade');
      }, 220);
      window.setTimeout(function () {
        target.classList.remove('custom-orders-scroll-highlight', 'custom-orders-scroll-highlight-fade');
      }, 1900);
    }

    function rememberCustomOrdersScroll(element) {
      try {
        sessionStorage.setItem(customOrdersScrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
        var targetSelector = resolveScrollTargetSelector(element);
        if (targetSelector !== '') {
          sessionStorage.setItem(customOrdersHighlightStorageKey, targetSelector);
        } else {
          sessionStorage.removeItem(customOrdersHighlightStorageKey);
        }
      } catch (err) {
      }
    }

    function restoreCustomOrdersScroll() {
      try {
        var stored = sessionStorage.getItem(customOrdersScrollStorageKey);
        if (stored === null) {
          return;
        }
        sessionStorage.removeItem(customOrdersScrollStorageKey);
        var targetSelector = sessionStorage.getItem(customOrdersHighlightStorageKey);
        if (targetSelector !== null) {
          sessionStorage.removeItem(customOrdersHighlightStorageKey);
        }
        var top = parseInt(stored, 10);
        if (!isNaN(top)) {
          window.requestAnimationFrame(function () {
            window.scrollTo(0, top);
            if (targetSelector) {
              window.setTimeout(function () {
                flashCustomOrdersTarget(document.querySelector(targetSelector));
              }, 140);
            }
          });
        } else if (targetSelector) {
          flashCustomOrdersTarget(document.querySelector(targetSelector));
        }
      } catch (err) {
      }
    }

    restoreCustomOrdersScroll();
    window.addEventListener('DOMContentLoaded', restoreCustomOrdersScroll);
    window.addEventListener('load', restoreCustomOrdersScroll);

    function mapTypeToDepartment(typeCode) {
      var code = String(typeCode || '').toUpperCase();
      if (!code) return '';
      if (code === 'S') return 'S';
      if (code === 'F') return 'F';
      if (code === 'P' || code === 'T' || code === 'M') return 'P';
      return 'G';
    }

    function applyCustomFittingDefaults(form, typeCode) {
      if (!form || String(typeCode || '').toUpperCase() !== 'F') return;
      var itemIdInput = form.querySelector('input[name="custom_item_id"]');
      if (itemIdInput && parseInt(itemIdInput.value || '0', 10) > 0) return;
      var titleInput = form.querySelector('input[name="title"]');
      var priceInput = form.querySelector('input[name="unit_price"]');
      if (titleInput && titleInput.value.trim() === '') {
        titleInput.value = 'Fitting';
      }
      if (priceInput) {
        var currentPrice = parseFloat(String(priceInput.value || '').replace(',', '.'));
        if (!Number.isFinite(currentPrice) || currentPrice <= 0) {
          priceInput.value = '39.90';
        }
      }
    }

    function syncCustomItemSpecGroups(selectEl) {
      if (!selectEl) return;
      var form = selectEl.closest('form');
      if (!form) return;
      var typeCode = String(selectEl.value || '').toUpperCase();
      var department = mapTypeToDepartment(typeCode);
      var subcategoryWrap = form.querySelector('[data-graphics-subcategory-wrap]');
      var subcategorySelect = form.querySelector('.custom-graphics-subcategory-select');
      var subcategory = subcategorySelect ? String(subcategorySelect.value || '').toUpperCase() : '';

      form.querySelectorAll('[data-builder-body]').forEach(function (block) {
        block.hidden = typeCode === '';
      });

      form.querySelectorAll('[data-builder-empty-note]').forEach(function (note) {
        note.hidden = typeCode !== '';
      });

      if (subcategoryWrap) {
        subcategoryWrap.hidden = department !== 'G';
      }
      if (department !== 'G' && subcategorySelect) {
        subcategorySelect.value = '';
        subcategory = '';
      }

      form.querySelectorAll('.custom-item-spec-group').forEach(function (group) {
        var visible = !!department && group.getAttribute('data-department') === department;
        if (visible && department === 'G') {
          visible = String(group.getAttribute('data-subcategory') || '').toUpperCase() === subcategory;
        }
        group.hidden = !visible;
      });

      form.querySelectorAll('[data-builder-row]').forEach(function (row) {
        row.classList.remove('item-type-G', 'item-type-P', 'item-type-S', 'item-type-F', 'item-type-T', 'item-type-M');
        if (typeCode) {
          row.classList.add('item-type-' + typeCode);
        }
      });

      form.querySelectorAll('[data-builder-type-badge]').forEach(function (badge) {
        badge.textContent = typeCode || '?';
      });
      applyCustomFittingDefaults(form, typeCode);
      syncCustomBuilderStatusOptions(form, typeCode, subcategory);
    }

    function syncBuilderPreview(form) {
      if (!form) return;
      var skuInput = form.querySelector('input[name="sku"]');
      var labelInput = form.querySelector('input[name="custom_label"]');
      var skuPreview = form.querySelector('[data-builder-sku-preview]');
      var labelPreview = form.querySelector('[data-builder-label-preview]');

      if (skuPreview) {
        skuPreview.textContent = skuInput && skuInput.value.trim() !== '' ? skuInput.value.trim() : 'MANUAL';
      }

      if (labelPreview) {
        labelPreview.textContent = labelInput && labelInput.value.trim() !== '' ? labelInput.value.trim() : '-';
      }
    }

    document.querySelectorAll('form[action^="scripts/custom_orders/"]').forEach(function (form) {
      form.addEventListener('submit', function () {
        rememberCustomOrdersScroll(form);
      });
    });

    document.querySelectorAll('a.custom-order-remember-position').forEach(function (link) {
      link.addEventListener('click', function () {
        rememberCustomOrdersScroll(link);
      });
    });

    function closeCustomOrderDetail(orderId) {
      var row = document.querySelector('.custom-order-table-row[data-order-id="' + orderId + '"]');
      var wrap = document.getElementById('custom-detail-' + orderId);
      if (wrap) {
        if (window.jQuery) {
          window.jQuery(wrap).stop(true, true).slideUp(120);
        } else {
          wrap.style.display = 'none';
        }
      }
      if (row) {
        row.classList.remove('order-row-open');
        var table = row.closest('table');
        if (table && !table.querySelector('.custom-order-table-row.order-row-open')) {
          table.classList.remove('table-has-open');
        }
      }
    }

    function customCategoryPickerSetOptions(select, values, placeholder, selectedValue, selectedModelCode) {
      if (!select) return false;
      select.innerHTML = '';
      var placeholderOption = document.createElement('option');
      placeholderOption.value = '';
      placeholderOption.textContent = placeholder;
      select.appendChild(placeholderOption);

      var selectedFound = false;
      (values || []).forEach(function (entry) {
        var isStructured = entry && typeof entry === 'object';
        var value = isStructured ? String(entry.value || '') : String(entry);
        var modelcode = isStructured ? String(entry.modelcode || '').trim() : '';
        var option = document.createElement('option');
        option.value = value;
        option.textContent = modelcode ? (value + ' | ' + modelcode) : value;
        option.setAttribute('data-modelcode', modelcode);
        var valueMatches = selectedValue && value === String(selectedValue);
        var codeMatches = !selectedModelCode || modelcode === String(selectedModelCode);
        if (!selectedFound && valueMatches && codeMatches) {
          option.selected = true;
          selectedFound = true;
        }
        select.appendChild(option);
      });
      select.disabled = false;
      if (!selectedFound) select.value = '';
      return selectedFound;
    }

    function customCategoryPickerLoad(level, brand, model) {
      var url = 'scripts/custom_orders/category_info_options.php?level=' + encodeURIComponent(level);
      if (brand) url += '&brand=' + encodeURIComponent(brand);
      if (model) url += '&model=' + encodeURIComponent(model);
      return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) {
          return response.text().then(function (rawBody) {
            var payload;
            try {
              payload = JSON.parse(rawBody);
            } catch (parseError) {
              throw new Error(String(rawBody || 'Invalid server response').trim().substring(0, 300));
            }
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Category options could not be loaded.');
            return payload.values || [];
          });
        });
    }

    function initializeCustomCategoryPicker(root) {
      var modal = root.querySelector('[data-category-picker-modal]');
      if (!modal || modal.dataset.categoryPickerBound === '1') return;
      modal.dataset.categoryPickerBound = '1';

      var brandSelect = modal.querySelector('[data-category-brand]');
      var modelSelect = modal.querySelector('[data-category-model]');
      var yearSelect = modal.querySelector('[data-category-year]');
      var preview = modal.querySelector('[data-category-preview]');
      var errorBox = modal.querySelector('[data-category-error]');
      var applyButton = modal.querySelector('[data-category-apply]');
      var clearButton = modal.querySelector('[data-category-clear]');
      var targetForm = null;
      var requestSerial = 0;

      function selectedModelCode() {
        var option = yearSelect.options[yearSelect.selectedIndex];
        return option ? String(option.getAttribute('data-modelcode') || '').trim() : '';
      }

      function showError(error) {
        errorBox.textContent = error && error.message ? error.message : String(error);
        errorBox.hidden = false;
      }

      function clearError() {
        errorBox.textContent = '';
        errorBox.hidden = true;
      }

      function updatePreview() {
        var values = [brandSelect.value, modelSelect.value, yearSelect.value, selectedModelCode()];
        var complete = values.every(function (value) { return value !== ''; });
        preview.textContent = complete ? values.join(' | ') : 'Select Brand, Model and Year range to load Model Code.';
        applyButton.disabled = !complete;
      }

      function setWaiting(select, text) {
        select.innerHTML = '';
        var option = document.createElement('option');
        option.value = '';
        option.textContent = text;
        select.appendChild(option);
        select.disabled = true;
      }

      function hideModal() {
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery(modal).modal('hide');
          return;
        }
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
      }

      function selectedState(form) {
        function valueOf(name) {
          var input = form.querySelector('input[name="' + name + '"]');
          return input ? input.value.trim() : '';
        }
        var state = {
          brand: valueOf('category_brand'),
          model: valueOf('category_model'),
          year: valueOf('category_year_range'),
          modelcode: valueOf('category_modelcode')
        };
        var legacyParts = valueOf('category_info').split(/\s*\|\s*/).filter(function (value) { return value !== ''; });
        if (legacyParts.length >= 3) {
          if (!state.brand) state.brand = legacyParts[0];
          if (!state.model) state.model = legacyParts[1];
          if (!state.year) state.year = legacyParts[2];
          if (!state.modelcode && legacyParts.length >= 4) state.modelcode = legacyParts[3];
        }
        return state;
      }

      function openPicker(form) {
        targetForm = form;
        clearError();
        applyButton.disabled = true;
        var state = selectedState(form);
        var serial = ++requestSerial;
        setWaiting(brandSelect, 'Loading brands...');
        setWaiting(modelSelect, 'Select brand first');
        setWaiting(yearSelect, 'Select model first');
        updatePreview();

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery(modal).modal('show');
        }

        customCategoryPickerLoad('brands').then(function (brands) {
          if (serial !== requestSerial) return;
          var hasBrand = customCategoryPickerSetOptions(brandSelect, brands, 'Select brand...', state.brand);
          if (!hasBrand) {
            updatePreview();
            return;
          }
          setWaiting(modelSelect, 'Loading models...');
          return customCategoryPickerLoad('models', state.brand).then(function (models) {
            if (serial !== requestSerial) return;
            var hasModel = customCategoryPickerSetOptions(modelSelect, models, 'Select model...', state.model);
            if (!hasModel) {
              updatePreview();
              return;
            }
            setWaiting(yearSelect, 'Loading year ranges...');
            return customCategoryPickerLoad('years', state.brand, state.model).then(function (years) {
              if (serial !== requestSerial) return;
              customCategoryPickerSetOptions(yearSelect, years, 'Select year range...', state.year, state.modelcode);
              updatePreview();
            });
          });
        }).catch(showError);
      }

      root.querySelectorAll('.custom-category-info-trigger').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
          var form = trigger.closest('form.custom-inline-item-edit-form');
          if (form) openPicker(form);
        });
      });

      modal.querySelectorAll('[data-dismiss="modal"]').forEach(function (closeButton) {
        closeButton.addEventListener('click', function (event) {
          event.preventDefault();
          hideModal();
        });
      });

      brandSelect.addEventListener('change', function () {
        clearError();
        var serial = ++requestSerial;
        setWaiting(modelSelect, brandSelect.value ? 'Loading models...' : 'Select brand first');
        setWaiting(yearSelect, 'Select model first');
        updatePreview();
        if (!brandSelect.value) return;
        customCategoryPickerLoad('models', brandSelect.value).then(function (models) {
          if (serial !== requestSerial) return;
          customCategoryPickerSetOptions(modelSelect, models, 'Select model...', '');
          updatePreview();
        }).catch(showError);
      });

      modelSelect.addEventListener('change', function () {
        clearError();
        var serial = ++requestSerial;
        setWaiting(yearSelect, modelSelect.value ? 'Loading year ranges...' : 'Select model first');
        updatePreview();
        if (!brandSelect.value || !modelSelect.value) return;
        customCategoryPickerLoad('years', brandSelect.value, modelSelect.value).then(function (years) {
          if (serial !== requestSerial) return;
          customCategoryPickerSetOptions(yearSelect, years, 'Select year range...', '');
          updatePreview();
        }).catch(showError);
      });

      yearSelect.addEventListener('change', updatePreview);

      applyButton.addEventListener('click', function () {
        if (!targetForm || applyButton.disabled) return;
        var selection = [brandSelect.value, modelSelect.value, yearSelect.value, selectedModelCode()];
        var fieldValues = {
          category_info: selection.join(' | '),
          category_brand: selection[0],
          category_model: selection[1],
          category_year_range: selection[2],
          category_modelcode: selection[3]
        };
        Object.keys(fieldValues).forEach(function (name) {
          var input = targetForm.querySelector('input[name="' + name + '"]');
          if (input) input.value = fieldValues[name];
        });
        var trigger = targetForm.querySelector('.custom-category-info-trigger');
        if (trigger) {
          trigger.classList.remove('is-empty');
          var textNode = trigger.querySelector('.custom-category-info-text');
          if (textNode) textNode.textContent = fieldValues.category_info;
        }
        hideModal();
      });

      clearButton.addEventListener('click', function () {
        if (!targetForm) return;
        ['category_info', 'category_brand', 'category_model', 'category_year_range', 'category_modelcode'].forEach(function (name) {
          var input = targetForm.querySelector('input[name="' + name + '"]');
          if (input) input.value = '';
        });
        var trigger = targetForm.querySelector('.custom-category-info-trigger');
        if (trigger) {
          trigger.classList.add('is-empty');
          var textNode = trigger.querySelector('.custom-category-info-text');
          if (textNode) textNode.textContent = 'Select Brand / Model / Year / Model Code';
        }
        hideModal();
      });
    }

    function initializeCustomOrderPhotos(root) {
      function ensureLightbox() {
        var existing = document.querySelector('.custom-order-photo-lightbox');
        if (existing) return existing;
        var lightbox = document.createElement('div');
        lightbox.className = 'custom-order-photo-lightbox';
        lightbox.innerHTML = '<button type="button" class="btn btn-sm btn-light custom-order-photo-lightbox-close">&times;</button><img src="" alt="Order photo">';
        function closeLightbox() {
          lightbox.classList.remove('is-open');
          lightbox.querySelector('img').removeAttribute('src');
        }
        lightbox.addEventListener('click', function (event) {
          if (event.target.tagName !== 'IMG') closeLightbox();
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && lightbox.classList.contains('is-open')) closeLightbox();
        });
        document.body.appendChild(lightbox);
        return lightbox;
      }

      function reloadPhotoDetail(card) {
        var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
        var detailWrap = card.closest('.custom-order-detail-wrap');
        if (!detailWrap || !orderId) return;
        detailWrap.style.minHeight = detailWrap.offsetHeight + 'px';
        detailWrap.dataset.loaded = '0';
        openCustomOrderDetail(orderId);
      }

      root.querySelectorAll('[data-custom-order-photo-card]').forEach(function (card) {
        if (card.dataset.customPhotoBound === '1') return;
        card.dataset.customPhotoBound = '1';
        var orderId = parseInt(card.getAttribute('data-order-id') || '0', 10);
        var dropzone = card.querySelector('[data-custom-order-photo-dropzone]');
        var input = card.querySelector('[data-custom-order-photo-input]');
        var progress = card.querySelector('[data-custom-order-photo-progress]');
        var progressBar = progress ? progress.querySelector('span') : null;

        function uploadPhotos(fileList) {
          var files = Array.prototype.slice.call(fileList || []).filter(function (file) {
            return file && /^image\//i.test(file.type || '');
          });
          if (!files.length || !orderId) return;
          var formData = new FormData();
          formData.append('custom_order_id', String(orderId));
          files.forEach(function (file) { formData.append('photos[]', file); });
          if (progress) progress.style.display = 'block';
          if (progressBar) progressBar.style.width = '0%';

          var xhr = new XMLHttpRequest();
          xhr.open('POST', 'scripts/custom_orders/upload_photos.php', true);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.upload.addEventListener('progress', function (event) {
            if (progressBar && event.lengthComputable) {
              progressBar.style.width = Math.round((event.loaded / event.total) * 100) + '%';
            }
          });
          xhr.addEventListener('load', function () {
            var payload;
            try {
              payload = JSON.parse(xhr.responseText || '{}');
            } catch (error) {
              payload = { ok: false, error: (xhr.responseText || 'Invalid server response').substring(0, 400) };
            }
            if (xhr.status < 200 || xhr.status >= 300 || !payload.ok) {
              alert(payload.error || 'Photo upload failed.');
              if (progress) progress.style.display = 'none';
              return;
            }
            reloadPhotoDetail(card);
          });
          xhr.addEventListener('error', function () {
            alert('Photo upload failed.');
            if (progress) progress.style.display = 'none';
          });
          xhr.send(formData);
        }

        if (dropzone && input) {
          dropzone.addEventListener('click', function (event) {
            if (event.target !== input) input.click();
          });
          input.addEventListener('change', function () {
            uploadPhotos(input.files);
            input.value = '';
          });
          ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
              event.preventDefault();
              event.stopPropagation();
              dropzone.classList.add('is-dragover');
            });
          });
          ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
              event.preventDefault();
              event.stopPropagation();
              dropzone.classList.remove('is-dragover');
              if (eventName === 'drop') uploadPhotos(event.dataTransfer.files);
            });
          });
        }

        card.querySelectorAll('.custom-order-photo-thumb').forEach(function (thumb) {
          thumb.addEventListener('click', function () {
            var lightbox = ensureLightbox();
            var image = lightbox.querySelector('img');
            image.src = thumb.getAttribute('data-full-src') || thumb.src;
            image.alt = thumb.alt || 'Order photo';
            lightbox.classList.add('is-open');
          });
        });

        card.querySelectorAll('.custom-order-photo-delete').forEach(function (button) {
          button.addEventListener('click', function () {
            if (!confirm('Delete this photo from the custom order?')) return;
            var body = 'custom_order_id=' + encodeURIComponent(orderId) + '&photo_id=' + encodeURIComponent(button.getAttribute('data-photo-id') || '0');
            fetch('scripts/custom_orders/delete_photo.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: body
            }).then(function (response) {
              return response.text().then(function (rawBody) {
                var payload;
                try { payload = JSON.parse(rawBody); }
                catch (error) { throw new Error(rawBody || 'Invalid server response.'); }
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'Photo could not be deleted.');
                return payload;
              });
            }).then(function () {
              var wrap = button.closest('.custom-order-photo-wrap');
              if (wrap) wrap.remove();
              var grid = card.querySelector('[data-custom-order-photo-grid]');
              if (grid && !grid.querySelector('.custom-order-photo-wrap')) {
                grid.innerHTML = '<div class="text-muted small custom-order-photo-empty">No photos yet.</div>';
              }
            }).catch(function (error) {
              alert(error && error.message ? error.message : String(error));
            });
          });
        });
      });
    }

    var CUSTOM_ORDER_COUNTRY_CODES = ['AF','AX','AL','DZ','AS','AD','AO','AI','AQ','AG','AR','AM','AW','AU','AT','AZ','BS','BH','BD','BB','BY','BE','BZ','BJ','BM','BT','BO','BQ','BA','BW','BV','BR','IO','BN','BG','BF','BI','CV','KH','CM','CA','KY','CF','TD','CL','CN','CX','CC','CO','KM','CG','CD','CK','CR','CI','HR','CU','CW','CY','CZ','DK','DJ','DM','DO','EC','EG','SV','GQ','ER','EE','SZ','ET','FK','FO','FJ','FI','FR','GF','PF','TF','GA','GM','GE','DE','GH','GI','GR','GL','GD','GP','GU','GT','GG','GN','GW','GY','HT','HM','VA','HN','HK','HU','IS','IN','ID','IR','IQ','IE','IM','IL','IT','JM','JP','JE','JO','KZ','KE','KI','KP','KR','KW','KG','LA','LV','LB','LS','LR','LY','LI','LT','LU','MO','MG','MW','MY','MV','ML','MT','MH','MQ','MR','MU','YT','MX','FM','MD','MC','MN','ME','MS','MA','MZ','MM','NA','NR','NP','NL','NC','NZ','NI','NE','NG','NU','NF','MK','MP','NO','OM','PK','PW','PS','PA','PG','PY','PE','PH','PN','PL','PT','PR','QA','RE','RO','RU','RW','BL','SH','KN','LC','MF','PM','VC','WS','SM','ST','SA','SN','RS','SC','SL','SG','SX','SK','SI','SB','SO','ZA','GS','SS','ES','LK','SD','SR','SJ','SE','CH','SY','TW','TJ','TZ','TH','TL','TG','TK','TO','TT','TN','TR','TM','TC','TV','UG','UA','AE','GB','US','UM','UY','UZ','VU','VE','VN','VG','VI','WF','EH','YE','ZM','ZW','XK'];
    var CUSTOM_ORDER_STATE_OPTIONS = {
      US: [['AL','Alabama'],['AK','Alaska'],['AZ','Arizona'],['AR','Arkansas'],['CA','California'],['CO','Colorado'],['CT','Connecticut'],['DE','Delaware'],['DC','District of Columbia'],['FL','Florida'],['GA','Georgia'],['HI','Hawaii'],['ID','Idaho'],['IL','Illinois'],['IN','Indiana'],['IA','Iowa'],['KS','Kansas'],['KY','Kentucky'],['LA','Louisiana'],['ME','Maine'],['MD','Maryland'],['MA','Massachusetts'],['MI','Michigan'],['MN','Minnesota'],['MS','Mississippi'],['MO','Missouri'],['MT','Montana'],['NE','Nebraska'],['NV','Nevada'],['NH','New Hampshire'],['NJ','New Jersey'],['NM','New Mexico'],['NY','New York'],['NC','North Carolina'],['ND','North Dakota'],['OH','Ohio'],['OK','Oklahoma'],['OR','Oregon'],['PA','Pennsylvania'],['RI','Rhode Island'],['SC','South Carolina'],['SD','South Dakota'],['TN','Tennessee'],['TX','Texas'],['UT','Utah'],['VT','Vermont'],['VA','Virginia'],['WA','Washington'],['WV','West Virginia'],['WI','Wisconsin'],['WY','Wyoming']],
      CA: [['AB','Alberta'],['BC','British Columbia'],['MB','Manitoba'],['NB','New Brunswick'],['NL','Newfoundland and Labrador'],['NS','Nova Scotia'],['NT','Northwest Territories'],['NU','Nunavut'],['ON','Ontario'],['PE','Prince Edward Island'],['QC','Quebec'],['SK','Saskatchewan'],['YT','Yukon']],
      AU: [['ACT','Australian Capital Territory'],['NSW','New South Wales'],['NT','Northern Territory'],['QLD','Queensland'],['SA','South Australia'],['TAS','Tasmania'],['VIC','Victoria'],['WA','Western Australia']]
    };

    function customOrderCountryFlagUrl(code) {
      code = String(code || '').toLowerCase();
      if (!/^[a-z]{2}$/.test(code)) return '';
      return 'plugins/flag-icon-css/flags/4x3/' + code + '.svg';
    }

    function updateCustomCountryFlag(countrySelect) {
      if (!countrySelect) return;
      var wrap = countrySelect.closest('.custom-country-select-wrap');
      if (!wrap) return;
      var flag = wrap.querySelector('[data-country-flag]');
      if (!flag) return;
      var countryCode = normalizeCustomOrderCountryValue(countrySelect.value);
      var flagUrl = customOrderCountryFlagUrl(countryCode);
      flag.classList.toggle('is-empty', !flagUrl);
      wrap.classList.toggle('no-flag', !flagUrl);
      flag.style.backgroundImage = flagUrl ? 'url("' + flagUrl + '")' : '';
      flag.title = countryCode || '';
    }

    function customOrderCountryName(code) {
      code = String(code || '').toUpperCase();
      if (code === 'XK') return 'Kosovo';
      if (typeof Intl !== 'undefined' && Intl.DisplayNames) {
        try {
          return new Intl.DisplayNames(['en'], { type: 'region' }).of(code) || code;
        } catch (error) {}
      }
      return code;
    }

    function normalizeCustomOrderCountryValue(value) {
      var raw = String(value || '').trim();
      var upper = raw.toUpperCase();
      var aliases = {
        UK: 'GB', EN: 'GB', USA: 'US',
        'UNITED STATES': 'US', 'UNITED STATES OF AMERICA': 'US',
        'UNITED KINGDOM': 'GB', 'GREAT BRITAIN': 'GB',
        GERMANY: 'DE', SLOVAKIA: 'SK', 'SLOVAK REPUBLIC': 'SK',
        CZECHIA: 'CZ', 'CZECH REPUBLIC': 'CZ', MEXICO: 'MX',
        AUSTRIA: 'AT', POLAND: 'PL', CANADA: 'CA', AUSTRALIA: 'AU',
        FRANCE: 'FR', ITALY: 'IT', SWITZERLAND: 'CH',
        NETHERLANDS: 'NL', 'THE NETHERLANDS': 'NL', BELGIUM: 'BE', SPAIN: 'ES'
      };
      if (aliases[upper]) return aliases[upper];
      if (/^[A-Z]{2}$/.test(upper)) return upper;
      for (var i = 0; i < CUSTOM_ORDER_COUNTRY_CODES.length; i++) {
        var code = CUSTOM_ORDER_COUNTRY_CODES[i];
        if (customOrderCountryName(code).toUpperCase() === upper) return code;
      }
      return upper;
    }

    function customOrderCountryLabel(code) {
      code = String(code || '').toUpperCase();
      return code ? code + ' - ' + customOrderCountryName(code) : '';
    }

    function populateCustomCountrySelect(select) {
      if (!select || select.dataset.countryPopulated === '1') return;
      var current = normalizeCustomOrderCountryValue(select.value);
      var countries = CUSTOM_ORDER_COUNTRY_CODES.map(function (code) {
        return { code: code, name: customOrderCountryName(code) };
      }).sort(function (a, b) {
        return a.name.localeCompare(b.name, 'en');
      });
      select.innerHTML = '';
      var emptyOption = document.createElement('option');
      emptyOption.value = '';
      emptyOption.textContent = select.getAttribute('data-country-placeholder') || 'Country';
      select.appendChild(emptyOption);
      countries.forEach(function (country) {
        var option = document.createElement('option');
        option.value = country.code;
        option.textContent = customOrderCountryLabel(country.code);
        select.appendChild(option);
      });
      if (current && CUSTOM_ORDER_COUNTRY_CODES.indexOf(current) === -1) {
        var legacyOption = document.createElement('option');
        legacyOption.value = current;
        legacyOption.textContent = current;
        select.appendChild(legacyOption);
      }
      select.value = current;
      select.dataset.countryPopulated = '1';
    }

    function updateCustomCountryState(countrySelect) {
      if (!countrySelect) return;
      var countryCode = normalizeCustomOrderCountryValue(countrySelect.value);
      if (countrySelect.value !== countryCode && CUSTOM_ORDER_COUNTRY_CODES.indexOf(countryCode) !== -1) countrySelect.value = countryCode;
      updateCustomCountryFlag(countrySelect);
      var row = countrySelect.closest('.custom-country-state-row');
      if (!row) return;
      var stateWrap = row.querySelector('[data-state-wrap]');
      var stateSelect = row.querySelector('[data-state-select]');
      if (!stateWrap || !stateSelect) return;
      var states = CUSTOM_ORDER_STATE_OPTIONS[countryCode] || [];
      var currentState = String(stateSelect.value || stateSelect.getAttribute('data-current-state') || '').toUpperCase();
      stateSelect.innerHTML = '';
      row.classList.toggle('has-state', states.length > 0);
      if (!states.length) {
          var emptyStateOption = document.createElement('option');
          emptyStateOption.value = '';
          emptyStateOption.textContent = 'State';
          stateSelect.appendChild(emptyStateOption);
        stateWrap.hidden = true;
        stateSelect.value = '';
        stateSelect.disabled = countrySelect.disabled;
        stateSelect.setAttribute('data-current-state', '');
        return;
      }
      stateWrap.hidden = false;
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'State / Province';
      stateSelect.appendChild(placeholder);
      var hasCurrent = false;
      states.forEach(function (state) {
        var option = document.createElement('option');
        option.value = state[0];
        option.textContent = state[0] + ' - ' + state[1];
        if (state[0] === currentState) hasCurrent = true;
        stateSelect.appendChild(option);
      });
      stateSelect.value = hasCurrent ? currentState : '';
      stateSelect.disabled = countrySelect.disabled;
      stateSelect.setAttribute('data-current-state', stateSelect.value);
    }

    function initializeCustomCountryState(root) {
      root.querySelectorAll('[data-country-select]').forEach(function (countrySelect) {
        if (countrySelect.dataset.countryStateBound !== '1') {
          countrySelect.dataset.countryStateBound = '1';
          countrySelect.addEventListener('change', function () {
            updateCustomCountryState(countrySelect);
          });
        }
        populateCustomCountrySelect(countrySelect);
        updateCustomCountryState(countrySelect);
      });
    }
    function initializeCustomBillingSame(root) {
      root.querySelectorAll('form[id^="custom-twin-header-form-"]').forEach(function (form) {
        if (form.dataset.billingSameBound === '1') return;
        form.dataset.billingSameBound = '1';
        var toggle = form.querySelector('[data-billing-same-toggle]');
        var billingWrap = form.querySelector('[data-billing-fields]');
        if (!toggle || !billingWrap) return;
        var billingFields = Array.prototype.slice.call(form.querySelectorAll('[data-billing-field]'));
        var shippingFields = {};
        Array.prototype.slice.call(form.querySelectorAll('[data-shipping-field]')).forEach(function (field) {
          shippingFields[field.getAttribute('data-shipping-field')] = field;
        });

        function copyShippingToBilling() {
          billingFields.forEach(function (field) {
            var source = shippingFields[field.getAttribute('data-billing-field')];
            if (source) {
              field.value = source.value;
              if (field.hasAttribute('data-country-select')) updateCustomCountryState(field);
            }
          });
        }

        function clearBilling() {
          billingFields.forEach(function (field) { field.value = ''; });
        }

        function applyBillingMode(clearWhenUnlocked) {
          var synced = toggle.checked;
          billingWrap.classList.toggle('is-synced', synced);
          if (synced) copyShippingToBilling();
          else if (clearWhenUnlocked) clearBilling();
          billingFields.forEach(function (field) {
            field.disabled = synced;
            if (field.hasAttribute('data-country-select')) updateCustomCountryState(field);
          });
        }

        toggle.addEventListener('change', function () {
          applyBillingMode(!toggle.checked);
        });
        Object.keys(shippingFields).forEach(function (key) {
          var field = shippingFields[key];
          field.addEventListener('input', function () { if (toggle.checked) copyShippingToBilling(); });
          field.addEventListener('change', function () { if (toggle.checked) copyShippingToBilling(); });
        });
        applyBillingMode(false);
      });
    }

    function initializeCustomContactSuggestions(root) {
      var searchableFields = [
        'social_handle',
        'billing_name', 'billing_company', 'billing_company_id', 'billing_street',
        'billing_city', 'billing_zip', 'billing_country', 'billing_email', 'billing_phone',
        'shipping_name', 'shipping_company', 'shipping_company_id', 'shipping_street',
        'shipping_city', 'shipping_zip', 'shipping_country', 'shipping_email', 'shipping_phone'
      ];

      root.querySelectorAll('form[id^="custom-twin-header-form-"]').forEach(function (form) {
        if (form.dataset.contactSuggestionsBound === '1') return;
        form.dataset.contactSuggestionsBound = '1';

        var menu = document.createElement('div');
        menu.className = 'custom-contact-suggestion-menu';
        menu.setAttribute('role', 'listbox');
        document.body.appendChild(menu);
        var activeInput = null;
        var debounceTimer = null;
        var requestController = null;
        var orderIdField = form.elements.namedItem('custom_order_id');
        var currentOrderId = orderIdField ? String(orderIdField.value || '0') : '0';

        function closeMenu() {
          menu.classList.remove('is-open');
          menu.innerHTML = '';
        }

        function positionMenu(input) {
          var rect = input.getBoundingClientRect();
          menu.style.left = (window.scrollX + rect.left) + 'px';
          menu.style.top = (window.scrollY + rect.bottom + 3) + 'px';
          menu.style.width = Math.max(rect.width, 310) + 'px';
        }

        function setFormValue(fieldName, value) {
          var field = form.elements.namedItem(fieldName);
          if (!field) return;
          value = value === null || value === undefined ? '' : String(value);
          if (field.hasAttribute('data-country-select')) {
            value = normalizeCustomOrderCountryValue(value);
            populateCustomCountrySelect(field);
          }
          if (field.tagName === 'SELECT' && value !== '' && !Array.prototype.some.call(field.options, function (option) { return option.value === value; })) {
            var legacyOption = document.createElement('option');
            legacyOption.value = value;
            legacyOption.textContent = value;
            field.appendChild(legacyOption);
          }
          field.value = value;
          field.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function applyProfile(profile) {
          Object.keys(profile || {}).forEach(function (fieldName) {
            setFormValue(fieldName, profile[fieldName]);
          });
          closeMenu();
        }

        function renderResults(input, results) {
          menu.innerHTML = '';
          if (!results.length || document.activeElement !== input) {
            closeMenu();
            return;
          }
          results.forEach(function (result) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'custom-contact-suggestion-item';
            button.setAttribute('role', 'option');
            var label = document.createElement('span');
            label.className = 'custom-contact-suggestion-label';
            label.textContent = result.label || 'Saved contact';
            button.appendChild(label);
            if (result.detail) {
              var detail = document.createElement('span');
              detail.className = 'custom-contact-suggestion-detail';
              detail.textContent = result.detail;
              button.appendChild(detail);
            }
            button.addEventListener('mousedown', function (event) {
              event.preventDefault();
              applyProfile(result.profile || {});
            });
            menu.appendChild(button);
          });
          positionMenu(input);
          menu.classList.add('is-open');
        }

        searchableFields.forEach(function (fieldName) {
          var input = form.elements.namedItem(fieldName);
          if (!input || input.tagName !== 'INPUT') return;
          input.setAttribute('autocomplete', 'off');
          input.addEventListener('input', function () {
            activeInput = input;
            window.clearTimeout(debounceTimer);
            var query = String(input.value || '').trim();
            if (query.length < 2) {
              if (requestController) requestController.abort();
              closeMenu();
              return;
            }
            debounceTimer = window.setTimeout(function () {
              if (requestController) requestController.abort();
              requestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
              fetch('scripts/custom_orders/contact_suggestions.php?q=' + encodeURIComponent(query) + '&exclude_id=' + encodeURIComponent(currentOrderId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: requestController ? requestController.signal : undefined
              }).then(function (response) {
                return response.text().then(function (rawBody) {
                  var payload;
                  try { payload = JSON.parse(rawBody); }
                  catch (error) { throw new Error(rawBody || 'Invalid suggestion response.'); }
                  if (!response.ok || !payload.ok) throw new Error(payload.error || 'Suggestions could not be loaded.');
                  return payload.results || [];
                });
              }).then(function (results) {
                if (activeInput === input) renderResults(input, results);
              }).catch(function (error) {
                if (error && error.name === 'AbortError') return;
                closeMenu();
              });
            }, 220);
          });
          input.addEventListener('focus', function () { activeInput = input; });
          input.addEventListener('blur', function () {
            window.setTimeout(function () {
              if (!menu.matches(':hover')) closeMenu();
            }, 120);
          });
        });

        window.addEventListener('resize', function () {
          if (activeInput && menu.classList.contains('is-open')) positionMenu(activeInput);
        });
        document.addEventListener('mousedown', function (event) {
          if (!menu.contains(event.target) && !form.contains(event.target)) closeMenu();
        });
      });
    }

    function alignCustomHelpIcons(root) {
      if (!root) return;
      root.querySelectorAll('.custom-help-icon').forEach(function (icon) {
        icon.classList.remove('help-align-left', 'help-align-right', 'help-align-bottom');
        var iconRect = icon.getBoundingClientRect();
        if (!iconRect.width) return;
        var clippingParent = icon.closest('.custom-twin-edit-card, .custom-builder-order-shell, .table-responsive, .modal-content, .custom-twin-order-card, .custom-orders-panel');
        var bounds = clippingParent
          ? clippingParent.getBoundingClientRect()
          : { left: 0, right: window.innerWidth, top: 0, bottom: window.innerHeight };
        var leftSpace = iconRect.left - Math.max(0, bounds.left);
        var rightSpace = Math.min(window.innerWidth, bounds.right) - iconRect.right;
        var topSpace = iconRect.top - Math.max(0, bounds.top);
        if (leftSpace < 170) {
          icon.classList.add('help-align-left');
        } else if (rightSpace < 170) {
          icon.classList.add('help-align-right');
        }
        if (topSpace < 90) {
          icon.classList.add('help-align-bottom');
        }
      });
    }

    function initializeCustomCollapsiblePanels(root) {
      if (!root) return;

      root.querySelectorAll('[data-custom-collapsible-panel]').forEach(function (panel) {
        var toggle = panel.querySelector('[data-custom-collapsible-toggle]');
        var body = panel.querySelector('[data-custom-collapsible-body]');
        if (!toggle || !body) return;

        var orderId = panel.getAttribute('data-order-id') || '0';
        var sectionKey = panel.getAttribute('data-section-key') || 'section';
        var storageKey = 'custom-order-section-expanded:' + sectionKey + ':' + orderId;

        function setExpanded(expanded, remember) {
          toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
          panel.classList.toggle('is-expanded', expanded);
          if (remember && window.jQuery) {
            var $body = window.jQuery(body);
            $body.stop(true, true);
            if (expanded) {
              body.hidden = false;
              $body.hide().slideDown(140);
            } else {
              $body.slideUp(140, function () {
                body.hidden = true;
                body.style.display = '';
              });
            }
          } else {
            body.hidden = !expanded;
            body.style.display = '';
          }
          if (remember) {
            try {
              window.sessionStorage.setItem(storageKey, expanded ? '1' : '0');
            } catch (storageError) {
            }
          }
          if (expanded) {
            window.requestAnimationFrame(function () { alignCustomHelpIcons(panel); });
          }
        }

        var initiallyExpanded = panel.getAttribute('data-default-expanded') === '1';
        try {
          var storedState = window.sessionStorage.getItem(storageKey);
          if (storedState === '1' || storedState === '0') {
            initiallyExpanded = storedState === '1';
          }
        } catch (storageError) {
        }
        setExpanded(initiallyExpanded, false);

        if (toggle.dataset.collapsibleBound === '1') return;
        toggle.dataset.collapsibleBound = '1';
        toggle.addEventListener('click', function () {
          setExpanded(toggle.getAttribute('aria-expanded') !== 'true', true);
        });
      });
    }

    function initializeCustomNoteReplies(root) {
      if (!root) return;

      root.querySelectorAll('[data-note-reply-toggle]').forEach(function (button) {
        if (button.dataset.noteReplyBound === '1') return;
        button.dataset.noteReplyBound = '1';
        button.addEventListener('click', function () {
          var selector = button.getAttribute('data-reply-form') || '';
          var notesPanel = button.closest('#custom-order-notes-panel') || root;
          var form = selector ? notesPanel.querySelector(selector) : null;
          if (!form) return;
          var shouldOpen = form.hidden;
          notesPanel.querySelectorAll('.custom-note-reply-form').forEach(function (otherForm) {
            otherForm.hidden = true;
          });
          form.hidden = !shouldOpen;
          if (shouldOpen) {
            var textarea = form.querySelector('textarea[name="note_body"]');
            if (textarea) textarea.focus();
          }
        });
      });

      root.querySelectorAll('[data-note-reply-cancel]').forEach(function (button) {
        if (button.dataset.noteReplyCancelBound === '1') return;
        button.dataset.noteReplyCancelBound = '1';
        button.addEventListener('click', function () {
          var form = button.closest('.custom-note-reply-form');
          if (!form) return;
          form.hidden = true;
          var textarea = form.querySelector('textarea[name="note_body"]');
          if (textarea) textarea.value = '';
        });
      });

      root.querySelectorAll('[data-note-edit-toggle]').forEach(function (button) {
        if (button.dataset.noteEditBound === '1') return;
        button.dataset.noteEditBound = '1';
        button.addEventListener('click', function () {
          var selector = button.getAttribute('data-edit-form') || '';
          var notesPanel = button.closest('#custom-order-notes-panel') || root;
          var form = selector ? notesPanel.querySelector(selector) : null;
          if (!form) return;
          var shouldOpen = form.hidden;
          notesPanel.querySelectorAll('.custom-note-edit-form').forEach(function (otherForm) {
            otherForm.hidden = true;
          });
          form.hidden = !shouldOpen;
          if (shouldOpen) {
            var textarea = form.querySelector('textarea[name="note_body"]');
            if (textarea) textarea.focus();
          }
        });
      });

      root.querySelectorAll('[data-note-edit-cancel]').forEach(function (button) {
        if (button.dataset.noteEditCancelBound === '1') return;
        button.dataset.noteEditCancelBound = '1';
        button.addEventListener('click', function () {
          var form = button.closest('.custom-note-edit-form');
          if (form) form.hidden = true;
        });
      });
    }

    function focusCustomOrderNote(root) {
      if (!root) return;
      var notesPanel = root.querySelector('#custom-order-notes-panel');
      if (!notesPanel) return;
      var noteId = parseInt(notesPanel.getAttribute('data-focus-note-id') || '0', 10);
      if (!noteId) return;
      var target = root.querySelector('#custom-note-' + noteId);
      if (!target) return;

      var body = notesPanel.querySelector('[data-custom-collapsible-body]');
      var toggle = notesPanel.querySelector('[data-custom-collapsible-toggle]');
      var wasCollapsed = !!(body && body.hidden);
      if (wasCollapsed && toggle) {
        toggle.click();
      }

      window.setTimeout(function () {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        flashCustomOrdersTarget(target);
      }, wasCollapsed ? 220 : 80);

      notesPanel.setAttribute('data-focus-note-id', '0');
      try {
        var cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('focus_note_id');
        window.history.replaceState({}, document.title, cleanUrl.toString());
      } catch (urlError) {
      }
    }

    function initializeInjectedDetail(root) {
      if (!root) return;

      applyCustomOrdersAccess(root);
      initializeCustomCountryState(root);
      initializeCustomBillingSame(root);
      initializeCustomCategoryPicker(root);
      initializeCustomOrderPhotos(root);
      initializeCustomContactSuggestions(root);
      initializeCustomCollapsiblePanels(root);
      initializeCustomNoteReplies(root);
      focusCustomOrderNote(root);
      alignCustomHelpIcons(root);
      root.querySelectorAll('.table-responsive').forEach(function (tableWrap) {
        if (tableWrap.dataset.helpAlignmentBound === '1') return;
        tableWrap.dataset.helpAlignmentBound = '1';
        tableWrap.addEventListener('scroll', function () { alignCustomHelpIcons(tableWrap); }, { passive: true });
      });

      var accountingPanel = root.querySelector('#custom-order-accounting-panel');
      var paymentsPanel = root.querySelector('#custom-order-payments-block');
      var productBuilderPanel = root.querySelector('#custom-order-builder-panel');
      if (accountingPanel && paymentsPanel && accountingPanel.nextElementSibling !== paymentsPanel) {
        accountingPanel.insertAdjacentElement('afterend', paymentsPanel);
      }
      var productAnchorPanel = paymentsPanel || accountingPanel;
      if (productAnchorPanel && productBuilderPanel && productAnchorPanel.nextElementSibling !== productBuilderPanel) {
        productAnchorPanel.insertAdjacentElement('afterend', productBuilderPanel);
      }

      root.querySelectorAll('form[action^="scripts/custom_orders/"]').forEach(function (form) {
        if (form.classList.contains('custom-inline-item-edit-form')) return;
        if (form.dataset.customOrdersBound === '1') return;
        form.dataset.customOrdersBound = '1';
        form.addEventListener('submit', function () {
          rememberCustomOrdersScroll(form);
        });
      });

      root.querySelectorAll('form.custom-inline-item-edit-form').forEach(function (form) {
        if (form.dataset.inlineSaveBound === '1') return;
        form.dataset.inlineSaveBound = '1';
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          var saveButton = form.querySelector('button[type="submit"]');
          var originalText = saveButton ? saveButton.textContent : 'Save';
          if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Saving…';
          }

          fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
          })
            .then(function (response) {
              return response.text().then(function (rawBody) {
                var payload;
                try {
                  payload = JSON.parse(rawBody);
                } catch (parseError) {
                  throw new Error(String(rawBody || 'Invalid server response').trim().substring(0, 400));
                }
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Item could not be saved.');
                return payload;
              });
            })
            .then(function (payload) {
              if (form.classList.contains('custom-add-item-form')) {
                var orderIdInput = form.querySelector('input[name="custom_order_id"]');
                var orderId = orderIdInput ? parseInt(orderIdInput.value || '0', 10) : 0;
                var detailWrap = form.closest('.custom-order-detail-wrap');
                if (detailWrap && orderId > 0) {
                  detailWrap.style.minHeight = detailWrap.offsetHeight + 'px';
                  detailWrap.dataset.loaded = '0';
                  openCustomOrderDetail(orderId);
                }
                return;
              }
              if (!saveButton) return;
              saveButton.classList.remove('btn-outline-success', 'btn-outline-danger');
              saveButton.classList.add('btn-success');
              saveButton.textContent = 'Saved';
              window.setTimeout(function () {
                saveButton.classList.remove('btn-success');
                saveButton.classList.add('btn-outline-success');
                saveButton.textContent = originalText;
                saveButton.disabled = false;
              }, 1200);
            })
            .catch(function (error) {
              if (!saveButton) return;
              saveButton.classList.remove('btn-outline-success', 'btn-success');
              saveButton.classList.add('btn-outline-danger');
              saveButton.textContent = 'Error';
              saveButton.title = error && error.message ? error.message : String(error);
              saveButton.disabled = false;
            });
        });
      });

      root.querySelectorAll('form[action="scripts/custom_orders/save_item.php"]').forEach(function (form) {
        if (form.dataset.customBuilderBound === '1') return;
        form.dataset.customBuilderBound = '1';
        var typeSelect = form.querySelector('.custom-item-type-select');
        var subcategorySelect = form.querySelector('.custom-graphics-subcategory-select');
        if (typeSelect) {
          syncCustomItemSpecGroups(typeSelect);
          typeSelect.addEventListener('change', function () { syncCustomItemSpecGroups(typeSelect); });
        }
        if (subcategorySelect && typeSelect) {
          subcategorySelect.addEventListener('change', function () { syncCustomItemSpecGroups(typeSelect); });
        }
        ['sku', 'custom_label'].forEach(function (fieldName) {
          var input = form.querySelector('input[name="' + fieldName + '"]');
          if (input) input.addEventListener('input', function () { syncBuilderPreview(form); });
        });
        syncBuilderPreview(form);
      });

      root.querySelectorAll('.custom-item-status-select').forEach(function (select) {
        syncCustomItemStatusColor(select);
        if (select.dataset.statusColorBound === '1') return;
        select.dataset.statusColorBound = '1';
        select.addEventListener('change', function () { syncCustomItemStatusColor(select); });
      });

      root.querySelectorAll('.btn-copy-inline').forEach(function (btn) {
        if (btn.dataset.copyBound === '1') return;
        btn.dataset.copyBound = '1';
        btn.addEventListener('click', function () {
          var value = btn.getAttribute('data-copy') || '';
          if (value && navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value);
        });
      });

      root.querySelectorAll('.custom-order-activity-toggle').forEach(function (activityToggle) {
        if (activityToggle.dataset.activityToggleBound === '1') return;
        activityToggle.dataset.activityToggleBound = '1';
        activityToggle.addEventListener('click', function () {
          var expanded = activityToggle.getAttribute('aria-expanded') === 'true';
          var activityModal = activityToggle.closest('.custom-order-activity-modal') || root;
          activityModal.querySelectorAll('.custom-activity-extra-row').forEach(function (activityRow) {
            activityRow.hidden = expanded;
          });
          activityToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          activityToggle.textContent = expanded ? 'Show more' : 'Show less';
        });
      });

      root.querySelectorAll('.custom-activity-modal-close').forEach(function (closeButton) {
        if (closeButton.dataset.activityCloseBound === '1') return;
        closeButton.dataset.activityCloseBound = '1';
        closeButton.addEventListener('click', function (event) {
          event.preventDefault();
          var activityModal = closeButton.closest('.custom-order-activity-modal');
          if (activityModal && window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            window.jQuery(activityModal).modal('hide');
          }
        });
      });
    }

    function openCustomOrderDetail(orderId, editItemId, focusNoteId) {
      var row = document.querySelector('.custom-order-table-row[data-order-id="' + orderId + '"]');
      var wrap = document.getElementById('custom-detail-' + orderId);
      if (!row || !wrap) return;

      document.querySelectorAll('.custom-order-table-row.order-row-open').forEach(function (openRow) {
        var openId = parseInt(openRow.getAttribute('data-order-id') || '0', 10);
        if (openId && openId !== orderId) closeCustomOrderDetail(openId);
      });

      if (wrap.dataset.loaded === '1') {
        row.classList.add('order-row-open');
        var loadedTable = row.closest('table');
        if (loadedTable) loadedTable.classList.add('table-has-open');
        if (window.jQuery) window.jQuery(wrap).stop(true, true).slideDown(120);
        else wrap.style.display = 'block';
        if (focusNoteId) {
          var loadedNotesPanel = wrap.querySelector('#custom-order-notes-panel');
          if (loadedNotesPanel) loadedNotesPanel.setAttribute('data-focus-note-id', String(focusNoteId));
          focusCustomOrderNote(wrap);
        }
        return;
      }

      row.classList.add('order-row-open');
      var table = row.closest('table');
      if (table) table.classList.add('table-has-open');
      wrap.style.display = 'block';
      wrap.innerHTML = '<div class="p-3 text-muted"><span class="spinner-border spinner-border-sm mr-2"></span>Loading custom order detail…</div>';

      fetch('scripts/custom_orders/get_order_detail.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: 'custom_order_id=' + encodeURIComponent(orderId) +
          (editItemId ? '&edit_item_id=' + encodeURIComponent(editItemId) : '') +
          (focusNoteId ? '&focus_note_id=' + encodeURIComponent(focusNoteId) : '')
      })
        .then(function (response) {
          return response.text().then(function (rawBody) {
            var payload = null;
            try {
              payload = JSON.parse(rawBody);
            } catch (parseError) {
              var serverMessage = String(rawBody || '').trim().substring(0, 500);
              throw new Error(
                'Endpoint scripts/custom_orders/get_order_detail.php returned HTTP ' + response.status +
                ' instead of JSON' + (serverMessage ? ': ' + serverMessage : '.')
              );
            }
            if (!response.ok || !payload || !payload.ok) {
              throw new Error(payload && payload.error ? payload.error : 'Detail request failed (HTTP ' + response.status + ').');
            }
            return payload;
          });
        })
        .then(function (payload) {
          wrap.innerHTML = payload.html;
          wrap.dataset.loaded = '1';
          initializeInjectedDetail(wrap);
          wrap.style.minHeight = '';
        })
        .catch(function (error) {
          wrap.style.minHeight = '';
          wrap.innerHTML = '<div class="alert alert-danger mb-0">Custom order detail could not be loaded: ' +
            String(error && error.message ? error.message : error).replace(/[&<>"']/g, function (char) {
              return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
            }) + '</div>';
        });
    }

    document.querySelectorAll('.custom-order-table-row[data-order-id]').forEach(function (row) {
      row.addEventListener('click', function (event) {
        if (event.target && event.target.closest('a, button, input, select, textarea, label')) return;
        var orderId = parseInt(row.getAttribute('data-order-id') || '0', 10);
        var wrap = document.getElementById('custom-detail-' + orderId);
        if (row.classList.contains('order-row-open') && wrap && wrap.style.display !== 'none') closeCustomOrderDetail(orderId);
        else openCustomOrderDetail(orderId);
      });
    });

    document.addEventListener('click', function (event) {
      var closeButton = event.target.closest('.btn-close-custom-order-detail');
      if (!closeButton) return;
      event.preventDefault();
      event.stopPropagation();
      closeCustomOrderDetail(parseInt(closeButton.getAttribute('data-order-id') || '0', 10));
    });

    var autoOpenOrderId = <?= (int) $customOrdersAutoOpenId ?>;
    var autoEditItemId = <?= (int) $customOrdersAutoEditItemId ?>;
    var autoFocusNoteId = <?= (int) $customOrdersAutoFocusNoteId ?>;
    if (autoOpenOrderId > 0) {
      window.setTimeout(function () { openCustomOrderDetail(autoOpenOrderId, autoEditItemId, autoFocusNoteId); }, 0);
    }

    document.querySelectorAll('form[action="scripts/custom_orders/save_item.php"]').forEach(function (form) {
      var typeSelect = form.querySelector('.custom-item-type-select');
      var subcategorySelect = form.querySelector('.custom-graphics-subcategory-select');
      if (typeSelect) {
        syncCustomItemSpecGroups(typeSelect);
        typeSelect.addEventListener('change', function () {
          syncCustomItemSpecGroups(typeSelect);
        });
      }
      if (subcategorySelect && typeSelect) {
        subcategorySelect.addEventListener('change', function () {
          syncCustomItemSpecGroups(typeSelect);
        });
      }

      ['sku', 'custom_label'].forEach(function (fieldName) {
        var input = form.querySelector('input[name="' + fieldName + '"]');
        if (!input) return;
        input.addEventListener('input', function () {
          syncBuilderPreview(form);
        });
      });

      syncBuilderPreview(form);
    });

    document.querySelectorAll('.btn-copy-inline').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var value = btn.getAttribute('data-copy') || '';
        if (!value) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(value);
        }
      });
    });

    document.querySelectorAll('.custom-order-activity-toggle').forEach(function (activityToggle) {
      if (activityToggle.dataset.activityToggleBound === '1') return;
      activityToggle.dataset.activityToggleBound = '1';
      activityToggle.addEventListener('click', function () {
        var expanded = activityToggle.getAttribute('aria-expanded') === 'true';
        var activityModal = activityToggle.closest('.custom-order-activity-modal') || document;
        activityModal.querySelectorAll('.custom-activity-extra-row').forEach(function (row) {
          row.hidden = expanded;
        });
        activityToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        activityToggle.textContent = expanded ? 'Show more' : 'Show less';
      });
    });

    document.querySelectorAll('.custom-activity-modal-close').forEach(function (closeButton) {
      if (closeButton.dataset.activityCloseBound === '1') return;
      closeButton.dataset.activityCloseBound = '1';
      closeButton.addEventListener('click', function (event) {
        event.preventDefault();
        var activityModal = closeButton.closest('.custom-order-activity-modal');
        if (activityModal && window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
          window.jQuery(activityModal).modal('hide');
        }
      });
    });

    initializeCustomCountryState(document);
    applyCustomOrdersAccess(document);
    initializeCustomCollapsiblePanels(document);
    initializeCustomNoteReplies(document);
    alignCustomHelpIcons(document);
    var helpAlignmentFrame = 0;
    window.addEventListener('resize', function () {
      window.cancelAnimationFrame(helpAlignmentFrame);
      helpAlignmentFrame = window.requestAnimationFrame(function () {
        alignCustomHelpIcons(document);
      });
    });
    document.querySelectorAll('.table-responsive').forEach(function (tableWrap) {
      tableWrap.addEventListener('scroll', function () { alignCustomHelpIcons(tableWrap); }, { passive: true });
    });
    if (window.jQuery) {
      window.jQuery(document).on('shown.bs.modal.customOrderHelp', '.modal', function () {
        alignCustomHelpIcons(this);
      });
    }
  })();
</script>
