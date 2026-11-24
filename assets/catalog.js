'use strict';

const lookup = {
  firmware: { meshcore: 'MeshCore only', meshtastic: 'Meshtastic only', both: 'Both firmware options' },
  kind: { handheld: 'Handheld', keyboard: 'Keyboard radio', solar: 'Solar node', base: 'Base station', tracker: 'GPS tracker', board: 'Board or kit', other: 'Other' },
  role: { companion: 'Companion', tracker: 'Tracker', repeater: 'Repeater', solar_repeater: 'Solar repeater', unspecified: 'Unspecified' },
  setup: { meshcore: 'MeshCore installed', meshtastic: 'Meshtastic installed', choice: 'Choose or request firmware', flash: 'Install MeshCore yourself', unknown: 'See listing for setup' },
  status: { in_stock: 'In stock', orderable: 'Orderable', out_of_stock: 'Out of stock', preorder: 'Preorder / backorder', unknown: 'Unverified' }
};
const priority = ['in_stock', 'orderable', 'preorder', 'unknown', 'out_of_stock'];
const roleOrder = Object.keys(lookup.role);
const byId = (id) => document.getElementById(id);
const formatMoney = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const formatDate = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
const previewSource = byId('preview-data');
let rows = [];
let lastSignature = '';
const filterIds = { q: 'search', firmware: 'firmware', stock: 'stock', role: 'role', kind: 'kind', sort: 'sort' };

function readUrlFilters() {
  const params = new URLSearchParams(location.search);
  for (const [key, id] of Object.entries(filterIds)) {
    const control = byId(id);
    const value = params.get(key);
    if (value === null) continue;
    control.value = value;
    if (control.value !== value && control.tagName === 'SELECT') control.selectedIndex = 0;
  }
}

function writeUrlFilters() {
  const params = new URLSearchParams();
  for (const [key, id] of Object.entries(filterIds)) {
    const control = byId(id);
    const value = control.value.trim();
    if (value && !(key === 'sort' && value === 'availability')) params.set(key, value);
  }
  const query = params.toString();
  try {
    history.replaceState(null, '', location.pathname + (query ? '?' + query : '') + location.hash);
  } catch (error) { /* URL sync is a convenience only */ }
}

function clearFilters() {
  byId('filters').reset();
}

function el(tag, className, value) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (value !== undefined) node.textContent = value;
  return node;
}

function displayStatus(item) {
  if (previewSource) return item.result.status;
  const timestamp = Date.parse(item.result.observed_at || '');
  return item.check_failed || !Number.isFinite(timestamp) || Date.now() - timestamp > 24 * 60 * 60 * 1000
    ? 'unknown' : item.result.status;
}

function radioRole(item) {
  return roleOrder.includes(item.role) ? item.role : 'unspecified';
}

const formatDay = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' });

function changeBadge(row, status) {
  const change = row.change;
  if (!change || previewSource) return null;
  const day = formatDay.format(new Date(change.t));
  if (change.type === 'back_in_stock' && (status === 'in_stock' || status === 'orderable')) {
    return el('p', 'change-badge', 'Back in stock · ' + day);
  }
  if (change.type === 'price_drop' && Number.isInteger(row.result.price) && row.result.price === change.to) {
    return el('p', 'change-badge', 'Price dropped from ' + formatMoney.format(change.from / 100) + ' · ' + day);
  }
  return null;
}

function card(row) {
  const status = displayStatus(row);
  const reading = row.result;
  const result = el('article', 'card');
  const first = el('div', 'card-top');
  const count = status === 'in_stock' && Number.isInteger(reading.qty) ? ' · ' + reading.qty + ' shown' : '';
  const role = radioRole(row);
  first.append(el('span', 'card-kind', role === 'unspecified' ? lookup.kind[row.kind] : lookup.role[role]), el('span', 'tag ' + status, lookup.status[status] + count));

  const title = el('div');
  title.append(el('h3', '', row.name), el('p', 'seller', row.seller + (row.location ? ' · ' + row.location : '')));

  const firmware = el('div');
  firmware.append(el('p', 'firmware-label', lookup.firmware[row.firmware]), el('p', 'setup-label', lookup.setup[row.setup]));

  const end = el('div', 'card-foot');
  const price = el('div', 'price');
  if (Number.isInteger(reading.price)) {
    const lastPrice = !previewSource && (reading.price_previous || status === 'unknown' || row.check_failed);
    if (lastPrice) price.append(el('small', '', 'Last reported price'));
    price.append(document.createTextNode((reading.from_price ? 'From ' : '') + formatMoney.format(reading.price / 100)));
  } else {
    price.textContent = 'See seller';
  }
  const link = el('a', 'retailer', 'View listing');
  const url = new URL(row.url);
  if (url.protocol === 'https:') {
    link.href = url.href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.setAttribute('aria-label', 'View ' + row.name + ' at ' + row.seller + ' (new tab)');
  }
  end.append(price, link);

  let detail = reading.detail;
  if (status === 'unknown' && row.seller_paused) detail = 'Seller paused automated checks';
  else if (row.check_failed) detail = 'Latest check needs review';
  else if (!previewSource && !row.manual && !row.attempted_at && !reading.observed_at) detail = 'Awaiting first stock check';
  else if (reading.observed_at && status === 'unknown' && reading.status !== 'unknown') detail = 'Stock reading is more than 24 hours old';
  else if (reading.observed_at) detail = (reading.source === 'manual' ? 'Manually checked ' : 'Checked ') + formatDate.format(new Date(reading.observed_at));
  if (row.manual) detail += ' · manual watch';
  result.append(first, title, firmware);
  const badge = changeBadge(row, status);
  if (badge) result.append(badge);
  if (row.note) result.append(el('p', 'note', row.note));
  result.append(end, el('p', 'observed', detail));
  return result;
}

