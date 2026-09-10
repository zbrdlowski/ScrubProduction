<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function customOrdersImportFail(string $message): void
{
  customOrdersFlash('danger', $message);
  customOrdersRedirect();
}

function customOrdersImportDecodeMimeHeader(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  if (function_exists('iconv_mime_decode')) {
    $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if (is_string($decoded) && $decoded !== '') {
      return $decoded;
    }
  }
  if (function_exists('mb_decode_mimeheader')) {
    $decoded = @mb_decode_mimeheader($value);
    if (is_string($decoded) && $decoded !== '') {
      return $decoded;
    }
  }
  return $value;
}

function customOrdersImportSplitMessage(string $raw): array
{
  $parts = preg_split("/\r\n\r\n|\n\n|\r\r/", $raw, 2);
  if (!is_array($parts) || count($parts) < 2) {
    return ['', $raw];
  }
  return [$parts[0], $parts[1]];
}

function customOrdersImportStartsWith(string $haystack, string $needle): bool
{
  return $needle === '' || substr($haystack, 0, strlen($needle)) === $needle;
}

function customOrdersImportContains(string $haystack, string $needle): bool
{
  return $needle === '' || strpos($haystack, $needle) !== false;
}

function customOrdersImportEndsWith(string $haystack, string $needle): bool
{
  if ($needle === '') {
    return true;
  }
  return substr($haystack, -strlen($needle)) === $needle;
}

function customOrdersImportSubstr(string $value, int $start, int $length): string
{
  if (function_exists('mb_substr')) {
    return mb_substr($value, $start, $length);
  }
  return substr($value, $start, $length);
}
function customOrdersImportParseHeaders(string $headerBlock): array
{
  $headers = [];
  $lines = preg_split("/\r\n|\n|\r/", $headerBlock) ?: [];
  $unfolded = [];
  foreach ($lines as $line) {
    if (preg_match('/^\s+/', $line) && $unfolded) {
      $unfolded[count($unfolded) - 1] .= ' ' . trim($line);
      continue;
    }
    $unfolded[] = rtrim($line);
  }
  foreach ($unfolded as $line) {
    if (!preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
      continue;
    }
    $headers[strtolower(trim($m[1]))] = trim($m[2]);
  }
  return $headers;
}

function customOrdersImportHeaderParam(string $headerValue, string $name): string
{
  if (!preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|([^;]+))/i', $headerValue, $m)) {
    return '';
  }
  return trim((string) ($m[2] !== '' ? $m[2] : $m[3]));
}

function customOrdersImportContentType(array $headers): string
{
  $contentType = strtolower(trim((string) ($headers['content-type'] ?? 'text/plain')));
  $semi = strpos($contentType, ';');
  if ($semi !== false) {
    $contentType = substr($contentType, 0, $semi);
  }
  return $contentType !== '' ? $contentType : 'text/plain';
}

function customOrdersImportDecodePartBody(string $body, array $headers): string
{
  $encoding = strtolower(trim((string) ($headers['content-transfer-encoding'] ?? '')));
  if ($encoding === 'base64') {
    $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
    if (is_string($decoded)) {
      $body = $decoded;
    }
  } elseif ($encoding === 'quoted-printable') {
    $body = quoted_printable_decode($body);
  }

  $charset = customOrdersImportHeaderParam((string) ($headers['content-type'] ?? ''), 'charset');
  if ($charset !== '' && strcasecmp($charset, 'UTF-8') !== 0 && function_exists('iconv')) {
    $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
    if (is_string($converted)) {
      $body = $converted;
    }
  }

  return $body;
}

