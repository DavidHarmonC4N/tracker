<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

final class ScanFailure extends RuntimeException {
    public function __construct(string $message, public readonly int $cooldown = 0) {
        parent::__construct($message);
    }
}

const SELLER_SCAN_GAP = 20 * 60;
const REQUEST_GAP_SECONDS = 2;
const ROBOTS_CACHE_TTL = 24 * 60 * 60;

function retry_after_delay(string $header, int $now, int $fallback, int $minimum): int {
    if ($header === '') {
        return $fallback;
    }
    if (ctype_digit($header)) {
        $seconds = min((int)$header, PHP_INT_MAX - $now);
    } else {
        $date = strtotime($header, $now);
        if ($date === false) {
            return $fallback;
        }
        $seconds = $date - $now;
    }
    return max($minimum, $seconds);
}

function fetch_page(string $url, ?string $rules = null): array {
    static $lastRequest = [];
    $original = product_url($url);
    $host = strtolower($original['host']);
    if ($rules !== null && !robots_allows($url, $rules)) {
        throw new ScanFailure('The seller disallows automated checks for this link.');
    }
    $ips = gethostbynamel($host) ?: [];
    if (!$ips) {
        throw new ScanFailure('Seller DNS lookup failed.');
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)
            || preg_match('/^(?:100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|22[4-9]\.|23\d\.|24\d\.|25[0-5]\.)/', $ip)) {
            throw new ScanFailure('Seller hostname did not resolve entirely to public addresses.');
        }
    }

    for ($hop = 0; $hop <= 2; $hop++) {
        if ($hop > 0 && $rules !== null && !robots_allows($url, $rules)) {
            throw new ScanFailure('The seller disallows automated checks for this link.');
        }
        $body = '';
        $location = '';
        $retryAfter = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROXY=>'', CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE=>[$host . ':443:' . $ips[0]],
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>9,
            CURLOPT_USERAGENT=>'OffgridCommsTracker/1.0 (public retail price and stock checks)',
            CURLOPT_ENCODING=>'', CURLOPT_HTTPHEADER=>['Accept: application/json, text/html, text/plain', 'Accept-Language: en-US,en;q=0.8'],
            CURLOPT_HEADERFUNCTION=>static function ($c, string $line) use (&$location, &$retryAfter): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($c, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4_000_000) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $wait = ($lastRequest[$host] ?? 0) + REQUEST_GAP_SECONDS - microtime(true);
        if ($wait > 0) {
            usleep((int)ceil($wait * 1_000_000));
        }
        $ok = curl_exec($curl);
        $lastRequest[$host] = microtime(true);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 403) {
            throw new ScanFailure('The seller declined automatic checks (HTTP 403).', retry_after_delay($retryAfter,time(),86400,86400));
        }
        if ($status === 429) {
            throw new ScanFailure('The seller rate limited this check (HTTP 429).', retry_after_delay($retryAfter,time(),21600,3600));
        }
        if ($status === 503) {
            throw new ScanFailure('The seller is temporarily unavailable (HTTP 503).', retry_after_delay($retryAfter,time(),1800,1800));
        }
        if ($ok === false) {
            throw new ScanFailure('Seller request timed out or could not be read.');
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
                $location = 'https://' . $host . $location;
            }
            $redirect = product_url($location);
            if (strtolower($redirect['host']) !== $host) {
                throw new ScanFailure('The link redirects to another site. Save the final seller URL.');
            }
            $url = $location;
            continue;
        }
        if (preg_match('/<title[^>]*>\s*(?:just a moment|access denied|attention required|pardon our interruption|robot check|verify you are human)/i', $body)) {
            throw new ScanFailure('The seller requires a browser verification.', 86400);
        }
        return [$status, $body];
    }
    throw new ScanFailure('Too many redirects.');
}

function robots_allows(string $url, string $rules): bool {
    $path = product_url($url);
    $target = $path['path'] . (isset($path['query']) ? '?' . $path['query'] : '');
    $groups = [];
    $agents = $lines = [];
    foreach (preg_split('/\R/', $rules) as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if (!str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            if ($lines) {
                $groups[] = [$agents, $lines];
                $agents = $lines = [];
            }
            $agents[] = strtolower($value);
        } elseif ($agents && in_array($key, ['allow','disallow'], true) && $value !== '') {
            $lines[] = [$key, $value];
        }
    }
    $groups[] = [$agents, $lines];
    $specific = false;
    foreach ($groups as [$agents]) {
        $specific = $specific || in_array('offgridcommstracker', $agents, true);
    }
    $winner = 'allow';
    $length = -1;
    foreach ($groups as [$agents, $lines]) {
        if (!in_array($specific ? 'offgridcommstracker' : '*', $agents, true)) {
            continue;
        }
        foreach ($lines as [$key,$rule]) {
            $anchored = str_ends_with($rule, '$');
            $literal = $anchored ? substr($rule, 0, -1) : $rule;
            $pattern = str_replace('\*', '.*', preg_quote($literal, '~'));
            $size = strlen(str_replace('*', '', $literal));
            if (($size > $length || ($size === $length && $key === 'allow'))
                && preg_match('~^' . $pattern . ($anchored ? '$' : '') . '~', $target)) {
                $length = $size;
                $winner = $key;
            }
        }
    }
    return $winner === 'allow';
}

