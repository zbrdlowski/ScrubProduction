<?php
function chat_notification_defaults(): array
{
    return ['toast_mode' => 'persistent', 'sound_enabled' => true, 'revision' => 0];
}

function chat_notification_preferences(mysqli $conn, int $userId): array
{
    try {
        $stmt = $conn->prepare('SELECT toast_mode, sound_enabled, revision FROM chat_notification_preferences WHERE user_id = ?');
        if (!$stmt) {
            if ($conn->errno === 1146) return chat_notification_defaults();
            throw new RuntimeException('Cannot load notification preferences.');
        }
    } catch (mysqli_sql_exception $error) {
        // Keep existing notifications working while the migration is being deployed.
        if ($error->getCode() === 1146) return chat_notification_defaults();
        throw $error;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) throw new RuntimeException('Cannot load notification preferences.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return chat_notification_defaults();
    return [
        'toast_mode' => $row['toast_mode'],
        'sound_enabled' => (bool)$row['sound_enabled'],
        'revision' => (int)$row['revision']
    ];
}

function chat_notification_validate_preferences(array $input): array
{
    $mode = $input['toast_mode'] ?? null;
    $sound = $input['sound_enabled'] ?? null;
    if (!in_array($mode, ['persistent', 'brief', 'badge'], true)
        || !in_array($sound, ['0', '1', 0, 1], true)) {
        throw new InvalidArgumentException('Neplatné nastavenie upozornení.');
    }
    return ['toast_mode' => $mode, 'sound_enabled' => (int)$sound];
}

function chat_notification_save_preferences(mysqli $conn, int $userId, array $input): array
{
    if ($userId <= 0) throw new InvalidArgumentException('Neplatný používateľ.');
    $preferences = chat_notification_validate_preferences($input);
    $stmt = $conn->prepare("INSERT INTO chat_notification_preferences (user_id, toast_mode, sound_enabled)
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE toast_mode = VALUES(toast_mode),
        sound_enabled = VALUES(sound_enabled), revision = revision + 1");
    if (!$stmt) throw new RuntimeException('Cannot save notification preferences.');
    $stmt->bind_param('isi', $userId, $preferences['toast_mode'], $preferences['sound_enabled']);
    if (!$stmt->execute()) throw new RuntimeException('Cannot save notification preferences.');
    $stmt->close();
    return chat_notification_preferences($conn, $userId);
}

function chat_notification_read_positions(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare('SELECT thread_id, last_read_message_id FROM chat_thread_members WHERE user_id = ?');
    if (!$stmt) throw new RuntimeException('Cannot load chat read positions.');
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) throw new RuntimeException('Cannot load chat read positions.');
    $result = $stmt->get_result();
    $positions = [];
    while ($row = $result->fetch_assoc()) {
        $positions[] = ['thread_id' => (int)$row['thread_id'], 'last_read_message_id' => (int)$row['last_read_message_id']];
    }
    $stmt->close();
    return $positions;
}

function chat_notification_csrf_token(): string
{
    if (empty($_SESSION['chat_notification_csrf'])) {
        $_SESSION['chat_notification_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['chat_notification_csrf'];
}

function chat_notification_valid_csrf($token): bool
{
    return is_string($token) && !empty($_SESSION['chat_notification_csrf'])
        && hash_equals($_SESSION['chat_notification_csrf'], $token);
}
