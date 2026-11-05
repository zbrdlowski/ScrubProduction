<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/designs.php';
require_once __DIR__ . '/graphics_versions.php';
function pb_save_uni(PDO $pdo, mysqli $conn, array $data): int
{
    $row = pb_validate_uni($data); $id = (int) ($data['id'] ?? 0);
    pb_modelcode_lock($pdo);
    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE product_builder_uni_categories SET brand=?,category_name=?,included_models=?,price_unlicensed=?,price_licensed=?,version=version+1 WHERE id=? AND version=?');
            $stmt->execute(array_merge(array_values($row), [$id,(int) ($data['version'] ?? 0)]));
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Another user has changed this category. Refresh the list.');
        } else {
            $code = strtoupper(pb_text($data['modelcode'] ?? '', 'Modelcode', 5, false));
            if ($code === '') $code = scrub_generate_modelcode($conn);
            if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/D', $code)) throw new InvalidArgumentException('Generate a valid four-character modelcode.');
            $stmt = $pdo->prepare('SELECT 1 FROM scrubdata WHERE modelcode=? LIMIT 1'); $stmt->execute([$code]);
            if ($stmt->fetchColumn()) throw new InvalidArgumentException('This modelcode is already used by a specific model. Generate a new one.');
            $stmt = $pdo->prepare('INSERT INTO product_builder_uni_categories (brand,category_name,included_models,price_unlicensed,price_licensed,modelcode) VALUES (?,?,?,?,?,?)');
            $stmt->execute(array_merge(array_values($row), [$code])); $id = (int) $pdo->lastInsertId();
        }
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('darkscrub_modelcode_write')"); }
}
function pb_import_uni(PDO $pdo, mysqli $conn, array $rows): array
{
    // Reimport adds missing categories, preserving user edits and all identifiers.
    pb_modelcode_lock($pdo);
    try {
        $pdo->beginTransaction(); $added = 0; $skipped = 0;
        $find = $pdo->prepare('SELECT id FROM product_builder_uni_categories WHERE brand=? AND category_name=?');
        $insert = $pdo->prepare('INSERT INTO product_builder_uni_categories (brand,category_name,included_models,price_unlicensed,price_licensed,modelcode) VALUES (?,?,?,?,?,?)');
        foreach ($rows as $input) {
            $row = pb_validate_uni($input); $find->execute([$row['brand'],$row['category_name']]);
            if ($find->fetchColumn()) { $skipped++; continue; }
            // mysqli cannot see this transaction's uncommitted inserts.
            $check = $pdo->prepare('SELECT id FROM product_builder_uni_categories WHERE modelcode=?');
            do { $code = scrub_generate_modelcode($conn); $check->execute([$code]); } while ($check->fetchColumn());
            $insert->execute(array_merge(array_values($row), [$code])); $added++;
        }
        $pdo->commit(); return ['added'=>$added,'skipped'=>$skipped];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('darkscrub_modelcode_write')"); }
}
function pb_delete_uni(PDO $pdo, int $id, int $version): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM product_builder_uni_categories WHERE id=? AND version=? FOR UPDATE'); $stmt->execute([$id,$version]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('This category has changed or no longer exists. Refresh the list.');
        $stmt = $pdo->prepare('SELECT id FROM product_builder_products WHERE uni_category_id=? LIMIT 1'); $stmt->execute([$id]);
        if ($stmt->fetchColumn()) throw new InvalidArgumentException('This category is used by a saved product. Change its category first.');
        $pdo->prepare('DELETE FROM product_builder_uni_categories WHERE id=?')->execute([$id]); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function pb_save_product(PDO $pdo, array $data, ?int $userId): array
{
    $clean = pb_validate_product($data); $id = (int) ($data['id'] ?? 0);
    $requestKey = pb_text($data['request_key'] ?? '', 'Form identifier', 32);
    if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey)) throw new InvalidArgumentException('Refresh the product form.');
    $pdo->beginTransaction();
    try {
        if ($id === 0) {
            $existing = $pdo->prepare('SELECT id,sku FROM product_builder_products WHERE request_key=?'); $existing->execute([$requestKey]);
            if ($row = $existing->fetch(PDO::FETCH_ASSOC)) { $pdo->commit(); return $row; }
        }
        // Preserve packaging values when an older client omits the new fields.
        if ($id > 0 && (!array_key_exists('box_id', $data) || !array_key_exists('weight_kg', $data))) {
            $previousBox = $pdo->prepare('SELECT box_id,weight_kg FROM product_builder_products WHERE id=? AND version=?');
            $previousBox->execute([$id,(int)($data['version'] ?? 0)]);
            $previousPackaging = $previousBox->fetch(PDO::FETCH_ASSOC);
            if (!array_key_exists('box_id', $data)) $clean['box_id'] = $previousPackaging['box_id'] ?? null;
            if (!array_key_exists('weight_kg', $data)) $clean['weight_kg'] = $previousPackaging['weight_kg'] ?? null;
        }
        if ($clean['box_id'] !== null) {
            $findBox = $pdo->prepare('SELECT id FROM product_builder_boxes WHERE id=? FOR UPDATE'); $findBox->execute([$clean['box_id']]);
            if (!$findBox->fetchColumn()) throw new InvalidArgumentException('The selected box no longer exists. Refresh the box list and select another box.');
        }
        // Lock selected designs before writing so deletion cannot race with a listing save.
        $designIds = array_values(array_unique(array_filter(array_column($clean['components'], 'design_id'))));
        sort($designIds, SORT_NUMERIC);
        $designs = [];
        $findDesign = $pdo->prepare('SELECT id,component_type FROM product_builder_designs WHERE id=? FOR UPDATE');
        foreach ($designIds as $designId) {
            $findDesign->execute([$designId]); $design = $findDesign->fetch(PDO::FETCH_ASSOC);
            if (!$design) throw new InvalidArgumentException('A selected design no longer exists. Refresh the design library.');
            $designs[$designId] = $design;
        }
        foreach ($clean['components'] as $component) {
            if ($component['design_id'] !== null && $designs[$component['design_id']]['component_type'] !== $component['component_type']) {
                throw new InvalidArgumentException('The selected design belongs to a different component type.');
            }
        }
        $graphicsVersionIds = array_values(array_unique(array_filter(array_column($clean['components'], 'graphics_version_id'))));
        sort($graphicsVersionIds, SORT_NUMERIC);
        $findVersion = $pdo->prepare('SELECT id FROM product_builder_graphics_versions WHERE id=? FOR UPDATE');
        foreach ($graphicsVersionIds as $graphicsVersionId) {
            $findVersion->execute([$graphicsVersionId]);
            if (!$findVersion->fetchColumn()) throw new InvalidArgumentException('A selected Graphics version no longer exists. Refresh the version list.');
        }
        $uniId = null;
        if ($clean['category_kind'] === 'universal') {
            $uniId = (int) ($data['uni_category_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT brand,category_name AS model,modelcode FROM product_builder_uni_categories WHERE id=? FOR UPDATE'); $stmt->execute([$uniId]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$category) throw new InvalidArgumentException('Select an existing UNI category.');
            $category['year_range'] = '';
        } else {
            $stmt = $pdo->prepare('SELECT brand,model,rangeyear AS year_range,modelcode FROM scrubdata WHERE brand=? AND model=? AND rangeyear=? AND modelcode=? LIMIT 1');
            $stmt->execute([pb_text($data['brand'] ?? '', 'Brand', 100),pb_text($data['model'] ?? '', 'Model', 150),
                pb_text($data['year_range'] ?? '', 'Years', 50),pb_text($data['modelcode'] ?? '', 'Modelcode', 7)]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$category) throw new InvalidArgumentException('Select an existing model, year range and modelcode using Category Info.');
        }
        $values = [$clean['name'],$clean['bundle_type'],$clean['category_kind'],$uniId,$category['brand'],$category['model'],$category['year_range'],$category['modelcode'],$clean['notes']];
        foreach (array_keys(pb_price_fields()) as $channel) { $values[] = $clean[$channel . '_price']; $values[] = $clean[$channel . '_currency']; }
        $values[] = $clean['box_id']; $values[] = $clean['weight_kg'];
        if ($id > 0) {
            $current = $pdo->prepare('SELECT sku FROM product_builder_products WHERE id=? AND version=? FOR UPDATE');
            $current->execute([$id,(int) ($data['version'] ?? 0)]);
            $existingSku = $current->fetchColumn();
            if ($existingSku === false) throw new InvalidArgumentException('Another user has changed this product. Open it again.');
            $sku = array_key_exists('sku', $data) ? $clean['sku'] : $existingSku;
            $stmt = $pdo->prepare('UPDATE product_builder_products SET name=?,bundle_type=?,category_kind=?,uni_category_id=?,brand=?,model=?,year_range=?,modelcode=?,notes=?,recommended_price=?,recommended_currency=?,web_price=?,web_currency=?,ebay_price=?,ebay_currency=?,amazon_price=?,amazon_currency=?,box_id=?,weight_kg=?,sku=?,version=version+1 WHERE id=? AND version=?');
            $stmt->execute(array_merge($values, [$sku,$id,(int) ($data['version'] ?? 0)]));
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Another user has changed this product. Open it again.');
            $pdo->prepare('DELETE FROM product_builder_components WHERE product_id=?')->execute([$id]);
        } else {
            // Generate only for new listings without an existing shop SKU.
            $sku = $clean['sku'] !== '' ? $clean['sku'] : $clean['bundle_type'] . '_' . strtoupper(bin2hex(random_bytes(10)));
            $stmt = $pdo->prepare('INSERT INTO product_builder_products (name,bundle_type,category_kind,uni_category_id,brand,model,year_range,modelcode,notes,recommended_price,recommended_currency,web_price,web_currency,ebay_price,ebay_currency,amazon_price,amazon_currency,box_id,weight_kg,sku,request_key,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute(array_merge($values, [$sku,$requestKey,$userId])); $id = (int) $pdo->lastInsertId();
        }
        $stmt = $pdo->prepare('INSERT INTO product_builder_components (product_id,component_type,label,reference,color,quantity,notes,sort_order,options_json,design_id,graphics_version_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($clean['components'] as $i => $row) $stmt->execute([$id,$row['component_type'],$row['label'],$row['reference'],$row['color'],$row['quantity'],$row['notes'],$i,json_encode((object)$row['options'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$row['design_id'],$row['graphics_version_id']]);
        $pdo->commit(); return ['id'=>$id,'sku'=>$sku];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062 && strpos($e->getMessage(), 'uq_pb_sku') !== false) {
            throw new InvalidArgumentException('This SKU is already used by another product. Choose a unique SKU.');
        }
        throw $e;
    }
}

/** Shared read path for the API and the database regression test. */
function pb_product_overview(PDO $pdo, string $query = ''): array
{
    $like = '%' . pb_text($query, 'Search', 150, false) . '%';
    $stmt = $pdo->prepare('SELECT p.*,u.brand AS uni_brand,u.category_name AS uni_name,b.name AS box_name,b.length_cm AS box_length_cm,b.width_cm AS box_width_cm,b.height_cm AS box_height_cm,b.weight_kg AS box_weight_kg,
        (SELECT COUNT(*) FROM product_builder_components c WHERE c.product_id=p.id) AS component_count
        FROM product_builder_products p
        LEFT JOIN product_builder_uni_categories u ON u.id=p.uni_category_id
        LEFT JOIN product_builder_boxes b ON b.id=p.box_id
        WHERE p.name LIKE ? OR p.sku LIKE ? OR p.modelcode LIKE ?
        ORDER BY p.created_at DESC,p.id DESC LIMIT 100');
    $stmt->execute([$like, $like, $like]);
    // Keep the latest 100 matches, then display them chronologically like Orders.
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    // SPECIFIC is reserved in MariaDB. Use unambiguous SQL aliases, then map the API keys.
    $counts = $pdo->query("SELECT COUNT(*) AS total_count,
        COALESCE(SUM(category_kind='specific'),0) AS specific_count,
        COALESCE(SUM(category_kind='universal'),0) AS universal_count
        FROM product_builder_products")->fetch(PDO::FETCH_ASSOC);
    return ['rows' => $rows, 'stats' => [
        'total' => (int) $counts['total_count'],
        'specific' => (int) $counts['specific_count'],
        'universal' => (int) $counts['universal_count'],
    ]];
}

/** Saved component data with current inventory information for plastics (never stored on the listing). */
function pb_product_components(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT c.*,d.name AS design_name,v.name AS graphics_version_name FROM product_builder_components c LEFT JOIN product_builder_designs d ON d.id=c.design_id LEFT JOIN product_builder_graphics_versions v ON v.id=c.graphics_version_id WHERE c.product_id=? ORDER BY c.sort_order,c.id');
    $stmt->execute([$productId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $inventory = [];
    if (in_array('P', array_column($components, 'component_type'), true)) {
        // Group by the saved component, not the barcode: duplicate inventory rows must not
        // multiply components or silently sum quantities from potentially different items.
        $stmt = $pdo->prepare("SELECT c.id,COUNT(i.id) AS match_count,
            CASE WHEN COUNT(i.id)=1 THEN MAX(i.main_supplier) ELSE NULL END AS main_supplier,
            CASE WHEN COUNT(i.id)=1 THEN MAX(i.quantity) ELSE NULL END AS stock_quantity
            FROM product_builder_components c LEFT JOIN items i ON i.barcode=c.reference AND c.reference<>''
            WHERE c.product_id=? AND c.component_type='P' GROUP BY c.id");
        $stmt->execute([$productId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) $inventory[$item['id']] = $item;
    }
    foreach ($components as &$component) {
        $component['options'] = (object)pb_component_options($component);
        unset($component['options_json']);
        if ($component['component_type'] === 'P') {
            $item = $inventory[$component['id']];
            $count = (int)$item['match_count'];
            $component['inventory_status'] = $component['reference'] === '' ? 'no_code' : ($count === 1 ? 'matched' : ($count > 1 ? 'ambiguous' : 'not_found'));
            $component['inventory_supplier'] = $item['main_supplier'];
            $component['inventory_quantity'] = $item['stock_quantity'];
        }
    }
    unset($component);
    return $components;
}

/** Exact inventory barcode lookup; never guess between different items sharing one code. */
function pb_find_plastic_item(PDO $pdo, string $barcode): ?array
{
    $barcode = pb_text($barcode, 'Product code', 100);
    $stmt = $pdo->prepare("SELECT DISTINCT barcode,COALESCE(description,'') AS label,COALESCE(color,'') AS color FROM items WHERE barcode=? LIMIT 2");
    $stmt->execute([$barcode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) throw new InvalidArgumentException('Multiple inventory items use this code. Enter the component name and color manually.');
    return $rows[0] ?? null;
}
