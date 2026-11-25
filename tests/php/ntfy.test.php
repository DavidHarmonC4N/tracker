<?php
declare(strict_types=1);
// ntfy phone alerts: topic and header helpers, what is posted to the private and public topics, the muted state,
// and the tap-button endpoint (ntfy.php). A throwaway local server stands in for ntfy and records every request.
require dirname(__DIR__) . '/lib/harness.php';
load_site();

$siteDir = (string)getenv('SITE_DIR');
$work = sys_get_temp_dir() . '/offgrid-ntfy-' . getmypid();
mkdir($work);

// ---- pure helpers (the unit site has local_test on, so http to this machine is allowed here)
check('an https topic is accepted', ntfy_topic_ok('https://ntfy.sh/offgrid-9f3k2lw8x0qa'));
foreach (['http://ntfy.sh/topic', 'https://ntfy.sh/', 'https://ntfy.sh/a/b', 'https://ntfy.sh/topic?x=1', 'https://ntfy.sh/topic#f', 'https://user:pw@ntfy.sh/topic',
    'https://ntfy.sh/has space', 'https://ntfy.sh/' . str_repeat('a', 65), 'ftp://ntfy.sh/topic', 'not a url'] as $bad) {
    check("a bad topic is refused: $bad", !ntfy_topic_ok($bad));
}
check('a 64 character topic is accepted', ntfy_topic_ok('https://ntfy.sh/' . str_repeat('a', 64)));
check('plain http is allowed only to this machine in local test mode', ntfy_topic_ok('http://127.0.0.1:9/topic') && !ntfy_topic_ok('http://example.com/topic'));

check('plain ASCII header values pass through', ntfy_header("Hello world") === 'Hello world');
check('other characters are sent in the encoded form', ntfy_header('Trekker ü') === '=?UTF-8?B?' . base64_encode('Trekker ü') . '?=');
check('line breaks cannot be smuggled into a header', !str_contains(ntfy_header("a\r\nX-Evil: 1"), "\n"));
$actions = ntfy_actions([['view', 'Open listing', 'https://shop.example/a', ['clear'=>'true']], ['http', 'Status, now; please', 'https://x.example/ntfy.php?do=status', ['method'=>'POST', 'headers.X-Offgrid-Token'=>'abc', 'clear'=>'true']]]);
check('actions are written the way ntfy expects', $actions === 'view, Open listing, https://shop.example/a, clear=true; http, Status  now  please, https://x.example/ntfy.php?do=status, method=POST, headers.X-Offgrid-Token=abc, clear=true', $actions);
check('at most three actions are sent', substr_count(ntfy_actions(array_fill(0, 5, ['view', 'x', 'https://a.example', []])), 'view,') === 3);

$base = 'https://ntfy.sh/offgrid-comms-public';
$a = ntfy_radio_topic($base, 'rok-tdeck');
check('each radio gets its own stable topic under the public name', $a === ntfy_radio_topic($base, 'rok-tdeck') && str_starts_with($a, $base . '-') && ntfy_radio_topic($base, 'spec-mini') !== $a);
check('a radio topic is always a valid topic', ntfy_topic_ok(ntfy_radio_topic('https://ntfy.sh/' . str_repeat('a', 55), 'rok-tdeck')));
check('with no public topic there is no radio topic', ntfy_radio_topic('', 'rok-tdeck') === '');

$state = ['items'=>[['paused'=>false, 'result'=>['status'=>'in_stock']], ['paused'=>false, 'result'=>['status'=>'in_stock']], ['paused'=>false, 'result'=>['status'=>'out_of_stock']], ['paused'=>true, 'result'=>['status'=>'in_stock']]],
    'last_runner_run'=>gmdate('c', time() - 600), 'events'=>[['t'=>gmdate('c', time() - 86400), 'id'=>'a', 'name'=>'T-Deck', 'seller'=>'Rokland', 'type'=>'price_drop', 'from'=>7495, 'to'=>6797],
        ['t'=>gmdate('c', time() - 40 * 86400), 'id'=>'b', 'name'=>'Old', 'seller'=>'S', 'type'=>'price_drop', 'from'=>2000, 'to'=>1000]]];
