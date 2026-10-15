<?php
declare(strict_types=1);

function add_item_rules(): array
{
    return [
        'brand' => ['label' => 'Brand', 'maxLength' => 50],
        'barcode' => ['label' => 'Barcode', 'maxLength' => 25, 'required' => true],
        'scrubcode' => ['label' => 'Scrubcode', 'maxLength' => 255],
        'name' => ['label' => 'Model', 'maxLength' => 255, 'required' => true],
        'description' => ['label' => 'Part', 'maxLength' => 65535, 'maxBytes' => 65535],
        'color' => ['label' => 'Color', 'maxLength' => 50],
        'optimum' => ['label' => 'Optimum', 'type' => 'integer', 'max' => 2147483647],
        'moq' => ['label' => 'Max', 'type' => 'integer', 'max' => 2147483647],
        'main_supplier' => ['label' => 'Main Supplier', 'maxLength' => 50],
        'baseline' => ['label' => 'Baseline', 'type' => 'integer', 'max' => 4294967295],
        'ufo_pn' => ['label' => 'UFO PN', 'maxLength' => 50],
        'ufo_barcode' => ['label' => 'UFO Barcode', 'maxLength' => 25],
        'rt_pn' => ['label' => 'R-Tech PN', 'maxLength' => 50],
        'rt_barcode' => ['label' => 'R-Tech Barcode', 'maxLength' => 25],
        'ps_pn' => ['label' => 'Polisport PN', 'maxLength' => 50],
        'ps_barcode' => ['label' => 'Polisport Barcode', 'maxLength' => 25],
        'ac_pn' => ['label' => 'Acerbis PN', 'maxLength' => 50],
        'ac_barcode' => ['label' => 'Acerbis Barcode', 'maxLength' => 25],
        'other_pn' => ['label' => 'Other PN', 'maxLength' => 50],
        'other_barcode' => ['label' => 'Other Barcode', 'maxLength' => 25],
    ];
}

function add_item_validate(array $submitted): array
{
    $data = [];
    $input = [];
    $errors = [];
    foreach (add_item_rules() as $field => $rule) {
        $value = $submitted[$field] ?? '';
        $input[$field] = '';
        if (!is_string($value) && !is_int($value)) {
            $errors[$field] = $rule['label'] . ': enter a single value.';
            continue;
        }
        $value = (string) $value;
        if (preg_match('//u', $value) !== 1) {
            $errors[$field] = $rule['label'] . ': enter valid text.';
            continue;
        }
        $value = preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $value);
        $input[$field] = $value;

        if (($rule['type'] ?? '') === 'integer') {
            if ($value === '') {
                $value = '0';
            }
            if (!preg_match('/^[0-9]+$/D', $value)) {
                $errors[$field] = $rule['label'] . ': enter a whole number of 0 or more.';
                continue;
            }
            $digits = ltrim($value, '0');
            $digits = $digits === '' ? '0' : $digits;
            $maximum = (string) $rule['max'];
            if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                $errors[$field] = $rule['label'] . ': maximum is ' . $maximum . '.';
                continue;
            }
            $data[$field] = $digits;
            $input[$field] = $digits;
            continue;
        }

        $data[$field] = $value;
        if (!empty($rule['required']) && $value === '') {
            $errors[$field] = $rule['label'] . ' is required.';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $errors[$field] = $rule['label'] . ': remove control characters.';
        } elseif (preg_match('/[\x{10000}-\x{10FFFF}]/u', $value)) {
            // The warehouse items table uses utf8mb3, including its TEXT column.
            $errors[$field] = $rule['label'] . ': this character cannot be stored. Remove emoji or other unsupported symbols.';
        } elseif (preg_match_all('/./us', $value) > $rule['maxLength']) {
            $errors[$field] = $rule['label'] . ': use at most ' . $rule['maxLength'] . ' characters.';
        } elseif (isset($rule['maxBytes']) && strlen($value) > $rule['maxBytes']) {
            $errors[$field] = $rule['label'] . ': the text is too long. Please shorten it.';
        }
    }
    return ['data' => $data, 'input' => $input, 'errors' => $errors];
}

/**
 * Returns false for an existing barcode. Call only with successfully validated data.
 * Serialize this form's check and insert; existing duplicate rows prevent adding
 * a UNIQUE index without a separate data cleanup.
 */
function add_item_insert(PDO $pdo, array $data): bool
{
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'darkscrub:add_item:' . sha1($database);
    $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the item creation lock.');
    }

    try {
        $duplicate = $pdo->prepare('SELECT 1 FROM items WHERE barcode = :barcode LIMIT 1');
        $duplicate->execute(['barcode' => $data['barcode']]);
        if ($duplicate->fetchColumn() !== false) {
            return false;
        }

        $fields = array_keys(add_item_rules());
        $columns = implode(', ', $fields);
        $placeholders = ':' . implode(', :', $fields);
        $stmt = $pdo->prepare("INSERT INTO items ($columns) VALUES ($placeholders)");
        $stmt->execute($data);
        return true;
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        } catch (Throwable $e) {
            error_log('add_item lock release: ' . $e->getMessage());
        }
    }
}