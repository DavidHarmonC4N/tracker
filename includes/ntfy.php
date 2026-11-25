<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

/**
 * ntfy support. ntfy (https://ntfy.sh) sends a phone notification for every message posted to a topic.
 *
 *   notify_webhook       your private topic: every alert, with tap buttons (see ntfy.php). Keep its name long and secret.
 *   notify_public_topic  an optional public topic: only back-in-stock and price-drop news. Each radio also gets its own
 *                        topic (this name plus a short code), so a visitor can follow just the radios they care about.
 *   ntfy_token           a secret that the tap buttons send back to ntfy.php. Without it no buttons are added.
 *
 * A topic is open to anyone who knows its name, so a public topic can receive fake posts from other people. The code
 * only ever posts public facts to it, and it stays off until notify_public_topic is set.
 */

const NTFY_TAGS = [
    'back_in_stock' => ['white_check_mark', 'package'],
    'price_drop'    => ['chart_with_downwards_trend', 'moneybag'],
    'submission'    => ['inbox_tray'],
    'test'          => ['test_tube'],
    'info'          => ['information_source'],
];

/** True for https://host/topic, with a topic of letters, digits, _ and - (http to this machine only when local_test is on). */
function ntfy_topic_ok(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $scheme = $parts['scheme'] ?? '';
    $host = strtolower((string)($parts['host'] ?? ''));
    $loopback = in_array($host, ['127.0.0.1', 'localhost'], true);
    if (!($scheme === 'https' || (config()['local_test'] === true && $scheme === 'http' && $loopback)) || $host === '') {
        return false;
    }
    return preg_match('~^/[A-Za-z0-9_-]{1,64}$~D', (string)($parts['path'] ?? '')) === 1;
}

/** Header values may hold only plain ASCII; anything else is sent in the RFC 2047 form ntfy understands. */
function ntfy_header(string $value): string {
    $value = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));
    return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/** Builds the Actions header. Each action is [type, label, url, [options]]. Commas and semicolons are removed from labels. */
