<?php
declare(strict_types=1);

const YTY_IMPORTED_SOURCE_NOTE = 'Imported from YearToYear.xlsx';
const YTY_DARKSCRUB_STATS_START_DATE = '2026-09-15';
const YTY_TRANSITION_YEAR = 2026;
const YTY_TRANSITION_WEEK = 38;
const YTY_TRANSITION_WEEK_BASELINE_PRODUCTS = 146;

const YTY_IMPORTED_WEEKLY_PRODUCT_COUNTS = [
  2026 => [
    null,
    0, 659, 477, 504, 489, 429, 537, 586, 558, 585, 691, 602, 639,
    490, 559, 639, 573, 543, 593, 661, 526, 449, 497, 538, 495, 445,
    514, 444, 501, 416, 446, 501, 434, 423, 438, 436, 420, 146,
    null, null, null, null, null, null, null, null, null, null, null, null,
    null, null,
  ],
  2025 => [
    null,
    483, 369, 384, 439, 429, 416, 432, 501, 586, 600, 493, 500, 492,
    459, 508, 433, 590, 494, 475, 536, 466, 506, 462, 453, 554, 506,
    448, 403, 436, 433, 418, 425, 386, 513, 382, 473, 475, 349, 407,
    426, 420, 384, 426, 411, 476, 481, 415, 541, 668, 510, 474, 180,
  ],
  2024 => [
    null,
    441, 378, 326, 367, 363, 397, 422, 419, 471, 456, 437, 439, 408,
    513, 407, 424, 384, 418, 407, 401, 455, 416, 395, 395, 348, 351,
    283, 415, 329, 369, 417, 401, 346, 241, 324, 351, 342, 388, 373,
    302, 376, 363, 344, 306, 369, 398, 367, 564, 622, 438, 349, null,
  ],
  2023 => [
    null,
    400, 364, 295, 301, 314, 308, 345, 393, 415, 385, 383, 361, 326,
    333, 357, 339, 349, 343, 335, 310, 335, 308, 336, 315, 354, 269,
    295, 277, 325, 321, 263, 285, 238, 257, 230, 304, 278, 273, 256,
    277, 285, 263, 272, 270, 283, 247, 366, 409, 387, 346, 249, null,
  ],
];

