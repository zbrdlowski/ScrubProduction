<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../scripts/product_builder/service.php';
function trimAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function trimReject(callable $call,string $why):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($why);}
foreach(['TFP'=>['G','F','P'],'TFPS'=>['G','F','P','S'],'T'=>['G'],'TP'=>['G','P'],'GT'=>['G'],'GTFP'=>['G','F','P'],'GFP'=>['G','F','P'],'S'=>['S']] as $bundle=>$types)trimAssert(pb_bundle_component_types($bundle)===$types,'Component types for '.$bundle);
$base=['name'=>'OEM look trim kit','bundle_type'=>'TFP','category_kind'=>'universal','recommended_price'=>'199.90','recommended_currency'=>'EUR','request_key'=>bin2hex(random_bytes(16)),'components'=>[
 ['component_type'=>'G','label'=>'Anomally R1 custom OEM text','quantity'=>1,'reference'=>'TRIM-ART','notes'=>'Keep original production note','options'=>['graphics_material'=>'Standard','graphics_finish'=>'Matte','graphics_name'=>'Name','graphics_buyer_note'=>'Small OEM style graphics']],
 ['component_type'=>'F','label'=>'Fitting','quantity'=>1,'options'=>['fitting'=>'JM-1']],
 ['component_type'=>'P','label'=>'Kit','reference'=>'HB00046','color'=>'OEM','quantity'=>1,'notes'=>'Keep plastic note']
]];
trimAssert(array_column(pb_validate_product($base)['components'],'component_type')===['G','F','P'],'TFP validates Graphics, Fitting and Plastics.');
$legacy=$base;$legacy['components'][0]['component_type']='T';trimAssert(pb_validate_product($legacy)['components'][0]['component_type']==='G','Old editor T row normalized on save.');
foreach([0,1,2] as $missing){$bad=$base;unset($bad['components'][$missing]);trimReject(fn()=>pb_validate_product($bad),'Missing TFP component rejected.');}
$bad=$base;$bad['components'][]=['component_type'=>'S','label'=>'Seat','quantity'=>1];trimReject(fn()=>pb_validate_product($bad),'TFP never requires or accepts a Seat Cover.');
$seat=$bad;$seat['bundle_type']='TFPS';trimAssert(array_column(pb_validate_product($seat)['components'],'component_type')===['G','F','P','S'],'Only TFPS adds a Seat Cover.');
$bad=$base;$bad['bundle_type']='GFP';$bad['components'][0]['component_type']='T';trimReject(fn()=>pb_validate_product($bad),'Legacy T input is accepted only for T bundles.');
require_once __DIR__.'/../includes/conn.php';
// Session-local tables shadow live data. No permanent listing/library/inventory changes.
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE items (id INT PRIMARY KEY,barcode VARCHAR(100),main_supplier VARCHAR(100),quantity INT)');
$pdo->exec("INSERT INTO items VALUES (1,'HB00046','Polisport',7)");
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','UNI','ZZ23','Models')");$base['uni_category_id']=(int)$pdo->lastInsertId();
$design=pb_save_design($pdo,['component_type'=>'G','name'=>'Anomally']);$seatDesign=pb_save_design($pdo,['component_type'=>'S','name'=>'Seat design']);$version=pb_save_graphics_version($pdo,['name'=>'R1','sort_order'=>1]);
$base['components'][0]['design_id']=$design;$base['components'][0]['graphics_version_id']=$version;
$created=pb_save_product($pdo,$base,null);trimAssert((bool)preg_match('/^TFP_[A-F0-9]{20}$/D',$created['sku']),'Generated SKU retains the TFP bundle prefix.');
$rows=pb_product_components($pdo,(int)$created['id']);trimAssert(array_column($rows,'component_type')===['G','F','P'],'Saved/read TFP uses G/F/P.');
trimAssert((int)$rows[0]['design_id']===$design && (int)$rows[0]['graphics_version_id']===$version && $rows[0]['design_name']==='Anomally','Trim graphics use Graphics designs and versions.');
trimAssert((array)$rows[0]['options']===$base['components'][0]['options'] && $rows[0]['notes']===$base['components'][0]['notes'],'All graphics options and notes survive.');
trimAssert($rows[2]['reference']==='HB00046' && $rows[2]['color']==='OEM' && (int)$rows[2]['inventory_quantity']===7,'Plastic data and stock details remain intact.');
$edit=$base+['id'=>$created['id'],'version'=>1,'sku'=>$created['sku']];$bad=$edit;$bad['components'][0]['design_id']=$seatDesign;trimReject(fn()=>pb_save_product($pdo,$bad,null),'Seat cover designs cannot be used for trim graphics.');
$edit['components'][0]['label']='Anomally R1 additional text';$updated=pb_save_product($pdo,$edit,null);trimAssert($updated['sku']===$created['sku'],'Editing keeps SKU.');
// Simulate a previously saved generic T component and ensure reads do not mutate it.
$pdo->exec("UPDATE product_builder_components SET component_type='T' WHERE product_id=".(int)$created['id']." AND component_type='G'");
$legacyRows=pb_product_components($pdo,(int)$created['id']);trimAssert($legacyRows[0]['component_type']==='G' && $legacyRows[0]['label']==='Anomally R1 additional text','Legacy T loads as Graphics without losing the name.');
trimAssert($pdo->query('SELECT component_type FROM product_builder_components WHERE product_id='.(int)$created['id'].' ORDER BY sort_order LIMIT 1')->fetchColumn()==='T','Reading leaves persisted legacy data untouched.');
$edit['version']=2;$edit['components']=json_decode(json_encode($legacyRows),true);pb_save_product($pdo,$edit,null);
trimAssert($pdo->query('SELECT component_type FROM product_builder_components WHERE product_id='.(int)$created['id'].' ORDER BY sort_order LIMIT 1')->fetchColumn()==='G','Explicit edit upgrades old T to G.');
$withSeat=$base;$withSeat['bundle_type']='TFPS';$withSeat['request_key']=bin2hex(random_bytes(16));$withSeat['components'][]=['component_type'=>'S','label'=>'Seat design','quantity'=>1,'design_id'=>$seatDesign,'options'=>['seat_waterproof_seams'=>'✓']];
$tfps=pb_save_product($pdo,$withSeat,null);trimAssert(str_starts_with($tfps['sku'],'TFPS_')&&array_column(pb_product_components($pdo,(int)$tfps['id']),'component_type')===['G','F','P','S'],'TFPS stores a separate real Seat Cover.');
$both=$base;$both['bundle_type']='GT';$both['request_key']=bin2hex(random_bytes(16));$both['components']=[$base['components'][0],array_merge($base['components'][0],['component_type'=>'T','label'=>'Second old graphic','quantity'=>2])];
$gt=pb_save_product($pdo,$both,null);$bothRows=pb_product_components($pdo,(int)$gt['id']);trimAssert(array_column($bothRows,'component_type')===['G','G']&&$bothRows[1]['label']==='Second old graphic'&&(int)$bothRows[1]['quantity']===2,'Historical G + T rows are both preserved; only their type is normalized.');
echo "Trim Kit: TFP/TFPS composition, design/version validation, SKU prefix, option storage and legacy T read/edit OK (temporary tables only).\n";
