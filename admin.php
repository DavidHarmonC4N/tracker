<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/scanner.php';
common_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

function go(string $query = ''): never {
    header('Location: admin.php' . $query, true, 303);
    exit;
}

function save_item(array $post, ?array $existing): array {
    $row = listing_fields($post, $existing['role'] ?? 'unspecified') + ['id'=>$existing['id'] ?? bin2hex(random_bytes(12))];
    foreach (['setup'=>SETUP,'mode'=>MODES] as $name=>$options) {
        $row[$name] = field($post,$name);
        if (!isset($options[$row[$name]])) {
            throw new InvalidArgumentException('Pick an option for ' . $name . '.');
        }
    }
    foreach (['variant'=>180,'scope'=>500,'ready'=>120,'sold'=>120,'price_path'=>500] as $name=>$limit) {
        $row[$name] = field($post, $name);
        if (strlen($row[$name]) > $limit) {
            throw new InvalidArgumentException('Check the length of ' . str_replace('_',' ',$name) . '.');
        }
    }
    $row['checker'] = field($post,'checker',$existing['checker'] ?? 'server');
    if (!isset(CHECKERS[$row['checker']])) {
        throw new InvalidArgumentException('Pick an option for checker.');
    }
    $row['paused'] = isset($post['paused']);
    $clearResult = !$existing;
    foreach (['url','variant','scope','ready','sold','price_path'] as $field) {
        $clearResult = $clearResult || $existing[$field] !== $row[$field];
    }
    $row['result'] = $clearResult ? ['status'=>'unknown','price'=>null,'qty'=>null,'from_price'=>false,
        'observed_at'=>null,'source'=>'new','detail'=>'Waiting for a check'] : $existing['result'];
    $row['attempted_at'] = $clearResult ? null : $existing['attempted_at'];
    // A manual-review listing is never scanned, so an old scan error would stay on it forever.
    $row['error'] = $clearResult || $row['mode'] === 'manual' ? null : $existing['error'];
    if (isset($post['record'])) {
        $status = field($post,'status');
        $quantity = field($post,'qty');
        if (!isset(STATUSES[$status]) || ($quantity !== '' && !preg_match('/^\d{1,6}$/D',$quantity))) {
            throw new InvalidArgumentException('Choose an availability status and enter a whole stock count.');
        }
        if ($quantity !== '' && $status !== 'in_stock') {
            throw new InvalidArgumentException('A positive quantity requires In stock status.');
        }
        if ($quantity !== '' && (int)$quantity < 1) {
            throw new InvalidArgumentException('Use Out of stock status for zero units.');
        }
        $row['result'] = ['status'=>$status,'price'=>cents(field($post,'price')),
            'qty'=>$quantity === '' ? null : (int)$quantity,'from_price'=>isset($post['from_price']),
            'observed_at'=>iso_now(),'source'=>'manual','detail'=>'Checked by the site administrator'];
        $row['error'] = null;
    }
    return $row;
}

