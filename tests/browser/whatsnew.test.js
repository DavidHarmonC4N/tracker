'use strict';
// The What's new fold-up and the version stamp must agree with each other on every page that carries them.
const { run } = require('./lib');

run(async (t) => {
  const ctx = await t.openContext({}, { unfold: false });
  const p = t.watch(await ctx.newPage());
  const pages = ['index.html', 'prices.html', 'alerts.html', 'radios.php'];
  const stamps = [];
  for (const page of pages) {
    await p.goto(t.SITE + page); await p.waitForSelector('#whats-new');
    t.ok(`${page}: What's new starts closed`, (await p.$eval('#whats-new', (e) => e.open)) === false);
    const version = (await p.textContent('#whats-new summary .ver')).trim();
    const code = (await p.textContent('#whats-new summary .code')).trim();
    t.ok(`${page}: the closed row shows a version and codename`, /^v\d+\.\d+$/.test(version) && code.length > 2, version + ' ' + code);
    await p.click('#whats-new summary');
    t.ok(`${page}: it opens on click`, await p.$eval('#whats-new', (e) => e.open));
    const releases = await p.$$eval('#whats-new .rel .rel-title', (h) => h.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
    t.ok(`${page}: the newest release matches the closed row`, releases[0].toLowerCase().replace(/\s/g, '').startsWith((version + code).toLowerCase()), releases[0]);
    t.ok(`${page}: three releases are listed, newest first`, releases.length === 3);
    const versions = releases.map((r) => r.match(/v(\d+)\.(\d+)/)).map((m) => Number(m[1]) * 1000 + Number(m[2]));
    t.ok(`${page}: versions descend`, versions[0] > versions[1] && versions[1] > versions[2], versions.join());
    t.ok(`${page}: each release has feature bullets`, (await p.$$eval('#whats-new .rel', (r) => r.every((x) => x.querySelectorAll('li').length >= 2))));
    await p.focus('#whats-new summary'); await p.keyboard.press('Enter');
    t.ok(`${page}: Enter closes it again`, (await p.$eval('#whats-new', (e) => e.open)) === false);
    const stamp = (await p.textContent('.stamp')).replace(/\s+/g, ' ').trim();
    stamps.push(stamp);
    t.ok(`${page}: the footer stamp names the same release`, stamp.includes(version) && stamp.includes(code), stamp);
    t.ok(`${page}: the call sign is in the footer`, /KY4DEH/.test(stamp));
  }
  t.ok('every page shows the same stamp', new Set(stamps).size === 1);
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'index.html'); await m.waitForSelector('#whats-new'); await m.click('#whats-new summary');
  t.ok('phone: no sideways scroll with it open', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
