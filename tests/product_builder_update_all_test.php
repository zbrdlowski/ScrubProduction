<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/conn.php';
function repairAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = dirname(__DIR__);
$prefix = 'pb_repair_test_' . bin2hex(random_bytes(4)) . '_';
$suffixes = ['components','products','uni_categories','designs','graphics_versions','boxes'];
$tables = array_map(fn($name) => $prefix . $name, $suffixes);
$translate = static fn(string $sql): string => str_replace(['product_builder_','fk_pb_'], [$prefix,'fk_' . $prefix], $sql);
$checkSql = $translate(file_get_contents($root . '/db/product_builder_check.sql'));
$updateSql = $translate(file_get_contents($root . '/db/product_builder_update_all.sql'));
$baseSql = file_get_contents($root . '/db/product_builder.sql');
$legacySql = preg_replace('/CREATE TABLE IF NOT EXISTS product_builder_designs \([\s\S]*?(?=CREATE TABLE IF NOT EXISTS product_builder_components)/', '', $baseSql);
$legacySql = preg_replace('/^ color VARCHAR[^\r\n]*\r?\n/m', '', $legacySql);
$legacySql = str_replace(' options_json LONGTEXT NULL,', '', $legacySql);
$legacySql = preg_replace('/^ CONSTRAINT fk_pb_component_(graphics_version|design)[^\r\n]*\r?\n/m', '', $legacySql);
$legacySql = preg_replace('/^ (recommended|web|ebay|amazon)_(price|currency) [^\r\n]*\r?\n/m', '', $legacySql);
$legacySql = preg_replace('/CREATE TABLE IF NOT EXISTS product_builder_boxes \([\s\S]*?ENGINE=InnoDB[^;]*;\s*/', '', $legacySql);
$legacySql = preg_replace('/^ (box_id|weight_kg) [^\r\n]*\r?\n/m', '', $legacySql);
$legacySql = preg_replace('/^ CONSTRAINT fk_pb_product_box [^\r\n]*\r?\n/m', '', $legacySql);
repairAssert(!str_contains($legacySql, 'design_id') && !str_contains($legacySql, 'options_json') && !str_contains($legacySql, 'color VARCHAR'), 'Legacy fixture must omit all four upgrade columns.');
function repairRun(PDO $pdo, string $sql): array {
    $statement = $pdo->query($sql); $sets = [];
    do { if ($statement->columnCount()) $sets[] = $statement->fetchAll(PDO::FETCH_ASSOC); } while ($statement->nextRowset());
    $statement->closeCursor(); return $sets;
}
$existing = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?,?,?)');
$existing->execute($tables);
repairAssert((int)$existing->fetchColumn() === 0, 'Test table names must be unused.');
$cleanup = static function() use ($pdo, $tables, $prefix): void {
    foreach ($tables as $table) {
        if (!preg_match('/^pb_repair_test_[a-f0-9]{8}_(components|products|uni_categories|designs|graphics_versions|boxes)$/D', $table) || !str_starts_with($table, $prefix)) throw new RuntimeException('Unsafe cleanup target.');
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
};
$ready = static function(array $sets): void {
    $rows = $sets[count($sets)-1];
    repairAssert(count($rows) === 20, 'Diagnostics must report all twenty required schema objects.');
    repairAssert(array_unique(array_column($rows, 'status')) === ['OK'], 'All schema objects must be present.');
};
try {
    $ready(repairRun($pdo, $updateSql));
    repairAssert((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}designs")->fetchColumn() === 1517, 'Fresh repair seeds all designs.');
    repairAssert((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}graphics_versions")->fetchColumn() === 12, 'Fresh repair seeds all versions.');
    $ready(repairRun($pdo, $updateSql));
    $cleanup();
    repairRun($pdo, $translate($legacySql));
    $checks = repairRun($pdo, $checkSql);
    repairAssert(count(array_filter($checks[count($checks)-1], fn($row) => $row['status'] === 'MISSING')) === 17, 'Legacy diagnostics identify three missing tables and fourteen columns.');
    $pdo->exec("INSERT INTO {$prefix}uni_categories (brand,category_name,modelcode,included_models) VALUES ('Test','Original category','ZQA7','Original models')");
    $pdo->exec("INSERT INTO {$prefix}products (sku,name,bundle_type,category_kind,uni_category_id,brand,model,modelcode,notes,request_key) VALUES ('EXISTING-SHOPTET-SKU','Original listing','P','universal',1,'Test','Original category','ZQA7','Original notes','0123456789abcdef0123456789abcdef')");
    $pdo->exec("INSERT INTO {$prefix}components (product_id,component_type,label,reference,quantity,notes) VALUES (1,'P','Original component','KT00543',2,'Original component notes')");
    $before = [];
    foreach (['products','components','uni_categories'] as $table) $before[$table] = $pdo->query("SELECT * FROM {$prefix}{$table}")->fetchAll(PDO::FETCH_ASSOC);
    $ready(repairRun($pdo, $updateSql));
    foreach ($before as $table => $rows) {
        $fields = implode(',', array_map(fn($field) => '`' . $field . '`', array_keys($rows[0])));
        repairAssert($pdo->query("SELECT {$fields} FROM {$prefix}{$table}")->fetchAll(PDO::FETCH_ASSOC) === $rows, 'Upgrade must preserve all original values in ' . $table);
    }
    $pdo->exec("UPDATE {$prefix}designs SET name='Renamed design',version=2 WHERE id=1");
    $pdo->exec("UPDATE {$prefix}graphics_versions SET name='Custom ST',sort_order=42,version=2 WHERE name='ST'");
    $pdo->exec("UPDATE {$prefix}components SET color='Orange',options_json='{\"note\":\"saved\"}',design_id=1,graphics_version_id=1 WHERE id=1");
    $pdo->exec("UPDATE {$prefix}products SET recommended_price=149.90,recommended_currency='EUR',web_price=139.90,web_currency='GBP',ebay_price=0.00,ebay_currency='USD',amazon_currency='CAD' WHERE id=1");
    $pdo->exec("INSERT INTO {$prefix}boxes (name,length_cm,width_cm,height_cm,weight_kg) VALUES ('Existing box',60.50,40,30,0)");
    $pdo->exec("UPDATE {$prefix}products SET box_id=1,weight_kg=2.345 WHERE id=1");
    $snapshots = [];
    foreach ($suffixes as $table) $snapshots[$table] = $pdo->query("SELECT * FROM {$prefix}{$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ready(repairRun($pdo, $updateSql));
    foreach ($snapshots as $table => $rows) repairAssert($pdo->query("SELECT * FROM {$prefix}{$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $rows, 'Repeated repair must preserve all records in ' . $table);
    echo "PASS: fresh installation, legacy upgrade, exact missing-object diagnostics, repeated import, existing SKU/component values and renamed library records preserved.\n";
} finally { $cleanup(); }
