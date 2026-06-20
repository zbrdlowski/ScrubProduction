<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$contactedAt = trim((string) ($_POST['contacted_at'] ?? ''));
if ($contactedAt === '') {
  $contactedAt = customOrdersNow();
}
$channel = trim((string) ($_POST['channel'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));

$stmt = $conn->prepare('INSERT INTO custom_order_followups (custom_order_id, contacted_at, channel, note, created_by) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('isssi', $orderId, $contactedAt, $channel, $note, $userId);
$stmt->execute();
$followupId = (int) $stmt->insert_id;
$stmt->close();

$stmt = $conn->prepare('UPDATE custom_orders SET last_contact_at = ?, updated_by = ? WHERE id = ?');
$stmt->bind_param('sii', $contactedAt, $userId, $orderId);
$stmt->execute();
$stmt->close();

customOrdersLog($conn, $orderId, 'followup_added', $userId, ['followup_id' => $followupId, 'channel' => $channel], 'Follow-up logged');
customOrdersFlash('success', 'Follow-up saved.');
customOrdersRedirect($orderId);
