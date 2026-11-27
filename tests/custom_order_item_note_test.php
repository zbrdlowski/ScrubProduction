<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cases = ['assigned', 'unassigned', 'item_assignment_only', 'owner_only', 'different_order', 'removed_assignment',
  'exported', 'timestamp_only', 'bad_csrf', 'missing_note', 'array_note', 'clear', 'noop', 'tampered', 'manager', 'no_work',
  'ui_assigned', 'ui_unassigned', 'ui_exported', 'ui_manager', 'ui_list'];
$case = $argv[1] ?? '';
if ($case === '') {
  foreach ($cases as $name) {
    $process = proc_open([PHP_BINARY, __FILE__, $name], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($out) !== $name . ': OK' || trim($err) !== '') throw new RuntimeException($name . ': ' . $out . $err);
  }
  echo 'custom_order_item_note: ', count($cases), " scenarios passed\n";
  exit;
}
if (!in_array($case, $cases, true)) throw new RuntimeException('Unknown case.');
require dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
// The complete page and real authorization use these two connections. Shadow both.
$tables = ['custom_orders', 'custom_order_items', 'custom_order_assignments', 'custom_order_item_assignments',
  'custom_order_payments', 'custom_order_followups', 'custom_order_activity', 'custom_order_notes',
  'custom_order_note_revisions', 'custom_order_photos', 'custom_order_contacts', 'custom_order_number_sequences',
  'orders', 'order_items', 'order_addresses', 'order_activity', 'order_photos', 'order_invoices', 'order_tracking_numbers', 'customers'];
