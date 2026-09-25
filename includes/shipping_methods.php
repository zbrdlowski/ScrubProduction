<?php
declare(strict_types=1);

function darkscrubShippingMethodOptions(): array
{
  return [
    'FedEx Economy',
    'FedEx International Economy',
    'FedEx Express',
    'GLS',
    'Post',
    'Pick Up',
    'Drop Ship',
  ];
}

function darkscrubShippingMethodOptionsWithCurrent(?string $currentValue): array
{
  $options = darkscrubShippingMethodOptions();
  $currentValue = trim((string) $currentValue);
  if ($currentValue !== '' && !in_array($currentValue, $options, true)) {
    $options[] = $currentValue;
  }
  return $options;
}

function darkscrubFedexStratusServiceForShipping(?string $shippingMethod): string
{
  $normalized = strtolower(trim((string) $shippingMethod));
  $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
  $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

  $isFedex = strpos($normalized, 'fedex') !== false;

  if ($isFedex && strpos($normalized, 'express') !== false) {
    return 'priority';
  }

  if ($isFedex && strpos($normalized, 'economy') !== false) {
    return 'economy';
  }

  return 'economy';
}
