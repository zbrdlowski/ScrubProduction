<?php
declare(strict_types=1);

if (false) {
  class Imagick {
    public function newImage($w, $h, $pixel) {}
    public function setImageFormat($format) {}
    public function queryFontMetrics($draw, $text) { return ['textWidth' => 0]; }
    public function drawImage($draw) {}
    public function getImageBlob() { return ''; }
    public function destroy() {}
  }
  class ImagickDraw {
    public function setFont($font) {}
    public function setFontSize($size) {}
    public function setStrokeColor($color) {}
    public function setStrokeWidth($width) {}
    public function setFillColor($color) {}
    public function setFillOpacity($opacity) {}
    public function rectangle($x1, $y1, $x2, $y2) {}
    public function annotation($x, $y, $text) {}
  }
  class ImagickPixel {
    public function __construct($color = null) {}
  }
}

function g(string $k, string $d = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$order        = g('order');
$name         = g('name');
$graphic      = g('graphic');
$type         = g('type') ?: $order;
$country      = g('country');
$gfp          = g('gfp');
$design       = g('design');
$basematerial = g('basematerial');
$finish       = g('finish');
$printer      = g('printer');
$grip         = g('grip');
$extra        = g('extra');
$hasFitting   = strpos($gfp, 'F') !== false;

$fields = array_values(array_filter([
  $order,
  $name,
  $graphic,
  $type,
  $order,
  $name,
  $country,
  $gfp,
  $design,
  $basematerial,
  $finish,
  $printer,
  $grip !== '' ? 'Grip' : '',
  $extra,
  $hasFitting ? 'Fitting!!!' : '',
], fn($v) => $v !== ''));

// ── Pomocná funkcia: vykresli prúžok cez GD ───────────────────────────────
function renderStripGD(array $fields, string $order, string $graphic, string $gfp, bool $hasFitting): void
{
  $w = 1800; $h = 45;
  $img   = imagecreatetruecolor($w, $h);
  $white = imagecolorallocate($img, 255, 255, 255);
  $black = imagecolorallocate($img, 0,   0,   0);
  $red   = imagecolorallocate($img, 200, 0,   0);
  $blue  = imagecolorallocate($img, 200, 220, 255);
  imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $white);

  $font = 5; $x = 0; $gap = 3; $last = count($fields) - 1;

  foreach ($fields as $i => $text) {
    $text  = mb_substr((string)$text, 0, 40);
    $tw    = imagefontwidth($font) * strlen($text);
    $cellW = max(58, $tw + 34);
    if ($i === $last) $cellW = max($cellW, $w - $x);

    $bg = $white; $fg = $black;
    if ($graphic !== '' && $text === $graphic)                          { $bg = $black; $fg = $white; }
    if ($text === 'Fitting!!!' || ($hasFitting && $text === $gfp))      { $bg = $red;   $fg = $white; }
    if ($text === 'Grip')                                               { $bg = $blue;  $fg = $black; }

    imagefilledrectangle($img, $x, 0, $x + $cellW - 1, $h - 1, $bg);
    imagerectangle      ($img, $x, 0, $x + $cellW - 1, $h - 1, $black);
    $tx = $x + (int)(($cellW - $tw) / 2);
    $ty = (int)(($h - imagefontheight($font)) / 2);
    imagestring($img, $font, $tx, $ty, $text, $fg);

    $x += $cellW + $gap;
    if ($x >= $w) break;
  }

  header('Content-Type: image/png');
  header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $order ?: 'rtp') . '_rtp.png"');
  imagepng($img);
  imagedestroy($img);
}

