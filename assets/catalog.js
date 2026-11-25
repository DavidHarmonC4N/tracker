'use strict';

const lookup = {
  firmware: { meshcore: 'MeshCore only', meshtastic: 'Meshtastic only', both: 'Both firmware options' },
  kind: { handheld: 'Handheld', keyboard: 'Keyboard radio', solar: 'Solar node', base: 'Base station', tracker: 'GPS tracker', board: 'Board or kit', other: 'Other' },
  warehouse: { us: 'U.S. warehouse', eu: 'EU warehouse', cn: 'China warehouse' },
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
const filterIds = { q: 'search', firmware: 'firmware', role: 'role', kind: 'kind', warehouse: 'warehouse', sort: 'sort' };
const tabs = [
  { key: 'available', label: 'Available now', note: 'In stock or orderable' },
  { key: 'in_stock', label: 'In stock' },
  { key: 'orderable', label: 'Orderable' },
  { key: 'preorder', label: 'Preorder' },
  { key: 'out_of_stock', label: 'Out of stock' },
  { key: 'unknown', label: 'Unverified' },
  { key: 'all', label: 'All' }
];
const groupNotes = {
  in_stock: 'The seller reports units on hand.',
  orderable: 'The seller accepts orders. Shelf stock is not confirmed.',
  preorder: 'Preorder or backorder.',
  unknown: 'Awaiting a check, paused by the seller, or a reading older than 24 hours. Prices may be out of date.',
  out_of_stock: 'The seller reports none available.'
};
let tab = 'available';
let showUnverified = false;
let restoreFocus = false;
let latestReading = null;
// Quick filters. They combine with the availability tab and the filters.
const chips = { seeed: false, manual: false };
const selectFilters = [['role', 'Use'], ['firmware', 'Firmware'], ['warehouse', 'Ships from'], ['kind', 'Radio type']];

// Small line icons for the radio type. They are decoration: the text label next to them says the same thing.
const ICONS = {
  handheld: '<rect x="7" y="2.5" width="10" height="19" rx="2.2"/><rect x="9.2" y="5.2" width="5.6" height="6" rx=".8"/><circle cx="12" cy="16.6" r="1.2"/>',
  keyboard: '<rect x="2.5" y="8" width="19" height="12" rx="2"/><path d="M6 11.5h1M9.5 11.5h1M13 11.5h1M16.5 11.5h1M6 14.5h1M9.5 14.5h1M13 14.5h1M16.5 14.5h1M8 17.3h8M12 8V4M9.8 5.4a3.2 3.2 0 0 1 4.4 0"/>',
  solar: '<path d="M3 20.5l3-9h12l3 9z"/><path d="M5 16h14M9.2 11.5l-1.1 9M14.8 11.5l1.1 9"/><circle cx="12" cy="4.6" r="1.8"/><path d="M12 1.2v.7M7.6 4.6h.7M15.7 4.6h.7"/>',
  tracker: '<rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M12 7a3.2 3.2 0 0 1 3.2 3.2c0 2.2-3.2 5.3-3.2 5.3s-3.2-3.1-3.2-5.3A3.2 3.2 0 0 1 12 7z"/><circle cx="12" cy="10.2" r="1.1"/>',
  base: '<rect x="3" y="13" width="18" height="7.5" rx="1.8"/><path d="M7 16.8h2M12 13V5M9.2 7a4 4 0 0 1 5.6 0M7 4.6a7 7 0 0 1 10 0"/>',
  board: '<rect x="5" y="5" width="14" height="14" rx="1.5"/><rect x="9" y="9" width="6" height="6"/><path d="M8 2v3M12 2v3M16 2v3M8 19v3M12 19v3M16 19v3M2 8h3M2 12h3M2 16h3M19 8h3M19 12h3M19 16h3"/>',
  other: '<circle cx="12" cy="12" r="2"/><path d="M7.8 7.8a6 6 0 0 0 0 8.4M16.2 7.8a6 6 0 0 1 0 8.4M4.9 4.9a10 10 0 0 0 0 14.2M19.1 4.9a10 10 0 0 1 0 14.2"/>'
};
const BARS = { in_stock: 4, orderable: 3, preorder: 2, unknown: 1, out_of_stock: 0 };

function iconNode(kind) {
  const node = el('span', 'ico');
  node.setAttribute('aria-hidden', 'true');
  // Static markup from the table above, never page data.
  node.innerHTML = '<svg viewBox="0 0 24 24" focusable="false">' + (ICONS[kind] || ICONS.other) + '</svg>';
  return node;
}

function statusBars(status) {
  const node = el('span', 'bars');
  node.setAttribute('aria-hidden', 'true');
  for (let i = 0; i < 4; i += 1) node.append(el('i', i < (BARS[status] ?? 0) ? 'on' : ''));
  return node;
}

function readUrlFilters() {
  const params = new URLSearchParams(location.search);
  for (const [key, id] of Object.entries(filterIds)) {
    const control = byId(id);
    const value = params.get(key);
    if (value === null) continue;
    control.value = value;
    if (control.value !== value && control.tagName === 'SELECT') control.selectedIndex = 0;
  }
  // "stock" is the older name for the availability filter, kept so existing shared links still work.
  const status = params.get('status') ?? params.get('stock');
  if (status && tabs.some((item) => item.key === status)) tab = status;
  chips.seeed = params.get('seller') === 'seeed';
  chips.manual = params.get('hand') === '1';
  // Links from when Seeed Studio and Manual review were tabs.
  if (status === 'seeed' || status === 'manual') {
    chips[status] = true;
    tab = 'all';
  }
  showUnverified = params.get('unverified') === '1';
}

function writeUrlFilters() {
  const params = new URLSearchParams();
  for (const [key, id] of Object.entries(filterIds)) {
    const control = byId(id);
    const value = control.value.trim();
    if (value && !(key === 'sort' && value === 'availability')) params.set(key, value);
  }
  if (tab !== 'available') params.set('status', tab);
  if (chips.seeed) params.set('seller', 'seeed');
  if (chips.manual) params.set('hand', '1');
  if (showUnverified) params.set('unverified', '1');
  const query = params.toString();
  try {
    history.replaceState(null, '', location.pathname + (query ? '?' + query : '') + location.hash);
  } catch (error) { /* URL sync is a convenience only */ }
}

function clearFilters() {
  // The page's reset button has id="reset", which shadows the form's own reset() method, so click it instead.
  byId('reset').click();
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

function fact(text, className, title) {
  const node = el('span', 'fact' + (className ? ' ' + className : ''), text);
  if (title) node.title = title;
  return node;
}

function card(row) {
  const status = displayStatus(row);
  const reading = row.result;
  const result = el('article', 'card');
  const first = el('div', 'card-top');
  const count = status === 'in_stock' && Number.isInteger(reading.qty) ? ' · ' + reading.qty + ' shown' : '';
  const role = radioRole(row);
  const kind = el('span', 'card-kind');
  kind.append(iconNode(row.kind), document.createTextNode(role === 'unspecified' ? lookup.kind[row.kind] : lookup.role[role]));
  const tag = el('span', 'tag ' + status);
  tag.append(statusBars(status), document.createTextNode(lookup.status[status] + count));
  first.append(kind, tag);

  const title = el('div');
  const sellerLine = el('p', 'seller', row.seller + (row.location ? ' · ' + row.location : ''));
  if (isManual(row)) {
    const star = el('span', 'asterisk', ' *');
    star.title = 'This seller blocks automated stock checks, so this listing is checked by hand.';
    star.setAttribute('aria-label', '. This seller blocks automated stock checks, so this listing is checked by hand.');
    sellerLine.append(star);
  }
  title.append(el('h3', '', row.name), sellerLine);

  const facts = el('p', 'facts');
  facts.append(fact(lookup.firmware[row.firmware]), fact(lookup.setup[row.setup]));
  if (lookup.warehouse[row.warehouse]) {
    facts.append(fact(lookup.warehouse[row.warehouse], row.warehouse,
      'Ships from the seller’s ' + lookup.warehouse[row.warehouse].replace(' warehouse', '') + ' warehouse. Shipping time, duties and fees depend on the warehouse.'));
  }

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
    link.setAttribute('aria-label', 'View listing: ' + row.name + ' at ' + row.seller + ' (new tab)');
  }
  end.append(price, link);

  let detail = reading.detail;
  if (status === 'unknown' && row.seller_paused) detail = 'Seller paused automated checks';
  else if (row.check_failed) detail = 'Latest check needs review';
  else if (!previewSource && !row.manual && !row.attempted_at && !reading.observed_at) detail = 'Awaiting first stock check';
  else if (reading.observed_at && status === 'unknown' && reading.status !== 'unknown') detail = 'Stock reading is more than 24 hours old';
  else if (reading.observed_at) detail = (reading.source === 'manual' ? 'Manually checked ' : 'Checked ') + formatDate.format(new Date(reading.observed_at));
  if (row.manual) detail += ' · manual watch';
  result.append(first, title, facts);
  const badge = changeBadge(row, status);
  if (badge) result.append(badge);
  if (row.note) result.append(el('p', 'note', row.note));
  result.append(end, el('p', 'observed', detail));
  return result;
}

// Manual-review listings are the sellers that block or refuse automated checks (Etsy, DigiKey, Mouser and so on).
function isManual(row) {
  return Boolean(row.manual);
}

function isSeeed(row) {
  try {
    const host = new URL(row.url).hostname.toLowerCase();
    return host === 'seeedstudio.com' || host.endsWith('.seeedstudio.com');
  } catch (error) {
    return false;
  }
}

function matchesChips(row) {
  return (!chips.seeed || isSeeed(row)) && (!chips.manual || isManual(row));
}

function matchesTab(key, row) {
  const status = displayStatus(row);
  return key === 'all' || (key === 'available' ? status === 'in_stock' || status === 'orderable' : status === key);
}

function buildTabs() {
  const bar = byId('status-tabs');
  bar.replaceChildren(...tabs.map((item) => {
    const button = el('button', 'status-tab');
    button.type = 'button';
    button.dataset.tab = item.key;
    if (item.note) button.title = item.note;
    button.append(el('span', 'tab-label', item.label), el('span', 'tab-count', '0'));
    button.addEventListener('click', () => {
      tab = item.key;
      showUnverified = false;
      draw();
    });
    return button;
  }));
}

function sortRows(list, sort) {
  list.sort((a, b) => {
    if (sort === 'price') return (a.result.price ?? Infinity) - (b.result.price ?? Infinity) || a.name.localeCompare(b.name);
    if (sort === 'recent') return (b.result.observed_at || '').localeCompare(a.result.observed_at || '') || a.name.localeCompare(b.name);
    if (sort === 'name') return a.name.localeCompare(b.name);
    if (sort === 'role') return roleOrder.indexOf(radioRole(a)) - roleOrder.indexOf(radioRole(b)) || a.name.localeCompare(b.name);
    return priority.indexOf(displayStatus(a)) - priority.indexOf(displayStatus(b)) || a.name.localeCompare(b.name);
  });
  return list;
}

function draw() {
  const query = byId('search').value.trim().toLowerCase();
  const firmware = byId('firmware').value;
  const kind = byId('kind').value;
  const role = byId('role').value;
  const warehouse = byId('warehouse').value;
  const sort = byId('sort').value;
  const base = rows.filter((row) =>
    (!firmware || row.firmware === firmware)
    && (!kind || row.kind === kind)
    && (!role || radioRole(row) === role)
    && (!warehouse || row.warehouse === warehouse)
    && matchesChips(row)
    && [row.name, row.seller, row.location, row.note].join(' ').toLowerCase().includes(query)
  );

  // Tab counts follow the other filters, so each tab shows what a click would give you.
  document.querySelectorAll('.status-tab').forEach((button) => {
    const key = button.dataset.tab;
    const total = base.filter((row) => matchesTab(key, row)).length;
    button.querySelector('.tab-count').textContent = total;
    button.setAttribute('aria-pressed', String(key === tab));
    button.classList.toggle('active', key === tab);
  });

  const unverifiedRows = base.filter((row) => displayStatus(row) === 'unknown');
  const inTab = base.filter((row) => matchesTab(tab, row));
  // Unverified listings stay out of the broad views until the visitor asks for them.
  const hideable = tab === 'available' || tab === 'all';
  const hiddenCount = hideable ? unverifiedRows.length : 0;
  let shown = inTab;
  if (hideable && tab === 'all' && !showUnverified) shown = inTab.filter((row) => displayStatus(row) !== 'unknown');
  if (hideable && tab === 'available' && showUnverified) shown = inTab.concat(unverifiedRows);
  sortRows(shown, sort);
  const visibleCount = shown.length;
  byId('count').textContent = visibleCount + ' of ' + rows.length + ' listings'
    + (hideable && !showUnverified && hiddenCount ? ' · ' + hiddenCount + ' unverified hidden' : '');
  writeUrlFilters();

  renderToolbarState();

  const parts = [];
  if (chips.manual) {
    parts.push(el('p', 'tab-intro', 'These sellers block automated stock checks, so their price and stock are entered by hand and may lag the seller’s page.'));
  }
  if (chips.seeed) {
    parts.push(el('p', 'tab-intro', 'Seeed Studio ships each product from U.S., EU and China warehouses. Price, shipping time, duties and fees differ by warehouse, so check the warehouse badge before you order.'));
  }
  if (!shown.length) {
    const empty = el('div', 'panel empty', '');
    const reset = el('button', 'text-button', 'Clear filters');
    reset.type = 'button';
    reset.addEventListener('click', clearFilters);
    const hint = tab === 'available' ? 'No available radios match. Try the All tab. ' : 'No radios match these filters. ';
    empty.append(document.createTextNode(hint), reset);
    parts.push(empty);
  } else if (!chips.seeed && sort === 'availability' && hideable) {
    for (const status of priority) {
      const group = shown.filter((row) => displayStatus(row) === status);
      if (!group.length) continue;
      const section = el('section', 'group');
      const heading = el('div', 'group-head');
      heading.append(el('h3', '', lookup.status[status] + ' · ' + group.length), el('p', 'dim', groupNotes[status]));
      const grid = el('div', 'catalog');
      grid.append(...group.map(card));
      section.append(heading, grid);
      parts.push(section);
    }
  } else if (chips.seeed) {
    // One section per warehouse, each in the chosen sort order.
    for (const key of ['us', 'eu', 'cn', '']) {
      const group = shown.filter((row) => (row.warehouse || '') === key);
      if (!group.length) continue;
      const label = lookup.warehouse[key] || 'Warehouse not specified';
      const section = el('section', 'group');
      const heading = el('div', 'group-head');
      heading.append(el('h3', '', label + ' · ' + group.length));
      const grid = el('div', 'catalog');
      grid.append(...group.map(card));
      section.append(heading, grid);
      parts.push(section);
    }
  } else {
    const grid = el('div', 'catalog');
    grid.append(...shown.map(card));
    parts.push(grid);
  }

  if (hideable && (hiddenCount || showUnverified)) {
    const toggle = el('button', 'unverified-toggle', showUnverified
      ? 'Hide unverified listings'
      : 'Show ' + hiddenCount + ' unverified listing' + (hiddenCount === 1 ? '' : 's'));
    toggle.type = 'button';
    toggle.id = 'unverified-toggle';
    toggle.setAttribute('aria-expanded', String(showUnverified));
    toggle.addEventListener('click', () => {
      showUnverified = !showUnverified;
      restoreFocus = true;
      draw();
    });
    parts.push(toggle);
  }
  byId('list').replaceChildren(...parts);
  byId('manual-note').hidden = !shown.some(isManual);
  if (restoreFocus) {
    restoreFocus = false;
    byId('unverified-toggle')?.focus();
  }
}

function activeFilters() {
  const list = [];
  const query = byId('search').value.trim();
  if (query) list.push({ label: 'Search: ' + query, clear: () => { byId('search').value = ''; } });
  for (const [id, name] of selectFilters) {
    const control = byId(id);
    if (control.value) list.push({ label: name + ': ' + control.options[control.selectedIndex].text, clear: () => { control.value = ''; }, select: true });
  }
  return list;
}

// Chips, the "Filters" count, the active-filter chips and the Clear all button.
function renderToolbarState() {
  document.querySelectorAll('[data-chip]').forEach((button) => {
    button.setAttribute('aria-pressed', String(Boolean(chips[button.dataset.chip])));
  });
  const active = activeFilters();
  const selects = active.filter((item) => item.select).length;
  const counter = byId('filters-n');
  counter.textContent = selects;
  counter.hidden = selects === 0;
  byId('active-chips').replaceChildren(...active.map((item) => {
    const button = el('button', 'chip x', item.label);
    button.type = 'button';
    button.setAttribute('aria-label', 'Remove filter: ' + item.label);
    button.addEventListener('click', () => {
      item.clear();
      draw();
      // Keep keyboard users in the toolbar: the remaining chips if any, otherwise the search box.
      (byId('active-chips').children.length ? byId('active-chips') : byId('search')).focus();
    });
    return button;
  }));
  byId('chip-sep').hidden = active.length === 0;
  byId('reset').hidden = !(active.length || chips.seeed || chips.manual || tab !== 'available');
}

function ago(time) {
  const minutes = Math.floor((Date.now() - time) / 60000);
  if (minutes < 1) return 'just now';
  if (minutes < 60) return minutes + ' min ago';
  const hours = Math.floor(minutes / 60);
  if (hours < 48) return hours + ' hr ago';
  return Math.floor(hours / 24) + ' days ago';
}

function showLatest() {
  const node = byId('last-run');
  if (latestReading === null) {
    node.textContent = 'Pending';
    node.removeAttribute('title');
  } else {
    node.textContent = ago(latestReading);
    node.title = formatDate.format(new Date(latestReading));
  }
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
    byId('orderable').textContent = rows.filter((row) => displayStatus(row) === 'orderable').length;
    byId('sellers').textContent = 'from ' + new Set(rows.map((row) => row.seller)).size + ' sellers';
    const readings = rows.map((row) => Date.parse(row.result.observed_at || '')).filter(Number.isFinite);
    latestReading = previewSource || !readings.length ? null : Math.max(...readings);
    showLatest();
    byId('feed-state').textContent = previewSource ? 'Preview · Nov 24, 2026 snapshot'
      : data.last_run || latestReading !== null ? 'Shared catalog · refreshes automatically'
      : 'Live checks pending · research prices shown';
    draw();
  } catch (error) {
    byId('feed-state').textContent = 'Catalog temporarily unavailable';
    if (!rows.length) byId('list').replaceChildren(el('p', 'panel empty', 'The server did not return the catalog. Reload this page or check the tracker setup.'));
  }
}

