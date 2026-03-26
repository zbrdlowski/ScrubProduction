<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();
chat_require_post();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$currentUserId = (int)$_SESSION['user_id'];
$otherUserId = chat_post_int('other_user_id');

if ($otherUserId <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing other_user_id'
    ], 422);
}

if ($otherUserId === $currentUserId) {
    chat_json([
        'status' => 'error',
        'message' => 'Cannot start chat with yourself'
    ], 422);
}

/* overíme druhého používateľa */
$stmt = $conn->prepare("
    SELECT id, firstname, lastname, active
    FROM employees
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmt->bind_param("i", $otherUserId);

if (!$stmt->execute()) {
    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $stmt->error
    ], 500);
}

$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    $stmt->close();
    chat_json([
        'status' => 'error',
        'message' => 'User not found'
    ], 404);
}

$otherUser = $result->fetch_assoc();
$stmt->close();

if (($otherUser['active'] ?? '') !== 'Active') {
    chat_json([
        'status' => 'error',
        'message' => 'User is not active'
    ], 422);
}

/* nájdi existujúci DM */
$threadId = chat_find_dm_thread($conn, $currentUserId, $otherUserId);

if ($threadId <= 0) {
    try {
        $threadId = chat_create_dm_thread($conn, $currentUserId, $currentUserId, $otherUserId);
    } catch (Throwable $e) {
        chat_json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
}

chat_json([
    'status' => 'success',
    'thread_id' => $threadId,
    'other_user' => [
        'id' => (int)$otherUser['id'],
        'name' => trim(($otherUser['firstname'] ?? '') . ' ' . ($otherUser['lastname'] ?? ''))
    ]
]);
?>