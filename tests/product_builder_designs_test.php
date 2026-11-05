<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function designAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function designReject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($message);}
// Shadow every table written by this test. No real listing or design rows are modified.
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
 $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
 $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$pdo->exec($ddl);
}
$sql=file_get_contents(__DIR__.'/../db/product_builder_update_004.sql');
$seed=substr($sql,strpos($sql,'-- Supplied Designz.csv seed'));
$pdo->exec($seed);
$counts=$pdo->query('SELECT component_type,COUNT(*) AS total FROM product_builder_designs GROUP BY component_type')->fetchAll(PDO::FETCH_KEY_PAIR);
designAssert((int)$counts['G']===453&&(int)$counts['S']===1064,'Import every unique CSV name into the correct group.');
$graphic=$pdo->query("SELECT * FROM product_builder_designs WHERE component_type='G' AND name='G1'")->fetch(PDO::FETCH_ASSOC);
$seat=$pdo->query("SELECT * FROM product_builder_designs WHERE component_type='S' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
pb_save_design($pdo,array_merge($graphic,['name'=>'Renamed source design']));$pdo->exec($seed);
$again=$pdo->query('SELECT * FROM product_builder_designs WHERE id='.(int)$graphic['id'])->fetch(PDO::FETCH_ASSOC);
designAssert($again['name']==='Renamed source design'&&(int)$again['version']===2,'Repeated seed must preserve renamed source designs and their identities.');
designAssert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_designs')->fetchColumn()===1517,'Repeated seed must not re-add renamed rows.');
$reusedName=pb_save_design($pdo,['component_type'=>'G','name'=>'G1']);$pdo->exec($seed);
designAssert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_designs')->fetchColumn()===1518,'Reusing a renamed source name must not collide during repeated import.');
designAssert($pdo->query('SELECT name FROM product_builder_designs WHERE id='.(int)$graphic['id'])->fetchColumn()==='Renamed source design','Source identity is preserved when its old name is reused.');
$g=pb_save_design($pdo,['component_type'=>'G','name'=>'Shared custom design']);
$s=pb_save_design($pdo,['component_type'=>'S','name'=>'Shared custom design']);
designAssert($g!==$s,'The same name is valid in separate groups.');
designReject(fn()=>pb_save_design($pdo,['component_type'=>'G','name'=>'SHARED CUSTOM DESIGN']),'Case-insensitive duplicates must be rejected within one group.');
designReject(fn()=>pb_save_design($pdo,['component_type'=>'P','name'=>'Invalid']),'Other component groups are not designs.');
designReject(fn()=>pb_save_design($pdo,['id'=>$g,'version'=>1,'component_type'=>'S','name'=>'Moved']),'Editing cannot move a design to a different group.');
pb_save_design($pdo,['id'=>$g,'version'=>1,'component_type'=>'G','name'=>'Custom renamed']);
designReject(fn()=>pb_save_design($pdo,['id'=>$g,'version'=>1,'component_type'=>'G','name'=>'Stale']),'Concurrent edits must be detected.');
designReject(fn()=>pb_delete_design($pdo,['id'=>$g,'version'=>1,'component_type'=>'G']),'Concurrent deletes must be detected.');
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','UNI','ZZ23','Test models')");$uni=(int)$pdo->lastInsertId();
$listing=['recommended_price'=>'149.90','recommended_currency'=>'EUR','name'=>'Design bundle','bundle_type'=>'GS','category_kind'=>'universal','uni_category_id'=>$uni,'request_key'=>bin2hex(random_bytes(16)),'components'=>[
 ['component_type'=>'G','design_id'=>$g,'label'=>'Custom listing name','quantity'=>1,'options'=>['graphics_finish'=>'Matte']],
 ['component_type'=>'S','design_id'=>$s,'label'=>'Seat name','quantity'=>1,'options'=>['seat_waterproof_seams'=>'✓']]]];
$saved=pb_save_product($pdo,$listing,null);
$components=$pdo->query('SELECT * FROM product_builder_components ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
designAssert((int)$components[0]['design_id']===$g&&(int)$components[1]['design_id']===$s&&$components[0]['label']==='Custom listing name','Persist design IDs independently of editable component names.');
designAssert(pb_component_options($components[0])['graphics_finish']==='Matte','Design selection retains extra component settings.');
$used=array_values(array_filter(pb_design_list($pdo),fn($d)=>(int)$d['id']===$g))[0];
designAssert((int)$used['usage_count']===1,'Show saved listing usage.');
designReject(fn()=>pb_delete_design($pdo,['id'=>$g,'version'=>2,'component_type'=>'G']),'Used designs cannot be deleted.');
$edit=array_merge($listing,['id'=>$saved['id'],'version'=>1,'sku'=>$saved['sku']]);
$bad=$edit;$bad['components'][0]['design_id']=$s;
designReject(fn()=>pb_save_product($pdo,$bad,null),'Cross-group design IDs must be rejected server-side.');
$bad=$edit;$bad['components'][0]['design_id']=999999;
designReject(fn()=>pb_save_product($pdo,$bad,null),'Missing design IDs must be rejected.');
designAssert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_components')->fetchColumn()===2,'Failed saves cannot remove existing components.');
pb_save_design($pdo,['id'=>$g,'version'=>2,'component_type'=>'G','name'=>'Library rename after linking']);
pb_save_product($pdo,$edit,null);
designAssert($pdo->query('SELECT label FROM product_builder_components WHERE design_id='.$g)->fetchColumn()==='Custom listing name','Renaming the library does not overwrite a saved component name.');
$edit['version']=2;foreach($edit['components'] as &$component)$component['design_id']=null;unset($component);
pb_save_product($pdo,$edit,null);pb_delete_design($pdo,['id'=>$g,'version'=>3,'component_type'=>'G']);
designAssert(!$pdo->query('SELECT id FROM product_builder_designs WHERE id='.$g)->fetchColumn(),'An unlinked design can be deleted.');
designAssert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_components WHERE design_id IS NULL')->fetchColumn()===2,'Legacy/manual components retain names without a design link.');
echo "Design library: seed/reimport, separate groups, duplicates, optimistic edits, usage/deletion, listing round trips and rollback OK (temporary tables only).\n";
