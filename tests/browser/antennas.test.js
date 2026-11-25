'use strict';
// The Antennas category: the switch, its filters, its cards, its pages, and the plain list.
const { run } = require('./lib');

run(async (t) => {
  const ctx = await t.openContext({ viewport: { width: 1280, height: 900 } });
  await t.mockCatalog(ctx, (data) => { const now = new Date().toISOString(); data.items.forEach((i, n) => { i.result = { ...i.result, status: 'in_stock', observed_at: now, price: 1000 + n * 10 }; }); });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');
  const names = () => p.$$eval('.card h3', (h) => h.map((x) => x.textContent));
  const visible = (sel) => p.$eval(sel, (n) => n.offsetParent !== null).catch(() => false);

  t.ok('the catalog opens on radios, with no antennas mixed in', (await names()).length > 20 && !(await names()).some((n) => /dBi|Pigtail|Magnet Base/.test(n)));
  const counts = await p.$$eval('.cat-tab .cat-count', (c) => c.map((x) => Number(x.textContent)));
  t.ok('the switch shows how many radios and antennas there are', counts[0] > 30 && counts[1] >= 20, counts.join(' / '));
  t.ok('radio filters show, antenna filters do not', (await visible('#firmware')) && !(await visible('#band')) && !(await visible('#ant-warn')));

  await p.click('[data-cat-tab="antenna"]');
  const ants = await names();
  t.ok('Antennas shows only antennas', ants.length >= 20 && ants.every((n) => /dBi|Antenna|Pigtail|Magnet|Omni|Whip|Yagi|Gooseneck|Blade|Wave/i.test(n)), ants.length + ' cards');
  t.ok('the address remembers the category', /cat=antennas/.test(await p.evaluate(() => location.href)));
  t.ok('antenna filters and the compatibility warning show; radio filters hide', (await visible('#band')) && (await visible('#connector')) && (await visible('#atype')) && (await visible('#ant-warn')) && !(await visible('#firmware')));
  t.ok('the heading follows the category', /Antenna catalog/.test(await p.textContent('#inventory-title')));
  const firstFacts = await p.$$eval('.card .facts .fact, .card .facts > *', (f) => f.slice(0, 6).map((x) => x.textContent));
  t.ok('a card shows band, gain and connector instead of firmware', firstFacts.some((x) => /MHz/.test(x)) && firstFacts.some((x) => /dBi/.test(x)) && !firstFacts.some((x) => /firmware|Meshtastic/i.test(x)), firstFacts.join(' | '));
  const bands = await p.$$eval('#band option', (o) => o.map((x) => x.value));
  t.ok('the band filter lists the bands that exist', bands.includes('915') && bands.includes('868') && bands[0] === '', bands.join());
  await p.selectOption('#band', '868');
  const n868 = await names();
  t.ok('an 868 filter keeps 868/915 antennas and drops 915-only ones', n868.length > 0 && n868.length < ants.length && n868.some((n) => /868\/915|Heltec/.test(n)) && !n868.some((n) => /Backcountry/.test(n)), n868.length + '');
  t.ok('a band filter is saved in the link', /band=868/.test(await p.evaluate(() => location.href)));
  await p.selectOption('#band', '');
  const conns = await p.$$eval('#connector option', (o) => o.map((x) => x.value).filter(Boolean));
  await p.selectOption('#connector', 'N female');
  const nf = await names();
  t.ok('the connector filter matches exactly', conns.includes('N female') && nf.length > 0 && nf.length < ants.length, nf.length + '');
  await p.selectOption('#connector', '');
  await p.selectOption('#atype', 'antenna_directional');
  t.ok('the type filter finds the Yagi', (await names()).length >= 1 && (await names()).every((n) => /Yagi/.test(n)));
  await p.selectOption('#atype', '');

  // back to radios: antenna filters are cleared, radio filters return
  await p.selectOption('#band', '915');
  await p.click('[data-cat-tab="radio"]');
  t.ok('switching back clears antenna filters and shows radios', (await visible('#firmware')) && !(await visible('#band')) && (await p.inputValue('#band')) === '' && !(await names()).some((n) => /dBi/.test(n)));

  // a shared antenna link
  await p.goto(t.SITE + 'index.html?cat=antennas&band=915&connector=SMA%20male'); await p.waitForSelector('.card');
  const shared = await names();
  t.ok('an antenna link opens on Antennas with its filters applied', shared.length > 0 && (await p.inputValue('#connector')) === 'SMA male' && (await p.inputValue('#band')) === '915', shared.length + ' cards');

  // the antenna's own page
  const page = await p.$eval('.card a.details', (a) => a.getAttribute('href'));
  await p.goto(t.SITE + page);
  t.ok('an antenna page says it is an antenna and lists its facts', /About this antenna/.test(await p.textContent('#facts')) && (await p.$$eval('.facts-list dt', (d) => d.map((x) => x.textContent))).some((x) => x === 'Connector'));
  t.ok('its breadcrumb goes to the Antennas section of the list', await p.$eval('.crumbs a[href="radios.php#antennas"]', (a) => /Antennas/.test(a.textContent)));
  t.ok('its similar list is other antennas', /Similar antennas/.test(await p.textContent('#similar')));

  // the plain list
  const crawler = await t.browser.newContext({ javaScriptEnabled: false });
  const list = await (await crawler.request.get(t.SITE + 'radios.php')).text();
  t.ok('the plain list has an Antennas section after the radios', /id="antennas"/.test(list) && list.indexOf('id="antennas"') > list.indexOf('id="g-in_stock"'));
  const rows = (list.split('id="antennas"')[1].match(/<th scope="row">/g) || []).length;
  t.ok('every antenna is listed there with its own link', rows >= 20 && (list.split('id="antennas"')[1].match(/<th scope="row"><a href="radio\.php\?r=/g) || []).length === rows, rows + ' rows');
  const radioRows = (list.split('id="antennas"')[0].match(/<th scope="row">/g) || []).length;
  t.ok('antennas are not counted as radios in the list lead', new RegExp('<p class="lead">' + radioRows + ' radios').test(list), String(radioRows));

  // the hero has a Browse antennas button that opens the Antennas category
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');
  const hero = await p.$$eval('.hero-actions a', (a) => a.map((x) => ({ text: x.textContent.trim(), href: x.getAttribute('href'), cls: x.className })));
  t.ok('the hero has Browse radios, Browse antennas and MESH tools buttons', hero.map((h) => h.text).join('|') === 'Browse radios|Browse antennas|MESH tools' && hero[0].cls === 'primary' && hero[1].cls === 'quiet', JSON.stringify(hero.map((h) => h.text)));
  await p.click('.hero-actions a:has-text("Browse antennas")');
  await p.waitForSelector('.card');
  t.ok('Browse antennas opens the antenna catalog', /Antenna catalog/.test(await p.textContent('#inventory-title')) && /cat=antennas/.test(await p.evaluate(() => location.href)) && (await p.$$eval('.card h3', (h) => h.length)) >= 20);
  await p.click('.hero-actions a:has-text("Browse radios")');
  await p.waitForSelector('.card');
  t.ok('Browse radios from there goes back to radios', /Radio catalog/.test(await p.textContent('#inventory-title')) && !/cat=antennas/.test(await p.evaluate(() => location.href)));

  // phone
  const phone = await t.openContext({ viewport: { width: 390, height: 844 } });
  await t.mockCatalog(phone, (data) => { const now = new Date().toISOString(); data.items.forEach((i) => { i.result = { ...i.result, status: 'in_stock', observed_at: now }; }); });
  const m = t.watch(await phone.newPage());
  await m.goto(t.SITE + 'index.html?cat=antennas'); await m.waitForSelector('.card');
  t.ok('phone: no sideways scroll on the antenna catalog', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  t.ok('phone: the category switch fits', await m.$eval('#cat-tabs', (n) => n.getBoundingClientRect().right <= innerWidth));
});
