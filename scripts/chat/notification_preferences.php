<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../includes/chat_notification_helpers.php';

chat_require_login();
header('Cache-Control: no-store');
$userId = (int)$_SESSION['user_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        chat_json(['status' => 'success', 'preferences' => chat_notification_preferences($conn, $userId)]);
    }
    chat_require_post();
    if (!chat_notification_valid_csrf($_POST['csrf_token'] ?? null)) {
        chat_json(['status' => 'error', 'message' => 'Platnosť formulára vypršala. Obnovte stránku.'], 403);
    }
    $preferences = chat_notification_save_preferences($conn, $userId, $_POST);
    chat_json(['status' => 'success', 'preferences' => $preferences]);
} catch (InvalidArgumentException $error) {
    chat_json(['status' => 'error', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Chat notification preferences: ' . $error->getMessage());
    chat_json(['status' => 'error', 'message' => 'Nastavenia sa nepodarilo uložiť alebo načítať. Skúste to znova.'], 500);
}