/**
 * Remembers each fetched page (or failure) for the rest of this process, so listings that share one product page,
 * such as one listing per warehouse, cost the seller a single request. $load does the real request.
 */
function fetch_cached(string $key, callable $load): array {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        if ($cache[$key] instanceof ScanFailure) {
            throw new ScanFailure($cache[$key]->getMessage(), $cache[$key]->cooldown);
        }
        return $cache[$key];
    }
    try {
        $result = $load();
    } catch (ScanFailure $failure) {
        $result = $failure;
    }
    if (count($cache) >= 12) {
        array_shift($cache);
    }
    $cache[$key] = $result;
    if ($result instanceof ScanFailure) {
        throw new ScanFailure($result->getMessage(), $result->cooldown);
    }
    return $result;
}

function guarded_fetch(string $url, string $dir): array {
    return fetch_cached($url, static fn() => guarded_fetch_live($url, $dir));
}

function guarded_fetch_live(string $url, string $dir): array {
    static $robots = [];
    $host = strtolower(product_url($url)['host']);
    $file = $dir . '/robots-' . hash('sha256',$host) . '.json';
    if (!isset($robots[$file])) {
        $entry = is_file($file) ? json_decode((string)@file_get_contents($file),true) : null;
        if (is_string($entry['rules'] ?? null) && ($entry['expires'] ?? 0) > time()) {
            $robots[$file] = $entry['rules'];
        } else {
            [$code, $body] = fetch_page('https://' . $host . '/robots.txt');
            if (!in_array($code, [200,404,410], true)) {
                throw new ScanFailure('Seller crawl rules could not be checked.');
            }
            $rules = $code === 200 ? $body : '';
            $robots[$file] = $rules;
            if (strlen($rules) <= 512_000) {
                $json = json_encode(['rules'=>$rules,'expires'=>time() + ROBOTS_CACHE_TTL],JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json !== false && @file_put_contents($file,$json,LOCK_EX) !== false) {
                    @chmod($file,0600);
                }
            }
        }
    }
    return fetch_page($url, $robots[$file]);
}

function result(string $status, ?int $price, string $detail, ?int $qty = null, bool $from = false): array {
    return ['status'=>$status,'price'=>$price,'qty'=>$qty,'from_price'=>$from,
        'observed_at'=>iso_now(),'source'=>'automatic','detail'=>$detail];
}

function shopify_result(string $body, array $item): ?array {
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['variants']) || !is_array($data['variants'])) {
        return null;
    }
    parse_str(parse_url($item['url'], PHP_URL_QUERY) ?? '', $query);
    $id = is_string($query['variant'] ?? null) ? $query['variant'] : '';
    // Variant text is compared without capitals or spaces, so "No case,No gps" matches "No case, No GPS".
    $flat = static fn($text) => (string)preg_replace('/\s+/u', '', strtolower((string)$text));
    $terms = array_values(array_filter(array_map($flat, explode('|', $item['variant'])), 'strlen'));
    $matches = [];
    foreach ($data['variants'] as $v) {
        if (!is_array($v) || !is_int($v['price'] ?? null) || !is_bool($v['available'] ?? null)) {
            continue;
        }
        $options = array_map($flat, $v['options'] ?? []);
        // Some stores keep the choice in the variant title ("915 MHz / BASE ...") rather than in separate options.
        foreach (explode('/', (string)($v['public_title'] ?? $v['title'] ?? '')) as $part) {
            $options[] = $flat($part);
        }
        if (($id !== '' && (string)$v['id'] !== $id) || array_diff($terms, $options)) {
            continue;
        }
        $matches[] = $v;
    }
    if (!$matches) {
        throw new ScanFailure('The selected product variant was not found. Review variant options in Admin.');
    }
    $available = array_values(array_filter($matches, static fn($v) => $v['available']));
    $choices = $available ?: $matches;
    $price = min(array_column($choices,'price'));
    // Shopify availability may include inventory policies that allow sales without shelf stock.
    $preorder = static fn($v) => (bool)preg_match('/\b(?:pre[ -]?order|back[ -]?order)\b/i', $v['title'] ?? '');
    $preorderOnly = $available && (bool)preg_match('/\b(?:pre[ -]?order|back[ -]?order)\b/i', $data['title'] ?? '');
    if ($available && count(array_filter($available, $preorder)) === count($available)) {
        $preorderOnly = true;
    }
    return result($available ? ($preorderOnly ? 'preorder' : 'orderable') : 'out_of_stock',
        $price, 'Store product feed · ' . ($id || $terms ? 'matching variants' : 'any available variant'),
        null, count(array_unique(array_column($choices, 'price'))) > 1);
}

