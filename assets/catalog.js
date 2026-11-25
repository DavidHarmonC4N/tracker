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
const chips = { seeed: false, manual: false, lowest: false, limited: false };
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

// My picks: a watchlist kept only in this browser (localStorage). No account, no cookie, nothing sent to the server.
const PICKS_KEY = 'offgrid.picks.v1';
const STAR_PATH = '<path d="M12 3.2l2.6 5.5 6 .8-4.4 4.2 1.1 6-5.3-2.9-5.3 2.9 1.1-6L3.4 9.5l6-.8z"/>';
const ID_PATTERN = /^[a-z0-9_-]{1,60}$/;
let picks = loadPicks();
let picksView = false;
let publicTopic = '';
let sharedIds = null;
const baseline = {};
for (const [id, pick] of Object.entries(picks)) baseline[id] = { price: pick.seenPrice ?? pick.price, status: pick.seenStatus ?? pick.status };

function loadPicks() {
  try {
    const value = JSON.parse(localStorage.getItem(PICKS_KEY) || '{}');
    if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
    return Object.fromEntries(Object.entries(value).filter(([id, pick]) => ID_PATTERN.test(id) && pick && typeof pick === 'object'));
  } catch (error) {
    return {};
  }
}

function savePicks() {
  try { localStorage.setItem(PICKS_KEY, JSON.stringify(picks)); } catch (error) { /* picks then last for this visit only */ }
}

function snapshot(row) {
  return { price: Number.isInteger(row.result.price) ? row.result.price : null, status: displayStatus(row) };
}

function togglePick(row) {
  if (picks[row.id]) {
    delete picks[row.id];
  } else {
    const now = snapshot(row);
    picks[row.id] = { added: Date.now(), price: now.price, status: now.status, seenPrice: now.price, seenStatus: now.status };
    baseline[row.id] = now;
  }
  savePicks();
  syncPickButtons();
  if (picksView) draw();
}

function syncPickButtons() {
  const count = Object.keys(picks).length;
  byId('picks-n').textContent = count;
  byId('picks-btn').setAttribute('aria-pressed', String(picksView));
  document.querySelectorAll('.star[data-pick]').forEach((button) => {
    const on = Boolean(picks[button.dataset.pick]);
    button.setAttribute('aria-pressed', String(on));
    const label = (on ? 'Stop watching ' : 'Watch ') + button.dataset.name;
    button.setAttribute('aria-label', label);
    button.title = on ? 'Stop watching' : 'Watch this radio';
  });
}

function starButton(row) {
  const button = el('button', 'star');
  button.type = 'button';
  button.dataset.pick = row.id;
  button.dataset.name = row.name;
  button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + STAR_PATH + '</svg>';
  button.addEventListener('click', () => togglePick(row));
  return button;
}

// What changed since the visitor last opened My picks (or since they added the radio).
function pickChanges(row, before) {
  const now = snapshot(row);
  const list = [];
  const available = (status) => status === 'in_stock' || status === 'orderable';
  if (before.status === 'out_of_stock' && available(now.status)) list.push(['up', 'Back in stock since you last looked']);
  else if (available(before.status) && now.status === 'out_of_stock') list.push(['out', 'Sold out since you last looked']);
  if (Number.isInteger(before.price) && Number.isInteger(now.price) && before.price !== now.price) {
    const diff = formatMoney.format(Math.abs(now.price - before.price) / 100);
    list.push(now.price < before.price ? ['down', 'Price dropped ' + diff + ' since you last looked'] : ['rise', 'Price rose ' + diff + ' since you last looked']);
  }
  return list;
}

function pickCard(row) {
  const node = card(row);
  node.querySelector('.change-badge')?.remove();
  const pick = picks[row.id];
  if (pick && !sharedIds) {
    const notes = pickChanges(row, baseline[row.id] || snapshot(row)).map(([kind, text]) => el('p', 'change ' + kind, text));
    if (notes.length) node.querySelector('.card-top').after(...notes);
    const added = 'Added ' + formatDay.format(new Date(pick.added)) + (Number.isInteger(pick.price) ? ' at ' + formatMoney.format(pick.price / 100) : '');
    const hist = el('p', 'hist', added);
    node.querySelector('.facts').after(hist);
    if (publicTopic && row.topic) hist.after(pageLink('ntfy-radio', 'Phone alerts for this radio', 'alerts.html?radio=' + encodeURIComponent(row.id), 'How to get alerts for just this radio. Free, no account.'));
  }
  return node;
}

