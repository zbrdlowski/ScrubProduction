<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/orders_pending_contact_helpers.php';

function pendingContactResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !auth_can('orders.view')) {
    pendingContactResponse(['ok' => false, 'error' => 'Sign in with permission to view Orders to see contacts.'], 403);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    pendingContactResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
$input = $method === 'POST' ? $_POST : $_GET;
$orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$orderId) pendingContactResponse(['ok' => false, 'error' => 'Invalid order.'], 422);
if (!pendingContactsSchemaReady($conn)) {
    pendingContactResponse(['ok' => false, 'error' => 'Install or update the pending contacts database migration first.'], 503);
}
if (empty($_SESSION['pending_contact_csrf'])) $_SESSION['pending_contact_csrf'] = bin2hex(random_bytes(32));
try {
    if ($method === 'POST') {
        if (!auth_can('orders.work')) pendingContactResponse(['ok' => false, 'error' => 'You do not have permission to record contacts.'], 403);
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['pending_contact_csrf'], $_POST['csrf'])) {
            pendingContactResponse(['ok' => false, 'error' => 'The form has expired. Please reopen it.'], 403);
        }
        $today = (string) $conn->query('SELECT CURRENT_DATE() AS today')->fetch_assoc()['today'];
        $data = pendingContactValidate($_POST, $today);
        $saved = pendingContactSave($conn, (int) $orderId, $userId, $data);
        pendingContactResponse(['ok' => true, 'contact_id' => $saved['id'], 'replayed' => $saved['replayed']]);
    }

    $sql = pendingContactSql();
    $stmt = $conn->prepare("SELECT o.id, o.status, o.order_number, o.external_order_id,
        {$sql['select']}, CURRENT_DATE() AS today,
        DATE_FORMAT(DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY), '%Y-%m-%d') AS suggested_date,
        COALESCE(NULLIF(cu.email, ''), (SELECT a.email FROM order_addresses a
            WHERE a.order_id = o.id AND a.email <> '' ORDER BY a.id LIMIT 1)) AS customer_email,
        COALESCE(NULLIF(cu.phone, ''), (SELECT a.phone FROM order_addresses a
            WHERE a.order_id = o.id AND a.phone <> '' ORDER BY a.id LIMIT 1)) AS customer_phone
        FROM orders o LEFT JOIN customers cu ON cu.id = o.customer_id {$sql['join']}
        WHERE o.id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) pendingContactResponse(['ok' => false, 'error' => 'Order not found.'], 404);

    $before = filter_var($_GET['before_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($before === false) pendingContactResponse(['ok' => false, 'error' => 'Invalid history page.'], 422);
    $stmt = $conn->prepare("SELECT pc.id, pc.contacted_at, pc.channel, pc.outcome, pc.note, pc.next_contact_at,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.firstname, e.lastname)), ''),
            CONCAT('User #', pc.actor_employee_id)) AS actor_name
        FROM order_pending_contacts pc LEFT JOIN employees e ON e.id = pc.actor_employee_id
        WHERE pc.order_id = ? AND (? = 0 OR pc.id < ?) ORDER BY pc.id DESC LIMIT 51");
    $stmt->bind_param('iii', $orderId, $before, $before);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $hasMore = count($history) > 50;
    if ($hasMore) array_pop($history);
    foreach ($history as &$contact) {
        $contact['channel_label'] = pendingContactChannels()[$contact['channel']] ?? 'Other';
        $contact['outcome_label'] = pendingContactOutcomes()[$contact['outcome']] ?? 'Other';
        $contact['contacted_label'] = pendingContactDate($contact['contacted_at']);
        // Ignore dates recorded by older versions for cancellation requests as well.
        if ($contact['outcome'] === 'cancel_requested') $contact['next_contact_at'] = null;
        $contact['next_label'] = $contact['next_contact_at'] !== null
            ? pendingContactDate($contact['next_contact_at']) : null;
    }
    unset($contact);
    pendingContactResponse([
        'ok' => true, 'order_id' => (int) $orderId,
        'order_label' => $order['order_number'] ?: $order['external_order_id'] ?: (string) $orderId,
        'status' => $order['status'],
        'can_write' => strtoupper(trim($order['status'])) === 'PENDING' && auth_can('orders.work'),
        'csrf' => $_SESSION['pending_contact_csrf'], 'request_token' => bin2hex(random_bytes(16)),
        'base_contact_id' => (int) ($order['pending_contact_id'] ?? 0),
        'suggested_date' => $order['suggested_date'], 'today' => $order['today'],
        'customer_email' => $order['customer_email'], 'customer_phone' => $order['customer_phone'],
        'history' => $history, 'has_more' => $hasMore,
        'next_cursor' => $history ? (int) $history[count($history) - 1]['id'] : 0,
    ]);
} catch (InvalidArgumentException $e) {
    pendingContactResponse(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (DomainException $e) {
    pendingContactResponse(['ok' => false, 'error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('Pending contacts: ' . $e->getMessage());
    pendingContactResponse(['ok' => false, 'error' => 'Unable to process contacts. Please try again.'], 500);
}
