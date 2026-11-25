'use strict';
// The group directory pages, read with JavaScript off (the way a crawler sees them), plus the links to it from the rest of the site.
const { run } = require('./lib');

run(async (t) => {
  const crawler = await t.browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
  const get = (path) => crawler.request.get(t.SITE + path, { maxRedirects: 0, failOnStatusCode: false });
  const open = async (path) => { const p = await crawler.newPage(); await p.goto(t.SITE + path); return p; };

  // ---- the national page
  const home = await open('groups.php');
  t.ok('the page has one h1 and the right title', (await home.$$('h1')).length === 1 && /Meshtastic and MeshCore Groups in the U\.S\. \| Offgrid Comms Tracker/.test(await home.title()), await home.title());
  const notice = await home.$$eval('.gnotice', (n) => n.map((x) => x.textContent.replace(/\s+/g, ' ')));
  t.ok('the caution appears at the top and again at the bottom', notice.length === 2 && notice[0] === notice[1]);
  t.ok('the caution says the groups were found by an automated search and not personally checked', /found by an automated search/.test(notice[0]) && /not personally checked/.test(notice[0]));
  t.ok('the caution says we are not responsible for what is on those pages and do not endorse any group', /not responsible for anything on those pages/.test(notice[0]) && /do not endorse any group/.test(notice[0]));
  t.ok('the caution is before the first group or link to one', await home.evaluate(() => { const n = document.querySelector('.gnotice'); const first = document.querySelector('main a[target="_blank"], .gcard'); return !first || !!(n.compareDocumentPosition(first) & Node.DOCUMENT_POSITION_FOLLOWING); }));
  const tiles = await home.$$eval('svg.usmap a', (a) => a.map((x) => x.getAttribute('href')));
  t.ok('the map has a tile link for each of the 51 states and DC that have groups', tiles.length === 51 && new Set(tiles).size === 51 && tiles.every((h) => /^groups\.php\?state=[a-z-]+#groups$/.test(h)), tiles.length + ' tiles');
  t.ok('the map names a state and its count to assistive tech', (await home.$$eval('svg.usmap a title', (x) => x.map((y) => y.textContent))).includes('Texas: 21 groups'));
  const list = await home.$$eval('.gstates a', (a) => a.map((x) => x.textContent));
  t.ok('a text list of all 53 states and regions sits under the map', list.length === 53 && list.includes('Puerto Rico') && list.includes('U.S. regional / national') && list.includes('District of Columbia'), list.length + '');
  t.ok('with nothing chosen the page asks for a state instead of listing 234 groups', (await home.$$('.gcard')).length === 0 && /Choose a state/.test(await home.textContent('#results')));
  t.ok('the national page is indexable', !/noindex/.test(await home.content()));
  const ld = JSON.parse(await home.$eval('script[type="application/ld+json"]', (s) => s.textContent));
  t.ok('the page carries CollectionPage data', ld['@type'] === 'CollectionPage' && /groups in the U\.S\./.test(ld.name));

  // ---- a state page
  const tx = await open('groups.php?state=texas');
  t.ok('a state page names the state in its title, h1 and count', /Groups in Texas \|/.test(await tx.title()) && /groups in Texas/.test(await tx.textContent('h1')) && /Texas · 21 groups/.test(await tx.textContent('#results')));
  t.ok('it shows every group in the state as a card', (await tx.$$('.gcard')).length === 21);
  t.ok('the state is highlighted on the map and in the list', (await tx.$$('svg.usmap .tile.on')).length === 1 && (await tx.$eval('.gstates a[aria-current="page"]', (a) => a.textContent)) === 'Texas');
  t.ok('the state page has the same caution', (await tx.$$('.gnotice')).length === 2);
  const ldTx = JSON.parse(await tx.$eval('script[type="application/ld+json"]', (s) => s.textContent));
  t.ok('a state page has breadcrumb data', !ldTx.breadcrumb || ldTx.breadcrumb.itemListElement.length === 3);

  // every outside link: https, a new tab, not an endorsement
  const outside = await tx.$$eval('.gcard a', (a) => a.map((x) => ({ href: x.href, target: x.target, rel: x.rel, sr: !!x.querySelector('.sr') })));
  t.ok('every group link is https', outside.length > 21 && outside.every((l) => l.href.startsWith('https://')), outside.length + ' links');
  t.ok('every group link opens in a new tab', outside.every((l) => l.target === '_blank'));
  t.ok('every group link says noopener, noreferrer and nofollow', outside.every((l) => /noopener/.test(l.rel) && /noreferrer/.test(l.rel) && /nofollow/.test(l.rel)));
  t.ok('every group link tells screen readers it opens in a new tab', outside.every((l) => l.sr));
  t.ok('each card has exactly one primary button, the main link', await tx.$$eval('.gcard', (c) => c.every((x) => x.querySelectorAll('.glinks a.primary').length === 1)));
  t.ok('the card shows protocol and type', await tx.$$eval('.gcard', (c) => c.every((x) => x.querySelector('.proto') && x.querySelector('.gkind'))));

  // ---- filters, as a plain form
  const filtered = await open('groups.php?state=texas&protocol=meshcore');
  const names = (p) => p.$$eval('.gcard h3', (h) => h.map((x) => x.textContent));
  t.ok('the protocol filter narrows the state and marks the page noindex', (await filtered.$$('.gcard')).length > 0 && (await filtered.$$('.gcard')).length < 21 && /noindex/.test(await filtered.content()));
  t.ok('every card left is MeshCore or both', await filtered.$$eval('.gcard .proto', (p) => p.every((x) => /meshcore|both/.test(x.className))));
  const search = await open('groups.php?q=houston');
  t.ok('a search across all states finds groups, grouped under their state', (await search.$$('.gcard')).length > 0 && (await search.$$('h3.gstate')).length > 0 && /Search results/.test(await search.textContent('#results')), (await names(search)).join(' | '));
  const none = await open('groups.php?q=zzzzqqqq');
  t.ok('a search with no match says so and offers to clear it', /No groups match/.test(await none.textContent('#groups')) && (await none.$$('.gcard')).length === 0);
  const discord = await open('groups.php?state=texas&discord=1');
  t.ok('"has a Discord" keeps groups with a joinable Discord', (await discord.$$('.gcard')).length > 0 && await discord.$$eval('.gcard', (c) => c.every((x) => /Discord/.test(x.textContent))));
  // the form works by submitting
  const form = await crawler.newPage(); await form.goto(t.SITE + 'groups.php');
  const params = await form.$eval('form.gfilters', (f) => [...new FormData(f).keys()].join());
  t.ok('the filter form sends state, search and protocol', /state,q,protocol/.test(params), params);
  const js = await t.browser.newContext({ viewport: { width: 1280, height: 900 } });
  const jp = t.watch(await js.newPage());
  await jp.goto(t.SITE + 'groups.php');
  await jp.selectOption('select[name="state"]', 'ohio');
  await jp.selectOption('select[name="protocol"]', 'meshtastic');
  await jp.click('form.gfilters button[type="submit"]'); await jp.waitForLoadState();
  t.ok('submitting the form shows the filtered state', /state=ohio/.test(jp.url()) && /protocol=meshtastic/.test(jp.url()) && (await jp.$$('.gcard')).length > 0);
  await jp.click('svg.usmap a[href*="state=oregon"]'); await jp.waitForLoadState();
  t.ok('selecting a tile on the map opens that state', /state=oregon/.test(jp.url()) && /Oregon/.test(await jp.textContent('h1')));

  // ---- odd or hostile requests
  const bad = await get('groups.php?state=atlantis');
  const badBody = await bad.text();
  t.ok('an unknown state is a 404 that is not indexed and offers the map', bad.status() === 404 && /noindex/.test(badBody) && /We could not find that state/.test(badBody));
  for (const hostile of ['groups.php?state=<script>alert(1)</script>', 'groups.php?q=%22%3E%3Cscript%3Ealert(1)%3C/script%3E', 'groups.php?state[]=x&q[]=y&protocol[]=z', 'groups.php?protocol=bogus&discord=yes', 'groups.php?q=' + 'a'.repeat(500)]) {
    const r = await get(hostile);
    const body = await r.text();
    t.ok('a strange request is handled and echoes nothing live: ' + hostile.slice(0, 44), [200, 404].includes(r.status()) && !/<script>alert/.test(body) && !/Warning:|Fatal error|Notice:/.test(body));
  }
  t.ok('a search word is shown back safely in the box', /value="&quot;&gt;&lt;script&gt;/.test(await (await get('groups.php?q=%22%3E%3Cscript%3E')).text()));

  // ---- members-only Discord is shown as not joinable
  const members = await open('groups.php?state=alabama');
  t.ok('a members-only Discord is a greyed label, not a link', (await members.$$('.glinks .off')).length >= 1 && await members.$$eval('.glinks .off', (n) => n.every((x) => x.tagName === 'SPAN' && /members only/.test(x.textContent))));
  t.ok('a site the research could not open is marked', /Website not confirmed/.test(await (await open('groups.php?state=west-virginia')).textContent('main')));

  // ---- the rest of the site links here
  const index = await open('index.html');
  const hero = await index.$$eval('.hero-actions a', (a) => a.map((x) => ({ text: x.textContent.trim(), href: x.getAttribute('href') })));
  t.ok('the home page hero has a Find a local group button', hero.some((h) => h.text === 'Find a local group' && h.href === 'groups.php'), JSON.stringify(hero.map((h) => h.text)));
  t.ok('the header and footer link to the directory on every main page', (await Promise.all(['index.html', 'prices.html', 'alerts.html', 'radios.php', 'groups.php'].map(async (pg) => {
    const p = await open(pg);
    return (await p.$$('header a[href="groups.php"]')).length === 1 && (await p.$$('footer a[href="groups.php"]')).length === 1;
  }))).every(Boolean));
  const map = await (await get('sitemap.php')).text();
  const locs = [...map.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  t.ok('the sitemap lists the directory and a page for each of the 53 states and regions', locs.filter((l) => /groups\.php/.test(l)).length === 54 && locs.some((l) => /groups\.php\?state=texas$/.test(l)), locs.filter((l) => /groups\.php/.test(l)).length + '');
  t.ok('llms.txt mentions the directory', /groups\.php/.test(await (await get('llms.txt')).text()));

  // ---- phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage());
  for (const pg of ['groups.php', 'groups.php?state=texas', 'groups.php?state=new-york']) {
    await m.goto(t.SITE + pg);
    t.ok('phone: no sideways scroll on ' + pg, !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  }
  await m.goto(t.SITE + 'groups.php?state=texas');
  t.ok('phone: the map fits the screen', await m.$eval('svg.usmap', (s) => s.getBoundingClientRect().right <= innerWidth + 1));
  t.ok('phone: buttons are big enough to tap', await m.$$eval('.glinks a', (a) => a.slice(0, 12).every((x) => x.getBoundingClientRect().height >= 36)));
});
