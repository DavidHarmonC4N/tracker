<?php
declare(strict_types=1);
// The group directory: link safety, cleaning the data, states and the map, filters, and the buttons on a card.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

// ---- what counts as a safe link
foreach (['https://example.org/', 'https://discord.gg/abc123', 'https://www.facebook.com/groups/x?y=1'] as $ok) {
    check('a normal https link is kept: ' . $ok, groups_url($ok) === $ok);
}
foreach (['http://example.org/', 'javascript:alert(1)', 'data:text/html,hi', '//example.org', 'https://', 'https://localhost/', 'https://192.168.0.1/', 'https://user:pw@example.org/', 'https://example.org/a b', 'https://example.org/"x', 'https://example.org/<x>', "https://example.org/\n", '', 'ftp://example.org/'] as $bad) {
    check('an unsafe link is dropped: ' . json_encode($bad), groups_url($bad) === '');
}
check('a link that is not text is dropped', groups_url(null) === '' && groups_url(['https://example.org/']) === '' && groups_url(str_repeat('a', 600)) === '');

// ---- cleaning the data
$dir = sys_get_temp_dir() . '/offgrid-groups-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$bad = $dir . '/groups.json';
file_put_contents($bad, json_encode(['verified'=>'2026-11-25', 'groups'=>[
    ['id'=>'G1', 'name'=>"Good\x07 Group", 'protocol'=>'both', 'url'=>'https://good.example.org/', 'discord'=>'javascript:x', 'facebook'=>'https://www.facebook.com/g', 'states'=>['Texas'], 'review'=>'weird'],
    ['id'=>'G2', 'name'=>'No link', 'protocol'=>'meshcore', 'url'=>'http://insecure.example.org/', 'states'=>['Texas']],
    ['id'=>'G3', 'name'=>'No state', 'protocol'=>'meshcore', 'url'=>'https://ok.example.org/', 'states'=>[]],
    ['id'=>'G4', 'name'=>'Odd protocol', 'protocol'=>'other', 'url'=>'https://ok.example.org/', 'states'=>['Texas']],
    ['id'=>'bad id!', 'name'=>'Odd id', 'protocol'=>'both', 'url'=>'https://ok.example.org/', 'states'=>['Texas']],
    'junk', ['name'=>'No id'],
]]));
$loaded = groups_load($bad);
check('rows with an unsafe main link, no state, an unknown protocol or a bad id are dropped', count($loaded['groups']) === 1 && $loaded['groups'][0]['id'] === 'G1');
check('control characters leave names, and an unsafe extra link is blanked', $loaded['groups'][0]['name'] === 'Good Group' && $loaded['groups'][0]['discord'] === '' && $loaded['groups'][0]['facebook'] === 'https://www.facebook.com/g');
check('an unknown review value becomes a plain directory listing', $loaded['groups'][0]['review'] === 'directory');
check('a missing or broken file gives an empty list, not an error', groups_load($dir . '/missing.json')['groups'] === [] && groups_load($dir . '/missing.json')['verified'] === '');

// ---- the real data
$data = groups_load();
$all = $data['groups'];
check('the list has the 234 groups from the research sheet', count($all) === 234, (string)count($all));
check('the date it was compiled is known', $data['verified'] === '2026-11-25');
check('no group was dropped by the cleaning (every row passed)', count(json_decode((string)file_get_contents($site . '/includes/groups.json'), true)['groups']) === count($all));
check('every group has a name, an https main link and at least one state', count(array_filter($all, static fn($g) => $g['name'] !== '' && str_starts_with($g['url'], 'https://') && $g['states'])) === count($all));
check('ids are unique', count(array_unique(array_column($all, 'id'))) === count($all));
$urls = [];
foreach ($all as $g) { foreach (['url', 'discord', 'facebook', 'other', 'map'] as $k) { if ($g[$k] !== '') { $urls[] = $g[$k]; } } }
check('every outside link in the data is https', count($urls) > 400 && count(array_filter($urls, static fn($u) => !str_starts_with($u, 'https://'))) === 0, count($urls) . ' links');
check('every protocol is one of the three', count(array_filter($all, static fn($g) => isset(GROUP_PROTOCOLS[$g['protocol']]))) === count($all));
check('members-only Discord links are flagged', count(array_filter($all, static fn($g) => $g['discord_members_only'])) >= 4);
check('the sites the research could not open are marked', count(array_filter($all, static fn($g) => $g['review'] === 'unconfirmed')) === 5);

