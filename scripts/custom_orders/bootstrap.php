<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__) . '/orders/activity_helper.php';
require_once dirname(__DIR__) . '/orders/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/helpers.php';

if ((int) ($_SESSION['permission'] ?? 0) < 300) {
  http_response_code(403);
  exit('No permission');
}
