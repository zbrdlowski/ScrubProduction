<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../scripts/product_builder/service.php';
require __DIR__.'/../includes/conn.php';
function deletionAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function deletionReject(callable $call):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected rejection.');}
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_compatibility','product_builder_components','product_builder_channel_listings','product_builder_transfers'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$pdo->exec("INSERT INTO product_builder_uni_categories(brand,category_name,modelcode,included_models) VALUES('Test','Default','TST2','All')");
$base=['name'=>'Delete and restore fixture','sku'=>'DELETE_TEST_'.bin2hex(random_bytes(8)),'bundle_type'=>'GP','category_kind'=>'universal','uni_category_id'=>(int)$pdo->lastInsertId(),'recommended_price'=>'149.90','notes'=>'Keep note','request_key'=>bin2hex(random_bytes(16)),'components'=>[['component_type'=>'G','label'=>'Graphics','quantity'=>1,'options'=>['graphics_number'=>'Yes']],['component_type'=>'P','label'=>'Plastics','reference'=>'TEST_BARCODE','color'=>'Orange','quantity'=>2]]];
$saved=pb_save_product($pdo,$base,7);$id=(int)$saved['id'];
$read=fn()=>$pdo->query('SELECT * FROM product_builder_products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
$componentRows=fn()=>$pdo->query('SELECT * FROM product_builder_components WHERE product_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$components=$componentRows();
$channel=['product_id'=>$id,'version'=>0,'channel'=>'web','status'=>'listed','price'=>'159.90','currency'=>'EUR','url'=>'https://example.test/product','listed_date'=>'2026-11-24'];pb_save_channel_listing($pdo,$channel,7);
pb_save_product_image($pdo,['id'=>$id,'img_version'=>1,'img_status'=>'Path','img_path'=>'Y:\Images with spaces\Graphics']);
$other=$base;$other['sku']='OTHER_'.bin2hex(random_bytes(8));$other['request_key']=bin2hex(random_bytes(16));pb_save_product($pdo,$other,7);
$pdo->prepare("INSERT INTO product_builder_transfers(order_item_id,order_id,order_number,title,source_sku,snapshot_json,work_note,status,product_id,completed_at) VALUES(1,1,'TEST','Graphics','G_TEST','{}','','completed',?,NOW())")->execute([$id]);
$transfer=$pdo->query('SELECT * FROM product_builder_transfers')->fetchAll(PDO::FETCH_ASSOC);
$before=$read();$deleted=pb_set_product_deleted($pdo,['id'=>$id,'version'=>1],7,true);
deletionAssert($deleted['deleted']&&$read()['deleted_at']!==null&&(int)$read()['deleted_by']===7,'Deletion records time and actor.');
deletionAssert((int)$read()['version']===2&&(int)$read()['img_version']===3,'Deletion invalidates open product and IMG editors.');
deletionAssert($componentRows()===$components,'Components are preserved exactly.');
deletionAssert($pdo->query('SELECT * FROM product_builder_transfers')->fetchAll(PDO::FETCH_ASSOC)===$transfer,'Completed transfer stays linked and locked.');
deletionAssert($read()['sku']===$before['sku']&&$read()['img_path']===$before['img_path'],'SKU and image path are preserved.');
$listed=$pdo->query('SELECT * FROM product_builder_channel_listings WHERE product_id='.$id)->fetch(PDO::FETCH_ASSOC);
deletionAssert($listed['listing_url']==='https://example.test/product'&&$listed['listed_price']==='159.90'&&(int)$listed['version']===2,'Platform data preserved and inline revision invalidated.');
deletionAssert(pb_product_overview($pdo)['pagination']['total']===1,'Default overview excludes deleted products.');
deletionAssert(pb_product_overview($pdo,$base['sku'])['pagination']['total']===0,'Active search excludes deleted products.');
$trash=pb_product_overview($pdo,$base['sku'],99,25,'deleted');deletionAssert($trash['pagination']['total']===1&&$trash['pagination']['page']===1&&(int)$trash['rows'][0]['id']===$id&&$trash['stats']['total']===1,'Deleted search and pagination are separate from active statistics.');
foreach([['id'=>0,'version'=>2],['id'=>$id,'version'=>0],['id'=>999999,'version'=>1],['id'=>$id,'version'=>1],['id'=>$id,'version'=>2]] as $invalid)deletionReject(fn()=>pb_set_product_deleted($pdo,$invalid,7,true));
deletionReject(fn()=>pb_set_product_deleted($pdo,['id'=>$id,'version'=>1],7,false));
deletionReject(fn()=>pb_save_product($pdo,$base,7));
$edit=$base+['id'=>$id,'version'=>2];deletionReject(fn()=>pb_save_product($pdo,$edit,7));
deletionReject(fn()=>pb_save_product_image($pdo,['id'=>$id,'img_version'=>3,'img_status'=>'A']));
$channel['version']=2;deletionReject(fn()=>pb_save_channel_listing($pdo,$channel,7));
$duplicate=$base;$duplicate['request_key']=bin2hex(random_bytes(16));deletionReject(fn()=>pb_save_product($pdo,$duplicate,7));
$pdo->exec("INSERT INTO product_builder_transfers(order_item_id,order_id,order_number,title,source_sku,snapshot_json,work_note) VALUES(2,2,'TEST-2','Graphics','G_TEST_2','{}','')");
$pendingId=(int)$pdo->lastInsertId();
deletionReject(fn()=>pb_transfer_save($pdo,['id'=>$pendingId,'version'=>1,'status'=>'completed','purpose'=>'update_images','product_id'=>$id],7));
deletionAssert($pdo->query('SELECT status FROM product_builder_transfers WHERE id='.$pendingId)->fetchColumn()==='pending','New transfers cannot attach to a deleted product.');
$restored=pb_set_product_deleted($pdo,['id'=>$id,'version'=>2],7,false);deletionAssert(!$restored['deleted']&&$read()['deleted_at']===null&&$read()['deleted_by']===null,'Restore reactivates the same product.');
deletionAssert(pb_product_overview($pdo)['pagination']['total']===2&&pb_product_overview($pdo,'',1,100,'deleted')['pagination']['total']===0,'Restored product returns only to active list.');
deletionAssert($componentRows()===$components&&$read()['sku']===$before['sku'],'Restore keeps component identities and original SKU.');
deletionReject(fn()=>pb_save_product_image($pdo,['id'=>$id,'img_version'=>3,'img_status'=>'A']));
deletionReject(fn()=>pb_save_channel_listing($pdo,$channel,7));
$edit['version']=3;$edit['name']='Restored and edited';pb_save_product($pdo,$edit,7);
deletionReject(fn()=>pb_set_product_deleted($pdo,['id'=>$id,'version'=>3],7,true));
deletionAssert($read()['deleted_at']===null&&$read()['name']==='Restored and edited','Stale delete cannot hide a newer edit.');
pb_set_product_deleted($pdo,['id'=>$id,'version'=>4],7,true);pb_set_product_deleted($pdo,['id'=>$id,'version'=>5],7,false);
deletionAssert(!$pdo->inTransaction(),'All transactions close on success and rejection.');
echo "Product deletion: recoverability, retained SKU/components/platforms/transfers, active/trash search, pagination, input validation and stale editor protection OK (temporary tables only).\n";
