<?php

declare(strict_types=1);
session_start();

if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return $needle !== '' && substr($haystack, 0, strlen($needle)) === $needle;
  }
}

/**
 * Shared library for importing platform CSVs into orders module.
 * Assumes mysqli connection in $conn from includes/conn.php
 */

function oi_set_utf8mb4(mysqli $conn): void {
  $conn->set_charset('utf8mb4');
}

function oi_now(): string {
  return date('Y-m-d H:i:s');
}

function oi_trim(?string $v): ?string {
  if ($v === null) return null;
  $v = trim($v);
  return ($v === '') ? null : $v;
}

function oi_normalize_headers(array $headers): array {
  // Remove wrapping quotes like '"code"'
  return array_map(function($h){
    $h = trim((string)$h);
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // BOM if present
    $h = trim($h);
    // strip surrounding quotes
    if (strlen($h) >= 2 && (($h[0] === '"' && $h[strlen($h)-1] === '"') || ($h[0] === "'" && $h[strlen($h)-1] === "'"))) {
      $h = substr($h, 1, -1);
    }
    return $h;
  }, $headers);
}

function oi_csv_read_assoc(string $path, string $encodingHint = 'UTF-8'): array {
  if (!is_file($path)) throw new RuntimeException("CSV file not found: $path");

  $fh = fopen($path, 'r');
  if (!$fh) throw new RuntimeException("Cannot open CSV: $path");

  // Autodetekcia oddeľovača — čítame prvý riadok ako raw text a porovnáme
  // počet čiarok vs bodkočiarok. Rieši problém US locale (Excel exportuje `;`
  // keď je systémový desatinný oddeľovač bodka, alebo `,` ked je čiarka).
  $firstLine = fgets($fh);
  if ($firstLine === false) throw new RuntimeException("Empty CSV: $path");
  rewind($fh);

  $commaCount     = substr_count($firstLine, ',');
  $semicolonCount = substr_count($firstLine, ';');
  $delimiter      = $semicolonCount > $commaCount ? ';' : ',';

  $rawHeaders = fgetcsv($fh, 0, $delimiter);
  if (!$rawHeaders) throw new RuntimeException("Empty CSV: $path");
  $headers = oi_normalize_headers($rawHeaders);

  $rows = [];
  $line = 1;
  while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $line++;
    if (count($row) === 1 && trim((string)$row[0]) === '') continue;

    $rec = [];
    foreach ($headers as $i => $h) {
      $rec[$h] = $row[$i] ?? null;
    }
    $rows[] = $rec;
  }
  fclose($fh);
  return $rows;
}

function oi_db_get_id_by_code(mysqli $conn, string $table, string $code): int {
  $sql = "SELECT id FROM $table WHERE code = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('s', $code);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$res) throw new RuntimeException("Missing code in $table: $code");
  return (int)$res['id'];
}

function oi_parse_money(?string $v): ?float {
  $v = oi_trim($v);
  if ($v === null) return null;

  // eBay: "£169.80" ; Shoptet: "963,30"
  $v = str_replace(["\u{00A0}", " "], "", $v);
  $v = preg_replace('/[^\d\.,\-]/', '', $v);
  if ($v === '' || $v === null) return null;

  // If comma is decimal separator (Shoptet)
  if (substr_count($v, ',') === 1 && substr_count($v, '.') === 0) {
    $v = str_replace(',', '.', $v);
  } else {
    // remove thousand separators: "1,234.56" -> "1234.56"
    if (substr_count($v, ',') > 0 && substr_count($v, '.') === 1) {
      $v = str_replace(',', '', $v);
    }
  }

  return is_numeric($v) ? (float)$v : null;
}

function oi_parse_date_any(?string $v): ?string {
  $v = oi_trim($v);
  if ($v === null) return null;

  // Handles:
  // - eBay: "20.02.2026"
  // - mx:   "2026-02-14"
  // - shoptet: "2026-02-06 17:55:30"
  $v = trim($v);

  // dd.mm.yyyy
  if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $v)) {
    $dt = DateTime::createFromFormat('d.m.Y', $v);
    return $dt ? $dt->format('Y-m-d 00:00:00') : null;
  }

  // yyyy-mm-dd
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
    return $v . ' 00:00:00';
  }

  // yyyy-mm-dd hh:mm:ss
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $v)) {
    return $v;
  }

  $ts = strtotime($v);
  if ($ts === false) return null;
  return date('Y-m-d H:i:s', $ts);
}

