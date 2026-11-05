<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/product_builder/service.php';
require_once __DIR__.'/../includes/conn.php';
function inventory_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
// All writes are session-local fixtures, never inventory or saved listings.
foreach (['product_builder_components','product_builder_designs','product_builder_graphics_versions'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT[^\n]*\n/m','',$ddl);
    $pdo->exec(preg_replace('/,\s*\)/', "\n)", $ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE items (id INT AUTO_INCREMENT PRIMARY KEY,barcode VARCHAR(25),main_supplier VARCHAR(50),quantity INT NULL, INDEX (barcode)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$item=$pdo->prepare('INSERT INTO items(barcode,main_supplier,quantity) VALUES (?,?,?)');
foreach ([['000042','Supplier <A>',12],['ZERO','Supplier B',0],['UNKNOWN',null,null],['DUP','Supplier A',2],['DUP','Supplier B',7],['SAME','Same',4],['SAME','Same',4],['','Blank barcode',99]] as $row) $item->execute($row);
$add=$pdo->prepare('INSERT INTO product_builder_components(product_id,component_type,label,reference,quantity,notes,options_json,sort_order) VALUES (?,?,?,?,?,?,?,?)');
foreach (['000042','ZERO','UNKNOWN','MISSING','DUP','SAME','','00004',"' OR 1=1 --"] as $i=>$code) $add->execute([1,'P','Saved label '.$i,$code,2,'Keep note','{"plastics_buyer_note":"Saved option"}',$i]);
$add->execute([1,'G','Graphics','000042',1,'','{}',20]);
$add->execute([2,'P','Other listing','000042',3,'','{}',0]);
$before=$pdo->query('SELECT * FROM product_builder_components ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$rows=pb_product_components($pdo,1);
inventory_assert(count($rows)===10,'Duplicate inventory must never multiply components or include another listing.');
inventory_assert($rows[0]['inventory_supplier']==='Supplier <A>' && (int)$rows[0]['inventory_quantity']===12 && $rows[0]['inventory_status']==='matched','Exact barcode including leading zeros.');
inventory_assert((int)$rows[0]['quantity']===2 && $rows[0]['label']==='Saved label 0' && $rows[0]['notes']==='Keep note' && $rows[0]['options']->plastics_buyer_note==='Saved option','Inventory must not replace saved component details or quantity.');
inventory_assert((string)$rows[1]['inventory_quantity']==='0','Zero inventory remains zero.');
inventory_assert($rows[2]['inventory_quantity']===null && $rows[2]['inventory_supplier']===null && $rows[2]['inventory_status']==='matched','Unknown inventory values remain null.');
foreach ([3,7,8] as $i) inventory_assert($rows[$i]['inventory_status']==='not_found' && $rows[$i]['inventory_quantity']===null,'Unknown, partial or SQL-like codes never match.');
foreach ([4,5] as $i) inventory_assert($rows[$i]['inventory_status']==='ambiguous' && $rows[$i]['inventory_quantity']===null && $rows[$i]['inventory_supplier']===null,'Duplicate barcode never guesses or sums stock, even for identical rows.');
inventory_assert($rows[6]['inventory_status']==='no_code' && $rows[6]['inventory_quantity']===null,'Empty reference cannot match an empty inventory barcode.');
inventory_assert(!array_key_exists('inventory_quantity',$rows[9]),'Inventory enrichment is only for P components.');
$pdo->exec("UPDATE items SET main_supplier='Updated supplier',quantity=5 WHERE barcode='000042'");
$updated=pb_product_components($pdo,1);
inventory_assert($updated[0]['inventory_supplier']==='Updated supplier' && (int)$updated[0]['inventory_quantity']===5,'Rereading gets current stock.');
inventory_assert($before===$pdo->query('SELECT * FROM product_builder_components ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Reading live stock never changes saved listing data.');
inventory_assert(pb_product_components($pdo,99)===[],'Missing product has no components.');
echo "Component inventory: exact barcode, supplier, zero/unknown quantities, duplicate handling, refresh and unchanged listings OK (temporary tables only).\n";
