<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/radios.php';

/**
 * Per-radio pages (radio.php?r=<name>-<code>). One page per product link: listings that share a product page (Seeed lists the
 * same page once per warehouse) are the same radio, shown as one page with a row per warehouse. The page is plain HTML, readable
 * without JavaScript, and includes the price and stock history drawn on the server.
 */

const RADIO_WAREHOUSE_ORDER = ['us'=>0, 'eu'=>1, 'cn'=>2, ''=>3];

function radio_slug(string $text): string {
    $ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    return trim(substr($slug, 0, 60), '-');
}

function radio_hash(string $groupKey): string {
    return substr(sha1($groupKey), 0, 8);
}

/** Groups public listings by product link. Returns [hash => group], each with the offers sorted by warehouse. */
function radio_groups(array $items): array {
    $byKey = [];
    foreach ($items as $item) {
        try {
            $byKey[listing_url_key((string)$item['url'])][] = $item;
        } catch (Throwable) {
            continue; // a malformed link has no page
        }
    }
    $groups = [];
    foreach ($byKey as $key => $offers) {
        usort($offers, static fn($a, $b) => (RADIO_WAREHOUSE_ORDER[$a['warehouse'] ?? ''] ?? 9) <=> (RADIO_WAREHOUSE_ORDER[$b['warehouse'] ?? ''] ?? 9) ?: strcmp((string)$a['id'], (string)$b['id']));
        $first = $offers[0];
        $hash = radio_hash((string)$key);
        $slug = radio_slug($first['name'] . ' ' . $first['seller']);
        $groups[$hash] = ['key'=>(string)$key, 'hash'=>$hash, 'ref'=>($slug !== '' ? $slug . '-' : '') . $hash, 'offers'=>$offers,
            'name'=>$first['name'], 'seller'=>$first['seller'], 'location'=>$first['location'] ?? '', 'url'=>$first['url'], 'kind'=>$first['kind'],
            'category'=>$first['category'] ?? 'radio', 'specs'=>$first['specs'] ?? null, 'antenna_port'=>$first['antenna_port'] ?? '',
            'role'=>$first['role'] ?? 'unspecified', 'firmware'=>$first['firmware'], 'setup'=>$first['setup'], 'note'=>$first['note'] ?? ''];
    }
    return $groups;
}

/**
 * [listing id => lead listing id] for the listings the public catalog shows. A paused listing is not shown, so it is left out here too:
 * the page and the alert poster must agree on each radio's topic, and both use this.
 */
function radio_topic_leads(array $listings): array {
    return radio_lead_ids(array_values(array_filter($listings, static fn($i) => empty($i['paused']))));
}

/** [listing id => id of the radio's lead listing]. The lead listing names the radio's alert topic, so every warehouse shares one topic. */
function radio_lead_ids(array $items): array {
    $map = [];
    foreach (radio_groups($items) as $group) {
        foreach ($group['offers'] as $offer) {
            $map[(string)$offer['id']] = (string)$group['offers'][0]['id'];
        }
    }
    return $map;
}

/** [listing id => page address] for every listing, used by the catalog so cards and lists can link to the page. */
function radio_page_map(array $groups): array {
    $map = [];
    foreach ($groups as $group) {
        foreach ($group['offers'] as $offer) {
            $map[$offer['id']] = 'radio.php?r=' . $group['ref'];
        }
    }
    return $map;
}

/** Finds a group from the r= value. Only the trailing eight-character code matters, so an out-of-date name part can be corrected. */
function radio_find(array $groups, string $ref): ?array {
    if (!preg_match('/^(?:[a-z0-9-]*-)?([0-9a-f]{8})$/D', $ref, $m)) {
        return null;
    }
    return $groups[$m[1]] ?? null;
}

/** The best status among a group's offers, and its price. */
function radio_group_summary(array $group, int $now): array {
    $best = 'out_of_stock';
    $bestRank = count(RADIO_STATUS_ORDER);
    $price = null;
    $observed = 0;
    foreach ($group['offers'] as $offer) {
        $status = radio_status($offer, $now);
        $rank = array_search($status, RADIO_STATUS_ORDER, true);
        $observed = max($observed, (int)strtotime((string)($offer['result']['observed_at'] ?? '')));
        if ($rank !== false && $rank < $bestRank) {
            $best = $status;
            $bestRank = $rank;
            $price = $offer['result']['price'] ?? null;
        } elseif ($rank === $bestRank && is_int($offer['result']['price'] ?? null) && (!is_int($price) || $offer['result']['price'] < $price)) {
            $price = $offer['result']['price'];
        }
    }
    return ['status'=>$best, 'price'=>is_int($price) ? $price : null, 'observed'=>$observed];
}

