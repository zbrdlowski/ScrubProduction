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

$deptCode = strtoupper(trim((string)($_GET['dept_code'] ?? '')));

$deptPositionMap = [
  'GRAPHICS' => 2,
  'PLASTICS' => 6,
  'SEATCOVER' => 8,
  'FITTING' => 9,
];

$positionId = $deptPositionMap[$deptCode] ?? 0;

$stmt = $conn->prepare("SELECT id, firstname, lastname
  FROM employees
  WHERE firstname LIKE ?
     OR lastname LIKE ?
     OR CONCAT(firstname, ' ', lastname) LIKE ?
  ORDER BY firstname, lastname
  LIMIT 20
");

$sql = "
  SELECT id, firstname, lastname
  FROM employees
  WHERE active = 'Active'
    AND (
      firstname LIKE ?
      OR lastname LIKE ?
      OR CONCAT(firstname, ' ', lastname) LIKE ?
    )
";

$params = [$like, $like, $like];
$types = 'sss';

if ($positionId > 0) {
  $sql .= " AND position_id = ? ";
  $params[] = $positionId;
  $types .= 'i';
}

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