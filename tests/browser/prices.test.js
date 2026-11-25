'use strict';
// Price history page: scope and range switches, the chart, the table, empty states, links, and the phone layout.
const { run } = require('./lib');

run(async (t) => {
  const T = Math.floor(Date.now() / 1000);
  const pt = (days, price, status = 'in_stock') => [T - days * 86400, price, status];
  const item = (id, name, seller, price, status, points) => {
    const priced = points.filter((x) => x[1]).map((x) => x[1]);
    return { id, name, seller, url: 'https://example.com/products/' + id, status, price, low: priced.length ? Math.min(...priced) : null, high: priced.length ? Math.max(...priced) : null, points };
  };
  const fixture = [
    item('a', 'LILYGO T-Deck', 'Rokland', 6797, 'in_stock', [pt(28, 7495), pt(18, 6995), pt(10, 6797)]),
    item('b', 'ThinkNode M9', 'ameriDroid', 7495, 'in_stock', [pt(25, 7995), pt(14, 7495)]),
    item('c', 'SenseCAP Card Tracker', 'Seeed Studio', 3990, 'in_stock', [pt(40, 3990), pt(9, 4490), pt(5, 3990)]),
    item('d', 'WisMesh Tag Navy', 'Rokland', 4600, 'out_of_stock', [pt(20, 4600, 'out_of_stock')]),
    item('e', 'Brand new listing', 'Somewhere', 5000, 'in_stock', []),
  ];
  const requested = [];
  const serve = async (context, items = fixture) => context.route('**/history.php*', (route) => {
    requested.push(route.request().url());
    const ids = new URL(route.request().url()).searchParams.get('ids');
    const list = ids ? items.filter((i) => ids.split(',').includes(i.id)) : items.filter((i) => i.status === 'in_stock');
    route.fulfill({ json: { now: T, items: list } });
  });
  const withPicks = (ids) => (context) => context.addInitScript((list) => {
    try { localStorage.setItem('offgrid.picks.v1', JSON.stringify(Object.fromEntries(list.map((id) => [id, { added: 1 }])))); } catch (error) { /* ok */ }
  }, ids);

  const ctx = await t.openContext({}, { unfold: false });
  await serve(ctx);
  await withPicks(['a', 'b', 'c', 'd'])(ctx);
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'prices.html'); await p.waitForSelector('#chart svg');
  const lines = () => p.$$eval('#chart svg path', (x) => x.length);

  t.ok('opens on My picks when there are picks', (await p.getAttribute('#scope [data-scope="picks"]', 'aria-pressed')) === 'true');
  t.ok('asks only for the picked ids', requested.some((u) => /ids=a%2Cb%2Cc%2Cd/.test(u)));
  t.ok('the subtitle summarises', /4 radios · last 30 days · your picks/.test(await p.textContent('#subtitle')), await p.textContent('#subtitle'));
  t.ok('one line per radio that has a price', (await lines()) === 4, String(await lines()));
  t.ok('rings mark the lowest prices', (await p.$$eval('#chart svg circle', (c) => c.length)) >= 2);
  t.ok('lines are labelled with names and latest prices', /LILYGO T-Deck/.test(await p.textContent('#chart svg')) && /\$67\.97/.test(await p.textContent('#chart svg')));
  const rows = await p.$$eval('.ptable tbody tr', (r) => r.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
  t.ok('the table lists every radio', rows.length === 4);
  t.ok('a radio at its low says Lowest seen', rows.some((r) => /LILYGO T-Deck.*Lowest seen/.test(r)));
  t.ok('a radio whose price never moved says No change', rows.some((r) => /WisMesh Tag Navy.*No change/.test(r)));
  t.ok('an out-of-stock radio is marked in the table', rows.some((r) => /WisMesh Tag Navy.*\(out\)/.test(r)));

  await p.click('.pick:has-text("ThinkNode M9")');
  t.ok('hiding a radio removes its line', (await lines()) === 3);
  await p.click('.pick:has-text("ThinkNode M9")');
  t.ok('showing it again restores the line', (await lines()) === 4);
  await p.click('#range [data-range="7"]');
  t.ok('7 days updates the subtitle and the link', /last 7 days/.test(await p.textContent('#subtitle')) && /range=7/.test((await p.evaluate(() => location.href))));
  await p.click('#range [data-range="all"]');
  t.ok('All time works', /all recorded history/.test(await p.textContent('#subtitle')));
  await p.click('#range [data-range="30"]');
  await p.click('#scope [data-scope="instock"]');
  await p.waitForFunction(() => /in stock or orderable/.test(document.getElementById('subtitle').textContent));
  t.ok('All in stock asks for the in-stock list', requested.some((u) => /scope=instock/.test(u)));
  t.ok('All in stock lists the available radios', (await p.$$('.ptable tbody tr')).length === 4);
  t.ok('its chart draws only radios whose price has moved', (await lines()) === 3, String(await lines()));
  t.ok('a radio without history says so', (await p.textContent('.ptable')).includes('No history yet'));
  const links = await p.$$eval('.ptable a.ext', (a) => a.map((x) => [x.target, x.rel]));
  t.ok('seller links in the table open in a new tab', links.length === 4 && links.every(([target, rel]) => target === '_blank' && /noopener/.test(rel)));
  const external = await p.$$eval('a[href^="http"]', (a) => a.filter((x) => !x.href.includes('127.0.0.1') && !x.href.includes('localhost') && !x.href.includes('mesh.davidandlaura.net')).map((x) => x.target));
  t.ok('every link that leaves the site opens in a new tab', external.length > 0 && external.every((x) => x === '_blank'), String(external.length));

  // no picks
  const empty = await t.openContext({}, { unfold: false });
  await serve(empty, fixture.map((i) => ({ ...i, points: [] })));
  const e = t.watch(await empty.newPage(), 'empty: ');
  await e.goto(t.SITE + 'prices.html?scope=picks'); await e.waitForSelector('.emptyc');
  t.ok('without picks: a friendly message and a Browse link', /not starred any radios/.test(await e.textContent('#chart')) && (await e.$('#chart a.primary')) !== null);
  await e.click('#scope [data-scope="instock"]'); await e.waitForSelector('.ptable');
  t.ok('with no history yet: "starts today", and the table still shows prices', /Price history starts today/.test(await e.textContent('#chart')) && (await e.$$('.ptable tbody tr')).length > 0);

  // phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  await serve(phone); await withPicks(['a', 'b', 'c', 'd', 'e'])(phone);
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'prices.html'); await m.waitForSelector('#chart svg');
  t.ok('phone: no sideways scroll', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  t.ok('phone: the extra columns are hidden', !(await m.$eval('.ptable th.wide', (x) => x.offsetParent !== null)));

  // the real endpoint, with nothing mocked
  const real = await t.browser.newContext();
  const r = t.watch(await real.newPage(), 'real: ');
  await r.goto(t.SITE + 'prices.html?scope=instock'); await r.waitForSelector('.emptyc, #chart svg');
  t.ok('the real history.php works before any history exists', /Price history starts today/.test(await r.textContent('#chart')) && (await r.$$('.ptable tbody tr')).length > 0);
  const json = await (await r.request.get(t.SITE + 'history.php?ids=spec-mini,../x')).json();
  t.ok('history.php returns only valid ids', json.items.length === 1 && json.items[0].id === 'spec-mini' && Array.isArray(json.items[0].points));
  const all = await (await r.request.get(t.SITE + 'history.php?scope=instock')).json();
  t.ok('history.php exposes no private fields', !/password|token|setup_key/i.test(JSON.stringify(all)));
});