$status = ntfy_status_text($state);
check('Status counts listings and leaves paused ones out', str_contains($status, 'In stock 2') && str_contains($status, 'out of stock 1'), $status);
check('Status says when the runner last checked in', str_contains($status, '10 min ago'));
check('Status lists the latest change and says alerts are on', str_contains($status, 'T-Deck at Rokland dropped') && str_contains($status, 'Alerts are on'));
$drops = ntfy_drops_text($state);
check('Drops lists only the last seven days', str_contains($drops, '1 price drop in the last 7 days') && !str_contains($drops, 'Old'), $drops);
check('Drops says so when there are none', str_contains(ntfy_drops_text(['events'=>[]]), 'No price drops'));
check('muting is read from the state', alerts_muted(['alerts_muted_until'=>time() + 60]) && !alerts_muted(['alerts_muted_until'=>time() - 60]) && !alerts_muted([]));

// ---- helpers for the scenarios below
function free_port(): int {
    for ($i = 0; $i < 50; $i++) {
        $port = random_int(20000, 40000);
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (!$probe) { return $port; }
        fclose($probe);
    }
    throw new RuntimeException('no free port');
}
function launch(array $command, array $env = []): mixed {
    $process = proc_open($command, [1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $pipes, null, $env + getenv());
    return $process;
}
function wait_for(int $port): void {
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe) { fclose($probe); return; }
        usleep(100000);
    }
}
function captured(string $log): array {
    return is_file($log) ? array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($log)))) : [];
}
/** Copies the site with config overrides; returns [site dir, data dir]. */
function site_copy(string $siteDir, string $work, string $name, array $settings): array {
    $copy = $work . '/' . $name;
    $data = $work . '/' . $name . '-data';
    mkdir($copy);
    mkdir($data);
    exec('cp -r ' . escapeshellarg($siteDir) . '/. ' . escapeshellarg($copy) . ' 2>&1');
    $config = include $copy . '/config.php';
    file_put_contents($copy . '/config.php', "<?php\nif (!defined('OFFGRID_APP')) { http_response_code(404); exit; }\nreturn " . var_export($settings + ['data_dir'=>$data] + $config, true) . ";\n");
    return [$copy, $data];
}
$router = dirname(__DIR__) . '/lib/capture-router.php';
$log = $work . '/captured.jsonl';
$capturePort = free_port();
$capture = launch(['php', '-S', "127.0.0.1:$capturePort", $router], ['CAPTURE_LOG'=>$log]);
wait_for($capturePort);
$private = "http://127.0.0.1:$capturePort/private-topic-1234";
$public = "http://127.0.0.1:$capturePort/public-topic";
$token = str_repeat('k', 40);
$child = dirname(__DIR__) . '/lib/alerts-child.php';
$none = ['notify_email'=>'', 'notify_from'=>'', 'notify_webhook'=>'', 'notify_public_topic'=>'', 'ntfy_token'=>'', 'base_url'=>'https://mesh.example.org/'];
$run = static function (string $name, string $mode, array $settings) use ($siteDir, $work, $child, $log): array {
    @unlink($log);
    [$copy, $data] = site_copy($siteDir, $work, $name, $settings);
    $lines = [];
    exec('SITE_DIR=' . escapeshellarg($copy) . ' php ' . escapeshellarg($child) . ' ' . escapeshellarg($mode) . ' 2>&1', $lines);
    $state = is_file($data . '/tracker.json') ? json_decode((string)file_get_contents($data . '/tracker.json'), true) : [];
    return [json_decode((string)end($lines), true), captured($log), $state];
};

// ---- an alert to the private topic
[$r, $posts] = $run('private-test', 'ntfy-test', ['notify_webhook'=>$private, 'ntfy_token'=>$token] + $none);
$h = $posts[0]['headers'] ?? [];
check('an alert is posted to the private topic', count($posts) === 1 && $posts[0]['uri'] === '/private-topic-1234' && $r['results'][0]['ok'] === true && $posts[0]['body'] === 'This is a test');
check('it has a title, a tag and tap-to-open', ($h['title'] ?? '') === 'Test alert' && ($h['tags'] ?? '') === 'test_tube' && ($h['click'] ?? '') === 'https://mesh.example.org/index.html', json_encode($h));
check('it has Open listing, Status and Mute 24h buttons', str_contains($h['actions'] ?? '', 'view, Open listing, https://mesh.example.org/index.html') && str_contains($h['actions'], 'http, Status, https://mesh.example.org/ntfy.php?do=status') && str_contains($h['actions'], 'Mute 24h'), $h['actions'] ?? '');
check('the buttons carry the secret in a header, not in the address', str_contains($h['actions'] ?? '', 'headers.X-Offgrid-Token=' . $token) && !str_contains($h['actions'] ?? '', 'do=status&'));
[, $posts] = $run('private-nobuttons', 'ntfy-test', ['notify_webhook'=>$private] + $none);
check('without ntfy_token there are no tap buttons, only Open listing', !str_contains($posts[0]['headers']['actions'] ?? '', 'http,') && str_contains($posts[0]['headers']['actions'] ?? '', 'view,'));

