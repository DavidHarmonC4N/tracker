<?php
declare(strict_types=1);
// A staging copy (config 'staging' => true) must be clearly marked, hidden from search engines, and unable to
// send alerts, accept the real U.S. runner, or start it. A normal site must be none of those things.
require dirname(__DIR__) . '/lib/harness.php';

$stagingDir = getenv('STAGING_SITE_DIR');
if (!$stagingDir) {
    echo "SKIP no staging copy was prepared (run this through tests/run.sh)\n";
    exit(0);
}

// Run the same checks in two separate processes: the config is read once per process.
if (($argv[1] ?? '') === 'staging') {
    load_site($stagingDir);
    require $stagingDir . '/includes/dispatch.php';
    check('staging: is_staging() is true', is_staging() === true);
    check('staging: the public catalog says so', endpoint_result()['staging'] === true);
    check('staging: ingest is off even though a token is configured', ingest_enabled() === false && strlen((string)config()['ingest_token']) >= 32);
    check('staging: GitHub triggering is off even though a token is configured', github_trigger_enabled() === false && config()['github_token'] !== '');
    check('staging: starter listings do not ask for the U.S. runner', count(array_filter(seed(), static fn($i) => ($i['checker'] ?? 'server') === 'remote')) === 0);
    check('staging: notify() returns without sending', (function () { $start = microtime(true); notify('subject', 'body'); return microtime(true) - $start < 1; })());
    finish();
}

// the default process runs the normal site and starts the staging check as a child
load_site();
check('normal site: is_staging() is false', is_staging() === false);
check('normal site: the public catalog says it is not staging', endpoint_result()['staging'] === false);
check('normal site: ingest follows the configured token', ingest_enabled() === true);
check('the repository config template keeps staging off', (include dirname(__DIR__, 2) . '/config.php')['staging'] === false);
$output = [];
exec('STAGING_SITE_DIR=' . escapeshellarg($stagingDir) . ' SITE_DIR=' . escapeshellarg($stagingDir) . ' php ' . escapeshellarg(__FILE__) . ' staging 2>&1', $output, $status);
foreach ($output as $line) {
    echo $line . "\n";
    if (str_starts_with($line, 'PASS')) { $GLOBALS['tests_passed']++; }
    if (str_starts_with($line, 'FAIL')) { $GLOBALS['tests_failed']++; }
}
check('the staging checks ran and passed', $status === 0);
finish();
