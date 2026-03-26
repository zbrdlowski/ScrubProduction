<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$currentUserId = (int)$_SESSION['user_id'];
$threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;

if ($threadId <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing thread_id'
    ], 422);
}

if (!chat_user_in_thread($conn, $threadId, $currentUserId)) {
    chat_json([
        'status' => 'error',
        'message' => 'Access denied'
    ], 403);
}

$stmt = $conn->prepare("
    SELECT 
        m.id,
        m.thread_id,
        m.sender_id,
        m.message_text,
        m.message_type,
        m.created_at,
        e.firstname,
        e.lastname,
        e.photo
    FROM chat_messages m
    INNER JOIN employees e ON e.id = m.sender_id
    WHERE m.thread_id = ?
      AND m.deleted_at IS NULL
    ORDER BY m.id DESC
    LIMIT 50
");
$stmt->bind_param("i", $threadId);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];

while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => (int)$row['id'],
        'thread_id' => (int)$row['thread_id'],
        'sender_id' => (int)$row['sender_id'],
        'sender_name' => trim($row['firstname'] . ' ' . $row['lastname']),
        'sender_photo' => $row['photo'],
        'message_text' => $row['message_text'],
        'message_type' => $row['message_type'],
        'created_at' => $row['created_at'],
        'is_own' => ((int)$row['sender_id'] === $currentUserId)
    ];
}
$stmt->close();

/* otočíme, aby frontend dostal staršie -> novšie */
$messages = array_reverse($messages);

chat_json([
    'status' => 'success',
    'messages' => $messages
]);
?>