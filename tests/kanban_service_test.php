<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/conn.php';
require __DIR__ . '/../scripts/kanban/service.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function fails(callable $fn, int $code): void {
    try { $fn(); } catch (KanbanError $e) { check($e->getCode() === $code, 'Expected HTTP ' . $code . ', got ' . $e->getCode()); return; }
    throw new RuntimeException('Expected failure ' . $code);
}
// Shadow every table on this connection. Never mutate application data.
$pdo->exec('CREATE TEMPORARY TABLE employees (id INT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('INSERT INTO employees VALUES (701), (702)');
$sql = file_get_contents(__DIR__ . '/../db/kanban.sql');
$sql = preg_replace('/,\s*CONSTRAINT [^\n]+/', '', $sql);
$sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql);
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') $pdo->exec($statement);
}
foreach (['employees', 'kanban_boards', 'kanban_columns', 'kanban_cards'] as $table) {
    check(strpos($pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1], 'CREATE TEMPORARY TABLE') === 0, 'Fixtures must be temporary');
}
check(kb_ready($pdo), 'Schema is ready');
check(kb_read($pdo, 701, [])['board'] === null, 'First visit has no board');
check(kb_read($pdo, 701, [])['active_count'] === 0, 'No board has zero active cards');
check(kb_active_card_count($pdo, 0) === 0, 'Guest count is zero');
kb_initialize($pdo, 701, 'general');
kb_initialize($pdo, 702, 'design');
kb_initialize($pdo, 701, 'support');
$a = kb_read($pdo, 701, []);
$b = kb_read($pdo, 702, []);
check(count($a['columns']) === 4 && $a['columns'][0]['title'] === 'Backlog', 'Initialization is idempotent');
check(count($b['columns']) === 5, 'Individual template');
$active = $a['columns'][0]['id'];
$todo = $a['columns'][1]['id'];
$done = $a['columns'][3]['id'];
$foreign = $b['columns'][0]['id'];
$change = static function (string $action, array $data = [], int $employee = 701) use ($pdo): array {
    $version = kb_read($pdo, $employee, [])['board']['version'];
    return kb_mutate($pdo, $employee, $action, $data + ['version' => $version]);
};
$make = static function (string $title, int $column) use ($change): int {
    return $change('save_card', ['title' => $title, 'column_id' => $column])['id'];
};
$id1 = $make('Prvá karta', $active);
$id2 = $make('Druhá karta', $active);
$id3 = $make('Tretia karta', $active);
check(kb_active_card_count($pdo, 701) === 3, 'Backlog cards count as active');
check(kb_active_card_count($pdo, 702) === 0, 'Another employee never counts these cards');
$change('move_card', ['id' => $id3, 'column_id' => $active, 'before_id' => $id1]);
check(array_column(kb_read($pdo, 701, [])['columns'][0]['cards'], 'id') === [$id3, $id1, $id2], 'Within-column ordering persists');
$change('move_card', ['id' => $id1, 'column_id' => $todo]);
check(kb_card($pdo, $a['board']['id'], $id1)['column_id'] == $todo, 'Cross-column move');
$change('move_card', ['id' => $id1, 'column_id' => $active, 'before_id' => $id2]);
check(array_column(kb_read($pdo, 701, [])['columns'][0]['cards'], 'id') === [$id3, $id1, $id2], 'Reverse move');
fails(fn() => $change('move_card', ['id' => $id1, 'column_id' => $foreign]), 404);
fails(fn() => $change('move_card', ['id' => $id1, 'column_id' => $foreign], 702), 404);
fails(fn() => $change('save_card', ['id' => $id1, 'title' => 'Steal', 'column_id' => $foreign], 702), 404);
fails(fn() => $change('delete_card', ['id' => $id1], 702), 404);
fails(fn() => kb_read($pdo, 702, ['column_id' => $active, 'version' => kb_read($pdo, 702, [])['board']['version']]), 404);
$version = kb_read($pdo, 701, [])['board']['version'];
fails(fn() => kb_mutate($pdo, 701, 'settings', ['version' => $version - 1, 'done_limit' => 10]), 409);
fails(fn() => $change('move_card', ['id' => $id1, 'column_id' => $todo, 'before_id' => $id2]), 409);
check(kb_read($pdo, 701, [])['board']['version'] === $version, 'Rejected changes do not increment revision');
$change('move_card', ['id' => $id1, 'column_id' => $done]);
check(kb_card($pdo, $a['board']['id'], $id1)['completed_at'] !== null, 'Completion timestamp');
check(kb_active_card_count($pdo, 701) === 2, 'Done decreases active count');
$pdo->exec("UPDATE kanban_cards SET completed_at = '2020-01-01 00:00:00' WHERE id = $id1");
$extraDone = $change('save_column', ['title' => 'Vyriešené', 'color' => '#34d399', 'is_done' => 1])['id'];
$change('move_card', ['id' => $id1, 'column_id' => $extraDone]);
check(strpos(kb_card($pdo, $a['board']['id'], $id1)['completed_at'], '2020-01-01') === 0, 'Done-to-done preserves timestamp');
check(kb_active_card_count($pdo, 701) === 2, 'Custom completed columns are excluded too');
$change('move_card', ['id' => $id1, 'column_id' => $active]);
check(kb_card($pdo, $a['board']['id'], $id1)['completed_at'] === null, 'Reopen clears timestamp');
check(kb_active_card_count($pdo, 701) === 3, 'Reopened card increases active count');
$change('move_card', ['id' => $id1, 'column_id' => $done]);
check(strpos(kb_card($pdo, $a['board']['id'], $id1)['completed_at'], '2020-01-01') !== 0, 'Recompletion gets new timestamp');
$change('settings', ['done_limit' => 2]);
$doneIds = [$id1];
for ($i = 0; $i < 4; ++$i) $doneIds[] = $make('Hotová ' . $i, $done);
$doneCol = array_values(array_filter(kb_read($pdo, 701, [])['columns'], fn($c) => $c['id'] === $done))[0];
check(count($doneCol['cards']) === 2 && $doneCol['total'] === 5 && $doneCol['has_more'], 'Done uses bounded server-side page');
check($doneCol['cards'][0]['id'] === end($doneIds), 'Newest completion first');
$board = kb_read($pdo, 701, [])['board'];
$page = kb_read($pdo, 701, ['column_id' => $done, 'version' => $board['version'], 'offset' => 2]);
check(count($page['cards']) === 2 && !array_intersect(array_column($page['cards'], 'id'), array_column($doneCol['cards'], 'id')), 'Older page without duplicates');
check((int) $pdo->query("SELECT COUNT(*) FROM kanban_cards WHERE column_id = $done")->fetchColumn() === 5, 'Limit never deletes data');
$search = kb_read($pdo, 701, ['search' => 'Hotová 0']);
$found = array_merge(...array_column($search['columns'], 'cards'));
check(count($found) === 1 && $found[0]['title'] === 'Hotová 0', 'Search reaches hidden done history');
$percent = $make('Zľava 50%_!', $active);
$literal = kb_read($pdo, 701, ['search' => '%_!']);
check(count(array_merge(...array_column($literal['columns'], 'cards'))) === 1, 'LIKE wildcards treated literally');
foreach ([
    ['title' => ''], ['title' => str_repeat('x', 201)], ['due_date' => '2026-02-30'],
    ['priority' => 'unknown'], ['reference_url' => 'javascript:alert(1)'], ['title' => ['array']],
] as $invalid) {
    fails(fn() => $change('save_card', $invalid + ['title' => 'Bad', 'column_id' => $active]), 422);
}
fails(fn() => $change('settings', ['done_limit' => 0]), 422);
fails(fn() => $change('settings', ['done_limit' => 101]), 422);
fails(fn() => $change('save_column', ['title' => 'Bad', 'color' => 'red']), 422);
fails(fn() => $change('delete_column', ['id' => $done]), 422);
$change('move_column', ['id' => $done, 'before_id' => $active]);
check(kb_read($pdo, 701, [])['columns'][0]['id'] === $done, 'Column order persists');
$change('save_column', ['id' => $active, 'title' => 'Archivované', 'color' => '#123456', 'is_done' => 1]);
check(kb_card($pdo, $a['board']['id'], $id2)['completed_at'] !== null, 'Column completion toggle applies to existing cards');
check(kb_active_card_count($pdo, 701) === 0, 'Completion flag excludes all cards irrespective of column name');
$change('save_column', ['id' => $active, 'title' => 'Rozpracované', 'color' => '#123456', 'is_done' => 0]);
check(kb_card($pdo, $a['board']['id'], $id2)['completed_at'] === null, 'Column reopening clears completion');
$change('delete_column', ['id' => $extraDone]);
fails(fn() => kb_column($pdo, $a['board']['id'], $extraDone), 404);
$change('delete_card', ['id' => $percent]);
fails(fn() => kb_card($pdo, $a['board']['id'], $percent), 404);
$beforeForeign = kb_read($pdo, 702, []);
check(count(array_merge(...array_column($beforeForeign['columns'], 'cards'))) === 0, 'Other employee data is untouched');
fails(fn() => kb_read($pdo, 701, ['column_id' => $done, 'version' => $board['version'], 'offset' => 2]), 409);
// Pagination must not scramble cards beyond the first page.
for ($i = 0; $i < 105; ++$i) $make('Batch ' . $i, $todo);
$todoCol = array_values(array_filter(kb_read($pdo, 701, [])['columns'], fn($c) => $c['id'] === $todo))[0];
check(count($todoCol['cards']) === 100 && $todoCol['total'] === 105, 'Active lists are paginated too');
check(kb_active_card_count($pdo, 701) === 107, 'Count includes cards beyond active page limit');
$filteredCount = kb_read($pdo, 701, ['search' => 'Hotová 0']);
check($filteredCount['active_count'] === 107 && count(array_merge(...array_column($filteredCount['columns'], 'cards'))) === 1, 'Searching completed history never changes active total');
$last = (int) $pdo->query("SELECT id FROM kanban_cards WHERE column_id = $todo ORDER BY position DESC, id DESC LIMIT 1")->fetchColumn();
$change('move_card', ['id' => $last, 'column_id' => $todo, 'before_id' => $todoCol['cards'][0]['id']]);
$ids = array_map('intval', $pdo->query("SELECT id FROM kanban_cards WHERE column_id = $todo ORDER BY position, id")->fetchAll(PDO::FETCH_COLUMN));
check($ids[0] === $last && count(array_unique($ids)) === 105, 'Move keeps all paginated cards');
// Reapplying a template must preserve every card, including both pagination windows.
$secondDone = $change('save_column', ['title' => 'Archive', 'color' => '#34d399', 'is_done' => 1])['id'];
$change('move_card', ['id' => $doneIds[0], 'column_id' => $secondDone]);
$change('save_card', ['id' => $id2, 'column_id' => $active, 'title' => 'Full details', 'description' => 'Preserve this text',
    'priority' => 'urgent', 'due_date' => '2027-01-01', 'label' => 'My label', 'reference' => 'Order 123', 'reference_url' => 'https://example.com/123']);
