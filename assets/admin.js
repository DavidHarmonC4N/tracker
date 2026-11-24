'use strict';
document.querySelectorAll('[data-confirm]').forEach((button) => {
  button.addEventListener('click', (event) => {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  });
});

const adminSearch = document.getElementById('admin-search');
if (adminSearch) {
  const rows = [...document.querySelectorAll('#admin-list .admin-row')];
  const count = document.getElementById('admin-count');
  const apply = () => {
    const terms = adminSearch.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    rows.forEach((row) => {
      const match = terms.every((term) => row.dataset.search.includes(term));
      row.hidden = !match;
      if (match) shown++;
    });
    count.textContent = terms.length ? shown + ' of ' + rows.length + ' listings match' : '';
  };
  adminSearch.addEventListener('input', apply);
}
