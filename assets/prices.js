'use strict';

// Price history page. Draws a step chart and a table from history.php. No libraries; the chart is plain SVG.

const byId = (id) => document.getElementById(id);
const SVGNS = 'http://www.w3.org/2000/svg';
const PICKS_KEY = 'offgrid.picks.v1';
const MAX_LINES = 8;
const COLORS = ['#c2dc87', '#e5c783', '#8fc3e8', '#f2a89b', '#d3a6ee', '#8fe0cf', '#f0b27a', '#b8c0ff'];
const DAY = 86400000;
const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const dayFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' });
const STATUS = { in_stock: 'In stock', orderable: 'Orderable', out_of_stock: 'Out of stock', preorder: 'Preorder', unknown: 'Unverified' };

let items = [];
let nowMs = Date.now();
let scope = 'picks';
let range = '30';
let selected = [];
let loadedAt = 0;

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function svg(tag, attrs) {
  const node = document.createElementNS(SVGNS, tag);
  for (const [key, value] of Object.entries(attrs || {})) node.setAttribute(key, String(value));
  return node;
}

function readPicks() {
  try {
    const value = JSON.parse(localStorage.getItem(PICKS_KEY) || '{}');
    return value && typeof value === 'object' && !Array.isArray(value)
      ? Object.keys(value).filter((id) => /^[a-z0-9_-]{1,60}$/.test(id)) : [];
  } catch (error) {
    return [];
  }
}

function readUrl() {
  const params = new URLSearchParams(location.search);
  if (params.get('scope') === 'instock' || params.get('scope') === 'picks') scope = params.get('scope');
  else scope = readPicks().length ? 'picks' : 'instock';
  if (['7', '30', 'all'].includes(params.get('range'))) range = params.get('range');
}

function writeUrl() {
  const params = new URLSearchParams({ scope, range });
  try { history.replaceState(null, '', location.pathname + '?' + params.toString()); } catch (error) { /* convenience only */ }
}

// ---- data

function prepare(raw) {
  return raw.map((item) => {
    const points = (item.points || []).map(([t, p, s]) => ({ t: t * 1000, p, s })).sort((a, b) => a.t - b.t);
    const priced = points.filter((point) => Number.isInteger(point.p));
    let lastChange = 0;
    for (let i = priced.length - 1; i > 0; i -= 1) {
      if (priced[i].p !== priced[i - 1].p) { lastChange = priced[i].t; break; }
    }
    return { ...item, points, priced, lastChange, moves: new Set(priced.map((point) => point.p)).size > 1 };
  });
}

function windowStart() {
  if (range === 'all') {
    const first = Math.min(...items.flatMap((item) => item.priced.map((point) => point.t)), nowMs - DAY);
    return Math.min(first, nowMs - DAY);
  }
  return nowMs - Number(range) * DAY;
}

// Step series inside the window: the price held at the start, each change, and the latest price carried to now.
function series(item, start) {
  const priced = item.priced;
  if (!priced.length) return [];
  let held = null;
  const inside = [];
  for (const point of priced) {
    if (point.t <= start) held = point;
    else inside.push(point);
  }
  const first = held || inside[0];
  const out = [{ t: Math.max(first.t, start), p: first.p }];
  for (const point of inside) if (point !== first) out.push({ t: point.t, p: point.p });
  out.push({ t: nowMs, p: out[out.length - 1].p });
  return out;
}

function chooseDefaults() {
  const movers = items.filter((item) => item.moves).sort((a, b) => b.lastChange - a.lastChange);
  if (scope === 'picks') {
    const rest = items.filter((item) => !item.moves && item.priced.length).sort((a, b) => a.name.localeCompare(b.name));
    selected = movers.concat(rest).slice(0, MAX_LINES).map((item) => item.id);
  } else {
    selected = movers.slice(0, MAX_LINES).map((item) => item.id);
  }
}

// ---- drawing

function niceStep(span) {
  for (const step of [1, 2, 5, 10, 25, 50, 100, 250]) if (span / step <= 5) return step;
  return 500;
}

