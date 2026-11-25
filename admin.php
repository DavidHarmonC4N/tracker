<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/scanner.php';
require __DIR__ . '/includes/dispatch.php';
require __DIR__ . '/includes/visits.php';
require __DIR__ . '/includes/addurl.php';
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
    foreach (['url','variant','scope','ready','sold','price_path','warehouse'] as $field) {
        $clearResult = $clearResult || ($existing[$field] ?? '') !== $row[$field];
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
$addurl = null;
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
    $addurl = is_array($_SESSION['addurl'] ?? null) ? $_SESSION['addurl'] : null;
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
            // Counted in its own small file before any password work, so guesses never rewrite tracker.json.
            $accepted = rate_limit_hit($dir, 'admin', $ip, 8, 900);
            if ($accepted && $action === 'setup') {
                $accepted = change_state($dir, static function (&$fresh) use ($password) {
                    if ($fresh['password'] || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, chr(0))
                        || !hash_equals((string)config()['setup_key'], field($_POST,'setup_key'))) {
                        return false;
                    }
                    $fresh['password'] = password_hash($password, PASSWORD_DEFAULT);
                    return true;
                });
            } elseif ($accepted) {
                $accepted = $action === 'login' && $state['password'] && strlen($password) <= 72
                    && password_verify($password, $state['password']);
            }
            if ($accepted) {
                rate_limit_clear($dir, 'admin', $ip);
            }
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
        if ($action === 'test_alert') {
            if (is_staging()) {
                throw new InvalidArgumentException('This is a staging copy. Staging never sends alerts.');
            }
            $results = notify_send('Test alert', "This is a test alert from your Offgrid Comms Tracker admin.\nIf you can read this, alerts are working.\n" . base_url() . 'index.html',
                ['type'=>'test', 'url'=>base_url() !== '' ? base_url() . 'index.html' : '']);
            if (!$results) {
                throw new InvalidArgumentException('Alerts are off. Add notify_email or notify_webhook to the config.php on your host, then try again.');
            }
            alert_log_record($dir, 'Test alert', $results);
            $lines = array_map(static fn($r) => ucfirst($r['channel']) . ': ' . $r['message'], $results);
            if (array_filter($results, static fn($r) => !$r['ok'])) {
                throw new InvalidArgumentException('Test alert problem. ' . implode(' ', $lines));
            }
            $_SESSION['notice'] = 'Test alert sent. ' . implode(' ', $lines);
            go();
        }
        if ($action === 'test_public') {
            if (is_staging()) {
                throw new InvalidArgumentException('This is a staging copy. Staging never posts alerts.');
            }
            $base = ntfy_public_base();
            if ($base === '') {
                throw new InvalidArgumentException('The public topic is off. Set notify_public_topic in config.php first.');
            }
            $sent = ntfy_publish($base, 'Test post from the Offgrid Comms Tracker', "This is a test post. Real alerts name a radio, its seller and what changed.\nYou can ignore this one.",
                ['tags'=>NTFY_TAGS['test'], 'click'=>base_url() !== '' ? base_url() . 'index.html' : '']);
            alert_log_record($dir, 'Test post to the public topic', [['channel'=>'public topic', 'ok'=>$sent['ok'], 'message'=>$sent['message']]]);
            if (!$sent['ok']) {
                throw new InvalidArgumentException('Public topic problem. ' . $sent['message']);
            }
            $_SESSION['notice'] = 'Test post sent to the public topic. ' . $sent['message'];
            go();
        }
        if ($action === 'unmute_alerts') {
            change_state($dir, static function (&$fresh) {
                $fresh['alerts_muted_until'] = 0;
            });
            $_SESSION['notice'] = 'Owner alerts are on again.';
            go();
        }
        if ($action === 'addurl_read') {
            $found = addurl_read(field($_POST,'link'), static fn($u) => guarded_fetch($u, $dir), $state['items']);
            // Seeed lists one page per warehouse, so a link that exists only as warehouse listings is not blocked here;
            // the save step checks the warehouse too.
            $existing = array_filter($state['items'], static fn($row) => listing_url_key($row['url']) === listing_url_key($found['url']));
            if ($existing && !array_filter($existing, static fn($row) => ($row['warehouse'] ?? '') !== '')
                && has_listing_url($state['items'], $found['url'])) {
                throw new InvalidArgumentException('This product link is already in the catalog.');
            }
            $readings = [];
            if ($found['ok']) {
                foreach ($found['product']['variants'] as $variant) {
                    $readings[$variant['id']] = addurl_reading($found['body'], addurl_listing_url($found['url'], $variant['id']));
                }
                $readings['any'] = addurl_reading($found['body'], addurl_listing_url($found['url'], null));
                unset($found['body']);
            }
            $session = ['read'=>$found, 'readings'=>$readings, 'multi'=>$found['ok'] && count($found['product']['variants']) > 1,
                'link_variant'=>addurl_link_variant($found['url']), 'guess'=>null, 'reading'=>null];
            if (!$found['ok']) {
                $session['guess'] = addurl_guess($found, null, $state['items'], ingest_enabled());
                $_SESSION['addurl'] = $session;
                go('?add=1&from=url');
            }
            if (!$session['multi']) {
                $session = addurl_choose($session, null, $state['items'], ingest_enabled());
                $_SESSION['addurl'] = $session;
                go('?add=1&from=url');
            }
            $_SESSION['addurl'] = $session;
            go('?addurl=variant');
        }
        if ($action === 'addurl_pick') {
            if (!$addurl || empty($addurl['read']['ok'])) {
                throw new InvalidArgumentException('Paste the product link again.');
            }
            $choice = field($_POST,'variant_id');
            $known = array_column($addurl['read']['product']['variants'], 'id');
            if ($choice !== 'any' && !in_array($choice, $known, true)) {
                throw new InvalidArgumentException('Pick one of the variants.');
            }
            $_SESSION['addurl'] = addurl_choose($addurl, $choice === 'any' ? null : $choice, $state['items'], ingest_enabled());
            go('?add=1&from=url');
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
        if ($action === 'dispatch') {
            $problem = github_dispatch();
            change_state($dir, static function (&$fresh) use ($problem) {
                $fresh['dispatch_error'] = $problem;
                if ($problem === '') {
                    $fresh['last_dispatch'] = time();
                }
            });
            if ($problem !== '') {
                throw new InvalidArgumentException('Could not start the U.S. runner. ' . $problem);
            }
            $_SESSION['notice'] = 'U.S. runner started on GitHub. Results appear in a minute or two.';
            go();
        }
        if ($action === 'remove_manual') {
            // Manual-entry listings need a person to keep them current. This deletes every one, with its price history.
            $removed = change_state($dir, static function (&$fresh) {
                $count = 0;
                foreach ($fresh['items'] as $index=>$row) {
                    if (($row['mode'] ?? '') === 'manual') {
                        unset($fresh['history'][$row['id']]);
                        $fresh['items'][$index] = null;
                        $count++;
                    }
                }
                $fresh['items'] = array_values(array_filter($fresh['items']));
                return $count;
            });
            $_SESSION['notice'] = $removed ? $removed . ' manual-entry listing(s) removed.' : 'There are no manual-entry listings.';
            go();
        }
        if ($action === 'route_all') {
            if (!ingest_enabled()) {
                throw new InvalidArgumentException('Set ingest_token in config.php first.');
            }
            // With no server cron, a listing still set to "This server" is never checked. Move every automatic one to the runner.
            $moved = change_state($dir, static function (&$fresh) {
                $count = 0;
                foreach ($fresh['items'] as &$row) {
                    if ($row['paused'] || $row['mode'] === 'manual' || is_remote($row)) {
                        continue;
                    }
                    $row['checker'] = 'remote';
                    $count++;
                }
                unset($row);
                return $count;
            });
            $_SESSION['notice'] = $moved ? $moved . ' listing(s) moved to the U.S. runner. It picks them up on its next runs.' : 'Every automatic listing is already on the U.S. runner.';
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
        $fromReading = isset($_POST['from_url']) && $id === '' && is_array($addurl['reading'] ?? null) ? $addurl['reading'] : null;
        change_state($dir, static function (&$fresh) use ($action,$id,&$events,&$eventUrl,$fromReading) {
            foreach ($fresh['items'] as $index=>$row) {
                if ($row['id'] !== $id) {
                    continue;
                }
                if ($action === 'delete') {
                    array_splice($fresh['items'],$index,1);
                    unset($fresh['history'][$id]);
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
                    if (isset($_POST['record'])) {
                        record_history($fresh, $saved['id'], $saved['result']);
                    }
                }
                return;
            }
            if ($action !== 'save' || $id !== '') {
                throw new InvalidArgumentException('This listing no longer exists.');
            }
            if (count($fresh['items']) >= 250) {
                throw new InvalidArgumentException('This installation supports up to 250 listings.');
            }
            $new = save_item($_POST,null);
            if (has_listing_url($fresh['items'],$new['url'],$new['warehouse'])) {
                throw new InvalidArgumentException('This product link is already in the catalog' . ($new['warehouse'] !== '' ? ' for that warehouse' : '') . '.');
            }
            if ($fromReading && $fromReading['listing_url'] === $new['url'] && $new['mode'] === 'auto') {
                // The administrator saw this reading a moment ago, so the card is right from the start.
                $new['result'] = $fromReading['result'];
                $new['attempted_at'] = iso_now();
                record_history($fresh, $new['id'], $new['result']);
            }
            $fresh['items'][] = $new;
        });
        announce_events($events, $eventUrl);
        $_SESSION['notice'] = match ($action) {
            'delete'=>'Listing removed.', 'queue'=>'Listing queued for the next scheduled run.', default=>'Listing saved.',
        };
        if ($action === 'save' && isset($_POST['from_url']) && $id === '') {
            if (isset($_POST['another']) && !empty($addurl['multi'])) {
                $_SESSION['notice'] = 'Listing saved. Pick the next variant.';
                go('?addurl=variant');
            }
            unset($_SESSION['addurl']);
        }
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
$fromUrl = $auth && !$blocking && isset($_GET['from']) && is_array($addurl['guess'] ?? null);
$showVariant = $auth && !$blocking && !$showEditor && ($_GET['addurl'] ?? '') === 'variant' && !empty($addurl['read']['ok']);
$values = $postEditor ? $_POST : ($editor ?? (($submission ?? ['firmware'=>'both','kind'=>'handheld','role'=>'unspecified'])
    + ['mode'=>'auto','setup'=>'unknown']));
if (!$postEditor && is_array($values['specs'] ?? null)) {
    foreach ($values['specs'] as $specKey=>$specValue) {
        $values['spec_' . $specKey] = $specValue;
    }
}
if ($fromUrl && !$postEditor) {
    $values = $addurl['guess']['values'] + $values;
    $GLOBALS['field_hints'] = $addurl['guess']['why'];
}
$fromUrlPost = $postEditor && isset($_POST['from_url']);
$reading = $editor['result'] ?? [];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= is_staging() ? '[STAGING] ' : '' ?><?= $auth && !empty($state['submissions']) ? '(' . h(count($state['submissions'])) . ') ' : '' ?>Admin · Offgrid Comms Tracker</title>
<link rel="stylesheet" href="assets/styles.css?v=20261201-review">
<script src="assets/admin.js?v=3" defer></script>
</head>
<body>
<?php if (is_staging()): ?><div class="staging-bar" role="note">STAGING · a private test copy, not the live site</div><?php endif; ?>
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
<?php elseif ($showVariant):
    $product = $addurl['read']['product'];
    $taken = [];
    foreach ($product['variants'] as $variant) {
        $taken[$variant['id']] = has_listing_url($state['items'], addurl_listing_url($addurl['read']['url'], $variant['id']));
    }
    $preselect = $addurl['link_variant'] ?? null;
    if ($preselect === null || !isset($taken[$preselect]) || $taken[$preselect]) {
        $preselect = null;
        foreach ($product['variants'] as $variant) {
            if ($taken[$variant['id']]) {
                continue;
            }
            if ($preselect === null || $variant['available']) {
                $preselect = $preselect === null || $variant['available'] ? $variant['id'] : $preselect;
                if ($variant['available']) {
                    break;
                }
            }
        }
    }
    ?>
  <form class="panel editor addurl" method="post">
    <div class="section-line"><h2>Add a listing by link</h2><a class="quiet" href="admin.php">Back to admin</a></div>
    <?= addurl_steps(2) ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <div class="prod"><b><?= h($product['title']) ?></b><span><?= h($addurl['read']['host']) ?><?= $product['vendor'] !== '' ? ' · ' . h($product['vendor']) : '' ?> · read just now from the product feed</span></div>
    <?php if ($addurl['read']['removed']): ?>
      <p class="anote"><b>Tidied your link:</b> removed <?= h(implode(', ', array_map(static fn($p) => explode('=', $p, 2)[0], $addurl['read']['removed']))) ?>. The listing keeps only the product address<?= $addurl['link_variant'] ? ' and ?variant=' . h($addurl['link_variant']) : '' ?>.</p>
    <?php endif; ?>
    <fieldset class="varlist"><legend>Which variant should we track?</legend>
      <?php foreach ($product['variants'] as $variant): $reading = $addurl['readings'][$variant['id']] ?? null; ?>
        <label class="var<?= $preselect === $variant['id'] ? ' pick' : '' ?><?= $taken[$variant['id']] ? ' taken' : '' ?>">
          <input type="radio" name="variant_id" value="<?= h($variant['id']) ?>" <?= $preselect === $variant['id'] ? 'checked' : '' ?> <?= $taken[$variant['id']] ? 'disabled' : '' ?>>
          <span><?= h(addurl_is_default_title($variant['title']) ? 'Single option' : $variant['title']) ?><?= $addurl['link_variant'] === $variant['id'] ? ' <small class="from-link">· from your link</small>' : '' ?><?= $taken[$variant['id']] ? ' <small class="from-link">· already in the catalog</small>' : '' ?></span>
          <span class="p"><?= h(money($variant['price'])) ?></span>
          <span class="stk <?= $variant['available'] ? 'in' : 'out' ?>"><?= $variant['available'] ? 'Available' : 'Out of stock' ?></span>
        </label>
      <?php endforeach; ?>
      <label class="var">
        <input type="radio" name="variant_id" value="any" <?= $preselect === null ? 'checked' : '' ?>>
        <span>Any available variant <small class="from-link">· for products where the option does not matter</small></span><span></span><span></span>
      </label>
    </fieldset>
    <div class="acts"><button class="primary" name="action" value="addurl_pick">Use this variant and continue</button><a class="quiet" href="admin.php">Cancel</a></div>
  </form>
<?php elseif ($showEditor): ?>
  <form class="panel editor" method="post">
    <div class="section-line"><h2><?= $submission ? 'Review submission' : ($editor ? 'Edit listing' : 'Add listing') ?></h2><a class="quiet" href="admin.php<?= $submission ? '#submissions' : '' ?>">Back to admin</a></div>
    <?php if ($submission): ?>
      <p class="dim">Check the seller, product, and variant before publishing. <a class="inline-link" href="<?= h($submission['url']) ?>" rel="noreferrer noopener" target="_blank">Open submitted product link (new tab)</a></p>
    <?php endif; ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <input type="hidden" name="id" value="<?= h($submission['id'] ?? $editor['id'] ?? '') ?>">
    <?php if ($fromUrl || $fromUrlPost): ?>
      <input type="hidden" name="from_url" value="1">
      <input type="hidden" name="action" value="save">
    <?php endif; ?>
    <?php if ($fromUrl): ?>
      <?= addurl_steps(3) ?>
      <?php if (empty($addurl['read']['ok'])): ?>
        <div class="anote fail"><b><?= match ($addurl['read']['reason'] ?? '') {
            'manual' => 'This seller blocks automated checks',
            'blocked' => 'This server could not read the page',
            default => 'Could not read a product feed',
        } ?>.</b> <?= h($addurl['read']['message'] ?? '') ?> <?= match ($addurl['read']['reason'] ?? '') {
            'manual' => 'The form is set to "Manual review only", and the public page will show the asterisk. Record the price and stock by hand under "Record a manual price or stock reading".',
            'blocked' => 'The form is set to scan the page' . (($addurl['guess']['values']['checker'] ?? '') === 'remote' ? ' with the U.S. runner, which takes the first reading within the hour' : '') . '. Check the fields below.',
            default => 'The form is filled in from the link and set to scan the page. Add page rules under "Variant and page rules" if the first checks fail.',
        } ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="form-grid">
      <?php input_box('name','Radio name',$values,'text','required maxlength="120"');
      input_box('seller','Seller',$values,'text','required maxlength="80"');
      input_box('location','Seller location',$values,'text','maxlength="70"');
      input_box('url','HTTPS product link',$values,'url','required maxlength="1500"');
      select_box('firmware','Firmware support',FIRMWARE,$values);
      select_box('kind','Type',KINDS,$values);
      select_box('antenna_port','Antenna port (radios)',ANTENNA_PORTS,$values);
      select_box('warehouse','Ships from',WAREHOUSES,$values);
      select_box('role','Primary use',ROLES,$values);
      select_box('setup','Setup needed',SETUP,$values);
      select_box('mode','Check method',MODES,$values);
      if (ingest_enabled()) { select_box('checker','Checked by',CHECKERS,$values); } ?>
    </div>
    <details <?= is_antenna_kind(field($values,'kind')) ? 'open' : '' ?>>
      <summary>Antenna facts (for antenna types only)</summary>
      <div class="form-grid">
        <?php input_box('spec_band','Band (915, 868/915 or any)',$values,'text','maxlength="12"');
        input_box('spec_gain','Gain (for example 5.8 dBi)',$values,'text','maxlength="12"');
        input_box('spec_connector','Connector (for example N male)',$values,'text','maxlength="30"');
        input_box('spec_length','Length (for example 31 in)',$values,'text','maxlength="12"'); ?>
      </div>
    </details>
    <label>Public notes<textarea name="note" maxlength="500" rows="2"><?= h(field($values,'note')) ?></textarea><?= field_hint('note') ?></label>
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
    <?php if ($fromUrl && !empty($addurl['reading']) && (($values['mode'] ?? '') === 'auto')): $first = $addurl['reading']['result']; ?>
      <p class="anote"><b>First reading is saved too:</b> <?= h(STATUSES[$first['status']] ?? $first['status']) ?><?= $first['price'] !== null ? ' · ' . h(money($first['price'])) : '' ?>, read just now from the store. The next scheduled check confirms it.</p>
    <?php endif; ?>
    <?php if ($fromUrl || $fromUrlPost): ?>
      <div class="acts"><button class="primary">Save listing</button><?php if (!empty($addurl['multi'])): ?><button class="quiet" name="another" value="1">Save and add another variant</button><?php endif; ?><a class="quiet" href="admin.php">Cancel</a></div>
    <?php else: ?>
    <button class="primary" name="action" value="<?= $submission ? 'approve' : 'save' ?>"><?= $submission ? 'Approve and publish' : 'Save listing' ?></button>
    <?php endif; ?>
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
  $serverAuto = 0;
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
      elseif ($auto) { $serverAuto++; }
      $rows[] = ['item'=>$item,'rank'=>$rank,'tag'=>$tag,'detail'=>$detail];
  }
  usort($rows, static fn($a, $b) => $a['rank'] <=> $b['rank']);
  $runAge = $state['last_run'] ? (int)floor(($nowTs - strtotime($state['last_run'])) / 60) : null;
  ?>
  <?php $runnerAge = $state['last_runner_run'] ? (int)floor(($nowTs - strtotime($state['last_runner_run'])) / 60) : null; ?>
  <?php if ($remote && ($runnerAge === null || $runnerAge > 45)): ?>
    <p class="notice danger" role="alert"><?= $runnerAge === null ? 'The U.S. runner has not checked in yet. Finish the GitHub setup steps in the README.' : 'The U.S. runner last checked in ' . h($runnerAge >= 120 ? intdiv($runnerAge, 60) . ' hours' : $runnerAge . ' minutes') . ' ago. Check the Actions tab on GitHub.' ?></p>
  <?php endif; ?>
  <?php if (!empty($state['dispatch_error'])): ?>
    <p class="notice danger" role="alert">The last automatic start of the U.S. runner failed: <?= h($state['dispatch_error']) ?></p>
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
  <?php
    $alertEmail = trim((string)(config()['notify_email'] ?? ''));
    $alertHook = trim((string)(config()['notify_webhook'] ?? ''));
    $maskedEmail = $alertEmail !== '' && str_contains($alertEmail, '@') ? substr($alertEmail, 0, 1) . '***@' . substr(strrchr($alertEmail, '@'), 1) : '';
    $hookHost = $alertHook !== '' ? (string)(parse_url($alertHook, PHP_URL_HOST) ?: 'invalid address') : '';
    $alertLog = array_reverse(array_slice($state['alert_log'] ?? [], -5));
  ?>
  <section class="panel" aria-labelledby="alerts-heading">
    <div class="section-line"><div><h2 id="alerts-heading">Alerts</h2><p class="dim">Back in stock, price drops and new submissions. Several changes in one run arrive as one message.</p></div>
      <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="test_alert">Send test alert</button></form></div>
    <?php if (is_staging()): ?>
      <p class="dim">This is a staging copy, so it never sends alerts.</p>
    <?php elseif ($alertEmail === '' && $alertHook === '' && ntfy_public_base() === ''): ?>
      <p class="notice" role="status">Alerts are off. Add <code>notify_email</code> (an email address) or <code>notify_webhook</code> (an https:// address, such as a private ntfy.sh topic) to the config.php on your host. A webhook is the more reliable choice on shared hosting.</p>
    <?php else: ?>
      <ul class="event-list">
        <li><strong>Email</strong> · <?= $alertEmail === '' ? 'not set' : h($maskedEmail !== '' ? $maskedEmail : 'invalid address') ?><?= $alertEmail !== '' ? ' · from ' . h(notify_from_address() ?: 'the host\'s default sender (set notify_from)') : '' ?></li>
        <li><strong>Private topic / webhook</strong> · <?= $alertHook === '' ? 'not set' : h($hookHost . (ntfy_owner_topic() !== '' ? ' · topic …' . substr($alertHook, -4) . ' · tap buttons ' . (ntfy_endpoint() !== '' ? 'on' : 'off (set ntfy_token and base_url)') : '')) ?></li>
        <?php $health = health_evaluate($state, time()); ?>
        <li><strong>Health alerts</strong> · on, and sent even while muted: you are told once if no checker reports for <?= h(HEALTH_STALL_AFTER / 3600) ?> hours, or if every listing from a seller keeps failing. Now: <?= $health['stalled'] ? 'stalled since ' . h(when(gmdate(DATE_ATOM, (int)$health['last']))) : ($health['last'] ? 'running, last check-in ' . h(when(gmdate(DATE_ATOM, (int)$health['last']))) : 'waiting for the first check') ?><?= $health['sellers'] ? '; seller trouble: ' . h(implode(', ', array_keys($health['sellers']))) : '' ?></li>
        <li><strong>Public topic</strong> · <?= ntfy_public_base() === '' ? 'off' : h(ntfy_public_base()) . ' · each radio also has its own topic' ?></li>
        <?php if (alerts_muted($state)): ?><li><strong>Muted</strong> until <?= h(gmdate('D H:i', (int)$state['alerts_muted_until'])) ?> UTC for owner alerts. Public topics are not affected.
          <form method="post" style="display:inline"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="unmute_alerts">Unmute</button></form></li><?php endif; ?>
      </ul>
      <?php if (ntfy_public_base() !== ''): ?>
        <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="test_public" data-confirm="This posts a message that everyone following the public topic will see. Send it?">Send test post to the public topic</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($alertLog): ?>
      <p class="dim">Latest results</p>
      <ul class="event-list">
        <?php foreach ($alertLog as $entry): ?>
          <li><span class="<?= $entry['ok'] ? '' : 'danger' ?>"><?= $entry['ok'] ? 'Sent' : 'Failed' ?></span> · <?= h(ucfirst($entry['channel'])) ?> · <?= h(when($entry['t'])) ?><br><?= h($entry['subject']) ?><br><span class="dim"><?= h($entry['message']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php $visits = null; try { $visits = visits_touch($dir, false, '', ''); } catch (Throwable) {} ?>
  <?php if ($visits && $visits['days']): ?>
  <section class="panel" aria-labelledby="visits-heading">
    <h2 id="visits-heading">Visitors</h2>
    <p class="dim">Unique visitors per day (UTC). <?= h(number_format($visits['total'])) ?> since <?= h($visits['since']) ?>. Returning visitors count once per day.</p>
    <ul class="event-list">
      <?php foreach (array_slice($visits['days'], 0, 14, true) as $day => $row): ?><li><?= h($day) ?> · <strong><?= h($row['unique']) ?></strong> visitors · <?= h($row['views']) ?> page views</li><?php endforeach; ?>
    </ul>
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
  <section class="panel editor addurl" aria-labelledby="addurl-heading">
    <div class="section-line"><div><h2 id="addurl-heading">Add a listing by link</h2><p class="dim">Paste a product page. The tracker reads it and fills in the form for you.</p></div><a class="quiet" href="admin.php?add=1">Add by hand</a></div>
    <?= addurl_steps(1) ?>
    <form method="post" class="urlrow"><input type="hidden" name="token" value="<?= h($token) ?>">
      <label>Product link<input type="url" name="link" required maxlength="1500" placeholder="https://store.example.com/products/…"></label>
      <button class="primary" name="action" value="addurl_read">Read this page</button></form>
    <p class="hint">Works with Shopify stores (Rokland, Seeed, SpecFive and others). Etsy, DigiKey and Mouser open as "Checked by hand". Other pages open the form with what the link tells us.</p>
  </section>
  <div class="panel">
    <div class="section-line"><div><h2>Watched listings</h2><p class="dim"><?= h(count($state['items'])) ?> listings · <?= !$serverAuto && $remote ? ($runnerAge === null ? 'Waiting for the U.S. runner' : 'U.S. runner last checked in ' . h(when($state['last_runner_run']))) : ($runAge === null ? 'Waiting for the first scheduled run' : 'Checker last ran ' . h(when($state['last_run']))) ?> · <?= h($failing) ?> need review · <?= h($pausedSellers) ?> seller-paused</p></div><div class="row-actions">
      <form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="diagnose">Run connection test</button></form>
      <?php if ($pausedSellers || $failing): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="clear_pauses" data-confirm="Clear all seller pauses and queue every automatic listing?">Clear seller pauses</button></form><?php endif; ?>
      <?php if (github_trigger_enabled()): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="dispatch">Run U.S. checker now</button></form><?php endif; ?>
      <?php $manualCount = count(array_filter($state['items'], static fn($i) => ($i['mode'] ?? '') === 'manual')); if ($manualCount): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="remove_manual" data-confirm="Delete <?= h($manualCount) ?> manual-entry listing(s) and their price history? This cannot be undone.">Remove <?= h($manualCount) ?> manual-entry</button></form><?php endif; ?>
      <?php if (ingest_enabled() && $serverAuto): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="route_all" data-confirm="Move <?= h($serverAuto) ?> automatic listing(s) from this server to the U.S. runner?">Move all <?= h($serverAuto) ?> to U.S. runner</button></form><?php endif; ?>
      <?php if (ingest_enabled() && ($failing || $pausedSellers)): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="route_failing" data-confirm="Move failing listings to the U.S. runner?">Move failing to U.S. runner</button></form><?php endif; ?>
      <?php if ($failing): ?><form method="post"><input type="hidden" name="token" value="<?= h($token) ?>"><button class="quiet" name="action" value="queue_failing">Queue all failing</button></form><?php endif; ?>
      <a class="primary" href="?add=1">Add listing</a></div></div>
    <label class="admin-search">Search listings<input type="search" id="admin-search" placeholder="Name, seller, or status" autocomplete="off"></label>
    <div class="admin-filters" role="group" aria-label="Filter listings">
      <span class="qlabel" id="admin-kind-label">Show</span>
      <?php foreach (['' => 'All', 'radio' => 'Radios', 'antenna' => 'Antennas'] as $key => $label): ?><button type="button" class="chip" data-filter="category" data-value="<?= h($key) ?>" aria-pressed="<?= $key === '' ? 'true' : 'false' ?>" aria-describedby="admin-kind-label"><?= h($label) ?> <b class="n"></b></button><?php endforeach; ?>
      <span class="sep" aria-hidden="true"></span>
      <span class="qlabel" id="admin-status-label">Status</span>
      <?php foreach (['' => 'Any', 'in_stock' => 'In stock', 'orderable' => 'Orderable', 'out_of_stock' => 'Out of stock', 'review' => 'Needs review'] as $key => $label): ?><button type="button" class="chip" data-filter="group" data-value="<?= h($key) ?>" aria-pressed="<?= $key === '' ? 'true' : 'false' ?>" aria-describedby="admin-status-label"><?= h($label) ?> <b class="n"></b></button><?php endforeach; ?>
    </div>
    <p class="dim" id="admin-count" role="status" aria-live="polite"></p>
    <div class="admin-list" id="admin-list">
      <?php foreach ($rows as $row): $item = $row['item']; ?>
      <?php $isAnt = is_antenna_kind((string)$item['kind']);
        $facts = $isAnt ? array_filter(array_values(clean_specs($item['specs'] ?? []))) : [];
        $group = $row['rank'] === 0 ? 'review' : ($row['rank'] === 4 ? 'hidden' : (string)$item['result']['status']); ?>
      <div class="admin-row" data-category="<?= $isAnt ? 'antenna' : 'radio' ?>" data-group="<?= h($group) ?>" data-search="<?= h(strtolower($item['name'] . ' ' . $item['seller'] . ' ' . $row['tag'] . ' ' . $row['detail'])) ?>"><div><strong><?= h($item['name']) ?></strong><p class="dim"><?= h($item['seller']) ?> · <?= $isAnt ? h(KINDS[$item['kind']] . ($facts ? ' · ' . implode(' · ', $facts) : '')) : h(FIRMWARE[$item['firmware']]) . ' · ' . h(ROLES[$item['role']]) ?></p><p class="dim<?= $row['rank'] === 0 ? ' danger' : '' ?>"><?= h($row['detail']) ?></p></div>
      <span class="tag <?= h($row['rank'] === 0 || $row['rank'] === 4 ? 'unknown' : $item['result']['status']) ?>"><?= h($row['tag']) ?></span>
      <a class="quiet" href="?edit=<?= h(rawurlencode($item['id'])) ?>">Edit</a></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</main>
</body>
</html>
