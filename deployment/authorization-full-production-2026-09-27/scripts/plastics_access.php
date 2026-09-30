<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once dirname(__DIR__) . '/includes/conn.php';
}
require_once dirname(__DIR__) . '/includes/auth.php';

$plasticsEntryScript = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
$plasticsPermissionMap = [
    'get_supplier_orders.php' => 'plastics.view',
    'load_order_items.php' => 'plastics.view',
    'fetch_kits.php' => 'plastics.work',
    'movements.php' => 'plastics.work',
    'scan_in.php' => 'plastics.work',
    'scan_out.php' => 'plastics.work',
    'relocate_item.php' => 'plastics.work',
    'update_kit_order.php' => 'plastics.work',
    'add_kit.php' => 'plastics.work',
    'delete_kits.php' => 'plastics.work',
    'update_kits.php' => 'plastics.work',
    'reset_position.php' => 'plastics.work',
    'clear_saved_order.php' => 'plastics.purchase',
    'save_order_item.php' => 'plastics.purchase',
    'submit_order.php' => 'plastics.purchase',
    'delete_order_row.php' => 'plastics.purchase',
    'update_order_number.php' => 'plastics.purchase',
    'update_order_status.php' => 'plastics.purchase',
    'update_order.php' => 'plastics.purchase',
    'update_quantity.php' => 'plastics.purchase',
    'receive_supply.php' => 'plastics.receive',
    'receive_supply_fifo.php' => 'plastics.receive',
    'receive_supply_upload.php' => 'plastics.receive',
    'get_open_suppliers.php' => 'plastics.receive',
    'get_intake_labels.php' => 'plastics.receive',
    'add_intake_label_manual.php' => 'plastics.receive',
    'mark_labels_printed.php' => 'plastics.receive',
    'add_item.php' => 'plastics.manage',
    'update_item.php' => 'plastics.manage',
    'upload_items.php' => 'plastics.manage',
    'add_shelf.php' => 'plastics.manage',
    'update_shelf.php' => 'plastics.manage',
    'save_shelf_layout.php' => 'plastics.manage',
    'update_stock_levels.php' => 'plastics.manage',
    'cleanup_empty_shelves.php' => 'plastics.manage',
    'upload_csv.php' => 'plastics.manage',
    'archive_data.php' => 'plastics.manage',
    'process_archive.php' => 'plastics.manage',
    'get_suppliers.php' => 'plastics.manage',
    'download_log.php' => 'plastics.manage',
    'sanitize_order_sql_dumps.php' => 'plastics.manage',
    'movement_trends.php' => 'plastics.reports',
    'supplier_distribution.php' => 'plastics.reports',
];

auth_require($plasticsPermissionMap[$plasticsEntryScript] ?? 'plastics.manage', 'No permission for this Plastics Stock action.');
