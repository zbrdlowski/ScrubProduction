<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function categorySearchAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['product_builder_uni_categories','product_builder_boxes','product_builder_products','product_builder_components'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Kawasaki','Default','UNI1','Models')");$uni=(int)$pdo->lastInsertId();
$insert=$pdo->prepare("INSERT INTO product_builder_products (sku,name,bundle_type,category_kind,uni_category_id,brand,model,year_range,modelcode,notes,request_key,created_at) VALUES (?,?,'P',?,?,?,?,?,?,'',?,'2026-11-09 12:00:00')");
$add=function(string $sku,string $brand,string $model,string $years,string $code,?int $uniId=null)use($insert,$pdo):int{$insert->execute([$sku,'Unrelated product '.$sku,$uniId?'universal':'specific',$uniId,$brand,$model,$years,$code,bin2hex(random_bytes(16))]);return (int)$pdo->lastInsertId();};
$ktm=[];for($i=0;$i<107;$i++)$ktm[]=$add('CAT_K_'.$i,'KTM','SX 50','2016-2023','KT50');
$kawasaki=$add('CAT_UNI','Old brand snapshot','Old category snapshot','','UNI1',$uni);
$husaberg=$add('CAT_H','Husaberg','TE','2013–2014','HUTE');
$other=$add('CAT_NOISE','Honda','CRF 50','2016-2023','HON1');
$apostrophe=$add('CAT_QUOTE',"O'Neal",'Special 100%','2020-2021','ON23');
$expectedKtm=array_reverse($ktm);
foreach(['KTM · SX 50 · 2016-2023','ktm sx 50 2016-2023','  KTM   ·  SX 50  ·  2016-2023  ','KTM 2016-2023','SX 50 KTM','KTM · SX 50 · 2016–2023'] as $query){
 $first=pb_product_overview($pdo,$query,1,100);$second=pb_product_overview($pdo,$query,2,100);
 categorySearchAssert($first['pagination']['total']===107&&$first['pagination']['pages']===2,'Category filtering counts all matches: '.$query);
 categorySearchAssert(array_map('intval',array_column(array_merge($first['rows'],$second['rows']),'id'))===$expectedKtm,'Category matches paginate in stable order: '.$query);
 categorySearchAssert($second['pagination']['from']===101&&$second['pagination']['to']===107,'Last category page has the correct range.');
 categorySearchAssert($first['stats']['total']===111,'Global statistics stay independent.');
}
foreach(['Kawasaki · Default'=>$kawasaki,'Kawasaki Default'=>$kawasaki,'Default'=>$kawasaki,'Husaberg · TE · 2013-2014'=>$husaberg,'TE 2013–2014'=>$husaberg,"O'Neal"=>$apostrophe,'Special 100%'=>$apostrophe,'CAT_UNI'=>$kawasaki,'HUTE'=>$husaberg,'Unrelated product CAT_H'=>$husaberg] as $query=>$id){
 $result=pb_product_overview($pdo,$query,99,25);categorySearchAssert($result['pagination']['total']===1&&(int)$result['rows'][0]['id']===$id,'Expected unique search match: '.$query);
}
categorySearchAssert(pb_product_overview($pdo,'Old brand snapshot')['rows']===[],'UNI search uses the current displayed category rather than the saved snapshot.');
$pdo->exec("UPDATE product_builder_uni_categories SET category_name='All models' WHERE id=".$uni);
categorySearchAssert(pb_product_overview($pdo,'Kawasaki Default')['rows']===[],'Renamed UNI category no longer matches its former display label.');
categorySearchAssert((int)pb_product_overview($pdo,'Kawasaki All models')['rows'][0]['id']===$kawasaki,'Updated UNI name is searchable immediately.');
foreach(['KTM Default','Husaberg 2016-2023',"' OR 1=1 --",'No such category'] as $query)categorySearchAssert(pb_product_overview($pdo,$query)['pagination']['total']===0,'Unrelated/injected category terms do not match: '.$query);
echo "Category search: specific/UNI fields, full pasted labels, spaces/separators/dashes, current UNI names, pagination/counts, old search fields and bound values OK (temporary tables only).\n";
