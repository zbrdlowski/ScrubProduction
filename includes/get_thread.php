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

function chat_online_status_meta($statusInt): array
{
    switch ((int)$statusInt) {
        case 1:
            return ['label' => 'At work', 'icon' => 'fa-briefcase', 'bg' => 'bg-success'];
        case 2:
            return ['label' => 'At home', 'icon' => 'fa-house-user', 'bg' => 'bg-danger'];
        case 3:
            return ['label' => 'Break', 'icon' => 'fa-smoking', 'bg' => 'bg-warning'];
        case 4:
            return ['label' => 'Lunch', 'icon' => 'fa-utensils', 'bg' => 'bg-info'];
        default:
            return ['label' => 'Unknown', 'icon' => 'fa-question', 'bg' => 'bg-secondary'];
    }
}

$stmt = $conn->prepare("
    SELECT 
        t.id,
        t.thread_type,
        t.title
    FROM chat_threads t
    WHERE t.id = ?
    LIMIT 1
");

if (!$stmt) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmt->bind_param("i", $threadId);

if (!$stmt->execute()) {
    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $stmt->error
    ], 500);
}

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
            e.online_status,
            p.description AS department_name
        FROM chat_thread_members tm
        INNER JOIN employees e ON e.id = tm.user_id
        LEFT JOIN position p ON p.id = e.position_id
        WHERE tm.thread_id = ?
          AND tm.user_id != ?
        LIMIT 1
    ");

    if (!$stmt) {
        chat_json([
            'status' => 'error',
            'message' => 'Prepare failed: ' . $conn->error
        ], 500);
    }

    $stmt->bind_param("ii", $threadId, $currentUserId);

    if (!$stmt->execute()) {
        chat_json([
            'status' => 'error',
            'message' => 'Execute failed: ' . $stmt->error
        ], 500);
    }

    $result = $stmt->get_result();
    $otherUser = $result->fetch_assoc();
    $stmt->close();

    if ($otherUser) {
        $meta = chat_online_status_meta((int)$otherUser['online_status']);

        chat_json([
            'status' => 'success',
            'thread' => [
                'id' => (int)$thread['id'],
                'thread_type' => $thread['thread_type'],
                'title' => trim(($otherUser['firstname'] ?? '') . ' ' . ($otherUser['lastname'] ?? '')),
                'other_user' => [
                    'id' => (int)$otherUser['id'],
                    'name' => trim(($otherUser['firstname'] ?? '') . ' ' . ($otherUser['lastname'] ?? '')),
                    'photo' => $otherUser['photo'] ?? '',
                    'department_name' => $otherUser['department_name'] ?? '',
                    'status_label' => $meta['label'],
                    'status_icon' => $meta['icon'],
                    'status_bg' => $meta['bg']
                ]
            ]
        ]);
    }
}

chat_json([
    'status' => 'success',
    'thread' => [
        'id' => (int)$thread['id'],
        'thread_type' => $thread['thread_type'],
        'title' => $thread['title'] ?: 'Konverzácia',
        'other_user' => null
    ]
]);
?>