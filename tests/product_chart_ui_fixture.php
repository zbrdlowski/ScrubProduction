<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/conn.php';
foreach (['scrubdata','scrubdata_meta','scrub_update_tracking'] as $table) {
    $ddl = $conn->query("SHOW CREATE TABLE $table")->fetch_row()[1];
    $conn->query(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
    $schema = $conn->query("SHOW CREATE TABLE $table")->fetch_row()[1];
    if (strpos($schema,'CREATE TEMPORARY TABLE') !== 0) throw new RuntimeException('Not a temporary table');
}
$conn->query("INSERT INTO scrubdata (brand,model,rangeyear,modelcode) VALUES ('Beta','RR Enduro 4T 125 LC','2021-2027','C57F'),('KTM','EXC','2026','TEST2')");
$meta = ['Graphics'=>['Available'=>'yes','Web'=>'yes','Note'=>'Keep this custom value'], 'Plastics'=>['Available'=>'yes','Web'=>'yes'], 'Seat Cover'=>['Available'=>'yes','Web'=>'no'], 'Configuration'=>['Create New Categories'=>'yes','Add Filters'=>'yes','Add Accessories'=>'no','Add Existing Designs'=>'yes'], 'Notes'=>['Owner'=>'Test team']];
$stmt=$conn->prepare("INSERT INTO scrubdata_meta (brand,model,rangeyear,modelcode,meta_json) VALUES ('Beta','RR Enduro 4T 125 LC','2021-2027','C57F',?)");
$json=json_encode($meta);$stmt->bind_param('s',$json);$stmt->execute();$stmt->close();unset($stmt);
$conn->query("INSERT INTO scrub_update_tracking (trackid,brand,model,rangeyear,modelcode,new_year,done_web,done_ebay,done_graphics_templates,done_seatcover_templates,done_products) VALUES (1,'Beta','RR Enduro 4T 125 LC','2021-2027','C57F',2027,1,0,1,0,0)");
$_SESSION=['user_id'=>17,'permission'=>500];
if (getenv('PC_FIXTURE_READER')) $_SESSION=['user_id'=>99999,'permission'=>1];
$_SERVER['REQUEST_URI']='/index.php?page=product_chart'; $_GET=[];
$target=__DIR__.'/../.cache/product-chart';if(!is_dir($target))mkdir($target,0777,true);
ob_start();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="http://localhost/darkscrub/"><link rel="stylesheet" href="dist/css/adminlte.min.css"><link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css"><link rel="stylesheet" href="plugins/datatables-bs4/css/dataTables.bootstrap4.min.css"><script src="plugins/jquery/jquery.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script><script src="plugins/jquery-ui/jquery-ui.min.js"></script><script src="plugins/datatables/jquery.dataTables.min.js"></script><script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script></head><body class="dark-mode" style="padding:20px;background:#172735"><?php require __DIR__.'/../includes/product_chart.php'; ?></body></html><?php
file_put_contents($target.'/'.(getenv('PC_FIXTURE_READER')?'reader':'preview').'.html',ob_get_clean());
echo "Product chart UI fixture ready.\n";
