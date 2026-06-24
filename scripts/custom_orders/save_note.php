<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$noteType = trim((string) ($_POST['note_type'] ?? 'INTERNAL'));
$noteBody = trim((string) ($_POST['note_body'] ?? ''));
if ($noteBody === '') {
  customOrdersFlash('danger', 'Note cannot be empty.');
  customOrdersRedirect($orderId);
}

customOrdersAddNote($conn, $orderId, $noteType, $noteBody, $userId);
customOrdersFlash('success', 'Note appended.');
customOrdersRedirect($orderId);
