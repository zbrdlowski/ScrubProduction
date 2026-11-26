<?php
declare(strict_types=1);
require_once __DIR__ . '/transfers.php';
require_once __DIR__ . '/compatibility.php';

function pb_manual_transfers_ready(PDO $pdo): bool
{
    try { $pdo->query('SELECT source_kind,request_key FROM product_builder_transfers LIMIT 0'); return true; }
    catch (PDOException $e) { if (in_array((int)($e->errorInfo[1] ?? 0),[1146,1054],true)) return false; throw $e; }
}
function pb_require_manual_transfers(PDO $pdo): void
{
    pb_require_transfers($pdo);
    if (!pb_manual_transfers_ready($pdo)) throw new InvalidArgumentException('Import product_builder_update_016.sql to enable manual transfers.');
}
function pb_manual_transfer_options(PDO $pdo): array
{
    pb_require_manual_transfers($pdo);
    return [
        'models'=>pb_model_catalogue($pdo),
        'categories'=>$pdo->query('SELECT brand,category_name,modelcode FROM product_builder_uni_categories ORDER BY brand,category_name')->fetchAll(PDO::FETCH_ASSOC),
        'designs'=>$pdo->query("SELECT name FROM product_builder_designs WHERE component_type='G' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        'versions'=>$pdo->query('SELECT name FROM product_builder_graphics_versions ORDER BY sort_order,id')->fetchAll(PDO::FETCH_COLUMN),
    ];
}
function pb_manual_transfer_snapshot(PDO $pdo, array $data, ?array $previous): array
{
    $previous = $previous ? pb_transfer_listing_snapshot($previous) : [];
    $category = $data['category'] ?? [];
    if (!is_array($category)) throw new InvalidArgumentException('Invalid category selection.');
    $clean = [];
    foreach (['brand'=>100,'model'=>150,'year_range'=>50,'modelcode'=>7] as $key=>$length) $clean[$key]=pb_text($category[$key] ?? '', 'Category', $length, false);
    if (implode('', $clean) !== '') {
        $valid = $previous && ($previous['category'] ?? null) === $clean;
        if (!$valid) foreach (pb_model_catalogue($pdo) as $model) if (pb_compatibility_key($model) === pb_compatibility_key($clean)) { $valid=true; break; }
        if (!$valid) {
            $stmt=$pdo->prepare('SELECT 1 FROM product_builder_uni_categories WHERE brand=? AND category_name=? AND modelcode=?');
            $stmt->execute([$clean['brand'],$clean['model'],$clean['modelcode']]);
            $valid=$clean['year_range']==='' && (bool)$stmt->fetchColumn();
        }
        if (!$valid) throw new InvalidArgumentException('Select an existing category or leave it empty for Product Builder.');
    }
    $quantity=filter_var($data['source_quantity'] ?? $previous['source_quantity'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>9999]]);
    if ($quantity===false) throw new InvalidArgumentException('Quantity must be a whole number between 1 and 9999.');
    $url=pb_text($data['source_url'] ?? $previous['source_url'] ?? '', 'Source URL',2048,false);
    if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true))) throw new InvalidArgumentException('Enter a valid HTTP or HTTPS source URL.');
    $input=$data['options'] ?? $previous['options'] ?? [];
    if (!is_array($input)) throw new InvalidArgumentException('Invalid graphics options.');
    $options=[];
    foreach (pb_transfer_graphics_fields() as $key=>$field) {
        $value=pb_text($input[$key] ?? '', $field['label'],255,false);
        if ($key==='graphics_number' && !in_array($value,['','Yes','No'],true)) throw new InvalidArgumentException('Choose Yes or No for Number.');
        if ($value!=='') $options[$key]=$value;
    }
    return pb_transfer_listing_snapshot([
        'category'=>$clean,'category_text'=>implode(' · ',array_filter($clean)),
        'component_notes'=>pb_text($data['component_notes'] ?? $previous['component_notes'] ?? '', 'Scrub Note',10000,false),
        'design_name'=>pb_text($data['design_name'] ?? '', 'Graphics design',150,false),
        'version_name'=>pb_text($data['version_name'] ?? '', 'Version',100,false),
        'options'=>$options,'source_url'=>$url,'source_quantity'=>$quantity,
    ]);
}
/** Manual records use the same snapshot and Ready -> Builder workflow, without fabricated order IDs. */
function pb_save_manual_transfer(PDO $pdo, array $data, ?int $userId): array
{
    pb_require_manual_transfers($pdo);
    $id=filter_var($data['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if ($id===false) throw new InvalidArgumentException('Invalid transfer.');
    $requestKey=pb_text($data['request_key'] ?? '', 'Request key',32,false);
    if (!$id && !preg_match('/^[a-f0-9]{32}$/D',$requestKey)) throw new InvalidArgumentException('Invalid request key. Refresh the page and try again.');
    $pdo->beginTransaction();
    try {
        $previous=null;$current=null;
        if ($id) {
            $stmt=$pdo->prepare('SELECT source_kind,status,product_id,version,snapshot_json,order_number FROM product_builder_transfers WHERE id=? FOR UPDATE');$stmt->execute([$id]);$current=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current || $current['source_kind']!=='manual') throw new InvalidArgumentException('Only manual transfers can be edited.');
            if ($current['product_id'] || $current['status']==='completed') throw new InvalidArgumentException('This transfer already has a listing. Open it from Listings.');
            if ((int)$current['version']!==(int)($data['version'] ?? 0)) throw new InvalidArgumentException('Another user has changed this transfer. Reload it and try again.');
            $previous=json_decode($current['snapshot_json'],true);
        } else {
            $stmt=$pdo->prepare('SELECT id FROM product_builder_transfers WHERE request_key=?');$stmt->execute([$requestKey]);
            if ($existing=$stmt->fetchColumn()) { $pdo->commit();return pb_transfer_get($pdo,(int)$existing); }
        }
        $title=pb_text($data['title'] ?? '', 'Product name',255);
        $reference=pb_text($data['order_number'] ?? $current['order_number'] ?? '', 'Source reference',150,false);
        $sku=pb_text($data['source_sku'] ?? '', 'Source SKU',255,false);
        $snapshot=pb_manual_transfer_snapshot($pdo,$data,is_array($previous)?$previous:null);
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if ($id) {
            $stmt=$pdo->prepare('UPDATE product_builder_transfers SET title=?,order_number=?,source_sku=?,snapshot_json=?,updated_by=?,version=version+1 WHERE id=?');
            $stmt->execute([$title,$reference,$sku,$json,$userId,$id]);
        } else {
            $stmt=$pdo->prepare("INSERT INTO product_builder_transfers(source_kind,request_key,order_item_id,order_id,order_number,title,source_sku,snapshot_json,work_note,created_by,updated_by) VALUES ('manual',?,NULL,NULL,?,?,?,?, '',?,?)");
            $stmt->execute([$requestKey,$reference,$title,$sku,$json,$userId,$userId]);$id=(int)$pdo->lastInsertId();
        }
        $pdo->commit();return pb_transfer_get($pdo,$id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Concurrent retries of one create return the original record, never a second transfer.
        if (!$id && $e instanceof PDOException && (int)($e->errorInfo[1] ?? 0)===1062) {
            $stmt=$pdo->prepare('SELECT id FROM product_builder_transfers WHERE request_key=?');$stmt->execute([$requestKey]);
            if ($existing=$stmt->fetchColumn()) return pb_transfer_get($pdo,(int)$existing);
        }
        throw $e;
    }
}
