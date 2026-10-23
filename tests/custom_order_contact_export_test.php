<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';

$checks = 0;
function contactExportAssert(bool $condition, string $message): void
{
  global $checks;
  $checks++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$order = [
  'official_order_number' => 'SO-TEST', 'customer_name' => 'Test Customer', 'social_handle' => '',
  'shipping_name' => 'Test Recipient', 'shipping_street' => 'Test Street 1', 'shipping_city' => 'Test City',
  'shipping_zip' => '12345', 'shipping_country' => 'SK', 'shipping_state' => '',
  'customer_email' => 'old@example.test', 'customer_phone' => '+421900000001',
  'shipping_email' => 'old@example.test', 'shipping_phone' => '+421900000001',
  'billing_email' => 'billing@example.test', 'billing_phone' => '+421900000002',
  'items' => [['id' => 1]], 'summary' => ['gross_total' => 100], 'production_order_id' => null,
];

// Exercise the actual save preprocessing without loading the HTTP bootstrap or accessing the database.
$saveSource = file_get_contents(dirname(__DIR__) . '/scripts/custom_orders/save_order.php');
$saveStart = strpos($saveSource, '$posted = static function');
$saveEnd = strpos($saveSource, '$postedCustomerCountry =', $saveStart);
contactExportAssert($saveStart !== false && $saveEnd !== false, 'Save preprocessing is available');
$saveCode = substr($saveSource, $saveStart, $saveEnd - $saveStart);
$save = static function (array $existing, array $posted) use ($saveCode): array {
  $_POST = $posted;
  eval($saveCode);
  return $data;
};

$deleted = $save($order, ['shipping_email' => '', 'shipping_phone' => '', 'billing_same_as_shipping' => '1']);
foreach (['customer', 'shipping', 'billing'] as $prefix) {
  foreach (['email', 'phone'] as $field) {
    contactExportAssert($deleted[$prefix . '_' . $field] === '', 'Clearing the visible contact also clears ' . $prefix . '_' . $field);
  }
}
contactExportAssert(customOrdersExportValidation($deleted)['messages'] !== [], 'Deleting both contacts blocks server-side export');

$changed = $save($order, ['shipping_email' => ' new@example.test ', 'shipping_phone' => ' +421900000003 ']);
contactExportAssert($changed['customer_email'] === 'new@example.test', 'Changing email replaces the hidden old value');
contactExportAssert($changed['customer_phone'] === '+421900000003', 'Changing phone replaces the hidden old value');
contactExportAssert($changed['billing_phone'] === $order['billing_phone'], 'Separate billing contact is preserved');
$partial = $save($order, ['status' => 'DRAFT_X']);
contactExportAssert($partial['customer_email'] === $order['customer_email'] && $partial['customer_phone'] === $order['customer_phone'], 'Unrelated partial saves preserve contacts');

// Old rows affected by the previous bug must also ignore the stale hidden values.
$stale = array_replace($order, ['shipping_email' => '', 'shipping_phone' => '']);
contactExportAssert(customOrdersExportValidation($stale)['messages'] !== [], 'Hidden stale contacts cannot bypass export validation');
foreach (['email', 'phone'] as $field) {
  contactExportAssert(customOrdersContactValue($stale, $field) === '', 'Export respects explicit empty ' . $field);
  contactExportAssert(customOrdersContactValue(array_replace($stale, ['shipping_' . $field => " \t "]), $field) === '', 'Whitespace is empty');
  contactExportAssert(customOrdersContactValue(array_replace($stale, ['shipping_' . $field => null]), $field) === '', 'Null visible field does not revive a hidden value');
  contactExportAssert(customOrdersContactValue($stale, $field, 'billing') === $order['billing_' . $field], 'Separate billing contact remains separate');
  contactExportAssert(customOrdersContactValue($deleted, $field, 'billing') === '', 'Cleared billing contact stays empty');
}
$phoneOnly = array_replace($stale, ['shipping_phone' => '+421900000003']);
$emailOnly = array_replace($stale, ['shipping_email' => 'new@example.test']);
contactExportAssert(customOrdersExportValidation($phoneOnly)['messages'] === [], 'A phone still satisfies server contact requirements');
contactExportAssert(customOrdersExportValidation($emailOnly)['messages'] === [], 'An email still satisfies server contact requirements');
contactExportAssert(customOrdersContactValue(['customer_phone' => '+421900000003'], 'phone') === '+421900000003', 'Customer-only callers remain supported');

// Verify the labels actually embedded in both export buttons, including pre-existing stale rows.
$template = file_get_contents(dirname(__DIR__) . '/includes/custom_orders.php');
$warningStart = strpos($template, '$customOrderExportMissingFields = [];');
$warningEnd = strpos($template, '$customOrderActiveFilterBadges = [];', $warningStart);
contactExportAssert($warningStart !== false && $warningEnd !== false, 'Warning generation is available');
$warningCode = substr($template, $warningStart, $warningEnd - $warningStart);
$warnings = static function (array $selectedOrder, string $customOrderHelpLang) use ($warningCode): array {
  eval($warningCode);
  return json_decode($customOrderExportMissingFieldsJson, true);
};
contactExportAssert($warnings($stale, 'en') === ['Contact email', 'Contact phone number'], 'Both missing contacts appear despite stale hidden copies');
contactExportAssert($warnings($emailOnly, 'en') === ['Contact phone number'], 'Phone warning appears even when email exists');
contactExportAssert($warnings($phoneOnly, 'sk') === ['Kontaktný e-mail'], 'Slovak contact warning is correct');
contactExportAssert($warnings($changed + $order, 'en') === [], 'Saved replacement contacts have no contact warnings');

// Production must not visually resurrect a deleted contact from the shared customer directory.
$detail = file_get_contents(dirname(__DIR__) . '/scripts/orders/get_order_detail.php');
$phoneStart = strpos($detail, "$" . "displayCustomerPhone = '';");
$phoneEnd = strpos($detail, '$postProductionInvoiceMeta =', $phoneStart);
$emailStart = strpos($detail, '$deliveryContactPhone =', $phoneEnd);
$emailEnd = strpos($detail, '// --- items', $emailStart);
contactExportAssert($phoneStart !== false && $phoneEnd !== false && $emailStart !== false && $emailEnd !== false, 'Production display logic is available');
$displayCode = substr($detail, $phoneStart, $phoneEnd - $phoneStart) . substr($detail, $emailStart, $emailEnd - $emailStart);
$display = static function (bool $isCustomOrder) use ($displayCode): array {
  $addr = ['SHIPPING' => ['email' => '', 'phone' => ''], 'BILLING' => ['email' => 'old@example.test', 'phone' => '+421900000002']];
  $order = ['customer_email' => 'old@example.test', 'customer_phone' => '+421900000001'];
  eval($displayCode);
  return [$deliveryEmail, $deliveryContactPhone];
};
contactExportAssert($display(true) === ['', ''], 'Custom Production order does not revive cleared contacts');
contactExportAssert($display(false) === ['old@example.test', '+421900000002'], 'Other order sources retain their existing fallback behavior');

echo 'custom_order_contact_export: ', $checks, " checks passed\n";
