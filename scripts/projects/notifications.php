<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function projectNotificationResponse(array $data, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) projectNotificationResponse(['error' => 'Please sign in.'], 401);
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/project_notification_helpers.php';
try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if (!in_array($method, ['GET', 'POST'], true)) projectNotificationResponse(['error' => 'Method not allowed.'], 405);
  if ($method === 'POST' && !projectWorkflowValidToken($_POST['csrf_token'] ?? null)) {
    projectNotificationResponse(['error' => 'The form expired. Reload the page.'], 403);
  }
  session_write_close();
  $preferences = projectNotificationPreferences($conn, $userId);
  if ($method === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
    $preferences = projectNotificationSavePreferences($conn, $userId, $_POST);
    projectNotificationResponse(['preferences' => $preferences]);
  }
  if (!projectWorkflowInstalled($conn)) projectNotificationResponse(['items' => [], 'count' => 0, 'available' => false, 'preferences' => $preferences]);
  if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') projectWorkflowMarkRead($conn, $userId, (int)($_POST['id'] ?? 0));
    elseif (in_array($action, ['approve_task', 'reject_task'], true)) projectWorkflowMutate($conn, $userId, $action, $_POST);
    else projectNotificationResponse(['error' => 'Unknown action.'], 422);
  }
  $items = projectWorkflowUnread($conn, $userId);
  projectNotificationResponse(['items' => $items, 'count' => count($items), 'available' => true, 'preferences' => $preferences]);
} catch (DomainException $error) {
  projectNotificationResponse(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
  error_log('Project notifications: ' . $error->getMessage());
  projectNotificationResponse(['error' => 'Could not update project notifications. Please try again.'], 500);
}