/** One series per offer from the recorded history: [label, points[[t, price]]]. Only priced points are kept. */
function radio_series(array $group, array $history): array {
    $series = [];
    foreach ($group['offers'] as $offer) {
        $points = [];
        foreach ($history[$offer['id']] ?? [] as $point) {
            if (is_int($point[1] ?? null)) {
                $points[] = [(int)$point[0], (int)$point[1], (string)($point[2] ?? '')];
            }
        }
        $label = WAREHOUSES[$offer['warehouse'] ?? ''] ?? '';
        $series[] = ['label'=>$label !== '' && ($offer['warehouse'] ?? '') !== '' ? $label : 'Price', 'points'=>$points];
    }
    return $series;
}

/** A server-drawn step chart of price over time. Returns '' when no offer has moved yet. */
function radio_history_svg(array $series, int $now, string $title): string {
    $moved = array_filter($series, static fn($s) => count(array_unique(array_column($s['points'], 1))) > 1);
    if (!$moved) {
        return '';
    }
    $all = [];
    foreach ($series as $s) {
        foreach ($s['points'] as $p) {
            $all[] = $p;
        }
    }
    $t0 = min(array_column($all, 0));
    $t1 = max($now, max(array_column($all, 0)));
    $lo = min(array_column($all, 1));
    $hi = max(array_column($all, 1));
    if ($hi === $lo) {
        $hi = $lo + 100;
    }
    $pad = (int)round(($hi - $lo) * 0.08);
    $lo = max(0, $lo - $pad);
    $hi += $pad;
    [$w, $h, $l, $r, $top, $bottom] = [720, 240, 56, 150, 14, 34];
    $x = static fn(int $t): float => $l + ($w - $l - $r) * (($t - $t0) / max(1, $t1 - $t0));
    $y = static fn(int $c): float => $top + ($h - $top - $bottom) * (1 - ($c - $lo) / max(1, $hi - $lo));
    $colors = ['#c2dc87', '#e5c783', '#8fc3e8', '#f2a89b'];
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="hist-title hist-desc" class="rsvg"><title id="hist-title">' . h($title) . '</title>'
        . '<desc id="hist-desc">Price over time. The table below lists every recorded change.</desc>';
    for ($i = 0; $i <= 3; $i++) {
        $cents = (int)round($lo + ($hi - $lo) * $i / 3);
        $svg .= '<line x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . round($y($cents), 1) . '" y2="' . round($y($cents), 1) . '" stroke="#2c3626"/>'
            . '<text x="' . ($l - 8) . '" y="' . round($y($cents) + 4, 1) . '" text-anchor="end" fill="#aeb8a5" font-size="11" font-family="monospace">' . h(money($cents)) . '</text>';
    }
    $svg .= '<text x="' . $l . '" y="' . ($h - 12) . '" fill="#aeb8a5" font-size="11" font-family="monospace">' . h(gmdate('M j', $t0)) . '</text>'
        . '<text x="' . ($w - $r) . '" y="' . ($h - 12) . '" text-anchor="end" fill="#aeb8a5" font-size="11" font-family="monospace">' . h(gmdate('M j', $t1)) . '</text>';
    foreach (array_values($series) as $i => $s) {
        if (!$s['points']) {
            continue;
        }
        $color = $colors[$i % count($colors)];
        $d = '';
        foreach ($s['points'] as $j => $p) {
            $d .= $j === 0 ? 'M' . round($x($p[0]), 1) . ',' . round($y($p[1]), 1) : 'H' . round($x($p[0]), 1) . 'V' . round($y($p[1]), 1);
        }
        $last = $s['points'][count($s['points']) - 1];
        $d .= 'H' . round($x($t1), 1);
        $svg .= '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="2.4" stroke-linejoin="round"/>'
            . '<text x="' . ($w - $r + 10) . '" y="' . round($y($last[1]) - 3, 1) . '" fill="' . $color . '" font-size="12" font-weight="700" font-family="sans-serif">' . h($s['label']) . '</text>'
            . '<text x="' . ($w - $r + 10) . '" y="' . round($y($last[1]) + 12, 1) . '" fill="#aeb8a5" font-size="11" font-family="monospace">' . h(money($last[1])) . '</text>';
    }
    return $svg . '</svg>';
}

