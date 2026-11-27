<?php
// Reusable, connection-local fixtures: never write application data.
require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/project_workflow.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$fixtureDefinitions = [
 'employees' => "id INT PRIMARY KEY, firstname VARCHAR(64), lastname VARCHAR(64), photo VARCHAR(255), active VARCHAR(20)",
 'projects' => "id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), description TEXT, status VARCHAR(32), priority VARCHAR(32),
   start_date DATE NULL,due_date DATE NULL,assigned_to INT NULL,created_by INT,notes TEXT,color VARCHAR(16),created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
 'project_tasks' => "id INT AUTO_INCREMENT PRIMARY KEY,project_id INT,title VARCHAR(255),assigned_to INT NULL,status ENUM('waiting','in_progress','stuck','done','pending_review') DEFAULT 'waiting',
   priority VARCHAR(32),due_date DATE NULL,notes TEXT,sort_order INT DEFAULT 0,parent_task_id INT NULL,requires_review TINYINT DEFAULT 0,
   review_requested_at DATETIME NULL,reviewed_at DATETIME NULL,reviewed_by INT NULL",
 'project_time_log' => "id INT AUTO_INCREMENT PRIMARY KEY,project_id INT,task_id INT NULL,employee_id INT,logged_date DATE,hours DECIMAL(8,2),note TEXT",
 'project_notification_preferences' => "user_id INT PRIMARY KEY,toast_mode ENUM('persistent','brief','badge') NOT NULL DEFAULT 'persistent',revision BIGINT UNSIGNED NOT NULL DEFAULT 1",
 'project_notifications' => "id INT AUTO_INCREMENT PRIMARY KEY,project_id INT,task_id INT,recipient_id INT,kind VARCHAR(32),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,read_at DATETIME NULL",
 'order_activity' => "id INT AUTO_INCREMENT PRIMARY KEY,order_id INT NULL,actor_employee_id INT NULL,action VARCHAR(64),entity_type VARCHAR(64),entity_id INT NULL,payload TEXT,note TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
];
foreach ($fixtureDefinitions as $table => $columns) {
 $conn->query("CREATE TEMPORARY TABLE {$table} ({$columns}) ENGINE=InnoDB");
 if (strpos($conn->query("SHOW CREATE TABLE {$table}")->fetch_row()[1], 'CREATE TEMPORARY TABLE') !== 0) throw new RuntimeException('Unsafe fixtures.');
}
$conn->query("INSERT INTO employees VALUES (7,'Project','Owner','','Active'),(8,'Designer','Honda','','Active'),(9,'Website','Publisher','','Active'),(10,'eBay','Publisher','','Active')");
$conn->query("INSERT INTO projects (id,title,description,status,priority,assigned_to,created_by,notes,color) VALUES
 (1,'Honda Designz','Create and list new designs','waiting','medium',7,7,'','#3a7bd5'),(2,'Other project','','waiting','medium',8,8,'','#3a7bd5')");
function fixtureAdd(mysqli $conn, string $title, int $assignee, ?int $parent = null, bool $review = false, int $project = 1): int {
 return projectWorkflowMutate($conn, 7, 'add_task', ['project_id'=>$project,'task_title'=>$title,'assigned_to'=>$assignee,
   'task_status'=>'waiting','parent_task_id'=>$parent,'requires_review'=>$review]);
}
function fixtureGet(mysqli $conn, int $id): array {
 return projectWorkflowRows($conn, 'SELECT * FROM project_tasks WHERE id=?', 'i', [$id])[0];
}
