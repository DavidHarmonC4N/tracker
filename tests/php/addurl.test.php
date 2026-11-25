<?php
declare(strict_types=1);
// "Add by link": link cleaning, reading a store feed, guessing the form fields. The store is stubbed.
require dirname(__DIR__) . '/lib/harness.php';
load_site();

$items = seed();
foreach ($items as &$it) {
    if ($it['id'] === 'rok-tdeck') { $it['checker'] = 'remote'; }
}
unset($it);
$rok = 'https://store.rokland.com/products/wismesh-tag-from-rakwireless-mokosmart-meshtastic-compatible-card-sized-node-us915-mhz?ref=trueprepper&variant=44663304552531';
$feed = (string)file_get_contents(dirname(__DIR__) . '/fixtures/rokland-wismesh.json');

// link cleaning
$c = addurl_clean_url($rok . '&utm_source=x#frag');
check('referral and utm parameters are removed, the variant is kept',
    $c['url'] === 'https://store.rokland.com/products/wismesh-tag-from-rakwireless-mokosmart-meshtastic-compatible-card-sized-node-us915-mhz?variant=44663304552531', $c['url']);
check('removed parameters are reported', count($c['removed']) === 2);
check('http links are rejected', throws(fn() => addurl_clean_url('http://store.rokland.com/products/x'), InvalidArgumentException::class));
check('www is folded into the seller host', addurl_clean_url('https://www.etsy.com/listing/1/x')['host'] === 'etsy.com');

// reading the feed
$r = addurl_read($rok, static fn($u) => [200, $feed], $items);
check('the feed is read', $r['ok'] && count($r['product']['variants']) === 2);
check('the variant in the link is detected', addurl_link_variant($r['url']) === '44663304552531');
$navy = array_values(array_filter($r['product']['variants'], static fn($v) => $v['id'] === '44663304552531'))[0];
$g = addurl_guess($r, $navy, $items, true);
$v = $g['values'];
check('name carries the variant', str_contains($v['name'], 'Navy'), $v['name']);
check('seller and location are reused from the same seller', $v['seller'] === 'Rokland' && $v['location'] === 'Florida');
check('firmware, kind and role are guessed', $v['firmware'] === 'meshtastic' && $v['kind'] === 'tracker' && $v['role'] === 'tracker');
check('a pre-flashed page sets the setup', $v['setup'] === 'meshtastic');
check('the checker is copied from the same seller', $v['checker'] === 'remote');
check('the note drops the marketing question and has no HTML', !str_contains($v['note'], '<') && !str_starts_with($v['note'], 'What is') && str_contains($v['note'], 'Navy option'), $v['note']);
foreach (['seller', 'firmware', 'kind', 'role', 'mode', 'checker', 'note', 'url', 'name', 'setup', 'warehouse'] as $field) {
    check("a reason is given for $field", isset($g['why'][$field]));
}

// listing urls and first readings
$black = addurl_listing_url($r['url'], '43033822593107');
check('the listing url takes the chosen variant', str_ends_with($black, '?variant=43033822593107'));
check('"any variant" has no variant parameter', !str_contains(addurl_listing_url($r['url'], null), 'variant='));
$reading = addurl_reading($r['body'], $black);
check('the first reading matches the chosen variant', $reading && $reading['status'] === 'out_of_stock' && $reading['price'] === 4600);
$session = addurl_choose(['read'=>$r, 'readings'=>['44663304552531'=>$reading], 'multi'=>true], '44663304552531', $items, true);
check('choosing a variant prepares the form and the reading', $session['choice'] === '44663304552531' && is_array($session['guess']['values']) && is_array($session['reading']));

// a new seller, and a single "Default Title" variant
$single = json_encode(['title'=>'Foo Handheld MeshCore Pager', 'vendor'=>'Foo', 'description'=>'MeshCore pocket radio.', 'tags'=>[],
    'variants'=>[['id'=>1234567, 'title'=>'Default Title', 'price'=>5995, 'available'=>true, 'options'=>['Default Title']]]]);
$r2 = addurl_read('https://shop.example-radios.com/products/foo-meshcore-handheld', static fn($u) => [200, $single], $items);
$g2 = addurl_guess($r2, $r2['product']['variants'][0], $items, true);
check('a new seller is named from the web address', $g2['values']['seller'] === 'Example radios');
check('MeshCore-only is guessed', $g2['values']['firmware'] === 'meshcore');
check('Default Title adds nothing to the name', $g2['values']['name'] === 'Foo Handheld MeshCore Pager');
check('a handheld is a companion', $g2['values']['kind'] === 'handheld' && $g2['values']['role'] === 'companion');
check('a new seller defaults to the server checker', $g2['values']['checker'] === 'server');

// failures still lead somewhere useful
$blocked = addurl_read($rok, static fn($u) => [403, ''], $items);
check('a 403 is reported as blocked', !$blocked['ok'] && $blocked['reason'] === 'blocked');
$gb = addurl_guess($blocked, null, [], true);
check('a blocked page opens in page mode on the U.S. runner', $gb['values']['mode'] === 'page' && $gb['values']['checker'] === 'remote' && $gb['values']['name'] !== '');
check('a fetch exception does not crash', !addurl_read($rok, static function ($u) { throw new ScanFailure('DNS'); }, $items)['ok']);
check('a 404 means no feed', addurl_read('https://shop.example-radios.com/products/foo', static fn($u) => [404, ''], $items)['reason'] === 'no_feed');
check('bad JSON means no feed', addurl_read($rok, static fn($u) => [200, 'not json'], $items)['reason'] === 'no_feed');
check('a non-product path means no feed', addurl_read('https://shop.example-radios.com/pages/about', static fn($u) => [200, '<html>'], $items)['reason'] === 'no_feed');
$etsy = addurl_read('https://www.etsy.com/listing/123/foo', static fn($u) => [200, ''], $items);
check('Etsy goes straight to manual review', !$etsy['ok'] && $etsy['reason'] === 'manual' && addurl_guess($etsy, null, $items, true)['values']['mode'] === 'manual');
finish();
