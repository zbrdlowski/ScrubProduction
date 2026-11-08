<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelcode_helpers.php';
function pb_component_types(): array
{
    return ['G'=>'Graphics', 'F'=>'Fitting', 'P'=>'Plastics', 'S'=>'Seat cover', 'T'=>'Trim Kit'];
}
function pb_bundle_types(): array
{
    $types = ['G','F','P','S','T','GP','GFP','GPS','GFPS','PF','PS','TFP','TFPS'];
    $letters = ['G','T','F','P','S']; $seen = [];
    foreach ($types as $type) { $key = str_split($type); sort($key); $seen[implode('', $key)] = true; }
    for ($mask = 1; $mask < 32; $mask++) {
        $type = '';
        foreach ($letters as $i => $letter) if ($mask & (1 << $i)) $type .= $letter;
        $key = str_split($type); sort($key); $key = implode('', $key);
        if (!isset($seen[$key])) { $types[] = $type; $seen[$key] = true; }
    }
    return $types;
}
/** T identifies a Trim Kit bundle; its graphics are regular G components. */
function pb_bundle_component_types(string $bundle): array
{
    return array_values(array_unique(array_map(static fn(string $type): string => $type === 'T' ? 'G' : $type, str_split($bundle))));
}
function pb_text($value, string $label, int $max, bool $required = true): string
{
    if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException($label . ': invalid value.');
    $text = trim((string) $value);
    if (($required && $text === '') || mb_strlen($text, 'UTF-8') > $max) {
        throw new InvalidArgumentException($label . ': ' . ($text === '' ? 'enter a value.' : 'maximum ' . $max . ' characters.'));
    }
    return $text;
}
function pb_price($value): ?string
{
    $value = str_replace(',', '.', pb_text($value, 'Price', 20, false));
    if ($value === '') return null;
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Price must be a non-negative number with no more than two decimal places.');
    return number_format((float) $value, 2, '.', '');
}
function pb_validate_uni(array $data): array
{
    return ['brand'=>pb_text($data['brand'] ?? '', 'Brand', 100),
        'category_name'=>pb_text($data['category_name'] ?? '', 'Category name', 150),
        'included_models'=>pb_text($data['included_models'] ?? '', 'Included models', 10000, false),
        'price_unlicensed'=>pb_price($data['price_unlicensed'] ?? ''),
        'price_licensed'=>pb_price($data['price_licensed'] ?? '')];
}
function pb_price_fields(): array
{
    return ['recommended'=>'Recommended price','web'=>'Web','ebay'=>'eBay','amazon'=>'Amazon'];
}
function pb_price_currencies(): array
{
    return ['EUR','USD','GBP','CZK','PLN','HUF','CHF','SEK','NOK','DKK','CAD','AUD','NZD','JPY'];
}
function pb_validate_product_prices(array $data): array
{
    $prices = [];
    foreach (pb_price_fields() as $channel => $label) {
        try { $price = pb_price($data[$channel . '_price'] ?? ''); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException($label . ': ' . $e->getMessage()); }
        if ($channel === 'recommended' && $price === null) throw new InvalidArgumentException('Enter the recommended price.');
        $currency = strtoupper(pb_text($data[$channel . '_currency'] ?? 'EUR', $label . ' currency', 3));
        if (!in_array($currency, pb_price_currencies(), true)) throw new InvalidArgumentException($label . ': select a supported currency.');
        $prices[$channel . '_price'] = $price;
        $prices[$channel . '_currency'] = $currency;
    }
    return $prices;
}
function pb_decimal($value, string $label, int $digits, int $scale, bool $allowZero): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException($label . ': enter a number.');
    $text = str_replace(',', '.', pb_text($value, $label, 24));
    if (!preg_match('/^[0-9]{1,' . $digits . '}(?:\.[0-9]{1,' . $scale . '})?$/D', $text)) {
        throw new InvalidArgumentException($label . ': enter a non-negative number with up to ' . $digits . ' digits before and ' . $scale . ' digits after the decimal point.');
    }
    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    $amount = (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, $scale, '0');
    if (!$allowZero && str_replace(['0','.'], '', $amount) === '') throw new InvalidArgumentException($label . ': enter a value greater than zero.');
    return $amount;
}
function pb_validate_product(array $data): array
{
    $bundle = pb_text($data['bundle_type'] ?? '', 'Bundle type', 5);
    if (!in_array($bundle, pb_bundle_types(), true)) throw new InvalidArgumentException('Select a valid bundle type.');
    $requiredTypes = pb_bundle_component_types($bundle);
    $kind = $data['category_kind'] ?? '';
    if (!in_array($kind, ['specific','universal'], true)) throw new InvalidArgumentException('Select a category type.');
    $components = $data['components'] ?? [];
    if (!is_array($components) || count($components) < 1 || count($components) > 100) throw new InvalidArgumentException('A product must contain between 1 and 100 components.');
    $clean = []; $found = [];
    foreach ($components as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid component.');
        $type = pb_text($row['component_type'] ?? '', 'Component type', 1);
        // Accept legacy T rows from an older editor, then store the canonical graphics type.
        if ($type === 'T' && strpos($bundle, 'T') !== false) $type = 'G';
        if (!in_array($type, $requiredTypes, true)) throw new InvalidArgumentException('The component type is not included in the selected bundle.');
        $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>9999]]);
        if ($quantity === false) throw new InvalidArgumentException('Quantity must be a whole number between 1 and 9999.');
        $designId = $row['design_id'] ?? null;
        if ($designId === '' || $designId === null) $designId = null;
        else {
            $designId = filter_var($designId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($designId === false || !in_array($type, ['G','S'], true)) throw new InvalidArgumentException('Select a valid design for this component type.');
        }
        $graphicsVersionId = $row['graphics_version_id'] ?? null;
        if ($graphicsVersionId === '' || $graphicsVersionId === null) $graphicsVersionId = null;
        else {
            $graphicsVersionId = filter_var($graphicsVersionId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($graphicsVersionId === false || $type !== 'G') throw new InvalidArgumentException('Select a valid Graphics version.');
        }
        $clean[] = ['graphics_version_id'=>$graphicsVersionId, 'design_id'=>$designId, 'component_type'=>$type, 'label'=>pb_text($row['label'] ?? '', 'Component name ' . $type, 255),
            'reference'=>pb_text($row['reference'] ?? '', 'Reference', 100, false), 'quantity'=>$quantity,
            'color'=>$type === 'P' ? pb_text($row['color'] ?? '', 'Color', 100, false) : '',
            'notes'=>pb_text($row['notes'] ?? '', 'Component details', 10000, false),
            'options'=>pb_validate_component_options($row['options'] ?? [])];
        $found[$type] = true;
    }
    foreach ($requiredTypes as $type) if (!isset($found[$type])) throw new InvalidArgumentException('Missing component of type ' . $type . '.');
    $sku = pb_text($data['sku'] ?? '', 'SKU', 80, false);
    if (preg_match('/[\x00-\x1F\x7F]/u', $sku)) throw new InvalidArgumentException('SKU cannot contain control characters.');
    if ((int)($data['id'] ?? 0) > 0 && array_key_exists('sku', $data) && $sku === '') throw new InvalidArgumentException('Enter the product SKU.');
    $boxId = $data['box_id'] ?? null;
    if ($boxId === '' || $boxId === null) $boxId = null;
    else {
        $boxId = filter_var($boxId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($boxId === false) throw new InvalidArgumentException('Select a valid box.');
    }
    $weight = $data['weight_kg'] ?? null;
    $weight = $weight === '' || $weight === null ? null : pb_decimal($weight, 'Total weight (kg)', 6, 3, true);
    return pb_validate_product_prices($data) + ['weight_kg'=>$weight, 'box_id'=>$boxId, 'sku'=>$sku, 'name'=>pb_text($data['name'] ?? '', 'Product name', 255), 'bundle_type'=>$bundle,
        'category_kind'=>$kind, 'notes'=>pb_text($data['notes'] ?? '', 'Notes', 10000, false), 'components'=>$clean];
}
function pb_schema_ready(PDO $pdo): bool
{
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('product_builder_products','product_builder_components','product_builder_uni_categories','product_builder_designs','product_builder_graphics_versions','product_builder_boxes')")->fetchColumn() === 6
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_components' AND column_name IN ('color','options_json','design_id','graphics_version_id')")->fetchColumn() === 4
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_builder_products' AND column_name IN ('recommended_price','recommended_currency','web_price','web_currency','ebay_price','ebay_currency','amazon_price','amazon_currency','box_id','weight_kg')")->fetchColumn() === 10;
}
function pb_modelcode_lock(PDO $pdo): void
{
    if ((int) $pdo->query("SELECT GET_LOCK('darkscrub_modelcode_write', 10)")->fetchColumn() !== 1) throw new RuntimeException('Another user is saving a modelcode. Please try again.');
}

function pb_validate_component_options($input): array
{
    if (!is_array($input) || count($input) > 64) throw new InvalidArgumentException('Invalid component settings.');
    $clean = [];
    foreach ($input as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $key)) throw new InvalidArgumentException('Invalid component setting key.');
        $clean[$key] = pb_text($value, 'Component setting', 10000, false);
    }
    if (strlen(json_encode((object)$clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 65535) throw new InvalidArgumentException('Component settings are too long.');
    return $clean;
}
function pb_component_options(array $row): array
{
    $decoded = json_decode((string)($row['options_json'] ?? '{}'), true);
    return is_array($decoded) ? $decoded : [];
}
