<?php
declare(strict_types=1);

function fedexConfigValue(string $key, string $default = ''): string
{
  $value = getenv($key);
  if ($value === false && isset($_SERVER[$key])) {
    $value = $_SERVER[$key];
  }

  $value = trim((string) $value);
  return $value !== '' ? $value : $default;
}

function fedexApiBaseUrl(): string
{
  $configured = fedexConfigValue('FEDEX_API_BASE_URL');
  if ($configured !== '') {
    return rtrim($configured, '/');
  }

  $sandbox = strtolower(fedexConfigValue('FEDEX_SANDBOX'));
  if (in_array($sandbox, ['1', 'true', 'yes', 'on'], true)) {
    return 'https://apis-sandbox.fedex.com';
  }

  return 'https://apis.fedex.com';
}

function fedexHttpRequest(string $method, string $url, array $headers, ?string $body = null, int $timeout = 35): array
{
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    if ($ch === false) {
      throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false) {
      throw new RuntimeException('FedEx HTTP request failed: ' . ($curlError !== '' ? $curlError : 'unknown cURL error'));
    }

    return ['status' => $statusCode, 'body' => (string) $responseBody];
  }

  $context = stream_context_create([
    'http' => [
      'method' => strtoupper($method),
      'header' => implode("\r\n", $headers),
      'content' => $body ?? '',
      'timeout' => $timeout,
      'ignore_errors' => true,
    ],
  ]);

  $responseBody = @file_get_contents($url, false, $context);
  if ($responseBody === false) {
    throw new RuntimeException('FedEx HTTP request failed and cURL is not available.');
  }

  $statusCode = 0;
  if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
    $statusCode = (int) $m[1];
  }

  return ['status' => $statusCode, 'body' => (string) $responseBody];
}

function fedexDecodeJsonResponse(array $response, string $operation): array
{
  $decoded = json_decode((string) ($response['body'] ?? ''), true);
  if (!is_array($decoded)) {
    throw new RuntimeException($operation . ' returned a non-JSON response.');
  }

  $status = (int) ($response['status'] ?? 0);
  if ($status < 200 || $status >= 300) {
    $message = fedexResponseMessage($decoded);
    throw new RuntimeException($operation . ' failed with HTTP ' . $status . ($message !== '' ? ': ' . $message : '.'));
  }

  return $decoded;
}

function fedexResponseMessage(array $response): string
{
  $messages = [];

  foreach (['errors', 'notifications'] as $key) {
    if (empty($response[$key]) || !is_array($response[$key])) {
      continue;
    }
    foreach ($response[$key] as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $parts = [];
      foreach (['code', 'message', 'localizedMessage', 'description'] as $field) {
        if (!empty($entry[$field])) {
          $parts[] = trim((string) $entry[$field]);
        }
      }
      if ($parts) {
        $messages[] = implode(' - ', array_unique($parts));
      }
    }
  }

  if (isset($response['error_description'])) {
    $messages[] = trim((string) $response['error_description']);
  } elseif (isset($response['error'])) {
    $messages[] = trim((string) $response['error']);
  }

  return implode(' | ', array_filter(array_unique($messages)));
}

