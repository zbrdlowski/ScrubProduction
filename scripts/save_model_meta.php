<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['permission'])) {
    out(403, ['ok' => false, 'error' => 'Not logged in']);
}
/*
// ── Oprávnenie: permission >= 300 (admin/manager) ─────────────────────────
if ((int)($_SESSION['permission'] ?? 0) < 300) {
    out(403, ['ok' => false, 'error' => 'Insufficient permission']);
}
*/
// scripts/ a includes/ sú súrodenci — __DIR__ je darkscrub/scripts
$connFile = dirname(__DIR__) . '/includes/conn.php';
if (!is_file($connFile)) {
    out(500, ['ok' => false, 'error' => 'conn.php not found: ' . $connFile]);
}
require_once $connFile;

$brand     = trim((string)($_POST['brand'] ?? ''));
$model     = trim((string)($_POST['model'] ?? ''));
$rangeyear = trim((string)($_POST['rangeyear'] ?? ''));
$modelcode = trim((string)($_POST['modelcode'] ?? ''));
$metaRaw   = trim((string)($_POST['meta_json'] ?? ''));

if ($brand === '' || $model === '' || $rangeyear === '' || $modelcode === '') {
    out(400, ['ok' => false, 'error' => 'Missing product identity']);
}
if ($metaRaw === '') {
    out(400, ['ok' => false, 'error' => 'Missing meta_json']);
}

// Validácia JSON
$decoded = json_decode($metaRaw, true);
if (!is_array($decoded)) {
    out(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

// Normalizácia — čistý re-encode
$metaClean = json_encode($decoded, JSON_UNESCAPED_UNICODE);

// Overenie, že modelcode existuje
$check = $conn->prepare("
    SELECT lineid
    FROM scrubdata
    WHERE brand = ?
      AND model = ?
      AND rangeyear = ?
      AND modelcode = ?
    LIMIT 1
");
$check->bind_param('ssss', $brand, $model, $rangeyear, $modelcode);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();

if (!$exists) {
    out(404, ['ok' => false, 'error' => 'Model not found: ' . $modelcode]);
}

// Upsert do scrubdata_meta
$updatedBy = (int)($_SESSION['user_id'] ?? 0) ?: null;

$stmt = $conn->prepare("
    INSERT INTO scrubdata_meta (brand, model, rangeyear, modelcode, meta_json, updated_by)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        meta_json  = VALUES(meta_json),
        updated_by = VALUES(updated_by),
        updated_at = CURRENT_TIMESTAMP
");
$stmt->bind_param('sssssi', $brand, $model, $rangeyear, $modelcode, $metaClean, $updatedBy);

if (!$stmt->execute()) {
    out(500, ['ok' => false, 'error' => 'DB error: ' . $stmt->error]);
}
$stmt->close();

out(200, ['ok' => true, 'modelcode' => $modelcode]);