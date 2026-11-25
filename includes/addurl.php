<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

/**
 * "Add by link" helpers for the admin: tidy a pasted product link, read the store's product feed,
 * and guess the form fields. Every guess comes with a short reason so the administrator can check it.
 * Nothing here saves anything.
 */

/** Sellers that refuse automated checks. Matches the "Checked by hand" listings on the public page. */
const ADDURL_MANUAL_HOSTS = ['etsy.com', 'digikey.com', 'mouser.com'];

/** Removes tracking and referral parameters and the fragment. The variant parameter is kept. */
function addurl_clean_url(string $url): array {
    $url = trim($url);
    $parts = product_url($url);
    $kept = [];
    $removed = [];
    foreach (array_filter(explode('&', (string)($parts['query'] ?? '')), 'strlen') as $pair) {
        $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
        if (str_starts_with($key, 'utm_') || in_array($key, ['ref', 'ref_', 'aff', 'affiliate', 'affiliate_id', 'tag', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'source', 'campaign'], true)) {
            $removed[] = $pair;
        } else {
            $kept[] = $pair;
        }
    }
    $clean = 'https://' . $parts['host'] . $parts['path'] . ($kept ? '?' . implode('&', $kept) : '');
    return ['url'=>$clean, 'removed'=>$removed, 'host'=>seller_bucket($parts['host'])];
}

function addurl_is_manual_host(string $host, array $items): bool {
    foreach (ADDURL_MANUAL_HOSTS as $blocked) {
        if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
            return true;
        }
    }
    foreach ($items as $item) {
        try {
            if (($item['mode'] ?? '') === 'manual' && seller_bucket(product_url($item['url'])['host']) === $host) {
                return true;
            }
        } catch (InvalidArgumentException) {
        }
    }
    return false;
}

/**
 * Reads a Shopify-style product feed for the link. $fetch takes a URL and returns [status, body], like guarded_fetch().
 * Returns ['ok'=>true, 'product'=>[...]] or ['ok'=>false, 'reason'=>'manual|blocked|no_feed|unreadable', 'message'=>'...'].
 */
function addurl_read(string $url, callable $fetch, array $items): array {
    $clean = addurl_clean_url($url);
    $parts = product_url($clean['url']);
    $base = ['url'=>$clean['url'], 'host'=>$clean['host'], 'removed'=>$clean['removed']];
    if (addurl_is_manual_host($clean['host'], $items)) {
        return $base + ['ok'=>false, 'reason'=>'manual', 'message'=>'This seller blocks automated checks.'];
    }
    if (!preg_match('~^(.*?/products/[a-z0-9][a-z0-9._-]*)/?$~iD', $parts['path'], $path)) {
        return $base + ['ok'=>false, 'reason'=>'no_feed', 'message'=>'This does not look like a store product page with a feed.'];
    }
    try {
        [$status, $body] = $fetch('https://' . strtolower($parts['host']) . $path[1] . '.js');
    } catch (ScanFailure $failure) {
        return $base + ['ok'=>false, 'reason'=>'blocked', 'message'=>$failure->getMessage()];
    }
    if (in_array($status, [401, 403, 429], true)) {
        return $base + ['ok'=>false, 'reason'=>'blocked', 'message'=>'The seller refused this server (HTTP ' . $status . ').'];
    }
    $data = $status === 200 ? json_decode($body, true) : null;
    if (!is_array($data) || empty($data['variants']) || !is_array($data['variants']) || !is_string($data['title'] ?? null)) {
        return $base + ['ok'=>false, 'reason'=>'no_feed', 'message'=>'The store did not return a product feed' . ($status !== 200 ? ' (HTTP ' . $status . ')' : '') . '.'];
    }
    $variants = [];
    foreach (array_slice($data['variants'], 0, 40) as $v) {
        if (!is_array($v) || !is_int($v['id'] ?? null) || !is_int($v['price'] ?? null) || !is_bool($v['available'] ?? null)) {
            continue;
        }
        $variants[] = ['id'=>(string)$v['id'], 'title'=>mb_substr((string)($v['public_title'] ?? $v['title'] ?? ''), 0, 120),
            'price'=>$v['price'], 'available'=>$v['available']];
    }
    if (!$variants) {
        return $base + ['ok'=>false, 'reason'=>'no_feed', 'message'=>'The product feed has no readable variants.'];
    }
    $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)($data['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $tags = array_values(array_filter(array_map('strval', is_array($data['tags'] ?? null) ? $data['tags'] : []), 'strlen'));
    return $base + ['ok'=>true, 'product'=>['title'=>mb_substr($data['title'], 0, 200), 'vendor'=>mb_substr((string)($data['vendor'] ?? ''), 0, 80),
        'description'=>mb_substr($text, 0, 1500), 'tags'=>array_slice($tags, 0, 20), 'variants'=>$variants], 'body'=>$body];
}

/** The first reading for a listing link, read from the feed body already fetched. Null when it cannot be matched. */
function addurl_reading(string $body, string $listingUrl): ?array {
    try {
        return shopify_result($body, ['url'=>$listingUrl, 'variant'=>'']);
    } catch (ScanFailure) {
        return null;
    }
}

/** The variant named by ?variant= in the link, or null. */
function addurl_link_variant(string $url): ?string {
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);
    return is_string($query['variant'] ?? null) && preg_match('/^\d{5,20}$/D', $query['variant']) ? $query['variant'] : null;
}

