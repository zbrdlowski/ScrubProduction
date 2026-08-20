<?php
$_SESSION['uri'] = $_SERVER['REQUEST_URI'];

// ── Filtre z GET ───────────────────────────────────────────────────────────
$scrubrand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$scrubmodel = isset($_GET['model']) ? trim($_GET['model']) : '';
$scrubrange = isset($_GET['range']) ? trim($_GET['range']) : '';
$scrubcode = isset($_GET['scrubcocode']) ? trim($_GET['scrubcocode']) : '';

$canArrangeProductChartColumns = !empty($_SESSION['user_id']);

// Management a vyššie (300+) — Add/Update Model Year + Updates to Apply tracking.
// Rovnaký limit ako v scrub_model_manage_ajax.php a scrub_update_tracking_ajax.php,
// aby tlačidlá v UI neboli zavádzajúco aktívne pre niekoho, koho backend rovnako odmietne (403).
$canManageModelYears = isset($_SESSION['permission']) && (int) $_SESSION['permission'] >= 300;

// Ak príde priamy scrubcocode link, načítaj brand/model/range
if ($scrubcode !== '') {
    $stmt = $conn->prepare("SELECT DISTINCT brand, model, rangeyear FROM scrubdata WHERE modelcode = ? LIMIT 1");
    $stmt->bind_param('s', $scrubcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $scrubrand = $row['brand'];
        $scrubmodel = $row['model'];
        $scrubrange = $row['rangeyear'];
    }
}

// ── Nadpis ────────────────────────────────────────────────────────────────
if (empty($scrubrand))
    $nadpis = 'Select Brand';
elseif (empty($scrubmodel))
    $nadpis = 'Select Model';
elseif (empty($scrubrange))
    $nadpis = 'Select Production Years';
else
    $nadpis = 'Selected Result';

// ── SQL pre hlavnú tabuľku ────────────────────────────────────────────────
$sql_parts = "SELECT DISTINCT sd.brand, sd.model, sd.rangeyear, sd.modelcode, sm.meta_json,
                     st.trackid, st.new_year AS track_new_year,
                     st.done_web, st.done_ebay, st.done_graphics_templates, st.done_seatcover_templates, st.done_products
               FROM scrubdata sd
               LEFT JOIN scrubdata_meta sm
                 ON sm.brand = sd.brand
                AND sm.model = sd.model
                AND sm.rangeyear = sd.rangeyear
                AND sm.modelcode = sd.modelcode
               LEFT JOIN (
                   SELECT t1.*
                   FROM scrub_update_tracking t1
                   INNER JOIN (
                       SELECT modelcode, brand, model, MAX(trackid) AS max_id
                       FROM scrub_update_tracking
                       GROUP BY modelcode, brand, model
                   ) t2 ON t2.modelcode = t1.modelcode
                       AND t2.brand = t1.brand
                       AND t2.model = t1.model
                       AND t2.max_id = t1.trackid
               ) st ON st.modelcode = sd.modelcode
                   AND st.brand COLLATE utf8_slovak_ci = sd.brand
                   AND st.model COLLATE utf8_slovak_ci = sd.model";
$sql_where = [];
$sql_params = [];
$sql_types = '';

if ($scrubrand !== '') {
    $sql_where[] = "sd.brand    = ?";
    $sql_types .= 's';
    $sql_params[] = $scrubrand;
}
if ($scrubmodel !== '') {
    $sql_where[] = "sd.model    = ?";
    $sql_types .= 's';
    $sql_params[] = $scrubmodel;
}
if ($scrubrange !== '') {
    $sql_where[] = "sd.rangeyear= ?";
    $sql_types .= 's';
    $sql_params[] = $scrubrange;
}

$sql2 = $sql_parts . (!empty($sql_where) ? ' WHERE ' . implode(' AND ', $sql_where) : '') . ' ORDER BY sd.brand, sd.rangeyear DESC';

// ── Helper: prečíta yes/no hodnotu z meta_json bloku ──────────────────────
function metaVal(array $meta, string $block, string $field): string
{
    return strtolower(trim($meta[$block][$field] ?? 'no'));
}

// ── Helper: samostatný YES/NO badge pre Available/Web ────────────────────
function statusBadge(string $value, string $field): string
{
    $value = strtolower(trim($value));
    $isYes = ($value === 'yes');
    $class = $isYes ? 'badge-success' : 'badge-danger';
    $label = $isYes ? 'Y' : 'N';

    return '<span class="badge ' . $class . ' meta-status-badge" title="' . htmlspecialchars($field) . '">'
        . $label
        . '</span>';
}

// ── Helper: dvojica badges Available + Web s voliteľným linkom ────────────
function badgePair(string $avail, string $web, string $linkHref = '#'): string
{
    $html = '<span class="badge-pair">'
        . statusBadge($avail, 'Available')
        . statusBadge($web, 'Web')
        . '</span>';

    if ($linkHref !== '#') {
        return '<a href="' . htmlspecialchars($linkHref) . '" class="badge-pair-link">' . $html . '</a>';
    }

    return $html;
}

// ── Helper: 4 mini-badges pre blok "Configuration" (Y/N toggle polia) ─────
function configBadges(array $meta): string
{
    $fields = [
        'Create New Categories' => 'CNC',
        'Add Filters'            => 'FLT',
        'Add Accessories'        => 'ACC',
        'Add Existing Designs'   => 'EXD',
    ];

    $html = '<span class="badge-pair config-badges">';
    foreach ($fields as $field => $abbr) {
        $val = metaVal($meta, 'Configuration', $field);
        $isYes = ($val === 'yes');
        $class = $isYes ? 'badge-success' : 'badge-danger';
        $html .= '<span class="badge meta-status-badge config-mini-badge ' . $class . '" title="' . htmlspecialchars($field) . '">'
            . $abbr
            . '</span>';
    }
    $html .= '</span>';

    return $html;
}

