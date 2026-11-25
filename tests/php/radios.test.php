<?php
declare(strict_types=1);
// The plain radio list (radios.php): the status rule, escaping, link safety, grouping, and the shared header and footer.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();
require $site . '/includes/radios.php';

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
check('every table has a caption and column headers for screen readers', substr_count($html, '<caption') === 4 && substr_count($html, 'scope="col"') === 4 * 7 && str_contains($html, 'scope="row"'));

// the shared header and footer come from the home page, so they cannot drift
$footer = radios_page_chrome('footer');
$header = radios_page_chrome('header');
check('the footer is the home page footer', str_contains($footer, 'site-footer') && str_contains($footer, 'whats-new') && str_contains($footer, 'KY4DEH'));
check('the header is the home page header', str_contains($header, 'class="mast"') && str_contains($header, 'Price history'));
check('anchors from the home page become links to it', !str_contains($header . $footer, 'href="#') && str_contains($header, 'href="./#inventory"'));
finish();
