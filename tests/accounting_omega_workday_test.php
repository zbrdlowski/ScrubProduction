<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/accounting/omega_export_helpers.php';

date_default_timezone_set('Europe/Bratislava');

function omegaWorkdayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// All database writes use an isolated in-memory database.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
omegaWorkdayAssert(omega_export_previous_workday('2026-04-07', $pdo) === '2026-04-02',
    'Without the optional holiday table, Easter and weekends must still be skipped.');

$pdo->exec('CREATE TABLE holiday_requests (
    request_type TEXT, status TEXT, start_date TEXT, end_date TEXT, admin_note TEXT
)');
$insert = $pdo->prepare('INSERT INTO holiday_requests VALUES (?, ?, ?, ?, ?)');

$mode = $argv[1] ?? '';
if ($mode !== '') {
    $today = date('Y-m-d');
    $closureStart = (new DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
    $insert->execute(['holiday', 'approved', $closureStart, $today, 'Company-wide holiday']);
    $expectedDefault = omega_export_previous_workday($closureStart);
    $_SESSION = ['permission' => 900, 'user_id' => 1];
    $_GET = [];
    $expectedFrom = $expectedDefault;
    if ($mode === 'manual-iso' || $mode === 'manual-local') {
        $_GET['import_from'] = $mode === 'manual-iso' ? '2026-04-06' : '6.4.2026';
        $expectedFrom = '2026-04-06'; // Manual selections may include holidays.
    } elseif ($mode === 'invalid') {
        $_GET['import_from'] = '2026-02-30';
    } elseif ($mode === 'empty') {
        $_GET['import_from'] = '';
    }
    ob_start();
    require dirname(__DIR__) . '/includes/accounting_omega_export.php';
    $html = ob_get_clean();
    $expectedDisplay = (new DateTimeImmutable($expectedFrom))->format('d.m.Y');
    omegaWorkdayAssert(preg_match('/id="omegaImportFrom"[^>]*value="' . preg_quote($expectedDisplay, '/') . '"/', $html) === 1,
        'The form must display the default or preserve the chosen date.');
    omegaWorkdayAssert(strpos($html, 'name="import_from" value="' . $expectedFrom . '"') !== false,
        'The package form must submit the same date as the filter.');
    omegaWorkdayAssert($from === $expectedFrom, 'The candidate filter must use the displayed date.');
    omegaWorkdayAssert($to === $today && $processingDate === $today, 'Other date defaults must remain today.');
    echo $mode . ": OK\n";
    exit;
}

foreach ([
    ['2026-11-27', '2026-11-26'],
    ['2026-11-30', '2026-11-27'],
    ['2026-11-28', '2026-11-27'],
    ['2026-11-29', '2026-11-27'],
    ['2026-05-04', '2026-04-30'],
    ['2026-09-16', '2026-09-14'],
    ['2026-12-28', '2026-12-23'],
    ['2027-01-04', '2026-12-31'],
    ['2026-04-03', '2026-04-02'],
    ['2026-04-04', '2026-04-02'],
    ['2026-04-06', '2026-04-02'],
    ['2026-04-07', '2026-04-02'],
    ['2027-03-30', '2027-03-25'],
    ['2028-04-18', '2028-04-13'],
    ['2028-03-01', '2028-02-29'],
] as [$date, $expected]) {
    omegaWorkdayAssert(omega_export_previous_workday($date, $pdo) === $expected,
        "Wrong previous workday for $date; expected $expected.");
}

foreach ([
    ['holiday', 'approved', '2026-12-21', '2026-12-23', 'Company-wide holiday'],
    ['holiday', 'approved', '2026-12-21', '2026-12-23', 'Company-wide holiday'],
    ['holiday', 'approved', '2026-12-28', '2027-01-05', 'Company-wide holiday'],
    ['holiday', 'pending', '2026-12-18', '2026-12-18', 'Company-wide holiday'],
    ['holiday', 'cancelled', '2026-12-18', '2026-12-18', 'Company-wide holiday'],
    ['holiday', 'rejected', '2026-12-18', '2026-12-18', 'Company-wide holiday'],
    ['holiday', 'approved', '2026-12-18', '2026-12-18', 'Personal leave'],
    ['sick', 'approved', '2026-12-18', '2026-12-18', 'Company-wide holiday'],
] as $row) {
    $insert->execute($row);
}
omegaWorkdayAssert(omega_export_previous_workday('2027-01-07', $pdo) === '2026-12-18',
    'Skip consecutive company closures, public holidays, weekends and the year boundary.');
omegaWorkdayAssert(omega_export_previous_workday('2026-12-30', $pdo) === '2026-12-18',
    'A company closure still in progress must be skipped.');
omegaWorkdayAssert(omega_export_previous_workday('2026-12-21', $pdo) === '2026-12-18',
    'Personal, pending, rejected, cancelled and non-holiday absences must not close the company.');

foreach (['default', 'manual-iso', 'manual-local', 'invalid', 'empty'] as $mode) {
    $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=0', __FILE__, $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    omegaWorkdayAssert($exitCode === 0 && trim($output) === $mode . ': OK' && trim($errors) === '',
        $mode . ': ' . $output . $errors);
}

echo "accounting_omega_workday: OK (calendar, company closures, form defaults and manual dates)\n";
