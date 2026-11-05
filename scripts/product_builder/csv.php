<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
function pb_csv_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value));
    $value = mb_strtolower($value, 'UTF-8');
    return preg_replace('/[^a-z0-9]/', '', strtr($value, ['á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','í'=>'i','ľ'=>'l','ĺ'=>'l','ň'=>'n','ó'=>'o','ô'=>'o','ŕ'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ý'=>'y','ž'=>'z']));
}
function pb_parse_csv(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false || strlen($content) > 2 * 1024 * 1024) throw new InvalidArgumentException('The CSV file cannot be read or exceeds 2 MB.');
    if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1250');
    $stream = fopen('php://temp', 'w+'); fwrite($stream, $content); rewind($stream);
    try {
        $line = fgets($stream); rewind($stream);
        if ($line === false) throw new InvalidArgumentException('The CSV file is empty.');
        $delimiter = ','; $best = 0;
        foreach ([',',';',"\t"] as $candidate) {
            $count = count(str_getcsv($line, $candidate, '"', ''));
            if ($count > $best) { $best = $count; $delimiter = $candidate; }
        }
        $headers = array_map('pb_csv_header', fgetcsv($stream, 0, $delimiter, '"', ''));
        $aliases = ['brand'=>['znackamotorky','znacka','brand'], 'category_name'=>['pomenovanieproduktucenovarelacia','nazovkategorie','categoryname'],
            'included_models'=>['zahrnutemodely','modely','includedmodels'], 'price_unlicensed'=>['nolicense','bezlicencie','priceunlicensed'], 'price_licensed'=>['licensed','slicenciou','pricelicensed']];
        $map = [];
        foreach ($aliases as $field => $names) foreach ($headers as $i => $header) if (in_array($header, $names, true)) { $map[$field] = $i; break; }
        // The supplied CSV has an unnamed third column containing model coverage.
        if (!isset($map['included_models']) && ($headers[2] ?? null) === '') $map['included_models'] = 2;
        foreach (array_keys($aliases) as $field) if (!isset($map[$field])) throw new InvalidArgumentException('CSV columns must be: Brand, Category name, Included models, No license, Licensed. The original Slovak headers are also supported.');
        $rows = []; $seen = []; $number = 1;
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $number++;
            if (count(array_filter($row, static function ($v) { return trim((string) $v) !== ''; })) === 0) continue;
            if (count($row) !== count($headers)) throw new InvalidArgumentException('CSV row ' . $number . ': incorrect number of columns.');
            $data = []; foreach ($map as $field => $i) $data[$field] = $row[$i];
            try { $data = pb_validate_uni($data); } catch (InvalidArgumentException $e) { throw new InvalidArgumentException('CSV row ' . $number . ': ' . $e->getMessage()); }
            $key = mb_strtolower($data['brand'] . "\0" . $data['category_name'], 'UTF-8');
            if (isset($seen[$key])) throw new InvalidArgumentException('CSV row ' . $number . ': duplicate brand and category.');
            $seen[$key] = true; $rows[] = $data;
            if (count($rows) > 2000) throw new InvalidArgumentException('The CSV file may contain up to 2000 categories.');
        }
        if (!$rows) throw new InvalidArgumentException('The CSV file contains no categories.');
        return $rows;
    } finally { fclose($stream); }
}
