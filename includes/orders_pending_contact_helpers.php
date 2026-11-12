<?php
declare(strict_types=1);

// This table is intentionally independent of the importer and order_activity cleanup.
function pendingContactsSchemaReady(mysqli $conn): bool
{
    try {
        $result = $conn->query('SELECT id, order_id, actor_employee_id, contacted_at, channel, outcome, note, next_contact_at, request_token FROM order_pending_contacts LIMIT 0');
        if (!$result) return false;
        $result->free();
        $column = $conn->query("SHOW COLUMNS FROM order_pending_contacts LIKE 'next_contact_at'");
        if (!$column) return false;
        $definition = $column->fetch_assoc();
        $column->free();
        return ($definition['Null'] ?? '') === 'YES';
    } catch (Throwable $e) {
        return false;
    }
}

function pendingContactChannels(): array
{
    return ['phone' => 'Phone', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'other' => 'Other'];
}

function pendingContactOutcomes(): array
{
    return ['promised_payment' => 'Payment promised', 'message_sent' => 'Message sent',
        'no_answer' => 'No answer', 'cancel_requested' => 'Cancellation requested', 'other' => 'Other'];
}

function pendingContactFilters(): array
{
    return ['all' => 'All', 'todo' => 'To contact', 'cancel_requested' => 'To cancel', 'contacted' => 'Contacted',
        'waiting' => 'Under 3 days', 'unknown' => 'Missing date'];
}

function pendingContactSql(): array
{
    // All expressions use the same database clock and the original order date.
    $due = "CASE WHEN pc.outcome = 'cancel_requested' THEN NULL
        WHEN pc.id IS NOT NULL THEN pc.next_contact_at
        WHEN o.order_date >= '1000-01-01' THEN DATE_ADD(o.order_date, INTERVAL 72 HOUR)
        ELSE NULL END";
    $state = "CASE
        WHEN UPPER(TRIM(o.status)) <> 'PENDING' THEN 'resolved'
        WHEN pc.outcome = 'cancel_requested' THEN 'cancel_requested'
        WHEN pc.id IS NOT NULL AND pc.next_contact_at <= NOW() THEN 'repeat'
        WHEN pc.id IS NOT NULL THEN 'contacted'
        WHEN o.order_date IS NULL OR o.order_date < '1000-01-01' THEN 'unknown'
        WHEN DATE_ADD(o.order_date, INTERVAL 72 HOUR) <= NOW() THEN 'due'
        ELSE 'waiting' END";
    return [
        'join' => "LEFT JOIN order_pending_contacts pc ON pc.id = (
            SELECT pcl.id FROM order_pending_contacts pcl
            WHERE pcl.order_id = o.id ORDER BY pcl.id DESC LIMIT 1
        )
        LEFT JOIN employees pce ON pce.id = pc.actor_employee_id",
        'state' => $state,
        'due' => $due,
        'select' => "$state AS pending_contact_state, $due AS pending_contact_due,
            pc.id AS pending_contact_id, pc.contacted_at AS pending_contact_at,
            pc.channel AS pending_contact_channel, pc.outcome AS pending_contact_outcome,
            pc.note AS pending_contact_note,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', pce.firstname, pce.lastname)), ''),
                CONCAT('User #', pc.actor_employee_id)) AS pending_contact_actor",
        'sort' => "CASE WHEN ($state) = 'cancel_requested' THEN 0
            WHEN ($state) IN ('due', 'repeat') THEN 1
            WHEN ($state) = 'unknown' THEN 2 ELSE 3 END ASC,
            COALESCE(($due), '9999-12-31 23:59:59') ASC",
    ];
}

