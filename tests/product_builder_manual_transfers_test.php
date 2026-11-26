<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Reuse the isolated workflow fixture and its automatic-transfer regression checks.
require __DIR__.'/product_builder_transfers_test.php';
require_once __DIR__.'/../scripts/product_builder/manual_transfers.php';
$pdo->exec("ALTER TABLE product_builder_transfers MODIFY order_id BIGINT UNSIGNED NULL, MODIFY order_item_id BIGINT UNSIGNED NULL, ADD source_kind ENUM('order','manual') NOT NULL DEFAULT 'order', ADD request_key CHAR(32) NULL, ADD UNIQUE KEY uq_pb_transfer_request(request_key)");
$pdo->exec("INSERT INTO product_builder_graphics_versions(name) VALUES('MANUAL-R1')");
$data=['request_key'=>bin2hex(random_bytes(16)),'title'=>'Manual graphics typo','order_number'=>'REFERENCE-1','source_sku'=>'SOURCE-1','source_url'=>'https://example.test/graphics','source_quantity'=>3,'design_name'=>'ARTLEX','version_name'=>'MANUAL-R1','category'=>['brand'=>'KTM','model'=>'SX 50','year_range'=>'2016-2023','modelcode'=>'TEST'],'options'=>['graphics_material'=>'Chrome','graphics_finish'=>'Gloss','graphics_number'=>'Yes','graphics_name'=>'Must not copy'],'component_notes'=>'Internal note'];
$manual=pb_save_manual_transfer($pdo,$data,81);$manualId=(int)$manual['id'];
transferAssert($manual['source_kind']==='manual'&&$manual['order_id']===null&&$manual['order_item_id']===null,'Manual source does not fabricate order links.');
transferAssert($manual['img_status']===null&&$manual['status']==='pending'&&(int)$manual['created_by']===81,'New manual transfer starts unassigned and audited.');
transferAssert($manual['snapshot']['source_quantity']===3&&!isset($manual['snapshot']['options']['graphics_name']),'Same seven-field projection as automatic transfer.');
transferAssert(count($manual['snapshot']['fields'])===7,'All supported graphics fields are present.');
$retry=pb_save_manual_transfer($pdo,$data,81);transferAssert((int)$retry['id']===$manualId,'Retry creates no duplicate.');
$second=pb_save_manual_transfer($pdo,array_replace($data,['request_key'=>bin2hex(random_bytes(16)),'title'=>'Second manual']),82);transferAssert((int)$second['id']!==$manualId,'Multiple manual records can have null order IDs.');
foreach([['title'=>''],['category'=>['brand'=>'Fake','model'=>'Unknown','year_range'=>'2000','modelcode'=>'XXXX']],['options'=>['graphics_number'=>'500']],['source_url'=>'javascript:alert(1)'],['source_quantity'=>0],['request_key'=>[]]] as $bad){
    transferReject(fn()=>pb_save_manual_transfer($pdo,array_replace($data,['request_key'=>bin2hex(random_bytes(16))],$bad),81));
}
$automatic=(int)$pdo->query("SELECT id FROM product_builder_transfers WHERE source_kind='order' LIMIT 1")->fetchColumn();
transferReject(fn()=>pb_save_manual_transfer($pdo,array_replace($data,['id'=>$automatic,'version'=>1,'source_kind'=>'manual']),81));
$ready=pb_transfer_save_image($pdo,['id'=>$manualId,'version'=>$manual['version'],'img_status'=>'ready'],82);
$withFolder=pb_transfer_save_folder($pdo,['id'=>$manualId,'version'=>$ready['version'],'folder_path'=>'Y:\GRAPHICS KIT\Manual'],82);
transferReject(fn()=>pb_save_manual_transfer($pdo,array_replace($data,['id'=>$manualId,'version'=>$manual['version'],'title'=>'Stale typo']),81));
$simpleEdit=array_intersect_key($data,array_flip(['source_sku','design_name','version_name','category']));
$edited=pb_save_manual_transfer($pdo,$simpleEdit+['id'=>$manualId,'version'=>$withFolder['version'],'title'=>'Corrected graphics'],83);
transferAssert($edited['order_number']==='REFERENCE-1'&&$edited['snapshot']['source_quantity']===3&&$edited['snapshot']['source_url']==='https://example.test/graphics'&&$edited['snapshot']['component_notes']==='Internal note'&&$edited['snapshot']['options']===$manual['snapshot']['options'],'Reduced form preserves every hidden legacy field.');
transferAssert($edited['title']==='Corrected graphics'&&$edited['img_status']==='ready'&&$edited['folder_path']===$withFolder['folder_path'],'Editing fixes text without changing workflow or folder.');
transferAssert((int)$edited['created_by']===81&&(int)$edited['updated_by']===83&&$edited['created_at']===$manual['created_at'],'Audit and original creation date preserved.');
$draft=pb_transfer_draft($pdo,$manualId);
transferAssert($draft['name']==='Corrected graphics'&&$draft['modelcode']==='TEST'&&$draft['components'][0]['options']['graphics_number']==='Yes'&&$draft['components'][0]['notes']==='Internal note','Manual snapshot fills the regular builder.');
transferAssert($draft['components'][0]['design_id']!==null&&$draft['components'][0]['graphics_version_id']!==null,'Design and version resolve by the same names as automatic transfers.');
$product=pb_save_product($pdo,$draft+['transfer_id'=>$manualId,'recommended_price'=>'129.90','request_key'=>bin2hex(random_bytes(16))],83);
$completed=pb_transfer_get($pdo,$manualId);transferAssert($completed['status']==='completed'&&(int)$completed['product_id']===(int)$product['id'],'Standard save completes manual transfer.');
transferReject(fn()=>pb_save_manual_transfer($pdo,array_replace($data,['id'=>$manualId,'version'=>$completed['version'],'title'=>'Cannot change completed']),81));
transferReject(fn()=>pb_transfer_save_image($pdo,['id'=>$manualId,'version'=>$completed['version'],'img_status'=>'gen'],81));
$defaults=pb_transfer_list($pdo,['q'=>'','img_filters'=>'unset,skip,gen,ready']);transferAssert(!in_array($manualId,array_map('intval',array_column($defaults['rows'],'id')),true),'Completed manual records are hidden by default.');
$found=pb_transfer_list($pdo,['q'=>'Corrected graphics','img_filters'=>'unset,skip,gen,ready']);transferAssert(in_array($manualId,array_map('intval',array_column($found['rows'],'id')),true),'Completed manual records remain searchable.');
$options=pb_manual_transfer_options($pdo);transferAssert(in_array('ARTLEX',$options['designs'],true)&&count($options['models'])>0,'Manual form reads catalogue choices.');
transferAssert(!$pdo->inTransaction(),'Every success and error closes its transaction.');
$minimal=pb_save_manual_transfer($pdo,$simpleEdit+['request_key'=>bin2hex(random_bytes(16)),'title'=>'ARTLEX MANUAL-R1 For KTM SX 50 2016-2023'],81);
transferAssert($minimal['snapshot']['source_quantity']===1&&$minimal['snapshot']['source_url']===''&&$minimal['snapshot']['component_notes']===''&&$minimal['snapshot']['options']===[]&&$minimal['order_number']==='','New five-field form uses empty extras and quantity one.');
echo "Manual transfers: create, duplicate retry, typed validation, automatic/completed edit protection, stale edits, audit, folder preservation and full Builder completion OK (temporary tables only).\n";
