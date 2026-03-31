<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

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

$sqlUsers = "SELECT
        e.id,
        e.employee_id,
        e.firstname,
        e.lastname,
        e.username,
        e.photo,
        e.position_id,
        e.permission,
        e.active,
        e.chat,
        e.online_status,
        p.description AS department_name,
        dm.thread_id,
        tm.last_read_message_id,
        lm.id AS last_message_id,
        lm.created_at AS last_message_at,
        (
            SELECT COUNT(*)
            FROM chat_messages cmu
            WHERE cmu.thread_id = dm.thread_id
              AND cmu.deleted_at IS NULL
              AND cmu.sender_id != ?
              AND (
                    tm.last_read_message_id IS NULL
                    OR cmu.id > tm.last_read_message_id
                  )
        ) AS unread_count
    FROM employees e
    LEFT JOIN position p
        ON p.id = e.position_id
    LEFT JOIN (
        SELECT
            t.id AS thread_id,
            CASE
                WHEN m1.user_id = ? THEN m2.user_id
                ELSE m1.user_id
            END AS other_user_id
        FROM chat_threads t
        INNER JOIN chat_thread_members m1
            ON m1.thread_id = t.id
        INNER JOIN chat_thread_members m2
            ON m2.thread_id = t.id
           AND m2.user_id != m1.user_id
        WHERE t.thread_type = 'dm'
          AND m1.user_id = ?
    ) dm
        ON dm.other_user_id = e.id
    LEFT JOIN chat_thread_members tm
        ON tm.thread_id = dm.thread_id
       AND tm.user_id = ?
    LEFT JOIN chat_messages lm
        ON lm.id = (
            SELECT MAX(cm.id)
            FROM chat_messages cm
            WHERE cm.thread_id = dm.thread_id
              AND cm.deleted_at IS NULL
        )
    WHERE e.chat = 'yes'
      AND e.id != ?
";

$params = [$userId, $userId, $userId, $userId, $userId];
$types = "iiiii";

if ($search !== '') {
    $sqlUsers .= "AND (
        e.firstname LIKE ?
        OR e.lastname LIKE ?
        OR CONCAT(e.firstname, ' ', e.lastname) LIKE ?
        OR e.username LIKE ?
        OR p.description LIKE ?
      )
    ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sssss";
}

$sqlUsers .= " ORDER BY e.firstname ASC, e.lastname ASC";

$stmtUsers = $conn->prepare($sqlUsers);

if (!$stmtUsers) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmtUsers->bind_param($types, ...$params);

if (!$stmtUsers->execute()) {
    $error = $stmtUsers->error;
    $stmtUsers->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$resultUsers = $stmtUsers->get_result();
$contacts = [];

while ($row = $resultUsers->fetch_assoc()) {
    $meta = chat_online_status_meta((int)($row['online_status'] ?? 0));

    $contacts[] = [
        'item_type' => 'user',
        'thread_type' => 'dm',
        'id' => (int)$row['id'],
        'employee_id' => $row['employee_id'] ?? '',
        'name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
        'firstname' => $row['firstname'] ?? '',
        'lastname' => $row['lastname'] ?? '',
        'username' => $row['username'] ?? '',
        'photo' => $row['photo'] ?? '',
        'department_name' => $row['department_name'] ?? '',
        'permission' => (int)($row['permission'] ?? 0),
        'online_status' => (int)($row['online_status'] ?? 0),
        'status_label' => $meta['label'],
        'status_icon' => $meta['icon'],
        'status_bg' => $meta['bg'],
        'thread_id' => (int)($row['thread_id'] ?? 0),
        'unread_count' => (int)($row['unread_count'] ?? 0),
        'last_message_at' => $row['last_message_at'] ?? null
    ];
}

$stmtUsers->close();

$sqlAnnouncement = "SELECT
        t.id AS thread_id,
        t.title,
        t.created_by,
        tm.last_read_message_id,
        lm.id AS last_message_id,
        lm.created_at AS last_message_at,
        (
            SELECT COUNT(*)
            FROM chat_messages cmu
            WHERE cmu.thread_id = t.id
              AND cmu.deleted_at IS NULL
              AND (
                    tm.last_read_message_id IS NULL
                    OR cmu.id > tm.last_read_message_id
                  )
        ) AS unread_count
    FROM chat_thread_members tm
    INNER JOIN chat_threads t
        ON t.id = tm.thread_id
       AND t.thread_type = 'announcement'
       AND t.is_active = 1
    LEFT JOIN chat_messages lm
        ON lm.id = (
            SELECT MAX(cm.id)
            FROM chat_messages cm
            WHERE cm.thread_id = t.id
              AND cm.deleted_at IS NULL
        )
    WHERE tm.user_id = ?
    ORDER BY COALESCE(lm.created_at, t.updated_at) DESC, t.id DESC
    LIMIT 1
";

$stmtAnnouncement = $conn->prepare($sqlAnnouncement);

if (!$stmtAnnouncement) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmtAnnouncement->bind_param("i", $userId);

if (!$stmtAnnouncement->execute()) {
    $error = $stmtAnnouncement->error;
    $stmtAnnouncement->close();

    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $error
    ], 500);
}

$resultAnnouncement = $stmtAnnouncement->get_result();
$row = $resultAnnouncement ? $resultAnnouncement->fetch_assoc() : null;

if ($row) {
    $threadId = (int)($row['thread_id'] ?? 0);
    $contacts[] = [
        'item_type' => 'announcement',
        'thread_type' => 'announcement',
        'id' => -$threadId,
        'employee_id' => '',
        'name' => trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : 'Hromadné správy',
        'firstname' => '',
        'lastname' => '',
        'username' => '',
        'photo' => '',
        'department_name' => '',
        'permission' => 0,
        'online_status' => 0,
        'status_label' => 'Announcement',
        'status_icon' => 'fa-bullhorn',
        'status_bg' => 'bg-maroon',
        'thread_id' => $threadId,
        'unread_count' => (int)($row['unread_count'] ?? 0),
        'last_message_at' => $row['last_message_at'] ?? null
    ];
}

$stmtAnnouncement->close();

chat_json([
    'status' => 'success',
    'contacts' => $contacts
]);
?>