// ---- several changes in one request, with a public topic
[, $posts, $state] = $run('flush', 'ntfy-flush', ['notify_webhook'=>$private, 'notify_public_topic'=>$public, 'ntfy_token'=>$token] + $none);
$byUri = [];
foreach ($posts as $post) { $byUri[$post['uri']][] = $post; }
check('one digest goes to the private topic', count($byUri['/private-topic-1234'] ?? []) === 1 && str_starts_with($byUri['/private-topic-1234'][0]['headers']['title'], '3 updates'));
check('one digest goes to the public topic', count($byUri['/public-topic'] ?? []) === 1 && str_starts_with($byUri['/public-topic'][0]['headers']['title'], '3 updates'));
check('each radio gets its own post on its own topic', count($posts) === 5 && count(array_filter(array_keys($byUri), static fn($u) => preg_match('~^/public-topic-[0-9a-f]{8}$~', $u))) === 3, implode(' ', array_keys($byUri)));
$tdeck = $byUri['/public-topic-' . substr(sha1('rok-tdeck'), 0, 8)][0] ?? null;
check('a radio post names the radio, the seller and the change, and opens the listing', $tdeck && str_contains($tdeck['body'], 'LILYGO T-Deck at Rokland dropped from $74.95 to $67.97') && $tdeck['headers']['click'] === 'https://shop.example/rok-tdeck' && $tdeck['headers']['tags'] === 'chart_with_downwards_trend,moneybag');
$leak = false;
foreach ($posts as $post) { if (str_starts_with($post['uri'], '/public-topic') && (isset($post['headers']['actions']) || str_contains(json_encode($post), $token))) { $leak = true; } }
check('public posts carry no buttons and no secret', !$leak);
check('a back-in-stock digest is high priority', ($byUri['/public-topic'][0]['headers']['priority'] ?? '') === '4');
$entries = array_column($state['alert_log'] ?? [], 'channel');
check('the results are in the alert log', in_array('webhook', $entries, true) && in_array('public topic', $entries, true) && in_array('radio topics', $entries, true), implode(',', $entries));

// ---- one change: a single message, not a digest
[, $posts] = $run('one', 'ntfy-one', ['notify_webhook'=>$private, 'notify_public_topic'=>$public] + $none);
$titles = array_column(array_column($posts, 'headers'), 'title');
check('one change is posted as itself', count($posts) === 3 && !in_array(true, array_map(static fn($t) => str_contains($t, 'updates'), $titles), true) && str_contains($titles[0], 'LILYGO T-Deck at Rokland dropped'), implode(' | ', $titles));

// ---- muted: the private topic is quiet, the public topic is not
[, $posts, $state] = $run('muted', 'ntfy-flush-muted', ['notify_webhook'=>$private, 'notify_public_topic'=>$public, 'ntfy_token'=>$token] + $none);
$uris = array_column($posts, 'uri');
check('muting silences the private topic only', !in_array('/private-topic-1234', $uris, true) && in_array('/public-topic', $uris, true) && count($posts) === 4, implode(' ', $uris));
check('a muted alert is logged as muted', in_array('owner alerts', array_column($state['alert_log'] ?? [], 'channel'), true));

// ---- public topic off by default
[, $posts] = $run('nopublic', 'ntfy-flush', ['notify_webhook'=>$private] + $none);
check('with no public topic nothing is posted publicly', count($posts) === 1 && $posts[0]['uri'] === '/private-topic-1234');
[$r] = $run('catalog-off', 'ntfy-catalog', $none);
check('the catalog advertises no topic when the public topic is off', $r['public_topic'] === '' && count(array_filter($r['topics'])) === 0);
[$r] = $run('catalog-on', 'ntfy-catalog', ['notify_public_topic'=>$public] + $none);
check('the catalog gives each radio its own topic when it is on (warehouses of one radio share it)', $r['public_topic'] === $public && $r['topics']['rok-tdeck'] === ntfy_radio_topic($public, 'rok-tdeck') && count(array_unique($r['topics'])) === 42 && count($r['topics']) > 42);
[$r, $posts] = $run('catalog-staging', 'ntfy-flush', ['staging'=>true, 'notify_webhook'=>$private, 'notify_public_topic'=>$public] + $none);
check('a staging copy posts to neither topic', $posts === []);

