<?php
declare(strict_types=1);
// Price history: recording on change, the summary used for "Lowest seen", and what the public endpoint adds.
require dirname(__DIR__) . '/lib/harness.php';
load_site();

$res = static fn(string $status, ?int $price, string $at) => ['status'=>$status, 'price'=>$price, 'qty'=>null, 'from_price'=>false,
    'observed_at'=>$at, 'source'=>'automatic', 'detail'=>'x'];
$state = ['history'=>[]];

record_history($state, 'a', $res('in_stock', 5000, '2026-10-01T00:00:00+00:00'));
check('the first reading is recorded', count($state['history']['a']) === 1);
record_history($state, 'a', $res('in_stock', 5000, '2026-10-02T00:00:00+00:00'));
check('the same price and status adds nothing', count($state['history']['a']) === 1);
record_history($state, 'a', $res('in_stock', 4500, '2026-10-10T00:00:00+00:00'));
record_history($state, 'a', $res('out_of_stock', 4500, '2026-10-12T00:00:00+00:00'));
record_history($state, 'a', $res('in_stock', 4500, '2026-10-14T00:00:00+00:00'));
record_history($state, 'a', $res('in_stock', 4900, '2026-10-20T00:00:00+00:00'));
check('price and stock changes are recorded', count($state['history']['a']) === 5);
record_history($state, 'a', $res('unknown', 4900, '2026-10-21T00:00:00+00:00'));
record_history($state, 'a', $res('in_stock', 1, ''));
check('unverified and unobserved readings are ignored', count($state['history']['a']) === 5);
record_history($state, 'b', $res('in_stock', null, '2026-10-01T00:00:00+00:00'));
check('a reading without a price still records the status', isset($state['history']['b'][0]) && $state['history']['b'][0][1] === null && $state['history']['b'][0][2] === 'in_stock');
for ($i = 0; $i < 150; $i++) {
    record_history($state, 'c', $res('in_stock', 1000 + $i, '2026-10-01T00:00:00+00:00'));
}
check('history is capped', count($state['history']['c']) === HISTORY_POINTS);

$sum = history_summary($state['history']['a']);
check('low and high', $sum['low'] === 4500 && $sum['high'] === 5000);
check('"was" is the price before the last change', $sum['was']['price'] === 4500);
$flat = history_summary([[1, 5000, 'in_stock']]);
check('one price: no change', $flat['low'] === 5000 && $flat['high'] === 5000 && $flat['was'] === null);
check('empty history', history_summary([])['low'] === null);
$drop = history_summary([[100, 7495, 'in_stock'], [200, 6797, 'in_stock'], [300, 6797, 'out_of_stock']]);
check('the time of the drop is when the current price began', $drop['was']['price'] === 7495 && $drop['was']['t'] === gmdate('c', 200));

// through the real scan path, on a throwaway data folder
$dir = data_dir();
$s = load_state($dir);
$id = $s['items'][0]['id'];
$host = seller_bucket(product_url($s['items'][0]['url'])['host']);
$fp = scan_fingerprint($s['items'][0]);
store_scan($dir, $id, $fp, $host, $res('in_stock', 5000, iso_now()), null, 0);
check('a scan records the first point', count(load_state($dir)['history'][$id] ?? []) === 1);
store_scan($dir, $id, $fp, $host, $res('in_stock', 5000, iso_now()), null, 0);
store_scan($dir, $id, $fp, $host, $res('in_stock', 4500, iso_now()), null, 0);
check('a scan adds a point only on change', count(load_state($dir)['history'][$id]) === 2);

$public = endpoint_result();
$row = array_values(array_filter($public['items'], static fn($i) => $i['id'] === $id))[0];
check('the catalog carries low, high and was', $row['low'] === 4500 && $row['high'] === 5000 && $row['was']['price'] === 5000);
$other = array_values(array_filter($public['items'], static fn($i) => $i['id'] !== $id))[0];
check('listings without history have null fields', $other['low'] === null && $other['was'] === null);
finish();