function ytyEnsureSchema(mysqli $conn): void
{
  $conn->query("
    CREATE TABLE IF NOT EXISTS year_to_year_product_stats (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      stat_year SMALLINT UNSIGNED NOT NULL,
      iso_week TINYINT UNSIGNED NOT NULL,
      product_count INT UNSIGNED NOT NULL,
      source VARCHAR(24) NOT NULL DEFAULT 'imported',
      note VARCHAR(255) DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_year_week_source (stat_year, iso_week, source),
      KEY idx_year_week (stat_year, iso_week)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

function ytySeedImportedStats(mysqli $conn): int
{
  $stmt = $conn->prepare("
    INSERT IGNORE INTO year_to_year_product_stats
      (stat_year, iso_week, product_count, source, note)
    VALUES (?, ?, ?, 'imported', ?)
  ");

  if (!$stmt) {
    return 0;
  }

  $inserted = 0;
  $note = YTY_IMPORTED_SOURCE_NOTE;
  foreach (YTY_IMPORTED_WEEKLY_PRODUCT_COUNTS as $year => $weeks) {
    foreach ($weeks as $week => $count) {
      if ($week < 1 || $count === null) {
        continue;
      }
      $yearInt = (int) $year;
      $weekInt = (int) $week;
      $countInt = max(0, (int) $count);
      $stmt->bind_param('iiis', $yearInt, $weekInt, $countInt, $note);
      $stmt->execute();
      $inserted += max(0, (int) $stmt->affected_rows);
    }
  }

  $stmt->close();
  return $inserted;
}

function ytyBootstrap(mysqli $conn): array
{
  ytyEnsureSchema($conn);
  $inserted = ytySeedImportedStats($conn);
  $totalImported = 0;
  $res = $conn->query("SELECT COUNT(*) AS c FROM year_to_year_product_stats WHERE source = 'imported'");
  if ($res && ($row = $res->fetch_assoc())) {
    $totalImported = (int) ($row['c'] ?? 0);
  }

  return [
    'inserted' => $inserted,
    'total_imported' => $totalImported,
  ];
}

function ytyFetchStoredStats(mysqli $conn): array
{
  $rows = [];
  $res = $conn->query("
    SELECT stat_year, iso_week, product_count, source
    FROM year_to_year_product_stats
    ORDER BY stat_year, iso_week, source
  ");

  if (!$res) {
    return $rows;
  }

  while ($row = $res->fetch_assoc()) {
    $year = (int) $row['stat_year'];
    $week = (int) $row['iso_week'];
    $source = (string) $row['source'];
    $rows[$year][$week] = [
      'value' => (int) $row['product_count'],
      'source' => $source,
    ];
  }

  return $rows;
}

function ytyFetchLiveOrderStats(mysqli $conn): array
{
  $rows = [];
  $startDate = $conn->real_escape_string(YTY_DARKSCRUB_STATS_START_DATE);
  $res = $conn->query("
    SELECT
      FLOOR(YEARWEEK(o.order_date, 3) / 100) AS stat_year,
      MOD(YEARWEEK(o.order_date, 3), 100) AS iso_week,
      SUM(COALESCE(NULLIF(oi.qty, 0), 1)) AS product_count
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE oi.deleted_at IS NULL
      AND o.order_date IS NOT NULL
      AND o.order_date >= '{$startDate}'
      AND UPPER(TRIM(COALESCE(oi.item_type_code, ''))) IN ('G', 'P', 'S')
      AND COALESCE(UPPER(o.status), '') <> 'CANCELLED'
    GROUP BY FLOOR(YEARWEEK(o.order_date, 3) / 100), MOD(YEARWEEK(o.order_date, 3), 100)
    HAVING iso_week BETWEEN 1 AND 53
    ORDER BY stat_year, iso_week
  ");

  if (!$res) {
    return $rows;
  }

  while ($row = $res->fetch_assoc()) {
    $year = (int) $row['stat_year'];
    $week = (int) $row['iso_week'];
    $rows[$year][$week] = [
      'value' => (int) $row['product_count'],
      'source' => 'darkscrub',
    ];
  }

  return $rows;
}

function ytyFetchCurrentYearDailyStats(mysqli $conn, int $year): array
{
  $yearStart = sprintf('%04d-01-01', $year);
  $yearEnd = sprintf('%04d-01-01', $year + 1);
  $startDate = max($yearStart, YTY_DARKSCRUB_STATS_START_DATE);

  $stmt = $conn->prepare("
    SELECT
      DATE(o.order_date) AS order_day,
      SUM(CASE
        WHEN UPPER(TRIM(COALESCE(oi.item_type_code, ''))) IN ('G', 'P', 'S')
        THEN COALESCE(NULLIF(oi.qty, 0), 1) ELSE 0 END
      ) AS products_without_fitting,
      SUM(CASE
        WHEN UPPER(TRIM(COALESCE(oi.item_type_code, ''))) = 'F'
        THEN COALESCE(NULLIF(oi.qty, 0), 1) ELSE 0 END
      ) AS fitting_count,
      SUM(CASE
        WHEN UPPER(TRIM(COALESCE(oi.item_type_code, ''))) IN ('G', 'F', 'P', 'S')
        THEN COALESCE(NULLIF(oi.qty, 0), 1) ELSE 0 END
      ) AS products_with_fitting
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE oi.deleted_at IS NULL
      AND o.order_date >= ?
      AND o.order_date < ?
      AND COALESCE(UPPER(o.status), '') <> 'CANCELLED'
    GROUP BY DATE(o.order_date)
    ORDER BY DATE(o.order_date)
  ");

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('ss', $startDate, $yearEnd);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];

  while ($row = $res->fetch_assoc()) {
    $date = new DateTimeImmutable((string) $row['order_day']);
    $rows[] = [
      'date' => $date->format('Y-m-d'),
      'day_label' => $date->format('D'),
      'iso_week' => (int) $date->format('W'),
      'is_after_weekend' => (int) $date->format('N') === 1,
      'products_without_fitting' => (int) ($row['products_without_fitting'] ?? 0),
      'fitting_count' => (int) ($row['fitting_count'] ?? 0),
      'products_with_fitting' => (int) ($row['products_with_fitting'] ?? 0),
    ];
  }

  $stmt->close();
  return $rows;
}

function ytyAverage(array $values): ?int
{
  $values = array_values(array_filter($values, static fn($value) => $value !== null));
  if (!$values) {
    return null;
  }
  return (int) round(array_sum($values) / count($values));
}

function ytyBuildReportData(mysqli $conn): array
{
  $bootstrap = ytyBootstrap($conn);
  $stored = ytyFetchStoredStats($conn);
  $live = ytyFetchLiveOrderStats($conn);

  $now = new DateTimeImmutable('now');
  $currentYear = (int) $now->format('o');
  $currentWeek = (int) $now->format('W');

  $years = array_values(array_unique(array_merge(
    array_keys($stored),
    array_keys($live),
    [$currentYear]
  )));
  rsort($years, SORT_NUMERIC);

  $maxWeek = 52;
  foreach ([$stored, $live] as $sourceRows) {
    foreach ($sourceRows as $weeks) {
      if (!$weeks) {
        continue;
      }
      $maxWeek = max($maxWeek, max(array_keys($weeks)));
    }
  }
  $maxWeek = min(53, max(52, $maxWeek));

  $series = [];
  $sources = [];
  $totals = [];
  $latest = [];
  $averages = [];

  foreach ($years as $year) {
    $series[$year] = [];
    $sources[$year] = [];
    $totals[$year] = 0;
    $latest[$year] = null;
    $averages[$year] = null;

    for ($week = 1; $week <= $maxWeek; $week++) {
      $cell = $stored[$year][$week] ?? null;

      if (
        $year === YTY_TRANSITION_YEAR
        && $week === YTY_TRANSITION_WEEK
        && isset($live[$year][$week])
      ) {
        $cell = [
          'value' => (int) ($cell['value'] ?? YTY_TRANSITION_WEEK_BASELINE_PRODUCTS) + (int) $live[$year][$week]['value'],
          'source' => 'mixed',
        ];
      } elseif (isset($live[$year][$week]) && ($cell === null || ($year === $currentYear && $week >= $currentWeek))) {
        $cell = $live[$year][$week];
      }

      $value = $cell['value'] ?? null;
      $source = $cell['source'] ?? null;

      $series[$year][] = $value;
      $sources[$year][] = $source;

      if ($value !== null) {
        $totals[$year] += (int) $value;
        $latest[$year] = [
          'week' => $week,
          'value' => (int) $value,
          'source' => $source,
        ];
      }
    }

    $averages[$year] = ytyAverage($series[$year]);
  }

  $dailyRows = ytyFetchCurrentYearDailyStats($conn, $currentYear);
  $dailyProductValues = array_map(static fn($row) => $row['products_without_fitting'], $dailyRows);
  $dailyWithFittingValues = array_map(static fn($row) => $row['products_with_fitting'], $dailyRows);
  $transitionLiveProducts = (int) ($live[YTY_TRANSITION_YEAR][YTY_TRANSITION_WEEK]['value'] ?? 0);

  return [
    'bootstrap' => $bootstrap,
    'years' => $years,
    'labels' => range(1, $maxWeek),
    'series' => $series,
    'sources' => $sources,
    'totals' => $totals,
    'latest' => $latest,
    'averages' => $averages,
    'live' => $live,
    'daily_rows' => $dailyRows,
    'daily_averages' => [
      'products_without_fitting' => ytyAverage($dailyProductValues),
      'products_with_fitting' => ytyAverage($dailyWithFittingValues),
    ],
    'transition' => [
      'year' => YTY_TRANSITION_YEAR,
      'week' => YTY_TRANSITION_WEEK,
      'start_date' => YTY_DARKSCRUB_STATS_START_DATE,
      'baseline_products' => YTY_TRANSITION_WEEK_BASELINE_PRODUCTS,
      'live_products' => $transitionLiveProducts,
      'combined_products' => YTY_TRANSITION_WEEK_BASELINE_PRODUCTS + $transitionLiveProducts,
    ],
    'current_year' => $currentYear,
    'current_week' => $currentWeek,
    'max_week' => $maxWeek,
  ];
}
