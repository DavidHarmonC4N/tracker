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
            'submissions'=>[],'submission_attempts'=>[],'events'=>[],'history'=>[],'alert_log'=>[],'last_runner_run'=>null,
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
    return $state + ['submissions'=>[],'submission_attempts'=>[],'host_next_scan'=>[],'events'=>[],'history'=>[],'alert_log'=>[],'last_runner_run'=>null];
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
    $publicTopic = ntfy_public_base();
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
        $public['topic'] = ntfy_radio_topic($publicTopic, (string)$item['id']);
        $public['seller_paused'] = !$public['manual'] && ($state['cooldowns'][$host] ?? 0) > $now;
        $items[] = $public;
    }
    return ['items'=>$items,'configured'=>$configured,'staging'=>is_staging(),'public_topic'=>$publicTopic,'last_run'=>$state['last_run'],
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
 * Owner alerts. Configure notify_email and/or notify_webhook in config.php (README: "Alerts").
 *
 * notify() queues an alert for you; notify_public_event() queues a public ntfy post. Everything queued during one request
 * is sent together when the request ends, as one digest, so a runner pass that finds twenty changes sends one message, not
 * twenty. notify_send() sends right away and reports what happened for each channel, which is what the admin "Send test
 * alert" button uses. Failures never break the caller; they are recorded in the alert log shown in the admin.
 * $meta may hold: url (the listing), type (back_in_stock, price_drop, submission, test), priority (1-5).
 */
function notify(string $subject, string $body, array $meta = []): void {
    if (is_staging()) {
        return; // a staging copy never sends alerts, even with production settings copied into it
    }
    notify_queue('owner', [$subject, $body, $meta]);
}

/** Queues a public ntfy post for a back-in-stock or price-drop event. */
function notify_public_event(array $event, string $url): void {
    if (is_staging() || ntfy_public_base() === '') {
        return;
    }
    notify_queue('public', [$event, $url]);
}

function notify_queue(string $kind, array $item): void {
    static $queues = ['owner'=>[], 'public'=>[]];
    static $registered = false;
    $queues[$kind][] = $item;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function () use (&$queues) {
        $owner = $queues['owner'];
        $public = $queues['public'];
        $queues = ['owner'=>[], 'public'=>[]];
        notify_flush($owner, $public);
    });
}

/** Sends what a request queued: one digest to the owner (unless muted) and the public posts. */
function notify_flush(array $owner, array $public): void {
    $log = [];
    $subject = '';
    try {
        $state = config()['data_dir'] !== '' ? load_state(data_dir()) : [];
        if ($owner) {
            [$subject, $body, $meta] = notify_digest($owner);
            if (alerts_muted($state)) {
                $log[] = ['channel'=>'owner alerts', 'ok'=>true, 'message'=>'Muted until ' . gmdate('H:i', (int)$state['alerts_muted_until']) . ' UTC, so this was not sent.'];
            } else {
                $log = array_merge($log, notify_send($subject, $body, $meta));
            }
        }
        if ($public) {
            $log = array_merge($log, notify_public_flush(array_map(static fn($item) => $item[0], $public), array_map(static fn($item) => $item[1], $public)));
            $subject = $subject !== '' ? $subject : ucfirst(event_text($public[0][0]));
        }
        if ($log && config()['data_dir'] !== '') {
            alert_log_record(data_dir(), $subject, $log);
        }
    } catch (Throwable $failure) {
        error_log('Offgrid notify: ' . $failure->getMessage());
    }
}

