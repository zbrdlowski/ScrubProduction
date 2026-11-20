<?php
declare(strict_types=1);
session_start(['use_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/chat_notification_helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Connection-local temporary tables ensure these tests cannot change application data.
$schema = file_get_contents(dirname(__DIR__) . '/db/chat_notification_preferences.sql');
$conn->query(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $schema));
$conn->query("CREATE TEMPORARY TABLE chat_thread_members (
    id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT, user_id INT, last_read_message_id INT NULL
) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE chat_threads (
    id INT PRIMARY KEY, thread_type VARCHAR(30), is_active TINYINT DEFAULT 1
) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE employees (
    id INT PRIMARY KEY, firstname VARCHAR(60), lastname VARCHAR(60), photo VARCHAR(255)
) ENGINE=InnoDB");
$conn->query("CREATE TEMPORARY TABLE chat_messages (
    id INT PRIMARY KEY, thread_id INT, sender_id INT, message_text TEXT,
    message_type VARCHAR(30), created_at DATETIME, deleted_at DATETIME NULL
) ENGINE=InnoDB");
$conn->query("INSERT INTO employees VALUES (1, 'Test', 'Recipient', ''), (2, 'Test', 'Sender', '')");
$conn->query("INSERT INTO chat_threads (id, thread_type) VALUES (10, 'dm'), (20, 'announcement'), (30, 'dm')");
$conn->query("INSERT INTO chat_thread_members (thread_id,user_id,last_read_message_id) VALUES (10,1,100),(20,1,0),(30,2,0)");
$conn->query("INSERT INTO chat_messages VALUES
    (100,10,2,'Old','text','2026-11-20 10:00:00',NULL),
    (101,10,2,'Same text','text','2026-11-20 10:01:00',NULL),
    (102,10,2,'Same text','text','2026-11-20 10:01:00',NULL),
    (200,20,2,'<h1>Announcement</h1>','announcement','2026-11-20 10:02:00',NULL),
    (300,30,2,'Other user','text','2026-11-20 10:03:00',NULL)");

function notificationAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
if (($argv[1] ?? '') === '--api') {
    $request = json_decode(stream_get_contents(STDIN), true);
    $endpoints = ['notification_preferences', 'mark_read', 'get_unread_threads', 'check_updates'];
    notificationAssert(in_array($request['endpoint'], $endpoints, true), 'Unknown test endpoint.');
    $_SESSION = ['user_id' => $request['user_id'] ?? 1, 'chat_notification_csrf' => str_repeat('a', 64)];
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'GET';
    $_POST = $request['post'] ?? [];
    if (isset($request['initial_read'])) {
        $initialRead = (int)$request['initial_read'];
        $conn->query("UPDATE chat_thread_members SET last_read_message_id = $initialRead WHERE user_id = 1 AND thread_id = 10");
    }
    register_shutdown_function(function () use ($conn, $request) {
        if (isset($request['expected_read'])) {
            $row = $conn->query('SELECT last_read_message_id FROM chat_thread_members WHERE user_id = 1 AND thread_id = 10')->fetch_assoc();
            notificationAssert((int)$row['last_read_message_id'] === $request['expected_read'], 'Read marker must stay monotonic and thread-scoped.');
        }
        if (isset($request['expected_preference_rows'])) {
            $row = $conn->query('SELECT COUNT(*) AS n FROM chat_notification_preferences')->fetch_assoc();
            notificationAssert((int)$row['n'] === $request['expected_preference_rows'], 'Rejected requests must not change preferences.');
        }
        fwrite(STDERR, 'http_status=' . (http_response_code() ?: 200));
    });
    require dirname(__DIR__) . '/scripts/chat/' . $request['endpoint'] . '.php';
    exit;
}

notificationAssert(chat_notification_preferences($conn, 1) === chat_notification_defaults(), 'New users get persistent toasts and sound.');
foreach (['persistent', 'brief', 'badge'] as $mode) {
    foreach ([0, 1] as $sound) {
        $saved = chat_notification_save_preferences($conn, 1, ['toast_mode' => $mode, 'sound_enabled' => $sound]);
        notificationAssert($saved['toast_mode'] === $mode && $saved['sound_enabled'] === (bool)$sound, 'Preference round-trip failed.');
    }
}
notificationAssert(chat_notification_preferences($conn, 1)['revision'] === 6, 'Preference revisions increase on every save.');
notificationAssert(chat_notification_preferences($conn, 2) === chat_notification_defaults(), 'Preferences must be user-scoped.');
foreach ([
    ['toast_mode' => 'other', 'sound_enabled' => '1'],
    ['toast_mode' => ['badge'], 'sound_enabled' => '1'],
    ['toast_mode' => 'brief', 'sound_enabled' => 'yes'],
    ['toast_mode' => 'brief', 'sound_enabled' => []]
] as $invalid) {
    try {
        chat_notification_save_preferences($conn, 1, $invalid);
        throw new RuntimeException('Invalid settings were accepted.');
    } catch (InvalidArgumentException $expected) {}
}
$_SESSION = ['chat_notification_csrf' => str_repeat('a', 64)];
notificationAssert(chat_notification_valid_csrf(str_repeat('a', 64)), 'Valid CSRF token.');
notificationAssert(!chat_notification_valid_csrf('bad') && !chat_notification_valid_csrf([]), 'Reject invalid CSRF tokens.');
notificationAssert(count(chat_notification_read_positions($conn, 1)) === 2, 'Read positions include only user memberships.');
echo "chat_notifications_backend: OK\n";
