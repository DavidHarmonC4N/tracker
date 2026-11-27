<?php
declare(strict_types=1);
// The plain radio list (radios.php): the status rule, escaping, link safety, grouping, and the shared header and footer.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();
require_once $site . '/includes/radios.php';

$now = time();
$fresh = gmdate('c', $now - 600);
$item = static fn(array $over = []) => $over + ['id'=>'spec-mini', 'name'=>'MiniTrekker MKII', 'seller'=>'SpecFive', 'location'=>'Texas', 'url'=>'https://specfive.com/products/x',
    'firmware'=>'both', 'setup'=>'choice', 'warehouse'=>'', 'manual'=>false, 'check_failed'=>false,
    'result'=>['status'=>'in_stock', 'price'=>5000, 'qty'=>null, 'from_price'=>false, 'observed_at'=>$fresh]];

// the status rule matches the catalog's
check('a fresh reading keeps its status', radio_status($item(), $now) === 'in_stock');
check('a reading older than 24 hours is unverified', radio_status($item(['result'=>['status'=>'in_stock', 'observed_at'=>gmdate('c', $now - 90000)]]), $now) === 'unknown');
check('a reading just inside 24 hours still counts', radio_status($item(['result'=>['status'=>'orderable', 'observed_at'=>gmdate('c', $now - 86000)]]), $now) === 'orderable');
check('a failed check is unverified', radio_status($item(['check_failed'=>true]), $now) === 'unknown');
check('a listing never checked is unverified', radio_status($item(['result'=>['status'=>'in_stock', 'observed_at'=>null]]), $now) === 'unknown');
check('an unexpected status is unverified', radio_status($item(['result'=>['status'=>'bogus', 'observed_at'=>$fresh]]), $now) === 'unknown');

// rows: escaping and link safety
$evil = radios_row($item(['id'=>'x" onmouseover="alert(1)', 'name'=>'<script>alert(1)</script>', 'seller'=>'<img src=x onerror=alert(1)>', 'location'=>'"><b>']), 'in_stock');
check('names, sellers, locations and ids are escaped', !str_contains($evil, '<script>') && !str_contains($evil, '<img') && !str_contains($evil, '"><b>') && !str_contains($evil, 'id="r-x" onmouseover'), $evil);
check('a javascript: link is never rendered as a link', !str_contains(radios_row($item(['url'=>'javascript:alert(1)']), 'in_stock'), 'href='));
check('a plain http link is not rendered either', !str_contains(radios_row($item(['url'=>'http://shop.example/x']), 'in_stock'), 'href='));
$row = radios_row($item(), 'in_stock');
check('a listing link opens in a new tab and says so', str_contains($row, 'target="_blank"') && str_contains($row, 'noopener') && str_contains($row, 'opens in a new tab'));
check('the price is shown in dollars', str_contains($row, '$50.00'));
check('a missing price says See seller', str_contains(radios_row($item(['result'=>['status'=>'in_stock', 'price'=>null, 'observed_at'=>$fresh]]), 'in_stock'), 'See seller'));
check('a starting price says From', str_contains(radios_row($item(['result'=>['status'=>'in_stock', 'price'=>5000, 'from_price'=>true, 'observed_at'=>$fresh]]), 'in_stock'), 'From $50.00'));
check('a stock count shows only for in-stock radios', str_contains(radios_row($item(['result'=>['status'=>'in_stock', 'price'=>1, 'qty'=>3, 'observed_at'=>$fresh]]), 'in_stock'), '3 shown') && !str_contains(radios_row($item(['result'=>['status'=>'out_of_stock', 'price'=>1, 'qty'=>3, 'observed_at'=>$fresh]]), 'out_of_stock'), '3 shown'));
check('the check time is a machine-readable time element', preg_match('~<time datetime="\d{4}-\d\d-\d\dT[^"]+">~', $row) === 1);
check('a hand-checked listing says so', str_contains(radios_row($item(['manual'=>true]), 'in_stock'), 'Checked by hand'));
check('each row has an anchor id', str_contains($row, 'id="r-spec-mini"'));

// grouping and order
$items = [
    $item(['id'=>'a', 'name'=>'zeta radio']),
    $item(['id'=>'b', 'name'=>'Alpha radio', 'result'=>['status'=>'out_of_stock', 'price'=>1, 'observed_at'=>$fresh]]),
    $item(['id'=>'c', 'name'=>'beta radio', 'result'=>['status'=>'in_stock', 'price'=>1, 'observed_at'=>$fresh]]),
    $item(['id'=>'d', 'name'=>'Old radio', 'result'=>['status'=>'in_stock', 'price'=>1, 'observed_at'=>gmdate('c', $now - 200000)]]),
    $item(['id'=>'e', 'name'=>'Orderable one', 'result'=>['status'=>'orderable', 'price'=>1, 'observed_at'=>$fresh]]),
];
$html = radios_tables($items, $now);
check('groups come in availability order', preg_match_all('~<h2 id="g-([a-z_]+)">~', $html, $m) && $m[1] === ['in_stock', 'orderable', 'unknown', 'out_of_stock'], json_encode($m[1] ?? []));
check('each group heading shows its count', str_contains($html, 'In stock · 2') && str_contains($html, 'Orderable · 1') && str_contains($html, 'Unverified · 1') && str_contains($html, 'Out of stock · 1'));
check('names are sorted without regard to case', strpos($html, 'beta radio') < strpos($html, 'zeta radio'));
check('empty groups are left out', !str_contains(radios_tables([$item()], $now), 'Preorder'));
check('every table has a caption and column headers for screen readers', substr_count($html, '<caption') === 4 && substr_count($html, 'scope="col"') === 4 * 5 && str_contains($html, 'scope="row"'));