function customOrdersImportCollectTextParts(string $raw, array &$parts, int $depth = 0): void
{
  if ($depth > 12) {
    return;
  }

  [$headerBlock, $body] = customOrdersImportSplitMessage($raw);
  $headers = customOrdersImportParseHeaders($headerBlock);
  $contentType = customOrdersImportContentType($headers);
  $disposition = strtolower((string) ($headers['content-disposition'] ?? ''));

  if (customOrdersImportStartsWith($contentType, 'multipart/')) {
    $boundary = customOrdersImportHeaderParam((string) ($headers['content-type'] ?? ''), 'boundary');
    if ($boundary === '') {
      return;
    }
    $chunks = explode('--' . $boundary, $body);
    foreach ($chunks as $index => $chunk) {
      if ($index === 0) {
        continue;
      }
      $chunk = ltrim($chunk, "\r\n");
      if (customOrdersImportStartsWith($chunk, '--')) {
        break;
      }
      $chunk = preg_replace("/\r\n--$/", '', $chunk) ?? $chunk;
      customOrdersImportCollectTextParts($chunk, $parts, $depth + 1);
    }
    return;
  }

  if ($contentType === 'message/rfc822') {
    customOrdersImportCollectTextParts(customOrdersImportDecodePartBody($body, $headers), $parts, $depth + 1);
    return;
  }

  if (($contentType === 'text/plain' || $contentType === 'text/html') && strpos($disposition, 'attachment') === false) {
    $parts[] = [
      'content_type' => $contentType,
      'body' => customOrdersImportDecodePartBody($body, $headers),
    ];
  }
}