function availability(string $value): string {
    $value = strtolower(preg_replace('~^.*/~', '', $value));
    return match ($value) {
        'instock','limitedavailability','onlineonly' => 'in_stock',
        'outofstock','soldout','discontinued' => 'out_of_stock',
        'preorder','presale','backorder' => 'preorder',
        default => 'unknown',
    };
}

function schema_entries(array $schema): Generator {
    if (array_is_list($schema)) {
        foreach ($schema as $entry) {
            if (is_array($entry)) {
                yield from schema_entries($entry);
            }
        }
        return;
    }
    yield $schema;
    if (is_array($schema['@graph'] ?? null)) {
        yield from schema_entries($schema['@graph']);
    }
    if (is_array($schema['mainEntity'] ?? null)) {
        yield from schema_entries($schema['mainEntity']);
    }
}

/** Maps a seller's warehouse name ("Germany Warehouse", "United States Warehouse") to a WAREHOUSES key. */
function warehouse_key(string $name): ?string {
    $name = strtolower($name);
    return match (true) {
        (bool)preg_match('/\b(?:china|shenzhen|cn)\b/', $name) => 'cn',
        (bool)preg_match('/\b(?:germany|munich|europe|eu)\b/', $name) => 'eu',
        (bool)preg_match('/\b(?:united states|usa|us|u\.s\.)\b/', $name) => 'us',
        default => null,
    };
}

/** For a listing tied to a warehouse, picks that warehouse's offer from an AggregateOffer that names its warehouses. */
function warehouse_offer(array $offer, string $warehouse): ?array {
    if ($warehouse === '' || !is_array($offer['offers'] ?? null) || !array_is_list($offer['offers'])) {
        return null;
    }
    $named = false;
    foreach ($offer['offers'] as $sub) {
        $place = is_array($sub) ? ($sub['availableAtOrFrom']['name'] ?? $sub['name'] ?? null) : null;
        $key = is_string($place) ? warehouse_key($place) : null;
        if ($key === null) {
            continue;
        }
        $named = true;
        if ($key === $warehouse) {
            return $sub + ['url'=>$offer['url'] ?? null];
        }
    }
    if ($named) {
        throw new ScanFailure('The seller page does not list this warehouse. Review the listing in Admin.');
    }
    return null;
}

function structured_offer(DOMXPath $xpath, array $item): ?array {
    if ($item['variant'] !== '' || preg_match('/(?:\?|&)variant=/', $item['url'])) {
        return null; // An unscoped product offer cannot verify a selected option.
    }
    $products = [];
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
        $json = json_decode(trim($script->textContent), true);
        if (!is_array($json)) {
            continue;
        }
        foreach (schema_entries($json) as $entry) {
            if (in_array('Product', (array)($entry['@type'] ?? []), true) && isset($entry['offers'])) {
                $products[] = $entry;
            }
        }
    }
    if (count($products) !== 1 || !is_array($products[0]['offers'])) {
        return null;
    }
    $offers = $products[0]['offers'];
    $offers = array_is_list($offers) ? $offers : [$offers];
    if (count($offers) !== 1 || !is_array($offers[0])) {
        return null;
    }
    $offer = warehouse_offer($offers[0], $item['warehouse'] ?? '') ?? $offers[0];
    $aggregate = in_array('AggregateOffer', (array)($offer['@type'] ?? []), true);
    $offerHost = parse_url(is_string($offer['url'] ?? null) ? $offer['url'] : '', PHP_URL_HOST);
    if ($offerHost && strcasecmp($offerHost, product_url($item['url'])['host']) !== 0) {
        return null;
    }
    $status = availability(is_string($offer['availability'] ?? null) ? $offer['availability'] : '');
    if ($aggregate && $status === 'in_stock') {
        $status = 'orderable';
    }
    $price = null;
    $rawPrice = $offer[$aggregate ? 'lowPrice' : 'price'] ?? null;
    if (strtoupper((string)($offer['priceCurrency'] ?? '')) === 'USD' && is_numeric($rawPrice)) {
        $price = cents((string)$rawPrice);
    }
    return $price !== null || $status !== 'unknown' ? result($status, $price, 'Seller product data', null, $aggregate) : null;
}

