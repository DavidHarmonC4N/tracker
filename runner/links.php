<?php
declare(strict_types=1);

// Monthly check of the links in the group directory. Runs from GitHub Actions (.github/workflows/links.yml): asks the site for its
// links, visits each one politely, and posts what it found. Needs OFFGRID_URL and OFFGRID_TOKEN in the environment.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('OFFGRID_APP', true);
require __DIR__ . '/../includes/app.php';

function links_api(string $base, string $token, string $action, array $body): array {
    $curl = curl_init($base . 'ingest.php?action=' . $action);
    curl_setopt_array($curl, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>60, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>getenv('OFFGRID_ALLOW_HTTP') === '1' ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'OffgridCommsTracker-Runner/1.0 (+GitHub Actions)',
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json', 'X-Offgrid-Token: ' . $token],
    ]);
    $response = curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $data = is_string($response) ? json_decode($response, true) : null;
    if ($code !== 200 || !is_array($data)) {
        throw new RuntimeException('Tracker API ' . $action . ' failed (HTTP ' . $code . ').');
    }
    return $data;
}

echo "Offgrid group link check\n";
try {
    $base = rtrim((string)getenv('OFFGRID_URL'), '/') . '/';
    $token = (string)getenv('OFFGRID_TOKEN');
    if ($base === '/' || strlen($token) < 32) {
        throw new RuntimeException('Set OFFGRID_URL and OFFGRID_TOKEN (at least 32 characters).');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Enable the PHP cURL extension.');
    }
    $allowHttp = getenv('OFFGRID_ALLOW_HTTP') === '1';
    $pause = max(0, (int)(getenv('LINKCHECK_PAUSE') ?: 4));
    $jobs = links_api($base, $token, 'group_jobs', [])['jobs'] ?? [];
    echo 'Checking ' . count($jobs) . " link(s).\n";
    $cache = [];      // the same address is checked once, however many groups use it
    $lastByHost = []; // at least a second between two visits to the same site
    $results = [];
    $counts = array_fill_keys(LINKCHECK_STATUSES, 0);
    foreach ($jobs as $job) {
        if (!is_array($job) || !is_string($job['url'] ?? null) || !is_string($job['id'] ?? null) || !is_string($job['field'] ?? null) || !is_string($job['h'] ?? null)) {
            continue;
        }
        $url = $job['url'];
        if (!isset($cache[$url])) {
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $wait = ($lastByHost[$host] ?? 0) + 1.2 - microtime(true);
            if ($wait > 0) {
                usleep((int)($wait * 1_000_000));
            }
            try {
                $cache[$url] = linkcheck_check($url, $allowHttp, $pause);
            } catch (Throwable $failure) {
                $cache[$url] = ['status'=>'error', 'code'=>0];
            }
            $lastByHost[$host] = microtime(true);
            echo '  ' . str_pad($cache[$url]['status'], 9) . ' ' . $cache[$url]['code'] . ' ' . substr($url, 0, 90) . "\n";
        }
        $counts[$cache[$url]['status']]++;
        $results[] = ['id'=>$job['id'], 'field'=>$job['field'], 'h'=>$job['h'], 'status'=>$cache[$url]['status'], 'code'=>$cache[$url]['code']];
    }
    $stored = 0;
    foreach (array_chunk($results, 400) as $chunk) {
        $stored += (int)(links_api($base, $token, 'group_results', ['results'=>$chunk])['stored'] ?? 0);
    }
    echo 'Done. ' . $stored . ' stored. ' . implode(', ', array_map(static fn($k, $v) => $v . ' ' . $k, array_keys($counts), $counts)) . ".\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'Offgrid link check: ' . $failure->getMessage() . "\n");
    exit(1);
}
