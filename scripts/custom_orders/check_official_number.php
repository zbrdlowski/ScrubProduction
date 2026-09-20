<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$finish = static function (array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
};

try {
  $prefix = strtoupper(trim((string) ($_POST['official_prefix'] ?? 'SO')));
  if (!in_array($prefix, ['SO', 'GO', 'SC'], true)) {
    throw new RuntimeException('Invalid official prefix.');
  }

  $stmt = $conn->prepare('SELECT current_value FROM custom_order_number_sequences WHERE prefix_code = ? LIMIT 1');
  if (!$stmt) {
    throw new RuntimeException('Could not load the official number sequence.');
  }
  $stmt->bind_param('s', $prefix);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    throw new RuntimeException('Missing sequence for ' . $prefix . '.');
  }

  $suggestedValue = ((int) $row['current_value']) + 1;
  $requestedRaw = trim((string) ($_POST['official_sequence_value'] ?? ''));
  $sequenceValue = $suggestedValue;
  if ($requestedRaw !== '') {
    if (!ctype_digit($requestedRaw) || (int) $requestedRaw <= 0 || (int) $requestedRaw > 2147483647) {
      $finish([
        'ok' => true,
        'valid' => false,
        'exists' => false,
        'suggested_value' => $suggestedValue,
        'message' => 'Enter a whole number between 1 and 2147483647.',
      ]);
    }
    $sequenceValue = (int) $requestedRaw;
  }

  $number = customOrdersFormatOfficialNumber($prefix, $sequenceValue);
  $exists = customOrdersOfficialNumberExists($conn, $number, (int) ($_POST['custom_order_id'] ?? 0));
  $finish([
    'ok' => true,
    'valid' => true,
    'exists' => $exists,
    'suggested_value' => $suggestedValue,
    'sequence_value' => $sequenceValue,
    'official_number' => $number,
    'message' => $exists ? ('Official number ' . $number . ' already exists in the database.') : '',
  ]);
} catch (Throwable $e) {
  $finish(['ok' => false, 'message' => $e->getMessage()], 400);
}
