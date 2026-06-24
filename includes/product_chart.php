<?php
$_SESSION['uri'] = $_SERVER['REQUEST_URI'];

// ── Filtre z GET ───────────────────────────────────────────────────────────
$scrubrand = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$scrubmodel = isset($_GET['model']) ? trim($_GET['model']) : '';
$scrubrange = isset($_GET['range']) ? trim($_GET['range']) : '';
$scrubcode = isset($_GET['scrubcocode']) ? trim($_GET['scrubcocode']) : '';

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
$sql_parts = "SELECT DISTINCT sd.brand, sd.model, sd.rangeyear, sd.modelcode, sm.meta_json
               FROM scrubdata sd
               LEFT JOIN scrubdata_meta sm ON sm.modelcode = sd.modelcode";
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
?>

<style>
    #scrubTable tbody tr.model-row:hover {
        background-color: #2c4a5e !important;
        cursor: pointer;
        transition: background-color 0.15s ease;
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
    .meta-status-badge{
    min-width:34px !important;
    padding:6px 10px !important;
}
</style>

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
                                    <?= htmlspecialchars($r['brand']) ?></option>
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
                                        <?= htmlspecialchars($r['model']) ?></option>
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
                                        <?= htmlspecialchars($r['rangeyear']) ?></option>
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
            <small class="text-muted">Click on row to see details</small>
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
                        <th style="background:#444242; color:#fff; text-align:center;">GRAPHICS</th>
                        <th style="background:#444242; color:#fff; text-align:center;">PLASTICS</th>
                        <th style="background:#444242; color:#fff; text-align:center;">SEAT COVER</th>
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

                    while ($row = $query->fetch_assoc()):
                        $code = $row['modelcode'];
                        $meta = [];
                        if (!empty($row['meta_json'])) {
                            $decoded = json_decode($row['meta_json'], true);
                            if (is_array($decoded))
                                $meta = $decoded;
                        }

                        // Rýchle hodnoty pre badges v riadku
                        $gfx_avail = metaVal($meta, 'Graphics', 'Available');
                        $gfx_web = metaVal($meta, 'Graphics', 'Web');
                        $pls_avail = metaVal($meta, 'Plastics', 'Available');
                        $pls_web = metaVal($meta, 'Plastics', 'Web');
                        $sct_avail = metaVal($meta, 'Seat Cover', 'Available');
                        $sct_web = metaVal($meta, 'Seat Cover', 'Web');

                        $plasticsLink = 'index.php?page=scrublistings&modelcode=' . urlencode($code);
                        ?>
                        <tr class="model-row" data-modelcode="<?= htmlspecialchars($code) ?>"
                            data-meta="<?= htmlspecialchars(json_encode($meta, JSON_UNESCAPED_UNICODE)) ?>">
                            <td class="text-center" style="vertical-align:middle;">
                                <i class="fas fa-chevron-right toggle-icon" style="font-size:0.75rem; color:#8eabc4;"></i>
                            </td>
                            <td><?= htmlspecialchars($row['brand']) ?></td>
                            <td><?= htmlspecialchars($row['model']) ?></td>
                            <td><?= htmlspecialchars($row['rangeyear']) ?></td>
                            <td class="text-center"><code><?= htmlspecialchars($code) ?></code></td>

                            <td class="text-center"><?= badgePair($gfx_avail, $gfx_web, '#') ?></td>
                            <td class="text-center"><?= badgePair($pls_avail, $pls_web, $plasticsLink) ?></td>
                            <td class="text-center"><?= badgePair($sct_avail, $sct_web, '#') ?></td>
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
                        <th style="background:#444242; color:#fff;">GRAPHICS</th>
                        <th style="background:#444242; color:#fff;">PLASTICS</th>
                        <th style="background:#444242; color:#fff;">SEAT COVER</th>
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
    while ($row2 = $query2->fetch_assoc()):
        $code2 = $row2['modelcode'];
        $meta2 = [];
        if (!empty($row2['meta_json'])) {
            $d = json_decode($row2['meta_json'], true);
            if (is_array($d))
                $meta2 = $d;
        }
        ?>
        <div class="scrub-detail-panel" id="detail-<?= htmlspecialchars($code2) ?>" style="display:none;">
            <div class="scrub-detail-inner">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted" style="font-size:0.85rem;">                        
                        <?= htmlspecialchars($row2['brand']) ?>
                        — <?= htmlspecialchars($row2['model']) ?>
                        (<?= htmlspecialchars($row2['rangeyear']) ?>)
                        <code><?= htmlspecialchars($code2) ?></code>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-warning btn-edit-model-meta"
                        data-modelcode="<?= htmlspecialchars($code2) ?>">
                        <i class="fas fa-edit mr-1"></i> Edit meta
                    </button>
                </div>

                <div class="row meta-view-area" id="view-<?= htmlspecialchars($code2) ?>"></div>

                <div class="meta-edit-area" id="edit-<?= htmlspecialchars($code2) ?>" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted">Pridaj alebo uprav bloky a fieldy.</small>
                        <button type="button" class="btn btn-sm btn-outline-info btn-add-meta-block"
                            data-modelcode="<?= htmlspecialchars($code2) ?>">
                            <i class="fas fa-plus mr-1"></i> Add block
                        </button>
                    </div>
                    <div class="meta-blocks-editor" id="editor-<?= htmlspecialchars($code2) ?>"></div>
                    <div class="d-flex justify-content-end mt-2" style="gap:8px;">
                        <button type="button" class="btn btn-sm btn-secondary btn-cancel-meta-edit"
                            data-modelcode="<?= htmlspecialchars($code2) ?>">Cancel</button>
                        <button type="button" class="btn btn-sm btn-success btn-save-model-meta"
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

</section>

<script src="scripts/product_chart_actions.js"></script>

<script>
    $(document).ready(function () {
        // DataTable inicializácia
        $('#scrubTable').DataTable({
            pageLength: 50,
            order: [[1, 'asc'], [2, 'asc']],
            columnDefs: [
                { orderable: false, targets: [0] }
            ],
            language: {
                search: 'Search:',
                lengthMenu: 'Show _MENU_ rows',
                info: 'Rows _START_ – _END_ of _TOTAL_',
                paginate: { previous: '‹', next: '›' }
            }
        });

        // Presuň všetky detail panely do skrytého skladu
        $('.scrub-detail-panel').each(function () {
            $('#scrubDetailStore').append($(this));
        });

        // Render meta VIEW blokov
        $('#scrubTable tbody tr.model-row').each(function () {
            const code = $(this).data('modelcode');
            const meta = $(this).data('meta') || {};
            renderMetaView(code, meta);
        });
    });
</script>