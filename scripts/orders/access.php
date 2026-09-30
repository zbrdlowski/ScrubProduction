<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (PHP_SAPI === 'cli') {
    return;
}

$ordersEntryScript = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$ordersPermissionMap = [
    'get_order_detail.php' => 'orders.view',
    'get_order_traffic.php' => 'orders.view',
    'get_print_suggestions.php' => 'orders.view',
    'load_activity_log.php' => 'orders.view',
    'take_order.php' => 'orders.work',
    'assign_order_item.php' => 'orders.work',
    'remove_order_assignment.php' => 'orders.work',
    'remove_order_item_assignment.php' => 'orders.work',
    'invite_collab.php' => 'orders.work',
    'update_item_status.php' => 'orders.work',
    'update_item_waiting.php' => 'orders.work',
    'update_item_options.php' => 'orders.work',
    'update_item_internal_options.php' => 'orders.work',
    'update_item_category_info.php' => 'orders.work',
    'update_item_product_url.php' => 'orders.manage',
    'update_production_note.php' => 'orders.work',
    'upload_order_photos.php' => 'orders.work',
    'delete_order_photo.php' => 'orders.manage',
    'add_order_item.php' => 'orders.manage',
    'delete_order_item.php' => 'orders.manage',
    'update_order_item.php' => 'orders.manage',
    'get_manual_item_builder.php' => 'orders.manage',
    'create_followup_order.php' => 'orders.manage',
    'update_order_header.php' => 'orders.manage',
    'update_order_country.php' => 'orders.manage',
    'update_order_priority.php' => 'orders.manage',
    'update_order_types.php' => 'orders.manage',
    'update_order_status.php' => 'orders.manage',
    'resume_order_workflow.php' => 'orders.manage',
    'add_invoice.php' => 'orders.financial',
    'delete_invoice.php' => 'orders.financial',
    'add_order_financial_adjustment.php' => 'orders.financial',
    'delete_order_financial_adjustment.php' => 'orders.financial',
    'update_order_financial_total.php' => 'orders.financial',
    'confirm_order_payment.php' => 'orders.financial',
    'add_tracking.php' => 'orders.shipping',
    'delete_tracking.php' => 'orders.shipping',
    'multishipping.php' => 'orders.view',
    'import_fedex_eod.php' => 'orders.shipping',
    'sync_fedex_delivery_status.php' => 'orders.shipping',
    'install_customs_identifier_2026_09.php' => 'orders.admin',
    'install_payment_release_2026_09.php' => 'orders.admin',
    'install_status_policies_2026_08.php' => 'orders.admin',
    'install_status_policies_2026_09.php' => 'orders.admin',
    'install_status_policies_2026_09_v6.php' => 'orders.admin',
    'Install_status_policies_2026-08_v5.php' => 'orders.admin',
];

if (isset($ordersPermissionMap[$ordersEntryScript])) {
    auth_require($ordersPermissionMap[$ordersEntryScript], 'No permission for this Orders action.');
}
