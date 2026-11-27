<?php
require_once __DIR__ . '/project_notification_helpers.php';
$projectNoticeConfig = ['userId' => (int)($_SESSION['user_id'] ?? 0), 'csrfToken' => projectWorkflowToken(),
  'endpoint' => 'scripts/projects/notifications.php',
  'preferences' => projectNotificationPreferences($conn, (int)($_SESSION['user_id'] ?? 0))];
?>
<li class="nav-item dropdown" id="projectNotificationNav">
  <a class="nav-link" href="#" data-toggle="dropdown" id="projectNotificationToggle" aria-label="Project notifications" aria-haspopup="true" aria-expanded="false">
    <i class="fas fa-tasks"></i><span id="projectNotificationBadge" class="badge badge-warning navbar-badge" hidden aria-live="polite"></span>
  </a>
  <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right" aria-labelledby="projectNotificationToggle" style="width:360px;max-width:92vw;">
    <div class="dropdown-header">Project notifications</div>
    <div id="projectNotificationError" class="text-danger small px-3" role="alert" hidden></div>
    <div id="projectNotificationItems" style="max-height:65vh;overflow:auto;"><div class="px-3 py-2 text-muted small">Loading…</div></div>
    <a class="dropdown-item dropdown-footer" href="index.php?page=projects">All projects</a>
  </div>
</li>
<script>window.darkScrubProjectNotifications = <?= json_encode($projectNoticeConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="scripts/projects/notifications.js?v=20261127-2" defer></script>
