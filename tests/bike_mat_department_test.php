<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/orders/department_config.php';

function bikeMatAssertSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)
        );
    }
}

bikeMatAssertSame(
    ['G', 'P'],
    dept_get_departments('G_MC004', 'G_MC004'),
    'Bike mat custom label and SKU must create graphics and plastics items'
);
bikeMatAssertSame(
    ['G', 'P'],
    dept_get_departments(null, 'g_mc123'),
    'Bike mat detection must be case-insensitive and work from SKU alone'
);
bikeMatAssertSame(
    ['G', 'P'],
    dept_get_departments('G', 'G_MC123'),
    'Bike mat SKU must add plastics even when the custom label is only graphics'
);
bikeMatAssertSame(
    ['G', 'P', 'F'],
    dept_get_departments('GFP_SE1047_LE', 'G_MC004'),
    'An existing multi-department classification containing plastics must stay unchanged'
);
bikeMatAssertSame(
    ['G'],
    dept_get_departments('G_ST00009', 'G_ST00009'),
    'Ordinary graphics items must remain graphics-only'
);

if (!dept_is_moto_carpet_graphics_item('G', 'MANUAL', '', '{"_subcat":"MOTO_CARPET"}')) {
    throw new RuntimeException('Stored Moto Carpet subcategory must create a Plastics companion');
}
if (!dept_is_moto_carpet_graphics_item('G', 'G_MC004', '', '{}')) {
    throw new RuntimeException('Moto Carpet SKU must create a Plastics companion');
}
if (dept_is_moto_carpet_graphics_item('P', 'G_MC004', '', '{"_subcat":"MOTO_CARPET"}')) {
    throw new RuntimeException('A Plastics item must not recursively create another Plastics companion');
}

$autoOptions = json_decode(dept_moto_carpet_plastics_options_json('{"name":"Rider"}', 42), true);
if (($autoOptions['_auto_generated'] ?? '') !== 'MOTO_CARPET_AUTO_PLASTICS'
    || (int) ($autoOptions['_auto_source_line_no'] ?? 0) !== 42
    || ($autoOptions['name'] ?? '') !== 'Rider') {
    throw new RuntimeException('Generated Plastics metadata must preserve source options and link to the Graphics line');
}

echo "bike_mat_department_test: OK\n";
