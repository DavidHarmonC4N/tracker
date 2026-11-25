<?php
declare(strict_types=1);
// Antennas in the catalog: the starter list, the facts that may be shown, and what the admin form accepts.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

$seed = seed();
$antennas = array_values(array_filter($seed, static fn($i) => is_antenna_kind($i['kind'])));
check('the starter list has antennas', count($antennas) >= 20, (string)count($antennas));
check('every starter listing is checked automatically, none by hand', count(array_filter($seed, static fn($i) => ($i['mode'] ?? '') === 'manual')) === 0);
check('every antenna has a valid type, https link and no firmware claim', count(array_filter($antennas, static function ($i) {
    return isset(KINDS[$i['kind']]) && str_starts_with($i['url'], 'https://') && ($i['firmware'] ?? '') === 'both';
})) === count($antennas));
check('every antenna has a band, a connector and a clean set of facts', count(array_filter($antennas, static fn($i) => ($i['specs']['band'] ?? '') !== '' && ($i['specs']['connector'] ?? '') !== '' && clean_specs($i['specs']) === $i['specs'])) === count($antennas));
check('antennas arrive on existing installs once, so deleting one sticks', count(array_filter($antennas, static fn($i) => !empty($i['late']))) === count($antennas));
check('no listing id is used twice', count(array_unique(array_column($seed, 'id'))) === count($seed));
check('no antenna link is used twice', count(array_unique(array_map(static fn($i) => listing_url_key($i['url']), $antennas))) === count($antennas));
check('the manual-entry sellers are gone from the starter list', !array_intersect(['erod-v4', 'w5sat-v4', 'nostep-solar', 'mango-roof', 'digikey-t1000', 'mouser-t1000', 'peak-ultimate', 'enzo-v4', 'poly-v4'], array_column($seed, 'id')));

// the public catalog
$catalog = endpoint_result();
$byId = array_column($catalog['items'], null, 'id');
check('the catalog marks antennas and radios', $byId['rok-ant-alfa-10']['category'] === 'antenna' && $byId['rok-tdeck']['category'] === 'radio');
check('an antenna carries its facts and a radio carries none', $byId['rok-ant-alfa-10']['specs'] === ['band'=>'915', 'gain'=>'10 dBi', 'connector'=>'N male', 'length'=>'63 in'] && $byId['rok-tdeck']['specs'] === null);

// what may be shown
check('facts are cut to a safe set of characters', clean_specs(['band'=>'915', 'gain'=>'<b>5</b>', 'connector'=>'N male', 'length'=>str_repeat('9', 40)]) === ['band'=>'915', 'gain'=>'', 'connector'=>'N male', 'length'=>'']);
check('missing or odd facts become empty', clean_specs(null) === ['band'=>'', 'gain'=>'', 'connector'=>'', 'length'=>''] && clean_specs(['band'=>['x']])['band'] === '');

// the admin form
$post = ['url'=>'https://store.example-shop.com/products/yagi', 'firmware'=>'both', 'kind'=>'antenna_directional', 'role'=>'unspecified', 'warehouse'=>'', 'name'=>'Yagi', 'seller'=>'Shop', 'location'=>'', 'note'=>'',
    'spec_band'=>'915', 'spec_gain'=>'12 dBi', 'spec_connector'=>'N female', 'spec_length'=>''];
$row = listing_fields($post);
check('the form keeps antenna facts for an antenna type', $row['specs'] === ['band'=>'915', 'gain'=>'12 dBi', 'connector'=>'N female', 'length'=>'']);
check('a radio type keeps no antenna facts', !isset(listing_fields(['kind'=>'handheld'] + $post)['specs']));
$bad = false;
try { listing_fields(['spec_gain'=>'12 dBi <script>'] + $post); } catch (InvalidArgumentException) { $bad = true; }
check('odd characters in an antenna fact are refused', $bad);

// radio ports and what fits
$ports = json_decode((string)file_get_contents($site . '/includes/ports.json'), true);
check('every starter port is a known value and names a real listing', count(array_filter($ports, static fn($v, $k) => isset(ANTENNA_PORTS[$v]) && $v !== '' && isset($byId[$k]), ARRAY_FILTER_USE_BOTH)) === count($ports));
check('the catalog gives a radio its port and an antenna none', $byId['atlavox-m1']['antenna_port'] === 'sma' && $byId['rok-ant-alfa-10']['antenna_port'] === '' && $byId['rok-tdeck']['antenna_port'] === '');
check('a radio with the port set in Admin keeps it over the starter value', (static function () use ($seed) {
    $rows = with_roles([['id'=>'atlavox-m1', 'kind'=>'handheld', 'antenna_port'=>'n']]);
    return $rows[0]['antenna_port'];
})() === 'n');
$port = listing_fields(['antenna_port'=>'rp-sma'] + $post, 'unspecified');
check('the form keeps the antenna port for a radio type only', listing_fields(['kind'=>'handheld', 'antenna_port'=>'rp-sma'] + $post)['antenna_port'] === 'rp-sma' && !isset($port['antenna_port']));
$bad = false;
try { listing_fields(['kind'=>'handheld', 'antenna_port'=>'banana'] + $post); } catch (InvalidArgumentException) { $bad = true; }
check('an unknown antenna port is refused', $bad);

