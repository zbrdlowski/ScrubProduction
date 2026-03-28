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

function chat_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / (1024 * 1024), 1) . ' MB';
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
        e.photo,
        a.id AS attachment_id,
        a.original_name,
        a.mime_type,
        a.extension,
        a.file_size
    FROM chat_messages m
    INNER JOIN employees e
        ON e.id = m.sender_id
    LEFT JOIN chat_attachments a
        ON a.message_id = m.id
       AND a.deleted_at IS NULL
    WHERE m.thread_id = ?
      AND m.deleted_at IS NULL
    ORDER BY m.id DESC
    LIMIT 50
");

if (!$stmt) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmt->bind_param("i", $threadId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$result = $stmt->get_result();
$messages = [];

while ($row = $result->fetch_assoc()) {
    $attachment = null;

    if (!empty($row['attachment_id'])) {
        $attachment = [
            'id' => (int)$row['attachment_id'],
            'original_name' => $row['original_name'] ?? '',
            'mime_type' => $row['mime_type'] ?? '',
            'extension' => $row['extension'] ?? '',
            'file_size' => (int)($row['file_size'] ?? 0),
            'file_size_human' => chat_format_bytes((int)($row['file_size'] ?? 0)),
            'download_url' => 'scripts/chat/download_attachment.php?id=' . (int)$row['attachment_id']
        ];
    }

    $messages[] = [
        'id' => (int)$row['id'],
        'thread_id' => (int)$row['thread_id'],
        'sender_id' => (int)$row['sender_id'],
        'sender_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
        'sender_photo' => $row['photo'] ?? '',
        'message_text' => $row['message_text'] ?? '',
        'message_type' => $row['message_type'] ?? 'text',
        'created_at' => $row['created_at'],
        'is_own' => ((int)$row['sender_id'] === $currentUserId),
        'attachment' => $attachment
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