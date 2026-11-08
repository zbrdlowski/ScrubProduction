<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function pageAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
// Connection-local temporary tables shadow production names for the entire test.
foreach(['product_builder_uni_categories','product_builder_boxes','product_builder_products','product_builder_components'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
 $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
 $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);
 $pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$insert=$pdo->prepare("INSERT INTO product_builder_products(sku,name,bundle_type,category_kind,brand,model,year_range,modelcode,notes,request_key,created_at) VALUES (?,?,'P','specific','Test','Test','2026',?,'',?,?)");
$pdo->beginTransaction();
for($i=1;$i<=2507;$i++)$insert->execute(['PAGE_'.$i,($i%4===0?'Needle ':'Listing ').$i,$i===1?'OLD1':'AB23',str_pad((string)$i,32,'0',STR_PAD_LEFT),'2026-01-'.str_pad((string)(1+intdiv($i,100)),2,'0',STR_PAD_LEFT).' 12:00:00']);
$pdo->commit();
$expected=array_map('intval',$pdo->query('SELECT id FROM product_builder_products ORDER BY created_at DESC,id DESC')->fetchAll(PDO::FETCH_COLUMN));
$first=pb_product_overview($pdo);
pageAssert($first['pagination']===['page'=>1,'per_page'=>100,'total'=>2507,'pages'=>26,'from'=>1,'to'=>100],'Metadata includes the entire database.');
$seen=[];
for($page=1;$page<=26;$page++){
 $result=pb_product_overview($pdo,'',$page,100);
 $ids=array_map('intval',array_column($result['rows'],'id'));
 pageAssert($ids===array_slice($expected,($page-1)*100,100),'Every page has a stable creation-date and ID order.');
 pageAssert($result['stats']['total']===2507,'Global stats do not shrink with the page.');
 $seen=array_merge($seen,$ids);
}
pageAssert($seen===$expected&&count(array_unique($seen))===2507,'Every listing, including the oldest, is reachable once.');
foreach([25,50,100] as $size){$last=pb_product_overview($pdo,'',PHP_INT_MAX,$size);pageAssert($last['pagination']['page']===(int)ceil(2507/$size),'Out-of-range page clamps before computing the SQL offset.');pageAssert($last['pagination']['to']===2507,'The last page reaches the oldest listing.');}
$filtered=pb_product_overview($pdo,'Needle',2,25);
pageAssert($filtered['pagination']===['page'=>2,'per_page'=>25,'total'=>626,'pages'=>26,'from'=>26,'to'=>50],'Filtering counts matches before pagination.');
pageAssert($filtered['stats']['total']===2507,'Global stats are independent of the search.');
foreach($filtered['rows'] as $row)pageAssert(str_starts_with($row['name'],'Needle '),'Only matching rows returned.');
foreach(['PAGE_2507','OLD1'] as $query){$result=pb_product_overview($pdo,$query,50,25);pageAssert($result['pagination']['total']===1&&$result['pagination']['page']===1,'SKU and modelcode searches cover all ages.');}
$none=pb_product_overview($pdo,'Nothing matches',20,50);pageAssert($none['rows']===[]&&$none['pagination']===['page'=>1,'per_page'=>50,'total'=>0,'pages'=>1,'from'=>0,'to'=>0],'No-match range is 0-0 and has no invalid offset.');
foreach([[0,100],[-1,100],['1.5',100],[[],100],['99999999999999999999999',100],['1 OR 1=1',100],[1,0],[1,10000],[1,'all'],[1,[]]] as [$page,$size]){
 try{pb_product_overview($pdo,'',$page,$size);throw new RuntimeException('Invalid pagination was accepted.');}catch(InvalidArgumentException $e){}
}
$pdo->exec('DELETE FROM product_builder_products');
$empty=pb_product_overview($pdo);pageAssert($empty['rows']===[]&&$empty['pagination']['total']===0&&$empty['stats']['total']===0,'Empty database remains usable.');
echo "Pagination: all 2507 listings reachable, stable dates/ties, 25/50/100 sizes, bounded offsets, full-database searches, counts, empty states and invalid parameters OK (temporary tables only).\n";
