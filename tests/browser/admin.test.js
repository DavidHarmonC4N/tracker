'use strict';
// Admin: first-time setup and sign-in, "Add a listing by link" (store feed stubbed by tests/run.sh), duplicate guards.
const fs = require('fs');
const { run } = require('./lib');

const PASSWORD = 'correct horse battery staple';
const SETUP_KEY = '0123456789abcdef0123456789abcdef0123';
const LINK = 'https://store.rokland.com/products/wismesh-tag-from-rakwireless-mokosmart-meshtastic-compatible-card-sized-node-us915-mhz';

run(async (t) => {
  const ctx = await t.browser.newContext({ viewport: { width: 1100, height: 900 } });
  const p = t.watch(await ctx.newPage());
  p.on('dialog', (dialog) => dialog.accept());
  const catalog = (page = p) => page.evaluate(async () => (await (await fetch('catalog.php')).json()).items);
  const read = async (link) => { await p.goto(t.ADMIN); await p.fill('input[name="link"]', link); await p.click('button[value="addurl_read"]'); await p.waitForLoadState(); };

  // sign in (the first visit sets the password)
  await p.goto(t.ADMIN);
  t.ok('a signed-out admin asks for credentials', (await p.$('input[name="password"]')) !== null && (await p.$('#addurl-heading')) === null);
  if (await p.$('input[name="setup_key"]')) {
    await p.fill('input[name="setup_key"]', SETUP_KEY); await p.fill('input[name="password"]', PASSWORD);
    await p.click('button[value="setup"]'); await p.waitForLoadState();
  }
  if (await p.$('input[name="password"]')) {
    await p.fill('input[name="password"]', 'wrong password here'); await p.click('button[value="login"]'); await p.waitForLoadState();
    t.ok('a wrong password is refused', /Access denied/.test((await p.textContent('.notice')) || ''));
    await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="login"]'); await p.waitForLoadState();
  }
  t.ok('signed in: the Add by link panel is there', (await p.$('#addurl-heading')) !== null);
  t.ok('the old cron warning banner is gone', !/scheduled checker/i.test(await p.textContent('body')));

  // listing filters: radios or antennas, and the three stock statuses (plus Needs review)
  const shownRows = () => p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.length);
  const allRows = await shownRows();
  const chipCount = async (filter, value) => Number((await p.textContent(`[data-filter="${filter}"][data-value="${value}"] .n`)).trim());
  t.ok('the list has radios and antennas, and each filter button shows its count', allRows > 80 && (await chipCount('category', 'radio')) + (await chipCount('category', 'antenna')) === allRows && (await chipCount('category', 'antenna')) >= 30, allRows + ' listings');
  t.ok('the filters offer All, Radios, Antennas and Any, In stock, Orderable, Out of stock, Needs review', JSON.stringify(await p.$$eval('[data-filter]', (b) => b.map((x) => x.textContent.replace(/\s+\d*$/, '').trim()))) === JSON.stringify(['All', 'Radios', 'Antennas', 'Any', 'In stock', 'Orderable', 'Out of stock', 'Needs review']));
  await p.click('[data-filter="category"][data-value="antenna"]');
  const antennaRows = await shownRows();
  t.ok('Antennas shows only antennas', antennaRows === (await chipCount('category', 'antenna')) && antennaRows >= 30 && (await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.every((x) => x.dataset.category === 'antenna'))));
  t.ok('the button pressed is marked and the count line says how many are shown', (await p.getAttribute('[data-filter="category"][data-value="antenna"]', 'aria-pressed')) === 'true' && new RegExp(antennaRows + ' of ' + allRows + ' listings shown').test(await p.textContent('#admin-count')));
  t.ok('an antenna row shows its type and facts, not firmware', /Omni antenna|Whip or flexible antenna/.test(await p.textContent('#admin-list .admin-row:not([hidden])')) && !/firmware/i.test(await p.textContent('#admin-list .admin-row:not([hidden]) .dim')));
  await p.click('[data-filter="group"][data-value="out_of_stock"]');
  const both = await shownRows();
  t.ok('a status narrows the antennas further, and the other buttons show counts for that choice', (await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.every((x) => x.dataset.category === 'antenna' && x.dataset.group === 'out_of_stock'))) && both <= antennaRows && (await chipCount('group', '')) === antennaRows);
  await p.fill('#admin-search', 'zzzz-no-such-listing');
  t.ok('search, category and status all have to match', (await shownRows()) === 0);
  await p.fill('#admin-search', '');
  await p.click('[data-filter="category"][data-value=""]'); await p.click('[data-filter="group"][data-value=""]');
  t.ok('All and Any bring everything back and clear the count line', (await shownRows()) === allRows && (await p.textContent('#admin-count')).trim() === '');
  await p.click('[data-filter="category"][data-value="radio"]');
  t.ok('Radios shows only radios', (await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.length > 50 && r.every((x) => x.dataset.category === 'radio'))));
  await p.click('[data-filter="category"][data-value=""]');

  // alerts: the panel, the test button, and what actually went out (run.sh points sendmail at a file)
  t.ok('the Alerts panel shows the address masked and the sender', /o\*\*\*@example\.com/.test(await p.textContent('#alerts-heading + p, .panel[aria-labelledby="alerts-heading"]')) && /alerts@example\.org/.test(await p.textContent('.panel[aria-labelledby="alerts-heading"]')));
  t.ok('the panel says the public topic is off and shows the buttons setting', /Public topic\s*·\s*off/.test(await p.textContent('.panel[aria-labelledby="alerts-heading"]')));
  t.ok('the full address is never shown on the page', !/owner@example\.com/.test(await p.textContent('body')));
  await p.click('button[value="test_alert"]'); await p.waitForLoadState();
  t.ok('Send test alert reports what happened', /Test alert sent/.test(await p.textContent('.notice')) && /not proof of delivery/.test(await p.textContent('.notice')), (await p.textContent('.notice')).trim().slice(0, 120));
  t.ok('the result appears under Latest results', /Sent\s*·\s*Email/.test(await p.textContent('.panel[aria-labelledby="alerts-heading"]')) && /Test alert/.test(await p.textContent('.panel[aria-labelledby="alerts-heading"]')));
  if (process.env.MAIL_CAPTURE) {
    const mail = fs.existsSync(process.env.MAIL_CAPTURE) ? fs.readFileSync(process.env.MAIL_CAPTURE, 'utf8') : '';
    t.ok('the email went to the owner from the configured sender', /To: owner@example\.com/.test(mail) && /From: Offgrid Comms Tracker <alerts@example\.org>/.test(mail), mail.split('\n').slice(0, 3).join(' | '));
    t.ok('the email says it is a test and links to the site', /If you can read this, alerts are working/.test(mail) && /index\.html/.test(mail));
  }

  // move every automatic listing to the U.S. runner (the starter list has several set to this server)
  const moveButton = await p.$('button[value="route_all"]');
  t.ok('the "Move all to U.S. runner" button is offered while listings are on this server', moveButton !== null && /^Move all \d+ to U\.S\. runner$/i.test((await moveButton.textContent()).trim()), moveButton ? (await moveButton.textContent()).trim() : 'missing');
  const countOnServer = moveButton ? Number(((await moveButton.textContent()).match(/\d+/) || [0])[0]) : 0;
  await p.click('button[value="route_all"]'); await p.waitForLoadState();
  const movedNotice = (await p.textContent('.notice')).trim();
  t.ok('it reports how many were moved', new RegExp('^' + countOnServer + ' listing\\(s\\) moved to the U\\.S\\. runner').test(movedNotice) && countOnServer > 0, movedNotice);
  t.ok('afterwards the button is gone, because nothing is left on this server', (await p.$('button[value="route_all"]')) === null);
  t.ok('the listing rows now say U.S. runner for at least the moved ones', ((await p.textContent('body')).match(/· U\.S\. runner/g) || []).length >= countOnServer, String(((await p.textContent('body')).match(/· U\.S\. runner/g) || []).length));

  // duplicates and bad links
  const before = (await catalog()).length;
  await read(LINK + '?ref=trueprepper&variant=44663304552531');
  t.ok('a link already in the catalog is refused, tracking parameters ignored', /already in the catalog/.test(await p.textContent('.notice')));
  await read('http://insecure.example.com/products/x');
  t.ok('an http link is rejected', /HTTPS/.test((await p.textContent('.notice.danger')) || ''));

  // clear the way: delete the starter WisMesh listing, then add it again through the flow
  await p.goto(t.ADMIN + '?edit=rok-wismesh-tag');
  await p.click('button[value="delete"]'); await p.waitForLoadState();
  t.ok('the starter listing was removed for the test', (await catalog()).length === before - 1);

  await read(LINK + '?ref=trueprepper&variant=44663304552531');
  t.ok('step 2 opens for a two-variant product', /addurl=variant/.test(p.url()) && /2 · Pick variant/.test(await p.textContent('.steps li.on')));
  t.ok('the product name comes from the feed', /WisMesh Tag/.test(await p.textContent('.prod')));
  t.ok('the removed referral tag is explained', /ref/.test(await p.textContent('.anote')));
  t.ok('the variant from the link is pre-selected', (await p.$eval('input[name="variant_id"]:checked', (e) => e.value)) === '44663304552531');
  const options = await p.$$eval('.var', (r) => r.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
  t.ok('both variants are listed with price and stock, plus "any"', options.length === 3 && options.some((o) => /Navy.*from your link.*\$46\.00.*Out of stock/.test(o)) && options.some((o) => /Any available/.test(o)), options.join(' || '));
  await p.click('button[value="addurl_pick"]'); await p.waitForLoadState();
  t.ok('step 3 is the Add listing form', /from=url/.test(p.url()) && (await p.$('input[name="name"]')) !== null);
  const v = async (name) => p.$eval(`[name="${name}"]`, (e) => e.value);
  t.ok('seller and location are reused', (await v('seller')) === 'Rokland' && (await v('location')) === 'Florida');
  t.ok('the link is cleaned and keeps the variant', !/ref=/.test(await v('url')) && /variant=44663304552531$/.test(await v('url')));
  t.ok('the name carries the variant', /Navy/.test(await v('name')));
  t.ok('the guesses are a tracker running Meshtastic', (await v('kind')) === 'tracker' && (await v('role')) === 'tracker' && (await v('firmware')) === 'meshtastic');
  t.ok('a reason is shown under the guessed fields', (await p.$$('.why')).length >= 8);
  t.ok('the first reading is announced', /First reading is saved too/.test(await p.textContent('.anote')));
  t.ok('Save and add another variant is offered', (await p.$('button[name="another"]')) !== null);
  await p.click('button.primary:has-text("Save listing")'); await p.waitForLoadState();
  t.ok('the listing is saved', /Listing saved/.test(await p.textContent('.notice')));
  const added = (await catalog()).find((i) => /variant=44663304552531/.test(i.url));
  t.ok('it appears in the public catalog with the first reading', !!added && added.result.source === 'automatic' && added.result.price === 4600 && !!added.result.observed_at, added && JSON.stringify(added.result));

  // the second colour, then the "any variant" state
  await read(LINK);
  const taken = await p.$$eval('.var.taken', (r) => r.map((x) => x.textContent.replace(/\s+/g, ' ')));
  t.ok('a variant already in the catalog is marked and disabled', taken.length === 1 && /Navy.*already in the catalog/.test(taken[0]));
  t.ok('the other colour is pre-selected', (await p.$eval('input[name="variant_id"]:checked', (e) => e.value)) === '43033822593107');
  await p.click('button[value="addurl_pick"]'); await p.waitForLoadState();
  t.ok('the form name carries Black', /Black/.test(await v('name')));
  await p.click('button[name="another"]'); await p.waitForLoadState();
  t.ok('Save and add another returns to the variant step', /addurl=variant/.test(p.url()) && /Listing saved/.test(await p.textContent('.notice')));
  t.ok('both colours are now taken and "any" is selected', (await p.$$('.var.taken')).length === 2 && (await p.$eval('input[value="any"]', (e) => e.checked)));
  const links = (await catalog()).filter((i) => /wismesh-tag/.test(i.url)).map((i) => i.url.split('?')[1]);
  t.ok('two separate listings exist, one per colour', links.length === 2 && new Set(links).size === 2);

  // fallbacks
  await read('https://www.etsy.com/listing/123456/example-meshtastic-case');
  t.ok('an Etsy link opens the form as manual review', /from=url/.test(p.url()) && (await v('mode')) === 'manual' && /blocks automated checks/.test(await p.textContent('.anote.fail')));
  await read('https://shop.example-radios.com/pages/about');
  t.ok('a non-product page opens the form in page mode', /from=url/.test(p.url()) && (await v('mode')) === 'page');

  // duplicate guard by hand, per warehouse (Seeed lists one page per warehouse)
  const seeed = (await catalog()).find((i) => i.warehouse === 'us' && /seeedstudio/.test(i.url));
  const fill = async (warehouse) => {
    await p.goto(t.ADMIN + '?add=1');
    await p.fill('[name="name"]', 'Duplicate test'); await p.fill('[name="seller"]', 'Seeed Studio'); await p.fill('[name="url"]', seeed.url);
    await p.selectOption('[name="warehouse"]', warehouse);
    await p.click('button[value="save"]'); await p.waitForLoadState();
  };
  await fill('us');
  t.ok('the same page for the same warehouse is refused', /already in the catalog for that warehouse/.test(await p.textContent('.notice.danger')));
  t.ok('Ships from can be left as Not specified (it used to block saving)', (await p.$eval('[name="warehouse"]', (e) => e.required)) === false);

  // phone
  const phone = await t.browser.newContext({ viewport: { width: 390, height: 844 } });
  const m = t.watch(await phone.newPage(), 'phone: ');
  await m.goto(t.ADMIN);
  await m.fill('input[name="password"]', PASSWORD); await m.click('button[value="login"]'); await m.waitForLoadState();
  t.ok('phone: no sideways scroll in the admin', !(await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
});
