<?php
declare(strict_types=1);
/** A compatibility is the whole tuple, never a modelcode alone. */
function pb_compatibility_key(array $row): string
{
    return json_encode([$row['brand'],$row['model'],$row['year_range'],$row['modelcode']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
function pb_model_catalogue(PDO $pdo): array
{
    return $pdo->query("SELECT DISTINCT brand,model,rangeyear AS year_range,modelcode FROM scrubdata WHERE brand<>'' AND model<>'' AND rangeyear<>'' AND modelcode<>'' ORDER BY brand,model,rangeyear DESC,modelcode")->fetchAll(PDO::FETCH_ASSOC);
}
/** One batch query for the current page; retain a fallback for older single-model records. */
function pb_product_compatibilities(PDO $pdo, array $products): array
{
    $result=[]; $ids=[];
    foreach ($products as $product) { $id=(int)$product['id']; $ids[]=$id; $result[$id]=[]; }
    if (!$ids) return $result;
    $stmt=$pdo->prepare('SELECT product_id,brand,model,year_range,modelcode FROM product_builder_compatibility WHERE product_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY sort_order,id');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $id=(int)$row['product_id']; unset($row['product_id']); $result[$id][]=$row; }
    foreach ($products as $product) {
        $id=(int)$product['id'];
        if ($product['category_kind']!=='specific') $result[$id]=[];
        elseif (!$result[$id] && !empty($product['modelcode'])) $result[$id][]=array_intersect_key($product,array_flip(['brand','model','year_range','modelcode']));
    }
    return $result;
}
function pb_validate_compatibilities(PDO $pdo, array $data, int $id): array
{
    if ($data['category_kind']!=='specific') return [];
    $existing=[];
    if ($id) {
        $stmt=$pdo->prepare('SELECT id,category_kind,brand,model,year_range,modelcode FROM product_builder_products WHERE id=?');$stmt->execute([$id]);
        if ($product=$stmt->fetch(PDO::FETCH_ASSOC)) $existing=pb_product_compatibilities($pdo,[$product])[$id];
    }
    $input=$data['compatibility'] ?? null;
    if (!array_key_exists('compatibility',$data)) {
        // Older clients can edit other fields without silently deleting additional models.
        if (count($existing)>1) {
            foreach (['brand','model','year_range','modelcode'] as $field) if (isset($data[$field]) && $data[$field]!==$existing[0][$field]) throw new InvalidArgumentException('Refresh Product Builder before changing multiple compatible models.');
            return $existing;
        }
        $input=[array_intersect_key($data,array_flip(['brand','model','year_range','modelcode']))];
    }
    // Keep list validation compatible with PHP 8.0, which has no array_is_list().
    if (!is_array($input) || !$input || count($input)>2000 || array_keys($input)!==range(0,count($input)-1)) throw new InvalidArgumentException('Select between 1 and 2000 compatible models.');
    $allowed=[];
    // Keep historical selections if a model was removed from Models Database later.
    foreach (array_merge($existing,pb_model_catalogue($pdo)) as $row) $allowed[pb_compatibility_key($row)]=$row;
    $selected=[];
    foreach ($input as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid compatible model selection.');
        $clean=[];
        foreach (['brand'=>100,'model'=>150,'year_range'=>50,'modelcode'=>7] as $field=>$length) $clean[$field]=pb_text($row[$field] ?? '',$field,$length,$field!=='year_range');
        $key=pb_compatibility_key($clean);
        if (!isset($allowed[$key])) throw new InvalidArgumentException('A selected model is no longer available. Refresh models and check the selection.');
        $selected[$key]=$allowed[$key];
    }
    return array_values($selected);
}
function pb_save_compatibilities(PDO $pdo, int $productId, array $rows): void
{
    $pdo->prepare('DELETE FROM product_builder_compatibility WHERE product_id=?')->execute([$productId]);
    $insert=$pdo->prepare('INSERT INTO product_builder_compatibility (product_id,brand,model,year_range,modelcode,sort_order) VALUES (?,?,?,?,?,?)');
    foreach ($rows as $index=>$row) $insert->execute([$productId,$row['brand'],$row['model'],$row['year_range'],$row['modelcode'],$index]);
}
