<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

/**
 * Checker health. Everything on the site depends on the scheduled checks, so a quiet failure (a switched-off schedule, an
 * expired token, a seller that starts refusing) is reported to the owner's alert channels once, with a message when it recovers.
 *
 * It is evaluated on catalog requests, at most every five minutes, so it needs no cron of its own. The decision is a pure
 * function of the saved state so it can be tested without a clock or a network.
 */
const HEALTH_STALL_AFTER = 3 * 3600;   // no runner check-in for this long counts as stalled
const HEALTH_SELLER_AFTER = 3 * 3600;  // every listing of a seller failing for this long counts as a seller problem
const HEALTH_EVERY = 300;              // seconds between evaluations

/**
 * What the state looks like right now: ['stalled'=>bool, 'last'=>unix time|null, 'sellers'=>[host => ['count'=>n, 'error'=>text]]].
 * "Last" is the runner's last check-in when any listing is checked by the runner, otherwise the server cron's last run.
 */
function health_evaluate(array $state, int $now): array {
    $auto = [];
    $remote = false;
    foreach ($state['items'] as $item) {
        if ($item['paused'] || ($item['mode'] ?? '') === 'manual') {
            continue;
        }
        $remote = $remote || is_remote($item);
        try {
            $auto[seller_bucket(product_url($item['url'])['host'])][] = $item;
        } catch (InvalidArgumentException) {
            continue;
        }
    }
    $ref = $remote ? ($state['last_runner_run'] ?? null) : ($state['last_run'] ?? null);
    $last = $ref ? (int)strtotime((string)$ref) : null;
    $sellers = [];
    foreach ($auto as $host => $list) {
        $failing = array_filter($list, static fn($i) => !empty($i['error']));
        if (count($list) >= 2 && count($failing) === count($list)) {
            $sellers[$host] = ['count'=>count($list), 'error'=>clean_text(reset($failing)['error'], 120)];
        }
    }
    return ['stalled'=>$auto !== [] && $last !== null && $now - $last >= HEALTH_STALL_AFTER, 'last'=>$last, 'sellers'=>$sellers];
}

/**
 * Compares what was already reported with what is true now. Returns [new health record, messages], where each message is
 * [subject, body]. Nothing is returned for a condition that was already reported.
 */
function health_next(array $health, array $eval, int $now): array {
    $notes = [];
    if ($eval['stalled'] && empty($health['stalled'])) {
        $hours = (int)floor(($now - (int)$eval['last']) / 3600);
        $health['stalled'] = $now;
        $notes[] = ['Checker stalled', 'No checker has reported for ' . $hours . ' hours (last: ' . gmdate('M j, g:i A', (int)$eval['last']) . " UTC), so prices and stock are going out of date.\n"
            . "Check that the scheduled workflow is switched on in GitHub (Actions), that the OFFGRID_TOKEN secret matches ingest_token in config.php, and the latest run's log."];
    } elseif (!$eval['stalled'] && !empty($health['stalled'])) {
        unset($health['stalled']);
        $notes[] = ['Checker is back', 'The checker is reporting again.'];
    }
    foreach ($eval['sellers'] as $host => $info) {
        $seen = $health['sellers'][$host] ?? null;
        if ($seen === null) {
            $health['sellers'][$host] = ['since'=>$now, 'alerted'=>false];
        } elseif (!$seen['alerted'] && $now - (int)$seen['since'] >= HEALTH_SELLER_AFTER) {
            $health['sellers'][$host]['alerted'] = true;
            $notes[] = ['Seller problem: ' . $host, 'All ' . $info['count'] . ' listings from ' . $host . ' have needed review for ' . (int)floor(($now - (int)$seen['since']) / 3600)
                . ' hours. First error: ' . $info['error'] . "\nThe seller may have changed its pages or started refusing automated checks."];
        }
    }
    foreach (array_keys($health['sellers'] ?? []) as $host) {
        if (!isset($eval['sellers'][$host])) {
            if (!empty($health['sellers'][$host]['alerted'])) {
                $notes[] = ['Seller recovered: ' . $host, 'Listings from ' . $host . ' are being read again.'];
            }
            unset($health['sellers'][$host]);
        }
    }
    if (empty($health['sellers'])) {
        unset($health['sellers']);
    }
    return [$health, $notes];
}

/** Called after a catalog request. Returns the messages it queued (for tests). */
function maybe_check_health(string $dir, ?int $now = null): array {
    $now ??= time();
    $stamp = $dir . '/health.check';
    if (is_file($stamp) && (int)@filemtime($stamp) > $now - HEALTH_EVERY) {
        return [];
    }
    @touch($stamp);
    $state = load_state($dir);
    [$next, $notes] = health_next($state['health'] ?? [], health_evaluate($state, $now), $now);
    if ($next === ($state['health'] ?? []) && !$notes) {
        return [];
    }
    // Decided again on the fresh copy, so two requests at once report once.
    $sent = change_state($dir, static function (&$fresh) use ($now) {
        [$record, $messages] = health_next($fresh['health'] ?? [], health_evaluate($fresh, $now), $now);
        $fresh['health'] = $record;
        return $messages;
    });
    $link = base_url() !== '' ? "\n" . base_url() . 'admin.php' : '';
    foreach ($sent as [$subject, $body]) {
        notify($subject, $body . $link, ['type'=>'health']);
    }
    return $sent;
}
