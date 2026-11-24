<?php
declare(strict_types=1);

// Anonymous visitor counter. POST records a page view and returns the counts, GET only returns them.
define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/visits.php';
common_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

try {
    if (config()['data_dir'] === '') {
        echo '{"today":0,"total":0}';
        exit;
    }
    $counts = visits_touch(data_dir(), ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST',
        (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    echo json_encode(['today'=>$counts['today'],'total'=>$counts['total']]);
} catch (Throwable $failure) {
    error_log('Offgrid visits: ' . $failure->getMessage());
    http_response_code(503);
    echo '{"error":"Visitor counts are unavailable."}';
}
