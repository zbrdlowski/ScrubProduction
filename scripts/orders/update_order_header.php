<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(403, ['ok'=>false,'error'=>'Not logged in']);
}

if ((int)($_SESSION['permission'] ?? 0) < 400) {
  out(403, ['ok'=>false,'error'=>'No permission']);
}

$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';
require_once $base . '/includes/orders_customs_helpers.php';

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) out(400, ['ok'=>false,'error'=>'Invalid order_id']);

$delivery = trim((string)($_POST['delivery'] ?? ''));
$payment  = trim((string)($_POST['payment'] ?? ''));
$customerName = trim((string)($_POST['customer_name'] ?? ''));
$customsIdentifier = trim((string)($_POST['customs_identifier'] ?? ''));

if (mb_strlen($customsIdentifier) > 128) {
  out(400, ['ok'=>false,'error'=>'Customs / Tax ID is too long (maximum 128 characters)']);
}

$billing = $_POST['billing'] ?? [];
$shipping = $_POST['shipping'] ?? [];

function clean($v): string {
  return trim((string)$v);
}

$conn->begin_transaction();

try {
  $orderStmt = $conn->prepare("
    SELECT customer_id
    FROM orders
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ");
  if (!$orderStmt) throw new Exception($conn->error);
  $orderStmt->bind_param('i', $orderId);
  $orderStmt->execute();
  $orderRow = $orderStmt->get_result()->fetch_assoc();
  $orderStmt->close();
  if (!$orderRow) {
    throw new Exception('Order not found.');
  }

  $customerEmail = clean($shipping['email'] ?? '');
  if ($customerEmail === '') {
    $customerEmail = clean($billing['email'] ?? '');
  }
  $customerPhone = clean($shipping['phone'] ?? '');
  if ($customerPhone === '') {
    $customerPhone = clean($billing['phone'] ?? '');
  }

  $customerId = (int)($orderRow['customer_id'] ?? 0);
  if ($customerId > 0) {
    $stmt = $conn->prepare("
      UPDATE customers
      SET name = ?,
          email = COALESCE(NULLIF(?, ''), email),
          phone = COALESCE(NULLIF(?, ''), phone)
      WHERE id = ?
      LIMIT 1
    ");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('sssi', $customerName, $customerEmail, $customerPhone, $customerId);
    $stmt->execute();
    $stmt->close();
  } elseif ($customerName !== '' || $customerEmail !== '' || $customerPhone !== '') {
    $stmt = $conn->prepare("INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('sss', $customerName, $customerEmail, $customerPhone);
    $stmt->execute();
    $customerId = (int)$stmt->insert_id;
    $stmt->close();
  }

  $stmt = $conn->prepare("
    UPDATE orders
    SET shipping_method = ?,
        payment_method = ?,
        customs_identifier = ?,
        customer_id = CASE WHEN ? > 0 THEN ? ELSE customer_id END
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) throw new Exception($conn->error);
  $stmt->bind_param('sssiii', $delivery, $payment, $customsIdentifier, $customerId, $customerId, $orderId);
  $stmt->execute();
  $stmt->close();

  $addressTypes = [
    'BILLING' => $billing,
    'SHIPPING' => $shipping,
  ];

  foreach ($addressTypes as $type => $a) {
    $name    = clean($a['name'] ?? '');
    $company = clean($a['company'] ?? '');
    $company_id = clean($a['company_id'] ?? '');
    $street  = clean($a['street'] ?? '');
    $city    = clean($a['city'] ?? '');
    $zip     = clean($a['zip'] ?? '');
    $country = strtoupper(clean($a['country'] ?? ''));
    $email   = clean($a['email'] ?? '');
    $phone   = clean($a['phone'] ?? '');

    $check = $conn->prepare("
      SELECT id FROM order_addresses
      WHERE order_id = ? AND type = ?
      LIMIT 1
    ");
    if (!$check) throw new Exception($conn->error);
    $check->bind_param('is', $orderId, $type);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
      $stmt = $conn->prepare("
        UPDATE order_addresses
        SET name=?, company=?, company_id=?, street=?, city=?, zip=?, country=?, email=?, phone=?
        WHERE id=?
        LIMIT 1
      ");
      if (!$stmt) throw new Exception($conn->error);
      $addrId = (int)$existing['id'];
      $stmt->bind_param(
        'sssssssssi',
        $name, $company, $company_id, $street, $city, $zip, $country, $email, $phone, $addrId
      );
      $stmt->execute();
      $stmt->close();
    } else {
      $stmt = $conn->prepare("
        INSERT INTO order_addresses
          (order_id, type, name, company, company_id, street, city, zip, country, email, phone)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      if (!$stmt) throw new Exception($conn->error);
      $stmt->bind_param(
        'issssssssss',
        $orderId, $type, $name, $company, $company_id, $street, $city, $zip, $country, $email, $phone
      );
      $stmt->execute();
      $stmt->close();
    }
  }

  $customStmt = $conn->prepare("
    SELECT id
    FROM custom_orders
    WHERE production_order_id = ?
    LIMIT 1
  ");
  if ($customStmt) {
    $customStmt->bind_param('i', $orderId);
    $customStmt->execute();
    $customRow = $customStmt->get_result()->fetch_assoc();
    $customStmt->close();
    if ($customRow) {
      $customOrderId = (int)$customRow['id'];
      $billingName = clean($billing['name'] ?? '');
      $billingCompany = clean($billing['company'] ?? '');
      $billingCompanyId = clean($billing['company_id'] ?? '');
      $billingStreet = clean($billing['street'] ?? '');
      $billingCity = clean($billing['city'] ?? '');
      $billingZip = clean($billing['zip'] ?? '');
      $billingCountry = strtoupper(clean($billing['country'] ?? ''));
      $billingEmail = clean($billing['email'] ?? '');
      $billingPhone = clean($billing['phone'] ?? '');
      $shippingName = clean($shipping['name'] ?? '');
      $shippingCompany = clean($shipping['company'] ?? '');
      $shippingCompanyId = clean($shipping['company_id'] ?? '');
      $shippingStreet = clean($shipping['street'] ?? '');
      $shippingCity = clean($shipping['city'] ?? '');
      $shippingZip = clean($shipping['zip'] ?? '');
      $shippingCountry = strtoupper(clean($shipping['country'] ?? ''));
      $shippingEmail = clean($shipping['email'] ?? '');
      $shippingPhone = clean($shipping['phone'] ?? '');
      $customerCountry = $shippingCountry !== '' ? $shippingCountry : $billingCountry;
      $userId = (int)($_SESSION['user_id'] ?? 0);

      $stmt = $conn->prepare("
        UPDATE custom_orders
        SET customer_name = ?,
            customer_email = COALESCE(NULLIF(?, ''), customer_email),
            customer_phone = COALESCE(NULLIF(?, ''), customer_phone),
            customer_country = ?,
            payment_method = ?,
            shipping_method = ?,
            billing_name = ?, billing_company = ?, billing_company_id = ?, billing_street = ?, billing_city = ?, billing_zip = ?, billing_country = ?, billing_email = ?, billing_phone = ?,
            shipping_name = ?, shipping_company = ?, shipping_company_id = ?, shipping_street = ?, shipping_city = ?, shipping_zip = ?, shipping_country = ?, shipping_email = ?, shipping_phone = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ");
      if (!$stmt) throw new Exception($conn->error);
      $stmt->bind_param(
        'ssssssssssssssssssssssssii',
        $customerName,
        $customerEmail,
        $customerPhone,
        $customerCountry,
        $payment,
        $delivery,
        $billingName,
        $billingCompany,
        $billingCompanyId,
        $billingStreet,
        $billingCity,
        $billingZip,
        $billingCountry,
        $billingEmail,
        $billingPhone,
        $shippingName,
        $shippingCompany,
        $shippingCompanyId,
        $shippingStreet,
        $shippingCity,
        $shippingZip,
        $shippingCountry,
        $shippingEmail,
        $shippingPhone,
        $userId,
        $customOrderId
      );
      $stmt->execute();
      $stmt->close();
    }
  }

  $conn->commit();
  $effectiveCountry = strtoupper(clean($shipping['country'] ?? ''));
  if ($effectiveCountry === '') {
    $effectiveCountry = strtoupper(clean($billing['country'] ?? ''));
  }
  $displayCustomerName = $customerName !== '' ? $customerName : $customerEmail;

  out(200, [
    'ok'=>true,
    'customer_name'=>$displayCustomerName,
    'customs_identifier_missing'=>ordersIsCustomsIdentifierMissing($effectiveCountry, $customsIdentifier),
  ]);

} catch (Throwable $e) {
  $conn->rollback();
  out(500, ['ok'=>false,'error'=>$e->getMessage()]);
}
