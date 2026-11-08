<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function skuAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function skuReject(callable $call,string $why):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($why);}
foreach(['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table){$ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);$pdo->exec(preg_replace('/,\s*\)/',"\n)",$ddl));}
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','UNI','ZZ23','Models')");$uni=(int)$pdo->lastInsertId();
$base=['name'=>'Keep listing','bundle_type'=>'P','category_kind'=>'universal','uni_category_id'=>$uni,'request_key'=>bin2hex(random_bytes(16)),'sku'=>'UNSUITABLE-SKU','recommended_price'=>'199.90','recommended_currency'=>'EUR','notes'=>'Keep note','components'=>[['component_type'=>'P','label'=>'Kit','reference'=>'HB00046','color'=>'OEM','quantity'=>2,'options'=>['plastics_buyer_note'=>'Keep setting']]]];
$saved=pb_save_product($pdo,$base,null);
$before=$pdo->query('SELECT * FROM product_builder_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$components=$pdo->query('SELECT * FROM product_builder_components ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$candidates=[];foreach(['P','G','S','TFP','GFPS'] as $bundle){$candidate=pb_generate_sku($pdo,$bundle);skuAssert((bool)preg_match('/^'.$bundle.'_[A-F0-9]{20}$/D',$candidate),'Generated format for '.$bundle);$candidates[]=$candidate;}
skuAssert(count(array_unique($candidates))===5,'Each generated preview differs.');
skuAssert($before===$pdo->query('SELECT * FROM product_builder_products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Generating does not change or create products.');
skuAssert($components===$pdo->query('SELECT * FROM product_builder_components ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Generating does not change components.');
foreach(['','UNKNOWN','P_','p',"P'; DROP TABLE"] as $invalid)skuReject(fn()=>pb_generate_sku($pdo,$invalid),'Reject invalid bundle.');
$edit=$base+['id'=>$saved['id'],'version'=>1];$edit['sku']=$candidates[0];
// Another user can take a preview before it is saved; the final unique constraint wins.
$competitor=$base;$competitor['request_key']=bin2hex(random_bytes(16));$competitor['sku']=$edit['sku'];pb_save_product($pdo,$competitor,null);
skuReject(fn()=>pb_save_product($pdo,$edit,null),'A generated preview cannot bypass SKU uniqueness at save.');
skuAssert($pdo->query('SELECT sku FROM product_builder_products WHERE id='.(int)$saved['id'])->fetchColumn()==='UNSUITABLE-SKU','Failed save preserves original SKU.');
$edit['sku']=pb_generate_sku($pdo,'P');$result=pb_save_product($pdo,$edit,null);skuAssert($result['sku']===$edit['sku']&&$result['sku']!=='UNSUITABLE-SKU','Normal edit commits generated SKU.');
$row=$pdo->query('SELECT * FROM product_builder_products WHERE id='.(int)$saved['id'])->fetch(PDO::FETCH_ASSOC);skuAssert($row['name']===$base['name']&&$row['notes']===$base['notes']&&$row['created_at']===$before[0]['created_at'],'SKU replacement preserves listing identity and creation date.');
$component=$pdo->query('SELECT * FROM product_builder_components WHERE product_id='.(int)$saved['id'])->fetch(PDO::FETCH_ASSOC);skuAssert($component['label']==='Kit'&&$component['reference']==='HB00046'&&$component['color']==='OEM'&&(int)$component['quantity']===2&&pb_component_options($component)===$base['components'][0]['options'],'Replacement preserves component fields.');
$edit['version']=2;$edit['sku']='';skuReject(fn()=>pb_save_product($pdo,$edit,null),'An empty existing SKU still requires explicit generation or manual input.');
$new=$base;$new['sku']='';$new['request_key']=bin2hex(random_bytes(16));$automatic=pb_save_product($pdo,$new,null);skuAssert((bool)preg_match('/^P_[A-F0-9]{20}$/D',$automatic['sku']),'New-product automatic generation is unchanged.');
echo "SKU generation: preview without writes, bundle prefixes, validation, final uniqueness, explicit replacement and preserved listing data OK (temporary tables only).\n";
