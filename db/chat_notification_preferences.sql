CREATE TABLE IF NOT EXISTS chat_notification_preferences (
    user_id INT NOT NULL PRIMARY KEY,
    toast_mode ENUM('persistent', 'brief', 'badge') NOT NULL DEFAULT 'persistent',
    sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
