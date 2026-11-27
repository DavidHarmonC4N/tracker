<?php
declare(strict_types=1);
// Reading a Shopify store's whole product feed at once: conversion, paging, the claim limits, and what the runner may report.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

$feed = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/shopify-feed.json'), true)['products'];
$single = $feed[0];
$multi = $feed[1];
$jsSingle = (string)file_get_contents(dirname(__DIR__) . '/fixtures/shopify-js-alfa-network-aoa-915.json');
$jsMulti = (string)file_get_contents(dirname(__DIR__) . '/fixtures/shopify-js-color-meshtac-2-5-db.json');
$item = static fn(string $handle, array $over = []) => $over + ['url'=>'https://store.rokland.com/products/' . $handle, 'variant'=>'', 'mode'=>'auto'];

// the feed gives the same answer as the page's own feed
$viaFeed = shopify_result(json_encode(shopify_feed_to_product($single)), $item($single['handle']));
$viaPage = shopify_result($jsSingle, $item($single['handle']));
check('a one-variant product reads the same from the bulk feed and from its page', $viaFeed['status'] === $viaPage['status'] && $viaFeed['price'] === $viaPage['price'] && $viaFeed['from_price'] === $viaPage['from_price'], json_encode([$viaFeed['price'], $viaPage['price']]));
check('the price is read in cents', $viaFeed['price'] === 12997, (string)$viaFeed['price']);
$multiFeed = shopify_result(json_encode(shopify_feed_to_product($multi)), $item($multi['handle']));
$multiPage = shopify_result($jsMulti, $item($multi['handle']));
check('a several-variant product reads the same both ways', $multiFeed['status'] === $multiPage['status'] && $multiFeed['price'] === $multiPage['price'] && $multiFeed['from_price'] === $multiPage['from_price']);
$colour = $multi['variants'][1]['option1'];
check('a variant chosen by option text matches in the bulk feed', shopify_result(json_encode(shopify_feed_to_product($multi)), $item($multi['handle'], ['variant'=>$colour]))['price'] === $multiPage['price']);
check('a variant chosen by id matches in the bulk feed', shopify_result(json_encode(shopify_feed_to_product($multi)), $item($multi['handle'] . '?variant=' . $multi['variants'][1]['id']))['price'] === $multiPage['price']);
check('a product with no readable variants is skipped', shopify_feed_to_product(['title'=>'x', 'variants'=>[['id'=>'a']]]) === null && shopify_feed_to_product(['variants'=>[]]) === null);
check('an unavailable variant is out of stock in the bulk feed', (static function () use ($single) {
    $p = $single; $p['variants'][0]['available'] = false;
    return shopify_result(json_encode(shopify_feed_to_product($p)), ['url'=>'https://x.test/products/a', 'variant'=>''])['status'];
})() === 'out_of_stock');

// handles
check('a handle is read from a product link', shopify_handle('https://store.rokland.com/products/Blade-Antenna?variant=1') === 'blade-antenna' && shopify_handle('https://x.test/collections/a') === null && shopify_handle('nonsense') === null);

// paging
$filler = static fn(int $from, int $count) => array_map(static fn($n) => ['handle'=>'filler-' . $n, 'title'=>'F', 'variants'=>[['id'=>$n + 1, 'title'=>'Default Title', 'option1'=>'Default Title', 'available'=>true, 'price'=>'1.00']]], range($from, $from + $count - 1));
$pages = [1=>array_merge($filler(0, 99), [$single]), 2=>array_merge($filler(100, 99), [$multi]), 3=>$filler(200, 5)];
$calls = [];
$fetch = static function (string $url) use (&$calls, $pages): array {
    preg_match('/page=(\d+)/', $url, $m);
    $calls[] = $url;
    return [200, json_encode(['products'=>$pages[(int)$m[1]] ?? []])];
};
$found = shopify_bulk_lookup('store.rokland.com', [$single['handle'], $multi['handle']], $fetch);
check('both wanted products are found across two pages', count($found) === 2 && isset($found[$single['handle']], $found[$multi['handle']]));
check('reading stops once everything wanted is found', count($calls) === 2, (string)count($calls));
check('every request is HTTPS to the store with a page size and number', (bool)preg_match('~^https://store\.rokland\.com/products\.json\?limit=100&page=1$~', $calls[0]));
$calls = [];
check('a product that is not in the feed is simply missing', array_keys(shopify_bulk_lookup('store.rokland.com', [$single['handle'], 'nothing-here'], $fetch)) === [$single['handle']] && count($calls) === 3, count($calls) . ' calls');
check('the feed is not read forever', (static function () {
    $n = 0;
    shopify_bulk_lookup('x.test', ['nope'], static function () use (&$n) { $n++; return [200, json_encode(['products'=>array_map(static fn($i) => ['handle'=>'h' . $i], range(1, 100))])]; });
    return $n;
})() === BULK_MAX_PAGES);
check('a store that refuses or sends junk gives no results', shopify_bulk_lookup('x.test', ['a'], static fn() => [403, 'blocked']) === [] && shopify_bulk_lookup('x.test', ['a'], static fn() => [200, '<html>']) === [] && shopify_bulk_lookup('x.test', ['a'], static function () { throw new ScanFailure('no'); }) === []);

