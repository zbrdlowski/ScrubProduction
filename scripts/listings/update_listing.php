<?php
session_start();
require_once __DIR__ . '/../../includes/conn.php';

header('Content-Type: application/json; charset=utf-8');

$barcode = trim($_POST['barcode'] ?? '');
$field   = trim($_POST['field'] ?? '');
$value   = $_POST['value'] ?? null;

$allowed = ['listed_price','listed_platform'];
if ($barcode === '' || !in_array($field, $allowed, true)) {
  echo json_encode(['ok'=>false,'error'=>'Bad request']);
  exit;
}

if ($field === 'listed_price') {
  $v = trim((string)$value);
  if ($v === '') {
    $val = null;
  } else {
    $v = str_replace(',', '.', $v);
    if (!is_numeric($v)) {
      echo json_encode(['ok'=>false,'error'=>'listed_price must be numeric']);
      exit;
    }
    $val = (float)$v;
  }

  $sql = "INSERT INTO listings (barcode, listed_price) VALUES (?, ?)
          ON DUPLICATE KEY UPDATE listed_price = VALUES(listed_price)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("sd", $barcode, $val);
  $stmt->execute();

  $display = ($val === null) ? '' : number_format($val, 2, '.', '');
  echo json_encode(['ok'=>true,'value_display'=>$display]);
  exit;
}

if ($field === 'listed_platform') {
  $val = trim((string)$value);
  if ($val === '') $val = null;

  $sql = "INSERT INTO listings (barcode, listed_platform) VALUES (?, ?)
          ON DUPLICATE KEY UPDATE listed_platform = VALUES(listed_platform)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $barcode, $val);
  $stmt->execute();

  echo json_encode(['ok'=>true,'value_display'=> ($val ?? '')]);
  exit;
}