<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';
$cases = ['item_edit', 'item_add', 'item_form', 'item_delete', 'status', 'worker_status',
  'followup', 'take', 'remove_assignment', 'linked_header', 'payment', 'note',
  'unexported_item', 'unexported_status', 'timestamp_only', 'ui_exported', 'ui_unexported',
  'ui_unexported_fitting', 'ui_unexported_edit_fitting'];
foreach (customOrdersExportLockedHeaderFields() as $field) $cases[] = 'header_' . $field;
$case = $argv[1] ?? '';
if ($case === '') {
  foreach ($cases as $name) {
    $process = proc_open([PHP_BINARY, __FILE__, $name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || trim($out) !== $name . ': OK' || trim($err) !== '') {
      throw new RuntimeException($name . ': ' . $out . $err);
    }
  }
  echo 'custom_order_export_lock: ', count($cases), " request scenarios passed\n";
  exit;
}
if (!in_array($case, $cases, true)) throw new RuntimeException('Unknown test case.');
require dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/scripts/orders/activity_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Shadow every table that these handlers can write; no application data is modified.
$tables = ['custom_orders', 'custom_order_items', 'custom_order_assignments', 'custom_order_item_assignments',
  'custom_order_payments', 'custom_order_followups', 'custom_order_activity', 'custom_order_notes',
  'custom_order_note_revisions', 'custom_order_photos', 'custom_order_contacts', 'custom_order_number_sequences',
  'orders', 'order_items', 'order_addresses', 'order_activity', 'order_photos', 'order_invoices', 'order_tracking_numbers', 'customers'];
foreach ($tables as $table) {
  $ddl = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
  $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
  $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
  $ddl = preg_replace('/,\s*\)/', "\n)", $ddl);
  $conn->query($ddl);
  if (strpos($conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1], 'CREATE TEMPORARY TABLE') !== 0) {
    throw new RuntimeException('Fixture must be temporary: ' . $table);
  }
}
$conn->query("INSERT INTO custom_orders (id,internal_code,official_order_number,status,production_order_id,exported_at,
  complexity_level,customer_name,shipping_name,shipping_street,shipping_city,shipping_zip,shipping_country,
  shipping_email,shipping_phone,currency,dead_order_flag,bike_model,deposit_revision_used,next_followup_at)
  VALUES (123,'CO-TEST','SO-TEST','EXPORTED',789,NOW(),5,'Fixture','Fixture','Original 1','Test','12345','SK',
  'old@example.test','+421900000001','EUR',1,'Original model',2,'2026-11-24 12:00:00')");
