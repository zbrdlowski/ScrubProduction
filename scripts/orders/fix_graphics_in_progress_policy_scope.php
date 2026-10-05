<?php
declare(strict_types=1);

/**
 * One-off production fix:
 * - Restores the allowed overall-status scope for "Graphics - In Progress".
 * - Recalculates NEW orders whose graphics items are already in a started state.
 *
 * Usage:
 *   Browser: upload to scripts/orders/ and open this file while logged in as admin.
 *   CLI:     php scripts/orders/fix_graphics_in_progress_policy_scope.php
 *
 * Safe to run more than once.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/orders_workflow_helpers.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        exit("Unauthorized - prihlas sa najprv do administracie.\n");
    }
    auth_require('orders.admin', "No permission for this Orders admin action.\n");
}

function fixGraphicsPolicyFail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    if (PHP_SAPI !== 'cli') {
        echo $message . "\n";
    }
    exit(1);
}

function fixGraphicsPolicyEnsureAllowedScopeTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS status_workflow_rule_allowed_order_statuses (
            id INT(11) NOT NULL AUTO_INCREMENT,
            rule_id INT(11) NOT NULL,
            order_status_code VARCHAR(32) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_status_workflow_rule_allowed_status (rule_id, order_status_code),
            KEY idx_status_workflow_rule_allowed_rule (rule_id),
            CONSTRAINT fk_status_workflow_rule_allowed_rule
                FOREIGN KEY (rule_id) REFERENCES status_workflow_rules (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    if ($conn->error !== '') {
        fixGraphicsPolicyFail('Could not ensure allowed scope table: ' . $conn->error);
    }
}

fixGraphicsPolicyEnsureAllowedScopeTable($conn);

$allowedStatuses = [
    'NEW',
    'IN_PROGRESS',
    'READY_TO_SHIP',
    'READY_TO_INVOICE',
    'INFO_REQUIRED',
    'INFO_REQUESTED',
    'COMMUNICATION',
    'HOLD',
    'DELAY',
    'PLASTICS_IN_STOCK',
];

$graphicsInProgressStatuses = [
    'RTP_AD_CHANGES',
    'RTP_READY',
    'RIP',
    'PRINTED',
    'CUT',
    'PRODUCED',
    'DRAFT_AD_CHANGES',
    'DRAFT_READY',
    'DRAFT_SENT',
    'HO_RIP',
    'REPRINT',
    'BARTOS_PRODUCTION',
];

$ruleStmt = $conn->prepare("
    SELECT id
    FROM status_workflow_rules
    WHERE name = 'Graphics - In Progress'
      AND result_order_status_code = 'IN_PROGRESS'
    LIMIT 1
");
if (!$ruleStmt) {
    fixGraphicsPolicyFail('Could not prepare rule lookup: ' . $conn->error);
}

$ruleStmt->execute();
$ruleRow = $ruleStmt->get_result()->fetch_assoc();
$ruleStmt->close();

if (!$ruleRow) {
    fixGraphicsPolicyFail('Policy not found: Graphics - In Progress');
}

$ruleId = (int) $ruleRow['id'];

$allowStmt = $conn->prepare("
    INSERT IGNORE INTO status_workflow_rule_allowed_order_statuses
        (rule_id, order_status_code)
    VALUES (?, ?)
");
if (!$allowStmt) {
    fixGraphicsPolicyFail('Could not prepare allowed scope insert: ' . $conn->error);
}

$insertedScopes = 0;
foreach ($allowedStatuses as $statusCode) {
    $allowStmt->bind_param('is', $ruleId, $statusCode);
    $allowStmt->execute();
    $insertedScopes += max(0, (int) $allowStmt->affected_rows);
}
$allowStmt->close();

$escapedGraphicsStatuses = array_map(static function (string $statusCode) use ($conn): string {
    return "'" . $conn->real_escape_string($statusCode) . "'";
}, $graphicsInProgressStatuses);
$graphicsStatusSql = implode(',', $escapedGraphicsStatuses);

$orderResult = $conn->query("
    SELECT DISTINCT
        o.id,
        o.order_number,
        o.status AS old_status
    FROM orders o
    JOIN order_items oi
      ON oi.order_id = o.id
     AND oi.deleted_at IS NULL
     AND UPPER(TRIM(oi.item_type_code)) = 'G'
    WHERE UPPER(TRIM(COALESCE(o.status, ''))) = 'NEW'
      AND UPPER(TRIM(COALESCE(oi.status, ''))) IN ($graphicsStatusSql)
    ORDER BY o.id ASC
");
if (!$orderResult instanceof mysqli_result) {
    fixGraphicsPolicyFail('Could not select affected orders: ' . $conn->error);
}

$orders = [];
while ($row = $orderResult->fetch_assoc()) {
    $orders[(int) $row['id']] = $row;
}
$orderResult->free();

foreach (array_keys($orders) as $orderId) {
    recalculateOrderWorkflow($conn, $orderId);
}

$changedOrders = 0;
echo "Graphics - In Progress rule ID: {$ruleId}\n";
echo "Inserted missing allowed scopes: {$insertedScopes}\n";
echo "Affected NEW graphics orders before recalculation: " . count($orders) . "\n";

foreach ($orders as $orderId => $row) {
    $statusAfter = '';
    $statusStmt = $conn->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1");
    if (!$statusStmt) {
        fixGraphicsPolicyFail('Could not prepare status check: ' . $conn->error);
    }

    $statusStmt->bind_param('i', $orderId);
    $statusStmt->execute();
    $statusStmt->bind_result($statusAfter);
    $statusStmt->fetch();
    $statusStmt->close();

    $oldStatus = strtoupper(trim((string) $row['old_status']));
    $newStatus = strtoupper(trim((string) $statusAfter));
    if ($newStatus !== $oldStatus) {
        $changedOrders++;
    }

    $orderNumber = trim((string) ($row['order_number'] ?? ''));
    echo "#{$orderId}";
    if ($orderNumber !== '') {
        echo " {$orderNumber}";
    }
    echo ": {$oldStatus} -> {$newStatus}\n";
}

$remainingResult = $conn->query("
    SELECT COUNT(DISTINCT o.id) AS remaining
    FROM orders o
    JOIN order_items oi
      ON oi.order_id = o.id
     AND oi.deleted_at IS NULL
     AND UPPER(TRIM(oi.item_type_code)) = 'G'
    WHERE UPPER(TRIM(COALESCE(o.status, ''))) = 'NEW'
      AND UPPER(TRIM(COALESCE(oi.status, ''))) IN ($graphicsStatusSql)
");
$remainingRow = $remainingResult instanceof mysqli_result ? $remainingResult->fetch_assoc() : ['remaining' => 'unknown'];
if ($remainingResult instanceof mysqli_result) {
    $remainingResult->free();
}

echo "Changed orders: {$changedOrders}\n";
echo "Remaining NEW orders with started graphics: " . (string) ($remainingRow['remaining'] ?? 'unknown') . "\n";
echo "Done.\n";