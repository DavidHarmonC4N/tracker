'use strict';
// Phone alerts through ntfy, as visitors see them: shown only when the owner has set a public topic.
const { run } = require('./lib');

run(async (t) => {
  const TOPIC = 'https://ntfy.sh/offgrid-comms-demo';
  const withTopics = (data) => {
    data.public_topic = TOPIC;
    data.items.forEach((item, i) => { item.topic = TOPIC + '-' + String(i).padStart(8, '0'); });
  };
  const readPicks = (ids) => (context) => context.addInitScript((list) => {
    try { localStorage.setItem('offgrid.picks.v1', JSON.stringify(Object.fromEntries(list.map((id) => [id, { added: 1, price: 1, status: 'in_stock', seenPrice: 1, seenStatus: 'in_stock' }])))); } catch (error) { /* ok */ }
  }, ids);

  // off: nothing about ntfy appears
  const off = await t.openContext();
  const p = t.watch(await off.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('#list > *');
  t.ok('with no public topic, the footer has no ntfy link', await p.$eval('#ntfy-footer', (e) => e.hidden));
  t.ok('the public catalog advertises no topic', ((await (await p.request.get(t.SITE + 'catalog.php')).json()).public_topic) === '');

  // on
  const on = await t.openContext();
  await t.mockCatalog(on, withTopics);
  const ids = await (async () => (await (await off.request.get(t.SITE + 'catalog.php')).json()).items.slice(0, 3).map((i) => i.id))();
  await readPicks(ids)(on);
  const q = t.watch(await on.newPage(), 'on: ');
  await q.goto(t.SITE + 'index.html'); await q.waitForSelector('#list > *');
  t.ok('the footer offers phone alerts', !(await q.$eval('#ntfy-footer', (e) => e.hidden)));
  const footer = await q.$eval('#ntfy-link', (a) => ({ href: a.href, target: a.target, rel: a.rel, text: a.textContent }));
  t.ok('it opens the public topic in a new tab, and says so', footer.href === TOPIC && footer.target === '_blank' && /noopener/.test(footer.rel) && /new tab/.test(footer.text), JSON.stringify(footer));

  await q.click('#picks-btn');
  await q.waitForSelector('.dashhead');
  const all = await q.$eval('.dashact a:has-text("Phone alerts (all)")', (a) => ({ href: a.href, target: a.target }));
  t.ok('My picks links to the public topic', all.href === TOPIC && all.target === '_blank');
  const radios = await q.$$eval('#list .card a.ntfy-radio', (a) => a.map((x) => ({ href: x.href, target: x.target, rel: x.rel, text: x.textContent })));
  t.ok('each pick links to its own radio topic', radios.length === 3 && new Set(radios.map((r) => r.href)).size === 3 && radios.every((r) => r.href.startsWith(TOPIC + '-') && r.target === '_blank' && /noopener/.test(r.rel)), JSON.stringify(radios.map((r) => r.href)));
  t.ok('the radio link says what it is and that it opens a new tab', radios.every((r) => /Phone alerts for this radio/.test(r.text) && /new tab/.test(r.text)));
  t.ok('phone alerts links add no personal data', radios.every((r) => !/[?&]/.test(r.href)));

  // a topic that is not https is never shown
  const bad = await t.openContext();
  await t.mockCatalog(bad, (data) => { data.public_topic = 'javascript:alert(1)'; data.items.forEach((i) => { i.topic = 'javascript:alert(1)'; }); });
  const b = t.watch(await bad.newPage(), 'bad: ');
  await b.goto(t.SITE + 'index.html'); await b.waitForSelector('#list > *');
  t.ok('an unsafe topic address is ignored', await b.$eval('#ntfy-footer', (e) => e.hidden));

  // the price history page shows the footer link too
  const history = await t.openContext();
  await history.route('**/history.php*', (route) => route.fulfill({ json: { now: Math.floor(Date.now() / 1000), public_topic: TOPIC, items: [] } }));
  const h = t.watch(await history.newPage(), 'history: ');
  await h.goto(t.SITE + 'prices.html?scope=instock'); await h.waitForSelector('#subtitle');
  await h.waitForFunction(() => !document.getElementById('ntfy-footer').hidden);
  t.ok('the price history page offers phone alerts too', (await h.$eval('#ntfy-link', (a) => a.href)) === TOPIC);

  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  await t.mockCatalog(phone, withTopics);
  await readPicks(ids)(phone);
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'index.html'); await m.waitForSelector('#list > *'); await m.click('#picks-btn'); await m.waitForSelector('.dashhead');
  t.ok('phone: no sideways scroll in My picks with the new links', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
