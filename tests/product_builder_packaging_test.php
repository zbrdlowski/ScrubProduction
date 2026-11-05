<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../scripts/product_builder/boxes.php';
function packingAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function packingReject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($message);}
$base=['name'=>'Packed listing','sku'=>'SHOP-PACKED-1','recommended_price'=>'149.90','bundle_type'=>'G','category_kind'=>'universal','request_key'=>bin2hex(random_bytes(16)),'components'=>[['component_type'=>'G','label'=>'Original design','quantity'=>1,'notes'=>'Keep note','options'=>['graphics_finish'=>'Matte']]]];
packingAssert(pb_validate_product($base)['weight_kg']===null&&pb_validate_product($base)['box_id']===null,'Old payloads remain valid with unset packaging.');
packingAssert(pb_validate_product($base+['weight_kg'=>'0'])['weight_kg']==='0.000','Zero total weight is distinct from an unset value.');
packingAssert(pb_validate_product($base+['weight_kg'=>'2,345'])['weight_kg']==='2.345','Accept gram precision and decimal comma.');
foreach(['-1','0.0001','1000000','1e3',[],true] as $bad)packingReject(fn()=>pb_validate_product($base+['weight_kg'=>$bad]),'Reject invalid total weight.');
foreach(['-1','abc',[],0,'1.5'] as $bad)packingReject(fn()=>pb_validate_product($base+['box_id'=>$bad]),'Reject invalid box identifier.');
require __DIR__.'/../includes/conn.php';
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table){$ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$pdo->exec($ddl);}
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','Packaging','ZZ23','Models')");$base['uni_category_id']=(int)$pdo->lastInsertId();
$a=pb_save_box($pdo,['name'=>'Box A','length_cm'=>60,'width_cm'=>40,'height_cm'=>30,'weight_kg'=>'0.500']);
$b=pb_save_box($pdo,['name'=>'Box B','length_cm'=>40,'width_cm'=>30,'height_cm'=>10,'weight_kg'=>'0']);
$saved=pb_save_product($pdo,$base,null);$read=fn()=>pb_product_overview($pdo)['rows'][0];
packingAssert($read()['box_id']===null&&$read()['weight_kg']===null,'New unassigned listing has no invented box or weight.');
$edit=$base+['id'=>$saved['id'],'version'=>1,'box_id'=>$a,'weight_kg'=>'2,345'];pb_save_product($pdo,$edit,null);
$row=$read();packingAssert((int)$row['box_id']===$a&&$row['box_name']==='Box A'&&$row['box_length_cm']==='60.00'&&$row['weight_kg']==='2.345'&&$row['box_weight_kg']==='0.500','Overview joins the box and keeps total weight separate.');
$used=array_values(array_filter(pb_box_list($pdo),fn($box)=>(int)$box['id']===$a))[0];packingAssert((int)$used['usage_count']===1,'Box usage count includes assigned products.');
packingReject(fn()=>pb_delete_box($pdo,['id'=>$a,'version'=>1]),'Used box cannot be deleted.');
pb_save_box($pdo,['id'=>$a,'version'=>1,'name'=>'Renamed box A','length_cm'=>61,'width_cm'=>40,'height_cm'=>30,'weight_kg'=>'0.750']);
$row=$read();packingAssert($row['box_name']==='Renamed box A'&&$row['box_weight_kg']==='0.750'&&$row['weight_kg']==='2.345','Editing the box updates its name/dimensions without changing total weight.');
packingReject(fn()=>pb_save_product($pdo,$edit,null),'Stale product save cannot overwrite packaging.');
$edit['version']=2;$edit['box_id']=$b;pb_save_product($pdo,$edit,null);packingAssert((int)$read()['box_id']===$b&&$read()['weight_kg']==='2.345','Changing to a zero-weight box keeps manual total weight.');
pb_delete_box($pdo,['id'=>$a,'version'=>2]);
$legacy=$base+['id'=>$saved['id'],'version'=>3];pb_save_product($pdo,$legacy,null);packingAssert((int)$read()['box_id']===$b&&$read()['weight_kg']==='2.345','A cached older client cannot erase the new fields.');
$before=$read();$bad=$edit;$bad['version']=4;$bad['box_id']=999999999;packingReject(fn()=>pb_save_product($pdo,$bad,null),'A deleted/nonexistent box is rejected.');packingAssert($read()===$before,'Invalid box causes no partial product update.');
$edit['version']=4;$edit['box_id']='';$edit['weight_kg']='0';pb_save_product($pdo,$edit,null);packingAssert($read()['box_id']===null&&$read()['weight_kg']==='0.000','Box can be unassigned independently of total weight.');
pb_delete_box($pdo,['id'=>$b,'version'=>1]);
$edit['version']=5;$edit['weight_kg']='';pb_save_product($pdo,$edit,null);packingAssert($read()['weight_kg']===null,'Clearing weight restores unknown instead of zero.');
$component=$pdo->query('SELECT * FROM product_builder_components')->fetch(PDO::FETCH_ASSOC);packingAssert($component['label']==='Original design'&&$component['notes']==='Keep note'&&pb_component_options($component)['graphics_finish']==='Matte'&&$read()['sku']==='SHOP-PACKED-1','Packaging changes preserve SKU and components.');
echo "Product packaging: links, manual weights, legacy clients, edits, unassignment, deletion protection and rollback OK (temporary tables only).\n";
