<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$data = [
  'status' => trim((string) ($_POST['status'] ?? 'LEAD')),
  'complexity_level' => max(1, min(10, (int) ($_POST['complexity_level'] ?? 1))),
  'source_channel' => trim((string) ($_POST['source_channel'] ?? '')),
  'social_platform' => trim((string) ($_POST['social_platform'] ?? '')),
  'social_handle' => trim((string) ($_POST['social_handle'] ?? '')),
  'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
  'customer_email' => trim((string) ($_POST['customer_email'] ?? '')),
  'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
  'customer_country' => customOrdersNormalizeCountry((string) ($_POST['customer_country'] ?? '')),
  'bike_brand' => trim((string) ($_POST['bike_brand'] ?? '')),
  'bike_model' => trim((string) ($_POST['bike_model'] ?? '')),
  'bike_year' => trim((string) ($_POST['bike_year'] ?? '')),
  'bike_details' => trim((string) ($_POST['bike_details'] ?? '')),
  'rider_name' => trim((string) ($_POST['rider_name'] ?? '')),
  'rider_number' => trim((string) ($_POST['rider_number'] ?? '')),
  'shipping_name' => trim((string) ($_POST['shipping_name'] ?? '')),
  'shipping_company' => trim((string) ($_POST['shipping_company'] ?? '')),
  'shipping_street' => trim((string) ($_POST['shipping_street'] ?? '')),
  'shipping_city' => trim((string) ($_POST['shipping_city'] ?? '')),
  'shipping_zip' => trim((string) ($_POST['shipping_zip'] ?? '')),
  'shipping_country' => customOrdersNormalizeCountry((string) ($_POST['shipping_country'] ?? '')),
  'shipping_email' => trim((string) ($_POST['shipping_email'] ?? '')),
  'shipping_phone' => trim((string) ($_POST['shipping_phone'] ?? '')),
  'shipping_method' => trim((string) ($_POST['shipping_method'] ?? '')),
  'shipping_price' => (float) ($_POST['shipping_price'] ?? 0),
  'currency' => trim((string) ($_POST['currency'] ?? 'EUR')),
  'deposit_revision_limit' => max(0, min(20, (int) ($_POST['deposit_revision_limit'] ?? 3))),
  'deposit_revision_used' => max(0, min(20, (int) ($_POST['deposit_revision_used'] ?? 0))),
  'graphics_brief' => trim((string) ($_POST['graphics_brief'] ?? '')),
  'customer_notes' => trim((string) ($_POST['customer_notes'] ?? '')),
  'internal_notes' => trim((string) ($_POST['internal_notes'] ?? '')),
  'bike_photo_urls' => trim((string) ($_POST['bike_photo_urls'] ?? '')),
  'reference_urls' => trim((string) ($_POST['reference_urls'] ?? '')),
  'last_contact_at' => trim((string) ($_POST['last_contact_at'] ?? '')) ?: null,
  'next_followup_at' => trim((string) ($_POST['next_followup_at'] ?? '')) ?: null,
  'dead_order_flag' => isset($_POST['dead_order_flag']) ? 1 : 0,
];

$contactId = customOrdersUpsertContactDirectory($conn, $data);

$stmt = $pdo->prepare('
  UPDATE custom_orders
  SET status = :status,
      complexity_level = :complexity_level,
      source_channel = :source_channel,
      social_platform = :social_platform,
      social_handle = :social_handle,
      customer_name = :customer_name,
      customer_email = :customer_email,
      customer_phone = :customer_phone,
      customer_country = :customer_country,
      bike_brand = :bike_brand,
      bike_model = :bike_model,
      bike_year = :bike_year,
      bike_details = :bike_details,
      rider_name = :rider_name,
      rider_number = :rider_number,
      shipping_name = :shipping_name,
      shipping_company = :shipping_company,
      shipping_street = :shipping_street,
      shipping_city = :shipping_city,
      shipping_zip = :shipping_zip,
      shipping_country = :shipping_country,
      shipping_email = :shipping_email,
      shipping_phone = :shipping_phone,
      shipping_method = :shipping_method,
      shipping_price = :shipping_price,
      currency = :currency,
      deposit_revision_limit = :deposit_revision_limit,
      deposit_revision_used = :deposit_revision_used,
      graphics_brief = :graphics_brief,
      customer_notes = :customer_notes,
      internal_notes = :internal_notes,
      bike_photo_urls = :bike_photo_urls,
      reference_urls = :reference_urls,
      last_contact_at = :last_contact_at,
      next_followup_at = :next_followup_at,
      dead_order_flag = :dead_order_flag,
      contact_directory_id = :contact_directory_id,
      updated_by = :updated_by
  WHERE id = :id
');
$stmt->execute([
  ':status' => $data['status'],
  ':complexity_level' => $data['complexity_level'],
  ':source_channel' => $data['source_channel'],
  ':social_platform' => $data['social_platform'],
  ':social_handle' => $data['social_handle'],
  ':customer_name' => $data['customer_name'],
  ':customer_email' => $data['customer_email'],
  ':customer_phone' => $data['customer_phone'],
  ':customer_country' => $data['customer_country'],
  ':bike_brand' => $data['bike_brand'],
  ':bike_model' => $data['bike_model'],
  ':bike_year' => $data['bike_year'],
  ':bike_details' => $data['bike_details'],
  ':rider_name' => $data['rider_name'],
  ':rider_number' => $data['rider_number'],
  ':shipping_name' => $data['shipping_name'],
  ':shipping_company' => $data['shipping_company'],
  ':shipping_street' => $data['shipping_street'],
  ':shipping_city' => $data['shipping_city'],
  ':shipping_zip' => $data['shipping_zip'],
  ':shipping_country' => $data['shipping_country'],
  ':shipping_email' => $data['shipping_email'],
  ':shipping_phone' => $data['shipping_phone'],
  ':shipping_method' => $data['shipping_method'],
  ':shipping_price' => $data['shipping_price'],
  ':currency' => $data['currency'],
  ':deposit_revision_limit' => $data['deposit_revision_limit'],
  ':deposit_revision_used' => $data['deposit_revision_used'],
  ':graphics_brief' => $data['graphics_brief'],
  ':customer_notes' => $data['customer_notes'],
  ':internal_notes' => $data['internal_notes'],
  ':bike_photo_urls' => $data['bike_photo_urls'],
  ':reference_urls' => $data['reference_urls'],
  ':last_contact_at' => $data['last_contact_at'],
  ':next_followup_at' => $data['next_followup_at'],
  ':dead_order_flag' => $data['dead_order_flag'],
  ':contact_directory_id' => $contactId,
  ':updated_by' => $userId,
  ':id' => $orderId,
]);

customOrdersLog($conn, $orderId, 'header_updated', $userId, ['status' => $data['status']], 'Header updated');
customOrdersFlash('success', 'Custom order saved.');
customOrdersRedirect($orderId);
