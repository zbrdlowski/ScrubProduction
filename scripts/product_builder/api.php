<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/i18n.php';
$GLOBALS['pbLanguage'] = pb_resolve_language();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function pb_reply(array $data, int $status = 200): void
{
    if (isset($data['error']) && is_string($data['error'])) $data['error'] = pb_translate_error($data['error']);
    http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
if (!isset($_SESSION['permission'])) pb_reply(['ok'=>false,'error'=>'Please sign in to the application.'],401);
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/boxes.php';
$action = (string) ($_GET['action'] ?? '');
if (!pb_can_access_api($_SERVER['REQUEST_METHOD'] ?? '', $action)) pb_reply(['ok'=>false,'error'=>'You do not have permission to perform this action.'],403);
try {
    if (!pb_schema_ready($pdo)) pb_reply(['ok'=>false,'error'=>'The product database needs to be installed or updated. Contact an administrator.'],503);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!auth_can('products.manage')) pb_reply(['ok'=>false,'error'=>'You do not have permission to make changes.'],403);
        if (!hash_equals((string) ($_SESSION['product_builder_csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) || empty($_SESSION['product_builder_csrf'])) {
            pb_reply(['ok'=>false,'error'=>'Your form has expired. Refresh the page and try again.'],403);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if ($action !== 'import_uni' && !is_array($data)) throw new InvalidArgumentException('Invalid form data.');
        if ($action === 'save_box') pb_reply(['ok'=>true,'id'=>pb_save_box($pdo,$data)]);
        if ($action === 'delete_box') { pb_delete_box($pdo,$data); pb_reply(['ok'=>true]); }
        if ($action === 'save_graphics_version') pb_reply(['ok'=>true,'id'=>pb_save_graphics_version($pdo,$data)]);
        if ($action === 'delete_graphics_version') { pb_delete_graphics_version($pdo,$data); pb_reply(['ok'=>true]); }
        if ($action === 'save_design') pb_reply(['ok'=>true,'id'=>pb_save_design($pdo,$data)]);
        if ($action === 'delete_design') { pb_delete_design($pdo,$data); pb_reply(['ok'=>true]); }
        if ($action === 'save_uni') pb_reply(['ok'=>true,'id'=>pb_save_uni($pdo,$conn,$data)]);
        if ($action === 'delete_uni') { pb_delete_uni($pdo,(int) ($data['id'] ?? 0),(int) ($data['version'] ?? 0)); pb_reply(['ok'=>true]); }
        if ($action === 'generate_modelcode') pb_reply(['ok'=>true,'code'=>scrub_generate_modelcode($conn)]);
        if ($action === 'generate_sku') pb_reply(['ok'=>true,'sku'=>pb_generate_sku($pdo,pb_text($data['bundle_type'] ?? '', 'Bundle type', 5))]);
        if ($action === 'save_channel_listing') pb_reply(['ok'=>true,'listing'=>pb_save_channel_listing($pdo,$data,(int) ($_SESSION['user_id'] ?? 0) ?: null)]);
        if ($action === 'save_product_image') pb_reply(['ok'=>true,'image'=>pb_save_product_image($pdo,$data)]);
        if ($action === 'save_product') pb_reply(['ok'=>true,'product'=>pb_save_product($pdo,$data,(int) ($_SESSION['user_id'] ?? 0) ?: null)]);
        if ($action === 'import_uni') {
            $file = $_FILES['csv'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) > 2097152) throw new InvalidArgumentException('Choose a CSV file no larger than 2 MB.');
            pb_reply(['ok'=>true,'result'=>pb_import_uni($pdo,$conn,pb_parse_csv($file['tmp_name']))]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'boxes') pb_reply(['ok'=>true,'rows'=>pb_box_list($pdo)]);
        if ($action === 'designs') pb_reply(['ok'=>true,'rows'=>pb_design_list($pdo),'versions'=>pb_graphics_version_list($pdo)]);
        if ($action === 'categories') pb_reply(['ok'=>true,'rows'=>$pdo->query('SELECT u.*, (SELECT COUNT(*) FROM product_builder_products p WHERE p.uni_category_id=u.id) AS usage_count FROM product_builder_uni_categories u ORDER BY brand,category_name')->fetchAll(PDO::FETCH_ASSOC)]);
        if ($action === 'plastic_item') pb_reply(['ok'=>true,'item'=>pb_find_plastic_item($pdo, pb_text($_GET['barcode'] ?? '', 'Product code', 100))]);
        if ($action === 'models') {
            $level = (string) ($_GET['level'] ?? 'brands');
            if ($level === 'brands') $rows = $pdo->query("SELECT DISTINCT brand FROM scrubdata WHERE brand<>'' AND modelcode<>'' ORDER BY brand")->fetchAll(PDO::FETCH_ASSOC);
            elseif ($level === 'models') {
                $stmt = $pdo->prepare("SELECT DISTINCT model FROM scrubdata WHERE brand=? AND model<>'' AND modelcode<>'' ORDER BY model"); $stmt->execute([pb_text($_GET['brand'] ?? '', 'Brand', 100)]); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($level === 'years') {
                $stmt = $pdo->prepare("SELECT DISTINCT rangeyear AS year_range,modelcode FROM scrubdata WHERE brand=? AND model=? AND rangeyear<>'' AND modelcode<>'' ORDER BY rangeyear DESC,modelcode");
                $stmt->execute([pb_text($_GET['brand'] ?? '', 'Brand', 100),pb_text($_GET['model'] ?? '', 'Model', 150)]); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else throw new InvalidArgumentException('Invalid category selection level.');
            pb_reply(['ok'=>true,'rows'=>$rows]);
        }
        if ($action === 'product') {
            $stmt = $pdo->prepare('SELECT p.*,u.brand AS uni_brand,u.category_name AS uni_name,b.name AS box_name,b.length_cm AS box_length_cm,b.width_cm AS box_width_cm,b.height_cm AS box_height_cm,b.weight_kg AS box_weight_kg FROM product_builder_products p LEFT JOIN product_builder_uni_categories u ON u.id=p.uni_category_id LEFT JOIN product_builder_boxes b ON b.id=p.box_id WHERE p.id=?'); $stmt->execute([(int) ($_GET['id'] ?? 0)]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) pb_reply(['ok'=>false,'error'=>'Product not found.'],404);
            $row['components'] = pb_product_components($pdo, (int)$row['id']);
            $row['channel_listings'] = pb_channel_listings($pdo, [(int)$row['id']])[(int)$row['id']];
            pb_reply(['ok'=>true,'product'=>$row]);
        }
        if ($action === 'products') {
            pb_reply(['ok' => true] + pb_product_overview($pdo, pb_text($_GET['q'] ?? '', 'Search', 150, false), $_GET['page'] ?? 1, $_GET['per_page'] ?? 100));
        }
    } else pb_reply(['ok'=>false,'error'=>'Method not allowed.'],405);
    pb_reply(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { pb_reply(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (PDOException $e) {
    error_log('Product builder: ' . $e->getMessage());
    $duplicate = ($e->errorInfo[1] ?? 0) === 1062;
    pb_reply(['ok'=>false,'error'=>$duplicate ? 'This brand and category combination or modelcode already exists. Refresh the data and try again.' : (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 'Unable to load the data. Please try again.' : 'Unable to save the data. Please try again.')], $duplicate ? 409 : 500);
} catch (Throwable $e) { error_log('Product builder: ' . $e->getMessage()); pb_reply(['ok'=>false,'error'=>'The operation failed. Please try again.'],500); }
