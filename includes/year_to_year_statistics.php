<?php
declare(strict_types=1);

/** @var mysqli $conn */
if (!isset($conn) || !$conn instanceof mysqli) {
  require_once __DIR__ . '/conn.php';
}

require_once __DIR__ . '/year_to_year_data.php';

function ytyH(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ytyNum(?int $value): string
{
  return $value === null ? '-' : number_format($value, 0, '.', ' ');
}

function ytyDailyLimitClass(?int $value): string
{
  if ($value === null) {
    return '';
  }

  if ($value < 50) {
    return 'yty-daily-limit-low';
  }

  if ($value <= 60) {
    return 'yty-daily-limit-mid';
  }

  return 'yty-daily-limit-high';
}

$report = ytyBuildReportData($conn);
$years = $report['years'];
$selectedYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) ($years[0] ?? $report['current_year']);
if (!in_array($selectedYear, $years, true)) {
  $selectedYear = (int) ($years[0] ?? $report['current_year']);
}

$labels = $report['labels'];
$series = $report['series'];
$totals = $report['totals'];
$latest = $report['latest'];
$averages = $report['averages'];
$dailyRows = $report['daily_rows'];
$dailyAverages = $report['daily_averages'];
$transition = $report['transition'];
$currentYear = (int) $report['current_year'];
$currentWeek = (int) $report['current_week'];
$currentWeekIndex = array_search($currentWeek, $labels, true);
$currentWeekProducts = $currentWeekIndex === false ? null : ($series[$currentYear][$currentWeekIndex] ?? null);
$selectedLatest = $latest[$selectedYear] ?? null;

$palette = [
  2026 => ['border' => '#34a853', 'fill' => 'rgba(52, 168, 83, 0.28)'],
  2025 => ['border' => '#4285f4', 'fill' => 'rgba(66, 133, 244, 0.24)'],
  2024 => ['border' => '#ea4335', 'fill' => 'rgba(234, 67, 53, 0.20)'],
  2023 => ['border' => '#fbbc04', 'fill' => 'rgba(251, 188, 4, 0.22)'],
];

$fallbackColors = [
  ['border' => '#5bc0de', 'fill' => 'rgba(91, 192, 222, 0.22)'],
  ['border' => '#d7a8ff', 'fill' => 'rgba(215, 168, 255, 0.20)'],
  ['border' => '#ff9f40', 'fill' => 'rgba(255, 159, 64, 0.18)'],
];

$chartColors = [];
foreach ($years as $idx => $year) {
  $chartColors[$year] = $palette[$year] ?? $fallbackColors[$idx % count($fallbackColors)];
}

$chartPayload = [
  'labels' => array_map('strval', $labels),
  'years' => array_map('strval', $years),
  'selectedYear' => (string) $selectedYear,
  'series' => array_combine(
    array_map('strval', array_keys($series)),
    array_values($series)
  ),
  'colors' => array_combine(
    array_map('strval', array_keys($chartColors)),
    array_values($chartColors)
  ),
];
?>

