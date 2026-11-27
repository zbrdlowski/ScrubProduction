<?php
require_once __DIR__ . '/project_workflow.php';

function projectNotificationDefaults(): array
{
  return ['toast_mode' => 'persistent', 'revision' => 0];
}
function projectNotificationPreferences(mysqli $conn, int $userId): array
{
  try {
    $stmt = $conn->prepare('SELECT toast_mode,revision FROM project_notification_preferences WHERE user_id=?');
    if (!$stmt) {
      if ($conn->errno === 1146) return projectNotificationDefaults();
      throw new RuntimeException('Could not load project notification preferences.');
    }
  } catch (mysqli_sql_exception $error) {
    // Preserve existing alerts while the additive migration is deployed.
    if ($error->getCode() === 1146) return projectNotificationDefaults();
    throw $error;
  }
  $stmt->bind_param('i', $userId);
  if (!$stmt->execute()) throw new RuntimeException('Could not load project notification preferences.');
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? ['toast_mode' => $row['toast_mode'], 'revision' => (int)$row['revision']] : projectNotificationDefaults();
}
function projectNotificationSavePreferences(mysqli $conn, int $userId, array $input): array
{
  $mode = $input['toast_mode'] ?? null;
  if ($userId <= 0 || !in_array($mode, ['persistent', 'brief', 'badge'], true)) {
    throw new DomainException('Neplatné nastavenie projektových upozornení.');
  }
  projectWorkflowExec($conn, 'INSERT INTO project_notification_preferences (user_id,toast_mode) VALUES (?,?)
    ON DUPLICATE KEY UPDATE toast_mode=VALUES(toast_mode),revision=revision+1', 'is', [$userId, $mode]);
  return projectNotificationPreferences($conn, $userId);
}
