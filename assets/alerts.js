'use strict';

// Phone alerts page. Fills in the public ntfy topic (and one radio's topic, from ?radio=) from catalog.php.
// The topic name is set in the host's config.php, so it is read from the site instead of being written into this page.

const byId = (id) => document.getElementById(id);

function topicName(url) {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' && /^\/[A-Za-z0-9_-]{1,64}$/.test(parsed.pathname) ? parsed.pathname.slice(1) : '';
  } catch (error) {
    return '';
  }
}

async function copyText(text, statusNode) {
  try {
    await navigator.clipboard.writeText(text);
    statusNode.textContent = 'Copied: ' + text;
  } catch (error) {
    statusNode.textContent = 'Select the name above and copy it.';
  }
}

function fill(url, nameNode, copyButton, openLink, statusNode) {
  const name = topicName(url);
  if (!name) return false;
  nameNode.textContent = name;
  copyButton.disabled = false;
  copyButton.addEventListener('click', () => copyText(name, statusNode));
  openLink.href = url;
  openLink.hidden = false;
  return true;
}

async function load() {
  try {
    const response = await fetch('catalog.php', { cache: 'no-store' });
    if (!response.ok) throw new Error('catalog');
    const data = await response.json();
    if (data.staging === true && !byId('staging-bar')) {
      const bar = document.createElement('div');
      bar.className = 'staging-bar';
      bar.id = 'staging-bar';
      bar.setAttribute('role', 'note');
      bar.textContent = 'STAGING · a private test copy, not the live site';
      document.body.prepend(bar);
      document.title = '[STAGING] ' + document.title;
    }
    const ready = typeof data.public_topic === 'string' && fill(data.public_topic, byId('topic-name'), byId('copy-topic'), byId('open-topic'), byId('copy-status'));
    if (!ready) {
      byId('topic-name').textContent = 'Not turned on yet';
      byId('alerts-off').hidden = false;
      return;
    }
    const wanted = new URLSearchParams(location.search).get('radio');
    const item = wanted && /^[a-z0-9_-]{1,60}$/.test(wanted) ? data.items.find((row) => row.id === wanted) : null;
    if (item && fill(item.topic, byId('radio-topic'), byId('copy-radio'), byId('open-radio'), byId('radio-status'))) {
      byId('radio-name').textContent = item.name + ' · ' + item.seller;
      byId('radio-box').hidden = false;
      byId('radios').scrollIntoView({ block: 'center' });
    }
  } catch (error) {
    byId('topic-name').textContent = 'Could not load. Reload the page.';
  }
}

load();
