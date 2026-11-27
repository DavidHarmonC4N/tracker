<?php
declare(strict_types=1);

// A plain, server-rendered list of every radio with its price and stock. The interactive catalog on the home page is built
// by JavaScript; this page carries the same facts in the HTML itself, for search engines, AI tools and anyone without scripts.

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/radios.php';

common_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');

try {
    $catalog = endpoint_result();
} catch (Throwable $error) {
    error_log('Offgrid radios: ' . $error->getMessage());
    http_response_code(503);
    echo '<!doctype html><title>Temporarily unavailable</title><p>The radio list is temporarily unavailable. Try again in a minute.</p>';
    exit;
}
$all = $catalog['items'];
$items = array_values(array_filter($all, static fn($item) => ($item['category'] ?? 'radio') === 'radio'));
$antennas = array_values(array_filter($all, static fn($item) => ($item['category'] ?? '') === 'antenna'));
$now = time();
$sellers = count(array_unique(array_column($items, 'seller')));
$productCount = count(radios_units($items, $now));
$antennaCount = count(radios_units($antennas, $now));
$latest = radios_latest_reading($items);
$base = base_url();
$title = 'All MeshCore and Meshtastic Radios: Prices and Stock | Offgrid Comms Tracker';
$description = $productCount . ' MeshCore and Meshtastic radios from ' . $sellers . ' sellers with current price, stock and where each one ships from, checked about once an hour.';
$version = (string)(@filemtime(__DIR__ . '/assets/styles.css') ?: 1);
$canonical = $base !== '' ? $base . 'radios.php' : '';
$graph = ['@context'=>'https://schema.org', '@type'=>'CollectionPage', 'name'=>'All MeshCore and Meshtastic radios', 'description'=>$description, 'inLanguage'=>'en']
    + ($canonical !== '' ? ['url'=>$canonical, 'isPartOf'=>['@type'=>'WebSite', 'name'=>'Offgrid Comms Tracker', 'url'=>$base]] : [])
    + ($latest ? ['dateModified'=>gmdate('c', $latest)] : []);
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
  <a class="skip" href="#radios">Skip to the radio list</a>
  <?= radios_page_chrome('header') ?>
  <main class="wrap guide" id="radios">
    <div class="pagehead">
      <div><p class="eyebrow">OFFGRID COMMS TRACKER</p><h1>All MeshCore and Meshtastic radios</h1>
        <p class="lead"><?= h($productCount) ?> radios from <?= h($sellers) ?> sellers, with the current price and stock for each. Stock is checked about once an hour<?= $latest ? ' (latest check <time datetime="' . h(gmdate('c', $latest)) . '">' . h(gmdate('M j, H:i', $latest)) . ' UTC</time>)' : '' ?>.
          Confirm stock and the selected variant with the seller before you order. This site receives no commissions.</p>
        <p class="plain-link"><a href="./#inventory">Open the interactive catalog</a> with filters, a price range and My picks, or see <a href="prices.html">price history</a>.</p></div>
    </div>
    <?= radios_tables($items, $now) ?>
    <?php if ($antennas): ?>
    <section id="antennas" aria-labelledby="antennas-h"><h2 class="sec-head" id="antennas-h">Antennas · <?= h($antennaCount) ?></h2>
      <p class="dim">Antennas and cables that fit these radios. Check the band (915 MHz in the U.S.) and the connector before you buy. Each name links to its own page with specs and price history.</p>
      <?= radios_tables($antennas, $now, 'a', 'Antennas: ', 'antennas') ?></section>
    <?php endif; ?>
  </main>
  <?= radios_page_chrome('footer') ?>
</body>
</html>