// ── Pomocná funkcia: vykresli prúžok cez Imagick ─────────────────────────
function renderStripImagick(array $fields, string $order, string $graphic, string $gfp, bool $hasFitting): void
{
  $w = 1800; $h = 45;
  $img  = new Imagick();
  $img->newImage($w, $h, new ImagickPixel('white'));
  $img->setImageFormat('png');

  $draw = new ImagickDraw();
  $draw->setFont('Arial');
  $draw->setFontSize(16);
  $draw->setStrokeColor(new ImagickPixel('black'));
  $draw->setStrokeWidth(1);

  $x = 0; $gap = 3; $last = count($fields) - 1;

  foreach ($fields as $i => $text) {
    $text  = mb_substr((string)$text, 0, 40);
    $metrics = $img->queryFontMetrics($draw, $text);
    $tw    = (int)($metrics['textWidth'] ?? 80);
    $cellW = max(58, $tw + 34);
    if ($i === $last) $cellW = max($cellW, $w - $x);

    $bg = 'white'; $fg = 'black';
    if ($graphic !== '' && $text === $graphic)                          { $bg = 'black';   $fg = 'white'; }
    if ($text === 'Fitting!!!' || ($hasFitting && $text === $gfp))      { $bg = '#c80000'; $fg = 'white'; }
    if ($text === 'Grip')                                               { $bg = '#c8dcff'; $fg = 'black'; }

    $draw->setFillColor(new ImagickPixel($bg));
    $draw->setStrokeColor(new ImagickPixel($bg));
    $draw->rectangle($x, 0, $x + $cellW - 1, $h - 1);

    $draw->setFillColor(new ImagickPixel('black'));
    $draw->setStrokeColor(new ImagickPixel('black'));
    $draw->setFillOpacity(0);
    $draw->rectangle($x, 0, $x + $cellW - 1, $h - 1);
    $draw->setFillOpacity(1);

    $draw->setFillColor(new ImagickPixel($fg));
    $draw->setStrokeWidth(0);
    $ty = (int)(($h + 14) / 2);
    $draw->annotation($x + (int)(($cellW - $tw) / 2), $ty, $text);
    $draw->setStrokeWidth(1);

    $x += $cellW + $gap;
    if ($x >= $w) break;
  }

  $img->drawImage($draw);
  header('Content-Type: image/png');
  header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $order ?: 'rtp') . '_rtp.png"');
  echo $img->getImageBlob();
  $img->destroy();
}

// ── Dispatch ──────────────────────────────────────────────────────────────
if (function_exists('imagecreatetruecolor')) {
  renderStripGD($fields, $order, $graphic, $gfp, $hasFitting);
  exit;
}

if (extension_loaded('imagick') && class_exists('Imagick')) {
  renderStripImagick($fields, $order, $graphic, $gfp, $hasFitting);
  exit;
}

// ── Ani GD ani Imagick nie sú dostupné ───────────────────────────────────
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="sk">
<head><meta charset="UTF-8"><title>Chyba – RTP</title>
<style>
  body { font: 15px Arial, sans-serif; padding: 30px; background: #1e1e2e; color: #cdd6f4; }
  .box { background: #313244; border-radius: 10px; padding: 24px 30px; max-width: 560px; }
  h2   { color: #f38ba8; margin-top: 0; }
  code { background: #45475a; padding: 2px 7px; border-radius: 4px; font-size: 13px; }
  ol   { line-height: 2; }
</style>
</head>
<body>
<div class="box">
  <h2>⚠ GD rozšírenie nie je povolené</h2>
  <p>Na generovanie PNG prúžku je potrebné <strong>GD</strong> alebo <strong>Imagick</strong>.</p>
  <p><strong>Ako zapnúť GD v XAMPP (2 kroky):</strong></p>
  <ol>
    <li>Otvor <code>php.ini</code> — v XAMPP Control Panel klikni <em>Config → PHP (php.ini)</em></li>
    <li>Nájdi riadok <code>;extension=gd</code> a odstraň bodkočiarku → <code>extension=gd</code><br>
        (starší XAMPP: <code>;extension=php_gd2.dll</code> → <code>extension=php_gd2.dll</code>)</li>
    <li>Ulož súbor a reštartuj Apache v XAMPP Control Panel</li>
  </ol>
  <p style="margin-top:16px; color:#a6e3a1;">Po reštarte bude tento skript fungovať automaticky.</p>
</div>
</body>
</html>
<?php
exit;