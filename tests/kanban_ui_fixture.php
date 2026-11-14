<?php
declare(strict_types=1);
// Static markup only. No authentication bypass, database access or application writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = __DIR__ . '/../.cache/kanban';
if (!is_dir($target)) mkdir($target, 0777, true);
foreach (['sk', 'en'] as $lang) {
    $_SESSION = ['user_id' => 701, 'permission' => 0, 'kanban_csrf' => 'kanban-ui-test-token'];
    $_GET = ['kb_lang' => $lang];
    ob_start();
?><!doctype html><html lang="<?= $lang ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="/darkscrub/"><title>Kanban – UI test</title><link rel="stylesheet" href="dist/css/adminlte.min.css"><link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css"></head><body class="dark-mode" style="padding:24px;background:#343a40"><?php
    require __DIR__ . '/../includes/kanban.php';
?></body></html><?php
    $html = ob_get_clean();
    file_put_contents($target . '/preview-' . $lang . '.html', $html);
    if ($lang === 'sk') file_put_contents($target . '/preview.html', $html);
}
echo "Static SK/EN kanban fixtures generated.\n";