function addurl_is_default_title(string $title): bool {
    return $title === '' || strcasecmp($title, 'Default Title') === 0;
}

/** The link to save: the cleaned link, with ?variant= for the chosen variant, or without one for "any available". */
function addurl_listing_url(string $cleanUrl, ?string $variantId): string {
    $parts = product_url($cleanUrl);
    $query = array_filter(explode('&', (string)($parts['query'] ?? '')), static fn($pair) => !str_starts_with(strtolower($pair), 'variant='));
    if ($variantId !== null) {
        $query[] = 'variant=' . $variantId;
    }
    return 'https://' . $parts['host'] . $parts['path'] . ($query ? '?' . implode('&', $query) : '');
}

function addurl_seller_name(string $host): string {
    $labels = explode('.', $host);
    $core = count($labels) >= 2 ? $labels[count($labels) - 2] : $host;
    return ucfirst(str_replace('-', ' ', $core));
}

function addurl_slug_name(string $path): string {
    $slug = basename(rtrim($path, '/'));
    $words = preg_split('/[-_]+/', preg_replace('/\.html?$/i', '', $slug), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return mb_substr(ucfirst(implode(' ', $words)), 0, 120);
}

/**
 * Guesses the listing fields. Returns ['values'=>form values, 'why'=>[field=>[tag, text]]].
 * $read is the result of addurl_read() (ok or not), $variant is the chosen variant array or null,
 * $items are the existing listings, and $remoteOk says whether the U.S. runner is set up.
 */
function addurl_guess(array $read, ?array $variant, array $items, bool $remoteOk): array {
    $product = $read['product'] ?? null;
    $host = $read['host'];
    $why = [];
    $values = ['firmware'=>'both', 'kind'=>'handheld', 'role'=>'unspecified', 'setup'=>'unknown', 'mode'=>'auto',
        'checker'=>'server', 'warehouse'=>'', 'location'=>'', 'note'=>'', 'variant'=>''];

    // Seller, location and checker: reuse what the catalog already knows about this host.
    $sibling = null;
    $siblingRemote = false;
    foreach ($items as $item) {
        try {
            if (seller_bucket(product_url($item['url'])['host']) === $host) {
                $sibling ??= $item;
                $siblingRemote = $siblingRemote || ($item['checker'] ?? 'server') === 'remote';
            }
        } catch (InvalidArgumentException) {
        }
    }
    if ($sibling) {
        $values['seller'] = $sibling['seller'];
        $values['location'] = $sibling['location'] ?? '';
        $why['seller'] = ['matches', 'your other ' . $sibling['seller'] . ' listings'];
        if ($values['location'] !== '') {
            $why['location'] = ['remembered', 'from your ' . $sibling['seller'] . ' listing "' . $sibling['name'] . '"'];
        }
    } else {
        $values['seller'] = addurl_seller_name($host);
        $why['seller'] = ['from the web address', 'please check the spelling'];
    }

    $values['url'] = $read['url'];
    if ($read['removed']) {
        $why['url'] = ['cleaned', 'removed ' . implode(', ', array_map(static fn($p) => explode('=', $p, 2)[0], $read['removed']))];
    }

    if (!$product) {
        $values['name'] = addurl_slug_name(product_url($read['url'])['path']);
        $why['name'] = ['from the link', 'the page could not be read, so this is only the last part of the address'];
        if ($read['reason'] === 'manual') {
            $values['mode'] = 'manual';
            $why['mode'] = ['chosen', 'this seller blocks automated checks, so the price and stock are entered by hand'];
        } else {
            $values['mode'] = 'page';
            $why['mode'] = ['chosen', 'no product feed, so the page itself is scanned. Add page rules if the first checks fail'];
            if ($read['reason'] === 'blocked' && $remoteOk) {
                $values['checker'] = 'remote';
                $why['checker'] = ['chosen', 'this server was refused, so the U.S. runner takes the first reading'];
            }
        }
        if ($sibling && $read['reason'] !== 'manual') {
            $values['checker'] = $siblingRemote ? 'remote' : 'server';
            $why['checker'] = ['matches', 'your other ' . $sibling['seller'] . ' listings'];
        }
        return ['values'=>$values, 'why'=>$why];
    }

    $label = $variant && !addurl_is_default_title($variant['title']) && count($product['variants']) > 1 ? $variant['title'] : '';
    $values['name'] = mb_substr($product['title'] . ($label !== '' ? ' · ' . $label : ''), 0, 120);
    $why['name'] = ['from the page title', $label !== '' ? 'the chosen variant is added. Shorten it if you like' : 'shorten it if you like'];

    $haystack = strtolower($product['title'] . ' ' . $product['description'] . ' ' . implode(' ', $product['tags']));
    $core = $haystack !== '' && str_contains($haystack, 'meshcore');
    $tastic = str_contains($haystack, 'meshtastic');
    if ($core && !$tastic) {
        $values['firmware'] = 'meshcore';
        $why['firmware'] = ['guessed', 'the page mentions MeshCore only. Please confirm'];
    } elseif ($tastic && !$core) {
        $values['firmware'] = 'meshtastic';
        $why['firmware'] = ['guessed', 'the page mentions Meshtastic only. Please confirm'];
    } else {
        $why['firmware'] = ['guessed', $core ? 'the page mentions both. Please confirm' : 'the page does not say, so "both" is a placeholder. Please confirm'];
    }

    $titleLower = strtolower($product['title']);
    $kinds = [
        'keyboard' => '/\b(t-?deck|keyboard|communicator|messenger|cardputer)\b/',
        'solar'    => '/\bsolar\b/',
        'tracker'  => '/\b(tracker|tag|gps)\b/',
        'base'     => '/\b(repeater|base station|gateway|rooftop)\b/',
        'board'    => '/\b(dev(elopment)? ?board|module|kit|devkit|core board)\b/',
        'handheld' => '/\b(handheld|pager|pocket|node)\b/',
    ];
    $kind = null;
    $evidence = '';
    foreach ($kinds as $name => $pattern) {
        if (preg_match($pattern, $titleLower, $m)) {
            $kind = $name;
            $evidence = 'the title says "' . $m[0] . '"';
            break;
        }
    }
    if ($kind === null) {
        foreach ($kinds as $name => $pattern) {
            if (preg_match($pattern, $haystack, $m)) {
                $kind = $name;
                $evidence = 'the description says "' . $m[0] . '"';
                break;
            }
        }
    }
    $values['kind'] = $kind ?? 'handheld';
    $why['kind'] = ['guessed', $kind ? $evidence : 'nothing clear on the page, so "handheld" is a placeholder'];
    $values['role'] = ['keyboard'=>'companion', 'handheld'=>'companion', 'tracker'=>'tracker', 'solar'=>'solar_repeater', 'base'=>'repeater'][$values['kind']] ?? 'unspecified';
    $why['role'] = ['guessed', 'from the radio type'];

    if (preg_match('/\b(pre-?flashed|pre-?loaded|pre-?installed|ships with (meshtastic|meshcore)|comes with (meshtastic|meshcore))\b/', $haystack)) {
        if ($values['firmware'] === 'meshtastic') {
            $values['setup'] = 'meshtastic';
        } elseif ($values['firmware'] === 'meshcore') {
            $values['setup'] = 'meshcore';
        }
    }

    $why['setup'] = $values['setup'] === 'unknown' ? ['left open', 'the page does not say what is installed. Set it if you know'] : ['guessed', 'the page says it ships with the firmware installed'];
    $why['warehouse'] = ['left blank', 'set it only if the seller ships from several warehouses'];
    $values['mode'] = 'auto';
    $why['mode'] = ['chosen', 'the product feed has price and stock for each variant'];
    if ($sibling) {
        $values['checker'] = $siblingRemote ? 'remote' : 'server';
        $why['checker'] = ['matches', 'your other ' . $sibling['seller'] . ' listings'];
    } else {
        $why['checker'] = ['chosen', 'the feed was read from this server. If a check is later refused, move it to the U.S. runner'];
    }

    // Drop a marketing opener such as "What is the X?" and keep the first few sentences.
    $text = (string)preg_replace('/^(?:[^.!?]{0,120}\?\s+)+/u', '', $product['description']);
    $text = mb_strlen($text) >= 60 ? $text : $product['description'];
    $note = mb_substr($text, 0, 280);
    if (mb_strlen($text) > 280) {
        $cut = mb_strrpos($note, '. ');
        $note = $cut !== false && $cut > 80 ? mb_substr($note, 0, $cut + 1) : rtrim($note) . '…';
    }
    if ($label !== '') {
        $note = trim($note . ' This tracks the ' . $label . ' option.');
    }
    $values['note'] = mb_substr($note, 0, 500);
    $why['note'] = ['drafted', 'from the product description. Edit freely'];
    return ['values'=>$values, 'why'=>$why];
}

/** Applies the administrator's variant choice (null means "any available variant") and prepares the form values and first reading. */
function addurl_choose(array $session, ?string $variantId, array $items, bool $remoteOk): array {
    $read = $session['read'];
    $variants = $read['product']['variants'];
    $variant = null;
    foreach ($variants as $v) {
        if ($variantId !== null && $v['id'] === $variantId) {
            $variant = $v;
        }
    }
    $listing = addurl_listing_url($read['url'], $variantId);
    $forGuess = $read;
    $forGuess['url'] = $listing;
    $session['choice'] = $variantId ?? 'any';
    $session['guess'] = addurl_guess($forGuess, $variant, $items, $remoteOk);
    $result = $session['readings'][$variantId ?? 'any'] ?? null;
    $session['reading'] = $result ? ['listing_url'=>$listing, 'result'=>$result, 'variant'=>$variant['title'] ?? ''] : null;
    return $session;
}

function addurl_steps(int $step): string {
    $names = ['Paste link', 'Pick variant', 'Review and save'];
    $out = '<ol class="steps" aria-label="Steps">';
    foreach ($names as $i => $name) {
        $out .= '<li' . ($i + 1 === $step ? ' class="on" aria-current="step"' : '') . '>' . ($i + 1) . ' · ' . h($name) . '</li>';
    }
    return $out . '</ol>';
}
