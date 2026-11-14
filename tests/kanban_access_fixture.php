<?php
declare(strict_types=1);
// Render the real application shell with an in-memory session. No logged-in session file is created.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$level = $argv[1] ?? '';
if ($level === '') {
    foreach ([0, 100, 300, 500, 900] as $permission) foreach (['sk', 'en'] as $language) {
        $process = proc_open([PHP_BINARY, __FILE__, (string) $permission, $language], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        if ($exit !== 0 || $err !== '') throw new RuntimeException($permission . '/' . $language . ': ' . $err . $out);
        echo $out;
    }
    exit;
}
if (!in_array($level, ['0', '100', '300', '500', '900'], true) || !in_array($argv[2] ?? '', ['sk', 'en'], true)) exit(1);
final class KanbanShellSession implements SessionHandlerInterface {
    private string $data = '';
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string { return $this->data; }
    public function write(string $id, string $data): bool { $this->data = $data; return true; }
    public function destroy(string $id): bool { $this->data = ''; return true; }
    public function gc(int $max_lifetime): int { return 0; }
}
session_set_save_handler(new KanbanShellSession(), true);
session_start();
$_SESSION = ['user_id' => 2147483000, 'permission' => (int) $level, 'dpt' => 6,
    'name' => 'Kanban test', 'dpt_name' => 'Test department', 'user_photo' => '', 'kanban_csrf' => 'kanban-ui-test-token'];
session_write_close();
$_GET = ['page' => 'kanban', 'kb_lang' => $argv[2]];
$_SERVER['PHP_SELF'] = '/darkscrub/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/darkscrub/index.php?page=kanban&kb_lang=' . $argv[2];
chdir(dirname(__DIR__));
ob_start();
require 'index.php';
$html = ob_get_clean();
foreach (['id="kb-app"', 'id="kb-guide-open"', 'id="kb-guide-dialog"', 'data-kb-language="sk"', 'data-kb-language="en"', 'id="kb-templates-open"'] as $element) {
    if (strpos($html, $element) === false) throw new RuntimeException('Missing UI element: ' . $element);
}
if (http_response_code() && http_response_code() !== 200) throw new RuntimeException('Page blocked');
$target = __DIR__ . '/../.cache/kanban';
if (!is_dir($target)) mkdir($target, 0777, true);
file_put_contents($target . '/shell-' . $level . '-' . $argv[2] . '.html', $html);
echo 'Kanban shell: permission ' . $level . ', ' . $argv[2] . " rendered.\n";
