'use strict';
// My picks: the star, the dashboard, change notes, copy link, personal RSS, shared links, clear all.
const { run } = require('./lib');

run(async (t) => {
  const ctx = await t.openContext({ permissions: ['clipboard-read', 'clipboard-write'] });
  let mode = 'a'; // 'a' = the radios as first starred, 'b' = after a restock and a price drop
  await t.mockCatalog(ctx, (data) => {
    const now = new Date().toISOString();
    data.items.slice(0, 4).forEach((item, i) => {
      item.result = { ...item.result, status: i === 0 ? (mode === 'a' ? 'out_of_stock' : 'in_stock') : 'in_stock',
        price: i === 1 ? (mode === 'a' ? 8000 : 7500) : 5000 + i, observed_at: now };
    });
  });
  const p = t.watch(await ctx.newPage());
  p.on('dialog', (dialog) => dialog.accept());
  await p.goto(t.SITE + 'index.html'); await p.waitForSelector('.card');

  const stars = await p.$$('.card .star');
  t.ok('every card has a star', stars.length === (await p.$$('.card')).length && stars.length >= 3, String(stars.length));
  t.ok('the star starts unpressed and the count is 0', (await stars[0].getAttribute('aria-pressed')) === 'false' && (await p.textContent('#picks-n')) === '0');

  await p.click('.status-tab[data-tab="all"]');
  const ids = await p.evaluate(async () => (await (await fetch('catalog.php')).json()).items.slice(0, 3).map((item) => item.id));
  for (const id of ids) { await p.click('#unverified-toggle').catch(() => {}); await p.click(`.star[data-pick="${id}"]`); }
  t.ok('starring three radios makes the count 3', (await p.textContent('#picks-n')) === '3');
  const first = `.star[data-pick="${ids[0]}"]`;
  t.ok('a starred radio is pressed and labelled "Stop watching"', (await p.getAttribute(first, 'aria-pressed')) === 'true' && /^Stop watching/.test(await p.getAttribute(first, 'aria-label')));

  mode = 'b';
  await p.reload(); await p.waitForSelector('.card');
  t.ok('picks survive a reload', (await p.textContent('#picks-n')) === '3');
  await p.click('#picks-btn');
  t.ok('My picks opens with view=picks in the link', /view=picks/.test(p.url()) && (await p.$('.dashhead h2')) !== null);
  t.ok('the tabs and filters are hidden in My picks', !(await p.$eval('#status-tabs', (e) => e.offsetParent !== null)) && !(await p.$eval('.filter-row', (e) => e.offsetParent !== null)));
  t.ok('all three picks are shown', (await p.$$('#list .card')).length === 3);
  const changes = await p.$$eval('.change', (e) => e.map((x) => x.textContent));
  t.ok('a restock and a price drop are flagged', changes.some((c) => /Back in stock/.test(c)) && changes.some((c) => /Price dropped \$5\.00/.test(c)), changes.join(' | '));
  t.ok('changed radios come first', await p.$eval('#list .card', (card) => !!card.querySelector('.change')));
  t.ok('each pick says when it was added', (await p.$$('.hist')).length === 3);
  t.ok('the header shows the latest stock reading', /latest stock reading/.test(await p.textContent('.dashhead')));
  t.ok('a Price history link is offered', (await p.getAttribute('.dashact a:has-text("Price history")', 'href')) === 'prices.html?scope=picks');

  await p.reload(); await p.waitForSelector('.card');
  t.ok('on the next visit the changes are already seen', (await p.$$('.change')).length === 0);

  await p.click('text=Copy my link');
  const copied = await p.evaluate(() => navigator.clipboard.readText());
  t.ok('Copy my link gives a ?picks= address', /\?picks=/.test(copied), copied);
  const rss = await p.getAttribute('a.sbtn:has-text("My RSS feed")', 'href');
  t.ok('My RSS feed points at feed.php with the ids', /^feed\.php\?ids=/.test(rss), rss);
  const feed = await (await p.request.get(t.SITE + rss)).text();
  t.ok('the personal feed is valid RSS', /<rss version="2.0">/.test(feed) && /My picks/.test(feed));

  const friend = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const q = t.watch(await friend.newPage(), 'friend: ');
  await q.goto(copied.replace(/^https?:\/\/[^/]+\//, t.SITE)); await q.waitForSelector('#list .card');
  t.ok('a shared link opens the picks for a new visitor', (await q.$$('#list .card')).length === 3 && /Shared picks/.test(await q.textContent('.dashhead h2')));
  t.ok('phone: no sideways scroll', !(await q.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  await q.click('text=Add all to my picks');
  t.ok('"Add all" saves them to my picks', (await q.textContent('#picks-n')) === '3' && !/shared/i.test(await q.textContent('.dashhead h2')));
  await q.goto(t.SITE + 'index.html?picks=../../x,<b>'); await q.waitForSelector('#list > *');
  t.ok('invalid ids in a link are ignored', (await q.$$('.dashhead')).length === 0);

  await p.click('.sbtn:has-text("Clear all")');
  t.ok('Clear all leaves the empty message', /Nothing starred yet/.test(await p.textContent('#list')));
  await p.click('#list .primary');
  t.ok('Browse radios returns to the catalog', await p.$eval('#status-tabs', (e) => e.offsetParent !== null));
});
