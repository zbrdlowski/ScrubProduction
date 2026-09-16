<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/orders_customs_helpers.php';

function assertSameValue($expected, $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException(
      $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
  }
}

assertSameValue(true, ordersRequiresCustomsIdentifier('MX'), 'Mexico requires an identifier');
assertSameValue(true, ordersRequiresCustomsIdentifier(' br '), 'Brazil is normalized');
assertSameValue(true, ordersRequiresCustomsIdentifier('CL'), 'Chile requires an identifier');
assertSameValue(false, ordersRequiresCustomsIdentifier('SK'), 'Unconfigured country does not require an identifier');
assertSameValue('RFC', ordersCustomsIdentifierLabel('mx'), 'Mexico label');
assertSameValue('CPF / CNPJ', ordersCustomsIdentifierLabel('BR'), 'Brazil label');
assertSameValue('RUT', ordersCustomsIdentifierLabel('CL'), 'Chile label');
assertSameValue(true, ordersIsCustomsIdentifierMissing('MX', '  '), 'Blank required identifier is missing');
assertSameValue(false, ordersIsCustomsIdentifierMissing('MX', 'RFC-123'), 'Filled required identifier is complete');
assertSameValue(false, ordersIsCustomsIdentifierMissing('DE', ''), 'Unconfigured country is never flagged');

echo "orders_customs_identifier_test: OK\n";
