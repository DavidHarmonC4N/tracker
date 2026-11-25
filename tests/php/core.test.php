<?php
declare(strict_types=1);
// Core rules: link validation, matching a store variant, change events, starter data, and what the public catalog exposes.
require dirname(__DIR__) . '/lib/harness.php';
load_site();

// product_url: only public HTTPS seller links
check('a normal HTTPS link is accepted', product_url('https://store.example.com/products/x')['host'] === 'store.example.com');
foreach (['http://store.example.com/products/x', 'https://user:pw@store.example.com/x', 'https://store.example.com:8443/x', 'https://localhost/x',
    'https://intranet.internal/x', 'https://127.0.0.1/x', 'https://store.example.com/a b', 'ftp://store.example.com/x', 'javascript:alert(1)'] as $bad) {
    check("rejected: $bad", throws(fn() => product_url($bad), InvalidArgumentException::class));
}

// listing_url_key: the same product is recognised through tracking parameters
check('tracking parameters and www do not make a different listing',
    listing_url_key('https://www.shop.com/products/a/?utm_source=x&gclid=1') === listing_url_key('https://shop.com/products/a'));
check('a different variant is a different listing', listing_url_key('https://shop.com/products/a?variant=1') !== listing_url_key('https://shop.com/products/a?variant=2'));
check('has_listing_url finds a duplicate', has_listing_url([['url'=>'https://shop.com/products/a']], 'https://shop.com/products/a?utm_medium=y'));

// cents
check('cents parses prices', cents('79.95') === 7995 && cents('0.5') === 50 && cents('') === null && cents('120') === 12000);
foreach (['1,000', '12.345', '-5', 'abc', '1234567'] as $bad) {
    check("cents rejects $bad", throws(fn() => cents($bad), InvalidArgumentException::class));
}

// shopify_result: choosing the right variant
$tdeck = ['url'=>'https://store.rokland.com/products/lilygo-t-deck', 'variant'=>'BASE (No case,No gps, no battery)'];
$feed = static fn(array $variants) => json_encode(['title'=>'T-Deck', 'variants'=>$variants]);
$variant = static fn(int $id, string $title, array $options, int $price, bool $available) => ['id'=>$id, 'title'=>$title, 'public_title'=>$title, 'options'=>$options, 'price'=>$price, 'available'=>$available];
$body = $feed([$variant(1, '915 MHz / BASE (No case, No GPS, no battery)', ['915 MHz', 'BASE (No case, No GPS, no battery)'], 6797, false),
    $variant(2, '915 MHz / BASE PLUS', ['915 MHz', 'BASE PLUS'], 8995, true)]);
$r = shopify_result($body, $tdeck);
check('variant text matches regardless of case and spacing', $r['status'] === 'out_of_stock' && $r['price'] === 6797, $r['status'] . ' ' . $r['price']);
check('a similar but different variant does not match', throws(fn() => shopify_result($feed([$variant(2, '915 MHz / BASE PLUS', ['915 MHz', 'BASE PLUS'], 8995, true)]), $tdeck), ScanFailure::class));
$byId = ['url'=>'https://shop.com/products/a?variant=2', 'variant'=>''];
check('a variant id in the link selects that variant', shopify_result($body, $byId)['price'] === 8995 && shopify_result($body, $byId)['status'] === 'orderable');
$any = shopify_result($feed([$variant(1, 'A', ['A'], 5000, false), $variant(2, 'B', ['B'], 6000, true), $variant(3, 'C', ['C'], 5500, true)]), ['url'=>'https://shop.com/products/a', 'variant'=>'']);
check('with no variant chosen, the cheapest available one is used', $any['status'] === 'orderable' && $any['price'] === 5500 && $any['from_price'] === true);
check('invalid feeds are not matched', shopify_result('not json', $tdeck) === null && shopify_result('{"variants":[]}', $tdeck) === null);

// detect_events
$base = ['id'=>'x', 'name'=>'X', 'seller'=>'S'];
$old = static fn(string $status, int $price, ?string $at = '2026-10-01T00:00:00+00:00') => $base + ['result'=>['status'=>$status, 'price'=>$price, 'observed_at'=>$at]];
$new = static fn(string $status, int $price) => ['status'=>$status, 'price'=>$price];
$types = static fn(array $events) => array_column($events, 'type');
check('back in stock', $types(detect_events($old('out_of_stock', 5000), $new('in_stock', 5000))) === ['back_in_stock']);
check('sold out', $types(detect_events($old('in_stock', 5000), $new('out_of_stock', 5000))) === ['sold_out']);
check('price drop and rise', $types(detect_events($old('in_stock', 5000), $new('in_stock', 4500))) === ['price_drop'] && $types(detect_events($old('in_stock', 5000), $new('in_stock', 5500))) === ['price_rise']);
check('a research snapshot never produces events', detect_events($old('out_of_stock', 5000, null), $new('in_stock', 4000)) === []);
check('unverified readings never produce stock events', detect_events($old('unknown', 5000), $new('in_stock', 5000)) === []);
check('no change means no events', detect_events($old('in_stock', 5000), $new('in_stock', 5000)) === []);

// starter data
$seed = seed();
check('the starter list is not empty', count($seed) > 50);
$ids = array_column($seed, 'id');
check('starter ids are unique', count($ids) === count(array_unique($ids)));
check('starter ids are safe for links, feeds and history', count(array_filter($ids, static fn($id) => !preg_match('/^[a-z0-9_-]{1,60}$/D', $id))) === 0);
$problems = [];
foreach ($seed as $item) {
    try {
        listing_fields($item + ['url'=>$item['url']]);
        if (!in_array($item['result']['status'], array_keys(STATUSES), true)) { $problems[] = $item['id'] . ' status'; }
        if ($item['result']['price'] !== null && !is_int($item['result']['price'])) { $problems[] = $item['id'] . ' price'; }
        if (!in_array($item['mode'], array_keys(MODES), true)) { $problems[] = $item['id'] . ' mode'; }
    } catch (Throwable $e) {
        $problems[] = $item['id'] . ': ' . $e->getMessage();
    }
}
check('every starter listing passes the same validation as the admin form', !$problems, implode('; ', array_slice($problems, 0, 3)));
$keys = array_map(static fn($i) => listing_url_key($i['url']) . '|' . ($i['warehouse'] ?? '') . '|' . ($i['variant'] ?? ''), $seed);
check('no two starter listings are the same link, warehouse and variant', count(array_unique($keys)) === count($seed));
check('one page can be listed once per warehouse', has_listing_url([['url'=>'https://seeedstudio.com/x.html', 'warehouse'=>'us']], 'https://seeedstudio.com/x.html', 'us')
    && !has_listing_url([['url'=>'https://seeedstudio.com/x.html', 'warehouse'=>'us']], 'https://seeedstudio.com/x.html', 'eu'));

// the public catalog exposes only public fields
$public = endpoint_result();
$allowed = ['id','seller','location','name','url','firmware','setup','kind','role','note','result','attempted_at','warehouse','manual','check_failed','change','low','high','was','topic','page','category','specs','seller_paused'];
$extra = [];
foreach ($public['items'] as $item) {
    $extra = array_merge($extra, array_diff(array_keys($item), $allowed));
}
check('catalog items carry only public fields', !$extra, implode(',', array_unique($extra)));
$json = strtolower(json_encode($public));
foreach (['password', 'ingest_token', 'setup_key', 'github_token', 'data_dir', 'ntfy_token', 'notify_webhook', 'notify_email'] as $secret) {
    check("the public catalog never mentions $secret", !str_contains($json, $secret));
}
finish();
