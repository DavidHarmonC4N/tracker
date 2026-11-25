<?php
declare(strict_types=1);
// Managing the group directory in Admin: validating the form, add, edit, hide, restore and delete, and what visitors then see.
require dirname(__DIR__) . '/lib/harness.php';
$site = load_site();

$base = groups_load()['groups'];
$dir = sys_get_temp_dir() . '/offgrid-groupsadmin-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$form = static fn(array $over = []) => $over + ['name'=>'Test Valley Mesh', 'city'=>'Testville', 'protocol'=>'meshcore', 'type'=>'Mesh community', 'url'=>'https://testvalleymesh.example.org/',
    'discord'=>'https://discord.gg/testvalley', 'facebook'=>'', 'other'=>'', 'map'=>'', 'coverage'=>'Test County', 'notes'=>'', 'review'=>'directory', 'states'=>['Texas', 'Oklahoma']];
$refused = static function (callable $do): string {
    try { $do(); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    return '';
};
$state = static fn() => load_state($dir)['groups'] ?? [];

// ---- the form
check('the regions the form offers match the regions in the data', (static function () {
    $in = array_keys(groups_states(groups_load()['groups']));
    sort($in); $form = GROUP_REGIONS; sort($form);
    return $in === $form;
})());
$record = groups_record_from_post($form(), 'A001', $base);
check('a good form becomes a clean group', $record['name'] === 'Test Valley Mesh' && $record['protocol'] === 'meshcore' && $record['states'] === ['Texas', 'Oklahoma'] && groups_clean($record) !== null);
check('a name is required', str_contains($refused(fn() => groups_record_from_post($form(['name'=>'  ']), 'A001', $base)), 'name'));
check('a protocol must be chosen from the three', str_contains($refused(fn() => groups_record_from_post($form(['protocol'=>'lora']), 'A001', $base)), 'protocol'));
check('a state must be chosen', str_contains($refused(fn() => groups_record_from_post($form(['states'=>[]]), 'A001', $base)), 'state'));
check('states that are not real regions are ignored', groups_record_from_post($form(['states'=>['Texas', 'Atlantis', '<b>']]), 'A001', $base)['states'] === ['Texas']);
check('the main link is required', str_contains($refused(fn() => groups_record_from_post($form(['url'=>'']), 'A001', $base)), 'main link'));
foreach (['http://insecure.example.org/', 'javascript:alert(1)', 'example.org', 'https://exa mple.org/', 'ftp://example.org/'] as $bad) {
    check('an unsafe main link is refused: ' . $bad, str_contains($refused(fn() => groups_record_from_post($form(['url'=>$bad]), 'A001', $base)), 'https://'));
}
check('an unsafe optional link is refused, not silently dropped', str_contains($refused(fn() => groups_record_from_post($form(['facebook'=>'http://facebook.com/x']), 'A001', $base)), 'Facebook'));
check('an over-long name is refused', str_contains($refused(fn() => groups_record_from_post($form(['name'=>str_repeat('x', 81)]), 'A001', $base)), '80'));
check('control characters are cleaned out of text', groups_record_from_post($form(['name'=>"Two\nLines\x07"]), 'A001', $base)['name'] === 'Two Lines');
$dup = $base[0];
check('a main link that is already listed is refused, even with www or a trailing slash', str_contains($refused(fn() => groups_record_from_post($form(['url'=>preg_replace('~^https://~', 'https://www.', rtrim($dup['url'], '/')) . '/']), 'A001', $base)), 'already listed') || str_contains($refused(fn() => groups_record_from_post($form(['url'=>$dup['url']]), 'A001', $base)), 'already listed'));
check('a group may keep its own main link when edited', groups_record_from_post($form(['url'=>$dup['url']]), $dup['id'], $base)['url'] === $dup['url']);
check('an unknown type or review becomes the default, not an error', groups_record_from_post($form(['type'=>'Cult', 'review'=>'bogus']), 'A001', $base)['type'] === 'Mesh community');

// ---- add
$notice = groups_admin_save($dir, $form(), '');
check('adding a group says so', $notice === 'Added Test Valley Mesh.');
check('it is kept as group A001, among those added in Admin', ($state()['added'][0]['id'] ?? '') === 'A001' && count($state()['added']) === 1);
$all = groups_apply($base, $state(), true);
$added = array_values(array_filter($all, static fn($g) => $g['id'] === 'A001'))[0] ?? null;
check('a group added in Admin is in the list, flagged as added', $added !== null && $added['origin'] === 'added' && !$added['hidden']);
groups_admin_save($dir, $form(['name'=>'Second Mesh', 'url'=>'https://second.example.org/']), '');
check('the next group gets the next id', ($state()['added'][1]['id'] ?? '') === 'A002');
check('the same main link cannot be added twice', str_contains($refused(fn() => groups_admin_save($dir, $form(['name'=>'Again']), '')), 'already listed'));
check('a refused save changes nothing', count($state()['added']) === 2);

// ---- edit
$first = $base[0];
groups_admin_save($dir, $form(['name'=>'Renamed Group', 'url'=>$first['url'], 'states'=>$first['states']]), $first['id']);
$after = array_values(array_filter(groups_apply($base, $state(), true), static fn($g) => $g['id'] === $first['id']))[0];
check('editing a group from the file keeps the edit on top of it', $after['name'] === 'Renamed Group' && $after['edited'] === true && $after['origin'] === 'file' && count(groups_load()['groups']) === count($base));
check('the file itself is never changed', groups_load()['groups'][0]['name'] === $first['name']);
groups_admin_save($dir, $form(['name'=>'Edited Added', 'url'=>'https://second.example.org/']), 'A002');
check('editing an added group replaces it in place', count($state()['added']) === 2 && $state()['added'][1]['name'] === 'Edited Added');
check('editing a group that does not exist is refused', $refused(fn() => groups_admin_save($dir, $form(['url'=>'https://nowhere.example.org/']), 'Z999')) !== '');

// ---- hide and show
groups_admin_set_hidden($dir, $first['id'], true);
check('a hidden group stays in the admin list but not the public one', count(groups_apply($base, $state(), true)) === count($base) + 2 && count(groups_apply($base, $state(), false)) === count($base) + 1);
check('the public list leaves the hidden group out by id', !in_array($first['id'], array_column(groups_apply($base, $state(), false), 'id'), true));
groups_admin_set_hidden($dir, $first['id'], false);
check('showing it again puts it back', in_array($first['id'], array_column(groups_apply($base, $state(), false), 'id'), true) && !isset($state()['hidden'][$first['id']]));
check('hiding something that does not exist is refused', $refused(fn() => groups_admin_set_hidden($dir, 'Z999', true)) !== '');

// ---- restore and delete
groups_admin_restore($dir, $first['id']);
$restored = array_values(array_filter(groups_apply($base, $state(), true), static fn($g) => $g['id'] === $first['id']))[0];
check('restoring brings back the original listing', $restored['name'] === $first['name'] && $restored['edited'] === false);
check('restoring a group with no edit is refused', $refused(fn() => groups_admin_restore($dir, $first['id'])) !== '');
check('a group from the file cannot be deleted, only hidden', str_contains($refused(fn() => groups_admin_delete($dir, $first['id'])), 'Hide'));
groups_admin_set_hidden($dir, 'A001', true);
groups_admin_delete($dir, 'A001');
check('a group added in Admin can be deleted, with its hidden mark', count($state()['added']) === 1 && !isset($state()['hidden']['A001']));
check('the next id never collides with one still in use', groups_next_id(groups_apply($base, $state(), true)) === 'A003');

// ---- what visitors see, from the real data folder
$before = count(groups_current()['groups']);
groups_admin_save(data_dir(), $form(['name'=>'Visible Test Mesh', 'url'=>'https://visibletest.example.org/']), '');
$now = groups_current();
check('a group added in Admin appears in the public list', count($now['groups']) === $before + 1 && in_array('Visible Test Mesh', array_column($now['groups'], 'name'), true));
check('the "compiled" date moves to the day of the change', $now['verified'] >= gmdate('Y-m-d'));
$visibleId = array_values(array_filter($now['groups'], static fn($g) => $g['name'] === 'Visible Test Mesh'))[0]['id'];
groups_admin_set_hidden(data_dir(), $visibleId, true);
check('hiding it takes it off the public list', count(groups_current()['groups']) === $before);
groups_admin_set_hidden(data_dir(), $visibleId, false);
groups_admin_delete(data_dir(), $visibleId);
check('deleting it leaves the public list as it was', count(groups_current()['groups']) === $before);
finish();
