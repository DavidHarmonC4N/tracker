'use strict';
// The group link check as the site sees it: the runner gets its list and posts results, and the result shows on the public page and in Admin.
const { run } = require('./lib');

const PASSWORD = 'correct horse battery staple';
const SETUP_KEY = '0123456789abcdef0123456789abcdef0123';
const TOKEN = '0123456789abcdef0123456789abcdef0123abcd';

run(async (t) => {
  const base = t.ADMIN.replace(/admin\.php$/, '');
  const anon = await t.browser.newContext();
  const post = (action, body, token = TOKEN) => anon.request.post(base + 'ingest.php?action=' + action, { data: body, headers: { 'Content-Type': 'application/json', 'X-Offgrid-Token': token }, failOnStatusCode: false });

  // ---- the list of links
  const noToken = await post('group_jobs', {}, 'x'.repeat(40));
  t.ok('the list of links is not given to a stranger', noToken.status() === 403);
  const listed = await (await post('group_jobs', {})).json();
  const jobs = listed.jobs || [];
  t.ok('the runner gets every link of every group, each with the address and a short hash', jobs.length > 400 && jobs.every((j) => /^https:\/\//.test(j.url) && /^[0-9a-f]{10}$/.test(j.h) && ['url', 'discord', 'facebook', 'other', 'map'].includes(j.field)), jobs.length + ' jobs');
  const alabama = jobs.find((j) => j.id === 'M001' && j.field === 'url');
  t.ok('a group is in the list by its id', !!alabama);
  t.ok('a members-only Discord link is not in the list', !jobs.some((j) => /discord\.com\/channels/.test(j.url)));
  const mark = (status, code = 404) => ({ results: [{ id: alabama.id, field: 'url', h: alabama.h, status, code }] });
  const bad = await post('group_results', mark('dead'), 'y'.repeat(40));
  t.ok('results are not accepted without the token', bad.status() === 403);

  // ---- sign in to Admin and the public page
  const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 } });
  const p = t.watch(await ctx.newPage());
  await p.goto(t.ADMIN);
  if (await p.$('input[name="setup_key"]')) { await p.fill('input[name="setup_key"]', SETUP_KEY); await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="setup"]'); await p.waitForLoadState(); }
  if (await p.$('input[name="password"]')) { await p.fill('input[name="password"]', PASSWORD); await p.click('button[value="login"]'); await p.waitForLoadState(); }
  const publicPage = async () => (await ctx.request.get(base + 'groups.php?state=alabama')).text();

  t.ok('before any check the public page has no notes', !/Did not respond when last checked/.test(await publicPage()));
  await p.goto(t.ADMIN + '?view=groups');
  t.ok('Admin says the links have not been checked yet', /have not been checked yet/.test(await p.textContent('#link-run')));

  // ---- one failure: Admin knows, visitors are not told
  const first = await post('group_results', mark('dead'));
  const firstBody = await first.json();
  t.ok('the first failure is stored', first.status() === 200 && firstBody.stored === 1 && firstBody.counts.dead === 1, JSON.stringify(firstBody));
  t.ok('visitors are not told after one failure', !/Did not respond when last checked/.test(await publicPage()));
  await p.goto(t.ADMIN + '?view=groups');
  t.ok('Admin shows the check, with counts', /Links last checked/.test(await p.textContent('#link-run')) && /1 did not/.test(await p.textContent('#link-run')));
  t.ok('Admin marks the group as having a link problem', await p.$$eval('#admin-list .admin-row', (r) => r.some((x) => x.dataset.group === 'broken' && /Alabama Mesh/.test(x.textContent) && /Link problem/.test(x.textContent))));
  await p.click('[data-filter="group"][data-value="broken"]');
  t.ok('the Link problem filter finds it', (await p.$$eval('#admin-list .admin-row:not([hidden])', (r) => r.length)) === 1);
  await p.click('[data-filter="group"][data-value=""]');

  // ---- two in a row: visitors are told, the owner is alerted once
  const second = await post('group_results', mark('dead'));
  t.ok('the second failure is stored', (await second.json()).stored === 1);
  const page = await publicPage();
  t.ok('visitors now see that the link did not respond', /Did not respond when last checked: Website/.test(page));
  t.ok('the link itself is still there, marked, and still opens in a new tab', /class="primary btnlink down" title="This link did not respond[^"]*" href="https:[^"]*" target="_blank" rel="noopener noreferrer nofollow"/.test(page));
  const third = await post('group_results', mark('dead'));
  t.ok('a third failure is stored without a second alert', (await third.json()).stored === 1);

  // ---- the edit page shows the result
  await p.goto(t.ADMIN + '?view=groups&edit=M001');
  const table = (await p.textContent('#link-health + table')).replace(/\s+/g, ' ');
  t.ok('the edit page shows what the check found, with the failures in a row', /Website/.test(table) && /Did not answer/.test(table) && /404/.test(table) && /3/.test(table), table.slice(0, 120));

  // ---- recovery
  const ok = await post('group_results', mark('ok', 200));
  t.ok('an answering link is stored', (await ok.json()).stored === 1);
  t.ok('the note is gone from the public page', !/Did not respond when last checked/.test(await publicPage()));
  await p.goto(t.ADMIN + '?view=groups');
  t.ok('and the Link problem tag is gone in Admin', !(await p.$$eval('#admin-list .admin-row', (r) => r.some((x) => x.dataset.group === 'broken'))));

  // ---- junk is ignored
  const junk = await post('group_results', { results: [{ id: 'nope', field: 'url', h: 'x', status: 'ok' }, { id: alabama.id, field: 'url', h: 'ffffffffff', status: 'ok' }, 'junk'] });
  const junkBody = await junk.json();
  t.ok('results that do not match a current link are ignored', junk.status() === 200 && junkBody.stored === 0 && junkBody.ignored === 3, JSON.stringify(junkBody));
  const huge = await post('group_results', { results: [], pad: 'x'.repeat(300000) });
  t.ok('an oversized request is refused', huge.status() === 413);
  t.ok('the "Check links now" button is only offered when GitHub can be started from here', (await p.$$('button[value="group_links_run"]')).length === 0);

  // ---- mail: one alert was sent when the second failure arrived
  if (process.env.MAIL_CAPTURE) {
    const fs = require('fs');
    const mail = fs.existsSync(process.env.MAIL_CAPTURE) ? fs.readFileSync(process.env.MAIL_CAPTURE, 'utf8') : '';
    const subject = Buffer.from((mail.match(/Subject: =\?UTF-8\?B\?([^?]+)\?=/) || [])[1] || '', 'base64').toString('utf8');
    t.ok('the owner was alerted once, with the number of links in the subject', /Group directory: 1 link not responding/.test(subject), subject);
    t.ok('the alert names the group and the link', /Alabama Mesh · Website/.test(mail) && /failed the last two monthly checks/.test(mail));
  }
});
