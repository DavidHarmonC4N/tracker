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
finish();
