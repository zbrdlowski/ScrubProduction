<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();
chat_require_post();

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentPermission = (int)($_SESSION['permission'] ?? 0);

if ($currentPermission < 500) {
    chat_json([
        'status' => 'error',
        'message' => 'Access denied'
    ], 403);
}

$messageText = chat_post_text('message_text');

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

$recipientIds = $_POST['recipient_ids'] ?? [];

if (!is_array($recipientIds)) {
    chat_json([
        'status' => 'error',
        'message' => 'Invalid recipient list'
    ], 422);
}

$recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), function ($id) use ($currentUserId) {
    return $id > 0 && $id !== $currentUserId;
})));

if (empty($recipientIds)) {
    chat_json([
        'status' => 'error',
        'message' => 'Select at least one recipient'
    ], 422);
}

$placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
$types = str_repeat('i', count($recipientIds));

$sqlRecipients = "
    SELECT id
    FROM employees
    WHERE active = 'Active'
      AND id IN ($placeholders)
";

$stmtRecipients = $conn->prepare($sqlRecipients);

if (!$stmtRecipients) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmtRecipients->bind_param($types, ...$recipientIds);

if (!$stmtRecipients->execute()) {
    $error = $stmtRecipients->error;
    $stmtRecipients->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$resultRecipients = $stmtRecipients->get_result();
$validRecipientIds = [];

while ($row = $resultRecipients->fetch_assoc()) {
    $validRecipientIds[] = (int)$row['id'];
}

$stmtRecipients->close();

$validRecipientIds = array_values(array_unique($validRecipientIds));

if (empty($validRecipientIds)) {
    chat_json([
        'status' => 'error',
        'message' => 'No valid recipients found'
    ], 422);
}

$conn->begin_transaction();

try {
    $threadTitle = 'Hromadná správa';

    $stmtThread = $conn->prepare("
        INSERT INTO chat_threads (
            thread_type,
            title,
            created_by,
            created_at,
            updated_at,
            is_active
        )
        VALUES ('announcement', ?, ?, NOW(), NOW(), 1)
    ");

    if (!$stmtThread) {
        throw new RuntimeException('Prepare failed for thread insert: ' . $conn->error);
    }

    $stmtThread->bind_param("si", $threadTitle, $currentUserId);

    if (!$stmtThread->execute()) {
        $error = $stmtThread->error;
        $stmtThread->close();
        throw new RuntimeException('Failed to create announcement thread: ' . $error);
    }

    $threadId = (int)$conn->insert_id;
    $stmtThread->close();

    $memberIds = array_values(array_unique(array_merge([$currentUserId], $validRecipientIds)));

    $stmtMember = $conn->prepare("
        INSERT INTO chat_thread_members (
            thread_id,
            user_id,
            joined_at,
            last_read_message_id,
            is_muted,
            is_hidden
        )
        VALUES (?, ?, NOW(), NULL, 0, 0)
    ");

    if (!$stmtMember) {
        throw new RuntimeException('Prepare failed for member insert: ' . $conn->error);
    }

    foreach ($memberIds as $memberUserId) {
        $stmtMember->bind_param("ii", $threadId, $memberUserId);

        if (!$stmtMember->execute()) {
            $error = $stmtMember->error;
            $stmtMember->close();
            throw new RuntimeException('Failed to attach recipient to announcement: ' . $error);
        }
    }

    $stmtMember->close();

    $stmtMessage = $conn->prepare("
        INSERT INTO chat_messages (
            thread_id,
            sender_id,
            message_text,
            message_type,
            created_at,
            edited_at,
            deleted_at
        )
        VALUES (?, ?, ?, 'announcement', NOW(), NULL, NULL)
    ");

    if (!$stmtMessage) {
        throw new RuntimeException('Prepare failed for message insert: ' . $conn->error);
    }

    $stmtMessage->bind_param("iis", $threadId, $currentUserId, $messageText);

    if (!$stmtMessage->execute()) {
        $error = $stmtMessage->error;
        $stmtMessage->close();
        throw new RuntimeException('Failed to create announcement message: ' . $error);
    }

    $messageId = (int)$conn->insert_id;
    $stmtMessage->close();

    $stmtRead = $conn->prepare("
        UPDATE chat_thread_members
        SET last_read_message_id = ?
        WHERE thread_id = ? AND user_id = ?
        LIMIT 1
    ");

    if (!$stmtRead) {
        throw new RuntimeException('Prepare failed for sender read marker: ' . $conn->error);
    }

    $stmtRead->bind_param("iii", $messageId, $threadId, $currentUserId);

    if (!$stmtRead->execute()) {
        $error = $stmtRead->error;
        $stmtRead->close();
        throw new RuntimeException('Failed to update sender read marker: ' . $error);
    }

    $stmtRead->close();

    $stmtUpdateThread = $conn->prepare("
        UPDATE chat_threads
        SET updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmtUpdateThread) {
        throw new RuntimeException('Prepare failed for thread update: ' . $conn->error);
    }

    $stmtUpdateThread->bind_param("i", $threadId);

    if (!$stmtUpdateThread->execute()) {
        $error = $stmtUpdateThread->error;
        $stmtUpdateThread->close();
        throw new RuntimeException('Failed to update thread timestamp: ' . $error);
    }

    $stmtUpdateThread->close();

    $conn->commit();

    chat_json([
        'status' => 'success',
        'thread_id' => $threadId,
        'message_id' => $messageId,
        'recipient_count' => count($validRecipientIds)
    ]);
} catch (Throwable $e) {
    $conn->rollback();

    chat_json([
        'status' => 'error',
        'message' => $e->getMessage()
    ], 500);
}
?>