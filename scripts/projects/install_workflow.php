<?php
// CLI only. Additive, repeatable MariaDB migration; existing tasks remain standalone.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/project_workflow.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$before = (int)$conn->query('SELECT COUNT(*) FROM project_tasks')->fetch_row()[0];
$sql = file_get_contents(__DIR__ . '/../../db/project_task_workflow.sql');
if ($sql === false) throw new RuntimeException('Migration file not found.');
$preferencesSql = file_get_contents(__DIR__ . '/../../db/project_notification_preferences.sql');
if ($preferencesSql === false) throw new RuntimeException('Preferences migration file not found.');
$conn->multi_query($sql . "\n" . $preferencesSql);
do {
  $result = $conn->store_result();
  if ($result) $result->free();
  if (!$conn->more_results()) break;
  $conn->next_result();
} while (true);
$after = (int)$conn->query('SELECT COUNT(*) FROM project_tasks')->fetch_row()[0];
if ($before !== $after || !projectWorkflowInstalled($conn)) throw new RuntimeException('Migration verification failed.');
echo "Project workflow migration installed. Existing tasks preserved: {$after}.\n";
