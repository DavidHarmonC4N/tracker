<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/dispatch.php';

common_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex'); // a data feed for the page, not a page to index
try {
    echo json_encode(endpoint_result(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    // Send the response first, then (rarely) ask GitHub to start the U.S. runner.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    try {
        if (config()['data_dir'] !== '') {
            maybe_dispatch_runner(data_dir());
            maybe_check_health(data_dir());
        }
    } catch (Throwable $trigger) {
        error_log('Offgrid trigger: ' . $trigger->getMessage());
    }
} catch (Throwable $error) {
    error_log('Offgrid catalog: ' . $error->getMessage());
    http_response_code(503);
    echo '{"error":"The catalog is temporarily unavailable."}';
}
