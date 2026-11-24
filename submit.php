<?php
declare(strict_types=1);

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
common_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

$open = false;
$notice = false;
$error = '';
$values = ['role'=>'unspecified'];
$token = '';
try {
    $dir = data_dir();
    if (load_state($dir)['password']) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        session_name('OffgridSubmit_' . substr(hash('sha256',__DIR__),0,12));
        if (!session_start(['use_strict_mode'=>true,'cookie_secure'=>$secure,'cookie_httponly'=>true,'cookie_samesite'=>'Lax'])) {
            throw new RuntimeException('Could not start a submission session.');
        }
        $open = true;
        $notice = (bool)($_SESSION['listing_received'] ?? false);
        unset($_SESSION['listing_received']);
        $token = $_SESSION['submission_token'] ??= bin2hex(random_bytes(32));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $values = $_POST;
            if (!hash_equals($token,field($values,'token'))) {
                throw new InvalidArgumentException('This form expired. Reload the page and try again.');
            }
            if (field($values,'website') === '') {
                $row = listing_fields($values);
                $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
                if (!rate_limit_hit($dir, 'submit', $ip, 5, 3600)) {
                    throw new InvalidArgumentException('The review queue is busy. Please try again later.');
                }
                $added = change_state($dir, static function (&$state) use ($row) {
                    if (count($state['submissions']) >= 100) {
                        throw new InvalidArgumentException('The review queue is busy. Please try again later.');
                    }
                    if (!has_listing_url($state['items'],$row['url']) && !has_listing_url($state['submissions'],$row['url'])) {
                        $id = bin2hex(random_bytes(12));
                        $state['submissions'][$id] = $row + ['id'=>$id];
                        return true;
                    }
                    return false;
                });
                if ($added) {
                    notify('New listing submission', $row['name'] . ' (' . $row['seller'] . ")\n" . $row['url']
                        . "\nReview it in Admin: " . (base_url() !== '' ? base_url() . 'admin.php#submissions' : 'admin.php'));
                }
            }
            $_SESSION['listing_received'] = true;
            $_SESSION['submission_token'] = bin2hex(random_bytes(32));
            header('Location: submit.php', true, 303);
            exit;
        }
    }
} catch (InvalidArgumentException $failure) {
    $error = $failure->getMessage();
} catch (Throwable $failure) {
    error_log('Offgrid submissions: ' . $failure->getMessage());
    $error = 'Submissions are temporarily unavailable. Please try again later.';
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Submit a listing · Offgrid Comms Tracker</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <header class="mast"><div class="wrap mast-inner">
    <a class="brand" href="index.html"><span class="brand-icon" aria-hidden="true"><i></i></span> OFFGRID <span>COMMS</span></a>
    <a class="quiet" href="index.html#inventory">Catalog</a>
  </div></header>
  <main class="wrap admin-area submission-area">
    <div class="admin-heading"><h1>Submit a listing</h1></div>
    <?php if ($notice): ?><p class="notice" role="status">Thanks for the suggestion. New links are reviewed before they appear in the catalog.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice danger" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if ($open): ?>
      <form class="panel editor" method="post">
        <p class="dim">Suggest a MeshCore or Meshtastic radio from a U.S. seller. Share the direct product link and, if possible, the link for the U.S. variant.</p>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div hidden><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="form-grid">
          <?php input_box('name','Radio name',$values,'text','required maxlength="120"');
          input_box('seller','Seller',$values,'text','required maxlength="80"');
          input_box('url','HTTPS product link',$values,'url','required maxlength="1500"');
          input_box('location','Seller location (optional)',$values,'text','maxlength="70"');
          select_box('firmware','Firmware support',[''=>'Select firmware'] + FIRMWARE,$values);
          select_box('role','Primary use',ROLES,$values);
          select_box('kind','Radio type',[''=>'Select type'] + KINDS,$values); ?>
        </div>
        <label>Listing notes (optional)<textarea name="note" maxlength="500" rows="3" aria-describedby="note-help"><?= h(field($values,'note')) ?></textarea></label>
        <p class="dim" id="note-help">Mention any variant or setup details. Approved notes appear on the public listing.</p>
        <button class="primary" type="submit">Send for review</button>
      </form>
    <?php elseif ($error === ''): ?>
      <p class="panel">Listing submissions open after the site administrator finishes setup.</p>
    <?php endif; ?>
  </main>
</body>
</html>
