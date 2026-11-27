<?php
declare(strict_types=1);
// Checking the links in the group directory: which links are checked, how an answer is read, fetching, and what the site keeps.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

// ---- which links are checked, and how
check('a Facebook link is not checked, because Facebook answers the same for every address', linkcheck_plan('https://www.facebook.com/groups/x')['status'] === 'unchecked' && linkcheck_plan('https://fb.me/x')['kind'] === 'skip' && linkcheck_plan('https://m.facebook.com/x')['kind'] === 'skip');
check('a Discord channel link, which needs membership, is not checked', linkcheck_plan('https://discord.com/channels/1/2')['kind'] === 'skip');
check('a Discord invite is asked about at Discord', linkcheck_plan('https://discord.gg/AbC123') === ['kind'=>'discord', 'code'=>'AbC123'] && linkcheck_plan('https://discord.com/invite/abc-d')['code'] === 'abc-d' && linkcheck_plan('https://www.discordapp.com/invite/zzz')['kind'] === 'discord');
check('odd Discord addresses are fetched like any page, not guessed at', linkcheck_discord_code('https://discord.gg/') === null && linkcheck_discord_code('https://discord.gg/a/b') === null && linkcheck_discord_code('https://discord.gg/' . str_repeat('a', 40)) === null && linkcheck_discord_code('https://discord.example.org/abc') === null);
check('an ordinary site is fetched', linkcheck_plan('https://club.example.org/mesh')['kind'] === 'http');

// ---- how an answer is read
foreach ([[200, 'ok'], [204, 'ok'], [301, 'ok'], [399, 'ok'], [404, 'dead'], [410, 'dead'], [500, 'error'], [503, 'error'], [401, 'blocked'], [403, 'blocked'], [429, 'blocked'], [999, 'error']] as [$code, $want]) {
    check('a page that answers ' . $code . ' is ' . $want, linkcheck_classify_http($code, '') === $want);
}
check('a page that cannot be reached at all is dead', linkcheck_classify_http(0, '') === 'dead' && linkcheck_classify_http(200, 'Could not resolve host') === 'dead');
check('Discord: found is ok, not found is dead, rate limited says nothing, a Discord error is an error', linkcheck_classify_discord(200) === 'ok' && linkcheck_classify_discord(404) === 'dead' && linkcheck_classify_discord(429) === 'blocked' && linkcheck_classify_discord(502) === 'error' && linkcheck_classify_discord(403) === 'blocked');