foreach ($tables as $table) {
  $ddl = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
  $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
  $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
  $conn->query(preg_replace('/,\s*\)/', "\n)", $ddl));
  $assert(strpos($conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1], 'CREATE TEMPORARY TABLE') === 0, 'Fixture must be temporary');
}
foreach (['auth_employee_overrides', 'auth_employee_roles', 'auth_role_permissions', 'auth_permissions', 'auth_roles'] as $table) {
  $ddl = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
  $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl);
  $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
  $pdo->exec(preg_replace('/,\s*\)/', "\n)", $ddl));
}
$pdo->exec("INSERT INTO auth_permissions(id,permission_key,module_key,label,is_active) VALUES
  (1,'custom_orders.view','Custom','View',1),(2,'custom_orders.work','Custom','Work',1),
  (3,'custom_orders.manage','Custom','Manage',1),(4,'custom_orders.items.delete','Custom','Delete',1),
  (5,'custom_orders.delete','Custom','Delete order',1),(6,'custom_orders.audit','Custom','Audit',1)");
$pdo->exec("INSERT INTO auth_employee_overrides(employee_id,permission_id,effect) VALUES (999001,1,'allow')");
if ($case !== 'no_work') $pdo->exec("INSERT INTO auth_employee_overrides(employee_id,permission_id,effect) VALUES (999001,2,'allow')");
require_once dirname(__DIR__) . '/includes/auth.php';
$_SESSION = ['user_id' => 999001, 'permission' => in_array($case, ['manager','ui_manager'], true) ? 900 : 100,
  'custom_order_item_note_csrf' => 'fixture-csrf-token'];
$conn->query("INSERT INTO custom_orders (id,internal_code,official_order_number,status,owner_employee_id,customer_name,
  shipping_name,shipping_street,shipping_city,shipping_zip,shipping_country,shipping_email,currency)
  VALUES (123,'CO-NOTE','SO-NOTE','LEAD',999002,'Fixture','Fixture','Test 1','Test','12345','SK','note@example.test','EUR'),
         (124,'CO-OTHER','SO-OTHER','LEAD',999002,'Fixture','Fixture','Test 1','Test','12345','SK','other@example.test','EUR')");
$originalOptions = ['my-item-note' => 'Original Scrub note', 'buyer-note' => 'Buyer instructions',
  'category_info' => 'Test category', 'category_modelcode' => 'TEST1', 'base-material' => 'Standard', 'nested' => ['keep' => 1]];
$optionsJson = json_encode($originalOptions, JSON_THROW_ON_ERROR);
$stmt = $conn->prepare("INSERT INTO custom_order_items
  (id,custom_order_id,line_no,item_type_code,sku,title,qty,unit_price,options_json,internal_options_json)
  VALUES (456,123,1,'G','TEST','Original item',2,150,?,'{\"keep\":true}'),(457,124,1,'G','OTHER','Other item',1,100,?,'{}')");
$stmt->bind_param('ss', $optionsJson, $optionsJson); $stmt->execute(); $stmt->close();
$unassigned = in_array($case, ['unassigned','item_assignment_only','owner_only','ui_unassigned','ui_list'], true);
if (!$unassigned) $conn->query('INSERT INTO custom_order_assignments (id,custom_order_id,employee_id,assigned_by) VALUES (234,123,999001,999001)');
if ($case === 'item_assignment_only') $conn->query('INSERT INTO custom_order_item_assignments (custom_order_id,custom_order_item_id,employee_id,assigned_by) VALUES (123,456,999001,999001)');
if ($case === 'owner_only') $conn->query('UPDATE custom_orders SET owner_employee_id=999001 WHERE id=123');
if (in_array($case, ['exported','timestamp_only','ui_exported'], true)) $conn->query("UPDATE custom_orders SET status='EXPORTED',production_order_id=789,exported_at=NOW() WHERE id=123");
if ($case === 'timestamp_only') $conn->query('UPDATE custom_orders SET production_order_id=NULL WHERE id=123');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/scripts/custom_orders/save_item_note.php';
$_POST = ['custom_order_id' => 123, 'custom_item_id' => 456, 'inline_edit' => 1, 'csrf_token' => 'fixture-csrf-token', 'scrub_note' => 'Updated Scrub note'];
if ($case === 'different_order') $_POST['custom_item_id'] = 457;
if ($case === 'removed_assignment') $conn->query('DELETE FROM custom_order_assignments');
if ($case === 'bad_csrf') $_POST['csrf_token'] = 'wrong';
if ($case === 'missing_note') unset($_POST['scrub_note']);
if ($case === 'array_note') $_POST['scrub_note'] = ['bad'];
if ($case === 'clear') $_POST['scrub_note'] = '';
if ($case === 'noop') $_POST['scrub_note'] = 'Original Scrub note';
if ($case === 'tampered') $_POST += ['title' => 'Changed', 'unit_price' => 1, 'qty' => 99, 'item_type_code' => 'P', 'status' => 'EXPORTED',
  'spec_graphics_buyer_note' => 'Changed buyer note', 'options_json' => '{}', 'internal_options_json' => '{}'];
if (strpos($case, 'ui_') === 0) {
  $_GET = $case === 'ui_list' ? ['help_lang' => 'en'] : ['custom_order_id' => 123, 'detail' => '1', 'help_lang' => 'en'];
  $_SERVER['REQUEST_URI'] = '/darkscrub/index.php?page=custom_orders';
  $file = dirname(__DIR__) . '/includes/custom_orders.php';
  $source = str_replace('customOrdersEnsureSchema($conn);', '', file_get_contents($file));
  $source = str_replace('__DIR__', var_export(dirname($file), true), $source);
  ob_start(); eval(substr($source, 5)); $html = ob_get_clean();
  if ($case === 'ui_assigned') {
    $assert(strpos($html, 'action="scripts/custom_orders/save_item_note.php"') !== false, 'Assigned worker needs note action');
    $assert(strpos($html, 'name="scrub_note"') !== false, 'Assigned worker needs note input');
  } else $assert(strpos($html, 'name="scrub_note"') === false, 'Do not grant note-only controls to other states');
  $target = dirname(__DIR__) . '/.cache/custom-order-item-note';
  if (!is_dir($target)) mkdir($target, 0777, true);
  $assets = '<base href="http://localhost/darkscrub/"><link rel="stylesheet" href="dist/css/adminlte.min.css"><script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>';
  file_put_contents($target . '/' . $case . '.html', '<!doctype html><html><head><meta charset="utf-8">' . $assets . '</head><body class="dark-mode" style="background:#172735;padding:20px">' . $html . '</body></html>');
  echo $case, ": OK\n"; exit;
}
$before = [];
foreach ($tables as $table) $before[$table] = $conn->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC);
ob_start();
register_shutdown_function(static function () use ($conn, $case, $tables, $before, $originalOptions, $assert): void {
  $raw = ob_get_clean(); $response = json_decode($raw, true);
  try {
    $success = in_array($case, ['assigned','clear','noop','tampered','manager'], true);
    $assert($case === 'no_work' ? http_response_code() === 403 : ($response['ok'] ?? null) === $success, 'Unexpected response: ' . $raw);
    foreach ($tables as $table) {
      $after = $conn->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC);
      if ($success && $case !== 'noop' && $table === 'custom_order_items') {
        $expectedOptions = $originalOptions;
        if ($case === 'clear') unset($expectedOptions['my-item-note']);
        else $expectedOptions['my-item-note'] = 'Updated Scrub note';
        $assert(json_decode($after[0]['options_json'], true) === $expectedOptions, 'Only the Scrub note option may change');
        $assert((int) $after[0]['updated_by'] === 999001, 'Record the author');
        foreach (['options_json','updated_by','updated_at'] as $field) $after[0][$field] = $before[$table][0][$field];
      } elseif ($success && $case !== 'noop' && $table === 'custom_order_activity') {
        $assert(count($after) === 1 && $after[0]['action'] === 'item_updated', 'Expected one audit entry');
        $payload = json_decode($after[0]['payload'], true);
        $assert($payload['changes'][0]['from'] === 'Original Scrub note' && $payload['changes'][0]['to'] === ($case === 'clear' ? '' : 'Updated Scrub note'), 'Audit needs old and new note');
        continue;
      }
      $assert($after === $before[$table], 'Unexpected write to ' . $table);
    }
    echo $case, ": OK\n";
  } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
});
// Execute the real permission map and handler, omitting only session I/O and schema DDL.
$bootstrapFile = dirname(__DIR__) . '/scripts/custom_orders/bootstrap.php';
$bootstrap = str_replace(['session_start();','customOrdersEnsureSchema($conn);'], '', file_get_contents($bootstrapFile));
$bootstrap = str_replace('__DIR__', var_export(dirname($bootstrapFile), true), $bootstrap);
eval(substr($bootstrap, 5));
$source = str_replace("require_once __DIR__ . '/bootstrap.php';", '', file_get_contents(dirname(__DIR__) . '/scripts/custom_orders/save_item_note.php'));
eval(substr($source, 5));