check('connectors are read the way sellers write them', antenna_connector_key('SMA male') === 'sma' && antenna_connector_key('SMA') === 'sma' && antenna_connector_key('RP-SMA male') === 'rp-sma' && antenna_connector_key('N male') === 'n' && antenna_connector_key('N-Type') === 'n' && antenna_connector_key('NMO') === 'nmo' && antenna_connector_key('U.FL to RP-SMA female') === 'cable' && antenna_connector_key('SMA male to N male') === 'cable' && antenna_connector_key('') === '');
check('RP-SMA is never read as SMA', antenna_connector_key('RP-SMA') === 'rp-sma');
foreach (['RP SMA', 'rp sma male', 'SMA-RP', 'SMA RP', 'sma rp male', 'Reverse Polarity SMA', 'reverse-polarity SMA male', 'RPSMA'] as $word) {
    check('"' . $word . '" is read as RP-SMA, not SMA', antenna_connector_key($word) === 'rp-sma');
}
check('plain SMA wordings stay SMA', antenna_connector_key('SMA') === 'sma' && antenna_connector_key('SMA male') === 'sma' && antenna_connector_key('SMA interface') === 'sma' && antenna_connector_key('SMA female') === 'sma');

require_once $site . '/includes/radio.php';
$groups = radio_groups($catalog['items']);
$byName = static function (string $id) use ($groups, $byId) { foreach ($groups as $g) { foreach ($g['offers'] as $o) { if ($o['id'] === $id) { return $g; } } } return null; };
$now = time();
$sma = radio_fitting_antennas($groups, $byName('atlavox-m1'), $now, 50);
check('an SMA radio is offered SMA antennas only, none for another connector', $sma && count(array_filter($sma, static fn($e) => antenna_connector_key($e['group']['specs']['connector']) !== 'sma')) === 0);
check('an SMA radio is not offered a 868-only or cable product', count(array_filter($sma, static fn($e) => $e['group']['kind'] === 'antenna_cable' || ($e['group']['specs']['band'] ?? '') === '868')) === 0);
check('an RP-SMA radio is offered RP-SMA antennas', ($rp = radio_fitting_antennas($groups, $byName('ameri-m5'), $now, 50)) && count($rp) >= 2 && count(array_filter($rp, static fn($e) => antenna_connector_key($e['group']['specs']['connector']) === 'rp-sma')) === count($rp));
check('a radio with a U.FL port is offered pigtail cables', ($ufl = radio_fitting_antennas($groups, $byName('rok-t096'), $now, 50)) && count($ufl) >= 1 && count(array_filter($ufl, static fn($e) => $e['group']['kind'] === 'antenna_cable')) === count($ufl));
check('a radio with no external port or an unknown port is offered nothing', radio_fitting_antennas($groups, $byName('rok-wismesh-tag'), $now) === [] && radio_fitting_antennas($groups, $byName('rok-tdeck'), $now) === []);
check('the list is capped', count(radio_fitting_antennas($groups, $byName('atlavox-m1'), $now, 3)) === 3);
$radios = antenna_fitting_radios($groups, $byName('atl-ant-gizont-rp'), $now, 50);
check('an RP-SMA antenna fits the RP-SMA radios, and only those', $radios && count(array_filter($radios, static fn($e) => $e['group']['antenna_port'] !== 'rp-sma')) === 0);
check('a cable, an NMO antenna, or a radio gets no fitting radios', antenna_fitting_radios($groups, $byName('rok-ant-pig-smn'), $now) === [] && antenna_fitting_radios($groups, $byName('rok-ant-wave'), $now) === [] && antenna_fitting_radios($groups, $byName('atlavox-m1'), $now) === []);

// the alert topic follows the lead listing the page shows, so a paused lead does not move it
$offers = static fn(array $pause) => array_map(static fn($w) => ['id'=>'t-' . $w, 'url'=>'https://shop.example.com/products/t1000e', 'warehouse'=>$w, 'paused'=>in_array($w, $pause, true), 'name'=>'T', 'seller'=>'S', 'kind'=>'tracker', 'firmware'=>'both', 'setup'=>'unknown'], ['us', 'eu', 'cn']);
$all = radio_topic_leads($offers([]));
check('with every listing live, the U.S. listing leads', $all['t-us'] === 't-us' && $all['t-eu'] === 't-us' && $all['t-cn'] === 't-us');
$paused = radio_topic_leads($offers(['us']));
check('when the lead is paused the next listing leads, for every listing that is still shown', $paused['t-eu'] === 't-eu' && $paused['t-cn'] === 't-eu' && !isset($paused['t-us']));
check('the same list gives the same answer whether or not paused rows are passed in', radio_topic_leads($offers(['us'])) === radio_lead_ids(array_values(array_filter($offers(['us']), static fn($i) => !$i['paused']))));
finish();
