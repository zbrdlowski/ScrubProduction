<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$orderId = (int) ($_POST['custom_order_id'] ?? 0);
$itemId = (int) ($_POST['custom_item_id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$finish = static function (bool $ok, string $message, int $status = 200) use ($isAjax, $orderId, $itemId): void {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message, 'custom_order_id' => $orderId, 'custom_item_id' => $itemId],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }
  customOrdersFlash($ok ? 'success' : 'danger', $message);
  customOrdersRedirect($orderId);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $finish(false, 'POST required.', 405);
if ($orderId <= 0 || $itemId <= 0 || $userId <= 0) $finish(false, 'Invalid custom item.', 422);
$token = $_POST['csrf_token'] ?? null;
$expectedToken = $_SESSION['custom_order_item_note_csrf'] ?? '';
if (!is_string($token) || $expectedToken === '' || !hash_equals($expectedToken, $token)) {
  $finish(false, 'Reload the custom order and try again.', 403);
}
if (!isset($_POST['scrub_note']) || !is_string($_POST['scrub_note'])) {
  $finish(false, 'Scrub note is required; send an empty value to clear it.', 422);
}
$note = trim($_POST['scrub_note']);

try {
  $conn->begin_transaction();
  // Recheck the current export and assignment while saving, including stale open tabs.
  $stmt = $conn->prepare('SELECT production_order_id, exported_at FROM custom_orders WHERE id = ? FOR UPDATE');
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$order) throw new RuntimeException('Custom order not found.', 404);
  if (customOrdersIsExported($order)) throw new RuntimeException(customOrdersExportLockMessage(), 409);

  if (!auth_can('custom_orders.manage')) {
    $stmt = $conn->prepare('SELECT employee_id FROM custom_order_assignments WHERE custom_order_id = ? FOR UPDATE');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int) ($assignment['employee_id'] ?? 0) !== $userId) {
      throw new RuntimeException('Only the user assigned to this custom order can edit its Scrub notes.', 403);
    }
  }

  $stmt = $conn->prepare('SELECT title, options_json FROM custom_order_items WHERE id = ? AND custom_order_id = ? FOR UPDATE');
  $stmt->bind_param('ii', $itemId, $orderId);
  $stmt->execute();
  $item = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$item) throw new RuntimeException('Custom item not found in this order.', 404);

  $options = json_decode((string) ($item['options_json'] ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($options)) throw new RuntimeException('Invalid saved item options.', 422);
  $previousNote = (string) ($options['my-item-note'] ?? '');
  if ($previousNote !== $note) {
    // Merge this one key into the current options; never save the posted product fields.
    if ($note === '') unset($options['my-item-note']);
    else $options['my-item-note'] = $note;
    $optionsJson = json_encode((object) $options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('UPDATE custom_order_items SET options_json = ?, updated_by = ? WHERE id = ? AND custom_order_id = ?');
    $stmt->bind_param('siii', $optionsJson, $userId, $itemId, $orderId);
    $stmt->execute();
    $stmt->close();
    customOrdersLog($conn, $orderId, 'item_updated', $userId, [
      'item_id' => $itemId,
      'title' => $item['title'],
      'changes' => [['field' => 'my-item-note', 'label' => 'Scrub note', 'from' => $previousNote, 'to' => $note]],
    ], 'Scrub note updated');
  }
  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  $status = in_array($e->getCode(), [403, 404, 409, 422], true) ? $e->getCode() : 500;
  $finish(false, $e->getMessage(), $status);
}
$finish(true, 'Scrub note saved.');