function selected_text(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string {
    if ($expression === '') {
        return '';
    }
    if ($context !== null && $expression === '.') {
        return trim(preg_replace('/\s+/u', ' ', $context->textContent) ?? '');
    }
    $nodes = @$xpath->query($expression, $context);
    if ($nodes === false) {
        throw new ScanFailure('The product selector is invalid.');
    }
    if ($nodes->length !== 1) {
        return '';
    }
    return trim(preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent) ?? '');
}

function page_result(string $body, array $item): ?array {
    $doc = new DOMDocument();
    if (!@$doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return null;
    }
    $xpath = new DOMXPath($doc);
    if ($item['scope'] === '') {
        return structured_offer($xpath, $item);
    }
    $node = @$xpath->query($item['scope']);
    if ($node === false || $node->length !== 1) {
        throw new ScanFailure('The stock selector must match one product block.');
    }
    $context = $node->item(0);
    $statusText = selected_text($xpath, '.', $context);
    if ($statusText === '') {
        return null;
    }
    $saysOut = $item['sold'] !== '' && stripos($statusText, $item['sold']) !== false;
    $saysIn = $item['ready'] !== '' && stripos($statusText, $item['ready']) !== false;
    $preorder = preg_match('/\b(?:pre[ -]?order|back[ -]?order)\b/i', $statusText);
    $status = match (true) {
        (bool)$preorder => 'preorder',
        $saysIn && !$saysOut => 'in_stock',
        $saysOut && !$saysIn => 'out_of_stock',
        default => 'unknown',
    };
    $qty = $status === 'in_stock'
        && preg_match('/\b(\d{1,6})\s+(?:left|ready|in stock|available)\b/i', $statusText, $match) ? (int)$match[1] : null;
    $priceText = selected_text($xpath, $item['price_path'], $context);
    $price = null;
    if ($priceText !== '' && preg_match_all('/\$\s*([0-9][0-9,]*\.\d{2})\b/', $priceText, $m) === 1) {
        $price = cents(str_replace(',', '', $m[1][0]));
    }
    return $status === 'unknown' && $price === null ? null : result($status, $price, 'Seller product page', $qty);
}

const BULK_MIN_PAGES = 3;   // a store with fewer product pages than this in one run is cheaper to read page by page
const BULK_PAGE_SIZE = 100; // products per feed page (250 can pass the 4 MB read limit)
const BULK_MAX_PAGES = 8;
const BULK_HOST_DAYS = 14;  // how long the server trusts "this store's feed works" without the runner confirming it

/** The product handle in a Shopify-style product link, or null. */
function shopify_handle(string $url): ?string {
    try {
        $path = product_url($url)['path'];
    } catch (InvalidArgumentException) {
        return null;
    }
    return preg_match('~/products/([a-z0-9][a-z0-9._-]*)/?$~iD', $path, $m) ? strtolower($m[1]) : null;
}

/** Converts a product from /products.json into the shape of /products/<handle>.js, so shopify_result() reads both. */
function shopify_feed_to_product(array $p): ?array {
    if (!is_string($p['title'] ?? null) || !is_array($p['variants'] ?? null)) {
        return null;
    }
    $variants = [];
    foreach ($p['variants'] as $v) {
        if (!is_array($v) || !is_int($v['id'] ?? null) || !is_bool($v['available'] ?? null) || !is_numeric($v['price'] ?? null)) {
            continue;
        }
        $options = array_values(array_filter([$v['option1'] ?? null, $v['option2'] ?? null, $v['option3'] ?? null], 'is_string'));
        $title = (string)($v['title'] ?? '');
        $variants[] = ['id'=>$v['id'], 'title'=>$title, 'public_title'=>strcasecmp($title, 'Default Title') === 0 ? null : $title,
            'options'=>$options, 'price'=>(int)round((float)$v['price'] * 100), 'available'=>$v['available']];
    }
    return $variants ? ['title'=>$p['title'], 'variants'=>$variants] : null;
}

/**
 * Reads a store's /products.json, page by page, until every wanted handle is found. $fetch takes a URL and returns [status, body].
 * Returns [handle => product in .js shape]. An empty list means the store has no readable feed, so the caller reads page by page.
 */
function shopify_bulk_lookup(string $host, array $handles, callable $fetch): array {
    $want = array_fill_keys($handles, true);
    $found = [];
    for ($page = 1; $page <= BULK_MAX_PAGES && count($found) < count($want); $page++) {
        try {
            [$status, $body] = $fetch('https://' . $host . '/products.json?limit=' . BULK_PAGE_SIZE . '&page=' . $page);
        } catch (ScanFailure) {
            break;
        }
        $data = $status === 200 ? json_decode($body, true) : null;
        if (!is_array($data) || !is_array($data['products'] ?? null)) {
            break;
        }
        foreach ($data['products'] as $product) {
            $handle = is_array($product) && is_string($product['handle'] ?? null) ? strtolower($product['handle']) : '';
            if (isset($want[$handle]) && !isset($found[$handle]) && ($converted = shopify_feed_to_product($product))) {
                $found[$handle] = $converted;
            }
        }
        if (count($data['products']) < BULK_PAGE_SIZE) {
            break;
        }
    }
    return $found;
}

