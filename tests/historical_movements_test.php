<?php
declare(strict_types=1);

// Isolated SQLite fixtures exercise the actual page and export, without application data.
if (($argv[1] ?? '') === '--render') {
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    session_start();
    $_GET = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (empty($_GET['_guest'])) {
        $_SESSION['permission'] = 1;
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE items (barcode TEXT, name TEXT, description TEXT, color TEXT, main_supplier TEXT)');
    $supplier = 'Supplier <A> & "Partners"';
    $insert = $pdo->prepare('INSERT INTO items VALUES (?, ?, ?, ?, ?)');
    foreach ([
        ['00042', '=1+1', "Description; with quotes \" and\nnew line", 'Blue', $supplier],
        ['00043', 'Other model', '', '', $supplier . ' Extra'],
        ['00044', 'Zero supplier', '', '', '0'],
        ['00045', 'Literal wildcard supplier', '', '', 'S_100%'],
        ['00046', 'Blank supplier', '', '', ''],
        ['00047', 'Null supplier', '', '', null],
        ['BIG', 'Large report', '', '', $supplier],
    ] as $item) {
        $insert->execute($item);
    }
    $pdo->exec("INSERT INTO items SELECT * FROM items WHERE barcode = '00042'");
    $tables = array_merge(
        array_map(static function ($year) { return 'archive_inventory_movements_' . $year; }, range(2020, 2025)),
        ['archive_inventory_movements', 'inventory_movements']
    );
    foreach ($tables as $table) {
        $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, operator TEXT, order_id TEXT, item_name TEXT,
            shelf_name TEXT, quantity INTEGER, movement_type TEXT, timestamp TEXT)");
        $insert = $pdo->prepare("INSERT INTO $table VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ([
            [1, 'Worker', 'ORDER-A-OUT', '00042', 'Shelf A', 3, 'OUT', '2026-10-12 23:59:59'],
            [2, 'Worker', 'ORDER-A-IN', '00042', 'Shelf A', 4, 'IN', '2026-10-12 00:00:00'],
            [3, 'Worker', 'ORDER-B', '00043', 'Shelf B', 5, 'OUT', '2026-10-12 12:00:00'],
            [4, 'Worker', 'ORDER-ZERO', '00044', 'Shelf B', 6, 'OUT', '2026-10-12 12:00:00'],
            [5, 'Worker', 'ORDER-LITERAL', '00045', 'Shelf B', 7, 'OUT', '2026-10-12 12:00:00'],
            [6, 'Worker', 'ORDER-UNKNOWN', 'MISSING', 'Shelf B', 8, 'OUT', '2026-10-12 12:00:00'],
            [7, 'Worker', 'ORDER-BLANK', '00046', 'Shelf B', 9, 'OUT', '2026-10-12 12:00:00'],
            [8, 'Worker', 'ORDER-NULL', '00047', 'Shelf B', 10, 'OUT', '2026-10-12 12:00:00'],
            [9, 'Worker', 'ORDER-BEFORE', '00042', 'Shelf A', 11, 'OUT', '2026-10-11 23:59:59'],
            [10, 'Worker', 'ORDER-AFTER', '00042', 'Shelf A', 12, 'OUT', '2026-10-13 00:00:00'],
        ] as $movement) {
            $insert->execute($movement);
        }
    }
    if (!empty($_GET['_large'])) {
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO inventory_movements VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 100; $i < 5102; $i++) {
            $insert->execute([$i, 'Worker', 'BULK-' . $i, 'BIG', 'Shelf A', 2, 'OUT', '2026-10-12 12:00:00']);
        }
        $pdo->commit();
    }
    // Simulate index.php output buffering: CSV must discard the HTML shell.
    ob_start();
    echo '<!-- PAGE SHELL -->';
    include __DIR__ . '/../includes/historical_movements.php';
    ob_end_flush();
    exit;
}

