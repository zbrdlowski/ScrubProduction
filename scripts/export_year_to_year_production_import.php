<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "CLI only\n";
  exit(1);
}

require __DIR__ . '/../includes/conn.php';

$outputPath = $argv[1] ?? (__DIR__ . '/../db/year_to_year_statistics_production_import.sql');
$tables = [
  'year_to_year_product_stats' => [
    'stat_year',
    'iso_week',
    'product_count',
    'source',
    'note',
    'created_at',
    'updated_at',
  ],
  'year_to_year_daily_product_stats' => [
    'stat_date',
    'stat_year',
    'iso_week',
    'day_code',
    'graphics_count',
    'plastics_count',
    'seat_count',
    'fitting_count',
    'products_without_fitting',
    'after_weekend_count',
    'products_with_fitting',
    'source',
    'note',
    'created_at',
    'updated_at',
  ],
];

$numericColumns = [
  'stat_year' => true,
  'iso_week' => true,
  'product_count' => true,
  'graphics_count' => true,
  'plastics_count' => true,
  'seat_count' => true,
  'fitting_count' => true,
  'products_without_fitting' => true,
  'after_weekend_count' => true,
  'products_with_fitting' => true,
];

function ytyExportSqlValue(mysqli $conn, mixed $value, string $column, array $numericColumns): string
{
  if ($value === null) {
    return 'NULL';
  }

  if (isset($numericColumns[$column]) && is_numeric($value)) {
    return (string) (0 + $value);
  }

  return "'" . $conn->real_escape_string((string) $value) . "'";
}

$sql = [
  '-- Year to Year Statistics import for Darkscrub production',
  '-- Generated from local scrubproduction database on ' . date('Y-m-d H:i:s'),
  "-- Imports only historical source='imported' rows. Live Darkscrub values are calculated from production orders.imported_at.",
  'SET NAMES utf8mb4;',
  'START TRANSACTION;',
];

foreach ($tables as $table => $columns) {
  $createResult = $conn->query("SHOW CREATE TABLE `{$table}`");
  if (!$createResult || !($createRow = $createResult->fetch_assoc())) {
    fwrite(STDERR, "Missing table {$table}\n");
    exit(1);
  }

  $createSql = (string) $createRow['Create Table'];
  $createSql = preg_replace('/^CREATE TABLE `/', 'CREATE TABLE IF NOT EXISTS `', $createSql) ?: $createSql;
  $columnSql = '`' . implode('`,`', $columns) . '`';
  $orderBy = $table === 'year_to_year_product_stats' ? '`stat_year`, `iso_week`' : '`stat_date`';
  $rows = $conn->query("SELECT {$columnSql} FROM `{$table}` WHERE `source` = 'imported' ORDER BY {$orderBy}");

  if (!$rows) {
    fwrite(STDERR, $conn->error . "\n");
    exit(1);
  }

  $sql[] = '';
  $sql[] = "-- Schema for {$table}";
  $sql[] = $createSql . ';';
  $sql[] = "DELETE FROM `{$table}` WHERE `source` = 'imported';";

  $values = [];
  while ($row = $rows->fetch_assoc()) {
    $items = [];
    foreach ($columns as $column) {
      $items[] = ytyExportSqlValue($conn, $row[$column], $column, $numericColumns);
    }
    $values[] = '(' . implode(',', $items) . ')';
  }

  foreach (array_chunk($values, 80) as $chunk) {
    $sql[] = "INSERT INTO `{$table}` ({$columnSql}) VALUES";
    $sql[] = implode(",\n", $chunk) . ';';
  }
}

$sql[] = '';
$sql[] = 'COMMIT;';

$dir = dirname($outputPath);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
  fwrite(STDERR, "Could not create output directory: {$dir}\n");
  exit(1);
}

file_put_contents($outputPath, implode("\n", $sql) . "\n");

echo $outputPath . "\n";
echo filesize($outputPath) . " bytes\n";
