<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$noteId = (int) ($_POST['note_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$permission = (int) ($_SESSION['permission'] ?? 0);
$newBody = trim((string) ($_POST['note_body'] ?? ''));

if ($orderId <= 0 || $noteId <= 0 || $newBody === '') {
  customOrdersFlash('danger', 'Invalid note edit.');
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
  exit('You may edit only your own notes.');
}

$oldBody = (string) ($note['note_body'] ?? '');
if ($oldBody === $newBody) {
  customOrdersFlash('info', 'No changes to save.');
  customOrdersRedirect($orderId);
}

$conn->begin_transaction();
try {
  $revision = $conn->prepare('
    INSERT INTO custom_order_note_revisions
      (note_id, custom_order_id, revision_action, old_body, new_body, actor_employee_id)
    VALUES (?, ?, \'EDIT\', ?, ?, ?)
  ');
  $revision->bind_param('iissi', $noteId, $orderId, $oldBody, $newBody, $userId);
  $revision->execute();
  $revision->close();

  $update = $conn->prepare('UPDATE custom_order_notes SET note_body = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND custom_order_id = ? AND deleted_at IS NULL');
  $update->bind_param('siii', $newBody, $userId, $noteId, $orderId);
  $update->execute();
  if ($update->affected_rows !== 1) {
    throw new RuntimeException('Note could not be updated.');
  }
  $update->close();

  customOrdersLog($conn, $orderId, 'note_edited', $userId, ['note_id' => $noteId], 'Note #' . $noteId . ' edited; full text is retained in the restricted note audit.');
  $conn->commit();
} catch (Throwable $error) {
  $conn->rollback();
  customOrdersFlash('danger', 'Note could not be updated: ' . $error->getMessage());
  customOrdersRedirect($orderId);
}

customOrdersFlash('success', 'Note updated.');
customOrdersRedirect($orderId, $noteId);
