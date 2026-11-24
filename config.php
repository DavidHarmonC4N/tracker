<?php
declare(strict_types=1);

if (!defined('OFFGRID_APP')) {
    http_response_code(404);
    exit;
}

return [
    // Create this folder outside every public web root. Use your host's absolute path.
    'data_dir' => '',
    // Set a unique random key (at least 32 characters) before opening admin.php.
    // The key only works until the first administrator password is set.
    'setup_key' => '',
    // Optional but recommended: the public address of this folder, for example https://example.com/offgrid/
    // Used for links in owner alerts and the RSS feed. When empty, links use the request's Host header.
    'base_url' => '',
    // Minimum listing refresh interval. Seller pacing and pauses can extend it.
    'interval_minutes' => 60,
    'batch_size' => 6,
    // Optional alerts for back-in-stock, price drops and new submissions. Leave empty to turn off.
    'notify_email' => '',
    // An https URL that accepts a plain-text POST, such as an ntfy.sh topic: https://ntfy.sh/your-private-topic
    'notify_webhook' => '',
    // Optional: lets the U.S. runner (see README) report checks to ingest.php. Use a unique random key of at
    // least 32 characters and put the same value in the OFFGRID_TOKEN secret on GitHub. Leave empty to turn off.
    'ingest_token' => '',
    // Optional: lets the site start the GitHub workflow itself when U.S. runner listings are due, so the runner
    // does not depend on GitHub's schedule. Create a fine-grained token limited to this one repository with
    // "Actions: Read and write" access. Leave github_token empty to turn off.
    'github_repo' => 'DavidHarmonC4N/tracker',
    'github_token' => '',
    'github_workflow' => 'check.yml',
    'github_ref' => 'main',
    // Leave false on a public host. True permits loopback HTTP for local testing only.
    'local_test' => false,
];
