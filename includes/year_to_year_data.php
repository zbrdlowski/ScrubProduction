<?php
declare(strict_types=1);

const YTY_IMPORTED_SOURCE_NOTE = 'Imported from YearToYear.xlsx';
const YTY_IMPORTED_DAILY_SOURCE_NOTE = 'Imported from WeeklyStat.xlsx';
const YTY_DARKSCRUB_STATS_START_DATE = '2026-09-15';
const YTY_TRANSITION_YEAR = 2026;
const YTY_TRANSITION_WEEK = 38;
const YTY_TRANSITION_WEEK_BASELINE_PRODUCTS = 146;

const YTY_IMPORTED_WEEKLY_PRODUCT_COUNTS = [
  2026 => [
    null,
    null, 659, 477, 504, 489, 429, 537, 586, 558, 585, 691, 602, 639,
    490, 559, 639, 573, 543, 593, 661, 526, 449, 497, 538, 495, 445,
    514, 444, 501, 416, 446, 501, 434, 423, 438, 436, 420, 166,
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

  $conn->query("
    CREATE TABLE IF NOT EXISTS year_to_year_daily_product_stats (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      stat_date DATE NOT NULL,
      stat_year SMALLINT UNSIGNED NOT NULL,
      iso_week TINYINT UNSIGNED NOT NULL,
      day_code VARCHAR(2) DEFAULT NULL,
      graphics_count INT UNSIGNED NOT NULL DEFAULT 0,
      plastics_count INT UNSIGNED NOT NULL DEFAULT 0,
      seat_count INT UNSIGNED NOT NULL DEFAULT 0,
      fitting_count INT UNSIGNED NOT NULL DEFAULT 0,
      products_without_fitting INT UNSIGNED NOT NULL DEFAULT 0,
      after_weekend_count INT UNSIGNED DEFAULT NULL,
      products_with_fitting INT UNSIGNED NOT NULL DEFAULT 0,
      source VARCHAR(24) NOT NULL DEFAULT 'imported',
      note VARCHAR(255) DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_date_source (stat_date, source),
      KEY idx_year_week (stat_year, iso_week),
      KEY idx_stat_date (stat_date)
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
      FLOOR(YEARWEEK(o.imported_at, 3) / 100) AS stat_year,
      MOD(YEARWEEK(o.imported_at, 3), 100) AS iso_week,
      SUM(COALESCE(NULLIF(oi.qty, 0), 1)) AS product_count
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE oi.deleted_at IS NULL
      AND o.imported_at IS NOT NULL
      AND o.imported_at >= '{$startDate}'
      AND UPPER(TRIM(COALESCE(oi.item_type_code, ''))) IN ('G', 'P', 'S')
      AND COALESCE(UPPER(o.status), '') <> 'CANCELLED'
    GROUP BY FLOOR(YEARWEEK(o.imported_at, 3) / 100), MOD(YEARWEEK(o.imported_at, 3), 100)
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

function ytyDayCode(DateTimeInterface $date): string
{
  return [
    1 => 'MO',
    2 => 'TU',
    3 => 'WE',
    4 => 'TH',
    5 => 'FR',
    6 => 'SA',
    7 => 'SU',
  ][(int) $date->format('N')] ?? strtoupper($date->format('D'));
}

function ytyFetchStoredDailyStats(mysqli $conn, int $year): array
{
  $stmt = $conn->prepare("
    SELECT
      stat_date,
      iso_week,
      day_code,
      graphics_count,
      plastics_count,
      seat_count,
      fitting_count,
      products_without_fitting,
      after_weekend_count,
      products_with_fitting,
      source
    FROM year_to_year_daily_product_stats
    WHERE stat_year = ?
      AND source = 'imported'
    ORDER BY stat_date
  ");

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('i', $year);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];

  while ($row = $res->fetch_assoc()) {
    $date = new DateTimeImmutable((string) $row['stat_date']);
    $afterWeekend = $row['after_weekend_count'];
    $rows[] = [
      'date' => $date->format('Y-m-d'),
      'day_label' => (string) ($row['day_code'] ?: ytyDayCode($date)),
      'iso_week' => (int) $row['iso_week'],
      'graphics_count' => (int) $row['graphics_count'],
      'plastics_count' => (int) $row['plastics_count'],
      'seat_count' => (int) $row['seat_count'],
      'fitting_count' => (int) $row['fitting_count'],
      'products_without_fitting' => (int) $row['products_without_fitting'],
      'after_weekend_count' => $afterWeekend === null ? null : (int) $afterWeekend,
      'is_after_weekend' => $afterWeekend !== null,
      'products_with_fitting' => (int) $row['products_with_fitting'],
      'source' => (string) $row['source'],
    ];
  }

  $stmt->close();
  return $rows;
}

function ytyFetchLiveDailyStats(mysqli $conn, int $year): array
{
  $yearStart = sprintf('%04d-01-01', $year);
  $yearEnd = sprintf('%04d-01-01', $year + 1);
  $startDate = max($yearStart, YTY_DARKSCRUB_STATS_START_DATE);

  $stmt = $conn->prepare("
    SELECT
      DATE(o.imported_at) AS import_day,
      SUM(CASE
        WHEN UPPER(TRIM(COALESCE(oi.item_type_code, ''))) = 'G'
        THEN COALESCE(NULLIF(oi.qty, 0), 1) ELSE 0 END
      ) AS graphics_count,
      SUM(CASE
        WHEN UPPER(TRIM(COALESCE(oi.item_type_code, ''))) = 'P'
        THEN COALESCE(NULLIF(oi.qty, 0), 1) ELSE 0 END
      ) AS plastics_count,
      SUM(CASE
        WHEN UPPER(TRIM(COALESCE(oi.item_type_code, ''))) = 'S'
        THEN COALESCE(NULLIF(oi.qty, 0), 1) ELSE 0 END
      ) AS seat_count,
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
      AND o.imported_at >= ?
      AND o.imported_at < ?
      AND COALESCE(UPPER(o.status), '') <> 'CANCELLED'
    GROUP BY DATE(o.imported_at)
    ORDER BY DATE(o.imported_at)
  ");

  if (!$stmt) {
    return [];
  }

  $stmt->bind_param('ss', $startDate, $yearEnd);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];

  while ($row = $res->fetch_assoc()) {
    $date = new DateTimeImmutable((string) $row['import_day']);
    $rows[] = [
      'date' => $date->format('Y-m-d'),
      'day_label' => ytyDayCode($date),
      'iso_week' => (int) $date->format('W'),
      'graphics_count' => (int) ($row['graphics_count'] ?? 0),
      'plastics_count' => (int) ($row['plastics_count'] ?? 0),
      'seat_count' => (int) ($row['seat_count'] ?? 0),
      'products_without_fitting' => (int) ($row['products_without_fitting'] ?? 0),
      'after_weekend_count' => null,
      'is_after_weekend' => false,
      'fitting_count' => (int) ($row['fitting_count'] ?? 0),
      'products_with_fitting' => (int) ($row['products_with_fitting'] ?? 0),
      'source' => 'darkscrub',
    ];
  }

  $stmt->close();
  return $rows;
}

function ytyBuildDailyStats(mysqli $conn, int $year): array
{
  $byDate = [];

  foreach (ytyFetchStoredDailyStats($conn, $year) as $row) {
    $byDate[$row['date']] = $row;
  }

  foreach (ytyFetchLiveDailyStats($conn, $year) as $row) {
    $byDate[$row['date']] = $row;
  }

  ksort($byDate);
  $rows = array_values($byDate);
  $previousWeek = null;

  foreach ($rows as &$row) {
    if (($row['source'] ?? '') === 'darkscrub') {
      $currentWeek = (int) $row['iso_week'];
      if (
        $previousWeek === null
        || $currentWeek > $previousWeek
        || ($currentWeek === 1 && $previousWeek >= 52)
      ) {
        $row['after_weekend_count'] = (int) $row['products_without_fitting'];
      }
      $row['is_after_weekend'] = $row['after_weekend_count'] !== null;
    }

    $previousWeek = (int) $row['iso_week'];
  }
  unset($row);

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

  $dailyRows = ytyBuildDailyStats($conn, $currentYear);
  $dailyProductValues = array_map(static fn($row) => $row['products_without_fitting'], $dailyRows);
  $dailyAfterWeekendValues = array_map(static fn($row) => $row['after_weekend_count'] ?? null, $dailyRows);
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
      'after_weekend' => ytyAverage($dailyAfterWeekendValues),
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
