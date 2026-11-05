<?php
declare(strict_types=1);
// Static UI fixture only. Never creates an authenticated web session or writes product data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION=['permission'=>900];
require __DIR__.'/../includes/conn.php';
$target=__DIR__.'/../.cache/product-builder';
if (!is_dir($target)) mkdir($target,0777,true);
$base=getenv('PRODUCT_BUILDER_BASE_PATH') ?: '/darkscrub/';
ob_start();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="<?= htmlspecialchars($base,ENT_QUOTES,'UTF-8') ?>"><link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css"><link rel="stylesheet" href="dist/css/adminlte.min.css"><script src="js/jquery-3.6.0.min.js"></script><script src="js/bootstrap.min.js"></script></head><body class="dark-mode" style="padding:24px;background:#343a40"><?php
require __DIR__.'/../includes/product_builder.php';
?><script src="plugins/jquery/jquery.min.js"></script><script src="plugins/jquery-ui/jquery-ui.min.js"></script><script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script></body></html><?php
file_put_contents($target.'/preview.html',ob_get_clean());
file_put_contents($target.'/categories.json',json_encode($pdo->query('SELECT *,0 AS usage_count FROM product_builder_uni_categories ORDER BY brand,category_name')->fetchAll(PDO::FETCH_ASSOC)));
require_once __DIR__.'/../scripts/product_builder/service.php';
file_put_contents($target.'/graphics-versions.json',json_encode(pb_graphics_version_list($pdo)));
file_put_contents($target.'/designs.json',json_encode(pb_design_list($pdo)));
file_put_contents($target.'/overview.json',json_encode(pb_product_overview($pdo)));
require_once __DIR__.'/../scripts/product_builder/boxes.php';
file_put_contents($target.'/boxes.json',json_encode(pb_box_list($pdo)));
echo "Static UI fixture generated in .cache/product-builder.\n";
