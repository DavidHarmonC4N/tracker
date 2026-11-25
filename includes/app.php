<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

const FIRMWARE = ['meshcore'=>'MeshCore only','meshtastic'=>'Meshtastic only','both'=>'Both firmware options'];
const KINDS = ['handheld'=>'Handheld','keyboard'=>'Keyboard radio','solar'=>'Solar node','base'=>'Base station','tracker'=>'GPS tracker','board'=>'Board or kit','other'=>'Other'];
const ROLES = ['companion'=>'Companion','tracker'=>'Tracker','repeater'=>'Repeater','solar_repeater'=>'Solar repeater','unspecified'=>'Unspecified'];
const SETUP = ['meshcore'=>'MeshCore installed','meshtastic'=>'Meshtastic installed','choice'=>'Choose or request firmware','flash'=>'Install MeshCore yourself','unknown'=>'See listing'];
const STATUSES = ['in_stock'=>'In stock','orderable'=>'Orderable','out_of_stock'=>'Out of stock','preorder'=>'Preorder / backorder','unknown'=>'Unverified'];
const WAREHOUSES = [''=>'Not specified','us'=>'U.S. warehouse','eu'=>'EU warehouse','cn'=>'China warehouse'];
const CHECKERS = ['server'=>'This server (cron)','remote'=>'U.S. runner (GitHub Actions)'];
const MODES = ['auto'=>'Automatic (Shopify or structured data)','page'=>'Product page','manual'=>'Manual review only'];

function config(): array {
    static $config;
    return $config ??= require __DIR__ . '/../config.php';
}

function data_dir(): string {
    $path = config()['data_dir'];
    $dir = is_string($path) && str_starts_with($path, '/') ? realpath($path) : false;
    if (!$dir || !is_dir($dir) || !is_readable($dir) || !is_writable($dir)) {
        throw new RuntimeException('Set data_dir in config.php to a writable absolute directory.');
    }
    $root = PHP_SAPI === 'cli' ? false : realpath($_SERVER['DOCUMENT_ROOT'] ?? '/');
    $app = realpath(__DIR__ . '/..');
    if ($dir === '/' || ($root && ($dir === $root || str_starts_with($dir, $root . '/')))
        || $dir === $app || str_starts_with($dir, $app . '/')) {
        throw new RuntimeException('The data directory must be outside the public web root.');
    }
    return $dir;
}

function seed(): array {
    static $cached; // Built once per request: load_state() needs the starter list on every call.
    if ($cached !== null) {
        return $cached;
    }
    $items = with_roles(json_decode(file_get_contents(__DIR__ . '/seed.json'), true, 512, JSON_THROW_ON_ERROR));
    if (!ingest_enabled()) {
        // Starter listings that ask for the U.S. runner fall back to the server cron when the runner is not set up.
        foreach ($items as &$item) {
            unset($item['checker']);
        }
        unset($item);
    }
    return $cached = $items;
}

