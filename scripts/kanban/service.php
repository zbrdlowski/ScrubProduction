<?php
declare(strict_types=1);
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/count.php';

final class KanbanError extends RuntimeException {}

function kb_query(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function kb_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id, employee_id, done_limit, version FROM kanban_boards LIMIT 0');
        $pdo->query('SELECT id, board_id, title, color, position, is_done FROM kanban_columns LIMIT 0');
        $pdo->query('SELECT id, column_id, title, description, priority, due_date, label, reference,
            reference_url, position, completed_at, created_at, updated_at FROM kanban_cards LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function kb_text($value, string $label, int $max, bool $required = false): string
{
    if (!is_string($value)) throw new KanbanError(kb_t('{label}: invalid value.', ['label' => kb_t($label)]), 422);
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $max
        || !mb_check_encoding($value, 'UTF-8')) {
        throw new KanbanError(kb_t('{label}: enter no more than {max} characters.', ['label' => kb_t($label), 'max' => $max]), 422);
    }
    return $value;
}

function kb_int($value, int $min, int $max, string $label): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
        throw new KanbanError(kb_t('{label}: invalid value.', ['label' => kb_t($label)]), 422);
    }
    return (int) $value;
}

function kb_column(PDO $pdo, int $boardId, int $id): array
{
    $row = kb_query($pdo, 'SELECT * FROM kanban_columns WHERE board_id = ? AND id = ?', [$boardId, $id])->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new KanbanError(kb_t('Column not found.'), 404);
    return $row;
}

function kb_card(PDO $pdo, int $boardId, int $id): array
{
    $row = kb_query($pdo, 'SELECT c.*, col.is_done FROM kanban_cards c
        JOIN kanban_columns col ON col.id = c.column_id WHERE col.board_id = ? AND c.id = ?',
        [$boardId, $id])->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new KanbanError(kb_t('Card not found.'), 404);
    return $row;
}

function kb_templates(): array
{
    return [
        'general' => [[kb_t('Backlog'), '#94a3b8'], [kb_t('To do'), '#60a5fa'], [kb_t('In progress'), '#fbbf24'], [kb_t('Done'), '#34d399']],
        'design' => [[kb_t('Brief'), '#94a3b8'], [kb_t('In progress'), '#60a5fa'], [kb_t('For approval'), '#c084fc'], [kb_t('Revisions'), '#fbbf24'], [kb_t('Done'), '#34d399']],
        'support' => [[kb_t('New'), '#60a5fa'], [kb_t('Handling'), '#fbbf24'], [kb_t('Waiting for customer'), '#c084fc'], [kb_t('Resolved'), '#34d399']],
    ];
}

