<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$currentUserId = (int)$_SESSION['user_id'];
$attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($attachmentId <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing attachment id'
    ], 422);
}

$stmt = $conn->prepare("
    SELECT
        a.id,
        a.message_id,
        a.thread_id,
        a.original_name,
        a.stored_name,
        a.storage_path,
        a.mime_type,
        a.extension,
        a.file_size,
        a.deleted_at
    FROM chat_attachments a
    WHERE a.id = ?
    LIMIT 1
");

if (!$stmt) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmt->bind_param("i", $attachmentId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$result = $stmt->get_result();
$attachment = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$attachment) {
    chat_json([
        'status' => 'error',
        'message' => 'Attachment not found'
    ], 404);
}

if (!empty($attachment['deleted_at'])) {
    chat_json([
        'status' => 'error',
        'message' => 'Attachment is deleted'
    ], 404);
}

$threadId = (int)$attachment['thread_id'];

if (!chat_user_in_thread($conn, $threadId, $currentUserId)) {
    chat_json([
        'status' => 'error',
        'message' => 'Access denied'
    ], 403);
}

$filePath = (string)$attachment['storage_path'];

if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
    chat_json([
        'status' => 'error',
        'message' => 'Stored file not found'
    ], 404);
}

$downloadName = (string)$attachment['original_name'];
if ($downloadName === '') {
    $downloadName = (string)$attachment['stored_name'];
}
if ($downloadName === '') {
    $downloadName = 'attachment';
}

$mimeType = (string)$attachment['mime_type'];
if ($mimeType === '') {
    $mimeType = 'application/octet-stream';
}

$fileSize = filesize($filePath);
if ($fileSize === false) {
    $fileSize = 0;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

readfile($filePath);
exit;
?>