'use strict';
// One card per product: listings of the same product (Seeed's three warehouses) are one card with their options inside.
const { run } = require('./lib');

run(async (t) => {
  const seed = await (await fetch(t.SITE + 'catalog.php')).json();
  const radios = seed.items.filter((i) => i.category === 'radio');
  const pages = new Set(radios.map((i) => i.page));
  const byName = (re) => radios.filter((i) => re.test(i.name));
  const t1000 = byName(/Card Tracker T1000-E/).filter((i) => /seeed/i.test(i.seller));
  const x1 = byName(/MeshTracker X1/).filter((i) => /seeed/i.test(i.seller));
  const p1pro = byName(/Solar Node P1-Pro/).filter((i) => /seeed/i.test(i.seller));
  t.ok('the starter data has products sold from several warehouses', t1000.length === 3 && x1.length === 3 && p1pro.length === 3);
  const wh = (list, w) => list.find((i) => i.warehouse === w).id;

  const ctx = await t.openContext({ viewport: { width: 1280, height: 900 } }, { unfold: false });
  const now = new Date().toISOString();
  await t.mockCatalog(ctx, (data) => {
    data.items.forEach((i, n) => { i.result = { ...i.result, status: 'in_stock', observed_at: now, price: 5000 + n * 11, qty: null }; i.low = null; i.high = null; i.was = null; });
    const set = (id, patch) => { const i = data.items.find((x) => x.id === id); i.result = { ...i.result, ...patch }; return i; };
    // X1: the U.S. option costs more
    set(wh(x1, 'us'), { price: 5199 }); set(wh(x1, 'eu'), { price: 4990 }); set(wh(x1, 'cn'), { price: 4990 });
    // T1000-E: one price everywhere
    t1000.forEach((i) => set(i.id, { price: 3990 }));
    // P1-Pro: the cheap U.S. option is sold out, the others are in stock
    set(wh(p1pro, 'us'), { price: 9000, status: 'out_of_stock' }); set(wh(p1pro, 'eu'), { price: 9390 }); set(wh(p1pro, 'cn'), { price: 9500 });
    // a lowest-seen option on the China listing of the X1
    const cn = data.items.find((x) => x.id === wh(x1, 'cn')); cn.low = 4990; cn.high = 5500;
  });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');
  const card = (re) => p.locator('.card', { has: p.locator('h3', { hasText: re }) }).filter({ hasText: /Seeed Studio/ }).first();

  // ---- fewer cards: one per product
  const total = await p.$$eval('.card', (c) => c.length);
  t.ok('the radio catalog shows one card per product, not one per listing', total === pages.size && total < radios.length, total + ' cards for ' + radios.length + ' listings');
  t.ok('the count line speaks of radios, and counts products', new RegExp('^' + pages.size + ' of ' + pages.size + ' radios').test((await p.textContent('#count')).trim()), await p.textContent('#count'));
  const multi = await p.$$eval('.card[data-options]', (c) => c.map((x) => Number(x.dataset.options)));
  t.ok('eleven products have several options, three each', multi.length === 11 && multi.every((n) => n === 3), multi.join());
  t.ok('a product with one option is the same plain card as before', await p.$$eval('.card:not([data-options])', (c) => c.length > 15 && c.every((x) => !x.querySelector('.optable') && !x.querySelector('.more'))));

  // ---- the grouped card
  const x1card = card(/MeshTracker X1/);
  t.ok('it says how many options there are', /Seeed Studio · 3 options/.test(await x1card.locator('.seller').textContent()));
  t.ok('it shows a chip for each warehouse', (await x1card.locator('.facts .fact.us, .facts .fact.eu, .facts .fact.cn').count()) === 3);
  t.ok('the price is the cheapest option and says From', /^From\s*\$49\.90$/.test((await x1card.locator('.price').textContent()).trim()), await x1card.locator('.price').textContent());
  const cheapest = await x1card.locator('a.retailer').getAttribute('href');
  const cheapestIds = [wh(x1, 'eu'), wh(x1, 'cn')].map((id) => radios.find((i) => i.id === id).url);
  t.ok('the main button says View cheapest option and opens a cheapest option in a new tab', /View cheapest option/.test(await x1card.locator('a.retailer').textContent()) && cheapestIds.includes(cheapest) && (await x1card.locator('a.retailer').getAttribute('target')) === '_blank');
  t.ok('the options are folded until asked for', (await x1card.locator('details.more').getAttribute('open')) === null && !(await x1card.locator('.optable').isVisible()));
  await x1card.locator('summary').click();
  t.ok('opening them shows a table with the three warehouses, U.S. first', (await x1card.locator('.optable').isVisible()) && JSON.stringify(await x1card.locator('.optable tbody th').allTextContents()).replace(/\s+/g, ' ').match(/U\.S\. warehouse.*EU warehouse.*China warehouse/) !== null);
  const prices = (await x1card.locator('.optable tbody td.num').allTextContents()).map((s) => s.trim());
  t.ok('each option shows its own price', prices.join() === '$51.99,$49.90,$49.90', prices.join());
  t.ok('the cheapest in-stock options are highlighted', (await x1card.locator('.optable tr.best').count()) === 2);
  const views = await x1card.locator('.optable a').evaluateAll((a) => a.map((x) => ({ href: x.href, target: x.target, rel: x.rel, label: x.getAttribute('aria-label') })));
  t.ok('every option has its own View link: https, new tab, labelled', views.length === 3 && views.every((v) => v.href.startsWith('https://') && v.target === '_blank' && /noopener/.test(v.rel) && /new tab/.test(v.label)), JSON.stringify(views.map((v) => v.label)));
  t.ok('the table has a caption and column headers for assistive tech', (await x1card.locator('.optable caption').count()) === 1 && (await x1card.locator('.optable thead th[scope="col"]').count()) === 3);
  t.ok('the details link is still there', await x1card.locator('a.details').count() === 1);

  const sameCard = card(/Card Tracker T1000-E/);
  t.ok('products with one price everywhere say so, and the button is plain', /Same price in all 3/.test(await sameCard.locator('.price small').textContent()) && /View listing/.test(await sameCard.locator('a.retailer').textContent()));

  // ---- status: the best option decides
  const p1 = card(/Solar Node P1-Pro/);
  t.ok('a product is In stock when any option is, with the sold-out one still listed', /In stock/.test(await p1.locator('.tag').first().textContent()));
  await p1.locator('summary').click();
  const p1rows = await p1.locator('.optable tbody tr').evaluateAll((r) => r.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
  t.ok('the table shows which option is out of stock', p1rows.length === 3 && /U\.S\. warehouse.*Out of stock/.test(p1rows[0]) && /EU warehouse.*In stock/.test(p1rows[1]), p1rows.join(' | '));
  t.ok('the price is the cheapest option that is on sale, not the sold-out one', /\$93\.90/.test(await p1.locator('.price').textContent()));

  // ---- badges look at every option
  t.ok('Lowest seen shows when any option is at its lowest', (await card(/MeshTracker X1/).locator('.badge.low').count()) === 1);

  // ---- sorting and filters work on the cheapest option
  const order = await p.$$eval('.card .price', (e) => e.map((x) => Number((x.textContent.match(/\$([\d,]+\.\d\d)\s*$/) || [])[1]?.replace(',', '')) || null).filter((v) => v !== null));
  t.ok('the list is still cheapest first, using each card\'s lowest price', order.every((v, i) => i === 0 || order[i - 1] <= v), order.slice(0, 6).join(', '));
  await p.selectOption('#warehouse', 'us');
  const usCards = await p.$$eval('.card', (c) => c.length);
  t.ok('Ships from U.S. keeps each product but shows only its U.S. option, as a plain card', usCards > 0 && usCards <= pages.size && (await p.$$('.card[data-options]')).length === 0);
  await p.selectOption('#warehouse', '');
  await p.fill('#pmax', '45');
  const underCards = await p.$$eval('.card .price', (e) => e.map((x) => x.textContent));
  t.ok('a price limit keeps a product when its cheapest option fits, and drops it when none does', (await p.locator('.card h3', { hasText: /Card Tracker T1000-E/ }).count()) === 1 && (await p.locator('.card h3', { hasText: /MeshTracker X1/ }).count()) === 0, underCards.join(' | '));
  await p.fill('#pmax', '');

  // ---- tabs count products
  const availableTab = Number((await p.textContent('.status-tab[data-tab="available"] .tab-count')).trim());
  t.ok('the Available tab counts products', availableTab === (await p.$$eval('.card', (c) => c.length)), String(availableTab));
  const allTab = Number((await p.textContent('.status-tab[data-tab="all"] .tab-count')).trim());
  t.ok('the All tab counts products, not listings', allTab === pages.size, String(allTab));

  // ---- starring a grouped card follows every option
  const star = x1card.locator('.star');
  await star.click();
  const picked = await p.evaluate(() => Object.keys(JSON.parse(localStorage.getItem('offgrid.picks.v1') || '{}')));
  t.ok('starring a grouped card watches all three options', x1.every((i) => picked.includes(i.id)) && picked.length === 3, picked.join());
  t.ok('the star shows as on, and the counter counts listings', (await star.getAttribute('aria-pressed')) === 'true' && (await p.textContent('#picks-n')).trim() === '3');
  await star.click();
  t.ok('starring again removes all of them', (await p.evaluate(() => Object.keys(JSON.parse(localStorage.getItem('offgrid.picks.v1') || '{}')).length)) === 0 && (await star.getAttribute('aria-pressed')) === 'false');
  await star.click();
  await p.click('#picks-btn');
  t.ok('My picks lists each watched option, as before', (await p.$$eval('.card', (c) => c.length)) === 3 && (await p.$$('.card[data-options]')).length === 0);
  await p.click('#picks-btn');

  // ---- the Seeed filter keeps its warehouse sections
  await p.click('[data-chip="seeed"]');
  t.ok('the Seeed filter lists each warehouse on its own, ungrouped', (await p.$$('.card[data-options]')).length === 0 && (await p.$$('.group-head h3')).length >= 2);
  await p.click('[data-chip="seeed"]');

  // ---- antennas and the other tabs are untouched
  await p.click('[data-cat-tab="antenna"]');
  t.ok('antenna cards are unchanged', (await p.$$('.card')).length >= 20 && (await p.$$('.card .optable')).length === 0);

  // ---- phone
  const phone = await t.openContext({ viewport: { width: 390, height: 844 } }, { unfold: false });
  await t.mockCatalog(phone, (data) => { data.items.forEach((i) => { i.result = { ...i.result, status: 'in_stock', observed_at: now, price: 5000 }; }); });
  const m = t.watch(await phone.newPage());
  await m.goto(t.SITE + 'index.html'); await m.waitForSelector('.card[data-options]');
  await m.locator('.card[data-options] summary').first().click();
  t.ok('phone: an opened options table does not make the page scroll sideways', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  t.ok('phone: the table stays inside its card', await m.$eval('.card[data-options] .optable', (tb) => tb.closest('.card').getBoundingClientRect().right >= tb.getBoundingClientRect().right - 1 || tb.closest('.opt-wrap').scrollWidth >= tb.scrollWidth));
});
