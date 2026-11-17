<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../scripts/product_builder/transfers.php';
require_once __DIR__.'/../scripts/product_builder/specs.php';
function fieldsAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$definitions=productSpecFieldDefinitions($conn,'G');
$ext=['base-material'=>'Standard','graphics-finish'=>'Gloss','number'=>'500','number-color'=>'Black','name-color'=>'White','grip'=>'Yes','tr-swingarms'=>'No','buyer-note'=>'Customer instructions','name'=>'Personal rider','number-font'=>'Font 1'];
$sample=['options_json'=>json_encode($ext),'internal_options_json'=>json_encode(['_print_material'=>'Chrome']),'qty'=>1];
$raw=pb_transfer_snapshot($sample,$definitions);
$projected=pb_transfer_listing_snapshot($raw);
$keys=array_keys(pb_transfer_graphics_fields());
fieldsAssert(count($projected['fields'])===7&&array_column($projected['fields'],'key')===$keys,'Exactly seven base Graphics fields, once each.');
fieldsAssert($projected['options']['graphics_material']==='Chrome','Internal material remains authoritative.');
fieldsAssert($projected['options']['graphics_number']==='Yes'&&$raw['options']['graphics_number']==='500','Number becomes Yes for the listing without altering the source value.');
fieldsAssert(!str_contains(json_encode($raw),'Customer instructions')&&!str_contains(json_encode($raw),'Moto Carpet')&&!str_contains(json_encode($raw),'Personal rider'),'New transfers omit irrelevant subtypes, buyer notes and personalization.');
foreach(['0','500','Yes'] as $value)fieldsAssert(pb_transfer_number_choice($value)==='Yes','Rider number or Yes becomes Yes.');
foreach(['No','Nie','Nein','none','-'] as $value)fieldsAssert(pb_transfer_number_choice($value)==='No','Explicit absence becomes No.');
fieldsAssert(pb_transfer_number_choice('')==='','Missing Number is not invented.');
// Reproduce the older saved snapshot: same source keys copied into every subcategory.
$legacy=$raw;
$legacy['options']+=['graphics_rim_tapes_base_material'=>'Other material','graphics_seat_patch_base_material'=>'Other material','graphics_moto_carpet_number'=>'123','graphics_stand_number'=>'123','graphics_number_font'=>'Font 1','graphics_buyer_note'=>'Customer instructions'];
$legacy['fields']=[['label'=>'Material','value'=>'Chrome'],['label'=>'Base Material','value'=>'Other material'],['label'=>'Moto Carpet Number','value'=>'123'],['label'=>'Buyer Note','value'=>'Customer instructions']];
$legacy['category']=['brand'=>'KTM','model'=>'SX','year_range'=>'2024-2025','modelcode'=>'TEST'];
$legacy['category_text']='KTM · SX · 2024-2025';
$schema=file_get_contents(__DIR__.'/../db/product_builder_update_012.sql');
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$schema));
$pdo->exec("ALTER TABLE product_builder_transfers ADD COLUMN img_status ENUM('skip','gen','ready') NULL DEFAULT NULL");
$pdo->exec('CREATE TEMPORARY TABLE product_builder_products(id BIGINT PRIMARY KEY,name VARCHAR(255),sku VARCHAR(80))');
$pdo->exec('CREATE TEMPORARY TABLE scrubdata(brand VARCHAR(100),model VARCHAR(150),rangeyear VARCHAR(50),modelcode VARCHAR(7))');
$pdo->exec("INSERT INTO scrubdata VALUES('KTM','SX','2024-2025','TEST')");
$encoded=json_encode($legacy);
$pdo->prepare("INSERT INTO product_builder_transfers(order_item_id,order_id,order_number,title,source_sku,snapshot_json,work_note) VALUES(1,1,'TEST','Graphics','G_TEST',?,'')")->execute([$encoded]);$id=(int)$pdo->lastInsertId();
$pdo->exec("UPDATE product_builder_transfers SET img_status='ready'");
$get=pb_transfer_get($pdo,$id);
fieldsAssert(array_column($get['snapshot']['fields'],'key')===$keys,'Existing snapshots display the same seven fields without SQL backfill.');
fieldsAssert(!str_contains(json_encode($get['snapshot']),'Customer instructions')&&!str_contains(json_encode($get['snapshot']),'moto_carpet'),'Old duplicate and buyer fields never reach the client or draft.');
fieldsAssert($pdo->query('SELECT snapshot_json FROM product_builder_transfers')->fetchColumn()===$encoded,'Opening a legacy transfer does not rewrite it.');
$save=['id'=>$id,'version'=>1,'status'=>'pending','purpose'=>'new_listing','folder_path'=>'','work_note'=>'Keep note','listing_number'=>'No'];
foreach(['500','yes',[],false] as $invalid){$rejected=false;try{pb_transfer_save($pdo,array_replace($save,['listing_number'=>$invalid]),7);}catch(InvalidArgumentException $e){$rejected=true;}fieldsAssert($rejected,'Invalid Number choices are rejected.');}
$saved=pb_transfer_save($pdo,$save,7);
fieldsAssert($saved['snapshot']['options']['graphics_number']==='No','Saved choice is returned in the modal.');
$stored=json_decode($pdo->query('SELECT snapshot_json FROM product_builder_transfers')->fetchColumn(),true);
fieldsAssert($stored['options']===$legacy['options']&&$stored['fields']===$legacy['fields'],'Saving the listing override preserves the original source snapshot.');
$draft=pb_transfer_draft($pdo,$id);
fieldsAssert($draft['components'][0]['options']['graphics_number']==='No'&&array_diff(array_keys($draft['components'][0]['options']),$keys)===[],'New product draft uses the selected Number and only base fields.');
$save['version']=$saved['version'];unset($save['listing_number']);pb_transfer_save($pdo,$save,7);
fieldsAssert(pb_transfer_draft($pdo,$id)['components'][0]['options']['graphics_number']==='No','Older clients omitting Number preserve the saved choice.');
$save['version']=3;$save['listing_number']='';pb_transfer_save($pdo,$save,7);
fieldsAssert(!isset(pb_transfer_draft($pdo,$id)['components'][0]['options']['graphics_number']),'Clearing the choice does not restore the source number.');
echo "Transfer fields: real definitions, deduplication, legacy snapshots, note/subtype filtering, Yes/No validation and persistence, unchanged source and draft mapping OK (temporary tables only).\n";
