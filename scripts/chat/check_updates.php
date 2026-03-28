<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);

$sql = "
    SELECT
        ct.id AS thread_id,
        ct.thread_type,
        MAX(cm.id) AS last_message_id,
        (
            SELECT cm2.sender_id
            FROM chat_messages cm2
            WHERE cm2.thread_id = ct.id
              AND cm2.deleted_at IS NULL
            ORDER BY cm2.id DESC
            LIMIT 1
        ) AS last_sender_id,
        (
            SELECT CONCAT(e.firstname, ' ', e.lastname)
            FROM chat_messages cm3
            LEFT JOIN employees e
                ON e.id = cm3.sender_id
            WHERE cm3.thread_id = ct.id
              AND cm3.deleted_at IS NULL
            ORDER BY cm3.id DESC
            LIMIT 1
        ) AS sender_name,
        (
            SELECT e.photo
            FROM chat_messages cm3
            LEFT JOIN employees e
                ON e.id = cm3.sender_id
            WHERE cm3.thread_id = ct.id
              AND cm3.deleted_at IS NULL
            ORDER BY cm3.id DESC
            LIMIT 1
        ) AS sender_photo,
        (
            SELECT cm5.message_text
            FROM chat_messages cm5
            WHERE cm5.thread_id = ct.id
              AND cm5.deleted_at IS NULL
            ORDER BY cm5.id DESC
            LIMIT 1
        ) AS last_message_text,
        (
            SELECT COUNT(*)
            FROM chat_messages cm4
            LEFT JOIN chat_thread_members ctmr
                ON ctmr.thread_id = cm4.thread_id
               AND ctmr.user_id = ?
            WHERE cm4.thread_id = ct.id
              AND cm4.deleted_at IS NULL
              AND cm4.sender_id != ?
              AND (
                    ctmr.last_read_message_id IS NULL
                    OR cm4.id > ctmr.last_read_message_id
                  )
        ) AS unread_count
    FROM chat_thread_members ctp
    INNER JOIN chat_threads ct
        ON ct.id = ctp.thread_id
       AND ct.is_active = 1
    LEFT JOIN chat_messages cm
        ON cm.thread_id = ct.id
       AND cm.deleted_at IS NULL
    WHERE ctp.user_id = ?
    GROUP BY ct.id, ct.thread_type
    ORDER BY last_message_id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmt->bind_param("iii", $userId, $userId, $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$result = $stmt->get_result();
$threads = [];

while ($row = $result->fetch_assoc()) {
    $threads[] = [
        'thread_id' => (int)($row['thread_id'] ?? 0),
        'thread_type' => $row['thread_type'] ?? 'dm',
        'last_message_id' => (int)($row['last_message_id'] ?? 0),
        'last_sender_id' => (int)($row['last_sender_id'] ?? 0),
        'sender_name' => $row['sender_name'] ?? '',
        'sender_photo' => $row['sender_photo'] ?? '',
        'last_message_text' => $row['last_message_text'] ?? '',
        'unread_count' => (int)($row['unread_count'] ?? 0)
    ];
}

$stmt->close();

chat_json([
    'status' => 'success',
    'threads' => $threads
]);
?>