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
    // Leave false on a public host. True permits loopback HTTP for local testing only.
    'local_test' => false,
];
