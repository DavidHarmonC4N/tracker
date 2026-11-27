<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

/**
 * Checking that the links in the group directory still work. The checking itself runs on GitHub (runner/links.php, monthly),
 * because that is a place that can reach every site; this file has the rules it follows, which are plain functions so they can be tested.
 *
 * A link is one of: ok (it answered), blocked (it answered "no" to a robot, so we cannot tell), unchecked (not checked on purpose),
 * dead (not found, or no answer at all) or error (the site had a server error).
 */

const LINKCHECK_STATUSES = ['ok', 'blocked', 'unchecked', 'dead', 'error'];

/** What to do with a link: skip it (and why), ask Discord about an invite, or just fetch it. */
function linkcheck_plan(string $url): array {
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    $host = preg_replace('/^www\./', '', $host);
    // Facebook shows a login page for every address, found or not, so a check would say nothing.
    if ($host === 'facebook.com' || str_ends_with($host, '.facebook.com') || $host === 'fb.me' || $host === 'fb.com') {
        return ['kind'=>'skip', 'status'=>'unchecked', 'why'=>'Facebook cannot be checked without signing in'];
    }
    if (($host === 'discord.com' || $host === 'discordapp.com') && str_starts_with($path, '/channels/')) {
        return ['kind'=>'skip', 'status'=>'unchecked', 'why'=>'a Discord channel link needs existing membership'];
    }
    $code = linkcheck_discord_code($url);
    return $code !== null ? ['kind'=>'discord', 'code'=>$code] : ['kind'=>'http'];
}

/** The invite code in a Discord invite link (discord.gg/abc or discord.com/invite/abc), or null. */
function linkcheck_discord_code(string $url): ?string {
    $parts = parse_url($url);
    $host = preg_replace('/^www\./', '', strtolower((string)($parts['host'] ?? '')));
    $path = trim((string)($parts['path'] ?? ''), '/');
    if ($host === 'discord.gg' && preg_match('~^([A-Za-z0-9-]{2,32})$~D', $path, $m)) {
        return $m[1];
    }
    if (in_array($host, ['discord.com', 'discordapp.com'], true) && preg_match('~^invite/([A-Za-z0-9-]{2,32})$~D', $path, $m)) {
        return $m[1];
    }
    return null;
}

/** The result of fetching an ordinary page. $error is cURL's error text, empty when the site answered. */
function linkcheck_classify_http(int $code, string $error): string {
    if ($error !== '' || $code === 0) {
        return 'dead';                       // no DNS, connection refused, timed out, bad certificate
    }
    if ($code >= 200 && $code < 400) {
        return 'ok';
    }
    if ($code === 404 || $code === 410) {
        return 'dead';
    }
    if ($code >= 500) {
        return 'error';
    }
    return 'blocked';                        // 401, 403, 429 and the like: it is there but will not talk to a robot
}

/** The answer of Discord's public invite lookup. */
function linkcheck_classify_discord(int $code): string {
    return match (true) {
        $code === 200 => 'ok',
        $code === 404 => 'dead',             // the invite is expired or does not exist
        $code === 429 => 'blocked',          // rate limited: say nothing rather than something wrong
        $code >= 500 => 'error',
        default => 'blocked',
    };
}

/**
 * Fetches a page for its status. Follows up to five redirects, reads at most 64 KB, and never sends cookies.
 * Returns [http code, error text]. $allowHttp is for tests that talk to a local server.
 */
function linkcheck_fetch(string $url, bool $allowHttp = false, int $timeout = 20): array {
    $curl = curl_init($url);
    $protocols = $allowHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS;
    $read = 0;
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_PROTOCOLS=>$protocols, CURLOPT_REDIR_PROTOCOLS=>(CURLPROTO_HTTP | CURLPROTO_HTTPS), // the address we were given must be https, but a site that sends us on to plain http is still a working site CURLOPT_USERAGENT=>'OffgridCommsTracker-LinkCheck/1.0 (+https://mesh.davidandlaura.net/)',
        CURLOPT_HTTPHEADER=>['Accept: text/html,*/*;q=0.5'], CURLOPT_PROXY=>'',
        CURLOPT_WRITEFUNCTION=>static function ($c, string $chunk) use (&$read): int {
            $read += strlen($chunk);
            return $read > 65536 ? 0 : strlen($chunk);   // stop reading: the status is all we need
        },
    ]);
    curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($curl);
    $error = curl_error($curl);
    curl_close($curl);
    // Stopping the read on purpose is not a failure.
    if ($errno === CURLE_WRITE_ERROR && $code > 0) {
        $error = '';
    }
    return [$code, $error];
}

/** Checks one link and returns ['status'=>..., 'code'=>int]. Retries a failure twice, a few seconds apart, so a blip is not reported. */
function linkcheck_check(string $url, bool $allowHttp = false, int $pause = 4): array {
    $plan = linkcheck_plan($url);
    if ($plan['kind'] === 'skip') {
        return ['status'=>'unchecked', 'code'=>0];
    }
    $result = ['status'=>'dead', 'code'=>0];
    for ($try = 0; $try < 3; $try++) {
        if ($try > 0) {
            sleep($pause);
        }
        if ($plan['kind'] === 'discord') {
            [$code, $error] = linkcheck_fetch('https://discord.com/api/v10/invites/' . $plan['code'], false);
            $result = ['status'=>$error !== '' ? 'dead' : linkcheck_classify_discord($code), 'code'=>$code];
        } else {
            [$code, $error] = linkcheck_fetch($url, $allowHttp);
            $result = ['status'=>linkcheck_classify_http($code, $error), 'code'=>$code];
        }
        if (!in_array($result['status'], ['dead', 'error'], true)) {
            break;
        }
    }
    return $result;
}