// a bulk-read item is used by scan_item without touching the network
$read = scan_item($item($single['handle']), sys_get_temp_dir(), [$single['handle'] => shopify_feed_to_product($single)]);
check('scan_item uses the bulk feed for a listing it contains', $read['price'] === 12997 && str_contains($read['detail'], 'Store product feed'));

// the claim limits and what the server remembers
$dir = sys_get_temp_dir() . '/offgrid-bulk-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
change_state($dir, static function (&$state) { foreach ($state['items'] as &$row) { $row['checker'] = 'remote'; $row['paused'] = false; } });
$rok = static fn(array $jobs) => count(array_filter($jobs, static fn($j) => str_contains($j['url'], 'store.rokland.com')));
check('a store not known to have a readable feed is claimed three pages at a time', $rok(claim_jobs($dir, 60)) === 3);
accept_results($dir, [], ['store.rokland.com', 'not-a-seller.example', 7], []);
$state = load_state($dir);
check('the server remembers a store whose feed the runner read, and only known stores', isset($state['bulk_hosts']['store.rokland.com']) && !isset($state['bulk_hosts']['not-a-seller.example']) && count($state['bulk_hosts']) === 1);
change_state($dir, static function (&$state) { $state['host_next_scan'] = []; foreach ($state['items'] as &$row) { $row['attempted_at'] = null; } });
$all = claim_jobs($dir, 60);
check('a store with a readable feed is claimed whole', $rok($all) > 20, (string)$rok($all));
$pagesPerHost = [];
foreach ($all as $j) { if (!str_contains($j['url'], 'rokland')) { $pagesPerHost[parse_url($j['url'], PHP_URL_HOST)][$j['url']] = true; } }
check('other stores keep the three-page limit', $pagesPerHost && max(array_map('count', $pagesPerHost)) <= 3);
accept_results($dir, [], [], ['store.rokland.com']);
check('a store whose feed stopped working loses the flag', !isset(load_state($dir)['bulk_hosts']['store.rokland.com']));
change_state($dir, static function (&$state) { $state['bulk_hosts']['store.rokland.com'] = time() - (BULK_HOST_DAYS + 1) * 86400; $state['host_next_scan'] = []; foreach ($state['items'] as &$row) { $row['attempted_at'] = null; } });
check('a flag nobody has confirmed for two weeks expires', $rok(claim_jobs($dir, 60)) === 3);

// ---- a store whose products have several option sets (MeshSmith's boards): choose by the frequency option
$option = static fn(string $freq, string $mod, string $solar, int $cents, bool $avail, int $id) => ['id'=>$id, 'title'=>$freq . ' / ' . $mod . ' / ' . $solar, 'option1'=>$freq, 'option2'=>$mod, 'option3'=>$solar, 'available'=>$avail, 'price'=>number_format($cents / 100, 2, '.', '')];
$photon = ['handle'=>'photon-1w', 'title'=>'Photon-1W', 'variants'=>[
    $option('915 Mhz (USA)', 'NRF52', 'No', 7500, true, 1), $option('915 Mhz (USA)', 'NRF52', 'Yes', 9500, true, 2), $option('915 Mhz (USA)', 'None (Diy)', 'No', 6500, true, 3),
    $option('868 Mhz', 'NRF52', 'No', 7500, false, 4), $option('868 Mhz', 'None (Diy)', 'No', 6500, false, 5)]];
$photonBody = json_encode(shopify_feed_to_product($photon));
$us = shopify_result($photonBody, ['url'=>'https://shop.meshsmith.net/products/photon-1w', 'variant'=>'915 Mhz (USA)']);
check('a frequency option picks the 915 MHz variants, and the price is the cheapest of them', $us['status'] === 'orderable' && $us['price'] === 6500 && $us['from_price'] === true, json_encode($us));
$eu = shopify_result($photonBody, ['url'=>'https://shop.meshsmith.net/products/photon-1w', 'variant'=>'868 Mhz']);
check('the 868 MHz variants are separate: all sold out here', $eu['status'] === 'out_of_stock');
check('a feed price in dollars is read as cents for these options too', shopify_feed_to_product($photon)['variants'][0]['price'] === 7500);
$oneOut = ['handle'=>'pimesh', 'title'=>'PiMesh-1W', 'variants'=>[$option('915 Mhz (USA)', 'No GPS', 'None', 6000, false, 1), $option('915 Mhz (USA)', 'With GPS', 'None', 7000, true, 2)]];
$pi = shopify_result(json_encode(shopify_feed_to_product($oneOut)), ['url'=>'https://shop.meshsmith.net/products/pimesh-1w-basic', 'variant'=>'915 Mhz (USA)']);
check('when the cheapest 915 MHz option is sold out the price is the cheapest one still available', $pi['price'] === 7000 && $pi['status'] === 'orderable');
check('a variant that is not offered is an error, not a wrong price', (static function () use ($photonBody) {
    try { shopify_result($photonBody, ['url'=>'https://shop.meshsmith.net/products/photon-1w', 'variant'=>'433 Mhz']); } catch (ScanFailure) { return true; }
    return false;
})());
finish();
