<?php
declare(strict_types=1);
// Price and stock events, price parsing, robots rules, and how a stored scan is handled. Each guards a mistake found in review.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

$now = time();
$row = static fn(string $status, ?int $price, int $age = 600) => ['id'=>'x', 'name'=>'Radio', 'seller'=>'Shop', 'result'=>['status'=>$status, 'price'=>$price, 'observed_at'=>gmdate('c', $now - $age)]];
$reading = static fn(string $status, ?int $price) => ['status'=>$status, 'price'=>$price];
$types = static fn(array $events) => array_column($events, 'type');

check('a price drop while on sale is an event', $types(detect_events($row('in_stock', 5000), $reading('in_stock', 4000), [], $now)) === ['price_drop']);
check('a price rise while on sale is an event', $types(detect_events($row('orderable', 4000), $reading('in_stock', 5000), [], $now)) === ['price_rise']);
check('coming back in stock at a different price reports the stock, not a price change', $types(detect_events($row('out_of_stock', 3000), $reading('in_stock', 5000), [], $now)) === ['back_in_stock']);
check('selling out at a different price reports the stock only', $types(detect_events($row('in_stock', 5000), $reading('out_of_stock', 3000), [], $now)) === ['sold_out']);
check('a price change on a sold-out item is not reported', detect_events($row('out_of_stock', 5000), $reading('out_of_stock', 4000), [], $now) === []);
$history = [[$now - 86400, 4000, 'in_stock'], [$now - 3600, 5000, 'in_stock']];
check('a price that returns to one seen in the last three days is not reported again', detect_events($row('in_stock', 5000), $reading('in_stock', 4000), $history, $now) === []);
check('an old price from weeks ago does not hide a real drop', $types(detect_events($row('in_stock', 5000), $reading('in_stock', 4000), [[$now - 20 * 86400, 4000, 'in_stock']], $now)) === ['price_drop']);
check('a new price never seen before is reported even with history', $types(detect_events($row('in_stock', 5000), $reading('in_stock', 3500), $history, $now)) === ['price_drop']);
check('the first reading of a listing makes no events', detect_events(['id'=>'x', 'name'=>'R', 'seller'=>'S', 'result'=>['status'=>'unknown', 'price'=>null, 'observed_at'=>null]], $reading('in_stock', 4000), [], $now) === []);

// zero and strange prices
check('a zero price is not a price', result('in_stock', 0, 'x')['price'] === null && result('in_stock', -5, 'x')['price'] === null && result('in_stock', 4000, 'x')['price'] === 4000);
check('a price with extra decimals is rounded', seller_price_cents('59.0000') === 5900 && seller_price_cents('49.995') === 5000 && seller_price_cents('1,299.00') === 129900 && seller_price_cents('0.3') === 30);
foreach (['0', '0.00', '', 'free', '12abc', '-5', '99999999'] as $bad) {
    $refused = false;
    try { seller_price_cents($bad); } catch (InvalidArgumentException) { $refused = true; }
    check('"' . $bad . '" is refused as a price', $refused);
}

// robots.txt
$robots = "User-agent: *\nDisallow:\nUser-agent: BadBot\nDisallow: /\n";
check('an empty Disallow does not merge with the group after it', robots_allows('https://shop.example.com/products/a', $robots));
check('a group that really disallows everything still blocks', !robots_allows('https://shop.example.com/products/a', "User-agent: *\nDisallow: /\n"));
check('a specific group for this tool wins over the wildcard', robots_allows('https://shop.example.com/products/a', "User-agent: *\nDisallow: /\n\nUser-agent: OffgridCommsTracker\nDisallow: /private\n"));
check('a path rule blocks only that path', !robots_allows('https://shop.example.com/cart/x', "User-agent: *\nDisallow: /cart\n") && robots_allows('https://shop.example.com/products/a', "User-agent: *\nDisallow: /cart\n"));

// stored scans
$dir = sys_get_temp_dir() . '/offgrid-events-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$id = 'rok-ant-alfa-10';
$item = static function () use ($dir, $id) { foreach (load_state($dir)['items'] as $i) { if ($i['id'] === $id) { return $i; } } return null; };
$fp = scan_fingerprint($item());
store_scan($dir, $id, $fp, 'store.rokland.com', null, 'Seller declined', $now + 86400);
store_scan($dir, $id, $fp, 'store.rokland.com', null, 'Rate limited', $now + 3600);
check('a short pause never shortens a longer one', load_state($dir)['cooldowns']['store.rokland.com'] === $now + 86400);
change_state($dir, static function (&$s) use ($id) { foreach ($s['items'] as &$r) { if ($r['id'] === $id) { $r['paused'] = true; $r['error'] = null; } } });
store_scan($dir, $id, scan_fingerprint($item()), 'store.rokland.com', ['status'=>'in_stock', 'price'=>999, 'qty'=>null, 'from_price'=>false, 'observed_at'=>gmdate('c'), 'source'=>'automatic', 'detail'=>'x'], null, 0);
check('a result that arrives for a listing paused meanwhile is dropped', $item()['result']['price'] !== 999 && $item()['error'] === null);

// results posted by the runner with odd shapes do not throw
$odd = [['id'=>['x'], 'fingerprint'=>'a', 'ok'=>true], ['id'=>$id, 'fingerprint'=>'a', 'ok'=>true, 'result'=>['status'=>['in_stock'], 'price'=>1]], 'junk', ['id'=>$id, 'fingerprint'=>'zz', 'ok'=>true, 'result'=>['status'=>'in_stock', 'price'=>1]]];
$summary = accept_results($dir, $odd);
check('malformed posted results are ignored, not fatal', $summary['stored'] === 0 && $summary['ignored'] === 4, json_encode($summary));
finish();
