-- Run once before deploying the project workflow PHP files (MariaDB).
ALTER TABLE project_tasks
  ADD COLUMN IF NOT EXISTS parent_task_id INT NULL,
  ADD COLUMN IF NOT EXISTS requires_review TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS review_requested_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS reviewed_by INT NULL;
ALTER TABLE project_tasks MODIFY status ENUM('waiting','in_progress','stuck','done','pending_review') NOT NULL DEFAULT 'waiting';
CREATE INDEX IF NOT EXISTS idx_project_task_parent ON project_tasks (project_id,parent_task_id);
CREATE TABLE IF NOT EXISTS project_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  task_id INT NOT NULL,
  recipient_id INT NOT NULL,
  kind VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  KEY idx_project_notification_unread (recipient_id,read_at,id),
  KEY idx_project_notification_task (task_id,kind),
  CONSTRAINT fk_project_notification_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_notification_task FOREIGN KEY (task_id) REFERENCES project_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