function pendingContactCounts(mysqli $conn, string $fromSql, string $whereSql, string $types = '', array $params = []): array
{
    $counts = array_fill_keys(array_keys(pendingContactFilters()), 0);
    $sql = pendingContactSql();
    $stmt = $conn->prepare("SELECT {$sql['state']} AS contact_state, COUNT(DISTINCT o.id) AS amount
        $fromSql {$sql['join']} $whereSql GROUP BY contact_state");
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $state = $row['contact_state'];
        $count = (int) $row['amount'];
        if ($state === 'resolved') continue;
        $counts['all'] += $count;
        if (in_array($state, ['due', 'repeat'], true)) $counts['todo'] += $count;
        elseif (isset($counts[$state])) $counts[$state] += $count;
    }
    $stmt->close();
    return $counts;
}

function pendingContactFilterSql(string $filter): string
{
    $state = pendingContactSql()['state'];
    if ($filter === 'todo') return "($state) IN ('due', 'repeat')";
    if (in_array($filter, ['waiting', 'contacted', 'unknown', 'cancel_requested'], true)) return "($state) = '$filter'";
    return '1 = 1';
}

function pendingContactEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pendingContactDate(?string $value, string $format = 'd. m. Y H:i'): string
{
    if (!$value || $value < '1000-01-01') return '—';
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    return $date ? $date->format($format) : '—';
}

function pendingContactLastCell(array $row): string
{
    if (empty($row['pending_contact_id'])) {
        return '<div class="pending-contact-last pending-contact-empty" title="Not contacted yet">'
            . '<span aria-hidden="true">—</span><span class="sr-only">Not contacted yet</span></div>';
    }

    $channel = pendingContactChannels()[$row['pending_contact_channel']] ?? 'Other';
    $outcome = pendingContactOutcomes()[$row['pending_contact_outcome']] ?? 'Other';
    $actor = (string) ($row['pending_contact_actor'] ?? '');
    $note = trim((string) ($row['pending_contact_note'] ?? ''));
    $summary = $outcome . ($actor !== '' ? ' · ' . $actor : '');
    $title = $channel . ' · ' . pendingContactDate($row['pending_contact_at']) . "\n" . $summary;
    if ($note !== '') $title .= "\n" . $note;

    $html = '<div class="pending-contact-last" title="' . pendingContactEscape($title) . '">'
        . '<div class="pending-contact-last-heading"><span class="pending-contact-line">'
        . pendingContactEscape($channel) . ' · '
        . pendingContactEscape(pendingContactDate($row['pending_contact_at'], 'd.m.y H:i')) . '</span>';
    if ($note !== '') {
        $html .= '<i class="far fa-sticky-note pending-contact-note-icon" aria-hidden="true"></i>'
            . '<span class="sr-only">Has a note; open contact history to read it.</span>';
    }
    return $html . '</div><div class="pending-contact-line pending-contact-secondary">'
        . pendingContactEscape($summary) . '</div></div>';
}

function pendingContactCell(array $row, bool $canWrite): string
{
    $state = $row['pending_contact_state'] ?? 'unknown';
    $labels = ['due' => 'Contact due', 'repeat' => 'Follow-up due',
        'waiting' => 'Waiting', 'contacted' => 'Contacted', 'unknown' => 'Missing order date', 'cancel_requested' => 'To cancel'];
    $colors = ['due' => 'warning', 'repeat' => 'danger', 'waiting' => 'secondary', 'contacted' => 'success', 'unknown' => 'warning', 'cancel_requested' => 'danger'];
    $label = $labels[$state] ?? 'Contact history';
    if ($state === 'contacted' && ($row['pending_contact_outcome'] ?? '') === 'no_answer') $label = 'Contact attempt';
    $html = '<div class="pending-contact-cell"><div class="pending-contact-schedule">'
        . '<span class="badge badge-' . ($colors[$state] ?? 'secondary') . '"'
        . ($state === 'waiting' ? ' title="First contact is due 72 hours after the order was created."' : '') . '>'
        . pendingContactEscape($label) . '</span>';
    if ($state === 'cancel_requested') {
        $html .= '<div class="pending-contact-secondary" title="Cancellation requested. Cancel the order using the existing order action.">No follow-up</div>';
    } elseif (!empty($row['pending_contact_due'])) {
        $html .= '<div class="pending-contact-secondary" title="Next contact: '
            . pendingContactEscape(pendingContactDate($row['pending_contact_due'])) . '">'
            . pendingContactEscape(pendingContactDate($row['pending_contact_due'], 'd.m.y H:i')) . '</div>';
    }
    $html .= '</div><button type="button" class="btn btn-xs btn-outline-info js-pending-contact" data-order-id="'
        . (int) $row['id'] . '">' . ($canWrite ? 'Record contact' : 'Contact history') . '</button></div>';
    return $html;
}