$state = ['password'=>null,'items'=>[],'last_run'=>null,'submissions'=>[]];
$diagnosis = null;
$blocking = $notice = $failure = '';
$editor = null;
$submission = null;
$reviewId = '';
$auth = false;
$setup = false;
$token = '';
try {
    $isLocal = config()['local_test'] === true && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (!$secure && !$isLocal) {
        throw new RuntimeException('Open this admin page over HTTPS.');
    }
    $dir = data_dir();
    $state = load_state($dir);
    $setup = !$state['password'];
    if ($setup && strlen((string)config()['setup_key']) < 32) {
        throw new RuntimeException('Set a unique setup_key of at least 32 characters in config.php first.');
    }
    session_name('OffgridAdmin_' . substr(hash('sha256',__DIR__),0,12));
    session_start(['use_strict_mode'=>true,'cookie_secure'=>!$isLocal,'cookie_httponly'=>true,'cookie_samesite'=>'Strict',
        'cookie_path'=>dirname($_SERVER['SCRIPT_NAME'] ?? '/admin.php') . (dirname($_SERVER['SCRIPT_NAME'] ?? '/admin.php') === '/' ? '' : '/')]);
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
    $token = $_SESSION['token'] ??= bin2hex(random_bytes(32));
    $auth = !$setup && !empty($_SESSION['is_admin']);
    $notice = $_SESSION['notice'] ?? '';
    unset($_SESSION['notice']);
    $editId = field($_GET,'edit');
    $reviewId = field($_GET,'review');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($token, field($_POST,'token'))) {
            throw new InvalidArgumentException('This form expired. Reload the page before submitting.');
        }
        $action = field($_POST,'action');
        if (!$auth) {
            if ($action !== ($setup ? 'setup' : 'login')) {
                throw new InvalidArgumentException('Sign in to manage listings.');
            }
            $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $accepted = change_state($dir, static function (&$fresh) use ($ip, $action, $password) {
                $now = time();
                $fresh['attempts'] = array_filter($fresh['attempts'], static fn($hit) => $hit['since'] > $now - 900);
                $key = isset($fresh['attempts'][$ip]) || count($fresh['attempts']) < 500 ? $ip : 'overflow';
                $hit = $fresh['attempts'][$key] ?? ['since'=>$now,'count'=>0];
                if ($hit['count'] >= 8) {
                    return false;
                }
                $fresh['attempts'][$key] = ['since'=>$hit['since'],'count'=>$hit['count']+1];
                if ($action === 'setup' && !$fresh['password']
                    && strlen($password) >= 12 && strlen($password) <= 72 && !str_contains($password, chr(0))
                    && hash_equals(config()['setup_key'], field($_POST,'setup_key'))) {
                    $fresh['password'] = password_hash($password, PASSWORD_DEFAULT);
                } elseif ($action !== 'login' || !$fresh['password'] || strlen($password) > 72
                    || !password_verify($password, $fresh['password'])) {
                    return false;
                }
                unset($fresh['attempts'][$key]);
                return true;
            });
            if (!$accepted) {
                throw new InvalidArgumentException('Access denied. Check your credentials, or try again in 15 minutes.');
            }
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['token'] = bin2hex(random_bytes(32));
            $_SESSION['notice'] = $setup ? 'Admin access is set up.' : 'Signed in.';
            go();
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            go();
        }
        $id = field($_POST,'id');
        $editId = $id;
        if ($action === 'queue_failing') {
            $queued = change_state($dir, static function (&$fresh) {
                $count = 0;
                foreach ($fresh['items'] as &$row) {
                    if (!$row['paused'] && $row['mode'] !== 'manual' && !empty($row['error'])) {
                        $row['attempted_at'] = null;
                        $count++;
                    }
                }
                unset($row);
                return $count;
            });
            $_SESSION['notice'] = $queued ? $queued . ' listing(s) with problems queued for the next scheduled runs.'
                : 'No listings with problems to queue.';
            go();
        }
        if ($action === 'clear_pauses') {
            change_state($dir, static function (&$fresh) {
                $fresh['cooldowns'] = [];
                $fresh['host_next_scan'] = [];
                foreach ($fresh['items'] as &$row) {
                    if (!$row['paused'] && $row['mode'] !== 'manual') {
                        $row['attempted_at'] = null;
                    }
                }
                unset($row);
            });
            $_SESSION['notice'] = 'Seller pauses cleared. Every automatic listing is queued for the next scheduled runs.';
            go();
        }
        if ($action === 'route_failing') {
            if (!ingest_enabled()) {
                throw new InvalidArgumentException('Set ingest_token in config.php first.');
            }
            $moved = change_state($dir, static function (&$fresh) {
                $count = 0;
                $now = time();
                foreach ($fresh['items'] as &$row) {
                    if ($row['paused'] || $row['mode'] === 'manual' || is_remote($row)) {
                        continue;
                    }
                    try {
                        $host = seller_bucket(product_url($row['url'])['host']);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    if (!empty($row['error']) || ($fresh['cooldowns'][$host] ?? 0) > $now) {
                        $row['checker'] = 'remote';
                        $row['attempted_at'] = null;
                        unset($fresh['cooldowns'][$host], $fresh['host_next_scan'][$host]);
                        $count++;
                    }
                }
                unset($row);
                return $count;
            });
            $_SESSION['notice'] = $moved ? $moved . ' listing(s) moved to the U.S. runner.' : 'No failing listings to move.';
            go();
        }
        if ($action === 'diagnose') {
            $diagnosis = run_diagnostics($state);
        } else {
        if ($action === 'check_now') {
            $_SESSION['notice'] = check_now($dir, $id);
            go('?edit=' . rawurlencode($id));
        }
        if (in_array($action,['approve','reject'],true)) {
            $reviewId = $id;
            change_state($dir, static function (&$fresh) use ($action,$id) {
                if (!isset($fresh['submissions'][$id])) {
                    throw new InvalidArgumentException('This submission has already been reviewed.');
                }
                if ($action === 'approve') {
                    if (count($fresh['items']) >= 250) {
                        throw new InvalidArgumentException('This installation supports up to 250 listings.');
                    }
                    $item = save_item($_POST,null);
                    if ($item['paused']) {
                        throw new InvalidArgumentException('Uncheck Hide listing before approving.');
                    }
                    if (has_listing_url($fresh['items'],$item['url'])) {
                        throw new InvalidArgumentException('This product link is already in the catalog.');
                    }
                    $fresh['items'][] = $item;
                }
                unset($fresh['submissions'][$id]);
            });
            $_SESSION['notice'] = $action === 'approve' ? 'Listing approved and published.' : 'Submission declined.';
            go('#submissions');
        }
        if (!in_array($action,['save','delete','queue'],true)) {
            throw new InvalidArgumentException('Unknown action.');
        }
        $events = [];
        $eventUrl = '';
        change_state($dir, static function (&$fresh) use ($action,$id,&$events,&$eventUrl) {
            foreach ($fresh['items'] as $index=>$row) {
                if ($row['id'] !== $id) {
                    continue;
                }
                if ($action === 'delete') {
                    array_splice($fresh['items'],$index,1);
                } elseif ($action === 'queue') {
                    if ($row['paused'] || $row['mode'] === 'manual') {
                        throw new InvalidArgumentException('Enable automatic checks for this listing first.');
                    }
                    $fresh['items'][$index]['attempted_at'] = null;
                } else {
                    $saved = save_item($_POST,$row);
                    if (isset($_POST['record'])) {
                        // A manual reading is compared with the previous reading, like an automatic scan.
                        $events = detect_events(['result'=>$row['result']] + $saved, $saved['result']);
                        record_events($fresh, $events);
                        $eventUrl = $saved['url'];
                    }
                    $fresh['items'][$index] = $saved;
                }
                return;
            }
            if ($action !== 'save' || $id !== '') {
                throw new InvalidArgumentException('This listing no longer exists.');
            }
            if (count($fresh['items']) >= 250) {
                throw new InvalidArgumentException('This installation supports up to 250 listings.');
            }
            $fresh['items'][] = save_item($_POST,null);
        });
        announce_events($events, $eventUrl);
        $_SESSION['notice'] = match ($action) {
            'delete'=>'Listing removed.', 'queue'=>'Listing queued for the next scheduled run.', default=>'Listing saved.',
        };
        go($action === 'queue' ? '?edit=' . rawurlencode($id) : '');
        }
    }
} catch (InvalidArgumentException $error) {
    $failure = $error->getMessage();
} catch (Throwable $error) {
    error_log('Offgrid admin: ' . $error->getMessage());
    $blocking = $error instanceof RuntimeException ? $error->getMessage() : 'Admin is temporarily unavailable.';
}

