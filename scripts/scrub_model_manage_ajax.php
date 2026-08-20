<?php
/**
 * scrub_model_manage_ajax.php
 * ------------------------------------------------------------------
 * AJAX backend pre "Add / Update Model Year" nástroj na product_chart.php.
 *
 * DÔLEŽITÉ – priprav pred nasadením:
 * - Uprav include nižšie (session_start / DB bootstrap) tak, aby zodpovedal
 *   tvojmu reálnemu bootstrap súboru (rovnako ako ostatné ajax_*.php v projekte).
 * - session premenná pre oprávnenie je $_SESSION['permission'] (900 = superadmin) — už nastavené.
 *
 * Akcie (přes $_REQUEST['action'] / JSON body pre 'save'):
 *   - search_modelcode  (GET, ?q=...)          -> autocomplete brand/model/modelcode
 *   - get_group         (GET, ?modelcode=...)  -> existujúce roky + rangeyear + compat template
 *   - save              (POST, JSON body)      -> transakčné uloženie nového roku + compat riadkov
 * ------------------------------------------------------------------
 */

// ---- Bootstrap (uprav podľa reálneho projektu) ----------------------------
session_start();
require_once __DIR__ . '/../includes/conn.php'; // subor je v scripts/, conn.php v includes/ (o uroven vyssie)

