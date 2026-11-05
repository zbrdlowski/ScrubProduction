<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function dateAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['product_builder_uni_categories','product_builder_boxes','product_builder_products','product_builder_components'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);
    $pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$insert=$pdo->prepare("INSERT INTO product_builder_products (sku,name,bundle_type,category_kind,brand,model,year_range,modelcode,notes,request_key,created_at,updated_at) VALUES (?,?,'P','specific','KTM','SX','2011-2012','X6XZ','',?,?,?)");
$ids=[];
$anchor=new DateTimeImmutable('2026-01-01 00:00:00');
for($i=1;$i<=102;$i++) {
    $created=$anchor->modify('+'.(102-$i).' hours')->format('Y-m-d H:i:s');
    $insert->execute(['P_DATE_'.$i,'Date listing '.$i,bin2hex(random_bytes(16)),$created,'2026-02-01 12:00:00']);
    $ids[$i]=(int)$pdo->lastInsertId();
}
$latest=$anchor->modify('+101 hours')->format('Y-m-d H:i:s');
$insert->execute(['P_DATE_TIE','Date listing tie',bin2hex(random_bytes(16)),$latest,'2026-02-01 12:00:00']);
$tieId=(int)$pdo->lastInsertId();
$rows=pb_product_overview($pdo)['rows'];
dateAssert(count($rows)===100,'The overview still limits the latest matches to 100.');
dateAssert((int)$rows[0]['id']===$ids[99] && (int)$rows[99]['id']===$tieId,'Newest 100 by creation time appear oldest first, regardless of insertion ID.');
dateAssert((int)$rows[98]['id']===$ids[1],'Equal timestamps have a stable ascending ID order.');
for($i=1;$i<count($rows);$i++)dateAssert($rows[$i-1]['created_at']<=$rows[$i]['created_at'],'Creation timestamps are chronological.');
$searched=pb_product_overview($pdo,'Date listing 102');
dateAssert(count($searched['rows'])===1 && (int)$searched['rows'][0]['id']===$ids[102],'Search applies before selecting the newest matches.');
dateAssert($searched['rows'][0]['created_at']==='2026-01-01 00:00:00','Recent updates do not replace the creation date.');
dateAssert(pb_product_overview($pdo,'No such listing')['rows']===[],'Empty search result remains empty.');
echo "Creation dates: newest 100 selected by creation time, chronological display, timestamp ties, search and independent update dates OK (temporary tables only).\n";
