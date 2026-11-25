'use strict';
// Layout details that broke before: long card titles, the hero, the radar, the favicon, and off-site links.
const { run } = require('./lib');

run(async (t) => {
  for (const [width, label] of [[1280, 'desktop'], [1000, 'tablet'], [390, 'phone']]) {
    const ctx = await t.openContext({ viewport: { width, height: 900 } });
    await t.mockCatalog(ctx, (data) => { data.items.forEach((item) => { item.result = { ...item.result, status: 'in_stock', observed_at: new Date().toISOString() }; }); });
    const p = t.watch(await ctx.newPage(), label + ': ');
    await p.goto(t.SITE + 'index.html?seller=seeed'); await p.waitForSelector('.card');
    const titles = await p.$$eval('.card h3', (hs) => hs.filter((h) => /T1000|MeshTracker|Solar Node/.test(h.textContent)).map((h) => {
      const lineHeight = parseFloat(getComputedStyle(h).lineHeight);
      const range = document.createRange(); range.selectNodeContents(h);
      const rects = [...range.getClientRects()];
      return { text: h.textContent, lines: Math.round(h.getBoundingClientRect().height / lineHeight), orphan: rects.length > 1 && rects[rects.length - 1].width < 24 };
    }));
    t.ok(`${label}: long titles stay within two lines`, titles.length > 0 && titles.every((x) => x.lines <= 2), titles.filter((x) => x.lines > 2).map((x) => x.text).join(' | '));
    t.ok(`${label}: no title ends with a stranded fragment such as the "E" of T1000-E`, titles.every((x) => !x.orphan));
    t.ok(`${label}: no sideways scroll`, !(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
    await ctx.close();
  }

  const ctx = await t.openContext();
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('#list > *');
  const h1 = await p.$eval('.hero h1', (e) => { const c = getComputedStyle(e); return { stroke: c.webkitTextStrokeWidth, order: c.paintOrder }; });
  t.ok('the hero heading has its outline', h1.stroke === '4px' && /stroke/.test(h1.order), JSON.stringify(h1));
  t.ok('the radar sweeps', (await p.$eval('.radar-sweep', (e) => getComputedStyle(e).animationName)) === 'radar-sweep');
  const still = await t.browser.newContext({ reducedMotion: 'reduce' });
  const r = t.watch(await still.newPage(), 'reduced motion: ');
  await r.goto(t.SITE + 'index.html'); await r.waitForSelector('#list > *');
  t.ok('the radar stops for people who ask for less motion', (await r.$eval('.radar-sweep', (e) => getComputedStyle(e).animationName)) === 'none');
  t.ok('the favicon is linked and served', (await p.getAttribute('link[rel=icon]', 'href')) === 'assets/favicon.svg' && (await (await p.request.get(t.SITE + 'assets/favicon.svg')).status()) === 200);

  // every link that leaves the site opens in a new tab, and says so to screen readers
  for (const page of ['index.html', 'prices.html', 'alerts.html', 'submit.php']) {
    await p.goto(t.SITE + page);
    const links = await p.$$eval('a[href^="http"]', (a, own) => a.filter((x) => !own.includes(x.host)).map((x) => ({ href: x.href, target: x.target, rel: x.rel, text: x.textContent, label: x.getAttribute('aria-label') || '' })), t.OWN_HOSTS);
    const bad = links.filter((l) => l.target !== '_blank' || !/noopener/.test(l.rel));
    t.ok(`${page}: every off-site link opens in a new tab`, bad.length === 0, bad.map((l) => l.href).join(' , '));
    if (page !== 'submit.php') t.ok(`${page}: there are off-site links to check`, links.length > 3, String(links.length));
    const textLinks = links.filter((l) => l.text.trim() !== '' && !/^\s*$/.test(l.text));
    t.ok(`${page}: text links say they open a new tab`, textLinks.every((l) => /new tab/.test(l.text + ' ' + l.label)) || textLinks.length === 0, textLinks.filter((l) => !/new tab/.test(l.text + ' ' + l.label)).map((l) => l.href).join(' , '));
  }
});