function fedexOauthAccessToken(): string
{
  static $token = '';
  static $expiresAt = 0;

  if ($token !== '' && $expiresAt > time() + 120) {
    return $token;
  }

  $clientId = fedexConfigValue('FEDEX_CLIENT_ID');
  $clientSecret = fedexConfigValue('FEDEX_CLIENT_SECRET');
  if ($clientId === '' || $clientSecret === '') {
    throw new RuntimeException('Missing FEDEX_CLIENT_ID or FEDEX_CLIENT_SECRET.');
  }

  $payload = [
    'grant_type' => fedexConfigValue('FEDEX_GRANT_TYPE', 'client_credentials'),
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
  ];

  $childKey = fedexConfigValue('FEDEX_CHILD_KEY');
  $childSecret = fedexConfigValue('FEDEX_CHILD_SECRET');
  if ($childKey !== '' && $childSecret !== '') {
    $payload['child_key'] = $childKey;
    $payload['child_secret'] = $childSecret;
  }

  $response = fedexHttpRequest(
    'POST',
    fedexApiBaseUrl() . '/oauth/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query($payload, '', '&')
  );
  $decoded = fedexDecodeJsonResponse($response, 'FedEx OAuth');

  $token = trim((string) ($decoded['access_token'] ?? ''));
  if ($token === '') {
    throw new RuntimeException('FedEx OAuth response did not include access_token.');
  }

  $expiresAt = time() + max(300, ((int) ($decoded['expires_in'] ?? 3600)) - 60);
  return $token;
}

function fedexTrackByTrackingNumbers(array $trackingNumbers, bool $includeDetailedScans = true): array
{
  $trackingNumbers = array_values(array_unique(array_filter(array_map(static function ($value): string {
    return trim((string) $value);
  }, $trackingNumbers))));

  if (!$trackingNumbers) {
    return [];
  }
  if (count($trackingNumbers) > 30) {
    throw new InvalidArgumentException('FedEx Track API allows a maximum of 30 tracking numbers per request.');
  }

  $trackingInfo = [];
  foreach ($trackingNumbers as $trackingNumber) {
    $trackingInfo[] = [
      'trackingNumberInfo' => [
        'trackingNumber' => $trackingNumber,
      ],
    ];
  }

  $payload = [
    'includeDetailedScans' => $includeDetailedScans,
    'trackingInfo' => $trackingInfo,
  ];

  $transactionId = 'darkscrub-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
  $response = fedexHttpRequest(
    'POST',
    fedexApiBaseUrl() . '/track/v1/trackingnumbers',
    [
      'Authorization: Bearer ' . fedexOauthAccessToken(),
      'Content-Type: application/json',
      'x-locale: ' . fedexConfigValue('FEDEX_LOCALE', 'en_US'),
      'x-customer-transaction-id: ' . $transactionId,
    ],
    json_encode($payload, JSON_UNESCAPED_SLASHES)
  );

  return fedexDecodeJsonResponse($response, 'FedEx Track');
}

function fedexNormalizeDateTime(?string $value): ?string
{
  $value = trim((string) $value);
  if ($value === '') {
    return null;
  }

  try {
    $dt = new DateTimeImmutable($value);
  } catch (Throwable $e) {
    return null;
  }

  $timezoneName = fedexConfigValue('FEDEX_DB_TIMEZONE', date_default_timezone_get() ?: 'Europe/Bratislava');
  try {
    $timezone = new DateTimeZone($timezoneName);
  } catch (Throwable $e) {
    $timezone = new DateTimeZone('Europe/Bratislava');
  }

  return $dt->setTimezone($timezone)->format('Y-m-d H:i:s');
}

function fedexTrackResultsByNumber(array $response): array
{
  $out = [];
  $completeResults = $response['output']['completeTrackResults'] ?? [];
  if (!is_array($completeResults)) {
    return $out;
  }

  foreach ($completeResults as $completeResult) {
    if (!is_array($completeResult)) {
      continue;
    }

    $fallbackTracking = trim((string) ($completeResult['trackingNumber'] ?? ''));
    $trackResults = $completeResult['trackResults'] ?? [];
    if (!is_array($trackResults)) {
      continue;
    }

    foreach ($trackResults as $trackResult) {
      if (!is_array($trackResult)) {
        continue;
      }

      $trackingNumber = fedexTrackingNumberFromResult($trackResult);
      if ($trackingNumber === '') {
        $trackingNumber = $fallbackTracking;
      }
      if ($trackingNumber === '') {
        continue;
      }

      $out[$trackingNumber] = [
        'summary' => fedexSummarizeTrackResult($trackResult),
        'raw' => $trackResult,
      ];
    }
  }

  return $out;
}

