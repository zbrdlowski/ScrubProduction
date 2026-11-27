# Projects: subtasks and completion review

## Behaviour

- Projects have two task levels: main task and subtask. Choose **Parent task** in Add/Edit Task, or drag a task's grip onto a main task. Drop it in **Standalone task** to detach it. The modal also works on touch devices and with a keyboard.
- A task that already contains subtasks cannot itself become a subtask. Parents must belong to the same project. Move/delete subtasks before deleting their parent.
- Subtasks can start or complete after the parent is Done. If the parent requires review, it must first be approved. Creating, moving or assigning a waiting subtask under an already completed parent sends the assignee an alert immediately.
- **Completion requires project owner review** is optional and off by default. Marking a reviewed task complete puts it into **Awaiting review**, which does not count toward completion. Only the project owner can approve it or return it for changes. The owner must explicitly approve even when also assigned to the task.
- The project owner is its **Responsible Employee** (`projects.assigned_to`). For a General project, the creator (`created_by`) reviews tasks. Pending review notifications follow a change of owner.
- Notifications appear in a task icon in the global navigation and as popups, polling every 15 seconds while the app is open. They are durable and appear when the recipient next opens the app. This does not send email or operating-system push notifications while the app is closed.
- In **Profile → Upozornenia → Upozornenia pre ownera projektu**, each employee chooses persistent owner toasts (default), brief toasts that hide after 10 seconds, or badge-only alerts. This applies to completion and review notices (`task_done`, `task_review`); assignee notices stay persistent. Settings persist per employee, independently of chat, and synchronize between open browser windows and through polling. Hiding/expiring a toast never marks the notice read or approves a task.
- The owner is notified of task/subtask completion. Review notifications have **Approve** and **Return for changes** actions; other notifications have **Mark read**. Closing a popup leaves the notification in the menu. Approving from the project detail refreshes the page.
- Reopening a parent withdraws unread ready alerts. Existing work is retained; children cannot newly start/complete until the parent is done again. Reopening completed children and returning pending children for changes remain possible.
- Existing signed-in-user editing permissions are preserved. Removing a required review is restricted to the project owner. All new task actions and notification writes validate a session CSRF token. Mutations and notifications share a transaction with a project row lock; repeated saves do not duplicate alerts.

## Deployment

1. On the existing MariaDB database, run `php scripts/projects/install_workflow.php` or import both `db/project_task_workflow.sql` and `db/project_notification_preferences.sql`. For an existing workflow installation, the preferences SQL file alone adds the new settings table. The migration is repeatable and preserves existing tasks, which start with no parent and no required review.
2. Deploy `includes/projects.php`, `includes/navbar.php`, `includes/project_workflow.php`, `includes/project_notification_nav.php`, `includes/project_notification_helpers.php`, `includes/profile_notifications.php`, and `scripts/projects/` together. No frontend build is needed.
3. Reload the application. The Projects page shows a migration notice if the new schema is absent; other navigation continues working without project alerts until installation.

## Verification

- `php tests/project_access_workflow_test.php`
- `php tests/project_employee_filters_test.php`
- `php tests/project_task_workflow_test.php`
- `php tests/project_notification_preferences_test.php`
- `node tests/project_notification_preferences_ui_test.js`
- `node tests/project_workflow_ui_test.js` (requires Playwright; optionally set `PROJECT_TEST_BROWSER_CHANNEL=msedge`)

Database tests use connection-local temporary tables, including notifications and audit entries. Browser/API tests also use temporary fixtures; screenshots are saved under `outputs/project-workflow/`.
