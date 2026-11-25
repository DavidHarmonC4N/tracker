'use strict';
// Catalog filters: tabs, quick filters, price range, Lowest seen and Limited stock, the badges, and the link that remembers them.
const { run } = require('./lib');

run(async (t) => {
  const ctx = await t.openContext({ viewport: { width: 1280, height: 900 } });
  const ago = (days) => new Date(Date.now() - days * 86400000).toISOString();
  await t.mockCatalog(ctx, (data) => {
    const now = new Date().toISOString();
    data.items.forEach((item, i) => {
      item.result = { ...item.result, status: 'in_stock', observed_at: now, price: 3000 + i * 150, qty: null };
      item.low = null; item.high = null; item.was = null;
    });
    data.items.forEach((item) => { item.manual = /etsy\.com/.test(item.url); }); // the starter list has none, your live admin does
    const a = data.items;
    a[0].result.price = 4000; a[0].low = 4000; a[0].high = 4500; a[0].was = { price: 4500, t: ago(9) };
    a[1].result.price = 5200; a[1].result.qty = 2;
    a[2].result.price = 6797; a[2].low = 6797; a[2].high = 7495; a[2].was = { price: 7495, t: ago(17) };
    a[3].result.price = 9000; a[3].low = 8000; a[3].high = 9000; a[3].was = { price: 8000, t: ago(3) }; // a rise, not a drop
    a[4].result.price = 3000; a[4].result.qty = 9; // more than five: not limited
  });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');
  const names = () => p.$$eval('.card h3', (h) => h.map((x) => x.textContent));
  const total = (await names()).length;
  t.ok('the catalog loads', total > 8, String(total));

  const tabs = await p.$$eval('.status-tab', (b) => b.map((x) => x.dataset.tab));
  t.ok('seven availability tabs', tabs.join() === 'available,in_stock,orderable,preorder,out_of_stock,unknown,all', tabs.join());
  t.ok('the tabs sit on one row', (await p.$$eval('.status-tab', (b) => new Set(b.map((x) => Math.round(x.getBoundingClientRect().top))).size)) === 1);
  t.ok('cards have a type icon and signal bars', (await p.$$eval('.card .ico svg', (s) => s.length)) > 0 && (await p.$$eval('.card .tag .bars', (s) => s.length)) > 0);
  t.ok('Clear all is hidden until something is active', await p.$eval('#reset', (e) => e.hidden));

  // badges
  t.ok('Lowest seen badges appear only at the recorded low', (await p.$$eval('.badge.low', (e) => e.length)) === 2);
  t.ok('Limited stock shows only for a count of five or fewer', (await p.$$eval('.badge.ltd', (e) => e.map((x) => x.textContent))).join() === 'Limited · 2 left');
  const drops = await p.$$eval('.badge.mute', (e) => e.map((x) => x.textContent));
  t.ok('recent drops show the amount and the age', drops.some((d) => /Dropped \$5\.00 · 9 days ago/.test(d)) && drops.some((d) => /Dropped \$6\.98 · 17 days ago/.test(d)), drops.join(' | '));
  t.ok('a price rise is not shown as a drop', !drops.some((d) => /\$10\.00/.test(d)));
  t.ok('a dropped radio shows its old price', (await p.$$eval('.price small', (s) => s.map((x) => x.textContent))).some((x) => /^was \$/.test(x)));

  // quick filters
  await p.click('[data-chip="lowest"]');
  t.ok('the Lowest seen chip filters to those radios and is saved in the link', (await names()).length === 2 && /lowest=1/.test(p.url()));
  await p.click('[data-chip="lowest"]'); await p.click('[data-chip="limited"]');
  t.ok('the Limited stock chip filters to the one radio', (await names()).length === 1);
  await p.click('[data-chip="limited"]');
  t.ok('these chips do not force the All tab', (await p.$eval('.status-tab.active', (b) => b.dataset.tab)) === 'available');
  await p.click('[data-chip="seeed"]');
  t.ok('the Seeed chip opens the All tab and groups by warehouse', (await p.$eval('.status-tab.active', (b) => b.dataset.tab)) === 'all' && /warehouse/.test(await p.textContent('.group-head h3')));
  await p.click('[data-chip="seeed"]'); await p.click('[data-chip="manual"]');
  t.ok('Checked by hand lists the manual sellers, each with an asterisk', (await p.$$eval('.card .asterisk', (s) => s.length)) === (await names()).length && (await names()).length > 0);
  await p.click('#reset'); await p.waitForTimeout(150);

  // price range
  await p.fill('#pmin', '40'); await p.fill('#pmax', '60');
  const shown = (await names()).length;
  const prices = await p.$$eval('.card .price', (e) => e.map((x) => parseFloat(x.lastChild.textContent.replace(/[^0-9.]/g, ''))));
  t.ok('the price filter keeps only radios between $40 and $60', shown > 0 && shown < total && prices.every((v) => v >= 40 && v <= 60), prices.slice(0, 4).join(','));
  t.ok('the range is saved in the link', /min=40/.test(p.url()) && /max=60/.test(p.url()));
  t.ok('an active chip shows the range', (await p.$$eval('#active-chips .chip', (c) => c.map((x) => x.textContent))).some((x) => /Price: \$40 to \$60/.test(x)));
  await p.click('#active-chips .chip');
  t.ok('removing that chip clears both boxes', (await p.inputValue('#pmin')) === '' && (await p.inputValue('#pmax')) === '' && (await names()).length === total);
  await p.fill('#pmax', '35');
  t.ok('a maximum alone works', (await names()).length > 0 && (await names()).length < total);

  // other filters
  await p.click('#reset'); await p.waitForTimeout(150);
  await p.selectOption('#role', 'companion'); await p.selectOption('#warehouse', 'us');
  t.ok('select filters show as removable chips', (await p.$$('#active-chips .chip')).length === 2);
  await p.click('#active-chips .chip');
  t.ok('removing a chip clears that filter and keeps the rest', (await p.inputValue('#role')) === '' && (await p.$$('#active-chips .chip')).length === 1);
  t.ok('focus moves to the chip group after removing one', (await p.evaluate(() => document.activeElement.id)) === 'active-chips');
  await p.fill('#search', 'zzzz-no-match');
  t.ok('an empty result offers to clear the filters', /No (available )?radios match/.test(await p.textContent('.empty')));
  await p.click('.empty .text-button'); await p.waitForTimeout(150);
  t.ok('Clear filters resets everything', (await p.$$('#active-chips .chip')).length === 0 && (await names()).length === total);

  // links
  await p.click('[data-chip="lowest"]'); await p.fill('#pmin', '30'); await p.fill('#pmax', '45');
  await p.goto(p.url()); await p.waitForSelector('#list > *');
  t.ok('filters come back from the link', (await p.inputValue('#pmin')) === '30' && (await p.inputValue('#pmax')) === '45' && (await p.getAttribute('[data-chip="lowest"]', 'aria-pressed')) === 'true');
  await p.goto(t.SITE + 'index.html?min=abc&max=-5&status=bogus'); await p.waitForSelector('.card');
  t.ok('junk values in the link are ignored', (await p.inputValue('#pmin')) === '' && (await p.inputValue('#pmax')) === '' && (await p.$eval('.status-tab.active', (b) => b.dataset.tab)) === 'available');
  await p.goto(t.SITE + 'index.html?status=seeed'); await p.waitForSelector('#list > *');
  t.ok('an old ?status=seeed link still turns on the chip', (await p.getAttribute('[data-chip="seeed"]', 'aria-pressed')) === 'true');

  // keyboard and phone
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');
  await p.focus('[data-chip="seeed"]'); await p.keyboard.press('Enter');
  t.ok('a chip works from the keyboard', (await p.getAttribute('[data-chip="seeed"]', 'aria-pressed')) === 'true');
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  await t.mockCatalog(phone, (data) => { data.items.forEach((item) => { item.result = { ...item.result, status: 'in_stock', observed_at: new Date().toISOString() }; }); });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'index.html'); await m.waitForSelector('.card');
  t.ok('phone: no sideways scroll with the filter row', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  t.ok('phone: the select filters fold behind a Filters button', !(await m.$eval('#filter-selects', (e) => e.offsetParent !== null)) && await m.$eval('#filters-toggle', (e) => e.offsetParent !== null));
  await m.click('#filters-toggle'); await m.selectOption('#role', 'companion');
  t.ok('phone: the Filters button counts the active filters', (await m.textContent('#filters-n')).trim() === '1');
});