function goneCard(id) {
  const node = el('article', 'card gone');
  const title = el('div');
  title.append(el('h3', '', 'No longer in the catalog'), el('p', 'seller', 'The owner removed this radio or paused its listing.'));
  const head = el('div', 'titlerow');
  const remove = el('button', 'sbtn quiet2', 'Remove');
  remove.type = 'button';
  remove.addEventListener('click', () => { delete picks[id]; delete baseline[id]; savePicks(); syncPickButtons(); draw(); });
  head.append(title);
  node.append(head, remove);
  return node;
}

function absoluteUrl(path) {
  return new URL(path, location.href).href;
}

function pickIds() {
  return (sharedIds || Object.keys(picks));
}

function drawPicks() {
  document.body.classList.add('picks-mode');
  byId('manual-note').hidden = true;
  byId('count').textContent = '';
  const ids = pickIds();
  const byKey = new Map(rows.map((row) => [row.id, row]));
  const parts = [];
  const head = el('div', 'dashhead');
  const titleBox = el('div');
  titleBox.append(el('h2', '', sharedIds ? 'Shared picks' : 'My picks'));
  const actions = el('div', 'dashact');
  const tools = el('div', 'dash');
  const status = el('p', 'copy-status');
  status.setAttribute('role', 'status');

  const present = ids.map((id) => byKey.get(id)).filter(Boolean);
  const changed = sharedIds ? 0 : present.filter((row) => pickChanges(row, baseline[row.id] || snapshot(row)).length).length;
  titleBox.append(el('p', '', ids.length + (ids.length === 1 ? ' radio' : ' radios')
    + (changed ? ' · ' + changed + ' changed since you last looked' : '')
    + (latestReading !== null ? ' · latest stock reading ' + formatDate.format(new Date(latestReading)) : '')));
  head.append(titleBox, actions);

  if (ids.length) {
    const link = el('button', 'sbtn', 'Copy my link');
    link.type = 'button';
    link.addEventListener('click', async () => {
      const url = absoluteUrl(location.pathname + '?picks=' + ids.join(','));
      try { await navigator.clipboard.writeText(url); status.textContent = 'Link copied. Open it on any device to see these picks.'; }
      catch (error) { status.textContent = 'Copy this link: ' + url; }
    });
    const feed = el('a', 'sbtn', 'My RSS feed');
    feed.href = 'feed.php?ids=' + ids.join(',');
    feed.target = '_blank';
    feed.rel = 'noopener';
    feed.title = 'Stock and price changes for these radios only. Add the address to any feed reader.';
    actions.append(link, feed);
    if (publicTopic) actions.append(pageLink('sbtn', 'Phone alerts (all)', 'alerts.html', 'How to get a phone alert for every restock and price drop. Free, no account.'));
    if (!sharedIds) {
      const history = el('a', 'sbtn', 'Price history');
      history.href = 'prices.html?scope=picks';
      actions.append(history);
    }
    if (sharedIds) {
      const add = el('button', 'sbtn', 'Add all to my picks');
      add.type = 'button';
      add.addEventListener('click', () => {
        for (const row of present) if (!picks[row.id]) togglePick(row);
        sharedIds = null;
        draw();
      });
      const mine = el('button', 'sbtn quiet2', 'View my picks');
      mine.type = 'button';
      mine.addEventListener('click', () => { sharedIds = null; draw(); });
      actions.append(add, mine);
    } else {
      const clear = el('button', 'sbtn quiet2', 'Clear all');
      clear.type = 'button';
      clear.addEventListener('click', () => {
        if (!window.confirm('Remove all ' + ids.length + ' radios from My picks?')) return;
        picks = {};
        for (const key of Object.keys(baseline)) delete baseline[key];
        savePicks();
        syncPickButtons();
        draw();
      });
      actions.append(clear);
    }
  }
  parts.push(head);
  parts.push(el('p', 'note2', sharedIds
    ? 'Someone shared this list with you. Star a radio to add it to your own picks.'
    : 'Your picks are saved in this browser only. No account, no cookies. Use Copy my link to open them on another device or send them to a friend.'));
  parts.push(status);

  if (!ids.length) {
    const empty = el('div', 'panel empty');
    empty.append(el('strong', '', 'Nothing starred yet'), document.createElement('br'),
      document.createTextNode('Tap the star on any radio to keep an eye on it. Your picks stay on this device.'));
    const browse = el('button', 'primary', 'Browse radios');
    browse.type = 'button';
    browse.addEventListener('click', () => { picksView = false; sharedIds = null; draw(); });
    const wrap = el('div', 'empty-action');
    wrap.append(browse);
    empty.append(wrap);
    parts.push(empty);
  } else if (rows.length) {
    const order = (row) => (sharedIds ? 0 : pickChanges(row, baseline[row.id] || snapshot(row)).length ? 0 : 1);
    present.sort((a, b) => order(a) - order(b) || priority.indexOf(displayStatus(a)) - priority.indexOf(displayStatus(b)) || a.name.localeCompare(b.name));
    const grid = el('div', 'catalog');
    grid.append(...present.map(pickCard));
    if (!sharedIds) grid.append(...ids.filter((id) => !byKey.has(id)).map(goneCard));
    parts.push(grid);
    byId('manual-note').hidden = !present.some(isManual);
    if (!sharedIds) {
      // Remember what the visitor has now seen, so next time only newer changes are flagged.
      let dirty = false;
      for (const row of present) {
        const now = snapshot(row);
        const pick = picks[row.id];
        if (pick && (pick.seenPrice !== now.price || pick.seenStatus !== now.status)) { pick.seenPrice = now.price; pick.seenStatus = now.status; dirty = true; }
      }
      if (dirty) savePicks();
    }
  }
  byId('list').replaceChildren(...parts);
  syncPickButtons();
  writeUrlFilters();
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
  chips.lowest = params.get('lowest') === '1';
  chips.limited = params.get('limited') === '1';
  for (const [key, id] of [['min', 'pmin'], ['max', 'pmax']]) {
    const value = params.get(key);
    byId(id).value = value !== null && /^\d{1,4}$/.test(value) ? value : '';
  }
  // Links from when Seeed Studio and Manual review were tabs.
  if (status === 'seeed' || status === 'manual') {
    chips[status] = true;
    tab = 'all';
  }
  showUnverified = params.get('unverified') === '1';
  const shared = (params.get('picks') || '').split(',').filter((id) => ID_PATTERN.test(id)).slice(0, 100);
  if (shared.length) { sharedIds = [...new Set(shared)]; picksView = true; }
  else if (params.get('view') === 'picks') picksView = true;
}

