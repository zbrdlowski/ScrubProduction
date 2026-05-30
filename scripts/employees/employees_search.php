<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';

function out(array $payload): void {
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(['ok' => false, 'error' => 'Not logged in']);
}

$q = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
  out(['ok' => true, 'items' => []]);
}

$like = '%' . $q . '%';

$sql = "
  SELECT id, firstname, lastname
  FROM employees
  WHERE active = 'Active'
    AND personal_orders = 1
    AND (
      firstname LIKE ?
      OR lastname LIKE ?
      OR CONCAT(firstname, ' ', lastname) LIKE ?
    )
";

$params = [$like, $like, $like];
$types = 'sss';

$sql .= "
  ORDER BY firstname, lastname
  LIMIT 20
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  out(['ok' => false, 'error' => $conn->error]);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$items = [];

while ($e = $res->fetch_assoc()) {
  $name = trim(($e['firstname'] ?? '') . ' ' . ($e['lastname'] ?? ''));

  $items[] = [
    'id' => (int)$e['id'],
    'name' => $name !== '' ? $name : ('Employee #' . (int)$e['id'])
  ];
}

$stmt->close();

out(['ok' => true, 'items' => $items]);