// ── Helper: zostaví tracking pole (Web/eBay/Templates/Products) z DB riadku ─
function buildTracking(array $row): ?array
{
    if (empty($row['trackid'])) {
        return null;
    }
    return [
        'trackid' => (int) $row['trackid'],
        'new_year' => (int) $row['track_new_year'],
        'done_web' => (int) $row['done_web'],
        'done_ebay' => (int) $row['done_ebay'],
        'done_graphics_templates' => (int) $row['done_graphics_templates'],
        'done_seatcover_templates' => (int) $row['done_seatcover_templates'],
        'done_products' => (int) $row['done_products'],
    ];
}

// Päť kompaktných badge pre stav prenesenia posledného modelového update.
// Model bez tracking záznamu je v predvolenom stave: všetko je hotové.
function trackingBadges(?array $tracking): string
{
    $fields = [
        'done_web' => ['WEB', 'Web'],
        'done_ebay' => ['EB', 'eBay'],
        'done_graphics_templates' => ['GT', 'Graphics Templates'],
        'done_seatcover_templates' => ['ST', 'Seatcover Templates'],
        'done_products' => ['PR', 'Products'],
    ];

    $html = '<span class="badge-pair config-badges">';
    foreach ($fields as $field => [$abbr, $label]) {
        $isDone = $tracking === null || !empty($tracking[$field]);
        $class = $isDone ? 'badge-success' : 'badge-danger';
        $html .= '<span class="badge meta-status-badge config-mini-badge ' . $class . '" title="'
            . htmlspecialchars($label) . '">' . $abbr . '</span>';
    }
    return $html . '</span>';
}

function trackingIsPending(?array $tracking): bool
{
    return $tracking !== null && (
        empty($tracking['done_web'])
        || empty($tracking['done_ebay'])
        || empty($tracking['done_graphics_templates'])
        || empty($tracking['done_seatcover_templates'])
        || empty($tracking['done_products'])
    );
}
?>