/** True when the runner has recently shown that this store's feed can be read in bulk. */
function bulk_host_fresh(array $state, string $host, int $now): bool {
    return ($state['bulk_hosts'][$host] ?? 0) > $now - BULK_HOST_DAYS * 86400;
}

/** $bulk is [handle => product] from shopify_bulk_lookup(), or null to read each page. */
function scan_item(array $item, string $dir, ?array $bulk = null): array {
    $url = $item['url'];
    $parsed = product_url($url);
    if ($bulk !== null && $item['mode'] === 'auto' && ($handle = shopify_handle($url)) !== null && isset($bulk[$handle])) {
        if ($found = shopify_result(json_encode($bulk[$handle], JSON_THROW_ON_ERROR), $item)) {
            return $found;
        }
    }
    if ($item['mode'] === 'auto' && preg_match('~^(.*?/products/[a-z0-9][a-z0-9._-]*)/?$~iD', $parsed['path'], $path)) {
        [$status, $body] = guarded_fetch('https://' . strtolower($parsed['host']) . $path[1] . '.js', $dir);
        if ($status === 200 && ($found = shopify_result($body, $item))) {
            return $found;
        }
    }
    [$status,$body] = guarded_fetch($url, $dir);
    if ($status !== 200) {
        throw new ScanFailure('Seller returned HTTP ' . $status . '.');
    }
    return page_result($body, $item)
        ?? throw new ScanFailure('No reliable price or stock signal was readable. Review the listing or set a page rule.');
}

const EVENT_LIMIT = 200;
const REMOTE_PER_SELLER = 3;

/** Compares a fresh reading with the previous live reading. Research snapshots never produce events. */
function detect_events(array $row, array $new): array {
    $old = $row['result'];
    if (empty($old['observed_at'])) {
        return [];
    }
    $base = ['t'=>iso_now(),'id'=>$row['id'],'name'=>$row['name'],'seller'=>$row['seller']];
    $live = ['in_stock','orderable'];
    $was = $old['status'];
    $is = $new['status'];
    $events = [];
    if ($was !== $is && $was !== 'unknown' && $is !== 'unknown') {
        if (in_array($is, $live, true) && !in_array($was, $live, true)) {
            $events[] = $base + ['type'=>'back_in_stock','status'=>$is];
        } elseif (in_array($was, $live, true) && $is === 'out_of_stock') {
            $events[] = $base + ['type'=>'sold_out'];
        }
    }
    if (is_int($old['price'] ?? null) && is_int($new['price'] ?? null) && $old['price'] !== $new['price']) {
        $events[] = $base + ['type'=>$new['price'] < $old['price'] ? 'price_drop' : 'price_rise',
            'from'=>$old['price'],'to'=>$new['price']];
    }
    return $events;
}

function record_events(array &$fresh, array $events): void {
    if ($events) {
        $fresh['events'] = array_slice(array_merge($fresh['events'] ?? [], $events), -EVENT_LIMIT);
    }
}

function announce_events(array $events, string $url): void {
    foreach ($events as $event) {
        if (in_array($event['type'], ['back_in_stock','price_drop'], true)) {
            notify(ucfirst(event_text($event)), event_text($event) . "\n" . $url, ['url'=>$url, 'type'=>$event['type']]);
            notify_public_event($event, $url);
        }
    }
}

function scan_fingerprint(array $item): string {
    $parts = [$item['url'],$item['mode'],$item['variant'] ?? '',$item['scope'] ?? '',
        $item['ready'] ?? '',$item['sold'] ?? '',$item['price_path'] ?? ''];
    if (($item['warehouse'] ?? '') !== '') {
        $parts[] = $item['warehouse']; // Added only when set, so existing listings keep their fingerprints.
    }
    return substr(hash('sha256', json_encode($parts)), 0, 24);
}

