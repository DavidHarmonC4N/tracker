'use strict';
// The Groups part of Admin: reached from the regular admin, listed and filtered, and add, edit, hide, restore and delete all work and
// show up (or disappear) on the public directory.
const { run } = require('./lib');

const PASSWORD = 'correct horse battery staple';
const SETUP_KEY = '0123456789abcdef0123456789abcdef0123';

run(async (t) => {
  const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
  const p = t.watch(await ctx.newPage());
  p.on('dialog', (dialog) => dialog.accept());
  const publicUrl = (path) => t.ADMIN.replace(/admin\.php$/, path);
  const names = async () => (await (await ctx.request.get(publicUrl('groups.php?q=Browser+Test'))).text()).match(/<h3>[^<]*Browser Test[^<]*<\/h3>/g) || [];

  // sign in (the first visit sets the password)
  await p.goto(t.ADMIN);
  if (await p.$('input[name="setup_key"]')) { await p.fill('input[name="setup_key"]', SETUP_KEY); await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="setup"]'); await p.waitForLoadState(); }
  if (await p.$('input[name="password"]')) { await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="login"]'); await p.waitForLoadState(); }

  // ---- reached from the regular admin
  const tabs = await p.$$eval('.admin-tabs a', (a) => a.map((x) => ({ text: x.textContent.trim(), current: x.getAttribute('aria-current') })));
  t.ok('the regular admin has tabs for Radios and antennas and for Groups', tabs.map((x) => x.text).join('|') === 'Radios and antennas|Groups' && tabs[0].current === 'page', JSON.stringify(tabs));
  await p.click('.admin-tabs a:has-text("Groups")'); await p.waitForLoadState();
  t.ok('the Groups tab opens the group list and is marked current', /view=groups/.test(p.url()) && (await p.getAttribute('.admin-tabs a:has-text("Groups")', 'aria-current')) === 'page' && /Group directory/.test(await p.textContent('#groups-heading')));
  t.ok('it is the same page: same sign-out button and header', (await p.$('button[value="logout"]')) !== null && /Catalog admin/.test(await p.textContent('h1')));
  const rows = await p.$$eval('#admin-list .admin-row', (r) => r.length);
  t.ok('all 234 groups from the research list are there', rows === 234, String(rows));
  t.ok('the summary says how many are shown', /234 groups shown on/.test(await p.textContent('#groups-heading + p')));
  t.ok('a signed-out visitor cannot see it', await (async () => { const anon = await t.browser.newContext(); const r = await anon.request.get(t.ADMIN + '?view=groups'); const body = await r.text(); return !/Group directory/.test(body) && /Sign in|Create administrator/.test(body); })());

  // ---- search and filters (the same behaviour as the radio list)
  await p.fill('#admin-search', 'texas');
  const txRows = await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.length);
  t.ok('searching by state narrows the list', txRows >= 21 && txRows < 234, String(txRows));
  await p.fill('#admin-search', '');
  await p.click('[data-filter="category"][data-value="meshcore"]');
  t.ok('the protocol filter shows only MeshCore groups', await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.length > 20 && r.every((x) => x.dataset.category === 'meshcore')));
  await p.click('[data-filter="category"][data-value=""]');
  await p.click('[data-filter="group"][data-value="unconfirmed"]');
  t.ok('"Website not confirmed" finds the 5 sites the research could not open', (await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.length)) === 5);
  await p.click('[data-filter="group"][data-value=""]');

  // ---- add a group, with the form refusing bad input first
  await p.click('a:has-text("Add group")'); await p.waitForLoadState();
  t.ok('the add form has every field and a box for each state and region', (await p.$$('.states-grid input[type="checkbox"]')).length === 53 && (await p.$$('#group-form input[name="url"]')).length === 1);
  await p.fill('input[name="name"]', 'Browser Test Mesh');
  await p.selectOption('select[name="protocol"]', 'meshcore');
  await p.fill('input[name="url"]', 'http://insecure.example.org/');
  await p.check('input[name="states[]"][value="Texas"]');
  await p.evaluate(() => { document.querySelector('input[name="url"]').type = 'text'; });
  await p.click('button[value="group_save"]'); await p.waitForLoadState();
  t.ok('a link that is not https is refused with a message, and the form keeps what was typed', /full https:\/\/ address/.test(await p.textContent('.notice')) && (await p.inputValue('input[name="name"]')) === 'Browser Test Mesh' && await p.isChecked('input[name="states[]"][value="Texas"]'));
  await p.fill('input[name="url"]', 'https://browsertest.example.org/');
  await p.fill('input[name="discord"]', 'https://discord.gg/browsertest');
  await p.fill('input[name="city"]', 'Testville');
  await p.click('button[value="group_save"]'); await p.waitForLoadState();
  t.ok('a good group is added, with a notice, and appears in the list', /Added Browser Test Mesh/.test(await p.textContent('.notice')) && /Browser Test Mesh/.test(await p.textContent('#admin-list')) && (await p.$$eval('#admin-list .admin-row', (r) => r.length)) === 235);
  t.ok('it is marked as added', await p.$$eval('#admin-list .admin-row', (r) => r.some((x) => /Browser Test Mesh/.test(x.textContent) && x.dataset.group === 'added')));
  t.ok('it shows on the public directory, on the Texas page and in search', (await names()).length === 1 && /Browser Test Mesh/.test(await (await ctx.request.get(publicUrl('groups.php?state=texas'))).text()));
  t.ok('and the sitemap still lists every state page', ((await (await ctx.request.get(publicUrl('sitemap.php'))).text()).match(/groups\.php\?state=/g) || []).length === 53);

  // ---- a duplicate is refused
  await p.click('a:has-text("Add group")'); await p.waitForLoadState();
  await p.fill('input[name="name"]', 'Browser Test Again'); await p.fill('input[name="url"]', 'https://www.browsertest.example.org');
  await p.check('input[name="states[]"][value="Ohio"]');
  await p.click('button[value="group_save"]'); await p.waitForLoadState();
  t.ok('the same main link cannot be listed twice', /already listed as "Browser Test Mesh"/.test(await p.textContent('.notice')));

  // ---- edit it
  await p.goto(t.ADMIN + '?view=groups');
  await p.fill('#admin-search', 'browser test');
  await p.click('#admin-list .admin-row:not([hidden]) a:has-text("Edit")'); await p.waitForLoadState();
  t.ok('the edit form opens with the saved values', (await p.inputValue('input[name="name"]')) === 'Browser Test Mesh' && (await p.inputValue('select[name="protocol"]')) === 'meshcore' && await p.isChecked('input[name="states[]"][value="Texas"]'));
  await p.fill('input[name="name"]', 'Browser Test Mesh Renamed');
  await p.click('button[value="group_save"]'); await p.waitForLoadState();
  t.ok('saving the edit shows it on the public page', /Saved Browser Test Mesh Renamed/.test(await p.textContent('.notice')) && (await names()).join('').includes('Renamed'));

  // ---- hide and show it
  await p.fill('#admin-search', 'browser test');
  await p.click('#admin-list .admin-row:not([hidden]) a:has-text("Edit")'); await p.waitForLoadState();
  await p.click('button[value="group_hide"]'); await p.waitForLoadState();
  t.ok('hiding says so and takes it off the public directory', /Hid Browser Test Mesh Renamed/.test(await p.textContent('.notice')) && (await names()).length === 0);
  t.ok('a hidden group is still in the admin list, marked Hidden', /Hidden from the public directory/.test(await p.textContent('.editor-actions')));
  await p.click('button[value="group_show"]'); await p.waitForLoadState();
  t.ok('showing it brings it back', (await names()).length === 1);

  // ---- a group from the research list: edit it, then restore it
  await p.goto(t.ADMIN + '?view=groups');
  await p.fill('#admin-search', 'alabama mesh');
  await p.click('#admin-list .admin-row:not([hidden]) a:has-text("Edit")'); await p.waitForLoadState();
  const original = await p.inputValue('input[name="name"]');
  t.ok('a group from the list cannot be deleted here, only hidden', (await p.$('button[value="group_delete"]')) === null && (await p.$('button[value="group_hide"]')) !== null);
  await p.fill('input[name="name"]', original + ' EDITED');
  await p.click('button[value="group_save"]'); await p.waitForLoadState();
  t.ok('the edit shows on the public page', (await (await ctx.request.get(publicUrl('groups.php?state=alabama'))).text()).includes(original + ' EDITED'));
  await p.fill('#admin-search', 'alabama mesh edited');
  await p.click('#admin-list .admin-row:not([hidden]) a:has-text("Edit")'); await p.waitForLoadState();
  await p.click('button[value="group_restore"]'); await p.waitForLoadState();
  t.ok('Restore original puts the listing back', /Restored the original/.test(await p.textContent('.notice')) && !(await (await ctx.request.get(publicUrl('groups.php?state=alabama'))).text()).includes('EDITED'));

  // ---- delete the one added here
  await p.goto(t.ADMIN + '?view=groups');
  await p.fill('#admin-search', 'browser test');
  await p.click('#admin-list .admin-row:not([hidden]) a:has-text("Edit")'); await p.waitForLoadState();
  await p.click('button[value="group_delete"]'); await p.waitForLoadState();
  t.ok('deleting a group added here removes it', /Deleted the group/.test(await p.textContent('.notice')) && (await p.$$eval('#admin-list .admin-row', (r) => r.length)) === 234 && (await names()).length === 0);

  // ---- Cancel on the delete prompt keeps the group
  await p.goto(t.ADMIN + '?view=groups&add=1');
  await p.fill('input[name="name"]', 'Browser Test Keep'); await p.fill('input[name="url"]', 'https://browsertestkeep.example.org/');
  await p.check('input[name="states[]"][value="Utah"]');
  await p.click('button[value="group_save"]'); await p.waitForLoadState();
  await p.fill('#admin-search', 'browser test keep');
  await p.click('#admin-list .admin-row:not([hidden]) a:has-text("Edit")'); await p.waitForLoadState();
  p.removeAllListeners('dialog');
  p.once('dialog', (dialog) => dialog.dismiss());
  await p.click('button[value="group_delete"]'); await p.waitForLoadState();
  t.ok('Cancel on the delete prompt keeps the group', (await p.$('input[name="name"]')) !== null && (await p.inputValue('input[name="name"]')) === 'Browser Test Keep');
  p.on('dialog', (dialog) => dialog.accept());
  await p.click('button[value="group_delete"]'); await p.waitForLoadState();

  // ---- the old forms and the other tab still work
  await p.goto(t.ADMIN);
  t.ok('the Radios and antennas tab still shows the listing list', (await p.$$('#admin-list .admin-row')).length > 80 && (await p.$$('[data-filter="category"][data-value="antenna"]')).length === 1);
  const forged = await ctx.request.post(t.ADMIN, { form: { action: 'group_hide', id: 'M001', token: 'wrong' }, failOnStatusCode: false });
  t.ok('a group change with the wrong form token is refused', !/Hid /.test(await forged.text()) && ((await (await ctx.request.get(publicUrl('groups.php?state=alabama'))).text()).includes('Alabama Mesh')));
});
