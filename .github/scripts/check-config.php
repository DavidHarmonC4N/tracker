<?php
declare(strict_types=1);

// Repository safety check. Fails when config.php holds anything private, or when a tracked file looks like it
// contains a password, token or private key. The repository is public, so a real value committed here is leaked.
// It prints key names and file locations only, never the value it found.
// Run it by hand with: php .github/scripts/check-config.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$problems = [];

define('OFFGRID_APP', true);
$config = require $root . '/config.php';
if (!is_array($config)) {
    $problems[] = 'config.php did not return a list of settings.';
} else {
    foreach (['data_dir','setup_key','base_url','notify_email','notify_webhook','notify_from','notify_public_topic','ntfy_token','ingest_token','github_token'] as $key) {
        if (($config[$key] ?? '') !== '') {
            $problems[] = "config.php: '$key' has a value. Only the blank template belongs in the repository; put real values in the config.php on your host.";
        }
    }
    if (($config['staging'] ?? false) !== false) {
        $problems[] = "config.php: 'staging' must be false in the repository. Set it only in the config.php on the staging host.";
    }
    if (($config['local_test'] ?? false) !== false) {
        $problems[] = "config.php: 'local_test' must be false in the repository.";
    }
}

$list = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $list, $status);
if ($status !== 0 || !$list) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if (!str_starts_with($path, '.git/')) {
            $list[] = $path;
        }
    }
}

$patterns = [
    'a GitHub token'      => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
    'a private key'       => '/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/',
    'an AWS access key'   => '/\bAKIA[0-9A-Z]{16}\b/',
    'an API secret key'   => '/\bsk-(?:ant-)?[A-Za-z0-9_-]{32,}\b/',
];
$skip = ['.github/scripts/check-config.php'];
foreach ($list as $path) {
    if (in_array($path, $skip, true) || !is_file($root . '/' . $path) || filesize($root . '/' . $path) > 2_000_000) {
        continue;
    }
    $text = file_get_contents($root . '/' . $path);
    if ($text === false || str_contains($text, "\0")) {
        continue; // binary file
    }
    foreach (explode("\n", $text) as $number => $line) {
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $line)) {
                $problems[] = sprintf('%s:%d looks like %s.', $path, $number + 1, $label);
            }
        }
    }
}

if ($problems) {
    fwrite(STDERR, "Repository safety check FAILED:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}
echo 'Repository safety check passed (' . count($list) . " files).\n";
