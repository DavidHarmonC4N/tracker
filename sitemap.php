<?php
declare(strict_types=1);

// The sitemap (referenced from robots.txt). Built from the live data so each page's lastmod is its latest real change.

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';

common_headers();
$base = base_url();
if ($base === '' || is_staging()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$latest = 0;
$radios = [];
try {
    $items = endpoint_result()['items'];
    foreach ($items as $item) {
        $latest = max($latest, (int)strtotime((string)($item['result']['observed_at'] ?? '')));
    }
    foreach (radio_groups($items) as $group) {
        $seen = 0;
        foreach ($group['offers'] as $offer) {
            $seen = max($seen, (int)strtotime((string)($offer['result']['observed_at'] ?? '')));
        }
        $radios['radio.php?r=' . $group['ref']] = $seen;
    }
} catch (Throwable $error) {
    error_log('Offgrid sitemap: ' . $error->getMessage());
}
$file = static fn(string $name): int => (int)(@filemtime(__DIR__ . '/' . $name) ?: 0);
$groupData = groups_load();
$groupMod = $groupData['verified'] !== '' ? (int)strtotime($groupData['verified']) : $file('groups.php');
$groupPages = ['groups.php' => $groupMod];
foreach (groups_states($groupData['groups']) as $groupState) {
    $groupPages['groups.php?state=' . $groupState['slug']] = $groupMod;
}
// Pages built from live data change when the data does; static pages change when their file does.
$pages = [
    ''            => max($latest, $file('index.html')),
    'radios.php'  => max($latest, $file('radios.php')),
    'prices.html' => max($latest, $file('prices.html')),
    'alerts.html' => $file('alerts.html'),
] + $groupPages + $radios;
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $path => $modified) {
    echo '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
        . ($modified ? '<lastmod>' . gmdate('c', $modified) . '</lastmod>' : '') . '</url>' . "\n";
}
echo '</urlset>' . "\n";
