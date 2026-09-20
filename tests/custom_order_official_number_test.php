<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';

function assertOfficialNumberSame(string $expected, string $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . ': expected ' . $expected . ', got ' . $actual);
  }
}

assertOfficialNumberSame('SO00001', customOrdersFormatOfficialNumber('so', 1), 'SO number is normalized and padded');
assertOfficialNumberSame('GO20776', customOrdersFormatOfficialNumber('GO', 20776), 'GO number keeps the branch sequence');
assertOfficialNumberSame('SC01695', customOrdersFormatOfficialNumber(' SC ', 1695), 'SC number is normalized and padded');

$invalidValueRejected = false;
try {
  customOrdersFormatOfficialNumber('SO', 0);
} catch (RuntimeException $e) {
  $invalidValueRejected = true;
}
if (!$invalidValueRejected) {
  throw new RuntimeException('A non-positive official number was not rejected.');
}

echo "custom_order_official_number_test: OK\n";
