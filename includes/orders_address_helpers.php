<?php
declare(strict_types=1);

function ordersAddressCountryCode(string $country): string
{
  $country = strtoupper(trim($country));
  $aliases = [
    'USA' => 'US', 'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US',
    'CAN' => 'CA', 'CANADA' => 'CA', 'AUS' => 'AU', 'AUSTRALIA' => 'AU',
  ];
  return $aliases[$country] ?? $country;
}

function ordersAddressStateOptions(): array
{
  return [
    'US' => [
      'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
      'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
      'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
      'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
      'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
      'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
      'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
      'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
      'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
      'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'PR' => 'Puerto Rico',
      'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
      'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
      'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
      'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ],
    'CA' => [
      'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba',
      'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia',
      'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario',
      'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ],
    'AU' => [
      'ACT' => 'Australian Capital Territory', 'NSW' => 'New South Wales',
      'NT' => 'Northern Territory', 'QLD' => 'Queensland', 'SA' => 'South Australia',
      'TAS' => 'Tasmania', 'VIC' => 'Victoria', 'WA' => 'Western Australia',
    ],
  ];
}

function ordersAddressStateCode(string $country, string $state): string
{
  $country = ordersAddressCountryCode($country);
  $state = strtoupper(trim($state));
  if (strpos($state, $country . '-') === 0) {
    $state = substr($state, strlen($country) + 1);
  }
  foreach (ordersAddressStateOptions()[$country] ?? [] as $code => $name) {
    if ($state === $code || $state === strtoupper($name)) {
      return $code;
    }
  }
  return '';
}

function ordersUsStateFromZip(string $zip): string
{
  $zip = preg_replace('/\D+/', '', $zip);
  if (strlen($zip) < 5) {
    return '';
  }
  $n = (int) substr($zip, 0, 5);

  $ranges = [
    'AL' => [[35000, 36999]], 'AK' => [[99500, 99999]], 'AZ' => [[85000, 86999]],
    'AR' => [[71600, 72999]], 'CA' => [[90000, 96699]], 'CO' => [[80000, 81999]],
    'CT' => [[6000, 6999]], 'DE' => [[19700, 19999]],
    'DC' => [[20000, 20099], [20200, 20599], [56900, 56999]],
    'FL' => [[32000, 34999]], 'GA' => [[30000, 31999], [39800, 39999]],
    'HI' => [[96700, 96899]], 'ID' => [[83200, 83999]], 'IL' => [[60000, 62999]],
    'IN' => [[46000, 47999]], 'IA' => [[50000, 52999]], 'KS' => [[66000, 67999]],
    'KY' => [[40000, 42999]], 'LA' => [[70000, 71599]], 'ME' => [[3900, 4999]],
    'MD' => [[20600, 21999]], 'MA' => [[1000, 2799], [5500, 5599]],
    'MI' => [[48000, 49999]], 'MN' => [[55000, 56799]], 'MS' => [[38600, 39799]],
    'MO' => [[63000, 65999]], 'MT' => [[59000, 59999]], 'NE' => [[68000, 69999]],
    'NV' => [[88900, 89999]], 'NH' => [[3000, 3899]], 'NJ' => [[7000, 8999]],
    'NM' => [[87000, 88499]], 'NY' => [[10000, 14999], [500, 599], [6390, 6390]],
    'NC' => [[27000, 28999]], 'ND' => [[58000, 58999]], 'OH' => [[43000, 45999]],
    'OK' => [[73000, 74999]], 'OR' => [[97000, 97999]], 'PA' => [[15000, 19699]],
    'RI' => [[2800, 2999]], 'SC' => [[29000, 29999]], 'SD' => [[57000, 57999]],
    'TN' => [[37000, 38599]], 'TX' => [[75000, 79999], [88500, 88599]],
    'UT' => [[84000, 84999]], 'VT' => [[5000, 5999]], 'VA' => [[20100, 24699]],
    'WA' => [[98000, 99499]], 'WV' => [[24700, 26999]], 'WI' => [[53000, 54999]],
    'WY' => [[82000, 83199]],
  ];

  foreach ($ranges as $state => $rs) {
    foreach ($rs as $r) {
      if ($n >= $r[0] && $n <= $r[1]) {
        return $state;
      }
    }
  }

  return '';
}

function ordersCaProvinceFromPostal(string $postal): string
{
  $postal = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $postal));
  if ($postal === '') {
    return '';
  }

  $map = [
    'A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB',
    'G' => 'QC', 'H' => 'QC', 'J' => 'QC',
    'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
    'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC',
    'X' => 'NT', 'Y' => 'YT',
  ];

  return $map[substr($postal, 0, 1)] ?? '';
}

function ordersResolveAddressState(string $country, string $state, string $zip): string
{
  $country = ordersAddressCountryCode($country);
  $stateCode = ordersAddressStateCode($country, $state);
  if ($stateCode !== '') {
    return $stateCode;
  }

  // Preserve postal-code fallback for older orders without a saved state.
  if ($country === 'US') {
    return ordersUsStateFromZip($zip);
  }
  if ($country === 'CA') {
    return ordersCaProvinceFromPostal($zip);
  }
  return '';
}
