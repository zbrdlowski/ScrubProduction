<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $level = trim((string) ($_GET['level'] ?? 'brands'));
  $brand = trim((string) ($_GET['brand'] ?? ''));
  $model = trim((string) ($_GET['model'] ?? ''));
  $values = [];

  if ($level === 'brands') {
    $result = $conn->query("SELECT DISTINCT brand AS value FROM scrubdata WHERE brand <> '' ORDER BY brand ASC");
    while ($row = $result->fetch_assoc()) {
      $values[] = (string) $row['value'];
    }
  } elseif ($level === 'models') {
    if ($brand === '') {
      throw new InvalidArgumentException('Brand is required.');
    }
    $stmt = $conn->prepare("SELECT DISTINCT model AS value FROM scrubdata WHERE brand = ? AND model <> '' ORDER BY model ASC");
    $stmt->bind_param('s', $brand);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
      $values[] = (string) $row['value'];
    }
    $stmt->close();
  } elseif ($level === 'years') {
    if ($brand === '' || $model === '') {
      throw new InvalidArgumentException('Brand and model are required.');
    }
    $stmt = $conn->prepare("SELECT DISTINCT rangeyear AS value FROM scrubdata WHERE brand = ? AND model = ? AND rangeyear <> '' ORDER BY rangeyear DESC");
    $stmt->bind_param('ss', $brand, $model);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
      $values[] = (string) $row['value'];
    }
    $stmt->close();
  } else {
    throw new InvalidArgumentException('Unknown category selection level.');
  }

  echo json_encode([
    'ok' => true,
    'values' => $values,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Category options could not be loaded.']);
}
