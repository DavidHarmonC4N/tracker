<?php
declare(strict_types=1);

// The Groups part of the admin panel (admin.php?view=groups). Included by admin.php after sign-in, so $state, $token, $notice and
// $failure are already set. The list in includes/groups.json is the starting point; what is changed here is kept in tracker.json.

if (!defined('OFFGRID_APP') || empty($auth)) {
    http_response_code(404);
    exit;
}

$store = $state['groups'] ?? [];
$base = groups_load()['groups'];
$everyGroup = groups_apply($base, $store, true);
$byId = array_column($everyGroup, null, 'id');
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && field($_POST, 'action') === 'group_save';
$editingId = $posted ? field($_POST, 'id') : field($_GET, 'edit');
$adding = $posted ? $editingId === '' : isset($_GET['add']);
$current = $editingId !== '' ? ($byId[$editingId] ?? null) : null;
$showForm = $adding || $current !== null;
// What the form shows: the values just sent (after a refused save), else the group, else a blank one.
$values = $posted ? $_POST + ['states'=>[]] : ($current ?? ['protocol'=>'meshtastic', 'type'=>'Mesh community', 'review'=>'directory', 'states'=>[]]);
$checked = is_array($values['states'] ?? null) ? $values['states'] : [];

$counts = ['all'=>count($everyGroup), 'hidden'=>0, 'edited'=>0, 'added'=>0, 'unconfirmed'=>0];
foreach ($everyGroup as $g) {
    $counts['hidden'] += $g['hidden'] ? 1 : 0;
    $counts['edited'] += $g['edited'] ? 1 : 0;
    $counts['added'] += $g['origin'] === 'added' ? 1 : 0;
    $counts['unconfirmed'] += $g['review'] === 'unconfirmed' ? 1 : 0;
}
$stateCodes = GROUP_STATE_CODES + ['Puerto Rico'=>'PR', 'U.S. regional / national'=>'US'];
?>
  <div class="panel" aria-labelledby="groups-heading">
    <div class="section-line"><div><h2 id="groups-heading">Group directory</h2>
      <p class="dim"><?= h((string)($counts['all'] - $counts['hidden'])) ?> groups shown on <a href="groups.php">the public directory</a><?= $counts['hidden'] ? ' · ' . h((string)$counts['hidden']) . ' hidden' : '' ?><?= $counts['added'] ? ' · ' . h((string)$counts['added']) . ' added here' : '' ?><?= $counts['edited'] ? ' · ' . h((string)$counts['edited']) . ' edited' : '' ?>. The list came from an automated search and has not been checked by hand. Hide a group to take it off the page, and edit one to fix a link.</p></div>
      <div class="actions"><?php if ($showForm): ?><a class="quiet" href="?view=groups">Back to the list</a><?php else: ?><a class="primary" href="?view=groups&amp;add=1">Add group</a><?php endif; ?></div></div>
  </div>

<?php if ($showForm): ?>
  <form class="panel" method="post" id="group-form">
    <h2><?= $current !== null ? 'Edit ' . h($current['name']) : 'Add a group' ?></h2>
    <input type="hidden" name="token" value="<?= h($token) ?>"><input type="hidden" name="id" value="<?= h($current['id'] ?? '') ?>">
    <div class="form-grid">
      <?php input_box('name', 'Group name', $values, 'text', 'required maxlength="80"');
      input_box('city', 'City or metro (optional)', $values, 'text', 'maxlength="60"');
      select_box('protocol', 'Protocol', GROUP_PROTOCOLS, $values);
      select_box('type', 'Type', GROUP_TYPES, $values);
      input_box('url', 'Main link (https)', $values, 'url', 'required maxlength="500"');
      input_box('discord', 'Discord link', $values, 'url', 'maxlength="500"');
      input_box('facebook', 'Facebook link', $values, 'url', 'maxlength="500"');
      input_box('other', 'Other link (Reddit, Groups.io, chat)', $values, 'url', 'maxlength="500"');
      input_box('map', 'Map or traffic link', $values, 'url', 'maxlength="500"');
      input_box('coverage', 'Coverage area', $values, 'text', 'maxlength="110"');
      select_box('review', 'Website check', GROUP_REVIEWS, $values); ?>
    </div>
    <label class="inline"><input type="checkbox" name="discord_members_only" <?= !empty($values['discord_members_only']) ? 'checked' : '' ?>> The Discord link needs existing membership (shown as "members only", not a join button)</label>
    <label>Notes shown on the card (optional)<textarea name="notes" maxlength="160" rows="2"><?= h(field($values, 'notes')) ?></textarea></label>
    <fieldset class="states-grid"><legend>States and regions it serves</legend>
      <?php foreach (GROUP_REGIONS as $region): ?><label class="inline"><input type="checkbox" name="states[]" value="<?= h($region) ?>" <?= in_array($region, $checked, true) ? 'checked' : '' ?>> <?= h($region) ?></label><?php endforeach; ?>
    </fieldset>
    <p class="dim">Links must be full https:// addresses. Every link on the public page opens in a new tab and carries the caution that these groups are not checked or endorsed.</p>
    <div class="actions"><button class="primary" name="action" value="group_save">Save</button><a class="quiet" href="?view=groups">Cancel</a></div>
  </form>