function with_roles(array $items): array {
    $starterRoles = json_decode(file_get_contents(__DIR__ . '/roles.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($items as &$item) {
        $item['role'] ??= $starterRoles[$item['id']] ?? ($item['kind'] === 'tracker' ? 'tracker' : 'unspecified');
    }
    return $items;
}

/** Starter listings added after launch carry "late". Existing installs get each one once, so deleting it sticks. */
function add_late_seed(array $state): array {
    $have = array_flip(array_column($state['items'], 'id'));
    $seeded = array_flip($state['seeded'] ?? []);
    foreach (seed() as $item) {
        if (empty($item['late']) || isset($seeded[$item['id']])) {
            continue;
        }
        $seeded[$item['id']] = true;
        if (!isset($have[$item['id']])) {
            $state['items'][] = $item;
        }
    }
    $state['seeded'] = array_keys($seeded);
    return $state;
}

function load_state(string $dir): array {
    $path = $dir . '/tracker.json';
    if (!is_file($path)) {
        return ['password'=>null,'items'=>seed(),'attempts'=>[],'last_run'=>null,'cooldowns'=>[],'host_next_scan'=>[],
            'submissions'=>[],'submission_attempts'=>[],'events'=>[],'history'=>[],'last_runner_run'=>null,
            'seeded'=>array_column(array_filter(seed(), static fn($i) => !empty($i['late'])), 'id')];
    }
    $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $state['items'] = with_roles($state['items']);
    $state = add_late_seed($state);
    $cooldowns = [];
    foreach ($state['cooldowns'] ?? [] as $host=>$until) {
        $key = seller_bucket($host);
        $cooldowns[$key] = max($cooldowns[$key] ?? 0, (int)$until);
    }
    $state['cooldowns'] = $cooldowns;
    return $state + ['submissions'=>[],'submission_attempts'=>[],'host_next_scan'=>[],'events'=>[],'history'=>[],'last_runner_run'=>null];
}

const HISTORY_POINTS = 120;

/**
 * Price history: one point [unix time, price in cents or null, status] each time a listing's price or stock status
 * differs from its last point. Unverified and unobserved readings are not recorded.
 */
function record_history(array &$state, string $id, array $result): void {
    $status = $result['status'] ?? 'unknown';
    if (empty($result['observed_at']) || $status === 'unknown' || !isset(STATUSES[$status])) {
        return;
    }
    $price = is_int($result['price'] ?? null) ? $result['price'] : null;
    $list = $state['history'][$id] ?? [];
    $last = $list ? end($list) : null;
    if ($last && $last[1] === $price && $last[2] === $status) {
        return;
    }
    $list[] = [strtotime((string)$result['observed_at']) ?: time(), $price, $status];
    $state['history'][$id] = array_slice($list, -HISTORY_POINTS);
}

/** Lowest and highest recorded price, and the last price change, for one listing's history. */
function history_summary(array $points): array {
    $prices = array_values(array_filter(array_column($points, 1), 'is_int'));
    $out = ['low'=>$prices ? min($prices) : null, 'high'=>$prices ? max($prices) : null, 'was'=>null];
    $priced = array_values(array_filter($points, static fn($p) => is_int($p[1])));
    if (count($priced) >= 2) {
        $now = $priced[count($priced) - 1][1];
        $start = count($priced) - 1;
        while ($start > 0 && $priced[$start - 1][1] === $now) {
            $start--;
        }
        if ($start > 0) {
            $out['was'] = ['price'=>$priced[$start - 1][1], 't'=>gmdate('c', (int)$priced[$start][0])];
        }
    }
    return $out;
}

function change_state(string $dir, callable $change): mixed {
    $lock = fopen($dir . '/tracker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the tracker data.');
    }
    $temp = null;
    try {
        $data = load_state($dir);
        $result = $change($data);
        $temp = tempnam($dir, 'tracker-');
        if ($temp === false) {
            throw new RuntimeException('Could not create a data file.');
        }
        chmod($temp, 0600);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $dir . '/tracker.json')) {
            throw new RuntimeException('Could not save the tracker data.');
        }
        return $result;
    } finally {
        if (is_string($temp) && is_file($temp)) {
            unlink($temp);
        }
        fclose($lock);
    }
}

function is_staging(): bool {
    return (config()['staging'] ?? false) === true;
}