function customOrdersImportHtmlToText(string $html): string
{
  $html = preg_replace('/<(br|p|div|li|tr|td|th|h[1-6])\b[^>]*>/i', "\n", $html) ?? $html;
  $text = strip_tags($html);
  return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function customOrdersImportCleanText(string $text): string
{
  $text = str_replace(["\xC2\xA0", "\t"], [' ', ' '], $text);
  $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
  $lines = preg_split('/\n/', $text) ?: [];
  $clean = [];
  foreach ($lines as $line) {
    $line = trim(preg_replace('/[ ]{2,}/', ' ', $line) ?? $line);
    if ($line === '' && (!$clean || end($clean) === '')) {
      continue;
    }
    $clean[] = $line;
  }
  return trim(implode("\n", $clean));
}

function customOrdersImportEmailText(string $raw): array
{
  [$headerBlock] = customOrdersImportSplitMessage($raw);
  $headers = customOrdersImportParseHeaders($headerBlock);
  $parts = [];
  customOrdersImportCollectTextParts($raw, $parts);

  $plain = [];
  $html = [];
  foreach ($parts as $part) {
    if (($part['content_type'] ?? '') === 'text/plain') {
      $plain[] = (string) ($part['body'] ?? '');
    } elseif (($part['content_type'] ?? '') === 'text/html') {
      $html[] = customOrdersImportHtmlToText((string) ($part['body'] ?? ''));
    }
  }

  $text = $plain ? implode("\n\n", $plain) : implode("\n\n", $html);
  if (trim($text) === '') {
    [, $body] = customOrdersImportSplitMessage($raw);
    $text = $body;
  }

  return [
    'headers' => $headers,
    'text' => customOrdersImportCleanText($text),
  ];
}

function customOrdersImportAsciiFold(string $value): string
{
  $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
  return is_string($converted) ? $converted : $value;
}

function customOrdersImportNormalizeKey(string $value): string
{
  $value = strtolower(customOrdersImportAsciiFold($value));
  $value = str_replace('&', ' and ', $value);
  $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
  return trim($value, '_');
}

function customOrdersImportCleanLabel(string $label): string
{
  $label = trim($label);
  $label = preg_replace('/^\*+|\*+$/', '', $label) ?? $label;
  return trim(rtrim($label, ':'));
}

function customOrdersImportCleanValue(string $value): string
{
  $value = trim($value);
  $value = preg_replace('/^\*+\s*/', '', $value) ?? $value;
  return trim($value);
}

function customOrdersImportLooksLikeSectionHeading(string $line): bool
{
  $line = trim($line);
  if ($line === '') {
    return false;
  }
  $normalized = customOrdersImportNormalizeKey($line);
  $knownHeadings = [
    'custom_design_form',
    'forwarded_message',
    'motorcycle_information',
    'design_information',
    'material_options',
    'other_products',
    'payment_information',
    'uploaded_files',
  ];
  if (in_array($normalized, $knownHeadings, true)) {
    return true;
  }
  $folded = trim(customOrdersImportAsciiFold($line));
  return strlen($folded) >= 4
    && strlen($folded) <= 80
    && strtoupper($folded) === $folded
    && preg_match('/[A-Z]/', $folded) === 1
    && preg_match('/@|https?:\/\/|\d{3,}/i', $folded) !== 1;
}

function customOrdersImportAliases(): array
{
  return [
    'customer_name' => ['fullname', 'full_name', 'your_name', 'customer_name', 'customer_fullname', 'name', 'meno', 'jmeno', 'jmeno_a_prijmeni', 'meno_a_priezvisko', 'kontaktni_osoba'],
    'customer_email' => ['email', 'e_mail', 'mail', 'your_email', 'kontakt_email', 'emailova_adresa'],
    'customer_phone' => ['phone', 'telefon', 'tel', 'telephone', 'mobile', 'mobil', 'whatsapp', 'contact_phone'],
    'customer_country' => ['country', 'krajina', 'stat', 'zeme', 'zem', 'state_country'],
    'shipping_street' => ['street', 'address', 'shipping_address', 'adresa', 'ulice', 'ulica'],
    'shipping_city' => ['city', 'mesto', 'obec'],
    'shipping_zip' => ['zip', 'postal_code', 'postcode', 'psc', 'ps_c'],
    'shipping_company' => ['company', 'firma', 'company_name', 'nazov_firmy'],
    'shipping_company_id' => ['company_id', 'ico', 'ic', 'vat', 'dic', 'ic_dph', 'tax_id'],
    'bike_brand' => ['bike_brand', 'brand', 'make', 'manufacturer', 'znacka', 'znacka_motorky', 'motorka_znacka'],
    'bike_model' => ['bike_model', 'model', 'model_motorky', 'motorcycle_model'],
    'bike_year' => ['bike_year', 'year', 'rok', 'rocnik', 'rok_vyroby', 'rocnik_motorky'],
    'bike_details' => ['bike', 'motorcycle', 'motorka', 'motocykel', 'motocykl', 'bike_details', 'motorcycle_details', 'typ_motorky', 'make_model_year'],
    'rider_name' => ['rider_name', 'name_on_graphics', 'meno_jazdca', 'meno_na_grafike', 'jmeno_jezdce', 'jmeno_na_grafice'],
    'rider_number' => ['rider_number', 'race_number', 'number', 'cislo', 'startovne_cislo', 'startovni_cislo'],
    'graphics_brief' => ['message', 'sprava', 'zprava', 'note', 'notes', 'poznamka', 'design', 'design_request', 'graphics_brief', 'description', 'popis', 'poziadavka', 'pozadavek', 'custom_design'],
    'reference_urls' => ['reference', 'references', 'reference_url', 'reference_urls', 'inspiration', 'image', 'images', 'link', 'links', 'url'],
  ];
}

function customOrdersImportCanonicalField(string $label): string
{
  $normalized = customOrdersImportNormalizeKey($label);
  foreach (customOrdersImportAliases() as $field => $aliases) {
    if (in_array($normalized, $aliases, true)) {
      return $field;
    }
  }
  if (customOrdersImportContains($normalized, 'email')) return 'customer_email';
  if (customOrdersImportContains($normalized, 'phone') || customOrdersImportContains($normalized, 'telefon')) return 'customer_phone';
  if (customOrdersImportContains($normalized, 'country') || customOrdersImportContains($normalized, 'krajina')) return 'customer_country';
  if (customOrdersImportContains($normalized, 'company_id')) return 'shipping_company_id';
  if (customOrdersImportContains($normalized, 'company') || customOrdersImportContains($normalized, 'firma')) return 'shipping_company';
  if (customOrdersImportContains($normalized, 'rider') && customOrdersImportContains($normalized, 'number')) return 'rider_number';
  if (customOrdersImportContains($normalized, 'rider') && customOrdersImportContains($normalized, 'name')) return 'rider_name';
  if (customOrdersImportContains($normalized, 'brand') || customOrdersImportContains($normalized, 'znacka')) return 'bike_brand';
  if (customOrdersImportContains($normalized, 'model')) return 'bike_model';
  if (customOrdersImportContains($normalized, 'year') || customOrdersImportContains($normalized, 'rocnik')) return 'bike_year';
  if (customOrdersImportContains($normalized, 'bike') || customOrdersImportContains($normalized, 'motorcycle') || customOrdersImportContains($normalized, 'motorka')) return 'bike_details';
  if (customOrdersImportContains($normalized, 'message') || customOrdersImportContains($normalized, 'design') || customOrdersImportContains($normalized, 'sprava') || customOrdersImportContains($normalized, 'zprava')) return 'graphics_brief';
  return '';
}

function customOrdersImportExtractLabelValues(string $text): array
{
  $lines = preg_split('/\n/', customOrdersImportCleanText($text)) ?: [];
  $pairs = [];
  $currentLabel = '';

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
      $currentLabel = '';
      continue;
    }
    if (preg_match('/^([^:]{1,90}):\s*(.*)$/u', $line, $m)) {
      $currentLabel = customOrdersImportCleanLabel((string) $m[1]);
      if ($currentLabel === '') {
        continue;
      }
      $pairs[$currentLabel] = customOrdersImportCleanValue((string) $m[2]);
      continue;
    }
    if (customOrdersImportLooksLikeSectionHeading($line)) {
      $currentLabel = '';
      continue;
    }
    if ($currentLabel !== '') {
      $pairs[$currentLabel] = trim((string) ($pairs[$currentLabel] ?? '') . "\n" . $line);
    }
  }

  $knownLabels = [];
  foreach (customOrdersImportAliases() as $aliases) {
    foreach ($aliases as $alias) {
      $knownLabels[$alias] = true;
    }
  }
  for ($i = 0, $count = count($lines); $i < $count - 1; $i++) {
    $label = customOrdersImportCleanLabel((string) $lines[$i]);
    $value = customOrdersImportCleanValue((string) $lines[$i + 1]);
    if ($label === '' || $value === '' || customOrdersImportLooksLikeSectionHeading($label)) {
      continue;
    }
    $normalized = customOrdersImportNormalizeKey($label);
    if (isset($knownLabels[$normalized]) && !isset($pairs[$label])) {
      $pairs[$label] = $value;
    }
  }

  return $pairs;
}

