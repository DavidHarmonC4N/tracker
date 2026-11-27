'use strict';
// Fixes from the code review: the ingest door, the Cancel side of a confirm prompt, and catalog behaviours that were wrong.
const { run } = require('./lib');

const PASSWORD = 'correct horse battery staple';
const TOKEN = '0123456789abcdef0123456789abcdef0123abcd';
const SETUP_KEY = '0123456789abcdef0123456789abcdef0123';

run(async (t) => {
  // ---- ingest.php: who can talk to it, and what it refuses
  const ingest = t.ADMIN.replace(/admin\.php$/, 'ingest.php');
  const anon = await t.browser.newContext();
  const post = (path, body, headers = {}) => anon.request.post(ingest + path, { data: body, headers: { 'Content-Type': 'application/json', ...headers }, failOnStatusCode: false });
  const get = await anon.request.get(ingest + '?action=jobs', { failOnStatusCode: false });
  t.ok('ingest refuses a plain GET', get.status() === 405 || get.status() === 403, String(get.status()));
  const none = await post('?action=jobs', {});
  t.ok('ingest refuses a request with no token', none.status() === 403, String(none.status()));
  const wrong = await post('?action=jobs', {}, { 'X-Offgrid-Token': 'x'.repeat(40) });
  t.ok('ingest refuses a wrong token', wrong.status() === 403 && /Access denied/.test(await wrong.text()));
  t.ok('a refusal gives no hint about the token', !/token|secret|0123456789/i.test(await wrong.text()));
  const good = await post('?action=jobs', { limit: 5 }, { 'X-Offgrid-Token': TOKEN });
  const body = await good.json().catch(() => ({}));
  t.ok('the right token gets a job list', good.status() === 200 && Array.isArray(body.jobs), good.status() + ' ' + JSON.stringify(body).slice(0, 80));
  const unknown = await post('?action=nonsense', {}, { 'X-Offgrid-Token': TOKEN });
  t.ok('an unknown action is a 400', unknown.status() === 400);
  const huge = await post('?action=results', { results: [], pad: 'x'.repeat(300000) }, { 'X-Offgrid-Token': TOKEN });
  t.ok('a request over 256 KB is refused', huge.status() === 413, String(huge.status()));
  const junk = await post('?action=results', { results: [{ id: ['x'], ok: true }, 'junk', { id: 'nope', fingerprint: 'zz', ok: true, result: { status: ['in_stock'] } }] }, { 'X-Offgrid-Token': TOKEN });
  const summary = await junk.json().catch(() => ({}));
  t.ok('odd results are ignored, not a server error', junk.status() === 200 && summary.stored === 0 && summary.ignored === 3, JSON.stringify(summary));

  // ---- Cancel on a confirm prompt leaves things alone
  const ctx = await t.browser.newContext({ viewport: { width: 1100, height: 900 } });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.ADMIN);
  if (await p.$('input[name="setup_key"]')) { await p.fill('input[name="setup_key"]', SETUP_KEY); await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="setup"]'); await p.waitForLoadState(); }
  if (await p.$('input[name="password"]')) { await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="login"]'); await p.waitForLoadState(); }
  const count = () => p.$$eval('#admin-list .admin-row', (r) => r.length);
  const before = await count();
  const edit = await p.$eval('#admin-list .admin-row a[href^="?edit="]', (a) => a.getAttribute('href'));
  await p.goto(t.ADMIN + edit);
  let asked = '';
  p.once('dialog', (dialog) => { asked = dialog.message(); dialog.dismiss(); });
  await p.click('button[value="delete"]'); await p.waitForLoadState();
  t.ok('Delete asks first, and Cancel keeps the listing', /delete|remove/i.test(asked) && (await p.$$('input[name="name"]')).length === 1, asked);
  await p.goto(t.ADMIN);
  t.ok('the list is the same size after Cancel', (await count()) === before, before + ' -> ' + (await count()));

  // ---- the catalog
  const site = await t.openContext({ viewport: { width: 1280, height: 900 } }, { unfold: false });
  await t.mockCatalog(site, (data) => {
    const now = new Date().toISOString();
    data.items.forEach((i, n) => { i.result = { ...i.result, status: n % 5 === 0 ? 'unknown' : 'in_stock', observed_at: n % 5 === 0 ? null : now, price: 3000 + n }; });
  });
  const c = t.watch(await site.newPage());
  await c.goto(t.SITE + 'index.html?sort=availability'); await c.waitForSelector('.card');
  const unverified = c.locator('#unverified-toggle');
  t.ok('there are unverified listings to show', (await unverified.count()) === 1);
  await unverified.click();
  const open = await c.evaluate(() => { const g = document.getElementById('group-unknown'); const b = document.getElementById('fold-unknown'); return g ? { hidden: g.hidden, cards: g.querySelectorAll('.card').length, expanded: b && b.getAttribute('aria-expanded') } : null; });
  t.ok('showing unverified listings shows them, open, with the button saying Hide', /Hide unverified/.test(await unverified.textContent()) && open && !open.hidden && open.cards > 0 && open.expanded === 'true', JSON.stringify(open));

  // a damaged pick does not blank My picks
  const damaged = await t.openContext({ viewport: { width: 1280, height: 900 } });
  await damaged.addInitScript(() => { try { localStorage.setItem('offgrid.picks.v1', JSON.stringify({ 'spec-mini': {}, 'spec-kilo': { added: 'x' } })); } catch (e) { /* ok */ } });
  await t.mockCatalog(damaged, (data) => { const now = new Date().toISOString(); data.items.forEach((i) => { i.result = { ...i.result, status: 'in_stock', observed_at: now }; }); });
  const d = t.watch(await damaged.newPage());
  await d.goto(t.SITE + 'index.html?view=picks'); await d.waitForSelector('.card');
  t.ok('My picks still draws with damaged saved picks', (await d.$$('.card')).length === 2);

  // the category switch is not offered inside My picks
  t.ok('the Radios/Antennas switch is hidden in My picks', await d.$eval('#cat-tabs', (n) => getComputedStyle(n).display === 'none'));

  // keyboard focus survives a redraw
  const focusCtx = await t.openContext({ viewport: { width: 1280, height: 900 } });
  await t.mockCatalog(focusCtx, (data) => { const now = new Date().toISOString(); data.items.forEach((i) => { i.result = { ...i.result, status: 'in_stock', observed_at: now }; }); });
  const f = t.watch(await focusCtx.newPage());
  await f.goto(t.SITE + 'index.html'); await f.waitForSelector('.card .star');
  await f.focus('.card .star');
  const picked = await f.evaluate(() => document.activeElement.dataset.pick);
  await f.click('.card .star');
  t.ok('after starring a listing the star keeps keyboard focus', (await f.evaluate(() => document.activeElement && document.activeElement.dataset.pick)) === picked && !!picked);

  // price history: the line's button keeps focus after toggling
  const priceCtx = await t.openContext({ viewport: { width: 1280, height: 900 } });
  const prices = t.watch(await priceCtx.newPage());
  await prices.goto(t.SITE + 'prices.html'); await prices.waitForTimeout(600);
  if (await prices.$('.pick')) {
    await prices.focus('.pick');
    const id = await prices.evaluate(() => document.activeElement.dataset.id);
    await prices.keyboard.press('Enter');
    t.ok('toggling a price line keeps focus on its button', (await prices.evaluate(() => document.activeElement && document.activeElement.dataset.id)) === id && !!id);
  } else {
    t.ok('price history had no lines to toggle in this data (nothing to check)', true);
  }
});
