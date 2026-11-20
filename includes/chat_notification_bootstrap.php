<?php
require_once __DIR__ . '/chat_notification_helpers.php';
$chatNotificationConfig = [
    'userId' => (int)($_SESSION['user_id'] ?? 0),
    'csrfToken' => chat_notification_csrf_token(),
    'preferences' => chat_notification_preferences($conn, (int)($_SESSION['user_id'] ?? 0))
];
?>
<script>
window.darkScrubChatNotificationConfig = <?= json_encode($chatNotificationConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="scripts/chat/notifications.js?v=20261120-1"></script>
