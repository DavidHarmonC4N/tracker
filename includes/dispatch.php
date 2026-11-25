<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/scanner.php';

/** Lets the site start the GitHub workflow itself, so the runner does not depend on GitHub's schedule. */
function github_trigger_enabled(): bool {
    if (is_staging()) {
        return false; // a staging copy never starts the real runner
    }
    $c = config();
    return (string)($c['github_token'] ?? '') !== ''
        && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', (string)($c['github_repo'] ?? '')) === 1;
}

/** Starts the workflow. Returns an empty string on success, or a short error message. */
function github_dispatch(): string {
    if (!github_trigger_enabled() || !extension_loaded('curl')) {
        return 'GitHub triggering is not configured, or cURL is missing.';
    }
    $c = config();
    $workflow = (string)($c['github_workflow'] ?? 'check.yml');
    $ref = (string)($c['github_ref'] ?? 'main');
    if (!preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/D', $workflow) || !preg_match('~^[A-Za-z0-9_./-]{1,100}$~D', $ref)) {
        return 'Check github_workflow and github_ref in config.php.';
    }
    $api = $c['local_test'] === true && !empty($c['github_api']) ? rtrim((string)$c['github_api'], '/') : 'https://api.github.com';
    $curl = curl_init($api . '/repos/' . $c['github_repo'] . '/actions/workflows/' . $workflow . '/dispatches');
    curl_setopt_array($curl, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['ref'=>$ref]), CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>$c['local_test'] === true ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'OffgridCommsTracker-Trigger/1.0',
        CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $c['github_token'], 'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28', 'Content-Type: application/json'],
    ]);
    $body = curl_exec($curl);
    $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($code === 204) {
        return '';
    }
    $hint = match ($code) {
        401 => 'The GitHub token was rejected. It may be wrong or expired.',
        403, 404 => 'GitHub refused it. Check the repository name, the workflow file name, and that the token has Actions read and write access to this repository.',
        422 => 'GitHub could not start the workflow. Check github_ref and that the workflow file has a workflow_dispatch trigger.',
        0 => 'Could not reach GitHub' . ($error !== '' ? ' (' . $error . ')' : '') . '.',
        default => 'GitHub answered HTTP ' . $code . '.',
    };
    return $hint;
}

/**
 * Called after a catalog request. When U.S. runner listings are due and no run was started in the last 9 minutes,
 * starts one. The state is only rewritten when a start is actually due, so normal page loads stay read-only.
 */
function maybe_dispatch_runner(string $dir): void {
    if (!github_trigger_enabled()) {
        return;
    }
    $state = load_state($dir);
    if ((int)($state['last_dispatch'] ?? 0) > time() - 540 || !has_remote_work($state)) {
        return;
    }
    $due = change_state($dir, static function (&$fresh) {
        if ((int)($fresh['last_dispatch'] ?? 0) > time() - 540 || !has_remote_work($fresh)) {
            return false;
        }
        $fresh['last_dispatch'] = time();
        return true;
    });
    if (!$due) {
        return;
    }
    $error = github_dispatch();
    change_state($dir, static function (&$fresh) use ($error) {
        $fresh['dispatch_error'] = $error;
    });
}
