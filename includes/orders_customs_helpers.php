<?php
declare(strict_types=1);

/**
 * Countries that require a customer customs/tax identifier before dispatch.
 *
 * Add another ISO 3166-1 alpha-2 country code here when the rule expands.
 * Example for South Korea:
 *   'KR' => 'Personal Customs Clearance Code (PCCC)',
 */
function ordersCustomsIdentifierCountryLabels(): array
{
  return [
    'MX' => 'RFC',
    'BR' => 'CPF / CNPJ',
    'CL' => 'RUT',
  ];
}

function ordersNormalizeCountryCode(string $countryCode): string
{
  return strtoupper(trim($countryCode));
}

function ordersRequiresCustomsIdentifier(string $countryCode): bool
{
  return array_key_exists(
    ordersNormalizeCountryCode($countryCode),
    ordersCustomsIdentifierCountryLabels()
  );
}

function ordersCustomsIdentifierLabel(string $countryCode): string
{
  $countryCode = ordersNormalizeCountryCode($countryCode);
  return ordersCustomsIdentifierCountryLabels()[$countryCode] ?? 'Customs / Tax ID';
}

function ordersIsCustomsIdentifierMissing(string $countryCode, ?string $identifier): bool
{
  return ordersRequiresCustomsIdentifier($countryCode) && trim((string) $identifier) === '';
}

