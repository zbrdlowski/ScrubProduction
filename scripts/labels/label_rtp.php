<?php
declare(strict_types=1);

function g(string $k, string $d = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$fields = [
  g('order'),
  g('name'),
  g('graphic'),
  g('type') ?: g('order'),
  g('order'),
  g('name'),
  g('country'),
  g('gfp'),
  g('design'),
  g('basematerial'),
  g('finish'),
  g('printer'),
  g('grip') !== '' ? 'Grip' : '',
  g('extra'),
  strpos(g('gfp'), 'F') !== false ? 'Fitting!!!' : '',
];

$fields = array_values(array_filter($fields, fn($v) => $v !== ''));

$w = 1800;
$h = 45;
$img = imagecreatetruecolor($w, $h);

$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
$red   = imagecolorallocate($img, 200, 0, 0);

imagefilledrectangle($img, 0, 0, $w, $h, $white);

$font = 5; // built-in GD font
$x = 0;
$gap = 3;

foreach ($fields as $i => $text) {
  $text = mb_substr($text, 0, 40);
  $tw = imagefontwidth($font) * strlen($text);
  $cellW = max(58, $tw + 34);

  if ($i === count($fields) - 1) {
    $cellW = max($cellW, $w - $x);
  }

  $bg = $white;
  $fg = $black;

  if ($text === g('graphic')) {
    $bg = $black;
    $fg = $white;
  }

  if ($text === 'Fitting!!!' || ($text === g('gfp') && strpos(g('gfp'), 'F') !== false)) {
    $bg = $red;
    $fg = $white;
  }

  imagefilledrectangle($img, $x, 0, $x + $cellW - 1, $h - 1, $bg);
  imagerectangle($img, $x, 0, $x + $cellW - 1, $h - 1, $black);

  $tx = $x + (int)(($cellW - $tw) / 2);
  $ty = (int)(($h - imagefontheight($font)) / 2);
  imagestring($img, $font, $tx, $ty, $text, $fg);

  $x += $cellW + $gap;
  if ($x >= $w) break;
}

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', g('order', 'rtp')) . '_rtp.png"');
imagepng($img);
imagedestroy($img);