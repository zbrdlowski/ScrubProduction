<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$userId = (int)$_SESSION['user_id'];
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

$sql = "
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
        p.description AS department_name,
        dm.thread_id,
        dm.last_message_id,
        dm.last_message_at,
        dm.last_message_text,
        COALESCE(dm.unread_count, 0) AS unread_count
    FROM employees e
    LEFT JOIN position p
        ON p.id = e.position_id
    LEFT JOIN (
        SELECT
            other.user_id AS other_user_id,
            self.thread_id,
            (
                SELECT cm1.id
                FROM chat_messages cm1
                WHERE cm1.thread_id = self.thread_id
                ORDER BY cm1.id DESC
                LIMIT 1
            ) AS last_message_id,
            (
                SELECT cm2.created_at
                FROM chat_messages cm2
                WHERE cm2.thread_id = self.thread_id
                ORDER BY cm2.id DESC
                LIMIT 1
            ) AS last_message_at,
            (
                SELECT cm3.message_text
                FROM chat_messages cm3
                WHERE cm3.thread_id = self.thread_id
                ORDER BY cm3.id DESC
                LIMIT 1
            ) AS last_message_text,
            (
                SELECT COUNT(*)
                FROM chat_messages cm4
                WHERE cm4.thread_id = self.thread_id
                  AND cm4.sender_id != ?
                  AND (
                        self.last_read_message_id IS NULL
                        OR cm4.id > self.last_read_message_id
                  )
            ) AS unread_count
        FROM chat_thread_members self
        INNER JOIN chat_thread_members other
            ON other.thread_id = self.thread_id
           AND other.user_id != self.user_id
        INNER JOIN chat_threads ct
            ON ct.id = self.thread_id
           AND ct.thread_type = 'dm'
        WHERE self.user_id = ?
          AND (
                SELECT COUNT(*)
                FROM chat_thread_members x
                WHERE x.thread_id = self.thread_id
          ) = 2
    ) dm
        ON dm.other_user_id = e.id
    WHERE e.active = 'Active'
      AND e.id != ?
";

$params = [$userId, $userId, $userId];
$types = "iii";

if ($search !== '') {
    $sql .= " AND (
        e.firstname LIKE ?
        OR e.lastname LIKE ?
        OR CONCAT(e.firstname, ' ', e.lastname) LIKE ?
        OR e.username LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$sql .= " ORDER BY
    CASE WHEN COALESCE(dm.unread_count, 0) > 0 THEN 0 ELSE 1 END ASC,
    COALESCE(dm.last_message_at, '1970-01-01 00:00:00') DESC,
    e.firstname ASC,
    e.lastname ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    chat_json([
        'status' => 'error',
        'message' => 'Prepare failed: ' . $conn->error
    ], 500);
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    chat_json([
        'status' => 'error',
        'message' => 'Execute failed: ' . $stmt->error
    ], 500);
}

$result = $stmt->get_result();
$contacts = [];

while ($row = $result->fetch_assoc()) {
    $meta = chat_online_status_meta((int)$row['online_status']);

    $contacts[] = [
        'id' => (int)$row['id'],
        'employee_id' => $row['employee_id'],
        'name' => trim($row['firstname'] . ' ' . $row['lastname']),
        'firstname' => $row['firstname'],
        'lastname' => $row['lastname'],
        'username' => $row['username'] ?? '',
        'photo' => $row['photo'] ?? '',
        'department_name' => $row['department_name'] ?? '',
        'permission' => (int)$row['permission'],
        'online_status' => (int)$row['online_status'],
        'status_label' => $meta['label'],
        'status_icon' => $meta['icon'],
        'status_bg' => $meta['bg'],
        'thread_id' => (int)($row['thread_id'] ?? 0),
        'last_message_id' => (int)($row['last_message_id'] ?? 0),
        'last_message_at' => $row['last_message_at'] ?? null,
        'last_message_text' => $row['last_message_text'] ?? '',
        'unread_count' => (int)($row['unread_count'] ?? 0)
    ];
}

$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'contacts' => $contacts
], JSON_UNESCAPED_UNICODE);
?>
