<?php
declare(strict_types=1);
// Checker health: what counts as stalled, when a seller problem is reported, that each is reported once, and that recovery is announced.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

$now = 1_800_000_000;
$iso = static fn(int $t) => gmdate('c', $t);
$item = static fn(string $id, string $host, array $over = []) => $over + ['id'=>$id, 'url'=>'https://' . $host . '/products/' . $id, 'mode'=>'auto', 'paused'=>false, 'checker'=>'remote', 'error'=>null];
$state = static fn(array $items, ?int $runner = null, ?int $cron = null) => ['items'=>$items, 'last_runner_run'=>$runner ? gmdate('c', $runner) : null, 'last_run'=>$cron ? gmdate('c', $cron) : null];

// ---- stalled or not
$one = [$item('a', 'shop.example.com')];
check('a runner that checked in an hour ago is fine', health_evaluate($state($one, $now - 3600), $now)['stalled'] === false);
check('a runner silent for three hours is stalled', health_evaluate($state($one, $now - 3 * 3600), $now)['stalled'] === true);
check('just under three hours is not yet stalled', health_evaluate($state($one, $now - 3 * 3600 + 60), $now)['stalled'] === false);
check('a site that has never been checked is not called stalled', health_evaluate($state($one, null), $now)['stalled'] === false);
check('with nothing to check there is nothing to stall', health_evaluate($state([$item('a', 'x.test', ['paused'=>true]), $item('b', 'x.test', ['mode'=>'manual'])], $now - 9 * 3600), $now)['stalled'] === false);
$server = [$item('a', 'shop.example.com', ['checker'=>'server'])];
check('a site checked by its own cron looks at the cron\'s last run, not the runner', health_evaluate($state($server, $now - 9 * 3600, $now - 600), $now)['stalled'] === false && health_evaluate($state($server, $now - 600, $now - 9 * 3600), $now)['stalled'] === true);

// ---- seller trouble
$bad = static fn(string $id, string $host) => $item($id, $host, ['error'=>'The seller declined automatic checks (HTTP 403).']);
$eval = health_evaluate($state([$bad('a', 'shop.example.com'), $bad('b', 'shop.example.com'), $item('c', 'other.example.com')], $now), $now);
check('a seller whose listings all fail is reported, with the first error', array_keys($eval['sellers']) === ['shop.example.com'] && $eval['sellers']['shop.example.com']['count'] === 2 && str_contains($eval['sellers']['shop.example.com']['error'], '403'));
check('one good listing is enough to clear a seller', health_evaluate($state([$bad('a', 's.test'), $item('b', 's.test')], $now), $now)['sellers'] === []);
check('a seller with a single listing is not reported', health_evaluate($state([$bad('a', 's.test')], $now), $now)['sellers'] === []);

// ---- reporting once, and recovery
$stalled = ['stalled'=>true, 'last'=>$now - 4 * 3600, 'sellers'=>[]];
$fine = ['stalled'=>false, 'last'=>$now - 60, 'sellers'=>[]];
[$h, $notes] = health_next([], $stalled, $now);
check('a stall is reported with how long and what to check', count($notes) === 1 && $notes[0][0] === 'Checker stalled' && str_contains($notes[0][1], '4 hours') && str_contains($notes[0][1], 'OFFGRID_TOKEN'));
[$h2, $again] = health_next($h, $stalled, $now + 3600);
check('the same stall is not reported again', $again === [] && $h2 === $h);
[$h3, $back] = health_next($h2, $fine, $now + 7200);
check('recovery is announced once and clears the record', count($back) === 1 && $back[0][0] === 'Checker is back' && $h3 === []);
check('nothing is said when all is well', health_next([], $fine, $now) === [[], []]);

$sellerEval = ['stalled'=>false, 'last'=>$now, 'sellers'=>['shop.example.com'=>['count'=>3, 'error'=>'HTTP 403']]];
[$s1, $n1] = health_next([], $sellerEval, $now);
check('a seller problem is not reported the first time it is seen', $n1 === [] && isset($s1['sellers']['shop.example.com']));
[$s2, $n2] = health_next($s1, $sellerEval, $now + HEALTH_SELLER_AFTER - 60);
check('nor before it has lasted three hours', $n2 === []);
[$s3, $n3] = health_next($s2, $sellerEval, $now + HEALTH_SELLER_AFTER);
check('after three hours it is reported with the count and the error', count($n3) === 1 && str_contains($n3[0][0], 'shop.example.com') && str_contains($n3[0][1], 'All 3 listings') && str_contains($n3[0][1], 'HTTP 403'));
[$s4, $n4] = health_next($s3, $sellerEval, $now + 5 * 3600);
check('and only once', $n4 === []);
[$s5, $n5] = health_next($s4, ['stalled'=>false, 'last'=>$now, 'sellers'=>[]], $now + 6 * 3600);
check('a reported seller that recovers is announced', count($n5) === 1 && str_contains($n5[0][0], 'recovered') && $s5 === []);
[$s6, $n6] = health_next($s1, ['stalled'=>false, 'last'=>$now, 'sellers'=>[]], $now + 600);
check('a brief seller failure that clears before three hours says nothing', $n6 === [] && $s6 === []);

// ---- end to end on a data folder
$dir = sys_get_temp_dir() . '/offgrid-health-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
change_state($dir, static function (&$s) use ($now) { foreach ($s['items'] as &$row) { $row['checker'] = 'remote'; $row['paused'] = false; $row['error'] = null; } $s['last_runner_run'] = gmdate('c', $now - 5 * 3600); });
$sent = maybe_check_health($dir, $now);
check('a stalled site is reported on a catalog request', count($sent) === 1 && $sent[0][0] === 'Checker stalled', json_encode($sent));
check('and the report is remembered in the data', isset(load_state($dir)['health']['stalled']));
check('a second request within five minutes does no work', maybe_check_health($dir, $now + 60) === []);
@unlink($dir . '/health.check');
check('a later request does not repeat the report', maybe_check_health($dir, $now + 3600) === []);
change_state($dir, static function (&$s) use ($now) { $s['last_runner_run'] = gmdate('c', $now + 3500); });
@unlink($dir . '/health.check');
$back = maybe_check_health($dir, $now + 3600);
check('when the runner reports again, recovery is sent', count($back) === 1 && $back[0][0] === 'Checker is back' && !isset(load_state($dir)['health']['stalled']));
finish();
