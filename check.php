<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/scanner.php';

try {
    if (PHP_VERSION_ID < 80300 || !extension_loaded('curl') || !extension_loaded('dom')) {
        throw new RuntimeException('Use PHP 8.3 or newer with cURL and DOM enabled.');
    }
    $count = run_checks(data_dir());
    echo 'Checked ' . $count . " listing(s).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Offgrid checker: ' . $error->getMessage() . "\n");
    exit(1);
}
