<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function versionAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function versionReject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($message);}
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
 $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
 $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$pdo->exec($ddl);
}
$sql=file_get_contents(__DIR__.'/../db/product_builder_update_005.sql');$seed=substr($sql,strpos($sql,'-- Default Graphics versions:'));$pdo->exec($seed);
$rows=pb_graphics_version_list($pdo);
versionAssert(array_column($rows,'name')===array_merge(['ST','LE'],array_map(fn($i)=>'R'.$i,range(1,10))),'Seed all 12 versions in requested order.');
$r1=$rows[2];$r2=$rows[3];
pb_save_graphics_version($pdo,array_merge($r1,['name'=>'R one','sort_order'=>77]));$pdo->exec($seed);
$edited=$pdo->query('SELECT * FROM product_builder_graphics_versions WHERE id='.(int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
versionAssert($edited['name']==='R one'&&(int)$edited['sort_order']===77&&(int)$edited['version']===2,'Reimport preserves names, order, identity and revision.');
pb_save_graphics_version($pdo,['name'=>'R1','sort_order'=>3]);$pdo->exec($seed);
versionAssert(count(pb_graphics_version_list($pdo))===13,'Reused source name does not collide during reimport.');
versionReject(fn()=>pb_save_graphics_version($pdo,['name'=>'st']),'Duplicate version names are case insensitive.');
versionReject(fn()=>pb_save_graphics_version($pdo,['name'=>'Invalid','sort_order'=>'1.5']),'Fractional order rejected.');
versionReject(fn()=>pb_save_graphics_version($pdo,['name'=>str_repeat('A',31)]),'Overlong version name rejected.');
versionReject(fn()=>pb_save_graphics_version($pdo,array_merge($r1,['name'=>'Stale'])),'Stale version edit rejected.');
versionReject(fn()=>pb_delete_graphics_version($pdo,$r1),'Stale version deletion rejected.');
$design=pb_save_design($pdo,['component_type'=>'G','name'=>'Anomally']);
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','UNI','ZZ23','Models')");$uni=(int)$pdo->lastInsertId();
$listing=['recommended_price'=>'149.90','recommended_currency'=>'EUR','name'=>'Version listing','bundle_type'=>'G','category_kind'=>'universal','uni_category_id'=>$uni,'request_key'=>bin2hex(random_bytes(16)),'components'=>[
 ['component_type'=>'G','design_id'=>$design,'graphics_version_id'=>$r1['id'],'label'=>'Anomally R one - custom text','reference'=>'Reference','quantity'=>1,'notes'=>'Keep note','options'=>['graphics_finish'=>'Matte']],
 ['component_type'=>'G','design_id'=>$design,'graphics_version_id'=>$r2['id'],'label'=>'Anomally R2','quantity'=>2]]];
$saved=pb_save_product($pdo,$listing,null);
$stored=$pdo->query('SELECT * FROM product_builder_components ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
versionAssert((int)$stored[0]['graphics_version_id']===(int)$r1['id']&&(int)$stored[1]['graphics_version_id']===(int)$r2['id'],'Each component stores its own version.');
versionAssert($stored[0]['label']==='Anomally R one - custom text'&&$stored[0]['reference']==='Reference'&&pb_component_options($stored[0])['graphics_finish']==='Matte','Custom text and component fields persist.');
$used=array_values(array_filter(pb_graphics_version_list($pdo),fn($v)=>(int)$v['id']===(int)$r1['id']))[0];
versionAssert((int)$used['usage_count']===1,'Version editor shows saved listing usage.');
versionReject(fn()=>pb_delete_graphics_version($pdo,$edited),'A used version cannot be deleted.');
$edit=array_merge($listing,['id'=>$saved['id'],'version'=>1,'sku'=>$saved['sku']]);
$bad=$edit;$bad['components'][0]['graphics_version_id']=999999;
versionReject(fn()=>pb_save_product($pdo,$bad,null),'Missing version rejected server-side.');
$bad=$edit;$bad['bundle_type']='S';$bad['components']=[array_merge($bad['components'][0],['component_type'=>'S','design_id'=>null])];
versionReject(fn()=>pb_save_product($pdo,$bad,null),'Seat covers cannot use Graphics versions.');
versionAssert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_components')->fetchColumn()===2,'Rejected edits preserve existing components.');
pb_save_graphics_version($pdo,array_merge($edited,['name'=>'Library renamed']));pb_save_product($pdo,$edit,null);
versionAssert($pdo->query('SELECT label FROM product_builder_components WHERE graphics_version_id='.(int)$r1['id'])->fetchColumn()==='Anomally R one - custom text','Library rename and listing save keep the custom name.');
$edit['version']=2;foreach($edit['components'] as &$component)$component['graphics_version_id']=null;unset($component);pb_save_product($pdo,$edit,null);
pb_delete_graphics_version($pdo,['id'=>$r1['id'],'version'=>3]);
versionAssert((int)$pdo->query('SELECT COUNT(*) FROM product_builder_components WHERE graphics_version_id IS NULL')->fetchColumn()===2,'Versions remain optional for existing/manual graphics.');
echo "Graphics versions: seed order/reimport, CRUD, stale writes, duplicates, type validation, independent component versions and custom-name round trips OK (temporary tables only).\n";
