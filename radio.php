<?php
declare(strict_types=1);

// One page per radio (radio.php?r=<name>-<code>): what it is, its price and stock now (one row per warehouse), the recorded
// price history, and similar radios. Plain HTML, readable without JavaScript.

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/radio.php';

common_headers();
header('Content-Type: text/html; charset=utf-8');

try {
    $items = endpoint_result()['items'];
    $groups = radio_groups($items);
    $history = config()['data_dir'] !== '' ? (load_state(data_dir())['history'] ?? []) : [];
} catch (Throwable $error) {
    error_log('Offgrid radio: ' . $error->getMessage());
    http_response_code(503);
    echo '<!doctype html><title>Temporarily unavailable</title><p>This page is temporarily unavailable. Try again in a minute.</p>';
    exit;
}
$ref = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';
$group = radio_find($groups, $ref);
$version = (string)(@filemtime(__DIR__ . '/assets/styles.css') ?: 1);

if (!$group) {
    http_response_code(404);
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Radio not found | Offgrid Comms Tracker</title><link rel="stylesheet" href="assets/styles.css?v=<?= h($version) ?>"></head>
<body><?= radios_page_chrome('header') ?><main class="wrap guide"><div class="pagehead"><div><h1>We could not find that radio</h1>
<p class="lead">It may have been removed from the catalog. <a href="radios.php">See every radio we track</a> or <a href="./#inventory">search the catalog</a>.</p></div></div></main><?= radios_page_chrome('footer') ?></body></html>
<?php
    exit;
}
if ($ref !== $group['ref']) {
    // The name part changed (the listing was renamed). Send visitors and crawlers to the current address.
    header('Location: radio.php?r=' . $group['ref'], true, 301);
    exit;
}
header('Cache-Control: public, max-age=300');

$now = time();
$summary = radio_group_summary($group, $now);
$base = base_url();
$canonical = $base !== '' ? $base . 'radio.php?r=' . $group['ref'] : '';
$statusLabel = RADIO_STATUS_LABEL[$summary['status']];
$title = $group['name'] . ' at ' . $group['seller'] . ': Price and Stock | Offgrid Comms Tracker';
$description = radio_meta_description($group, $summary);
$series = radio_series($group, $history);
$svg = radio_history_svg($series, $now, 'Price history for ' . $group['name'] . ' at ' . $group['seller']);
$changes = radio_change_rows($group, $history);
$similar = radio_similar($groups, $group, $now);
$fitting = ($group['category'] ?? 'radio') === 'antenna' ? antenna_fitting_radios($groups, $group, $now) : radio_fitting_antennas($groups, $group, $now);
$isAntenna = ($group['category'] ?? 'radio') === 'antenna';
$warehouses = array_values(array_filter(array_map(static fn($o) => WAREHOUSES[$o['warehouse'] ?? ''] ?? '', $group['offers'])));
$https = parse_url((string)$group['url'], PHP_URL_SCHEME) === 'https';
$modified = $summary['observed'];
$graph = ['@context'=>'https://schema.org', '@type'=>'WebPage', 'name'=>$title, 'description'=>$description, 'inLanguage'=>'en']
    + ($canonical !== '' ? ['url'=>$canonical, 'isPartOf'=>['@type'=>'WebSite', 'name'=>'Offgrid Comms Tracker', 'url'=>$base],
        'breadcrumb'=>['@type'=>'BreadcrumbList', 'itemListElement'=>[
            ['@type'=>'ListItem', 'position'=>1, 'name'=>'Offgrid Comms Tracker', 'item'=>$base],
            ['@type'=>'ListItem', 'position'=>2, 'name'=>$isAntenna ? 'Antennas' : 'All radios', 'item'=>$base . 'radios.php' . ($isAntenna ? '#antennas' : '')],
            ['@type'=>'ListItem', 'position'=>3, 'name'=>$group['name'], 'item'=>$canonical]]]] : [])
    + ($modified ? ['dateModified'=>gmdate('c', $modified)] : []);
