<?php
declare(strict_types=1);
// CLI-only additive installation, with idempotent import of the supplied CSV.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/csv.php';
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_002.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_003.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_004.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_005.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_006.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_007.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../../db/product_builder_update_008.sql'));
// Permissions are installed separately by the access administrator; no employee roles are assigned here.
$result = pb_import_uni($pdo,$conn,pb_parse_csv(__DIR__ . '/UniCategories.csv'));
echo 'Product builder ready. UNI added: ' . $result['added'] . '; existing: ' . $result['skipped'] . PHP_EOL;
