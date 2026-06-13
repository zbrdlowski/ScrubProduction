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

if (!$itemId || $newStatus === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, order_id, status, item_type_code, options_json, internal_options_json
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
$itemType = strtoupper(trim((string)($item['item_type_code'] ?? '')));
$allowed = ordersGetItemStatusCodes($conn, $itemType, true);

if (!in_array($newStatus, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Status is not allowed for this department']);
    exit;
}

if ($newStatus === 'READY' && $itemType === 'S') {
    // DOČASNE VYPNUTÉ:
    // Validácia seat-cover operácií pred prechodom na READY ostáva zachovaná
    // nižšie v tomto bloku, ale je teraz bypassnutá. Dôvod: vstupné hodnoty
    // z importu zatiaľ prichádzajú vo viacerých jazykových/platformových
    // variantoch. Po zavedení translation flat file / normalizačnej vrstvy
    // treba tento blok znovu zapnúť a doladiť nad unifikovanými hodnotami.
    if (false) {
    $extOptArr      = json_decode((string)($item['options_json'] ?? ''), true) ?: [];
    $internalOptArr = json_decode((string)($item['internal_options_json'] ?? ''), true) ?: [];
    $confirmed      = $internalOptArr['_seat_cover_ops_confirmed'] ?? [];
    if (!is_array($confirmed)) $confirmed = [];

    // Rovnaká definícia ako v get_order_detail.php
    $seatOpsMeta = [
        'waterproof-seams'   => ['code' => 'Waterproof Seams',  'required_when' => 'exists_in_json', 'json_key' => 'waterproof-seams',   'internal_key' => '_seat_waterproof_seams'],
        'enduro-pocket'      => ['code' => 'Enduro Pocket',     'required_when' => 'filled',         'json_key' => 'enduro-pocket',      'internal_key' => '_seat_enduro_pocket'],
        'side-brand-patches' => ['code' => 'Sidebrand Patches', 'required_when' => 'exists_in_json', 'json_key' => 'side-brand-patches', 'internal_key' => '_seat_side_brand_patches'],
        'patch-applied'      => ['code' => 'Patch Applied',     'required_when' => 'exists_in_json', 'json_key' => 'patch-style',        'internal_key' => '_seat_patch_applied'],
    ];

    $missing = [];
    foreach ($seatOpsMeta as $opKey => $meta) {
        $jsonKey  = $meta['json_key'];
        $required = false;
        if ($meta['required_when'] === 'exists_in_json') {
            $required = array_key_exists($jsonKey, $extOptArr);
        } elseif ($meta['required_when'] === 'filled') {
            $val = $extOptArr[$jsonKey] ?? null;
            $required = ($val !== null && $val !== '' && $val !== [] && $val !== 'none');
        }
        $confirmedValue = $internalOptArr[$meta['internal_key']] ?? ($confirmed[$opKey] ?? ($extOptArr[$jsonKey] ?? null));
        $normalizedConfirmedValue = trim(mb_strtolower((string)$confirmedValue, 'UTF-8'));
        $isConfirmed = $confirmedValue === true
            || in_array($normalizedConfirmedValue, ['1', 'true', 'yes'], true);

        if ($required && !$isConfirmed) {
            $missing[] = $meta['code'];
        }
    }

    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => '⚠️ Položku nemožno označiť ako Ready. Najskôr potvrď: ' . implode(', ', $missing)
        ]);
        exit;
    }
    }
}

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
$trafficStmt = $conn->prepare("SELECT traffic_summary_json, status FROM orders WHERE id = ? LIMIT 1");
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

$orderStatus = strtoupper((string)($trafficRow['status'] ?? ''));

echo json_encode([
    'success'         => true,
    'order_id'        => $orderId,
    'traffic_summary' => $trafficSummary,
    'order_status'    => $orderStatus,
]);