function writeUrlFilters() {
  const params = new URLSearchParams();
  if (picksView) {
    if (sharedIds) params.set('picks', sharedIds.join(','));
    else params.set('view', 'picks');
    try { history.replaceState(null, '', location.pathname + '?' + params.toString() + location.hash); } catch (error) { /* convenience only */ }
    return;
  }
  for (const [key, id] of Object.entries(filterIds)) {
    const control = byId(id);
    const value = control.value.trim();
    if (value && !(key === 'sort' && value === 'availability')) params.set(key, value);
  }
  if (tab !== 'available') params.set('status', tab);
  if (chips.seeed) params.set('seller', 'seeed');
  if (chips.manual) params.set('hand', '1');
  if (chips.lowest) params.set('lowest', '1');
  if (chips.limited) params.set('limited', '1');
  if (byId('pmin').value) params.set('min', byId('pmin').value);
  if (byId('pmax').value) params.set('max', byId('pmax').value);
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

// Tiny vector flags for the warehouse tags. Static markup, drawn here so they look the same on every device.
const FLAGS = {
  us: '<svg class="flag" viewBox="0 0 30 20" aria-hidden="true" focusable="false"><rect y="0.000" width="30" height="1.558" fill="#B22234"/><rect y="1.538" width="30" height="1.558" fill="#FFFFFF"/><rect y="3.077" width="30" height="1.558" fill="#B22234"/><rect y="4.615" width="30" height="1.558" fill="#FFFFFF"/><rect y="6.154" width="30" height="1.558" fill="#B22234"/><rect y="7.692" width="30" height="1.558" fill="#FFFFFF"/><rect y="9.231" width="30" height="1.558" fill="#B22234"/><rect y="10.769" width="30" height="1.558" fill="#FFFFFF"/><rect y="12.308" width="30" height="1.558" fill="#B22234"/><rect y="13.846" width="30" height="1.558" fill="#FFFFFF"/><rect y="15.385" width="30" height="1.558" fill="#B22234"/><rect y="16.923" width="30" height="1.558" fill="#FFFFFF"/><rect y="18.462" width="30" height="1.558" fill="#B22234"/><rect width="12" height="10.77" fill="#3C3B6E"/><circle cx="2.20" cy="1.90" r=".85" fill="#fff"/><circle cx="5.10" cy="1.90" r=".85" fill="#fff"/><circle cx="8.00" cy="1.90" r=".85" fill="#fff"/><circle cx="10.90" cy="1.90" r=".85" fill="#fff"/><circle cx="2.20" cy="4.45" r=".85" fill="#fff"/><circle cx="5.10" cy="4.45" r=".85" fill="#fff"/><circle cx="8.00" cy="4.45" r=".85" fill="#fff"/><circle cx="10.90" cy="4.45" r=".85" fill="#fff"/><circle cx="2.20" cy="7.00" r=".85" fill="#fff"/><circle cx="5.10" cy="7.00" r=".85" fill="#fff"/><circle cx="8.00" cy="7.00" r=".85" fill="#fff"/><circle cx="10.90" cy="7.00" r=".85" fill="#fff"/><circle cx="2.20" cy="9.55" r=".85" fill="#fff"/><circle cx="5.10" cy="9.55" r=".85" fill="#fff"/><circle cx="8.00" cy="9.55" r=".85" fill="#fff"/><circle cx="10.90" cy="9.55" r=".85" fill="#fff"/></svg>',
  eu: '<svg class="flag" viewBox="0 0 30 20" aria-hidden="true" focusable="false"><rect width="30" height="20" fill="#003399"/><circle cx="21.20" cy="10.00" r="1.15" fill="#FFCC00"/><circle cx="20.37" cy="13.10" r="1.15" fill="#FFCC00"/><circle cx="18.10" cy="15.37" r="1.15" fill="#FFCC00"/><circle cx="15.00" cy="16.20" r="1.15" fill="#FFCC00"/><circle cx="11.90" cy="15.37" r="1.15" fill="#FFCC00"/><circle cx="9.63" cy="13.10" r="1.15" fill="#FFCC00"/><circle cx="8.80" cy="10.00" r="1.15" fill="#FFCC00"/><circle cx="9.63" cy="6.90" r="1.15" fill="#FFCC00"/><circle cx="11.90" cy="4.63" r="1.15" fill="#FFCC00"/><circle cx="15.00" cy="3.80" r="1.15" fill="#FFCC00"/><circle cx="18.10" cy="4.63" r="1.15" fill="#FFCC00"/><circle cx="20.37" cy="6.90" r="1.15" fill="#FFCC00"/></svg>',
  cn: '<svg class="flag" viewBox="0 0 30 20" aria-hidden="true" focusable="false"><rect width="30" height="20" fill="#DE2910"/><polygon points="5.00,2.40 5.67,4.47 7.85,4.47 6.09,5.75 6.76,7.83 5.00,6.55 3.24,7.83 3.91,5.75 2.15,4.47 4.33,4.47" fill="#FFDE00"/><polygon points="10.00,1.00 10.22,1.69 10.95,1.69 10.36,2.12 10.59,2.81 10.00,2.38 9.41,2.81 9.64,2.12 9.05,1.69 9.78,1.69" fill="#FFDE00"/><polygon points="12.00,3.10 12.22,3.79 12.95,3.79 12.36,4.22 12.59,4.91 12.00,4.48 11.41,4.91 11.64,4.22 11.05,3.79 11.78,3.79" fill="#FFDE00"/><polygon points="12.00,6.00 12.22,6.69 12.95,6.69 12.36,7.12 12.59,7.81 12.00,7.38 11.41,7.81 11.64,7.12 11.05,6.69 11.78,6.69" fill="#FFDE00"/><polygon points="10.00,8.10 10.22,8.79 10.95,8.79 10.36,9.22 10.59,9.91 10.00,9.48 9.41,9.91 9.64,9.22 9.05,8.79 9.78,8.79" fill="#FFDE00"/></svg>',
};

function flagNode(key) {
  if (!FLAGS[key]) return null;
  const holder = document.createElement('span');
  holder.innerHTML = FLAGS[key];
  return holder.firstChild;
}

function fact(text, className, title) {
  const node = el('span', 'fact' + (className ? ' ' + className : ''));
  const flag = FLAGS[className] ? flagNode(className) : null;
  if (flag) node.append(flag);
  node.append(document.createTextNode(text));
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
    const drop = lastPrice ? null : recentDrop(row);
    if (lastPrice) price.append(el('small', '', 'Last reported price'));
    else if (drop) price.append(el('small', '', 'was ' + formatMoney.format(drop.from / 100)));
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
  const head = el('div', 'titlerow');
  head.append(title, starButton(row));
  result.append(first, head, facts);
  const marks = el('div', 'badges');
  if (lowestSeen(row)) marks.append(el('span', 'badge low', 'Lowest seen'));
  if (isLimited(row)) marks.append(el('span', 'badge ltd', 'Limited · ' + row.result.qty + ' left'));
  const fall = recentDrop(row);
  if (fall) {
    const when = fall.days === 0 ? 'today' : fall.days === 1 ? '1 day ago' : fall.days + ' days ago';
    marks.append(el('span', 'badge mute', 'Dropped ' + formatMoney.format((fall.from - row.result.price) / 100) + ' · ' + when));
  }
  if (marks.children.length) result.append(marks);
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

// "Lowest seen": at the lowest price this tracker has recorded, and the price has moved at least once.
function lowestSeen(row) {
  return !previewSource && Number.isInteger(row.low) && Number.isInteger(row.high) && row.high > row.low
    && Number.isInteger(row.result.price) && row.result.price === row.low && displayStatus(row) !== 'unknown';
}

// "Limited": the seller reports a small count, five or fewer.
function isLimited(row) {
  return displayStatus(row) === 'in_stock' && Number.isInteger(row.result.qty) && row.result.qty > 0 && row.result.qty <= 5;
}

// A price drop in the last 30 days, from the recorded history.
function recentDrop(row) {
  const was = row.was;
  if (previewSource || !was || !Number.isInteger(row.result.price) || was.price <= row.result.price) return null;
  const days = (Date.now() - Date.parse(was.t)) / 86400000;
  return Number.isFinite(days) && days <= 30 ? { from: was.price, days: Math.max(0, Math.round(days)) } : null;
}

function priceBounds() {
  const read = (id) => {
    const value = byId(id).value.trim();
    return /^\d{1,4}$/.test(value) ? Number(value) * 100 : null;
  };
  return { min: read('pmin'), max: read('pmax') };
}

function matchesPrice(row) {
  const { min, max } = priceBounds();
  if (min === null && max === null) return true;
  const price = row.result.price;
  return Number.isInteger(price) && (min === null || price >= min) && (max === null || price <= max);
}

function matchesChips(row) {
  return (!chips.seeed || isSeeed(row)) && (!chips.manual || isManual(row))
    && (!chips.lowest || lowestSeen(row)) && (!chips.limited || isLimited(row)) && matchesPrice(row);
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

// Roll-up sections. Which groups the visitor folded is remembered in this browser. Out of stock and Unverified start folded.
const FOLD_KEY = 'offgrid.folds.v1';
const foldDefaults = { out_of_stock: true, unknown: true };
let folds = loadFolds();
const CHEVRON = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>';

function loadFolds() {
  try {
    const value = JSON.parse(localStorage.getItem(FOLD_KEY) || '{}');
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  } catch (error) {
    return {};
  }
}

function isFolded(key) {
  return key in folds ? Boolean(folds[key]) : Boolean(foldDefaults[key]);
}

function setFolded(key, value) {
  folds[key] = value;
  try { localStorage.setItem(FOLD_KEY, JSON.stringify(folds)); } catch (error) { /* folds then last for this visit only */ }
}

// One group of cards with a heading. With a single group on screen there is nothing to fold, so it stays a plain heading.
function groupSection(key, titleNodes, note, cardsList, foldable) {
  const section = el('section', 'group');
  const head = el('div', 'group-head');
  const heading = el('h3');
  const grid = el('div', 'catalog');
  grid.append(...cardsList);
  if (foldable) {
    const button = el('button', 'fold');
    button.type = 'button';
    button.dataset.fold = key;
    button.id = 'fold-' + key.replace(/[^a-z0-9]/gi, '-');
    grid.id = 'group-' + key.replace(/[^a-z0-9]/gi, '-');
    button.setAttribute('aria-controls', grid.id);
    const chevron = el('span', 'chev');
    chevron.innerHTML = CHEVRON;
    button.append(chevron, ...titleNodes);
    const apply = (folded) => {
      section.classList.toggle('folded', folded);
      grid.hidden = folded;
      button.setAttribute('aria-expanded', String(!folded));
    };
    apply(isFolded(key));
    button.addEventListener('click', () => {
      const folded = !section.classList.contains('folded');
      setFolded(key, folded);
      apply(folded);
      syncFoldAll();
    });
    heading.append(button);
  } else {
    heading.append(...titleNodes);
  }
  head.append(heading);
  if (note) head.append(el('p', 'dim', note));
  section.append(head, grid);
  return section;
}

function syncFoldAll() {
  const button = byId('fold-all');
  const groups = document.querySelectorAll('#list .fold');
  button.hidden = groups.length < 2;
  const anyOpen = [...groups].some((item) => item.getAttribute('aria-expanded') === 'true');
  button.textContent = anyOpen ? 'Collapse all' : 'Expand all';
  button.dataset.mode = anyOpen ? 'collapse' : 'expand';
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

// A staging copy of the site says so on every page, so nobody mistakes it for the live catalog.
function markStaging(data) {
  if (!data || data.staging !== true || document.getElementById('staging-bar')) return;
  const bar = el('div', 'staging-bar', 'STAGING · a private test copy, not the live site');
  bar.id = 'staging-bar';
  bar.setAttribute('role', 'note');
  document.body.prepend(bar);
  if (!document.title.startsWith('[STAGING]')) document.title = '[STAGING] ' + document.title;
}

// Phone alerts through ntfy: shown only when the owner has set a public topic. The links go to the "Phone alerts" page.
function pageLink(className, text, href, hint) {
  const link = el('a', className, text);
  link.href = href;
  link.title = hint;
  return link;
}

function draw() {
  document.body.classList.toggle('picks-mode', picksView);
  if (picksView) { drawPicks(); return; }
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
    const groups = priority.map((status) => [status, shown.filter((row) => displayStatus(row) === status)]).filter(([, list]) => list.length);
    for (const [status, group] of groups) {
      parts.push(groupSection(status, [document.createTextNode(lookup.status[status] + ' · ' + group.length)], groupNotes[status], group.map(card), groups.length > 1));
    }
  } else if (chips.seeed) {
    // One section per warehouse, each in the chosen sort order.
    const groups = ['us', 'eu', 'cn', ''].map((key) => [key, shown.filter((row) => (row.warehouse || '') === key)]).filter(([, list]) => list.length);
    for (const [key, group] of groups) {
      const label = lookup.warehouse[key] || 'Warehouse not specified';
      const flag = flagNode(key);
      const title = [];
      if (flag) title.push(flag);
      title.push(document.createTextNode(label + ' · ' + group.length));
      parts.push(groupSection('wh:' + key, title, '', group.map(card), groups.length > 1));
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
  syncFoldAll();
  syncPickButtons();
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
  const { min, max } = priceBounds();
  if (min !== null || max !== null) {
    const label = 'Price: ' + (min !== null ? '$' + min / 100 : 'any') + ' to ' + (max !== null ? '$' + max / 100 : 'any');
    list.push({ label, clear: () => { byId('pmin').value = ''; byId('pmax').value = ''; } });
  }
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
  byId('reset').hidden = !(active.length || Object.values(chips).some(Boolean) || tab !== 'available');
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
    markStaging(data);
    publicTopic = typeof data.public_topic === 'string' && /^https:\/\//.test(data.public_topic) ? data.public_topic : '';
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
  for (const key of Object.keys(chips)) chips[key] = false;
  showUnverified = false;
  setTimeout(draw, 0);
});
document.querySelectorAll('[data-chip]').forEach((button) => {
  button.addEventListener('click', () => {
    const key = button.dataset.chip;
    chips[key] = !chips[key];
    // "Available now" would hide most of a seller's listings, so a chip opens the full list.
    if (chips[key] && tab === 'available' && (key === 'seeed' || key === 'manual')) tab = 'all';
    showUnverified = false;
    draw();
  });
});
byId('fold-all').addEventListener('click', () => {
  const collapse = byId('fold-all').dataset.mode === 'collapse';
  document.querySelectorAll('#list .fold').forEach((item) => {
    setFolded(item.dataset.fold, collapse);
  });
  draw();
});
byId('picks-btn').addEventListener('click', () => {
  picksView = !picksView;
  if (!picksView) sharedIds = null;
  draw();
  if (picksView) byId('inventory').scrollIntoView({ block: 'start' });
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
  // Coming back to the tab (or the network) should not wait for the next tick.
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('online', refresh);
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
