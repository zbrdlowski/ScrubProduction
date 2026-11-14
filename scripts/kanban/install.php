<?php
declare(strict_types=1);
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    session_start();
    header('Cache-Control: no-store');
    if ((int) ($_SESSION['user_id'] ?? 0) <= 0 || (int) ($_SESSION['permission'] ?? 0) !== 900) {
        http_response_code(403);
        exit('Inštalácia vyžaduje administrátora.');
    }
    if (empty($_SESSION['kanban_install_csrf'])) $_SESSION['kanban_install_csrf'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/service.php';
$run = $isCli ? in_array('--execute', $argv ?? [], true) : ($_SERVER['REQUEST_METHOD'] === 'POST');
if (!$run) {
    if ($isCli) {
        echo "Creates the three personal kanban tables. Existing data is preserved.\n";
        echo "Run: php scripts/kanban/install.php --execute\n";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="sk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Inštalácia kanbanu</title><body style="background:#222a34;color:#ecf1f7;font:16px sans-serif;padding:32px;color-scheme:dark">';
        echo '<h1>Inštalácia osobného kanbanu</h1><p>Vytvorí tri nové tabuľky. Existujúce údaje zostanú zachované.</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['kanban_install_csrf'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="submit" style="padding:12px">Pripraviť kanban</button></form></body></html>';
    }
    exit;
}
if (!$isCli && (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['kanban_install_csrf'], $_POST['csrf']))) {
    http_response_code(403);
    exit('Formulár vypršal. Obnovte stránku.');
}
try {
    $sql = file_get_contents(__DIR__ . '/../../db/kanban.sql');
    if ($sql === false) throw new RuntimeException('Migration file is missing.');
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    if (!kb_ready($pdo)) throw new RuntimeException('Schema verification failed.');
    echo "OK — kanban je pripravený. Otvorte index.php?page=kanban.\n";
} catch (Throwable $e) {
    error_log('Kanban installation: ' . $e->getMessage());
    http_response_code(500);
    if ($isCli) { fwrite(STDERR, 'Installation failed: ' . $e->getMessage() . "\n"); exit(1); }
    echo 'Inštalácia sa nepodarila. Správca nájde podrobnosti v PHP error logu.';
}
