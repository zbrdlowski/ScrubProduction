<?php
/**
 * scrub_update_tracking_ajax.php
 * ------------------------------------------------------------------
 * AJAX backend pre "Update Tracking" alert na product_chart.php.
 * Sleduje, či bol update modelového roku premietnutý do:
 * Web, eBay, Graphics Templates, Seatcover Templates.
 *
 * Akcie (přes $_REQUEST['action']):
 *   - list_pending   (GET)  -> zoznam nedokončených tracking záznamov
 *   - count_pending  (GET)  -> počet nedokončených záznamov (pre badge)
 *   - toggle_flag    (POST) -> prepnutie jedného flagu (trackid, field, value)
 *   - update_flags   (POST) -> hromadné uloženie všetkých 4 flagov (trackid, ...)
 * ------------------------------------------------------------------
 */

session_start();
require_once __DIR__ . '/../includes/conn.php'; // subor je v scripts/, conn.php v includes/ (o uroven vyssie)

// ---- ACL: superadmin alebo explicitne povoleni editori --------------------
$productChartEditorIds = [2, 3, 16, 17, 10]; // explicitne povoleni useri (okrem superadmina)
$productChartUserId = (int) ($_SESSION['user_id'] ?? 0);
$productChartPermission = (int) ($_SESSION['permission'] ?? 0);
$canEditProductChart = $productChartPermission >= 900 || in_array($productChartUserId, $productChartEditorIds, true);

if (!$canEditProductChart) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Nedostatočné oprávnenie.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function jexit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Whitelist povolených stĺpcov (nikdy nedôveruj $_POST['field'] priamo v SQL)
const TRACKING_FLAGS = ['done_web', 'done_ebay', 'done_graphics_templates', 'done_seatcover_templates', 'done_products'];

function trackingFilterValue(string $key): string
{
    return trim((string)($_REQUEST[$key] ?? ''));
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------------
    case 'list_pending':
        $brandFilter = trackingFilterValue('brand');
        $modelFilter = trackingFilterValue('model');
        $rangeFilter = trackingFilterValue('range');

        $stmt = $conn->prepare(
            "SELECT t.trackid, t.modelcode, t.brand, t.model, t.rangeyear, t.new_year,
                    t.done_web, t.done_ebay, t.done_graphics_templates, t.done_seatcover_templates, t.done_products,
                    t.created_at
             FROM scrub_update_tracking t
             INNER JOIN (
                 SELECT modelcode, brand, model, MAX(trackid) AS max_trackid
                 FROM scrub_update_tracking
                 GROUP BY modelcode, brand, model
             ) latest ON latest.max_trackid = t.trackid
             WHERE NOT (t.done_web AND t.done_ebay AND t.done_graphics_templates AND t.done_seatcover_templates AND t.done_products)
               AND (? = '' OR t.brand = ?)
               AND (? = '' OR t.model = ?)
               AND (? = '' OR t.rangeyear = ?)
             ORDER BY t.created_at DESC"
        );
        $stmt->bind_param('ssssss', $brandFilter, $brandFilter, $modelFilter, $modelFilter, $rangeFilter, $rangeFilter);
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
    case 'count_pending':
        $brandFilter = trackingFilterValue('brand');
        $modelFilter = trackingFilterValue('model');
        $rangeFilter = trackingFilterValue('range');

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM scrub_update_tracking t
             INNER JOIN (
                 SELECT modelcode, brand, model, MAX(trackid) AS max_trackid
                 FROM scrub_update_tracking
                 GROUP BY modelcode, brand, model
             ) latest ON latest.max_trackid = t.trackid
             WHERE NOT (t.done_web AND t.done_ebay AND t.done_graphics_templates AND t.done_seatcover_templates AND t.done_products)
               AND (? = '' OR t.brand = ?)
               AND (? = '' OR t.model = ?)
               AND (? = '' OR t.rangeyear = ?)"
        );
        $stmt->bind_param('ssssss', $brandFilter, $brandFilter, $modelFilter, $modelFilter, $rangeFilter, $rangeFilter);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        jexit(['ok' => true, 'count' => (int)$row['cnt']]);
        break;

    // ------------------------------------------------------------------
    // Instantné prepnutie jedného flagu (napr. z alert modalu)
    case 'toggle_flag':
        $trackid = (int)($_POST['trackid'] ?? 0);
        $field = trim($_POST['field'] ?? '');
        $value = (int)($_POST['value'] ?? 0) ? 1 : 0;

        if ($trackid <= 0 || !in_array($field, TRACKING_FLAGS, true)) {
            jexit(['ok' => false, 'error' => 'Neplatné parametre.']);
        }

        $stmt = $conn->prepare("UPDATE scrub_update_tracking SET `$field` = ? WHERE trackid = ?");
        $stmt->bind_param('ii', $value, $trackid);
        $stmt->execute();
        $stmt->close();

        // Vráť aktuálny stav záznamu (pre prípadné prekreslenie na klientovi)
        $stmt = $conn->prepare(
            "SELECT trackid, done_web, done_ebay, done_graphics_templates, done_seatcover_templates, done_products
             FROM scrub_update_tracking WHERE trackid = ?"
        );
        $stmt->bind_param('i', $trackid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        jexit(['ok' => true, 'row' => $row]);
        break;

    // ------------------------------------------------------------------
    // Hromadné uloženie (z "Edit tracking" bloku v rozklikanom riadku)
    case 'update_flags':
        $trackid = (int)($_POST['trackid'] ?? 0);
        if ($trackid <= 0) {
            jexit(['ok' => false, 'error' => 'Chýba trackid.']);
        }

        $doneWeb = !empty($_POST['done_web']) ? 1 : 0;
        $doneEbay = !empty($_POST['done_ebay']) ? 1 : 0;
        $doneGfx = !empty($_POST['done_graphics_templates']) ? 1 : 0;
        $doneSct = !empty($_POST['done_seatcover_templates']) ? 1 : 0;
        $doneProducts = !empty($_POST['done_products']) ? 1 : 0;

        $stmt = $conn->prepare(
            "UPDATE scrub_update_tracking
             SET done_web=?, done_ebay=?, done_graphics_templates=?, done_seatcover_templates=?, done_products=?
             WHERE trackid = ?"
        );
        $stmt->bind_param('iiiiii', $doneWeb, $doneEbay, $doneGfx, $doneSct, $doneProducts, $trackid);
        $stmt->execute();
        $stmt->close();

        jexit([
            'ok' => true,
            'done_web' => $doneWeb,
            'done_ebay' => $doneEbay,
            'done_graphics_templates' => $doneGfx,
            'done_seatcover_templates' => $doneSct,
            'done_products' => $doneProducts,
        ]);
        break;

    default:
        jexit(['ok' => false, 'error' => 'Neznáma akcia.']);
}
