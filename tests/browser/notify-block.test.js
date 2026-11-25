'use strict';
// The Notify me block on a radio page: shown only when a public topic is set, works without JavaScript, shares one topic per radio.
const { run } = require('./lib');

run(async (t) => {
  const ALERTS = process.env.ALERTS_URL;
  const crawler = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  const catalog = await (await crawler.request.get(ALERTS + 'catalog.php')).json();
  const seeed = catalog.items.filter((i) => /^seeed-t1000e-/.test(i.id));
  const ref = seeed[0].page;
  const topics = new Set(seeed.map((i) => i.topic));
  t.ok('listings of one radio share one catalog topic', seeed.length > 1 && topics.size === 1 && /^https:\/\/ntfy\.sh\/offgrid-test-public-[0-9a-f]{8}$/.test([...topics][0]), [...topics].join(' '));
  t.ok('different radios have different topics', new Set(catalog.items.map((i) => i.topic)).size === new Set(catalog.items.map((i) => i.page)).size);

  const page = await crawler.newPage();
  await page.goto(ALERTS + ref);
  const name = [...topics][0].split('/').pop();
  t.ok('the block is there with its heading and the radio\'s topic, with JavaScript off', /Get notified about this radio/.test(await page.textContent('#notify')) && (await page.textContent('#notify-topic')) === name);
  const contrast = await page.$eval('.notify-actions a.primary', (a) => {
    const lum = (c) => { const m = c.match(/\d+/g).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]; };
    const s = getComputedStyle(a); const [x, y] = [lum(s.color), lum(s.backgroundColor)];
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  });
  t.ok('the main button label is readable against its background', contrast > 7, contrast.toFixed(1) + ':1');
  const links = await page.$$eval('.notify-actions a', (a) => a.map((x) => x.getAttribute('href')));
  t.ok('the app link points at the topic', links[0] === 'ntfy://ntfy.sh/' + name, links[0]);
  t.ok('the RSS link is limited to this radio\'s listings', /^feed\.php\?ids=/.test(links[1]) && decodeURIComponent(links[1]).split('=')[1].split(',').length === seeed.length, links[1]);
  t.ok('How alerts work goes to the alerts page', links[2] === 'alerts.html');
  t.ok('the Copy button stays hidden without JavaScript', await page.$eval('#notify-copy', (b) => b.hidden));
  t.ok('the page says the topic is public', /Anyone who knows the topic can read it/.test(await page.textContent('.notify-note')));

  // the RSS link works and only lists this radio
  const feed = await crawler.request.get(ALERTS + links[1]);
  t.ok('the per-radio feed answers with RSS', feed.status() === 200 && /rss/.test(feed.headers()['content-type']));

  // with JavaScript: the copy button appears and copies
  const ctx = await t.browser.newContext({ viewport: { width: 1280, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const js = t.watch(await ctx.newPage());
  await js.goto(ALERTS + ref);
  t.ok('with JavaScript the Copy button shows', await js.$eval('#notify-copy', (b) => !b.hidden));
  await js.click('#notify-copy');
  t.ok('Copy puts the topic on the clipboard and says so', (await js.evaluate(() => navigator.clipboard.readText())) === name && /Copied/.test(await js.textContent('#notify-status')));

  // phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage());
  await m.goto(ALERTS + ref);
  t.ok('phone: no sideways scroll and the buttons are reachable', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)) && (await m.$$eval('.notify-actions a', (a) => a.every((x) => x.getBoundingClientRect().width > 80))));

  // off: no public topic means no block, and nothing about it on the page
  const off = await (await crawler.request.get(t.SITE + ref)).text();
  t.ok('with no public topic the block is not on the page', !/id="notify"/.test(off) && !/notify\.js/.test(off));
});