function customOrdersImportFirstEmail(string $text): string
{
  if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches)) {
    return '';
  }
  foreach ($matches[0] as $email) {
    $email = strtolower(trim($email));
    if ($email !== '' && !customOrdersImportEndsWith($email, '@scrubdesignz.com') && $email !== 'zbrdlowski@gmail.com') {
      return $email;
    }
  }
  return '';
}

function customOrdersImportFirstPhone(string $text): string
{
  if (!preg_match_all('/(?:\+\d{1,4}[\s.-]?)?(?:\(?\d{2,5}\)?[\s.-]?){2,6}\d{2,5}/', $text, $matches)) {
    return '';
  }
  foreach ($matches[0] as $phone) {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) >= 8) {
      return trim($phone);
    }
  }
  return '';
}

function customOrdersImportSubjectName(string $subject): string
{
  $subject = customOrdersImportDecodeMimeHeader($subject);
  if (preg_match('/-\s*([^-]+)\s*$/u', $subject, $m)) {
    return trim($m[1]);
  }
  return '';
}

function customOrdersImportParseBikeDetails(array &$data): void
{
  $details = trim((string) ($data['bike_details'] ?? ''));
  if ($details === '') {
    return;
  }
  if (($data['bike_year'] ?? '') === '' && preg_match('/\b((?:19|20)\d{2})\b/', $details, $m)) {
    $data['bike_year'] = $m[1];
  }
  if (($data['bike_brand'] ?? '') === '' && preg_match('/\b(KTM|Husqvarna|Husky|Yamaha|Honda|Kawasaki|Suzuki|Beta|Gas\s*Gas|Sherco|TM|Ducati|BMW)\b/i', $details, $m)) {
    $brand = preg_replace('/\s+/', ' ', $m[1]) ?? $m[1];
    $data['bike_brand'] = strtoupper($brand) === 'HUSKY' ? 'Husqvarna' : ucwords(strtolower($brand));
  }
}

