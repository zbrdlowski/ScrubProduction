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

  if ($value < 60) {
    return 'yty-daily-limit-mid';
  }

  return 'yty-daily-limit-high';
}

function ytyChartScaleMax(array $series): int
{
  $maxValue = 0;
  foreach ($series as $yearSeries) {
    if (!is_array($yearSeries)) {
      continue;
    }
    foreach ($yearSeries as $value) {
      if ($value === null) {
        continue;
      }
      $maxValue = max($maxValue, (int) $value);
    }
  }

  if ($maxValue <= 0) {
    return 0;
  }

  $step = $maxValue <= 100 ? 10 : ($maxValue <= 500 ? 50 : 100);
  return (int) (ceil($maxValue / $step) * $step);
}

$requestedYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
$report = ytyBuildReportData($conn, $requestedYear);
$years = $report['years'];
$selectedYear = (int) ($report['selected_year'] ?? ($years[0] ?? $report['current_year']));

$labels = $report['labels'];
$series = $report['series'];
$totals = $report['totals'];
$latest = $report['latest'];
$averages = $report['averages'];
$dailyRows = $report['daily_rows'];
$dailyAverages = $report['daily_averages'];
$dailyDepartmentWeeklySeries = $report['daily_department_weekly_series'] ?? [
  'labels' => [],
  'series_by_year' => [],
  'scale_series' => [],
];
$dailyYear = (int) ($report['daily_year'] ?? $selectedYear);
$dailyImportDays = (int) ($dailyAverages['import_days'] ?? count($dailyRows));
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

