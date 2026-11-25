<?php
declare(strict_types=1);

// The group directory: local Meshtastic and MeshCore groups in the U.S., one page per state. Server-rendered, so it works without
// JavaScript and search engines can read it. The list was compiled by an automated search and nobody here has checked it.

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/radios.php';
require_once __DIR__ . '/includes/groups.php';

common_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');

$data = groups_load();
$all = $data['groups'];
$states = groups_states($all);
$bySlug = [];
foreach ($states as $s) {
    $bySlug[$s['slug']] = $s;
}

// What was asked for. Anything unexpected is ignored, or is a 404 for an unknown state.
$stateParam = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
$state = null;
if ($stateParam !== '') {
    $state = $bySlug[$stateParam] ?? null;
    if ($state === null) {
        http_response_code(404);
    }
}
$protocol = isset($_GET['protocol']) && is_string($_GET['protocol']) && isset(GROUP_PROTOCOLS[$_GET['protocol']]) ? $_GET['protocol'] : '';
$discord = isset($_GET['discord']) && $_GET['discord'] === '1';
$query = isset($_GET['q']) && is_string($_GET['q']) ? substr(trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $_GET['q'])), 0, 60) : '';
$filtered = $protocol !== '' || $discord || $query !== '';

$scope = groups_in($all, $state['name'] ?? '');
$shown = $filtered || $state !== null ? groups_filter($scope, $protocol, $discord, $query) : [];
usort($shown, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

$base = base_url();
$where = $state !== null ? $state['name'] : 'the U.S.';
$title = 'Meshtastic and MeshCore Groups in ' . $where . ' | Offgrid Comms Tracker';
$description = $state !== null
    ? count($scope) . ' Meshtastic and MeshCore ' . (count($scope) === 1 ? 'group' : 'groups') . ' in ' . $state['name'] . ', with links to their websites, Discord and Facebook pages. A directory, not an endorsement.'
    : count($all) . ' local Meshtastic and MeshCore groups across all 50 states and DC, with links to their websites, Discord and Facebook pages. A directory, not an endorsement.';
$canonical = $base !== '' && ($state !== null || $stateParam === '') ? $base . 'groups.php' . ($state !== null ? '?state=' . $state['slug'] : '') : '';
$noindex = $state === null && $stateParam !== '' || $filtered;
$version = (string)(@filemtime(__DIR__ . '/assets/styles.css') ?: 1);
$verifiedText = $data['verified'] !== '' ? gmdate('M j, Y', (int)strtotime($data['verified'])) : '';
$graph = ['@context'=>'https://schema.org', '@type'=>'CollectionPage', 'name'=>'Meshtastic and MeshCore groups in ' . $where, 'description'=>$description, 'inLanguage'=>'en']
    + ($canonical !== '' ? ['url'=>$canonical, 'isPartOf'=>['@type'=>'WebSite', 'name'=>'Offgrid Comms Tracker', 'url'=>$base],
        'breadcrumb'=>['@type'=>'BreadcrumbList', 'itemListElement'=>array_values(array_filter([
            ['@type'=>'ListItem', 'position'=>1, 'name'=>'Offgrid Comms Tracker', 'item'=>$base],
            ['@type'=>'ListItem', 'position'=>2, 'name'=>'Groups', 'item'=>$base . 'groups.php'],
            $state !== null ? ['@type'=>'ListItem', 'position'=>3, 'name'=>$state['name'], 'item'=>$canonical] : null]))]] : [])
    + ($data['verified'] !== '' ? ['dateModified'=>$data['verified']] : []);

/** One outside link as a button: new tab, not an endorsement. */
function group_button(array $link): string {
    return '<a class="' . ($link[2] ? 'primary' : 'quiet') . ' btnlink" href="' . h($link[1]) . '" target="_blank" rel="noopener noreferrer nofollow">' . h($link[0]) . '<span class="sr"> (opens in a new tab)</span></a>';
}

/** One group as a card. */
function group_card(array $g): string {
    $where = $g['city'] !== '' ? $g['city'] : $g['coverage'];
    $html = '<article class="gcard"><div class="gtop"><span class="gkind">' . h($g['type']) . '</span><span class="proto ' . h($g['protocol']) . '">' . h(GROUP_PROTOCOLS[$g['protocol']]) . '</span></div>'
        . '<h3>' . radios_nowrap_models(h($g['name'])) . '</h3>'
        . ($where !== '' ? '<p class="gwhere">' . h($where) . '</p>' : '')
        . ($g['city'] !== '' && $g['coverage'] !== '' && strcasecmp($g['coverage'], $g['city']) !== 0 ? '<p class="gcov">' . h($g['coverage']) . '</p>' : '')
        . ($g['review'] === 'unconfirmed' ? '<p class="gflag">Website not confirmed</p>' : '')
        . ($g['notes'] !== '' ? '<p class="gcov">' . h($g['notes']) . '</p>' : '')
        . (count($g['states']) > 1 ? '<p class="gcov">Serves ' . h(implode(', ', array_map(static fn($s) => GROUP_STATE_CODES[$s] ?? $s, $g['states']))) . '</p>' : '')
        . '<div class="glinks">';
    foreach (groups_links($g) as $link) {
        $html .= group_button($link);
    }
    if ($g['discord_members_only']) {
        $html .= '<span class="quiet btnlink off" title="This Discord link needs existing membership">Discord (members only)</span>';
    }
    return $html . '</div></article>';
}

$disclaimer = '<p class="gnotice" role="note"><strong>Please read.</strong> ' . h(GROUPS_DISCLAIMER) . '</p>';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <meta name="theme-color" content="#121511">
  <?php if ($noindex): ?><meta name="robots" content="noindex,follow">
  <?php endif; ?>
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
  <link rel="stylesheet" href="assets/styles.css?v=<?= h($version) ?>">
  <script type="application/ld+json"><?= json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
</head>
<body>
  <a class="skip" href="#groups">Skip to the groups</a>
  <?= radios_page_chrome('header') ?>
  <main class="wrap guide" id="directory">
<?php if ($stateParam !== '' && $state === null): ?>
    <div class="pagehead"><div><p class="eyebrow">OFFGRID COMMS TRACKER</p><h1>We could not find that state</h1>
      <p class="lead">There is no group directory page at that address. <a href="groups.php">See the map of every state</a>.</p></div></div>
<?php else: ?>
    <nav class="crumbs" aria-label="Breadcrumb"><a href="./">Home</a> <span aria-hidden="true">›</span><?php if ($state !== null): ?> <a href="groups.php">Groups</a> <span aria-hidden="true">›</span> <span aria-current="page"><?= h($state['name']) ?></span><?php else: ?> <span aria-current="page">Groups</span><?php endif; ?></nav>
    <div class="pagehead"><div><p class="eyebrow">OFFGRID COMMS TRACKER</p>
      <h1>Meshtastic and MeshCore groups in <?= h($where) ?></h1>
      <p class="lead"><?= $state !== null ? h((string)count($scope)) . ' ' . (count($scope) === 1 ? 'group' : 'groups') . ' in ' . h($state['name']) : h((string)count($all)) . ' groups across all 50 states and DC' ?>. Find the people running a mesh near you, then join their chat.<?= $verifiedText !== '' ? ' List compiled ' . h($verifiedText) . '.' : '' ?></p></div></div>
    <?= $disclaimer ?>

    <section class="panel guide-step" aria-labelledby="find">
      <h2 id="find">Find a group</h2>
      <form class="gfilters" method="get" action="groups.php">
        <label class="field"><span>State</span><select name="state">
          <option value="">All states</option>
          <?php foreach ($states as $s): ?><option value="<?= h($s['slug']) ?>"<?= $state !== null && $state['slug'] === $s['slug'] ? ' selected' : '' ?>><?= h($s['name']) ?> (<?= h((string)$s['count']) ?>)</option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>Search</span><input type="search" name="q" value="<?= h($query) ?>" maxlength="60" placeholder="Group, city or county" autocomplete="off"></label>
        <label class="field"><span>Protocol</span><select name="protocol">
          <option value="">Any</option>
          <?php foreach (GROUP_PROTOCOLS as $key => $label): if ($key === 'both') { continue; } ?><option value="<?= h($key) ?>"<?= $protocol === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
        </select></label>
        <label class="inline gcheck"><input type="checkbox" name="discord" value="1"<?= $discord ? ' checked' : '' ?>> Has a Discord to join</label>
        <button class="primary" type="submit">Show groups</button>
        <?php if ($filtered || $state !== null): ?><a class="quiet btnlink" href="groups.php">Clear</a><?php endif; ?>
      </form>
    </section>

    <section class="panel guide-step" aria-labelledby="mapped">
      <h2 id="mapped">Map of groups by state</h2>
      <p class="dim">Each tile is a state, and the number is how many groups are listed there. Select a tile to see them. Darker means more.</p>
      <?= groups_map_svg($states, $state['slug'] ?? '') ?>
      <ul class="gstates" aria-label="States and regions">
        <?php foreach ($states as $s): ?><li><a href="groups.php?state=<?= h($s['slug']) ?>#groups"<?= $state !== null && $state['slug'] === $s['slug'] ? ' aria-current="page"' : '' ?>><?= h($s['name']) ?></a> <span class="dim"><?= h((string)$s['count']) ?></span></li><?php endforeach; ?>
      </ul>
    </section>

    <section id="groups" aria-labelledby="results">
<?php if ($state !== null || $filtered): ?>
      <h2 id="results"><?= $state !== null ? h($state['name']) : 'Search results' ?> · <?= h((string)count($shown)) ?> <?= count($shown) === 1 ? 'group' : 'groups' ?></h2>
<?php if (!$shown): ?>
      <p class="panel">No groups match. <a href="groups.php<?= $state !== null ? '?state=' . h($state['slug']) : '' ?>">Clear the filters</a> or choose another state.</p>
<?php elseif ($state === null): ?>
<?php foreach ($states as $s): $inState = array_values(array_filter($shown, static fn($g) => in_array($s['name'], $g['states'], true))); if (!$inState) { continue; } ?>
      <h3 class="gstate"><a href="groups.php?state=<?= h($s['slug']) ?>"><?= h($s['name']) ?></a> <span class="dim"><?= h((string)count($inState)) ?></span></h3>
      <div class="ggrid"><?php foreach ($inState as $g) { echo group_card($g); } ?></div>
<?php endforeach; ?>
<?php else: ?>
      <div class="ggrid"><?php foreach ($shown as $g) { echo group_card($g); } ?></div>
<?php endif; ?>
<?php else: ?>
      <h2 id="results">Choose a state</h2>
      <p class="dim">Select a state on the map or from the list above to see its groups, or use the search to look across all of them.</p>
<?php endif; ?>
    </section>

    <?= $disclaimer ?>
    <p class="dim">Do you know a group that is missing, or see a link that no longer works? A way to send corrections is coming soon.</p>
<?php endif; ?>
  </main>
  <?= radios_page_chrome('footer') ?>
</body>
</html>
