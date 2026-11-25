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

function radios_row(array $item, string $status): string {
    $reading = $item['result'];
    $price = is_int($reading['price'] ?? null) ? (!empty($reading['from_price']) ? 'From ' : '') . money($reading['price']) : 'See seller';
    $observed = strtotime((string)($reading['observed_at'] ?? ''));
    $checked = $observed ? '<time datetime="' . h(gmdate('c', $observed)) . '">' . h(gmdate('M j, H:i', $observed)) . ' UTC</time>' : 'Not checked yet';
    $host = parse_url((string)$item['url'], PHP_URL_SCHEME) === 'https' ? h($item['url']) : '';
    $link = $host !== '' ? '<a href="' . $host . '" target="_blank" rel="noopener noreferrer">View listing<span class="sr"> for ' . h($item['name']) . ' (opens in a new tab)</span></a>' : '';
    $stock = h(RADIO_STATUS_LABEL[$status]) . (is_int($reading['qty'] ?? null) && $status === 'in_stock' ? ' · ' . (int)$reading['qty'] . ' shown' : '');
    $facts = [FIRMWARE[$item['firmware']] ?? '', SETUP[$item['setup']] ?? ''];
    return '<tr id="r-' . h($item['id']) . '"><th scope="row">' . radios_name($item) . '<small>' . h(implode(' · ', array_filter($facts))) . '</small></th>'
        . '<td>' . h($item['seller']) . (($item['location'] ?? '') !== '' ? '<small>' . h($item['location']) . '</small>' : '') . (!empty($item['manual']) ? '<small>Checked by hand</small>' : '') . '</td>'
        . '<td class="num">' . h($price) . '</td><td class="nw">' . $stock . '</td>'
        . '<td class="nw">' . h(WAREHOUSES[$item['warehouse'] ?? ''] ?? '') . '</td><td class="nw">' . $checked . '</td><td class="nw">' . $link . '</td></tr>';
}

/** One table per availability group, in the same order as the catalog. */
function radios_tables(array $items, int $now): string {
    $groups = array_fill_keys(RADIO_STATUS_ORDER, []);
    foreach ($items as $item) {
        $groups[radio_status($item, $now)][] = $item;
    }
    $html = '';
    foreach (RADIO_STATUS_ORDER as $status) {
        $list = $groups[$status];
        if (!$list) {
            continue;
        }
        usort($list, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $html .= '<section class="rgroup" aria-labelledby="g-' . $status . '"><h2 id="g-' . $status . '">' . h(RADIO_STATUS_LABEL[$status]) . ' · ' . count($list) . '</h2>'
            . '<p class="dim">' . h(RADIO_STATUS_NOTE[$status]) . '</p><div class="rtable-wrap"><table class="rtable rwide"><caption class="sr">' . h(RADIO_STATUS_LABEL[$status]) . ' radios</caption>'
            . '<thead><tr><th scope="col">Radio</th><th scope="col">Seller</th><th scope="col">Price</th><th scope="col">Stock</th><th scope="col">Ships from</th><th scope="col">Last checked</th><th scope="col">Listing</th></tr></thead><tbody>';
        foreach ($list as $item) {
            $html .= radios_row($item, $status);
        }
        $html .= '</tbody></table></div></section>';
    }
    return $html;
}
