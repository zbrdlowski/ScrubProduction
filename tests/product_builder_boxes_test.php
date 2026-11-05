<?php
declare(strict_types=1);
require_once __DIR__ . '/../scripts/product_builder/boxes.php';
function boxAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function boxReject(callable $call, string $message): void { try { $call(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException($message); }
$box=['name'=>'Seat cover box','length_cm'=>'60,5','width_cm'=>'40','height_cm'=>'12.25','weight_kg'=>'0'];
$clean=pb_validate_box($box);
boxAssert($clean===['name'=>'Seat cover box','length_cm'=>'60.50','width_cm'=>'40.00','height_cm'=>'12.25','weight_kg'=>'0.000'],'Normalize three dimensions and allow zero weight.');
boxAssert(pb_validate_box(array_merge($box,['weight_kg'=>'0,001']))['weight_kg']==='0.001','One gram weight is preserved exactly.');
boxAssert(pb_validate_box(array_merge($box,['length_cm'=>'99999.99','weight_kg'=>'999999.999']))['length_cm']==='99999.99','Accept schema bounds.');
foreach (['0','0.00','-1','1.234','100000','1e2','',[],null] as $bad) boxReject(fn()=>pb_validate_box(array_merge($box,['length_cm'=>$bad])),'Reject invalid dimensions.');
foreach (['-0.001','0.0001','1000000','',[],null] as $bad) boxReject(fn()=>pb_validate_box(array_merge($box,['weight_kg'=>$bad])),'Reject invalid weight.');
foreach (['',str_repeat('a',151),"Bad\nname"] as $bad) boxReject(fn()=>pb_validate_box(array_merge($box,['name'=>$bad])),'Reject invalid names.');
require __DIR__ . '/../includes/conn.php';
foreach (['product_builder_boxes','product_builder_products'] as $table) {
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
 $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$pdo->exec($ddl);
}
boxAssert(pb_box_list($pdo)===[],'New box library is empty.');
$id=pb_save_box($pdo,$box);$rows=pb_box_list($pdo);
boxAssert(count($rows)===1 && (int)$rows[0]['id']===$id && $rows[0]['weight_kg']==='0.000','Create and list box with zero weight.');
boxReject(fn()=>pb_save_box($pdo,array_merge($box,['name'=>'SEAT COVER BOX'])),'Duplicate names are case insensitive.');
$edit=array_merge($box,['id'=>$id,'version'=>1,'name'=>'Updated box','width_cm'=>'42.50','weight_kg'=>'0.125']);pb_save_box($pdo,$edit);
$after=pb_box_list($pdo);boxAssert($after[0]['name']==='Updated box' && $after[0]['width_cm']==='42.50' && $after[0]['weight_kg']==='0.125' && (int)$after[0]['version']===2,'Edit persists dimensions and weight.');
boxReject(fn()=>pb_save_box($pdo,$edit),'Reject stale updates.');boxReject(fn()=>pb_delete_box($pdo,['id'=>$id,'version'=>1]),'Reject stale deletion.');boxAssert(pb_box_list($pdo)===$after,'Stale mutations preserve data.');
$other=pb_save_box($pdo,array_merge($box,['name'=>'Another box']));boxReject(fn()=>pb_save_box($pdo,array_merge($edit,['version'=>2,'name'=>'Another box'])),'Renaming cannot create duplicates.');
pb_delete_box($pdo,['id'=>$id,'version'=>2]);boxAssert(count(pb_box_list($pdo))===1 && (int)pb_box_list($pdo)[0]['id']===$other,'Delete only selected box.');
boxReject(fn()=>pb_delete_box($pdo,['id'=>$id,'version'=>2]),'Already deleted box is reported.');
echo "Box library: dimensions, zero/gram weights, bounds, names, duplicates, CRUD and concurrent edits OK (temporary table only).\n";
