<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

/**
 * The plain, server-rendered list of every radio (radios.php). The interactive catalog on the home page is built by
 * JavaScript, which most crawlers and AI tools do not run, so this page puts the same facts in the HTML itself.
 */

const RADIO_STATUS_ORDER = ['in_stock', 'orderable', 'preorder', 'unknown', 'out_of_stock'];
const RADIO_STATUS_LABEL = ['in_stock'=>'In stock', 'orderable'=>'Orderable', 'preorder'=>'Preorder / backorder', 'unknown'=>'Unverified', 'out_of_stock'=>'Out of stock'];
const RADIO_STATUS_NOTE = [
    'in_stock'=>'The seller reports units on hand.',
    'orderable'=>'The seller accepts orders. Shelf stock is not confirmed.',
    'preorder'=>'Preorder or backorder.',
    'unknown'=>'Awaiting a check, paused by the seller, or a reading older than 24 hours. Prices may be out of date.',
    'out_of_stock'=>'The seller reports none available.',
];

/** The status shown for a listing: a reading older than 24 hours, or a failed check, counts as unverified (same rule as the catalog). */
function radio_status(array $item, int $now): string {
    $observed = strtotime((string)($item['result']['observed_at'] ?? ''));
    if (!empty($item['check_failed']) || !$observed || $now - $observed > 86400) {
        return 'unknown';
    }
    return isset(RADIO_STATUS_LABEL[$item['result']['status'] ?? '']) ? $item['result']['status'] : 'unknown';
}

/** The home page's header or footer, so this page always matches it. Anchors on the home page become links to it. */
function radios_page_chrome(string $part): string {
    $html = (string)@file_get_contents(dirname(__DIR__) . '/index.html');
    $tag = $part === 'header' ? 'header' : 'footer';
    if (!preg_match('~<' . $tag . '\b.*?</' . $tag . '>~s', $html, $match)) {
        return '';
    }
    return str_replace('href="#', 'href="./#', $match[0]);
}

function radios_latest_reading(array $items): ?int {
    $latest = 0;
    foreach ($items as $item) {
        $latest = max($latest, (int)strtotime((string)($item['result']['observed_at'] ?? '')));
    }
    return $latest ?: null;
}

/** Keeps model numbers such as T1000-E whole: a line may not break inside them. Takes text that is already escaped. */
function radios_nowrap_models(string $escaped): string {
    return preg_replace('/\b[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+\b/', '<span class="nb">$0</span>', $escaped) ?? $escaped;
}

/** The radio's name, linked to its own page when it has one. */
function radios_name(array $item): string {
    $page = (string)($item['page'] ?? '');
    return preg_match('~^radio\.php\?r=[a-z0-9-]+$~D', $page) ? '<a href="' . h($page) . '">' . radios_nowrap_models(h($item['name'])) . '</a>' : radios_nowrap_models(h($item['name']));
}

/** The short place a listing ships from: "U.S.", "EU" or "China". Empty when the seller has one listing for everywhere. */
function radios_place(array $item): string {
    return str_replace(' warehouse', '', WAREHOUSES[$item['warehouse'] ?? ''] ?? '');
}

/**
 * Listings of one product (Seeed lists the same page once per warehouse) as one unit, the way the catalog shows them:
 * ['options'=>[listing, ...] in warehouse order, 'best'=>the best listing, 'status'=>its status, 'price'=>the lowest price on sale or null].
 */
function radios_unit(array $options, int $now): array {
    $statuses = [];
    foreach ($options as $i => $option) {
        $statuses[$i] = radio_status($option, $now);
    }
    $rank = static fn(string $status): int => (int)array_search($status, RADIO_STATUS_ORDER, true);
    $order = array_keys($options);
    usort($order, static fn($x, $y) => $rank($statuses[$x]) <=> $rank($statuses[$y])
        ?: ($options[$x]['result']['price'] ?? PHP_INT_MAX) <=> ($options[$y]['result']['price'] ?? PHP_INT_MAX));
    $best = $options[$order[0]];
    $status = $statuses[$order[0]];
    $live = array_filter($options, static fn($o) => in_array(radio_status($o, $now), ['in_stock', 'orderable'], true) && is_int($o['result']['price'] ?? null));
    $use = $live ?: array_filter($options, static fn($o) => is_int($o['result']['price'] ?? null));
    $usedPrices = array_values(array_map(static fn($o) => $o['result']['price'], $use));
    $price = $usedPrices ? min($usedPrices) : null;
    $warehouses = RADIO_WAREHOUSE_ORDER;
    usort($options, static fn($x, $y) => ($warehouses[$x['warehouse'] ?? ''] ?? 9) <=> ($warehouses[$y['warehouse'] ?? ''] ?? 9) ?: strcmp((string)$x['id'], (string)$y['id']));
    return ['options'=>$options, 'best'=>$best, 'status'=>$status, 'price'=>$price, 'prices'=>$usedPrices];
}

/** Groups listings into units by product page. Listings with no product page stand alone. */
function radios_units(array $items, int $now): array {
    $byKey = [];
    foreach ($items as $item) {
        $page = (string)($item['page'] ?? '');
        $byKey[preg_match('~^radio\.php\?r=[a-z0-9-]+$~D', $page) ? $page : 'id:' . $item['id']][] = $item;
    }
    return array_map(static fn($options) => radios_unit($options, $now), array_values($byKey));
}

/** One row for one listing. A listing is a unit of one. */
function radios_row(array $item, string $status): string {
    $unit = radios_unit([$item], time());
    $unit['status'] = $status;
    return radios_unit_row($unit);
}

