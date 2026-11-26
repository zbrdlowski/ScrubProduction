<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/scripts/order_import_lib.php';
require_once dirname(__DIR__) . '/scripts/import_darkscrub_unified.php';
require_once dirname(__DIR__) . '/scripts/orders/fix_discount_workflow.php';

// Every table written by the real importer/workflow is shadowed for this
// connection. No application orders, customers or policies are modified.
$tables = [
    'orders', 'order_items', 'order_addresses', 'customers', 'shipments',
    'order_categories', 'order_item_categories', 'order_item_assignments',
    'order_item_statuses', 'order_activity', 'order_sources',
    'status_definitions', 'status_definition_targets', 'status_definition_tab_bar_positions',
    'status_workflow_rules', 'status_workflow_rule_conditions',
    'status_workflow_rule_allowed_order_statuses',
];
$checks = 0;
$assert = static function ($expected, $actual, string $message) use (&$checks): void {
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
};
$csvPath = tempnam(sys_get_temp_dir(), 'discount-workflow-');
try {
    foreach ($tables as $table) {
        $ddl = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
        $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
        $ddl = preg_replace('/^\s*CONSTRAINT .+\n/m', '', $ddl);
        $ddl = preg_replace('/,\n\) ENGINE/', "\n) ENGINE", $ddl);
        $conn->query($ddl);
    }
    foreach (['G', 'P', 'S', 'F'] as $department) {
        foreach (['RTP_✗' => 10, 'READY' => 50, 'DRAFT_✗' => 52, 'REPRINT' => 130, 'WAITING' => 150] as $code => $sort) {
            $stmt = $conn->prepare("INSERT INTO status_definitions (scope, department, code, label, sort_order, active, tab_bar) VALUES ('item', ?, ?, ?, ?, 1, 0)");
            $stmt->bind_param('sssi', $department, $code, $code, $sort);
            $stmt->execute();
            $stmt->close();
        }
    }
    $conn->query("INSERT INTO status_definitions (scope, department, code, label, sort_order, active, tab_bar) VALUES ('item', 'G', 'OLD_DRAFT', 'Old Draft', 20, 0, 0)");
    foreach ([1 => ['Ready', 'READY_TO_INVOICE', 'READY'], 2 => ['Draft', 'NEW', 'DRAFT_✗'], 3 => ['Reprint', 'IN_PROGRESS', 'REPRINT']] as $id => [$name, $result, $itemStatus]) {
        $conn->query("INSERT INTO status_workflow_rules (id, name, priority, result_order_status_code, stop_on_match, active) VALUES ($id, '$name', $id, '$result', 1, 1)");
        $conn->query("INSERT INTO status_workflow_rule_conditions (rule_id, department, condition_type, operator, status_code, sort_order) VALUES ($id, 'G', 'status', 'IN', '$itemStatus', 1)");
        foreach (['NEW', 'IN_PROGRESS', 'READY_TO_SHIP', 'READY_TO_INVOICE'] as $status) {
            $conn->query("INSERT INTO status_workflow_rule_allowed_order_statuses (rule_id, order_status_code) VALUES ($id, '$status')");
        }
    }

    foreach ([
        [['sku' => 'COUPON20', 'title' => 'Rabattcode Nr. BF26 - Rabatt 20%'], true],
        [['item_sku' => ' coupon6 '], true],
        [['title' => 'Zľavový kupón číslo SPMOTO - Zľava 15%'], true],
        [['itemName' => 'Slevový kupón TEST'], true],
        [['item_name' => 'Discount code BF26'], true],
        [['sku' => 'G_KTM00067', 'title' => 'Graphics kit', 'unit_price' => 0], false],
        [['sku' => 'G_FREE', 'title' => 'Free replacement', 'unit_price' => -10], false],
        [['title' => 'Graphics with discount code lettering'], false],
        [['title' => 'Gift voucher'], false],
    ] as [$row, $expected]) {
        $assert($expected, ordersIsDiscountItem($row), 'Discount classification');
    }
    foreach ([
        ['Rabattcode Nr. BF26 - Rabatt 20%', '20%', 'BF26'],
        ['Rabattcode Nr. BF26- Rabatt 20%', '20%', 'BF26'],
        ['Zľavový kupón číslo SPMOTO - Zľava 15%', '15%', 'SPMOTO'],
        ['Slevový kupón číslo LETO - Sleva 12,5%', '12.5%', 'LETO'],
        ['Discount code: WELCOME - Discount 10%', '10%', 'WELCOME'],
        ['Discount code ZERO', '', 'ZERO'],
        ['Discount coupon 20%', '20%', ''],
        ['Discount code GIFT - Discount 120%', '', 'GIFT'],
        ['', '', ''],
    ] as [$title, $percentage, $code]) {
        $details = ordersDiscountDisplayDetails(['sku' => 'COUPON20', 'title' => $title]);
        $assert($percentage, $details['percentage'], 'Display uses explicit valid percentage only: ' . $title);
        $assert($code, $details['code'], 'Display extracts customer coupon code: ' . $title);
    }
    $assert(null, ordersDiscountDisplayDetails(['sku' => 'G_TEST', 'title' => 'Graphics kit']), 'Ordinary products have no discount note');

    $assert([], oi_detect_import_row_departments(['item_type_code' => 'G'], 'COUPON20', null, 'Rabattcode BF26'), 'Coupon overrides erroneous source Graphics classification');
    $assert(false, oi_order_has_graphics_draft_request([['item_sku' => 'COUPON20', 'options_json' => '{"draft":"Yes"}']]), 'Coupon does not request graphics draft');

    foreach (['G', 'P', 'S', 'F'] as $department) {
        foreach (['DRAFT_✗', 'REPRINT', 'RTP_✗', 'WAITING'] as $unfinished) {
            $items = [['status' => 'READY'], ['status' => $unfinished]];
            $assert($unfinished, ordersDepartmentWorkflowStatus($conn, $department, $items), "$department: unfinished status wins over Ready");
            $assert('ORANGE', itemTrafficState($conn, $department, $items), "$department: mixed items never green");
        }
        $assert('READY', ordersDepartmentWorkflowStatus($conn, $department, [['status' => 'READY'], ['status' => 'READY']]), "$department: all items ready");
    }
    foreach (['UNKNOWN_STATUS', 'OLD_DRAFT'] as $unfinished) {
        $assert('PROCESSING', ordersDepartmentWorkflowStatus($conn, 'G', [['status' => 'READY'], ['status' => $unfinished]]), 'Unknown/inactive status still blocks Ready');
    }

    $header = ['source', 'external_order_id', 'item_sku', 'item_name', 'item_type_code', 'item_qty', 'item_total_price_with_vat', 'price_to_pay', 'delivery_country', 'paid', 'options_json'];
    $file = fopen($csvPath, 'wb');
    fputcsv($file, $header);
    fputcsv($file, ['SHOPTET', '__DISCOUNT_TEST', 'G_KTM00067', 'Graphics kit', 'G', '1', '100', '80', 'DE', '1', '{"draft":"Yes"}']);
    fputcsv($file, ['SHOPTET', '__DISCOUNT_TEST', 'COUPON20', 'Rabattcode Nr. BF26 - Rabatt 20%', 'G', '1', '-20', '80', 'DE', '1', '{"applyinggraphics":"Yes","seat-cover":"Yes"}']);
    fputcsv($file, ['SHOPTET', '__DISCOUNT_TEST', 'COUPON0', 'Discount code ZERO', '', '1', '0', '80', 'DE', '1', '{}']);
    fclose($file);
    $stats = import_darkscrub_unified_csv($conn, $csvPath);
    $assert(1, $stats['created'], 'Real importer creates one order');
    $assert(3, $stats['items'], 'Coupons create no companion production items');
    $order = $conn->query("SELECT * FROM orders WHERE external_order_id = '__DISCOUNT_TEST'")->fetch_assoc();
    $orderId = (int) $order['id'];
    $assert('80.00', $order['total'], 'Discounted order total preserved');
    $coupon = $conn->query("SELECT * FROM order_items WHERE sku = 'COUPON20'")->fetch_assoc();
    $assert(null, $coupon['item_type_code'], 'Imported coupon has no production department');
    $assert('-20.00', $coupon['unit_price'], 'Discount amount preserved');
    $assert(0, (int) $conn->query('SELECT COUNT(*) FROM order_item_categories WHERE item_id = ' . (int) $coupon['id'])->fetch_row()[0], 'Coupon has no production category');
    $assert(1, count(ordersBuildWorkflowGroups($conn, $orderId)['G']), 'Only actual graphics enters workflow');
    $appliedDiscounts = ordersLoadAppliedDiscounts($conn, $orderId);
    $assert(2, count($appliedDiscounts), 'Financial display loads coupons without a production department');
    $assert(['percentage' => '20%', 'code' => 'BF26', 'description' => ''], $appliedDiscounts[0], 'Financial display includes discount and code');
    $assert('ZERO', $appliedDiscounts[1]['code'], 'Zero-value coupon is still displayed');
    $conn->query('UPDATE order_items SET deleted_at = NOW() WHERE id = ' . (int) $coupon['id']);
    $assert(1, count(ordersLoadAppliedDiscounts($conn, $orderId)), 'Deleted coupon is not displayed');
    $conn->query('UPDATE order_items SET deleted_at = NULL WHERE id = ' . (int) $coupon['id']);
    $assert([], ordersLoadAppliedDiscounts($conn, $orderId + 1), 'Another order cannot inherit discount notes');


    // Reproduce old data from the screenshot, including a stale Ready for Label.
    $conn->query("UPDATE order_items SET item_type_code = 'G', status = 'READY' WHERE sku LIKE 'COUPON%'");
    $conn->query("UPDATE orders SET status = 'READY_TO_SHIP' WHERE id = $orderId");
    recalculateOrderWorkflow($conn, $orderId);
    $readOrder = static function () use ($conn, $orderId): array {
        return $conn->query("SELECT status, traffic_light FROM orders WHERE id = $orderId")->fetch_assoc();
    };
    $assert('NEW', $readOrder()['status'], 'Legacy Ready coupon cannot complete unfinished draft');
    $assert('ORANGE', $readOrder()['traffic_light'], 'Legacy coupon cannot make traffic green');
    $conn->query("UPDATE order_items SET status = 'READY' WHERE sku = 'G_KTM00067'");
    $conn->query("UPDATE order_items SET status = 'WAITING' WHERE sku LIKE 'COUPON%'");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('READY_TO_SHIP', $readOrder()['status'], 'Completed real graphics can advance even with Waiting coupon');
    $assert('GREEN', $readOrder()['traffic_light'], 'Coupon never blocks finished production');

    $conn->query("UPDATE order_addresses SET country = 'US' WHERE order_id = $orderId");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('READY_TO_INVOICE', $readOrder()['status'], 'Finished non-EU order still requires invoice');
    $conn->query("UPDATE order_addresses SET country = 'DE' WHERE order_id = $orderId");
    $conn->query("UPDATE orders SET source_meta = '{\"_invoice_request\":{\"required\":true,\"target\":\"company\"}}' WHERE id = $orderId");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('READY_TO_INVOICE', $readOrder()['status'], 'Custom invoice request still takes precedence for finished EU orders');
    $conn->query("UPDATE orders SET source_meta = '{\"_followup\":{\"do_not_invoice\":true}}' WHERE id = $orderId");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('READY_TO_SHIP', $readOrder()['status'], 'Finished no-invoice follow-up still goes to labels');

    $conn->query("UPDATE order_items SET status = 'UNKNOWN_STATUS' WHERE sku = 'G_KTM00067'");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('IN_PROGRESS', $readOrder()['status'], 'Stale Ready is corrected even without a matching fallback policy');
    foreach (['PENDING', 'SHIPPED', 'CANCELLED', 'DELIVERED'] as $protected) {
        $conn->query("UPDATE orders SET status = '$protected' WHERE id = $orderId");
        recalculateOrderWorkflow($conn, $orderId);
        $assert($protected, $readOrder()['status'], 'Out-of-scope status preserved');
    }
    $conn->query("UPDATE orders SET status = 'READY_TO_SHIP', status_override = 1 WHERE id = $orderId");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('READY_TO_SHIP', $readOrder()['status'], 'Manual override preserved');
    $conn->query("UPDATE orders SET status_override = 0 WHERE id = $orderId");
    $conn->query("UPDATE order_items SET deleted_at = NOW() WHERE sku = 'G_KTM00067'");
    recalculateOrderWorkflow($conn, $orderId);
    $assert([], ordersBuildWorkflowGroups($conn, $orderId), 'Coupon-only order has no production departments');
    $assert('NEW', $readOrder()['status'], 'Coupon-only order cannot become Ready');

    // A free replacement is still real production; a misconfigured policy must
    // not send it to labels just because the department is present.
    $conn->query("UPDATE order_items SET deleted_at = NULL, unit_price = 0, status = 'DRAFT_✗' WHERE sku = 'G_KTM00067'");
    $conn->query("INSERT INTO status_workflow_rules (id, name, priority, result_order_status_code, stop_on_match, active) VALUES (99, 'Incomplete ready policy', -1, 'READY_TO_SHIP', 1, 1)");
    $conn->query("INSERT INTO status_workflow_rule_conditions (rule_id, department, condition_type, operator, status_code, sort_order) VALUES (99, 'G', 'presence', 'PRESENT', '', 1)");
    $conn->query("INSERT INTO status_workflow_rule_allowed_order_statuses (rule_id, order_status_code) VALUES (99, 'NEW')");
    recalculateOrderWorkflow($conn, $orderId);
    $assert('IN_PROGRESS', $readOrder()['status'], 'Even an incomplete ready policy cannot complete free unfinished production');
    $conn->query('UPDATE status_workflow_rules SET active = 0 WHERE id = 99');

    // Legacy title-only coupons are excluded as well as reserved COUPON SKUs.
    $conn->query("UPDATE order_items SET sku = 'BF26' WHERE sku = 'COUPON20'");
    $assert(1, count(ordersBuildWorkflowGroups($conn, $orderId)['G']), 'Legacy coupon detected from German title alone');
    $conn->query("INSERT INTO order_item_categories (item_id, category_id) VALUES (" . (int) $coupon['id'] . ", 1)");
    $conn->query("UPDATE orders SET status = 'READY_TO_SHIP' WHERE id = $orderId");
    $preview = discountWorkflowRepairRows($conn);
    $assert(1, count($preview), 'Repair preview detects affected order');
    $assert(2, count($preview[0]['coupons']), 'Repair preview detects both coupons');
    $assert('READY_TO_SHIP', $readOrder()['status'], 'Preview never changes order status');
    foreach (['PENDING', 'SHIPPED', 'CANCELLED', 'DELIVERED', 'DONE'] as $protected) {
        $conn->query("UPDATE orders SET status = '$protected' WHERE id = $orderId");
        $assert([], discountWorkflowRepairApply($conn, null), 'Repair excludes ' . $protected);
        $assert($protected, $readOrder()['status'], 'Repair preserves ' . $protected);
    }
    $conn->query("UPDATE orders SET status = 'READY_TO_SHIP', status_override = 1 WHERE id = $orderId");
    $assert([], discountWorkflowRepairApply($conn, null), 'Repair excludes manual override');
    $conn->query("UPDATE orders SET status_override = 0 WHERE id = $orderId");
    $repaired = discountWorkflowRepairApply($conn, null);
    $assert(1, count($repaired), 'Repair applies to affected automatic order');
    $assert('NEW', $readOrder()['status'], 'Repair returns unfinished draft to correct status');
    $savedCoupon = $conn->query('SELECT * FROM order_items WHERE id = ' . (int) $coupon['id'])->fetch_assoc();
    $assert(null, $savedCoupon['item_type_code'], 'Repair removes legacy Graphics classification');
    $assert('-20.00', $savedCoupon['unit_price'], 'Repair preserves discount amount');
    $assert($coupon['options_json'], $savedCoupon['options_json'], 'Repair preserves source data');
    $assert(0, (int) $conn->query('SELECT COUNT(*) FROM order_item_categories WHERE item_id = ' . (int) $coupon['id'])->fetch_row()[0], 'Repair removes coupon production categories');
    $assert(1, (int) $conn->query("SELECT COUNT(*) FROM order_activity WHERE action = 'DISCOUNT_WORKFLOW_REPAIR'")->fetch_row()[0], 'Repair writes audit history');
    $assert([], discountWorkflowRepairRows($conn), 'Repair leaves no pending candidates');
    $assert([], discountWorkflowRepairApply($conn, null), 'Repeated repair changes nothing');

    echo "orders_discount_workflow: $checks checks passed\n";
} finally {
    foreach (array_reverse($tables) as $table) {
        $conn->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
    if (is_file($csvPath)) unlink($csvPath);
}
