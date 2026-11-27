'use strict';
// A listing that ships from the UK: a UK flag and label in the catalog, the Ships from filter, the options table, and the Admin form.
const { run } = require('./lib');

const PASSWORD = 'correct horse battery staple';
const SETUP_KEY = '0123456789abcdef0123456789abcdef0123';

run(async (t) => {
  const seed = await (await fetch(t.SITE + 'catalog.php')).json();
  const t1000 = seed.items.filter((i) => /Card Tracker T1000-E/.test(i.name) && /seeed/i.test(i.seller));
  const cnId = t1000.find((i) => i.warehouse === 'cn').id;
  const ctx = await t.openContext({ viewport: { width: 1280, height: 900 } }, { unfold: false });
  const now = new Date().toISOString();
  await t.mockCatalog(ctx, (data) => {
    data.items.forEach((i, n) => { i.result = { ...i.result, status: 'in_stock', observed_at: now, price: 5000 + n }; });
    const china = data.items.find((i) => i.id === cnId);
    china.warehouse = 'uk'; // the same product, with its third option shipping from the UK
  });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');

  const options = await p.$$eval('#warehouse option', (o) => o.map((x) => x.value + ':' + x.textContent));
  t.ok('the Ships from filter offers the UK, between EU and China', options.join('|') === ':Any warehouse|us:U.S. warehouse|eu:EU warehouse|uk:UK warehouse|cn:China warehouse', options.join('|'));

  const card = p.locator('.card', { has: p.locator('h3', { hasText: /Card Tracker T1000-E/ }) }).filter({ hasText: /Seeed Studio/ }).first();
  const chips = await card.locator('.facts .fact.us, .facts .fact.eu, .facts .fact.uk, .facts .fact.cn').allTextContents();
  t.ok('the card shows the UK chip, in order after the EU', chips.map((c) => c.trim()).join('|') === 'U.S.|EU|UK', chips.join('|'));
  t.ok('the UK chip has its own flag, drawn on the page', (await card.locator('.fact.uk svg.flag').count()) === 1 && (await card.locator('.fact.uk svg.flag path').count()) === 4);
  t.ok('the UK chip is not styled like the other places', await card.locator('.fact.uk').evaluate((n) => getComputedStyle(n).color !== getComputedStyle(n.parentElement.querySelector('.fact.eu')).color));
  await card.locator('summary').click();
  const rows = (await card.locator('.optable tbody th').allTextContents()).map((s) => s.replace(/\s+/g, ' ').trim());
  t.ok('the options table lists U.S., EU and UK warehouses in that order', rows.join('|') === 'U.S. warehouse|EU warehouse|UK warehouse', rows.join('|'));

  await p.selectOption('#warehouse', 'uk');
  const cards = await p.$$eval('.card h3', (h) => h.map((x) => x.textContent));
  t.ok('Ships from UK shows only the product that has a UK option, as a single plain card', cards.length === 1 && /Card Tracker T1000-E/.test(cards[0]) && (await p.$$('.card[data-options]')).length === 0, cards.join(' | '));
  t.ok('that card says UK', /UK/.test(await p.locator('.card .facts').first().textContent()));
  t.ok('the choice is saved in the link', /warehouse=uk/.test(await p.evaluate(() => location.href)));
  await p.selectOption('#warehouse', '');
  await p.click('[data-chip="seeed"]');
  const sections = await p.$$eval('.group-head h3', (h) => h.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
  t.ok('the Seeed filter has a UK warehouse section, in order', sections.some((s) => /^UK warehouse · 1/.test(s)) && sections.findIndex((s) => /^EU/.test(s)) < sections.findIndex((s) => /^UK/.test(s)) && sections.findIndex((s) => /^UK/.test(s)) < sections.findIndex((s) => /^China/.test(s)), sections.join(' | '));

  // ---- Admin
  const admin = await t.browser.newContext({ viewport: { width: 1100, height: 900 } });
  const a = t.watch(await admin.newPage());
  await a.goto(t.ADMIN);
  if (await a.$('input[name="setup_key"]')) { await a.fill('input[name="setup_key"]', SETUP_KEY); await a.fill('input[name="password"]', PASSWORD); await a.click('button[value="setup"]'); await a.waitForLoadState(); }
  if (await a.$('input[name="password"]')) { await a.fill('input[name="password"]', PASSWORD); await a.click('button[value="login"]'); await a.waitForLoadState(); }
  await a.goto(t.ADMIN + '?add=1');
  const adminOptions = await a.$$eval('select[name="warehouse"] option', (o) => o.map((x) => x.value + ':' + x.textContent));
  t.ok('the Admin form can set a listing to ship from the UK', adminOptions.join('|') === ':Not specified|us:U.S. warehouse|eu:EU warehouse|uk:UK warehouse|cn:China warehouse', adminOptions.join('|'));
});