$allBefore = $pdo->query('SELECT * FROM kanban_cards ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$activeOrder = array_map('intval', kb_query($pdo, 'SELECT c.id FROM kanban_cards c JOIN kanban_columns col ON col.id = c.column_id
    WHERE col.board_id = ? AND col.is_done = 0 ORDER BY col.position, col.id, c.position, c.id', [$a['board']['id']])->fetchAll(PDO::FETCH_COLUMN));
$beforeTemplate = kb_read($pdo, 701, []);
$foreignBefore = kb_read($pdo, 702, []);
fails(fn() => $change('apply_template', ['template' => 'invalid']), 422);
fails(fn() => kb_mutate($pdo, 701, 'apply_template', ['template' => 'design', 'version' => $beforeTemplate['board']['version'] - 1]), 409);
check(kb_read($pdo, 701, []) === $beforeTemplate, 'Invalid or stale template leaves board unchanged');
$GLOBALS['kbLanguage'] = 'sk';
$change('apply_template', ['template' => 'design']);
$afterTemplate = kb_read($pdo, 701, []);
check(count($afterTemplate['columns']) === 5 && $afterTemplate['columns'][0]['title'] === 'Zadanie', 'Template uses the selected language');
check($afterTemplate['board']['id'] === $beforeTemplate['board']['id'] && $afterTemplate['board']['done_limit'] === 2, 'Board identity and done limit are preserved');
check($afterTemplate['board']['version'] === $beforeTemplate['board']['version'] + 1, 'Template increments version once');
$allAfter = $pdo->query('SELECT * FROM kanban_cards ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(count($allAfter) === count($allBefore), 'No cards lost, including history beyond the visible page');
$firstId = $afterTemplate['columns'][0]['id'];
$lastId = $afterTemplate['columns'][4]['id'];
foreach ($allBefore as $i => $old) {
    $new = $allAfter[$i];
    check((int) $new['column_id'] === ($old['completed_at'] === null ? $firstId : $lastId), 'Card goes to appropriate active or completed column');
    unset($old['column_id'], $old['position'], $new['column_id'], $new['position']);
    check($new === $old, 'Card content, identity and all dates are preserved');
}
check(array_map('intval', kb_query($pdo, 'SELECT id FROM kanban_cards WHERE column_id = ? ORDER BY position, id', [$firstId])->fetchAll(PDO::FETCH_COLUMN)) === $activeOrder, 'Active cards retain relative order');
check(!array_intersect(array_column($beforeTemplate['columns'], 'id'), array_column($afterTemplate['columns'], 'id')), 'Old columns are replaced after moving cards');
check(kb_read($pdo, 702, []) === $foreignBefore, 'Template change does not affect another employee');
check((int) $pdo->query('SELECT COUNT(*) FROM kanban_cards c LEFT JOIN kanban_columns col ON col.id = c.column_id WHERE col.id IS NULL')->fetchColumn() === 0, 'No orphan cards');
$change('apply_template', ['template' => 'support']);
check(count(kb_read($pdo, 701, [])['columns']) === 4, 'Further template changes do not accumulate columns');
fails(fn() => $change('move_card', ['id' => $id2, 'column_id' => $active]), 404);
$change('apply_template', ['template' => 'support'], 702);
check(count(kb_read($pdo, 702, [])['columns']) === 4, 'Empty board can change template too');

echo "kanban_service: $checks checks passed (temporary tables only).\n";
