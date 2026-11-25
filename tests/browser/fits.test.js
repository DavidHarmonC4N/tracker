'use strict';
// "Antennas that fit" on radio pages and "Radios this antenna fits" on antenna pages, read with JavaScript off.
const { run } = require('./lib');

run(async (t) => {
  const crawler = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  const catalog = await (await crawler.request.get(t.SITE + 'catalog.php')).json();
  const pageOf = (id) => catalog.items.find((i) => i.id === id).page;
  const open = async (id) => { const p = await crawler.newPage(); await p.goto(t.SITE + pageOf(id)); return p; };
  const list = (p) => p.$$eval('#fits ~ * a, section[aria-labelledby="fits"] a', (a) => a.map((x) => x.textContent));

  const sma = await open('atlavox-m1');
  t.ok('an SMA radio shows its port in the facts', (await sma.$$eval('.facts-list div', (d) => d.map((x) => x.textContent))).some((x) => /Antenna port\s*SMA/.test(x)));
  const smaList = await list(sma);
  t.ok('it lists antennas that fit, and says how they were matched', smaList.length >= 3 && /connector matches this radio's SMA port, as stated by their sellers/.test(await sma.textContent('#fits ~ p, section[aria-labelledby="fits"] p')), smaList.length + ' antennas');
  t.ok('every antenna listed is an SMA antenna', (await Promise.all(smaList.map(async (name) => {
    const item = catalog.items.find((i) => i.name === name.replace(/\s+/g, ' '));
    return item && /SMA/.test(item.specs.connector) && !/RP-SMA|N /.test(item.specs.connector);
  }))).every(Boolean), smaList.join(' | '));
  t.ok('the fits section sits between the facts and the history', await sma.evaluate(() => {
    const ids = [...document.querySelectorAll('main section[aria-labelledby]')].map((s) => s.getAttribute('aria-labelledby'));
    return ids.indexOf('facts') < ids.indexOf('fits') && ids.indexOf('fits') < ids.indexOf('history');
  }));

  const ufl = await open('rok-t096');
  t.ok('a U.FL radio is pointed to a pigtail cable', /U\.FL \(IPEX\)/.test(await ufl.textContent('.facts-list')) && /U\.FL to SMA pigtail/.test(await ufl.textContent('section[aria-labelledby="fits"]')) && /Pigtail/.test((await list(ufl)).join(' ')));

  const none = await open('rok-wismesh-tag');
  t.ok('a radio with no external port says its antenna is built in', /built in/.test(await none.textContent('section[aria-labelledby="fits"]')) && (await list(none)).length === 0);

  const unknown = await open('rok-tdeck');
  t.ok('a radio whose port is not known has no fits section and no port row', (await unknown.$('section[aria-labelledby="fits"]')) === null && !/Antenna port/.test(await unknown.textContent('.facts-list')));

  const ant = await open('atl-ant-gizont-rp');
  t.ok('an RP-SMA antenna page lists radios with an RP-SMA port', /Radios this antenna fits/.test(await ant.textContent('section[aria-labelledby="fits"] h2')) && (await list(ant)).some((n) => /ThinkNode M5/.test(n)));
  t.ok('it says the match is by the port the sellers state', /antenna port, as stated by their sellers/.test(await ant.textContent('section[aria-labelledby="fits"] p')));
  const cable = await open('rok-ant-pig-smn');
  t.ok('a cable page has no fits section', (await cable.$('section[aria-labelledby="fits"]')) === null);

  // every link in a fits section works
  let dead = 0, total = 0;
  for (const id of ['atlavox-m1', 'rok-t096', 'atl-ant-gizont-rp']) {
    const p = await open(id);
    for (const href of await p.$$eval('section[aria-labelledby="fits"] a', (a) => a.map((x) => x.getAttribute('href')))) {
      total += 1;
      if ((await crawler.request.get(t.SITE + href)).status() !== 200) dead += 1;
    }
  }
  t.ok('every link in a fits section opens a real page', total > 5 && dead === 0, total + ' links');

  // phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage());
  await m.goto(t.SITE + pageOf('atlavox-m1'));
  t.ok('phone: no sideways scroll on a page with a fits section', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