function draw() {
  const query = byId('search').value.trim().toLowerCase();
  const firmware = byId('firmware').value;
  const kind = byId('kind').value;
  const role = byId('role').value;
  const stock = byId('stock').value;
  const sort = byId('sort').value;
  const filtered = rows.filter((row) =>
    (!firmware || row.firmware === firmware)
    && (!kind || row.kind === kind)
    && (!role || radioRole(row) === role)
    && (!stock || displayStatus(row) === stock)
    && [row.name, row.seller, row.location, row.note].join(' ').toLowerCase().includes(query)
  );
  filtered.sort((a, b) => {
    if (sort === 'price') return (a.result.price ?? Infinity) - (b.result.price ?? Infinity) || a.name.localeCompare(b.name);
    if (sort === 'recent') return (b.result.observed_at || '').localeCompare(a.result.observed_at || '') || a.name.localeCompare(b.name);
    if (sort === 'name') return a.name.localeCompare(b.name);
    if (sort === 'role') return roleOrder.indexOf(radioRole(a)) - roleOrder.indexOf(radioRole(b)) || a.name.localeCompare(b.name);
    return priority.indexOf(displayStatus(a)) - priority.indexOf(displayStatus(b)) || a.name.localeCompare(b.name);
  });
  byId('count').textContent = filtered.length + ' of ' + rows.length + ' listings';
  writeUrlFilters();
  let empty;
  if (!filtered.length) {
    empty = el('div', 'panel empty', '');
    const reset = el('button', 'text-button', 'Clear filters');
    reset.type = 'button';
    reset.addEventListener('click', clearFilters);
    empty.append(document.createTextNode('No radios match these filters. '), reset);
  }
  byId('list').replaceChildren(...(filtered.length ? filtered.map(card) : [empty]));
}

async function refresh() {
  try {
    let data;
    if (previewSource) {
      data = JSON.parse(previewSource.textContent);
      document.querySelector('[data-admin]').hidden = true;
      document.querySelector('[data-submit]').href = '#preview-submissions';
    } else {
      const response = await fetch('catalog.php', { cache: 'no-store' });
      if (!response.ok) throw new Error('Catalog returned an error');
      data = await response.json();
    }
    // Skip redraws when nothing changed so keyboard focus and scroll position survive the 60-second refresh.
    const signature = JSON.stringify([data, data.items.map(displayStatus)]);
    if (signature === lastSignature) return;
    lastSignature = signature;
    rows = data.items;
    byId('total').textContent = rows.length;
    byId('available').textContent = rows.filter((row) => displayStatus(row) === 'in_stock').length;
    byId('sellers').textContent = new Set(rows.map((row) => row.seller)).size;
    byId('last-run').textContent = data.last_run ? formatDate.format(new Date(data.last_run)) : 'Pending';
    byId('feed-state').textContent = previewSource ? 'Preview · Nov 24, 2026 snapshot'
      : data.last_run ? 'Shared catalog · refreshes automatically'
      : 'Live checks pending · research prices shown';
    draw();
  } catch (error) {
    byId('feed-state').textContent = 'Catalog temporarily unavailable';
    if (!rows.length) byId('list').replaceChildren(el('p', 'panel empty', 'The server did not return the catalog. Reload this page or check the tracker setup.'));
  }
}

byId('filters').addEventListener('submit', (event) => event.preventDefault());
byId('filters').addEventListener('input', draw);
byId('filters').addEventListener('reset', () => setTimeout(draw, 0));
readUrlFilters();
refresh();
if (!previewSource) setInterval(() => { if (!document.hidden) refresh(); }, 60_000);