function common_headers(): void {
    if (is_staging()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
}

function field(array $source, string $key, string $fallback = ''): string {
    $value = $source[$key] ?? $fallback;
    return is_string($value) ? trim($value) : $fallback;
}

function h(string|int|null $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Optional one-line reasons shown under a field, used by the Add by link screen. [field => [tag, text]] */
function field_hint(string $key): string {
    $hint = $GLOBALS['field_hints'][$key] ?? null;
    return $hint ? '<span class="why"><i>' . h($hint[0]) . '</i> · ' . h($hint[1]) . '</span>' : '';
}

function select_box(string $key, string $label, array $options, array $values): void {
    // A menu that offers a blank choice ("Not specified") must not also be required, or the browser blocks saving.
    echo '<label>' . h($label) . '<select name="' . h($key) . '"' . (isset($options['']) ? '' : ' required') . '>';
    foreach ($options as $value=>$title) {
        echo '<option value="' . h($value) . '"' . (field($values,$key) === $value ? ' selected' : '') . '>' . h($title) . '</option>';
    }
    echo '</select>' . field_hint($key) . '</label>';
}

function input_box(string $key, string $label, array $values, string $type = 'text', string $attributes = ''): void {
    echo '<label>' . h($label) . '<input name="' . h($key) . '" type="' . h($type)
        . '" value="' . h(field($values,$key)) . '" ' . $attributes . '>' . field_hint($key) . '</label>';
}

function product_url(string $url): array {
    if (strlen($url) > 1500 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Enter a valid HTTPS product page URL.');
    }
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])
        || (isset($p['port']) && $p['port'] !== 443)
        || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D', $host)
        || preg_match('/\.(?:local|internal|localhost|test|invalid|example)$/D', $host)) {
        throw new InvalidArgumentException('Use a public HTTPS seller link on port 443.');
    }
    return $p + ['path'=>'/'];
}

function seller_bucket(string $host): string {
    $host = strtolower($host);
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function listing_fields(array $post, string $defaultRole = 'unspecified'): array {
    $row = ['url'=>field($post,'url')];
    product_url($row['url']);
    foreach (['firmware'=>FIRMWARE,'kind'=>KINDS,'role'=>ROLES] as $key=>$options) {
        $row[$key] = field($post,$key,$key === 'role' ? $defaultRole : '');
        if (!isset($options[$row[$key]])) {
            throw new InvalidArgumentException('Choose a valid ' . ($key === 'kind' ? 'radio type' : $key) . '.');
        }
    }
    $row['warehouse'] = field($post,'warehouse');
    if (!isset(WAREHOUSES[$row['warehouse']])) {
        throw new InvalidArgumentException('Choose a valid warehouse.');
    }
    foreach (['name'=>120,'seller'=>80,'location'=>70,'note'=>500] as $key=>$limit) {
        $row[$key] = field($post,$key);
        if (strlen($row[$key]) > $limit || (in_array($key,['name','seller'],true) && $row[$key] === '')) {
            throw new InvalidArgumentException('Check the length of ' . $key . '.');
        }
    }
    return $row;
}

function listing_url_key(string $url): string {
    $parts = product_url($url);
    $host = seller_bucket($parts['host']);
    $query = array_filter(explode('&', $parts['query'] ?? ''), static function ($pair) {
        $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
        return $key !== '' && !str_starts_with($key, 'utm_') && !in_array($key, ['gclid','fbclid','msclkid'], true);
    });
    sort($query, SORT_STRING);
    return $host . rtrim($parts['path'], '/') . ($query ? '?' . implode('&',$query) : '');
}

/** True when a listing with the same link exists. With $warehouse, only a listing for that warehouse counts (Seeed lists one page per warehouse). */
function has_listing_url(array $rows, string $url, ?string $warehouse = null): bool {
    $key = listing_url_key($url);
    foreach ($rows as $row) {
        if (listing_url_key($row['url']) === $key && ($warehouse === null || ($row['warehouse'] ?? '') === $warehouse)) {
            return true;
        }
    }
    return false;
}

function endpoint_result(): array {
    $configured = config()['data_dir'] !== '';
    $state = $configured ? load_state(data_dir()) : ['items'=>seed(),'last_run'=>null];
    $items = [];
    $now = time();
    $latest = [];
    foreach ($state['events'] ?? [] as $event) {
        $latest[$event['id']] = $event;
    }
    foreach ($state['items'] as $item) {
        try {
            if ($item['paused']) {
                continue;
            }
            $host = seller_bucket(product_url($item['url'])['host']);
        } catch (Throwable $skip) {
            // One malformed listing must not take the whole public catalog down.
            error_log('Offgrid catalog: skipped listing ' . ($item['id'] ?? '?') . ': ' . $skip->getMessage());
            continue;
        }
        $public = array_intersect_key($item, array_flip([
            'id','seller','location','name','url','firmware','setup','kind','role','note','result','attempted_at',
        ]));
        $public['warehouse'] = isset(WAREHOUSES[$item['warehouse'] ?? '']) ? ($item['warehouse'] ?? '') : '';
        $public['manual'] = $item['mode'] === 'manual';
        $public['check_failed'] = !empty($item['error']);
        $change = $latest[$item['id']] ?? null;
        $public['change'] = $change && in_array($change['type'], ['back_in_stock','price_drop'], true)
            && strtotime($change['t']) > $now - 7 * 86400
            ? array_intersect_key($change, array_flip(['type','t','from','to'])) : null;
        $summary = history_summary($state['history'][$item['id']] ?? []);
        $public['low'] = $summary['low'];
        $public['high'] = $summary['high'];
        $public['was'] = $summary['was'];
        $public['seller_paused'] = !$public['manual'] && ($state['cooldowns'][$host] ?? 0) > $now;
        $items[] = $public;
    }
    return ['items'=>$items,'configured'=>$configured,'staging'=>is_staging(),'last_run'=>$state['last_run'],
        'interval_minutes'=>max(30, (int)config()['interval_minutes'])];
}

function cents(string $value): ?int {
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Enter a USD price such as 79.95.');
    }
    return (int)round((float)$value * 100);
}