byId('filters').addEventListener('submit', (event) => event.preventDefault());
byId('filters').addEventListener('input', draw);
byId('filters').addEventListener('reset', () => {
  tab = 'available';
  chips.seeed = false;
  chips.manual = false;
  showUnverified = false;
  setTimeout(draw, 0);
});
document.querySelectorAll('[data-chip]').forEach((button) => {
  button.addEventListener('click', () => {
    const key = button.dataset.chip;
    chips[key] = !chips[key];
    // "Available now" would hide most of a seller's listings, so a chip opens the full list.
    if (chips[key] && tab === 'available') tab = 'all';
    showUnverified = false;
    draw();
  });
});
byId('filters-toggle').addEventListener('click', () => {
  const panel = byId('filter-selects');
  const open = !panel.classList.contains('open');
  panel.classList.toggle('open', open);
  byId('filters-toggle').setAttribute('aria-expanded', String(open));
});
buildTabs();
readUrlFilters();
refresh();
if (!previewSource) {
  setInterval(() => { if (!document.hidden) refresh(); }, 60_000);
  setInterval(showLatest, 30_000);
}

// Anonymous visitor count. Skipped in preview mode. With Do Not Track on, the page only reads the counts.
async function countVisit() {
  if (previewSource) return;
  try {
    const dnt = navigator.doNotTrack === '1' || window.doNotTrack === '1';
    const response = await fetch('visit.php', { method: dnt ? 'GET' : 'POST', cache: 'no-store' });
    if (!response.ok) return;
    const counts = await response.json();
    if (!Number.isInteger(counts.today) || !Number.isInteger(counts.total)) return;
    byId('visitors-today').textContent = counts.today.toLocaleString('en-US');
    byId('visitors-total').textContent = counts.total.toLocaleString('en-US');
    byId('visitors').hidden = false;
  } catch (error) { /* the counter is optional */ }
}
countVisit();
