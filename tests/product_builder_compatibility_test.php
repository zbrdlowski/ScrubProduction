<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../scripts/product_builder/service.php';
require __DIR__.'/../includes/conn.php';
function compatAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function compatReject(callable $call):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected rejection.');}
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_compatibility','product_builder_components','product_builder_channel_listings'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE scrubdata (brand VARCHAR(100),model VARCHAR(150),rangeyear VARCHAR(50),modelcode VARCHAR(7))');
$a=['brand'=>'Yamaha','model'=>'WR450F','year_range'=>'2019-2023','modelcode'=>'NGL2'];
$b=['brand'=>'Yamaha','model'=>'WR250F','year_range'=>'2020-2024','modelcode'=>'NGL2'];
$c=['brand'=>'Suzuki','model'=>'DR-Z400 SM','year_range'=>'1999-2024','modelcode'=>'VMVQ'];
$insert=$pdo->prepare('INSERT INTO scrubdata VALUES(?,?,?,?)');foreach([$a,$b,$c,$a] as $model)$insert->execute(array_values($model));
compatAssert(count(pb_model_catalogue($pdo))===3,'Catalogue deduplicates repeated source rows, not shared modelcodes.');
$base=['name'=>'Compatibility fixture','bundle_type'=>'G','category_kind'=>'specific','recommended_price'=>'149.90','request_key'=>bin2hex(random_bytes(16)),'compatibility'=>[$a,$b,$a],'components'=>[['component_type'=>'G','label'=>'Graphics','quantity'=>1,'options'=>[]]]]+$a;
$product=pb_save_product($pdo,$base,7);$id=(int)$product['id'];
$read=function()use($pdo,$id){$p=$pdo->query('SELECT * FROM product_builder_products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);return pb_product_compatibilities($pdo,[$p])[$id];};
compatAssert($read()===[$a,$b],'Two Yamaha models with NGL2 remain distinct; exact duplicates are removed.');
compatAssert((int)$pdo->query("SELECT COUNT(DISTINCT product_id) FROM product_builder_compatibility WHERE modelcode='NGL2'")->fetchColumn()===1,'Modelcode statistics count the listing once.');
$update=$base+['id'=>$id,'version'=>1];$update['compatibility']=[$a,$b,$c];pb_save_product($pdo,$update,7);
foreach(['WR250F','Yamaha · WR250F · 2020-2024','VMVQ','Suzuki DR-Z400 SM 1999-2024'] as $query){$result=pb_product_overview($pdo,$query);compatAssert($result['pagination']['total']===1&&(int)$result['rows'][0]['id']===$id,'Secondary compatibility searchable: '.$query);compatAssert(count($result['rows'][0]['compatibility'])===3,'Overview contains every compatibility.');}
$update['version']=2;$bad=$update;$bad['compatibility']=[array_replace($a,['modelcode'=>'FAKE'])];compatReject(fn()=>pb_save_product($pdo,$bad,7));
compatAssert($read()===[$a,$b,$c],'Invalid selection rolls back and retains every saved model.');
foreach([[],null,'NGL2',[false],['model'=>$a],[1=>$a],[0=>$a,2=>$b],[1=>$b,0=>$a],array_fill(0,2001,$a)] as $invalid){$bad=$update;$bad['compatibility']=$invalid;compatReject(fn()=>pb_save_product($pdo,$bad,7));}
$oldClient=$update;unset($oldClient['compatibility']);pb_save_product($pdo,$oldClient,7);compatAssert(count($read())===3,'Old client cannot erase additional models when editing other fields.');
$oldClient['version']=3;$oldClient['model']='WR250F';compatReject(fn()=>pb_save_product($pdo,$oldClient,7));
// A source-model deletion must not erase an existing product selection.
$pdo->exec("DELETE FROM scrubdata WHERE model='WR450F'");$update['version']=3;pb_save_product($pdo,$update,7);compatAssert(count($read())===3,'Historical compatibility survives an unchanged edit.');
$bad=$base;$bad['request_key']=bin2hex(random_bytes(16));compatReject(fn()=>pb_save_product($pdo,$bad,7));
$insert->execute(array_values($a));
$pdo->exec("INSERT INTO product_builder_uni_categories(brand,category_name,modelcode,included_models) VALUES('Yamaha','Default','U123','All')");$uni=(int)$pdo->lastInsertId();
$update['version']=4;$update['category_kind']='universal';$update['uni_category_id']=$uni;pb_save_product($pdo,$update,7);compatAssert($read()===[],'Switching to UNI clears specific associations.');
$update['version']=5;$update['category_kind']='specific';$update['compatibility']=[$b];pb_save_product($pdo,$update,7);compatAssert($read()===[$b],'Replacing the selection removes obsolete associations.');
$primary=$pdo->query('SELECT model,modelcode FROM product_builder_products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);compatAssert($primary['model']==='WR250F'&&$primary['modelcode']==='NGL2','Legacy primary fields track the first chosen model.');
$backfill=explode('-- Preserve the original specific category.',file_get_contents(__DIR__.'/../db/product_builder_update_014.sql'))[1];
$backfill=substr($backfill,strpos($backfill,'INSERT INTO'));$pdo->exec($backfill);$pdo->exec($backfill);compatAssert($read()===[$b],'Repeated migration preserves an edited selection.');
for($i=0;$i<26;$i++){$new=$base;$new['request_key']=bin2hex(random_bytes(16));$new['compatibility']=[$a,$b,$c];pb_save_product($pdo,$new,7);}
$page=pb_product_overview($pdo,'VMVQ',2,25);compatAssert($page['pagination']['total']===26&&count($page['rows'])===1,'Multi-model matching paginates without duplicated listings.');
compatAssert(pb_product_overview($pdo,'NGL2')['pagination']['total']===27,'Shared modelcodes do not multiply counts.');
echo "Compatibility: shared codes, deduplication, source validation, rollback, legacy edits, UNI switching, repeat migration, secondary search and pagination OK (temporary tables only).\n";