function iso_now(): string {
    return gmdate(DATE_ATOM);
}

function when(?string $iso): string {
    $time = $iso ? strtotime($iso) : false;
    return $time ? gmdate('M j, g:i A', $time) . ' UTC' : '';
}

function money(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

function event_text(array $event): string {
    $what = match ($event['type'] ?? '') {
        'back_in_stock' => 'is back in stock' . (($event['status'] ?? '') === 'orderable' ? ' (orderable)' : ''),
        'sold_out' => 'is out of stock',
        'price_drop' => 'dropped from ' . money((int)$event['from']) . ' to ' . money((int)$event['to']),
        'price_rise' => 'rose from ' . money((int)$event['from']) . ' to ' . money((int)$event['to']),
        default => 'changed',
    };
    return $event['name'] . ' at ' . $event['seller'] . ' ' . $what;
}

function base_url(): string {
    // A fixed address from config.php wins, so a forged Host header cannot change links in alerts or the feed.
    $fixed = (string)(config()['base_url'] ?? '');
    if ($fixed !== '') {
        $parts = parse_url($fixed);
        if (($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user'], $parts['query'], $parts['fragment'])
            && preg_match('/^[a-z0-9.-]+$/Di', $parts['host'])) {
            return 'https://' . strtolower($parts['host']) . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '')
                . rtrim($parts['path'] ?? '', '/') . '/';
        }
        error_log('Offgrid: base_url in config.php must be a plain https:// address. Falling back to the request host.');
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/D', $host)) {
        return '';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($secure ? 'https://' : 'http://') . $host . $dir . '/';
}

/**
 * Optional alerts to the site owner. Configure notify_email and/or notify_webhook in config.php.
 * The webhook receives a plain-text POST (works with an ntfy.sh topic URL). Failures never break the caller.
 */
function notify(string $subject, string $body): void {
    if (is_staging()) {
        return; // a staging copy never sends alerts, even with production settings copied into it
    }
    $subject = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $subject));
    $body = preg_replace('/\r\n?/', "\n", $body);
    try {
        $email = (string)(config()['notify_email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            @mail($email, '=?UTF-8?B?' . base64_encode('[Offgrid] ' . $subject) . '?=', $body,
                "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8");
        }
        $hook = (string)(config()['notify_webhook'] ?? '');
        if ($hook !== '' && str_starts_with($hook, 'https://') && function_exists('curl_init')) {
            $curl = curl_init($hook);
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Title: ' . preg_replace('/[^\x20-\x7e]/', '', $subject), 'Content-Type: text/plain; charset=utf-8'],
            ]);
            curl_exec($curl);
            curl_close($curl);
        }
    } catch (Throwable $failure) {
        error_log('Offgrid notify: ' . $failure->getMessage());
    }
}