function hm_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function hm_render(array $filters): string
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--render', base64_encode(json_encode($filters, JSON_THROW_ON_ERROR))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    hm_assert(proc_close($process) === 0 && $error === '', 'Page execution failed: ' . $error);
    return $output;
}
function hm_xpath(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}
function hm_rows(string $html): int
{
    return hm_xpath($html)->query('//table[@id="example1"]/tbody/tr')->length;
}
function hm_csv(string $text): array
{
    hm_assert(substr($text, 0, 3) === "\xEF\xBB\xBF", 'CSV starts with a UTF-8 BOM, without the HTML shell.');
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, substr($text, 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}

$supplier = 'Supplier <A> & "Partners"';
$dates = ['from' => '2026-10-12', 'to' => '2026-10-12'];
$html = hm_render([]);
$xpath = hm_xpath($html);
hm_assert($xpath->query('//select[@name="main_supplier"]/option[@selected and @value=""]')->length === 1, 'Default supplier is All.');
hm_assert($xpath->query('//select[@name="main_supplier"]/option')->length === 5, 'Supplier choices are distinct and omit blank/null values.');
hm_assert(hm_rows($html) === 0 && strpos($html, 'Please enter at least one filter') !== false, 'No automatic unfiltered archive query.');
hm_assert(hm_rows(hm_render(['main_supplier' => $supplier])) === 32, 'Supplier alone searches every archive and the live table.');
hm_assert(hm_rows(hm_render($dates)) === 64, 'All preserves movements without an item or supplier.');
hm_assert(hm_rows(hm_render($dates + ['main_supplier' => $supplier])) === 16, 'Both day boundaries included, adjacent dates excluded.');
hm_assert(hm_rows(hm_render($dates + ['main_supplier' => $supplier, 'movement_type' => 'IN'])) === 8, 'Receipt filter combines with supplier.');
hm_assert(hm_rows(hm_render($dates + ['main_supplier' => '0'])) === 8, 'Supplier named zero is a real filter.');
hm_assert(hm_rows(hm_render($dates + ['main_supplier' => 'S_100%'])) === 8, 'Supplier uses equality, not LIKE wildcards.');
$unknownHtml = hm_render($dates + ['main_supplier' => "' OR 1=1 --"]);
hm_assert(hm_rows($unknownHtml) === 0, 'Supplier input remains a bound value.');
hm_assert(hm_xpath($unknownHtml)->query('//select[@name="main_supplier"]/option[@selected]')->item(0)->getAttribute('value') === "' OR 1=1 --", 'An unavailable bookmarked supplier never silently switches to All.');
hm_assert(hm_rows(hm_render($dates + ['main_supplier' => []])) === 64, 'Malformed supplier input does not crash.');

$filters = $dates + ['main_supplier' => $supplier, 'movement_type' => 'OUT', 'item_name' => '00042',
    'order_id' => '-A-OUT', 'shelf_name' => 'Shelf A', 'operator' => 'Worker'];
$html = hm_render($filters);
hm_assert(hm_rows($html) === 8, 'Every existing filter combines with supplier across all source tables.');
$xpath = hm_xpath($html);
hm_assert($xpath->query('//select[@name="main_supplier"]/option[@selected]')->item(0)->getAttribute('value') === $supplier, 'Supplier selection survives submit and HTML escaping.');
hm_assert($xpath->query('//table[@id="example1"]/tbody/tr/td[8]')->item(0)->textContent === $supplier, 'Supplier is displayed in the result.');

$csv = hm_csv(hm_render($filters + ['export' => 'csv']));
hm_assert(count($csv) === 9 && $csv[0][7] === 'Main Supplier', 'CSV exports the same filtered rows with supplier column.');
hm_assert(count(array_unique(array_column(array_slice($csv, 1), 11))) === 8, 'CSV includes all eight sources.');
foreach (array_slice($csv, 1) as $row) {
    hm_assert(count($row) === 12 && $row[7] === $supplier && $row[3] === '00042' && $row[9] === '3', 'CSV retains text, barcode and quantity.');
    hm_assert($row[4] === "'=1+1" && $row[5] === "Description; with quotes \" and\nnew line", 'CSV escapes formula prefixes and round-trips quotes, delimiters and newlines.');
}
$large = $dates + ['main_supplier' => $supplier, 'item_name' => 'BIG', '_large' => '1'];
$html = hm_render($large);
hm_assert(hm_rows($html) === 5000 && strpos($html, 'Showing the latest 5,000') !== false, 'Preview remains bounded and clearly warns about truncation.');
$csv = hm_csv(hm_render($large + ['export' => 'csv']));
hm_assert(count($csv) === 5003, 'Full CSV exports all 5,002 records beyond the preview limit.');
hm_assert($csv[1][2] === 'BULK-5101' && end($csv)[2] === 'BULK-100', 'CSV has deterministic newest-first ordering.');
hm_assert(count(hm_csv(hm_render($dates + ['main_supplier' => 'Unknown', 'export' => 'csv']))) === 1, 'No matches produces a header-only CSV.');
hm_assert(strpos(hm_render(['export' => 'csv']), 'Please enter at least one filter') !== false, 'An empty form does not export all history.');
hm_assert(hm_render(['_guest' => '1', 'export' => 'csv', 'main_supplier' => $supplier]) === '<!-- PAGE SHELL -->Login required.', 'Export retains the login requirement.');

echo "Historical movements: supplier/date/type filters, duplicate item cards, all archives, default/reset state, CSV escaping, login and full 5,002-row export OK.\n";
