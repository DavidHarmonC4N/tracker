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
  t.ok('the footer always links to the Phone alerts page, even with no public topic (it explains itself)', await p.$eval('footer a[href="alerts.html"]', (a) => a.offsetParent !== null && !a.closest('[hidden]')));
  t.ok('the public catalog advertises no topic', ((await (await p.request.get(t.SITE + 'catalog.php')).json()).public_topic) === '');

  // on
  const on = await t.openContext();
  await t.mockCatalog(on, withTopics);
  const ids = await (async () => (await (await off.request.get(t.SITE + 'catalog.php')).json()).items.slice(0, 3).map((i) => i.id))();
  await readPicks(ids)(on);
  const q = t.watch(await on.newPage(), 'on: ');
  await q.goto(t.SITE + 'index.html'); await q.waitForSelector('#list > *');
  t.ok('the footer offers phone alerts', await q.$eval('footer a[href="alerts.html"]', (a) => a.offsetParent !== null));
  const footer = await q.$eval('footer a[href="alerts.html"]', (a) => ({ href: a.href, target: a.target, text: a.textContent }));
  t.ok('the footer link goes to the Phone alerts page, on this site', footer.href === t.SITE + 'alerts.html' && footer.target === '' && /Phone alerts/.test(footer.text), JSON.stringify(footer));

  await q.click('#picks-btn');
  await q.waitForSelector('.dashhead');
  const all = await q.$eval('.dashact a:has-text("Phone alerts (all)")', (a) => ({ href: a.href, target: a.target }));
  t.ok('My picks links to the Phone alerts page', all.href === t.SITE + 'alerts.html' && all.target === '');
  const radios = await q.$$eval('#list .card a.ntfy-radio', (a) => a.map((x) => ({ href: x.href, text: x.textContent })));
  t.ok('each pick links to the page for its own radio', radios.length === 3 && new Set(radios.map((r) => r.href)).size === 3 && radios.every((r, i) => r.href === t.SITE + 'alerts.html?radio=' + ids[i] || r.href.startsWith(t.SITE + 'alerts.html?radio=')), JSON.stringify(radios.map((r) => r.href)));
  t.ok('the radio link says what it is', radios.every((r) => /Phone alerts for this radio/.test(r.text)));
  t.ok('the links carry only the radio id, nothing personal', radios.every((r) => /^[^?]+\?radio=[a-z0-9_-]+$/.test(r.href)));

  // a topic that is not https is never shown
  const bad = await t.openContext();
  await t.mockCatalog(bad, (data) => { data.public_topic = 'javascript:alert(1)'; data.items.forEach((i) => { i.topic = 'javascript:alert(1)'; }); });
  const b = t.watch(await bad.newPage(), 'bad: ');
  await b.goto(t.SITE + 'index.html'); await b.waitForSelector('#list > *');
  t.ok('an unsafe topic address is never put on the page', !(await b.content()).includes('javascript:alert') && (await b.$$('.ntfy-radio')).length === 0);

  // the price history page shows the footer link too
  const history = await t.openContext();
  await history.route('**/history.php*', (route) => route.fulfill({ json: { now: Math.floor(Date.now() / 1000), public_topic: TOPIC, items: [] } }));
  const h = t.watch(await history.newPage(), 'history: ');
  await h.goto(t.SITE + 'prices.html?scope=instock'); await h.waitForSelector('#subtitle');
  t.ok('the price history page links to phone alerts too', (await h.$eval('footer a[href="alerts.html"]', (a) => a.href)) === t.SITE + 'alerts.html');

  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  await t.mockCatalog(phone, withTopics);
  await readPicks(ids)(phone);
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'index.html'); await m.waitForSelector('#list > *'); await m.click('#picks-btn'); await m.waitForSelector('.dashhead');
  t.ok('phone: no sideways scroll in My picks with the new links', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