/** Posts public events to the public topic (one message) and to each radio's own topic. */
function notify_public_flush(array $events, array $urls): array {
    $base = ntfy_public_base();
    if ($base === '' || !$events) {
        return [];
    }
    $site = base_url() !== '' ? base_url() . 'index.html' : '';
    $note = "\nConfirm stock and the selected variant with the seller.";
    $types = array_column($events, 'type');
    $results = [];
    if (count($events) === 1) {
        $title = ucfirst(event_text($events[0]));
        $body = event_text($events[0]) . $note;
        $click = $urls[0];
    } else {
        $title = count($events) . ' updates: ' . ucfirst(event_text($events[0])) . ' and ' . (count($events) - 1) . ' more';
        $body = implode("\n", array_map(static fn($event) => '• ' . event_text($event), $events));
        $click = $site;
    }
    $options = ['priority'=>in_array('back_in_stock', $types, true) ? 4 : 3, 'tags'=>NTFY_TAGS[$types[0]] ?? NTFY_TAGS['info'], 'click'=>$click];
    $first = ntfy_publish($base, $title, $body, $options);
    $results[] = ['channel'=>'public topic', 'ok'=>$first['ok'], 'message'=>$first['message']];
    $failed = '';
    $posted = 0;
    foreach (array_slice($events, 0, 25) as $i => $event) {
        $one = ntfy_publish(ntfy_radio_topic($base, (string)$event['id']), ucfirst(event_text($event)), event_text($event) . $note,
            ['priority'=>$event['type'] === 'back_in_stock' ? 4 : 3, 'tags'=>NTFY_TAGS[$event['type']] ?? NTFY_TAGS['info'], 'click'=>$urls[$i]]);
        $one['ok'] ? $posted++ : ($failed = $failed ?: $one['message']);
    }
    $results[] = ['channel'=>'radio topics', 'ok'=>$failed === '', 'message'=>$failed === '' ? 'Posted to ' . $posted . ' radio topic' . ($posted === 1 ? '' : 's') . '.' : $failed];
    return $results;
}

/** Joins queued alerts into one subject, body and meta. One alert is sent as it is. */
function notify_digest(array $queue): array {
    if (count($queue) === 1) {
        return [(string)$queue[0][0], (string)$queue[0][1], (array)($queue[0][2] ?? [])];
    }
    $parts = array_map(static fn($item) => trim((string)$item[1]), $queue);
    $types = array_filter(array_map(static fn($item) => $item[2]['type'] ?? '', $queue));
    $meta = ['type'=>in_array('back_in_stock', $types, true) ? 'back_in_stock' : (string)reset($types),
        'url'=>base_url() !== '' ? base_url() . 'index.html' : ''];
    return [count($queue) . ' updates: ' . $queue[0][0] . ' and ' . (count($queue) - 1) . ' more', implode("\n\n", $parts), $meta];
}

