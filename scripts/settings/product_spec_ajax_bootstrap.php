<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out_json(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

if (!isset($_SESSION['permission']) || (int) $_SESSION['permission'] < 300) {
  out_json(403, ['ok' => false, 'error' => 'Forbidden']);
}

$paths = [
  __DIR__ . '/includes/conn.php',
  dirname(__DIR__) . '/includes/conn.php',
  dirname(__DIR__, 2) . '/includes/conn.php',
];

$connFile = '';
foreach ($paths as $path) {
  if (is_file($path)) {
    $connFile = $path;
    break;
  }
}

if ($connFile === '') {
  out_json(500, ['ok' => false, 'error' => 'conn.php not found']);
}

require_once $connFile;

if (!isset($conn) || !($conn instanceof mysqli)) {
  out_json(500, ['ok' => false, 'error' => 'Database connection not available']);
}

function post_string(string $key, int $max = 120): string
{
  $value = trim((string) ($_POST[$key] ?? ''));
  if ($value === '') {
    out_json(400, ['ok' => false, 'error' => $key . ' is required']);
  }
  if (mb_strlen($value) > $max) {
    out_json(400, ['ok' => false, 'error' => $key . ' is too long']);
  }
  return $value;
}

/**
 * Rovnaké ako post_string, ale nevyžaduje hodnotu — vracia '' ak chýba.
 */
function post_string_optional(string $key, int $max = 120): string
{
  $value = trim((string) ($_POST[$key] ?? ''));
  if ($value !== '' && mb_strlen($value) > $max) {
    out_json(400, ['ok' => false, 'error' => $key . ' is too long']);
  }
  return $value;
}

function post_int(string $key, int $default = 0): int
{
  if (!isset($_POST[$key]) || $_POST[$key] === '') {
    return $default;
  }
  return (int) $_POST[$key];
}

function product_spec_column_exists(mysqli $conn, string $column): bool
{
  static $cache = [];

  $column = trim($column);
  if ($column === '') {
    return false;
  }

  if (array_key_exists($column, $cache)) {
    return $cache[$column];
  }

  $stmt = $conn->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_spec_options'
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  if (!$stmt) {
    $cache[$column] = false;
    return false;
  }

  $stmt->bind_param('s', $column);
  $exists = false;
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc() !== null;
  }
  $stmt->close();

  $cache[$column] = $exists;
  return $exists;
}

function product_spec_decode_field_meta(?string $rawValue): array
{
  $rawValue = trim((string) $rawValue);
  if ($rawValue === '') {
    return ['source_key' => '', 'field_label' => ''];
  }

  $decoded = json_decode($rawValue, true);
  if (is_array($decoded)) {
    return [
      'source_key' => trim((string) ($decoded['source_key'] ?? '')),
      'field_label' => trim((string) ($decoded['field_label'] ?? '')),
    ];
  }

  return ['source_key' => $rawValue, 'field_label' => ''];
}

function product_spec_encode_field_meta(?string $sourceKey, ?string $fieldLabel, bool $hasDedicatedFieldLabelColumn): ?string
{
  $sourceKey = trim((string) $sourceKey);
  $fieldLabel = trim((string) $fieldLabel);

  if ($hasDedicatedFieldLabelColumn || $fieldLabel === '') {
    return $sourceKey !== '' ? $sourceKey : null;
  }

  return json_encode(
    [
      'source_key' => $sourceKey,
      'field_label' => $fieldLabel,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  ) ?: ($sourceKey !== '' ? $sourceKey : null);
}

function ensure_product_spec_field_label_column(mysqli $conn): void
{
  static $done = false;

  if ($done) {
    return;
  }

  if (!product_spec_column_exists($conn, 'field_label')) {
    $conn->query("
      ALTER TABLE product_spec_options
      ADD COLUMN field_label VARCHAR(190) NULL DEFAULT NULL AFTER field_type
    ");
  }

  $done = true;
}

ensure_product_spec_field_label_column($conn);
