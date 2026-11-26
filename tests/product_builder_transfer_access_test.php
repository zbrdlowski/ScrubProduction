<?php
declare(strict_types=1);
// CLI-only endpoint authorization test. Sessions are kept in memory and no product data is written.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cases = [
    'anonymous' => ['save_transfer_image', false, 'test-token', 401, 'Please sign in to the application.'],
    'manual' => ['save_manual_transfer', true, 'test-token', 422, 'Invalid form data.'],
    'manual-anonymous' => ['save_manual_transfer', false, 'test-token', 401, 'Please sign in to the application.'],
    'manual-csrf' => ['save_manual_transfer', true, '', 403, 'Your form has expired. Refresh the page and try again.'],
    'image' => ['save_transfer_image', true, 'test-token', 422, 'Invalid form data.'],
    'folder' => ['save_transfer_folder', true, 'test-token', 422, 'Invalid form data.'],
    'missing-csrf' => ['save_transfer_image', true, '', 403, 'Your form has expired. Refresh the page and try again.'],
    'invalid-csrf' => ['save_transfer_folder', true, 'wrong-token', 403, 'Your form has expired. Refresh the page and try again.'],
    'complete' => ['save_transfer', true, 'test-token', 403, 'You do not have permission to perform this action.'],
    'product' => ['save_product', true, 'test-token', 403, 'You do not have permission to perform this action.'],
    'product-image' => ['save_product_image', true, 'test-token', 403, 'You do not have permission to perform this action.'],
    'platform' => ['save_channel_listing', true, 'test-token', 403, 'You do not have permission to perform this action.'],
];
if (!isset($argv[1])) {
    foreach (array_keys($cases) as $case) {
        $process = proc_open([PHP_BINARY, __FILE__, $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to start endpoint test.');
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($case . ': ' . $output . $errors);
    }
    echo "Transfer API access: signed-in preparation, CSRF, anonymous rejection and protected product/platform writes OK (temporary permission tables; in-memory sessions).\n";
    exit;
}
if (!isset($cases[$argv[1]])) throw new InvalidArgumentException('Unknown test case.');
[$action, $signedIn, $token, $expectedStatus, $expectedError] = $cases[$argv[1]];
require_once __DIR__ . '/../includes/conn.php';
foreach (['auth_employee_overrides','auth_employee_roles','auth_role_permissions','auth_permissions','auth_roles'] as $table) {
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
    $pdo->exec(preg_replace('/,\s*\)/', "\n)", $ddl));
}
$pdo->exec("INSERT INTO auth_permissions(permission_key,module_key,label,is_active) VALUES ('products.view','Products','View',1),('products.manage','Products','Manage',1)");
// No grants: this employee must not inherit product management access.
ini_set('session.use_cookies', '0');
ini_set('session.serialize_handler', 'php_serialize');
session_cache_limiter('');
$sessionData = serialize($signedIn ? ['permission'=>100,'user_id'=>999001,'product_builder_csrf'=>'test-token'] : []);
session_set_save_handler(
    static function ($path, $name) { return true; },
    static function () { return true; },
    static function ($id) use ($sessionData) { return $sessionData; },
    static function ($id, $data) { return true; },
    static function ($id) { return true; },
    static function ($age) { return 0; }
);
session_id('product-transfer-access-cli-test');
$_GET = ['action'=>$action, 'pb_lang'=>'en'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
ob_start();
register_shutdown_function(static function () use ($expectedStatus, $expectedError) {
    $response = json_decode(ob_get_clean(), true);
    if (http_response_code() !== $expectedStatus || ($response['error'] ?? null) !== $expectedError || ($response['ok'] ?? null) !== false) {
        fwrite(STDERR, 'Unexpected response: ' . http_response_code() . ' ' . json_encode($response) . "\n");
        exit(1);
    }
});
// Empty CLI input deliberately stops allowed writes at payload validation, before any mutation.
require __DIR__ . '/../scripts/product_builder/api.php';
