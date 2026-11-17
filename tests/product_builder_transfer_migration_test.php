<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/../includes/conn.php';
$prefix='pb_transfer_migration_test_'.bin2hex(random_bytes(4)).'_';
$table=$prefix.'transfers';
$translate=fn($sql)=>str_replace('product_builder_',$prefix,$sql);
try {
 $pdo->exec($translate(file_get_contents(__DIR__.'/../db/product_builder_update_012.sql')));
 $pdo->exec("INSERT INTO `$table` (order_item_id,order_id,order_number,title,snapshot_json,work_note,status) VALUES (1,1,'TEST','Original','{}','Keep note','dismissed')");
 $before=$pdo->query("SELECT * FROM `$table`")->fetch(PDO::FETCH_ASSOC);
 $sql=$translate(file_get_contents(__DIR__.'/../db/product_builder_update_013.sql'));
 $pdo->exec($sql);$pdo->exec($sql);
 $after=$pdo->query("SELECT * FROM `$table`")->fetch(PDO::FETCH_ASSOC);
 if($after['img_status']!==null)throw new RuntimeException('Existing records must start with no decision.');
 unset($after['img_status']);if($after!==$before)throw new RuntimeException('Migration changed existing data.');
 echo "Transfer migration 013: upgrade, repeated import, NULL default and existing-data preservation OK.\n";
}finally{
 if(!preg_match('/^pb_transfer_migration_test_[a-f0-9]{8}_transfers$/D',$table))throw new RuntimeException('Invalid test cleanup target.');
 $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
