<?php
declare(strict_types=1);
require_once __DIR__ . '/../scripts/product_builder/csv.php';
require_once __DIR__ . '/../scripts/product_builder/service.php';
function pb_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function pb_reject(callable $call, string $message): void {
    try { $call(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$rows=pb_parse_csv(__DIR__ . '/../scripts/product_builder/UniCategories.csv');
pb_assert(count($rows)===53,'All 53 supplied categories must load.');
pb_assert($rows[0]['brand']==='AJP' && $rows[0]['included_models']==='Big bike','Unnamed coverage column must be mapped.');
pb_assert($rows[0]['category_name']==='Default' && $rows[0]['price_unlicensed']==='149.90','Category name and price mapping.');
pb_assert(count(pb_bundle_types())===31,'Every combination of the five components must be available.');
foreach(['G','F','P','S','GP','GFP','GPS','GFPS','PF','PS','TFP','TFPS'] as $type) pb_assert(in_array($type,pb_bundle_types(),true),'Required bundle: '.$type);
for($i=0;$i<100;$i++)pb_assert((bool)preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/D',scrub_modelcode_candidate()),'Shared modelcode alphabet.');
pb_reject(static function(){pb_price('-1');},'Negative price must fail.');
pb_reject(static function(){pb_price('1.234');},'Sub-cent price must fail.');
pb_assert(pb_price('12,50')==='12.50' && pb_price('')===null,'Decimal comma and optional price.');
$fixture=tempnam(sys_get_temp_dir(),'pb-csv-');
try {
    file_put_contents($fixture,"\xEF\xBB\xBFZnačka;Názov kategórie;Zahrnuté modely;No license;Licensed\nTest;UNI;\"Model A\nModel B\";12,50;0\n");
    $parsed=pb_parse_csv($fixture);pb_assert($parsed[0]['included_models']==="Model A\nModel B" && $parsed[0]['price_unlicensed']==='12.50','BOM, semicolon, quoted multiline, decimal comma.');
    file_put_contents($fixture,"Značka,Názov kategórie,Zahrnuté modely,No license,Licensed\nTest,UNI,A,1,2\nTest,UNI,B,3,4\n");
    pb_reject(static function()use($fixture){pb_parse_csv($fixture);},'Duplicate CSV categories must fail before writes.');
    file_put_contents($fixture,"wrong,headers\n1,2\n");pb_reject(static function()use($fixture){pb_parse_csv($fixture);},'Unknown CSV columns must fail.');
} finally {unlink($fixture);}
$base=['recommended_price'=>'149.90','recommended_currency'=>'EUR','request_key'=>bin2hex(random_bytes(16)),'name'=>'Test bundle','bundle_type'=>'GP','category_kind'=>'universal','components'=>[
    ['component_type'=>'G','label'=>'Design','quantity'=>1],['component_type'=>'P','label'=>'Plastics','quantity'=>2]]];
pb_assert(count(pb_validate_product($base)['components'])===2,'Bundle components accepted.');
$bad=$base;$bad['components']=[$bad['components'][0]];pb_reject(static function()use($bad){pb_validate_product($bad);},'Missing required component rejected.');
$bad=$base;$bad['components'][0]['component_type']='S';pb_reject(static function()use($bad){pb_validate_product($bad);},'Foreign component rejected.');
$bad=$base;$bad['components'][0]['quantity']='1.5';pb_reject(static function()use($bad){pb_validate_product($bad);},'Fractional quantity rejected.');
$bad=$base;$bad['sku']=str_repeat('X',81);pb_reject(static function()use($bad){pb_validate_product($bad);},'Overlong SKU rejected.');
$bad=$base;$bad['sku']="ABC\nDEF";pb_reject(static function()use($bad){pb_validate_product($bad);},'Control characters in SKU rejected.');
$bad=$base;$bad['id']=1;$bad['sku']='';pb_reject(static function()use($bad){pb_validate_product($bad);},'Existing SKU cannot be cleared.');
$bad=$base;$bad['components'][1]['color']=str_repeat('X',101);pb_reject(static function()use($bad){pb_validate_product($bad);},'Overlong color rejected.');
$bad=$base;$bad['components'][0]['options']=['bad key'=>'value'];pb_reject(static function()use($bad){pb_validate_product($bad);},'Setting keys must be valid.');
$bad=$base;$bad['components'][0]['options']=['graphics_material'=>['nested']];pb_reject(static function()use($bad){pb_validate_product($bad);},'Nested setting values rejected.');
$bad=$base;$bad['components'][0]['options']=array_fill_keys(array_map(static fn($i)=>'field_'.$i,range(1,65)),'x');pb_reject(static function()use($bad){pb_validate_product($bad);},'Limit the number of component settings.');
echo "Product builder validation and CSV: OK\n";
if(!in_array('--db',$argv,true))exit;
require_once __DIR__ . '/../includes/conn.php';
// Session-local temporary tables shadow live tables. No persisted test rows or deletes.
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);
    $ddl=preg_replace('/,\s*\)/', "\n)", $ddl);
    $pdo->exec($ddl);
}
$empty=pb_product_overview($pdo);
pb_assert($empty['rows']===[] && $empty['stats']===['total'=>0,'specific'=>0,'universal'=>0], 'First-load query must return zero counts without SQL errors.');
$r=pb_import_uni($pdo,$conn,$rows);pb_assert($r['added']===53,'Initial seed.');
$r=pb_import_uni($pdo,$conn,$rows);pb_assert($r['added']===0 && $r['skipped']===53,'Repeat import must be idempotent.');
$u=$pdo->query('SELECT * FROM product_builder_uni_categories ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$code=$u['modelcode'];$u['included_models']="Edited A\nEdited B";pb_save_uni($pdo,$conn,$u);
pb_import_uni($pdo,$conn,$rows);
$u=$pdo->query('SELECT * FROM product_builder_uni_categories ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
pb_assert($u['modelcode']===$code && $u['included_models']==="Edited A\nEdited B",'Import must preserve category edits and modelcode.');
$base['uni_category_id']=$u['id'];$created=pb_save_product($pdo,$base,null);
pb_assert((bool)preg_match('/^GP_[A-F0-9]{20}$/D',$created['sku']),'Generated SKU starts with the selected bundle type.');
$again=pb_save_product($pdo,$base,null);pb_assert((int)$again['id']===(int)$created['id'],'Request retry must not duplicate product.');
$edited=$base;$edited['id']=$created['id'];$edited['version']=1;$edited['name']='Changed';
$saved=pb_save_product($pdo,$edited,null);pb_assert($saved['sku']===$created['sku'],'SKU must remain stable after edit.');
pb_reject(static function()use($pdo,$edited){pb_save_product($pdo,$edited,null);},'Stale edits rejected.');
pb_assert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_components')->fetchColumn()===2,'Stale edit cannot remove components.');
pb_reject(static function()use($pdo,$u){pb_delete_uni($pdo,(int)$u['id'],(int)$u['version']);},'Used category deletion blocked.');
$model=$pdo->query("SELECT brand,model,rangeyear AS year_range,modelcode FROM scrubdata WHERE modelcode<>'' AND rangeyear<>'' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$specific=array_merge($base,$model,['category_kind'=>'specific','request_key'=>bin2hex(random_bytes(16))]);
$s=pb_save_product($pdo,$specific,null);pb_assert($s['sku']!==$created['sku'],'Every product gets a unique SKU.');
$specific['request_key']=bin2hex(random_bytes(16));$specific['modelcode']='INVALID';pb_reject(static function()use($pdo,$specific){pb_save_product($pdo,$specific,null);},'Forged category rejected.');
$collision=$rows[0];$collision['category_name']='Collision';$collision['modelcode']=$model['modelcode'];
pb_reject(static function()use($pdo,$conn,$collision){pb_save_uni($pdo,$conn,$collision);},'Specific modelcode collision rejected.');
$unused=$rows[0];$unused['brand']='Temporary test';$unusedId=pb_save_uni($pdo,$conn,$unused);pb_delete_uni($pdo,$unusedId,1);
$stmt=$pdo->prepare('SELECT COUNT(*) FROM product_builder_uni_categories WHERE id=?');$stmt->execute([$unusedId]);pb_assert((int)$stmt->fetchColumn()===0,'Unused category deletion.');
$unique=$pdo->query('SELECT COUNT(DISTINCT modelcode)=COUNT(*) FROM product_builder_uni_categories')->fetchColumn();pb_assert((int)$unique===1,'UNI modelcodes unique.');
$overview=pb_product_overview($pdo);
pb_assert($overview['stats']===['total'=>2,'specific'=>1,'universal'=>1], 'Real overview query counts both category types.');
$filtered=pb_product_overview($pdo, 'Changed');
pb_assert(count($filtered['rows'])===1 && $filtered['rows'][0]['sku']===$created['sku'] && (int)$filtered['rows'][0]['component_count']===2, 'Product search must load joined category and component data.');
// Editable SKU and stored color regressions.
$edited['version']=2;$edited['sku']='SHOP-0001';$edited['components'][1]['reference']='HO00042';$edited['components'][1]['color']='Orange';
$custom=pb_save_product($pdo,$edited,null);
pb_assert($custom['sku']==='SHOP-0001','Existing product accepts a shop SKU.');
$plastic=$pdo->query("SELECT reference,color FROM product_builder_components WHERE product_id=".(int)$custom['id']." AND component_type='P'")->fetch(PDO::FETCH_ASSOC);
pb_assert($plastic===['reference'=>'HO00042','color'=>'Orange'],'Plastic reference and color survive storage.');
$duplicate=$base;$duplicate['request_key']=bin2hex(random_bytes(16));$duplicate['sku']='shop-0001';
pb_reject(static function()use($pdo,$duplicate){pb_save_product($pdo,$duplicate,null);},'Duplicate SKU creation rejected case-insensitively.');
$oldSpecific=$pdo->query('SELECT * FROM product_builder_products WHERE id='.(int)$s['id'])->fetch(PDO::FETCH_ASSOC);
$duplicate=array_merge($base,$model,['id'=>$s['id'],'version'=>1,'sku'=>'SHOP-0001','category_kind'=>'specific']);
pb_reject(static function()use($pdo,$duplicate){pb_save_product($pdo,$duplicate,null);},'Editing to another product SKU rejected.');
pb_assert($oldSpecific===$pdo->query('SELECT * FROM product_builder_products WHERE id='.(int)$s['id'])->fetch(PDO::FETCH_ASSOC),'Duplicate SKU edit must roll back without changing data or version.');
$manual=$base;$manual['request_key']=bin2hex(random_bytes(16));$manual['sku']='  000042-A/BLUE  ';
$manualSaved=pb_save_product($pdo,$manual,null);
pb_assert($manualSaved['sku']==='000042-A/BLUE','New listing uses the exact trimmed custom SKU, including leading zeros.');
$retry=pb_save_product($pdo,$manual,null);pb_assert($retry['id']==$manualSaved['id'],'Custom SKU creation remains idempotent.');
$manual['id']=$manualSaved['id'];$manual['version']=1;
$manualSaved=pb_save_product($pdo,$manual,null);pb_assert($manualSaved['sku']==='000042-A/BLUE','Saving an unchanged custom SKU is allowed.');
$skuSearch=pb_product_overview($pdo,'SHOP-0001');pb_assert(count($skuSearch['rows'])===1 && (int)$skuSearch['rows'][0]['id']===(int)$created['id'],'Search finds the edited SKU.');

// Product options survive save, edit and a JSON round trip for each component type.
$specProduct=$base;$specProduct['request_key']=bin2hex(random_bytes(16));$specProduct['sku']='SPEC-TEST';$specProduct['bundle_type']='GFPS';
$specProduct['components']=[
 ['component_type'=>'G','label'=>'Graphics','quantity'=>1,'notes'=>'Existing internal note','options'=>['graphics_material'=>'Chrome','graphics_finish'=>'Matte','graphics_name'=>'Ján <Team>','graphics_buyer_note'=>"Line 1\nLine 2",'graphics_retired_field'=>'Historical value']],
 ['component_type'=>'F','label'=>'Fitting','quantity'=>1,'options'=>['fitting'=>'jm-1']],
 ['component_type'=>'P','label'=>'Plastic','quantity'=>2,'color'=>'ORANGE','notes'=>'Existing plastic note','options'=>['plastics_buyer_note'=>'Buyer detail']],
 ['component_type'=>'S','label'=>'Seat cover','quantity'=>1,'options'=>['seat_waterproof_seams'=>'✓','seat_enduro_pocket'=>'A','seat_patch_style'=>'✗']]
];
$specSaved=pb_save_product($pdo,$specProduct,null);
$storedSpecs=$pdo->query('SELECT * FROM product_builder_components WHERE product_id='.(int)$specSaved['id'].' ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
foreach($storedSpecs as $i=>$component)pb_assert(pb_component_options($component)===$specProduct['components'][$i]['options'],'Options round trip for type '.$component['component_type']);
pb_assert($storedSpecs[0]['notes']==='Existing internal note' && $storedSpecs[2]['color']==='ORANGE','Separate notes and color remain intact.');
$specProduct['id']=$specSaved['id'];$specProduct['version']=1;$specProduct['components'][0]['options']['graphics_material']='Standard';
$specUpdated=pb_save_product($pdo,$specProduct,null);
$firstSpec=$pdo->query('SELECT * FROM product_builder_components WHERE product_id='.(int)$specSaved['id'].' ORDER BY sort_order LIMIT 1')->fetch(PDO::FETCH_ASSOC);
pb_assert($specUpdated['sku']==='SPEC-TEST' && pb_component_options($firstSpec)['graphics_material']==='Standard' && pb_component_options($firstSpec)['graphics_retired_field']==='Historical value','Editing one option preserves SKU and historical fields.');
pb_assert(pb_component_options(['options_json'=>null])===[],'Legacy components without options load as empty settings.');
echo "Product builder component options: OK (G/F/P/S, Unicode, notes, historical choices, edits)\n";

// Inventory lookup uses temporary fixtures, never alters real stock records.
$pdo->exec('CREATE TEMPORARY TABLE items (barcode VARCHAR(25),description TEXT,color VARCHAR(50)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$insertItem=$pdo->prepare('INSERT INTO items (barcode,description,color) VALUES (?,?,?)');
$insertItem->execute(['000042','Airbox','RED 70']);
$foundItem=pb_find_plastic_item($pdo,' 000042 ');
pb_assert($foundItem===['barcode'=>'000042','label'=>'Airbox','color'=>'RED 70'],'Exact barcode returns description and color with leading zeros preserved.');
pb_assert(pb_find_plastic_item($pdo,'00004')===null && pb_find_plastic_item($pdo,"' OR 1=1 --")===null,'No partial or injected matches.');
$insertItem->execute(['000042','Airbox','RED 70']);pb_assert(pb_find_plastic_item($pdo,'000042')===$foundItem,'Identical duplicate stock rows are unambiguous.');
$insertItem->execute(['000042','Airbox','BLUE']);pb_reject(static function()use($pdo){pb_find_plastic_item($pdo,'000042');},'Different colors sharing a barcode require manual entry.');
$insertItem->execute(['EMPTY',null,null]);pb_assert(pb_find_plastic_item($pdo,'EMPTY')===['barcode'=>'EMPTY','label'=>'','color'=>''],'Missing stock text stays empty.');
echo "Product builder temporary-table integration: OK (SKU changes, uniqueness, color, inventory lookup; no persistent test data)\n";

// Access regression: all authorization fixtures are also session-local temporary tables.
require_once __DIR__ . '/../includes/auth.php';
pb_assert(auth_page_permission('product_builder')==='products.view','New page must be protected.');
foreach(['auth_employee_overrides','auth_employee_roles','auth_role_permissions','auth_permissions','auth_roles'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);
    $pdo->exec(preg_replace('/,\n\)/',"\n)",$ddl));
}
$pdo->exec("INSERT INTO auth_permissions (permission_key,module_key,label,is_active) VALUES ('products.view','Products','View',1),('products.manage','Products','Manage',1)");
$viewId=(int)$pdo->query("SELECT id FROM auth_permissions WHERE permission_key='products.view'")->fetchColumn();
$manageId=(int)$pdo->query("SELECT id FROM auth_permissions WHERE permission_key='products.manage'")->fetchColumn();
$grant=$pdo->prepare("INSERT INTO auth_employee_overrides(employee_id,permission_id,effect) VALUES (?,?,'allow')");
$grant->execute([999991,$viewId]);
$_SESSION=['user_id'=>999991,'permission'=>300];
pb_assert(auth_can('products.view') && !auth_can('products.manage'),'View-only user cannot edit.');
$grant->execute([999992,$viewId]);$grant->execute([999992,$manageId]);
$_SESSION=['user_id'=>999992,'permission'=>300];
pb_assert(auth_can('products.view') && auth_can('products.manage'),'Explicit product permissions allow management.');
$_SESSION=['user_id'=>999993,'permission'=>300];
pb_assert(!auth_can('products.view') && !auth_can('products.manage'),'No implicit employee access.');
$_SESSION=['user_id'=>999994,'permission'=>900];
pb_assert(auth_can('products.view') && auth_can('products.manage'),'Administrator access.');
echo "Product builder authorization: OK (temporary tables only)\n";