// the shared header and footer come from the home page, so they cannot drift
$footer = radios_page_chrome('footer');
$header = radios_page_chrome('header');
check('the footer is the home page footer', str_contains($footer, 'site-footer') && str_contains($footer, 'whats-new') && str_contains($footer, 'KY4DEH'));
check('the header is the home page header', str_contains($header, 'class="mast"') && str_contains($header, 'Price history'));
check('anchors from the home page become links to it', !str_contains($header . $footer, 'href="#') && str_contains($header, 'href="./#inventory"'));

// ---- one row per product, cheapest first
$offer = static fn(string $id, string $wh, int $price, string $status = 'in_stock', array $over = []) => $over + $item(['id'=>$id, 'name'=>'Seeed Tracker', 'seller'=>'Seeed Studio', 'location'=>'', 'warehouse'=>$wh,
    'page'=>'radio.php?r=seeed-tracker-aaaaaaaa', 'url'=>'https://shop.example/' . $id, 'result'=>['status'=>$status, 'price'=>$price, 'qty'=>null, 'from_price'=>false, 'observed_at'=>$fresh]]);
$three = [$offer('t-cn', 'cn', 4990), $offer('t-us', 'us', 5199), $offer('t-eu', 'eu', 4990)];
$units = radios_units($three, $now);
check('three warehouse listings of one product page are one unit', count($units) === 1 && count($units[0]['options']) === 3);
check('the options are in U.S., EU, China order', array_column($units[0]['options'], 'warehouse') === ['us', 'eu', 'cn']);
check('the unit price is the lowest price on sale', $units[0]['price'] === 4990 && $units[0]['status'] === 'in_stock');
check('a listing without a product page stands alone', count(radios_units([$item(['id'=>'a']), $item(['id'=>'b'])], $now)) === 2);
$soldOut = radios_units([$offer('t-us', 'us', 3000, 'out_of_stock'), $offer('t-eu', 'eu', 4000)], $now)[0];
check('a sold-out cheap option does not set the price, and the unit takes the best status', $soldOut['price'] === 4000 && $soldOut['status'] === 'in_stock');
$row = radios_unit_row($units[0]);
check('the row says From when the options differ, and names each warehouse with its price', str_contains($row, 'From $49.90') && str_contains($row, 'U.S. $51.99') && str_contains($row, 'EU $49.90') && str_contains($row, 'China $49.90'));
check('the row links each option, in order, to its own listing in a new tab', preg_match_all('~<a class="nw opt" href="https://shop\.example/(t-[a-z]+)" target="_blank" rel="noopener noreferrer">(U\.S\.|EU|China)~', $row, $m) === 3 && $m[1] === ['t-us', 't-eu', 't-cn'] && $m[2] === ['U.S.', 'EU', 'China']);
$same = radios_unit_row(radios_units([$offer('s-us', 'us', 3990), $offer('s-eu', 'eu', 3990)], $now)[0]);
check('equal prices say so, and show no per-warehouse prices', str_contains($same, '$39.90') && !str_contains($same, 'From $') && str_contains($same, 'Same in all 2') && !str_contains($same, 'U.S. $39.90'));
$mixed = radios_unit_row(radios_units([$offer('m-us', 'us', 3000, 'out_of_stock'), $offer('m-eu', 'eu', 4000)], $now)[0]);
check('when options differ in stock, each is said', str_contains($mixed, 'U.S.: Out of stock') && str_contains($mixed, 'EU: In stock'));
check('when all options match in stock, nothing extra is said', !str_contains(radios_unit_row($units[0]), 'U.S.: '));
check('the row has no "Seller" cell: the seller sits under the name', substr_count($row, '<td') === 4 && str_contains($row, '<th scope="row">') && str_contains($row, 'Seeed Studio'));
// order inside a table: cheapest first, no price last, then by name
$many = [$item(['id'=>'p3', 'name'=>'Zed', 'result'=>['status'=>'in_stock', 'price'=>9000, 'observed_at'=>$fresh]]), $item(['id'=>'p1', 'name'=>'Beta', 'result'=>['status'=>'in_stock', 'price'=>1000, 'observed_at'=>$fresh]]),
    $item(['id'=>'p0', 'name'=>'Aaa', 'result'=>['status'=>'in_stock', 'price'=>null, 'observed_at'=>$fresh]]), $item(['id'=>'p2', 'name'=>'Alpha', 'result'=>['status'=>'in_stock', 'price'=>5000, 'observed_at'=>$fresh]])];
$table = radios_tables($many, $now);
preg_match_all('~id="r-(p\d)"~', $table, $ids);
check('a table lists products cheapest first, with no price last', $ids[1] === ['p1', 'p2', 'p3', 'p0'], implode(',', $ids[1]));
check('the table says it is cheapest first', str_contains($table, 'Cheapest first') && str_contains($table, ', cheapest first</caption>'));
// antennas show their facts, not firmware
$ant = radios_unit_row(radios_units([$item(['id'=>'a1', 'name'=>'Test Omni', 'category'=>'antenna', 'kind'=>'antenna_omni', 'specs'=>['band'=>'915', 'gain'=>'5 dBi', 'connector'=>'N male', 'length'=>'31 in']])], $now)[0]);
check('an antenna row shows band, gain, connector and length instead of firmware', str_contains($ant, '915 MHz · 5 dBi · N male · 31 in') && !str_contains($ant, 'Both firmware'));
finish();
