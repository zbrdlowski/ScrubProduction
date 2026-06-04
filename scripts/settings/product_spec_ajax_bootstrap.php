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

function post_int(string $key, int $default = 0): int
{
  if (!isset($_POST[$key]) || $_POST[$key] === '') {
    return $default;
  }
  return (int) $_POST[$key];
}
