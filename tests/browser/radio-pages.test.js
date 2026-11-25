'use strict';
// Per-radio pages: every page works with JavaScript off, addresses are stable, and the rest of the site links to them.
const { run } = require('./lib');

run(async (t) => {
  const crawler = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  const get = async (path) => crawler.request.get(t.SITE + path, { maxRedirects: 0 });
  const catalog = await (await crawler.request.get(t.SITE + 'catalog.php')).json();
  const pages = [...new Set(catalog.items.map((i) => i.page))];

  t.ok('every listing in the catalog says where its page is', catalog.items.every((i) => /^radio\.php\?r=[a-z0-9-]+-[0-9a-f]{8}$/.test(i.page)));
  t.ok('listings that share a product link share one page (fewer pages than listings)', pages.length > 30 && pages.length < catalog.items.length, pages.length + ' pages for ' + catalog.items.length + ' listings');

  // ---- every page, read as a crawler
  const problems = [];
  const titles = new Set();
  for (const page of pages) {
    const r = await get(page);
    const html = await r.text();
    const title = (html.match(/<title>(.*?)<\/title>/) || [])[1] || '';
    titles.add(title);
    const ld = (() => { try { return JSON.parse((html.match(/application\/ld\+json">(.*?)<\/script>/s) || [])[1]); } catch (e) { return null; } })();
    if (r.status() !== 200) problems.push(page + ': HTTP ' + r.status());
    if ((html.match(/<h1>/g) || []).length !== 1) problems.push(page + ': h1 count');
    if (!/Price and Stock \| Offgrid Comms Tracker$/.test(title)) problems.push(page + ': title');
    if (!/<link rel="canonical" href="[^"]+radio\.php\?r=[^"]+">/.test(html)) problems.push(page + ': canonical');
    if (!ld || ld['@type'] !== 'WebPage' || ld.breadcrumb.itemListElement.length !== 3) problems.push(page + ': structured data');
    if (/(Warning|Notice|Fatal error|Deprecated):|undefined|Array to string/.test(html)) problems.push(page + ': PHP or template error text');
    if (!/<table class="rtable">/.test(html) || !/id="o-/.test(html)) problems.push(page + ': no offers table');
    if (/noindex/.test(html) || r.headers()['x-robots-tag']) problems.push(page + ': noindex');
  }
  t.ok(`all ${pages.length} radio pages are valid, indexable pages with one h1, a canonical, breadcrumbs and an offers table`, problems.length === 0, problems.slice(0, 4).join(' | '));
  t.ok('every radio page has its own title', titles.size === pages.length, titles.size + ' unique titles');

  // ---- one multi-warehouse radio in detail
  const seeed = catalog.items.filter((i) => /^seeed-t1000e-/.test(i.id));
  const ref = seeed[0].page;
  const p = await crawler.newPage();
  await p.goto(t.SITE + ref);
  t.ok('the h1 is the radio\'s name and the seller is shown', /SenseCAP Card Tracker T1000-E/.test(await p.textContent('h1')) && /SEEED STUDIO/i.test(await p.textContent('.eyebrow')));
  const offers = await p.$$eval('#now ~ * tbody tr, section[aria-labelledby="now"] tbody tr', (r) => r.map((x) => x.querySelector('th').textContent.trim()));
  t.ok('one row per warehouse, U.S. first', offers.length === seeed.length && /^U\.S\. warehouse/.test(offers[0]) && offers.some((o) => /^EU warehouse/.test(o)) && offers.some((o) => /^China warehouse/.test(o)), offers.join(' | '));
  t.ok('the price cells are plain cells, not round badges', await p.$eval('section[aria-labelledby="now"] td.num', (e) => { const c = getComputedStyle(e); return c.display === 'table-cell' && c.borderRadius === '0px'; }));
  t.ok('the page has the headings people and crawlers expect', (await p.$$eval('h2', (h) => h.map((x) => x.textContent))).join('|').startsWith('Price and stock now|About this radio|Price and stock history'));
  t.ok('the facts list names type, use, firmware, setup and seller', (await p.$$eval('.facts-list dt', (d) => d.map((x) => x.textContent))).join() === 'Type,Primary use,Firmware,Setup,Seller,Ships from');
  const seller = await p.$eval('.pcontrols a', (a) => ({ href: a.href, target: a.target, rel: a.rel, text: a.textContent }));
  t.ok('the seller button is https, opens in a new tab and says so', seller.href.startsWith('https://') && seller.target === '_blank' && /noopener/.test(seller.rel) && /new tab/.test(seller.text));
  t.ok('the breadcrumb links home and to the full list', (await p.$eval('.crumbs', (n) => n.querySelector('a[href="./"]') !== null && n.querySelector('a[href="radios.php"]') !== null)));
  t.ok('the history section explains itself when there is no history yet', /History starts with the next check|has not changed since/.test(await p.textContent('#history ~ *') + await p.textContent('section[aria-labelledby="history"]')));
  const similar = await p.$$eval('#similar ~ ul a, section[aria-labelledby="similar"] a', (a) => a.map((x) => x.getAttribute('href')));
  t.ok('similar radios link to other radio pages', similar.length >= 3 && similar.every((h) => /^radio\.php\?r=/.test(h)) && !similar.includes(ref));
  let dead = 0;
  for (const href of similar) if ((await get(href)).status() !== 200) dead += 1;
  t.ok('every similar-radio link works', dead === 0);

  // ---- addresses: a renamed radio redirects, strange ones are 404s
  const code = ref.slice(-8);
  const old = await get('radio.php?r=an-old-name-' + code);
  t.ok('a stale name part redirects (301) to the current address', old.status() === 301 && old.headers().location.endsWith(ref), old.status() + ' ' + (old.headers().location || ''));
  const codeOnly = await get('radio.php?r=' + code);
  t.ok('the bare code also redirects to the full address', codeOnly.status() === 301 && codeOnly.headers().location.endsWith(ref));
  for (const bad of ['radio.php?r=nothing-here-00000000', 'radio.php', 'radio.php?r=', 'radio.php?r=%3Cscript%3Ealert(1)%3C/script%3E', 'radio.php?r[]=x', 'radio.php?r=' + 'a'.repeat(500)]) {
    const r = await get(bad);
    const body = await r.text();
    t.ok('a bad address is a 404 that is not indexed and has no script: ' + bad.slice(0, 40), r.status() === 404 && /noindex/.test(body) && !/<script>alert/.test(body) && /We could not find that radio/.test(body));
  }

  // ---- the rest of the site links to the pages
  const list = await (await crawler.request.get(t.SITE + 'radios.php')).text();
  const listed = [...list.matchAll(/<th scope="row"><a href="(radio\.php\?r=[^"]+)">/g)].map((m) => m[1]);
  t.ok('the plain list links every radio\'s name to its page', listed.length === catalog.items.length && listed.every((h) => pages.includes(h)), listed.length + ' links');
  const map = await (await crawler.request.get(t.SITE + 'sitemap.php')).text();
  const locs = [...map.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  t.ok('the sitemap lists every radio page, plus the four main pages', locs.length === pages.length + 4 && pages.every((pg) => locs.some((l) => l.endsWith(pg))), locs.length + ' urls');

  const ctx = await t.openContext();
  await t.mockCatalog(ctx, (data) => { const now = new Date().toISOString(); data.items.forEach((i) => { i.result = { ...i.result, status: 'in_stock', observed_at: now }; }); });
  const js = t.watch(await ctx.newPage());
  await js.goto(t.SITE + 'index.html'); await js.waitForSelector('.card');
  const link = await js.$eval('.card a.details', (a) => ({ href: a.getAttribute('href'), label: a.getAttribute('aria-label'), text: a.textContent }));
  t.ok('each catalog card links to its details page', /^radio\.php\?r=/.test(link.href) && /Details and price history/.test(link.text) && /^Details and price history for /.test(link.label), JSON.stringify(link));
  t.ok('every card has the link', (await js.$$eval('.card', (c) => c.every((x) => x.querySelector('a.details')))));
  await js.click('.card a.details');
  await js.waitForURL(/radio\.php\?r=/);
  t.ok('following it opens the radio page', (await js.$$('h1')).length === 1 && /Price and stock now/.test(await js.textContent('main')));
  await js.click('.crumbs a[href="radios.php"]');
  t.ok('the breadcrumb leads to the full list', /All MeshCore and Meshtastic radios/.test(await js.textContent('h1')));

  // ---- phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + ref);
  t.ok('phone: no sideways scroll on a three-warehouse radio page', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  t.ok('phone: the title and the seller button are visible without scrolling far', await m.$eval('h1', (h) => h.getBoundingClientRect().top < 600));
});