/** The address alerts are sent from: notify_from in config.php, or alerts@ the site's own domain. */
function notify_from_address(): string {
    $set = trim((string)(config()['notify_from'] ?? ''));
    if ($set !== '' && filter_var($set, FILTER_VALIDATE_EMAIL)) {
        return $set;
    }
    $host = strtolower((string)(parse_url(base_url(), PHP_URL_HOST) ?: ($_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/^www\./', '', $host);
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ''; // an IP address has no domain to send from
    }
    $labels = array_values(array_filter(explode('.', $host), 'strlen'));
    // Use the registrable domain (two labels) so the address matches what the host's mail system signs for.
    $domain = count($labels) >= 2 ? implode('.', array_slice($labels, -2)) : '';
    return $domain !== '' && preg_match('/^[a-z0-9.-]+$/D', $domain) ? 'alerts@' . $domain : '';
}

/** Sends an alert now. Returns one entry per channel: ['channel'=>'email'|'webhook', 'ok'=>bool, 'message'=>string]. */
function notify_send(string $subject, string $body, array $meta = []): array {
    $results = [];
    if (is_staging()) {
        return $results;
    }
    $subject = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $subject));
    $body = preg_replace('/\r\n?/', "\n", $body);
    $email = trim((string)(config()['notify_email'] ?? ''));
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $results[] = ['channel'=>'email', 'ok'=>false, 'message'=>'notify_email in config.php is not a valid address.'];
        } else {
            $from = notify_from_address();
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: Offgrid Comms Tracker"
                . ($from !== '' ? "\r\nFrom: Offgrid Comms Tracker <$from>\r\nReply-To: $from" : '');
            $encoded = '=?UTF-8?B?' . base64_encode('[Offgrid] ' . $subject) . '?=';
            $sent = false;
            $problem = '';
            try {
                // Hosts that use sendmail want the envelope sender set too; some refuse the extra argument, so try without.
                $sent = $from !== '' && @mail($email, $encoded, $body, $headers, '-f' . $from);
                if (!$sent) {
                    $sent = @mail($email, $encoded, $body, $headers);
                }
                if (!$sent) {
                    $problem = (string)(error_get_last()['message'] ?? '');
                }
            } catch (Throwable $failure) {
                $problem = $failure->getMessage();
            }
            $results[] = $sent
                ? ['channel'=>'email', 'ok'=>true, 'message'=>'Handed to the host\'s mail system' . ($from !== '' ? ' from ' . $from : '') . '. That is not proof of delivery: check the inbox and the spam folder.']
                : ['channel'=>'email', 'ok'=>false, 'message'=>'The host\'s mail system refused it' . ($problem !== '' ? ': ' . $problem : '') . '. Use a webhook such as an ntfy.sh topic instead, or ask the host to enable mail().'];
        }
    }
    $hook = trim((string)(config()['notify_webhook'] ?? ''));
    if ($hook !== '') {
        if (ntfy_topic_ok($hook)) {
            // An ntfy topic: add a tag, a priority, tap-to-open and the tap buttons.
            $type = (string)($meta['type'] ?? 'info');
            $sent = ntfy_publish($hook, $subject, $body, ['priority'=>(int)($meta['priority'] ?? ($type === 'back_in_stock' ? 4 : 3)),
                'tags'=>NTFY_TAGS[$type] ?? NTFY_TAGS['info'], 'click'=>(string)($meta['url'] ?? ''), 'actions'=>ntfy_alert_buttons((string)($meta['url'] ?? ''))]);
            $results[] = ['channel'=>'webhook', 'ok'=>$sent['ok'], 'message'=>$sent['message']];
        } elseif (!str_starts_with($hook, 'https://') || !function_exists('curl_init')) {
            $results[] = ['channel'=>'webhook', 'ok'=>false, 'message'=>'notify_webhook must be an https:// address, and PHP needs cURL.'];
        } else {
            $curl = curl_init($hook);
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Title: ' . preg_replace('/[^\x20-\x7e]/', '', $subject), 'Content-Type: text/plain; charset=utf-8'],
            ]);
            curl_exec($curl);
            $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            $results[] = $code >= 200 && $code < 300
                ? ['channel'=>'webhook', 'ok'=>true, 'message'=>'Accepted (HTTP ' . $code . ').']
                : ['channel'=>'webhook', 'ok'=>false, 'message'=>$error !== '' ? 'Could not reach it: ' . $error : 'The webhook answered HTTP ' . $code . '.'];
        }
    }
    return $results;
}

const ALERT_LOG_LIMIT = 20;

/** Keeps the last few alert results so the admin can show whether alerts are working. */
function alert_log_record(string $dir, string $subject, array $results): void {
    if (!$results) {
        return;
    }
    $stamp = iso_now();
    change_state($dir, static function (&$state) use ($subject, $results, $stamp) {
        foreach ($results as $result) {
            $state['alert_log'][] = ['t'=>$stamp, 'subject'=>mb_substr($subject, 0, 120), 'channel'=>$result['channel'],
                'ok'=>$result['ok'], 'message'=>mb_substr($result['message'], 0, 300)];
        }
        $state['alert_log'] = array_slice($state['alert_log'], -ALERT_LOG_LIMIT);
    });
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

require_once __DIR__ . '/ntfy.php';
