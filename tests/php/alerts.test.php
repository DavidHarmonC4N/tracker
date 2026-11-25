<?php
declare(strict_types=1);
// Owner alerts: the sender address, one digest per request, real results per channel, and the alert log.
// Each scenario runs in its own PHP process on its own copy of the site, with a fake "sendmail" that writes the message
// to a file, so the real headers can be checked without sending any email.
require dirname(__DIR__) . '/lib/harness.php';
load_site();

$siteDir = (string)getenv('SITE_DIR');
$work = sys_get_temp_dir() . '/offgrid-alerts-' . getmypid();
mkdir($work);
$child = dirname(__DIR__) . '/lib/alerts-child.php';

// pure helpers, in this process (the unit site has base_url https://mesh.example.org/)
[$subject, $body] = notify_digest([['One', 'body one']]);
check('a single alert is sent as it is', $subject === 'One' && $body === 'body one');
[$subject, $body] = notify_digest([['First', 'body one'], ['Second', 'body two'], ['Third', 'body three']]);
check('several alerts become one digest', str_starts_with($subject, '3 updates: First and 2 more') && str_contains($body, 'body one') && str_contains($body, "body three"));
check('the sender defaults to alerts@ the site\'s domain', notify_from_address() === 'alerts@example.org', notify_from_address());

/** Copies the site, applies config overrides, runs a scenario with a capturing sendmail, returns [json, mail file contents]. */
function scenario(string $name, string $mode, array $settings, ?string $sendmail, string $work, string $siteDir, string $child): array {
    $copy = $work . '/' . $name;
    mkdir($copy);
    $data = $work . '/' . $name . '-data'; // the app refuses a data folder inside the site, so keep it beside it
    mkdir($data);
    exec('cp -r ' . escapeshellarg($siteDir) . '/. ' . escapeshellarg($copy) . ' 2>&1');
    $config = include $copy . '/config.php';
    $config = $settings + ['data_dir'=>$data] + $config;
    file_put_contents($copy . '/config.php', "<?php\nif (!defined('OFFGRID_APP')) { http_response_code(404); exit; }\nreturn " . var_export($config, true) . ";\n");
    $mail = $copy . '/mail.eml';
    $ini = $sendmail === null ? '' : ' -d ' . escapeshellarg('sendmail_path=' . str_replace('{file}', $mail, $sendmail));
    $lines = [];
    exec('SITE_DIR=' . escapeshellarg($copy) . ' php' . $ini . ' ' . escapeshellarg($child) . ' ' . escapeshellarg($mode) . ' 2>&1', $lines);
    $json = json_decode((string)end($lines), true);
    $state = is_file($data . '/tracker.json') ? json_decode((string)file_get_contents($data . '/tracker.json'), true) : [];
    return [$json, is_file($mail) ? (string)file_get_contents($mail) : '', $state, implode("\n", $lines)];
}
$accept = 'cat > {file}';            // a sendmail that accepts the message
$refuse = '/bin/false';              // a sendmail that always fails
$noEmail = ['notify_email'=>'', 'notify_webhook'=>'', 'notify_from'=>''];

[$r] = scenario('none', 'send', $noEmail, $accept, $work, $siteDir, $child);
check('with no channel set up, nothing is sent and the result is empty', $r['results'] === []);

[$r] = scenario('badmail', 'send', ['notify_email'=>'not-an-address'] + $noEmail, $accept, $work, $siteDir, $child);
check('an invalid notify_email is reported, not ignored', $r['results'][0]['ok'] === false && str_contains($r['results'][0]['message'], 'not a valid address'));

[$r, $mail] = scenario('ok', 'send', ['notify_email'=>'owner@example.com', 'notify_from'=>'alerts@example.org'] + $noEmail, $accept, $work, $siteDir, $child);
check('email accepted by the mail system is reported as handed over, not as delivered', $r['results'][0]['ok'] === true && str_contains($r['results'][0]['message'], 'not proof of delivery'));
check('the sender is the configured notify_from', str_contains($mail, 'From: Offgrid Comms Tracker <alerts@example.org>') && str_contains($mail, 'Reply-To: alerts@example.org'), substr($mail, 0, 120));
check('the subject is encoded and tagged', preg_match('/^Subject: =\?UTF-8\?B\?/m', $mail) === 1 && str_contains($mail, base64_encode('[Offgrid] Subject with ümlaut')));
check('the body is plain text UTF-8', str_contains($mail, 'Content-Type: text/plain; charset=UTF-8') && str_contains($mail, 'Body line two'));
check('the message is addressed to the owner', str_contains($mail, 'To: owner@example.com'));

