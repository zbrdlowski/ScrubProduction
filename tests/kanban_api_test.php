<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$case = $argv[1] ?? '';
$cases = ['guest' => 401, 'no_permission' => 401, 'missing_csrf' => 403, 'wrong_csrf' => 403,
    'method' => 405, 'invalid_body' => 400, 'read' => 200, 'foreign_column' => 404];
if ($case === '') {
    $checks = 0;
    foreach (['sk', 'en'] as $lang) foreach ($cases as $name => $expected) {
      foreach (in_array($name, ['read', 'foreign_column', 'missing_csrf'], true) ? [0, 100, 300, 500, 900] : [0] as $level) {
        ++$checks;
        $process = proc_open([PHP_BINARY, __FILE__, $name, $lang, (string) $level], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        if ($exit !== 0 || trim($err) !== 'STATUS:' . $expected || !is_array(json_decode($out, true))) {
            throw new RuntimeException($name . ': ' . $out . ' ' . $err);
        }
        $body = json_decode($out, true);
        $messages = [
            'guest' => ['sk' => 'Prihláste sa do aplikácie.', 'en' => 'Please sign in to the application.'],
            'wrong_csrf' => ['sk' => 'Formulár vypršal. Obnovte stránku.', 'en' => 'Your form has expired. Refresh the page.'],
            'foreign_column' => ['sk' => 'Stĺpec sa nenašiel.', 'en' => 'Column not found.'],
        ];
        if (isset($messages[$name]) && ($body['error'] ?? '') !== $messages[$name][$lang]) {
            throw new RuntimeException($name . ': wrong error language ' . $out);
        }
        if ($name === 'read' && ($body['board']['id'] !== 1 || count($body['columns']) !== 4)) {
            throw new RuntimeException('GET must ignore caller-supplied employee/board IDs.');
        }
    }
    }
    echo 'kanban_api: ' . $checks . " SK/EN request scenarios passed (in-memory sessions and temporary tables).\n";
    exit;
}
if (!isset($cases[$case])) throw new RuntimeException('Unknown case');
// Real endpoint and its real session_start(), without creating an authenticated session file.
final class KanbanTestSession implements SessionHandlerInterface {
    private string $value = '';
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string { return $this->value; }
    public function write(string $id, string $data): bool { $this->value = $data; return true; }
    public function destroy(string $id): bool { $this->value = ''; return true; }
    public function gc(int $max_lifetime): int { return 0; }
}
ini_set('session.use_cookies', '0');
session_set_save_handler(new KanbanTestSession(), true);
session_start();
$_SESSION = ['user_id' => 701, 'permission' => (int) ($argv[3] ?? 0), 'kanban_csrf' => 'test-token'];
if ($case === 'guest') $_SESSION = [];
if ($case === 'no_permission') unset($_SESSION['permission']);
session_write_close();
require __DIR__ . '/../includes/conn.php';
require __DIR__ . '/../scripts/kanban/service.php';
$pdo->exec('CREATE TEMPORARY TABLE employees (id INT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('INSERT INTO employees VALUES (701), (702)');
$sql = preg_replace('/,\s*CONSTRAINT [^\n]+/', '', file_get_contents(__DIR__ . '/../db/kanban.sql'));
$sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql);
foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
kb_initialize($pdo, 701, 'general'); kb_initialize($pdo, 702, 'support');
$_SERVER['REQUEST_METHOD'] = in_array($case, ['missing_csrf', 'wrong_csrf', 'invalid_body'], true) ? 'POST' : 'GET';
$_SERVER['HTTP_X_CSRF_TOKEN'] = $case === 'wrong_csrf' ? 'invalid' : 'test-token';
if ($case === 'missing_csrf') unset($_SERVER['HTTP_X_CSRF_TOKEN']);
if ($case === 'method') $_SERVER['REQUEST_METHOD'] = 'DELETE';
$_GET = ['employee_id' => 702, 'board_id' => 2, 'action' => 'save_card', 'kb_lang' => $argv[2] ?? 'sk'];
if ($case === 'foreign_column') {
    $_GET += ['column_id' => kb_read($pdo, 702, [])['columns'][0]['id'], 'version' => 1];
}
register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200));
});
require __DIR__ . '/../scripts/kanban/api.php';
