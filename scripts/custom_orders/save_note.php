<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($orderId <= 0) {
  customOrdersFlash('danger', 'Invalid custom order.');
  customOrdersRedirect();
}

$noteBody = trim((string) ($_POST['note_body'] ?? ''));
$parentNoteId = (int) ($_POST['parent_note_id'] ?? 0);
if ($noteBody === '') {
  customOrdersFlash('danger', 'Note cannot be empty.');
  customOrdersRedirect($orderId);
}

customOrdersAddNote($conn, $orderId, 'INTERNAL', $noteBody, $userId, $parentNoteId);
customOrdersFlash('success', $parentNoteId > 0 ? 'Reply submitted.' : 'Note submitted.');
customOrdersRedirect($orderId);