// ---- ACL – management a vyššie (permission 300+) --------------------------
if (!isset($_SESSION['permission']) || (int)$_SESSION['permission'] < 300) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nedostatočné oprávnenie.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function jexit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------------
    // Autocomplete – hľadanie existujúcich brand/model/modelcode kombinácií
    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    // Vygeneruje nový unikátny modelcode (4 znaky, rovnaká bezpečná znaková
    // sada ako pôvodný Python skript — bez 0/O/I/1/L kvôli zámene pri čítaní).
    // Over si vždy proti DB, aby sa nikdy neduplikoval s existujúcim kódom.
    // ------------------------------------------------------------------
    case 'generate_modelcode':
        $safeChars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $safeLen = strlen($safeChars);
        $maxAttempts = 50;
        $found = null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = '';
            for ($j = 0; $j < 4; $j++) {
                $code .= $safeChars[random_int(0, $safeLen - 1)];
            }
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM scrubdata WHERE modelcode = ?");
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $cnt = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
            if ($cnt === 0) {
                $found = $code;
                break;
            }
        }

        if ($found === null) {
            jexit(['ok' => false, 'error' => 'Nepodarilo sa vygenerovať unikátny kód, skús to znova.']);
        }
        jexit(['ok' => true, 'code' => $found]);
        break;

    // ------------------------------------------------------------------
    case 'search_modelcode':
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            jexit(['ok' => true, 'rows' => []]);
        }
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            "SELECT DISTINCT brand, model, modelcode FROM scrubdata
             WHERE brand LIKE ? OR model LIKE ? OR modelcode LIKE ?
             ORDER BY brand, model LIMIT 20"
        );
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = $r;
        }
        $stmt->close();
        jexit(['ok' => true, 'rows' => $out]);
        break;

    // ------------------------------------------------------------------
    // Detail existujúcej skupiny (podľa modelcode + brand + model): roky,
    // rangeyear, a compat "template" (posledný existujúci rok) na predvyplnenie.
    //
    // POZOR: jeden modelcode môže byť zdieľaný viacerými modelmi naraz
    // (napr. Honda CRF250R aj CRF450R majú rovnaký kód G3UP, lebo majú
    // rovnaký grafický kit). Preto ak brand/model nie sú zadané a modelcode
    // je nejednoznačný, vrátime zoznam kandidátov na výber namiesto dát.
    // ------------------------------------------------------------------
    case 'get_group':
        $modelcode = trim($_GET['modelcode'] ?? '');
        $brandFilter = trim($_GET['brand'] ?? '');
        $modelFilter = trim($_GET['model'] ?? '');
        if ($modelcode === '') {
            jexit(['ok' => false, 'error' => 'Chýba modelcode.']);
        }

        // Ak nemáme presný brand+model, over či je modelcode jednoznačný
        if ($brandFilter === '' || $modelFilter === '') {
            $stmtA = $conn->prepare("SELECT DISTINCT brand, model FROM scrubdata WHERE modelcode = ? ORDER BY brand, model");
            $stmtA->bind_param('s', $modelcode);
            $stmtA->execute();
            $resA = $stmtA->get_result();
            $combos = [];
            while ($r = $resA->fetch_assoc()) {
                $combos[] = $r;
            }
            $stmtA->close();

            if (count($combos) > 1) {
                // Nejednoznačné – nechaj frontend nech si vyberie správnu kombináciu
                jexit(['ok' => true, 'ambiguous' => true, 'groups' => $combos]);
            }
            if (count($combos) === 1) {
                $brandFilter = $combos[0]['brand'];
                $modelFilter = $combos[0]['model'];
            }
            // count($combos) === 0 → úplne nový modelcode, pokračuje nižšie s prázdnym brand/model
        }

        $sql = "SELECT brand, model, exactyear, rangeyear FROM scrubdata WHERE modelcode = ?";
        $types = 's';
        $params = [$modelcode];
        if ($brandFilter !== '' && $modelFilter !== '') {
            $sql .= " AND brand = ? AND model = ?";
            $types .= 'ss';
            $params[] = $brandFilter;
            $params[] = $modelFilter;
        }
        $sql .= " ORDER BY exactyear ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $years = [];
        $brand = $brandFilter;
        $model = $modelFilter;
        $rangeyear = '';
        while ($r = $res->fetch_assoc()) {
            $years[] = (int)$r['exactyear'];
            $brand = $r['brand'];
            $model = $r['model'];
            $rangeyear = $r['rangeyear'];
        }
        $stmt->close();

        $template = [];
        $templateSourceYear = null;
        if (!empty($years)) {
            $templateSourceYear = max($years);
            $stmt2 = $conn->prepare(
                "SELECT compatbrand, compatmodel FROM scrubcompat
                 WHERE compatcode = ? AND compatyear = ?
                 ORDER BY compatbrand, compatmodel"
            );
            $stmt2->bind_param('si', $modelcode, $templateSourceYear);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($r2 = $res2->fetch_assoc()) {
                $template[] = $r2;
            }
            $stmt2->close();
        }

        jexit([
            'ok' => true,
            'ambiguous' => false,
            'exists' => !empty($years),
            'brand' => $brand,
            'model' => $model,
            'rangeyear' => $rangeyear,
            'years' => $years,
            'template_source_year' => $templateSourceYear,
            'compat_template' => $template,
        ]);
        break;

    // ------------------------------------------------------------------
    // Uloženie nového roku (prípadne úplne nového modelu) + compat riadkov.
    // Transakčné: update rangeyear na existujúcich riadkoch + insert nového
    // riadku scrubdata + insert vybraných/pridaných scrubcompat riadkov.
    // ------------------------------------------------------------------
    case 'save':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            jexit(['ok' => false, 'error' => 'Neplatné dáta.']);
        }

        $brand = trim($data['brand'] ?? '');
        $model = trim($data['model'] ?? '');
        $modelcode = trim($data['modelcode'] ?? '');
        $newyear = (int)($data['newyear'] ?? 0);
        $compatRows = is_array($data['compat_rows'] ?? null) ? $data['compat_rows'] : [];

        if ($brand === '' || $model === '' || $modelcode === '' || $newyear < 1980 || $newyear > 2100) {
            jexit(['ok' => false, 'error' => 'Chýbajú alebo sú neplatné povinné polia (brand/model/modelcode/rok).']);
        }
        if (strlen($modelcode) > 5) {
            // scrubcompat.compatcode je varchar(5), aj keď scrubdata.modelcode je varchar(7)
            jexit(['ok' => false, 'error' => 'Modelcode nesmie mať viac ako 5 znakov (limit stĺpca scrubcompat.compatcode).']);
        }

        $conn->begin_transaction();
        try {
            // Zamkni existujúce riadky TEJTO KONKRÉTNEJ skupiny (modelcode + brand + model),
            // aby sa predišlo race condition pri súbežnom pridávaní rokov k tomu istému modelu.
            //
            // POZOR: modelcode sám o sebe NIE JE jednoznačný identifikátor skupiny — viacero
            // rôznych modelov môže zdieľať rovnaký modelcode (napr. Honda CRF250R aj CRF450R
            // majú rovnaký grafický kód). Preto MUSÍME filtrovať aj podľa brand+model, inak by
            // update rangeyear zasiahol aj cudzie modely s tým istým kódom.
            $stmt = $conn->prepare("SELECT exactyear, rangeyear FROM scrubdata WHERE modelcode = ? AND brand = ? AND model = ? FOR UPDATE");
            $stmt->bind_param('sss', $modelcode, $brand, $model);
            $stmt->execute();
            $res = $stmt->get_result();
            $years = [];
            $oldRangeyear = null;
            while ($r = $res->fetch_assoc()) {
                $years[] = (int)$r['exactyear'];
                $oldRangeyear = $r['rangeyear']; // všetky riadky tejto skupiny majú rovnaký rangeyear
            }
            $stmt->close();

            if (in_array($newyear, $years, true)) {
                throw new Exception("Rok {$newyear} už pre {$brand} {$model} [{$modelcode}] existuje.");
            }

            $years[] = $newyear;
            $newRangeyear = (min($years) === max($years))
                ? (string) min($years)
                : min($years) . '-' . max($years);

            // 1) Update rangeyear na existujúcich riadkoch TEJTO skupiny (modelcode+brand+model)
            if (!empty($years)) {
                $stmtU = $conn->prepare("UPDATE scrubdata SET rangeyear = ? WHERE modelcode = ? AND brand = ? AND model = ?");
                $stmtU->bind_param('ssss', $newRangeyear, $modelcode, $brand, $model);
                $stmtU->execute();
                $stmtU->close();
            }

            // 1b) DÔLEŽITÉ: scrubdata_meta (Graphics/Plastics/Seat Cover/Configuration
            // zaškrtnutia) je naviazaná na presnú kombináciu brand+model+rangeyear+modelcode.
            // Ak zmeníme rangeyear v scrubdata a nepremietneme to aj sem, appka stratí spojenie
            // so starou meta_json a všetko sa bude javiť ako nezaškrtnuté (aj keď dáta v DB
            // fyzicky existujú, len "visia" na starom, už neplatnom rangeyear).
            if ($oldRangeyear !== null && $oldRangeyear !== $newRangeyear) {
                $stmtM = $conn->prepare(
                    "UPDATE scrubdata_meta SET rangeyear = ?
                     WHERE brand = ? AND model = ? AND modelcode = ? AND rangeyear = ?"
                );
                $stmtM->bind_param('sssss', $newRangeyear, $brand, $model, $modelcode, $oldRangeyear);
                $stmtM->execute();
                $stmtM->close();
            }

            // 2) Insert nového riadku pre nový rok
            $stmtI = $conn->prepare(
                "INSERT INTO scrubdata (brand, model, exactyear, rangeyear, modelcode) VALUES (?, ?, ?, ?, ?)"
            );
            $stmtI->bind_param('ssiss', $brand, $model, $newyear, $newRangeyear, $modelcode);
            $stmtI->execute();
            $stmtI->close();

            // 3) Insert compat riadkov (s dedupikáciou, keby niektorý už náhodou existoval)
            $insertedCompat = 0;
            $stmtCheck = $conn->prepare(
                "SELECT compatid FROM scrubcompat WHERE compatcode=? AND compatbrand=? AND compatmodel=? AND compatyear=?"
            );
            $stmtIns = $conn->prepare(
                "INSERT INTO scrubcompat (compatcode, compatbrand, compatmodel, compatyear) VALUES (?, ?, ?, ?)"
            );
            foreach ($compatRows as $row) {
                $cb = trim($row['compatbrand'] ?? '');
                $cm = trim($row['compatmodel'] ?? '');
                if ($cb === '' || $cm === '') {
                    continue;
                }
                $stmtCheck->bind_param('sssi', $modelcode, $cb, $cm, $newyear);
                $stmtCheck->execute();
                $exists = $stmtCheck->get_result()->fetch_assoc();
                if ($exists) {
                    continue;
                }
                $stmtIns->bind_param('sssi', $modelcode, $cb, $cm, $newyear);
                $stmtIns->execute();
                $insertedCompat++;
            }
            $stmtCheck->close();
            $stmtIns->close();

            // 4) Založ tracking záznam ("nutne premietnuť update aj do Web/eBay/Templates")
            $createdBy = $_SESSION['user_id'] ?? null;
            $stmtTrack = $conn->prepare(
                "INSERT INTO scrub_update_tracking
                    (modelcode, brand, model, rangeyear, new_year, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmtTrack->bind_param('ssssii', $modelcode, $brand, $model, $newRangeyear, $newyear, $createdBy);
            $stmtTrack->execute();
            $newTrackId = $stmtTrack->insert_id;
            $stmtTrack->close();

            $conn->commit();
            jexit([
                'ok' => true,
                'new_rangeyear' => $newRangeyear,
                'inserted_compat' => $insertedCompat,
                'track_id' => $newTrackId,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            jexit(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------------
    // Kompatibilné modely pre KONKRÉTNY existujúci rok (napr. 2017 v strede
    // rozsahu 2016-2018). Vracia aj compatid, aby vedel frontend rozlíšiť
    // existujúce riadky (update/delete) od nových (insert).
    // ------------------------------------------------------------------
    case 'get_year_compat':
        $modelcode = trim($_GET['modelcode'] ?? '');
        $year = (int)($_GET['year'] ?? 0);
        if ($modelcode === '' || $year <= 0) {
            jexit(['ok' => false, 'error' => 'Chýba modelcode alebo rok.']);
        }

        $stmt = $conn->prepare(
            "SELECT compatid, compatbrand, compatmodel, compatyear
             FROM scrubcompat
             WHERE compatcode = ? AND compatyear = ?
             ORDER BY compatbrand, compatmodel"
        );
        $stmt->bind_param('si', $modelcode, $year);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();

        jexit(['ok' => true, 'rows' => $rows]);
        break;

    // ------------------------------------------------------------------
    // Uloženie zmien kompatibilných modelov pre KONKRÉTNY existujúci rok.
    // Nemení scrubdata/rangeyear — iba insert/update/delete v scrubcompat,
    // presne pre daný modelcode + rok. Každý riadok v payloade je buď:
    //   - {compatid: 0, compatbrand, compatmodel, compatyear}  → INSERT
    //   - {compatid: N, compatbrand, compatmodel, compatyear}  → UPDATE
    //   - {compatid: N, deleted: true}                          → DELETE
    // ------------------------------------------------------------------
    case 'save_year_compat':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            jexit(['ok' => false, 'error' => 'Neplatné dáta.']);
        }

        $modelcode = trim($data['modelcode'] ?? '');
        $year = (int)($data['year'] ?? 0);
        $rows = is_array($data['compat_rows'] ?? null) ? $data['compat_rows'] : [];

        if ($modelcode === '' || $year <= 0) {
            jexit(['ok' => false, 'error' => 'Chýba modelcode alebo rok.']);
        }
        if (strlen($modelcode) > 5) {
            jexit(['ok' => false, 'error' => 'Modelcode nesmie mať viac ako 5 znakov.']);
        }

        $conn->begin_transaction();
        try {
            $inserted = 0;
            $updated = 0;
            $deleted = 0;

            $stmtDel = $conn->prepare("DELETE FROM scrubcompat WHERE compatid = ? AND compatcode = ?");
            $stmtUpd = $conn->prepare(
                "UPDATE scrubcompat SET compatbrand=?, compatmodel=?, compatyear=? WHERE compatid = ? AND compatcode = ?"
            );
            $stmtIns = $conn->prepare(
                "INSERT INTO scrubcompat (compatcode, compatbrand, compatmodel, compatyear) VALUES (?, ?, ?, ?)"
            );

            foreach ($rows as $row) {
                $compatid = (int)($row['compatid'] ?? 0);
                $isDeleted = !empty($row['deleted']);

                if ($isDeleted) {
                    if ($compatid > 0) {
                        $stmtDel->bind_param('is', $compatid, $modelcode);
                        $stmtDel->execute();
                        $deleted++;
                    }
                    continue;
                }

                $cb = trim($row['compatbrand'] ?? '');
                $cm = trim($row['compatmodel'] ?? '');
                $cy = (int)($row['compatyear'] ?? $year);
                if ($cb === '' || $cm === '') {
                    continue;
                }

                if ($compatid > 0) {
                    $stmtUpd->bind_param('ssiis', $cb, $cm, $cy, $compatid, $modelcode);
                    $stmtUpd->execute();
                    $updated++;
                } else {
                    $stmtIns->bind_param('sssi', $modelcode, $cb, $cm, $cy);
                    $stmtIns->execute();
                    $inserted++;
                }
            }

            $stmtDel->close();
            $stmtUpd->close();
            $stmtIns->close();

            $conn->commit();
            jexit([
                'ok' => true,
                'inserted' => $inserted,
                'updated' => $updated,
                'deleted' => $deleted,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            jexit(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ------------------------------------------------------------------
    // Zmazanie KONKRÉTNEHO roku modelu (presná kombinácia brand+model+
    // modelcode+exactyear). NIKDY nemaže podľa samotného modelcode — ten
    // môže byť zdieľaný viacerými modelmi (rovnaké plasty/templaty), preto
    // by to inak mohlo omylom vymazať dáta cudzieho modelu.
    //
    // scrubcompat (compatcode+compatyear) sa zmaže IBA VTEDY, ak po zmazaní
    // tohto riadku už žiadny INÝ model (iný brand/model) nepoužíva rovnaký
    // modelcode+rok — inak by sme zmazali kompatibilné modely, ktoré ešte
    // potrebuje ten druhý (zdieľaný) model.
    // ------------------------------------------------------------------
    case 'delete_year':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            jexit(['ok' => false, 'error' => 'Neplatné dáta.']);
        }

        $modelcode = trim($data['modelcode'] ?? '');
        $brand = trim($data['brand'] ?? '');
        $model = trim($data['model'] ?? '');
        $year = (int) ($data['year'] ?? 0);

        if ($modelcode === '' || $brand === '' || $model === '' || $year <= 0) {
            jexit(['ok' => false, 'error' => 'Chýbajú povinné polia (modelcode/brand/model/rok).']);
        }

        $conn->begin_transaction();
        try {
            // 1) Nájdi a zamkni presne TENTO riadok (nie celú skupinu)
            $stmt = $conn->prepare(
                "SELECT lineid FROM scrubdata WHERE brand=? AND model=? AND modelcode=? AND exactyear=? FOR UPDATE"
            );
            $stmt->bind_param('sssi', $brand, $model, $modelcode, $year);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                throw new Exception("Záznam pre {$brand} {$model} [{$modelcode}] rok {$year} neexistuje (možno už bol zmazaný).");
            }

            $stmt = $conn->prepare("DELETE FROM scrubdata WHERE lineid = ?");
            $stmt->bind_param('i', $row['lineid']);
            $stmt->execute();
            $stmt->close();

            // 2) Prepočítaj rangeyear zo zostávajúcich rokov TEJTO skupiny (brand+model+modelcode)
            $stmt = $conn->prepare("SELECT exactyear, rangeyear FROM scrubdata WHERE brand=? AND model=? AND modelcode=?");
            $stmt->bind_param('sss', $brand, $model, $modelcode);
            $stmt->execute();
            $res = $stmt->get_result();
            $remainingYears = [];
            $oldRangeyear = null;
            while ($r = $res->fetch_assoc()) {
                $remainingYears[] = (int) $r['exactyear'];
                $oldRangeyear = $r['rangeyear'];
            }
            $stmt->close();

            if (!empty($remainingYears)) {
                $newRangeyear = (min($remainingYears) === max($remainingYears))
                    ? (string) min($remainingYears)
                    : min($remainingYears) . '-' . max($remainingYears);

                if ($oldRangeyear !== $newRangeyear) {
                    $stmtU = $conn->prepare("UPDATE scrubdata SET rangeyear=? WHERE brand=? AND model=? AND modelcode=?");
                    $stmtU->bind_param('ssss', $newRangeyear, $brand, $model, $modelcode);
                    $stmtU->execute();
                    $stmtU->close();

                    // rovnaká propagácia do scrubdata_meta ako pri pridávaní roku,
                    // aby sa nestratili zaškrtnutia Graphics/Plastics/Seat Cover/Configuration
                    $stmtM = $conn->prepare(
                        "UPDATE scrubdata_meta SET rangeyear=? WHERE brand=? AND model=? AND modelcode=? AND rangeyear=?"
                    );
                    $stmtM->bind_param('sssss', $newRangeyear, $brand, $model, $modelcode, $oldRangeyear);
                    $stmtM->execute();
                    $stmtM->close();
                }
            }

            // 3) scrubcompat zmaž len vtedy, ak už žiadny INÝ model (iný brand/model)
            // nepoužíva rovnaký modelcode+rok
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM scrubdata WHERE modelcode=? AND exactyear=?");
            $stmt->bind_param('si', $modelcode, $year);
            $stmt->execute();
            $stillUsedElsewhere = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            $deletedCompat = 0;
            $compatKept = $stillUsedElsewhere > 0;
            if (!$compatKept) {
                $stmt = $conn->prepare("DELETE FROM scrubcompat WHERE compatcode=? AND compatyear=?");
                $stmt->bind_param('si', $modelcode, $year);
                $stmt->execute();
                $deletedCompat = $stmt->affected_rows;
                $stmt->close();
            }

            $conn->commit();
            jexit([
                'ok' => true,
                'deleted_compat' => $deletedCompat,
                'compat_kept' => $compatKept,
                'remaining_years' => $remainingYears,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            jexit(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        jexit(['ok' => false, 'error' => 'Neznáma akcia.']);
}