function customOrdersImportBuildOrderData(string $text, array $headers, string $originalName, string $rawHash): array
{
  $subject = customOrdersImportDecodeMimeHeader((string) ($headers['subject'] ?? ''));
  $from = customOrdersImportDecodeMimeHeader((string) ($headers['from'] ?? ''));
  $messageId = trim((string) ($headers['message-id'] ?? ''));
  $date = trim((string) ($headers['date'] ?? ''));
  $pairs = customOrdersImportExtractLabelValues($text);

  $data = [
    'status' => 'LEAD',
    'complexity_level' => 1,
    'source_channel' => 'Email',
    'social_platform' => '',
    'social_handle' => '',
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'customer_country' => null,
    'bike_brand' => '',
    'bike_model' => '',
    'bike_year' => '',
    'bike_details' => '',
    'rider_name' => '',
    'rider_number' => '',
    'payment_method' => '',
    'shipping_name' => '',
    'shipping_company' => '',
    'shipping_company_id' => '',
    'shipping_street' => '',
    'shipping_city' => '',
    'shipping_zip' => '',
    'shipping_country' => null,
    'shipping_state' => null,
    'shipping_email' => '',
    'shipping_phone' => '',
    'shipping_method' => '',
    'shipping_price' => 0.0,
    'billing_name' => '',
    'billing_company' => '',
    'billing_company_id' => '',
    'billing_street' => '',
    'billing_city' => '',
    'billing_zip' => '',
    'billing_country' => null,
    'billing_state' => null,
    'billing_email' => '',
    'billing_phone' => '',
    'currency' => 'EUR',
    'deposit_revision_limit' => 0,
    'deposit_revision_used' => 0,
    'graphics_brief' => '',
    'customer_notes' => '',
    'internal_notes' => '',
    'bike_photo_urls' => '',
    'reference_urls' => '',
    'last_contact_at' => null,
    'next_followup_at' => null,
    'dead_order_flag' => 0,
  ];

  $unmapped = [];
  foreach ($pairs as $label => $value) {
    $value = trim((string) $value);
    if ($value === '') {
      continue;
    }
    $field = customOrdersImportCanonicalField((string) $label);
    if ($field === '') {
      $unmapped[(string) $label] = $value;
      continue;
    }
    if ($field === 'customer_country') {
      $country = customOrdersNormalizeCountry($value);
      $data['customer_country'] = $country;
      $data['shipping_country'] = $country;
      $data['billing_country'] = $country;
      continue;
    }
    if ($field === 'graphics_brief') {
      $data['graphics_brief'] = trim((string) $data['graphics_brief'] . "\n\n" . $value);
      continue;
    }
    if ($field === 'reference_urls') {
      $data['reference_urls'] = trim((string) $data['reference_urls'] . "\n" . $value);
      continue;
    }
    if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
      $data[$field] = $value;
    }
  }

  if ($data['customer_email'] === '') {
    $data['customer_email'] = customOrdersImportFirstEmail($text);
  }
  if ($data['customer_phone'] === '') {
    $data['customer_phone'] = customOrdersImportFirstPhone($text);
  }
  if ($data['customer_name'] === '') {
    $data['customer_name'] = customOrdersImportSubjectName($subject);
  }

  customOrdersImportParseBikeDetails($data);

  if ($data['shipping_name'] === '') $data['shipping_name'] = $data['customer_name'];
  if ($data['shipping_email'] === '') $data['shipping_email'] = $data['customer_email'];
  if ($data['shipping_phone'] === '') $data['shipping_phone'] = $data['customer_phone'];
  if ($data['shipping_country'] === null) $data['shipping_country'] = $data['customer_country'];

  foreach (['name', 'company', 'company_id', 'street', 'city', 'zip', 'country', 'state', 'email', 'phone'] as $addressField) {
    $data['billing_' . $addressField] = $data['shipping_' . $addressField] ?? '';
  }

  if (!preg_match_all('/https?:\/\/[^\s<>"\']+/i', $text, $urlMatches)) {
    $urlMatches = [[]];
  }
  $urls = array_values(array_unique(array_map(static function ($url): string {
    return rtrim((string) $url, '.,;)');
  }, $urlMatches[0])));
  if ($urls) {
    $data['reference_urls'] = trim($data['reference_urls'] . "\n" . implode("\n", $urls));
  }

  if ($data['graphics_brief'] === '') {
    $data['graphics_brief'] = customOrdersImportSubstr($text, 0, 4000);
  }

  $noteLines = [
    'Imported from custom web contact form email.',
    'Source file: ' . $originalName,
    'Subject: ' . $subject,
    'From: ' . $from,
    'Date: ' . $date,
    'Message-ID: ' . $messageId,
    'SHA-256: ' . $rawHash,
    '',
    'Parsed fields:',
  ];
  foreach ($pairs as $label => $value) {
    $noteLines[] = '- ' . trim((string) $label) . ': ' . trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
  }
  if ($unmapped) {
    $noteLines[] = '';
    $noteLines[] = 'Unmapped fields kept for review:';
    foreach ($unmapped as $label => $value) {
      $noteLines[] = '- ' . $label . ': ' . trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
  }
  $data['internal_notes'] = customOrdersImportSubstr(implode("\n", $noteLines), 0, 12000);

  return [
    'data' => $data,
    'pairs' => $pairs,
    'headers' => [
      'subject' => $subject,
      'from' => $from,
      'date' => $date,
      'message_id' => $messageId,
      'raw_sha256' => $rawHash,
      'source_file' => $originalName,
    ],
  ];
}

