<?php
declare(strict_types=1);

/**
 * cd E:\volume_0\darkscrub
 * Removes order_activity rows for orders that are already DELIVERED.
 *
 * Dry run:
 *   php scripts/orders/cleanup_delivered_order_activity.php
 *
 * Execute:
 *   php scripts/orders/cleanup_delivered_order_activity.php --execute
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not found';
    exit(1);
}

$options = getopt('', [
    'execute',
    'batch-size:',
    'max-batches:',
    'older-than-days:',
    'help',
]);

if (array_key_exists('help', $options)) {
    echo "Delivered order activity cleanup\n\n";
    echo "Dry run:\n";
    echo "  php scripts/orders/cleanup_delivered_order_activity.php\n\n";
    echo "Execute:\n";
    echo "  php scripts/orders/cleanup_delivered_order_activity.php --execute\n\n";
    echo "Optional:\n";
    echo "  --batch-size=5000       Rows deleted per batch. Default: 5000, max: 50000\n";
    echo "  --max-batches=20        Stop after this many batches. Default: 0 = no limit\n";
    echo "  --older-than-days=7     Only delete activity older than this many days. Default: 0 = no age filter\n";
    exit(0);
}

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/includes/conn.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$execute = array_key_exists('execute', $options);
$batchSize = normalizePositiveInt($options['batch-size'] ?? 5000, 5000, 50000);
$maxBatches = normalizeNonNegativeInt($options['max-batches'] ?? 0, 0);
$olderThanDays = normalizeNonNegativeInt($options['older-than-days'] ?? 0, 0);

try {
    $startedAt = new DateTimeImmutable('now');
    $before = fetchCleanupCounts($conn, $olderThanDays);

    printf("[%s] Delivered order activity cleanup\n", $startedAt->format('Y-m-d H:i:s'));
    printf("Mode: %s\n", $execute ? 'EXECUTE' : 'DRY RUN');
    printf("Batch size: %d\n", $batchSize);
    if ($maxBatches > 0) {
        printf("Max batches: %d\n", $maxBatches);
    }
    if ($olderThanDays > 0) {
        printf("Age filter: older than %d day(s)\n", $olderThanDays);
    }
    printf("Matching delivered orders: %d\n", $before['orders']);
    printf("Matching activity rows: %d\n", $before['activity_rows']);

    if (!$execute) {
        echo "Dry run only. Add --execute to delete matching rows.\n";
        exit(0);
    }

    $deletedTotal = 0;
    $batchNumber = 0;

    do {
        $deleted = deleteActivityBatch($conn, $batchSize, $olderThanDays);
        $batchNumber++;
        $deletedTotal += $deleted;

        printf("Batch %d deleted %d row(s)\n", $batchNumber, $deleted);

        if ($deleted < $batchSize) {
            break;
        }
        if ($maxBatches > 0 && $batchNumber >= $maxBatches) {
            break;
        }
    } while (true);

    $after = fetchCleanupCounts($conn, $olderThanDays);

    printf("Deleted total: %d\n", $deletedTotal);
    printf("Remaining matching activity rows: %d\n", $after['activity_rows']);
    echo "Done.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

function normalizePositiveInt($value, int $default, int $max): int
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        return $default;
    }
    return min((int) $value, $max);
}

function normalizeNonNegativeInt($value, int $default): int
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false || $value < 0) {
        return $default;
    }
    return (int) $value;
}

function deliveredActivityWhereSql(int $olderThanDays, string $activityAlias = 'oa'): string
{
    $where = "UPPER(TRIM(o.status)) = 'DELIVERED'";
    if ($olderThanDays > 0) {
        $where .= " AND {$activityAlias}.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
    }
    return $where;
}

function fetchCleanupCounts(mysqli $conn, int $olderThanDays): array
{
    $where = deliveredActivityWhereSql($olderThanDays);
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS activity_rows,
            COUNT(DISTINCT oa.order_id) AS orders
        FROM order_activity oa
        INNER JOIN orders o ON o.id = oa.order_id
        WHERE {$where}
    ");

    if ($olderThanDays > 0) {
        $stmt->bind_param('i', $olderThanDays);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'activity_rows' => (int) ($row['activity_rows'] ?? 0),
        'orders' => (int) ($row['orders'] ?? 0),
    ];
}

function deleteActivityBatch(mysqli $conn, int $batchSize, int $olderThanDays): int
{
    $where = deliveredActivityWhereSql($olderThanDays, 'oa2');
    $stmt = $conn->prepare("
        DELETE oa
        FROM order_activity oa
        INNER JOIN (
            SELECT id
            FROM (
                SELECT oa2.id
                FROM order_activity oa2
                INNER JOIN orders o ON o.id = oa2.order_id
                WHERE {$where}
                ORDER BY oa2.id ASC
                LIMIT ?
            ) batch_ids
        ) batch ON batch.id = oa.id
    ");

    if ($olderThanDays > 0) {
        $stmt->bind_param('ii', $olderThanDays, $batchSize);
    } else {
        $stmt->bind_param('i', $batchSize);
    }

    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    return max(0, (int) $deleted);
}
