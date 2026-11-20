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


foreach ([
  ['78233', '78233'], ['78233-5953', '78233'], ['782335953', '78233'],
  ['78233 5953', '78233'], ["'02108", '02108'], ['2703', '02703'],
  ['', ''], ['12130 Example St', ''], ['San Antonio TX 78233', ''],
  ['78233-12', ''], ['123456', ''], ['7823359530', ''],
] as [$zip, $expected]) {
  assertSameValue($expected, ordersNormalizeUsZip($zip), 'Normalize ZIP ' . $zip);
}

// Exercise the real detail helpers without executing the database endpoint.
$detail = file_get_contents(dirname(__DIR__) . '/scripts/orders/get_order_detail.php');
$start = strpos($detail, 'function normalizeUsZipFromAddress(');
$end = strpos($detail, 'function addressCopyText(', $start);
if ($start === false || $end === false) throw new RuntimeException('Detail address helpers not found.');
eval(substr($detail, $start, $end - $start));
foreach ([
  ['78233-5953', '', 'TX'], ['78233', '', 'TX'], ['', '', ''],
  ['782335953', '', 'TX'], ['invalid', '', ''], ['78233-12', '', ''],
  ['02108', '', 'MA'], ['2703', '', 'MA'], ['', 'TX', 'TX'],
  ['78233-5953', 'CA', 'CA'],
] as [$zip, $state, $expected]) {
  $address = ['country' => 'US', 'state' => $state, 'zip' => $zip,
    'street' => '12130 Example St', 'city' => 'San Antonio'];
  assertSameValue($expected, orderDetailAddressState($address), 'Detail ZIP ' . $zip . ' / state ' . $state);
  assertSameValue($expected, ordersResolveAddressState('US', $state, $zip), 'FedEx ZIP ' . $zip . ' / state ' . $state);
}
assertSameValue('', normalizeUsZipFromAddress(['street' => '12130 Example St', 'city' => 'San Antonio 78233']), 'Never search other address fields for ZIP');

echo "orders_address_helpers_test: OK\n";
