<?php
declare(strict_types=1);
// Helper for tests/php/alerts.test.php. Runs one alert scenario in its own process (the config is read once per process)
// and prints a single JSON line. Usage: SITE_DIR=<site copy> php alerts-child.php <scenario>
require __DIR__ . '/harness.php';
load_site();

$scenario = $argv[1] ?? '';
$out = ['from'=>notify_from_address()];
switch ($scenario) {
    case 'send':
        $out['results'] = notify_send('Subject with ümlaut', "Body line one\nBody line two");
        break;
    case 'queue':
        notify('First alert', 'first body https://example.com/1');
        notify('Second alert', 'second body https://example.com/2');
        notify('Third alert', 'third body https://example.com/3');
        echo json_encode($out) . "\n"; // the digest is sent when this process ends
        return;
    case 'queue-one':
        notify('Only alert', 'only body');
        echo json_encode($out) . "\n";
        return;
}
echo json_encode($out) . "\n";
