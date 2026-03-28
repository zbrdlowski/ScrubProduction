<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
$threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;

if ($threadId <= 0) {
    chat_json([
        'status' => 'error',
        'message' => 'Missing thread_id'
    ], 422);
}

if (!chat_user_in_thread($conn, $threadId, $userId)) {
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

$stmtThread = $conn->prepare("
    SELECT
        ct.id,
        ct.title,
        ct.thread_type,
        ct.created_by
    FROM chat_threads ct
    WHERE ct.id = ?
    LIMIT 1
");

if (!$stmtThread) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare thread failed: ' . $conn->error
    ], 500);
}

$stmtThread->bind_param("i", $threadId);

if (!$stmtThread->execute()) {
    $error = $stmtThread->error;
    $stmtThread->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$resThread = $stmtThread->get_result();
$threadRow = $resThread ? $resThread->fetch_assoc() : null;
$stmtThread->close();

if (!$threadRow) {
    chat_json([
        'status' => 'error',
        'message' => 'Thread not found'
    ], 404);
}

$threadType = (string)($threadRow['thread_type'] ?? 'dm');

if ($threadType === 'announcement') {
    chat_json([
        'status' => 'success',
        'thread' => [
            'id' => (int)$threadRow['id'],
            'title' => trim((string)($threadRow['title'] ?? '')) !== '' ? (string)$threadRow['title'] : 'Hromadné správy',
            'thread_type' => 'announcement',
            'created_by' => (int)($threadRow['created_by'] ?? 0),
            'other_user' => null,
            'can_reply' => false
        ]
    ]);
}

$stmtOther = $conn->prepare("
    SELECT
        e.id,
        e.employee_id,
        e.firstname,
        e.lastname,
        e.username,
        e.photo,
        e.position_id,
        e.permission,
        e.active,
        e.online_status,
        p.description AS department_name
    FROM employees e
    LEFT JOIN position p
        ON p.id = e.position_id
    WHERE e.id = (
        SELECT x.user_id
        FROM (
            SELECT tm.user_id
            FROM chat_thread_members tm
            WHERE tm.thread_id = ?
              AND tm.user_id != ?
            LIMIT 1
        ) x
    )
    LIMIT 1
");

if (!$stmtOther) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare other user failed: ' . $conn->error
    ], 500);
}

$stmtOther->bind_param("ii", $threadId, $userId);

if (!$stmtOther->execute()) {
    $error = $stmtOther->error;
    $stmtOther->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$resOther = $stmtOther->get_result();
$otherUserRow = $resOther ? $resOther->fetch_assoc() : null;
$stmtOther->close();

$otherUser = null;

if ($otherUserRow) {
    $meta = chat_online_status_meta((int)($otherUserRow['online_status'] ?? 0));

    $otherUser = [
        'id' => (int)$otherUserRow['id'],
        'employee_id' => $otherUserRow['employee_id'] ?? '',
        'name' => trim(($otherUserRow['firstname'] ?? '') . ' ' . ($otherUserRow['lastname'] ?? '')),
        'firstname' => $otherUserRow['firstname'] ?? '',
        'lastname' => $otherUserRow['lastname'] ?? '',
        'username' => $otherUserRow['username'] ?? '',
        'photo' => $otherUserRow['photo'] ?? '',
        'department_name' => $otherUserRow['department_name'] ?? '',
        'permission' => (int)($otherUserRow['permission'] ?? 0),
        'online_status' => (int)($otherUserRow['online_status'] ?? 0),
        'status_label' => $meta['label'],
        'status_icon' => $meta['icon'],
        'status_bg' => $meta['bg']
    ];
}

chat_json([
    'status' => 'success',
    'thread' => [
        'id' => (int)$threadRow['id'],
        'title' => $threadRow['title'] ?? '',
        'thread_type' => 'dm',
        'created_by' => (int)($threadRow['created_by'] ?? 0),
        'other_user' => $otherUser,
        'can_reply' => true
    ]
]);
?>