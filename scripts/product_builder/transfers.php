<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

function pb_transfers_ready(PDO $pdo): bool
{
    // Probe the table itself, also supporting isolated temporary tables in tests.
    try { $pdo->query('SELECT id,order_item_id,snapshot_json,status,img_status,product_id,version FROM product_builder_transfers LIMIT 0'); return true; }
    catch (PDOException $e) { if (in_array((int)($e->errorInfo[1] ?? 0), [1146,1054], true)) return false; throw $e; }
}
function pb_require_transfers(PDO $pdo): void
{
    if (!pb_transfers_ready($pdo)) throw new InvalidArgumentException('Import product_builder_update_013.sql to enable Transfer D-base.');
}
function pb_transfer_value(array $values, array $keys): string
{
    $normal = [];
    foreach ($values as $key=>$value) if (is_scalar($value)) $normal[strtolower(str_replace(['_',' '], '-', (string)$key))] = trim((string)$value);
    foreach ($keys as $key) { $value = $normal[strtolower(str_replace(['_',' '], '-', $key))] ?? ''; if ($value !== '') return mb_substr($value,0,10000); }
    return '';
}
/** These are the listing-level Graphics fields, not fields of other G subcategories. */
function pb_transfer_graphics_fields(): array
{
    return [
        'graphics_material'=>['label'=>'Material','internal'=>'_print_material','sources'=>['base-material','material','graphics-material']],
        'graphics_finish'=>['label'=>'Finish','internal'=>'_print_finish','sources'=>['graphics-finish','finish']],
        'graphics_number'=>['label'=>'Number','internal'=>'_graphics_number','sources'=>['number']],
        'graphics_number_color'=>['label'=>'Number Color','internal'=>'_graphics_number_color','sources'=>['number-color']],
        'graphics_name_color'=>['label'=>'Number Plates Color','internal'=>'_graphics_name_color','sources'=>['name-color','number-plates-color']],
        'graphics_tr_swingarms'=>['label'=>'Tr. Swingarms','internal'=>'_print_tr_swingarms','sources'=>['tr-swingarms']],
        'graphics_grip'=>['label'=>'Grip','internal'=>'_print_grip','sources'=>['grip']],
    ];
}
function pb_transfer_number_choice($value): string
{
    if (!is_scalar($value)) return '';
    $value = trim((string)$value);
    if ($value === '') return '';
    // A literal rider number, including 0, means that the product includes a number.
    return in_array(mb_strtolower($value),['no','none','nie','nein','without','false','-'],true) ? 'No' : 'Yes';
}
/** Project both old and new snapshots onto the same small set of catalogue fields. */
function pb_transfer_listing_snapshot(array $snapshot): array
{
    $original = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [];
    $options = []; $fields = [];
    foreach (pb_transfer_graphics_fields() as $key=>$definition) {
        $value = pb_transfer_value($original,[$key]);
        if ($key === 'graphics_number') {
            $overrides = is_array($snapshot['listing_options'] ?? null) ? $snapshot['listing_options'] : [];
            $value = pb_transfer_number_choice($overrides[$key] ?? $value);
        }
        if ($value !== '') $options[$key] = $value;
        $fields[] = ['key'=>$key,'label'=>$definition['label'],'value'=>$value];
    }
    $snapshot['options'] = $options;
    $snapshot['fields'] = $fields;
    return $snapshot;
}