<?php if ($current !== null): ?>
  <div class="panel editor-actions">
    <div><h2><?= $current['hidden'] ? 'Hidden from the public directory' : 'Shown on the public directory' ?></h2>
      <p><?= $current['origin'] === 'added' ? 'Added here.' : 'From the research list.' ?><?= $current['edited'] ? ' Edited here.' : '' ?></p></div>
    <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><input type="hidden" name="id" value="<?= h($current['id']) ?>">
      <?php if ($current['hidden']): ?><button class="quiet" name="action" value="group_show">Show it again</button>
      <?php else: ?><button class="quiet" name="action" value="group_hide">Hide from the directory</button><?php endif; ?>
      <?php if ($current['edited']): ?><button class="quiet" name="action" value="group_restore" data-confirm="Undo your edits and go back to the original listing?">Restore original</button><?php endif; ?>
      <?php if ($current['origin'] === 'added'): ?><button class="quiet remove" name="action" value="group_delete" data-confirm="Delete this group? This cannot be undone.">Delete group</button><?php endif; ?>
    </form>
  </div>
<?php endif; ?>
<?php else: ?>
  <div class="panel">
    <label class="admin-search">Search groups<input type="search" id="admin-search" placeholder="Name, state, or status" autocomplete="off"></label>
    <div class="admin-filters" role="group" aria-label="Filter groups">
      <span class="qlabel" id="admin-kind-label">Protocol</span>
      <?php foreach (['' => 'All', 'meshtastic' => 'Meshtastic', 'meshcore' => 'MeshCore', 'both' => 'Both'] as $key => $label): ?><button type="button" class="chip" data-filter="category" data-value="<?= h($key) ?>" aria-pressed="<?= $key === '' ? 'true' : 'false' ?>" aria-describedby="admin-kind-label"><?= h($label) ?> <b class="n"></b></button><?php endforeach; ?>
      <span class="sep" aria-hidden="true"></span>
      <span class="qlabel" id="admin-status-label">Show</span>
      <?php foreach (['' => 'Any', 'visible' => 'Shown', 'hidden' => 'Hidden', 'edited' => 'Edited', 'added' => 'Added here', 'unconfirmed' => 'Website not confirmed'] as $key => $label): ?><button type="button" class="chip" data-filter="group" data-value="<?= h($key) ?>" aria-pressed="<?= $key === '' ? 'true' : 'false' ?>" aria-describedby="admin-status-label"><?= h($label) ?> <b class="n"></b></button><?php endforeach; ?>
    </div>
    <p class="dim" id="admin-count" role="status" aria-live="polite"></p>
    <div class="admin-list" id="admin-list">
      <?php usort($everyGroup, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
      foreach ($everyGroup as $g):
        $group = $g['hidden'] ? 'hidden' : ($g['origin'] === 'added' ? 'added' : ($g['edited'] ? 'edited' : ($g['review'] === 'unconfirmed' ? 'unconfirmed' : 'visible')));
        $tag = $g['hidden'] ? 'Hidden' : ($g['origin'] === 'added' ? 'Added' : ($g['edited'] ? 'Edited' : ($g['review'] === 'unconfirmed' ? 'Not confirmed' : 'Shown')));
        $codes = implode(', ', array_map(static fn($s) => $stateCodes[$s] ?? $s, $g['states'])); ?>
      <div class="admin-row" data-category="<?= h($g['protocol']) ?>" data-group="<?= h($group) ?>" data-search="<?= h(strtolower($g['name'] . ' ' . $g['city'] . ' ' . implode(' ', $g['states']) . ' ' . $tag . ' ' . $g['protocol'])) ?>">
        <div><strong><?= h($g['name']) ?></strong><p class="dim"><?= h($codes) ?> · <?= h(GROUP_PROTOCOLS[$g['protocol']]) ?> · <?= h($g['type']) ?></p><p class="dim"><?= h((string)parse_url($g['url'], PHP_URL_HOST)) ?></p></div>
        <span class="tag <?= $g['hidden'] || $g['review'] === 'unconfirmed' ? 'unknown' : 'in_stock' ?>"><?= h($tag) ?></span>
        <a class="quiet" href="?view=groups&amp;edit=<?= h(rawurlencode($g['id'])) ?>">Edit</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