<style>
  .yty-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }

  .yty-title {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 700;
  }

  .yty-subtitle {
    margin-top: 3px;
    color: #adb5bd;
  }

  .yty-year-picker {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .yty-year-picker .btn {
    min-width: 64px;
  }

  .yty-stat-card .inner {
    min-height: 92px;
  }

  .yty-stat-card h3 {
    font-size: 1.85rem;
    margin-bottom: 4px;
  }

  .yty-chart-wrap {
    position: relative;
    height: 340px;
  }

  .yty-week-table th,
  .yty-week-table td,
  .yty-daily-table th,
  .yty-daily-table td {
    text-align: center;
    vertical-align: middle !important;
    white-space: nowrap;
  }

  .yty-week-table td.is-current-week {
    box-shadow: inset 0 0 0 1px rgba(52, 168, 83, .85);
    background: rgba(52, 168, 83, .08);
  }

  .yty-daily-table td.is-after-weekend {
    font-weight: 700;
  }

  .yty-daily-table td.yty-daily-limit-low {
    background: rgba(220, 53, 69, .26);
    box-shadow: inset 0 0 0 1px rgba(220, 53, 69, .45);
    color: #ffd7dc;
    font-weight: 700;
  }

  .yty-daily-table td.yty-daily-limit-mid {
    background: rgba(255, 193, 7, .28);
    box-shadow: inset 0 0 0 1px rgba(255, 193, 7, .48);
    color: #fff2bd;
    font-weight: 700;
  }

  .yty-daily-table td.yty-daily-limit-high {
    background: rgba(40, 167, 69, .30);
    box-shadow: inset 0 0 0 1px rgba(40, 167, 69, .50);
    color: #d8f8df;
    font-weight: 700;
  }

  .yty-muted-note {
    color: #adb5bd;
    font-size: 12px;
  }

  @media (max-width: 767.98px) {
    .yty-chart-wrap {
      height: 260px;
    }
  }
</style>

<div class="container-fluid">
  <div class="yty-header">
    <div>
      <h1 class="yty-title">Year to Year Statistics</h1>
      <div class="yty-subtitle">
        Product counts by ISO week. Pending orders are included; cancelled orders are excluded. Fitting is tracked separately in the daily view.
      </div>
    </div>
    <div class="yty-year-picker">
      <?php foreach ($years as $year): ?>
        <a
          href="?page=year_to_year_statistics&amp;year=<?= (int) $year ?>"
          class="btn btn-sm <?= (int) $year === $selectedYear ? 'btn-primary' : 'btn-outline-light' ?>"
        >
          <?= (int) $year ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ((int) ($report['bootstrap']['inserted'] ?? 0) > 0): ?>
    <div class="alert alert-success">
      Imported <?= (int) $report['bootstrap']['inserted'] ?> weekly rows from the saved YearToYear.xlsx data.
    </div>
  <?php endif; ?>

  <div class="alert alert-info">
    Week <?= (int) $transition['week'] ?> / <?= (int) $transition['year'] ?> is combined:
    <?= ytyNum((int) $transition['baseline_products']) ?> products from Excel through Monday
    + <?= ytyNum((int) $transition['live_products']) ?> products from Darkscrub since
    <?= ytyH(date('d.m.Y', strtotime((string) $transition['start_date']))) ?>
    = <?= ytyNum((int) $transition['combined_products']) ?>.
  </div>

  <div class="row">
    <div class="col-lg-3 col-6">
      <div class="small-box bg-info yty-stat-card">
        <div class="inner">
          <h3><?= ytyNum($currentWeekProducts === null ? null : (int) $currentWeekProducts) ?></h3>
          <p>Current week products</p>
        </div>
        <div class="icon"><i class="fas fa-chart-line"></i></div>
      </div>
    </div>

    <div class="col-lg-3 col-6">
      <div class="small-box bg-success yty-stat-card">
        <div class="inner">
          <h3><?= ytyNum((int) ($totals[$selectedYear] ?? 0)) ?></h3>
          <p><?= (int) $selectedYear ?> total products</p>
        </div>
        <div class="icon"><i class="fas fa-boxes"></i></div>
      </div>
    </div>

    <div class="col-lg-3 col-6">
      <div class="small-box bg-warning yty-stat-card">
        <div class="inner">
          <h3><?= ytyNum($averages[$selectedYear] ?? null) ?></h3>
          <p>AVG / Week <?= (int) $selectedYear ?></p>
        </div>
        <div class="icon"><i class="far fa-calendar-alt"></i></div>
      </div>
    </div>

    <div class="col-lg-3 col-6">
      <div class="small-box bg-secondary yty-stat-card">
        <div class="inner">
          <h3><?= ytyNum($dailyAverages['products_with_fitting'] ?? null) ?></h3>
          <p>AVG / Day <?= (int) $currentYear ?> (G+F+P+S)</p>
        </div>
        <div class="icon"><i class="fas fa-calendar-day"></i></div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xl-5">
      <div class="card card-dark">
        <div class="card-header">
          <h3 class="card-title">Number of Products per Week: <?= (int) $selectedYear ?></h3>
        </div>
        <div class="card-body">
          <div class="yty-chart-wrap">
            <canvas id="ytyBarChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-7">
      <div class="card card-dark">
        <div class="card-header">
          <h3 class="card-title mb-0"><?= ytyH(implode(' vs. ', array_map('strval', $years))) ?></h3>
        </div>
        <div class="card-body">
          <div class="yty-chart-wrap">
            <canvas id="ytyCompareChart"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card card-dark">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
      <h3 class="card-title mb-0">Daily Values: <?= (int) $currentYear ?></h3>
      <span class="yty-muted-note">
        Daily values use import date. AVG / Day uses G+F+P+S / day. Historical rows come from WeeklyStat.xlsx; Darkscrub continues from <?= ytyH(date('d.m.Y', strtotime((string) $transition['start_date']))) ?>.
      </span>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-bordered table-striped table-sm mb-0 yty-daily-table">
        <thead>
          <tr>
            <th>Import Date</th>
            <th>Day</th>
            <th>Week</th>
            <th>G+P+S / day</th>
            <th>G+P+S / After wknd</th>
            <th>F / day</th>
            <th>G+F+P+S / day</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$dailyRows): ?>
            <tr>
              <td colspan="7" class="text-muted py-4">No Darkscrub daily data for <?= (int) $currentYear ?> yet.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($dailyRows as $row): ?>
            <?php
            $productsWithoutFitting = (int) $row['products_without_fitting'];
            $afterWeekendCount = $row['after_weekend_count'] ?? null;
            $productsWithFitting = (int) $row['products_with_fitting'];
            ?>
            <tr>
              <td><?= ytyH(date('d.m.Y', strtotime((string) $row['date']))) ?></td>
              <td><?= ytyH((string) $row['day_label']) ?></td>
              <td><?= (int) $row['iso_week'] ?></td>
              <td class="<?= ytyH(ytyDailyLimitClass($productsWithoutFitting)) ?>"><?= ytyNum($productsWithoutFitting) ?></td>
              <td class="<?= ytyH(trim(($afterWeekendCount !== null ? 'is-after-weekend ' : '') . ytyDailyLimitClass($afterWeekendCount === null ? null : (int) $afterWeekendCount))) ?>">
                <?= $afterWeekendCount !== null ? ytyNum((int) $afterWeekendCount) : '' ?>
              </td>
              <td><?= ytyNum((int) $row['fitting_count']) ?></td>
              <td class="<?= ytyH(ytyDailyLimitClass($productsWithFitting)) ?>"><?= ytyNum($productsWithFitting) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card card-dark">
    <div class="card-header">
      <h3 class="card-title">Weekly Values</h3>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-bordered table-striped table-sm mb-0 yty-week-table">
        <thead>
          <tr>
            <th>Week</th>
            <?php foreach ($years as $year): ?>
              <th><?= (int) $year ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($labels as $labelIndex => $week): ?>
            <tr>
              <th><?= (int) $week ?></th>
              <?php foreach ($years as $year): ?>
                <?php
                $value = $series[$year][$labelIndex] ?? null;
                $isCurrentWeek = (int) $year === $currentYear && (int) $week === $currentWeek;
                ?>
                <td class="<?= $isCurrentWeek ? 'is-current-week' : '' ?>">
                  <?php if ($value === null): ?>
                    <span class="text-muted">-</span>
                  <?php else: ?>
                    <?= ytyNum((int) $value) ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  window.addEventListener('load', function () {
    if (!window.Chart) {
      return;
    }

    var ytyData = <?= json_encode($chartPayload, JSON_UNESCAPED_SLASHES) ?>;
    var gridColor = 'rgba(255,255,255,.10)';
    var tickColor = '#ced4da';

    function valuesFor(year) {
      return (ytyData.series[String(year)] || []).map(function (value) {
        return value === null ? null : Number(value);
      });
    }

    var selectedYear = String(ytyData.selectedYear);
    var selectedColors = ytyData.colors[selectedYear] || { border: '#4285f4', fill: 'rgba(66, 133, 244, .24)' };
    var selectedValues = valuesFor(selectedYear);

    var barCanvas = document.getElementById('ytyBarChart');
    if (barCanvas) {
      new Chart(barCanvas.getContext('2d'), {
        type: 'bar',
        data: {
          labels: ytyData.labels,
          datasets: [{
            label: selectedYear,
            data: selectedValues,
            backgroundColor: selectedColors.border,
            borderColor: selectedColors.border,
            borderWidth: 1,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          legend: { display: false },
          tooltips: { mode: 'index', intersect: false },
          scales: {
            xAxes: [{ gridLines: { display: false }, ticks: { fontColor: tickColor, maxRotation: 0, autoSkip: true } }],
            yAxes: [{ gridLines: { color: gridColor }, ticks: { beginAtZero: true, fontColor: tickColor } }]
          }
        }
      });
    }

    var compareDatasets = ytyData.years.map(function (year) {
      var colors = ytyData.colors[String(year)] || { border: '#5bc0de', fill: 'rgba(91, 192, 222, .22)' };
      return {
        label: String(year),
        data: valuesFor(year),
        borderColor: colors.border,
        backgroundColor: colors.fill,
        pointBackgroundColor: colors.border,
        pointBorderColor: colors.border,
        pointRadius: 2,
        pointHoverRadius: 4,
        borderWidth: 2,
        lineTension: 0.32,
        fill: true,
        spanGaps: false
      };
    });

    var compareCanvas = document.getElementById('ytyCompareChart');
    if (compareCanvas) {
      new Chart(compareCanvas.getContext('2d'), {
        type: 'line',
        data: {
          labels: ytyData.labels,
          datasets: compareDatasets
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          legend: { labels: { fontColor: tickColor, boxWidth: 12 } },
          tooltips: { mode: 'index', intersect: false },
          hover: { mode: 'nearest', intersect: true },
          scales: {
            xAxes: [{ gridLines: { color: 'rgba(255,255,255,.04)' }, ticks: { fontColor: tickColor, maxRotation: 0, autoSkip: true } }],
            yAxes: [{ gridLines: { color: gridColor }, ticks: { beginAtZero: true, fontColor: tickColor } }]
          }
        }
      });
    }
  });
</script>
