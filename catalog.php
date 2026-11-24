<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';

common_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    echo json_encode(endpoint_result(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('Offgrid catalog: ' . $error->getMessage());
    http_response_code(503);
    echo '{"error":"The catalog is temporarily unavailable."}';
}