// ---- states and the map
$states = groups_states($all);
check('there are 53 states and regions, and the listings add up', count($states) === 53 && array_sum(array_column($states, 'count')) === 274, count($states) . ' / ' . array_sum(array_column($states, 'count')));
check('Texas has 21 groups', $states['Texas']['count'] === 21 && $states['Texas']['slug'] === 'texas' && $states['Texas']['code'] === 'TX');
check('slugs are safe, unique and readable', count(array_unique(array_column($states, 'slug'))) === 53 && count(array_filter($states, static fn($s) => preg_match('/^[a-z0-9-]+$/D', $s['slug']))) === 53 && isset($states['District of Columbia']) && $states['District of Columbia']['slug'] === 'district-of-columbia');
check('every state has a tile, and every tile is a state', count(GROUP_TILES) === 51 && count(array_diff(array_values(GROUP_STATE_CODES), array_keys(GROUP_TILES))) === 0 && count(array_diff(array_keys(GROUP_TILES), array_values(GROUP_STATE_CODES))) === 0);
check('no two tiles share a place', count(array_unique(array_map(static fn($p) => $p[0] . ',' . $p[1], GROUP_TILES))) === count(GROUP_TILES));
check('all tiles sit on the 12 by 8 grid', count(array_filter(GROUP_TILES, static fn($p) => $p[0] >= 0 && $p[0] < 8 && $p[1] >= 0 && $p[1] < 12)) === 51);
$svg = groups_map_svg($states, 'texas');
check('the map links every state that has groups and marks the one on show', substr_count($svg, '<a href="groups.php?state=') === 51 && str_contains($svg, 'groups.php?state=texas#groups') && substr_count($svg, 'tile l') === 51 && substr_count($svg, ' on"') === 1);
check('the map is labelled for screen readers', str_contains($svg, 'role="group"') && str_contains($svg, '<title id="map-title">') && str_contains($svg, 'Texas: 21 groups'));
check('a state with one group says "group", not "groups"', str_contains($svg, 'Alaska: 1 group<') && str_contains($svg, 'Wyoming: 2 groups<'));
check('a state with no groups is a tile that is not a link', substr_count(groups_map_svg([], ''), '<a href') === 0 && str_contains(groups_map_svg([], ''), 'no groups listed'));

// ---- filters
$tx = groups_in($all, 'Texas');
check('a state shows the groups that serve it, including ones that serve several states', count($tx) === 21 && count(groups_in($all, '')) === 234 && count(groups_in($all, 'Nowhere')) === 0);
$meshcore = groups_filter($all, 'meshcore', false, '');
check('the MeshCore filter includes MeshCore-only groups and groups that use both', count(array_filter($meshcore, static fn($g) => $g['protocol'] === 'meshcore')) > 30 && count(array_filter($meshcore, static fn($g) => $g['protocol'] === 'both')) > 30);
check('the MeshCore filter drops Meshtastic-only groups', count(array_filter($meshcore, static fn($g) => $g['protocol'] === 'meshtastic')) === 0);
check('the Meshtastic filter drops MeshCore-only groups', count(array_filter(groups_filter($all, 'meshtastic', false, ''), static fn($g) => $g['protocol'] === 'meshcore')) === 0);
$withDiscord = groups_filter($all, '', true, '');
check('"has a Discord" keeps only groups with a joinable one', count($withDiscord) > 100 && count($withDiscord) < 234 && count(array_filter($withDiscord, static fn($g) => $g['discord'] === '' && !str_contains($g['url'], 'discord.'))) === 0);
check('search matches name, city, coverage and state, and needs every word', count(groups_filter($all, '', false, 'texas')) >= 21 && count(groups_filter($all, '', false, 'TEXAS mesh')) <= count(groups_filter($all, '', false, 'texas')) && groups_filter($all, '', false, 'zzzzqqqq') === []);

// ---- the buttons on a card
$one = ['url'=>'https://club.example.org/', 'discord'=>'https://discord.gg/abc', 'discord_members_only'=>false, 'facebook'=>'https://www.facebook.com/groups/x', 'other'=>'https://www.reddit.com/r/x', 'map'=>'https://map.example.org/'];
$links = groups_links($one);
check('the main link comes first, as the primary button, then the rest in order', array_column($links, 0) === ['Website', 'Discord', 'Facebook', 'Reddit', 'Map'] && $links[0][2] === true && count(array_filter($links, static fn($l) => $l[2])) === 1);
check('a link is never listed twice', count(groups_links(['url'=>'https://discord.gg/abc', 'discord'=>'https://discord.gg/abc'] + $one)) === 4 && groups_links(['url'=>'https://discord.gg/abc', 'discord'=>'https://discord.gg/abc'] + $one)[0][0] === 'Discord');
check('a members-only Discord is not offered as a link', !in_array('Discord', array_column(groups_links(['discord_members_only'=>true] + $one), 0), true));
check('a link is named by where it goes', groups_link_label('https://www.facebook.com/x', 'W') === 'Facebook' && groups_link_label('https://groups.io/g/x', 'W') === 'Groups.io' && groups_link_label('https://t.me/x', 'W') === 'Telegram' && groups_link_label('https://club.example.org', 'Website') === 'Website');
check('the caution says what was asked for', str_contains(GROUPS_DISCLAIMER, 'automated search') && str_contains(GROUPS_DISCLAIMER, 'not personally checked') && str_contains(GROUPS_DISCLAIMER, 'not responsible') && str_contains(GROUPS_DISCLAIMER, 'do not endorse'));
finish();
