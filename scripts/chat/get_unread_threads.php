<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$currentUserId = (int)$_SESSION['user_id'];

$sql = "
    SELECT 
        t.id AS thread_id,
        MAX(m.id) AS last_message_id
    FROM chat_thread_members tm
    INNER JOIN chat_threads t ON t.id = tm.thread_id
    INNER JOIN chat_messages m ON m.thread_id = t.id
    WHERE tm.user_id = ?
      AND m.sender_id != ?
      AND m.deleted_at IS NULL
      AND (
            tm.last_read_message_id IS NULL
            OR m.id > tm.last_read_message_id
          )
    GROUP BY t.id
    ORDER BY last_message_id DESC
    LIMIT 5
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $currentUserId, $currentUserId);

if (!$stmt->execute()) {
    chat_json([
        'status' => 'error',
        'message' => $stmt->error
    ], 500);
}

$result = $stmt->get_result();
$threads = [];

while ($row = $result->fetch_assoc()) {
    $threadId = (int)$row['thread_id'];

    $stmt2 = $conn->prepare("
        SELECT 
            e.firstname,
            e.lastname,
            e.photo,
            m.message_text,
            m.created_at
        FROM chat_messages m
        INNER JOIN employees e ON e.id = m.sender_id
        WHERE m.thread_id = ?
          AND m.sender_id != ?
          AND m.deleted_at IS NULL
        ORDER BY m.id DESC
        LIMIT 1
    ");
    $stmt2->bind_param("ii", $threadId, $currentUserId);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $last = $res2->fetch_assoc();
    $stmt2->close();

    if ($last) {
        $threads[] = [
            'thread_id' => $threadId,
            'name' => trim(($last['firstname'] ?? '') . ' ' . ($last['lastname'] ?? '')),
            'photo' => $last['photo'] ?? '',
            'message_text' => $last['message_text'] ?? '',
            'created_at' => $last['created_at'] ?? ''
        ];
    }
}

$stmt->close();

chat_json([
    'status' => 'success',
    'count' => count($threads),
    'threads' => $threads
]);
?>