/** Only product fields are copied. No customer/address/payment blobs are included. */
function pb_transfer_snapshot(array $item, array $definitions): array
{
    $ext = json_decode((string)($item['options_json'] ?? '{}'),true); $ext = is_array($ext) ? $ext : [];
    $internal = json_decode((string)($item['internal_options_json'] ?? '{}'),true); $internal = is_array($internal) ? $internal : [];
    $options = []; $fields = [];
    $baseFields = pb_transfer_graphics_fields();
    // An exact key whitelist excludes Moto Carpet, Seat Patch, Rim Tapes, etc.,
    // even when their source_key is the same as a base Graphics field.
    foreach ($definitions as $definition) {
        $key = (string)$definition['spec_key'];
        if (!isset($baseFields[$key])) continue;
        $meta = $baseFields[$key];
        $value = pb_transfer_value($internal,[$meta['internal'],'_'.$key]);
        if ($value === '') $value = pb_transfer_value($ext,array_merge($meta['sources'],[(string)$definition['source_key'],$key]));
        if ($value !== '') { $options[$key] = $value; $fields[] = ['key'=>$key,'label'=>$meta['label'],'value'=>$value]; }
    }
    $category = [
        'brand'=>pb_transfer_value($ext,['category_brand','brand','bike-brand','manufacturer']),
        'model'=>pb_transfer_value($ext,['category_model','model','bike-model','bike']),
        'year_range'=>pb_transfer_value($ext,['category_year_range','year','bike-year','model-year','year range']),
        'modelcode'=>pb_transfer_value($ext,['category_modelcode','modelcode','design_code','category_code','model_code','sku-code']),
    ];
    $categoryInfo = pb_transfer_value($ext,['category_info','Category Info','category','bike-category','model-category']);
    $parts = array_map('trim',explode('|',$categoryInfo));
    foreach (array_keys($category) as $i=>$key) if ($category[$key] === '') $category[$key] = $parts[$i] ?? '';
    return ['category'=>$category,'category_text'=>$categoryInfo ?: implode(' · ',array_filter($category)),
        'component_notes'=>pb_transfer_value($internal,['_graphics_my_item_note']) ?: pb_transfer_value($ext,['my-item-note','scrub-note']),
        'options'=>$options,'fields'=>$fields,'source_url'=>pb_transfer_value($item,['product_url']),
        'design_name'=>pb_transfer_value($ext,['graphics_design','design_name','design']),
        'version_name'=>pb_transfer_value($ext,['graphics_version','design_version','version']),
        'source_quantity'=>(int)($item['qty'] ?? 1)];
}
/** One source item creates exactly one immutable snapshot, even with concurrent clicks. */
function pb_create_transfer(PDO $pdo, int $itemId, int $orderId, array $definitions, ?int $userId): array
{
    pb_require_transfers($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT i.id,i.order_id,i.title,i.sku,i.custom_label,i.item_type_code,i.qty,i.product_url,i.options_json,i.internal_options_json,o.order_number,o.external_order_id FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.id=? AND i.order_id=? AND i.deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$itemId,$orderId]); $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item || strtoupper(trim((string)$item['item_type_code'])) !== 'G') throw new InvalidArgumentException('Only an existing Graphics item can be transferred.');
        $find = $pdo->prepare('SELECT id,status,product_id FROM product_builder_transfers WHERE order_item_id=?'); $find->execute([$itemId]);
        if ($existing = $find->fetch(PDO::FETCH_ASSOC)) { $pdo->commit(); return $existing; }
        $snapshot = pb_transfer_snapshot($item,$definitions);
        $stmt = $pdo->prepare('INSERT INTO product_builder_transfers(order_item_id,order_id,order_number,title,source_sku,snapshot_json,work_note,created_by,updated_by) VALUES (?,?,?,?,?,?,\'\',?,?)');
        $stmt->execute([$itemId,$orderId,mb_substr(trim((string)($item['order_number'] ?: $item['external_order_id'] ?: $orderId)),0,150),mb_substr((string)$item['title'],0,255),mb_substr(trim((string)($item['custom_label'] ?: $item['sku'])),0,255),json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$userId,$userId]);
        $id = (int)$pdo->lastInsertId(); $pdo->commit();
        return ['id'=>$id,'status'=>'pending','product_id'=>null];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function pb_transfer_get(PDO $pdo, int $id): array
{
    pb_require_transfers($pdo);
    $stmt = $pdo->prepare('SELECT t.*,p.name AS product_name,p.sku AS product_sku FROM product_builder_transfers t LEFT JOIN product_builder_products p ON p.id=t.product_id WHERE t.id=?');
    $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Transfer not found.');
    $snapshot = json_decode($row['snapshot_json'],true);
    $row['snapshot'] = pb_transfer_listing_snapshot(is_array($snapshot) ? $snapshot : []); unset($row['snapshot_json']);
    return $row;
}
function pb_transfer_list(PDO $pdo, array $data): array
{
    pb_require_transfers($pdo);
    $status = $data['status'] ?? 'all';
    if (!in_array($status,['all','pending','completed','dismissed'],true)) throw new InvalidArgumentException('Invalid transfer status.');
    $page = max(1,(int)($data['page'] ?? 1));
    $perPage = (int)($data['per_page'] ?? 100);
    if (!in_array($perPage,[25,50,100],true)) $perPage=100;
    $q = pb_text($data['q'] ?? '', 'Search',150,false);
    $like = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
    $where = " WHERE (t.title LIKE ? ESCAPE '!' OR t.order_number LIKE ? ESCAPE '!' OR t.source_sku LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' OR p.name LIKE ? ESCAPE '!' OR CONCAT_WS(' · ',JSON_UNQUOTE(JSON_EXTRACT(t.snapshot_json,'$.category.brand')),JSON_UNQUOTE(JSON_EXTRACT(t.snapshot_json,'$.category.model')),JSON_UNQUOTE(JSON_EXTRACT(t.snapshot_json,'$.category.year_range')),JSON_UNQUOTE(JSON_EXTRACT(t.snapshot_json,'$.category.modelcode'))) LIKE ? ESCAPE '!')";
    $args = [$like,$like,$like,$like,$like,$like];
    if ($status !== 'all') { $where.=' AND t.status=?'; $args[]=$status; }
    // Completed/linked records form a separate bucket, regardless of their old IMG value.
    if (array_key_exists('img_filters',$data)) {
        $raw=$data['img_filters'];
        if (!is_string($raw) || strlen($raw)>100) throw new InvalidArgumentException('Invalid transfer status filter.');
        $filters=$raw===''?[]:array_values(array_unique(array_map('trim',explode(',',$raw))));
        if (array_diff($filters,['unset','skip','gen','ready','completed'])) throw new InvalidArgumentException('Invalid transfer status filter.');
        // Search also finds finished work hidden from the active preparation queue.
        if ($q !== '' && !in_array('completed',$filters,true)) $filters[]='completed';
        if (!$filters) $where.=' AND 1=0';
        else {
            $where.=" AND (CASE WHEN t.product_id IS NOT NULL OR t.status='completed' THEN 'completed' WHEN t.img_status IS NULL THEN 'unset' ELSE t.img_status END) IN (".implode(',',array_fill(0,count($filters),'?')).')';
            array_push($args,...$filters);
        }
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM product_builder_transfers t LEFT JOIN product_builder_products p ON p.id=t.product_id'.$where); $count->execute($args); $total = (int)$count->fetchColumn();
    $pages = max(1,(int)ceil($total/$perPage)); $page = min($page,$pages);
    $stmt = $pdo->prepare('SELECT t.*,p.name AS product_name,p.sku AS product_sku FROM product_builder_transfers t LEFT JOIN product_builder_products p ON p.id=t.product_id'.$where.' ORDER BY t.created_at DESC,t.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
    $stmt->execute($args); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $snapshot=json_decode($row['snapshot_json'],true);
        $row['snapshot']=pb_transfer_listing_snapshot(is_array($snapshot)?$snapshot:[]);
        unset($row['snapshot_json']);
    }
    unset($row);
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>$pages,
        'from'=>$total?($page-1)*$perPage+1:0,'to'=>min($page*$perPage,$total),
        'pending'=>(int)$pdo->query("SELECT COUNT(*) FROM product_builder_transfers WHERE status='pending'")->fetchColumn()];
}
/** Preparation status is editable until the transfer produces a listing. */
function pb_transfer_save_image(PDO $pdo, array $data, ?int $userId): array
{
    pb_require_transfers($pdo);
    $state=$data['img_status'] ?? '';
    if (!in_array($state,['','skip','gen','ready'],true)) throw new InvalidArgumentException('Invalid transfer image status.');
    $id=(int)($data['id'] ?? 0); $version=(int)($data['version'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT version,status,product_id FROM product_builder_transfers WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['version']!==$version) throw new InvalidArgumentException('Another user has changed this transfer. Reload it and try again.');
        if ($row['product_id'] || $row['status']==='completed') throw new InvalidArgumentException('This transfer already has a listing. Open it from Listings.');
        $stmt=$pdo->prepare('UPDATE product_builder_transfers SET img_status=?,status=?,completed_at=NULL,updated_by=?,version=version+1 WHERE id=?');
        $stmt->execute([$state?:null,$state==='skip'?'dismissed':'pending',$userId,$id]);
        $pdo->commit(); return pb_transfer_get($pdo,$id);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Store a local folder reference; this does not access or upload its contents. */
function pb_transfer_save_folder(PDO $pdo, array $data, ?int $userId): array
{
    pb_require_transfers($pdo);
    $path=pb_text($data['folder_path'] ?? '', 'Folder path',2048,false);
    if (preg_match('/[\x00-\x1F\x7F]/',$path)) throw new InvalidArgumentException('Enter the folder path on a single line.');
    $id=(int)($data['id'] ?? 0); $version=(int)($data['version'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT version,status,img_status,product_id FROM product_builder_transfers WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['version']!==$version) throw new InvalidArgumentException('Another user has changed this transfer. Reload it and try again.');
        if ($row['product_id'] || $row['status']==='completed') throw new InvalidArgumentException('This transfer already has a listing. Open it from Listings.');
        if ($row['status']!=='pending' || $row['img_status']!=='ready') throw new InvalidArgumentException('Set IMG to Ready before adding an image folder.');
        $pdo->prepare('UPDATE product_builder_transfers SET folder_path=?,updated_by=?,version=version+1 WHERE id=?')->execute([$path,$userId,$id]);
        $pdo->commit(); return pb_transfer_get($pdo,$id);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function pb_transfer_save(PDO $pdo, array $data, ?int $userId): array
{
    pb_require_transfers($pdo);
    $id = (int)($data['id'] ?? 0); $version = (int)($data['version'] ?? 0);
    $status = $data['status'] ?? ''; $purpose = $data['purpose'] ?? '';
    if (!in_array($status,['pending','completed','dismissed'],true) || !in_array($purpose,['new_listing','update_images'],true)) throw new InvalidArgumentException('Invalid transfer status.');
    $productId = filter_var($data['product_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
    if ($status === 'completed' && !$productId) throw new InvalidArgumentException('Choose the processed listing before marking this transfer complete.');
    $path = pb_text($data['folder_path'] ?? '', 'Folder path',2048,false);
    if (preg_match('/[\x00-\x1F\x7F]/',$path)) throw new InvalidArgumentException('Enter the folder path on a single line.');
    $note = pb_text($data['work_note'] ?? '', 'Notes',10000,false);
    if (array_key_exists('listing_number',$data) && !in_array($data['listing_number'],['','Yes','No'],true)) throw new InvalidArgumentException('Select Yes or No for Number.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT version,status,completed_at,snapshot_json,product_id FROM product_builder_transfers WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current || (int)$current['version'] !== $version) throw new InvalidArgumentException('Another user has changed this transfer. Reload it and try again.');
        if ($current['product_id'] && (int)$current['product_id'] !== $productId) throw new InvalidArgumentException('This transfer already has a listing. Open it from Listings.');
        if ($productId) {
            $stmt = $pdo->prepare('SELECT p.id FROM product_builder_products p WHERE p.id=? AND p.deleted_at IS NULL AND EXISTS(SELECT 1 FROM product_builder_components c WHERE c.product_id=p.id AND c.component_type=\'G\') FOR UPDATE'); $stmt->execute([$productId]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Choose an existing listing containing Graphics.');
        }
        // Store a catalogue override alongside the original snapshot; source data stays intact.
        $snapshotJson = $current['snapshot_json'];
        if (array_key_exists('listing_number',$data)) {
            $snapshot = json_decode($snapshotJson,true); $snapshot = is_array($snapshot) ? $snapshot : [];
            if (!is_array($snapshot['listing_options'] ?? null)) $snapshot['listing_options'] = [];
            $snapshot['listing_options']['graphics_number'] = $data['listing_number'];
            $snapshotJson = json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
        $completed = $status === 'completed' ? ($current['completed_at'] ?: date('Y-m-d H:i:s')) : null;
        $pdo->prepare('UPDATE product_builder_transfers SET status=?,purpose=?,product_id=?,folder_path=?,work_note=?,snapshot_json=?,completed_at=?,updated_by=?,version=version+1 WHERE id=?')->execute([$status,$purpose,$productId,$path,$note,$snapshotJson,$completed,$userId,$id]);
        $pdo->commit(); return pb_transfer_get($pdo,$id);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Called inside the product-save transaction, before any product is written. */
function pb_transfer_lock_for_product(PDO $pdo, int $id): array
{
    pb_require_transfers($pdo);
    $stmt = $pdo->prepare('SELECT id,status,img_status,product_id,folder_path,version FROM product_builder_transfers WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Transfer not found.');
    if (!$row['product_id'] && ($row['status'] !== 'pending' || $row['img_status'] !== 'ready')) throw new InvalidArgumentException('Set IMG to Ready in Transfer D-base before preparing a listing.');
    return $row;
}
function pb_transfer_draft(PDO $pdo, int $id): array
{
    $row = pb_transfer_get($pdo,$id);
    if ($row['status'] !== 'pending' || $row['product_id']) throw new InvalidArgumentException('This transfer already has a listing or is no longer pending.');
    if ($row['img_status'] !== 'ready') throw new InvalidArgumentException('Set IMG to Ready in Transfer D-base before preparing a listing.');
    $snapshot = $row['snapshot']; $category = $snapshot['category']; $options = $snapshot['options'];
    // The snapshot projection already excludes personalization, buyer notes and subcategory fields.
    $draft = ['folder_path'=>$row['folder_path'],'transfer_version'=>(int)$row['version'],'name'=>$row['title'],'sku'=>'','bundle_type'=>'G','category_kind'=>'specific','notes'=>'','components'=>[['component_type'=>'G','label'=>$row['title'],'reference'=>'','quantity'=>1,'notes'=>$snapshot['component_notes'] ?? '','options'=>$options,'design_id'=>null,'graphics_version_id'=>null]]];
    $find = $pdo->prepare('SELECT DISTINCT brand,model,rangeyear AS year_range,modelcode FROM scrubdata WHERE modelcode=? AND modelcode<>\'\' LIMIT 2'); $find->execute([$category['modelcode']]); $matches = $find->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches) !== 1 && $category['brand'] !== '' && $category['model'] !== '' && $category['year_range'] !== '') {
        $find = $pdo->prepare('SELECT DISTINCT brand,model,rangeyear AS year_range,modelcode FROM scrubdata WHERE brand=? AND model=? AND rangeyear=? AND modelcode<>\'\' LIMIT 2'); $find->execute([$category['brand'],$category['model'],$category['year_range']]); $matches = $find->fetchAll(PDO::FETCH_ASSOC);
    }
    if (count($matches) === 1) $draft += $matches[0];
    else {
        $find = $pdo->prepare('SELECT id,modelcode FROM product_builder_uni_categories WHERE modelcode=? AND modelcode<>\'\''); $find->execute([$category['modelcode']]);
        if ($uni = $find->fetch(PDO::FETCH_ASSOC)) { $draft['category_kind']='universal'; $draft['uni_category_id']=$uni['id']; }
    }
    foreach (['design_name'=>['product_builder_designs','design_id'],'version_name'=>['product_builder_graphics_versions','graphics_version_id']] as $key=>[$table,$field]) {
        if (empty($snapshot[$key])) continue;
        $find = $pdo->prepare('SELECT id FROM '.$table.' WHERE name=?'.($field === 'design_id' ? " AND component_type='G'" : '').' LIMIT 2'); $find->execute([$snapshot[$key]]); $ids = $find->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1) $draft['components'][0][$field] = (int)$ids[0];
    }
    return $draft;
}