if ($auth && !$blocking) {
    $submission = $state['submissions'][$reviewId] ?? null;
    if ($reviewId !== '' && !$submission && $failure === '') {
        $failure = 'This submission has already been reviewed.';
    }
    foreach ($state['items'] as $item) {
        if ($item['id'] === ($editId ?? '')) {
            $editor = $item;
            break;
        }
    }
}
$postEditor = $auth && in_array(field($_POST,'action'),['save','approve'],true);
$showEditor = $auth && ($editor || $submission || ($postEditor && $reviewId === '') || isset($_GET['add']));
$values = $postEditor ? $_POST : ($editor ?? (($submission ?? ['firmware'=>'both','kind'=>'handheld','role'=>'unspecified'])
    + ['mode'=>'auto','setup'=>'unknown']));
$reading = $editor['result'] ?? [];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $auth && !empty($state['submissions']) ? '(' . h(count($state['submissions'])) . ') ' : '' ?>Admin · Offgrid Comms Tracker</title>
<link rel="stylesheet" href="assets/styles.css">
<script src="assets/admin.js?v=2" defer></script>
</head>
<body>
<header class="mast"><div class="wrap mast-inner">
<a class="brand" href="index.html"><span class="brand-icon" aria-hidden="true"><i></i></span> OFFGRID <span>COMMS</span></a>
<a class="quiet" href="index.html">Public catalog</a>
</div></header>
<main class="wrap admin-area">
<div class="admin-heading">
  <div><p class="eyebrow">OFFGRID COMMS TRACKER</p><h1>Catalog admin</h1></div>
  <?php if ($auth): ?>
  <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button name="action" value="logout" class="quiet">Sign out</button></form>
  <?php endif; ?>
