<?php
require __DIR__ . '/project_workflow_fixture.php';
require_once dirname(__DIR__) . '/includes/project_notification_helpers.php';
function preferenceCheck(bool $valid, string $message): void {
  if (!$valid) throw new RuntimeException($message);
}
preferenceCheck(projectNotificationPreferences($conn,7) === ['toast_mode'=>'persistent','revision'=>0], 'Existing users keep persistent toasts.');
foreach (['badge','brief','persistent'] as $index=>$mode) {
  $saved=projectNotificationSavePreferences($conn,7,['toast_mode'=>$mode,'user_id'=>8]);
  preferenceCheck($saved['toast_mode']===$mode && $saved['revision']===$index+1,'Modes persist with monotonic revisions.');
  preferenceCheck(projectNotificationPreferences($conn,8) === projectNotificationDefaults(),'Posted user IDs cannot change another employee preferences.');
}
foreach ([null,'invalid',[],1] as $mode) {
  try { projectNotificationSavePreferences($conn,7,['toast_mode'=>$mode]); throw new RuntimeException('Invalid mode accepted.'); }
  catch (DomainException $expected) {}
}
preferenceCheck(projectNotificationPreferences($conn,7)['revision']===3,'Invalid saves leave preferences unchanged.');
try { projectNotificationSavePreferences($conn,0,['toast_mode'=>'badge']); throw new RuntimeException('Anonymous save accepted.'); }
catch (DomainException $expected) {}
preferenceCheck((int)$conn->query('SELECT COUNT(*) FROM project_notification_preferences')->fetch_row()[0]===1,'Only the authenticated employee has a preferences row.');
echo "project_notification_preferences: OK (defaults, persistence, revisions, validation, user isolation)\n";
