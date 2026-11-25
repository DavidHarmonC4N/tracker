'use strict';
document.querySelectorAll('[data-confirm]').forEach((button) => {
  button.addEventListener('click', (event) => {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  });
});

// Listing search and filters: the words typed, the category (radios or antennas) and the status all have to match.
const adminSearch = document.getElementById('admin-search');
if (adminSearch) {
  const rows = [...document.querySelectorAll('#admin-list .admin-row')];
  const count = document.getElementById('admin-count');
  const chosen = { category: '', group: '' };
  const filterButtons = [...document.querySelectorAll('[data-filter]')];
  const matches = (row, terms, skip) => terms.every((term) => row.dataset.search.includes(term))
    && (skip === 'category' || !chosen.category || row.dataset.category === chosen.category)
    && (skip === 'group' || !chosen.group || row.dataset.group === chosen.group);
  const apply = () => {
    const terms = adminSearch.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    rows.forEach((row) => {
      const match = matches(row, terms, '');
      row.hidden = !match;
      if (match) shown++;
    });
    // Each button shows how many listings it would give, with the other filters and the search applied.
    filterButtons.forEach((button) => {
      const key = button.dataset.filter;
      const value = button.dataset.value;
      button.setAttribute('aria-pressed', String(chosen[key] === value));
      button.querySelector('.n').textContent = rows.filter((row) => matches(row, terms, key) && (!value || row.dataset[key] === value)).length;
    });
    const filtered = terms.length || chosen.category || chosen.group;
    count.textContent = filtered ? shown + ' of ' + rows.length + ' listings shown' : '';
  };
  adminSearch.addEventListener('input', apply);
  filterButtons.forEach((button) => button.addEventListener('click', () => {
    chosen[button.dataset.filter] = button.dataset.value;
    apply();
  }));
  apply();
}
