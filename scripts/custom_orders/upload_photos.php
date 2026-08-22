<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function customPhotoOut(int $status, array $payload): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

function customPhotoSafeName(string $name): string
{
  $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'photo';
  $name = trim($name, '._-');
  return $name !== '' ? $name : 'photo';
}

function customPhotoImageFromMime(string $path, string $mime)
{
  switch ($mime) {
    case 'image/jpeg': return imagecreatefromjpeg($path);
    case 'image/png': return imagecreatefrompng($path);
    case 'image/webp': return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false;
    case 'image/gif': return imagecreatefromgif($path);
    default: return false;
  }
}

function customPhotoSaveResized(string $sourcePath, string $destinationPath, string $mime, int $maxSide = 1500): array
{
  $info = @getimagesize($sourcePath);
  if (!$info || empty($info[0]) || empty($info[1])) {
    throw new RuntimeException('Invalid image.');
  }
  $sourceWidth = (int) $info[0];
  $sourceHeight = (int) $info[1];
  $scale = min(1.0, $maxSide / max($sourceWidth, $sourceHeight));
  $width = max(1, (int) round($sourceWidth * $scale));
  $height = max(1, (int) round($sourceHeight * $scale));
  $source = customPhotoImageFromMime($sourcePath, $mime);
  if (!$source) {
    throw new RuntimeException('Unsupported image type.');
  }
  $destination = imagecreatetruecolor($width, $height);
  if ($mime === 'image/png' || $mime === 'image/webp') {
    imagealphablending($destination, false);
    imagesavealpha($destination, true);
    $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
    imagefilledrectangle($destination, 0, 0, $width, $height, $transparent);
  }
  imagecopyresampled($destination, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
  if ($mime === 'image/png') {
    $saved = imagepng($destination, $destinationPath, 6);
  } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
    $saved = imagewebp($destination, $destinationPath, 86);
  } else {
    $saved = imagejpeg($destination, $destinationPath, 86);
  }
  imagedestroy($source);
  imagedestroy($destination);
  if (!$saved) {
    throw new RuntimeException('Could not save resized image.');
  }
  return [$width, $height, filesize($destinationPath) ?: 0];
}

$customOrderId = (int) ($_POST['custom_order_id'] ?? 0);
if ($customOrderId <= 0) {
  customPhotoOut(400, ['ok' => false, 'error' => 'Invalid custom order.']);
}
if (!customOrdersTableExists($conn, 'custom_order_photos')) {
  customPhotoOut(500, ['ok' => false, 'error' => 'Custom order photo storage is not available.']);
}

$check = $conn->prepare('SELECT id, production_order_id FROM custom_orders WHERE id = ? LIMIT 1');
$check->bind_param('i', $customOrderId);
$check->execute();
$order = $check->get_result()->fetch_assoc();
$check->close();
if (!$order) {
  customPhotoOut(404, ['ok' => false, 'error' => 'Custom order not found.']);
}
$productionOrderId = (int) ($order['production_order_id'] ?? 0);
if (empty($_FILES['photos']) || !is_array($_FILES['photos']['tmp_name'])) {
  customPhotoOut(400, ['ok' => false, 'error' => 'No photos uploaded.']);
}

$base = dirname(__DIR__, 2);
$uploadRoot = $base . '/uploads/order_photos/custom-' . $customOrderId;
$publicRoot = 'uploads/order_photos/custom-' . $customOrderId;
if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0775, true)) {
  customPhotoOut(500, ['ok' => false, 'error' => 'Could not create upload directory.']);
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'jpg'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$createdBy = (int) ($_SESSION['user_id'] ?? 0);
$items = [];
$count = count($_FILES['photos']['tmp_name']);

for ($i = 0; $i < $count; $i++) {
  if ((int) ($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
  $temporaryPath = (string) $_FILES['photos']['tmp_name'][$i];
  if (!is_uploaded_file($temporaryPath)) continue;
  $mime = $finfo->file($temporaryPath) ?: '';
  if (!isset($allowed[$mime])) continue;
  $originalName = customPhotoSafeName((string) ($_FILES['photos']['name'][$i] ?? 'photo'));
  $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
  $destinationPath = $uploadRoot . '/' . $storedName;
  $publicPath = $publicRoot . '/' . $storedName;
  try {
    [$width, $height, $fileSize] = customPhotoSaveResized($temporaryPath, $destinationPath, $mime, 1500);
  } catch (Throwable $e) {
    continue;
  }
  $storedMime = $mime === 'image/gif' ? 'image/jpeg' : $mime;
  $stmt = $conn->prepare('
    INSERT INTO custom_order_photos
      (custom_order_id, file_name, original_name, file_path, mime_type, file_size, width, height, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ');
  if (!$stmt) {
    @unlink($destinationPath);
    customPhotoOut(500, ['ok' => false, 'error' => 'Photo database record could not be prepared.']);
  }
  $stmt->bind_param('issssiiii', $customOrderId, $storedName, $originalName, $publicPath, $storedMime, $fileSize, $width, $height, $createdBy);
  $stmt->execute();
  $photoId = (int) $stmt->insert_id;
  $stmt->close();

  if ($productionOrderId > 0 && customOrdersTableExists($conn, 'order_photos')) {
    $productionStmt = $conn->prepare('
      INSERT INTO order_photos
        (order_id, file_name, original_name, file_path, mime_type, file_size, width, height, created_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    if (!$productionStmt) {
      customPhotoOut(500, ['ok' => false, 'error' => 'Production photo record could not be prepared.']);
    }
    $productionStmt->bind_param('issssiiii', $productionOrderId, $storedName, $originalName, $publicPath, $storedMime, $fileSize, $width, $height, $createdBy);
    $productionStmt->execute();
    $productionPhotoId = (int) $productionStmt->insert_id;
    $productionStmt->close();
    $linkStmt = $conn->prepare('UPDATE custom_order_photos SET production_photo_id = ?, exported_at = NOW() WHERE id = ?');
    $linkStmt->bind_param('ii', $productionPhotoId, $photoId);
    $linkStmt->execute();
    $linkStmt->close();
  }
  $items[] = ['id' => $photoId, 'url' => $publicPath, 'original_name' => $originalName];
}

if (!$items) {
  customPhotoOut(422, ['ok' => false, 'error' => 'No valid images were uploaded.']);
}
customOrdersLog($conn, $customOrderId, 'photos_uploaded', $createdBy, ['count' => count($items)], 'Custom order photos uploaded');
customPhotoOut(200, ['ok' => true, 'items' => $items]);