<style>
    #scrubTable tbody tr.model-row:hover {
        background-color: #2c4a5e !important;
        cursor: pointer;
        transition: background-color 0.15s ease;
    }

    #trackingStatusFilter {
        background-color: #243447;
        color: #e0eaf4;
        border-color: #344f65;
    }

    #trackingStatusFilter:focus {
        border-color: #3c759e;
        box-shadow: 0 0 0 0.15rem rgba(60, 117, 158, 0.3);
    }

    #productChartColumnList .list-group-item {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #243447;
        color: #e0eaf4;
        border-color: #344f65;
        cursor: move;
        user-select: none;
    }

    #productChartColumnList .column-drag-handle {
        color: #8eabc4;
    }

    #productChartColumnList .ui-sortable-helper {
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.45);
    }

    #scrubTable tbody tr.model-row.open {
        background-color: #1e3448 !important;
    }

    .scrub-detail-row td {
        padding: 0 !important;
        border-top: none !important;
    }

    .scrub-detail-inner {
        padding: 16px 20px;
        background: #1a2a38;
        border-bottom: 2px solid #3c759e;
    }

    .scrub-detail-inner .block-card {
        background: #243447;
        border: 1px solid #344f65;
        border-radius: 6px;
        margin-bottom: 0;
    }

    .scrub-detail-inner .block-card .card-header {
        background: #2d4560;
        border-radius: 6px 6px 0 0;
        padding: 8px 14px;
        font-weight: 600;
        font-size: 0.85rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .scrub-detail-inner .block-card .card-body {
        padding: 10px 14px;
        font-size: 0.88rem;
    }

    .scrub-detail-inner .block-card .card-body .field-row {
        display: flex;
        justify-content: space-between;
        padding: 3px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .scrub-detail-inner .block-card .card-body .field-row:last-child {
        border-bottom: none;
    }

    .scrub-detail-inner .block-card .card-body .field-key {
        color: #8eabc4;
        margin-right: 10px;
    }

    .scrub-detail-inner .block-card .card-body .field-val {
        font-weight: 600;
        color: #e0eaf4;
    }

    .status-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-right: 5px;
        vertical-align: middle;
    }

    .status-dot.yes {
        background: #28a745;
    }

    .status-dot.no {
        background: #dc3545;
    }

    #scrubTable td .badge {
        font-size: 0.95rem;
        padding: 7px 14px;
        display: inline-flex;
        align-items: center;
        min-width: 70px;
        justify-content: center;
    }

    #scrubTable td .badge-pair {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    #scrubTable td .badge-pair-link {
        display: inline-flex;
        text-decoration: none;
    }

    #scrubTable td .meta-status-badge {
        min-width: 52px;
        padding: 7px 10px;
    }

    .meta-status-badge {
        min-width: 34px !important;
        padding: 6px 10px !important;
    }

    /* Config blok — 4 mini-badges (CNC/FLT/ACC/EXD) v jednej bunke */
    #scrubTable td .meta-status-badge.config-mini-badge {
        min-width: 38px !important;
        padding: 5px 5px !important;
        font-size: 0.68rem;
        letter-spacing: 0.02em;
    }

    .config-badges {
        flex-wrap: wrap;
        gap: 4px !important;
    }

    /* ── Modal: Add / Update Model Year — dark mode ─────────────────────── */
    #modelYearModal .modal-content {
        background: #1a2a38;
        color: #e0eaf4;
    }

    #modelYearModal label {
        color: #8eabc4;
    }

    #modelYearModal .form-control {
        background-color: #243447 !important;
        color: #e0eaf4 !important;
        border-color: #344f65 !important;
    }

    #modelYearModal .form-control::placeholder {
        color: #6c8298;
    }

    #modelYearModal .form-control:focus {
        background-color: #243447 !important;
        color: #e0eaf4 !important;
        border-color: #3c759e !important;
        box-shadow: 0 0 0 0.2rem rgba(60, 117, 158, 0.35);
    }

    #modelYearModal .form-control[readonly] {
        background-color: #1e2e3d !important;
        color: #8eabc4 !important;
        cursor: not-allowed;
    }

    /* Autocomplete dropdown so search */
    #mym_search_results,
    #mym_ambiguous_picker {
        background: #243447;
        border: 1px solid #344f65;
        border-radius: 4px;
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
    }

    #mym_search_results .list-group-item,
    #mym_ambiguous_picker .list-group-item {
        background: #243447;
        color: #e0eaf4;
        border-color: #344f65;
    }

    #mym_search_results .list-group-item:hover,
    #mym_ambiguous_picker .list-group-item:hover {
        background: #2c4a5e;
        color: #fff;
        cursor: pointer;
    }

    /* Kompatibilné modely tabuľka */
    #modelYearModal .table {
        color: #e0eaf4;
        background: #1a2a38;
    }

    #modelYearModal .table thead th {
        background: #2d4560;
        color: #fff;
        border-color: #344f65;
    }

    #modelYearModal .table td,
    #modelYearModal .table th {
        border-color: #344f65;
        vertical-align: middle;
    }

    #modelYearModal .table-bordered {
        border-color: #344f65;
    }

    #modelYearModal #mym_existing_years,
    #modelYearModal #mym_template_hint,
    #modelYearModal .text-muted {
        color: #8eabc4 !important;
    }

    /* Compat Year stĺpec — viditeľný iba v "edit existujúceho roku" režime */
    #mym_compat_table .mym-year-col {
        display: none;
    }

    #mym_compat_table.show-year-col .mym-year-col {
        display: table-cell;
    }

    /* Riadok označený na zmazanie (v edit režime existujúceho roku) */
    #mym_compat_body tr.mym-row-marked-delete {
        opacity: 0.45;
        text-decoration: line-through;
    }

    /* Prepínacie "pills" pre existujúce roky + Nový rok */
    #mym_year_pills {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .mym-year-pill {
        background: #243447;
        color: #8eabc4;
        border: 1px solid #344f65;
    }

    .mym-year-pill:hover {
        background: #2c4a5e;
        color: #fff;
    }

    .mym-year-pill.active {
        background: #3c759e;
        color: #fff;
        border-color: #3c759e;
        font-weight: 600;
    }

    .mym-year-pill-add.active {
        background: #2e7d32;
        border-color: #2e7d32;
    }

    #mym_edit_year_banner {
        background: #4a3b12;
        border-color: #6b5420;
        color: #ffd97a;
    }

    #mym_delete_year_btn {
        flex-shrink: 0;
        border-color: #c0392b;
        color: #ff8a80;
    }

    #mym_delete_year_btn:hover {
        background: #c0392b;
        color: #fff;
    }

    #mym_reset_form_btn {
        border-color: #344f65;
        color: #8eabc4;
    }

    #mym_reset_form_btn:hover {
        background: #2c4a5e;
        color: #fff;
    }

    #modelYearModal hr {
        border-color: #344f65;
    }

    #modelYearModal .close {
        color: #e0eaf4;
        text-shadow: none;
        opacity: 0.8;
    }

    #modelYearModal .close:hover {
        color: #fff;
        opacity: 1;
    }

    #mym_generate_code {
        border-color: #344f65;
    }

    #mym_generate_code:hover:not(:disabled) {
        background: #2c4a5e;
        color: #fff;
    }

    #mym_generate_code:disabled {
        opacity: 0.4;
    }

    /* ── Update Tracking sekcia (v rozklikanom riadku) — odlíšená farba ── */
    .tracking-section .tracking-label {
        color: #f0b429;
        font-weight: 600;
        font-size: 0.8rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .tracking-section .block-card .card-header {
        background: #5c4415;
        color: #ffd97a;
    }

    /* Tracking sekcia má teraz 5 položiek (Web/eBay/Graphics Templates/
       Seatcover Templates/Products) — vlastný rovnomerný 5-stĺpcový layout,
       nezávislý od .col-md-3 gridu, ktorý používajú kapability bloky nižšie
       (Graphics/Plastics/Seat Cover/Configuration zostávajú nezmenené na 4). */
    .tracking-section .col-track5 {
        flex: 0 0 100%;
        max-width: 100%;
        padding-left: 10px;
        padding-right: 10px;
        box-sizing: border-box;
    }

    @media (min-width: 768px) {
        .tracking-section .col-track5 {
            flex: 0 0 20%;
            max-width: 20%;
        }
    }

    .tracking-divider {
        border: none;
        border-top: 2px dashed #3c4f65;
        margin: 14px 0 16px 0;
    }

    .tracking-toggle-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 3px 0;
    }

    .tracking-toggle-row .field-key {
        color: #8eabc4;
    }

    /* ── Alert bell button + badge v hlavičke karty ─────────────────────── */
    #btnUpdateTrackingAlert {
        position: relative;
    }

    #trackingPendingBadge {
        position: absolute;
        top: -8px;
        right: -8px;
        min-width: 18px;
        height: 18px;
        padding: 0 4px;
        border-radius: 9px;
        background: #dc3545;
        color: #fff;
        font-size: 0.68rem;
        font-weight: 700;
        display: none;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }

    #trackingPendingBadge.show {
        display: inline-flex;
    }

    /* ── Update Tracking alert modal — dark mode ────────────────────────── */
    #updateTrackingModal .modal-content {
        background: #1a2a38;
        color: #e0eaf4;
    }

    #updateTrackingModal .table {
        color: #e0eaf4;
    }

    #updateTrackingModal .table thead th {
        background: #2d4560;
        color: #fff;
        border-color: #344f65;
    }

    #updateTrackingModal .table td,
    #updateTrackingModal .table th {
        border-color: #344f65;
        vertical-align: middle;
    }

    #updateTrackingModal .tracking-row-done {
        opacity: 0.4;
    }

    #updateTrackingModal .close {
        color: #e0eaf4;
        opacity: 0.8;
    }

    #updateTrackingModal .close:hover {
        color: #fff;
        opacity: 1;
    }
