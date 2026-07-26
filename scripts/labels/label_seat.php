<!DOCTYPE html>
<html lang="sk">
<head>
  <meta charset="UTF-8">
  <title>Seat Cover Label</title>
  <style>
    body {
      background-color: white;
      font: 15px Tahoma, Helvetica, sans-serif;
      color: black;
      margin: 0;
      padding: 10px;
    }
    table {
    border: 3px solid black;
    border-collapse: separate;
    border-spacing: 3px;
    /*background: black;    vyplní medzery medzi bunkami */
}

td {
    border: 1px solid black;
    /*background: white;*/
}
    #name  { text-align: center; font-size: 40px; padding: 6px; }
    #order { text-align: center; font-size: 50px; padding: 6px; }
    #country, #ship {
      text-align: center; font-size: 30px; font-weight: bold; padding: 6px;
    }
    #gebul {
      text-align: center; font-size: 15px; padding: 5px; color: #888;
    }
    .dept-active {
      color: white; background-color: #444;
      text-align: center; font-size: 15px; font-weight: bold; padding: 5px;
    }
    .dept-empty { padding: 5px; text-align: center; font-size: 15px; }
    .note-cell  { padding: 20px; text-align: center; font-size: 20px; }
    .priority-cell {
      padding: 10px; text-align: center; font-size: 20px; font-weight: bold;
      background: #ffdd00;
    }
    @media print { body { padding: 0; } }
  </style>
</head>
<body>
<?php
function g(string $k, string $d = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$order     = g('order');
$name      = g('name');
$country   = g('country');
$gfp       = g('gfp');
$item      = g('item');
$version   = g('version');
$ship      = g('ship');
$date      = g('date');
$note      = g('note');
$material  = g('material');
$bike      = g('bike');
$extra     = g('extra');
$extranote = g('extranote');

$priority = stripos($extranote, 'prior') !== false;
$mf       = stripos($extra, 'MF') !== false;
$rt       = stripos($extra, 'RT') !== false;
$sc       = stripos($extra, 'SC') !== false;
$stickers = ($extra === 'Stickers');
if ($stickers) $sc = false;
?>

<div align="center">
  <table width="800" border="0" cellpadding="1" cellspacing="1">

    <!-- Meno + QR (rowspan 4) -->
    <tr>
      <td colspan="5" id="name"><?= htmlspecialchars($name) ?></td>
      <td colspan="2" rowspan="4" align="center">
        <img src="https://api.qrserver.com/v1/create-qr-code/?color=000000&bgcolor=FFFFFF&data=<?= urlencode($order) ?>&qzone=1&margin=0&size=190x190&ecc=L" alt="QR">
      </td>
    </tr>

    <!-- Číslo objednávky -->
    <tr>
      <td colspan="5" id="order"><?= htmlspecialchars($order) ?></td>
    </tr>

    <!-- Labels riadok (header pre detail) -->
    <tr>
      <td id="gebul" width="15%">Order type</td>
      <td id="gebul" width="15%">Country</td>
      <td id="gebul" width="15%">&nbsp;</td>
      <td id="gebul" width="15%">Shipping</td>
      <td id="gebul" width="15%">Date</td>
    </tr>

    <!-- GFP / Krajina / Shipping / Dátum -->
    <tr>
      <td id="country" width="15%"><?= htmlspecialchars($gfp) ?></td>
      <td id="ship"    width="15%"><?= htmlspecialchars($country) ?></td>
      <td id="ship"    width="15%">&nbsp;</td>
      <td id="ship"    width="15%"><?= htmlspecialchars($ship) ?></td>
      <td id="ship"    width="15%"><?= htmlspecialchars($date) ?></td>
    </tr>

    <!-- Header: Bike / Item / Version / Material -->
    <tr>
      <td id="gebul" colspan="2" width="30%">Bike</td>
      <td id="gebul" colspan="2">Item</td>
      <td id="gebul" width="15%">Version</td>
      <td id="gebul" width="15%">Material</td>
    </tr>

    <!-- Hodnoty: Bike / Item / Version / Material -->
    <tr>
      <td id="country" colspan="2" width="30%"><?= htmlspecialchars($bike) ?></td>
      <td id="ship"    colspan="2"><?= htmlspecialchars($item) ?></td>
      <td id="ship"    width="15%"><?= htmlspecialchars($version) ?></td>
      <td id="ship"    width="15%"><?= htmlspecialchars($material) ?></td>
    </tr>

    <!-- Dept flagy -->
    <?php if (strlen($gfp) > 1): ?>
    <tr>
      <td class="<?= strpos($gfp,'G') !== false ? 'dept-active' : 'dept-empty' ?>">GRAPHICS</td>
      <td class="<?= strpos($gfp,'F') !== false ? 'dept-active' : 'dept-empty' ?>">FITTING</td>
      <td class="dept-active" style="background:#1a7a3c;">SEAT COVER</td>
      <td class="<?= strpos($gfp,'P') !== false ? 'dept-active' : 'dept-empty' ?>">PLASTICS</td>
      <td class="<?= $mf ? 'dept-active' : 'dept-empty' ?>">MID FORK</td>
      <td class="<?= $sc ? 'dept-active' : 'dept-empty' ?>"><?= $stickers ? 'STICKERS' : 'SPOKES' ?></td>
      <td class="<?= $rt ? 'dept-active' : 'dept-empty' ?>">RIM TAPE</td>
    </tr>
    <?php endif; ?>

    <!-- Poznámka -->
    <tr>
      <td colspan="7" class="note-cell">
        <?= $note !== '' ? htmlspecialchars($note) : '&nbsp;' ?>
      </td>
    </tr>

    <!-- Priority -->
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