/** Saves one scan outcome. Discards it if the listing was edited or removed while the scan ran. */
function store_scan(string $dir, string $id, string $fingerprint, string $host, ?array $new, ?string $message,
    int $cooldownUntil): array {
    $stamp = iso_now();
    $events = [];
    $url = '';
    change_state($dir, static function (&$fresh) use ($id,$fingerprint,$host,$new,$message,$stamp,$cooldownUntil,&$events,&$url) {
        if ($cooldownUntil) {
            $fresh['cooldowns'][$host] = $cooldownUntil;
        }
        foreach ($fresh['items'] as &$row) {
            if ($row['id'] !== $id || scan_fingerprint($row) !== $fingerprint) {
                continue;
            }
            $url = $row['url'];
            $row['attempted_at'] = $stamp;
            $row['error'] = $message;
            if ($new !== null) {
                if ($new['price'] === null && $row['result']['price'] !== null) {
                    $new['price'] = $row['result']['price'];
                    $new['from_price'] = $row['result']['from_price'];
                    $new['price_previous'] = true;
                }
                $events = detect_events($row, $new);
                $row['result'] = $new;
                record_history($fresh, $id, $new);
            }
            break;
        }
        unset($row);
        record_events($fresh, $events);
    });
    announce_events($events, $url);
    return $events;
}

function check_listing(string $dir, array $item, array &$state): void {
    $host = seller_bucket(product_url($item['url'])['host']);
    $next = time() + SELLER_SCAN_GAP;
    $state['host_next_scan'][$host] = $next;
    change_state($dir, static function (&$fresh) use ($host,$next) {
        $fresh['host_next_scan'][$host] = $next;
    });
    $new = null;
    $message = null;
    $cooldownUntil = 0;
    try {
        $new = scan_item($item, $dir);
    } catch (ScanFailure | InvalidArgumentException $e) {
        $message = $e->getMessage();
        if ($e instanceof ScanFailure && $e->cooldown > 0) {
            $cooldownUntil = time() + min($e->cooldown, 604800);
        }
    }
    if ($cooldownUntil) {
        $state['cooldowns'][$host] = $cooldownUntil;
    }
    store_scan($dir, $item['id'], scan_fingerprint($item), $host, $new, $message, $cooldownUntil);
}

function is_remote(array $item): bool {
    return ($item['checker'] ?? 'server') === 'remote';
}

/** Returns the seller host when this listing is due for the U.S. runner right now, or null. */
function remote_ready(array $state, array $item, int $now): ?string {
    $gap = max(30, (int)config()['interval_minutes']) * 60 - 60;
    if (!is_remote($item) || $item['paused'] || $item['mode'] === 'manual'
        || ($item['attempted_at'] && $now - strtotime($item['attempted_at']) < $gap)) {
        return null;
    }
    try {
        $host = seller_bucket(product_url($item['url'])['host']);
    } catch (InvalidArgumentException) {
        return null;
    }
    return max($state['cooldowns'][$host] ?? 0, $state['host_next_scan'][$host] ?? 0) > $now ? null : $host;
}

function has_remote_work(array $state): bool {
    $now = time();
    foreach ($state['items'] as $item) {
        if (remote_ready($state, $item, $now) !== null) {
            return true;
        }
    }
    return false;
}

/** Hands the U.S. runner its next listings: eligible, up to REMOTE_PER_SELLER distinct pages per seller, with the same pacing as the server cron. */
function claim_jobs(string $dir, int $limit): array {
    $jobs = [];
    change_state($dir, static function (&$state) use (&$jobs,$limit) {
        $now = time();
        $state['last_runner_run'] = iso_now();
        $order = array_keys($state['items']);
        usort($order, static fn($a,$b) => strcmp($state['items'][$a]['attempted_at'] ?? '', $state['items'][$b]['attempted_at'] ?? ''));
        $perHost = [];
        foreach ($order as $index) {
            if (count($jobs) >= $limit) {
                break;
            }
            $item = $state['items'][$index];
            $host = remote_ready($state, $item, $now);
            if ($host === null) {
                continue;
            }
            // The cap counts distinct pages, not listings: listings that share a page (one per warehouse, for
            // example) are fetched once by the runner, so they all go out together.
            $pages = $perHost[$host] ?? [];
            // A store whose product feed the runner can read in bulk is claimed whole: one feed read covers every page.
            $cap = bulk_host_fresh($state, $host, $now) ? 60 : REMOTE_PER_SELLER;
            if (!isset($pages[$item['url']]) && count($pages) >= $cap) {
                continue;
            }
            $perHost[$host][$item['url']] = true;
            $jobs[] = ['id'=>$item['id'],'name'=>$item['name'],'url'=>$item['url'],'mode'=>$item['mode'],
                'variant'=>$item['variant'] ?? '','scope'=>$item['scope'] ?? '','ready'=>$item['ready'] ?? '',
                'sold'=>$item['sold'] ?? '','price_path'=>$item['price_path'] ?? '','warehouse'=>$item['warehouse'] ?? '',
                'fingerprint'=>scan_fingerprint($item)];
        }
        // One pause per seller covers the whole group, so the next group waits the usual 20 minutes.
        foreach (array_keys($perHost) as $host) {
            $state['host_next_scan'][$host] = $now + SELLER_SCAN_GAP;
        }
    });
    return $jobs;
}

