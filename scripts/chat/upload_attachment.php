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

if (!chat_user_in_thread($conn, $threadId, $currentUserId)) {
    chat_json([
        'status' => 'error',
        'message' => 'Access denied'
    ], 403);
}

if (mb_strlen($messageText) > 5000) {
    chat_json([
        'status' => 'error',
        'message' => 'Message is too long'
    ], 422);
}

if (!isset($_FILES['attachment'])) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing attachment'
    ], 422);
}

$file = $_FILES['attachment'];

if (!is_array($file) || !isset($file['error'])) {
    chat_json([
        'status' => 'error',
        'message' => 'Invalid attachment payload'
    ], 422);
}

if ((int)$file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form upload limit',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload'
    ];

    chat_json([
        'status' => 'error',
        'message' => $uploadErrors[(int)$file['error']] ?? 'Upload failed'
    ], 422);
}

if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    chat_json([
        'status' => 'error',
        'message' => 'Invalid uploaded file'
    ], 422);
}

$maxFileSize = 15 * 1024 * 1024; // 15 MB
$fileSize = isset($file['size']) ? (int)$file['size'] : 0;

if ($fileSize <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Uploaded file is empty'
    ], 422);
}

if ($fileSize > $maxFileSize) {
    chat_json([
        'status' => 'error',
        'message' => 'File is too large. Maximum allowed size is 15 MB'
    ], 422);
}

$originalName = isset($file['name']) ? trim((string)$file['name']) : '';
if ($originalName === '') {
    chat_json([
        'status' => 'error',
        'message' => 'Invalid original filename'
    ], 422);
}

$originalName = str_replace(["\0", "\r", "\n"], '', $originalName);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$allowedExtensions = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'webp',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'zip',
    'txt',
    'csv'
];

if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
    chat_json([
        'status' => 'error',
        'message' => 'File type is not allowed'
    ], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string)$finfo->file($file['tmp_name']);

$allowedMimeTypes = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'doc'  => ['application/msword', 'application/octet-stream'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream'
    ],
    'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream'
    ],
    'zip'  => [
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
        'application/octet-stream'
    ],
    'txt'  => ['text/plain', 'application/octet-stream'],
    'csv'  => [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream'
    ]
];

if (!isset($allowedMimeTypes[$extension]) || !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
    chat_json([
        'status' => 'error',
        'message' => 'Detected MIME type is not allowed for this file extension'
    ], 422);
}

function chat_detect_storage_root(): string
{
    $candidates = [
        '/volume1/chat_attachments',
        'E:\\chat_attachments'
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate) && is_readable($candidate) && is_writable($candidate)) {
            return rtrim($candidate, DIRECTORY_SEPARATOR);
        }
    }

    throw new RuntimeException('No writable attachment storage directory found');
}

function chat_slug_filename_base(string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base ?? '');
    $base = trim((string)$base, '._-');

    if ($base === '') {
        $base = 'file';
    }

    return substr($base, 0, 80);
}

$storedAbsolutePath = null;

try {
    $storageRoot = chat_detect_storage_root();
    $threadDir = $storageRoot . DIRECTORY_SEPARATOR . 'thread_' . $threadId;

    if (!is_dir($threadDir)) {
        if (!mkdir($threadDir, 0775, true) && !is_dir($threadDir)) {
            throw new RuntimeException('Failed to create thread attachment directory');
        }
    }

    if (!is_writable($threadDir)) {
        throw new RuntimeException('Attachment directory is not writable');
    }

    $safeBase = chat_slug_filename_base($originalName);
    $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '_' . $safeBase . '.' . $extension;
    $storedAbsolutePath = $threadDir . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $storedAbsolutePath)) {
        throw new RuntimeException('Failed to move uploaded file');
    }

    $conn->begin_transaction();

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
        VALUES (?, ?, ?, 'attachment', NOW(), NULL, NULL)
    ");

    if (!$stmt) {
        throw new RuntimeException('Prepare failed for message insert: ' . $conn->error);
    }

    $stmt->bind_param("iis", $threadId, $currentUserId, $messageText);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Failed to create attachment message: ' . $error);
    }

    $messageId = (int)$conn->insert_id;
    $stmt->close();

    $stmtAttachment = $conn->prepare("
        INSERT INTO chat_attachments (
            message_id,
            thread_id,
            uploaded_by,
            original_name,
            stored_name,
            storage_path,
            mime_type,
            extension,
            file_size,
            created_at,
            deleted_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)
    ");

    if (!$stmtAttachment) {
        throw new RuntimeException('Prepare failed for attachment insert: ' . $conn->error);
    }

    $stmtAttachment->bind_param(
        "iiisssssi",
        $messageId,
        $threadId,
        $currentUserId,
        $originalName,
        $storedName,
        $storedAbsolutePath,
        $mimeType,
        $extension,
        $fileSize
    );

    if (!$stmtAttachment->execute()) {
        $error = $stmtAttachment->error;
        $stmtAttachment->close();
        throw new RuntimeException('Failed to save attachment metadata: ' . $error);
    }

    $attachmentId = (int)$conn->insert_id;
    $stmtAttachment->close();

    $stmtUpdate = $conn->prepare("
        UPDATE chat_threads
        SET updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmtUpdate) {
        throw new RuntimeException('Prepare failed for thread update: ' . $conn->error);
    }

    $stmtUpdate->bind_param("i", $threadId);

    if (!$stmtUpdate->execute()) {
        $error = $stmtUpdate->error;
        $stmtUpdate->close();
        throw new RuntimeException('Failed to update thread timestamp: ' . $error);
    }

    $stmtUpdate->close();

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

    $conn->commit();

    chat_json([
        'status' => 'success',
        'message_id' => $messageId,
        'attachment_id' => $attachmentId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'file_size' => $fileSize,
        'mime_type' => $mimeType,
        'extension' => $extension
    ]);
} catch (Throwable $e) {
    if ($conn->errno === 0) {
        // no-op
    }

    try {
        if ($conn->connect_errno === 0) {
            $conn->rollback();
        }
    } catch (Throwable $rollbackError) {
        // ignore rollback failure
    }

    if ($storedAbsolutePath && is_file($storedAbsolutePath)) {
        @unlink($storedAbsolutePath);
    }

    chat_json([
        'status' => 'error',
        'message' => $e->getMessage()
    ], 500);
}
?>