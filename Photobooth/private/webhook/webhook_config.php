<?php

// This file is loaded by private/webhook/webhook_receiver.php.
// If accessed directly via HTTP, do not disclose anything.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

return [
    // Shared secret between Selfie-Upload sender and this receiver
    // Set this to the same value as SELFIE_WEBHOOK_TOKEN in the Selfie-Upload Webserver.
    'SELFIE_WEBHOOK_TOKEN' => '',

    // Only allow image_url from this host (leave empty to disable host check)
    'SELFIE_UPLOAD_HOST' => 'selfie.example.com',

    // Where to store downloaded images
    'IMAGE_DIRECTORY' => '/var/www/html/data/images/',

    // Photobooth gallery DB file (JSON array of filenames)
    'PHOTOBOOTH_DB_FILE' => '/var/www/html/data/db.txt',

    // Download constraints
    'MAX_DOWNLOAD_BYTES' => 8 * 1024 * 1024,
    'DOWNLOAD_CONNECT_TIMEOUT_SECONDS' => 5,
    'DOWNLOAD_TIMEOUT_SECONDS' => 10,

    // Retry behavior (tune to match HAProxy timeouts)
    'MAX_RETRIES' => 2,
    'RETRY_DELAY_SECONDS' => 1,

    // Optional cleanup callback
    // If empty, the receiver derives it from image_url as: https://<host>/<base>/delete_image.php
    // 'DELETE_IMAGE_WEBHOOK_URL' => 'https://selfie.example.com/delete_image.php',
    'DELETE_CONNECT_TIMEOUT_SECONDS' => 5,
    'DELETE_TIMEOUT_SECONDS' => 10,
];
