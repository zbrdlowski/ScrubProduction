<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$currentUserId = (int)$_SESSION['user_id'];

$sql = "
    SELECT COUNT(*) AS unread_count
    FROM chat_messages m
    INNER JOIN chat_thread_members tm
        ON tm.thread_id = m.thread_id
    WHERE tm.user_id = ?
      AND m.sender_id != ?
      AND m.deleted_at IS NULL
      AND (
            tm.last_read_message_id IS NULL
            OR m.id > tm.last_read_message_id
          )
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $currentUserId, $currentUserId);
$stmt->execute();
$result = $stmt->get_result();

$unreadCount = 0;
if ($result && $row = $result->fetch_assoc()) {
    $unreadCount = (int)$row['unread_count'];
}

$stmt->close();

chat_json([
    'status' => 'success',
    'unread_count' => $unreadCount
]);
?>