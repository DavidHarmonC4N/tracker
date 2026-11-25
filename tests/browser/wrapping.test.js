'use strict';
// Text wrapping: model numbers are never split across lines, short table cells stay on one line, and no block ends in a one-word orphan stub.
const { run } = require('./lib');

// Runs inside the page. Returns problems found.
function audit() {
  const out = [];
  const rangeRects = (node, from, to) => { const r = document.createRange(); r.setStart(node, from); r.setEnd(node, to); return [...r.getClientRects()].filter((x) => x.width > 0); };
  const body = document.querySelector('main') || document.body;
  const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode())) {
    const el = node.parentElement;
    if (!el || /^(SCRIPT|STYLE|NOSCRIPT|TEXTAREA|OPTION)$/.test(el.tagName) || el.closest('.sr, [hidden], svg') || getComputedStyle(el).display === 'none') continue;
    const text = node.textContent;
    // hyphenated model numbers such as T1000-E must sit on one line
    for (const m of text.matchAll(/\b[A-Za-z]*\d[A-Za-z0-9]*(?:-[A-Za-z0-9]+)+\b/g)) {
      const rects = rangeRects(node, m.index, m.index + m[0].length);
      if (rects.length > 1 && new Set(rects.map((r) => Math.round(r.top / 4))).size > 1) out.push('split model number: ' + m[0]);
    }
  }
  // short table cells should not wrap on wide screens
  if (innerWidth >= 900) {
    for (const cell of body.querySelectorAll('td, th[scope="row"]')) {
      if (cell.closest('.sr') || cell.matches('th[scope="row"]') && cell.textContent.trim().length > 24) continue;
      const text = cell.textContent.trim();
      if (!text || text.length > 24 || cell.querySelector('small, br, div, p')) continue;
      const r = document.createRange(); r.selectNodeContents(cell);
      const tops = new Set([...r.getClientRects()].filter((x) => x.width > 0).map((x) => Math.round(x.top / 4)));
      if (tops.size > 1) out.push('short cell wraps: "' + text + '"');
    }
  }
  // orphan last lines in paragraphs
  for (const block of body.querySelectorAll('p, li, h1, h2')) {
    if (block.closest('.sr, [hidden]') || block.children.length > 3) continue;
    const tn = [...block.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim());
    if (!tn.length) continue;
    const last = tn[tn.length - 1];
    const rects = rangeRects(last, 0, last.textContent.length);
    const lines = new Set(rects.map((r) => Math.round(r.top / 4)));
    if (lines.size < 2) continue;
    const bottom = Math.max(...rects.map((r) => r.top));
    const lastLine = rects.filter((r) => Math.abs(r.top - bottom) < 4);
    const w = lastLine.reduce((s, r) => s + r.width, 0);
    if (w < 24 && getComputedStyle(block).textAlign !== 'center') out.push('orphan last line: "' + block.textContent.trim().slice(-30) + '"');
  }
  return [...new Set(out)];
}

run(async (t) => {
  const catalog = await (await fetch(t.SITE + 'catalog.php')).json();
  const seeed = catalog.items.find((i) => /^seeed-t1000e-/.test(i.id));
  const antenna = catalog.items.find((i) => i.category === 'antenna');
  const fits = catalog.items.find((i) => i.id === 'atlavox-m1');
  const pages = ['radios.php', seeed.page, antenna.page, fits.page, 'prices.html', 'alerts.html', 'index.html', 'index.html?cat=antennas', 'groups.php', 'groups.php?state=texas', 'groups.php?state=new-york'];
  for (const width of [1280, 1000, 390]) {
    const ctx = await t.openContext({ viewport: { width, height: 900 } });
    await t.mockCatalog(ctx, (data) => { const now = new Date().toISOString(); data.items.forEach((i) => { i.result = { ...i.result, status: 'in_stock', observed_at: now }; }); });
    for (const pg of pages) {
      const page = t.watch(await ctx.newPage());
      await page.goto(t.SITE + pg);
      if (pg.startsWith('index.html')) await page.waitForSelector('.card');
      if (pg === 'prices.html') await page.waitForTimeout(500);
      const problems = await page.evaluate(audit);
      t.ok(`${pg} at ${width}px: no split model numbers, wrapping short cells or orphan lines`, problems.length === 0, problems.slice(0, 5).join(' | '));
      await page.close();
    }
    await ctx.close();
  }
});
