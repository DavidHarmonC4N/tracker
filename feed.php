<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';

common_headers();
header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');

function xml(string $value): string {
    return htmlspecialchars(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$entries = [];
try {
    if (config()['data_dir'] !== '') {
        $state = load_state(data_dir());
        $visible = [];
        foreach ($state['items'] as $item) {
            if (!$item['paused']) {
                $visible[$item['id']] = $item['url'];
            }
        }
        foreach (array_reverse($state['events'] ?? []) as $event) {
            if (isset($visible[$event['id']])) {
                $entries[] = $event + ['url'=>$visible[$event['id']]];
            }
            if (count($entries) >= 50) {
                break;
            }
        }
    }
} catch (Throwable $failure) {
    error_log('Offgrid feed: ' . $failure->getMessage());
}
$site = base_url() . 'index.html';
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"><channel>
<title>Offgrid Comms Tracker: stock and price changes</title>
<link><?= xml($site) ?></link>
<description>Back-in-stock alerts, sell-outs and price changes for MeshCore and Meshtastic radios from U.S. sellers.</description>
<?php foreach ($entries as $event): ?>
<item>
<title><?= xml(event_text($event)) ?></title>
<link><?= xml($event['url']) ?></link>
<guid isPermaLink="false"><?= xml($event['id'] . '-' . $event['t'] . '-' . $event['type']) ?></guid>
<pubDate><?= gmdate(DATE_RSS, strtotime($event['t'])) ?></pubDate>
<description><?= xml(event_text($event) . '. Confirm stock and the selected variant with the seller.') ?></description>
</item>
<?php endforeach; ?>
</channel></rss>
