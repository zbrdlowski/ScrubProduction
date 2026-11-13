<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/sales.php';
function pb_listing_channels(): array
{
    return ['web'=>'Web','ebay'=>'eBay','amazon'=>'Amazon'];
}
function pb_channel_listing_default(int $productId, string $channel): array
{
    return ['product_id'=>$productId,'channel'=>$channel,'status'=>'do_not_list','url'=>null,'price'=>null,'currency'=>'EUR','listed_date'=>null,'sold_quantity'=>null,'version'=>0];
}
/** Fetch the three independent channel states for a whole result page with batched sales counts. */
function pb_channel_listings(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if (!$productIds) return [];
    $result = [];
    foreach ($productIds as $id) foreach (pb_listing_channels() as $channel=>$label) $result[$id][$channel] = pb_channel_listing_default($id, $channel);
    $stmt = $pdo->prepare('SELECT product_id,channel,listing_status,listing_url,listed_price,listed_currency,listed_date,version FROM product_builder_channel_listings WHERE product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')');
    $stmt->execute($productIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset(pb_listing_channels()[$row['channel']])) continue;
        $result[(int)$row['product_id']][$row['channel']] = ['product_id'=>(int)$row['product_id'],'channel'=>$row['channel'],'status'=>$row['listing_status'],'url'=>$row['listing_url'],'price'=>$row['listed_price'],'currency'=>$row['listed_currency'],'listed_date'=>$row['listed_date'],'version'=>(int)$row['version']];
    }
    $sales = pb_listing_sales($pdo, $productIds);
    foreach ($result as $id=>&$channels) foreach ($channels as $channel=>&$listing) $listing['sold_quantity'] = $sales[$id][$channel];
    unset($channels, $listing);
    return $result;
}
/** Channel edits never overwrite suggested prices, product revisions or components. */
function pb_save_channel_listing(PDO $pdo, array $data, ?int $userId): array
{
    $id = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $version = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    $channel = $data['channel'] ?? null; $status = $data['status'] ?? null;
    if (!$id || $version === false || $version === null || !is_string($channel) || !array_key_exists($channel, pb_listing_channels()) || !in_array($status, ['do_not_list','list','listed'], true)) throw new InvalidArgumentException('Invalid listing channel or status.');
    $price = null; $currency = null; $url = null; $listedDate = null;
    if ($status === 'listed') {
        $listedDate = pb_text($data['listed_date'] ?? '', 'Listing date', 10, false);
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $listedDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $listedDate) : false;
        if (!$date || $date->format('Y-m-d') !== $listedDate || $listedDate < '1000-01-01') throw new InvalidArgumentException('Enter a valid listing date.');
        $price = pb_price($data['price'] ?? '');
        if ($price === null) throw new InvalidArgumentException('Enter the listed price.');
        $currency = strtoupper(pb_text($data['currency'] ?? '', 'Currency', 3, false));
        if (!in_array($currency, pb_price_currencies(), true)) throw new InvalidArgumentException('Select a supported listing currency.');
        $url = pb_text($data['url'] ?? '', 'Listing URL', 2048, false);
        $parts = parse_url($url);
        if (!$url || preg_match('/[\x00-\x20\x7F]/', $url) || !filter_var($url, FILTER_VALIDATE_URL) || !$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Enter a valid http:// or https:// listing URL.');
    }
    $pdo->beginTransaction();
    try {
        // Lock the parent too: two initial writes to the same missing channel cannot both succeed.
        $findProduct = $pdo->prepare('SELECT id FROM product_builder_products WHERE id=? FOR UPDATE'); $findProduct->execute([$id]);
        if (!$findProduct->fetchColumn()) throw new InvalidArgumentException('Product not found.');
        $find = $pdo->prepare('SELECT * FROM product_builder_channel_listings WHERE product_id=? AND channel=? FOR UPDATE'); $find->execute([$id,$channel]); $current = $find->fetch(PDO::FETCH_ASSOC);
        if ((int)($current['version'] ?? 0) !== $version) throw new InvalidArgumentException('Another user has changed this channel. Reload the latest channel data and try again.');
        // Keep the last real price and URL for reuse when a listing is scheduled again.
        if ($status !== 'listed') { $price = $current['listed_price'] ?? null; $currency = $current['listed_currency'] ?? 'EUR'; $url = $current['listing_url'] ?? null; $listedDate = $current['listed_date'] ?? null; }
        if ($current) {
            $save = $pdo->prepare('UPDATE product_builder_channel_listings SET listing_status=?,listing_url=?,listed_price=?,listed_currency=?,listed_date=?,version=version+1,updated_by=? WHERE product_id=? AND channel=?');
            $save->execute([$status,$url,$price,$currency,$listedDate,$userId,$id,$channel]);
        } else {
            $save = $pdo->prepare('INSERT INTO product_builder_channel_listings (product_id,channel,listing_status,listing_url,listed_price,listed_currency,listed_date,updated_by) VALUES (?,?,?,?,?,?,?,?)');
            $save->execute([$id,$channel,$status,$url,$price,$currency,$listedDate,$userId]);
        }
        $pdo->commit();
        return ['product_id'=>(int)$id,'channel'=>$channel,'status'=>$status,'url'=>$url,'price'=>$price,'currency'=>$currency,'listed_date'=>$listedDate,'version'=>$version+1];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