</style>

<?php
// ── Počet nedokončených tracking záznamov (pre alert badge) ────────────────
$trackingPendingCount = 0;
$resTrackCnt = $conn->query(
    "SELECT COUNT(*) AS cnt FROM scrub_update_tracking
     WHERE NOT (done_web AND done_ebay AND done_graphics_templates AND done_seatcover_templates AND done_products)"
);
if ($resTrackCnt) {
    $trackingPendingCount = (int) $resTrackCnt->fetch_assoc()['cnt'];
}
?>

<section class="content">

    <!-- ── Filter karta ─────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= htmlspecialchars($nadpis) ?></h3>
        </div>
        <div class="card-body">
            <table width="100%" class="table table-bordered mb-0">
                <tr>
                    <!-- BRAND -->
                    <td>
                        <select class="form-control" onchange="if(this.value) window.location=this.value;">
                            <option hidden>Pick Brand</option>
                            <?php
                            $res = $conn->query("SELECT DISTINCT brand FROM scrubdata ORDER BY brand ASC");
                            while ($r = $res->fetch_assoc()):
                                $url = 'index.php?page=product_chart&brand=' . urlencode($r['brand']);
                                $sel = ($r['brand'] === $scrubrand) ? ' selected' : '';
                                ?>
                                <option value="<?= htmlspecialchars($url) ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($r['brand']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </td>

                    <!-- MODEL -->
                    <td>
                        <?php if ($scrubrand === ''): ?>
                            <div class="text-muted pt-1">Model</div>
                        <?php else: ?>
                            <select class="form-control" onchange="if(this.value) window.location=this.value;">
                                <option hidden>Pick Model</option>
                                <?php
                                $stmt = $conn->prepare("SELECT DISTINCT model FROM scrubdata WHERE brand=? ORDER BY model ASC");
                                $stmt->bind_param('s', $scrubrand);
                                $stmt->execute();
                                $res = $stmt->get_result();
                                while ($r = $res->fetch_assoc()):
                                    $url = 'index.php?page=product_chart&brand=' . urlencode($scrubrand) . '&model=' . urlencode($r['model']);
                                    $sel = ($r['model'] === $scrubmodel) ? ' selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($url) ?>" <?= $sel ?>>
                                        <?= htmlspecialchars($r['model']) ?>
                                    </option>
                                <?php endwhile;
                                $stmt->close(); ?>
                            </select>
                        <?php endif; ?>
                    </td>

                    <!-- RANGE -->
                    <td>
                        <?php if ($scrubmodel === ''): ?>
                            <div class="text-muted pt-1">Production Years</div>
                        <?php else: ?>
                            <select class="form-control" onchange="if(this.value) window.location=this.value;">
                                <option hidden>Pick Year Range</option>
                                <?php
                                $stmt = $conn->prepare("SELECT DISTINCT rangeyear FROM scrubdata WHERE brand=? AND model=? ORDER BY rangeyear DESC");
                                $stmt->bind_param('ss', $scrubrand, $scrubmodel);
                                $stmt->execute();
                                $res = $stmt->get_result();
                                while ($r = $res->fetch_assoc()):
                                    $url = 'index.php?page=product_chart&brand=' . urlencode($scrubrand) . '&model=' . urlencode($scrubmodel) . '&range=' . urlencode($r['rangeyear']);
                                    $sel = ($r['rangeyear'] === $scrubrange) ? ' selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($url) ?>" <?= $sel ?>>
                                        <?= htmlspecialchars($r['rangeyear']) ?>
                                    </option>
                                <?php endwhile;
                                $stmt->close(); ?>
                            </select>
                        <?php endif; ?>
                    </td>

                    <!-- RESET -->
                    <td style="width:120px;">
                        <?php if ($scrubrand !== ''): ?>
                            <a href="?page=product_chart">
                                <button type="button" class="btn btn-block bg-gradient-primary btn-sm">RESET</button>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-block bg-gradient-secondary btn-sm disabled">RESET</button>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ── Výsledková karta ─────────────────────────────────── -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">Scrub Database</h3>
            <div class="d-flex align-items-center" style="gap:14px;">
                <small class="text-muted">Click on row to see details</small>
                <select id="trackingStatusFilter" class="form-control form-control-sm" style="width:165px;">
                    <option value="">All update statuses</option>
                    <option value="Pending">Pending updates</option>
                    <option value="Complete">Complete</option>
                </select>
                <?php if ($canArrangeProductChartColumns): ?>
                    <button type="button" class="btn btn-sm btn-outline-info"
                        data-toggle="modal" data-target="#columnArrangeModal">
                        <i class="fas fa-columns mr-1"></i> Arrange columns
                    </button>
                <?php endif; ?>
                <?php if ($canManageModelYears): ?>
                    <button type="button" id="btnUpdateTrackingAlert" class="btn btn-sm btn-outline-warning"
                        data-toggle="modal" data-target="#updateTrackingModal">
                        <i class="fas fa-bell mr-1"></i> Updates to Apply
                        <span id="trackingPendingBadge" class="<?= $trackingPendingCount > 0 ? 'show' : '' ?>">
                            <?= $trackingPendingCount ?>
                        </span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" data-toggle="modal" data-target="#modelYearModal">
                        <i class="fas fa-plus mr-1"></i> Add / Update Model Year
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-warning" disabled
                        title="Vyžaduje oprávnenie Management (300) a vyššie" data-toggle="tooltip">
                        <i class="fas fa-lock mr-1"></i> Updates to Apply
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" disabled
                        title="Vyžaduje oprávnenie Management (300) a vyššie" data-toggle="tooltip">
                        <i class="fas fa-lock mr-1"></i> Add / Update Model Year
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <table id="scrubTable" class="table table-bordered mb-0" style="font-size:1rem;">
                <thead>
                    <tr>
                        <th style="background:#444242; color:#fff; width:30px;"></th>
                        <th style="background:#444242; color:#fff;">BRAND</th>
                        <th style="background:#444242; color:#fff;">MODEL</th>
                        <th style="background:#444242; color:#fff;">RANGE</th>
                        <th style="background:#444242; color:#fff;">CODE</th>
                        <th style="background:#444242; color:#fff; text-align:center;">CONFIG</th>
                        <th style="background:#444242; color:#fff; text-align:center;">GRAPHICS</th>
                        <th style="background:#444242; color:#fff; text-align:center;">PLASTICS</th>
                        <th style="background:#444242; color:#fff; text-align:center;">SEAT COVER</th>
                        <th style="background:#444242; color:#fff; text-align:center;"
                            title="WEB / eBay / Graphics Templates / Seatcover Templates">UPDATE</th>
                    </tr>
                </thead>
                <tbody id="scrubTableBody">
                    <?php
                    // ── Hlavná query ──────────────────────────────────
                    if ($sql_types !== '') {
                        $stmt = $conn->prepare($sql2);
                        $stmt->bind_param($sql_types, ...$sql_params);
                        $stmt->execute();
                        $query = $stmt->get_result();
                    } else {
                        $query = $conn->query($sql2);
                    }
                                if (!$query) {
    die('SQL ERROR main query: ' . $conn->error . '<br><pre>' . htmlspecialchars($sql2) . '</pre>');
}
                    while ($row = $query->fetch_assoc()):
                        $code = $row['modelcode'];
                        $rowkey = md5($row['brand'] . '|' . $row['model'] . '|' . $row['rangeyear'] . '|' . $row['modelcode']);
                        $meta = [];
                        if (!empty($row['meta_json'])) {
                            $decoded = json_decode($row['meta_json'], true);
                            if (is_array($decoded))
                                $meta = $decoded;
                        }
                        $tracking = buildTracking($row);
                        $trackingPending = trackingIsPending($tracking);

                        // Rýchle hodnoty pre badges v riadku
                        $gfx_avail = metaVal($meta, 'Graphics', 'Available');
                        $gfx_web = metaVal($meta, 'Graphics', 'Web');
                        $pls_avail = metaVal($meta, 'Plastics', 'Available');
                        $pls_web = metaVal($meta, 'Plastics', 'Web');
                        $sct_avail = metaVal($meta, 'Seat Cover', 'Available');
                        $sct_web = metaVal($meta, 'Seat Cover', 'Web');

                        $plasticsLink = 'index.php?page=scrublistings&modelcode=' . urlencode($code);
                        ?>
                        <tr class="model-row" data-rowkey="<?= htmlspecialchars($rowkey) ?>"
                            data-brand="<?= htmlspecialchars($row['brand']) ?>"
                            data-model="<?= htmlspecialchars($row['model']) ?>"
                            data-rangeyear="<?= htmlspecialchars($row['rangeyear']) ?>"
                            data-modelcode="<?= htmlspecialchars($code) ?>"
                            data-meta="<?= htmlspecialchars(json_encode($meta, JSON_UNESCAPED_UNICODE)) ?>"
                            data-tracking="<?= htmlspecialchars(json_encode($tracking, JSON_UNESCAPED_UNICODE)) ?>">
                            <td class="text-center" style="vertical-align:middle;">
                                <i class="fas fa-chevron-right toggle-icon" style="font-size:0.75rem; color:#8eabc4;"></i>
                            </td>
                            <td><?= htmlspecialchars($row['brand']) ?></td>
                            <td><?= htmlspecialchars($row['model']) ?></td>
                            <td><?= htmlspecialchars($row['rangeyear']) ?></td>
                            <td class="text-center"><code><?= htmlspecialchars($code) ?></code></td>
                            <td class="text-center config-status-cell"><?= configBadges($meta) ?></td>

                            <td class="text-center graphics-status-cell"><?= badgePair($gfx_avail, $gfx_web, '#') ?></td>
                            <td class="text-center plastics-status-cell"><?= badgePair($pls_avail, $pls_web, $plasticsLink) ?></td>
                            <td class="text-center seatcover-status-cell"><?= badgePair($sct_avail, $sct_web, '#') ?></td>
                            <td class="text-center tracking-status-cell"
                                data-order="<?= $trackingPending ? '0' : '1' ?>"
                                data-search="<?= $trackingPending ? 'Pending' : 'Complete' ?>">
                                <?= trackingBadges($tracking) ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if (isset($stmt))
                        $stmt->close(); ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th style="background:#444242; color:#fff;"></th>
                        <th style="background:#444242; color:#fff;">BRAND</th>
                        <th style="background:#444242; color:#fff;">MODEL</th>
                        <th style="background:#444242; color:#fff;">RANGE</th>
                        <th style="background:#444242; color:#fff;">CODE</th>
                        <th style="background:#444242; color:#fff;">CONFIG</th>
                        <th style="background:#444242; color:#fff;">GRAPHICS</th>
                        <th style="background:#444242; color:#fff;">PLASTICS</th>
                        <th style="background:#444242; color:#fff;">SEAT COVER</th>
                        <th style="background:#444242; color:#fff;">UPDATE</th>
                    </tr>
                </tfoot>
            </table>
        </div><!-- /.card-body -->
    </div><!-- /.card -->

    <!-- ── Detail panely — MIMO DataTables tabuľky ─────────────────────────── -->
    <?php
    if ($sql_types !== '') {
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param($sql_types, ...$sql_params);
        $stmt2->execute();
        $query2 = $stmt2->get_result();
    } else {
        $query2 = $conn->query($sql2);
    }
    if (!$query2) {
    die('SQL ERROR detail query: ' . $conn->error . '<br><pre>' . htmlspecialchars($sql2) . '</pre>');
}
    while ($row2 = $query2->fetch_assoc()):
    $code2 = $row2['modelcode'];
    $rowkey2 = md5($row2['brand'] . '|' . $row2['model'] . '|' . $row2['rangeyear'] . '|' . $row2['modelcode']);
        $meta2 = [];
        if (!empty($row2['meta_json'])) {
            $d = json_decode($row2['meta_json'], true);
            if (is_array($d))
                $meta2 = $d;
        }
        $tracking2 = buildTracking($row2);
        ?>
        <div class="scrub-detail-panel" id="detail-<?= htmlspecialchars($rowkey2) ?>" style="display:none;">
            <div class="scrub-detail-inner">

                <!-- ── Update Tracking — Web / eBay / Graphics Templates / Seatcover Templates / Products ── -->
                <div class="tracking-section mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="tracking-label">
                            <i class="fas fa-bullhorn mr-1"></i> Update Tracking
                            <?php if ($tracking2): ?>
                                <span class="text-muted" style="text-transform:none; font-weight:400;">
                                    — rok <?= htmlspecialchars((string) $tracking2['new_year']) ?> premietnutý do:
                                </span>
                            <?php endif; ?>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-info btn-edit-tracking"
                            data-rowkey="<?= htmlspecialchars($rowkey2) ?>"
                            data-trackid="<?= $tracking2 ? (int) $tracking2['trackid'] : '' ?>"
                            style="<?= $tracking2 ? '' : 'display:none;' ?>">
                            <i class="fas fa-edit mr-1"></i> Edit tracking
                        </button>
                    </div>

                    <div class="row tracking-view-area" id="tracking-view-<?= htmlspecialchars($rowkey2) ?>"></div>

                    <div class="tracking-edit-area" id="tracking-edit-<?= htmlspecialchars($rowkey2) ?>" style="display:none;">
                        <div class="tracking-toggles" id="tracking-editor-<?= htmlspecialchars($rowkey2) ?>"></div>
                        <div class="d-flex justify-content-end mt-2" style="gap:8px;">
                            <button type="button" class="btn btn-sm btn-secondary btn-cancel-tracking-edit"
                                data-rowkey="<?= htmlspecialchars($rowkey2) ?>">Cancel</button>
                            <button type="button" class="btn btn-sm btn-success btn-save-tracking"
                                data-rowkey="<?= htmlspecialchars($rowkey2) ?>">
                                <i class="fas fa-save mr-1"></i> Save
                            </button>
                        </div>
                    </div>
                </div>

                <hr class="tracking-divider">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted" style="font-size:0.85rem;">
                        <?= htmlspecialchars($row2['brand']) ?>
                        — <?= htmlspecialchars($row2['model']) ?>
                        (<?= htmlspecialchars($row2['rangeyear']) ?>)
                        <code><?= htmlspecialchars($code2) ?></code>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-warning btn-edit-model-meta"
                        data-rowkey="<?= htmlspecialchars($rowkey2) ?>"
                        data-brand="<?= htmlspecialchars($row2['brand']) ?>"
                        data-model="<?= htmlspecialchars($row2['model']) ?>"
                        data-rangeyear="<?= htmlspecialchars($row2['rangeyear']) ?>"
                        data-modelcode="<?= htmlspecialchars($code2) ?>">
                        <i class="fas fa-edit mr-1"></i> Edit meta
                    </button>
                </div>

                <div class="row meta-view-area" id="view-<?= htmlspecialchars($rowkey2) ?>"></div>

                <div class="meta-edit-area" id="edit-<?= htmlspecialchars($rowkey2) ?>" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted">Pridaj alebo uprav bloky a fieldy.</small>
                        <button type="button" class="btn btn-sm btn-outline-info btn-add-meta-block"
                            data-rowkey="<?= htmlspecialchars($rowkey2) ?>">
                            <i class="fas fa-plus mr-1"></i> Add block
                        </button>
                    </div>
                    <div class="meta-blocks-editor" id="editor-<?= htmlspecialchars($rowkey2) ?>"></div>
                    <div class="d-flex justify-content-end mt-2" style="gap:8px;">
                        <button type="button" class="btn btn-sm btn-secondary btn-cancel-meta-edit"
                        data-rowkey="<?= htmlspecialchars($rowkey2) ?>">Cancel</button>
                        <button type="button" class="btn btn-sm btn-success btn-save-model-meta"
                            data-rowkey="<?= htmlspecialchars($rowkey2) ?>"
                            data-brand="<?= htmlspecialchars($row2['brand']) ?>"
                            data-model="<?= htmlspecialchars($row2['model']) ?>"
                            data-rangeyear="<?= htmlspecialchars($row2['rangeyear']) ?>"
                            data-modelcode="<?= htmlspecialchars($code2) ?>">
                            <i class="fas fa-save mr-1"></i> Save
                        </button>
                    </div>
                </div>

            </div>
        </div>
    <?php endwhile; ?>
    <?php if (isset($stmt2))
        $stmt2->close(); ?>

    <!-- Skrytý sklad pre detail panely — JS ich odtiaľto presúva za riadok -->
    <div id="scrubDetailStore" style="display:none;"></div>

    <!-- ── Modal: Add / Update Model Year ──────────────────────────────── -->
    <div class="modal fade" id="modelYearModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="background:#1a2a38; color:#e0eaf4;">
                <div class="modal-header" style="border-bottom:1px solid #344f65;">
                    <h5 class="modal-title">Add / Update Model Year</h5>
                    <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">

                    <!-- Vyhľadanie existujúceho modelu -->
                    <div class="form-group position-relative">
                        <label class="mb-1">Nájsť existujúci model (brand / model / modelcode)</label>
                        <input type="text" id="mym_search" class="form-control" placeholder="napr. KTM EXC alebo BLXB">
                        <div id="mym_search_results" class="list-group position-absolute"
                             style="z-index:1080; width:100%; max-height:220px; overflow-y:auto; display:none;"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label class="mb-1">Brand</label>
                            <input type="text" id="mym_brand" class="form-control">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="mb-1">Model</label>
                            <input type="text" id="mym_model" class="form-control">
                        </div>
                        <div class="form-group col-md-4">
                            <label class="mb-1">Modelcode</label>
                            <div class="input-group">
                                <input type="text" id="mym_modelcode" class="form-control" maxlength="5">
                                <div class="input-group-append">
                                    <button type="button" id="mym_generate_code" class="btn btn-outline-info"
                                        title="Vygenerovať nový unikátny kód (pre úplne nový model, ktorý nezdieľa kód so žiadnym iným)">
                                        <i class="fas fa-dice"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2" id="mym_existing_years">
                        <span class="text-muted">Zadaj alebo vyber modelcode.</span>
                    </div>

                    <div class="mb-2" id="mym_year_pills" style="display:none;"></div>

                    <div class="mb-2" id="mym_ambiguous_picker" style="display:none;"></div>

                    <div class="alert alert-warning py-2 px-3 mb-2 d-flex justify-content-between align-items-center"
                        id="mym_edit_year_banner" style="display:none;">
                        <span id="mym_edit_year_banner_text"></span>
                        <button type="button" id="mym_delete_year_btn" class="btn btn-sm btn-outline-danger ml-2">
                            <i class="fas fa-trash-alt mr-1"></i> Delete
                        </button>
                    </div>

                    <div id="mym_newyear_group">
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-4">
                                <label class="mb-1">Nový rok (exactyear)</label>
                                <input type="number" id="mym_newyear" class="form-control" min="1980" max="2100">
                            </div>
                            <div class="form-group col-md-8">
                                <label class="mb-1">Nový rangeyear (automaticky)</label>
                                <div class="form-control" style="background:#243447;">
                                    <span id="mym_rangeyear_preview">—</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr style="border-color:#344f65;">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong>Kompatibilné modely</strong>
                            <div class="text-muted small" id="mym_template_hint"></div>
                        </div>
                        <button type="button" id="mym_add_compat_row" class="btn btn-sm btn-outline-info">
                            <i class="fas fa-plus mr-1"></i> Add row
                        </button>
                    </div>

                    <table class="table table-sm table-bordered mb-0" id="mym_compat_table">
                        <thead>
                            <tr>
                                <th style="width:40px;" class="text-center">✓</th>
                                <th>Compat Brand</th>
                                <th>Compat Model</th>
                                <th style="width:90px;" class="text-center mym-year-col">Compat Year</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="mym_compat_body"></tbody>
                    </table>

                    <div id="mym_status" class="mt-3"></div>
                </div>
                <div class="modal-footer justify-content-between" style="border-top:1px solid #344f65;">
                    <button type="button" id="mym_reset_form_btn" class="btn btn-outline-secondary">
                        <i class="fas fa-undo mr-1"></i> Reset Form
                    </button>
                    <div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" id="mym_save_btn" class="btn btn-success">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Modal: Update Tracking Alert ────────────────────────────────── -->
    <div class="modal fade" id="updateTrackingModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header" style="border-bottom:1px solid #344f65;">
                    <h5 class="modal-title"><i class="fas fa-bell mr-2"></i>Updates to Apply</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">
                        Modely, kde bol nedávno pridaný/rozšírený modelový rok. Odklikni sekcie, kde už bol update aplikovaný.
                    </p>
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Brand</th>
                                <th>Model</th>
                                <th>Range</th>
                                <th>Code</th>
                                <th class="text-center">Nový rok</th>
                                <th class="text-center">Web</th>
                                <th class="text-center">eBay</th>
                                <th class="text-center">Graphics Templates</th>
                                <th class="text-center">Seatcover Templates</th>
                                <th class="text-center">Products</th>
                            </tr>
                        </thead>
                        <tbody id="tut_body">
                            <tr>
                                <td colspan="10" class="text-center text-muted py-3">Načítavam…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canArrangeProductChartColumns): ?>
        <div class="modal fade" id="columnArrangeModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content" style="background:#1a2a38; color:#e0eaf4;">
                    <div class="modal-header" style="border-bottom:1px solid #344f65;">
                        <h5 class="modal-title"><i class="fas fa-columns mr-2"></i>Arrange columns</h5>
                        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Potiahni stĺpce do požadovaného poradia. Nastavenie sa uloží do tvojho používateľského profilu.</p>
                        <ul id="productChartColumnList" class="list-group"></ul>
                    </div>
                    <div class="modal-footer" style="border-top:1px solid #344f65;">
                        <button type="button" id="resetProductChartColumns" class="btn btn-outline-secondary mr-auto">
                            Reset default
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" id="saveProductChartColumns" class="btn btn-info">
                            <i class="fas fa-save mr-1"></i> Apply
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</section>