/** Create the template columns on the locked board and return their IDs in order. */
function kb_create_template_columns(PDO $pdo, int $boardId, array $template): array
{
    $ids = [];
    foreach ($template as $position => $col) {
        kb_query($pdo, 'INSERT INTO kanban_columns (board_id, title, color, position, is_done) VALUES (?, ?, ?, ?, ?)',
            [$boardId, $col[0], $col[1], $position, $position === count($template) - 1 ? 1 : 0]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    return $ids;
}

function kb_initialize(PDO $pdo, int $employeeId, string $template): void
{
    $templates = kb_templates();
    if (!isset($templates[$template])) throw new KanbanError(kb_t('Choose a valid template.'), 422);
    $pdo->beginTransaction();
    try {
        // The unique employee key makes retries and simultaneous first visits idempotent.
        kb_query($pdo, 'INSERT INTO kanban_boards (employee_id) VALUES (?)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [$employeeId]);
        $board = kb_query($pdo, 'SELECT * FROM kanban_boards WHERE employee_id = ? FOR UPDATE', [$employeeId])->fetch(PDO::FETCH_ASSOC);
        $count = (int) kb_query($pdo, 'SELECT COUNT(*) FROM kanban_columns WHERE board_id = ?', [$board['id']])->fetchColumn();
        if ($count === 0) {
            kb_create_template_columns($pdo, (int) $board['id'], $templates[$template]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function kb_card_values(array $data): array
{
    $due = kb_text($data['due_date'] ?? '', 'Due date', 10);
    if ($due !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
        if (!$parsed || $parsed->format('Y-m-d') !== $due || $due < '1000-01-01') {
            throw new KanbanError(kb_t('Enter a valid due date.'), 422);
        }
    }
    $priority = kb_text($data['priority'] ?? 'normal', 'Priority', 10);
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) throw new KanbanError(kb_t('Invalid priority.'), 422);
    $url = kb_text($data['reference_url'] ?? '', 'Link', 1000);
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url))) {
        throw new KanbanError(kb_t('The link must be a full http or https URL.'), 422);
    }
    return [
        kb_text($data['title'] ?? '', 'Title', 200, true),
        kb_text($data['description'] ?? '', 'Description', 10000),
        $priority, $due !== '' ? $due : null,
        kb_text($data['label'] ?? '', 'Label', 60),
        kb_text($data['reference'] ?? '', 'Customer / reference', 160),
        $url,
    ];
}

/** Move by neighbouring ID so unseen, paginated cards keep their relative order. */
function kb_move(PDO $pdo, array $card, array $target, int $beforeId): void
{
    $cardId = (int) $card['id'];
    $targetId = (int) $target['id'];
    if (!(int) $target['is_done']) {
        $ids = array_map('intval', kb_query($pdo, 'SELECT id FROM kanban_cards
            WHERE column_id = ? AND id <> ? ORDER BY position, id', [$targetId, $cardId])->fetchAll(PDO::FETCH_COLUMN));
        if ($beforeId !== 0) {
            $index = array_search($beforeId, $ids, true);
            if ($index === false) throw new KanbanError(kb_t('The drop position no longer exists. Refresh the board.'), 409);
        } else {
            $index = count($ids);
        }
        array_splice($ids, $index, 0, [$cardId]);
        foreach ($ids as $position => $id) {
            kb_query($pdo, 'UPDATE kanban_cards SET position = ? WHERE id = ?', [$position, $id]);
        }
    }
    // Moving within/between done columns preserves completion time.
    $completed = (int) $target['is_done']
        ? ((int) $card['is_done'] ? 'completed_at' : 'CURRENT_TIMESTAMP(6)')
        : 'NULL';
    kb_query($pdo, "UPDATE kanban_cards SET column_id = ?, completed_at = $completed WHERE id = ?", [$targetId, $cardId]);
}

function kb_mutate(PDO $pdo, int $employeeId, string $action, array $data): array
{
    $pdo->beginTransaction();
    try {
        $board = kb_query($pdo, 'SELECT * FROM kanban_boards WHERE employee_id = ? FOR UPDATE', [$employeeId])->fetch(PDO::FETCH_ASSOC);
        if (!$board) throw new KanbanError(kb_t('Create a board first.'), 404);
        $version = kb_int($data['version'] ?? 0, 1, PHP_INT_MAX, 'Version');
        if ($version !== (int) $board['version']) throw new KanbanError(kb_t('The board was changed in another window. Refresh it and try again.'), 409);
        $boardId = (int) $board['id'];
        $result = [];
        switch ($action) {
            case 'apply_template':
                $template = kb_text($data['template'] ?? '', 'Template', 20, true);
                $templates = kb_templates();
                if (!isset($templates[$template])) throw new KanbanError(kb_t('Choose a valid template.'), 422);
                $oldColumns = array_map('intval', kb_query($pdo, 'SELECT id FROM kanban_columns WHERE board_id = ?',
                    [$boardId])->fetchAll(PDO::FETCH_COLUMN));
                // Read all cards, including paginated history, before replacing the columns.
                $cards = kb_query($pdo, 'SELECT c.id, col.is_done FROM kanban_cards c
                    JOIN kanban_columns col ON col.id = c.column_id
                    WHERE col.board_id = ? ORDER BY col.position, col.id, c.position, c.id', [$boardId])->fetchAll(PDO::FETCH_ASSOC);
                $newColumns = kb_create_template_columns($pdo, $boardId, $templates[$template]);
                $positions = [0, 0];
                foreach ($cards as $card) {
                    $done = (int) $card['is_done'];
                    $target = $done ? $newColumns[count($newColumns) - 1] : $newColumns[0];
                    // Keep content, IDs, original completion times and edit dates intact.
                    kb_query($pdo, 'UPDATE kanban_cards SET column_id = ?, position = ?, updated_at = updated_at WHERE id = ?',
                        [$target, $positions[$done]++, $card['id']]);
                }
                if ($oldColumns) {
                    $placeholders = implode(',', array_fill(0, count($oldColumns), '?'));
                    kb_query($pdo, "DELETE FROM kanban_columns WHERE board_id = ? AND id IN ($placeholders)", array_merge([$boardId], $oldColumns));
                }
                break;
            case 'settings':
                $limit = kb_int($data['done_limit'] ?? 20, 1, 100, 'Number of completed cards');
                kb_query($pdo, 'UPDATE kanban_boards SET done_limit = ? WHERE id = ?', [$limit, $boardId]);
                break;
            case 'save_column':
                $id = kb_int($data['id'] ?? 0, 0, PHP_INT_MAX, 'Column');
                $title = kb_text($data['title'] ?? '', 'Column name', 80, true);
                $color = kb_text($data['color'] ?? '#60a5fa', 'Colour', 7);
                if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) throw new KanbanError(kb_t('Invalid colour.'), 422);
                $done = kb_int($data['is_done'] ?? 0, 0, 1, 'Completed column');
                if ($id) {
                    $col = kb_column($pdo, $boardId, $id);
                    kb_query($pdo, 'UPDATE kanban_columns SET title = ?, color = ?, is_done = ? WHERE id = ?', [$title, $color, $done, $id]);
                    if ($done !== (int) $col['is_done']) {
                        $completed = $done ? 'CURRENT_TIMESTAMP(6)' : 'NULL';
                        kb_query($pdo, "UPDATE kanban_cards SET completed_at = $completed WHERE column_id = ?", [$id]);
                    }
                } else {
                    $count = (int) kb_query($pdo, 'SELECT COUNT(*) FROM kanban_columns WHERE board_id = ?', [$boardId])->fetchColumn();
                    if ($count >= 20) throw new KanbanError(kb_t('A board can have no more than 20 columns.'), 422);
                    $position = (int) kb_query($pdo, 'SELECT COALESCE(MAX(position), -1) + 1 FROM kanban_columns WHERE board_id = ?', [$boardId])->fetchColumn();
                    kb_query($pdo, 'INSERT INTO kanban_columns (board_id, title, color, position, is_done) VALUES (?, ?, ?, ?, ?)',
                        [$boardId, $title, $color, $position, $done]);
                    $id = (int) $pdo->lastInsertId();
                }
                $result['id'] = $id;
                break;
            case 'move_column':
                $id = kb_int($data['id'] ?? 0, 1, PHP_INT_MAX, 'Column');
                kb_column($pdo, $boardId, $id);
                $before = kb_int($data['before_id'] ?? 0, 0, PHP_INT_MAX, 'Position');
                $ids = array_map('intval', kb_query($pdo, 'SELECT id FROM kanban_columns WHERE board_id = ? AND id <> ? ORDER BY position, id',
                    [$boardId, $id])->fetchAll(PDO::FETCH_COLUMN));
                $index = $before ? array_search($before, $ids, true) : count($ids);
                if ($index === false) throw new KanbanError(kb_t('Invalid drop position.'), 422);
                array_splice($ids, $index, 0, [$id]);
                foreach ($ids as $position => $colId) kb_query($pdo, 'UPDATE kanban_columns SET position = ? WHERE id = ?', [$position, $colId]);
                break;
            case 'delete_column':
                $id = kb_int($data['id'] ?? 0, 1, PHP_INT_MAX, 'Column');
                kb_column($pdo, $boardId, $id);
                if ((int) kb_query($pdo, 'SELECT COUNT(*) FROM kanban_cards WHERE column_id = ?', [$id])->fetchColumn()) {
                    throw new KanbanError(kb_t('The column contains cards. Move them to another column first.'), 422);
                }
                if ((int) kb_query($pdo, 'SELECT COUNT(*) FROM kanban_columns WHERE board_id = ?', [$boardId])->fetchColumn() <= 1) {
                    throw new KanbanError(kb_t('Keep at least one column.'), 422);
                }
                kb_query($pdo, 'DELETE FROM kanban_columns WHERE id = ?', [$id]);
                break;
            case 'save_card':
                $id = kb_int($data['id'] ?? 0, 0, PHP_INT_MAX, 'Card');
                $target = kb_column($pdo, $boardId, kb_int($data['column_id'] ?? 0, 1, PHP_INT_MAX, 'Column'));
                $values = kb_card_values($data);
                if ($id) {
                    $card = kb_card($pdo, $boardId, $id);
                    kb_query($pdo, 'UPDATE kanban_cards SET title = ?, description = ?, priority = ?, due_date = ?,
                        label = ?, reference = ?, reference_url = ? WHERE id = ?', array_merge($values, [$id]));
                    if ((int) $card['column_id'] !== (int) $target['id']) kb_move($pdo, $card, $target, 0);
                } else {
                    $position = (int) kb_query($pdo, 'SELECT COALESCE(MAX(position), -1) + 1 FROM kanban_cards WHERE column_id = ?', [$target['id']])->fetchColumn();
                    $completed = (int) $target['is_done'] ? 'CURRENT_TIMESTAMP(6)' : 'NULL';
                    kb_query($pdo, "INSERT INTO kanban_cards (title, description, priority, due_date, label, reference,
                        reference_url, column_id, position, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, $completed)",
                        array_merge($values, [$target['id'], $position]));
                    $id = (int) $pdo->lastInsertId();
                }
                $result['id'] = $id;
                break;
            case 'move_card':
                $card = kb_card($pdo, $boardId, kb_int($data['id'] ?? 0, 1, PHP_INT_MAX, 'Card'));
                $target = kb_column($pdo, $boardId, kb_int($data['column_id'] ?? 0, 1, PHP_INT_MAX, 'Column'));
                kb_move($pdo, $card, $target, kb_int($data['before_id'] ?? 0, 0, PHP_INT_MAX, 'Position'));
                break;
            case 'delete_card':
                $id = kb_int($data['id'] ?? 0, 1, PHP_INT_MAX, 'Card');
                kb_card($pdo, $boardId, $id);
                kb_query($pdo, 'DELETE FROM kanban_cards WHERE id = ?', [$id]);
                break;
            default:
                throw new KanbanError(kb_t('Unknown action.'), 400);
        }
        kb_query($pdo, 'UPDATE kanban_boards SET version = version + 1 WHERE id = ?', [$boardId]);
        $pdo->commit();
        return $result + ['version' => $version + 1];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function kb_column_page(PDO $pdo, array $column, int $limit, int $offset, string $search): array
{
    $params = [(int) $column['id']];
    $where = 'column_id = ?';
    if ($search !== '') {
        // Treat user text literally, including %, _ and the chosen escape character.
        $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where .= " AND (title LIKE ? ESCAPE '!' OR description LIKE ? ESCAPE '!' OR label LIKE ? ESCAPE '!' OR reference LIKE ? ESCAPE '!')";
        $params = array_merge($params, [$pattern, $pattern, $pattern, $pattern]);
    }
    $total = (int) kb_query($pdo, 'SELECT COUNT(*) FROM kanban_cards WHERE ' . $where, $params)->fetchColumn();
    $order = (int) $column['is_done'] ? 'completed_at DESC, id DESC' : 'position, id';
    $cards = kb_query($pdo, "SELECT * FROM kanban_cards WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset", $params)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cards as &$card) {
        $card['id'] = (int) $card['id'];
        $card['column_id'] = (int) $card['column_id'];
    }
    unset($card);
    return ['cards' => $cards, 'total' => $total, 'has_more' => $offset + count($cards) < $total];
}

function kb_read(PDO $pdo, int $employeeId, array $query): array
{
    $search = kb_text($query['search'] ?? '', 'Search', 100);
    $pdo->beginTransaction();
    try {
        $board = kb_query($pdo, 'SELECT * FROM kanban_boards WHERE employee_id = ? LOCK IN SHARE MODE', [$employeeId])->fetch(PDO::FETCH_ASSOC);
        if (!$board) {
            $pdo->commit();
            return ['board' => null, 'columns' => [], 'active_count' => 0];
        }
        $board['id'] = (int) $board['id'];
        $board['version'] = (int) $board['version'];
        $board['done_limit'] = (int) $board['done_limit'];
        unset($board['employee_id']);
        if (isset($query['column_id'])) {
            if (kb_int($query['version'] ?? 0, 1, PHP_INT_MAX, 'Version') !== $board['version']) {
                throw new KanbanError(kb_t('The board has changed. Refresh it before loading more cards.'), 409);
            }
            $column = kb_column($pdo, $board['id'], kb_int($query['column_id'], 1, PHP_INT_MAX, 'Column'));
            $offset = kb_int($query['offset'] ?? 0, 0, 1000000, 'Page');
            $result = kb_column_page($pdo, $column, (int) $column['is_done'] ? $board['done_limit'] : 100, $offset, $search);
        } else {
            $columns = kb_query($pdo, 'SELECT * FROM kanban_columns WHERE board_id = ? ORDER BY position, id', [$board['id']])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as &$column) {
                $column['id'] = (int) $column['id'];
                $column['is_done'] = (int) $column['is_done'];
                $column += kb_column_page($pdo, $column, $column['is_done'] ? $board['done_limit'] : 100, 0, $search);
            }
            unset($column);
            // Count independently of search and pagination while the board lock is held.
            $result = ['board' => $board, 'columns' => $columns, 'active_count' => kb_active_card_count($pdo, $employeeId)];
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
