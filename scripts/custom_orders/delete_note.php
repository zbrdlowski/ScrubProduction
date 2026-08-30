<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$noteId = (int) ($_POST['note_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$permission = (int) ($_SESSION['permission'] ?? 0);

if ($orderId <= 0 || $noteId <= 0) {
  customOrdersFlash('danger', 'Invalid note deletion.');
  customOrdersRedirect($orderId > 0 ? $orderId : 0);
}

$stmt = $conn->prepare('SELECT id, note_body, created_by, deleted_at FROM custom_order_notes WHERE id = ? AND custom_order_id = ? LIMIT 1');
$stmt->bind_param('ii', $noteId, $orderId);
$stmt->execute();
$note = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$note || !empty($note['deleted_at'])) {
  customOrdersFlash('danger', 'Note not found or already deleted.');
  customOrdersRedirect($orderId);
}
if ($permission < 300 && (int) ($note['created_by'] ?? 0) !== $userId) {
  http_response_code(403);
  exit('You may delete only your own notes.');
}

$oldBody = (string) ($note['note_body'] ?? '');
$conn->begin_transaction();
try {
  $revision = $conn->prepare('
    INSERT INTO custom_order_note_revisions
      (note_id, custom_order_id, revision_action, old_body, new_body, actor_employee_id)
    VALUES (?, ?, \'DELETE\', ?, NULL, ?)
  ');
  $revision->bind_param('iisi', $noteId, $orderId, $oldBody, $userId);
  $revision->execute();
  $revision->close();

  $update = $conn->prepare('UPDATE custom_order_notes SET deleted_by = ?, deleted_at = NOW() WHERE id = ? AND custom_order_id = ? AND deleted_at IS NULL');
  $update->bind_param('iii', $userId, $noteId, $orderId);
  $update->execute();
  if ($update->affected_rows !== 1) {
    throw new RuntimeException('Note could not be deleted.');
  }
  $update->close();

  customOrdersLog($conn, $orderId, 'note_deleted', $userId, ['note_id' => $noteId], 'Note #' . $noteId . ' deleted from the normal view; full text is retained in the restricted note audit.');
  $conn->commit();
} catch (Throwable $error) {
  $conn->rollback();
  customOrdersFlash('danger', 'Note could not be deleted: ' . $error->getMessage());
  customOrdersRedirect($orderId);
}

customOrdersFlash('success', 'Note deleted.');
customOrdersRedirect($orderId, $noteId);