function pendingContactValidate(array $input, string $today): array
{
    foreach (['channel', 'outcome', 'note', 'next_contact_date', 'request_token'] as $field) {
        if (isset($input[$field]) && !is_scalar($input[$field])) throw new InvalidArgumentException('Invalid form value.');
    }
    $channel = trim((string) ($input['channel'] ?? ''));
    $outcome = trim((string) ($input['outcome'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));
    $next = trim((string) ($input['next_contact_date'] ?? ''));
    $token = trim((string) ($input['request_token'] ?? ''));
    if (!isset(pendingContactChannels()[$channel]) || !isset(pendingContactOutcomes()[$outcome])) {
        throw new InvalidArgumentException('Select a contact method and an outcome.');
    }
    if (mb_strlen($note, 'UTF-8') > 2000) throw new InvalidArgumentException('The note must be no longer than 2,000 characters.');
    if (($channel === 'other' || $outcome === 'other') && $note === '') throw new InvalidArgumentException('Please add a note when selecting Other.');
    $nextContactAt = null;
    if ($outcome !== 'cancel_requested') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $next);
        if (!$date || $date->format('Y-m-d') !== $next || $next < $today) {
            throw new InvalidArgumentException('The next contact date must be today or a valid future date.');
        }
        $nextContactAt = $next . ' 00:00:00';
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) throw new InvalidArgumentException('Invalid form. Please reopen it.');
    if (!isset($input['base_contact_id']) || !is_scalar($input['base_contact_id'])
        || !ctype_digit((string) $input['base_contact_id'])) throw new InvalidArgumentException('Invalid contact version.');
    return ['channel' => $channel, 'outcome' => $outcome, 'note' => $note,
        'next_contact_at' => $nextContactAt, 'request_token' => $token,
        'base_contact_id' => (int) $input['base_contact_id']];
}

function pendingContactSave(mysqli $conn, int $orderId, int $actorId, array $data): array
{
    if ($orderId <= 0 || $actorId <= 0) throw new InvalidArgumentException('Invalid order or user.');
    $conn->begin_transaction();
    try {
        // Same order lock as payment confirmation: a contact cannot race a release into production.
        $stmt = $conn->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) throw new DomainException('Order not found.');
        $stmt = $conn->prepare('SELECT id, order_id, actor_employee_id FROM order_pending_contacts WHERE request_token = ?');
        $stmt->bind_param('s', $data['request_token']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            if ((int) $existing['order_id'] !== $orderId || (int) $existing['actor_employee_id'] !== $actorId) {
                throw new DomainException('Invalid record identifier.');
            }
            $conn->commit();
            return ['id' => (int) $existing['id'], 'replayed' => true];
        }
        if (strtoupper(trim($order['status'])) !== 'PENDING') throw new DomainException('This order is no longer PENDING. Refresh the list.');
        $stmt = $conn->prepare('SELECT id FROM order_pending_contacts WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $latest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int) ($latest['id'] ?? 0) !== $data['base_contact_id']) {
            throw new DomainException('Another contact has been recorded. Reopen the form and check the history.');
        }
        $stmt = $conn->prepare('INSERT INTO order_pending_contacts
            (order_id, actor_employee_id, channel, outcome, note, next_contact_at, request_token, contacted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('iisssss', $orderId, $actorId, $data['channel'], $data['outcome'],
            $data['note'], $data['next_contact_at'], $data['request_token']);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();
        $conn->commit();
        return ['id' => $id, 'replayed' => false];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
