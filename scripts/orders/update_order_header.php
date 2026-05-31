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

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) out(400, ['ok'=>false,'error'=>'Invalid order_id']);

$delivery = trim((string)($_POST['delivery'] ?? ''));
$payment  = trim((string)($_POST['payment'] ?? ''));

$billing = $_POST['billing'] ?? [];
$shipping = $_POST['shipping'] ?? [];

function clean($v): string {
  return trim((string)$v);
}

$conn->begin_transaction();

try {
  $stmt = $conn->prepare("
    UPDATE orders
    SET shipping_method = ?, payment_method = ?
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) throw new Exception($conn->error);
  $stmt->bind_param('ssi', $delivery, $payment, $orderId);
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

  $conn->commit();
  out(200, ['ok'=>true]);

} catch (Throwable $e) {
  $conn->rollback();
  out(500, ['ok'=>false,'error'=>$e->getMessage()]);
}