/**
 * Category parsing
 * Rules:
 * - G/T/M => GRAPHICS
 * - P => PLASTICS
 * - S => SEATCOVER
 * - F => FITTING
 * - combinations allowed: "GFP_..." or "GFP"
 */
function oi_parse_category_letters(?string $maybeCode, ?string $fallbackText): array {
  $text = oi_trim($maybeCode);

  $prefix = null;
  if ($text !== null) {
    $text = preg_split('/\|/', $text)[0] ?? $text;
    $text = trim($text);

    if (preg_match('/^\s*([A-Z]{1,6})\s*[_-]/', $text, $m)) {
      $prefix = $m[1];
    } elseif (preg_match('/^\s*([A-Z]{1,6})\s*$/', $text, $m)) {
      $prefix = $m[1];
    }
  }

  if (!$prefix) {
    // fallback heuristic (lightweight)
    $t = mb_strtolower((string)$fallbackText);
    $letters = [];
    if (str_contains($t, 'graphic') || str_contains($t, 'decal') || str_contains($t, 'sticker')) $letters[] = 'G';
    if (str_contains($t, 'plastic')) $letters[] = 'P';
    if (str_contains($t, 'seat') || str_contains($t, 'cover')) $letters[] = 'S';
    if (str_contains($t, 'fitting') || str_contains($t, 'install') || str_contains($t, 'application')) $letters[] = 'F';
    return array_values(array_unique($letters));
  }

  $letters = array_unique(str_split($prefix));
  $out = [];
  foreach ($letters as $ch) {
    if ($ch === 'T' || $ch === 'M') $ch = 'G';
    if (in_array($ch, ['G','P','S','F'], true)) $out[] = $ch;
  }
  return array_values(array_unique($out));
}

function oi_letter_to_category_id(array $catIds, string $letter): int {
  switch ($letter) {
    case 'G':
      return $catIds['GRAPHICS'];
    case 'P':
      return $catIds['PLASTICS'];
    case 'S':
      return $catIds['SEATCOVER'];
    case 'F':
      return $catIds['FITTING'];
    default:
      throw new RuntimeException("Unknown category letter: " . $letter);
  }
}

