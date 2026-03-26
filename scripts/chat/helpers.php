<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/conn.php';

if (!isset($conn)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection variable $conn was not found in includes/conn.php'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection is not mysqli'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_json($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        chat_json([
            'status' => 'error',
            'message' => 'Unauthorized'
        ], 401);
    }
}

function chat_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        chat_json([
            'status' => 'error',
            'message' => 'Invalid request method'
        ], 405);
    }
}

function chat_post_int(string $key): int
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}

function chat_post_text(string $key): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

function chat_user_in_thread(mysqli $conn, int $threadId, int $userId): bool
{
    $stmt = $conn->prepare("
        SELECT id
        FROM chat_thread_members
        WHERE thread_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $threadId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function chat_find_dm_thread(mysqli $conn, int $userA, int $userB): int
{
    $stmt = $conn->prepare("
        SELECT t.id
        FROM chat_threads t
        INNER JOIN chat_thread_members m1
            ON m1.thread_id = t.id AND m1.user_id = ?
        INNER JOIN chat_thread_members m2
            ON m2.thread_id = t.id AND m2.user_id = ?
        WHERE t.thread_type = 'dm'
          AND (
              SELECT COUNT(*)
              FROM chat_thread_members x
              WHERE x.thread_id = t.id
          ) = 2
        LIMIT 1
    ");
    $stmt->bind_param("ii", $userA, $userB);
    $stmt->execute();
    $result = $stmt->get_result();

    $threadId = 0;
    if ($result && $row = $result->fetch_assoc()) {
        $threadId = (int)$row['id'];
    }

    $stmt->close();
    return $threadId;
}

function chat_create_dm_thread(mysqli $conn, int $createdBy, int $userA, int $userB): int
{
    $stmt = $conn->prepare("
        INSERT INTO chat_threads (thread_type, title, created_by, created_at, updated_at, is_active)
        VALUES ('dm', NULL, ?, NOW(), NOW(), 1)
    ");
    $stmt->bind_param("i", $createdBy);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception("Failed to create thread: " . $error);
    }

    $threadId = (int)$conn->insert_id;
    $stmt->close();

    $stmtMember = $conn->prepare("
        INSERT INTO chat_thread_members (thread_id, user_id, joined_at, last_read_message_id, is_muted, is_hidden)
        VALUES (?, ?, NOW(), NULL, 0, 0)
    ");

    foreach ([$userA, $userB] as $uid) {
        $stmtMember->bind_param("ii", $threadId, $uid);
        if (!$stmtMember->execute()) {
            $error = $stmtMember->error;
            $stmtMember->close();
            throw new Exception("Failed to create thread member: " . $error);
        }
    }

    $stmtMember->close();

    return $threadId;
}
?>