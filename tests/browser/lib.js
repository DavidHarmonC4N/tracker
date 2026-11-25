'use strict';

// Shared helper for the browser tests. A test file calls run(async (t) => { ... }) and uses t.ok(name, condition, detail).
// tests/run.sh starts the test servers and sets SITE_URL and ADMIN_URL.

const { chromium } = require('playwright');

const SITE = process.env.SITE_URL || 'http://127.0.0.1:8071/';
const ADMIN = process.env.ADMIN_URL || 'http://127.0.0.1:8072/admin.php';
const OWN_HOSTS = [new URL(SITE).host, 'mesh.davidandlaura.net'];

async function run(body) {
  const browser = await chromium.launch();
  const errors = [];
  let failed = 0;
  let passed = 0;
  const t = {
    SITE,
    ADMIN,
    OWN_HOSTS,
    browser,
    errors,
    ok(name, condition, detail = '') {
      console.log((condition ? 'PASS ' : 'FAIL ') + name + (detail ? '  ' + detail : ''));
      if (condition) passed += 1; else failed += 1;
    },
    // Records JavaScript errors from a page. Any error fails the test file at the end.
    watch(page, label = '') {
      page.on('pageerror', (error) => errors.push(label + String(error)));
      return page;
    },
    // Serves the real catalog.php but lets the test change the data first.
    async mockCatalog(context, change) {
      await context.route('**/catalog.php', async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        change(data);
        route.fulfill({ response, json: data });
      });
    },
    // A context with the "folds" setting preset so every group starts open (Out of stock starts folded by default).
    async openContext(options = {}, { unfold = true } = {}) {
      const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ...options });
      if (unfold) {
        await context.addInitScript(() => {
          try { localStorage.setItem('offgrid.folds.v1', JSON.stringify({ out_of_stock: false, unknown: false })); } catch (error) { /* ok */ }
        });
      }
      return context;
    },
  };
  try {
    await body(t);
  } catch (error) {
    const where = ((error && error.stack) || '').split('\n').find((line) => line.includes('.test.js')) || '';
    console.log('FAIL the test stopped early: ' + (error && error.message ? error.message.split('\n')[0] : error) + (where ? '  ' + where.trim() : ''));
    failed += 1;
  } finally {
    await browser.close();
  }
  if (errors.length) {
    console.log('FAIL JavaScript errors on the page: ' + errors.join(' | '));
    failed += 1;
  }
  console.log(failed ? failed + ' FAILED' : 'ALL PASSED (' + passed + ' checks)');
  process.exit(failed ? 1 : 0);
}

module.exports = { run, SITE, ADMIN };
