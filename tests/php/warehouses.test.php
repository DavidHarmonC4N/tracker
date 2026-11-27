<?php
declare(strict_types=1);
// The warehouses a listing can ship from, including the UK, and how they are named, ordered and read from a seller's wording.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();
require_once $site . '/includes/radios.php';

check('the places are U.S., EU, UK, China (and not specified)', array_keys(WAREHOUSES) === ['', 'us', 'eu', 'uk', 'cn'] && WAREHOUSES['uk'] === 'UK warehouse');
check('a listing in the UK is accepted by the form rules', (static function () {
    $post = ['url'=>'https://shop.example.com/products/x', 'firmware'=>'both', 'kind'=>'board', 'role'=>'unspecified', 'name'=>'X', 'seller'=>'Shop', 'warehouse'=>'uk'];
    return listing_fields($post)['warehouse'] === 'uk';
})());
check('an unknown place is still refused', (static function () {
    try { listing_fields(['url'=>'https://shop.example.com/products/x', 'firmware'=>'both', 'kind'=>'board', 'role'=>'unspecified', 'name'=>'X', 'seller'=>'Shop', 'warehouse'=>'mars']); } catch (InvalidArgumentException) { return true; }
    return false;
})());
foreach (['United Kingdom Warehouse' => 'uk', 'UK warehouse' => 'uk', 'U.K. stock' => 'uk', 'Great Britain' => 'uk', 'England' => 'uk', 'Scotland hub' => 'uk',
    'United States Warehouse' => 'us', 'Germany Warehouse' => 'eu', 'China Warehouse' => 'cn'] as $name => $key) {
    check('a seller\'s "' . $name . '" is read as ' . $key, warehouse_key($name) === $key);
}
check('a place that is none of these is not guessed', warehouse_key('Australia Warehouse') === null && warehouse_key('Ukraine') === null && warehouse_key('Sukkur') === null);
check('the order inside a product is U.S., EU, UK, China, then unspecified', array_keys(RADIO_WAREHOUSE_ORDER) === ['us', 'eu', 'uk', 'cn', ''] && RADIO_WAREHOUSE_ORDER['uk'] > RADIO_WAREHOUSE_ORDER['eu'] && RADIO_WAREHOUSE_ORDER['uk'] < RADIO_WAREHOUSE_ORDER['cn']);

$now = time();
$fresh = gmdate('c', $now - 600);
$offer = static fn(string $id, string $wh, int $price) => ['id'=>$id, 'name'=>'Case', 'seller'=>'Shop', 'location'=>'', 'warehouse'=>$wh, 'page'=>'radio.php?r=case-aaaaaaaa', 'url'=>'https://shop.example/' . $id,
    'firmware'=>'both', 'setup'=>'unknown', 'manual'=>false, 'check_failed'=>false, 'result'=>['status'=>'in_stock', 'price'=>$price, 'qty'=>null, 'from_price'=>false, 'observed_at'=>$fresh]];
$unit = radios_units([$offer('c-cn', 'cn', 3000), $offer('c-uk', 'uk', 3600), $offer('c-us', 'us', 4000), $offer('c-eu', 'eu', 3500)], $now)[0];
check('the plain list orders the options U.S., EU, UK, China', array_column($unit['options'], 'warehouse') === ['us', 'eu', 'uk', 'cn']);
$row = radios_unit_row($unit);
check('the plain list calls it UK, not "UK warehouse", and gives it its own link', str_contains($row, 'UK $36.00') && preg_match('~<a class="nw opt" href="https://shop\.example/c-uk"[^>]*>UK~', $row) === 1);
finish();
