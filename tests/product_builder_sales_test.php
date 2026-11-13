<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/conn.php';
require __DIR__.'/../scripts/product_builder/sales.php';
function salesAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo->exec('CREATE TEMPORARY TABLE product_builder_products (id BIGINT PRIMARY KEY,sku VARCHAR(128))');
$pdo->exec('CREATE TEMPORARY TABLE order_sources (id INT PRIMARY KEY,code VARCHAR(32))');
$pdo->exec('CREATE TEMPORARY TABLE orders (id BIGINT PRIMARY KEY,source_id INT,status VARCHAR(40),source_meta TEXT)');
$pdo->exec('CREATE TEMPORARY TABLE order_items (id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,sku VARCHAR(128),custom_label VARCHAR(255),qty INT,deleted_at DATETIME,status VARCHAR(40),options_json TEXT)');
$pdo->exec("INSERT INTO product_builder_products VALUES (1,'GFP_TEST'),(2,'P_%_EXACT')");
$pdo->exec("INSERT INTO order_sources VALUES (1,'SHOPTET'),(2,'EBAY'),(4,'CUSTOM')");
$order=$pdo->prepare('INSERT INTO orders VALUES (?,?,?,?)');
foreach([[1,1,'SHIPPED',null],[2,1,'CANCELLED',null],[3,1,'NEW',null],[4,1,'SHIPPED','{"_followup":{"type":"SPLIT"}}'],[5,1,'SHIPPED','{"_followup":{"type":"WARRANTY"}}'],[6,1,'SHIPPED','{"_followup":{"type":"REPEAT"}}'],[7,2,'SHIPPED',null],[8,4,'SHIPPED',null]] as $row)$order->execute($row);
$item=$pdo->prepare('INSERT INTO order_items(order_id,sku,custom_label,qty,deleted_at,status,options_json) VALUES (?,?,?,?,?,?,?)');
$add=fn($id,$qty,$extra=[])=>$item->execute(array_replace([0=>$id,1=>'GFP_TEST',2=>null,3=>$qty,4=>null,5=>'READY',6=>null],$extra));
$add(1,2);$add(1,2,[6=>'{"_auto_generated":"GFP_AUTO_PLASTICS"}']);$add(1,2,[6=>'{"_auto_generated":"GFP_AUTO_FITTING"}']);
$add(1,3);$add(1,1); // Separate purchases of the same SKU must accumulate.
$add(2,20);$add(1,30,[4=>'2026-11-01']);$add(1,40,[5=>'CANCELLED']);$add(1,-3);
$add(3,1);$add(4,2); // Active remainder + split shipment = three actual units.
$add(5,8);$add(6,8); // Replacements/manual repeats are not another marketplace sale.
$add(1,50,[1=>'GFP_TEST_VARIANT']);$add(1,50,[1=>'gfp_test']);$add(8,80);
$add(7,2,[1=>'123456789',2=>'GFP_TEST']);$add(7,3);$add(7,2,[1=>'123456789',2=>'GFP_TEST',6=>'{"_auto_generated":"AUTO_GP_P"}']);
$add(7,100,[2=>'OTHER_SKU']); // eBay numeric/platform SKU never overrides a seller label.
$add(1,4,[1=>'P_%_EXACT']);$add(1,10,[1=>'P_ABC_EXACT']);
$result=pb_listing_sales($pdo,[1,2]);
salesAssert($result[1]===['web'=>9,'ebay'=>5,'amazon'=>null],'Primary bundle units, cancellations, split orders, followups and exact eBay labels.');
salesAssert($result[2]===['web'=>4,'ebay'=>0,'amazon'=>null],'Percent and underscore in an SKU are literal, not wildcards.');
$pdo->exec("INSERT INTO order_sources VALUES (3,'AMAZON')");$order->execute([9,3,'SHIPPED',null]);$add(9,7);
salesAssert(pb_listing_sales($pdo,[1])[1]['amazon']===7,'Amazon counts imported units when its source exists.');
salesAssert(pb_listing_sales($pdo,[])===[],'Empty result page.');
echo "Sales counts: primary bundle quantities, exact SKU, repeated lines, cancelled/deleted items, split orders, excluded replacements, eBay seller labels, unavailable source and Amazon OK (temporary tables only).\n";