$departmentColors = [
  'G' => ['border' => '#34a853', 'fill' => 'rgba(52, 168, 83, 0.12)'],
  'P' => ['border' => '#4285f4', 'fill' => 'rgba(66, 133, 244, 0.12)'],
  'F' => ['border' => '#fbbc04', 'fill' => 'rgba(251, 188, 4, 0.12)'],
  'S' => ['border' => '#ea4335', 'fill' => 'rgba(234, 67, 53, 0.12)'],
];

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
  'weeklyScaleMax' => ytyChartScaleMax($series),
  'departmentWeeklyLabels' => $dailyDepartmentWeeklySeries['labels'] ?? [],
  'departmentWeeklySeriesByYear' => $dailyDepartmentWeeklySeries['series_by_year'] ?? [],
  'departmentColors' => $departmentColors,
  'departmentWeeklyScaleMax' => ytyChartScaleMax($dailyDepartmentWeeklySeries['scale_series'] ?? []),
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

  .yty-chart-controls {
    display: flex;
    align-items: center;
    gap: 12px 20px;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }

  .yty-chart-control-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .yty-chart-control-title {
    color: #adb5bd;
    font-size: 12px;
    font-weight: 700;
  }

  .yty-chart-toggle {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin: 0;
    color: #dee2e6;
    cursor: pointer;
    font-weight: 400;
  }

  .yty-chart-toggle input {
    margin: 0;
  }

  .yty-week-table th,
  .yty-week-table td,
  .yty-daily-table th,
  .yty-daily-table td {
    text-align: center;
    vertical-align: middle !important;
    white-space: nowrap;
  }

  .yty-week-table thead th,
  .yty-daily-table thead th,
  .yty-floating-table-header thead th {
    background: #343a40;
    border-color: #454d55 !important;
    color: #f8f9fa;
  }

  .yty-floating-table-header {
    display: none;
    position: fixed;
    left: 0;
    top: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 1040;
    box-shadow: 0 3px 8px rgba(0, 0, 0, .26);
  }

  .yty-floating-table-header.is-active {
    display: block;
  }

  .yty-floating-table-header table {
    margin-bottom: 0 !important;
    table-layout: fixed;
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
          <p>AVG / Day <?= (int) $dailyYear ?> (G+F+P+S)</p>
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
    <div class="card-header">
      <h3 class="card-title mb-0">Products by Department per Week: <?= (int) $dailyYear ?></h3>
    </div>
    <div class="card-body">
      <div class="yty-chart-controls" id="ytyDepartmentControls">
        <div class="yty-chart-control-group">
          <span class="yty-chart-control-title">Years</span>
          <?php foreach ($years as $year): ?>
            <label class="yty-chart-toggle">
              <input
                type="checkbox"
                value="<?= (int) $year ?>"
                data-yty-department-year
                <?= (int) $year === $selectedYear ? 'checked' : '' ?>
              >
              <span><?= (int) $year ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="yty-chart-control-group">
          <span class="yty-chart-control-title">Departments</span>
          <?php foreach (['G' => 'G', 'P' => 'P', 'F' => 'F', 'S' => 'S'] as $departmentCode => $departmentLabel): ?>
            <label class="yty-chart-toggle">
              <input
                type="checkbox"
                value="<?= ytyH($departmentCode) ?>"
                data-yty-department-code
                checked
              >
              <span><?= ytyH($departmentLabel) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="yty-chart-wrap">
        <canvas id="ytyDepartmentChart"></canvas>
      </div>
    </div>
  </div>

  <div class="card card-dark">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
      <h3 class="card-title mb-0">Daily Values: <?= (int) $dailyYear ?> (<?= ytyNum($dailyImportDays) ?> import days)</h3>
      <span class="yty-muted-note">
        Daily values use import date. AVG / Day divides G+F+P+S by displayed import days, not calendar days. Historical rows come from WeeklyStat.xlsx; Darkscrub continues from <?= ytyH(date('d.m.Y', strtotime((string) $transition['start_date']))) ?>.
      </span>
    </div>
    <div class="card-body table-responsive p-0">
      <table class="table table-bordered table-striped table-sm mb-0 yty-daily-table">
        <thead>
          <tr>
            <th>Import Date</th>
            <th>Day</th>
            <th>Week</th>
            <th>G / day</th>
            <th>P / day</th>
            <th>F / day</th>
            <th>S / day</th>
            <th>G+P+S / day</th>
            <th>G+P+S / After wknd</th>
            <th>G+F+P+S / day</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$dailyRows): ?>
            <tr>
              <td colspan="10" class="text-muted py-4">No daily data for <?= (int) $dailyYear ?> yet.</td>
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
              <td><?= ytyNum((int) ($row['graphics_count'] ?? 0)) ?></td>
              <td><?= ytyNum((int) ($row['plastics_count'] ?? 0)) ?></td>
              <td><?= ytyNum((int) ($row['fitting_count'] ?? 0)) ?></td>
              <td><?= ytyNum((int) ($row['seat_count'] ?? 0)) ?></td>
              <td class="<?= ytyH(ytyDailyLimitClass($productsWithoutFitting)) ?>"><?= ytyNum($productsWithoutFitting) ?></td>
              <td class="<?= ytyH(trim(($afterWeekendCount !== null ? 'is-after-weekend ' : '') . ytyDailyLimitClass($afterWeekendCount === null ? null : (int) $afterWeekendCount))) ?>">
                <?= $afterWeekendCount !== null ? ytyNum((int) $afterWeekendCount) : '' ?>
              </td>
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
    initYtyStickyTableHeaders();

    if (!window.Chart) {
      return;
    }

    var ytyData = <?= json_encode($chartPayload, JSON_UNESCAPED_SLASHES) ?>;
    var gridColor = 'rgba(255,255,255,.10)';
    var tickColor = '#ced4da';
    var weeklyScaleMax = Number(ytyData.weeklyScaleMax || 0);
    var departmentWeeklyScaleMax = Number(ytyData.departmentWeeklyScaleMax || 0);

    function weeklyYAxisTicks() {
      var ticks = { beginAtZero: true, fontColor: tickColor };
      if (weeklyScaleMax > 0) {
        ticks.max = weeklyScaleMax;
      }
      return ticks;
    }

    function departmentYAxisTicks() {
      var ticks = { beginAtZero: true, fontColor: tickColor };
      if (departmentWeeklyScaleMax > 0) {
        ticks.max = departmentWeeklyScaleMax;
      }
      return ticks;
    }

    function valuesFor(year) {
      return (ytyData.series[String(year)] || []).map(function (value) {
        return value === null ? null : Number(value);
      });
    }

    function initYtyStickyTableHeaders() {
      var tables = Array.prototype.map.call(
        document.querySelectorAll('.yty-daily-table, .yty-week-table'),
        function (table) {
          return {
            table: table,
            wrap: table.closest('.table-responsive') || table.parentNode
          };
        }
      ).filter(function (config) {
        return config.table && config.table.tHead;
      });

      if (!tables.length) {
        return;
      }

      var floating = document.createElement('div');
      floating.className = 'yty-floating-table-header';
      document.body.appendChild(floating);

      var activeTable = null;
      var activeClone = null;
      var scheduled = false;

      function stickyTopOffset() {
        var header = document.querySelector('.main-header');
        if (!header) {
          return 0;
        }

        var style = window.getComputedStyle(header);
        if (style.position !== 'fixed' && style.position !== 'sticky') {
          return 0;
        }

        var rect = header.getBoundingClientRect();
        return rect.top <= 0 && rect.bottom > 0 ? Math.ceil(rect.bottom) : 0;
      }

      function syncClone(config) {
        if (activeTable !== config.table) {
          floating.innerHTML = '';
          activeClone = config.table.cloneNode(false);
          activeClone.removeAttribute('id');
          activeClone.appendChild(config.table.tHead.cloneNode(true));
          floating.appendChild(activeClone);
          activeTable = config.table;
        }

        var sourceCells = config.table.tHead.rows[0].cells;
        var cloneCells = activeClone.tHead.rows[0].cells;
        Array.prototype.forEach.call(sourceCells, function (cell, index) {
          if (!cloneCells[index]) {
            return;
          }
          var width = cell.getBoundingClientRect().width + 'px';
          cloneCells[index].style.width = width;
          cloneCells[index].style.minWidth = width;
          cloneCells[index].style.maxWidth = width;
        });

        var tableRect = config.table.getBoundingClientRect();
        activeClone.style.width = tableRect.width + 'px';
        activeClone.style.marginLeft = '-' + (config.wrap.scrollLeft || 0) + 'px';
      }

      function updateStickyHeader() {
        scheduled = false;
        var top = stickyTopOffset();
        var activeConfig = null;

        tables.some(function (config) {
          var tableRect = config.table.getBoundingClientRect();
          var headerHeight = config.table.tHead.getBoundingClientRect().height;
          if (tableRect.top <= top && tableRect.bottom > top + headerHeight) {
            activeConfig = config;
            return true;
          }
          return false;
        });

        if (!activeConfig) {
          floating.classList.remove('is-active');
          activeTable = null;
          activeClone = null;
          floating.innerHTML = '';
          return;
        }

        var wrapRect = activeConfig.wrap.getBoundingClientRect();
        syncClone(activeConfig);
        floating.style.top = top + 'px';
        floating.style.left = wrapRect.left + 'px';
        floating.style.width = wrapRect.width + 'px';
        floating.classList.add('is-active');
      }

      function requestStickyUpdate() {
        if (scheduled) {
          return;
        }
        scheduled = true;
        window.requestAnimationFrame(updateStickyHeader);
      }

      window.addEventListener('scroll', requestStickyUpdate, { passive: true });
      window.addEventListener('resize', requestStickyUpdate);
      tables.forEach(function (config) {
        config.wrap.addEventListener('scroll', requestStickyUpdate, { passive: true });
      });
      requestStickyUpdate();
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
            yAxes: [{ gridLines: { color: gridColor }, ticks: weeklyYAxisTicks() }]
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

    var departmentKeys = ['G', 'P', 'F', 'S'];
    var departmentDash = {
      G: [],
      P: [6, 4],
      F: [2, 3],
      S: [10, 4]
    };

    function departmentValuesFor(year, department) {
      var byYear = ytyData.departmentWeeklySeriesByYear || {};
      var values = ((byYear[String(year)] || {})[department]) || [];
      return values.map(function (value) {
        return value === null ? null : Number(value);
      });
    }

    var departmentDatasets = [];
    ytyData.years.forEach(function (year) {
      departmentKeys.forEach(function (department) {
        var colors = ytyData.colors[String(year)] || { border: '#5bc0de', fill: 'rgba(91, 192, 222, .12)' };
        var hidden = String(year) !== selectedYear;
        departmentDatasets.push({
          label: String(year) + ' ' + department,
          data: departmentValuesFor(year, department),
          borderColor: colors.border,
          backgroundColor: colors.fill,
          pointBackgroundColor: colors.border,
          pointBorderColor: colors.border,
          pointRadius: 2,
          pointHoverRadius: 4,
          borderWidth: 2,
          borderDash: departmentDash[department] || [],
          lineTension: 0.25,
          fill: false,
          spanGaps: false,
          hidden: hidden,
          ytyYear: String(year),
          ytyDepartment: department
        });
      });
    });

    function checkedValues(selector) {
      var values = {};
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (input) {
        if (input.checked) {
          values[String(input.value)] = true;
        }
      });
      return values;
    }

    var departmentCanvas = document.getElementById('ytyDepartmentChart');
    if (departmentCanvas) {
      var departmentChart = new Chart(departmentCanvas.getContext('2d'), {
        type: 'line',
        data: {
          labels: ytyData.departmentWeeklyLabels || [],
          datasets: departmentDatasets
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          legend: { labels: { fontColor: tickColor, boxWidth: 12 } },
          tooltips: { mode: 'index', intersect: false },
          hover: { mode: 'nearest', intersect: true },
          scales: {
            xAxes: [{ gridLines: { color: 'rgba(255,255,255,.04)' }, ticks: { fontColor: tickColor, maxRotation: 0, autoSkip: true } }],
            yAxes: [{ gridLines: { color: gridColor }, ticks: departmentYAxisTicks() }]
          }
        }
      });

      function syncDepartmentChartVisibility() {
        var activeYears = checkedValues('[data-yty-department-year]');
        var activeDepartments = checkedValues('[data-yty-department-code]');
        departmentChart.data.datasets.forEach(function (dataset, index) {
          var shouldHide = !activeYears[dataset.ytyYear] || !activeDepartments[dataset.ytyDepartment];
          dataset.hidden = shouldHide;
          departmentChart.getDatasetMeta(index).hidden = shouldHide ? true : null;
        });
        departmentChart.update();
      }

      Array.prototype.forEach.call(
        document.querySelectorAll('[data-yty-department-year], [data-yty-department-code]'),
        function (input) {
          input.addEventListener('change', syncDepartmentChartVisibility);
        }
      );

      syncDepartmentChartVisibility();
    }
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
            yAxes: [{ gridLines: { color: gridColor }, ticks: weeklyYAxisTicks() }]
          }
        }
      });
    }
  });
</script>
