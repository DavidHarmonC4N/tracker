<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';

common_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Robots-Tag: noindex'); // a data feed for the page, not a page to index

/**
 * Public price history. ?scope=instock returns every available listing, ?ids=a,b,c returns just those (up to 60).
 * Each item carries its recorded points [unix time, price in cents or null, status], oldest first.
 */
try {
    $configured = config()['data_dir'] !== '';
    $state = $configured ? load_state(data_dir()) : ['items'=>seed(),'history'=>[]];
    $wanted = null;
    if (isset($_GET['ids']) && is_string($_GET['ids'])) {
        $wanted = [];
        foreach (array_slice(explode(',', $_GET['ids']), 0, 60) as $id) {
            if (preg_match('/^[a-z0-9_-]{1,60}$/', $id)) {
                $wanted[$id] = true;
            }
        }
    }
    $items = [];
    foreach ($state['items'] as $item) {
        if ($item['paused'] || ($wanted !== null && !isset($wanted[$item['id']]))) {
            continue;
        }
        $status = $item['result']['status'] ?? 'unknown';
        if ($wanted === null && !in_array($status, ['in_stock', 'orderable'], true)) {
            continue;
        }
        $points = $state['history'][$item['id']] ?? [];
        $summary = history_summary($points);
        $items[] = ['id'=>$item['id'], 'name'=>$item['name'], 'seller'=>$item['seller'], 'url'=>$item['url'],
            'status'=>$status, 'price'=>is_int($item['result']['price'] ?? null) ? $item['result']['price'] : null,
            'observed_at'=>$item['result']['observed_at'] ?? null,
            'low'=>$summary['low'], 'high'=>$summary['high'], 'points'=>$points];
    }
    echo json_encode(['now'=>time(), 'staging'=>is_staging(), 'public_topic'=>ntfy_public_base(), 'items'=>$items], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('Offgrid history: ' . $error->getMessage());
    http_response_code(503);
    echo '{"error":"Price history is temporarily unavailable."}';
}
