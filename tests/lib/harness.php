<?php
declare(strict_types=1);

// Tiny test helper for the PHP tests. A test file requires this, calls check() for each thing it verifies,
// and ends with finish(). The output format ("PASS name" / "FAIL name") matches the browser tests.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['tests_passed'] = 0;
$GLOBALS['tests_failed'] = 0;

function check(string $name, bool $condition, string $detail = ''): void {
    $GLOBALS[$condition ? 'tests_passed' : 'tests_failed']++;
    $detail = trim(str_replace(["\r", "\n"], ['', ' | '], $detail)); // one line per check, so a real FAIL stands out
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? '  ' . $detail : '') . "\n";
}

/** True when $run throws $class (any Throwable by default). */
function throws(callable $run, string $class = Throwable::class): bool {
    try {
        $run();
    } catch (Throwable $caught) {
        return $caught instanceof $class;
    }
    return false;
}

function finish(): never {
    $failed = $GLOBALS['tests_failed'];
    echo $failed ? "$failed FAILED\n" : "ALL PASSED ({$GLOBALS['tests_passed']} checks)\n";
    exit($failed ? 1 : 0);
}

/** The site the tests run against: a copy made by tests/run.sh (SITE_DIR), or the repository itself. */
function load_site(?string $folder = null): string {
    $root = $folder ?: (getenv('SITE_DIR') ?: dirname(__DIR__, 2));
    define('OFFGRID_APP', true);
    chdir($root);
    require $root . '/includes/app.php';
    require $root . '/includes/scanner.php';
    require $root . '/includes/addurl.php';
    return $root;
}
