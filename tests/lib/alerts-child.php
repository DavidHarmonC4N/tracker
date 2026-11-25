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
    case 'ntfy-test':
        $out['results'] = notify_send('Test alert', 'This is a test', ['type'=>'test', 'url'=>'https://mesh.example.org/index.html']);
        break;
    case 'ntfy-flush':
    case 'ntfy-flush-muted':
        if ($scenario === 'ntfy-flush-muted') {
            change_state(data_dir(), static function (&$state) { $state['alerts_muted_until'] = time() + 3600; });
        }
        $back = ['t'=>iso_now(), 'id'=>'spec-mini', 'name'=>'MiniTrekker MKII', 'seller'=>'SpecFive', 'type'=>'back_in_stock', 'status'=>'in_stock'];
        $drop = ['t'=>iso_now(), 'id'=>'rok-tdeck', 'name'=>'LILYGO T-Deck', 'seller'=>'Rokland', 'type'=>'price_drop', 'from'=>7495, 'to'=>6797];
        $third = ['t'=>iso_now(), 'id'=>'spec-kilo', 'name'=>'Trekker Kilo', 'seller'=>'SpecFive', 'type'=>'price_drop', 'from'=>9000, 'to'=>8500];
        foreach ([$back, $drop, $third] as $event) {
            notify(ucfirst(event_text($event)), event_text($event) . "\nhttps://shop.example/" . $event['id'], ['url'=>'https://shop.example/' . $event['id'], 'type'=>$event['type']]);
            notify_public_event($event, 'https://shop.example/' . $event['id']);
        }
        echo json_encode($out) . "\n";
        return;
    case 'health-muted':
        change_state(data_dir(), static function (&$state) { $state['alerts_muted_until'] = time() + 3600; });
        notify('Back in stock: Radio', 'stock body', ['type'=>'back_in_stock']);
        notify('Checker stalled', 'health body', ['type'=>'health']);
        echo json_encode($out) . "\n";
        return;
    case 'ntfy-one':
        $drop = ['t'=>iso_now(), 'id'=>'rok-tdeck', 'name'=>'LILYGO T-Deck', 'seller'=>'Rokland', 'type'=>'price_drop', 'from'=>7495, 'to'=>6797];
        notify(ucfirst(event_text($drop)), event_text($drop), ['url'=>'https://shop.example/t-deck', 'type'=>'price_drop']);
        notify_public_event($drop, 'https://shop.example/t-deck');
        echo json_encode($out) . "\n";
        return;
    case 'ntfy-catalog':
        $catalog = endpoint_result();
        $out['public_topic'] = $catalog['public_topic'];
        $out['topics'] = array_column($catalog['items'], 'topic', 'id');
        break;
    case 'queue-one':
        notify('Only alert', 'only body');
        echo json_encode($out) . "\n";
        return;
}
echo json_encode($out) . "\n";