</div>
<?php if ($notice): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
<?php if ($failure): ?><p class="notice danger" role="alert"><?= h($failure) ?></p><?php endif; ?>
<?php if ($blocking): ?>
  <div class="panel"><h2>Admin setup</h2><p><?= h($blocking) ?></p><p>Follow the installation steps in the download’s README.</p></div>
<?php elseif (!$auth): ?>
  <form class="panel narrow" method="post">
    <h2><?= $setup ? 'Create administrator password' : 'Sign in' ?></h2>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <?php if ($setup): ?>
    <label>Private setup key<input name="setup_key" type="password" autocomplete="off" required></label>
    <?php endif; ?>
    <label><?= $setup ? 'New password (12–72 bytes)' : 'Password' ?><input name="password" type="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" <?= $setup ? 'minlength="12" maxlength="72"' : '' ?> required></label>
    <button class="primary" name="action" value="<?= $setup ? 'setup' : 'login' ?>"><?= $setup ? 'Create password' : 'Sign in' ?></button>
  </form>
<?php elseif ($showEditor): ?>
  <form class="panel editor" method="post">
    <div class="section-line"><h2><?= $submission ? 'Review submission' : ($editor ? 'Edit listing' : 'Add listing') ?></h2><a class="quiet" href="admin.php<?= $submission ? '#submissions' : '' ?>">Back to admin</a></div>
    <?php if ($submission): ?>
      <p class="dim">Check the seller, product, and variant before publishing. <a class="inline-link" href="<?= h($submission['url']) ?>" rel="noreferrer noopener" target="_blank">Open submitted product link (new tab)</a></p>
    <?php endif; ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <input type="hidden" name="id" value="<?= h($submission['id'] ?? $editor['id'] ?? '') ?>">
    <div class="form-grid">
      <?php input_box('name','Radio name',$values,'text','required maxlength="120"');
      input_box('seller','Seller',$values,'text','required maxlength="80"');
      input_box('location','Seller location',$values,'text','maxlength="70"');
      input_box('url','HTTPS product link',$values,'url','required maxlength="1500"');
      select_box('firmware','Firmware support',FIRMWARE,$values);
      select_box('kind','Radio type',KINDS,$values);
      select_box('role','Primary use',ROLES,$values);
      select_box('setup','Setup needed',SETUP,$values);
      select_box('mode','Check method',MODES,$values);
      if (ingest_enabled()) { select_box('checker','Checked by',CHECKERS,$values); } ?>
    </div>
    <label>Public notes<textarea name="note" maxlength="500" rows="2"><?= h(field($values,'note')) ?></textarea></label>
    <?php if (!$submission): ?><label class="inline"><input type="checkbox" name="paused" <?= !empty($values['paused']) ? 'checked' : '' ?>> Hide listing and pause checks</label><?php endif; ?>
    <details <?= field($values,'scope') ? 'open' : '' ?>>
      <summary>Variant and page rules</summary>
      <p>For Shopify, paste a link with <code>?variant=</code> or enter exact option values separated by <code>|</code>, such as <code>MeshCore | Antenna</code>. For custom page rules, select Product page as the check method.</p>
      <div class="form-grid">
        <?php input_box('variant','Exact variant options',$values,'text','maxlength="180"');
        input_box('scope','Stock block XPath',$values,'text','maxlength="500"');
        input_box('ready','In-stock phrase',$values,'text','maxlength="120"');
        input_box('sold','Out-of-stock phrase',$values,'text','maxlength="120"');
        input_box('price_path','Price XPath within stock block',$values,'text','maxlength="500"'); ?>
      </div>
    </details>
    <details <?= isset($_POST['record']) ? 'open' : '' ?>>
      <summary>Record a manual price or stock reading</summary>
      <p>Use this when the seller blocks automated checks or stocks a variant the page cannot reliably identify.</p>
      <label class="inline"><input type="checkbox" name="record" <?= isset($_POST['record']) ? 'checked' : '' ?>> Save the manual reading below</label>
      <div class="form-grid">
        <?php
        $manual = $postEditor ? $_POST : ['status'=>$reading['status'] ?? 'unknown',
            'price'=>isset($reading['price']) ? number_format($reading['price']/100,2,'.','') : '',
            'qty'=>isset($reading['qty']) ? (string)$reading['qty'] : ''];
        select_box('status','Stock',STATUSES,$manual);
        input_box('price','USD price',$manual,'text','inputmode="decimal" placeholder="79.95"');
        input_box('qty','Quantity, if known',$manual,'number','min="1" max="999999"');
        ?>
      </div>
      <label class="inline"><input type="checkbox" name="from_price" <?= ($postEditor ? isset($_POST['from_price']) : !empty($reading['from_price'])) ? 'checked' : '' ?>> Show this as a starting price</label>
    </details>
    <button class="primary" name="action" value="<?= $submission ? 'approve' : 'save' ?>"><?= $submission ? 'Approve and publish' : 'Save listing' ?></button>
  </form>
  <?php if ($submission): ?>
  <div class="panel editor-actions">
    <div><h2>Decline submission</h2><p>Remove it from the review queue.</p></div>
    <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><input type="hidden" name="id" value="<?= h($submission['id']) ?>">
      <button class="quiet remove" name="action" value="reject" data-confirm="Decline this submitted listing?">Decline</button>
    </form>
  </div>
  <?php endif; ?>
  <?php if ($editor): ?>
  <div class="panel editor-actions">
    <div><h2>Check activity</h2><p><?= h($editor['error'] ?? ($editor['attempted_at'] ? 'Last attempted ' . when($editor['attempted_at']) : 'Waiting for the first automated check.')) ?></p></div>
    <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><input type="hidden" name="id" value="<?= h($editor['id']) ?>">
      <button class="quiet" name="action" value="check_now">Check now</button>
      <button class="quiet" name="action" value="queue">Queue check</button>
      <button class="quiet remove" name="action" value="delete" data-confirm="Delete this listing from the public catalog?">Delete listing</button>
    </form>
  </div>
  <?php endif; ?>