/** One table row for a product: its name, price, stock, where it ships from, and a link for each option. */
function radios_unit_row(array $unit): string {
    $item = $unit['best'];
    $options = $unit['options'];
    $many = count($options) > 1;
    $status = $unit['status'];
    $reading = $item['result'];
    $money = static fn(array $o) => is_int($o['result']['price'] ?? null) ? (!empty($o['result']['from_price']) ? 'From ' : '') . money($o['result']['price']) : 'See seller';
    $prices = array_values(array_unique(array_filter(array_map(static fn($o) => $o['result']['price'] ?? null, $options), 'is_int')));
    // Price: the lowest option on sale. "From" when the options differ.
    if ($many && $unit['price'] !== null) {
        $price = (count(array_unique($unit['prices'])) > 1 ? 'From ' : '') . money($unit['price']);
        $priceNote = count(array_unique($unit['prices'])) > 1 ? '' : '<small>Same in all ' . count($options) . '</small>';
    } else {
        $price = $many ? 'See seller' : $money($item);
        $priceNote = '';
    }
    $stock = h(RADIO_STATUS_LABEL[$status]) . (!$many && is_int($reading['qty'] ?? null) && $status === 'in_stock' ? ' · ' . (int)$reading['qty'] . ' shown' : '');
    // When the options are not all the same, say which is which.
    $mixed = '';
    if ($many) {
        $labels = array_map(static fn($o) => radio_status($o, time()), $options);
        if (count(array_unique($labels)) > 1) {
            $mixed = '<small>' . implode('<br>', array_map(static fn($o, $l) => h(radios_place($o) ?: 'Listing') . ': ' . h(RADIO_STATUS_LABEL[$l]), $options, $labels)) . '</small>';
        }
    }
    $latest = 0;
    foreach ($options as $o) {
        $latest = max($latest, (int)strtotime((string)($o['result']['observed_at'] ?? '')));
    }
    $checked = $latest ? '<small><time datetime="' . h(gmdate('c', $latest)) . '">Checked ' . h(gmdate('M j, H:i', $latest)) . ' UTC</time></small>' : '<small>Not checked yet</small>';
    if (($item['category'] ?? 'radio') === 'antenna') {
        $factLine = implode(' · ', array_map(static fn($f) => $f[1], radio_antenna_facts($item)));
    } else {
        $factLine = implode(' · ', array_filter([FIRMWARE[$item['firmware']] ?? '', SETUP[$item['setup']] ?? '']));
    }
    $seller = (mb_strlen((string)$item['seller']) <= 24 ? '<span class="nb">' . h($item['seller']) . '</span>' : h($item['seller']))
        . (($item['location'] ?? '') !== '' ? ' · ' . h($item['location']) : '') . (!empty($item['manual']) ? ' · Checked by hand' : '');
    $ships = '';
    foreach ($options as $o) {
        $place = radios_place($o);
        if ($place !== '') {
            $ships .= '<span class="nw opt">' . h($place) . ($many && count($prices) > 1 && is_int($o['result']['price'] ?? null) ? ' ' . h(money($o['result']['price'])) : '') . '</span> ';
        }
    }
    $links = '';
    foreach ($options as $o) {
        if (parse_url((string)$o['url'], PHP_URL_SCHEME) !== 'https') {
            continue;
        }
        $place = radios_place($o);
        $links .= '<a class="nw opt" href="' . h($o['url']) . '" target="_blank" rel="noopener noreferrer">' . h($many && $place !== '' ? $place : 'View listing')
            . '<span class="sr"> for ' . h($o['name']) . ($place !== '' ? ' from the ' . h($place) . ' warehouse' : '') . ' (opens in a new tab)</span></a> ';
    }
    return '<tr id="r-' . h($item['id']) . '"><th scope="row">' . radios_name($item) . '<small>' . $seller . '</small><small>' . h($factLine) . '</small></th>'
        . '<td class="num">' . h($price) . $priceNote . '</td><td class="nw">' . $stock . $mixed . $checked . '</td>'
        . '<td>' . ($ships !== '' ? trim($ships) : '<span class="dim">Not stated</span>') . '</td><td class="links">' . trim($links) . '</td></tr>';
}

/** One table per availability group, in the same order as the catalog. Products are one row each, cheapest first. */
function radios_tables(array $items, int $now, string $idPrefix = 'g', string $labelPrefix = '', string $noun = 'radios'): string {
    $groups = array_fill_keys(RADIO_STATUS_ORDER, []);
    foreach (radios_units($items, $now) as $unit) {
        $groups[$unit['status']][] = $unit;
    }
    $html = '';
    foreach (RADIO_STATUS_ORDER as $status) {
        $list = $groups[$status];
        if (!$list) {
            continue;
        }
        usort($list, static fn($a, $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX) ?: strcasecmp($a['best']['name'], $b['best']['name']));
        $html .= '<section class="rgroup" aria-labelledby="' . $idPrefix . '-' . $status . '"><h2 id="' . $idPrefix . '-' . $status . '">' . h($labelPrefix . RADIO_STATUS_LABEL[$status]) . ' · ' . count($list) . '</h2>'
            . '<p class="dim">' . h(RADIO_STATUS_NOTE[$status]) . ' Cheapest first.</p><div class="rtable-wrap"><table class="rtable"><caption class="sr">' . h(RADIO_STATUS_LABEL[$status]) . ' ' . h($noun) . ', cheapest first</caption>'
            . '<thead><tr><th scope="col">' . ($noun === 'radios' ? 'Radio' : 'Name') . '</th><th scope="col">Price</th><th scope="col">Stock</th><th scope="col">Ships from</th><th scope="col">Where to buy</th></tr></thead><tbody>';
        foreach ($list as $unit) {
            $html .= radios_unit_row($unit);
        }
        $html .= '</tbody></table></div></section>';
    }
    return $html;
}
