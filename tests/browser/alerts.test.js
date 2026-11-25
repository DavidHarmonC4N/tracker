'use strict';
// The Phone alerts page: steps, app store links, the topic read from the site, per-radio topics, and the off state.
const { run } = require('./lib');

run(async (t) => {
  const TOPIC = 'https://ntfy.sh/offgrid-comms-demo';
  const ctx = await t.openContext({ permissions: ['clipboard-read', 'clipboard-write'] });
  let first = null;
  await t.mockCatalog(ctx, (data) => {
    data.public_topic = TOPIC;
    data.items.forEach((item, i) => { item.topic = TOPIC + '-' + String(i).padStart(8, '0'); });
    first = data.items[2];
  });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.SITE + 'alerts.html');
  await p.waitForFunction(() => document.getElementById('topic-name').textContent !== 'Loading…');

  // content
  t.ok('the page explains itself', /Phone alerts/.test(await p.textContent('h1')) && /free/i.test(await p.textContent('.lead')) && /no account/i.test(await p.textContent('.lead')));
  const steps = await p.$$eval('.guide-steps > li h2', (h) => h.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
  t.ok('three numbered steps', steps.length === 3 && /^1\s*Install/.test(steps[0]) && /^2\s*Follow/.test(steps[1]) && /^3\s*Or follow/.test(steps[2]), steps.join(' | '));
  t.ok('it shows what an alert looks like and what to know', (await p.$$('.sample')).length === 1 && /topic is public/i.test(await p.textContent('#know + ul')));

  // app store links
  const badges = await p.$$eval('.badge-link', (a) => a.map((x) => ({ href: x.href, target: x.target, rel: x.rel, alt: x.querySelector('img').alt, loaded: x.querySelector('img').naturalWidth > 0 })));
  t.ok('an App Store button for ntfy', badges.some((b) => b.href === 'https://apps.apple.com/us/app/ntfy/id1625396347' && /App Store/.test(b.alt)));
  t.ok('a Google Play button for ntfy', badges.some((b) => b.href === 'https://play.google.com/store/apps/details?id=io.heckel.ntfy' && /Google Play/.test(b.alt)));
  t.ok('the buttons open in a new tab and their images load', badges.length === 2 && badges.every((b) => b.target === '_blank' && /noopener/.test(b.rel) && b.loaded), JSON.stringify(badges.map((b) => b.loaded)));
  t.ok('F-Droid is offered for Android without Google Play', (await p.$$eval('a[href*="f-droid.org"]', (a) => a.length)) === 1);

  // the topic comes from the site
  t.ok('the topic name is read from the site', (await p.textContent('#topic-name')) === 'offgrid-comms-demo');
  t.ok('Open in your browser links to the topic page in a new tab', (await p.$eval('#open-topic', (a) => !a.hidden && a.href === 'https://ntfy.sh/offgrid-comms-demo' && a.target === '_blank' && /noopener/.test(a.rel))));
  t.ok('the "not turned on" notice is hidden', await p.$eval('#alerts-off', (e) => e.hidden));
  await p.click('#copy-topic');
  t.ok('Copy name puts only the topic name on the clipboard', (await p.evaluate(() => navigator.clipboard.readText())) === 'offgrid-comms-demo' && /Copied/.test(await p.textContent('#copy-status')));
  t.ok('no radio box without a radio in the link', await p.$eval('#radio-box', (e) => e.hidden));

  // one radio
  await p.goto(t.SITE + 'alerts.html?radio=' + first.id);
  await p.waitForFunction(() => !document.getElementById('radio-box').hidden);
  t.ok('a radio in the link shows its own topic', (await p.textContent('#radio-topic')) === first.topic.split('/').pop() && /offgrid-comms-demo-0000000\d/.test(await p.textContent('#radio-topic')));
  t.ok('and names the radio', (await p.textContent('#radio-name')).includes(first.name));
  await p.click('#copy-radio');
  t.ok('its Copy name copies the radio topic', (await p.evaluate(() => navigator.clipboard.readText())) === first.topic.split('/').pop());
  t.ok('its browser link goes to the radio topic', (await p.getAttribute('#open-radio', 'href')) === first.topic);
  await p.goto(t.SITE + 'alerts.html?radio=../../x');
  await p.waitForFunction(() => document.getElementById('topic-name').textContent !== 'Loading…');
  t.ok('a bad radio id in the link is ignored', await p.$eval('#radio-box', (e) => e.hidden));
  await p.goto(t.SITE + 'alerts.html?radio=does-not-exist');
  await p.waitForFunction(() => document.getElementById('topic-name').textContent !== 'Loading…');
  t.ok('an unknown radio is ignored', await p.$eval('#radio-box', (e) => e.hidden));

  // navigation
  t.ok('the page is linked from its own footer and the header still works', (await p.$$eval('a[href="alerts.html"]', (a) => a.length)) >= 1 && (await p.$$eval('header nav a', (a) => a.length)) >= 5);
  t.ok('the footer version stamp is present', /KY4DEH/.test(await p.textContent('.stamp')));

  // off
  const off = await t.openContext();
  const o = t.watch(await off.newPage(), 'off: ');
  await o.goto(t.SITE + 'alerts.html');
  await o.waitForFunction(() => document.getElementById('topic-name').textContent !== 'Loading…');
  t.ok('with no public topic the page says it is not turned on yet', !(await o.$eval('#alerts-off', (e) => e.hidden)) && /Not turned on/.test(await o.textContent('#topic-name')));
  t.ok('and offers the RSS feed meanwhile', (await o.$$eval('#alerts-off a[href="feed.php"]', (a) => a.length)) === 1);
  t.ok('the copy button stays disabled', await o.$eval('#copy-topic', (b) => b.disabled));

  // an unsafe topic is never shown
  const bad = await t.openContext();
  await t.mockCatalog(bad, (data) => { data.public_topic = 'javascript:alert(1)'; });
  const b = t.watch(await bad.newPage(), 'bad: ');
  await b.goto(t.SITE + 'alerts.html');
  await b.waitForFunction(() => document.getElementById('topic-name').textContent !== 'Loading…');
  t.ok('an unsafe topic address is treated as off', !(await b.$eval('#alerts-off', (e) => e.hidden)) && await b.$eval('#open-topic', (a) => a.hidden));

  // phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  await t.mockCatalog(phone, (data) => { data.public_topic = TOPIC; data.items.forEach((item) => { item.topic = TOPIC + '-abcdef12'; }); });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.SITE + 'alerts.html?radio=' + (await (await off.request.get(t.SITE + 'catalog.php')).json()).items[0].id);
  await m.waitForFunction(() => !document.getElementById('radio-box').hidden);
  t.ok('phone: no sideways scroll', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  t.ok('phone: the topic name is fully visible', await m.$eval('#topic-name', (e) => e.getBoundingClientRect().right <= innerWidth));
});
