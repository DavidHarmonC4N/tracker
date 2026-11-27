<?php
declare(strict_types=1);

// Machine endpoint for the U.S. runner (see runner/run.php). Disabled until ingest_token is set in config.php.
define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/scanner.php';
common_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

function reply(int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    // Alerts are sent after this returns, and a slow ntfy post must not make the runner's request time out.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    exit;
}

$token = (string)(config()['ingest_token'] ?? '');
if (strlen($token) < 32) {
    http_response_code(404);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['error'=>'Use POST.']);
}
try {
    $dir = data_dir();
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''));
    $given = (string)($_SERVER['HTTP_X_OFFGRID_TOKEN'] ?? '');
    // Checked without touching tracker.json: a wrong guess only updates the small rate-limit file.
    if (rate_limited($dir, 'ingest', $ip, 20, 900)) {
        $allowed = 'limited';
    } elseif ($given !== '' && hash_equals($token, $given)) {
        rate_limit_clear($dir, 'ingest', $ip);
        $allowed = 'ok';
    } else {
        rate_limit_hit($dir, 'ingest', $ip, 20, 900);
        $allowed = 'denied';
    }
    if ($allowed === 'limited') {
        reply(429, ['error'=>'Too many failed attempts. Try again later.']);
    }
    if ($allowed !== 'ok') {
        reply(403, ['error'=>'Access denied.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if ($raw === false || strlen($raw) > 262144) {
        reply(413, ['error'=>'Request too large.']);
    }
    $input = json_decode($raw === '' ? '{}' : $raw, true);
    if (!is_array($input)) {
        reply(400, ['error'=>'Send a JSON object.']);
    }
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'jobs') {
        $limit = max(1, min(60, (int)($input['limit'] ?? 24)));
        reply(200, ['jobs'=>claim_jobs($dir, $limit)]);
    }
    if ($action === 'group_jobs') {
        reply(200, ['jobs'=>groups_link_jobs(groups_current()['groups'])]);
    }
    if ($action === 'group_results') {
        $summary = groups_store_link_results($dir, is_array($input['results'] ?? null) ? $input['results'] : []);
        if ($summary['newly']) {
            $lines = array_map(static fn($n) => '• ' . $n[0] . ' · ' . $n[1], array_slice($summary['newly'], 0, 20));
            notify('Group directory: ' . count($summary['newly']) . ' link' . (count($summary['newly']) === 1 ? '' : 's') . ' not responding',
                "These links failed the last two monthly checks and now carry a note on the public page:\n" . implode("\n", $lines)
                . (count($summary['newly']) > 20 ? "\n…and " . (count($summary['newly']) - 20) . ' more.' : '') . (base_url() !== '' ? "\n" . base_url() . 'admin.php?view=groups' : ''), ['type'=>'groups']);
        }
        reply(200, ['stored'=>$summary['stored'], 'ignored'=>$summary['ignored'], 'counts'=>$summary['counts']]);
    }
    if ($action === 'results') {
        reply(200, accept_results($dir, is_array($input['results'] ?? null) ? $input['results'] : [],
            is_array($input['bulk_hosts'] ?? null) ? $input['bulk_hosts'] : [], is_array($input['bulk_failed'] ?? null) ? $input['bulk_failed'] : []));
    }
    reply(400, ['error'=>'Unknown action.']);
} catch (Throwable $failure) {
    error_log('Offgrid ingest: ' . $failure->getMessage());
    reply(500, ['error'=>'The tracker could not process this request.']);
}