$phoneAlerts = ntfy_public_base() !== '' ? ntfy_radio_topic(ntfy_public_base(), (string)$group['offers'][0]['id']) : '';
$topicName = $phoneAlerts !== '' ? basename((string)parse_url($phoneAlerts, PHP_URL_PATH)) : '';
$topicHost = $phoneAlerts !== '' ? (string)parse_url($phoneAlerts, PHP_URL_HOST) : '';
$feedIds = implode(',', array_map(static fn($offer) => rawurlencode((string)$offer['id']), $group['offers']));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <meta name="theme-color" content="#121511">
  <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= h($canonical) ?>">
  <?php endif; ?>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="favicon.ico" sizes="32x32">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Offgrid Comms Tracker">
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <?php if ($canonical !== ''): ?><meta property="og:url" content="<?= h($canonical) ?>">
  <meta property="og:image" content="<?= h($base) ?>assets/og-image.png">
  <?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= h($title) ?>">
  <meta name="twitter:description" content="<?= h($description) ?>">
  <link rel="alternate" type="application/rss+xml" title="Stock and price changes" href="feed.php">
  <link rel="stylesheet" href="assets/styles.css?v=<?= h($version) ?>">
  <script type="application/ld+json"><?= json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
</head>
<body>
  <a class="skip" href="#radio">Skip to this radio</a>
  <?= radios_page_chrome('header') ?>
  <main class="wrap guide" id="radio">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="./">Home</a> <span aria-hidden="true">›</span> <a href="radios.php<?= $isAntenna ? '#antennas' : '' ?>"><?= $isAntenna ? 'Antennas' : 'All radios' ?></a> <span aria-hidden="true">›</span> <span aria-current="page"><?= radios_nowrap_models(h($group['name'])) ?></span></nav>
    <div class="pagehead">
      <div><p class="eyebrow"><?= h(strtoupper($group['seller'])) ?></p><h1><?= radios_nowrap_models(h($group['name'])) ?></h1>
        <p class="lead"><?= h(radio_summary_text($group, $summary)) ?></p>
        <?php if (($group['note'] ?? '') !== ''): ?><p class="rnote"><?= h($group['note']) ?></p><?php endif; ?></div>
      <?php if ($https): ?><div class="pcontrols"><a class="primary btnlink" href="<?= h($group['url']) ?>" target="_blank" rel="noopener noreferrer">View at <?= h($group['seller']) ?><span class="sr"> (opens in a new tab)</span></a></div><?php endif; ?>
    </div>

    <section class="panel guide-step" aria-labelledby="now">
      <h2 id="now">Price and stock now</h2>
      <div class="rtable-wrap"><table class="rtable">
        <caption class="sr">Price and stock for <?= h($group['name']) ?></caption>
        <thead><tr><th scope="col"><?= count($group['offers']) > 1 ? 'Ships from' : 'Listing' ?></th><th scope="col">Price</th><th scope="col">Stock</th><th scope="col">Last checked</th></tr></thead>
        <tbody>
        <?php foreach ($group['offers'] as $offer): $status = radio_status($offer, $now); $reading = $offer['result']; $seen = strtotime((string)($reading['observed_at'] ?? '')); ?>
          <tr id="o-<?= h($offer['id']) ?>"><th scope="row" class="nw"><?= h(($offer['warehouse'] ?? '') !== '' ? (WAREHOUSES[$offer['warehouse']] ?? '') : $group['seller']) ?><?= !empty($offer['manual']) ? '<small>Checked by hand</small>' : '' ?></th>
            <td class="num"><?= is_int($reading['price'] ?? null) ? h((!empty($reading['from_price']) ? 'From ' : '') . money($reading['price'])) : 'See seller' ?></td>
            <td class="nw"><?= h(RADIO_STATUS_LABEL[$status]) ?><?= is_int($reading['qty'] ?? null) && $status === 'in_stock' ? ' · ' . (int)$reading['qty'] . ' shown' : '' ?></td>
            <td class="nw"><?= $seen ? '<time datetime="' . h(gmdate('c', $seen)) . '">' . h(gmdate('M j, H:i', $seen)) . ' UTC</time>' : 'Not checked yet' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <p class="dim"><?= h(RADIO_STATUS_NOTE[$summary['status']]) ?> Confirm stock and the selected variant with the seller before you order. Prices are in USD before tax and shipping.</p>
    </section>

    <?php if ($phoneAlerts !== ''): ?>
    <section class="panel guide-step notify" aria-labelledby="notify">
      <h2 id="notify">Get notified about this radio</h2>
      <p class="dim">A free phone alert when this radio comes back in stock or its price drops. No account, no email.</p>
      <div class="topic-line"><span class="dim">Topic</span> <code id="notify-topic"><?= h($topicName) ?></code> <button type="button" class="quiet" id="notify-copy" hidden>Copy</button><span class="copy-status" id="notify-status" role="status"></span></div>
      <div class="notify-actions">
        <a class="primary btnlink" href="ntfy://<?= h($topicHost . '/' . $topicName) ?>">Open in ntfy app</a>
        <a class="quiet btnlink" href="feed.php?ids=<?= h($feedIds) ?>">Add by RSS</a>
        <a class="quiet btnlink" href="alerts.html">How alerts work</a>
      </div>
      <ol class="notify-steps dim"><li>Install the free ntfy app (App Store, Google Play or F-Droid).</li><li>Tap Open in ntfy app, or tap + in the app and type the topic above.</li></ol>
      <p class="dim notify-note">Anyone who knows the topic can read it, so it carries only public facts: price, stock and a link. You can mute or unsubscribe in the app at any time.</p>
    </section>
    <script src="assets/notify.js?v=<?= (int)@filemtime(__DIR__ . '/assets/notify.js') ?>" defer></script>
    <?php endif; ?>

    <section class="panel guide-step" aria-labelledby="facts">
      <h2 id="facts">About this <?= ($group['category'] ?? 'radio') === 'antenna' ? 'antenna' : 'radio' ?></h2>
      <dl class="facts-list">
        <div><dt>Type</dt><dd><?= h(KINDS[$group['kind']] ?? '') ?></dd></div>
        <?php if (($group['category'] ?? 'radio') === 'antenna'): foreach (radio_antenna_facts($group) as $fact): ?>
        <div><dt><?= h($fact[0]) ?></dt><dd><?= h($fact[1]) ?></dd></div>
        <?php endforeach; else: ?>
        <div><dt>Primary use</dt><dd><?= h(ROLES[$group['role']] ?? '') ?></dd></div>
        <div><dt>Firmware</dt><dd><?= h(FIRMWARE[$group['firmware']] ?? '') ?></dd></div>
        <div><dt>Setup</dt><dd><?= h(SETUP[$group['setup']] ?? '') ?></dd></div>
        <?php endif; ?>
        <div><dt>Seller</dt><dd><?= h($group['seller']) ?><?= ($group['location'] ?? '') !== '' ? ' · ' . h($group['location']) : '' ?></dd></div>
        <?php if ($warehouses): ?><div><dt>Ships from</dt><dd><?= h(implode(', ', $warehouses)) ?></dd></div><?php endif; ?>
        <?php if (!$isAntenna && ($group['antenna_port'] ?? '') !== ''): ?><div><dt>Antenna port</dt><dd><?= h(ANTENNA_PORTS[$group['antenna_port']] ?? '') ?></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($fitting || (!$isAntenna && ($group['antenna_port'] ?? '') === 'none')): ?>
    <section class="panel guide-step" aria-labelledby="fits">
      <h2 id="fits"><?= $isAntenna ? 'Radios this antenna fits' : 'Antennas that fit' ?></h2>
      <?php if (!$fitting): ?>
        <p class="dim">This radio has no external antenna port. Its antenna is built in.</p>
      <?php else: ?>
        <p class="dim"><?php if ($isAntenna): ?>Radios with a <?= h(antenna_connector_label($group)) ?> antenna port, as stated by their sellers. Check your own radio before you order.<?php elseif (($group['antenna_port'] ?? '') === 'ufl'): ?>This radio has a U.FL (IPEX) port. Add a U.FL to SMA pigtail cable, then an SMA antenna fits.<?php else: ?>Antennas for U.S. use whose connector matches this radio's <?= h(ANTENNA_PORTS[$group['antenna_port']]) ?> port, as stated by their sellers. Check the connector before you order.<?php endif; ?></p>
        <ul class="rlist">
          <?php foreach ($fitting as $entry): ?><li><a href="radio.php?r=<?= h($entry['group']['ref']) ?>"><?= radios_nowrap_models(h($entry['group']['name'])) ?></a> · <?= h($entry['group']['seller']) ?> · <?= h(RADIO_STATUS_LABEL[$entry['summary']['status']]) ?><?= $entry['summary']['price'] !== null ? ' · ' . h(money($entry['summary']['price'])) : '' ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel guide-step" aria-labelledby="history">
      <h2 id="history">Price and stock history</h2>
      <?php if ($svg !== ''): ?>
        <figure class="rfigure"><?= $svg ?></figure>
      <?php elseif ($changes): ?>
        <p>The price has not changed since tracking began on <time datetime="<?= h(gmdate('c', end($changes)['t'])) ?>"><?= h(gmdate('M j, Y', end($changes)['t'])) ?></time>.</p>
      <?php else: ?>
        <p>History starts with the next check. Every change in price or stock is recorded from then on.</p>
      <?php endif; ?>
      <?php if ($changes): ?>
        <div class="rtable-wrap"><table class="rtable"><caption class="sr">Recorded changes for <?= h($group['name']) ?>, newest first</caption>
          <thead><tr><th scope="col">When</th><th scope="col"><?= count($group['offers']) > 1 ? 'Ships from' : 'Listing' ?></th><th scope="col">Price</th><th scope="col">Stock</th></tr></thead><tbody>
          <?php foreach ($changes as $row): ?><tr><th scope="row" class="nw"><time datetime="<?= h(gmdate('c', $row['t'])) ?>"><?= h(gmdate('M j, Y H:i', $row['t'])) ?> UTC</time></th><td class="nw"><?= h($row['label']) ?></td><td class="num"><?= $row['price'] !== null ? h(money($row['price'])) : '·' ?></td><td class="nw"><?= h(RADIO_STATUS_LABEL[$row['status']] ?? 'Unverified') ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
      <p class="dim"><a href="prices.html">Compare the price history of several radios</a> · <a href="./?q=<?= h(rawurlencode($group['name'])) ?>#inventory">Find it in the interactive catalog</a></p>
    </section>

    <?php if ($similar): ?>
    <section class="panel guide-step" aria-labelledby="similar">
      <h2 id="similar">Similar <?= ($group['category'] ?? 'radio') === 'antenna' ? 'antennas' : 'radios' ?></h2>
      <ul class="rlist">
        <?php foreach ($similar as $entry): ?><li><a href="radio.php?r=<?= h($entry['group']['ref']) ?>"><?= radios_nowrap_models(h($entry['group']['name'])) ?></a> · <?= h($entry['group']['seller']) ?> · <?= h(RADIO_STATUS_LABEL[$entry['summary']['status']]) ?><?= $entry['summary']['price'] !== null ? ' · ' . h(money($entry['summary']['price'])) : '' ?></li><?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </main>
  <?= radios_page_chrome('footer') ?>
</body>
</html>