[$r] = scenario('refused', 'send', ['notify_email'=>'owner@example.com'] + $noEmail, $refuse, $work, $siteDir, $child);
check('a refusing mail system is reported with advice', $r['results'][0]['ok'] === false && str_contains($r['results'][0]['message'], 'refused') && str_contains($r['results'][0]['message'], 'webhook'), $r['results'][0]['message'] ?? '');

// some hosts reject the extra "-f sender" argument; the message must then be sent without it
$picky = $work . '/picky-sendmail.sh';
file_put_contents($picky, "#!/bin/sh\nfor a in \"\$@\"; do case \"\$a\" in -f*) exit 1 ;; esac; done\ncat > " . $work . "/picky/mail.eml\n");
chmod($picky, 0755);
[$r, $mail] = scenario('picky', 'send', ['notify_email'=>'owner@example.com', 'notify_from'=>'alerts@example.org'] + $noEmail, $picky, $work, $siteDir, $child);
check('a host that rejects the envelope sender still gets the message', $r['results'][0]['ok'] === true && str_contains($mail, 'Body line one') && str_contains($mail, 'From: Offgrid Comms Tracker <alerts@example.org>'), json_encode($r['results']));

[$r] = scenario('hookhttp', 'send', ['notify_webhook'=>'http://example.com/topic'] + $noEmail, null, $work, $siteDir, $child);
check('a webhook that is not https is reported', $r['results'][0]['ok'] === false && str_contains($r['results'][0]['message'], 'https://'));
[$r] = scenario('hookdown', 'send', ['notify_webhook'=>'https://127.0.0.1:9/never'] + $noEmail, null, $work, $siteDir, $child);
check('an unreachable webhook is reported', $r['results'][0]['ok'] === false && str_contains($r['results'][0]['message'], 'Could not reach'), $r['results'][0]['message'] ?? '');

// several alerts in one request are sent as one digest, and the outcome is logged
[$r, $mail, $state, $raw] = scenario('digest', 'queue', ['notify_email'=>'owner@example.com', 'notify_from'=>'alerts@example.org'] + $noEmail, $accept, $work, $siteDir, $child);
check('three alerts in one request make one email', substr_count($mail, 'Subject:') === 1 && str_contains($mail, base64_encode('[Offgrid] 3 updates: First alert and 2 more')), substr($mail, 0, 200));
check('the digest carries every alert', str_contains($mail, 'first body') && str_contains($mail, 'second body') && str_contains($mail, 'third body'));
check('the result is written to the alert log', count($state['alert_log'] ?? []) === 1 && $state['alert_log'][0]['ok'] === true && $state['alert_log'][0]['channel'] === 'email', json_encode($state['alert_log'] ?? null));
[, $mail, $state] = scenario('single', 'queue-one', ['notify_email'=>'owner@example.com', 'notify_from'=>'alerts@example.org'] + $noEmail, $accept, $work, $siteDir, $child);
check('a single queued alert keeps its own subject', str_contains($mail, base64_encode('[Offgrid] Only alert')));
[, $mail, $state] = scenario('failed-log', 'queue-one', ['notify_email'=>'owner@example.com'] + $noEmail, $refuse, $work, $siteDir, $child);
check('a failed send is logged so the admin can show it', ($state['alert_log'][0]['ok'] ?? null) === false && str_contains($state['alert_log'][0]['message'] ?? '', 'refused'), json_encode($state['alert_log'] ?? null));

// a staging copy sends nothing
[$r, $mail] = scenario('staging', 'send', ['staging'=>true, 'notify_email'=>'owner@example.com'] + $noEmail, $accept, $work, $siteDir, $child);
check('a staging copy sends nothing even with an address set', $r['results'] === [] && $mail === '');

// the alert log keeps only the latest entries
$dir = $work . '/log-data';
mkdir($dir);
$fake = ['ok'=>true, 'channel'=>'email', 'message'=>'x'];
for ($i = 0; $i < 30; $i++) {
    change_state_dir_exists($dir);
    alert_log_record($dir, "Alert $i", [$fake]);
}
$log = json_decode((string)file_get_contents($dir . '/tracker.json'), true)['alert_log'];
check('the alert log keeps the last ' . ALERT_LOG_LIMIT . ' entries', count($log) === ALERT_LOG_LIMIT && $log[ALERT_LOG_LIMIT - 1]['subject'] === 'Alert 29');

exec('rm -r ' . escapeshellarg($work));
finish();

/** change_state() needs a state file to read; start one for the throwaway folder. */
function change_state_dir_exists(string $dir): void {
    if (!is_file($dir . '/tracker.json')) {
        file_put_contents($dir . '/tracker.json', json_encode(load_state($dir)));
    }
}