function fedexTrackingNumberFromResult(array $trackResult): string
{
  $info = $trackResult['trackingNumberInfo'] ?? [];
  if (is_array($info)) {
    foreach (['trackingNumber', 'trackingNumberUniqueId', 'trackingNumberUniqueIdentifier'] as $key) {
      $value = trim((string) ($info[$key] ?? ''));
      if ($key === 'trackingNumberUniqueId' && strpos($value, '~') !== false) {
        $parts = explode('~', $value);
        foreach ($parts as $part) {
          if (preg_match('/^\d{8,30}$/', $part)) {
            return $part;
          }
        }
      } elseif ($value !== '') {
        return $value;
      }
    }
  }

  return '';
}

function fedexSummarizeTrackResult(array $trackResult): array
{
  $latestStatus = is_array($trackResult['latestStatusDetail'] ?? null) ? $trackResult['latestStatusDetail'] : [];
  $statusCode = trim((string) (($latestStatus['code'] ?? '') ?: ($latestStatus['derivedCode'] ?? '')));
  $statusDetail = trim((string) (($latestStatus['statusByLocale'] ?? '') ?: ($latestStatus['description'] ?? '')));

  $scanEvents = is_array($trackResult['scanEvents'] ?? null) ? $trackResult['scanEvents'] : [];
  $latestEventAt = null;
  $deliveredAt = null;

  foreach (is_array($trackResult['dateAndTimes'] ?? null) ? $trackResult['dateAndTimes'] : [] as $dateAndTime) {
    if (!is_array($dateAndTime)) {
      continue;
    }
    $type = strtoupper(trim((string) ($dateAndTime['type'] ?? '')));
    if (in_array($type, ['ACTUAL_DELIVERY', 'ACTUAL_DELIVERY_DATE', 'DELIVERY'], true)) {
      $deliveredAt = fedexNormalizeDateTime((string) ($dateAndTime['dateTime'] ?? ''));
      if ($deliveredAt !== null) {
        break;
      }
    }
  }

  foreach ($scanEvents as $event) {
    if (!is_array($event)) {
      continue;
    }

    $eventAt = fedexNormalizeDateTime((string) (($event['date'] ?? '') ?: ($event['dateTime'] ?? '')));
    if ($latestEventAt === null && $eventAt !== null) {
      $latestEventAt = $eventAt;
    }

    $eventType = strtoupper(trim((string) ($event['eventType'] ?? '')));
    $eventDescription = trim((string) (($event['eventDescription'] ?? '') ?: ($event['description'] ?? '')));
    if ($statusCode === '' && $eventType !== '') {
      $statusCode = $eventType;
    }
    if ($statusDetail === '' && $eventDescription !== '') {
      $statusDetail = $eventDescription;
    }

    if ($deliveredAt === null && fedexEventMeansDelivered($eventType, $eventDescription)) {
      $deliveredAt = $eventAt;
    }
  }

  $delivered = fedexEventMeansDelivered($statusCode, $statusDetail) || $deliveredAt !== null;
  if ($delivered && $deliveredAt === null) {
    $deliveredAt = $latestEventAt;
  }

  return [
    'status_code' => $statusCode,
    'status_detail' => $statusDetail,
    'latest_event_at' => $latestEventAt,
    'delivered' => $delivered,
    'delivered_at' => $deliveredAt,
    'error' => fedexResponseMessage($trackResult),
  ];
}

function fedexEventMeansDelivered(string $code, string $description): bool
{
  $code = strtoupper(trim($code));
  if (in_array($code, ['DL', 'DELIVERED'], true)) {
    return true;
  }

  $description = strtolower(trim($description));
  if ($description === '' || strpos($description, 'not delivered') !== false) {
    return false;
  }

  return (bool) preg_match('/\bdelivered\b/', $description);
}