<?php else: ?>
  <section class="panel review-queue" id="submissions" aria-labelledby="submissions-heading">
    <div class="section-line"><div><h2 id="submissions-heading">Pending submissions</h2><p class="dim"><?= h(count($state['submissions'])) ?> awaiting review</p></div></div>
    <?php if ($state['submissions']): ?><div class="admin-list">
      <?php foreach ($state['submissions'] as $proposal): ?>
      <div class="admin-row"><div><strong><?= h($proposal['name']) ?></strong><p class="dim"><?= h($proposal['seller']) ?> · <?= h(ROLES[$proposal['role']]) ?></p></div>
        <span class="tag"><?= h(FIRMWARE[$proposal['firmware']]) ?></span>
        <a class="quiet" href="?review=<?= h($proposal['id']) ?>">Review</a>
      </div>
      <?php endforeach; ?>
    </div><?php endif; ?>
  </section>
  <?php
  $nowTs = time();
  $rows = [];
  $failing = 0;
  $pausedSellers = 0;
  $remote = 0;
  foreach ($state['items'] as $item) {
      $host = '';
      try { $host = seller_bucket(product_url($item['url'])['host']); } catch (Throwable) {}
      $auto = !$item['paused'] && $item['mode'] !== 'manual';
      $sellerPaused = $auto && ($state['cooldowns'][$host] ?? 0) > $nowTs;
      if ($item['paused']) {
          $rank = 4; $tag = 'Hidden'; $detail = 'Hidden from the public catalog';
      } elseif ($item['error']) {
          $rank = 0; $tag = 'Needs review'; $detail = $item['error']; $failing++;
      } elseif ($sellerPaused) {
          $rank = 1; $tag = STATUSES[$item['result']['status']]; $pausedSellers++;
          $detail = 'Seller paused automated checks until ' . when(gmdate(DATE_ATOM, $state['cooldowns'][$host]));
      } elseif ($auto && !$item['attempted_at'] && empty($item['result']['observed_at'])) {
          $rank = 2; $tag = STATUSES[$item['result']['status']]; $detail = 'Awaiting first check';
      } else {
          $rank = 3; $tag = STATUSES[$item['result']['status']];
          $seen = when($item['result']['observed_at'] ?? null);
          $detail = $item['mode'] === 'manual' ? 'Manual watch' . ($seen ? ' · recorded ' . $seen : '')
              : ($seen ? 'Checked ' . $seen : 'No reading yet');
      }
      if (is_remote($item) && !$item['paused']) { $detail .= ' · U.S. runner'; $remote++; }
      $rows[] = ['item'=>$item,'rank'=>$rank,'tag'=>$tag,'detail'=>$detail];
  }
  usort($rows, static fn($a, $b) => $a['rank'] <=> $b['rank']);
  $runAge = $state['last_run'] ? (int)floor(($nowTs - strtotime($state['last_run'])) / 60) : null;
  ?>
  <?php $runnerAge = $state['last_runner_run'] ? (int)floor(($nowTs - strtotime($state['last_runner_run'])) / 60) : null; ?>
  <?php if ($remote && ($runnerAge === null || $runnerAge > 45)): ?>
    <p class="notice danger" role="alert"><?= $runnerAge === null ? 'The U.S. runner has not checked in yet. Finish the GitHub setup steps in the README.' : 'The U.S. runner last checked in ' . h($runnerAge >= 120 ? intdiv($runnerAge, 60) . ' hours' : $runnerAge . ' minutes') . ' ago. Check the Actions tab on GitHub.' ?></p>
  <?php endif; ?>
  <?php if ($runAge === null): ?>
    <p class="notice danger" role="alert">The scheduled checker has not run yet. Add the cron job from the README, then run <code>check.php</code> once to confirm it works.</p>
  <?php elseif ($runAge > 30): ?>
    <p class="notice danger" role="alert">The scheduled checker last ran <?= h($runAge >= 120 ? intdiv($runAge, 60) . ' hours' : $runAge . ' minutes') ?> ago (<?= h(when($state['last_run'])) ?>). It should run every 10 minutes, so check the cron job.</p>
  <?php endif; ?>
  <?php if ($diagnosis): ?>
  <section class="panel" aria-labelledby="diag-heading">
    <h2 id="diag-heading">Connection test</h2>
    <p class="dim">The first row is a neutral control site. If it works but sellers do not, the sellers are refusing this server.</p>
    <ul class="event-list">
      <?php foreach ($diagnosis as $row): ?><li><strong><?= h($row['url'] ?: 'Setup') ?></strong><br>
        HTTP <?= h($row['http'] ?: 'no response') ?> · <?= h($row['ms']) ?> ms · DNS: <?= h($row['dns'] ?: 'n/a') ?>
        <?= $row['curl'] ? '<br><span class="danger">' . h($row['curl']) . '</span>' : '' ?>
        <?= $row['server'] ? '<br>' . h($row['server']) : '' ?>
        <?= $row['note'] ? '<br>' . h($row['note']) : '' ?></li><?php endforeach; ?>
    </ul>
    <p class="dim">PHP <?= h(PHP_VERSION) ?> · cURL <?= h(function_exists('curl_version') ? curl_version()['version'] : 'missing') ?> · <?= h(function_exists('curl_version') ? curl_version()['ssl_version'] : '') ?></p>
  </section>
  <?php endif; ?>
  <?php $recent = array_slice(array_reverse($state['events'] ?? []), 0, 10); ?>
  <?php if ($recent): ?>
  <section class="panel" aria-labelledby="recent-heading">
    <h2 id="recent-heading">Recent changes</h2>
    <ul class="event-list">
      <?php foreach ($recent as $event): ?><li><?= h(event_text($event)) ?> <span class="dim">· <?= h(when($event['t'])) ?></span></li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <div class="panel">
    <div class="section-line"><div><h2>Watched listings</h2><p class="dim"><?= h(count($state['items'])) ?> listings · <?= $runAge === null ? 'Waiting for the first scheduled run' : 'Checker last ran ' . h(when($state['last_run'])) ?> · <?= h($failing) ?> need review · <?= h($pausedSellers) ?> seller-paused</p></div><div class="row-actions">
      <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="diagnose">Run connection test</button></form>
      <?php if ($pausedSellers || $failing): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="clear_pauses" data-confirm="Clear all seller pauses and queue every automatic listing?">Clear seller pauses</button></form><?php endif; ?>
      <?php if (ingest_enabled() && ($failing || $pausedSellers)): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="route_failing" data-confirm="Move failing listings to the U.S. runner?">Move failing to U.S. runner</button></form><?php endif; ?>
      <?php if ($failing): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="queue_failing">Queue all failing</button></form><?php endif; ?>
      <a class="primary" href="?add=1">Add listing</a></div></div>
    <label class="admin-search">Search listings<input type="search" id="admin-search" placeholder="Name, seller, or status" autocomplete="off"></label>
    <p class="dim" id="admin-count" role="status" aria-live="polite"></p>
    <div class="admin-list" id="admin-list">
      <?php foreach ($rows as $row): $item = $row['item']; ?>
      <div class="admin-row" data-search="<?= h(strtolower($item['name'] . ' ' . $item['seller'] . ' ' . $row['tag'] . ' ' . $row['detail'])) ?>"><div><strong><?= h($item['name']) ?></strong><p class="dim"><?= h($item['seller']) ?> · <?= h(FIRMWARE[$item['firmware']]) ?> · <?= h(ROLES[$item['role']]) ?></p><p class="dim<?= $row['rank'] === 0 ? ' danger' : '' ?>"><?= h($row['detail']) ?></p></div>
      <span class="tag <?= h($row['rank'] === 0 || $row['rank'] === 4 ? 'unknown' : $item['result']['status']) ?>"><?= h($row['tag']) ?></span>
      <a class="quiet" href="?edit=<?= h(rawurlencode($item['id'])) ?>">Edit</a></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</main>
</body>
</html>