function oi_upsert_customer(mysqli $conn, ?string $name, ?string $email, ?string $phone): ?int {
  $name = oi_trim($name);
  $email = oi_trim($email);
  $phone = oi_trim($phone);

  if ($name === null && $email === null && $phone === null) return null;

  if ($email !== null) {
    $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($res) return (int)$res['id'];
  }

  $stmt = $conn->prepare("INSERT INTO customers (name, email, phone) VALUES (?,?,?)");
  $stmt->bind_param('sss', $name, $email, $phone);
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

/**
 * Create or update order header. Returns internal orders.id
 */
function oi_upsert_order(mysqli $conn, int $sourceId, string $externalOrderId, array $data): int {
  $sql = "SELECT id FROM orders WHERE source_id=? AND external_order_id=? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('is', $sourceId, $externalOrderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $order_number = $data['order_number'] ?? null;
  $order_date = $data['order_date'] ?? null;
  $currency = $data['currency'] ?? null;
  $total = $data['total'] ?? null;
  $payment_method = $data['payment_method'] ?? null;
  $shipping_method = $data['shipping_method'] ?? null;
  $note = $data['note'] ?? null;
  $source_meta = $data['source_meta'] ?? null; // already json string or null
  $customer_id = $data['customer_id'] ?? null;

  if ($row) {
    $id = (int)$row['id'];
    // Keep existing values unless new one is non-null
    $upd = $conn->prepare("
      UPDATE orders
      SET order_number = COALESCE(order_number, ?),
          order_date = COALESCE(order_date, ?),
          currency = COALESCE(currency, ?),
          total = COALESCE(total, ?),
          payment_method = COALESCE(payment_method, ?),
          shipping_method = COALESCE(shipping_method, ?),
          note = COALESCE(note, ?),
          source_meta = COALESCE(source_meta, ?),
          customer_id = COALESCE(customer_id, ?)
      WHERE id = ?
    ");
    $upd->bind_param('sssdssssii', $order_number, $order_date, $currency, $total, $payment_method, $shipping_method, $note, $source_meta, $customer_id, $id);
    $upd->execute();
    $upd->close();
    return $id;
  }

  $imported_at = oi_now();
  $ins = $conn->prepare("
    INSERT INTO orders
      (source_id, external_order_id, order_number, imported_at, order_date, status,
       currency, total, payment_method, shipping_method, note, source_meta, customer_id)
    VALUES
      (?, ?, ?, ?, ?, 'NEW', ?, ?, ?, ?, ?, ?, ?)
  ");
  // total is double; customer_id is int nullable
  $ins->bind_param(
    'issssss d sss s i',
    $sourceId, $externalOrderId, $order_number, $imported_at, $order_date,
    $currency, $total, $payment_method, $shipping_method, $note, $source_meta, $customer_id
  );
  // mysqli bind typing is picky; easiest is to cast total to float or null and bind as string.
  // We'll instead bind total as string:
  $ins->close(); // We'll handle inserts per importer (simpler) if needed.

  throw new RuntimeException("oi_upsert_order: use importer-specific insert/update (mysqli typing).");
}

/**
 * Upsert address snapshot (BILLING/SHIPPING).
 */
function oi_upsert_address(mysqli $conn, int $orderId, string $type, array $a): void {
  $type = strtoupper($type);
  $name = oi_trim($a['name'] ?? null);
  $company = oi_trim($a['company'] ?? null);
  $company_id = oi_trim($a['company_id'] ?? null);
  $street = oi_trim($a['street'] ?? null);
  $city = oi_trim($a['city'] ?? null);
  $zip = oi_trim($a['zip'] ?? null);
  $country = oi_trim($a['country'] ?? null);
  $email = oi_trim($a['email'] ?? null);
  $phone = oi_trim($a['phone'] ?? null);

  $stmt = $conn->prepare("SELECT id FROM order_addresses WHERE order_id=? AND type=? LIMIT 1");
  $stmt->bind_param('is', $orderId, $type);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($row) {
    $upd = $conn->prepare("
      UPDATE order_addresses
      SET name=?, company=?, company_id=?, street=?, city=?, zip=?, country=?, email=?, phone=?
      WHERE order_id=? AND type=?
    ");
    $upd->bind_param('sssssssssis', $name, $company, $company_id, $street, $city, $zip, $country, $email, $phone, $orderId, $type);
    $upd->execute();
    $upd->close();
    return;
  }

  $ins = $conn->prepare("
    INSERT INTO order_addresses (order_id, type, name, company, company_id, street, city, zip, country, email, phone)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
  ");
  $ins->bind_param('issssssssss', $orderId, $type, $name, $company, $company_id, $street, $city, $zip, $country, $email, $phone);
  $ins->execute();
  $ins->close();
}

function oi_delete_items_for_order(mysqli $conn, int $orderId): void {
  // Remove item assignments first, otherwise FK fk_oia_item blocks item refresh
  // during add/update imports.
  $stmt = $conn->prepare("
    DELETE oia
    FROM order_item_assignments oia
    JOIN order_items oi ON oi.id = oia.item_id
    WHERE oi.order_id = ?
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $stmt->close();

  // Cleanup historical item statuses for the items that are about to be replaced.
  $stmt = $conn->prepare("
    DELETE ois
    FROM order_item_statuses ois
    JOIN order_items oi ON oi.id = ois.order_item_id
    WHERE oi.order_id = ?
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $stmt->close();

  // delete item categories first
  $stmt = $conn->prepare("
    DELETE oic
    FROM order_item_categories oic
    JOIN order_items oi ON oi.id = oic.item_id
    WHERE oi.order_id = ?
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare("DELETE FROM order_items WHERE order_id=?");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $stmt->close();
}

function oi_get_order_reimport_lock_info(mysqli $conn, int $orderId): array {
  $assignmentCount = 0;
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM order_item_assignments oia
    JOIN order_items oi ON oi.id = oia.item_id
    WHERE oi.order_id = ?
      AND oi.deleted_at IS NULL
      AND oia.removed_at IS NULL
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) {
    $assignmentCount = (int) ($row['cnt'] ?? 0);
  }

  $nonNewStatusCount = 0;
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM order_items
    WHERE order_id = ?
      AND deleted_at IS NULL
      AND status IS NOT NULL
      AND TRIM(status) <> ''
      AND UPPER(TRIM(status)) <> 'NEW'
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) {
    $nonNewStatusCount = (int) ($row['cnt'] ?? 0);
  }

  $statusHistoryCount = 0;
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM order_item_statuses ois
    JOIN order_items oi ON oi.id = ois.order_item_id
    WHERE oi.order_id = ?
      AND oi.deleted_at IS NULL
  ");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) {
    $statusHistoryCount = (int) ($row['cnt'] ?? 0);
  }

  $reasons = [];
  if ($assignmentCount > 0) {
    $reasons[] = 'assignments';
  }
  if ($nonNewStatusCount > 0) {
    $reasons[] = 'item_status';
  }
  if ($statusHistoryCount > 0) {
    $reasons[] = 'status_history';
  }

  return [
    'locked' => !empty($reasons),
    'assignment_count' => $assignmentCount,
    'non_new_status_count' => $nonNewStatusCount,
    'status_history_count' => $statusHistoryCount,
    'reasons' => $reasons,
  ];
}

function oi_insert_item(mysqli $conn, int $orderId, ?int $lineNo, ?string $sku, ?string $title, ?string $customLabel, int $qty, ?string $optionsJson): int {
  $sku = oi_trim($sku);
  $title = oi_trim($title);
  $customLabel = oi_trim($customLabel);

  $stmt = $conn->prepare("
    INSERT INTO order_items (order_id, line_no, sku, title, custom_label, qty, options_json)
    VALUES (?,?,?,?,?,?,?)
  ");
  $stmt->bind_param('iisssis', $orderId, $lineNo, $sku, $title, $customLabel, $qty, $optionsJson);
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

function oi_add_item_categories(mysqli $conn, int $itemId, array $categoryIds): void {
  $stmt = $conn->prepare("INSERT IGNORE INTO order_item_categories (item_id, category_id) VALUES (?,?)");
  foreach ($categoryIds as $cid) {
    $stmt->bind_param('ii', $itemId, $cid);
    $stmt->execute();
  }
  $stmt->close();
}

function oi_refresh_order_categories(mysqli $conn, int $orderId): void {
  $stmt = $conn->prepare("DELETE FROM order_categories WHERE order_id=?");
  $stmt->bind_param('i', $orderId);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare("
    INSERT INTO order_categories (order_id, category_id)
    SELECT ?, oic.category_id
    FROM order_items oi
    JOIN order_item_categories oic ON oic.item_id = oi.id
    WHERE oi.order_id = ?
    GROUP BY oic.category_id
  ");
  $stmt->bind_param('ii', $orderId, $orderId);
  $stmt->execute();
  $stmt->close();
}

function oi_upsert_order_header_mysqli(mysqli $conn, int $sourceId, string $externalOrderId, array $data): int {
  // manual mysqli-friendly upsert
  $stmt = $conn->prepare("SELECT id FROM orders WHERE source_id=? AND external_order_id=? LIMIT 1");
  $stmt->bind_param('is', $sourceId, $externalOrderId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $order_number = oi_trim($data['order_number'] ?? null);
  $order_date = oi_trim($data['order_date'] ?? null);
  $currency = oi_trim($data['currency'] ?? null);
  $total = $data['total'] ?? null; // float|null
  $payment_method = oi_trim($data['payment_method'] ?? null);
  $shipping_method = oi_trim($data['shipping_method'] ?? null);
  $note = oi_trim($data['note'] ?? null);
  $source_meta = $data['source_meta_json'] ?? null; // string|null
  $customer_id = $data['customer_id'] ?? null; // int|null
  $initial_status = oi_trim($data['initial_status'] ?? null) ?? 'NEW';

  if ($row) {
    $id = (int)$row['id'];
    $upd = $conn->prepare("
      UPDATE orders
      SET order_number = COALESCE(order_number, ?),
          order_date = COALESCE(order_date, ?),
          currency = COALESCE(currency, ?),
          total = COALESCE(total, ?),
          payment_method = COALESCE(payment_method, ?),
          shipping_method = COALESCE(shipping_method, ?),
          note = COALESCE(note, ?),
          source_meta = COALESCE(?, source_meta),
          customer_id = COALESCE(customer_id, ?)
      WHERE id = ?
    ");
    // total as double, customer_id as int nullable
    $upd->bind_param('sssdssssii', $order_number, $order_date, $currency, $total, $payment_method, $shipping_method, $note, $source_meta, $customer_id, $id);
    $upd->execute();
    $upd->close();
    return $id;
  }

  $imported_at = oi_now();
  $ins = $conn->prepare("
    INSERT INTO orders
      (source_id, external_order_id, order_number, imported_at, order_date, status,
       currency, total, payment_method, shipping_method, note, source_meta, customer_id)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $ins->bind_param('issssssdssssi', $sourceId, $externalOrderId, $order_number, $imported_at, $order_date, $initial_status, $currency, $total, $payment_method, $shipping_method, $note, $source_meta, $customer_id);

  $ins->execute();
  $id = (int)$ins->insert_id;
  $ins->close();
  return $id;
}
?>