<script src="scripts/product_chart_actions.js?v=<?= (int) @filemtime(__DIR__ . '/../scripts/product_chart_actions.js') ?>"></script>
<script src="scripts/scrub_model_manage.js?v=<?= (int) @filemtime(__DIR__ . '/../scripts/scrub_model_manage.js') ?>"></script>
<script src="scripts/scrub_update_tracking.js?v=<?= (int) @filemtime(__DIR__ . '/../scripts/scrub_update_tracking.js') ?>"></script>

<script>
    const productChartColumns = [
        { id: 'expand', label: 'Row details' },
        { id: 'brand', label: 'Brand' },
        { id: 'model', label: 'Model' },
        { id: 'range', label: 'Range' },
        { id: 'code', label: 'Code' },
        { id: 'config', label: 'Config' },
        { id: 'graphics', label: 'Graphics' },
        { id: 'plastics', label: 'Plastics' },
        { id: 'seat_cover', label: 'Seat Cover' },
        { id: 'update', label: 'Update' }
    ];
    const productChartPreferencesUrl = 'scripts/user_ui_preferences_ajax.php';

    function defaultProductChartColumnOrder() {
        return productChartColumns.map(function (column) { return column.id; });
    }

    function normalizeProductChartColumnOrder(value) {
        const defaults = defaultProductChartColumnOrder();
        if (!Array.isArray(value) || value.length !== defaults.length) return defaults;
        const unique = Array.from(new Set(value));
        return unique.length === defaults.length && defaults.every(function (id) { return unique.includes(id); })
            ? value
            : defaults;
    }

    function applyProductChartColumnOrder(order) {
        const defaults = defaultProductChartColumnOrder();
        $('#scrubTable tr').each(function () {
            const $cells = $(this).children('th, td');
            if ($cells.length !== defaults.length) return;
            const originalCells = $cells.toArray();
            const $row = $(this);
            order.forEach(function (columnId) {
                $row.append(originalCells[defaults.indexOf(columnId)]);
            });
        });
    }

    function saveProductChartPreferences(value, onSuccess) {
        $.ajax({
            url: productChartPreferencesUrl + '?action=save_scope',
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ scope: 'product_chart', value: value })
        }).done(function (resp) {
            if (!resp || !resp.ok) {
                alert(resp && resp.error ? resp.error : 'UI preferences could not be saved.');
                return;
            }
            if (typeof onSuccess === 'function') onSuccess();
        }).fail(function (xhr) {
            console.error('Saving UI preferences failed:', xhr.responseText);
            alert('UI preferences could not be saved.');
        });
    }

    function initializeProductChart(preferences) {
        const chartPreferences = preferences && preferences.product_chart ? preferences.product_chart : {};
        const savedFilters = chartPreferences.filters || {};
        const columnOrder = normalizeProductChartColumnOrder(chartPreferences.column_order);
        const columnIndex = function (columnId) { return columnOrder.indexOf(columnId); };

        applyProductChartColumnOrder(columnOrder);

        const labels = {};
        productChartColumns.forEach(function (column) { labels[column.id] = column.label; });
        const $columnList = $('#productChartColumnList');
        columnOrder.forEach(function (columnId) {
            $columnList.append(
                $('<li class="list-group-item" data-column-id="' + columnId + '"></li>')
                    .append('<i class="fas fa-grip-vertical column-drag-handle"></i>')
                    .append($('<span></span>').text(labels[columnId]))
            );
        });
        if ($columnList.length && $.fn.sortable) {
            $columnList.sortable({ axis: 'y', handle: '.column-drag-handle' });
        }

        const allowedPageLengths = [10, 25, 50, 100];
        const savedPageLength = parseInt(savedFilters.page_length, 10);

        // DataTable inicializácia
        const scrubTable = $('#scrubTable').DataTable({
            pageLength: allowedPageLengths.includes(savedPageLength) ? savedPageLength : 50,
            search: { search: String(savedFilters.search || '') },
            order: [
                [columnIndex('update'), 'asc'],
                [columnIndex('brand'), 'asc'],
                [columnIndex('model'), 'asc']
            ],
            columnDefs: [
                { orderable: false, targets: [columnIndex('expand')] }
            ],
            language: {
                search: 'Search:',
                lengthMenu: 'Show _MENU_ rows',
                info: 'Rows _START_ – _END_ of _TOTAL_',
                paginate: { previous: '‹', next: '›' }
            }
        });

        $('#trackingStatusFilter').val(savedFilters.tracking_status || '');
        if ($('#trackingStatusFilter').val()) {
            scrubTable.column(columnIndex('update'))
                .search('^' + $('#trackingStatusFilter').val() + '$', true, false)
                .draw();
        }

        function collectProductChartPreferences(order) {
            return {
                column_order: order || columnOrder,
                filters: {
                    tracking_status: $('#trackingStatusFilter').val() || '',
                    search: scrubTable.search() || '',
                    page_length: scrubTable.page.len()
                }
            };
        }

        let preferenceSaveTimer = null;
        function scheduleProductChartPreferenceSave() {
            clearTimeout(preferenceSaveTimer);
            preferenceSaveTimer = setTimeout(function () {
                saveProductChartPreferences(collectProductChartPreferences());
            }, 350);
        }

        $('#trackingStatusFilter').on('change', function () {
            const status = $(this).val();
            scrubTable.column(columnIndex('update')).search(status ? '^' + status + '$' : '', true, false).draw();
            scheduleProductChartPreferenceSave();
        });

        scrubTable.on('search.dt length.dt', scheduleProductChartPreferenceSave);

        $('#saveProductChartColumns').on('click', function () {
            const newOrder = $columnList.children().map(function () {
                return $(this).data('column-id');
            }).get();
            saveProductChartPreferences(collectProductChartPreferences(newOrder), function () {
                window.location.reload();
            });
        });

        $('#resetProductChartColumns').on('click', function () {
            saveProductChartPreferences(collectProductChartPreferences(defaultProductChartColumnOrder()), function () {
                window.location.reload();
            });
        });

        // Presuň všetky detail panely do skrytého skladu
        $('.scrub-detail-panel').each(function () {
            $('#scrubDetailStore').append($(this));
        });

        // Render meta VIEW blokov
        $('#scrubTable tbody tr.model-row').each(function () {
            const rowkey = $(this).data('rowkey');
            const meta = $(this).data('meta') || {};
            renderMetaView(rowkey, meta);
        });
    }

    $(document).ready(function () {
        $.get(productChartPreferencesUrl, { action: 'get' }, function (resp) {
            initializeProductChart(resp && resp.ok ? resp.preferences : {});
        }, 'json').fail(function (xhr) {
            console.error('Loading UI preferences failed:', xhr.responseText);
            initializeProductChart({});
        });
    });
</script>