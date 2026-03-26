<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();
chat_require_post();

$currentUserId = (int)$_SESSION['user_id'];
$threadId = chat_post_int('thread_id');
$messageText = chat_post_text('message_text');

if ($threadId <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing thread_id'
    ], 422);
}

if ($messageText === '') {
    chat_json([
        'status' => 'error',
        'message' => 'Message cannot be empty'
    ], 422);
}

if (mb_strlen($messageText) > 5000) {
    chat_json([
        'status' => 'error',
        'message' => 'Message is too long'
    ], 422);
}

if (!chat_user_in_thread($conn, $threadId, $currentUserId)) {
    chat_json([
        'status' => 'error',
        'message' => 'Access denied'
    ], 403);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        INSERT INTO chat_messages (
            thread_id,
            sender_id,
            message_text,
            message_type,
            created_at,
            edited_at,
            deleted_at
        )
        VALUES (?, ?, ?, 'text', NOW(), NULL, NULL)
    ");
    $stmt->bind_param("iis", $threadId, $currentUserId, $messageText);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Failed to send message: " . $error);
    }

    $messageId = (int)$conn->insert_id;
    $stmt->close();

    $stmtUpdate = $conn->prepare("
        UPDATE chat_threads
        SET updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");
    $stmtUpdate->bind_param("i", $threadId);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    /* sender si môže rovno označiť poslednú vlastnú správu ako prečítanú */
    $stmtRead = $conn->prepare("
        UPDATE chat_thread_members
        SET last_read_message_id = ?
        WHERE thread_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmtRead->bind_param("iii", $messageId, $threadId, $currentUserId);
    $stmtRead->execute();
    $stmtRead->close();

    $conn->commit();

    chat_json([
        'status' => 'success',
        'message_id' => $messageId
    ]);
} catch (Throwable $e) {
    $conn->rollback();

    chat_json([
        'status' => 'error',
        'message' => $e->getMessage()
    ], 500);
}
?>