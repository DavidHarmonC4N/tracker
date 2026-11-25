<?php
declare(strict_types=1);
// Per-radio pages: grouping by product link, stable addresses, lookup and redirect rules, the history chart and table, similar radios.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();
require_once $site . '/includes/radios.php';
require_once $site . '/includes/radio.php';

$now = time();
$fresh = gmdate('c', $now - 600);
$offer = static fn(string $id, string $warehouse, int $price, string $status = 'in_stock', array $over = []) => $over + [
    'id'=>$id, 'name'=>'SenseCAP Card Tracker T1000-E', 'seller'=>'Seeed Studio', 'location'=>'', 'url'=>'https://www.seeedstudio.com/t1000e.html?utm_source=x',
    'firmware'=>'meshtastic', 'setup'=>'meshtastic', 'kind'=>'tracker', 'role'=>'tracker', 'note'=>'Card-sized GPS tracker.', 'warehouse'=>$warehouse,
    'manual'=>false, 'check_failed'=>false, 'result'=>['status'=>$status, 'price'=>$price, 'qty'=>null, 'from_price'=>false, 'observed_at'=>$fresh]];

// slugs and codes
check('a slug is lowercase letters, digits and dashes', radio_slug('Heltec V4 · Complete Mesh Node!') === 'heltec-v4-complete-mesh-node');
check('accents never leave odd characters in a slug', preg_match('/^[a-z0-9-]+$/D', radio_slug('Café Radio Ü')) === 1 && str_contains(radio_slug('Café Radio Ü'), 'radio'));
check('an empty or symbol-only name gives an empty slug', radio_slug('***') === '');
check('a long name is cut at 60 characters without a trailing dash', strlen(radio_slug(str_repeat('abc def ', 30))) <= 60 && !str_ends_with(radio_slug(str_repeat('abc def ', 30)), '-'));
check('the code is eight hex characters and stable', radio_hash('seeedstudio.com/x') === radio_hash('seeedstudio.com/x') && preg_match('/^[0-9a-f]{8}$/D', radio_hash('a')) === 1 && radio_hash('a') !== radio_hash('b'));

// grouping: one page per product link, whatever the tracking parameters, with one offer per warehouse
$items = [
    $offer('t-eu', 'eu', 4490), $offer('t-us', 'us', 3990), $offer('t-cn', 'cn', 3590, 'out_of_stock'),
    $offer('t-tracked', 'us', 3990, 'in_stock', ['url'=>'https://seeedstudio.com/t1000e.html?gclid=1&utm_medium=y']),
    $offer('heltec', '', 5200, 'orderable', ['name'=>'Heltec V4', 'seller'=>'erodbuilds', 'url'=>'https://shop.erodbuilds.com/products/heltec', 'kind'=>'handheld', 'role'=>'companion']),
    $offer('other-tracker', '', 6100, 'in_stock', ['name'=>'Wio Tracker L1', 'seller'=>'Seeed Studio', 'url'=>'https://www.seeedstudio.com/wio.html']),
];
$groups = radio_groups($items);
check('listings sharing a product link become one page', count($groups) === 3, (string)count($groups));
$tracker = array_values(array_filter($groups, static fn($g) => $g['name'] === 'SenseCAP Card Tracker T1000-E'))[0];
check('www and tracking parameters do not split a radio', count($tracker['offers']) === 4);
check('offers are ordered U.S., EU, China', array_column(array_slice($tracker['offers'], 0, 2), 'warehouse') === ['us', 'us'] && in_array($tracker['offers'][2]['warehouse'], ['eu', 'cn'], true) && $tracker['offers'][3]['warehouse'] === 'cn');
check('the page reference is the name, the seller and the code', $tracker['ref'] === 'sensecap-card-tracker-t1000-e-seeed-studio-' . $tracker['hash'], $tracker['ref']);
check('two different radios never share a reference', count(array_unique(array_column($groups, 'ref'))) === 3);
check('every listing gets its radio\'s page address', radio_page_map($groups)['t-eu'] === 'radio.php?r=' . $tracker['ref'] && radio_page_map($groups)['heltec'] !== radio_page_map($groups)['t-eu']);
check('a listing with an unusable link gets no page and does not break the rest', count(radio_groups([$offer('bad', '', 1, 'in_stock', ['url'=>'not a url']), $offer('ok', '', 1)])) === 1);