/** Recorded changes, newest first, for the table under the chart: [time, label, price, status]. */
function radio_change_rows(array $group, array $history, int $limit = 12): array {
    $rows = [];
    foreach ($group['offers'] as $offer) {
        $label = ($offer['warehouse'] ?? '') !== '' ? (WAREHOUSES[$offer['warehouse']] ?? '') : 'Listing';
        foreach ($history[$offer['id']] ?? [] as $point) {
            $rows[] = ['t'=>(int)$point[0], 'label'=>$label, 'price'=>is_int($point[1] ?? null) ? $point[1] : null, 'status'=>(string)($point[2] ?? '')];
        }
    }
    usort($rows, static fn($a, $b) => $b['t'] <=> $a['t']);
    return array_slice($rows, 0, $limit);
}

/**
 * What an antenna's connector is, in the same terms as a radio's port: sma, rp-sma, n, nmo, ufl, or 'cable' for a cable or adapter
 * (which names two connectors). Empty when it cannot be told. The seller's wording is read, nothing is assumed.
 */
function antenna_connector_key(string $connector): string {
    $c = strtolower(trim($connector));
    if ($c === '') {
        return '';
    }
    if (str_contains($c, ' to ')) {
        return 'cable';
    }
    // Sellers write it "RP-SMA", "RP SMA", "SMA-RP", "SMA RP" or "reverse polarity SMA". All are the reverse-polarity connector.
    if (preg_match('/\brp[\s-]*sma|sma[\s-]*rp\b|reverse[\s-]*polarity/', $c)) {
        return 'rp-sma';
    }
    if (str_contains($c, 'sma')) {
        return 'sma';
    }
    if (preg_match('/^n\b/', $c)) {
        return 'n';
    }
    return str_contains($c, 'nmo') ? 'nmo' : (preg_match('/u\.?fl|ipex|mhf/', $c) ? 'ufl' : '');
}

/** True for an antenna that works in the U.S. (915 MHz, or a dual 868/915 antenna). */
function antenna_is_915(array $group): bool {
    $band = clean_specs($group['specs'] ?? [])['band'];
    return in_array($band, ['915', '868/915'], true);
}

/**
 * Antennas that fit a radio's port, best first: in stock, then cheapest. A radio with a U.FL port takes a pigtail cable instead,
 * and one with no known port, or no external port, gets none.
 */
function radio_fitting_antennas(array $groups, array $radio, int $now, int $limit = 6): array {
    $port = (string)($radio['antenna_port'] ?? '');
    if (($radio['category'] ?? 'radio') !== 'radio' || !in_array($port, ['sma', 'rp-sma', 'n', 'ufl'], true)) {
        return [];
    }
    $list = [];
    foreach ($groups as $group) {
        if (($group['category'] ?? '') !== 'antenna') {
            continue;
        }
        $specs = clean_specs($group['specs'] ?? []);
        $key = antenna_connector_key($specs['connector']);
        $fits = $port === 'ufl'
            ? ($key === 'cable' && preg_match('/u\.?fl|ipex|mhf/', strtolower($specs['connector'])) === 1 && preg_match('/\b(?:sma|n)\b/', strtolower($specs['connector'])) === 1)
            : ($key === $port && $group['kind'] !== 'antenna_cable' && antenna_is_915($group));
        if ($fits) {
            $list[] = ['group'=>$group, 'summary'=>radio_group_summary($group, $now)];
        }
    }
    return radio_sort_entries($list, $limit);
}

/** Radios whose antenna port this antenna fits. Only radios with a known port are listed. */
function antenna_fitting_radios(array $groups, array $antenna, int $now, int $limit = 6): array {
    $specs = clean_specs($antenna['specs'] ?? []);
    $key = antenna_connector_key($specs['connector']);
    if (($antenna['category'] ?? '') !== 'antenna' || !in_array($key, ['sma', 'rp-sma', 'n'], true) || $antenna['kind'] === 'antenna_cable' || !antenna_is_915($antenna)) {
        return [];
    }
    $list = [];
    foreach ($groups as $group) {
        if (($group['category'] ?? 'radio') === 'radio' && ($group['antenna_port'] ?? '') === $key) {
            $list[] = ['group'=>$group, 'summary'=>radio_group_summary($group, $now)];
        }
    }
    return radio_sort_entries($list, $limit);
}

/** The connector of an antenna group as written for people, for example "SMA" for "SMA male". */
function antenna_connector_label(array $group): string {
    $connector = clean_specs($group['specs'] ?? [])['connector'];
    return preg_replace('/\s+(?:male|female)$/i', '', $connector) ?: 'matching';
}

