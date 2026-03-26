<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

header('Content-Type: application/json; charset=utf-8');

$userId   = (int)$_SESSION['user_id'];
$threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;

if ($threadId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing thread_id'
    ], JSON_UNESCAPED_UNICODE);
    exit;
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

/*
 * 1) načítaj thread
 */
$sqlThread = "
    SELECT
        ct.id,
        ct.title
    FROM chat_threads ct
    WHERE ct.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sqlThread);
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Prepare thread failed: ' . $conn->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param("i", $threadId);
$stmt->execute();
$resThread = $stmt->get_result();
$threadRow = $resThread->fetch_assoc();
$stmt->close();

if (!$threadRow) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Thread not found'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * 2) nájdi "other_user" v DM konverzácii
 *
 * Priorita:
 * - najprv posledný iný sender v tomto threade
 * - ak nič nenájde, tak akýkoľvek iný sender v tomto threade
 */
$sqlOtherUser = "
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
    LEFT JOIN position p ON p.id = e.position_id
    WHERE e.id = (
        SELECT x.sender_id
        FROM (
            SELECT cm.sender_id
            FROM chat_messages cm
            WHERE cm.thread_id = ?
              AND cm.sender_id != ?
            ORDER BY cm.id DESC
            LIMIT 1
        ) x
    )
    LIMIT 1
";

$stmt = $conn->prepare($sqlOtherUser);
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Prepare other user failed: ' . $conn->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param("ii", $threadId, $userId);
$stmt->execute();
$resOther = $stmt->get_result();
$otherUserRow = $resOther->fetch_assoc();
$stmt->close();

/*
 * Fallback: ak posledný iný sender neexistuje, zober hociktorého iného sendera v threade
 */
if (!$otherUserRow) {
    $sqlOtherUserFallback = "
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
        LEFT JOIN position p ON p.id = e.position_id
        WHERE e.id = (
            SELECT x.sender_id
            FROM (
                SELECT cm.sender_id
                FROM chat_messages cm
                WHERE cm.thread_id = ?
                  AND cm.sender_id != ?
                GROUP BY cm.sender_id
                ORDER BY MAX(cm.id) DESC
                LIMIT 1
            ) x
        )
        LIMIT 1
    ";

    $stmt = $conn->prepare($sqlOtherUserFallback);
    if ($stmt) {
        $stmt->bind_param("ii", $threadId, $userId);
        $stmt->execute();
        $resOther = $stmt->get_result();
        $otherUserRow = $resOther->fetch_assoc();
        $stmt->close();
    }
}

$otherUser = null;

if ($otherUserRow) {
    $meta = chat_online_status_meta((int)$otherUserRow['online_status']);

    $otherUser = [
        'id'              => (int)$otherUserRow['id'],
        'employee_id'     => $otherUserRow['employee_id'] ?? '',
        'name'            => trim(($otherUserRow['firstname'] ?? '') . ' ' . ($otherUserRow['lastname'] ?? '')),
        'firstname'       => $otherUserRow['firstname'] ?? '',
        'lastname'        => $otherUserRow['lastname'] ?? '',
        'username'        => $otherUserRow['username'] ?? '',
        'photo'           => $otherUserRow['photo'] ?? '',
        'department_name' => $otherUserRow['department_name'] ?? '',
        'permission'      => (int)($otherUserRow['permission'] ?? 0),
        'online_status'   => (int)($otherUserRow['online_status'] ?? 0),
        'status_label'    => $meta['label'],
        'status_icon'     => $meta['icon'],
        'status_bg'       => $meta['bg']
    ];
}

echo json_encode([
    'status' => 'success',
    'thread' => [
        'id'         => (int)$threadRow['id'],
        'title'      => $threadRow['title'] ?? '',
        'other_user' => $otherUser
    ]
], JSON_UNESCAPED_UNICODE);
?>