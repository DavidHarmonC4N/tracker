'use strict';
// Roll-up sections: folding groups, remembering the choice, Collapse all / Expand all, the Seeed warehouse groups.
const { run } = require('./lib');

run(async (t) => {
  // This test checks the defaults, so it does not preset the fold setting.
  const ctx = await t.openContext({}, { unfold: false });
  await t.mockCatalog(ctx, (data) => {
    const now = new Date().toISOString();
    const statuses = ['in_stock', 'in_stock', 'orderable', 'orderable', 'orderable', 'out_of_stock', 'out_of_stock', 'preorder'];
    data.items.forEach((item, i) => {
      item.result = { ...item.result, status: statuses[i % statuses.length], price: 5000 + i, observed_at: now };
    });
  });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');
  const folds = () => p.$$eval('#list .fold', (f) => f.map((x) => x.dataset.fold + ':' + x.getAttribute('aria-expanded')));

  t.ok('Available now: In stock and Orderable start open', (await folds()).join() === 'in_stock:true,orderable:true', (await folds()).join());
  t.ok('Collapse all is offered when there are two groups', !(await p.$eval('#fold-all', (e) => e.hidden)));
  await p.click('.status-tab[data-tab="all"]');
  const all = await folds();
  t.ok('All tab: Out of stock starts folded, the rest open', all.includes('out_of_stock:false') && all.includes('in_stock:true') && all.includes('preorder:true'), all.join());
  t.ok('a folded group hides its cards', await p.$eval('#group-out-of-stock', (e) => e.hidden));
  t.ok('a folded heading still shows its count', /Out of stock · \d+/.test(await p.textContent('#fold-out-of-stock')));
  await p.click('#fold-out-of-stock');
  t.ok('clicking opens a folded group', (await p.getAttribute('#fold-out-of-stock', 'aria-expanded')) === 'true' && !(await p.$eval('#group-out-of-stock', (e) => e.hidden)));
  await p.click('#fold-orderable');
  t.ok('clicking folds an open group', await p.$eval('#group-orderable', (e) => e.hidden));
  await p.reload(); await p.waitForSelector('.card'); await p.click('.status-tab[data-tab="all"]');
  const remembered = await folds();
  t.ok('the choices are remembered after a reload', remembered.includes('orderable:false') && remembered.includes('out_of_stock:true'), remembered.join());
  await p.focus('#fold-orderable'); await p.keyboard.press('Enter');
  t.ok('Enter opens a group from the keyboard', (await p.getAttribute('#fold-orderable', 'aria-expanded')) === 'true');
  await p.click('#fold-all');
  t.ok('Collapse all folds everything and the button flips', (await folds()).every((x) => x.endsWith(':false')) && /Expand all/.test(await p.textContent('#fold-all')));
  await p.click('#fold-all');
  t.ok('Expand all opens everything', (await folds()).every((x) => x.endsWith(':true')));
  await p.click('.status-tab[data-tab="out_of_stock"]');
  t.ok('a single-status tab has nothing to fold', (await p.$$('#list .fold')).length === 0 && (await p.$$('#list .card')).length > 0);
  await p.click('.status-tab[data-tab="all"]'); await p.click('[data-chip="seeed"]');
  const warehouses = await folds();
  t.ok('Seeed warehouse sections fold too', warehouses.length >= 2 && warehouses.every((x) => x.startsWith('wh:')), warehouses.join());
  t.ok('warehouse headings keep their flags', (await p.$$('.group-head h3 svg.flag')).length === warehouses.length);
  await p.click('#fold-wh-us');
  t.ok('a warehouse section can be folded', (await p.$$('#list .group.folded')).length === 1);
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'index.html?status=all'); await m.waitForSelector('#list > *');
  t.ok('phone: no sideways scroll', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
