'use strict';
// The plain list (radios.php): one row per product, cheapest first, five columns that fit, and the same for the antennas section.
const { run } = require('./lib');

run(async (t) => {
  const catalog = await (await fetch(t.SITE + 'catalog.php')).json();
  const radioPages = new Set(catalog.items.filter((i) => i.category === 'radio').map((i) => i.page));
  const antennaPages = new Set(catalog.items.filter((i) => i.category === 'antenna').map((i) => i.page));

  for (const width of [1240, 1100, 990]) {
    const ctx = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(t.SITE + 'radios.php');
    t.ok(`${width}px: no table needs sideways scrolling and the page does not either`, !(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth || [...document.querySelectorAll('.rtable-wrap')].some((w) => w.scrollWidth > w.clientWidth + 1))));
    t.ok(`${width}px: the last column, Where to buy, is fully visible`, await p.$$eval('.rtable', (tb) => tb.every((x) => { const th = x.querySelector('thead th:last-child').getBoundingClientRect(); return th.right <= x.parentElement.getBoundingClientRect().right + 1; })));
  }

  const ctx = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width: 1240, height: 900 } });
  const p = await ctx.newPage();
  await p.goto(t.SITE + 'radios.php');
  const radioRows = await p.$$eval('#radios .rgroup:not(#antennas .rgroup) tbody tr, main > section.rgroup tbody tr', (r) => r.length);
  t.ok('the radios list has one row per product, not per listing', radioRows === radioPages.size, radioRows + ' rows for ' + radioPages.size + ' products');
  const antennaRows = await p.$$eval('#antennas tbody tr', (r) => r.length);
  t.ok('the antennas section also has one row per product', antennaRows === antennaPages.size && antennaRows > 30, antennaRows + ' rows');
  t.ok('every table has five columns: name, price, stock, ships from, where to buy', await p.$$eval('table.rtable', (tb) => tb.every((x) => [...x.querySelectorAll('thead th')].map((h) => h.textContent.trim()).join('|').match(/^(Radio|Name)\|Price\|Stock\|Ships from\|Where to buy$/))));
  const prices = await p.$$eval('table.rtable', (tables) => tables.map((tb) => [...tb.querySelectorAll('tbody td.num')].map((c) => { const m = c.textContent.match(/\$([\d,]+\.\d\d)/); return m ? Number(m[1].replace(',', '')) : null; })));
  t.ok('inside every table the prices run low to high, with no price last', prices.length >= 2 && prices.every((list) => { const known = list.filter((v) => v !== null); return known.every((v, i) => i === 0 || known[i - 1] <= v) && (list.indexOf(null) === -1 || list.slice(list.indexOf(null)).every((v) => v === null)); }), JSON.stringify(prices.map((l) => l.slice(0, 4))));
  t.ok('the antennas section is sorted the same way (by price, and by name when there is no price yet)', await p.$$eval('#antennas table.rtable', (tables) => tables.every((tb) => {
    const rows = [...tb.querySelectorAll('tbody tr')].map((r) => ({ price: Number(((r.querySelector('td.num').textContent.match(/\\$([\\d,]+\\.\\d\\d)/) || [])[1] || 'NaN').replace(',', '')), name: r.querySelector('th a').textContent }));
    return rows.length > 3 && rows.every((r, i) => { if (i === 0) return true; const q = rows[i - 1]; const a = Number.isNaN(q.price) ? Infinity : q.price; const b = Number.isNaN(r.price) ? Infinity : r.price; return a < b || (a === b && q.name.toLowerCase() <= r.name.toLowerCase()); });
  })));
  t.ok('the sections say they are cheapest first', (await p.$$('.rgroup .dim')).length >= 2 && (await p.$$eval('.rgroup > p.dim', (n) => n.every((x) => /Cheapest first/.test(x.textContent)))));

  // a Seeed product: one row, a link per warehouse in order
  const seeed = p.locator('tbody tr', { has: p.locator('th a', { hasText: 'SenseCAP Card Tracker T1000-E' }) }).first();
  const links = await seeed.locator('td.links a').evaluateAll((a) => a.map((x) => ({ text: x.firstChild.textContent.trim(), href: x.href, target: x.target, rel: x.rel })));
  t.ok('a Seeed product is one row with a link for the U.S., EU and China, in that order', links.map((l) => l.text).join() === 'U.S.,EU,China', JSON.stringify(links.map((l) => l.text)));
  t.ok('each link is https and opens in a new tab', links.length === 3 && links.every((l) => l.href.startsWith('https://') && l.target === '_blank' && /noopener/.test(l.rel)));
  t.ok('the row lists where it ships from, one per line', (await seeed.locator('td').nth(2).locator('.opt').allTextContents()).join('|').match(/U\.S\..*\|EU.*\|China/) !== null);
  t.ok('the name links to the product page and the seller sits under it', (await seeed.locator('th a').getAttribute('href')).startsWith('radio.php?r=') && /Seeed Studio/.test(await seeed.locator('th').textContent()));
  const single = p.locator('tbody tr', { has: p.locator('th a', { hasText: 'LILYGO T-Deck' }) }).first();
  t.ok('a one-listing product keeps a plain View listing link', (await single.locator('td.links a').allTextContents()).map((x) => x.trim().replace(/\s+for .*$/, '')).join() === 'View listing');
  t.ok('an antenna row shows its facts under the name', /915 MHz · \d/.test(await p.locator('#antennas tbody tr').first().locator('th').textContent()));
  t.ok('the lead counts products, not listings', new RegExp('^' + radioPages.size + ' radios from').test((await p.textContent('p.lead')).trim()), (await p.textContent('p.lead')).slice(0, 40));

  // phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = await phone.newPage();
  await m.goto(t.SITE + 'radios.php');
  t.ok('phone: the page itself does not scroll sideways (a table may, inside its box)', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