/**
 * Per-visitor rate limits kept in their own small file, so failed logins and token guesses never rewrite or lock
 * tracker.json. When the table is full the oldest entries are dropped, so one crowd of addresses cannot push
 * everyone else into a shared bucket and lock the administrator out.
 */
const RATE_LIMIT_MAX_ENTRIES = 2000;

function rate_limit_read(string $dir, string $name, int $window): int {
    $file = $dir . '/ratelimit.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $hit = is_array($data) ? ($data[$name] ?? null) : null;
    return is_array($hit) && ($hit['since'] ?? 0) > time() - $window ? (int)($hit['count'] ?? 0) : 0;
}

/** True when this visitor has already used up $limit attempts in the window. Read-only. */
function rate_limited(string $dir, string $bucket, string $visitor, int $limit, int $window): bool {
    return rate_limit_read($dir, $bucket . '|' . $visitor, $window) >= $limit;
}

/** Counts one attempt. Returns false, without counting, when the visitor is already over the limit. */
function rate_limit_hit(string $dir, string $bucket, string $visitor, int $limit, int $window): bool {
    $name = $bucket . '|' . $visitor;
    return rate_limit_update($dir, static function (array &$data) use ($name, $limit, $window): bool {
        $now = time();
        $hit = $data[$name] ?? null;
        if (!is_array($hit) || ($hit['since'] ?? 0) <= $now - $window) {
            $hit = ['since'=>$now,'count'=>0];
        }
        if ($hit['count'] >= $limit) {
            return false;
        }
        $data[$name] = ['since'=>$hit['since'],'count'=>$hit['count'] + 1];
        return true;
    });
}

function rate_limit_clear(string $dir, string $bucket, string $visitor): void {
    $name = $bucket . '|' . $visitor;
    if (rate_limit_read($dir, $name, 86400) === 0) {
        return; // Nothing recorded, so there is nothing to write.
    }
    rate_limit_update($dir, static function (array &$data) use ($name): bool {
        unset($data[$name]);
        return true;
    });
}

function rate_limit_update(string $dir, callable $change): bool {
    $lock = fopen($dir . '/ratelimit.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the rate limits.');
    }
    try {
        $file = $dir . '/ratelimit.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $now = time();
        // No window is longer than a day, so older entries are never useful.
        $data = array_filter(is_array($data) ? $data : [], static fn($hit) => is_array($hit) && ($hit['since'] ?? 0) > $now - 86400);
        $result = $change($data);
        if (count($data) > RATE_LIMIT_MAX_ENTRIES) {
            uasort($data, static fn($a, $b) => $b['since'] <=> $a['since']);
            $data = array_slice($data, 0, RATE_LIMIT_MAX_ENTRIES, true);
        }
        $temp = tempnam($dir, 'rate-');
        if ($temp === false) {
            throw new RuntimeException('Could not create a data file.');
        }
        chmod($temp, 0600);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Could not save the rate limits.');
        }
        return $result;
    } finally {
        fclose($lock);
    }
}

function ingest_enabled(): bool {
    if (is_staging()) {
        return false; // the real U.S. runner must never write into a staging copy
    }
    return strlen((string)(config()['ingest_token'] ?? '')) >= 32;
}
