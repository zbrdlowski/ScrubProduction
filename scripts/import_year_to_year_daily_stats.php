<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "CLI only\n";
  exit(1);
}

$jsonPath = $argv[1] ?? '';
if ($jsonPath === '' || !is_readable($jsonPath)) {
  fwrite(STDERR, "Usage: php scripts/import_year_to_year_daily_stats.php <daily-stats.json>\n");
  exit(1);
}

require __DIR__ . '/../includes/conn.php';
require __DIR__ . '/../includes/year_to_year_data.php';

$payload = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
$rows = $payload['rows'] ?? null;
if (!is_array($rows)) {
  fwrite(STDERR, "Invalid payload: missing rows array\n");
  exit(1);
}

ytyEnsureSchema($conn);

$note = YTY_IMPORTED_DAILY_SOURCE_NOTE;
$inserted = 0;

$conn->begin_transaction();
try {
  $conn->query("DELETE FROM year_to_year_daily_product_stats WHERE source = 'imported'");
  $deleted = (int) $conn->affected_rows;

  $stmt = $conn->prepare("
    INSERT INTO year_to_year_daily_product_stats (
      stat_date,
      stat_year,
      iso_week,
      day_code,
      graphics_count,
      plastics_count,
      seat_count,
      fitting_count,
      products_without_fitting,
      after_weekend_count,
      products_with_fitting,
      source,
      note
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'imported', ?)
    ON DUPLICATE KEY UPDATE
      stat_year = VALUES(stat_year),
      iso_week = VALUES(iso_week),
      day_code = VALUES(day_code),
      graphics_count = VALUES(graphics_count),
      plastics_count = VALUES(plastics_count),
      seat_count = VALUES(seat_count),
      fitting_count = VALUES(fitting_count),
      products_without_fitting = VALUES(products_without_fitting),
      after_weekend_count = VALUES(after_weekend_count),
      products_with_fitting = VALUES(products_with_fitting),
      note = VALUES(note)
  ");

  if (!$stmt) {
    throw new RuntimeException($conn->error);
  }

  foreach ($rows as $row) {
    $statDate = (string) ($row['stat_date'] ?? '');
    $statYear = (int) ($row['stat_year'] ?? 0);
    $isoWeek = (int) ($row['iso_week'] ?? 0);
    $dayCode = (string) ($row['day_code'] ?? '');
    $graphics = (int) ($row['graphics_count'] ?? 0);
    $plastics = (int) ($row['plastics_count'] ?? 0);
    $seats = (int) ($row['seat_count'] ?? 0);
    $fitting = (int) ($row['fitting_count'] ?? 0);
    $withoutFitting = (int) ($row['products_without_fitting'] ?? 0);
    $afterWeekend = array_key_exists('after_weekend_count', $row) && $row['after_weekend_count'] !== null
      ? (int) $row['after_weekend_count']
      : null;
    $withFitting = (int) ($row['products_with_fitting'] ?? ($withoutFitting + $fitting));

    if ($statDate === '' || $statYear < 2000 || $isoWeek < 1 || $isoWeek > 53) {
      throw new RuntimeException('Invalid daily stats row: ' . json_encode($row, JSON_UNESCAPED_SLASHES));
    }

    $stmt->bind_param(
      'siisiiiiiiis',
      $statDate,
      $statYear,
      $isoWeek,
      $dayCode,
      $graphics,
      $plastics,
      $seats,
      $fitting,
      $withoutFitting,
      $afterWeekend,
      $withFitting,
      $note
    );
    $stmt->execute();
    $inserted++;
  }

  $stmt->close();
  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}

echo "deleted_imported_daily_rows={$deleted}\n";
echo "imported_daily_rows={$inserted}\n";

$summary = $conn->query("
  SELECT
    stat_year,
    COUNT(*) AS rows_count,
    MIN(stat_date) AS first_date,
    MAX(stat_date) AS last_date,
    ROUND(AVG(after_weekend_count)) AS avg_day_after_weekend,
    ROUND(AVG(products_without_fitting)) AS avg_day_without_fitting,
    ROUND(AVG(products_with_fitting)) AS avg_day_with_fitting
  FROM year_to_year_daily_product_stats
  WHERE source = 'imported'
  GROUP BY stat_year
  ORDER BY stat_year DESC
");

while ($summary && ($row = $summary->fetch_assoc())) {
  echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}
