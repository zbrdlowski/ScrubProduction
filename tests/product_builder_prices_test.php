<?php
declare(strict_types=1);
require_once __DIR__ . '/../scripts/product_builder/service.php';
function priceAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function priceReject(callable $call, string $message): void { try { $call(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException($message); }
$prices = ['recommended_price'=>'149,90','recommended_currency'=>'EUR','web_price'=>'129.50','web_currency'=>'GBP','ebay_price'=>'0','ebay_currency'=>'USD','amazon_price'=>'','amazon_currency'=>'CAD'];
$clean = pb_validate_product_prices($prices);
priceAssert($clean['recommended_price']==='149.90' && $clean['web_price']==='129.50' && $clean['ebay_price']==='0.00' && $clean['amazon_price']===null, 'Amounts normalize; zero differs from an unset price.');
priceAssert($clean['recommended_currency']==='EUR' && $clean['web_currency']==='GBP' && $clean['ebay_currency']==='USD' && $clean['amazon_currency']==='CAD', 'Each channel retains its own currency, including unset prices.');
priceAssert(pb_validate_product_prices(['recommended_price'=>'99999999.99','recommended_currency'=>'usd'])['recommended_currency']==='USD', 'Maximum supported amount and normalized currency.');
foreach (['',null,'-1','1.234','1e2','100000000','NaN',[]] as $bad) priceReject(fn()=>pb_validate_product_prices(array_merge($prices,['recommended_price'=>$bad])), 'Invalid recommended price rejected.');
foreach (['ZZZ','',[], 'EURO'] as $bad) priceReject(fn()=>pb_validate_product_prices(array_merge($prices,['web_currency'=>$bad])), 'Invalid currencies rejected.');
priceReject(fn()=>pb_validate_product_prices(array_merge($prices,['amazon_price'=>'-0.01'])), 'Optional platform prices are also validated.');
require __DIR__ . '/../includes/conn.php';
foreach (['product_builder_boxes','product_builder_graphics_versions','product_builder_designs','product_builder_uni_categories','product_builder_products','product_builder_components'] as $table) {
    $ddl = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', "\n)", $ddl); $pdo->exec($ddl);
}
$pdo->exec("INSERT INTO product_builder_uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','Price category','ZZ23','Models')");
$uni = (int)$pdo->lastInsertId();
$listing = $prices + ['name'=>'Multi-currency listing','sku'=>'SHOP-PRICE-1','bundle_type'=>'G','category_kind'=>'universal','uni_category_id'=>$uni,'request_key'=>bin2hex(random_bytes(16)), 'components'=>[['component_type'=>'G','label'=>'Original design name','quantity'=>1,'options'=>['graphics_finish'=>'Matte']]]];
$saved = pb_save_product($pdo,$listing,null);
$read = fn()=>$pdo->query('SELECT * FROM product_builder_products WHERE id='.(int)$saved['id'])->fetch(PDO::FETCH_ASSOC);
$row = $read();
foreach ($clean as $field=>$value) priceAssert($row[$field] === $value, 'Stored value must match ' . $field);
$overview = pb_product_overview($pdo)['rows'][0];
foreach ($clean as $field=>$value) priceAssert($overview[$field] === $value, 'Listing API includes ' . $field);
$edited = array_merge($listing,['id'=>$saved['id'],'version'=>1,'recommended_price'=>'169.90','amazon_price'=>'189.99','amazon_currency'=>'AUD']);
pb_save_product($pdo,$edited,null);
$row = $read();priceAssert($row['web_price']==='129.50' && $row['web_currency']==='GBP' && $row['amazon_price']==='189.99' && $row['amazon_currency']==='AUD', 'Changing two prices does not change the other channels.');
priceReject(fn()=>pb_save_product($pdo,$edited,null), 'Stale edits rejected.');priceAssert($read()===$row, 'Stale save cannot change prices or currencies.');
$edited['version']=2;$edited['web_price']='';pb_save_product($pdo,$edited,null);
$row=$read();priceAssert($row['web_price']===null && $row['web_currency']==='GBP' && $row['ebay_price']==='0.00' && $row['amazon_price']==='189.99', 'Clearing one platform price leaves zero and all other prices intact.');
$edited['version']=3;$edited['recommended_price']='';priceReject(fn()=>pb_save_product($pdo,$edited,null), 'Recommended price is mandatory on edit too.');priceAssert($read()===$row,'Rejected price cannot change existing records.');
$component=$pdo->query('SELECT * FROM product_builder_components')->fetch(PDO::FETCH_ASSOC);
priceAssert($row['sku']==='SHOP-PRICE-1' && $component['label']==='Original design name' && pb_component_options($component)['graphics_finish']==='Matte','SKU, components and options survive price edits.');
echo "Product prices: validation, currencies, blank vs zero, independent edits, overview, stale saves and component preservation OK (temporary tables only).\n";
