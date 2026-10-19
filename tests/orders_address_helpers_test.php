<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/orders_address_helpers.php';

function assertSameValue($expected, $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
  }
}

foreach ([
  ['USA', 'California', 'CA'],
  ['CAN', 'British Columbia', 'BC'],
  ['AUS', 'Victoria', 'VIC'],
  [' au ', ' nsw ', 'NSW'],
  ['CA', 'CA-BC', 'BC'],
  ['US', 'VIC', ''],
  ['AU', 'CA', ''],
  ['CA', '', ''],
  ['DE', 'CA', ''],
] as [$country, $state, $expected]) {
  assertSameValue($expected, ordersAddressStateCode($country, $state), 'Normalize ' . $country . ' / ' . $state);
}

foreach ([
  ['US', 'CA', '10001', 'CA'], // A saved state takes precedence over a conflicting ZIP.
  ['CAN', 'British Columbia', 'M5V 3A8', 'BC'],
  ['CA', 'NU', 'X0A 0H0', 'NU'], // An explicit province overrides the legacy X -> NT fallback.
  ['AUS', 'Victoria', '3000', 'VIC'],
  ['AU', 'NSW', '', 'NSW'],
  ['USA', '', '90210-1234', 'CA'],
  ['US', '', '02108', 'MA'],
  ['CA', '', 'v6b 1a1', 'BC'],
  ['CAN', '', 'M5V 3A8', 'ON'],
  ['AU', '', '3000', ''],
  ['US', '', '', ''],
  ['DE', 'CA', '90210', ''],
] as [$country, $state, $zip, $expected]) {
  assertSameValue($expected, ordersResolveAddressState($country, $state, $zip), 'Resolve ' . $country . ' / ' . $state . ' / ' . $zip);
}

echo "orders_address_helpers_test: OK\n";
