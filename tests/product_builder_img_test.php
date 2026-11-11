<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../scripts/product_builder/service.php';
function imgAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function imgReject(callable $call):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid or stale image write was accepted.');}
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table){$ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));}
$pdo->exec("INSERT INTO product_builder_uni_categories(brand,category_name,modelcode,included_models) VALUES ('Husqvarna','All','ZA23','Models')");
$base=['name'=>'IMG listing','sku'=>'TEST-IMG-1','recommended_price'=>'99','bundle_type'=>'G','category_kind'=>'universal','uni_category_id'=>(int)$pdo->lastInsertId(),'request_key'=>bin2hex(random_bytes(16)),'notes'=>'Keep product notes','components'=>[['component_type'=>'G','label'=>'Graphics','quantity'=>1,'notes'=>'Keep component notes']]];
$saved=pb_save_product($pdo,$base,null);$id=$saved['id'];
$read=fn()=>$pdo->query('SELECT * FROM product_builder_products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
$before=$read();$components=$pdo->query('SELECT * FROM product_builder_components')->fetchAll(PDO::FETCH_ASSOC);
imgAssert($before['img_status']==='none'&&$before['img_path']===null&&(int)$before['img_version']===1,'New products start at ✗.');
$revision=1;
foreach(['R','A','none'] as $status){$result=pb_save_product_image($pdo,['id'=>$id,'img_status'=>$status,'img_version'=>$revision]);$revision++;imgAssert($result['img_version']===$revision&&$read()['img_status']===$status,'Immediate status persists.');}
$path='Y:\E-SHOP\_WEB_2025\GRAPHICS_KIT\HUSQVARNA AT';
$result=pb_save_product_image($pdo,['id'=>$id,'img_status'=>'Path','img_path'=>$path,'img_version'=>$revision++]);
imgAssert($read()['img_path']===$path&&$result['img_path']===$path,'Spaces and backslashes round-trip exactly.');
$prior=$read();imgReject(fn()=>pb_save_product_image($pdo,['id'=>$id,'img_status'=>'R','img_version'=>1]));imgAssert($read()===$prior,'Stale writers cannot overwrite IMG.');
pb_save_product_image($pdo,['id'=>$id,'img_status'=>'A','img_version'=>$revision++]);imgAssert($read()['img_path']===$path,'Other states retain the path for later reuse.');
foreach(['',"C:\bad\nfolder",str_repeat('X',2049),['array']] as $bad)imgReject(fn()=>pb_save_product_image($pdo,['id'=>$id,'img_status'=>'Path','img_version'=>$revision,'img_path'=>$bad]));
foreach(['bad',null,[],true] as $bad)imgReject(fn()=>pb_save_product_image($pdo,['id'=>$id,'img_status'=>$bad,'img_version'=>$revision]));
imgReject(fn()=>pb_save_product_image($pdo,['id'=>999999,'img_status'=>'A','img_version'=>1]));
$unc='\\\\server\Shared Designs\O\'Neal & <img src=x> "AT"';
pb_save_product_image($pdo,['id'=>$id,'img_status'=>'Path','img_path'=>$unc,'img_version'=>$revision++]);imgAssert($read()['img_path']===$unc,'Paths are stored as plain text, never interpolated into SQL or opened.');
$after=$read();foreach($before as $field=>$value){if(in_array($field,['img_status','img_path','img_version','updated_at'],true))continue;imgAssert($after[$field]===$value,'IMG must preserve '.$field);}
imgAssert($pdo->query('SELECT * FROM product_builder_components')->fetchAll(PDO::FETCH_ASSOC)===$components,'Inline update must not rebuild components.');
$edit=$base+['id'=>$id,'version'=>1];$edit['name']='Edited in an already open form';pb_save_product($pdo,$edit,null);
imgAssert($read()['name']===$edit['name']&&$read()['img_path']===$unc&&(int)$read()['img_version']===$revision,'An open product editor still saves without replacing IMG.');
imgAssert(pb_product_overview($pdo)['rows'][0]['img_path']===$unc,'Listing API query includes saved IMG values.');
echo "IMG persistence: defaults, all states, spaces/backslashes/UNC, validation, concurrent writes, preservation of products/components and existing form saves OK (temporary tables only).\n";
