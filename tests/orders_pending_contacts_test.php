<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/includes/orders_pending_contact_helpers.php';

// Connection-local temporary tables shadow application tables. No real rows are changed.
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $call, string $class, string $message) use ($assert): void {
    try { $call(); } catch (Throwable $e) {
        $assert($e instanceof $class, $message . ': ' . get_class($e));
        return;
    }
    $assert(false, $message);
};
$payload = static function (array $overrides = []): array {
    return array_merge(['channel' => 'phone', 'outcome' => 'promised_payment', 'note' => '',
        'next_contact_date' => '2026-11-14', 'request_token' => bin2hex(random_bytes(16)),
        'base_contact_id' => '0'], $overrides);
};

try {
    $conn->query('CREATE TEMPORARY TABLE orders (
        id INT PRIMARY KEY, status VARCHAR(32), source_id INT DEFAULT 1,
        order_date DATETIME NULL, imported_at DATETIME, production_started_at DATETIME NULL,
        payment_received_amount DECIMAL(12,2) NULL
    ) ENGINE=InnoDB');
    $conn->query('CREATE TEMPORARY TABLE employees (id INT PRIMARY KEY, firstname VARCHAR(100), lastname VARCHAR(100))');
    $migration = file_get_contents(dirname(__DIR__) . '/db/orders/pending_contacts_2026_11.sql');
    $migration = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $migration);
    $conn->query($migration);
    $assert(pendingContactsSchemaReady($conn), 'Migration creates every required contact column');
    $conn->query("SET timestamp = UNIX_TIMESTAMP('2026-11-12 10:00:00')");
    $conn->query("INSERT INTO employees VALUES (7, 'Jana', 'Test')");
    $conn->query("INSERT INTO orders (id, status, order_date, imported_at) VALUES
        (1, 'PENDING', '2026-11-09 10:00:00', '2026-11-12 08:00:00'),
        (2, 'PENDING', '2026-11-09 10:00:01', '2026-11-12 08:00:00'),
        (3, 'PENDING', '2026-11-01 10:00:00', '2026-11-02 08:00:00'),
        (4, 'PENDING', '2026-11-01 10:00:00', '2026-11-02 08:00:00'),
        (5, 'NEW', '2026-11-01 10:00:00', '2026-11-12 08:00:00'),
        (6, 'PENDING', NULL, '2026-11-01 08:00:00'),
        (7, 'PENDING', '2026-11-01 10:00:00', '2026-11-02 08:00:00'),
        (8, 'CANCELLED', '2026-11-01 10:00:00', '2026-11-02 08:00:00'),
        (9, 'PENDING', '2026-11-01 10:00:00', '2026-11-02 08:00:00'),
        (10, 'PENDING', '2026-11-13 10:00:00', '2026-11-12 08:00:00')");
    $sql = pendingContactSql();
    $fetch = static function (int $id) use ($conn, $sql): array {
        return $conn->query("SELECT o.*, {$sql['select']} FROM orders o {$sql['join']} WHERE o.id = " . $id)->fetch_assoc();
    };
    $assert($fetch(1)['pending_contact_state'] === 'due', 'Exactly 72 hours is due');
    $assert($fetch(2)['pending_contact_state'] === 'waiting', 'One second before 72 hours is not due');
    $assert($fetch(1)['pending_contact_due'] === '2026-11-12 10:00:00', 'Deadline uses order_date, not recent import');
    $assert($fetch(6)['pending_contact_state'] === 'unknown', 'Missing order date requires review without invented import deadline');
    $assert($fetch(10)['pending_contact_state'] === 'waiting', 'Future order is not prematurely contacted');
    $assert($fetch(5)['pending_contact_state'] === 'resolved' && $fetch(8)['pending_contact_state'] === 'resolved', 'Non-pending states are excluded');
    $counts = pendingContactCounts($conn, 'FROM orders o', "WHERE o.status = 'PENDING'");
    $assert($counts === ['all' => 8, 'todo' => 5, 'cancel_requested' => 0, 'contacted' => 0, 'waiting' => 2, 'unknown' => 1], 'Initial aggregate counts');

    $record3 = pendingContactSave($conn, 3, 7, pendingContactValidate($payload(), '2026-11-12'));
    $record4 = pendingContactSave($conn, 4, 7, pendingContactValidate($payload(['next_contact_date' => '2026-11-12']), '2026-11-12'));
    $assert($fetch(3)['pending_contact_state'] === 'contacted', 'Saved contact waits until next scheduled date');
    $assert($fetch(4)['pending_contact_state'] === 'repeat', 'Next contact date starts at midnight');
    $assert($fetch(3)['status'] === 'PENDING' && $fetch(3)['payment_received_amount'] === null
        && $fetch(3)['production_started_at'] === null, 'Contact never confirms payment or releases production');
    $assert($fetch(3)['pending_contact_actor'] === 'Jana Test', 'Actor name is displayed');
    $assert($fetch(3)['pending_contact_at'] === '2026-11-12 10:00:00', 'Contact timestamp uses database clock');
    $counts = pendingContactCounts($conn, 'FROM orders o', "WHERE o.status = 'PENDING'");
    $assert($counts['todo'] === 4 && $counts['contacted'] === 1 && $counts['all'] === 8, 'Counts update after contact');
    foreach (['all' => 8, 'todo' => 4, 'cancel_requested' => 0, 'contacted' => 1, 'waiting' => 2, 'unknown' => 1] as $filter => $expected) {
        $where = pendingContactFilterSql($filter);
        $rows = $conn->query("SELECT o.id FROM orders o {$sql['join']} WHERE o.status = 'PENDING' AND $where")->fetch_all(MYSQLI_ASSOC);
        $assert(count($rows) === $expected, 'Filter matches aggregate: ' . $filter);
    }
    $conn->query('UPDATE orders SET source_id = 2 WHERE id = 3');
    $scoped = pendingContactCounts($conn, 'FROM orders o', "WHERE o.status = 'PENDING' AND o.source_id = ?", 'i', [2]);
    $assert($scoped['all'] === 1 && $scoped['contacted'] === 1, 'Counts preserve other bound filters');

    $data = pendingContactValidate($payload(['outcome' => 'no_answer', 'note' => '<script>alert("x")</script>']), '2026-11-12');
    $saved = pendingContactSave($conn, 7, 7, $data);
    $replayed = pendingContactSave($conn, 7, 7, $data);
    $assert($saved['id'] === $replayed['id'] && $replayed['replayed'], 'Retry is idempotent');
    $assert((int) $conn->query('SELECT COUNT(*) AS n FROM order_pending_contacts WHERE order_id = 7')->fetch_assoc()['n'] === 1, 'Retry creates one history record');
    $html = pendingContactLastCell($fetch(7)) . pendingContactCell($fetch(7), true);
    $assert(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Notes are escaped in HTML');
    $assert(strpos($html, 'Contact attempt') !== false, 'Unanswered call is visibly an attempt');
    $throws(static function () use ($conn, $payload): void {
        pendingContactSave($conn, 7, 7, pendingContactValidate($payload(), '2026-11-12'));
    }, DomainException::class, 'Stale form cannot overwrite a newer contact');
    $new = pendingContactSave($conn, 7, 7, pendingContactValidate($payload(['base_contact_id' => (string) $saved['id']]), '2026-11-12'));
    $assert($new['id'] > $saved['id'], 'A new deliberate contact preserves older history');

    $conn->query("UPDATE orders SET imported_at = '2026-11-13 08:00:00' WHERE id = 7");
    $assert((int) $fetch(7)['pending_contact_id'] === $new['id'], 'Header/import changes do not erase local contact history');
    $conn->query("UPDATE orders SET status = 'NEW', payment_received_amount = 149.90, production_started_at = NOW() WHERE id = 7");
    $assert($fetch(7)['pending_contact_state'] === 'resolved', 'Paid order leaves the contact workflow');
    $assert((int) $conn->query('SELECT COUNT(*) AS n FROM order_pending_contacts WHERE order_id = 7')->fetch_assoc()['n'] === 2, 'Payment keeps contact history');
    foreach ([7, 8, 999] as $id) {
        $throws(static function () use ($conn, $id, $payload): void {
            pendingContactSave($conn, $id, 7, pendingContactValidate($payload(), '2026-11-12'));
        }, DomainException::class, 'Paid/cancelled/missing order cannot receive a pending contact');
    }
    foreach ([
        ['channel' => 'invalid'], ['outcome' => 'invalid'], ['note' => str_repeat('á', 2001)],
        ['channel' => 'other'], ['outcome' => 'other'], ['next_contact_date' => '2026-02-30'],
        ['next_contact_date' => '2026-11-11'], ['next_contact_date' => ''], ['request_token' => 'bad'],
        ['base_contact_id' => '-1'], ['note' => ['invalid']],
    ] as $invalid) {
        $throws(static function () use ($payload, $invalid): void {
            pendingContactValidate($payload($invalid), '2026-11-12');
        }, InvalidArgumentException::class, 'Invalid contact input is rejected');
    }
    $rows = $conn->query("SELECT o.id FROM orders o {$sql['join']}
        WHERE o.status = 'PENDING' ORDER BY {$sql['sort']}, o.id ASC")->fetch_all(MYSQLI_ASSOC);
    $ids = array_map('intval', array_column($rows, 'id'));
    $assert(array_slice($ids, 0, 3) === [9, 4, 1], 'Overdue contacts sort by deadline before newer orders');
    $assert($ids[3] === 6, 'Missing-date review follows overdue contacts');

    $conn->query("SET timestamp = UNIX_TIMESTAMP('2026-11-14 00:00:00')");
    $assert($fetch(3)['pending_contact_state'] === 'repeat', 'Waiting contact becomes due without an import or stored state update');

    // An existing installation can be upgraded repeatedly without losing history.
    $conn->query('ALTER TABLE order_pending_contacts MODIFY COLUMN next_contact_at DATETIME NOT NULL');
    $assert(!pendingContactsSchemaReady($conn), 'Old schema is identified as requiring an upgrade');
    $beforeUpgrade = $conn->query('SELECT * FROM order_pending_contacts ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $upgrade = file_get_contents(dirname(__DIR__) . '/db/orders/pending_contacts_optional_next_2026_11.sql');
    $conn->query($upgrade);
    $conn->query($upgrade);
    $assert(pendingContactsSchemaReady($conn), 'Upgrade supports absent follow-up dates');
    $assert($beforeUpgrade === $conn->query('SELECT * FROM order_pending_contacts ORDER BY id')->fetch_all(MYSQLI_ASSOC),
        'Repeated upgrade preserves every contact value');

    foreach (['phone', 'email', 'whatsapp', 'other'] as $i => $channel) {
        $id = 11 + $i;
        $conn->query("INSERT INTO orders (id, status, order_date, imported_at)
            VALUES ($id, 'PENDING', '2026-11-01 10:00:00', '2026-11-02 08:00:00')");
        $input = $payload(['channel' => $channel, 'outcome' => 'cancel_requested', 'note' => 'Customer requested cancellation.']);
        unset($input['next_contact_date']);
        $data = pendingContactValidate($input, '2026-11-14');
        $assert($data['next_contact_at'] === null, 'Cancellation requires no date for ' . $channel);
        pendingContactSave($conn, $id, 7, $data);
        $row = $fetch($id);
        $assert($row['pending_contact_state'] === 'cancel_requested' && $row['pending_contact_due'] === null,
            'Cancellation is not scheduled for another contact');
        $assert($row['status'] === 'PENDING' && $row['payment_received_amount'] === null && $row['production_started_at'] === null,
            'Cancellation request does not cancel, confirm payment or start production');
        $assert($conn->query("SELECT next_contact_at FROM order_pending_contacts WHERE order_id = $id")->fetch_assoc()['next_contact_at'] === null,
            'Absent follow-up is stored as SQL NULL');
    }
    $assert(pendingContactValidate($payload(['outcome' => 'cancel_requested', 'next_contact_date' => '2000-01-01']), '2026-11-14')['next_contact_at'] === null,
        'Cancellation ignores an old or stale date sent by a previous form');

    // Older releases required a date even for cancellation: it must no longer become overdue.
    $conn->query("INSERT INTO orders (id, status, order_date, imported_at)
        VALUES (15, 'PENDING', '2026-11-01 10:00:00', '2026-11-02 08:00:00')");
    $legacyToken = bin2hex(random_bytes(16));
    $conn->query("INSERT INTO order_pending_contacts
        (order_id, actor_employee_id, channel, outcome, note, next_contact_at, request_token)
        VALUES (15, 7, 'email', 'cancel_requested', 'Legacy request', '2026-11-01', '$legacyToken')");
    $assert($fetch(15)['pending_contact_state'] === 'cancel_requested' && $fetch(15)['pending_contact_due'] === null,
        'Legacy cancellation dates do not trigger reminders');
    $counts = pendingContactCounts($conn, 'FROM orders o', "WHERE o.status = 'PENDING'");
    $assert($counts['cancel_requested'] === 5, 'Cancellation requests have their own count');
    $where = pendingContactFilterSql('cancel_requested');
    $assert((int) $conn->query("SELECT COUNT(*) AS n FROM orders o {$sql['join']} WHERE o.status = 'PENDING' AND $where")->fetch_assoc()['n'] === 5,
        'To cancel filter matches its count');
    $where = pendingContactFilterSql('todo');
    $assert((int) $conn->query("SELECT COUNT(*) AS n FROM orders o {$sql['join']} WHERE o.id >= 11 AND $where")->fetch_assoc()['n'] === 0,
        'Cancellation requests are excluded from To contact');
    $html = pendingContactCell($fetch(15), true);
    $assert(strpos($html, 'To cancel') !== false && strpos($html, 'No follow-up') !== false && strpos($html, '01.11.26') === false,
        'Cancellation cell shows its action without a misleading date');
    $rows = $conn->query("SELECT o.id FROM orders o {$sql['join']} WHERE o.status = 'PENDING' ORDER BY {$sql['sort']}, o.id")->fetch_all(MYSQLI_ASSOC);
    $assert(array_map('intval', array_column(array_slice($rows, 0, 5), 'id')) === [11, 12, 13, 14, 15],
        'Pending cancellation requests remain prominent in All');

    $conn->query("SET timestamp = UNIX_TIMESTAMP('2026-11-20 10:00:00')");
    $assert($fetch(11)['pending_contact_state'] === 'cancel_requested', 'Time does not reactivate cancellation reminders');
    pendingContactSave($conn, 12, 7, pendingContactValidate($payload([
        'outcome' => 'promised_payment', 'next_contact_date' => '2026-11-21',
        'base_contact_id' => (string) $fetch(12)['pending_contact_id'],
    ]), '2026-11-20'));
    $assert($fetch(12)['pending_contact_state'] === 'contacted', 'A newer contact can resume follow-up after customer changes their mind');
    $conn->query("UPDATE orders SET status = 'CANCELLED' WHERE id = 13");
    $assert($fetch(13)['pending_contact_state'] === 'resolved' && (int) $fetch(13)['pending_contact_id'] > 0,
        'Manual cancellation resolves the request and retains its history');
    $counts = pendingContactCounts($conn, 'FROM orders o', "WHERE o.status = 'PENDING'");
    $assert($counts['cancel_requested'] === 3, 'Resumed and cancelled orders leave To cancel');
} finally {
    $conn->query('SET timestamp = DEFAULT');
    foreach (['order_pending_contacts', 'employees', 'orders'] as $table) {
        $conn->query('DROP TEMPORARY TABLE IF EXISTS ' . $table);
    }
}
echo 'orders_pending_contacts: ', $checks, " checks passed\n";
