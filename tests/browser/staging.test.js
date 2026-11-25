'use strict';
// A staging copy must say so on every page; a normal site must not.
const { run } = require('./lib');

run(async (t) => {
  const T = Math.floor(Date.now() / 1000);

  // normal site: no banner
  const normal = await t.openContext();
  const p = t.watch(await normal.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('#list > *');
  t.ok('the live site shows no staging bar', (await p.$('#staging-bar')) === null && !/^\[STAGING\]/.test(await p.title()));
  const response = await p.request.get(t.SITE + 'catalog.php');
  t.ok('the live catalog says staging is false', (await response.json()).staging === false);
  t.ok('the live data feed is marked noindex, and the pages are not', response.headers()['x-robots-tag'] === 'noindex' && !(await p.request.get(t.SITE + 'index.html')).headers()['x-robots-tag']);

  // a staging copy (the catalog says so)
  const staged = await t.openContext();
  await t.mockCatalog(staged, (data) => { data.staging = true; });
  const s = t.watch(await staged.newPage(), 'staging: ');
  await s.goto(t.SITE + 'index.html'); await s.waitForSelector('#staging-bar');
  t.ok('the catalog page shows a STAGING bar at the very top', /STAGING/.test(await s.textContent('#staging-bar')) && await s.$eval('body', (b) => b.firstElementChild.id === 'staging-bar'));
  t.ok('the tab title is marked', /^\[STAGING\]/.test(await s.title()));
  t.ok('the bar is announced as a note, not an alert', (await s.getAttribute('#staging-bar', 'role')) === 'note');
  t.ok('the bar is only added once', (await s.$$('#staging-bar')).length === 1);

  await staged.route('**/history.php*', (route) => route.fulfill({ json: { now: T, staging: true, items: [] } }));
  await s.goto(t.SITE + 'prices.html?scope=instock'); await s.waitForSelector('#staging-bar');
  t.ok('the price history page shows it too', /^\[STAGING\]/.test(await s.title()));

  // the admin page on a normal site has no bar
  const a = t.watch(await normal.newPage());
  await a.goto(t.ADMIN);
  t.ok('the live admin page has no staging bar', (await a.$('.staging-bar')) === null && !/STAGING/.test(await a.title()));
});