function drawChart() {
  const holder = byId('chart');
  const start = windowStart();
  const lines = selected.map((id) => items.find((item) => item.id === id)).filter(Boolean)
    .map((item) => ({ item, pts: series(item, start) })).filter((line) => line.pts.length);
  const anyHistory = items.some((item) => item.moves);
  if (!lines.length) {
    const box = el('div', 'emptyc');
    const text = el('div');
    text.append(el('strong', '', anyHistory ? 'No radios selected' : 'Price history starts today'),
      document.createElement('br'),
      document.createTextNode(anyHistory ? 'Choose a radio in the table to draw its line.'
        : 'Every check from now on is recorded. Come back in a few days to see lines.'));
    box.append(text);
    holder.replaceChildren(box);
    return;
  }
  const W = 940, H = 330, L = 58, R = 175, T = 14, B = 34;
  const all = lines.flatMap((line) => line.pts.map((point) => point.p / 100));
  let lo = Math.min(...all), hi = Math.max(...all);
  if (hi - lo < 4) { const mid = (hi + lo) / 2; lo = mid - 2; hi = mid + 2; }
  const step = niceStep(hi - lo);
  lo = Math.floor((lo - (hi - lo) * 0.06) / step) * step;
  hi = Math.ceil((hi + (hi - lo) * 0.06) / step) * step;
  const X = (t) => L + (W - L - R) * ((t - start) / (nowMs - start || 1));
  const Y = (p) => T + (H - T - B) * (1 - (p - lo) / (hi - lo || 1));
  const chart = svg('svg', { viewBox: `0 0 ${W} ${H}`, width: '100%', role: 'img', 'aria-label': 'Price history chart. The table below lists the same prices.' });
  for (let v = Math.ceil(lo / step) * step; v <= hi; v += step) {
    chart.append(svg('line', { x1: L, x2: W - R, y1: Y(v).toFixed(1), y2: Y(v).toFixed(1), stroke: '#2c3626' }));
    const label = svg('text', { x: L - 8, y: (Y(v) + 4).toFixed(1), 'text-anchor': 'end', fill: '#aeb8a5', 'font-size': 11, 'font-family': 'monospace' });
    label.textContent = '$' + v;
    chart.append(label);
  }
  for (let i = 0; i <= 3; i += 1) {
    const t = start + ((nowMs - start) * i) / 3;
    const label = svg('text', { x: X(t).toFixed(1), y: H - 12, 'text-anchor': i === 0 ? 'start' : i === 3 ? 'end' : 'middle', fill: '#aeb8a5', 'font-size': 11, 'font-family': 'monospace' });
    label.textContent = dayFormat.format(new Date(t));
    chart.append(label);
  }
  const ends = [];
  lines.forEach((line) => {
    const color = COLORS[selected.indexOf(line.item.id) % COLORS.length];
    let d = '';
    line.pts.forEach((point, i) => { d += i === 0 ? `M${X(point.t).toFixed(1)},${Y(point.p / 100).toFixed(1)}` : `H${X(point.t).toFixed(1)}V${Y(point.p / 100).toFixed(1)}`; });
    chart.append(svg('path', { d, fill: 'none', stroke: color, 'stroke-width': 2.4, 'stroke-linejoin': 'round' }));
    if (line.item.moves) {
      for (const point of line.pts.slice(0, -1)) {
        if (point.p === line.item.low) chart.append(svg('circle', { cx: X(point.t).toFixed(1), cy: Y(point.p / 100).toFixed(1), r: 4.5, fill: '#101510', stroke: color, 'stroke-width': 2 }));
      }
    }
    ends.push({ y: Y(line.pts[line.pts.length - 1].p / 100), name: line.item.name, price: line.pts[line.pts.length - 1].p, color });
  });
  ends.sort((a, b) => a.y - b.y);
  let last = -Infinity;
  for (const end of ends) {
    const y = Math.max(end.y, last + 30);
    last = y;
    const name = svg('text', { x: W - R + 10, y: (y - 3).toFixed(1), fill: end.color, 'font-size': 12, 'font-weight': 700, 'font-family': 'sans-serif' });
    name.textContent = end.name.length > 20 ? end.name.slice(0, 19) + '…' : end.name;
    const price = svg('text', { x: W - R + 10, y: (y + 12).toFixed(1), fill: '#aeb8a5', 'font-size': 11, 'font-family': 'monospace' });
    price.textContent = money.format(end.price / 100);
    chart.append(name, price);
  }
  holder.replaceChildren(chart);
}

function sparkline(item, start, color) {
  const pts = series(item, start);
  const node = svg('svg', { viewBox: '0 0 110 30', width: 110, height: 30, 'aria-hidden': 'true' });
  if (pts.length < 2) return node;
  const values = pts.map((point) => point.p);
  const lo = Math.min(...values), hi = Math.max(...values);
  const X = (t) => 2 + 106 * ((t - start) / (nowMs - start || 1));
  const Y = (p) => (hi === lo ? 15 : 2 + 26 * (1 - (p - lo) / (hi - lo)));
  let d = '';
  pts.forEach((point, i) => { d += i === 0 ? `M${X(point.t).toFixed(1)},${Y(point.p).toFixed(1)}` : `H${X(point.t).toFixed(1)}V${Y(point.p).toFixed(1)}`; });
  node.append(svg('path', { d, fill: 'none', stroke: color, 'stroke-width': 2 }));
  return node;
}