$conn->query("INSERT INTO custom_order_items (id,custom_order_id,line_no,item_type_code,sku,title,qty,unit_price,options_json,internal_options_json)
  VALUES (456,123,1,'M','TEST','Original item',1,100,'{}','{}')");
$conn->query("INSERT INTO custom_order_assignments (id,custom_order_id,employee_id,assigned_by) VALUES (234,123,7,7)");
$conn->query("INSERT INTO orders (id,source_id,external_order_id,order_number,currency,total,status,source_meta)
  VALUES (789,1,'CO-TEST','SO-TEST','EUR',100,'NEW','{\"custom_order_id\":123}')");
if (strpos($case, 'unexported_') === 0 || strpos($case, 'ui_unexported') === 0) $conn->query("UPDATE custom_orders SET status='LEAD',production_order_id=NULL,exported_at=NULL");
if ($case === 'timestamp_only') $conn->query('UPDATE custom_orders SET production_order_id=NULL');
$_SESSION = ['user_id' => 7, 'permission' => $case === 'worker_status' ? 0 : 900];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_POST = ['custom_order_id' => 123, 'custom_item_id' => 456, 'inline_edit' => 1,
  'item_type_code' => 'M', 'sku' => 'CHANGED', 'title' => 'Changed item', 'qty' => 3, 'unit_price' => 50];
$endpoint = 'save_item.php';
if ($case === 'item_add') $_POST['custom_item_id'] = 0;
if ($case === 'item_form') unset($_SERVER['HTTP_X_REQUESTED_WITH']);
if ($case === 'item_delete') $endpoint = 'delete_item.php';
if (in_array($case, ['status','unexported_status'], true)) { $endpoint = 'update_status.php'; $_POST['status'] = 'DRAFT_SENT'; }
if ($case === 'worker_status') { $endpoint = 'save_order.php'; $_POST['status'] = 'DRAFT_SENT'; }
if ($case === 'followup') { $endpoint = 'save_followup.php'; $_POST['note'] = 'Unsynced followup'; }
if ($case === 'take') $endpoint = 'take_order.php';
if ($case === 'remove_assignment') { $endpoint = 'remove_order_assignment.php'; $_POST['assignment_id'] = 234; $_POST['custom_order_id'] = 999; }
if (strpos($case, 'header_') === 0) { $endpoint = 'save_order.php'; $_POST[substr($case, 7)] = 'Changed'; }
if ($case === 'linked_header') {
  $endpoint = 'save_order.php';
  $_POST = ['custom_order_id' => 123, 'shipping_street' => 'Changed 2', 'shipping_email' => 'new@example.test',
    'shipping_phone' => '', 'shipping_price' => '12.50', 'customs_ddp_amount' => '5', 'customer_notes' => 'Live note'];
}
if ($case === 'payment') { $endpoint = 'save_payment.php'; $_POST['amount'] = 25; $_POST['payment_kind'] = 'DEPOSIT'; }
if ($case === 'note') { $endpoint = 'save_note.php'; $_POST['note_body'] = 'Live shared note'; }
if (strpos($case, 'ui_') === 0) {
  $_GET = ['custom_order_id' => 123, 'detail' => '1', 'help_lang' => 'en'];
  // Reuse the isolated page renderer for Fitting defaults and saved-item regression tests.
  if ($case === 'ui_unexported_fitting') $_GET['builder_type'] = 'F';
  if ($case === 'ui_unexported_edit_fitting') {
    $_GET['edit_item_id'] = 456;
    $conn->query("UPDATE custom_order_items SET item_type_code='F',title='Fitting',unit_price=39.90 WHERE id=456");
  }
  $_SERVER['REQUEST_URI'] = '/darkscrub/index.php?page=custom_orders&custom_order_id=123&detail=1';
  // Render the real page with temporary order data; omit schema initialization from this test.
  $file = dirname(__DIR__) . '/includes/custom_orders.php';
  $pageSource = str_replace('customOrdersEnsureSchema($conn);', '', file_get_contents($file));
  $pageSource = str_replace('__DIR__', var_export(dirname($file), true), $pageSource);
  ob_start();
  eval(substr($pageSource, 5));
  $html = ob_get_clean();
  $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
  $locked = $case === 'ui_exported';
  $forms = $xpath->query('//form[contains(@action,"/save_item.php")]');
  if ($forms->length < 2) throw new RuntimeException('Both product forms must remain visible.');
  foreach ($forms as $form) {
    foreach ($xpath->query('.//input[not(@type="hidden")]|.//select|.//textarea|.//button[not(@data-toggle="modal")]', $form) as $control) {
      if ($locked && !$control->hasAttribute('disabled')) throw new RuntimeException('Unlocked product control: ' . $dom->saveHTML($control));
    }
  }
  foreach (['status','complexity_level'] as $field) {
    $control = $xpath->query('//select[@name="' . $field . '" and @form]')->item(0);
    if (!$control || $control->hasAttribute('disabled') !== $locked) throw new RuntimeException('Header lock mismatch: ' . $field);
  }
  foreach (['shipping_street','shipping_email','shipping_price','payment_method'] as $field) {
    $control = $xpath->query('//*[@name="' . $field . '"]')->item(0);
    if (!$control || $control->hasAttribute('disabled')) throw new RuntimeException('Linked field must remain enabled: ' . $field);
  }
  $target = dirname(__DIR__) . '/.cache/custom-order-export-lock';
  if (!is_dir($target)) mkdir($target, 0777, true);
  $assets = '<base href="http://localhost/darkscrub/"><link rel="stylesheet" href="dist/css/adminlte.min.css"><script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>';
  file_put_contents($target . '/' . $case . '.html', '<!doctype html><html><head><meta charset="utf-8">' . $assets . '</head><body class="dark-mode" style="background:#172735;padding:20px">' . $html . '</body></html>');
  echo $case, ": OK\n";
  exit;
}
$before = [];
foreach ($tables as $table) $before[$table] = $conn->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC);
ob_start();
register_shutdown_function(static function () use ($conn, $case, $tables, $before): void {
  $response = ob_get_clean();
  $assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
  try {
    $fatal = error_get_last();
    $assert(!$fatal || !in_array($fatal['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true), 'Fatal handler error');
    $order = $conn->query('SELECT * FROM custom_orders WHERE id=123')->fetch_assoc();
    if ($case === 'linked_header') {
      $assert(($_SESSION['custom_orders_flash']['type'] ?? '') === 'success', 'Linked header save must succeed: ' . json_encode($_SESSION));
      foreach (customOrdersExportLockedHeaderFields() as $field) {
        $assert(($order[$field] ?? null) === ($before['custom_orders'][0][$field] ?? null), 'Partial header save changed locked ' . $field);
      }
      $shipping = $conn->query("SELECT * FROM order_addresses WHERE order_id=789 AND type='SHIPPING'")->fetch_assoc();
      $production = $conn->query('SELECT * FROM orders WHERE id=789')->fetch_assoc();
      $assert($shipping['street'] === 'Changed 2' && $shipping['email'] === 'new@example.test' && $shipping['phone'] === '', 'Contacts/address must sync');
      $assert($production['note'] === 'Live note' && (float) $production['total'] === 117.5, 'Notes and shipping/DDP must sync');
    } elseif ($case === 'payment') {
      $assert((int) $conn->query('SELECT COUNT(*) FROM custom_order_payments')->fetch_row()[0] === 1, 'Live payments stay editable');
    } elseif ($case === 'note') {
      $assert((int) $conn->query('SELECT COUNT(*) FROM custom_order_notes')->fetch_row()[0] === 1, 'Shared notes stay editable');
    } elseif ($case === 'unexported_item') {
      $item = $conn->query('SELECT * FROM custom_order_items WHERE id=456')->fetch_assoc();
      $assert($item['title'] === 'Changed item' && (int) $item['qty'] === 3, 'Items remain editable before export');
    } elseif ($case === 'unexported_status') {
      $assert($order['status'] === 'DRAFT_SENT', 'Status remains editable before export');
    } else {
      foreach ($tables as $table) $assert($conn->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC) === $before[$table], 'Blocked request changed ' . $table);
      $json = json_decode($response, true);
      $message = $json['message'] ?? ($_SESSION['custom_orders_flash']['message'] ?? '');
      $assert(strpos($message, 'Production') !== false, 'Blocked request must explain lock: ' . $response);
    }
    echo $case, ": OK\n";
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
  }
});
// Execute the real handler without HTTP bootstrap (schema initialization/auth are unrelated to this regression).
$source = file_get_contents(dirname(__DIR__) . '/scripts/custom_orders/' . $endpoint);
$source = str_replace("require_once __DIR__ . '/bootstrap.php';", '', $source);
eval(substr($source, 5));