function customOrdersImportFindExisting(mysqli $conn, string $rawHash): int
{
  if ($rawHash === '') {
    return 0;
  }
  $needle = '%' . $rawHash . '%';
  $stmt = $conn->prepare("
    SELECT custom_order_id
    FROM custom_order_activity
    WHERE action = 'email_imported'
      AND payload LIKE ?
    ORDER BY id DESC
    LIMIT 1
  ");
  if (!$stmt) {
    return 0;
  }
  $stmt->bind_param('s', $needle);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? (int) ($row['custom_order_id'] ?? 0) : 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  customOrdersImportFail('Invalid request method.');
}

$file = $_FILES['email_file'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  customOrdersImportFail('Upload a .eml file first.');
}

$originalName = trim((string) ($file['name'] ?? 'email.eml'));
$tmpName = (string) ($file['tmp_name'] ?? '');
$size = (int) ($file['size'] ?? 0);
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
  customOrdersImportFail('Uploaded email file is not available.');
}
if ($size <= 0 || $size > 20 * 1024 * 1024) {
  customOrdersImportFail('Email file must be smaller than 20 MB.');
}
if (!preg_match('/\.(eml|txt)$/i', $originalName)) {
  customOrdersImportFail('Please upload an .eml file.');
}

$raw = file_get_contents($tmpName);
if (!is_string($raw) || trim($raw) === '') {
  customOrdersImportFail('Email file is empty.');
}

$rawHash = hash('sha256', $raw);
$existingOrderId = customOrdersImportFindExisting($conn, $rawHash);
if ($existingOrderId > 0) {
  customOrdersFlash('warning', 'This email was already imported. Opening existing lead.');
  customOrdersRedirect($existingOrderId);
}

$parsed = customOrdersImportEmailText($raw);
$built = customOrdersImportBuildOrderData(
  (string) $parsed['text'],
  is_array($parsed['headers']) ? $parsed['headers'] : [],
  $originalName,
  $rawHash
);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$orderId = customOrdersCreateSkeleton($conn, $userId);
$data = $built['data'];
$contactId = customOrdersUpsertContactDirectory($conn, $data);

$availableColumns = customOrdersTableColumns($conn, 'custom_orders');
$updateValues = $data;
$updateValues['contact_directory_id'] = $contactId;
$updateValues['updated_by'] = $userId;

$assignments = [];
$params = [':id' => $orderId];
foreach ($updateValues as $column => $value) {
  if (!isset($availableColumns[$column])) {
    continue;
  }
  $assignments[] = $column . ' = :' . $column;
  $params[':' . $column] = $value;
}

if (!$assignments) {
  customOrdersImportFail('No compatible custom order columns found for email import.');
}

$stmt = $pdo->prepare('UPDATE custom_orders SET ' . implode(",\n      ", $assignments) . ' WHERE id = :id');
if (!$stmt->execute($params)) {
  customOrdersImportFail('Failed to create custom lead from email.');
}

customOrdersLog(
  $conn,
  $orderId,
  'email_imported',
  $userId,
  [
    'source' => 'custom_form_email',
    'headers' => $built['headers'],
    'parsed_fields' => $built['pairs'],
  ],
  'Custom lead imported from email'
);

$displayName = trim((string) ($data['customer_name'] ?? ''));
customOrdersFlash('success', 'Custom lead imported from email' . ($displayName !== '' ? ': ' . $displayName : '') . '.');
customOrdersRedirect($orderId);