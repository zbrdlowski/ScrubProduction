<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$userId = (int)$_SESSION['user_id'];

$sql = "
    SELECT
        ct.id AS thread_id,
        MAX(cm.id) AS last_message_id,
        (
            SELECT cm2.sender_id
            FROM chat_messages cm2
            WHERE cm2.thread_id = ct.id
            ORDER BY cm2.id DESC
            LIMIT 1
        ) AS last_sender_id,
        (
            SELECT CONCAT(e.firstname, ' ', e.lastname)
            FROM chat_messages cm3
            LEFT JOIN employees e ON e.id = cm3.sender_id
            WHERE cm3.thread_id = ct.id
            ORDER BY cm3.id DESC
            LIMIT 1
        ) AS sender_name,
        (
            SELECT COUNT(*)
            FROM chat_messages cm4
            LEFT JOIN chat_reads cr
                ON cr.thread_id = cm4.thread_id
               AND cr.user_id = ?
            WHERE cm4.thread_id = ct.id
              AND cm4.sender_id != ?
              AND (
                    cr.last_read_message_id IS NULL
                    OR cm4.id > cr.last_read_message_id
                  )
        ) AS unread_count
    FROM chat_thread_participants ctp
    INNER JOIN chat_threads ct
        ON ct.id = ctp.thread_id
    LEFT JOIN chat_messages cm
        ON cm.thread_id = ct.id
    WHERE ctp.user_id = ?
    GROUP BY ct.id
    ORDER BY last_message_id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ]);
    exit;
}

$stmt->bind_param("iii", $userId, $userId, $userId);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Execute failed: ' . $stmt->error
    ]);
    exit;
}

$result = $stmt->get_result();
$threads = [];

while ($row = $result->fetch_assoc()) {
    $threads[] = [
        'thread_id' => (int)($row['thread_id'] ?? 0),
        'last_message_id' => (int)($row['last_message_id'] ?? 0),
        'last_sender_id' => (int)($row['last_sender_id'] ?? 0),
        'sender_name' => $row['sender_name'] ?? '',
        'unread_count' => (int)($row['unread_count'] ?? 0)
    ];
}

$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'threads' => $threads
], JSON_UNESCAPED_UNICODE);
?>