function drawTable() {
  const start = windowStart();
  const wrap = byId('tablewrap');
  if (!items.length) { wrap.replaceChildren(); return; }
  const rows = items.slice().sort((a, b) => b.lastChange - a.lastChange || a.name.localeCompare(b.name));
  const table = el('table', 'ptable');
  const head = document.createElement('thead');
  const hr = document.createElement('tr');
  for (const [label, cls] of [['Radio', ''], ['Now', 'num'], ['Lowest', 'num'], ['Highest', 'num wide'], ['Trend', 'wide']]) hr.append(el('th', cls, label));
  head.append(hr);
  const body = document.createElement('tbody');
  for (const item of rows) {
    const tr = document.createElement('tr');
    const index = selected.indexOf(item.id);
    const color = index >= 0 ? COLORS[index % COLORS.length] : '#455437';
    const first = document.createElement('td');
    const toggle = el('button', 'pick');
    toggle.type = 'button';
    toggle.setAttribute('aria-pressed', String(index >= 0));
    toggle.title = index >= 0 ? 'Hide this line' : 'Show this line';
    const dot = el('i', 'dot');
    dot.style.background = color;
    toggle.append(dot, el('b', '', item.name));
    toggle.addEventListener('click', () => {
      const at = selected.indexOf(item.id);
      if (at >= 0) selected.splice(at, 1);
      else { selected.push(item.id); if (selected.length > MAX_LINES) selected.shift(); }
      drawChart();
      drawTable();
    });
    const link = el('a', 'ext', item.seller);
    try {
      const url = new URL(item.url);
      if (url.protocol === 'https:') {
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.title = 'View listing at ' + item.seller + ' (opens in a new tab)';
        link.setAttribute('aria-label', 'View listing at ' + item.seller + ' (opens in a new tab)');
      }
    } catch (error) { /* seller name only */ }
    first.append(toggle, link);
    const price = Number.isInteger(item.price) ? money.format(item.price / 100) : 'See seller';
    const note = el('span', 'marks');
    if (item.moves && item.price === item.low && item.high > item.low) note.append(el('span', 'badge low', 'Lowest seen'));
    else if (!item.moves) note.append(el('span', 'badge mute', item.priced.length ? 'No change' : 'No history yet'));
    else if (item.price > item.low) note.append(el('span', 'badge mute', 'Above its low'));
    if (note.children.length) first.append(note);
    tr.append(first, el('td', 'num', price + (item.status === 'out_of_stock' ? ' (out)' : '')),
      el('td', 'num', Number.isInteger(item.low) ? money.format(item.low / 100) : '·'),
      el('td', 'num wide', Number.isInteger(item.high) ? money.format(item.high / 100) : '·'));
    const trend = el('td', 'wide');
    trend.append(sparkline(item, start, color));
    tr.append(trend);
    body.append(tr);
  }
  table.append(head, body);
  wrap.replaceChildren(table);
}

function drawControls() {
  document.querySelectorAll('#scope button').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.scope === scope)));
  document.querySelectorAll('#range button').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.range === range)));
}

function drawEmptyPicks() {
  const box = el('div', 'emptyc');
  const text = el('div');
  const browse = el('a', 'primary btnlink', 'Browse radios');
  browse.href = 'index.html#inventory';
  text.append(el('strong', '', 'You have not starred any radios yet'), document.createElement('br'),
    document.createTextNode('Star radios on the catalog to follow their prices here, or switch to All in stock.'),
    document.createElement('br'), browse);
  box.append(text);
  byId('chart').replaceChildren(box);
  byId('tablewrap').replaceChildren();
  byId('subtitle').textContent = 'No picks yet';
}

function render() {
  drawControls();
  writeUrl();
  if (scope === 'picks' && !readPicks().length) { drawEmptyPicks(); return; }
  const span = range === 'all' ? 'all recorded history' : 'last ' + range + ' days';
  byId('subtitle').textContent = items.length + (items.length === 1 ? ' radio' : ' radios') + ' · ' + span
    + (scope === 'picks' ? ' · your picks' : ' · in stock or orderable now');
  drawChart();
  drawTable();
}

async function load() {
  try {
    const picks = readPicks();
    const url = scope === 'picks' ? 'history.php?ids=' + encodeURIComponent(picks.join(',')) : 'history.php?scope=instock';
    if (scope === 'picks' && !picks.length) { items = []; render(); return; }
    const response = await fetch(url, { cache: 'no-store' });
    if (!response.ok) throw new Error('history');
    const data = await response.json();
    nowMs = (data.now || Math.floor(Date.now() / 1000)) * 1000;
    const phone = byId('ntfy-footer');
    if (phone && typeof data.public_topic === 'string' && /^https:\/\//.test(data.public_topic)) {
      byId('ntfy-link').href = data.public_topic;
      phone.hidden = false;
    }
    if (data.staging === true && !byId('staging-bar')) {
      const bar = el('div', 'staging-bar', 'STAGING · a private test copy, not the live site');
      bar.id = 'staging-bar';
      bar.setAttribute('role', 'note');
      document.body.prepend(bar);
      document.title = '[STAGING] ' + document.title;
    }
    items = prepare(data.items || []);
    loadedAt = Date.now();
    chooseDefaults();
    render();
  } catch (error) {
    byId('subtitle').textContent = 'Price history is temporarily unavailable';
    byId('chart').replaceChildren(el('p', 'panel empty', 'The server did not return price history. Reload the page in a moment.'));
  }
}

document.querySelectorAll('#scope button').forEach((button) => button.addEventListener('click', () => {
  scope = button.dataset.scope;
  load();
}));
document.querySelectorAll('#range button').forEach((button) => button.addEventListener('click', () => {
  range = button.dataset.range;
  render();
}));
document.addEventListener('visibilitychange', () => { if (!document.hidden && Date.now() - loadedAt > 120000) load(); });
readUrl();
load();