// ---- fetching, against a local server
$router = dirname(__DIR__) . '/lib/linkcheck-router.php';
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int)explode(':', (string)stream_socket_get_name($sock, false))[1];
fclose($sock);
$proc = proc_open(['php', '-d', 'max_execution_time=30', '-S', "127.0.0.1:$port", $router], [1=>['file', '/dev/null', 'w'], 2=>['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if ($probe) { fclose($probe); break; }
    usleep(100000);
}
$u = static fn(string $path) => "http://127.0.0.1:$port$path";
$status = static fn(string $path) => linkcheck_check($u($path), true, 0)['status'];
check('a page that answers is ok', $status('/ok') === 'ok');
check('a redirect is followed to the page it leads to', $status('/moved') === 'ok' && $status('/chain') === 'ok');
check('a missing page is dead, and so is a gone one', $status('/missing') === 'dead' && $status('/gone') === 'dead');
check('a page that refuses a robot is blocked, not dead', $status('/denied') === 'blocked');
check('a server error is an error', $status('/boom') === 'error');
check('a redirect loop is dead, not an endless wait', $status('/loop') === 'dead');
check('a big page is fine: reading stops early without being called a failure', $status('/big') === 'ok');
[$slowCode, $slowError] = linkcheck_fetch($u('/slow'), true, 1);
check('a page that takes too long is a failure with a reason', $slowError !== '' && linkcheck_classify_http($slowCode, $slowError) === 'dead');
$flag = sys_get_temp_dir() . '/offgrid-flaky-' . bin2hex(random_bytes(3));
check('a blip is retried, so a page that fails twice and then works is ok', $status('/flaky?f=' . $flag) === 'ok');
@unlink($flag);
check('https is required unless a test asks for http', linkcheck_fetch($u('/ok'), false)[1] !== '');
proc_terminate($proc);
proc_close($proc);

// ---- what the site keeps
$dir = sys_get_temp_dir() . '/offgrid-linkstore-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$base = groups_load()['groups'];
$one = $base[0];
$now = 1_800_000_000;
$result = static fn(array $g, string $field, string $status, int $code = 200) => ['id'=>$g['id'], 'field'=>$field, 'h'=>groups_link_hash($g[$field]), 'status'=>$status, 'code'=>$code];
$jobs = groups_link_jobs($base);
check('there is a job for every link of every group', count($jobs) > 400 && count(array_filter($jobs, static fn($j) => str_starts_with($j['url'], 'https://') && strlen($j['h']) === 10)) === count($jobs), count($jobs) . ' jobs');
check('a members-only Discord is not a job', count(array_filter($jobs, static fn($j) => $j['field'] === 'discord' && str_contains($j['url'], 'discord.com/channels'))) === 0);
check('each job names the group, the field and the address', isset($jobs[0]['id'], $jobs[0]['field'], $jobs[0]['url']) && in_array($jobs[0]['field'], array_keys(GROUP_LINK_FIELDS), true));
$state = static fn() => load_state($dir)['groups'] ?? [];

$s1 = groups_store_link_results($dir, [$result($one, 'url', 'dead', 404)], $now);
check('a first failure is kept with one failure counted, and nothing is reported yet', $s1['stored'] === 1 && $s1['newly'] === [] && $state()['links'][$one['id'] . '|url']['fails'] === 1);
$info1 = groups_link_info($state(), $one['id'], $one);
check('after one failure visitors are told nothing', $info1['dead'] === [] && $info1['link_health']['url']['status'] === 'dead');
$s2 = groups_store_link_results($dir, [$result($one, 'url', 'dead', 404)], $now + 86400 * 30);
check('the second failure in a row is reported, once', count($s2['newly']) === 1 && $s2['newly'][0][0] === $one['name'] && $s2['newly'][0][1] === 'Website');
check('and visitors are then told', groups_link_info($state(), $one['id'], $one)['dead'] === ['url']);
$s3 = groups_store_link_results($dir, [$result($one, 'url', 'dead', 404)], $now + 86400 * 60);
check('a third failure is not reported again', $s3['newly'] === [] && $state()['links'][$one['id'] . '|url']['fails'] === 3);
groups_store_link_results($dir, [$result($one, 'url', 'ok', 200)], $now + 86400 * 90);
check('an answer clears it', groups_link_info($state(), $one['id'], $one)['dead'] === [] && $state()['links'][$one['id'] . '|url']['fails'] === 0);
groups_store_link_results($dir, [$result($one, 'url', 'dead')], $now);
groups_store_link_results($dir, [$result($one, 'url', 'blocked', 403)], $now + 100);
groups_store_link_results($dir, [$result($one, 'url', 'dead')], $now + 200);
check('a refusal or "cannot check" in between breaks the run of failures', $state()['links'][$one['id'] . '|url']['fails'] === 1);
groups_store_link_results($dir, [$result($one, 'url', 'error', 503)], $now + 300);
check('a server error counts as a failure too', $state()['links'][$one['id'] . '|url']['fails'] === 2 && groups_link_info($state(), $one['id'], $one)['dead'] === ['url']);

// results that do not belong are ignored
$bogus = groups_store_link_results($dir, [['id'=>'ZZZ', 'field'=>'url', 'h'=>'x', 'status'=>'ok'], ['id'=>$one['id'], 'field'=>'bogus', 'h'=>groups_link_hash($one['url']), 'status'=>'ok'], ['id'=>$one['id'], 'field'=>'url', 'h'=>'0000000000', 'status'=>'ok'],
    ['id'=>$one['id'], 'field'=>'url', 'h'=>groups_link_hash($one['url']), 'status'=>'great'], 'junk', ['id'=>['x'], 'field'=>'url'], ['id'=>$one['id'], 'field'=>'map', 'h'=>groups_link_hash((string)($one['map'] ?? '')), 'status'=>'ok']], $now);
check('results for an unknown group, field, address, status or shape are ignored', $bogus['ignored'] >= 5, json_encode($bogus));
check('a result for a link the group does not have is ignored', ($one['map'] ?? '') !== '' || $bogus['ignored'] === 7);

// an address edited in Admin drops its old results
groups_admin_save($dir, ['name'=>$one['name'], 'protocol'=>$one['protocol'], 'type'=>$one['type'] ?: 'Mesh community', 'url'=>'https://changed.example.org/', 'states'=>$one['states'], 'review'=>$one['review']], $one['id']);
$applied = array_values(array_filter(groups_apply($base, $state(), true), static fn($g) => $g['id'] === $one['id']))[0];
check('editing the address clears the old result, so a fixed link is not blamed', $applied['dead'] === [] && !isset($applied['link_health']['url']));
$after = groups_store_link_results($dir, [], $now);
check('results for addresses no longer used are forgotten', !isset($state()['links'][$one['id'] . '|url']));

// hidden groups are not reported, and the last run is remembered
$two = $base[1];
groups_admin_set_hidden($dir, $two['id'], true);
groups_store_link_results($dir, [$result($two, 'url', 'dead')], $now);
$hidden = groups_store_link_results($dir, [$result($two, 'url', 'dead')], $now + 1);
check('a hidden group that fails is not reported', $hidden['newly'] === []);
check('the run is remembered with its counts', ($state()['links_run']['at'] ?? 0) === $now + 1 && isset($state()['links_run']['dead']));
check('the list of jobs leaves hidden groups out', count(array_filter(groups_link_jobs(groups_apply($base, $state(), false)), static fn($j) => $j['id'] === $two['id'])) === 0);
check('link checks never change the "compiled" date', ($state()['updated'] ?? 0) !== $now + 1);
finish();
