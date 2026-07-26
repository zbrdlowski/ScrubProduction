<!DOCTYPE html>
<html lang="sk">
<head>
  <meta charset="UTF-8">
  <title>Label</title>
  <style>
    body {
      background-color: white;
      font: 15px Tahoma, Helvetica, sans-serif;
      color: black;
      margin: 0;
      padding: 10px;
    }
    table { border: 2px solid black; border-collapse: collapse; }
    td    { border: 2px solid black; }
    #name  { text-align: center; font-size: 40px; padding: 6px; }
    #order { text-align: center; font-size: 50px; padding: 6px; }
    #country, #ship {
      text-align: center; font-size: 30px; font-weight: bold; padding: 6px;
    }
    .dept-active {
      color: white; background-color: #444;
      text-align: center; font-size: 15px; font-weight: bold; padding: 5px;
    }
    .dept-empty { padding: 5px; text-align: center; font-size: 15px; }
    .note-cell {
      padding: 20px; text-align: center; font-size: 20px;
    }
    .priority-cell {
      padding: 10px; text-align: center; font-size: 20px; font-weight: bold;
      background: #ffdd00;
    }
    @media print {
      body { padding: 0; }
    }
  </style>
</head>
<body>
<?php
function g(string $k, string $d = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$order        = g('order');
$name         = g('name');
$country      = g('country');
$gfp          = g('gfp');
$item         = g('item');
$ship         = g('ship');
$date         = g('date');
$note         = g('note');
$extranote    = g('extranote');
$basematerial = g('basematerial');
$finish       = g('finish');
$extra        = g('extra');

$priority = stripos($extranote, 'prior') !== false;
$mf       = stripos($extra, 'MF') !== false;
$rt       = stripos($extra, 'RT') !== false;
$sc       = stripos($extra, 'SC') !== false;
$stickers = ($extra === 'Stickers');
if ($stickers) $sc = false;
?>

<div align="center">
  <table width="800" border="1" cellpadding="2" cellspacing="2">

    <!-- Meno + QR -->
    <tr>
      <td colspan="5" id="name"><?= htmlspecialchars($name) ?></td>
      <td colspan="2" rowspan="2" align="center">
        <img src="https://api.qrserver.com/v1/create-qr-code/?color=000000&bgcolor=FFFFFF&data=<?= urlencode($order) ?>&qzone=1&margin=0&size=190x190&ecc=L" alt="QR">
      </td>
    </tr>

    <!-- Číslo objednávky -->
    <tr>
      <td colspan="5" id="order"><?= htmlspecialchars($order) ?></td>
    </tr>

    <!-- GFP / Krajina / Shipping / Dátum / Material -->
    <tr>
      <td id="country" width="15%"><?= htmlspecialchars($gfp) ?></td>
      <td id="ship"    width="15%"><?= htmlspecialchars($country) ?></td>
      <td id="ship"    width="15%">&nbsp;</td>
      <td id="ship"    width="15%"><?= htmlspecialchars($ship) ?></td>
      <td id="ship"    width="15%"><?= htmlspecialchars($date) ?></td>
      <td colspan="2" style="text-align:center; font-size:18px; font-weight:bold; padding:6px;">
        <?= htmlspecialchars($basematerial) ?>&nbsp;&nbsp;<?= htmlspecialchars($finish) ?>
      </td>
    </tr>

    <!-- Názov položky -->
    <tr>
      <td colspan="7" style="padding:20px; text-align:center; font-size:20px;">
        <?= htmlspecialchars(str_replace('+', ' + ', $item)) ?>
      </td>
    </tr>

    <!-- Dept flagy (len ak viacero deptov v objednávke) -->
    <?php if (strlen($gfp) > 1): ?>
    <tr>
      <td class="<?= strpos($gfp,'G') !== false ? 'dept-active' : 'dept-empty' ?>">GRAPHICS</td>
      <td class="<?= strpos($gfp,'F') !== false ? 'dept-active' : 'dept-empty' ?>">FITTING</td>
      <td class="<?= strpos($gfp,'S') !== false ? 'dept-active' : 'dept-empty' ?>">SEAT COVER</td>
      <td class="<?= strpos($gfp,'P') !== false ? 'dept-active' : 'dept-empty' ?>">PLASTICS</td>
      <td class="<?= $mf       ? 'dept-active' : 'dept-empty' ?>">MID FORK</td>
      <td class="<?= $sc       ? 'dept-active' : 'dept-empty' ?>"><?= $stickers ? 'STICKERS' : 'SPOKES' ?></td>
      <td class="<?= $rt       ? 'dept-active' : 'dept-empty' ?>">RIM TAPE</td>
    </tr>
    <tr>
      <?php for ($i = 0; $i < 7; $i++): ?>
        <td style="padding:5px;">&nbsp;<br>&nbsp;</td>
      <?php endfor; ?>
    </tr>
    <?php endif; ?>

    <!-- Poznámka -->
    <tr>
      <td colspan="7" class="note-cell">
        <?= $note !== '' ? htmlspecialchars($note) : '&nbsp;' ?>
      </td>
    </tr>

    <!-- Priority poznámka -->
    <?php if ($priority && $extranote !== ''): ?>
    <tr>
      <td colspan="7" class="priority-cell">
        ⚡ <?= htmlspecialchars($extranote) ?>
      </td>
    </tr>
    <?php endif; ?>

  </table>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
  window.print();
  setTimeout(function () { window.close(); }, 1500);
});
</script>
</body>
</html>
