<?php
declare(strict_types=1);

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

// ── GD path (Synology / server) ───────────────────────────────────────────
if (function_exists('imagecreatetruecolor')) {

  $w = 1800;
  $h = 45;
  $img = imagecreatetruecolor($w, $h);

  $white = imagecolorallocate($img, 255, 255, 255);
  $black = imagecolorallocate($img, 0,   0,   0);
  $red   = imagecolorallocate($img, 200, 0,   0);
  $blue  = imagecolorallocate($img, 200, 220, 255);

  imagefilledrectangle($img, 0, 0, $w, $h, $white);

  $font = 5;
  $x    = 0;
  $gap  = 3;
  $last = count($fields) - 1;

  foreach ($fields as $i => $text) {
    $text  = mb_substr($text, 0, 40);
    $tw    = imagefontwidth($font) * strlen($text);
    $cellW = max(58, $tw + 34);
    if ($i === $last) $cellW = max($cellW, $w - $x);

    $bg = $white;
    $fg = $black;
    if ($graphic !== '' && $text === $graphic)            { $bg = $black; $fg = $white; }
    if ($text === 'Fitting!!!' || ($hasFitting && $text === $gfp)) { $bg = $red;   $fg = $white; }
    if ($text === 'Grip')                                 { $bg = $blue;  $fg = $black; }

    imagefilledrectangle($img, $x,     0,     $x + $cellW - 1, $h - 1, $bg);
    imagerectangle      ($img, $x,     0,     $x + $cellW - 1, $h - 1, $black);
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
  exit;
}

// ── HTML/CSS fallback (XAMPP / GD nedostupné) ─────────────────────────────
// Renderuje rovnakú vizuálnu štruktúru cez HTML tabuľku + html2canvas → PNG
?>
<!DOCTYPE html>
<html lang="sk">
<head>
  <meta charset="UTF-8">
  <title>RTP – <?= htmlspecialchars($order) ?></title>
  <style>
    body { margin: 0; padding: 10px; background: #fff; font-family: Arial, sans-serif; }
    #rtp-strip {
      display: inline-flex;
      height: 45px;
      width: 1800px;
      overflow: hidden;
      border-left: 1px solid #000;
    }
    .rc {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 45px;
      min-width: 58px;
      padding: 0 12px;
      border-top: 1px solid #000;
      border-right: 1px solid #000;
      border-bottom: 1px solid #000;
      font-size: 16px;
      font-family: Arial, sans-serif;
      white-space: nowrap;
      box-sizing: border-box;
      background: #fff;
      color: #000;
    }
    .rc.dark    { background: #000; color: #fff; font-weight: bold; }
    .rc.red     { background: #c80000; color: #fff; font-weight: bold; }
    .rc.blue    { background: #c8dcff; color: #000; font-weight: bold; }
    .hint { margin-top: 10px; font-size: 12px; color: #888; }
  </style>
</head>
<body>

<div id="rtp-strip">
<?php foreach ($fields as $text):
  $cls = 'rc';
  if ($graphic !== '' && $text === $graphic)                         $cls .= ' dark';
  elseif ($text === 'Fitting!!!' || ($hasFitting && $text === $gfp)) $cls .= ' red';
  elseif ($text === 'Grip')                                          $cls .= ' blue';
?>
  <div class="<?= $cls ?>"><?= htmlspecialchars($text) ?></div>
<?php endforeach; ?>
</div>

<p class="hint" id="hint">Renderujem PNG…</p>

<script src="js/html2canvas.min.js"></script>
<script>
window.onload = function () {
  var strip = document.getElementById('rtp-strip');
  html2canvas(strip, {
    backgroundColor: '#ffffff',
    scale: 1,
    width: 1800,
    height: 45,
    windowWidth: 1840,
    windowHeight: 120,
    scrollX: 0,
    scrollY: 0,
    useCORS: true
  }).then(function (canvas) {
    document.body.innerHTML = '';
    document.body.style.cssText = 'margin:0;padding:0;background:#fff;';
    canvas.style.display = 'block';
    document.body.appendChild(canvas);

    var btn = document.createElement('a');
    btn.download = '<?= preg_replace('/[^A-Za-z0-9_-]+/', '_', htmlspecialchars($order ?: 'rtp')) ?>_rtp.png';
    btn.href = canvas.toDataURL('image/png');
    btn.style.cssText = 'position:fixed;bottom:10px;right:10px;padding:8px 18px;'
      + 'background:#1a6fb5;color:#fff;font:bold 14px Arial;border-radius:4px;'
      + 'text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.3);';
    btn.textContent = '\u2B07 Stiahnuť PNG';
    document.body.appendChild(btn);
  });
};
</script>
</body>
</html>