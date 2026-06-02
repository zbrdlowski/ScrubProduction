<!DOCTYPE html>
<html lang="sk">
<head>
  <meta charset="UTF-8">
  <title>RTP Strip</title>
  <style>
    body {
      background: white;
      margin: 0;
      padding: 8px;
      font-family: Arial, sans-serif;
    }
    .div {
      border: 1px solid black;
      padding: 0 8px;
      background-color: white;
      color: black;
      height: 19px;
      font-size: 18px;
      font-family: Arial;
      text-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      white-space: nowrap;
    }
    #capture {
      width: 1800px;
      height: 45px;
      overflow: hidden;
      display: flex;
    }
    #capture table {
      width: 1800px;
      border-collapse: collapse;
    }
    #capture td {
      height: 45px;
      vertical-align: middle;
    }
    .hint {
      margin-top: 12px;
      font-size: 12px;
      color: #777;
    }
    @media print {
      .hint { display: none; }
    }
  </style>
</head>
<body>
<?php
function g(string $k, string $d = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}
function e(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$type         = g('type');
$order        = g('order');
$name         = g('name');
$country      = g('country');
$gfp          = g('gfp');
$design       = g('design');
$ship         = g('ship');
$date         = g('date');
$note         = g('note');
$extranote    = g('extranote');
$basematerial = g('basematerial');
$finish       = g('finish');
$extra        = g('extra');
$graphic      = g('graphic');   // grafik / číslo
$grip         = g('grip');
$printer      = g('printer');   // nový parameter (nie je v pôv. scripte)

$hasFitting = strpos($gfp, 'F') !== false;
?>

<div id="capture">
<table cellpadding="1" cellspacing="0" style="width:1800px;" border="0">
  <tr>
    <td style="height:45px;"><div class="div"><?= e($order) ?></div></td>
    <td style="height:45px;"><div class="div"><?= e($name) ?></div></td>

    <?php if ($graphic !== ''): ?>
    <td width="1%" style="height:45px;">
      <div class="div" style="background-color:black; color:white; font-weight:bold;">
        <?= e($graphic) ?>
      </div>
    </td>
    <?php endif; ?>

    <td width="1%" style="height:45px;"><div class="div"><?= e($type ?: $order) ?></div></td>
    <td style="height:45px;"><div class="div"><?= e($order) ?></div></td>
    <td style="height:45px;"><div class="div"><?= e($name) ?></div></td>
    <td style="height:45px;"><div class="div" width="1%"><?= e($country) ?></div></td>

    <?php if ($hasFitting): ?>
    <td width="1%" style="height:45px;">
      <div class="div" style="background-color:#c00; color:white; font-weight:bold;">
        <?= e($gfp) ?>
      </div>
    </td>
    <?php else: ?>
    <td width="1%" style="height:45px;"><div class="div"><?= e($gfp) ?></div></td>
    <?php endif; ?>

    <?php if ($design !== ''): ?>
    <td style="height:45px;"><div class="div"><?= e($design) ?></div></td>
    <?php else: ?>
    <td style="height:45px;"><div class="div">&nbsp;&nbsp;&nbsp;&nbsp;</div></td>
    <?php endif; ?>

    <td style="height:45px;"><div class="div"><?= e($basematerial) ?></div></td>
    <td style="height:45px;"><div class="div"><?= e($finish) ?></div></td>

    <?php if ($printer !== ''): ?>
    <td style="height:45px;">
      <div class="div" style="background:#e8f0fe; font-weight:bold;">
        <?= e($printer) ?>
      </div>
    </td>
    <?php endif; ?>

    <?php if ($grip !== ''): ?>
    <td width="1%" style="height:45px;">
      <div class="div" style="background:#d4f0d4; font-weight:bold;">Grip</div>
    </td>
    <?php else: ?>
    <td width="1%" style="height:45px;"><div class="div">&nbsp;&nbsp;&nbsp;&nbsp;</div></td>
    <?php endif; ?>

    <?php if ($extra !== ''): ?>
    <td width="3%" style="height:45px;"><div class="div"><?= e($extra) ?></div></td>
    <?php else: ?>
    <td width="1%" style="height:45px;"><div class="div">&nbsp;&nbsp;&nbsp;&nbsp;</div></td>
    <?php endif; ?>

    <?php if ($hasFitting): ?>
    <td width="1%" style="height:45px;">
      <div class="div" style="background-color:red; color:white; font-weight:bold;">
        Fitting!!!
      </div>
    </td>
    <?php else: ?>
    <td width="1%" style="height:45px;">
      <div class="div">&nbsp;</div>
    </td>
    <?php endif; ?>

  </tr>
</table>
</div>

<p class="hint">Prúžok sa renderuje… klikni "Stiahnuť PNG" pre uloženie do tlačového súboru.</p>

<script src="js/html2canvas.min.js"></script>
<script>
window.onload = function () {
  var target = document.getElementById('capture');
  html2canvas(target, {
    backgroundColor: '#ffffff',
    scale: 1,
    width: 1800,
    height: 45,
    windowWidth: 1840,
    windowHeight: 120,
    scrollX: 0,
    scrollY: 0
  }).then(function (canvas) {
    // Nahradiť obsah canvasom (ako pôvodný script)
    document.body.innerHTML = '';
    document.body.style.cssText = 'margin:0;padding:0;background:#fff;';
    canvas.style.display = 'block';
    document.body.appendChild(canvas);

    // Tlačidlo na stiahnutie PNG
    var btn = document.createElement('a');
    btn.download = '<?= e($order) ?>_rtp.png';
    btn.href = canvas.toDataURL('image/png');
    btn.style.cssText = 'position:fixed;bottom:10px;right:10px;padding:8px 18px;'
      + 'background:#1a6fb5;color:#fff;font:bold 14px Arial;border-radius:4px;'
      + 'text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.3);';
    btn.textContent = '⬇ Stiahnuť PNG';
    document.body.appendChild(btn);
  });
};
</script>
</body>
</html>