function clean_text(mixed $value, int $limit): string {
    return substr(trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', is_string($value) ? $value : '')), 0, $limit);
}

/** Validates and stores results posted by the U.S. runner. Returns counts for the runner's log. */
function accept_results(string $dir, array $results, array $bulkHosts = [], array $bulkFailed = []): array {
    $summary = ['stored'=>0,'ignored'=>0];
    $state = load_state($dir);
    $byId = [];
    foreach ($state['items'] as $item) {
        $byId[$item['id']] = $item;
    }
    foreach (array_slice($results, 0, 60) as $r) {
        $item = is_array($r) ? ($byId[$r['id'] ?? ''] ?? null) : null;
        if (!$item || !is_remote($item) || $item['paused'] || $item['mode'] === 'manual'
            || !is_string($r['fingerprint'] ?? null) || !hash_equals(scan_fingerprint($item), $r['fingerprint'])) {
            $summary['ignored']++;
            continue;
        }
        $host = seller_bucket(product_url($item['url'])['host']);
        $new = null;
        $message = null;
        $cooldownUntil = 0;
        if (($r['ok'] ?? false) === true) {
            $reading = is_array($r['result'] ?? null) ? $r['result'] : [];
            $price = $reading['price'] ?? null;
            $qty = $reading['qty'] ?? null;
            if (!isset(STATUSES[$reading['status'] ?? '']) || ($price !== null && (!is_int($price) || $price < 0 || $price > 99999999))
                || ($qty !== null && (!is_int($qty) || $qty < 0 || $qty > 999999))) {
                $summary['ignored']++;
                continue;
            }
            $new = result($reading['status'], $price, clean_text($reading['detail'] ?? '', 200), $qty, ($reading['from_price'] ?? false) === true);
        } else {
            $message = clean_text($r['error'] ?? '', 200) ?: 'The U.S. runner reported a failed check.';
            $cooldown = is_int($r['cooldown'] ?? null) ? max(0, min($r['cooldown'], 604800)) : 0;
            $cooldownUntil = $cooldown ? time() + $cooldown : 0;
        }
        store_scan($dir, $item['id'], $r['fingerprint'], $host, $new, $message, $cooldownUntil);
        $summary['stored']++;
    }
    $known = [];
    foreach ($state['items'] as $item) {
        try {
            $known[seller_bucket(product_url($item['url'])['host'])] = true;
        } catch (InvalidArgumentException) {
            continue;
        }
    }
    $up = array_values(array_filter(array_slice($bulkHosts, 0, 20), static fn($h) => is_string($h) && isset($known[$h])));
    $down = array_values(array_filter(array_slice($bulkFailed, 0, 20), static fn($h) => is_string($h) && isset($known[$h])));
    if ($up || $down) {
        change_state($dir, static function (&$fresh) use ($up, $down) {
            foreach ($up as $host) {
                $fresh['bulk_hosts'][$host] = time();
            }
            foreach ($down as $host) {
                unset($fresh['bulk_hosts'][$host]);
            }
        });
    }
    return $summary;
}

/** Admin "Check now": runs one listing immediately, respecting seller pauses and pacing. */
function check_now(string $dir, string $id): string {
    if (!extension_loaded('curl') || !extension_loaded('dom')) {
        throw new InvalidArgumentException('Enable cURL and DOM in the web PHP version to check from Admin.');
    }
    $runLock = fopen($dir . '/checker.lock', 'c');
    if (!$runLock || !flock($runLock, LOCK_EX | LOCK_NB)) {
        throw new InvalidArgumentException('The scheduled checker is running right now. Try again in a minute.');
    }
    try {
        $state = load_state($dir);
        foreach ($state['items'] as $item) {
            if ($item['id'] !== $id) {
                continue;
            }
            if ($item['paused'] || $item['mode'] === 'manual') {
                throw new InvalidArgumentException('Enable automatic checks for this listing first.');
            }
            $host = seller_bucket(product_url($item['url'])['host']);
            $wait = max($state['cooldowns'][$host] ?? 0, $state['host_next_scan'][$host] ?? 0) - time();
            if ($wait > 0) {
                throw new InvalidArgumentException('This seller was checked or paused recently. Try again in '
                    . max(1, (int)ceil($wait / 60)) . ' minute(s).');
            }
            if (is_remote($item)) {
                throw new InvalidArgumentException('This listing is checked by the U.S. runner. Use Queue check to hand it to the next run.');
            }
            @set_time_limit(60);
            check_listing($dir, $item, $state);
            $fresh = load_state($dir);
            foreach ($fresh['items'] as $row) {
                if ($row['id'] === $id) {
                    return $row['error'] ? 'Check finished with a problem: ' . $row['error']
                        : 'Check complete: ' . STATUSES[$row['result']['status']] . '.';
                }
            }
            return 'Check complete.';
        }
        throw new InvalidArgumentException('This listing no longer exists.');
    } finally {
        fclose($runLock);
    }
}

function run_checks(string $dir): int {
    $runLock = fopen($dir . '/checker.lock', 'c');
    if (!$runLock || !flock($runLock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    try {
        $state = load_state($dir);
        $items = $state['items'];
        usort($items, static fn($a,$b) => strcmp($a['attempted_at'] ?? '', $b['attempted_at'] ?? ''));
        $limit = max(1, min(10, (int)config()['batch_size']));
        $gap = max(30, (int)config()['interval_minutes']) * 60 - 60;
        $deadline = time() + 110;
        $done = 0;
        foreach ($items as $item) {
            if ($done >= $limit || time() >= $deadline) {
                break;
            }
            if ($item['paused'] || $item['mode'] === 'manual' || is_remote($item)
                || ($item['attempted_at'] && time() - strtotime($item['attempted_at']) < $gap)) {
                continue;
            }
            try {
                $host = seller_bucket(product_url($item['url'])['host']);
            } catch (InvalidArgumentException) {
                continue; // A malformed stored link must not stop the rest of the batch.
            }
            if (max($state['cooldowns'][$host] ?? 0, $state['host_next_scan'][$host] ?? 0) > time()) {
                continue;
            }
            check_listing($dir, $item, $state);
            $done++;
        }
        change_state($dir, static function (&$fresh) {
            $fresh['last_run'] = iso_now();
            $fresh['cooldowns'] = array_filter($fresh['cooldowns'], static fn($until) => $until > time());
            $fresh['host_next_scan'] = array_filter($fresh['host_next_scan'], static fn($until) => $until > time());
        });
        return $done;
    } finally {
        fclose($runLock);
    }
}

/** Admin diagnostic: one plain request with the checker's own settings, reporting what actually happened. */
function diagnose_url(string $url): array {
    $out = ['url'=>$url,'dns'=>'','http'=>0,'curl'=>'','ms'=>0,'server'=>'','note'=>''];
    try {
        $host = strtolower(product_url($url)['host']);
    } catch (InvalidArgumentException $e) {
        return ['curl'=>$e->getMessage()] + $out;
    }
    $ips = gethostbynamel($host) ?: [];
    $out['dns'] = $ips ? implode(', ', $ips) : 'lookup failed';
    if (!$ips) {
        return $out;
    }
    $headers = [];
    $body = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROXY=>'',
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4, CURLOPT_RESOLVE=>[$host . ':443:' . $ips[0]],
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>12,
        CURLOPT_USERAGENT=>'OffgridCommsTracker/1.0 (public retail price and stock checks)',
        CURLOPT_ENCODING=>'', CURLOPT_HTTPHEADER=>['Accept: application/json, text/html, text/plain', 'Accept-Language: en-US,en;q=0.8'],
        CURLOPT_HEADERFUNCTION=>static function ($c, string $line) use (&$headers): int {
            if (preg_match('/^(server|cf-ray|cf-mitigated|retry-after|location|x-shopify[a-z-]*):\s*(.+)$/i', trim($line), $m)) {
                $headers[] = strtolower($m[1]) . ': ' . substr($m[2], 0, 80);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function ($c, string $chunk) use (&$body): int {
            $body .= substr($chunk, 0, max(0, 3000 - strlen($body)));
            return strlen($chunk);
        },
    ]);
    $started = microtime(true);
    $ok = curl_exec($curl);
    $out['ms'] = (int)round((microtime(true) - $started) * 1000);
    $out['http'] = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($ok === false) {
        $out['curl'] = curl_errno($curl) . ': ' . curl_error($curl);
    }
    curl_close($curl);
    $out['server'] = implode(' · ', $headers);
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
        $out['note'] = 'Page title: ' . trim(preg_replace('/\s+/', ' ', html_entity_decode($m[1])));
    }
    return $out;
}

function run_diagnostics(array $state): array {
    if (!extension_loaded('curl')) {
        return [['curl'=>'The cURL extension is not loaded in the web PHP version.','url'=>'','dns'=>'','http'=>0,'ms'=>0,'server'=>'','note'=>'']];
    }
    @set_time_limit(90);
    $targets = ['https://example.com/'];
    $seen = [];
    foreach ($state['items'] as $item) {
        try {
            $host = seller_bucket(product_url($item['url'])['host']);
        } catch (InvalidArgumentException) {
            continue;
        }
        if ($item['mode'] !== 'manual' && !isset($seen[$host]) && count($seen) < 4) {
            $seen[$host] = true;
            $targets[] = $item['url'];
        }
    }
    $report = [];
    foreach ($targets as $url) {
        $report[] = diagnose_url($url);
    }
    return $report;
}
