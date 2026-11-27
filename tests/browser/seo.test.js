'use strict';
// Search and AI crawler readiness. Pages are read with JavaScript switched off, the way a basic crawler sees them.
const { run } = require('./lib');

run(async (t) => {
  const crawler = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  const raw = async (path) => { const r = await crawler.request.get(t.SITE + path); return { status: r.status(), type: r.headers()['content-type'] || '', headers: r.headers(), text: await r.text() }; };
  const jsonLd = (html) => [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
  const page = await crawler.newPage();

  // ---- the plain radio list works with no JavaScript
  const catalog = await (await crawler.request.get(t.SITE + 'catalog.php')).json();
  await page.goto(t.SITE + 'radios.php');
  const rows = await page.$$eval('table.rtable tbody tr', (r) => r.length);
  const products = new Set(catalog.items.map((i) => i.page)).size;
  t.ok('radios.php lists every product in the HTML, one row each (warehouses share a row)', rows === products && rows > 40 && rows < catalog.items.length, rows + ' rows for ' + catalog.items.length + ' listings');
  const names = await page.$$eval('table.rtable tbody th', (r) => r.map((x) => x.childNodes[0].textContent));
  t.ok('the names a crawler reads match the catalog', catalog.items.every((i) => names.includes(i.name)));
  t.ok('it has one h1 and a heading for each availability group', (await page.$$('h1')).length === 1 && (await page.$$('h2[id^="g-"]')).length >= 1);
  t.ok('every table has a caption and header cells', (await page.$$eval('table.rtable', (tb) => tb.every((x) => x.querySelector('caption') && x.querySelectorAll('thead th[scope="col"]').length === 5))));
  const links = await page.$$eval('table.rtable td a', (a) => a.map((x) => ({ target: x.target, rel: x.rel, https: x.href.startsWith('https://') })));
  t.ok('every listing link is https and opens in a new tab', links.length > 40 && links.every((l) => l.https && l.target === '_blank' && /noopener/.test(l.rel)));
  const head = await raw('radios.php');
  t.ok('it has a unique title, a description and Open Graph tags', /<title>All MeshCore and Meshtastic Radios/.test(head.text) && /<meta name="description" content="\d+ MeshCore/.test(head.text) && /property="og:title"/.test(head.text));
  t.ok('it is canonical to itself, not noindex', /<link rel="canonical" href="[^"]*radios\.php">/.test(head.text) && !head.headers['x-robots-tag'] && !/noindex/.test(head.text));
  const ld = jsonLd(head.text);
  t.ok('its structured data is valid JSON and describes a collection page', ld.length === 1 && ld[0]['@type'] === 'CollectionPage' && ld[0].name.includes('radios'));
  t.ok('it shares the home page footer: stamp, What\'s new and the Phone alerts link', /KY4DEH/.test(head.text) && /id="whats-new"/.test(head.text) && /href="alerts\.html"/.test(head.text));
  t.ok('it links back to the interactive catalog', /href="\.\/#inventory"/.test(head.text));

  // ---- robots.txt and the sitemap
  const robots = await raw('robots.txt');
  t.ok('robots.txt exists and is plain text', robots.status === 200 && /text\/plain/.test(robots.type));
  t.ok('it allows crawlers and blocks the private endpoints', /User-agent: \*/.test(robots.text) && ['admin.php', 'ingest.php', 'check.php', 'ntfy.php', 'visit.php'].every((p) => new RegExp('^Disallow: /' + p.replace('.', '\\.') + '$', 'm').test(robots.text)));
  t.ok('it does NOT block the data files the catalog needs to render', !/Disallow: \/(catalog|history)\.php/.test(robots.text) && !/^Disallow: \/\s*$/m.test(robots.text));
  t.ok('it points to the sitemap', /^Sitemap: https:\/\/\S+\/sitemap\.php$/m.test(robots.text));
  const map = await raw('sitemap.php');
  t.ok('the sitemap is XML', map.status === 200 && /xml/.test(map.type) && /<urlset xmlns="http:\/\/www\.sitemaps\.org\/schemas\/sitemap\/0\.9">/.test(map.text));
  const locs = [...map.text.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  t.ok('it lists the four main pages and every radio page, with absolute addresses', locs.length > 40 && locs.every((l) => /^https?:\/\//.test(l)) && locs.some((l) => l.endsWith('radios.php')) && locs.some((l) => l.endsWith('prices.html')) && locs.some((l) => l.endsWith('alerts.html')) && locs.filter((l) => /radio\.php\?r=/.test(l)).length === locs.length - 4 - 54, locs.length + ' urls');
  t.ok('it leaves out private pages and index.html', !locs.some((l) => /admin|submit|ingest|index\.html/.test(l)));
  t.ok('it has no duplicate addresses', new Set(locs).size === locs.length);
  const mods = [...map.text.matchAll(/<lastmod>([^<]+)<\/lastmod>/g)].map((m) => m[1]);
  t.ok('every lastmod is a valid time, and the four main pages have one (a radio never checked has none)', mods.length >= 4 && mods.length <= locs.length && mods.every((m) => !Number.isNaN(Date.parse(m))) && [...map.text.matchAll(/<url><loc>([^<]+)<\/loc>(<lastmod>)?/g)].filter((m) => !/radio\.php\?r=/.test(m[1])).every((m) => m[2]));
  const parser = await (await t.openContext()).newPage();
  const parsed = await parser.evaluate((x) => { const d = new DOMParser().parseFromString(x, 'application/xml'); return { error: !!d.querySelector('parsererror'), urls: d.querySelectorAll('urlset > url > loc').length }; }, map.text);
  t.ok('the sitemap parses as valid XML with every url', !parsed.error && parsed.urls === locs.length, JSON.stringify(parsed));
  const llms = await raw('llms.txt');
  t.ok('llms.txt describes the site and points to the plain list', llms.status === 200 && /^# Offgrid Comms Tracker/.test(llms.text) && /radios\.php/.test(llms.text));

  // ---- the home page, as a crawler reads it
  const home = await raw('');
  t.ok('the home title names what the site does', /<title>MeshCore &amp; Meshtastic Radios: Live Prices and Stock/.test(home.text));
  t.ok('the home description is a useful length', (() => { const d = (home.text.match(/<meta name="description" content="([^"]+)"/) || [])[1] || ''; return d.length >= 120 && d.length <= 165; })());
  t.ok('Open Graph and Twitter titles match the title', /og:title" content="MeshCore &amp; Meshtastic Radios/.test(home.text) && /twitter:title" content="MeshCore &amp; Meshtastic Radios/.test(home.text));
  const homeLd = jsonLd(home.text);
  const graph = homeLd[0] && homeLd[0]['@graph'] || [];
  t.ok('the home page defines the website and its publisher in structured data', graph.some((g) => g['@type'] === 'WebSite' && g.name === 'Offgrid Comms Tracker') && graph.some((g) => g['@type'] === 'Organization'));
  t.ok('the structured data addresses use the canonical host', graph.every((g) => !g.url || g.url.startsWith('https://mesh.davidandlaura.net/')));
  await page.goto(t.SITE + 'index.html');
  t.ok('the h1 carries the topic for screen readers and crawlers, and the page still has one h1', (await page.$$('h1')).length === 1 && /MeshCore and Meshtastic radio prices and stock/.test(await page.textContent('h1')));
  t.ok('without JavaScript the catalog area points to the plain list', (await page.$$eval('a[href="radios.php"]', (a) => a.length)) >= 3);
  t.ok('a visible plain-list link sits above the catalog', (await page.$eval('.plain-link a', (a) => a.offsetParent !== null)));

  // ---- links, headings, icons
  for (const path of ['', 'prices.html', 'alerts.html', 'radios.php']) {
    const html = (await raw(path)).text;
    const label = path || 'home';
    t.ok(`${label}: the Phone alerts link is in the HTML and not hidden`, /<li><a href="alerts\.html"[^>]*>Phone alerts \(ntfy\)<\/a><\/li>/.test(html));
    t.ok(`${label}: footer labels and release titles are not headings`, !/<h[23] class="footer-head"/.test(html) && !/<div class="rel"><h3/.test(html) && /class="rel-title"/.test(html));
    t.ok(`${label}: internal links go to the site root, not index.html`, !/href="index\.html/.test(html) || path === '');
    t.ok(`${label}: it links to the plain list`, /href="radios\.php"/.test(html));
    t.ok(`${label}: it declares the favicon and touch icon`, /rel="icon" href="favicon\.ico"/.test(html) && /rel="apple-touch-icon" href="assets\/apple-touch-icon\.png"/.test(html));
  }
  const ico = await crawler.request.get(t.SITE + 'favicon.ico');
  t.ok('favicon.ico is served as an icon', ico.status() === 200 && /icon|octet/.test(ico.headers()['content-type'] || '') && (await ico.body()).length > 100);
  const touch = await crawler.request.get(t.SITE + 'assets/apple-touch-icon.png');
  const png = await touch.body();
  t.ok('the touch icon is a 180 by 180 PNG', touch.status() === 200 && png.readUInt32BE(16) === 180 && png.readUInt32BE(20) === 180);

  // ---- data feeds are not pages
  t.ok('catalog.php and history.php are marked noindex', (await raw('catalog.php')).headers['x-robots-tag'] === 'noindex' && (await raw('history.php?scope=instock')).headers['x-robots-tag'] === 'noindex');
  const tagged = [];
  for (const path of ['', 'prices.html', 'alerts.html', 'radios.php', 'sitemap.php', 'robots.txt']) if ((await raw(path)).headers['x-robots-tag']) tagged.push(path);
  t.ok('the public pages, the sitemap and robots.txt are not marked noindex', tagged.length === 0, tagged.join(' '));

  // ---- layout stability on the Price history page (it used to shift 0.5 and more)
  for (const [label, size] of [['desktop', { width: 1350, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    const ctx = await t.browser.newContext({ viewport: size });
    const p = t.watch(await ctx.newPage(), label + ': ');
    await p.addInitScript(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
    await p.goto(t.SITE + 'prices.html?scope=instock', { waitUntil: 'networkidle' });
    await p.waitForSelector('#tablewrap table, #chart .emptyc, #chart svg');
    await p.waitForTimeout(800);
    const cls = await p.evaluate(() => window.__cls);
    t.ok(`${label}: the Price history page has a good layout shift score (under 0.1)`, cls < 0.1, cls.toFixed(3));
    await ctx.close();
  }

  // ---- the plain list on a phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'radios.php');
  t.ok('phone: the plain list does not scroll the page sideways (the table scrolls inside its box)', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
