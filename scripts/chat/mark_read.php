<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();
chat_require_post();

$currentUserId = (int)$_SESSION['user_id'];
$threadId = chat_post_int('thread_id');
$lastMessageId = chat_post_int('last_message_id');

if ($threadId <= 0 || $lastMessageId <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing thread_id or last_message_id'
    ], 422);
}

if (!chat_user_in_thread($conn, $threadId, $currentUserId)) {
    chat_json([
        'status' => 'error',
        'message' => 'Access denied'
    ], 403);
}

$messageStmt = $conn->prepare('SELECT id FROM chat_messages WHERE id = ? AND thread_id = ?');
$messageStmt->bind_param('ii', $lastMessageId, $threadId);
$messageStmt->execute();
$messageExists = $messageStmt->get_result()->fetch_assoc();
$messageStmt->close();
if (!$messageExists) {
    chat_json(['status' => 'error', 'message' => 'Message does not belong to this conversation'], 422);
}

$stmt = $conn->prepare("
    UPDATE chat_thread_members
    SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?)
    WHERE thread_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param("iii", $lastMessageId, $threadId, $currentUserId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    chat_json([
        'status' => 'error',
        'message' => $error
    ], 500);
}

$stmt->close();

chat_json([
    'status' => 'success',
    'thread_id' => $threadId,
    'last_read_message_id' => $lastMessageId
]);
?>