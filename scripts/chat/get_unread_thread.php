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
        t.id,
        t.thread_type
    FROM chat_threads t
    WHERE t.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $threadId);
$stmt->execute();
$result = $stmt->get_result();
$thread = $result->fetch_assoc();
$stmt->close();

if (!$thread) {
    chat_json([
        'status' => 'error',
        'message' => 'Thread not found'
    ], 404);
}

if ($thread['thread_type'] === 'dm') {
    $stmt = $conn->prepare("
        SELECT 
            e.id,
            e.firstname,
            e.lastname,
            e.photo,
            p.description AS department_name
        FROM chat_thread_members tm
        INNER JOIN employees e ON e.id = tm.user_id
        LEFT JOIN position p ON p.id = e.position_id
        WHERE tm.thread_id = ?
          AND tm.user_id != ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $threadId, $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $otherUser = $result->fetch_assoc();
    $stmt->close();

    chat_json([
        'status' => 'success',
        'thread' => [
            'id' => (int)$thread['id'],
            'thread_type' => $thread['thread_type'],
            'title' => $otherUser ? trim($otherUser['firstname'] . ' ' . $otherUser['lastname']) : 'Konverzácia',
            'other_user' => $otherUser ? [
                'id' => (int)$otherUser['id'],
                'name' => trim($otherUser['firstname'] . ' ' . $otherUser['lastname']),
                'photo' => $otherUser['photo'] ?? '',
                'department_name' => $otherUser['department_name'] ?? ''
            ] : null
        ]
    ]);
}

chat_json([
    'status' => 'success',
    'thread' => [
        'id' => (int)$thread['id'],
        'thread_type' => $thread['thread_type'],
        'title' => 'Konverzácia'
    ]
]);
?>