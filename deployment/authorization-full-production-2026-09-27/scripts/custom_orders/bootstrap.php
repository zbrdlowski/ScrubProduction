<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/orders/activity_helper.php';
require_once dirname(__DIR__) . '/orders/category_sync_helper.php';
require_once dirname(__DIR__, 2) . '/includes/orders_workflow_helpers.php';
require_once __DIR__ . '/helpers.php';

customOrdersEnsureSchema($conn);

$customOrdersEntryScript = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$customOrdersPermissionMap = [
  'get_order_detail.php' => 'custom_orders.view',
  'contact_suggestions.php' => 'custom_orders.view',
  'category_info_options.php' => 'custom_orders.view',
  'check_official_number.php' => 'custom_orders.view',
  'save_note.php' => 'custom_orders.work',
  'edit_note.php' => 'custom_orders.work',
  'delete_note.php' => 'custom_orders.work',
  'upload_photos.php' => 'custom_orders.work',
  'take_order.php' => 'custom_orders.work',
  'remove_order_assignment.php' => 'custom_orders.work',
  'take_item.php' => 'custom_orders.work',
  'save_followup.php' => 'custom_orders.work',
  'save_order.php' => 'custom_orders.work',
  'update_status.php' => 'custom_orders.work',
  'save_payment.php' => 'custom_orders.financial',
  'delete_payment.php' => 'custom_orders.financial',
  'assign_official_number.php' => 'custom_orders.export',
  'export_order.php' => 'custom_orders.export',
  'delete_order.php' => 'custom_orders.delete',
  'delete_item.php' => 'custom_orders.delete',
  'delete_photo.php' => 'custom_orders.delete',
];
auth_require($customOrdersPermissionMap[$customOrdersEntryScript] ?? 'custom_orders.manage', 'No permission for this Custom Orders action.');
