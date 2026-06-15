<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

function safeBaseName(string $name): string {
  $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'photo';
  $name = trim($name, '._-');
  return $name !== '' ? $name : 'photo';
}

function imageCreateFromMime(string $path, string $mime) {
  switch ($mime) {
    case 'image/jpeg': return imagecreatefromjpeg($path);
    case 'image/png': return imagecreatefrompng($path);
    case 'image/webp': return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false;
    case 'image/gif': return imagecreatefromgif($path);
    default: return false;
  }
}

function saveResizedImage(string $tmpPath, string $destPath, string $mime, int $maxSide = 1500): array {
  $info = @getimagesize($tmpPath);
  if (!$info || empty($info[0]) || empty($info[1])) {
    throw new RuntimeException('Invalid image');
  }

  $srcW = (int) $info[0];
  $srcH = (int) $info[1];
  $scale = min(1.0, $maxSide / max($srcW, $srcH));
  $dstW = max(1, (int) round($srcW * $scale));
  $dstH = max(1, (int) round($srcH * $scale));

  $src = imageCreateFromMime($tmpPath, $mime);
  if (!$src) {
    throw new RuntimeException('Unsupported image type');
  }

  $dst = imagecreatetruecolor($dstW, $dstH);
  if ($mime === 'image/png' || $mime === 'image/webp') {
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
  }

  imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

  $ok = false;
  if ($mime === 'image/png') {
    $ok = imagepng($dst, $destPath, 6);
  } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
    $ok = imagewebp($dst, $destPath, 86);
  } else {
    // GIF aj JPEG ukladáme ako JPEG kvôli veľkosti a konzistentnému preview.
    $ok = imagejpeg($dst, $destPath, 86);
  }

  imagedestroy($src);
  imagedestroy($dst);

  if (!$ok) {
    throw new RuntimeException('Could not save resized image');
  }

  return [$dstW, $dstH, filesize($destPath) ?: 0];
}

if (!isset($_SESSION['permission']) || (int) $_SESSION['permission'] < 300) {
  out(403, ['ok' => false, 'error' => 'Forbidden']);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
  out(400, ['ok' => false, 'error' => 'Invalid order_id']);
}

$base = dirname(__DIR__, 2);
$connFile = $base . '/includes/conn.php';
if (!is_file($connFile)) {
  out(500, ['ok' => false, 'error' => 'conn.php not found']);
}
require_once $connFile;

$check = $conn->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
$check->bind_param('i', $orderId);
$check->execute();
$exists = (bool) $check->get_result()->fetch_row();
$check->close();
if (!$exists) {
  out(404, ['ok' => false, 'error' => 'Order not found']);
}

if (empty($_FILES['photos']) || !is_array($_FILES['photos']['tmp_name'])) {
  out(400, ['ok' => false, 'error' => 'No photos uploaded']);
}

$uploadRoot = $base . '/uploads/order_photos/' . $orderId;
$publicRoot = 'uploads/order_photos/' . $orderId;
if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0775, true)) {
  out(500, ['ok' => false, 'error' => 'Could not create upload directory']);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$allowed = [
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/webp' => 'webp',
  'image/gif' => 'jpg',
];

$createdBy = (int) ($_SESSION['user_id'] ?? 0);
$items = [];
$count = count($_FILES['photos']['tmp_name']);

for ($i = 0; $i < $count; $i++) {
  if ((int) ($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    continue;
  }

  $tmp = (string) $_FILES['photos']['tmp_name'][$i];
  if (!is_uploaded_file($tmp)) {
    continue;
  }

  $mime = $finfo->file($tmp) ?: '';
  if (!isset($allowed[$mime])) {
    continue;
  }

  $original = safeBaseName((string) ($_FILES['photos']['name'][$i] ?? 'photo'));
  $ext = $allowed[$mime];
  $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  $dest = $uploadRoot . '/' . $storedName;
  $publicPath = $publicRoot . '/' . $storedName;

  try {
    [$width, $height, $size] = saveResizedImage($tmp, $dest, $mime, 1500);
  } catch (Throwable $e) {
    continue;
  }

  $storedMime = ($mime === 'image/gif') ? 'image/jpeg' : $mime;
  $stmt = $conn->prepare('
    INSERT INTO order_photos
      (order_id, file_name, original_name, file_path, mime_type, file_size, width, height, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ');
  if (!$stmt) {
    @unlink($dest);
    out(500, ['ok' => false, 'error' => 'SQL prepare failed: ' . mysqli_error($conn)]);
  }
  $stmt->bind_param('issssiiii', $orderId, $storedName, $original, $publicPath, $storedMime, $size, $width, $height, $createdBy);
  $stmt->execute();
  $photoId = $stmt->insert_id;
  $stmt->close();

  $items[] = [
    'id' => $photoId,
    'url' => $publicPath,
    'original_name' => $original,
    'width' => $width,
    'height' => $height,
  ];
}

out(200, ['ok' => true, 'items' => $items]);
