<?php
declare(strict_types=1);

// The tap buttons on your private ntfy alerts call this page. Each call must carry the secret ntfy_token from config.php
// in an X-Offgrid-Token header. The actions are deliberately small: read a summary, start the U.S. runner, mute alerts.
// The answer is also posted back into your private topic, so it appears on your phone.

define('OFFGRID_APP', true);
require __DIR__ . '/includes/app.php';
require __DIR__ . '/includes/scanner.php';
require __DIR__ . '/includes/dispatch.php';

common_headers();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function ntfy_stop(int $code, string $text): never {
    http_response_code($code);
    echo $text . "\n";
    exit;
}

if (is_staging() || ntfy_token() === '' || config()['data_dir'] === '') {
    ntfy_stop(404, 'Not found.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ntfy_stop(405, 'Use POST.');
}
$given = (string)($_SERVER['HTTP_X_OFFGRID_TOKEN'] ?? '');
if ($given === '' || !hash_equals(ntfy_token(), $given)) {
    // Count wrong tries and stop answering after ten, so a guessed token cannot be brute-forced.
    try {
        if (!rate_limit_hit(data_dir(), 'ntfy-bad', hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 10, 900)) {
            ntfy_stop(429, 'Too many wrong tries. Try again later.');
        }
    } catch (RuntimeException) {
    }
    ntfy_stop(403, 'Wrong token.');
}

try {
    $dir = data_dir();
    if (!rate_limit_hit($dir, 'ntfy', 'all', 60, 600)) {
        ntfy_stop(429, 'Too many requests. Try again in a few minutes.');
    }
    $action = (string)($_GET['do'] ?? '');
    $title = 'Offgrid';
    $state = load_state($dir);
    switch ($action) {
        case 'status':
            $title = 'Status';
            $text = ntfy_status_text($state);
            break;
        case 'drops':
            $title = 'Drops this week';
            $text = ntfy_drops_text($state);
            break;
        case 'check':
            $title = 'Check now';
            if (!github_trigger_enabled()) {
                $text = 'The U.S. runner trigger is not set up (github_token in config.php), so nothing was started.';
            } else {
                $problem = github_dispatch();
                change_state($dir, static function (&$fresh) use ($problem) {
                    $fresh['dispatch_error'] = $problem;
                    if ($problem === '') {
                        $fresh['last_dispatch'] = time();
                    }
                });
                $text = $problem === '' ? 'U.S. runner started. Results appear in a minute or two.' : 'Could not start the U.S. runner. ' . $problem;
            }
            break;
        case 'mute':
        case 'unmute':
            $until = $action === 'mute' ? time() + 86400 : 0;
            change_state($dir, static function (&$fresh) use ($until) {
                $fresh['alerts_muted_until'] = $until;
            });
            $state['alerts_muted_until'] = $until;
            $title = $action === 'mute' ? 'Muted for 24 hours' : 'Alerts are back on';
            $text = $action === 'mute' ? 'No owner alerts until ' . gmdate('D H:i', $until) . ' UTC. Public topics are not affected. Tap Unmute to turn them back on.' : 'Owner alerts are on again.';
            break;
        default:
            ntfy_stop(400, 'Unknown action.');
    }
    $topic = ntfy_owner_topic();
    $posted = $topic !== '' ? ntfy_publish($topic, $title, $text, ['tags'=>NTFY_TAGS['info'], 'actions'=>ntfy_reply_buttons(alerts_muted($state))]) : ['ok'=>false];
    header('X-Offgrid-Reply-Posted: ' . ($posted['ok'] ? 'yes' : 'no'));
    echo $text . "\n";
} catch (Throwable $error) {
    error_log('Offgrid ntfy: ' . $error->getMessage());
    ntfy_stop(500, 'Something went wrong.');
}