function ntfy_actions(array $actions): string {
    $out = [];
    foreach (array_slice($actions, 0, 3) as [$type, $label, $url, $options]) {
        $parts = [$type, str_replace([',', ';'], ' ', $label), $url];
        foreach ($options as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $out[] = implode(', ', $parts);
    }
    return implode('; ', $out);
}

/**
 * Posts one message. $options: priority (1-5), tags (list), click (a URL), actions (see ntfy_actions()).
 * Returns ['ok'=>bool, 'message'=>string, 'http'=>int].
 */
function ntfy_publish(string $topicUrl, string $title, string $body, array $options = []): array {
    if (!function_exists('curl_init')) {
        return ['ok'=>false, 'message'=>'PHP needs cURL to reach ntfy.', 'http'=>0];
    }
    $headers = ['Title: ' . ntfy_header($title), 'Content-Type: text/plain; charset=utf-8'];
    if (isset($options['priority']) && $options['priority'] >= 1 && $options['priority'] <= 5) {
        $headers[] = 'Priority: ' . (int)$options['priority'];
    }
    $tags = array_values(array_filter(array_map('strval', $options['tags'] ?? []), static fn($t) => preg_match('/^[a-z0-9_]{1,40}$/D', $t) === 1));
    if ($tags) {
        $headers[] = 'Tags: ' . implode(',', $tags);
    }
    if (!empty($options['click']) && preg_match('~^https?://[^\s]+$~D', (string)$options['click'])) {
        $headers[] = 'Click: ' . $options['click'];
    }
    if (!empty($options['actions'])) {
        $headers[] = 'Actions: ' . ntfy_actions($options['actions']);
    }
    $curl = curl_init($topicUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => config()['local_test'] === true ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($code >= 200 && $code < 300) {
        return ['ok'=>true, 'message'=>'Accepted (HTTP ' . $code . ').', 'http'=>$code];
    }
    return ['ok'=>false, 'message'=>$error !== '' ? 'Could not reach it: ' . $error : 'It answered HTTP ' . $code . '.', 'http'=>$code];
}

function ntfy_public_base(): string {
    $url = trim((string)(config()['notify_public_topic'] ?? ''));
    return $url !== '' && ntfy_topic_ok($url) && strlen((string)parse_url($url, PHP_URL_PATH)) <= 56 ? $url : '';
}

/** The topic for one radio: the public topic's name plus eight characters derived from the listing id. */
function ntfy_radio_topic(string $base, string $id): string {
    return $base === '' ? '' : $base . '-' . substr(sha1($id), 0, 8);
}

/** The private topic, when notify_webhook is a valid ntfy-style topic address. */
function ntfy_owner_topic(): string {
    $url = trim((string)(config()['notify_webhook'] ?? ''));
    return $url !== '' && ntfy_topic_ok($url) ? $url : '';
}

function ntfy_token(): string {
    $token = (string)(config()['ntfy_token'] ?? '');
    return strlen($token) >= 32 ? $token : '';
}

/** Where the tap buttons call back to. Empty when the site's own address is not known or the token is not set. */
function ntfy_endpoint(): string {
    return ntfy_token() !== '' && base_url() !== '' ? base_url() . 'ntfy.php' : '';
}

function ntfy_button(string $label, string $action): array {
    return ['http', $label, ntfy_endpoint() . '?do=' . $action, ['method'=>'POST', 'headers.X-Offgrid-Token'=>ntfy_token(), 'clear'=>'true']];
}

/** The buttons for an alert to the owner: open the listing, Status, Mute 24h. */
function ntfy_alert_buttons(string $url): array {
    $buttons = [];
    if ($url !== '') {
        $buttons[] = ['view', 'Open listing', $url, ['clear'=>'true']];
    }
    if (ntfy_endpoint() !== '') {
        $buttons[] = ntfy_button('Status', 'status');
        $buttons[] = ntfy_button('Mute 24h', 'mute');
    }
    return array_slice($buttons, 0, 3);
}

/** The buttons on a reply: Check now, Drops this week, and Mute or Unmute. */
function ntfy_reply_buttons(bool $muted): array {
    return ntfy_endpoint() === '' ? [] : [ntfy_button('Check now', 'check'), ntfy_button('Drops this week', 'drops'), ntfy_button($muted ? 'Unmute' : 'Mute 24h', $muted ? 'unmute' : 'mute')];
}

function alerts_muted(array $state): bool {
    return (int)($state['alerts_muted_until'] ?? 0) > time();
}

function ntfy_minutes_ago(?string $iso): string {
    $at = $iso ? strtotime($iso) : false;
    if (!$at) {
        return 'never';
    }
    $minutes = max(0, (int)floor((time() - $at) / 60));
    return $minutes < 90 ? $minutes . ' min ago' : (int)round($minutes / 60) . ' h ago';
}

/** A short summary for the Status button. */
function ntfy_status_text(array $state): string {
    $count = ['in_stock'=>0, 'orderable'=>0, 'out_of_stock'=>0, 'other'=>0];
    foreach ($state['items'] as $item) {
        if (!empty($item['paused'])) {
            continue;
        }
        $status = $item['result']['status'] ?? 'unknown';
        $count[isset($count[$status]) ? $status : 'other']++;
    }
    $lines = [
        'In stock ' . $count['in_stock'] . ' · orderable ' . $count['orderable'] . ' · out of stock ' . $count['out_of_stock'] . ' · other ' . $count['other'],
        'U.S. runner last checked in ' . ntfy_minutes_ago($state['last_runner_run'] ?? null),
    ];
    $recent = array_slice(array_reverse($state['events'] ?? []), 0, 3);
    if ($recent) {
        $lines[] = 'Latest changes:';
        foreach ($recent as $event) {
            $lines[] = '• ' . event_text($event);
        }
    }
    $lines[] = alerts_muted($state) ? 'Alerts are muted until ' . gmdate('H:i', (int)$state['alerts_muted_until']) . ' UTC.' : 'Alerts are on.';
    return implode("\n", $lines);
}

/** Price drops in the last seven days, for the Drops button. */
function ntfy_drops_text(array $state): string {
    $since = time() - 7 * 86400;
    $drops = array_filter($state['events'] ?? [], static fn($e) => ($e['type'] ?? '') === 'price_drop' && (strtotime((string)$e['t']) ?: 0) >= $since);
    if (!$drops) {
        return 'No price drops in the last 7 days.';
    }
    $lines = [];
    foreach (array_slice(array_reverse($drops), 0, 8) as $event) {
        $lines[] = '• ' . event_text($event);
    }
    return count($drops) . " price drop" . (count($drops) === 1 ? '' : 's') . " in the last 7 days:\n" . implode("\n", $lines);
}