/** In stock first, then cheapest, then by name. */
function radio_sort_entries(array $list, int $limit): array {
    usort($list, static fn($a, $b) => (array_search($a['summary']['status'], RADIO_STATUS_ORDER, true) <=> array_search($b['summary']['status'], RADIO_STATUS_ORDER, true))
        ?: ($a['summary']['price'] ?? PHP_INT_MAX) <=> ($b['summary']['price'] ?? PHP_INT_MAX) ?: strcmp($a['group']['name'], $b['group']['name']));
    return array_slice($list, 0, $limit);
}

/** [label, value] for each antenna fact that is known. */
function radio_antenna_facts(array $group): array {
    $specs = clean_specs($group['specs'] ?? []);
    $band = $specs['band'] === 'any' ? 'Any band' : ($specs['band'] !== '' ? $specs['band'] . ' MHz' : '');
    $list = [['Band', $band], ['Gain', $specs['gain']], ['Connector', $specs['connector']], ['Length', $specs['length']]];
    return array_values(array_filter($list, static fn($f) => $f[1] !== ''));
}

/** A short factual summary written from the data, shown at the top of the page. */
function radio_summary_text(array $group, array $summary): string {
    $kind = KINDS[$group['kind']] ?? 'Radio';
    if (($group['category'] ?? 'radio') === 'antenna') {
        $parts = [$group['name'] . ' is a ' . lcfirst($kind) . ' sold by ' . $group['seller'] . (($group['location'] ?? '') !== '' ? ' (' . $group['location'] . ')' : '') . '.'];
        $facts = radio_antenna_facts($group);
        if ($facts) {
            $parts[] = implode('. ', array_map(static fn($f) => $f[0] . ': ' . $f[1], $facts)) . '.';
        }
        $parts[] = $summary['status'] === 'unknown'
            ? ($summary['price'] !== null ? 'We have no recent reading. The last price we saw was ' . money($summary['price']) . '.' : 'We have no recent reading, and no price we can read.')
            : 'Right now it is ' . strtolower(RADIO_STATUS_LABEL[$summary['status']]) . ($summary['price'] !== null ? ' at ' . money($summary['price']) : '') . '.';
        return implode(' ', $parts);
    }
    $parts = [$group['name'] . ' is a ' . (preg_match('/^[A-Z]{2,}/', $kind) ? $kind : lcfirst($kind)) . ' sold by ' . $group['seller'] . (($group['location'] ?? '') !== '' ? ' (' . $group['location'] . ')' : '') . '.'];
    $parts[] = 'Firmware: ' . (FIRMWARE[$group['firmware']] ?? 'not stated') . '. Setup: ' . (SETUP[$group['setup']] ?? 'see the listing') . '.';
    if ($summary['status'] === 'unknown') {
        $parts[] = $summary['price'] !== null
            ? 'We have no recent reading. The last price we saw was ' . money($summary['price']) . '.'
            : 'We have no recent reading, and no price we can read.';
    } else {
        $parts[] = 'Right now it is ' . strtolower(RADIO_STATUS_LABEL[$summary['status']]) . ($summary['price'] !== null ? ' at ' . money($summary['price']) : '') . '.';
    }
    return implode(' ', $parts);
}

/** A short meta description for search results. */
function radio_meta_description(array $group, array $summary): string {
    $state = $summary['status'] === 'unknown' ? 'no recent reading' : strtolower(RADIO_STATUS_LABEL[$summary['status']]);
    return $group['name'] . ' at ' . $group['seller'] . ': ' . $state . ($summary['price'] !== null ? ', ' . money($summary['price']) : '') . '. Price history, stock by warehouse and similar ' . (($group['category'] ?? 'radio') === 'antenna' ? 'antennas' : 'radios') . '.';
}

/** Up to $limit other radios of the same type, closest in price first. */
function radio_similar(array $groups, array $current, int $now, int $limit = 5): array {
    $base = radio_group_summary($current, $now);
    $list = [];
    foreach ($groups as $group) {
        if ($group['hash'] === $current['hash'] || $group['kind'] !== $current['kind']) {
            continue;
        }
        $summary = radio_group_summary($group, $now);
        $list[] = ['group'=>$group, 'summary'=>$summary, 'distance'=>($base['price'] !== null && $summary['price'] !== null) ? abs($base['price'] - $summary['price']) : PHP_INT_MAX];
    }
    usort($list, static fn($a, $b) => (array_search($a['summary']['status'], RADIO_STATUS_ORDER, true) <=> array_search($b['summary']['status'], RADIO_STATUS_ORDER, true)) ?: $a['distance'] <=> $b['distance'] ?: strcmp($a['group']['name'], $b['group']['name']));
    return array_slice($list, 0, $limit);
}
