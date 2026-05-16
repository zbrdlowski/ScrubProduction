<?php
session_start();

$base = dirname(__DIR__, 2);

require_once $base . '/includes/conn.php';
require_once $base . '/includes/orders_status_helpers.php';
require_once $base . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/activity_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$newStatus = trim($_POST['status'] ?? '');
$note = trim($_POST['note'] ?? '');
$expectedDate = trim($_POST['expected_date'] ?? '');

$allowed = [
    'NEW',
    'PROCESSING',
    'RTP',
    'PRINT_QUEUE',
    'PRINTED',
    'CUT',
    'READY',
    'WAITING',
    'DONE'
];

if (!$itemId || !in_array($newStatus, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, order_id, status
    FROM order_items
    WHERE id = ?
");
$stmt->execute([$itemId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo json_encode(['success' => false, 'message' => 'Item not found']);
    exit;
}

$oldStatus = $item['status'];
$orderId = (int)$item['order_id'];
$userId = (int)$_SESSION['user_id'];

$update = $pdo->prepare("
    UPDATE order_items
    SET status = ?,
        waiting_note = ?,
        expected_date = NULLIF(?, ''),
        completed_by = ?,
        completed_at = NOW()
    WHERE id = ?
");
$update->execute([
    $newStatus,
    $note ?: null,
    $expectedDate,
    $userId,
    $itemId
]);

$history = $pdo->prepare("
    INSERT INTO order_item_statuses
    (order_item_id, old_status, new_status, note, expected_date, changed_by)
    VALUES (?, ?, ?, ?, NULLIF(?, ''), ?)
");
$history->execute([
    $itemId,
    $oldStatus,
    $newStatus,
    $note ?: null,
    $expectedDate,
    $userId
]);
log_order_activity(
    $conn,
    $orderId,
    $userId,
    'ITEM_STATUS_CHANGED',
    'order_item',
    $itemId,
    [
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'note' => $note,
        'expected_date' => $expectedDate
    ],
    'Item status changed: ' . $oldStatus . ' → ' . $newStatus
);
//recalculateOrderStatus($pdo, $orderId);
recalculateOrderWorkflow($conn, $orderId);

// Po prepočte načítame aktuálny traffic_summary_json a vrátime ho v odpovedi
// — JS ho priamo aplikuje na badge v riadku tabuľky bez extra requestu
$trafficStmt = $conn->prepare("SELECT traffic_summary_json FROM orders WHERE id = ? LIMIT 1");
$trafficStmt->bind_param('i', $orderId);
$trafficStmt->execute();
$trafficRow = $trafficStmt->get_result()->fetch_assoc();
$trafficStmt->close();

$trafficSummary = null;
if (!empty($trafficRow['traffic_summary_json'])) {
    $decoded = json_decode((string)$trafficRow['traffic_summary_json'], true);
    if (is_array($decoded)) {
        $trafficSummary = $decoded;
    }
}

echo json_encode(['success' => true, 'order_id' => $orderId, 'traffic_summary' => $trafficSummary]);