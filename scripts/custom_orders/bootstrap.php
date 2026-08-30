<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__) . '/orders/activity_helper.php';
require_once dirname(__DIR__) . '/orders/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/helpers.php';

customOrdersEnsureSchema($conn);

$customOrdersPermission = (int) ($_SESSION['permission'] ?? 0);
if ($customOrdersPermission < 1) {
  http_response_code(403);
  exit('No permission');
}

// Permission 1+ may view Custom Orders and contribute notes/photos. Every
// other endpoint remains management-only unless it is explicitly allowlisted.
if ($customOrdersPermission < 300) {
  $customOrdersLimitedEndpoints = [
    'get_order_detail.php',
    'contact_suggestions.php',
    'category_info_options.php',
    'save_note.php',
    'edit_note.php',
    'delete_note.php',
    'upload_photos.php',
  ];
  $customOrdersEntryScript = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
  if (!in_array($customOrdersEntryScript, $customOrdersLimitedEndpoints, true)) {
    http_response_code(403);
    exit('Read-only Custom Orders access.');
  }
}
