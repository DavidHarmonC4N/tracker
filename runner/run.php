<?php
declare(strict_types=1);

// U.S.-side checker. Runs from GitHub Actions (or any CLI): asks the site for listings, scans the sellers from
// this machine's network, and posts the results back. Needs OFFGRID_URL and OFFGRID_TOKEN in the environment.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('OFFGRID_APP', true);
require __DIR__ . '/../includes/app.php';
require __DIR__ . '/../includes/scanner.php';

function api(string $base, string $token, string $action, array $body): array {
    $seen = [];
    $curl = curl_init($base . 'ingest.php?action=' . $action);
    curl_setopt_array($curl, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>getenv('OFFGRID_ALLOW_HTTP') === '1' ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'OffgridCommsTracker-Runner/1.0 (+GitHub Actions)',
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json', 'X-Offgrid-Token: ' . $token],
        CURLOPT_HEADERFUNCTION=>static function ($c, string $line) use (&$seen): int {
            if (preg_match('/^(server|cf-ray|x-[a-z-]*(?:waf|block|firewall|sucuri|imunify)[a-z-]*|content-type):\s*(.+)$/i', trim($line), $m)) {
                $seen[] = strtolower($m[1]) . ': ' . substr($m[2], 0, 60);
            }
            return strlen($line);
        },
    ]);
    $response = curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($response) ? json_decode($response, true) : null;
    if ($code !== 200 || !is_array($data)) {
        throw new RuntimeException('Tracker API ' . $action . ' failed (HTTP ' . $code . ($error !== '' ? ', ' . $error : '')
            . (is_array($data) && isset($data['error']) ? ': ' . $data['error'] : '') . ').'
            . (!is_array($data) || !isset($data['error']) ? ' The response did not come from ingest.php. First 200 characters: '
                . substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$response))), 0, 200)
                . ($seen ? ' [' . implode('; ', $seen) . ']' : '') : ''));
    }
    return $data;
}

echo "Offgrid runner v2 (sends User-Agent)\n";
try {
    $base = rtrim((string)getenv('OFFGRID_URL'), '/') . '/';
    $token = (string)getenv('OFFGRID_TOKEN');
    if ($base === '/' || strlen($token) < 32) {
        throw new RuntimeException('Set OFFGRID_URL and OFFGRID_TOKEN (at least 32 characters).');
    }
    if (!extension_loaded('curl') || !extension_loaded('dom')) {
        throw new RuntimeException('Enable the PHP cURL and DOM extensions.');
    }
    $jobs = api($base, $token, 'jobs', ['limit'=>60])['jobs'] ?? [];
    echo 'Claimed ' . count($jobs) . " listing(s).\n";
    $cache = sys_get_temp_dir() . '/offgrid-runner-' . bin2hex(random_bytes(4));
    mkdir($cache, 0700);
    $deadline = time() + 240;
    // Stores with several listings in this run: read the whole product feed once instead of one page per listing.
    $wanted = [];
    foreach ($jobs as $job) {
        $handle = ($job['mode'] ?? '') === 'auto' ? shopify_handle((string)($job['url'] ?? '')) : null;
        if ($handle !== null) {
            try {
                $wanted[seller_bucket(product_url($job['url'])['host'])][$handle] = true;
            } catch (InvalidArgumentException) {
                continue;
            }
        }
    }
    $feeds = [];
    $bulkHosts = [];
    $bulkFailed = [];
    $kept = [];
    foreach ($wanted as $host => $handles) {
        if (count($handles) < BULK_MIN_PAGES) {
            continue;
        }
        $feeds[$host] = shopify_bulk_lookup($host, array_keys($handles), static fn($url) => guarded_fetch_live($url, $cache));
        if ($feeds[$host]) {
            $bulkHosts[] = $host;
            echo 'Read ' . $host . ' in bulk: ' . count($feeds[$host]) . ' of ' . count($handles) . " listing(s) from its product feed.\n";
        } else {
            $bulkFailed[] = $host;
            echo $host . " has no readable product feed. Reading its pages one by one.\n";
        }
    }
    // Without a feed, a store keeps the old limit of a few pages a run. The rest wait for the next run.
    $perHost = [];
    $jobs = array_values(array_filter($jobs, static function ($job) use ($bulkFailed, &$perHost) {
        $host = seller_bucket(product_url((string)$job['url'])['host']);
        if (!in_array($host, $bulkFailed, true)) {
            return true;
        }
        $perHost[$host][$job['url']] = true;
        return count($perHost[$host]) <= REMOTE_PER_SELLER;
    }));
    $results = [];
    $fallbacks = [];
    foreach ($jobs as $job) {
        // A listing the feed did not contain is read page by page, but only a few per store per run.
        $feedHost = seller_bucket(product_url((string)$job['url'])['host']);
        $feedHandle = shopify_handle((string)$job['url']);
        if (!empty($feeds[$feedHost]) && ($feedHandle === null || !isset($feeds[$feedHost][$feedHandle]))
            && ++$fallbacks[$feedHost] > REMOTE_PER_SELLER) {
            continue;
        }
        $line = ($job['name'] ?? '?') . ' <' . ($job['url'] ?? '') . '> ';
        // Out of time: the rest are left alone, not reported as failed, so they are not marked as checked or flagged for review.
        // They are claimed again on a later run.
        if (time() >= $deadline) {
            echo "Out of time. The remaining listing(s) wait for the next run.\n";
            break;
        }
        $out = ['id'=>$job['id'],'fingerprint'=>$job['fingerprint'],'ok'=>false,'error'=>'','cooldown'=>0];
        try {
            $found = scan_item($job, $cache, $feeds[seller_bucket(product_url((string)$job['url'])['host'])] ?? null);
            $out = ['id'=>$job['id'],'fingerprint'=>$job['fingerprint'],'ok'=>true,
                'result'=>array_intersect_key($found, array_flip(['status','price','qty','from_price','detail']))];
            echo $line . '-> ' . $found['status'] . ' ' . ($found['price'] === null ? '' : '$' . number_format($found['price'] / 100, 2)) . "\n";
        } catch (ScanFailure $failure) {
            $out['error'] = $failure->getMessage();
            $out['cooldown'] = $failure->cooldown;
            echo $line . '-> FAILED: ' . $failure->getMessage() . "\n";
        } catch (InvalidArgumentException $failure) {
            $out['error'] = $failure->getMessage();
            echo $line . '-> FAILED: ' . $failure->getMessage() . "\n";
        } catch (Throwable $failure) {
            $out['error'] = 'The runner could not read this page.';
            echo $line . '-> ERROR: ' . $failure->getMessage() . "\n";
        }
        $results[] = $out;
    }
    if ($results) {
        $summary = api($base, $token, 'results', ['results'=>$results, 'bulk_hosts'=>$bulkHosts, 'bulk_failed'=>$bulkFailed]);
        echo 'Posted results: ' . ($summary['stored'] ?? 0) . ' stored, ' . ($summary['ignored'] ?? 0) . " ignored.\n";
    }
} catch (Throwable $failure) {
    fwrite(STDERR, 'Offgrid runner: ' . $failure->getMessage() . "\n");
    exit(1);
}