// lookup and canonical address
check('the exact reference finds the radio', radio_find($groups, $tracker['ref'])['hash'] === $tracker['hash']);
check('an out-of-date name part still finds it, so it can be redirected', radio_find($groups, 'old-name-' . $tracker['hash'])['hash'] === $tracker['hash'] && radio_find($groups, $tracker['hash'])['hash'] === $tracker['hash']);
foreach (['', 'nothing-here-00000000', 'x-zzzzzzzz', '<script>', "../../etc/passwd", str_repeat('a', 400), 'UPPER-' . strtoupper($tracker['hash'])] as $bad) {
    check('an unknown or malformed reference finds nothing: ' . substr($bad, 0, 24), radio_find($groups, $bad) === null);
}

// summary: the best status among offers
$summary = radio_group_summary($tracker, $now);
check('the group status is the best of its offers', $summary['status'] === 'in_stock');
check('the group price is the lowest price at that status', $summary['price'] === 3990, (string)$summary['price']);
check('the latest check time is the newest', $summary['observed'] === (int)strtotime($fresh));
$old = radio_group_summary(['offers'=>[$offer('a', '', 777, 'in_stock', ['result'=>['status'=>'in_stock', 'price'=>777, 'observed_at'=>gmdate('c', $now - 200000)]])]], $now);
check('with every reading old, the group is unverified and keeps the last price', $old['status'] === 'unknown' && $old['price'] === 777, json_encode($old));

// words
$text = radio_summary_text($tracker, $summary);
check('the summary names the radio, the seller, the firmware and the price', str_contains($text, 'SenseCAP Card Tracker T1000-E is a GPS tracker sold by Seeed Studio') && str_contains($text, 'Firmware: Meshtastic only') && str_contains($text, 'in stock at $39.90'), $text);
$unverified = radio_summary_text($tracker, ['status'=>'unknown', 'price'=>3990, 'observed'=>0]);
check('an unverified radio says plainly that there is no recent reading', str_contains($unverified, 'no recent reading') && str_contains($unverified, '$39.90') && !str_contains($unverified, 'unverified at'));
$meta = radio_meta_description($tracker, $summary);
check('the meta description is short and factual', strlen($meta) <= 170 && str_starts_with($meta, 'SenseCAP Card Tracker T1000-E at Seeed Studio: in stock, $39.90.'), $meta);

// history
$t = $now - 20 * 86400;
$history = ['t-us'=>[[$t, 4490, 'in_stock'], [$t + 8 * 86400, 3990, 'in_stock'], [$t + 15 * 86400, 3990, 'out_of_stock']], 't-eu'=>[[$t, 4490, 'in_stock']], 't-cn'=>[[$t, null, 'in_stock']]];
$series = radio_series($tracker, $history);
check('each offer gets a series with only its priced points', count($series) === 4 && count(array_filter($series, static fn($s) => count($s['points']) === 3)) === 1 && count(array_filter($series, static fn($s) => $s['points'] === [])) === 2, json_encode(array_map(static fn($s) => count($s['points']), $series)));
$svg = radio_history_svg($series, $now, 'Price history for <b>x</b>');
check('a chart is drawn once a price has moved', str_starts_with($svg, '<svg') && str_contains($svg, '<path') && str_contains($svg, 'role="img"'));
check('the chart is labelled for screen readers and escapes its title', str_contains($svg, '<title id="hist-title">Price history for &lt;b&gt;x&lt;/b&gt;</title>') && str_contains($svg, '<desc'));
check('the chart shows dollar amounts', str_contains($svg, '$39.90') && str_contains($svg, '$44.90'));
check('no chart is drawn when no price has moved', radio_history_svg([['label'=>'Price', 'points'=>[[$t, 3990, 'in_stock'], [$t + 100, 3990, 'out_of_stock']]]], $now, 'x') === '' && radio_history_svg([], $now, 'x') === '');
$rows = radio_change_rows($tracker, $history);
check('the change table lists the newest first', $rows[0]['t'] >= $rows[1]['t'] && $rows[0]['t'] === $t + 15 * 86400);
check('the change table names the warehouse', in_array('U.S. warehouse', array_column($rows, 'label'), true));
check('the change table is capped', count(radio_change_rows($tracker, ['t-us'=>array_map(static fn($i) => [$t + $i, 100 + $i, 'in_stock'], range(1, 40))], 12)) === 12);

// similar radios
$similar = radio_similar($groups, $tracker, $now);
check('similar radios are the same type, never the radio itself', $similar && array_unique(array_column(array_column($similar, 'group'), 'kind')) === ['tracker'] && !in_array($tracker['hash'], array_column(array_column($similar, 'group'), 'hash'), true));
check('similar radios are capped', count(radio_similar(array_merge($groups, array_map(static fn($i) => ['hash'=>'h' . $i, 'kind'=>'tracker', 'name'=>'N' . $i, 'offers'=>[$offer('x' . $i, '', 100)]], range(1, 20))), $tracker, $now, 5)) === 5);
finish();
