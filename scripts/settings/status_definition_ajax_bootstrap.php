<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function status_out_json(int $code, array $payload): void
{
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

if (!isset($_SESSION['permission']) || (int) $_SESSION['permission'] < 300) {
  status_out_json(403, ['ok' => false, 'error' => 'Forbidden']);
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
  status_out_json(500, ['ok' => false, 'error' => 'conn.php not found']);
}

require_once $connFile;

if (!isset($conn) || !($conn instanceof mysqli)) {
  status_out_json(500, ['ok' => false, 'error' => 'Database connection not available']);
}

require_once dirname(__DIR__, 2) . '/includes/status_definition_extensions.php';
if (!statusDefinitionEnsureExtensions($conn)) {
  status_out_json(500, ['ok' => false, 'error' => 'Status definition extensions could not be installed']);
}

function status_post_string(string $key, int $max = 120, bool $required = true): string
{
  $value = trim((string) ($_POST[$key] ?? ''));
  if ($required && $value === '') {
    status_out_json(400, ['ok' => false, 'error' => $key . ' is required']);
  }
  if (mb_strlen($value) > $max) {
    status_out_json(400, ['ok' => false, 'error' => $key . ' is too long']);
  }
  return $value;
}

function status_post_int(string $key, int $default = 0): int
{
  if (!isset($_POST[$key]) || $_POST[$key] === '') {
    return $default;
  }
  return (int) $_POST[$key];
}

function status_allowed_groups(): array
{
  return [
    'order|' => ['scope' => 'order', 'department' => null],
    'item|G' => ['scope' => 'item', 'department' => 'G'],
    'item|S' => ['scope' => 'item', 'department' => 'S'],
    'item|P' => ['scope' => 'item', 'department' => 'P'],
    'item|F' => ['scope' => 'item', 'department' => 'F'],
  ];
}

function status_parse_group(string $groupKey): array
{
  $allowed = status_allowed_groups();
  if (!isset($allowed[$groupKey])) {
    status_out_json(400, ['ok' => false, 'error' => 'Invalid status group']);
  }
  return $allowed[$groupKey];
}