// ---- the tap-button endpoint, on a real server
@unlink($log);
[$copy, $data] = site_copy($siteDir, $work, 'endpoint', ['notify_webhook'=>$private, 'ntfy_token'=>$token, 'base_url'=>'https://mesh.example.org/'] + $none);
$sitePort = free_port();
$site = launch(['php', '-S', "127.0.0.1:$sitePort", '-t', $copy]);
wait_for($sitePort);
$call = static function (string $query, string $method = 'POST', ?string $sentToken = null) use ($sitePort, $token): array {
    $context = stream_context_create(['http'=>['method'=>$method, 'ignore_errors'=>true, 'header'=>$sentToken === null ? '' : 'X-Offgrid-Token: ' . $sentToken . "\r\n", 'content' => '']]);
    $body = (string)@file_get_contents("http://127.0.0.1:$sitePort/ntfy.php$query", false, $context);
    preg_match('~HTTP/\S+ (\d{3})~', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body];
};
check('a GET is refused', $call('?do=status', 'GET', $token)[0] === 405);
check('no token is refused', $call('?do=status')[0] === 403);
check('a wrong token is refused', $call('?do=status', 'POST', 'wrong')[0] === 403);
[$code, $text] = $call('?do=status', 'POST', $token);
check('Status answers with the summary', $code === 200 && str_contains($text, 'In stock') && str_contains($text, 'Alerts are on'), $text);
$posts = captured($log);
$reply = $posts[count($posts) - 1] ?? [];
check('the answer is also posted into the private topic with follow-up buttons', ($reply['uri'] ?? '') === '/private-topic-1234' && ($reply['headers']['title'] ?? '') === 'Status'
    && str_contains($reply['headers']['actions'] ?? '', 'Check now') && str_contains($reply['headers']['actions'], 'Drops this week') && str_contains($reply['headers']['actions'], 'Mute 24h'), $reply['headers']['actions'] ?? '');
[$code, $text] = $call('?do=mute', 'POST', $token);
$state = json_decode((string)file_get_contents($data . '/tracker.json'), true);
check('Mute 24h mutes for a day', $code === 200 && ($state['alerts_muted_until'] ?? 0) > time() + 86000 && ($state['alerts_muted_until'] ?? 0) <= time() + 86400, $text);
$posts = captured($log);
check('the mute reply offers Unmute', str_contains(($posts[count($posts) - 1]['headers']['actions'] ?? ''), 'Unmute'));
check('Status now says alerts are muted', str_contains($call('?do=status', 'POST', $token)[1], 'muted'));
[$code] = $call('?do=unmute', 'POST', $token);
check('Unmute turns alerts back on', $code === 200 && (json_decode((string)file_get_contents($data . '/tracker.json'), true)['alerts_muted_until'] ?? 1) === 0);
check('Drops answers', $call('?do=drops', 'POST', $token)[0] === 200);
[$code, $text] = $call('?do=check', 'POST', $token);
check('Check now says plainly when the runner trigger is not set up', $code === 200 && str_contains($text, 'not set up'), $text);
check('an unknown action is refused', $call('?do=format-disk', 'POST', $token)[0] === 400);
for ($i = 0; $i < 12; $i++) { $last = $call('?do=status', 'POST', 'guess' . $i)[0]; }
check('after ten wrong tries the endpoint stops answering', $last === 429, (string)$last);
check('the right token still works after someone else guessed wrong', $call('?do=status', 'POST', $token)[0] === 200);

// without a token configured, or on staging, the endpoint does not exist
[$copy2] = site_copy($siteDir, $work, 'endpoint-off', $none);
$offPort = free_port();
$off = launch(['php', '-S', "127.0.0.1:$offPort", '-t', $copy2]);
wait_for($offPort);
$context = stream_context_create(['http'=>['method'=>'POST', 'ignore_errors'=>true, 'header'=>"X-Offgrid-Token: $token\r\n", 'content'=>'']]);
@file_get_contents("http://127.0.0.1:$offPort/ntfy.php?do=status", false, $context);
check('with no ntfy_token configured the endpoint answers 404', str_contains($http_response_header[0] ?? '', '404'));

foreach ([$capture, $site, $off] as $process) {
    proc_terminate($process);
    proc_close($process);
}
exec('rm -r ' . escapeshellarg($work));
finish();
