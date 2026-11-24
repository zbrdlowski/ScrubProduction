<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/conn.php';
require __DIR__ . '/../scripts/product_chart_service.php';

// Connection-local shadows. No application rows are read or changed.
$pdo->exec('CREATE TEMPORARY TABLE scrubdata (lineid INT PRIMARY KEY, brand VARCHAR(70), model VARCHAR(100), rangeyear VARCHAR(20), modelcode VARCHAR(7)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE scrubdata_meta (id INT AUTO_INCREMENT PRIMARY KEY, brand VARCHAR(70), model VARCHAR(100), rangeyear VARCHAR(20), modelcode VARCHAR(7), meta_json LONGTEXT, updated_by INT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY identity_key (brand,model,rangeyear,modelcode)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE scrub_update_tracking (trackid INT PRIMARY KEY, brand VARCHAR(70), model VARCHAR(100), modelcode VARCHAR(7), new_year INT, done_web INT, done_ebay INT, done_graphics_templates INT, done_seatcover_templates INT, done_products INT) ENGINE=InnoDB');
foreach (['scrubdata', 'scrubdata_meta', 'scrub_update_tracking'] as $table) {
    if (strpos($pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1], 'CREATE TEMPORARY TABLE') !== 0) throw new RuntimeException('Not a temporary table');
}
$pdo->exec("INSERT INTO scrubdata VALUES (1,'Beta','RR','2021-2027','C57F'),(2,'KTM','EXC','2026','TEST2')");
$pdo->exec("INSERT INTO scrub_update_tracking VALUES (10,'Beta','RR','C57F',2027,0,0,0,0,0),(11,'KTM','EXC','TEST2',2026,0,0,0,0,0)");
$checks = 0;
function pcCheck(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
function pcFails(callable $call, int $status): void {
    try { $call(); } catch (Throwable $e) { pcCheck((int)$e->getCode() === $status, $e->getMessage()); return; }
    throw new RuntimeException('Expected failure ' . $status);
}
$meta = ['GFP'=>['Available'=>'yes','Web'=>'no','eBay'=>'yes'], 'Graphics'=>['Note'=>'Keep custom data']];
$original = ['trackid'=>10] + array_fill_keys(PC_SAVE_TRACKING_FLAGS, 0);
$flags = ['trackid'=>10] + array_fill_keys(PC_SAVE_TRACKING_FLAGS, 1);
$input = ['brand'=>'Beta','model'=>'RR','rangeyear'=>'2021-2027','modelcode'=>'C57F','meta_json'=>json_encode($meta),'tracking_json'=>json_encode($flags),'tracking_original_json'=>json_encode($original)];
$result = pcSaveModelDetails($pdo, $input, 17);
pcCheck($result['tracking']['done_products'] === 1 && $result['tracking']['new_year'] === 2027, 'Tracking is returned after save');
$saved = $pdo->query("SELECT * FROM scrubdata_meta WHERE modelcode='C57F'")->fetch(PDO::FETCH_ASSOC);
pcCheck(json_decode($saved['meta_json'], true) === $meta && (int)$saved['updated_by'] === 17, 'Metadata and custom fields persisted');
pcCheck((int)$pdo->query('SELECT done_ebay FROM scrub_update_tracking WHERE trackid=10')->fetchColumn() === 1, 'Tracking persisted');
pcFails(fn()=>pcSaveModelDetails($pdo, $input, 17), 409);
pcCheck($pdo->inTransaction() === false, 'Conflict rolls transaction back');
$input['tracking_original_json'] = json_encode($flags);
$foreign = $input; $foreign['tracking_json'] = json_encode(['trackid'=>11] + array_fill_keys(PC_SAVE_TRACKING_FLAGS, 0));
pcFails(fn()=>pcSaveModelDetails($pdo, $foreign, 17), 409);
$invalid = $input; $invalid['tracking_json'] = '{"trackid":10,"done_web":1}';
pcFails(fn()=>pcSaveModelDetails($pdo, $invalid, 17), 400);
$invalid = $input; $invalid['modelcode'] = 'MISSING';
pcFails(fn()=>pcSaveModelDetails($pdo, $invalid, 17), 404);
$legacy = $input; unset($legacy['tracking_json'], $legacy['tracking_original_json']);
pcCheck(pcSaveModelDetails($pdo, $legacy, 17)['tracking'] === null, 'Metadata-only clients still work');
pcCheck((int)$pdo->query('SELECT done_ebay FROM scrub_update_tracking WHERE trackid=10')->fetchColumn() === 1, 'Metadata-only save preserves tracking');
$pdo->exec("INSERT INTO scrubdata VALUES (3,'Honda','CR','2020','EMPTY')");
$empty = ['brand'=>'Honda','model'=>'CR','rangeyear'=>'2020','modelcode'=>'EMPTY','meta_json'=>'{}'];
pcCheck(pcSaveModelDetails($pdo, $empty, 17)['tracking'] === null, 'Model without tracking saves');
// Force the second write to fail after metadata has already been written.
$pdo->exec('UPDATE scrub_update_tracking SET done_products=0');
$pdo->exec('ALTER TABLE scrub_update_tracking ADD CONSTRAINT test_reject_completed CHECK (done_products=0)');
$before = $pdo->query("SELECT meta_json FROM scrubdata_meta WHERE modelcode='C57F'")->fetchColumn();
$bad = $input; unset($bad['tracking_original_json']); $bad['meta_json'] = '{"GFP":{"eBay":"no"}}';
try { pcSaveModelDetails($pdo, $bad, 17); throw new RuntimeException('Expected database error'); }
catch (PDOException $e) { pcCheck(true, 'Second write rejected'); }
pcCheck($pdo->query("SELECT meta_json FROM scrubdata_meta WHERE modelcode='C57F'")->fetchColumn() === $before, 'Metadata rollback is atomic');
pcCheck((int)$pdo->query('SELECT done_products FROM scrub_update_tracking WHERE trackid=10')->fetchColumn() === 0, 'Tracking rollback is atomic');
pcCheck(!$pdo->inTransaction(), 'No transaction left open');
echo "Product chart save: $checks checks passed (temporary tables only).\n";
